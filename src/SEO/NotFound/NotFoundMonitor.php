<?php
namespace Yablonsky\PerformanceSEO\SEO\NotFound;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NotFoundMonitor {
    private const CRON = 'yseo_404_prune';
    /** @var NotFoundRepository */ private $repository;
    public function __construct( ?NotFoundRepository $repository = null ) { $this->repository = $repository instanceof NotFoundRepository ? $repository : new NotFoundRepository(); }

    public function register(): void {
        add_action( 'template_redirect', array( $this, 'capture' ), 999 );
        add_action( self::CRON, array( $this, 'prune' ) );
        $scheduled = wp_next_scheduled( self::CRON );
        if ( $scheduled && 'hourly' !== wp_get_schedule( self::CRON ) ) {
            wp_clear_scheduled_hook( self::CRON );
            $scheduled = false;
        }
        if ( ! $scheduled ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON ); }
    }

    public static function clearSchedule(): void { wp_clear_scheduled_hook( self::CRON ); }

    public function capture(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_404() || headers_sent() ) { return; }
        $method = strtoupper( trim( (string) filter_input( INPUT_SERVER, 'REQUEST_METHOD', FILTER_UNSAFE_RAW ) ) );
        if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) { return; }
        $uri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW );
        $path = is_string( $uri ) ? (string) wp_parse_url( $uri, PHP_URL_PATH ) : '';
        if ( '' === $path || $this->ignored( $path ) ) { return; }
        $language = apply_filters( 'yablonsky_seo_request_language_v1', '', $path );
        $language = is_scalar( $language ) ? (string) $language : '';
        $classification = NotFoundSignalClassifier::classifyDetailed( $path );
        $signal = (string) $classification['signal'];
        $event = NotFoundSecurityBridge::emitObserved( $path, $signal, $method, $classification );
        if ( NotFoundSecurityBridge::shouldPersistAfterSecurity( $event ) ) {
            $this->repository->record( $path, $language );
        }
    }

    public function prune(): void { $this->repository->pruneAdaptive(); }

    private function ignored( string $path ): bool {
        $path = strtolower( $path );
        if ( preg_match( '#/(wp-admin|wp-login\.php|xmlrpc\.php)(/|$)#', $path ) ) { return true; }
        if ( preg_match( '#(?:^|/)(?:favicon\.ico|robots\.txt|sitemap(?:_index)?\.xml|wp-sitemap\.xml)$#', trim( $path, '/' ) ) ) { return true; }
        if ( preg_match( '#\.(?:css|js|map|jpg|jpeg|png|gif|webp|svg|ico|woff2?|ttf|eot)$#', $path ) ) { return true; }
        return false;
    }
}
