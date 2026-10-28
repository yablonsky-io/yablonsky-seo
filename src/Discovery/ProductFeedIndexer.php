<?php
namespace Yablonsky\PerformanceSEO\Discovery;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded event-driven materialization. Heavy catalog work never runs in a public feed request or admin page render. */
final class ProductFeedIndexer {
    public const CRON_HOOK = 'yseo_product_feed_backfill_v1';
    public const DIRTY_SYNC_HOOK = 'yseo_product_feed_dirty_sync_v1';
    private const OPTION_DIRTY_PRODUCTS = 'yseo_product_feed_dirty_products_v1';
    private const BACKFILL_BATCH = 200;
    private const DIRTY_BATCH = 100;
    /** @var ProductFeedRepository */ private $repository;
    /** @var ProductFeedRecordBuilder */ private $builder;
    /** @var CommerceFeedSnapshot|null */ private $snapshot;
    /** @var bool */ private $bulkMode = false;

    public function __construct( ProductFeedRepository $repository, ?CommerceFeedSnapshot $snapshot = null ) {
        $this->repository = $repository;
        $this->builder = new ProductFeedRecordBuilder();
        $this->snapshot = $snapshot;
    }

    public function register(): void {
        add_action( 'save_post_product', array( $this, 'sync' ), 40, 1 );
        add_action( 'woocommerce_update_product', array( $this, 'sync' ), 40, 1 );
        add_action( 'before_delete_post', array( $this, 'delete' ), 40, 1 );
        add_action( 'added_post_meta', array( $this, 'metaAdded' ), 40, 4 );
        add_action( 'updated_post_meta', array( $this, 'metaUpdated' ), 40, 4 );
        add_action( 'deleted_post_meta', array( $this, 'metaDeleted' ), 40, 4 );
        add_action( 'woocommerce_product_set_stock', array( $this, 'stockChanged' ), 40, 1 );
        add_action( 'woocommerce_variation_set_stock', array( $this, 'stockChanged' ), 40, 1 );
        add_action( self::DIRTY_SYNC_HOOK, array( $this, 'flushDirtyProducts' ) );
        add_action( self::CRON_HOOK, array( $this, 'runBackfill' ) );
        $event = wp_get_scheduled_event( self::CRON_HOOK );
        if ( $event && ! empty( $event->schedule ) ) { wp_clear_scheduled_hook( self::CRON_HOOK ); }
        if ( ! $this->repository->isReady() ) { $this->scheduleBackfill( 15 ); }
    }

    public function sync( int $productId ): void {
        if ( 'product' !== get_post_type( $productId ) || 'publish' !== get_post_status( $productId ) ) { $this->repository->delete( $productId ); return; }
        $record = $this->builder->build( $productId );
        if ( null === $record ) { $this->repository->delete( $productId ); return; }
        $this->repository->upsert( $productId, $record );
        if ( $this->snapshot && ! $this->bulkMode ) { $this->snapshot->markDirty( 'product-change' ); }
    }

    /** @param mixed $metaValue */
    public function metaAdded( int $metaId, int $objectId, string $metaKey, $metaValue ): void {
        unset( $metaId, $metaValue );
        $this->handlePostMetaChange( $objectId, $metaKey );
    }

    /** @param mixed $metaValue */
    public function metaUpdated( int $metaId, int $objectId, string $metaKey, $metaValue ): void {
        unset( $metaId, $metaValue );
        $this->handlePostMetaChange( $objectId, $metaKey );
    }

    /**
     * WordPress passes an array of deleted metadata IDs to deleted_post_meta.
     *
     * @param array<int|string> $metaIds
     * @param mixed             $metaValue
     */
    public function metaDeleted( array $metaIds, int $objectId, string $metaKey, $metaValue ): void {
        unset( $metaIds, $metaValue );
        $this->handlePostMetaChange( $objectId, $metaKey );
    }

    private function handlePostMetaChange( int $objectId, string $metaKey ): void {
        if ( ! in_array( $metaKey, array( '_price', '_regular_price', '_sale_price', '_stock', '_stock_status', '_sku', '_thumbnail_id', '_product_image_gallery', '_global_unique_id', '_gtin', 'gtin', '_mpn', 'mpn' ), true ) ) { return; }
        $this->queueSync( $objectId );
    }

    /** @param mixed $product */
    public function stockChanged( $product ): void {
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) { return; }
        $this->queueSync( (int) $product->get_id() );
    }

    public function queueSync( int $objectId ): void {
        if ( $objectId <= 0 ) { return; }
        $type = get_post_type( $objectId );
        if ( 'product_variation' === $type ) { $objectId = (int) wp_get_post_parent_id( $objectId ); $type = get_post_type( $objectId ); }
        if ( 'product' !== $type || $objectId <= 0 ) { return; }
        $ids = get_option( self::OPTION_DIRTY_PRODUCTS, array() ); $ids = is_array( $ids ) ? $ids : array();
        $ids[ $objectId ] = time();
        if ( count( $ids ) > 1000 ) { asort( $ids ); $ids = array_slice( $ids, -1000, null, true ); }
        update_option( self::OPTION_DIRTY_PRODUCTS, $ids, false );
        if ( ! wp_next_scheduled( self::DIRTY_SYNC_HOOK ) ) { wp_schedule_single_event( time() + 30, self::DIRTY_SYNC_HOOK ); }
    }

    public function flushDirtyProducts(): void {
        $ids = get_option( self::OPTION_DIRTY_PRODUCTS, array() );
        if ( ! is_array( $ids ) || empty( $ids ) ) { return; }
        $batch = array_slice( $ids, 0, self::DIRTY_BATCH, true );
        $remaining = array_diff_key( $ids, $batch );
        if ( empty( $remaining ) ) { delete_option( self::OPTION_DIRTY_PRODUCTS ); }
        else { update_option( self::OPTION_DIRTY_PRODUCTS, $remaining, false ); }
        $this->bulkMode = true;
        try { foreach ( array_keys( $batch ) as $productId ) { $this->sync( (int) $productId ); } }
        finally { $this->bulkMode = false; }
        if ( $this->snapshot ) { $this->snapshot->markDirty( 'product-batch' ); }
        if ( ! empty( $remaining ) && ! wp_next_scheduled( self::DIRTY_SYNC_HOOK ) ) { wp_schedule_single_event( time() + 30, self::DIRTY_SYNC_HOOK ); }
    }

    public function delete( int $postId ): void {
        if ( 'product' !== get_post_type( $postId ) ) { return; }
        $this->repository->delete( $postId );
        if ( $this->snapshot && ! $this->bulkMode ) { $this->snapshot->markDirty( 'product-delete' ); }
    }

    public function runBackfill( int $limit = self::BACKFILL_BATCH ): void {
        $state = $this->repository->backfillState();
        if ( ! empty( $state['complete'] ) ) { return; }
        $lock = $this->repository->acquireLock( 120 );
        if ( null === $lock ) { $this->scheduleBackfill( 30 ); return; }
        try {
            $this->bulkMode = true;
            $cursor = max( 0, (int) ( $state['cursor'] ?? 0 ) );
            $limit = max( 50, min( 500, $limit ) );
            global $wpdb;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded keyset scan; one background batch per event.
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND post_type='product' AND post_status='publish' ORDER BY ID ASC LIMIT %d", $cursor, $limit ) );
            foreach ( (array) $ids as $id ) { $this->sync( (int) $id ); $cursor = (int) $id; }
            $complete = count( (array) $ids ) < $limit;
            $this->repository->saveBackfillState( array( 'cursor' => $cursor, 'complete' => $complete ) );
            if ( $complete ) {
                if ( $this->snapshot ) { $this->snapshot->markDirty( 'catalog-reconciled' ); }
            } else {
                $this->scheduleBackfill( 15 );
            }
        } finally {
            $this->bulkMode = false;
            $this->repository->releaseLock( $lock );
        }
    }

    public function requestFullRebuild( string $reason = 'materialization-rebuild' ): void {
        $this->repository->truncateAndReset();
        if ( $this->snapshot ) { $this->snapshot->markDirty( $reason ); }
        $this->scheduleBackfill( 5 );
    }

    public function forceRebuild(): void { $this->requestFullRebuild(); }
    public function startReconciliation(): void { $this->requestFullRebuild( 'scheduled-reconcile' ); }

    private function scheduleBackfill( int $delay ): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) { wp_schedule_single_event( time() + max( 5, $delay ), self::CRON_HOOK ); }
    }
}
