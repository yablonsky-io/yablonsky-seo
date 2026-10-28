<?php
namespace Yablonsky\PerformanceSEO\SEO\Freshness;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Resolves truthful machine freshness without inventing crawl-frequency signals.
 * Stored markers cover semantic changes WordPress core does not timestamp itself;
 * collection resources also inherit the freshest public member modification.
 */
final class FreshnessResolver {
    private const POST_META = '_yseo_semantic_modified_gmt';
    private const TERM_META = '_yseo_term_modified_gmt';
    private const AUTHOR_META = '_yseo_author_modified_gmt';
    private const COLLECTION_OPTION = 'yseo_collection_freshness_v1';

    /** @var array<string,string> */
    private $cache = array();
    /** @var array<int,string> */
    private $termMemberCache = array();
    /** @var array<int,string> */
    private $authorMemberCache = array();

    public function postLastmod( int $postId ): string {
        $key = 'post:' . $postId;
        if ( isset( $this->cache[ $key ] ) ) { return $this->cache[ $key ]; }
        $core = (string) get_post_modified_time( 'Y-m-d H:i:s', true, $postId );
        $semantic = (string) get_post_meta( $postId, self::POST_META, true );
        return $this->cache[ $key ] = $this->latestMysqlUtc( array( $core, $semantic ) );
    }

    public function termLastmod( int $termId, string $taxonomy = '' ): string {
        $taxonomy = sanitize_key( $taxonomy );
        $key = 'term:' . $taxonomy . ':' . $termId;
        if ( isset( $this->cache[ $key ] ) ) { return $this->cache[ $key ]; }
        $marker = (string) get_term_meta( $termId, self::TERM_META, true );
        $member = $this->latestTermMember( $termId, $taxonomy );
        return $this->cache[ $key ] = $this->latestMysqlUtc( array( $marker, $member ) );
    }

    public function authorLastmod( int $userId ): string {
        $key = 'author:' . $userId;
        if ( isset( $this->cache[ $key ] ) ) { return $this->cache[ $key ]; }
        $marker = (string) get_user_meta( $userId, self::AUTHOR_META, true );
        $member = $this->latestAuthorMember( $userId );
        return $this->cache[ $key ] = $this->latestMysqlUtc( array( $marker, $member ) );
    }

    public function postTypeArchiveLastmod( string $postType ): string {
        $postType = sanitize_key( $postType );
        $key = 'archive:' . $postType;
        if ( isset( $this->cache[ $key ] ) ) { return $this->cache[ $key ]; }
        if ( '' === $postType || ! $this->isPublicPostType( $postType ) ) { return $this->cache[ $key ] = ''; }
        $markers = $this->collectionMarkers();
        $marker = (string) ( $markers[ 'archive:' . $postType ] ?? '' );
        return $this->cache[ $key ] = $this->latestMysqlUtc( array( $this->latestPostTypeMember( $postType ), $marker ) );
    }

    public function siteLastmod(): string {
        $key = 'site';
        if ( isset( $this->cache[ $key ] ) ) { return $this->cache[ $key ]; }
        $types = $this->publicPostTypes();
        if ( empty( $types ) ) { return $this->cache[ $key ] = ''; }
        $markers = $this->collectionMarkers();
        return $this->cache[ $key ] = $this->latestMysqlUtc( array( $this->latestPublicPostModified( $types ), (string) ( $markers['site'] ?? '' ) ) );
    }

    /** @param array<int,int> $termIds */
    public function primeTermMembers( array $termIds ): void {
        $termIds = array_values( array_unique( array_filter( array_map( 'intval', $termIds ) ) ) );
        if ( empty( $termIds ) ) { return; }
        $types = $this->publicPostTypes();
        if ( empty( $types ) ) { return; }
        update_meta_cache( 'term', $termIds );
        global $wpdb;
        $idPlaceholders = implode( ',', array_fill( 0, count( $termIds ), '%d' ) );
        $typePlaceholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT tt.term_id,MAX(p.post_modified_gmt) AS lastmod FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.term_id IN ({$idPlaceholders}) AND p.post_status='publish' AND p.post_type IN ({$typePlaceholders}) GROUP BY tt.term_id";
        $args = array_merge( $termIds, $types );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded backfill batch over core relationship tables; all IDs/types are prepared.
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        foreach ( $termIds as $termId ) { $this->termMemberCache[ $termId ] = ''; }
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $termId = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;
            if ( $termId > 0 ) { $this->termMemberCache[ $termId ] = isset( $row['lastmod'] ) ? (string) $row['lastmod'] : ''; }
        }
    }

    /** @param array<int,int> $userIds */
    public function primeAuthorMembers( array $userIds ): void {
        $userIds = array_values( array_unique( array_filter( array_map( 'intval', $userIds ) ) ) );
        if ( empty( $userIds ) ) { return; }
        $types = $this->publicPostTypes();
        if ( empty( $types ) ) { return; }
        update_meta_cache( 'user', $userIds );
        global $wpdb;
        $idPlaceholders = implode( ',', array_fill( 0, count( $userIds ), '%d' ) );
        $typePlaceholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT post_author,MAX(post_modified_gmt) AS lastmod FROM {$wpdb->posts} WHERE post_author IN ({$idPlaceholders}) AND post_status='publish' AND post_type IN ({$typePlaceholders}) GROUP BY post_author";
        $args = array_merge( $userIds, $types );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded backfill author batch over core posts table; all IDs/types are prepared.
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        foreach ( $userIds as $userId ) { $this->authorMemberCache[ $userId ] = ''; }
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $userId = isset( $row['post_author'] ) ? (int) $row['post_author'] : 0;
            if ( $userId > 0 ) { $this->authorMemberCache[ $userId ] = isset( $row['lastmod'] ) ? (string) $row['lastmod'] : ''; }
        }
    }

    public function touchPost( int $postId ): void {
        if ( $postId <= 0 ) { return; }
        update_post_meta( $postId, self::POST_META, current_time( 'mysql', true ) );
        unset( $this->cache[ 'post:' . $postId ], $this->cache['site'] );
    }

    public function touchTerm( int $termId ): void {
        if ( $termId <= 0 ) { return; }
        update_term_meta( $termId, self::TERM_META, current_time( 'mysql', true ) );
        unset( $this->termMemberCache[ $termId ] );
        foreach ( array_keys( $this->cache ) as $key ) {
            if ( 0 === strpos( $key, 'term:' ) && substr( $key, -1 * strlen( ':' . $termId ) ) === ':' . $termId ) { unset( $this->cache[ $key ] ); }
        }
    }

    public function touchAuthor( int $userId ): void {
        if ( $userId <= 0 ) { return; }
        update_user_meta( $userId, self::AUTHOR_META, current_time( 'mysql', true ) );
        unset( $this->cache[ 'author:' . $userId ], $this->authorMemberCache[ $userId ] );
    }


    public function touchArchiveAndSite( string $postType ): void {
        $postType = sanitize_key( $postType );
        if ( '' === $postType || ! $this->isPublicPostType( $postType ) ) { return; }
        $markers = $this->collectionMarkers();
        $now = current_time( 'mysql', true );
        $markers[ 'archive:' . $postType ] = $now;
        $markers['site'] = $now;
        update_option( self::COLLECTION_OPTION, $markers, false );
        unset( $this->cache[ 'archive:' . $postType ], $this->cache['site'] );
    }

    public function touchPostCollections( int $postId ): void {
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post ) { return; }
        if ( (int) $post->post_author > 0 ) { $this->touchAuthor( (int) $post->post_author ); }
        foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
            $tax = get_taxonomy( $taxonomy );
            if ( ! $tax || empty( $tax->public ) ) { continue; }
            $terms = get_the_terms( $postId, $taxonomy );
            if ( ! is_array( $terms ) ) { continue; }
            foreach ( $terms as $term ) {
                if ( $term instanceof \WP_Term ) { $this->touchTerm( (int) $term->term_id ); }
            }
        }
        $this->touchArchiveAndSite( (string) $post->post_type );
    }

    /** @return array<int,string> */
    public static function semanticPostMetaKeys(): array {
        return array(
            '_yps_seo_title', '_yps_meta_description', '_yps_canonical', '_yps_robots_noindex',
            '_yps_robots_nofollow', '_yps_robots_advanced', '_yps_og_title', '_yps_og_description',
            '_yps_og_image', '_yps_og_type', '_yps_twitter_title', '_yps_twitter_description',
            '_yps_twitter_image', '_yps_schema_page_type', '_yps_schema_article_type',
            '_yps_schema_entity_type', '_yps_schema_entity_name', '_yps_schema_entity_description',
            '_yps_schema_person_job_title', '_yps_schema_person_image_url', '_yps_schema_person_same_as',
            '_yps_schema_person_knows_about', '_yps_schema_service_type', '_yps_schema_area_served',
            '_yps_schema_application_category', '_yps_schema_operating_system', '_yps_schema_offer_price',
            '_yps_schema_offer_currency', '_yps_redirect_target', '_thumbnail_id', '_product_image_gallery', '_sku', '_price', '_regular_price',
            '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to', '_stock_status', '_stock', '_manage_stock', '_backorders',
        );
    }

    public static function isSemanticPostMeta( string $metaKey ): bool {
        return in_array( $metaKey, self::semanticPostMetaKeys(), true );
    }

    /** @return array<int,string> */
    public static function collectionSemanticPostMetaKeys(): array {
        return array(
            '_thumbnail_id', '_price', '_regular_price', '_sale_price', '_sale_price_dates_from',
            '_sale_price_dates_to', '_stock_status', '_stock', '_manage_stock', '_backorders',
        );
    }

    public static function isCollectionSemanticPostMeta( string $metaKey ): bool {
        return in_array( $metaKey, self::collectionSemanticPostMetaKeys(), true );
    }

    private function latestTermMember( int $termId, string $taxonomy ): string {
        if ( array_key_exists( $termId, $this->termMemberCache ) ) { return $this->termMemberCache[ $termId ]; }
        global $wpdb;
        $types = $this->publicPostTypes();
        if ( $termId <= 0 || empty( $types ) ) { return ''; }
        if ( '' === $taxonomy ) {
            $term = get_term( $termId );
            $taxonomy = $term instanceof \WP_Term ? sanitize_key( (string) $term->taxonomy ) : '';
        }
        if ( '' === $taxonomy ) { return ''; }
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT MAX(p.post_modified_gmt) FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.term_id=%d AND tt.taxonomy=%s AND p.post_status='publish' AND p.post_type IN ({$placeholders})";
        $args = array_merge( array( $termId, $taxonomy ), $types );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core-table aggregate over a bounded public post-type set; prepared values, no user SQL fragments.
        $value = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
        $this->termMemberCache[ $termId ] = is_string( $value ) ? $value : '';
        return $this->termMemberCache[ $termId ];
    }

    private function latestAuthorMember( int $userId ): string {
        if ( array_key_exists( $userId, $this->authorMemberCache ) ) { return $this->authorMemberCache[ $userId ]; }
        $types = $this->publicPostTypes();
        if ( $userId <= 0 || empty( $types ) ) { return ''; }
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_author=%d AND post_status='publish' AND post_type IN ({$placeholders})";
        $args = array_merge( array( $userId ), $types );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Registry-derived post types and author ID are prepared; single core-table aggregate only.
        $value = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
        $this->authorMemberCache[ $userId ] = is_string( $value ) ? $value : '';
        return $this->authorMemberCache[ $userId ];
    }

    private function latestPostTypeMember( string $postType ): string {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared single aggregate from WordPress core posts table.
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type=%s AND post_status='publish'", $postType ) );
        return is_string( $value ) ? $value : '';
    }

    /** @param array<int,string> $types */
    private function latestPublicPostModified( array $types ): string {
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ({$placeholders})";
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Public post types are registry-derived and values are prepared; single aggregate only.
        $value = $wpdb->get_var( $wpdb->prepare( $sql, $types ) );
        return is_string( $value ) ? $value : '';
    }

    /** @return array<int,string> */
    private function publicPostTypes(): array {
        $types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
        return array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
    }

    private function isPublicPostType( string $postType ): bool {
        $object = get_post_type_object( $postType );
        return $object && ! empty( $object->public );
    }

    /** @return array<string,string> */
    private function collectionMarkers(): array {
        $markers = get_option( self::COLLECTION_OPTION, array() );
        if ( ! is_array( $markers ) ) { return array(); }
        $clean = array();
        foreach ( $markers as $key => $value ) {
            $key = trim( (string) $key );
            $value = trim( (string) $value );
            if ( 'site' !== $key && ! preg_match( '/^archive:[a-z0-9_-]+$/', $key ) ) { continue; }
            if ( '' !== $value ) { $clean[ $key ] = $value; }
        }
        return $clean;
    }

    /** @param array<int,string> $values */
    private function latestMysqlUtc( array $values ): string {
        $latest = '';
        $latestTs = 0;
        foreach ( $values as $value ) {
            $value = trim( (string) $value );
            if ( '' === $value || '0000-00-00 00:00:00' === $value ) { continue; }
            $ts = strtotime( $value . ' UTC' );
            if ( false !== $ts && $ts >= $latestTs ) { $latestTs = $ts; $latest = gmdate( 'Y-m-d H:i:s', $ts ); }
        }
        return $latest;
    }
}
