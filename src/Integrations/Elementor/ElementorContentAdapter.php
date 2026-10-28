<?php
namespace Yablonsky\PerformanceSEO\Integrations\Elementor;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Optional Elementor analysis adapter.
 *
 * It never depends on Elementor PHP classes. When Elementor's stored document is
 * present, the adapter projects supported visible widget content into YSEO's local
 * analysis stream. Public SEO output and saved Elementor data are never modified.
 */
final class ElementorContentAdapter {
    public function register(): void {
        add_filter( 'yablonsky_seo_analysis_content_v1', array( $this, 'analysisContent' ), 10, 2 );
        add_filter( 'yablonsky_seo_analysis_content_source_v1', array( $this, 'analysisSource' ), 10, 2 );
    }

    /** @param mixed $content @param mixed $post */
    public function analysisContent( $content, $post ): string {
        $content = is_scalar( $content ) ? (string) $content : '';
        if ( ! $post instanceof \WP_Post || $post->ID < 1 ) { return $content; }
        $projected = $this->projectPost( $post->ID );
        if ( '' === $projected ) { return $content; }
        return $content . "\n" . '<div data-yseo-analysis-source="elementor">' . $projected . '</div>';
    }

    /** @param mixed $source @param mixed $post */
    public function analysisSource( $source, $post ): string {
        $source = is_scalar( $source ) ? sanitize_key( (string) $source ) : 'wordpress';
        if ( ! $post instanceof \WP_Post || $post->ID < 1 ) { return $source; }
        return '' !== $this->projectPost( $post->ID ) ? 'wordpress+elementor' : $source;
    }

    public function hasElementorContent( int $postId ): bool {
        return '' !== $this->projectPost( $postId );
    }

    private function projectPost( int $postId ): string {
        // A multisite switch can reuse the same post ID for different sites.
        static $cache = array();
        $cacheKey = (string) get_current_blog_id() . ':' . $postId;
        if ( array_key_exists( $cacheKey, $cache ) ) { return (string) $cache[ $cacheKey ]; }
        $raw = get_post_meta( $postId, '_elementor_data', true );
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) { $cache[ $cacheKey ] = ''; return ''; }
        $data = json_decode( $raw, true, 64 );
        if ( ! is_array( $data ) ) { $cache[ $cacheKey ] = ''; return ''; }
        $parts = array();
        $this->walk( $data, $parts, 0 );
        $cache[ $cacheKey ] = implode( "\n", array_slice( $parts, 0, 1200 ) );
        return (string) $cache[ $cacheKey ];
    }

    /** @param array<int|string,mixed> $nodes @param array<int,string> $parts */
    private function walk( array $nodes, array &$parts, int $depth ): void {
        if ( $depth > 20 || count( $parts ) >= 1200 ) { return; }
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $widget = isset( $node['widgetType'] ) ? sanitize_key( (string) $node['widgetType'] ) : '';
            $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
            if ( '' !== $widget ) { $this->projectWidget( $widget, $settings, $parts ); }
            if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) { $this->walk( $node['elements'], $parts, $depth + 1 ); }
        }
    }

    /** @param array<string,mixed> $settings @param array<int,string> $parts */
    private function projectWidget( string $widget, array $settings, array &$parts ): void {
        if ( 'heading' === $widget ) {
            $this->appendText( $parts, (string) ( $settings['title'] ?? '' ), 'h2' );
            return;
        }
        if ( 'text-editor' === $widget ) {
            $this->appendHtml( $parts, (string) ( $settings['editor'] ?? '' ) );
            return;
        }
        if ( 'button' === $widget ) {
            $text = trim( wp_strip_all_tags( (string) ( $settings['text'] ?? '' ), true ) );
            $url = isset( $settings['link']['url'] ) && is_scalar( $settings['link']['url'] ) ? esc_url_raw( (string) $settings['link']['url'] ) : '';
            if ( '' !== $text ) { $parts[] = '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>' : '<p>' . esc_html( $text ) . '</p>'; }
            return;
        }
        if ( 'image' === $widget ) {
            $image = isset( $settings['image'] ) && is_array( $settings['image'] ) ? $settings['image'] : array();
            $id = absint( $image['id'] ?? 0 );
            $url = isset( $image['url'] ) && is_scalar( $image['url'] ) ? esc_url_raw( (string) $image['url'] ) : '';
            $alt = $id > 0 ? trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : '';
            if ( '' !== $url || '' !== $alt ) { $parts[] = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" />'; }
            return;
        }
        if ( 'icon-list' === $widget && isset( $settings['icon_list'] ) && is_array( $settings['icon_list'] ) ) {
            $items = array();
            foreach ( $settings['icon_list'] as $item ) {
                if ( ! is_array( $item ) ) { continue; }
                $text = trim( wp_strip_all_tags( (string) ( $item['text'] ?? '' ), true ) );
                if ( '' !== $text ) { $items[] = '<li>' . esc_html( $text ) . '</li>'; }
            }
            if ( ! empty( $items ) ) { $parts[] = '<ul>' . implode( '', $items ) . '</ul>'; }
            return;
        }
        if ( in_array( $widget, array( 'accordion', 'toggle', 'tabs' ), true ) ) {
            $rows = isset( $settings['tabs'] ) && is_array( $settings['tabs'] ) ? $settings['tabs'] : ( isset( $settings['items'] ) && is_array( $settings['items'] ) ? $settings['items'] : array() );
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) { continue; }
                $this->appendText( $parts, (string) ( $row['tab_title'] ?? $row['title'] ?? '' ), 'h3' );
                $this->appendHtml( $parts, (string) ( $row['tab_content'] ?? $row['content'] ?? '' ) );
            }
            return;
        }
        if ( 'testimonial' === $widget ) {
            $this->appendHtml( $parts, (string) ( $settings['testimonial_content'] ?? '' ) );
            $this->appendText( $parts, (string) ( $settings['testimonial_name'] ?? '' ), 'p' );
            return;
        }
        foreach ( array( 'title', 'description', 'text', 'content' ) as $key ) {
            if ( isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ) { $this->appendText( $parts, (string) $settings[ $key ], 'p' ); }
        }
    }

    /** @param array<int,string> $parts */
    private function appendText( array &$parts, string $text, string $tag ): void {
        $text = trim( wp_strip_all_tags( $text, true ) );
        if ( '' === $text ) { return; }
        $tag = in_array( $tag, array( 'h2', 'h3', 'p' ), true ) ? $tag : 'p';
        $parts[] = '<' . $tag . '>' . esc_html( $text ) . '</' . $tag . '>';
    }

    /** @param array<int,string> $parts */
    private function appendHtml( array &$parts, string $html ): void {
        $html = trim( wp_kses_post( $html ) );
        if ( '' !== $html ) { $parts[] = $html; }
    }
}
