<?php
namespace Yablonsky\PerformanceSEO\Migration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Decodes imported provider values without instantiating PHP objects.
 *
 * Migration only needs scalar/array metadata. WordPress provider tables may
 * contain serialized arrays, but source-controlled object graphs are never an
 * accepted migration contract because object construction can trigger magic
 * methods in unrelated loaded classes.
 */
final class SafeSerializedValue {
    private const MAX_IMPORT_BYTES = 4194304;
    private const MAX_NESTING_DEPTH = 32;
    private const MAX_VISITED_VALUES = 10000;
    /** @param mixed $value @return mixed */
    public static function decode( $value ) {
        if ( ! is_string( $value ) || ! is_serialized( $value ) ) { return $value; }
        $trimmed = trim( $value );
        if ( strlen( $trimmed ) > self::MAX_IMPORT_BYTES ) { return ''; }
        $decoded = @unserialize( $trimmed, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Import-only decoder explicitly disables classes.
        if ( false === $decoded && 'b:0;' !== $trimmed ) { return $value; }
        $visited = 0;
        return self::containsObject( $decoded, 0, $visited ) ? '' : $decoded;
    }

    /** @param mixed $value */
    private static function containsObject( $value, int $depth, int &$visited ): bool {
        if ( ++$visited > self::MAX_VISITED_VALUES ) { return true; }
        if ( is_object( $value ) ) { return true; }
        if ( ! is_array( $value ) ) { return false; }
        if ( $depth >= self::MAX_NESTING_DEPTH ) { return true; }
        foreach ( $value as $item ) {
            if ( self::containsObject( $item, $depth + 1, $visited ) ) { return true; }
        }
        return false;
    }
}
