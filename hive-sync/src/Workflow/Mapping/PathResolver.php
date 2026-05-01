<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Mapping;

/**
 * Pure dot-path navigator for nested arrays.
 *
 *   resolve($row, 'sku')              → $row['sku']
 *   resolve($row, 'sizes.size_eu')    when $row['sizes'] is an array of
 *                                      objects → array of each size_eu
 *   resolve($row, 'a.b.c')            traverses scalars + indexed arrays
 *                                      uniformly; returns null on missing.
 *
 * No support for wildcards, slicing, JSONPath syntax — keep it small.
 */
final class PathResolver
{
    /**
     * @return mixed scalar | array | null
     */
    public static function resolve( array $row, string $path ): mixed
    {
        if ( $path === '' ) return null;
        return self::walk( $row, explode( '.', $path ) );
    }

    /**
     * @param mixed     $value
     * @param string[]  $segments
     */
    private static function walk( mixed $value, array $segments ): mixed
    {
        if ( ! $segments ) return $value;
        $head = array_shift( $segments );

        if ( ! is_array( $value ) ) return null;

        // Associative array: direct key lookup.
        if ( array_key_exists( $head, $value ) && ! self::isList( $value ) ) {
            return self::walk( $value[ $head ], $segments );
        }

        // Indexed list: fan out — apply remaining path to each element,
        // collecting non-null results. Enables `sizes.size_eu` style.
        if ( self::isList( $value ) ) {
            $out = [];
            foreach ( $value as $item ) {
                $r = self::walk( $item, array_merge( [ $head ], $segments ) );
                if ( is_array( $r ) ) {
                    foreach ( $r as $v ) if ( $v !== null && $v !== '' ) $out[] = $v;
                } elseif ( $r !== null && $r !== '' ) {
                    $out[] = $r;
                }
            }
            return $out;
        }

        return null;
    }

    /**
     * Cheap list detection that avoids array_is_list's strict ordinal
     * keys requirement (some PHP-decoded JSON has gaps).
     */
    private static function isList( array $arr ): bool
    {
        if ( $arr === [] ) return true;
        return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
    }
}
