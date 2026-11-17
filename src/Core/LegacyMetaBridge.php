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

    public static function register(): void {
        add_filter( 'get_post_metadata', array( __CLASS__, 'fallback' ), 10, 5 );
        add_filter( 'get_term_metadata', array( __CLASS__, 'fallback' ), 10, 5 );
        add_filter( 'get_user_metadata', array( __CLASS__, 'fallback' ), 10, 5 );
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
}
