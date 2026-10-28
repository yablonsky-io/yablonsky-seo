<?php
namespace Yablonsky\PerformanceSEO\Diagnostics;

use Yablonsky\PerformanceSEO\Runtime\OutputAuthorityGate;
use Yablonsky\PerformanceSEO\Runtime\ProviderDetector;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapRepository;
use Yablonsky\PerformanceSEO\SEO\Redirects\RedirectRepository;
use Yablonsky\PerformanceSEO\Migration\MigrationRepository;
use Yablonsky\PerformanceSEO\Migration\Diagnostics\MigrationConflictInspector;
use Yablonsky\PerformanceSEO\SEO\Schema\SearchSurfacePolicy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Builds a bounded local support snapshot with no secrets or content bodies. */
final class SupportSnapshotBuilder {
    /** @var ProviderDetector */ private $detector;
    public function __construct( ProviderDetector $detector ) { $this->detector = $detector; }
    /** @return array<string,mixed> */
    public function build(): array {
        $providers = $this->detector->detectActiveProviders();
        $gate = new OutputAuthorityGate( $providers );
        $sitemap = ( new SitemapRepository() )->diagnostics();
        $redirects = ( new RedirectRepository() )->diagnostics();
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $providerRows = array();
        foreach ( $providers as $provider ) { $providerRows[] = array( 'id' => $provider->id(), 'name' => $provider->name(), 'version' => $provider->version() ); }
        $features = array();
        foreach ( array( 'agent_markdown_enabled', 'agent_scoped_llms_enabled', 'agent_sitemap_extension_enabled', 'indexnow_enabled', 'apple_ai_answer_context_disabled' ) as $key ) {
            if ( array_key_exists( $key, $settings ) ) { $features[ $key ] = ! empty( $settings[ $key ] ); }
        }
        $features['merchant_return_policy_configured'] = ! empty( $settings['merchant_return_policy_url'] );
        $features['schema_aggregation'] = $gate->canEmitPublicSeo();
        $features['headless_seo_api'] = $gate->canEmitPublicSeo();
        $features['redirect_manager_runtime'] = $gate->canEmitPublicSeo();
        $sitemapSafe = array();
        foreach ( array( 'urls', 'chunks', 'bucket_size', 'backfill_complete', 'backfill_phase', 'persistent_object_cache', 'dirty', 'cache_generation' ) as $key ) {
            if ( array_key_exists( $key, $sitemap ) && is_scalar( $sitemap[ $key ] ) ) { $sitemapSafe[ $key ] = $sitemap[ $key ]; }
        }

        $migrationState = ( new MigrationRepository() )->load();
        $migration = null;
        $migrationConflicts = null;
        if ( null !== $migrationState ) {
            $migrationData = $migrationState->toArray();
            $migration = array(
                'provider'                  => sanitize_key( (string) ( $migrationData['provider'] ?? '' ) ),
                'provider_version'          => sanitize_text_field( (string) ( $migrationData['provider_version'] ?? '' ) ),
                'contract_revision'         => sanitize_text_field( (string) ( $migrationData['contract_revision'] ?? '' ) ),
                'mode'                      => sanitize_key( (string) ( $migrationData['mode'] ?? '' ) ),
                'status'                    => sanitize_key( (string) ( $migrationData['status'] ?? '' ) ),
                'phase'                     => sanitize_key( (string) ( $migrationData['phase'] ?? '' ) ),
                'processed_posts'           => max( 0, (int) ( $migrationData['processed_posts'] ?? 0 ) ),
                'processed_user_meta'       => max( 0, (int) ( $migrationData['processed_user_meta'] ?? 0 ) ),
                'processed_terms'           => max( 0, (int) ( $migrationData['processed_terms'] ?? 0 ) ),
                'mapped_values'             => max( 0, (int) ( $migrationData['mapped_values'] ?? 0 ) ),
                'global_mapped_values'      => max( 0, (int) ( $migrationData['global_mapped_values'] ?? 0 ) ),
                'global_imported_values'    => max( 0, (int) ( $migrationData['global_imported_values'] ?? 0 ) ),
                'global_verified_values'    => max( 0, (int) ( $migrationData['global_verified_values'] ?? 0 ) ),
                'imported_values'           => max( 0, (int) ( $migrationData['imported_values'] ?? 0 ) ),
                'verified_values'           => max( 0, (int) ( $migrationData['verified_values'] ?? 0 ) ),
                'conflicts'                 => max( 0, (int) ( $migrationData['conflicts'] ?? 0 ) ),
                'source_coverage_ready'     => ! empty( $migrationData['source_coverage_ready'] ),
                'backup_export_verified'    => ! empty( $migrationData['backup_export_verified'] ),
                'source_immutable_ready'    => ! empty( $migrationData['source_immutable_ready'] ),
                'shadow_data_parity_ready'  => ! empty( $migrationData['shadow_data_parity_ready'] ),
                'cutover_ready'             => ! empty( $migrationData['cutover_ready'] ),
                'readiness_blockers'        => array_values( array_map( 'sanitize_key', is_array( $migrationData['readiness_blockers'] ?? null ) ? $migrationData['readiness_blockers'] : array() ) ),
                'import_blockers'           => array_values( array_map( 'sanitize_key', is_array( $migrationData['import_blockers'] ?? null ) ? $migrationData['import_blockers'] : array() ) ),
                'updated_at'                => sanitize_text_field( (string) ( $migrationData['updated_at'] ?? '' ) ),
            );
            if ( (int) $migration['conflicts'] > 0 ) {
                $migrationConflicts = ( new MigrationConflictInspector() )->inspect( $migrationState );
            }
        }
        $historyRaw = get_option( 'yseo_upgrade_history', array() );
        $history = array();
        foreach ( array_slice( is_array( $historyRaw ) ? $historyRaw : array(), -10 ) as $entry ) {
            if ( ! is_array( $entry ) ) { continue; }
            $history[] = array(
                'from' => sanitize_text_field( (string) ( $entry['from'] ?? '' ) ),
                'to'   => sanitize_text_field( (string) ( $entry['to'] ?? '' ) ),
                'at'   => sanitize_text_field( (string) ( $entry['at'] ?? '' ) ),
            );
        }
        return array(
            'schema' => 'yablonsky.seo-support-snapshot/v2',
            'generated_at' => gmdate( 'c' ),
            'site_host' => sanitize_text_field( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ),
            'yseo_version' => YPS_VERSION,
            'wordpress_version' => (string) get_bloginfo( 'version' ),
            'php_version' => PHP_VERSION,
            'multisite' => is_multisite(),
            'https' => 'https' === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ),
            'pretty_permalinks' => '' !== (string) get_option( 'permalink_structure', '' ),
            'authority' => $gate->canEmitPublicSeo() ? 'yseo' : ( empty( $providers ) ? 'manual-shadow' : 'provider' ),
            'providers' => $providerRows,
            'features' => $features,
            'schema_surface_policy' => SearchSurfacePolicy::document(),
            'sitemap' => $sitemapSafe,
            'redirects' => array( 'total' => (int) $redirects['total'], 'enabled' => (int) $redirects['enabled'], 'plain' => (int) $redirects['plain'], 'regex' => (int) $redirects['regex'] ),
            'migration' => $migration,
            'migration_conflicts' => $migrationConflicts,
            'upgrade_history' => $history,
            'privacy_note' => 'No API keys, post content, user email addresses, raw SEO values or database credentials are included.',
        );
    }
}
