<?php
namespace Yablonsky\PerformanceSEO\Migration\Yoast;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Canonical author/profile fields written by Yoast to usermeta.
 */
final class YoastUserFieldMap {
    /** @return array<string,string> */
    public static function fields(): array {
        return array(
            'wpseo_title'          => '_yps_author_seo_title',
            'wpseo_metadesc'       => '_yps_author_meta_description',
            'wpseo_noindex_author' => '_yps_author_robots_noindex',
        );
    }

    /** @return array<int,string> */
    public static function socialContactFields(): array {
        return array(
            'facebook',
            'instagram',
            'linkedin',
            'myspace',
            'pinterest',
            'soundcloud',
            'tumblr',
            'twitter',
            'youtube',
            'wikipedia',
            'mastodon',
        );
    }

    /** @return array<int,string> */
    public static function ignoredDerived(): array {
        return array(
            '_yoast_wpseo_profile_updated',
            // Yoast 26.8 Introductions_Seen_Repository stores admin onboarding/UI
            // state here (which introductions a user has seen). It is not public
            // SEO/profile output and must not become YPS author metadata.
            '_yoast_wpseo_introductions',
        );
    }

    public static function isIgnoredDerived( string $sourceKey ): bool {
        return in_array( $sourceKey, self::ignoredDerived(), true );
    }

    public static function targetForKey( string $sourceKey ): string {
        $fields = self::fields();
        if ( isset( $fields[ $sourceKey ] ) ) {
            return $fields[ $sourceKey ];
        }
        if ( in_array( $sourceKey, self::socialContactFields(), true ) ) {
            return '_yps_author_social_' . sanitize_key( $sourceKey );
        }
        return '';
    }
}
