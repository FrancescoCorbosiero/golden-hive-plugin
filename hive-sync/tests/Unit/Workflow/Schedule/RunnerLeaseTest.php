<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Workflow\Schedule;

use HiveSync\Workflow\Schedule\LeaseStore;
use HiveSync\Workflow\Schedule\RunnerLease;
use PHPUnit\Framework\TestCase;

/**
 * The runner mutex. The old tick lock was a transient (get, then set):
 * two cron requests inside that window both "acquired" and advanced the
 * same job cursor in parallel — each creating the same `new` SKUs. The
 * lease is compare-and-set all the way down; these tests pin that a
 * second process can never hold it at the same time as the first.
 */
final class RunnerLeaseTest extends TestCase
{
    private MemoryLeaseStore $store;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->store = new MemoryLeaseStore();
        $this->now   = 1_000_000;
    }

    private function lease(): RunnerLease
    {
        return new RunnerLease( $this->store, fn(): int => $this->now );
    }

    public function testOnlyOneProcessHoldsTheLease(): void
    {
        $a = $this->lease();
        $b = $this->lease();
        $this->assertTrue( $a->acquire( 900 ) );
        $this->assertFalse( $b->acquire( 900 ) );
        $this->assertTrue( $a->isHeld() );
        $this->assertFalse( $b->isHeld() );
    }

    public function testReleaseLetsTheNextProcessIn(): void
    {
        $a = $this->lease();
        $b = $this->lease();
        $a->acquire( 900 );
        $a->release();
        $this->assertNull( $this->store->read() );
        $this->assertTrue( $b->acquire( 900 ) );
    }

    public function testDeadHolderIsTakenOverAfterItsTtl(): void
    {
        $dead = $this->lease();
        $dead->acquire( 900 );

        $this->now += 899;
        $this->assertFalse( $this->lease()->acquire( 900 ), 'still alive: must not be stolen' );

        $this->now += 2;
        $heir = $this->lease();
        $this->assertTrue( $heir->acquire( 900 ) );

        // The old holder wakes up: its renew fails (it must stop working)…
        $this->assertFalse( $dead->renew( 900 ) );
        $this->assertFalse( $dead->isHeld() );
        // …and its release can't drop the heir's lease.
        $dead->release();
        $this->assertNotNull( $this->store->read() );
        $this->assertTrue( $heir->renew( 900 ) );
    }

    public function testExpiredButUntakenLeaseIsStillRenewableByItsHolder(): void
    {
        $a = $this->lease();
        $a->acquire( 60 );
        $this->now += 120;
        $this->assertTrue( $a->renew( 60 ), 'nobody took it over: the holder keeps it' );
    }

    public function testTwoHeirsRacingForTheSameDeadLeaseOnlyOneWins(): void
    {
        $this->lease()->acquire( 10 );
        $this->now += 60;
        $this->store->raceHook = function (): void {
            // Between B's read and B's CAS, C takes the dead lease over.
            $this->store->raceHook = null;
            $this->assertTrue( $this->lease()->acquire( 900 ) );
        };
        $this->assertFalse( $this->lease()->acquire( 900 ) );
    }

    public function testRenewInTheSameSecondDoesNotReadAsLost(): void
    {
        $a = $this->lease();
        $a->acquire( 900 );
        $writes = $this->store->writes;
        $this->assertTrue( $a->renew( 900 ) );
        $this->assertSame( $writes, $this->store->writes, 'an identical value needs no write' );
        $this->now += 1;
        $this->assertTrue( $a->renew( 900 ) );
        $this->assertSame( $writes + 1, $this->store->writes );
    }

    public function testMalformedValueIsReclaimable(): void
    {
        $this->store->value = 'garbage-without-expiry';
        $this->assertTrue( $this->lease()->acquire( 900 ) );
    }

    public function testInspectReportsHolderAndExpiry(): void
    {
        $this->assertNull( $this->lease()->inspect() );
        $this->lease()->acquire( 900 );
        $info = $this->lease()->inspect();
        $this->assertSame( $this->now + 900, $info['expires'] );
        $this->assertFalse( $info['expired'] );
        $this->now += 901;
        $this->assertTrue( $this->lease()->inspect()['expired'] );
    }

    public function testForceReleaseStopsTheLiveHolderAtItsNextRenew(): void
    {
        $a = $this->lease();
        $a->acquire( 900 );
        $this->lease()->forceRelease();
        $this->now += 1;
        $this->assertFalse( $a->renew( 900 ) );
    }
}

/**
 * In-memory LeaseStore with the same CAS semantics as the SQL one.
 */
final class MemoryLeaseStore implements LeaseStore
{
    public ?string $value = null;
    public int $writes = 0;
    /** @var (callable(): void)|null fires once inside replace(), before the CAS */
    public $raceHook = null;

    public function read(): ?string
    {
        return $this->value;
    }

    public function insertIfAbsent( string $value ): bool
    {
        if ( $this->value !== null ) return false;
        $this->value = $value;
        $this->writes++;
        return true;
    }

    public function replace( string $expected, string $value ): bool
    {
        if ( $this->raceHook !== null ) ( $this->raceHook )();
        if ( $this->value !== $expected ) return false;
        $this->value = $value;
        $this->writes++;
        return true;
    }

    public function deleteIf( string $expected ): bool
    {
        if ( $this->value !== $expected ) return false;
        $this->value = null;
        $this->writes++;
        return true;
    }

    public function clear(): void
    {
        $this->value = null;
    }
}
