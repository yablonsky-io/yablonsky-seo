<?php
namespace Yablonsky\PerformanceSEO\Diagnostics;

use Yablonsky\PerformanceSEO\Runtime\OutputAuthorityGate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded local release/support evidence. No external requests or SEO scoring. */
final class ReleaseReadinessAudit {
    /**
     * @param array<int,\Yablonsky\PerformanceSEO\Runtime\Provider> $providers
     * @param array<string,mixed> $sitemapDiagnostics
     * @return array{summary:array<string,int>,checks:array<int,array<string,string>>}
     */
    public function run( array $providers, OutputAuthorityGate $gate, array $sitemapDiagnostics ): array {
        $checks = array();
        $this->add( $checks, 'runtime_php', version_compare( PHP_VERSION, '7.4', '>=' ), __( 'PHP runtime', 'yablonsky-seo' ), PHP_VERSION, __( 'PHP 7.4 or newer is required.', 'yablonsky-seo' ) );
        $wpVersion = (string) get_bloginfo( 'version' );
        $this->add( $checks, 'runtime_wp', version_compare( $wpVersion, '6.8', '>=' ), __( 'WordPress runtime', 'yablonsky-seo' ), $wpVersion, __( 'WordPress 6.8 or newer is required.', 'yablonsky-seo' ) );
        $scheme = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
        $this->add( $checks, 'https', 'https' === $scheme, __( 'Public HTTPS', 'yablonsky-seo' ), 'https' === $scheme ? __( 'HTTPS', 'yablonsky-seo' ) : __( 'Detection pending', 'yablonsky-seo' ), __( 'Public HTTPS is recommended for production.', 'yablonsky-seo' ) );
        $authoritySafe = empty( $providers ) ? $gate->canEmitPublicSeo() : ! $gate->canEmitPublicSeo();
        $this->add( $checks, 'authority', $authoritySafe, __( 'SEO authority boundary', 'yablonsky-seo' ), $authoritySafe ? __( 'Coherent', 'yablonsky-seo' ) : __( 'Review', 'yablonsky-seo' ), __( 'A competing provider keeps YSEO in Shadow; explicit YSEO authority remains optional.', 'yablonsky-seo' ) );
        if ( $gate->canEmitPublicSeo() ) {
            $ready = ! empty( $sitemapDiagnostics['backfill_complete'] );
            $this->add( $checks, 'sitemap', $ready, __( 'Sitemap materialization', 'yablonsky-seo' ), $ready ? __( 'Complete', 'yablonsky-seo' ) : __( 'Warming up', 'yablonsky-seo' ), __( 'Public YSEO authority should have a completed sitemap membership ledger.', 'yablonsky-seo' ) );
        } else {
            $checks[] = array( 'id' => 'sitemap', 'status' => 'pass', 'label' => __( 'Sitemap materialization', 'yablonsky-seo' ), 'value' => __( 'Shadow-safe', 'yablonsky-seo' ), 'detail' => __( 'Sitemap background work is intentionally paused while another provider owns public authority.', 'yablonsky-seo' ) );
        }
        $memoryBytes = $this->effectiveMemoryBytes();
        $memoryLabel = $memoryBytes < 0 ? __( 'Unlimited', 'yablonsky-seo' ) : size_format( $memoryBytes, 0 );
        $memoryOk = $memoryBytes < 0 || $memoryBytes >= 134217728;
        $this->add( $checks, 'memory', $memoryOk, __( 'Admin PHP memory', 'yablonsky-seo' ), $memoryLabel, __( '128 MB or more is recommended for control-plane tasks such as large plugin checks and migrations.', 'yablonsky-seo' ) );
        $permalinks = '' !== (string) get_option( 'permalink_structure', '' );
        $this->add( $checks, 'permalinks', $permalinks, __( 'Pretty permalinks', 'yablonsky-seo' ), $permalinks ? __( 'Configured', 'yablonsky-seo' ) : __( 'Plain', 'yablonsky-seo' ), __( 'Pretty permalinks provide the expected canonical route surface for production SEO.', 'yablonsky-seo' ) );
        $manifest = is_readable( YPS_DIR . 'SHA256SUMS.txt' );
        $this->add( $checks, 'manifest', $manifest, __( 'Package manifest', 'yablonsky-seo' ), $manifest ? __( 'Present', 'yablonsky-seo' ) : __( 'Attention needed', 'yablonsky-seo' ), __( 'The packaged SHA256 manifest should be present for support/release provenance.', 'yablonsky-seo' ) );
        $legacy = trailingslashit( WP_PLUGIN_DIR ) . 'yablonsky-performance-seo/yablonsky-performance-seo.php';
        $legacyClear = ! file_exists( $legacy );
        $this->add( $checks, 'legacy_folder', $legacyClear, __( 'Legacy plugin folder', 'yablonsky-seo' ), $legacyClear ? __( 'Clear', 'yablonsky-seo' ) : __( 'Present', 'yablonsky-seo' ), __( 'Canonical runtime is yablonsky-seo; an old inactive folder is only a cleanup item.', 'yablonsky-seo' ) );
        $summary = array( 'pass' => 0, 'attention' => 0 );
        foreach ( $checks as $check ) { ++$summary[ isset( $check['status'] ) && 'pass' === $check['status'] ? 'pass' : 'attention' ]; }
        return array( 'summary' => $summary, 'checks' => $checks );
    }
    /** @param array<int,array<string,string>> $checks */
    private function add( array &$checks, string $id, bool $ok, string $label, string $value, string $detail ): void {
        $checks[] = array( 'id' => $id, 'status' => $ok ? 'pass' : 'attention', 'label' => $label, 'value' => $value, 'detail' => $detail );
    }
    private function effectiveMemoryBytes(): int {
        $values = array();
        foreach ( array( (string) ini_get( 'memory_limit' ), defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '' ) as $raw ) {
            $raw = trim( $raw );
            if ( '' === $raw ) { continue; }
            if ( '-1' === $raw ) { return -1; }
            $bytes = wp_convert_hr_to_bytes( $raw );
            if ( is_int( $bytes ) && $bytes > 0 ) { $values[] = $bytes; }
        }
        return empty( $values ) ? 0 : max( $values );
    }
}
