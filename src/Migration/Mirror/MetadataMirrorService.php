<?php
namespace Yablonsky\PerformanceSEO\Migration\Mirror;

use Yablonsky\PerformanceSEO\Migration\SafeSerializedValue;

use Yablonsky\PerformanceSEO\Migration\PostIdBatchReader;
use Yablonsky\PerformanceSEO\Migration\YoastFieldMap;
use Yablonsky\PerformanceSEO\Migration\YoastTaxonomyBatchReader;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastGlobalSettingsNormalizer;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastOptionSnapshotReader;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastPostMetaBatchReader;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastUserFieldMap;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastUserMetaBatchReader;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Exact, preserve-only Yoast -> YSEO shadow metadata mirror.
 *
 * It never writes Yoast source data and never overwrites a non-empty YSEO value.
 * The job is intentionally independent from takeover evidence so owners can mirror,
 * compare and edit metadata long before changing public authority.
 */
final class MetadataMirrorService {
    /** @var MetadataMirrorRepository */ private $repository;
    /** @var PostIdBatchReader */ private $posts;
    /** @var YoastPostMetaBatchReader */ private $postMeta;
    /** @var YoastUserMetaBatchReader */ private $userMeta;
    /** @var YoastTaxonomyBatchReader */ private $taxonomy;

    public function __construct( MetadataMirrorRepository $repository ) {
        $this->repository = $repository;
        $this->posts = new PostIdBatchReader();
        $this->postMeta = new YoastPostMetaBatchReader();
        $this->userMeta = new YoastUserMetaBatchReader();
        $this->taxonomy = new YoastTaxonomyBatchReader();
    }

    public function start( string $scope ): MetadataMirrorState {
        $scope = MetadataMirrorState::SCOPE_ALL === $scope ? MetadataMirrorState::SCOPE_ALL : MetadataMirrorState::SCOPE_SEARCH;
        $state = new MetadataMirrorState(
            array(
                'scope'   => $scope,
                'message' => MetadataMirrorState::SCOPE_ALL === $scope
                    ? 'Mirroring all mapped Yoast metadata into empty YSEO shadow fields. Existing YSEO values and all Yoast source data are preserved.'
                    : 'Mirroring existing Yoast search metadata into empty YSEO shadow fields. Existing YSEO values and all Yoast source data are preserved.',
            )
        );
        $this->repository->save( $state );
        return $state;
    }

    public function step(): MetadataMirrorState {
        $state = $this->repository->load();
        if ( null === $state ) { return $this->start( MetadataMirrorState::SCOPE_SEARCH ); }
        if ( MetadataMirrorState::STATUS_RUNNING !== $state->get( 'status' ) ) { return $state; }
        $phase = (string) $state->get( 'phase' );
        if ( 'posts' === $phase ) { $next = $this->stepPosts( $state ); }
        elseif ( 'users' === $phase ) { $next = $this->stepUsers( $state ); }
        elseif ( 'taxonomy' === $phase ) { $next = $this->stepTaxonomy( $state ); }
        elseif ( 'globals' === $phase ) { $next = $this->stepGlobals( $state ); }
        else { $next = $this->complete( $state ); }
        $this->repository->save( $next );
        return $next;
    }

    /** @return array<string,mixed> */
    public function inspectPost( int $postId ): array {
        $batch = $this->postMeta->read( array( $postId ) );
        $allMeta = isset( $batch[ $postId ] ) && is_array( $batch[ $postId ] ) ? $batch[ $postId ] : array();
        $result = array(
            'mapped' => 0, 'search_mapped' => 0,
            'yseo_present' => 0, 'search_yseo_present' => 0,
            'matched' => 0, 'different' => 0,
            'search_matched' => 0, 'search_different' => 0,
        );
        foreach ( $allMeta as $sourceKey => $values ) {
            $target = YoastFieldMap::targetForPostKey( (string) $sourceKey );
            if ( '' === $target || YoastFieldMap::isIgnoredDerivedPostKey( (string) $sourceKey ) ) { continue; }
            $source = isset( $values[0] ) ? SafeSerializedValue::decode( $values[0] ) : '';
            if ( ! is_scalar( $source ) || '' === trim( (string) $source ) ) { continue; }
            ++$result['mapped'];
            if ( $this->targetAllowed( $target, MetadataMirrorState::SCOPE_SEARCH ) ) { ++$result['search_mapped']; }
            $existing = (string) get_post_meta( $postId, $target, true );
            if ( '' !== trim( $existing ) ) {
                ++$result['yseo_present'];
                $isSearch = $this->targetAllowed( $target, MetadataMirrorState::SCOPE_SEARCH );
                if ( $isSearch ) { ++$result['search_yseo_present']; }
                if ( $existing === (string) $source ) {
                    ++$result['matched'];
                    if ( $isSearch ) { ++$result['search_matched']; }
                } else {
                    ++$result['different'];
                    if ( $isSearch ) { ++$result['search_different']; }
                }
            }
        }
        return $result;
    }

    /** @return array<string,int> */
    public function mirrorPost( int $postId, string $scope ): array {
        $scope = MetadataMirrorState::SCOPE_ALL === $scope ? MetadataMirrorState::SCOPE_ALL : MetadataMirrorState::SCOPE_SEARCH;
        $batch = $this->postMeta->read( array( $postId ) );
        $allMeta = isset( $batch[ $postId ] ) && is_array( $batch[ $postId ] ) ? $batch[ $postId ] : array();
        $counts = $this->zeroCounts();
        foreach ( $allMeta as $sourceKey => $values ) {
            $target = YoastFieldMap::targetForPostKey( (string) $sourceKey );
            if ( '' === $target || ! $this->targetAllowed( $target, $scope ) || YoastFieldMap::isIgnoredDerivedPostKey( (string) $sourceKey ) ) { continue; }
            $source = isset( $values[0] ) ? SafeSerializedValue::decode( $values[0] ) : '';
            $this->mirrorScalar( 'post', $postId, $target, $source, $counts );
        }
        return $counts;
    }

    private function stepPosts( MetadataMirrorState $state ): MetadataMirrorState {
        $ids = $this->posts->next( (int) $state->get( 'post_cursor' ), (int) $state->get( 'batch_size' ) );
        if ( empty( $ids ) ) { return $state->merge( array( 'phase' => 'users' ) ); }
        $batch = $this->postMeta->read( $ids );
        $counts = $this->countsFromState( $state );
        foreach ( $ids as $postId ) {
            $allMeta = isset( $batch[ $postId ] ) && is_array( $batch[ $postId ] ) ? $batch[ $postId ] : array();
            foreach ( $allMeta as $sourceKey => $values ) {
                $target = YoastFieldMap::targetForPostKey( (string) $sourceKey );
                if ( '' === $target || ! $this->targetAllowed( $target, (string) $state->get( 'scope' ) ) || YoastFieldMap::isIgnoredDerivedPostKey( (string) $sourceKey ) ) { continue; }
                $source = isset( $values[0] ) ? SafeSerializedValue::decode( $values[0] ) : '';
                $this->mirrorScalar( 'post', (int) $postId, $target, $source, $counts );
            }
        }
        return $state->merge( array_merge( $counts, array( 'post_cursor' => max( $ids ), 'processed_posts' => (int) $state->get( 'processed_posts' ) + count( $ids ) ) ) );
    }

    private function stepUsers( MetadataMirrorState $state ): MetadataMirrorState {
        $batch = $this->userMeta->next( (int) $state->get( 'user_meta_cursor' ), (int) $state->get( 'batch_size' ) );
        $rows = is_array( $batch['rows'] ) ? $batch['rows'] : array();
        if ( empty( $rows ) && $batch['complete'] ) { return $state->merge( array( 'phase' => 'taxonomy' ) ); }
        $counts = $this->countsFromState( $state );
        $users = array();
        foreach ( $rows as $row ) {
            $userId = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
            $sourceKey = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
            if ( $userId <= 0 || '' === $sourceKey || YoastUserFieldMap::isIgnoredDerived( $sourceKey ) ) { continue; }
            $target = YoastUserFieldMap::targetForKey( $sourceKey );
            if ( '' === $target || ! $this->targetAllowed( $target, (string) $state->get( 'scope' ) ) ) { continue; }
            $users[ $userId ] = true;
            $this->mirrorScalar( 'user', $userId, $target, SafeSerializedValue::decode( $row['meta_value'] ?? '' ), $counts );
        }
        return $state->merge( array_merge( $counts, array(
            'user_meta_cursor' => (int) $batch['cursor'],
            'processed_users'  => (int) $state->get( 'processed_users' ) + count( $users ),
            'phase'            => $batch['complete'] ? 'taxonomy' : 'users',
        ) ) );
    }

    private function stepTaxonomy( MetadataMirrorState $state ): MetadataMirrorState {
        $batch = $this->taxonomy->next( (string) $state->get( 'taxonomy_cursor' ), (int) $state->get( 'taxonomy_term_cursor' ), (int) $state->get( 'batch_size' ) );
        $records = is_array( $batch['records'] ) ? $batch['records'] : array();
        if ( empty( $records ) && $batch['complete'] ) { return $state->merge( array( 'phase' => 'globals' ) ); }
        $counts = $this->countsFromState( $state );
        $map = YoastFieldMap::taxonomyFields();
        foreach ( $records as $record ) {
            $termId = isset( $record['term_id'] ) ? (int) $record['term_id'] : 0;
            $fields = isset( $record['fields'] ) && is_array( $record['fields'] ) ? $record['fields'] : array();
            foreach ( $fields as $sourceKey => $source ) {
                if ( ! isset( $map[ $sourceKey ] ) || ! $this->targetAllowed( $map[ $sourceKey ], (string) $state->get( 'scope' ) ) ) { continue; }
                $this->mirrorScalar( 'term', $termId, $map[ $sourceKey ], $source, $counts );
            }
        }
        return $state->merge( array_merge( $counts, array(
            'taxonomy_cursor'      => (string) $batch['taxonomy_cursor'],
            'taxonomy_term_cursor' => (int) $batch['term_cursor'],
            'processed_terms'      => (int) $state->get( 'processed_terms' ) + count( $records ),
            'phase'                => $batch['complete'] ? 'globals' : 'taxonomy',
        ) ) );
    }

    private function stepGlobals( MetadataMirrorState $state ): MetadataMirrorState {
        $snapshot = ( new YoastOptionSnapshotReader() )->read( array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_llmstxt' ) );
        $normalized = ( new YoastGlobalSettingsNormalizer() )->normalize( $snapshot );
        $sourceValues = isset( $normalized['values'] ) && is_array( $normalized['values'] ) ? $normalized['values'] : array();
        $existing = get_option( 'yps_shadow_global_settings', array() );
        $existing = is_array( $existing ) ? $existing : array();
        $counts = $this->countsFromState( $state );
        $changed = false;
        foreach ( $sourceValues as $key => $value ) {
            if ( ! $this->globalAllowed( (string) $key, (string) $state->get( 'scope' ) ) || ! is_scalar( $value ) ) { continue; }
            if ( '' === trim( (string) $value ) ) { continue; }
            ++$counts['source_values'];
            if ( ! array_key_exists( $key, $existing ) || '' === trim( (string) $existing[ $key ] ) ) {
                $existing[ $key ] = $value;
                ++$counts['copied_values'];
                $changed = true;
            } elseif ( (string) $existing[ $key ] === (string) $value ) {
                ++$counts['matched_values'];
            } else {
                ++$counts['preserved_values'];
            }
        }
        if ( $changed ) { update_option( 'yps_shadow_global_settings', $existing, false ); }
        return $state->merge( array_merge( $counts, array( 'phase' => 'complete' ) ) );
    }

    private function complete( MetadataMirrorState $state ): MetadataMirrorState {
        return $state->merge( array(
            'status'  => MetadataMirrorState::STATUS_COMPLETE,
            'message' => sprintf(
                'Metadata mirror complete: %d exact source values copied, %d already matched, %d existing YSEO values preserved. Yoast source data and public authority were not changed.',
                (int) $state->get( 'copied_values' ),
                (int) $state->get( 'matched_values' ),
                (int) $state->get( 'preserved_values' )
            ),
        ) );
    }

    /** @param mixed $source @param array<string,int> $counts */
    private function mirrorScalar( string $type, int $id, string $target, $source, array &$counts ): void {
        if ( ! is_scalar( $source ) ) { ++$counts['invalid_values']; return; }
        $source = (string) $source;
        if ( '' === trim( $source ) ) { return; }
        ++$counts['source_values'];
        if ( 'post' === $type ) { $existing = get_post_meta( $id, $target, true ); }
        elseif ( 'term' === $type ) { $existing = get_term_meta( $id, $target, true ); }
        else { $existing = get_user_meta( $id, $target, true ); }
        if ( '' !== trim( (string) $existing ) ) {
            if ( (string) $existing === $source ) { ++$counts['matched_values']; }
            else { ++$counts['preserved_values']; }
            return;
        }
        if ( 'post' === $type ) { update_post_meta( $id, $target, $source ); $verified = (string) get_post_meta( $id, $target, true ) === $source; }
        elseif ( 'term' === $type ) { update_term_meta( $id, $target, $source ); $verified = (string) get_term_meta( $id, $target, true ) === $source; }
        else { update_user_meta( $id, $target, $source ); $verified = (string) get_user_meta( $id, $target, true ) === $source; }
        if ( $verified ) { ++$counts['copied_values']; } else { ++$counts['invalid_values']; }
    }

    private function targetAllowed( string $target, string $scope ): bool {
        if ( MetadataMirrorState::SCOPE_ALL === $scope ) { return true; }
        return in_array( $target, array(
            '_yps_focus_keyphrase', '_yps_focus_keyphrases_json', '_yps_keyphrase_synonyms_json',
            '_yps_seo_title', '_yps_meta_description', '_yps_canonical',
            '_yps_robots_noindex', '_yps_robots_nofollow', '_yps_robots_advanced',
            '_yps_author_seo_title', '_yps_author_meta_description', '_yps_author_robots_noindex',
        ), true );
    }

    private function globalAllowed( string $key, string $scope ): bool {
        if ( MetadataMirrorState::SCOPE_ALL === $scope ) { return true; }
        return 0 === strpos( $key, 'templates.' ) || 0 === strpos( $key, 'robots.' ) || 0 === strpos( $key, 'archives.' );
    }

    /** @return array<string,int> */
    private function zeroCounts(): array {
        return array( 'source_values' => 0, 'copied_values' => 0, 'matched_values' => 0, 'preserved_values' => 0, 'invalid_values' => 0 );
    }

    /** @return array<string,int> */
    private function countsFromState( MetadataMirrorState $state ): array {
        $counts = $this->zeroCounts();
        foreach ( array_keys( $counts ) as $key ) { $counts[ $key ] = (int) $state->get( $key ); }
        return $counts;
    }
}
