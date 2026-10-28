<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Machine-first sitemap eligibility policy.
 *
 * A published WordPress object is not automatically a search result candidate.
 * This policy removes service, account, transaction and protected surfaces before
 * they are materialized into the sitemap ledger. Public editorial/catalog content
 * remains eligible unless normal noindex/canonical rules say otherwise.
 */
final class SitemapEligibilityPolicy {
    public const VERSION = '2';

    /** @return array{eligible:bool,reason:string} */
    public function evaluatePost( \WP_Post $post, string $url ): array {
        if ( 'publish' !== (string) $post->post_status ) { return $this->deny( 'not_published' ); }
        if ( '' !== (string) $post->post_password ) { return $this->deny( 'password_protected' ); }

        $object = get_post_type_object( (string) $post->post_type );
        if ( ! $object || empty( $object->public ) || ! is_post_type_viewable( $object ) ) { return $this->deny( 'post_type_not_viewable' ); }

        $url = esc_url_raw( $url, array( 'http', 'https' ) );
        if ( '' === $url ) { return $this->deny( 'missing_public_url' ); }
        if ( $this->isReservedSystemUrl( $url ) ) { return $this->deny( 'reserved_system_route' ); }

        $postId = (int) $post->ID;
        if ( 'page' === (string) $post->post_type ) {
            $role = $this->configuredServicePageRole( $postId );
            if ( '' !== $role ) { return $this->deny( 'service_page:' . $role ); }
            if ( $this->containsServiceSurface( (string) $post->post_content ) ) { return $this->deny( 'service_page:content_signature' ); }
        }

        $decision = array( 'eligible' => true, 'reason' => 'public_search_content' );
        /**
         * Allows another application/plugin to exclude a known service surface.
         * Returning eligible=false is fail-closed for sitemap inclusion only and
         * does not change the page, permissions, robots meta, or runtime routing.
         *
         * @param array{eligible:bool,reason:string} $decision
         * @param \WP_Post $post
         * @param string $url
         */
        $filtered = apply_filters( 'yablonsky_seo_sitemap_post_eligibility_v1', $decision, $post, $url );
        if ( is_bool( $filtered ) ) {
            return $filtered ? $decision : $this->deny( 'extension_policy' );
        }
        if ( is_array( $filtered ) && array_key_exists( 'eligible', $filtered ) ) {
            return array(
                'eligible' => ! empty( $filtered['eligible'] ),
                'reason'   => sanitize_key( (string) ( $filtered['reason'] ?? ( ! empty( $filtered['eligible'] ) ? 'extension_allow' : 'extension_policy' ) ) ),
            );
        }
        return $decision;
    }

    private function isReservedSystemUrl( string $url ): bool {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $path = '/' . ltrim( rawurldecode( $path ), '/' );
        $normalized = strtolower( untrailingslashit( $path ) );
        if ( '' === $normalized ) { $normalized = '/'; }

        $exact = array(
            '/wp-login.php', '/wp-register.php', '/wp-cron.php', '/xmlrpc.php',
            '/wp-comments-post.php', '/wp-trackback.php', '/wp-mail.php',
            '/wp-signup.php', '/wp-activate.php', '/wp-links-opml.php',
        );
        if ( in_array( $normalized, $exact, true ) ) { return true; }

        $prefixes = array( '/wp-admin', '/wp-includes', '/wp-content' );
        $restPrefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
        if ( '' !== $restPrefix ) { $prefixes[] = '/' . strtolower( $restPrefix ); }
        foreach ( $prefixes as $prefix ) {
            if ( $normalized === $prefix || 0 === strpos( $normalized . '/', $prefix . '/' ) ) { return true; }
        }

        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        if ( '' !== $query ) {
            parse_str( $query, $args );
            if ( isset( $args['rest_route'] ) ) { return true; }
        }
        return false;
    }

    /** Returns a stable machine role for configured transactional/account pages. */
    private function configuredServicePageRole( int $postId ): string {
        $roles = array(
            'woocommerce_cart_page_id'      => 'woocommerce_cart',
            'woocommerce_checkout_page_id'  => 'woocommerce_checkout',
            'woocommerce_myaccount_page_id' => 'woocommerce_my_account',
        );
        foreach ( $roles as $option => $role ) {
            if ( $postId > 0 && $postId === (int) get_option( $option, 0 ) ) { return $role; }
        }
        return '';
    }

    /**
     * Detects only strong service-page signatures. It deliberately does not ban
     * generic forms, account links, legal pages, shops, search boxes, or public
     * dashboards because those may be legitimate public content.
     */
    private function containsServiceSurface( string $content ): bool {
        if ( '' === trim( $content ) ) { return false; }

        $shortcodes = array(
            'woocommerce_cart',
            'woocommerce_checkout',
            'woocommerce_my_account',
            'woocommerce_order_tracking',
        );
        foreach ( $shortcodes as $shortcode ) {
            if ( function_exists( 'has_shortcode' ) && has_shortcode( $content, $shortcode ) ) { return true; }
            if ( false !== stripos( $content, '[' . $shortcode ) ) { return true; }
        }

        $blocks = array(
            'woocommerce/cart',
            'woocommerce/checkout',
            'woocommerce/order-confirmation',
            'woocommerce/my-account',
            'woocommerce/order-tracking',
        );
        foreach ( $blocks as $block ) {
            if ( function_exists( 'has_block' ) && has_block( $block, $content ) ) { return true; }
            if ( false !== stripos( $content, '<!-- wp:' . $block ) ) { return true; }
        }
        return false;
    }

    /** @return array{eligible:false,reason:string} */
    private function deny( string $reason ): array {
        return array( 'eligible' => false, 'reason' => sanitize_key( str_replace( ':', '_', $reason ) ) );
    }
}
