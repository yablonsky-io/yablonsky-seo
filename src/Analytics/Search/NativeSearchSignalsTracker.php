<?php
namespace Yablonsky\PerformanceSEO\Analytics\Search;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local-only organic entrance counter; stores only daily aggregate evidence. */
final class NativeSearchSignalsTracker {
    private const CLEANUP_HOOK = 'yseo_native_search_signals_cleanup';
    /** @var NativeSearchSignalsRepository */ private $repository;

    public function __construct( ?NativeSearchSignalsRepository $repository = null ) {
        $this->repository = $repository instanceof NativeSearchSignalsRepository ? $repository : new NativeSearchSignalsRepository();
    }

    public function register(): void {
        add_action( 'template_redirect', array( $this, 'capture' ), 99 );
        add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    public static function clearSchedule(): void { wp_clear_scheduled_hook( self::CLEANUP_HOOK ); }

    public function cleanup(): void { $this->repository->cleanup( 180 ); }

    public function capture(): void {
        if ( '0' === (string) get_option( 'yseo_native_search_signals_enabled', '1' ) ) { return; }
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_user_logged_in() ) { return; }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return; }
        if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) { return; }
        if ( is_feed() || is_preview() || is_robots() || is_trackback() ) { return; }

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        // A HEAD request does not retrieve a page body and is not an organic entrance.
        if ( 'GET' !== $method ) { return; }
        $rawReferrerInput = filter_input( INPUT_SERVER, 'HTTP_REFERER', FILTER_UNSAFE_RAW );
        $rawReferrer = is_string( $rawReferrerInput ) ? wp_unslash( $rawReferrerInput ) : '';
        $referrer = esc_url_raw( $rawReferrer, array( 'http', 'https' ) );
        if ( '' === $referrer ) { return; }
        $host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
        if ( '' === $host ) { return; }
        $siteHost = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( '' !== $siteHost && $host === $siteHost ) { return; }

        $engine = $this->engineForHost( $host );
        if ( '' === $engine ) { return; }
        $postId = is_singular() ? max( 0, (int) get_queried_object_id() ) : 0;
        $this->repository->record( $postId, $engine );
    }

    private function engineForHost( string $host ): string {
        $host = strtolower( trim( $host, ". \t\n\r\0\x0B" ) );
        if ( '' === $host ) { return ''; }
        if ( preg_match( '/(^|\.)google\.[a-z.]+$/', $host ) ) { return 'google'; }
        if ( 'bing.com' === $host || $this->endsWith( $host, '.bing.com' ) ) { return 'bing'; }
        if ( 'duckduckgo.com' === $host || $this->endsWith( $host, '.duckduckgo.com' ) ) { return 'duckduckgo'; }
        if ( preg_match( '/(^|\.)search\.yahoo\.[a-z.]+$/', $host ) || preg_match( '/(^|\.)yahoo\.[a-z.]+$/', $host ) ) { return 'yahoo'; }
        if ( preg_match( '/(^|\.)yandex\.[a-z.]+$/', $host ) ) { return 'yandex'; }
        if ( 'baidu.com' === $host || $this->endsWith( $host, '.baidu.com' ) ) { return 'baidu'; }
        if ( 'search.brave.com' === $host ) { return 'brave'; }
        if ( 'ecosia.org' === $host || $this->endsWith( $host, '.ecosia.org' ) ) { return 'ecosia'; }
        if ( 'qwant.com' === $host || $this->endsWith( $host, '.qwant.com' ) ) { return 'qwant'; }
        if ( 'startpage.com' === $host || $this->endsWith( $host, '.startpage.com' ) ) { return 'startpage'; }
        if ( 'naver.com' === $host || $this->endsWith( $host, '.naver.com' ) ) { return 'naver'; }
        return (string) apply_filters( 'yablonsky_seo_native_search_engine_for_host', '', $host );
    }

    private function endsWith( string $value, string $suffix ): bool {
        if ( '' === $suffix ) { return true; }
        if ( strlen( $suffix ) > strlen( $value ) ) { return false; }
        return substr( $value, -strlen( $suffix ) ) === $suffix;
    }
}
