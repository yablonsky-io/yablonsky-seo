<?php
namespace Yablonsky\PerformanceSEO\Migration\Portability;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reads only explicitly verified source storage. No method writes source data.
 */
final class PortableMetadataReader {
    /** @var PortableSourceProfile */ private $profile;
    /** @var array<int,array<string,mixed>> */ private $aioRows = array();
    /** @var array<int,string>|null */ private $aioColumns = null;

    public function __construct( PortableSourceProfile $profile ) { $this->profile = $profile; }

    /** @param array<int,int> $postIds */
    public function primePosts( array $postIds ): void {
        $postIds = array_values( array_unique( array_filter( array_map( 'intval', $postIds ) ) ) );
        if ( empty( $postIds ) ) { return; }
        if ( 'meta' === $this->profile->storage() || 'slim-array' === $this->profile->storage() ) {
            update_meta_cache( 'post', $postIds );
            return;
        }
        if ( 'aioseo-table' === $this->profile->storage() ) { $this->primeAioseoPosts( $postIds ); }
    }

    /** @param array<int,int> $termIds */
    public function primeTerms( array $termIds ): void {
        if ( 'meta' === $this->profile->storage() || 'slim-array' === $this->profile->storage() ) {
            $termIds = array_values( array_unique( array_filter( array_map( 'intval', $termIds ) ) ) );
            if ( ! empty( $termIds ) ) { update_meta_cache( 'term', $termIds ); }
        }
    }

    /** @return array<string,mixed> target YSEO meta key => source scalar */
    public function postValues( int $postId ): array {
        if ( $postId <= 0 ) { return array(); }
        if ( 'slim-array' === $this->profile->storage() ) { return $this->slimValues( get_post_meta( $postId, 'slim_seo', true ) ); }
        if ( 'aioseo-table' === $this->profile->storage() ) { return $this->aioseoRowValues( $this->aioRows[ $postId ] ?? array() ); }
        return $this->withVerifiedRobots( 'post', $postId, $this->mappedMetaValues( 'post', $postId, $this->profile->postFields() ) );
    }

    /** @return array<string,mixed> target YSEO meta key => source scalar */
    public function termValues( int $termId ): array {
        if ( $termId <= 0 || ! $this->profile->supportsTerms() ) { return array(); }
        if ( 'slim-array' === $this->profile->storage() ) { return $this->slimValues( get_term_meta( $termId, 'slim_seo', true ) ); }
        return $this->withVerifiedRobots( 'term', $termId, $this->mappedMetaValues( 'term', $termId, $this->profile->termFields() ) );
    }

    /**
     * @param array<string,string> $map
     * @return array<string,mixed>
     */
    private function mappedMetaValues( string $type, int $id, array $map ): array {
        $values = array();
        foreach ( $map as $sourceKey => $targetKey ) {
            $source = 'term' === $type ? get_term_meta( $id, $sourceKey, true ) : get_post_meta( $id, $sourceKey, true );
            if ( ! is_scalar( $source ) || '' === trim( (string) $source ) ) { continue; }
            $source = $this->portableMappedValue( $targetKey, (string) $source );
            if ( '' !== $source ) { $values[ $targetKey ] = $source; }
        }
        return $values;
    }

    private function portableMappedValue( string $targetKey, string $source ): string {
        $source = trim( $source );
        if ( '' === $source ) { return ''; }
        if ( '_yps_is_cornerstone' === $targetKey ) { return $this->truthy( $source ) ? '1' : ''; }

        // Full provider template-language translation is intentionally outside
        // preserve-only portability. Skip templated values rather than leaking
        // an unsupported source token into YSEO public output after takeover.
        if ( 'rank-math' === $this->profile->id() && preg_match( '/%[A-Za-z0-9_\-]+%/', $source ) ) { return ''; }
        if ( 'seopress' === $this->profile->id() && preg_match( '/%%[A-Za-z0-9_\-]+%%/', $source ) ) { return ''; }
        if ( 'surerank' === $this->profile->id() && preg_match( '/%[A-Za-z0-9_\-]+%/', $source ) ) { return ''; }
        return $source;
    }

    /**
     * Adds only provider robot directives whose source representation and
     * target meaning are unambiguous. Missing/false values remain inherited.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function withVerifiedRobots( string $type, int $id, array $values ): array {
        $getMeta = static function ( string $objectType, int $objectId, string $key ) {
            return 'term' === $objectType ? get_term_meta( $objectId, $key, true ) : get_post_meta( $objectId, $key, true );
        };

        if ( 'rank-math' === $this->profile->id() ) {
            $robots = $getMeta( $type, $id, 'rank_math_robots' );
            $robots = is_array( $robots ) ? array_map( 'strval', $robots ) : array();
            if ( in_array( 'noindex', $robots, true ) ) { $values['_yps_robots_noindex'] = '1'; }
            if ( in_array( 'nofollow', $robots, true ) ) { $values['_yps_robots_nofollow'] = '1'; }
            return $values;
        }

        if ( 'surerank' === $this->profile->id() ) {
            if ( $this->truthy( $getMeta( $type, $id, 'surerank_settings_post_no_index' ) ) ) { $values['_yps_robots_noindex'] = '1'; }
            if ( $this->truthy( $getMeta( $type, $id, 'surerank_settings_post_no_follow' ) ) ) { $values['_yps_robots_nofollow'] = '1'; }
            if ( $this->truthy( $getMeta( $type, $id, 'surerank_settings_post_no_archive' ) ) ) { $values['_yps_robots_advanced'] = 'noarchive'; }
            return $values;
        }

        if ( 'seopress' === $this->profile->id() ) {
            if ( $this->truthy( $getMeta( $type, $id, '_seopress_robots_index' ) ) ) { $values['_yps_robots_noindex'] = '1'; }
            if ( $this->truthy( $getMeta( $type, $id, '_seopress_robots_follow' ) ) ) { $values['_yps_robots_nofollow'] = '1'; }
            $advanced = array();
            if ( $this->truthy( $getMeta( $type, $id, '_seopress_robots_snippet' ) ) ) { $advanced[] = 'nosnippet'; }
            if ( $this->truthy( $getMeta( $type, $id, '_seopress_robots_imageindex' ) ) ) { $advanced[] = 'noimageindex'; }
            if ( ! empty( $advanced ) ) { $values['_yps_robots_advanced'] = implode( ',', $advanced ); }
        }
        return $values;
    }

    /** @return array<string,mixed> */
    private function slimValues( $raw ): array {
        $raw = is_array( $raw ) ? $raw : array();
        $map = array(
            'title'       => '_yps_seo_title',
            'description' => '_yps_meta_description',
            'canonical'   => '_yps_canonical',
            'noindex'     => '_yps_robots_noindex',
        );
        $values = array();
        foreach ( $map as $sourceKey => $targetKey ) {
            if ( ! array_key_exists( $sourceKey, $raw ) || ! is_scalar( $raw[ $sourceKey ] ) ) { continue; }
            if ( 'noindex' === $sourceKey ) {
                if ( $this->truthy( $raw[ $sourceKey ] ) ) { $values[ $targetKey ] = '1'; }
                continue;
            }
            $source = (string) $raw[ $sourceKey ];
            if ( '' !== trim( $source ) ) { $values[ $targetKey ] = $source; }
        }
        return $values;
    }

    /** @param array<int,int> $postIds */
    private function primeAioseoPosts( array $postIds ): void {
        global $wpdb;
        $this->aioRows = array();
        $columns = $this->aioseoColumns();
        if ( empty( $columns ) ) { return; }
        $wanted = array(
            'post_id', 'title', 'description', 'canonical_url', 'keyphrases', 'pillar_content',
            'robots_default', 'robots_noindex', 'robots_nofollow', 'robots_noarchive',
            'robots_noimageindex', 'robots_nosnippet', 'robots_notranslate',
            'og_title', 'og_description', 'og_image_type', 'og_image_custom_url',
            'twitter_title', 'twitter_description', 'twitter_image_type', 'twitter_image_custom_url',
        );
        $selected = array_values( array_intersect( $wanted, $columns ) );
        if ( ! in_array( 'post_id', $selected, true ) ) { return; }
        $table = $wpdb->prefix . 'aioseo_posts';
        $columnPlaceholders = implode( ',', array_fill( 0, count( $selected ), '%i' ) );
        $idPlaceholders = implode( ',', array_fill( 0, count( $postIds ), '%d' ) );
        $args = array_merge( $selected, array( $table ), $postIds );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholder shapes are generated locally; column/table identifiers and post IDs are all passed through wpdb::prepare().
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$columnPlaceholders} FROM %i WHERE post_id IN ({$idPlaceholders})", $args ), ARRAY_A );
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
            if ( $id > 0 ) { $this->aioRows[ $id ] = $row; }
        }
    }

    /** @return array<int,string> */
    private function aioseoColumns(): array {
        if ( null !== $this->aioColumns ) { return $this->aioColumns; }
        global $wpdb;
        $table = $wpdb->prefix . 'aioseo_posts';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One admin-only storage-contract inspection; no source data is modified.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $table !== (string) $exists ) { $this->aioColumns = array(); return $this->aioColumns; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only read-only schema inspection; the table identifier is prepared explicitly with %i.
        $rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
        $columns = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $field = isset( $row['Field'] ) ? sanitize_key( (string) $row['Field'] ) : '';
            if ( '' !== $field ) { $columns[] = $field; }
        }
        $this->aioColumns = array_values( array_unique( $columns ) );
        return $this->aioColumns;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function aioseoRowValues( array $row ): array {
        if ( empty( $row ) ) { return array(); }
        $values = array();
        $this->addText( $values, '_yps_seo_title', $this->aioPortableText( $row['title'] ?? '' ) );
        $this->addText( $values, '_yps_meta_description', $this->aioPortableText( $row['description'] ?? '' ) );
        $this->addText( $values, '_yps_canonical', $this->aioPortableText( $row['canonical_url'] ?? '' ) );
        $this->addText( $values, '_yps_og_title', $this->aioPortableText( $row['og_title'] ?? '' ) );
        $this->addText( $values, '_yps_og_description', $this->aioPortableText( $row['og_description'] ?? '' ) );
        if ( 'custom_image' === (string) ( $row['og_image_type'] ?? '' ) ) { $this->addText( $values, '_yps_og_image', $row['og_image_custom_url'] ?? '' ); }
        $this->addText( $values, '_yps_twitter_title', $this->aioPortableText( $row['twitter_title'] ?? '' ) );
        $this->addText( $values, '_yps_twitter_description', $this->aioPortableText( $row['twitter_description'] ?? '' ) );
        if ( 'custom_image' === (string) ( $row['twitter_image_type'] ?? '' ) ) { $this->addText( $values, '_yps_twitter_image', $row['twitter_image_custom_url'] ?? '' ); }
        if ( $this->truthy( $row['pillar_content'] ?? false ) ) { $values['_yps_is_cornerstone'] = '1'; }
        $this->addText( $values, '_yps_focus_keyphrase', $this->aioFocusKeyphrase( $row['keyphrases'] ?? null ) );

        if ( array_key_exists( 'robots_default', $row ) && ! $this->truthy( $row['robots_default'] ) ) {
            if ( $this->truthy( $row['robots_noindex'] ?? false ) ) { $values['_yps_robots_noindex'] = '1'; }
            if ( $this->truthy( $row['robots_nofollow'] ?? false ) ) { $values['_yps_robots_nofollow'] = '1'; }
            $advanced = array();
            foreach ( array( 'noarchive' => 'robots_noarchive', 'noimageindex' => 'robots_noimageindex', 'nosnippet' => 'robots_nosnippet', 'notranslate' => 'robots_notranslate' ) as $directive => $column ) {
                if ( $this->truthy( $row[ $column ] ?? false ) ) { $advanced[] = $directive; }
            }
            if ( ! empty( $advanced ) ) { $values['_yps_robots_advanced'] = implode( ',', $advanced ); }
        }
        return $values;
    }

    private function aioFocusKeyphrase( $raw ): string {
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) { return ''; }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['focus'] ) || ! is_array( $decoded['focus'] ) ) { return ''; }
        $value = $decoded['focus']['keyphrase'] ?? '';
        return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
    }

    private function aioPortableText( $raw ): string {
        $value = $this->aioDisplayText( $raw );
        if ( '' === $value ) { return ''; }
        return preg_match( '/#[A-Za-z][A-Za-z0-9_\-]*/', $value ) ? '' : $value;
    }

    private function aioDisplayText( $raw ): string {
        if ( ! is_scalar( $raw ) ) { return ''; }
        $value = (string) $raw;
        $previous = null;
        $passes = 0;
        while ( $value !== $previous && $passes < 3 ) {
            $previous = $value;
            $charset = (string) get_bloginfo( 'charset' );
            $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML401, '' !== $charset ? $charset : 'UTF-8' );
            ++$passes;
        }
        $value = wp_strip_all_tags( $value, true );
        $collapsed = preg_replace( '/\s+/u', ' ', $value );
        return trim( is_string( $collapsed ) ? $collapsed : $value );
    }

    /** @param array<string,mixed> $values */
    private function addText( array &$values, string $target, $source ): void {
        if ( is_scalar( $source ) && '' !== trim( (string) $source ) ) { $values[ $target ] = (string) $source; }
    }

    private function truthy( $value ): bool {
        if ( is_bool( $value ) ) { return $value; }
        if ( is_int( $value ) || is_float( $value ) ) { return 0 !== (int) $value; }
        return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on', 'noindex', 'nofollow' ), true );
    }
}
