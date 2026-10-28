<?php
namespace Yablonsky\PerformanceSEO\Analysis;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bounded local sample of recent public resources for AEO setup evidence.
 *
 * This is deliberately not a site-wide ranking score. It samples recently
 * modified public resources and reports the same local readiness states shown
 * in the editor.
 */
final class AeoReadinessAudit {
    private const LIMIT = 20;

    /** @return array<string,int|string> */
    public function report(): array {
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $policy = AeoPolicy::fromSettings( $settings );
        $fingerprint = md5( implode( '|', array( $policy->profile(), $policy->questionHeadingGuidance() ? '1' : '0', $policy->compactStructureGuidance() ? '1' : '0', $policy->faqGuardrail() ? '1' : '0', (string) get_lastpostmodified( 'GMT' ) ) ) );
        $cached = get_transient( 'yseo_aeo_readiness_v1' );
        if ( is_array( $cached ) && isset( $cached['fingerprint'], $cached['report'] ) && hash_equals( $fingerprint, (string) $cached['fingerprint'] ) && is_array( $cached['report'] ) ) {
            return $cached['report'];
        }

        $types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
        $types = array_values( array_filter( $types, static function ( string $type ): bool { return 'attachment' !== $type; } ) );
        if ( empty( $types ) ) {
            return $this->emptyReport();
        }

        $posts = get_posts(
            array(
                'post_type' => $types,
                'post_status' => 'publish',
                'numberposts' => self::LIMIT,
                'orderby' => 'modified',
                'order' => 'DESC',
                'suppress_filters' => false,
            )
        );
        if ( ! is_array( $posts ) || empty( $posts ) ) {
            return $this->emptyReport();
        }

        $analyzer = new ContentOptimizationAnalyzer();
        $good = 0;
        $attention = 0;
        $critical = 0;
        $withSummary = 0;
        $withSections = 0;

        foreach ( $posts as $post ) {
            if ( ! $post instanceof \WP_Post ) { continue; }
            $values = array(
                'focus_keyphrase' => (string) get_post_meta( $post->ID, '_yps_focus_keyphrase', true ),
                'focus_keyphrases' => get_post_meta( $post->ID, '_yps_focus_keyphrases_json', true ),
                'seo_title' => (string) get_post_meta( $post->ID, '_yps_seo_title', true ),
                'meta_description' => (string) get_post_meta( $post->ID, '_yps_meta_description', true ),
                'noindex' => (string) get_post_meta( $post->ID, '_yps_robots_noindex', true ),
                'schema_page_type' => (string) get_post_meta( $post->ID, '_yps_schema_page_type', true ),
                'schema_entity_type' => (string) get_post_meta( $post->ID, '_yps_schema_entity_type', true ),
                'schema_entity_name' => (string) get_post_meta( $post->ID, '_yps_schema_entity_name', true ),
                'og_image' => (string) get_post_meta( $post->ID, '_yps_og_image', true ),
            );
            $analysis = $analyzer->analyzePost( $post, $values );
            $dimension = isset( $analysis['dimensions']['answer'] ) && is_array( $analysis['dimensions']['answer'] ) ? $analysis['dimensions']['answer'] : array();
            $status = (string) ( $dimension['status'] ?? 'attention' );
            if ( 'good' === $status ) { ++$good; }
            elseif ( 'critical' === $status ) { ++$critical; }
            else { ++$attention; }

            if ( '' !== trim( (string) $values['meta_description'] ) || '' !== trim( (string) $post->post_excerpt ) ) { ++$withSummary; }
            $facts = isset( $analysis['facts'] ) && is_array( $analysis['facts'] ) ? $analysis['facts'] : array();
            if ( (int) ( $facts['heading_count'] ?? 0 ) >= 2 ) { ++$withSections; }
        }

        $sampled = $good + $attention + $critical;
        $report = array(
            'sampled' => $sampled,
            'good' => $good,
            'attention' => $attention,
            'critical' => $critical,
            'with_summary' => $withSummary,
            'with_sections' => $withSections,
            'limit' => self::LIMIT,
        );
        set_transient( 'yseo_aeo_readiness_v1', array( 'fingerprint' => $fingerprint, 'report' => $report ), HOUR_IN_SECONDS );
        return $report;
    }

    /** @return array<string,int|string> */
    private function emptyReport(): array {
        return array( 'sampled' => 0, 'good' => 0, 'attention' => 0, 'critical' => 0, 'with_summary' => 0, 'with_sections' => 0, 'limit' => self::LIMIT );
    }
}
