<?php
namespace Yablonsky\PerformanceSEO\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Small deterministic owner task list. It converts existing local evidence into
 * ordered actions; it does not crawl remotely or invent ranking priorities.
 */
final class SeoTaskList {
    private const MAX_TASKS = 8;

    /**
     * @param array<int,string> $providers
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $sitemap
     * @param array<string,mixed> $links
     * @param array<string,mixed> $aeo
     * @param array<string,int> $localeCoverage
     * @return array<int,array<string,string|int>>
     */
    public function build( array $providers, array $settings, array $sitemap, array $links, array $aeo, array $localeCoverage ): array {
        $tasks = array();
        if ( ! empty( $providers ) ) {
            $tasks[] = $this->task( 100, 'critical', __( 'Resolve SEO output authority', 'yablonsky-seo' ), __( 'Another SEO provider is active. Finish migration/takeover only when you are ready to let YSEO own canonical public output.', 'yablonsky-seo' ), __( 'Open Migration', 'yablonsky-seo' ), admin_url( 'admin.php?page=yablonsky-seo#yps-migration' ) );
        }
        if ( '' === trim( (string) ( $settings['owner_name'] ?? '' ) ) ) {
            $tasks[] = $this->task( 90, 'attention', __( 'Complete site identity', 'yablonsky-seo' ), __( 'Add the organization or person behind the site so Schema and GEO entity resolution have a stable owner.', 'yablonsky-seo' ), __( 'Edit identity', 'yablonsky-seo' ), admin_url( 'admin.php?page=yablonsky-seo-settings#general' ) );
        }
        if ( '' === trim( (string) ( $settings['home_title'] ?? '' ) ) || '' === trim( (string) ( $settings['home_description'] ?? '' ) ) ) {
            $tasks[] = $this->task( 85, 'attention', __( 'Finish homepage search metadata', 'yablonsky-seo' ), __( 'Set an explicit homepage SEO title and description instead of relying entirely on WordPress fallbacks.', 'yablonsky-seo' ), __( 'Edit SEO settings', 'yablonsky-seo' ), admin_url( 'admin.php?page=yablonsky-seo-settings#search' ) );
        }
        if ( empty( $sitemap['backfill_complete'] ) ) {
            $tasks[] = $this->task( 80, 'attention', __( 'Let sitemap indexing finish', 'yablonsky-seo' ), __( 'The materialized sitemap index is still building. Public fallback remains safe while the bounded backfill completes.', 'yablonsky-seo' ), __( 'Open Sitemaps', 'yablonsky-seo' ), admin_url( 'admin.php?page=yablonsky-seo#yps-sitemaps' ) );
        }
        $orphaned = max( 0, (int) ( $links['orphaned'] ?? 0 ) );
        if ( $orphaned > 0 ) {
            /* translators: %d: number of orphaned published resources. */
            $detail = sprintf( _n( '%d published resource currently has no observed inbound link in saved content.', '%d published resources currently have no observed inbound links in saved content.', $orphaned, 'yablonsky-seo' ), $orphaned );
            $tasks[] = $this->task( 75, 'attention', __( 'Review orphaned content', 'yablonsky-seo' ), $detail, __( 'Open Internal Links', 'yablonsky-seo' ), admin_url( 'admin.php?page=yablonsky-seo-internal-links&yseo_view=orphaned' ) );
        }
        $criticalAeo = max( 0, (int) ( $aeo['critical'] ?? 0 ) );
        if ( $criticalAeo > 0 ) {
            /* translators: %d: number of sampled resources with critical AEO findings. */
            $detail = sprintf( _n( '%d sampled resource has critical answer-readiness findings.', '%d sampled resources have critical answer-readiness findings.', $criticalAeo, 'yablonsky-seo' ), $criticalAeo );
            $tasks[] = $this->task( 65, 'attention', __( 'Improve answer readiness', 'yablonsky-seo' ), $detail, __( 'Open AEO', 'yablonsky-seo' ), admin_url( 'admin.php?page=yablonsky-seo#yps-answer' ) );
        }
        $coreTotal = max( 0, (int) ( $localeCoverage['core_total'] ?? 0 ) );
        $coreComplete = max( 0, (int) ( $localeCoverage['core_complete'] ?? 0 ) );
        if ( $coreTotal > 0 && $coreComplete < $coreTotal ) {
            /* translators: 1: complete core locale count, 2: total core locale count. */
            /* translators: 1: release-complete mandatory UI locale count, 2: total mandatory UI locale count. */
            $detail = sprintf( __( '%1$d of %2$d mandatory product UI locales currently have release-complete evidence.', 'yablonsky-seo' ), $coreComplete, $coreTotal );
            $tasks[] = $this->task( 45, 'neutral', __( 'Complete product language packs', 'yablonsky-seo' ), $detail, __( 'Open language status', 'yablonsky-seo' ), admin_url( 'admin.php?page=yablonsky-seo-settings#general' ) );
        }
        if ( empty( $tasks ) ) {
            $tasks[] = $this->task( 0, 'good', __( 'SEO setup is clear of urgent tasks', 'yablonsky-seo' ), __( 'Current local readiness evidence is clear of high-priority setup actions. Continue with normal content review and field acceptance.', 'yablonsky-seo' ), __( 'Open content', 'yablonsky-seo' ), admin_url( 'edit.php' ) );
        }
        usort( $tasks, static function ( array $a, array $b ): int { return (int) $b['priority'] <=> (int) $a['priority']; } );
        return array_slice( $tasks, 0, self::MAX_TASKS );
    }

    /** @return array<string,string|int> */
    private function task( int $priority, string $tone, string $label, string $detail, string $action, string $url ): array {
        return array( 'priority' => $priority, 'tone' => $tone, 'label' => $label, 'detail' => $detail, 'action' => $action, 'url' => $url );
    }
}
