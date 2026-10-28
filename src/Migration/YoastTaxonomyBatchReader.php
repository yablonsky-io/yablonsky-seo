<?php
namespace Yablonsky\PerformanceSEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class YoastTaxonomyBatchReader {
    /**
     * @return array{records:array<int,array<string,mixed>>,taxonomy_cursor:string,term_cursor:int,complete:bool}
     */
    public function next( string $taxonomyCursor, int $termCursor, int $limit ): array {
        $snapshot = ( new \Yablonsky\PerformanceSEO\Migration\Yoast\YoastOptionSnapshotReader() )->read( array( 'wpseo_taxonomy_meta' ) );
        $raw = $snapshot['wpseo_taxonomy_meta'] ?? array();
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return array( 'records' => array(), 'taxonomy_cursor' => '', 'term_cursor' => 0, 'complete' => true );
        }

        $taxonomies = array_keys( $raw );
        sort( $taxonomies, SORT_STRING );
        $limit = max( 1, min( 500, $limit ) );
        $records = array();
        $started = '' === $taxonomyCursor;
        $lastTaxonomy = $taxonomyCursor;
        $lastTerm = $termCursor;

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! $started ) {
                if ( $taxonomy !== $taxonomyCursor ) {
                    continue;
                }
                $started = true;
            }
            $terms = isset( $raw[ $taxonomy ] ) && is_array( $raw[ $taxonomy ] ) ? $raw[ $taxonomy ] : array();
            $termIds = array_map( 'intval', array_keys( $terms ) );
            sort( $termIds, SORT_NUMERIC );
            foreach ( $termIds as $termId ) {
                if ( $taxonomy === $taxonomyCursor && $termId <= $termCursor ) {
                    continue;
                }
                $fields = isset( $terms[ $termId ] ) && is_array( $terms[ $termId ] ) ? $terms[ $termId ] : array();
                $records[] = array(
                    'taxonomy' => (string) $taxonomy,
                    'term_id'  => $termId,
                    'fields'   => $fields,
                );
                $lastTaxonomy = (string) $taxonomy;
                $lastTerm = $termId;
                if ( count( $records ) >= $limit ) {
                    return array(
                        'records'         => $records,
                        'taxonomy_cursor' => $lastTaxonomy,
                        'term_cursor'     => $lastTerm,
                        'complete'        => false,
                    );
                }
            }
            $termCursor = 0;
        }

        return array(
            'records'         => $records,
            'taxonomy_cursor' => $lastTaxonomy,
            'term_cursor'     => $lastTerm,
            'complete'        => true,
        );
    }
}
