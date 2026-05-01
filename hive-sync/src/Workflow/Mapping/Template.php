<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Mapping;

/**
 * Tiny placeholder substitution: `{path}` chunks are replaced with the
 * value at that dot-path in the row. Anything missing becomes the
 * empty string. Non-string scalars are stringified; arrays are
 * pipe-joined.
 *
 * Examples:
 *   render('Hi {name}',                      ['name' => 'Foo'])  → 'Hi Foo'
 *   render('{brand_name} {name}',            $row)               → 'Adidas Samba…'
 *   render('Sizes: {sizes.size_eu}',         $row)               → 'Sizes: 35.5|36|36 2/3'
 *   render('No braces',                      $row)               → 'No braces' (passthrough)
 *
 * Detection helper isTemplate() lets the mapping engine decide whether
 * a config value should be rendered as a template or treated as a
 * direct path.
 */
final class Template
{
    public static function isTemplate( string $value ): bool
    {
        return (bool) preg_match( '/\{[a-zA-Z0-9_.]+\}/', $value );
    }

    public static function render( string $template, array $row ): string
    {
        return (string) preg_replace_callback(
            '/\{([a-zA-Z0-9_.]+)\}/',
            static function ( array $m ) use ( $row ): string {
                $value = PathResolver::resolve( $row, $m[1] );
                return self::stringify( $value );
            },
            $template,
        );
    }

    private static function stringify( mixed $v ): string
    {
        if ( $v === null )  return '';
        if ( is_scalar( $v ) ) return (string) $v;
        if ( is_array( $v ) ) {
            $flat = [];
            foreach ( $v as $item ) {
                if ( is_scalar( $item ) ) $flat[] = (string) $item;
            }
            return implode( '|', $flat );
        }
        return '';
    }
}
