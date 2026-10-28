<?php
namespace Yablonsky\PerformanceSEO\SEO\Crawl;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CrawlOptimizationController {
    /** @var array<string,mixed> */ private $settings = array();

    public function register(): void {
        $raw = get_option( 'yps_public_settings', array() );
        $this->settings = is_array( $raw ) ? $raw : array();

        if ( $this->enabled( 'crawl_remove_shortlinks' ) ) {
            remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
            remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
        }
        if ( $this->enabled( 'crawl_remove_rest_links' ) ) {
            remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
            remove_action( 'template_redirect', 'rest_output_link_header', 11 );
        }
        if ( $this->enabled( 'crawl_remove_rsd_wlw' ) ) {
            remove_action( 'wp_head', 'rsd_link' );
            remove_action( 'wp_head', 'wlwmanifest_link' );
        }
        if ( $this->enabled( 'crawl_remove_oembed_links' ) ) {
            remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
            remove_action( 'wp_head', 'wp_oembed_add_host_js' );
        }
        if ( $this->enabled( 'crawl_remove_generator' ) ) {
            remove_action( 'wp_head', 'wp_generator' );
            add_filter( 'the_generator', '__return_empty_string' );
        }
        if ( $this->enabled( 'crawl_remove_pingback_header' ) ) {
            add_filter( 'wp_headers', array( $this, 'removePingbackHeader' ) );
        }
        if ( $this->enabled( 'crawl_remove_powered_by_header' ) ) {
            add_action( 'send_headers', array( $this, 'removePoweredByHeader' ), 99 );
        }
        if ( $this->enabled( 'crawl_remove_emoji' ) ) {
            remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
            remove_action( 'wp_print_styles', 'print_emoji_styles' );
            remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
            add_filter( 'emoji_svg_url', '__return_false' );
        }

        if ( $this->enabled( 'crawl_disable_global_feeds' ) ) { add_filter( 'feed_links_show_posts_feed', '__return_false' ); }
        if ( $this->enabled( 'crawl_disable_comments_feeds' ) ) { add_filter( 'feed_links_show_comments_feed', '__return_false' ); }
        if ( $this->enabled( 'crawl_disable_post_comments_feeds' ) ) { add_filter( 'feed_links_extra_show_post_comments_feed', '__return_false' ); }
        if ( $this->enabled( 'crawl_disable_author_feeds' ) ) { add_filter( 'feed_links_extra_show_author_feed', '__return_false' ); }
        if ( $this->enabled( 'crawl_disable_post_type_feeds' ) ) { add_filter( 'feed_links_extra_show_post_type_archive_feed', '__return_false' ); }
        if ( $this->enabled( 'crawl_disable_category_feeds' ) ) { add_filter( 'feed_links_extra_show_category_feed', '__return_false' ); }
        if ( $this->enabled( 'crawl_disable_tag_feeds' ) ) { add_filter( 'feed_links_extra_show_tag_feed', '__return_false' ); }
        if ( $this->enabled( 'crawl_disable_taxonomy_feeds' ) ) { add_filter( 'feed_links_extra_show_tax_feed', '__return_false' ); }
        if ( $this->enabled( 'crawl_disable_search_feeds' ) ) { add_filter( 'feed_links_extra_show_search_feed', '__return_false' ); }

        add_action( 'template_redirect', array( $this, 'handleFeedAndSearchCleanup' ), 0 );
        add_filter( 'wp_robots', array( $this, 'searchRobots' ) );
        add_filter( 'robots_txt', array( $this, 'robotsTxt' ), 40, 2 );
    }

    /** @param array<string,string> $headers @return array<string,string> */
    public function removePingbackHeader( array $headers ): array {
        foreach ( array_keys( $headers ) as $name ) {
            if ( 'x-pingback' === strtolower( (string) $name ) ) { unset( $headers[ $name ] ); }
        }
        return $headers;
    }

    public function removePoweredByHeader(): void {
        if ( function_exists( 'header_remove' ) ) { header_remove( 'X-Powered-By' ); }
    }

    public function handleFeedAndSearchCleanup(): void {
        if ( is_feed() && $this->feedShouldBeRemoved() ) {
            status_header( 410 );
            nocache_headers();
            $charset = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) get_option( 'blog_charset', 'UTF-8' ) );
            header( 'Content-Type: text/plain; charset=' . ( '' !== $charset ? $charset : 'UTF-8' ) );
            echo esc_html__( 'This feed is intentionally disabled.', 'yablonsky-seo' );
            exit;
        }
        if ( $this->enabled( 'crawl_clean_tracking_params' ) ) { $this->maybeCleanTrackingParameters(); }
        if ( ! is_search() ) { return; }
        $query = trim( (string) get_search_query( false ) );
        $max = min( 200, max( 20, (int) ( $this->settings['crawl_search_max_chars'] ?? 80 ) ) );
        if ( '' !== $query && strlen( $query ) > $max ) { $this->rejectSearch(); }
        if ( $this->enabled( 'crawl_search_filter_spam' ) && preg_match( '/(?:TALK\s*:|QQ\s*:|[：（）〖〗［］])/iu', $query ) ) { $this->rejectSearch(); }
        if ( $this->enabled( 'crawl_search_filter_special' ) && '' !== $query && ! preg_match( '/[\p{L}\p{N}]/u', $query ) ) { $this->rejectSearch(); }
        if ( $this->enabled( 'crawl_search_redirect_pretty' ) ) {
            $requestUriRaw = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW );
            $requestUri = is_string( $requestUriRaw ) ? sanitize_text_field( wp_unslash( $requestUriRaw ) ) : '';
            $requestPath = trim( (string) wp_parse_url( $requestUri, PHP_URL_PATH ), '/' );
            if ( 0 === strpos( $requestPath . '/', 'search/' ) ) {
                $target = add_query_arg( 's', $query, home_url( '/' ) );
                wp_safe_redirect( $target, 301, 'Yablonsky SEO' );
                exit;
            }
        }
    }

    /** @param array<string,bool> $robots @return array<string,bool> */
    public function searchRobots( array $robots ): array {
        if ( is_search() && $this->enabled( 'crawl_search_noindex' ) ) {
            $robots['noindex'] = true;
            unset( $robots['index'] );
        }
        return $robots;
    }

    public function robotsTxt( string $output, bool $public ): string {
        unset( $public );
        $lines = array();
        if ( $this->enabled( 'crawl_disallow_wp_json' ) ) {
            $lines[] = 'Disallow: /wp-json/';
            $lines[] = 'Disallow: /?rest_route=';
        }
        if ( $this->enabled( 'crawl_search_noindex' ) ) {
            $lines[] = 'Disallow: /search/';
            $lines[] = 'Disallow: /?s=';
        }
        if ( empty( $lines ) ) { return $output; }
        $block = "\n# Yablonsky SEO crawl optimization\nUser-agent: *\n" . implode( "\n", array_unique( $lines ) ) . "\n";
        return rtrim( $output ) . "\n" . $block;
    }

    private function maybeCleanTrackingParameters(): void {
        $methodRaw = filter_input( INPUT_SERVER, 'REQUEST_METHOD', FILTER_UNSAFE_RAW );
        $method = is_string( $methodRaw ) ? strtoupper( sanitize_key( $methodRaw ) ) : 'GET';
        if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || is_feed() ) { return; }
        $queryRaw = filter_input( INPUT_SERVER, 'QUERY_STRING', FILTER_UNSAFE_RAW );
        if ( ! is_string( $queryRaw ) || '' === $queryRaw ) { return; }
        $args = array();
        parse_str( $queryRaw, $args );
        if ( ! is_array( $args ) || empty( $args ) ) { return; }
        $changed = false;
        foreach ( array_keys( $args ) as $key ) {
            $clean = strtolower( sanitize_key( (string) $key ) );
            if ( 0 === strpos( $clean, 'utm_' ) || in_array( $clean, array( 'gclid','dclid','fbclid','msclkid','mc_cid','mc_eid','_ga','gbraid','wbraid' ), true ) ) {
                unset( $args[ $key ] );
                $changed = true;
            }
        }
        if ( ! $changed ) { return; }
        $requestUriRaw = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW );
        $requestUri = is_string( $requestUriRaw ) ? wp_unslash( $requestUriRaw ) : '/';
        $path = (string) wp_parse_url( $requestUri, PHP_URL_PATH );
        if ( '' === $path ) { $path = '/'; }
        $target = home_url( $path );
        if ( ! empty( $args ) ) { $target = add_query_arg( map_deep( $args, 'sanitize_text_field' ), $target ); }
        wp_safe_redirect( $target, 301, 'Yablonsky SEO' );
        exit;
    }

    private function feedShouldBeRemoved(): bool {
        $feed = sanitize_key( (string) get_query_var( 'feed' ) );
        if ( $this->enabled( 'crawl_disable_atom_rdf_feeds' ) && in_array( $feed, array( 'atom', 'rdf' ), true ) ) { return true; }
        if ( is_comment_feed() ) {
            if ( is_singular() && $this->enabled( 'crawl_disable_post_comments_feeds' ) ) { return true; }
            return $this->enabled( 'crawl_disable_comments_feeds' );
        }
        if ( is_author() && $this->enabled( 'crawl_disable_author_feeds' ) ) { return true; }
        if ( is_category() && $this->enabled( 'crawl_disable_category_feeds' ) ) { return true; }
        if ( is_tag() && $this->enabled( 'crawl_disable_tag_feeds' ) ) { return true; }
        if ( is_tax() && $this->enabled( 'crawl_disable_taxonomy_feeds' ) ) { return true; }
        if ( is_search() && $this->enabled( 'crawl_disable_search_feeds' ) ) { return true; }
        if ( is_post_type_archive() && $this->enabled( 'crawl_disable_post_type_feeds' ) ) { return true; }
        return $this->enabled( 'crawl_disable_global_feeds' );
    }

    private function rejectSearch(): void {
        status_header( 404 );
        nocache_headers();
        if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ) { $GLOBALS['wp_query']->set_404(); }
    }

    private function enabled( string $key ): bool { return ! empty( $this->settings[ $key ] ); }
}
