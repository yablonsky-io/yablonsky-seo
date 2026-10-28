<?php
namespace Yablonsky\PerformanceSEO\Discovery;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Atomic materialized XML snapshot + bounded operational journal. */
final class CommerceFeedSnapshot {
    public const PUBLISH_HOOK = 'yseo_commerce_feed_publish_v1';
    public const RECONCILE_HOOK = 'yseo_commerce_feed_reconcile_v1';
    private const OPTION_STATE = 'yseo_commerce_feed_snapshot_v1';
    private const OPTION_LOG = 'yseo_commerce_feed_log_v1';
    private const OPTION_LOCK = 'yseo_commerce_feed_snapshot_lock_v1';
    private const LOG_LIMIT = 20;
    /** @var ProductFeedRepository */ private $repository;

    public function __construct( ProductFeedRepository $repository ) { $this->repository = $repository; }

    public function register(): void {
        add_filter( 'cron_schedules', array( $this, 'cronSchedules' ) );
        add_action( self::PUBLISH_HOOK, array( $this, 'publishIfDirty' ) );
        add_action( self::RECONCILE_HOOK, array( $this, 'reconcile' ) );
        $this->ensureReconcileSchedule();
    }

    /** @param array<string,array<string,mixed>> $schedules @return array<string,array<string,mixed>> */
    public function cronSchedules( array $schedules ): array {
        $schedules['yseo_15_minutes'] = array( 'interval' => 900, 'display' => __( 'Every 15 minutes', 'yablonsky-seo' ) );
        $schedules['yseo_6_hours'] = array( 'interval' => 21600, 'display' => __( 'Every 6 hours', 'yablonsky-seo' ) );
        return $schedules;
    }

    public function markDirty( string $reason = 'product-change' ): void {
        $state = $this->state();
        $state['dirty'] = true;
        $state['dirty_at'] = time();
        $state['dirty_reason'] = sanitize_key( $reason );
        update_option( self::OPTION_STATE, $state, false );
        if ( ! wp_next_scheduled( self::PUBLISH_HOOK ) ) { wp_schedule_single_event( time() + 30, self::PUBLISH_HOOK ); }
    }

    public function publishIfDirty(): void {
        $state = $this->state();
        if ( empty( $state['dirty'] ) && is_file( $this->path() ) ) { return; }
        $this->publish( (string) ( $state['dirty_reason'] ?? 'scheduled' ) );
    }

    public function reconcile(): void {
        if ( ! ( new DiscoverySettings() )->commerceEnabled() ) { return; }
        $indexer = new ProductFeedIndexer( $this->repository, $this );
        if ( $this->repository->isReady() ) { $indexer->startReconciliation(); }
        else { $indexer->runBackfill( 200 ); }
    }

    /** @return array<string,mixed> */
    public function publish( string $reason = 'manual' ): array {
        $lock = $this->acquireLock( 300 );
        if ( null === $lock ) { return array( 'ok' => false, 'error' => 'locked' ); }
        $started = microtime( true );
        try {
            if ( ! $this->repository->isReady() ) { $this->appendLog( 'warning', 'publish-skipped', array( 'reason' => 'materialization-not-ready' ) ); return array( 'ok' => false, 'error' => 'materialization-not-ready' ); }
            $directory = dirname( $this->path() );
            if ( ! wp_mkdir_p( $directory ) ) { throw new \RuntimeException( 'snapshot-directory-unavailable' ); }
            $temp = $this->path() . '.tmp-' . wp_generate_password( 8, false, false );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a potentially large generated XML snapshot avoids loading the entire feed into PHP memory.
            $handle = fopen( $temp, 'wb' );
            if ( false === $handle ) { throw new \RuntimeException( 'snapshot-temp-open-failed' ); }
            try { $stats = ( new CommerceFeedSerializer( $this->repository ) )->write( $handle ); }
            finally {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the deliberate streaming handle opened above.
                fclose( $handle );
            }
            clearstatcache( true, $temp );
            $bytes = is_file( $temp ) ? (int) filesize( $temp ) : 0;
            if ( $bytes <= 0 ) { wp_delete_file( $temp ); throw new \RuntimeException( 'snapshot-empty' ); }
            $validation = $this->validatePath( $temp );
            if ( empty( $validation['ok'] ) ) { wp_delete_file( $temp ); throw new \RuntimeException( 'snapshot-invalid-xml' ); }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename provides atomic snapshot publication; WP_Filesystem::move() does not guarantee atomic replacement.
            if ( ! @rename( $temp, $this->path() ) ) { wp_delete_file( $temp ); throw new \RuntimeException( 'snapshot-atomic-replace-failed' ); }
            $durationMs = (int) round( ( microtime( true ) - $started ) * 1000 );
            $state = array(
                'dirty' => false, 'generated_at' => time(), 'duration_ms' => $durationMs,
                'products' => (int) ( $stats['products'] ?? 0 ), 'bytes' => $bytes,
                'sha256' => (string) hash_file( 'sha256', $this->path() ), 'revision' => $this->repository->revision(),
                'reason' => sanitize_key( $reason ), 'status' => 'ready',
            );
            update_option( self::OPTION_STATE, $state, false );
            $this->appendLog( 'success', 'snapshot-published', array( 'reason' => $state['reason'], 'products' => $state['products'], 'bytes' => $bytes, 'duration_ms' => $durationMs ) );
            return array_merge( array( 'ok' => true ), $state );
        } catch ( \Throwable $error ) {
            $state = $this->state(); $state['status'] = 'error'; $state['last_error'] = sanitize_text_field( $error->getMessage() ); $state['last_error_at'] = time(); update_option( self::OPTION_STATE, $state, false );
            $this->appendLog( 'error', 'snapshot-failed', array( 'reason' => sanitize_key( $reason ), 'message' => sanitize_text_field( $error->getMessage() ) ) );
            return array( 'ok' => false, 'error' => $error->getMessage() );
        } finally { $this->releaseLock( $lock ); }
    }

    /** @return array<string,mixed> */
    public function validate(): array { return $this->validatePath( $this->path() ); }

    /** @return array<string,mixed> */
    public function state(): array { $state = get_option( self::OPTION_STATE, array( 'dirty' => true, 'status' => 'pending' ) ); return is_array( $state ) ? $state : array( 'dirty' => true, 'status' => 'pending' ); }
    /** @return array<int,array<string,mixed>> */
    public function logs(): array { $logs = get_option( self::OPTION_LOG, array() ); return is_array( $logs ) ? array_slice( $logs, -self::LOG_LIMIT ) : array(); }
    public function path(): string { $uploads = wp_upload_dir( null, false ); $base = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads'; return trailingslashit( $base ) . 'yablonsky-seo/commerce/' . CommerceFeedController::PATH; }

    private function ensureReconcileSchedule(): void {
        $wanted = $this->scheduleName( ( new DiscoverySettings() )->commerceRefresh() );
        $event = wp_get_scheduled_event( self::RECONCILE_HOOK );
        if ( $event && isset( $event->schedule ) && $event->schedule !== $wanted ) { wp_clear_scheduled_hook( self::RECONCILE_HOOK ); $event = false; }
        if ( ! $event ) { wp_schedule_event( time() + 300, $wanted, self::RECONCILE_HOOK ); }
    }

    private function scheduleName( string $refresh ): string {
        if ( 'fifteen_minutes' === $refresh ) { return 'yseo_15_minutes'; }
        if ( 'six_hours' === $refresh ) { return 'yseo_6_hours'; }
        if ( 'daily' === $refresh ) { return 'daily'; }
        return 'hourly';
    }

    /** @return array<string,mixed> */
    private function validatePath( string $path ): array {
        if ( ! is_file( $path ) ) { return array( 'ok' => false, 'error' => 'missing' ); }
        if ( class_exists( '\\XMLReader' ) ) {
            $reader = new \XMLReader();
            $opened = @$reader->open( $path, null, LIBXML_NONET | LIBXML_COMPACT );
            if ( ! $opened ) { return array( 'ok' => false, 'error' => 'open-failed' ); }
            $items = 0; $valid = true;
            libxml_use_internal_errors( true );
            while ( @$reader->read() ) { if ( \XMLReader::ELEMENT === $reader->nodeType && 'item' === $reader->localName ) { $items++; } }
            if ( libxml_get_errors() ) { $valid = false; }
            libxml_clear_errors(); $reader->close();
            return array( 'ok' => $valid, 'items' => $items, 'bytes' => (int) filesize( $path ) );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded 512-byte validation fallback for a local generated snapshot.
        $head = file_get_contents( $path, false, null, 0, 512 );
        $tail = $this->tail( $path, 512 );
        $ok = is_string( $head ) && 0 === strpos( $head, '<?xml' ) && false !== strpos( $head, '<rss ' ) && false !== strpos( $tail, '</rss>' );
        return array( 'ok' => $ok, 'items' => null, 'bytes' => (int) filesize( $path ) );
    }

    private function tail( string $path, int $bytes ): string {
        $size = (int) filesize( $path );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded tail read of a local generated snapshot.
        $handle = fopen( $path, 'rb' );
        if ( false === $handle ) { return ''; }
        if ( $size > $bytes ) { fseek( $handle, -$bytes, SEEK_END ); }
        $data = stream_get_contents( $handle );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the deliberate bounded validation handle.
        fclose( $handle );
        return is_string( $data ) ? $data : '';
    }

    /** @param array<string,mixed> $context */
    private function appendLog( string $level, string $event, array $context = array() ): void {
        $logs = $this->logs(); $logs[] = array( 'at' => time(), 'level' => sanitize_key( $level ), 'event' => sanitize_key( $event ), 'context' => $context ); update_option( self::OPTION_LOG, array_slice( $logs, -self::LOG_LIMIT ), false );
    }

    private function acquireLock( int $ttl ): ?string {
        $now = time(); $token = wp_generate_uuid4(); $value = array( 'token' => $token, 'expires_at' => $now + max( 60, $ttl ) );
        if ( add_option( self::OPTION_LOCK, $value, '', false ) ) { return $token; }
        $existing = get_option( self::OPTION_LOCK, null );
        if ( ! is_array( $existing ) || ! isset( $existing['expires_at'] ) || (int) $existing['expires_at'] > $now ) { return null; }
        delete_option( self::OPTION_LOCK ); return add_option( self::OPTION_LOCK, $value, '', false ) ? $token : null;
    }
    private function releaseLock( string $token ): void { $existing = get_option( self::OPTION_LOCK, null ); if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) ) { delete_option( self::OPTION_LOCK ); } }
}
