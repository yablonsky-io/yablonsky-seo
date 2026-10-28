<?php
namespace Yablonsky\PerformanceSEO\SEO\Template;

use Yablonsky\PerformanceSEO\Commerce\WooProductTruthResolver;
use Yablonsky\PerformanceSEO\Resource\ResourceIdentity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clean-room template-variable resolver.
 *
 * YSEO intentionally accepts the documented Yoast-style %%variable%% syntax as an
 * interoperability surface. Migration therefore preserves source templates verbatim;
 * rendering resolves them at runtime instead of destructively rewriting stored data.
 */
final class TemplateResolver {
    public function resolve( string $template, ?ResourceIdentity $resource = null ): string {
        $template = trim( wp_strip_all_tags( $template, true ) );
        if ( '' === $template ) {
            return '';
        }

        $objectId = null === $resource ? 0 : $resource->objectId();
        $subtype  = null === $resource ? '' : $resource->subtype();
        $type     = null === $resource ? '' : $resource->type();
        $title    = $this->resourceTitle( $resource );
        $excerptOnly = $this->resourceExcerptOnly( $resource );
        $excerpt  = '' !== $excerptOnly ? $excerptOnly : $this->generatedExcerpt( $resource );
        $termName = $this->termName( $resource );
        $siteName = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ), true ) );
        $siteDesc = trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ), true ) );
        $separator = $this->separator();
        $paged = max( 1, (int) get_query_var( 'paged', 1 ), (int) get_query_var( 'page', 1 ) );
        $pageTotal = isset( $GLOBALS['wp_query'] ) && is_object( $GLOBALS['wp_query'] ) && isset( $GLOBALS['wp_query']->max_num_pages ) ? max( 1, (int) $GLOBALS['wp_query']->max_num_pages ) : 1;
        $pageText = $paged > 1 ? sprintf( 'Page %1$d of %2$d', $paged, max( $paged, $pageTotal ) ) : '';
        $postType = '' !== $subtype ? get_post_type_object( $subtype ) : null;
        $authorId = $this->authorId( $resource );
        $author = $authorId > 0 ? get_userdata( $authorId ) : false;
        $categories = $this->taxonomyNamesForPost( $objectId, 'category' );
        $tags = $this->taxonomyNamesForPost( $objectId, 'post_tag' );
        $archiveTitle = $this->archiveTitle( $resource );
        $termDescription = $this->termDescription( $resource );
        $focusKeyphrase = $objectId > 0 ? trim( (string) get_post_meta( $objectId, '_yps_focus_keyphrase', true ) ) : '';
        $parentTitle = $this->parentTitle( $objectId );
        $caption = 'attachment' === $type && $objectId > 0 ? trim( wp_strip_all_tags( (string) wp_get_attachment_caption( $objectId ), true ) ) : '';

        $replacements = array(
            '%%title%%'                => $title,
            '%%postname%%'             => $title,
            '%%parent_title%%'         => $parentTitle,
            '%%archive_title%%'        => $archiveTitle,
            '%%sitename%%'             => $siteName,
            '%%sitedesc%%'             => $siteDesc,
            '%%tagline%%'              => $siteDesc,
            '%%searchphrase%%'         => function_exists( 'get_search_query' ) ? trim( wp_strip_all_tags( (string) get_search_query( false ), true ) ) : '',
            '%%search_phrase%%'        => function_exists( 'get_search_query' ) ? trim( wp_strip_all_tags( (string) get_search_query( false ), true ) ) : '',
            '%%term404%%'              => function_exists( 'get_query_var' ) ? trim( wp_strip_all_tags( (string) get_query_var( 'name', '' ), true ) ) : '',
            '%%sep%%'                  => $separator,
            '%%page%%'                 => $pageText,
            '%%pagetotal%%'            => $pageTotal > 1 ? (string) $pageTotal : '',
            '%%pagenumber%%'           => $paged > 1 ? (string) $paged : '',
            '%%excerpt%%'              => $excerpt,
            '%%excerpt_only%%'         => $excerptOnly,
            '%%term_title%%'           => $termName,
            '%%term_description%%'     => $termDescription,
            '%%category%%'             => implode( ', ', $categories ),
            '%%tag%%'                  => implode( ', ', $tags ),
            '%%category_description%%' => $this->taxonomyDescription( $resource, $objectId, 'category' ),
            '%%tag_description%%'      => $this->taxonomyDescription( $resource, $objectId, 'post_tag' ),
            '%%primary_category%%'     => $this->primaryCategory( $objectId ),
            '%%name%%'                 => $author instanceof \WP_User ? (string) $author->user_nicename : '',
            '%%user_description%%'     => $authorId > 0 ? trim( wp_strip_all_tags( (string) get_the_author_meta( 'description', $authorId ), true ) ) : '',
            '%%userid%%'               => $authorId > 0 ? (string) $authorId : '',
            '%%id%%'                   => $objectId > 0 ? (string) $objectId : '',
            '%%focuskw%%'              => $focusKeyphrase,
            '%%caption%%'              => $caption,
            '%%currenttime%%'          => wp_date( get_option( 'time_format' ) ),
            '%%currentdate%%'          => wp_date( get_option( 'date_format' ) ),
            '%%currentyear%%'          => wp_date( 'Y' ),
            '%%currentmonth%%'         => wp_date( 'F' ),
            '%%currentday%%'           => wp_date( 'j' ),
            '%%date%%'                 => $objectId > 0 ? (string) get_the_date( '', $objectId ) : '',
            '%%modified%%'             => $objectId > 0 ? (string) get_the_modified_date( '', $objectId ) : '',
            '%%pt_single%%'            => is_object( $postType ) && isset( $postType->labels->singular_name ) ? (string) $postType->labels->singular_name : '',
            '%%pt_plural%%'            => is_object( $postType ) && isset( $postType->labels->name ) ? (string) $postType->labels->name : '',
        );

        $resolved = strtr( $template, $replacements );
        $resolved = $this->resolveDynamicVariables( $resolved, $resource );
        $resolved = $this->resolveWooVariables( $resolved, $resource );

        // Unknown compatibility variables never leak literally into public output.
        $resolved = (string) preg_replace( '/%%[A-Za-z0-9_\-]+%%/', '', $resolved );
        $resolved = (string) preg_replace( '/\s+/u', ' ', $resolved );
        return trim( $resolved );
    }

    private function resolveDynamicVariables( string $template, ?ResourceIdentity $resource ): string {
        if ( null === $resource ) {
            return $template;
        }
        $postId = $resource->objectId();
        if ( $postId > 0 ) {
            $template = (string) preg_replace_callback(
                '/%%cf_([A-Za-z0-9_\-]+)%%/',
                static function ( array $match ) use ( $postId ): string {
                    $value = get_post_meta( $postId, $match[1], true );
                    return is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value, true ) ) : '';
                },
                $template
            );
            $template = (string) preg_replace_callback(
                '/%%ct_((?!desc_)[A-Za-z0-9_\-]+)%%/',
                static function ( array $match ) use ( $postId ): string {
                    if ( ! taxonomy_exists( $match[1] ) ) { return ''; }
                    $terms = get_the_terms( $postId, $match[1] );
                    if ( ! is_array( $terms ) ) { return ''; }
                    return implode( ', ', array_map( static function ( $term ): string { return is_object( $term ) && isset( $term->name ) ? trim( wp_strip_all_tags( (string) $term->name, true ) ) : ''; }, $terms ) );
                },
                $template
            );
        }
        $template = (string) preg_replace_callback(
            '/%%ct_desc_([A-Za-z0-9_\-]+)%%/',
            function ( array $match ) use ( $resource, $postId ): string {
                $taxonomy = $match[1];
                if ( ! taxonomy_exists( $taxonomy ) ) { return ''; }
                if ( 'term' === $resource->type() && $resource->subtype() === $taxonomy ) {
                    $term = get_term( $resource->objectId(), $taxonomy );
                    return $term && ! is_wp_error( $term ) ? trim( wp_strip_all_tags( (string) $term->description, true ) ) : '';
                }
                if ( $postId > 0 ) {
                    $terms = get_the_terms( $postId, $taxonomy );
                    if ( is_array( $terms ) && ! empty( $terms ) ) {
                        $term = reset( $terms );
                        return is_object( $term ) && isset( $term->description ) ? trim( wp_strip_all_tags( (string) $term->description, true ) ) : '';
                    }
                }
                return '';
            },
            $template
        );
        return $template;
    }

    private function resolveWooVariables( string $template, ?ResourceIdentity $resource ): string {
        if ( null === $resource || 'product' !== $resource->type() || $resource->objectId() < 1 ) {
            return $template;
        }
        $truth = ( new WooProductTruthResolver() )->resolve( $resource->objectId() );
        if ( null === $truth ) { return $template; }
        $shortDescription = trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $resource->objectId() ), true ) );
        return strtr(
            $template,
            array(
                '%%wc_shortdesc%%' => $shortDescription,
                '%%wc_sku%%'       => trim( (string) $truth->get( 'sku' ) ),
                '%%wc_brand%%'     => trim( (string) $truth->get( 'brand' ) ),
                '%%wc_price%%'     => trim( (string) $truth->get( 'price' ) ),
            )
        );
    }

    private function resourceTitle( ?ResourceIdentity $resource ): string {
        if ( null === $resource ) { return ''; }
        $id = $resource->objectId();
        if ( $id > 0 && in_array( $resource->type(), array( 'post', 'product', 'posts_page', 'home', 'attachment' ), true ) ) {
            return trim( wp_strip_all_tags( (string) get_the_title( $id ), true ) );
        }
        if ( 'term' === $resource->type() ) {
            $term = get_term( $id, $resource->subtype() );
            return $term && ! is_wp_error( $term ) ? trim( wp_strip_all_tags( (string) $term->name, true ) ) : '';
        }
        if ( 'author_archive' === $resource->type() ) {
            return trim( wp_strip_all_tags( (string) get_the_author_meta( 'display_name', $id ), true ) );
        }
        if ( 'post_type_archive' === $resource->type() ) {
            $object = get_post_type_object( $resource->subtype() );
            return is_object( $object ) && isset( $object->labels->name ) ? trim( wp_strip_all_tags( (string) $object->labels->name, true ) ) : '';
        }
        return '';
    }

    private function resourceExcerptOnly( ?ResourceIdentity $resource ): string {
        if ( null === $resource ) { return ''; }
        $id = $resource->objectId();
        if ( $id > 0 && in_array( $resource->type(), array( 'post', 'product', 'posts_page', 'home' ), true ) ) {
            return trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $id ), true ) );
        }
        if ( 'term' === $resource->type() ) {
            return $this->termDescription( $resource );
        }
        if ( 'author_archive' === $resource->type() ) {
            return trim( wp_strip_all_tags( (string) get_the_author_meta( 'description', $id ), true ) );
        }
        return '';
    }

    private function generatedExcerpt( ?ResourceIdentity $resource ): string {
        if ( null === $resource || $resource->objectId() < 1 ) { return ''; }
        if ( ! in_array( $resource->type(), array( 'post', 'product', 'posts_page', 'home' ), true ) ) { return ''; }
        return trim( wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $resource->objectId() ), true ), 32, '…' ) );
    }

    private function termName( ?ResourceIdentity $resource ): string {
        if ( null === $resource || 'term' !== $resource->type() ) { return ''; }
        $term = get_term( $resource->objectId(), $resource->subtype() );
        return $term && ! is_wp_error( $term ) ? trim( wp_strip_all_tags( (string) $term->name, true ) ) : '';
    }

    private function termDescription( ?ResourceIdentity $resource ): string {
        if ( null === $resource || 'term' !== $resource->type() ) { return ''; }
        $term = get_term( $resource->objectId(), $resource->subtype() );
        return $term && ! is_wp_error( $term ) ? trim( wp_strip_all_tags( (string) $term->description, true ) ) : '';
    }

    /** @return string[] */
    private function taxonomyNamesForPost( int $postId, string $taxonomy ): array {
        if ( $postId < 1 || ! taxonomy_exists( $taxonomy ) ) { return array(); }
        $terms = get_the_terms( $postId, $taxonomy );
        if ( ! is_array( $terms ) ) { return array(); }
        $names = array();
        foreach ( $terms as $term ) {
            if ( is_object( $term ) && isset( $term->name ) ) { $names[] = trim( wp_strip_all_tags( (string) $term->name, true ) ); }
        }
        return array_values( array_filter( $names, static function ( string $name ): bool { return '' !== $name; } ) );
    }

    private function taxonomyDescription( ?ResourceIdentity $resource, int $postId, string $taxonomy ): string {
        if ( null !== $resource && 'term' === $resource->type() && $resource->subtype() === $taxonomy ) {
            return $this->termDescription( $resource );
        }
        if ( $postId < 1 ) { return ''; }
        $terms = get_the_terms( $postId, $taxonomy );
        if ( ! is_array( $terms ) || empty( $terms ) ) { return ''; }
        $term = reset( $terms );
        return is_object( $term ) && isset( $term->description ) ? trim( wp_strip_all_tags( (string) $term->description, true ) ) : '';
    }

    private function primaryCategory( int $postId ): string {
        if ( $postId < 1 || ! is_object_in_taxonomy( (string) get_post_type( $postId ), 'category' ) ) { return ''; }
        $primary = (int) get_post_meta( $postId, '_yps_primary_category', true );
        if ( $primary > 0 ) {
            $term = get_term( $primary, 'category' );
            if ( $term && ! is_wp_error( $term ) ) { return trim( wp_strip_all_tags( (string) $term->name, true ) ); }
        }
        $terms = get_the_terms( $postId, 'category' );
        if ( ! is_array( $terms ) || empty( $terms ) ) { return ''; }
        $first = reset( $terms );
        return is_object( $first ) && isset( $first->name ) ? trim( wp_strip_all_tags( (string) $first->name, true ) ) : '';
    }

    private function parentTitle( int $postId ): string {
        if ( $postId < 1 ) { return ''; }
        $parentId = (int) wp_get_post_parent_id( $postId );
        return $parentId > 0 ? trim( wp_strip_all_tags( (string) get_the_title( $parentId ), true ) ) : '';
    }

    private function archiveTitle( ?ResourceIdentity $resource ): string {
        if ( null === $resource ) { return ''; }
        if ( 'term' === $resource->type() ) { return $this->termName( $resource ); }
        if ( 'author_archive' === $resource->type() ) { return $this->resourceTitle( $resource ); }
        if ( 'post_type_archive' === $resource->type() ) { return $this->resourceTitle( $resource ); }
        return '';
    }

    private function authorId( ?ResourceIdentity $resource ): int {
        if ( null === $resource ) { return 0; }
        if ( 'author_archive' === $resource->type() ) { return $resource->objectId(); }
        if ( $resource->objectId() > 0 && in_array( $resource->type(), array( 'post', 'product', 'posts_page', 'home', 'attachment' ), true ) ) {
            return (int) get_post_field( 'post_author', $resource->objectId() );
        }
        return 0;
    }

    private function separator(): string {
        $globals = get_option( 'yps_shadow_global_settings', array() );
        $raw = is_array( $globals ) && isset( $globals['templates.separator'] ) ? trim( (string) $globals['templates.separator'] ) : '';
        if ( '' === $raw ) { return '-'; }

        // Yoast persists separator identifiers such as "sc-dash", not always the glyph.
        // Resolve the documented/interoperable values locally; never emit the identifier.
        $map = array(
            'sc-dash'   => '-',
            'sc-ndash'  => '–',
            'sc-mdash'  => '—',
            'sc-middot' => '·',
            'sc-bull'   => '•',
            'sc-star'   => '*',
            'sc-pipe'   => '|',
            'sc-tilde'  => '~',
            'sc-laquo'  => '«',
            'sc-raquo'  => '»',
            'sc-lt'     => '<',
            'sc-gt'     => '>',
        );
        if ( isset( $map[ $raw ] ) ) { return $map[ $raw ]; }
        if ( 0 === strpos( $raw, 'sc-' ) ) { return '-'; }
        $value = trim( wp_strip_all_tags( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ), true ) );
        return '' !== $value ? $value : '-';
    }
}
