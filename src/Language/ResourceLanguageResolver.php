<?php
namespace Yablonsky\PerformanceSEO\Language;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves the language of a specific public resource without using the
 * administrator UI locale as content-language authority.
 *
 * Multilingual owners may provide an explicit versioned language key through
 * yablonsky_seo_resource_language_v1. Existing alternate-language contracts are
 * also inspected by matching the resource canonical URL to an alternate URL.
 */
final class ResourceLanguageResolver {
    /** @var LanguageProviderInterface */
    private $provider;

    public function __construct( ?LanguageProviderInterface $provider = null ) {
        $this->provider = $provider instanceof LanguageProviderInterface ? $provider : new WordPressLanguageProvider();
    }

    /** @return array{language_key:string,source:string} */
    public function forPost( int $postId ): array {
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) {
            return array( 'language_key' => 'auto', 'source' => 'content-auto' );
        }
        $resourceType = 'product' === (string) $post->post_type ? 'product' : 'post';
        $url = (string) get_permalink( $postId );
        $authority = $this->resolveAuthority( $resourceType, $postId, $url );
        if ( null !== $authority ) { return $authority; }

        // AI/content analysis must never inherit the administrator or site UI locale.
        // When a multilingual authority is unavailable, infer only from this exact
        // resource's visible text and fail closed to auto when confidence is low.
        $visibleText = implode( "\n", array(
            (string) $post->post_title,
            (string) $post->post_excerpt,
            strip_shortcodes( (string) $post->post_content ),
        ) );
        $detected = ( new ContentLanguageDetector() )->detect( $visibleText );
        $language = $this->normalize( (string) ( $detected['language_key'] ?? '' ) );
        if ( '' !== $language && 'auto' !== $language && 'x-default' !== $language ) {
            return array( 'language_key' => $language, 'source' => 'content-detected' );
        }
        return array( 'language_key' => 'auto', 'source' => 'content-auto' );
    }

    /** @return array{language_key:string,source:string} */
    public function resolve( string $resourceType, int $objectId, string $url, bool $allowRequestLocale = true ): array {
        $resourceType = sanitize_key( $resourceType );
        $url = esc_url_raw( $url, array( 'http', 'https' ) );

        $authority = $this->resolveAuthority( $resourceType, $objectId, $url );
        if ( null !== $authority ) { return $authority; }

        /*
         * A normal single-language WordPress site already has a content-language
         * authority: Settings -> General -> Site Language, exposed by
         * get_bloginfo( 'language' ). Use that only when no multilingual contract
         * is attached. If a multilingual owner is present but cannot resolve this
         * resource, remain fail-closed with auto instead of guessing from admin UI.
         */
        if ( ! $this->hasMultilingualContract() ) {
            $language = $this->normalize( (string) get_bloginfo( 'language' ) );
            if ( '' !== $language && 'x-default' !== $language && 'auto' !== $language ) {
                return array( 'language_key' => $language, 'source' => 'site-default' );
            }
        }

        if ( $allowRequestLocale ) {
            $language = $this->normalize( $this->provider->currentLanguageKey() );
            if ( '' !== $language && 'x-default' !== $language ) {
                return array( 'language_key' => $language, 'source' => 'request-locale' );
            }
        }

        return array( 'language_key' => 'auto', 'source' => 'content-auto' );
    }

    /** @return array{language_key:string,source:string}|null */
    private function resolveAuthority( string $resourceType, int $objectId, string $url ): ?array {
        $explicit = apply_filters( 'yablonsky_seo_resource_language_v1', '', $resourceType, $objectId, $url );
        $explicit = apply_filters( 'yablonsky_performance_seo_resource_language_v1', $explicit, $resourceType, $objectId, $url );
        $language = $this->normalize( is_scalar( $explicit ) ? (string) $explicit : '' );
        if ( '' !== $language && 'x-default' !== $language && 'auto' !== $language ) {
            return array( 'language_key' => $language, 'source' => 'resource-authority' );
        }

        if ( '' !== $url ) {
            foreach ( $this->provider->alternates( $resourceType, $objectId ) as $alternate ) {
                if ( ! is_array( $alternate ) ) { continue; }
                $alternateUrl = isset( $alternate['url'] ) && is_scalar( $alternate['url'] ) ? (string) $alternate['url'] : '';
                if ( ! $this->sameUrl( $url, $alternateUrl ) ) { continue; }
                $language = $this->normalize( isset( $alternate['language_key'] ) && is_scalar( $alternate['language_key'] ) ? (string) $alternate['language_key'] : '' );
                if ( '' !== $language && 'x-default' !== $language && 'auto' !== $language ) {
                    return array( 'language_key' => $language, 'source' => 'alternate-family' );
                }
            }
        }
        return null;
    }

    /**
     * Treat region-only variants such as en and en-US as the same content
     * language while preserving fail-closed behavior for explicit script tags.
     */
    public function equivalent( string $left, string $right ): bool {
        $left = $this->normalize( $left );
        $right = $this->normalize( $right );
        if ( '' === $left || '' === $right || 'auto' === $left || 'auto' === $right || 'x-default' === $left || 'x-default' === $right ) { return false; }
        if ( $left === $right ) { return true; }
        $leftParts = explode( '-', $left );
        $rightParts = explode( '-', $right );
        if ( $leftParts[0] !== $rightParts[0] ) { return false; }
        if ( '' !== $this->scriptSubtag( $leftParts ) || '' !== $this->scriptSubtag( $rightParts ) ) { return false; }
        return true;
    }

    public function normalize( string $language ): string {
        $language = strtolower( str_replace( '_', '-', trim( $language ) ) );
        if ( 'auto' === $language || 'x-default' === $language ) { return $language; }
        return preg_match( '/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/', $language ) ? $language : '';
    }

    private function hasMultilingualContract(): bool {
        if ( ! function_exists( 'has_filter' ) ) { return false; }
        return false !== has_filter( 'yablonsky_seo_language_alternates_v1' )
            || false !== has_filter( 'yablonsky_performance_seo_language_alternates_v1' )
            || false !== has_filter( 'yablonsky_seo_resource_language_v1' )
            || false !== has_filter( 'yablonsky_performance_seo_resource_language_v1' );
    }

    /** @param array<int,string> $parts */
    private function scriptSubtag( array $parts ): string {
        foreach ( array_slice( $parts, 1 ) as $part ) {
            if ( 4 === strlen( $part ) && ctype_alpha( $part ) ) { return strtolower( $part ); }
        }
        return '';
    }

    private function sameUrl( string $left, string $right ): bool {
        $left = untrailingslashit( esc_url_raw( $left, array( 'http', 'https' ) ) );
        $right = untrailingslashit( esc_url_raw( $right, array( 'http', 'https' ) ) );
        return '' !== $left && '' !== $right && $left === $right;
    }
}
