<?php
namespace Yablonsky\PerformanceSEO\SEO\InternalLinks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class InternalLinkSuggestionService {
    /** @var InternalLinkRepository */ private $repository;
    public function __construct( ?InternalLinkRepository $repository = null ) { $this->repository = $repository instanceof InternalLinkRepository ? $repository : new InternalLinkRepository(); }

    /** @return array<int,array<string,mixed>> */
    public function suggestionsForPost( int $postId, int $limit = 8 ): array {
        $source = get_post( $postId );
        if ( ! $source instanceof \WP_Post ) { return array(); }
        $limit = min( 12, max( 1, $limit ) );
        $candidateIds = $this->candidateIds( $source );
        $already = array_keys( $this->repository->outboundTargetIds( $postId ) );
        $sourceTerms = $this->termIds( $postId, $source->post_type );
        $sourceTokens = $this->titleTokens( get_the_title( $source ) );
        $boundedCandidateIds = array_slice( $candidateIds, 0, 120 );
        $termMap = $this->termMap( $boundedCandidateIds );
        update_meta_cache( 'post', $boundedCandidateIds );
        _prime_post_caches( $boundedCandidateIds, false, false );
        $rows = array();
        foreach ( $boundedCandidateIds as $candidateId ) {
            if ( $candidateId === $postId || in_array( $candidateId, $already, true ) ) { continue; }
            if ( '1' === (string) get_post_meta( $candidateId, '_yps_robots_noindex', true ) ) { continue; }
            $candidate = get_post( $candidateId );
            if ( ! $candidate instanceof \WP_Post || 'publish' !== $candidate->post_status ) { continue; }
            $candidateTerms = isset( $termMap[ $candidateId ] ) ? $termMap[ $candidateId ] : array();
            $sharedTerms = count( array_intersect( $sourceTerms, $candidateTerms ) );
            $sharedWords = count( array_intersect( $sourceTokens, $this->titleTokens( get_the_title( $candidate ) ) ) );
            $score = ( $sharedTerms * 10 ) + ( $sharedWords * 3 ) + ( $candidate->post_type === $source->post_type ? 2 : 0 ) + ( '1' === (string) get_post_meta( $candidateId, '_yps_is_cornerstone', true ) ? 5 : 0 );
            if ( $score <= 0 ) { continue; }
            $rows[] = array(
                'post_id' => $candidateId,
                'title' => get_the_title( $candidateId ),
                'url' => get_permalink( $candidateId ),
                'post_type' => $candidate->post_type,
                'score' => $score,
                'shared_terms' => $sharedTerms,
                'shared_title_words' => $sharedWords,
            );
        }
        usort( $rows, static function( array $a, array $b ): int { return (int) $b['score'] <=> (int) $a['score']; } );
        return array_slice( $rows, 0, $limit );
    }

    /** @return array<int,int> */
    private function candidateIds( \WP_Post $source ): array {
        $ids = array();
        $terms = $this->termIds( $source->ID, $source->post_type );
        if ( ! empty( $terms ) ) {
            global $wpdb;
            $placeholders = implode( ',', array_fill( 0, count( $terms ), '%d' ) );
            $types = array_values( array_filter( array_diff( array_values( get_post_types( array( 'public' => true ), 'names' ) ), array( 'attachment' ) ) ) );
            $typePlaceholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
            if ( ! empty( $types ) ) {
                $sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.term_id IN ({$placeholders}) AND p.ID<>%d AND p.post_status='publish' AND p.post_type IN ({$typePlaceholders}) ORDER BY p.post_modified_gmt DESC LIMIT 100";
                $args = array_merge( $terms, array( $source->ID ), $types );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Registry/term-derived values are prepared; bounded editor-only candidate discovery.
                $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
            }
        }
        $recent = get_posts(
            array(
                'post_type' => array_values( array_filter( array_diff( array_values( get_post_types( array( 'public' => true ), 'names' ) ), array( 'attachment' ) ) ) ),
                'post_status' => 'publish',
                'posts_per_page' => 40,
                'orderby' => 'modified',
                'order' => 'DESC',
                'fields' => 'ids',
                'suppress_filters' => false,
            )
        );
        return array_values( array_unique( array_merge( $ids, array_map( 'intval', (array) $recent ) ) ) );
    }

    /** @param array<int,int> $postIds @return array<int,array<int,int>> */
    private function termMap( array $postIds ): array {
        $postIds = array_values( array_unique( array_filter( array_map( 'intval', $postIds ) ) ) );
        if ( empty( $postIds ) ) { return array(); }
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $postIds ), '%d' ) );
        $sql = "SELECT tr.object_id,tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id IN ({$placeholders}) ORDER BY tr.object_id,tt.term_id";
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded candidate IDs are prepared; one batch replaces per-candidate taxonomy queries.
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $postIds ), ARRAY_A );
        $map = array();
        foreach ( $rows as $row ) {
            $objectId = (int) ( $row['object_id'] ?? 0 );
            $termId = (int) ( $row['term_id'] ?? 0 );
            if ( $objectId > 0 && $termId > 0 ) { $map[ $objectId ][] = $termId; }
        }
        foreach ( $map as $objectId => $ids ) { $map[ $objectId ] = array_values( array_unique( array_map( 'intval', $ids ) ) ); }
        return $map;
    }

    /** @return array<int,int> */
    private function termIds( int $postId, string $postType ): array {
        $taxonomies = get_object_taxonomies( $postType, 'names' );
        if ( empty( $taxonomies ) ) { return array(); }
        $ids = wp_get_object_terms( $postId, $taxonomies, array( 'fields' => 'ids' ) );
        return is_wp_error( $ids ) ? array() : array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
    }

    /** @return array<int,string> */
    private function titleTokens( string $title ): array {
        $title = strtolower( wp_strip_all_tags( remove_accents( $title ) ) );
        $parts = preg_split( '/[^\p{L}\p{N}]+/u', $title );
        $parts = is_array( $parts ) ? $parts : array();
        return array_values( array_unique( array_filter( $parts, static function( string $part ): bool { return function_exists( 'mb_strlen' ) ? mb_strlen( $part ) >= 3 : strlen( $part ) >= 3; } ) ) );
    }
}
