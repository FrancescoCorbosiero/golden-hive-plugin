<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Core\Source;

use GH\Core\Source\AbstractSource;
use GH\Core\Source\Context;
use GH\Core\Source\Diff;
use GH\Core\Source\FeedItem;
use GH\Core\Source\FetchRequest;
use GH\Core\Source\FetchResult;
use GH\Core\Source\MaterializeResult;
use GH\Core\Source\SourceCapabilities;
use PHPUnit\Framework\TestCase;

final class ConfiguredSource extends AbstractSource
{
    public function id(): string { return 'cfg'; }
    public function label(): string { return 'Cfg'; }
    public function capabilities(): SourceCapabilities { return new SourceCapabilities(); }
    public function configSchema(): array
    {
        return [
            'url'    => ['type' => 'url',    'required' => true,  'max' => 100],
            'token'  => ['type' => 'secret', 'required' => true,  'max' => 50],
            'format' => ['type' => 'enum',   'required' => false, 'options' => ['flat', 'hierarchical']],
            'limit'  => ['type' => 'int',    'required' => false],
        ];
    }
    public function fetch(FetchRequest $r, Context $c): FetchResult { return new FetchResult([]); }
    public function diff(array $items, Context $c): Diff { return new Diff(); }
    public function materialize(FeedItem $i, Context $c): MaterializeResult
    {
        return MaterializeResult::skipped(null, 'test');
    }

    public function publicValidateConfig(array $config): array
    {
        return $this->validateConfig($config);
    }
}

final class AbstractSourceConfigTest extends TestCase
{
    private ConfiguredSource $src;

    protected function setUp(): void
    {
        $this->src = new ConfiguredSource();
    }

    public function test_accepts_valid_config(): void
    {
        $r = $this->src->publicValidateConfig([
            'url'    => 'https://api.example.com/feed',
            'token'  => 'secret-abc',
            'format' => 'flat',
            'limit'  => '500',
        ]);

        self::assertTrue($r['ok']);
        self::assertSame('https://api.example.com/feed', $r['config']['url']);
        self::assertSame('secret-abc', $r['config']['token']);
        self::assertSame('flat', $r['config']['format']);
        self::assertSame(500, $r['config']['limit'], 'int field is coerced');
    }

    public function test_flags_missing_required_fields(): void
    {
        $r = $this->src->publicValidateConfig(['format' => 'flat']);
        self::assertFalse($r['ok']);
        self::assertSame('required', $r['errors']['url']);
        self::assertSame('required', $r['errors']['token']);
    }

    public function test_rejects_invalid_url(): void
    {
        $r = $this->src->publicValidateConfig([
            'url'   => 'ftp://nope',
            'token' => 'x',
        ]);
        self::assertFalse($r['ok']);
        self::assertSame('invalid_url', $r['errors']['url']);
    }

    public function test_rejects_invalid_enum_option(): void
    {
        $r = $this->src->publicValidateConfig([
            'url'    => 'https://x.test',
            'token'  => 'x',
            'format' => 'wrong',
        ]);
        self::assertFalse($r['ok']);
        self::assertSame('invalid_option', $r['errors']['format']);
    }

    public function test_enforces_max_length(): void
    {
        $r = $this->src->publicValidateConfig([
            'url'   => 'https://x.test',
            'token' => str_repeat('a', 51),
        ]);
        self::assertFalse($r['ok']);
        self::assertSame('too_long', $r['errors']['token']);
    }
}
