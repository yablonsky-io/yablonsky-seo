<?php
namespace Yablonsky\PerformanceSEO\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compatibility reads for historical SEO metadata.
 *
 * Canonical writes use _yablseo_* keys. Existing _yps_* rows are never deleted
 * or mutated by this reader. They remain available as an on-demand fallback
 * until the canonical key has been set, including the intentional empty value.
 * This avoids an unbounded activation-time rewrite of large WordPress databases.
 */
final class LegacyMetaBridge {
    /** @var bool */
    private static $inside = false;

    /** Return a historical key for read-only compatibility queries. */
    public static function legacyKeyFor( string $canonicalKey ): string {
        if ( 0 !== strpos( $canonicalKey, '_yablseo_' ) ) { return ''; }
        return '_yps_' . substr( $canonicalKey, strlen( '_yablseo_' ) );
    }

    public static function register(): void {
        add_filter( 'get_post_metadata', array( __CLASS__, 'fallback' ), 10, 5 );
        add_filter( 'get_term_metadata', array( __CLASS__, 'fallback' ), 10, 5 );
        add_filter( 'get_user_metadata', array( __CLASS__, 'fallback' ), 10, 5 );
        // Clearing a canonical value must never revive the historical value.
        add_filter( 'delete_post_metadata', array( __CLASS__, 'preserveExplicitClear' ), 10, 5 );
        add_filter( 'delete_term_metadata', array( __CLASS__, 'preserveExplicitClear' ), 10, 5 );
        add_filter( 'delete_user_metadata', array( __CLASS__, 'preserveExplicitClear' ), 10, 5 );
    }

    /**
     * @param mixed $value Existing metadata override from another filter.
     * @param int $objectId Post, term or user ID.
     * @param string $metaKey Canonical metadata key.
     * @param bool $single Return the WordPress single-value format.
     * @param string $metaType WordPress metadata type.
     * @return mixed Null permits WordPress's ordinary metadata lookup.
     */
    public static function fallback( $value, $objectId, $metaKey, $single, $metaType ) {
        if ( null !== $value || self::$inside || ! is_string( $metaKey ) || 0 !== strpos( $metaKey, '_yablseo_' ) ) {
            return $value;
        }
        if ( ! in_array( $metaType, array( 'post', 'term', 'user' ), true ) || (int) $objectId <= 0 ) {
            return $value;
        }

        self::$inside = true;
        try {
            // A saved canonical value wins even if it is deliberately empty.
            if ( metadata_exists( $metaType, (int) $objectId, $metaKey ) ) {
                return null;
            }
            $legacyKey = '_yps_' . substr( $metaKey, strlen( '_yablseo_' ) );
            if ( ! metadata_exists( $metaType, (int) $objectId, $legacyKey ) ) {
                return null;
            }
            return get_metadata( $metaType, (int) $objectId, $legacyKey, (bool) $single );
        } finally {
            self::$inside = false;
        }
    }
    /**
     * A deliberate deletion overrides legacy fallback without erasing the
     * historical row. Only canonical plugin keys with existing old data are
     * handled. A blank canonical value acts as an explicit-clear marker.
     *
     * @param null|bool $result Existing override from another filter.
     * @param int $objectId Metadata object ID.
     * @param string $metaKey Canonical metadata key.
     * @param mixed $metaValue Optional specific match value.
     * @param bool $deleteAll Whether all objects are targeted.
     * @return null|bool Null to use ordinary WordPress deletion.
     */
    public static function preserveExplicitClear( $result, $objectId, $metaKey, $metaValue, $deleteAll ) {
        if ( null !== $result || self::$inside || $deleteAll || ! is_string( $metaKey ) || 0 !== strpos( $metaKey, '_yablseo_' ) ) {
            return $result;
        }
        if ( '' !== $metaValue && null !== $metaValue && false !== $metaValue ) {
            return $result;
        }
        $type = (string) current_filter();
        if ( ! preg_match( '/^delete_(post|user|term)_metadata$/', $type, $parts ) || (int) $objectId <= 0 ) {
            return $result;
        }
        $type = $parts[1];
        $legacyKey = '_yps_' . substr( $metaKey, strlen( '_yablseo_' ) );
        self::$inside = true;
        try {
            if ( ! metadata_exists( $type, (int) $objectId, $legacyKey ) ) {
                return null;
            }
            // Preserve rollback evidence while honoring an explicit user clear.
            if ( metadata_exists( $type, (int) $objectId, $metaKey ) ) {
                if ( '' === get_metadata( $type, (int) $objectId, $metaKey, true ) ) {
                    return true;
                }
            }
            update_metadata( $type, (int) $objectId, $metaKey, '' );
            return metadata_exists( $type, (int) $objectId, $metaKey );
        } finally {
            self::$inside = false;
        }
    }

}
