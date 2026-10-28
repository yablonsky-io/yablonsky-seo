<?php
/**
 * Yablonsky SEO uninstall cleanup.
 *
 * SEO content/meta and migration evidence are intentionally retained for data safety,
 * but remote-service credentials and cached account capability data are removed.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'yseo_ai_api_secret' );
delete_option( 'yseo_ai_settings' );
delete_option( 'yseo_ai_account_cache' );
delete_option( 'yseo_upgrade_history' );
delete_option( 'yseo_settings_portability_backup_v1' );
delete_option( 'yseo_settings_portability_last_import_v1' );
delete_transient( 'yseo_agent_llms_txt_v1' );
delete_transient( 'yseo_aeo_readiness_v1' );

delete_option( 'yseo_indexnow_key_v1' );
delete_option( 'yseo_indexnow_queue_v1' );
delete_option( 'yseo_indexnow_status_v1' );
delete_option( 'yseo_collection_freshness_v1' );
delete_option( 'yseo_search_performance_snapshot_v1' );
delete_option( 'yseo_native_search_signals_enabled' );
delete_option( 'yseo_native_search_signals_schema_version' );
wp_clear_scheduled_hook( 'yseo_indexnow_flush_v1' );
wp_clear_scheduled_hook( 'yseo_native_search_signals_cleanup' );

delete_option( 'yseo_internal_link_schema_version' );
delete_option( 'yseo_internal_link_backfill_cursor' );
delete_option( 'yseo_internal_link_backfill_complete' );
wp_clear_scheduled_hook( 'yseo_internal_link_backfill' );

delete_option( 'yseo_video_backfill_offset' );
delete_option( 'yseo_video_backfill_complete' );
wp_clear_scheduled_hook( 'yseo_video_backfill' );

global $wpdb;
if ( isset( $wpdb ) && $wpdb instanceof wpdb ) {
    $yseoInternalLinksTable = $wpdb->prefix . 'yseo_internal_links';
    $yseoInternalSourcesTable = $wpdb->prefix . 'yseo_internal_link_sources';
    $yseoSearchSignalsTable = $wpdb->prefix . 'yseo_search_signals_daily';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes only deterministic plugin-owned derived-data tables; identifier is prepared with %i.
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $yseoInternalLinksTable ) );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes only deterministic plugin-owned derived-data tables; identifier is prepared with %i.
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $yseoInternalSourcesTable ) );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes only the plugin-owned aggregate search-signals table.
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $yseoSearchSignalsTable ) );
}
