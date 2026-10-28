<?php
namespace Yablonsky\PerformanceSEO\Discovery;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Search discovery and feed distribution settings.
 *
 * The stored surface is intentionally small. Sitemap family choices use a
 * three-state policy (recommended/include/exclude) so administrators can
 * override YSEO without conflating sitemap inclusion with robots noindex.
 */
final class DiscoverySettings {
    /** @var array<string,array<string,mixed>>|null */ private static $inventoryCache = null;
    public const OPTION = 'yseo_discovery_settings_v1';
    public const VERSION = 1;

    /** @return array<string,mixed> */
    public function all(): array {
        $saved = get_option( self::OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        if ( isset( $saved['feeds']['google_merchant'] ) && is_array( $saved['feeds']['google_merchant'] ) && ! isset( $saved['feeds']['commerce'] ) ) {
            $saved['feeds']['commerce'] = $saved['feeds']['google_merchant'];
        }
        return $this->mergeRecursive( $this->defaults(), $saved );
    }

    /** @return array<string,mixed> */
    public function defaults(): array {
        return array(
            'version' => self::VERSION,
            'sitemap' => array(
                'families' => array(),
                'payload' => array(
                    'lastmod'    => true,
                    'images'     => 'primary', // off | primary | all
                    'max_images' => 1,
                ),
            ),
            'feeds' => array(
                'commerce' => array(
                    'enabled' => false,
                    'refresh' => 'six_hours',
                    'additional_images' => true,
                    'brand' => true,
                    'gtin' => true,
                    'mpn' => true,
                    'sale_price' => true,
                    'product_type' => true,
                ),
            ),
        );
    }

    /** @return array<string,array<string,mixed>> */
    public function familyInventory(): array {
        if ( null !== self::$inventoryCache ) { return self::$inventoryCache; }
        $inventory = array();
        $postTypes = get_post_types( array( 'public' => true ), 'objects' );
        foreach ( (array) $postTypes as $name => $object ) {
            $name = sanitize_key( (string) $name );
            if ( '' === $name || 'attachment' === $name || ! is_post_type_viewable( $object ) ) { continue; }
            $label = isset( $object->labels->name ) ? (string) $object->labels->name : $name;
            $inventory[ 'post:' . $name ] = array(
                'key' => 'post:' . $name,
                'resource_type' => 'post',
                'resource_subtype' => $name,
                'label' => $label,
                'machine' => $name,
                'kind' => 'post_type',
                'recommended' => $this->recommendedPostType( $name ),
                'reason' => $this->postTypeRecommendationReason( $name ),
            );
        }

        $taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
        foreach ( (array) $taxonomies as $name => $object ) {
            $name = sanitize_key( (string) $name );
            if ( '' === $name ) { continue; }
            $label = isset( $object->labels->name ) ? (string) $object->labels->name : $name;
            $inventory[ 'term:' . $name ] = array(
                'key' => 'term:' . $name,
                'resource_type' => 'term',
                'resource_subtype' => $name,
                'label' => $label,
                'machine' => $name,
                'kind' => 'taxonomy',
                'recommended' => $this->recommendedTaxonomy( $name ),
                'reason' => $this->taxonomyRecommendationReason( $name ),
            );
        }

        $inventory['author:author'] = array(
            'key' => 'author:author',
            'resource_type' => 'author',
            'resource_subtype' => 'author',
            'label' => __( 'Author archives', 'yablonsky-seo' ),
            'machine' => 'author',
            'kind' => 'author',
            'recommended' => false,
            'reason' => __( 'Enable only when author pages are meaningful standalone search destinations.', 'yablonsky-seo' ),
        );
        self::$inventoryCache = $inventory;
        return $inventory;
    }

    public function familyMode( string $type, string $subtype ): string {
        $key = sanitize_key( $type ) . ':' . sanitize_key( $subtype );
        $settings = $this->all();
        $families = isset( $settings['sitemap']['families'] ) && is_array( $settings['sitemap']['families'] ) ? $settings['sitemap']['families'] : array();
        $mode = isset( $families[ $key ] ) ? sanitize_key( (string) $families[ $key ] ) : 'recommended';
        return in_array( $mode, array( 'recommended', 'include', 'exclude' ), true ) ? $mode : 'recommended';
    }

    public function familyEnabled( string $type, string $subtype ): bool {
        $mode = $this->familyMode( $type, $subtype );
        if ( 'include' === $mode ) { return true; }
        if ( 'exclude' === $mode ) { return false; }
        $key = sanitize_key( $type ) . ':' . sanitize_key( $subtype );
        $inventory = $this->familyInventory();
        if ( isset( $inventory[ $key ] ) ) { return ! empty( $inventory[ $key ]['recommended'] ); }
        return false;
    }

    public function includeLastmod(): bool {
        $settings = $this->all();
        return ! empty( $settings['sitemap']['payload']['lastmod'] );
    }

    public function imageMode(): string {
        $settings = $this->all();
        $mode = sanitize_key( (string) ( $settings['sitemap']['payload']['images'] ?? 'primary' ) );
        return in_array( $mode, array( 'off', 'primary', 'all' ), true ) ? $mode : 'primary';
    }

    public function maxImages(): int {
        $settings = $this->all();
        $max = (int) ( $settings['sitemap']['payload']['max_images'] ?? 1 );
        return in_array( $max, array( 1, 3, 5, 10, 20 ), true ) ? $max : 1;
    }


    public function sitemapPayloadSignature(): string {
        $settings = $this->all();
        $payload = isset( $settings['sitemap']['payload'] ) && is_array( $settings['sitemap']['payload'] ) ? $settings['sitemap']['payload'] : array();
        return substr( hash( 'sha256', wp_json_encode( $payload ) ?: '' ), 0, 12 );
    }

    public function commerceEnabled(): bool {
        $settings = $this->all();
        return ! empty( $settings['feeds']['commerce']['enabled'] );
    }

    public function commerceFieldEnabled( string $field ): bool {
        $settings = $this->all();
        return ! empty( $settings['feeds']['commerce'][ sanitize_key( $field ) ] );
    }

    public function commerceRefresh(): string {
        $settings = $this->all();
        $value = sanitize_key( (string) ( $settings['feeds']['commerce']['refresh'] ?? 'six_hours' ) );
        return in_array( $value, array( 'fifteen_minutes', 'hourly', 'six_hours', 'daily' ), true ) ? $value : 'six_hours';
    }

    /** Backward-compatible aliases for the short-lived 5.66.0 internal surface. */
    public function merchantEnabled(): bool { return $this->commerceEnabled(); }
    public function merchantFieldEnabled( string $field ): bool { return $this->commerceFieldEnabled( $field ); }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function sanitize( array $input ): array {
        $output = $this->defaults();
        $output['sitemap'] = $this->sanitizeSitemapSection( isset( $input['sitemap'] ) && is_array( $input['sitemap'] ) ? $input['sitemap'] : array() );
        $feeds = isset( $input['feeds'] ) && is_array( $input['feeds'] ) ? $input['feeds'] : array();
        $output['feeds']['commerce'] = $this->sanitizeCommerceSection( isset( $feeds['commerce'] ) && is_array( $feeds['commerce'] ) ? $feeds['commerce'] : array() );
        return $output;
    }

    /** @param array<string,mixed> $settings */
    public function save( array $settings ): void {
        update_option( self::OPTION, $this->sanitize( $settings ), false );
    }

    /** @param array<string,mixed> $sitemap */
    public function saveSitemapSection( array $sitemap ): void {
        $current = $this->all();
        $current['sitemap'] = $this->sanitizeSitemapSection( $sitemap );
        update_option( self::OPTION, $current, false );
    }

    /** @param array<string,mixed> $commerce */
    public function saveCommerceSection( array $commerce ): void {
        $current = $this->all();
        $current['feeds']['commerce'] = $this->sanitizeCommerceSection( $commerce );
        update_option( self::OPTION, $current, false );
    }

    /** @param array<string,mixed> $sitemap @return array<string,mixed> */
    private function sanitizeSitemapSection( array $sitemap ): array {
        $output = $this->defaults()['sitemap'];
        $inventory = $this->familyInventory();
        $families = isset( $sitemap['families'] ) && is_array( $sitemap['families'] ) ? $sitemap['families'] : array();
        foreach ( $inventory as $key => $definition ) {
            unset( $definition );
            $mode = isset( $families[ $key ] ) ? sanitize_key( (string) $families[ $key ] ) : 'recommended';
            if ( ! in_array( $mode, array( 'recommended', 'include', 'exclude' ), true ) ) { $mode = 'recommended'; }
            if ( 'recommended' !== $mode ) { $output['families'][ $key ] = $mode; }
        }
        $payload = isset( $sitemap['payload'] ) && is_array( $sitemap['payload'] ) ? $sitemap['payload'] : array();
        $output['payload']['lastmod'] = ! empty( $payload['lastmod'] );
        $imageMode = sanitize_key( (string) ( $payload['images'] ?? 'primary' ) );
        $output['payload']['images'] = in_array( $imageMode, array( 'off', 'primary', 'all' ), true ) ? $imageMode : 'primary';
        $maxImages = (int) ( $payload['max_images'] ?? 1 );
        $output['payload']['max_images'] = in_array( $maxImages, array( 1, 3, 5, 10, 20 ), true ) ? $maxImages : 1;
        if ( 'primary' === $output['payload']['images'] ) { $output['payload']['max_images'] = 1; }
        return $output;
    }

    /** @param array<string,mixed> $commerce @return array<string,mixed> */
    private function sanitizeCommerceSection( array $commerce ): array {
        $output = $this->defaults()['feeds']['commerce'];
        foreach ( array( 'enabled', 'additional_images', 'brand', 'gtin', 'mpn', 'sale_price', 'product_type' ) as $field ) {
            $output[ $field ] = ! empty( $commerce[ $field ] );
        }
        $refresh = sanitize_key( (string) ( $commerce['refresh'] ?? 'six_hours' ) );
        $output['refresh'] = in_array( $refresh, array( 'fifteen_minutes', 'hourly', 'six_hours', 'daily' ), true ) ? $refresh : 'six_hours';
        return $output;
    }

    private function recommendedPostType( string $name ): bool {
        return in_array( $name, array( 'post', 'page', 'product', 'yseo_location' ), true );
    }

    private function postTypeRecommendationReason( string $name ): string {
        if ( in_array( $name, array( 'post', 'page' ), true ) ) { return __( 'Core editorial search content.', 'yablonsky-seo' ); }
        if ( 'product' === $name ) { return __( 'Product landing pages are primary commerce search destinations.', 'yablonsky-seo' ); }
        if ( 'yseo_location' === $name ) { return __( 'Public location landing pages are intended search destinations.', 'yablonsky-seo' ); }
        return __( 'Custom post type: review its search intent before inclusion.', 'yablonsky-seo' );
    }

    private function recommendedTaxonomy( string $name ): bool {
        return in_array( $name, array( 'category', 'product_cat' ), true );
    }

    private function taxonomyRecommendationReason( string $name ): string {
        if ( in_array( $name, array( 'category', 'product_cat' ), true ) ) { return __( 'Category archives can act as meaningful search hubs.', 'yablonsky-seo' ); }
        if ( in_array( $name, array( 'post_tag', 'product_tag' ), true ) ) { return __( 'Tags are excluded by default because thin or overlapping archives are common.', 'yablonsky-seo' ); }
        return __( 'Third-party/custom taxonomy: excluded until its search value is explicitly confirmed.', 'yablonsky-seo' );
    }

    /** @param array<string,mixed> $defaults @param array<string,mixed> $saved @return array<string,mixed> */
    private function mergeRecursive( array $defaults, array $saved ): array {
        foreach ( $saved as $key => $value ) {
            if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) ) {
                $defaults[ $key ] = $this->mergeRecursive( $defaults[ $key ], $value );
            } else {
                $defaults[ $key ] = $value;
            }
        }
        return $defaults;
    }
}
