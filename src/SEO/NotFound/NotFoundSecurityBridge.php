<?php
namespace Yablonsky\PerformanceSEO\SEO\NotFound;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Versioned, privacy-light handoff contract for dedicated security products.
 *
 * YSEO remains an SEO observer. It never blocks, challenges, rate-limits or
 * changes firewall policy. Security products can subscribe to new observations
 * and pull bounded historical non-SEO aggregates through WordPress hooks.
 */
final class NotFoundSecurityBridge {
    public const CONTRACT = 'yablonsky.seo-404-security-evidence/v1';
    public const QUERY_FILTER = 'yablonsky_seo_404_security_evidence_query_v1';
    public const OBSERVED_ACTION = 'yablonsky_seo_404_observed_v1';
    public const STATUS_FILTER = 'yablonsky_seo_404_security_bridge_status_v1';
    public const PERSIST_FILTER = 'yablonsky_seo_404_persist_after_security_v1';

    /** @var NotFoundRepository */ private $repository;

    public function __construct( ?NotFoundRepository $repository = null ) {
        $this->repository = $repository instanceof NotFoundRepository ? $repository : new NotFoundRepository();
    }

    public function register(): void {
        add_filter( 'yablonsky_seo_404_security_evidence_query_v1', array( $this, 'query' ), 10, 2 );
    }

    /** @param mixed $response @param mixed $query @return mixed */
    public function query( $response, $query ) {
        if ( null !== $response ) { return $response; }
        $query = is_array( $query ) ? $query : array();
        $afterId = max( 0, (int) ( $query['after_id'] ?? 0 ) );
        $limit = max( 1, min( 250, (int) ( $query['limit'] ?? 100 ) ) );
        $items = $this->repository->securityBatch( $afterId, $limit );
        $nextId = $afterId;
        foreach ( $items as &$item ) {
            $nextId = max( $nextId, (int) ( $item['id'] ?? 0 ) );
            $detail = NotFoundSignalClassifier::classifyDetailed( (string) ( $item['path'] ?? '' ) );
            $item['reason'] = (string) $detail['reason'];
            $item['confidence'] = (string) $detail['confidence'];
            $item['classifier_version'] = NotFoundSignalClassifier::VERSION;
            $item['low_value'] = (int) ( $item['hits'] ?? 0 ) <= NotFoundRetentionPolicy::LOW_VALUE_MAX_HITS;
        }
        unset( $item );
        return array(
            'contract' => self::CONTRACT,
            'after_id' => $afterId,
            'next_id' => $nextId,
            'limit' => $limit,
            'has_more' => count( $items ) === $limit,
            'items' => $items,
            'privacy' => array( 'ip' => false, 'user_agent' => false, 'referrer' => false, 'query_string' => false ),
        );
    }

    /** @param array<string,string> $classification @return array<string,mixed> */
    public static function emitObserved( string $path, string $signal, string $method, array $classification = array() ): array {
        $detail = ! empty( $classification ) ? $classification : NotFoundSignalClassifier::classifyDetailed( $path );
        $event = array(
            'contract' => self::CONTRACT,
            'path' => $path,
            'signal' => $signal,
            'reason' => sanitize_key( (string) ( $detail['reason'] ?? '' ) ),
            'confidence' => in_array( (string) ( $detail['confidence'] ?? '' ), array( 'low', 'medium', 'high' ), true ) ? (string) $detail['confidence'] : 'medium',
            'classifier_version' => NotFoundSignalClassifier::VERSION,
            'method' => in_array( $method, array( 'GET', 'HEAD' ), true ) ? $method : 'GET',
            'observed_gmt' => gmdate( 'c' ),
            'privacy' => array( 'ip' => false, 'user_agent' => false, 'referrer' => false, 'query_string' => false ),
        );
        if ( NotFoundSignalClassifier::SEO !== $signal ) { do_action( 'yablonsky_seo_404_observed_v1', $event ); }
        return $event;
    }

    /** @param array<string,mixed> $event */
    public static function shouldPersistAfterSecurity( array $event ): bool {
        if ( NotFoundSignalClassifier::SEO === (string) ( $event['signal'] ?? '' ) ) { return true; }
        $status = self::status();
        if ( empty( $status['connected'] ) ) { return true; }
        $persist = apply_filters( 'yablonsky_seo_404_persist_after_security_v1', true, $event );
        return false !== $persist;
    }

    /** @return array{installed:bool,active:bool,version:string,file:string} */
    public static function yablonskySecurityPluginState(): array {
        $state = array( 'installed' => false, 'active' => false, 'version' => '', 'file' => '' );
        if ( ! is_admin() ) { return $state; }
        if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        if ( ! function_exists( 'get_plugins' ) ) { return $state; }
        foreach ( get_plugins() as $file => $headers ) {
            $name = sanitize_text_field( (string) ( $headers['Name'] ?? '' ) );
            if ( false === stripos( $name, 'Yablonsky Security' ) ) { continue; }
            $state['installed'] = true;
            $state['active'] = function_exists( 'is_plugin_active' ) ? is_plugin_active( (string) $file ) : false;
            $state['version'] = sanitize_text_field( (string) ( $headers['Version'] ?? '' ) );
            $state['file'] = sanitize_text_field( (string) $file );
            break;
        }
        return $state;
    }

    /** @return array{contract:string,available:bool,connected:bool,provider:string,live_events:bool,history_pull:bool,suppress_duplicate_storage:bool} */
    public static function status(): array {
        $base = array( 'contract' => self::CONTRACT, 'available' => true, 'connected' => false, 'provider' => '', 'live_events' => false, 'history_pull' => false, 'suppress_duplicate_storage' => false );
        $value = apply_filters( 'yablonsky_seo_404_security_bridge_status_v1', $base );
        if ( ! is_array( $value ) ) { return $base; }
        return array(
            'contract' => self::CONTRACT,
            'available' => true,
            'connected' => ! empty( $value['connected'] ),
            'provider' => sanitize_text_field( (string) ( $value['provider'] ?? '' ) ),
            'live_events' => ! empty( $value['live_events'] ),
            'history_pull' => ! empty( $value['history_pull'] ),
            'suppress_duplicate_storage' => ! empty( $value['suppress_duplicate_storage'] ),
        );
    }
}
