<?php
namespace Yablonsky\PerformanceSEO\AI\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Product invariant for Settings authoring assistance.
 *
 * Manual authoring surfaces are registered once here so UI rendering, AJAX
 * validation and release coverage evidence share the same authority. Fields
 * that are credentials, verification tokens, external URLs, booleans or
 * numeric policy controls are intentionally outside this registry.
 */
final class SettingsAuthoringRegistry {
    /** @return array<int,string> */
    public static function textFields(): array {
        return array(
            'site_display_name',
            'site_description',
            'owner_name',
            'owner_job_title',
            'owner_description',
            'owner_knows_about',
            'home_title',
            'home_description',
            'home_og_title',
            'home_og_description',
            'home_twitter_title',
            'home_twitter_description',
            'search_title_template',
            'error_404_title_template',
            'date_archive_title_template',
            'post_format_archive_title_template',
            'rss_before_content',
            'rss_after_content',
        );
    }

    /** @return array<int,string> */
    public static function imageFields(): array {
        return array(
            'owner_logo_url',
            'owner_image_url',
            'default_social_image',
            'home_search_image',
            'home_og_image',
            'home_twitter_image',
        );
    }

    public static function supportsText( string $field ): bool {
        return in_array( sanitize_key( $field ), self::textFields(), true );
    }

    public static function supportsImage( string $field ): bool {
        return in_array( sanitize_key( $field ), self::imageFields(), true );
    }

    /** @return array<int,string> */
    public static function socialTemplateTextSlots(): array {
        return array( 'og_title', 'twitter_title', 'og_description', 'twitter_description' );
    }

    /** @return array<int,string> */
    public static function socialTemplateImageSlots(): array {
        return array( 'og_image', 'twitter_image' );
    }

    public static function supportsSocialTemplateText( string $slot ): bool {
        return in_array( sanitize_key( $slot ), self::socialTemplateTextSlots(), true );
    }

    public static function supportsSocialTemplateImage( string $slot ): bool {
        return in_array( sanitize_key( $slot ), self::socialTemplateImageSlots(), true );
    }

    /**
     * Resolve a live WordPress social-template family and prevent arbitrary
     * caller-provided keys from becoming AI prompt authority.
     *
     * @return array{group:string,key:string,label:string}|null
     */
    public static function socialTemplateFamily( string $group, string $key ): ?array {
        $group = sanitize_key( $group );
        $key = sanitize_key( $key );
        if ( '' === $group || '' === $key ) { return null; }

        if ( 'singular' === $group ) {
            $object = get_post_type_object( $key );
            if ( ! $object || empty( $object->public ) || 'attachment' === $key ) { return null; }
            $label = isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : $key;
            return array( 'group' => $group, 'key' => $key, 'label' => $label );
        }
        if ( 'archive' === $group ) {
            $object = get_post_type_object( $key );
            if ( ! $object || empty( $object->public ) || empty( $object->has_archive ) || 'attachment' === $key ) { return null; }
            $label = isset( $object->labels->name ) ? (string) $object->labels->name : $key;
            return array( 'group' => $group, 'key' => $key, 'label' => $label );
        }
        if ( 'taxonomy' === $group ) {
            $object = get_taxonomy( $key );
            if ( ! $object || empty( $object->public ) ) { return null; }
            $label = isset( $object->labels->name ) ? (string) $object->labels->name : $key;
            return array( 'group' => $group, 'key' => $key, 'label' => $label );
        }
        if ( 'special' === $group ) {
            $labels = array(
                'posts_page' => __( 'Posts page', 'yablonsky-seo' ),
                'author' => __( 'Author archives', 'yablonsky-seo' ),
                'date' => __( 'Date archives', 'yablonsky-seo' ),
            );
            if ( ! isset( $labels[ $key ] ) ) { return null; }
            return array( 'group' => $group, 'key' => $key, 'label' => (string) $labels[ $key ] );
        }
        return null;
    }
}
