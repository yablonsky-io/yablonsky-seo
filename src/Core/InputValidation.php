<?php
namespace Yablonsky\PerformanceSEO\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Input-boundary validators for byte-sensitive request fields.
 *
 * Ordinary scalars are sanitized with sanitize_text_field() at the filter_input
 * boundary. The fields below must preserve their meaningful syntax (JSON,
 * CSV, redirect patterns, and encoded request URLs), so validation is paired
 * with a separate, context-specific parser/sanitizer at the call site.
 */
final class InputValidation {
    /** @param mixed $value */
    public static function json( $value ) {
        if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 1048576 ) { return null; }
        json_decode( $value, true );
        return JSON_ERROR_NONE === json_last_error() ? $value : null;
    }

    /** @param mixed $value */
    public static function csv( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 1048576 ) { return null; }
        return preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ? null : $value;
    }

    /** @param mixed $value */
    public static function urlOrPattern( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 4096 ) { return null; }
        return preg_match( '/[\x00-\x1F\x7F]/', $value ) ? null : $value;
    }

    /** @param mixed $value */
    public static function requestTarget( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 8192 ) { return null; }
        return preg_match( '/[\x00-\x1F\x7F]/', $value ) ? null : $value;
    }

    /**
     * Preserve meaningful newlines, HTML and percent-encoded bytes for values
     * whose field-specific sanitizer runs at the call site. Reject unexpected
     * control characters and unbounded request payloads before processing.
     * @param mixed $value
     */
    public static function structuredScalar( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 1048576 ) { return null; }
        return preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ? null : $value;
    }

    /** @param mixed $value */
    public static function richHtml( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 1048576 ) { return null; }
        return wp_kses_post( $value );
    }
}
