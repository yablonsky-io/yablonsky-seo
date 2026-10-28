<?php
namespace Yablonsky\PerformanceSEO\SEO\Breadcrumbs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BreadcrumbsController {
    public function register(): void {
        add_shortcode( 'yseo_breadcrumbs', array( $this, 'shortcode' ) );
        add_action( 'yablonsky_seo_breadcrumbs', array( $this, 'render' ) );
    }

    /** @param array<string,mixed> $atts */
    public function shortcode( array $atts = array() ): string {
        $atts = shortcode_atts(
            array(
                'separator' => '›',
                'home'      => __( 'Home', 'yablonsky-seo' ),
                'class'     => '',
            ),
            $atts,
            'yseo_breadcrumbs'
        );
        $items = $this->items( (string) $atts['home'] );
        if ( count( $items ) < 2 ) { return ''; }
        $separator = apply_filters( 'yablonsky_seo_breadcrumb_separator_v1', (string) $atts['separator'] );
        $class = trim( 'yseo-breadcrumbs ' . sanitize_html_class( (string) $atts['class'] ) );
        $parts = array();
        $last = count( $items ) - 1;
        foreach ( $items as $index => $item ) {
            $label = esc_html( (string) $item['name'] );
            if ( $index === $last || '' === (string) $item['url'] ) {
                $parts[] = '<span class="yseo-breadcrumb-current" aria-current="page">' . $label . '</span>';
            } else {
                $parts[] = '<a href="' . esc_url( (string) $item['url'] ) . '">' . $label . '</a>';
            }
        }
        return '<nav class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Breadcrumbs', 'yablonsky-seo' ) . '">' . implode( '<span class="yseo-breadcrumb-separator" aria-hidden="true">' . esc_html( $separator ) . '</span>', $parts ) . '</nav>';
    }

    public function render(): void {
        echo $this->shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode() escapes every dynamic component.
    }

    /** @return array<int,array{name:string,url:string}> */
    private function items( string $homeLabel ): array {
        $items = array();
        $this->push( $items, '' !== trim( $homeLabel ) ? $homeLabel : (string) get_bloginfo( 'name' ), home_url( '/' ) );
        if ( is_front_page() ) { return array(); }

        if ( is_singular() ) {
            $post = get_queried_object();
            if ( $post instanceof \WP_Post ) {
                if ( is_post_type_hierarchical( $post->post_type ) ) {
                    foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestorId ) {
                        $this->push( $items, (string) get_the_title( $ancestorId ), (string) get_permalink( $ancestorId ) );
                    }
                } elseif ( 'post' === $post->post_type ) {
                    $postsPage = (int) get_option( 'page_for_posts', 0 );
                    if ( $postsPage > 0 ) { $this->push( $items, (string) get_the_title( $postsPage ), (string) get_permalink( $postsPage ) ); }
                } else {
                    $archive = get_post_type_archive_link( $post->post_type );
                    $object = get_post_type_object( $post->post_type );
                    if ( is_string( $archive ) && '' !== $archive && $object && ! empty( $object->has_archive ) ) {
                        $this->push( $items, isset( $object->labels->name ) ? (string) $object->labels->name : $post->post_type, $archive );
                    }
                }
                $this->push( $items, (string) get_the_title( $post ), (string) get_permalink( $post ) );
            }
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            if ( $term instanceof \WP_Term ) {
                foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestorId ) {
                    $ancestor = get_term( (int) $ancestorId, $term->taxonomy );
                    if ( $ancestor instanceof \WP_Term ) {
                        $url = get_term_link( $ancestor );
                        if ( ! is_wp_error( $url ) ) { $this->push( $items, (string) $ancestor->name, (string) $url ); }
                    }
                }
                $url = get_term_link( $term );
                $this->push( $items, (string) $term->name, is_wp_error( $url ) ? '' : (string) $url );
            }
        } elseif ( is_author() ) {
            $author = get_queried_object();
            if ( $author instanceof \WP_User ) { $this->push( $items, (string) $author->display_name, (string) get_author_posts_url( (int) $author->ID ) ); }
        } elseif ( is_post_type_archive() ) {
            $type = (string) get_query_var( 'post_type' );
            if ( is_array( $type ) ) { $type = (string) reset( $type ); }
            $object = get_post_type_object( $type );
            $this->push( $items, $object && isset( $object->labels->name ) ? (string) $object->labels->name : $type, (string) get_post_type_archive_link( $type ) );
        } elseif ( is_search() ) {
            /* translators: %s: the visitor's search query. */
            $this->push( $items, sprintf( __( 'Search: %s', 'yablonsky-seo' ), get_search_query() ), '' );
        } elseif ( is_404() ) {
            $this->push( $items, __( 'Page unavailable', 'yablonsky-seo' ), '' );
        } elseif ( is_date() ) {
            $this->push( $items, (string) wp_get_document_title(), '' );
        }
        $items = apply_filters( 'yablonsky_seo_breadcrumb_items_v1', $items );
        return is_array( $items ) ? array_values( $items ) : array();
    }

    /** @param array<int,array{name:string,url:string}> $items */
    private function push( array &$items, string $name, string $url ): void {
        $name = trim( wp_strip_all_tags( $name, true ) );
        if ( '' === $name ) { return; }
        $url = esc_url_raw( $url, array( 'http', 'https' ) );
        $items[] = array( 'name' => $name, 'url' => is_string( $url ) ? $url : '' );
    }
}
