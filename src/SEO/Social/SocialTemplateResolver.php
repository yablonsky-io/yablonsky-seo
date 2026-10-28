<?php
namespace Yablonsky\PerformanceSEO\SEO\Social;

use Yablonsky\PerformanceSEO\Resource\ResourceIdentity;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Resolves reusable social templates by resource family.
 *
 * Contract: per-resource metadata always wins. These templates are only defaults
 * for singular content, post-type archives, taxonomy archives and common special
 * archives. No remote request is involved.
 */
final class SocialTemplateResolver {
    /** @var array<int,string> */
    private const FIELDS = array( 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image' );

    /** @param array<string,mixed> $settings */
    public function value( ResourceIdentity $resource, string $field, array $settings ): string {
        if ( ! in_array( $field, self::FIELDS, true ) ) { return ''; }
        $templates = isset( $settings['social_templates'] ) && is_array( $settings['social_templates'] ) ? $settings['social_templates'] : array();
        $scope = $this->scope( $resource );
        if ( '' === $scope['group'] || '' === $scope['key'] ) { return ''; }
        $row = isset( $templates[ $scope['group'] ][ $scope['key'] ] ) && is_array( $templates[ $scope['group'] ][ $scope['key'] ] ) ? $templates[ $scope['group'] ][ $scope['key'] ] : array();
        $value = isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) ? trim( (string) $row[ $field ] ) : '';
        $filtered = apply_filters( 'yablonsky_seo_social_template_value_v1', $value, $field, $scope['group'], $scope['key'], $resource );
        return is_scalar( $filtered ) ? trim( (string) $filtered ) : $value;
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    public static function sanitize( array $raw ): array {
        $out = array();
        foreach ( array( 'singular', 'archive', 'taxonomy', 'special' ) as $group ) {
            if ( empty( $raw[ $group ] ) || ! is_array( $raw[ $group ] ) ) { continue; }
            foreach ( $raw[ $group ] as $rawKey => $rawRow ) {
                $key = sanitize_key( (string) $rawKey );
                if ( '' === $key || ! is_array( $rawRow ) ) { continue; }
                $row = array();
                foreach ( self::FIELDS as $field ) {
                    if ( ! array_key_exists( $field, $rawRow ) || ! is_scalar( $rawRow[ $field ] ) ) { continue; }
                    $value = (string) $rawRow[ $field ];
                    if ( in_array( $field, array( 'og_image', 'twitter_image' ), true ) ) {
                        $row[ $field ] = esc_url_raw( $value, array( 'http', 'https' ) );
                    } else {
                        $row[ $field ] = sanitize_text_field( $value );
                    }
                }
                if ( ! empty( array_filter( $row, static function ( $value ): bool { return '' !== trim( (string) $value ); } ) ) ) {
                    $out[ $group ][ $key ] = $row;
                }
            }
        }
        return $out;
    }

    /** @return array{group:string,key:string} */
    private function scope( ResourceIdentity $resource ): array {
        if ( in_array( $resource->type(), array( 'post', 'product' ), true ) ) {
            return array( 'group' => 'singular', 'key' => sanitize_key( $resource->subtype() ) );
        }
        if ( 'posts_page' === $resource->type() ) {
            return array( 'group' => 'special', 'key' => 'posts_page' );
        }
        if ( 'post_type_archive' === $resource->type() ) {
            return array( 'group' => 'archive', 'key' => sanitize_key( $resource->subtype() ) );
        }
        if ( 'term' === $resource->type() ) {
            return array( 'group' => 'taxonomy', 'key' => sanitize_key( $resource->subtype() ) );
        }
        if ( 'author_archive' === $resource->type() ) {
            return array( 'group' => 'special', 'key' => 'author' );
        }
        if ( 'date_archive' === $resource->type() ) {
            return array( 'group' => 'special', 'key' => 'date' );
        }
        return array( 'group' => '', 'key' => '' );
    }
}
