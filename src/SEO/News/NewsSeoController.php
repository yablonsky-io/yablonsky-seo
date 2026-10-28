<?php
namespace Yablonsky\PerformanceSEO\SEO\News;

use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapPresentation;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NewsSeoController {
    private const MAX_ITEMS = 1000;
    /** @var bool */ private $emitPublicSeo;

    public function __construct( bool $emitPublicSeo = true ) { $this->emitPublicSeo = $emitPublicSeo; }

    public function register(): void {
        if ( ! $this->emitPublicSeo ) { return; }
        add_action( 'template_redirect', array( $this, 'route' ), 0 );
        add_filter( 'yablonsky_seo_schema_graph_v1', array( $this, 'augmentSchema' ), 20, 3 );
        add_filter( 'yablonsky_seo_sitemap_index_extra_v1', array( $this, 'sitemapIndexEntry' ) );
    }

    /** @param array<int,array<string,string>> $items @return array<int,array<string,string>> */
    public function sitemapIndexEntry( array $items ): array {
        $latest = get_posts(
            array(
                'post_type'              => 'post',
                'post_status'            => 'publish',
                'has_password'           => false,
                'posts_per_page'         => self::MAX_ITEMS,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'date_query'             => array( array( 'after' => '2 days ago', 'inclusive' => true ) ),
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => true,
                'suppress_filters'       => false,
            )
        );
        $resolver = new ResourceLanguageResolver();
        foreach ( (array) $latest as $candidateId ) {
            $candidateId = (int) $candidateId;
            if ( $candidateId <= 0 || '1' !== (string) get_post_meta( $candidateId, '_yps_news_enabled', true ) || '1' === (string) get_post_meta( $candidateId, '_yps_news_exclude', true ) || '1' === (string) get_post_meta( $candidateId, '_yps_robots_noindex', true ) ) { continue; }
            $language = $resolver->forPost( $candidateId );
            $lang = isset( $language['language_key'] ) ? (string) $language['language_key'] : 'auto';
            if ( '' === $lang || 'auto' === $lang ) { continue; }
            $items[] = array( 'loc' => home_url( '/news-sitemap.xml' ), 'lastmod' => (string) get_post_modified_time( 'c', true, $candidateId ) );
            break;
        }
        return $items;
    }

    public function route(): void {
        $requestUri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
        $path = trim( (string) wp_parse_url( is_string( $requestUri ) ? $requestUri : '', PHP_URL_PATH ), '/' );
        if ( 'news-sitemap.xml' !== $path ) { return; }
        $this->renderSitemap();
        exit;
    }

    /** @param array<string,mixed> $graph @param array<string,mixed> $document @return array<string,mixed> */
    public function augmentSchema( array $graph, array $document, $resource ): array {
        if ( ! is_object( $resource ) || ! method_exists( $resource, 'objectId' ) ) { return $graph; }
        $postId = (int) $resource->objectId();
        if ( $postId <= 0 || '1' !== (string) get_post_meta( $postId, '_yps_news_enabled', true ) || '1' === (string) get_post_meta( $postId, '_yps_news_exclude', true ) ) { return $graph; }
        if ( empty( $graph['@graph'] ) || ! is_array( $graph['@graph'] ) ) { return $graph; }
        foreach ( $graph['@graph'] as &$piece ) {
            if ( is_array( $piece ) && isset( $piece['@id'] ) && false !== strpos( (string) $piece['@id'], '#article' ) ) {
                $piece['@type'] = 'NewsArticle';
            }
        }
        unset( $piece );
        return $graph;
    }

    private function renderSitemap(): void {
        $posts = get_posts(
            array(
                'post_type'              => 'post',
                'post_status'            => 'publish',
                'has_password'           => false,
                'posts_per_page'         => self::MAX_ITEMS,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'date_query'             => array( array( 'after' => '2 days ago', 'inclusive' => true ) ),
                'no_found_rows'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
                'suppress_filters'       => false,
            )
        );
        SitemapPresentation::sendXmlHeaders();
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";
        $resolver = new ResourceLanguageResolver();
        $publication = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ), true ) );
        foreach ( $posts as $post ) {
            if ( ! $post instanceof \WP_Post || '' !== (string) $post->post_password || '1' !== (string) get_post_meta( (int) $post->ID, '_yps_news_enabled', true ) || '1' === (string) get_post_meta( (int) $post->ID, '_yps_news_exclude', true ) || '1' === (string) get_post_meta( (int) $post->ID, '_yps_robots_noindex', true ) ) { continue; }
            $language = $resolver->forPost( (int) $post->ID );
            $lang = isset( $language['language_key'] ) ? (string) $language['language_key'] : 'auto';
            if ( 'auto' === $lang || '' === $lang ) { continue; }
            $url = get_permalink( $post );
            if ( ! is_string( $url ) || '' === $url ) { continue; }
            $title = trim( wp_strip_all_tags( (string) get_the_title( $post ), true ) );
            $date = get_post_time( 'c', true, $post );
            $tickers = $this->stockTickers( (string) get_post_meta( (int) $post->ID, '_yps_news_stock_tickers', true ) );
            echo '<url><loc>' . esc_url( $url ) . '</loc><news:news>';
            echo '<news:publication><news:name>' . esc_html( $publication ) . '</news:name><news:language>' . esc_html( $this->newsLanguage( $lang ) ) . '</news:language></news:publication>';
            echo '<news:publication_date>' . esc_html( (string) $date ) . '</news:publication_date><news:title>' . esc_html( $title ) . '</news:title>';
            if ( '' !== $tickers ) { echo '<news:stock_tickers>' . esc_html( $tickers ) . '</news:stock_tickers>'; }
            echo '</news:news></url>' . "\n";
        }
        echo '</urlset>' . "\n";
    }

    private function newsLanguage( string $language ): string {
        $parts = preg_split( '/[-_]/', strtolower( $language ) );
        return is_array( $parts ) && ! empty( $parts[0] ) ? (string) $parts[0] : $language;
    }

    private function stockTickers( string $raw ): string {
        $parts = preg_split( '/[\r\n,]+/', strtoupper( $raw ) );
        if ( ! is_array( $parts ) ) { return ''; }
        $out = array();
        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( preg_match( '/^[A-Z0-9._-]{1,20}:[A-Z0-9._-]{1,20}$/', $part ) ) { $out[] = $part; }
        }
        return implode( ', ', array_slice( array_values( array_unique( $out ) ), 0, 20 ) );
    }
}
