<?php
/**
 * Response Parser — parse JSON, XML, CSV con auto-detect.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rileva il formato della risposta dal content-type e dal body.
 *
 * @param string $content_type Header Content-Type.
 * @param string $body         Body della risposta.
 * @return string 'json' | 'xml' | 'csv' | 'text'
 */
function rp_rc_detect_content_type( string $content_type, string $body ): string {

    $ct = strtolower( $content_type );

    if ( str_contains( $ct, 'json' ) ) return 'json';
    if ( str_contains( $ct, 'xml' ) )  return 'xml';
    if ( str_contains( $ct, 'csv' ) )  return 'csv';

    // Auto-detect dal body
    $trimmed = ltrim( $body );
    if ( str_starts_with( $trimmed, '{' ) || str_starts_with( $trimmed, '[' ) ) return 'json';
    if ( str_starts_with( $trimmed, '<' ) ) return 'xml';

    return 'text';
}

/**
 * Parsa il body della risposta nel formato rilevato.
 *
 * @param string $body   Body raw.
 * @param string $format 'json' | 'xml' | 'csv' | 'text'
 * @return array|WP_Error Dati parsati.
 */
function rp_rc_parse_response( string $body, string $format ): array|WP_Error {

    return match ( $format ) {
        'json' => rp_rc_parse_json( $body ),
        'xml'  => rp_rc_parse_xml( $body ),
        'csv'  => rp_rc_parse_csv( $body ),
        default => [ 'raw' => $body ],
    };
}

/**
 * Parsa JSON.
 *
 * @param string $body
 * @return array|WP_Error
 */
function rp_rc_parse_json( string $body ): array|WP_Error {

    $data = json_decode( $body, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'json_error', 'JSON parse error: ' . json_last_error_msg() );
    }
    return $data;
}

/**
 * Parsa XML in array associativo.
 *
 * @param string $body
 * @return array|WP_Error
 */
function rp_rc_parse_xml( string $body ): array|WP_Error {

    libxml_use_internal_errors( true );
    $xml = simplexml_load_string( $body );
    if ( ! $xml ) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        return new WP_Error( 'xml_error', 'XML parse error: ' . ( $errors[0]->message ?? 'unknown' ) );
    }
    return json_decode( json_encode( $xml ), true );
}

/**
 * Parsa CSV con auto-detect del separatore.
 *
 * @param string $body
 * @return array|WP_Error
 */
function rp_rc_parse_csv( string $body ): array|WP_Error {

    $lines = explode( "\n", trim( $body ) );
    if ( count( $lines ) < 2 ) {
        return new WP_Error( 'csv_error', 'CSV troppo corto (serve almeno header + 1 riga).' );
    }

    // Auto-detect separatore
    $sep    = ',';
    $sample = implode( "\n", array_slice( $lines, 0, 3 ) );
    $counts = [ ',' => substr_count( $sample, ',' ), ';' => substr_count( $sample, ';' ), "\t" => substr_count( $sample, "\t" ) ];
    arsort( $counts );
    $sep = array_key_first( $counts );

    $headers = str_getcsv( array_shift( $lines ), $sep );
    $result  = [];

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( ! $line ) continue;
        $values  = str_getcsv( $line, $sep );
        $row     = [];
        foreach ( $headers as $i => $h ) {
            $row[ trim( $h ) ] = $values[ $i ] ?? '';
        }
        $result[] = $row;
    }

    return $result;
}

/**
 * Appiattisce un array annidato con notazione dot.
 *
 * @param array  $data
 * @param string $prefix
 * @return array
 */
function rp_rc_flatten_response( array $data, string $prefix = '' ): array {

    $result = [];
    foreach ( $data as $key => $value ) {
        $full_key = $prefix ? "{$prefix}.{$key}" : $key;
        if ( is_array( $value ) && ! isset( $value[0] ) ) {
            $result = array_merge( $result, rp_rc_flatten_response( $value, $full_key ) );
        } else {
            $result[ $full_key ] = $value;
        }
    }
    return $result;
}
