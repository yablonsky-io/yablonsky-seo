<?php
namespace Yablonsky\PerformanceSEO\Migration\Portability;

use Yablonsky\PerformanceSEO\Migration\PostIdBatchReader;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Preserve-only provider -> YSEO metadata portability.
 *
 * Source providers remain public authority. Source data is never modified,
 * existing non-empty YSEO values are never overwritten, and this service has
 * no cutover/takeover side effects.
 */
final class PortableMirrorService {
    /** @var PortableMirrorRepository */ private $repository;
    /** @var PortableSourceRegistry */ private $registry;
    /** @var PostIdBatchReader */ private $posts;
    /** @var PortableTermBatchReader */ private $terms;

    public function __construct( PortableMirrorRepository $repository, ?PortableSourceRegistry $registry = null ) {
        $this->repository = $repository;
        $this->registry = $registry ?: new PortableSourceRegistry();
        $this->posts = new PostIdBatchReader();
        $this->terms = new PortableTermBatchReader();
    }

    public function start( string $providerId ): PortableMirrorState {
        $profile = $this->registry->get( $providerId );
        if ( null === $profile ) {
            $state = new PortableMirrorState( array( 'status' => PortableMirrorState::STATUS_BLOCKED, 'provider_id' => sanitize_key( $providerId ), 'message' => __( 'Preserve-only metadata portability activates when a verified contract is available for this provider.', 'yablonsky-seo' ) ) );
            $this->repository->save( $state );
            return $state;
        }
        $state = new PortableMirrorState(
            array(
                'provider_id'    => $profile->id(),
                'provider_label' => $profile->label(),
                'message'        => __( 'Copying only verified explicit source metadata into empty YSEO shadow fields. Source data and public authority remain unchanged.', 'yablonsky-seo' ),
            )
        );
        $this->repository->save( $state );
        return $state;
    }

    public function step(): PortableMirrorState {
        $state = $this->repository->load();
        if ( null === $state ) { return new PortableMirrorState( array( 'status' => PortableMirrorState::STATUS_BLOCKED, 'message' => __( 'Start a portability mirror first.', 'yablonsky-seo' ) ) ); }
        if ( PortableMirrorState::STATUS_RUNNING !== $state->get( 'status' ) ) { return $state; }
        $profile = $this->registry->get( (string) $state->get( 'provider_id' ) );
        if ( null === $profile ) { return $this->save( $state->merge( array( 'status' => PortableMirrorState::STATUS_BLOCKED, 'message' => __( 'The saved provider portability contract is no longer available.', 'yablonsky-seo' ) ) ) ); }
        if ( 'posts' === (string) $state->get( 'phase' ) ) { return $this->save( $this->stepPosts( $state, $profile ) ); }
        if ( 'terms' === (string) $state->get( 'phase' ) ) { return $this->save( $this->stepTerms( $state, $profile ) ); }
        return $this->save( $state->merge( array( 'status' => PortableMirrorState::STATUS_COMPLETE, 'phase' => 'complete', 'message' => __( 'Preserve-only metadata portability completed while preserving source provider authority and source storage.', 'yablonsky-seo' ) ) ) );
    }

    private function stepPosts( PortableMirrorState $state, PortableSourceProfile $profile ): PortableMirrorState {
        if ( ! $profile->supportsPosts() ) { return $state->merge( array( 'phase' => $profile->supportsTerms() ? 'terms' : 'complete' ) ); }
        $ids = $this->posts->next( (int) $state->get( 'post_cursor' ), (int) $state->get( 'batch_size' ) );
        if ( empty( $ids ) ) { return $state->merge( array( 'phase' => $profile->supportsTerms() ? 'terms' : 'complete' ) ); }
        $reader = new PortableMetadataReader( $profile );
        $reader->primePosts( $ids );
        $counts = $this->counts( $state );
        foreach ( $ids as $postId ) { $this->mirrorValues( 'post', (int) $postId, $reader->postValues( (int) $postId ), $counts ); }
        return $state->merge( array_merge( $counts, array( 'post_cursor' => max( $ids ), 'processed_posts' => (int) $state->get( 'processed_posts' ) + count( $ids ) ) ) );
    }

    private function stepTerms( PortableMirrorState $state, PortableSourceProfile $profile ): PortableMirrorState {
        if ( ! $profile->supportsTerms() ) { return $state->merge( array( 'phase' => 'complete' ) ); }
        $ids = $this->terms->next( (int) $state->get( 'term_cursor' ), (int) $state->get( 'batch_size' ) );
        if ( empty( $ids ) ) { return $state->merge( array( 'phase' => 'complete' ) ); }
        $reader = new PortableMetadataReader( $profile );
        $reader->primeTerms( $ids );
        $counts = $this->counts( $state );
        foreach ( $ids as $termId ) { $this->mirrorValues( 'term', (int) $termId, $reader->termValues( (int) $termId ), $counts ); }
        return $state->merge( array_merge( $counts, array( 'term_cursor' => max( $ids ), 'processed_terms' => (int) $state->get( 'processed_terms' ) + count( $ids ) ) ) );
    }

    /** @param array<string,mixed> $values @param array<string,int> $counts */
    private function mirrorValues( string $type, int $id, array $values, array &$counts ): void {
        foreach ( $values as $target => $source ) {
            // Defense in depth: preserve-only portability may write only YSEO-owned metadata.
            if ( 0 !== strpos( (string) $target, '_yps_' ) ) { ++$counts['invalid_values']; continue; }
            if ( ! is_scalar( $source ) ) { ++$counts['invalid_values']; continue; }
            $source = (string) $source;
            if ( '' === trim( $source ) ) { continue; }
            ++$counts['source_values'];
            $existing = 'term' === $type ? get_term_meta( $id, $target, true ) : get_post_meta( $id, $target, true );
            if ( '' !== trim( (string) $existing ) ) {
                if ( (string) $existing === $source ) { ++$counts['matched_values']; } else { ++$counts['preserved_values']; }
                continue;
            }
            if ( 'term' === $type ) { update_term_meta( $id, $target, $source ); $verified = (string) get_term_meta( $id, $target, true ) === $source; }
            else { update_post_meta( $id, $target, $source ); $verified = (string) get_post_meta( $id, $target, true ) === $source; }
            if ( $verified ) { ++$counts['copied_values']; } else { ++$counts['invalid_values']; }
        }
    }

    /** @return array<string,int> */
    private function counts( PortableMirrorState $state ): array {
        $out = array();
        foreach ( array( 'source_values', 'copied_values', 'matched_values', 'preserved_values', 'invalid_values' ) as $key ) { $out[ $key ] = (int) $state->get( $key ); }
        return $out;
    }

    private function save( PortableMirrorState $state ): PortableMirrorState { $this->repository->save( $state ); return $state; }
}
