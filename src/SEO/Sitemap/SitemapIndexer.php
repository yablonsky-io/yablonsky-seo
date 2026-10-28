<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

use Yablonsky\PerformanceSEO\SEO\Freshness\FreshnessResolver;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;
use Yablonsky\PerformanceSEO\Discovery\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SitemapIndexer {
    private const OPTION_ELIGIBILITY_POLICY = 'yseo_sitemap_eligibility_policy_version';
    /** @var SitemapRepository */ private $repository;
    /** @var array<string,array<string,string>> */ private $languageMap = array();
    /** @var array<int,int> */ private $deletedPostAuthors = array();
    /** @var array<int,array<int,array{term_id:int,taxonomy:string}>> */ private $deletedPostTerms = array();
    /** @var FreshnessResolver */ private $freshness;
    /** @var array<int,bool> Post ID => collection surfaces also changed. */ private $pendingSemanticPosts = array();
    public function __construct( SitemapRepository $repository ) { $this->repository = $repository; $this->freshness = new FreshnessResolver(); }

    public function register(): void {
        $this->ensureEligibilityPolicyVersion();
        add_action( 'save_post', array( $this, 'syncPost' ), 30, 1 );
        add_action( 'save_post', array( $this, 'syncRelatedResources' ), 35, 1 );
        add_action( 'post_updated', array( $this, 'postUpdated' ), 30, 3 );
        add_action( 'transition_post_status', array( $this, 'postVisibilityChanged' ), 25, 3 );
        add_action( 'set_object_terms', array( $this, 'taxonomyAssignmentsChanged' ), 35, 6 );
        add_action( 'before_delete_post', array( $this, 'beforeDeletePost' ), 30, 1 );
        add_action( 'deleted_post', array( $this, 'deletePost' ), 30, 1 );
        add_action( 'created_term', array( $this, 'termFreshnessChanged' ), 20, 3 );
        add_action( 'edited_term', array( $this, 'termFreshnessChanged' ), 20, 3 );
        add_action( 'created_term', array( $this, 'syncTerm' ), 30, 3 );
        add_action( 'edited_term', array( $this, 'syncTerm' ), 30, 3 );
        add_action( 'delete_term', array( $this, 'deleteTerm' ), 30, 4 );
        add_action( 'profile_update', array( $this, 'userFreshnessChanged' ), 20, 1 );
        add_action( 'profile_update', array( $this, 'syncUser' ), 30, 1 );
        add_action( 'deleted_user', array( $this, 'deleteUser' ), 30, 1 );
        add_action( 'added_post_meta', array( $this, 'maybeSyncPostMeta' ), 30, 4 );
        add_action( 'updated_post_meta', array( $this, 'maybeSyncPostMeta' ), 30, 4 );
        add_action( 'deleted_post_meta', array( $this, 'maybeSyncPostMeta' ), 30, 4 );
        add_action( 'added_term_meta', array( $this, 'maybeSyncTermMeta' ), 30, 4 );
        add_action( 'updated_term_meta', array( $this, 'maybeSyncTermMeta' ), 30, 4 );
        add_action( 'deleted_term_meta', array( $this, 'maybeSyncTermMeta' ), 30, 4 );
        add_action( 'added_user_meta', array( $this, 'maybeSyncUserMeta' ), 30, 4 );
        add_action( 'updated_user_meta', array( $this, 'maybeSyncUserMeta' ), 30, 4 );
        add_action( 'deleted_user_meta', array( $this, 'maybeSyncUserMeta' ), 30, 4 );
        add_action( 'updated_option', array( $this, 'maybeRebuildForOption' ), 30, 3 );
        add_action( 'yablonsky_seo_sitemap_rebuild_v1', array( $this, 'forceRebuild' ) );
        add_action( 'yps_sitemap_backfill', array( $this, 'runBackfill' ) );
        add_action( 'shutdown', array( $this, 'flushPendingSemanticPosts' ), 20 );
        $event = wp_get_scheduled_event( 'yps_sitemap_backfill' );
        if ( $event && ! empty( $event->schedule ) ) { wp_clear_scheduled_hook( 'yps_sitemap_backfill' ); $event = false; }
        if ( ! $this->repository->isReady() && ! $event ) { wp_schedule_single_event( time() + 15, 'yps_sitemap_backfill' ); }
    }

    public function runBackfill( int $limit = 200 ): void {
        $state = $this->repository->backfillState();
        if ( ! empty( $state['complete'] ) ) { return; }
        $lock = $this->repository->acquireBackfillLock( 180 );
        if ( null === $lock ) { return; }

        try {
            $limit = max( 50, min( 500, $limit ) );
            $state = $this->repository->backfillState();
            if ( ! empty( $state['complete'] ) ) { return; }
            $phase = isset( $state['phase'] ) ? (string) $state['phase'] : 'posts';
            $cursor = isset( $state['cursor'] ) ? (int) $state['cursor'] : 0;
            global $wpdb;

            if ( 'posts' === $phase ) {
                // One-shot keyset batch. WP_Query would hydrate/filter far more state on large catalogs.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared bounded keyset scan of the core posts table for local sitemap warm-up.
                $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND post_status='publish' ORDER BY ID ASC LIMIT %d", $cursor, $limit ) );
                $this->primeLanguageMap( 'post', array_map( 'intval', $ids ) );
                foreach ( $ids as $id ) { $this->syncPost( (int) $id ); $cursor = (int) $id; }
                if ( count( $ids ) < $limit ) { $phase = 'terms'; $cursor = 0; }
            } elseif ( 'terms' === $phase ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared bounded keyset scan of the core terms table for local sitemap warm-up.
                $ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id>%d ORDER BY term_id ASC LIMIT %d", $cursor, $limit ) );
                $termIds = array_map( 'intval', $ids );
                $this->primeLanguageMap( 'term', $termIds );
                $this->freshness->primeTermMembers( $termIds );
                foreach ( $ids as $id ) {
                    $taxonomies = get_term( (int) $id );
                    if ( $taxonomies && ! is_wp_error( $taxonomies ) && isset( $taxonomies->taxonomy ) ) { $this->syncTerm( (int) $id, 0, (string) $taxonomies->taxonomy ); }
                    $cursor = (int) $id;
                }
                if ( count( $ids ) < $limit ) { $phase = 'users'; $cursor = 0; }
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared bounded keyset scan of the core users table for local sitemap warm-up.
                $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID>%d ORDER BY ID ASC LIMIT %d", $cursor, $limit ) );
                $userIds = array_map( 'intval', $ids );
                $this->primeLanguageMap( 'author', $userIds );
                $this->freshness->primeAuthorMembers( $userIds );
                foreach ( $ids as $id ) { $this->syncUser( (int) $id ); $cursor = (int) $id; }
                if ( count( $ids ) < $limit ) {
                    $this->repository->saveBackfillState( array( 'phase' => 'complete', 'cursor' => $cursor, 'complete' => true ) );
                    return;
                }
            }
            $this->repository->saveBackfillState( array( 'phase' => $phase, 'cursor' => $cursor, 'complete' => false ) );
            if ( ! wp_next_scheduled( 'yps_sitemap_backfill' ) ) { wp_schedule_single_event( time() + 15, 'yps_sitemap_backfill' ); }
        } finally {
            $this->repository->releaseBackfillLock( $lock );
        }
    }

    public function syncPost( int $postId ): void {
        if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) { return; }
        $post = get_post( $postId );
        if ( ! $post || 'publish' !== $post->post_status ) { $this->repository->deleteObject( 'post', $postId ); return; }
        $url = (string) get_permalink( $postId );
        $eligibility = ( new SitemapEligibilityPolicy() )->evaluatePost( $post, $url );
        if ( empty( $eligibility['eligible'] ) || ! ( new DiscoverySettings() )->familyEnabled( 'post', (string) $post->post_type ) ) { $this->repository->deleteObject( 'post', $postId ); return; }
        $indexable = ! $this->truthy( get_post_meta( $postId, '_yps_robots_noindex', true ) ) && ! $this->globalNoindex( 'post', (string) $post->post_type );
        $canonical = trim( (string) get_post_meta( $postId, '_yps_canonical', true ) );
        if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( (string) $url ) ) { $indexable = false; }
        $this->repository->upsert( array(
            'resource_key' => 'post:' . $postId,
            'resource_type' => 'post',
            'resource_subtype' => (string) $post->post_type,
            'object_id' => $postId,
            'language_key' => $this->languageKey( 'post', $postId ),
            'url' => (string) $url,
            'lastmod' => $this->freshness->postLastmod( $postId ),
            'indexable' => $indexable,
        ) );
    }

    public function postUpdated( int $postId, \WP_Post $postAfter, \WP_Post $postBefore ): void {
        unset( $postId );
        if ( (int) $postAfter->post_author !== (int) $postBefore->post_author ) {
            $this->freshness->touchAuthor( (int) $postBefore->post_author );
            $this->freshness->touchAuthor( (int) $postAfter->post_author );
            $this->syncUser( (int) $postBefore->post_author );
        }
        $this->syncUser( (int) $postAfter->post_author );
    }

    public function postVisibilityChanged( string $newStatus, string $oldStatus, \WP_Post $post ): void {
        if ( $newStatus === $oldStatus || ( 'publish' !== $newStatus && 'publish' !== $oldStatus ) ) { return; }
        if ( (int) $post->post_author > 0 ) {
            $this->freshness->touchAuthor( (int) $post->post_author );
            $this->syncUser( (int) $post->post_author );
        }
        foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
            $tax = get_taxonomy( $taxonomy );
            if ( ! $tax || empty( $tax->public ) ) { continue; }
            $terms = get_the_terms( $post->ID, $taxonomy );
            if ( ! is_array( $terms ) ) { continue; }
            foreach ( $terms as $term ) {
                if ( ! $term instanceof \WP_Term ) { continue; }
                $this->freshness->touchTerm( (int) $term->term_id );
                $this->syncTerm( (int) $term->term_id, 0, (string) $term->taxonomy );
            }
        }
        $this->freshness->touchArchiveAndSite( (string) $post->post_type );
    }

    public function beforeDeletePost( int $postId ): void {
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post ) { return; }
        $this->deletedPostAuthors[ $postId ] = (int) $post->post_author;
        if ( (int) $post->post_author > 0 ) { $this->freshness->touchAuthor( (int) $post->post_author ); }
        $this->freshness->touchArchiveAndSite( (string) $post->post_type );
        $this->deletedPostTerms[ $postId ] = array();
        foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
            $tax = get_taxonomy( $taxonomy );
            if ( ! $tax || empty( $tax->public ) ) { continue; }
            $terms = get_the_terms( $postId, $taxonomy );
            if ( ! is_array( $terms ) ) { continue; }
            foreach ( $terms as $term ) {
                if ( ! $term instanceof \WP_Term ) { continue; }
                $this->freshness->touchTerm( (int) $term->term_id );
                $this->deletedPostTerms[ $postId ][] = array( 'term_id' => (int) $term->term_id, 'taxonomy' => (string) $term->taxonomy );
            }
        }
    }

    public function deletePost( int $postId ): void {
        $this->repository->deleteObject( 'post', $postId );
        if ( isset( $this->deletedPostAuthors[ $postId ] ) ) { $this->syncUser( (int) $this->deletedPostAuthors[ $postId ] ); unset( $this->deletedPostAuthors[ $postId ] ); }
        foreach ( $this->deletedPostTerms[ $postId ] ?? array() as $term ) { $this->syncTerm( (int) $term['term_id'], 0, (string) $term['taxonomy'] ); }
        unset( $this->deletedPostTerms[ $postId ] );
    }

    public function syncTerm( int $termId, int $ttId = 0, string $taxonomy = '' ): void {
        unset( $ttId );
        $term = get_term( $termId, $taxonomy ?: '' );
        if ( ! $term || is_wp_error( $term ) ) { return; }
        $taxonomy = (string) $term->taxonomy;
        $tax = get_taxonomy( $taxonomy );
        if ( ! $tax || empty( $tax->public ) || ! ( new DiscoverySettings() )->familyEnabled( 'term', $taxonomy ) ) { $this->repository->deleteObject( 'term', $termId ); return; }
        $url = get_term_link( $term );
        if ( is_wp_error( $url ) ) { return; }
        $indexable = ! $this->truthy( get_term_meta( $termId, '_yps_robots_noindex', true ) ) && ! $this->globalNoindex( 'term', $taxonomy );
        $canonical = trim( (string) get_term_meta( $termId, '_yps_canonical', true ) );
        if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( (string) $url ) ) { $indexable = false; }
        $this->repository->upsert( array(
            'resource_key' => 'term:' . $taxonomy . ':' . $termId,
            'resource_type' => 'term', 'resource_subtype' => $taxonomy, 'object_id' => $termId,
            'language_key' => $this->languageKey( 'term', $termId ), 'url' => (string) $url,
            'lastmod' => $this->freshness->termLastmod( $termId, $taxonomy ), 'indexable' => $indexable,
        ) );
    }

    public function deleteTerm( int $termId, int $ttId = 0, string $taxonomy = '', $deletedTerm = null ): void { unset( $ttId, $taxonomy, $deletedTerm ); $this->repository->deleteObject( 'term', $termId ); }

    public function syncUser( int $userId ): void {
        if ( ! ( new DiscoverySettings() )->familyEnabled( 'author', 'author' ) ) { $this->repository->deleteObject( 'author', $userId ); return; }
        $url = get_author_posts_url( $userId );
        $publicTypes = array_values( get_post_types( array( 'public' => true ), 'names' ) );
        $count = count_user_posts( $userId, $publicTypes, true );
        $indexable = $count > 0 && ! $this->truthy( get_user_meta( $userId, '_yps_author_robots_noindex', true ) ) && ! $this->globalNoindex( 'author', 'author' );
        $this->repository->upsert( array(
            'resource_key' => 'author:' . $userId, 'resource_type' => 'author', 'resource_subtype' => 'author',
            'object_id' => $userId, 'language_key' => $this->languageKey( 'author', $userId ), 'url' => (string) $url,
            'lastmod' => $this->freshness->authorLastmod( $userId ), 'indexable' => $indexable,
        ) );
    }
    public function deleteUser( int $userId ): void { $this->repository->deleteObject( 'author', $userId ); }

    public function maybeSyncPostMeta( $metaId, $postId, $metaKey, $value ): void {
        unset( $metaId, $value );
        $metaKey = (string) $metaKey;
        if ( FreshnessResolver::isSemanticPostMeta( $metaKey ) ) {
            $targetId = $this->semanticParentPostId( (int) $postId );
            if ( ! array_key_exists( $targetId, $this->pendingSemanticPosts ) ) {
                $this->freshness->touchPost( $targetId );
                $this->pendingSemanticPosts[ $targetId ] = false;
            }
            if ( FreshnessResolver::isCollectionSemanticPostMeta( $metaKey ) && ! $this->pendingSemanticPosts[ $targetId ] ) {
                $this->freshness->touchPostCollections( $targetId );
                $this->pendingSemanticPosts[ $targetId ] = true;
            }
        }
    }

    public function maybeSyncTermMeta( $metaId, $termId, $metaKey, $value ): void {
        unset( $metaId, $value );
        if ( 0 === strpos( (string) $metaKey, '_yps_' ) ) {
            $this->freshness->touchTerm( (int) $termId );
            $this->syncTerm( (int) $termId );
        }
    }

    public function maybeSyncUserMeta( $metaId, $userId, $metaKey, $value ): void {
        unset( $metaId, $value );
        if ( 0 === strpos( (string) $metaKey, '_yps_' ) ) {
            $this->freshness->touchAuthor( (int) $userId );
            $this->syncUser( (int) $userId );
        }
    }

    public function termFreshnessChanged( int $termId, int $ttId = 0, string $taxonomy = '' ): void {
        unset( $ttId, $taxonomy );
        $this->freshness->touchTerm( $termId );
    }

    public function userFreshnessChanged( int $userId ): void {
        $this->freshness->touchAuthor( $userId );
    }

    public function syncRelatedResources( int $postId ): void {
        if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) { return; }
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) { return; }
        $this->syncUser( (int) $post->post_author );
        foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
            $terms = get_the_terms( $postId, $taxonomy );
            if ( ! is_array( $terms ) ) { continue; }
            foreach ( $terms as $term ) {
                if ( $term instanceof \WP_Term ) { $this->syncTerm( (int) $term->term_id, 0, (string) $term->taxonomy ); }
            }
        }
    }

    /** @param array<int,int> $ttIds @param array<int,int> $oldTtIds */
    public function taxonomyAssignmentsChanged( int $objectId, $terms, array $ttIds, string $taxonomy, bool $append, array $oldTtIds ): void {
        unset( $terms, $append );
        $post = get_post( $objectId );
        if ( ! $post instanceof \WP_Post ) { return; }
        $this->freshness->touchPost( $objectId );
        $this->syncPost( $objectId );
        $all = array_values( array_unique( array_merge( $ttIds, $oldTtIds ) ) );
        foreach ( $all as $ttId ) {
            $term = get_term_by( 'term_taxonomy_id', (int) $ttId, $taxonomy );
            if ( $term instanceof \WP_Term ) {
                $this->freshness->touchTerm( (int) $term->term_id );
                $this->syncTerm( (int) $term->term_id, 0, $taxonomy );
            }
        }
    }

    public function flushPendingSemanticPosts(): void {
        $pending = $this->pendingSemanticPosts;
        $this->pendingSemanticPosts = array();
        foreach ( $pending as $postId => $collectionsChanged ) {
            $this->syncPost( (int) $postId );
            if ( $collectionsChanged ) { $this->syncRelatedResources( (int) $postId ); }
        }
    }

    private function semanticParentPostId( int $postId ): int {
        $post = get_post( $postId );
        if ( $post instanceof \WP_Post && 'product_variation' === $post->post_type && (int) $post->post_parent > 0 ) { return (int) $post->post_parent; }
        return $postId;
    }

    /** @param array<int,int> $ids */
    private function primeLanguageMap( string $type, array $ids ): void {
        $requests = array();
        foreach ( $ids as $id ) { $requests[] = array( 'resource_type' => $type, 'object_id' => $id ); }
        $map = apply_filters( 'yablonsky_seo_sitemap_language_map_v1', array(), $requests );
        $this->languageMap = is_array( $map ) ? $map : array();
    }

    private function languageKey( string $type, int $id ): string {
        $key = $type . ':' . $id;
        if ( isset( $this->languageMap[ $key ]['language_key'] ) ) {
            $language = ( new ResourceLanguageResolver() )->normalize( (string) $this->languageMap[ $key ]['language_key'] );
            if ( '' !== $language ) { return $language; }
        }
        $request = array( 'resource_type' => $type, 'object_id' => $id );
        if ( 'term' === $type ) {
            $term = get_term( $id );
            if ( $term instanceof \WP_Term ) { $request['resource_subtype'] = (string) $term->taxonomy; $request['taxonomy'] = (string) $term->taxonomy; }
        }
        $map = apply_filters( 'yablonsky_seo_sitemap_language_map_v1', array(), array( $request ) );
        if ( is_array( $map ) && isset( $map[ $key ]['language_key'] ) ) {
            $language = ( new ResourceLanguageResolver() )->normalize( (string) $map[ $key ]['language_key'] );
            if ( '' !== $language ) { return $language; }
        }

        $url = '';
        if ( 'post' === $type ) { $url = (string) get_permalink( $id ); }
        elseif ( 'term' === $type ) { $termUrl = get_term_link( $id ); $url = is_wp_error( $termUrl ) ? '' : (string) $termUrl; }
        elseif ( 'author' === $type ) { $url = (string) get_author_posts_url( $id ); }
        $resolved = ( new ResourceLanguageResolver() )->resolve( $type, $id, $url, false );
        $language = (string) ( $resolved['language_key'] ?? 'auto' );
        return '' !== $language ? $language : 'auto';
    }

    public function maybeRebuildForOption( string $option, $oldValue, $value ): void {
        unset( $oldValue, $value );
        if ( in_array( $option, array( 'permalink_structure', 'home', 'siteurl', 'yps_shadow_global_settings' ), true ) ) { $this->forceRebuild(); }
    }

    public function forceRebuild(): void { $this->languageMap = array(); $this->repository->truncateAndReset(); if ( ! wp_next_scheduled( 'yps_sitemap_backfill' ) ) { wp_schedule_single_event( time() + 5, 'yps_sitemap_backfill' ); } }

    private function ensureEligibilityPolicyVersion(): void {
        $current = (string) get_option( self::OPTION_ELIGIBILITY_POLICY, '' );
        if ( SitemapEligibilityPolicy::VERSION === $current ) { return; }
        $this->languageMap = array();
        $this->repository->truncateAndReset();
        update_option( self::OPTION_ELIGIBILITY_POLICY, SitemapEligibilityPolicy::VERSION, false );
    }

    private function globalNoindex( string $type, string $subtype ): bool {
        $globals = get_option( 'yps_shadow_global_settings', array() );
        if ( ! is_array( $globals ) ) { return false; }
        $key = 'author' === $type ? 'robots.author.noindex' : 'robots.' . $subtype . '.noindex';
        return isset( $globals[ $key ] ) && $this->truthy( $globals[ $key ] );
    }

    private function truthy( $value ): bool { return in_array( strtolower( trim( (string) $value ) ), array( '1','true','yes','on','noindex' ), true ); }
}
