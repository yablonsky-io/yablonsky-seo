<?php
namespace Yablonsky\PerformanceSEO\Visibility;

use Yablonsky\PerformanceSEO\SEO\Freshness\FreshnessResolver;
use Yablonsky\PerformanceSEO\Commerce\WooProductTruthResolver;
use Yablonsky\PerformanceSEO\Language\WordPressLanguageProvider;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Converts saved, rendered WordPress content into a compact Markdown alternate.
 * HTML remains canonical; this renderer exists only for explicit agent-readable requests.
 */
final class MarkdownRenderer {
    public function renderPost( \WP_Post $post ): string {
        // Public machine documents must never expose password-protected or unpublished content, even to browsers carrying a password cookie.
        if ( 'publish' !== (string) $post->post_status || '' !== (string) $post->post_password ) { return ''; }
        $title = trim( wp_strip_all_tags( (string) get_the_title( $post ), true ) );
        $canonical = get_permalink( $post );
        $description = trim( (string) get_post_meta( $post->ID, '_yps_meta_description', true ) );
        if ( '' === $description ) {
            $description = trim( wp_strip_all_tags( (string) get_the_excerpt( $post ), true ) );
        }
        $language = $this->resourceLanguage( 'product' === $post->post_type ? 'product' : 'post', (int) $post->ID, is_string( $canonical ) ? $canonical : '' );
        $modified = $this->mysqlUtcToW3c( ( new FreshnessResolver() )->postLastmod( (int) $post->ID ) );
        $author = trim( wp_strip_all_tags( (string) get_the_author_meta( 'display_name', (int) $post->post_author ), true ) );
        $preferredImage = trim( (string) get_post_meta( $post->ID, '_yps_og_image', true ) );
        if ( '' === $preferredImage && has_post_thumbnail( $post ) ) {
            $featured = get_the_post_thumbnail_url( $post, 'full' );
            $preferredImage = is_string( $featured ) ? $featured : '';
        }

        $lines = array( '# ' . ( '' !== $title ? $title : __( 'Untitled content', 'yablonsky-seo' ) ), '' );
        if ( is_string( $canonical ) && '' !== $canonical ) { $lines[] = 'Canonical: ' . $canonical; }
        if ( '' !== $language ) { $lines[] = 'Language: ' . $language; }
        $this->appendLanguageAlternates( $lines, 'product' === $post->post_type ? 'product' : 'post', (int) $post->ID, is_string( $canonical ) ? $canonical : '' );
        if ( is_string( $modified ) && '' !== $modified ) { $lines[] = 'Last modified: ' . $modified; }
        if ( '' !== $author ) { $lines[] = 'Author: ' . $author; }
        if ( '' !== $preferredImage ) { $lines[] = 'Preferred image: ' . $preferredImage; }
        $orientation = ( new AgentResourceLocator() )->describedByUrl( 'post', (int) $post->ID, is_string( $canonical ) ? $canonical : '' );
        if ( '' !== $orientation ) { $lines[] = 'Agent orientation: ' . $orientation; }
        if ( '' !== $description ) { $lines[] = ''; $lines[] = $description; }

        $previousPost = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ? $GLOBALS['post'] : null;
        $GLOBALS['post'] = $post;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally invoke the WordPress core content filter for canonical saved-content rendering.
        $html = apply_filters( 'the_content', (string) $post->post_content );
        if ( $previousPost instanceof \WP_Post ) { $GLOBALS['post'] = $previousPost; } else { unset( $GLOBALS['post'] ); }
        $body = $this->htmlToMarkdown( is_string( $html ) ? $html : '' );
        if ( '' !== $body ) { $lines[] = ''; $lines[] = $body; }

        $productFacts = $this->productFacts( $post );
        if ( ! empty( $productFacts ) ) {
            $lines[] = '';
            $lines[] = '## Product facts';
            foreach ( $productFacts as $label => $value ) { $lines[] = '- ' . $label . ': ' . $value; }
        }

        return trim( implode( "\n", $lines ) ) . "\n";
    }



    public function renderTerm( \WP_Term $term ): string {
        $taxonomy = get_taxonomy( $term->taxonomy );
        $taxonomyLabel = $taxonomy && isset( $taxonomy->labels->singular_name ) ? (string) $taxonomy->labels->singular_name : (string) $term->taxonomy;
        $canonical = get_term_link( $term );
        $description = trim( wp_strip_all_tags( (string) term_description( (int) $term->term_id ), true ) );
        if ( '' === $description ) { $description = trim( wp_strip_all_tags( (string) $term->description, true ) ); }
        $locator = new AgentResourceLocator();
        $lines = array( '# ' . trim( wp_strip_all_tags( (string) $term->name, true ) ), '' );
        if ( ! is_wp_error( $canonical ) ) { $lines[] = 'Canonical: ' . (string) $canonical; }
        $lines[] = 'Resource type: taxonomy term';
        $lines[] = 'Taxonomy: ' . trim( wp_strip_all_tags( $taxonomyLabel, true ) );
        $language = $this->resourceLanguage( 'term', (int) $term->term_id, ! is_wp_error( $canonical ) ? (string) $canonical : '' );
        if ( '' !== $language ) { $lines[] = 'Language: ' . $language; }
        $this->appendLanguageAlternates( $lines, 'term', (int) $term->term_id, ! is_wp_error( $canonical ) ? (string) $canonical : '' );
        $modified = $this->mysqlUtcToW3c( ( new FreshnessResolver() )->termLastmod( (int) $term->term_id, (string) $term->taxonomy ) );
        if ( '' !== $modified ) { $lines[] = 'Last modified: ' . $modified; }
        if ( ! is_wp_error( $canonical ) ) {
            $orientation = $locator->describedByUrl( 'term', (int) $term->term_id, (string) $canonical );
            if ( '' !== $orientation ) { $lines[] = 'Agent orientation: ' . $orientation; }
        }
        if ( '' !== $description ) { $lines[] = ''; $lines[] = $description; }

        $children = get_terms(
            array(
                'taxonomy' => $term->taxonomy,
                'parent' => (int) $term->term_id,
                'hide_empty' => true,
                'number' => 30,
                'orderby' => 'name',
                'order' => 'ASC',
                'suppress_filter' => false,
            )
        );
        if ( is_array( $children ) && ! empty( $children ) ) {
            $lines[] = '';
            $lines[] = '## Subsections';
            foreach ( $children as $child ) {
                if ( ! $child instanceof \WP_Term ) { continue; }
                $childUrl = get_term_link( $child );
                if ( is_wp_error( $childUrl ) ) { continue; }
                $childMd = $locator->markdownUrl( 'term', (int) $child->term_id, (string) $childUrl );
                if ( '' !== $childMd ) { $lines[] = '- [' . $this->safeLabel( (string) $child->name ) . '](' . $childMd . ')'; }
            }
        }

        $query = new \WP_Query(
            array(
                'post_type' => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
                'post_status' => 'publish',
                'has_password' => false,
                'posts_per_page' => 40,
                'orderby' => 'modified',
                'order' => 'DESC',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'suppress_filters' => false,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Bounded to 40 published rows for one resolved public term; needed to build the term's agent navigation.
                'tax_query' => array(
                    array(
                        'taxonomy' => $term->taxonomy,
                        'field' => 'term_id',
                        'terms' => array( (int) $term->term_id ),
                        'include_children' => true,
                    ),
                ),
            )
        );
        if ( ! empty( $query->posts ) ) {
            $lines[] = '';
            $lines[] = '## Related content';
            foreach ( $query->posts as $post ) {
                if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type || '1' === (string) get_post_meta( $post->ID, '_yps_robots_noindex', true ) ) { continue; }
                $url = get_permalink( $post );
                if ( ! is_string( $url ) || '' === $url || false !== strpos( $url, '?' ) ) { continue; }
                $md = $locator->markdownUrl( 'post', (int) $post->ID, $url );
                if ( '' !== $md ) { $lines[] = '- [' . $this->safeLabel( (string) get_the_title( $post ) ) . '](' . $md . ')'; }
            }
        }
        wp_reset_postdata();
        return trim( implode( "\n", $lines ) ) . "\n";
    }

    public function renderAuthor( \WP_User $user ): string {
        $displayName = trim( wp_strip_all_tags( (string) $user->display_name, true ) );
        $canonical = get_author_posts_url( (int) $user->ID );
        $description = trim( wp_strip_all_tags( (string) get_user_meta( (int) $user->ID, 'description', true ), true ) );
        $locator = new AgentResourceLocator();
        $lines = array( '# ' . ( '' !== $displayName ? $displayName : __( 'Author', 'yablonsky-seo' ) ), '' );
        $lines[] = 'Canonical: ' . $canonical;
        $lines[] = 'Resource type: author archive';
        $language = $this->resourceLanguage( 'author_archive', (int) $user->ID, $canonical );
        if ( '' !== $language ) { $lines[] = 'Language: ' . $language; }
        $this->appendLanguageAlternates( $lines, 'author_archive', (int) $user->ID, $canonical );
        $modified = $this->mysqlUtcToW3c( ( new FreshnessResolver() )->authorLastmod( (int) $user->ID ) );
        if ( '' !== $modified ) { $lines[] = 'Last modified: ' . $modified; }
        $orientation = $locator->describedByUrl( 'author', (int) $user->ID, $canonical );
        if ( '' !== $orientation ) { $lines[] = 'Agent orientation: ' . $orientation; }
        if ( '' !== $description ) { $lines[] = ''; $lines[] = $description; }

        $query = new \WP_Query(
            array(
                'author' => (int) $user->ID,
                'post_type' => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
                'post_status' => 'publish',
                'has_password' => false,
                'posts_per_page' => 40,
                'orderby' => 'modified',
                'order' => 'DESC',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'suppress_filters' => false,
            )
        );
        if ( ! empty( $query->posts ) ) {
            $lines[] = '';
            $lines[] = '## Recent content';
            foreach ( $query->posts as $post ) {
                if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type || '1' === (string) get_post_meta( $post->ID, '_yps_robots_noindex', true ) ) { continue; }
                $url = get_permalink( $post );
                if ( ! is_string( $url ) || '' === $url || false !== strpos( $url, '?' ) ) { continue; }
                $md = $locator->markdownUrl( 'post', (int) $post->ID, $url );
                if ( '' !== $md ) { $lines[] = '- [' . $this->safeLabel( (string) get_the_title( $post ) ) . '](' . $md . ')'; }
            }
        }
        wp_reset_postdata();
        return trim( implode( "\n", $lines ) ) . "\n";
    }

    public function renderPostTypeArchive( string $postType ): string {
        $object = get_post_type_object( $postType );
        $canonical = get_post_type_archive_link( $postType );
        if ( ! $object || empty( $object->public ) || empty( $object->has_archive ) || ! is_string( $canonical ) || '' === $canonical ) { return ''; }

        $title = isset( $object->labels->name ) ? trim( wp_strip_all_tags( (string) $object->labels->name, true ) ) : $postType;
        $description = isset( $object->description ) ? trim( wp_strip_all_tags( (string) $object->description, true ) ) : '';
        $locator = new AgentResourceLocator();
        $lines = array( '# ' . ( '' !== $title ? $title : $postType ), '' );
        $lines[] = 'Canonical: ' . $canonical;
        $lines[] = 'Resource type: post type archive';
        $lines[] = 'Post type: ' . sanitize_key( $postType );
        $language = $this->resourceLanguage( 'post_type_archive', 0, $canonical );
        if ( '' !== $language ) { $lines[] = 'Language: ' . $language; }
        $modified = $this->mysqlUtcToW3c( ( new FreshnessResolver() )->postTypeArchiveLastmod( $postType ) );
        if ( '' !== $modified ) { $lines[] = 'Last modified: ' . $modified; }
        $orientation = $locator->describedByUrl( 'post_type_archive', 0, $canonical );
        if ( '' !== $orientation ) { $lines[] = 'Agent orientation: ' . $orientation; }
        if ( '' !== $description ) { $lines[] = ''; $lines[] = $description; }

        $query = new \WP_Query(
            array(
                'post_type' => $postType,
                'post_status' => 'publish',
                'has_password' => false,
                'posts_per_page' => 60,
                'orderby' => 'modified',
                'order' => 'DESC',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'suppress_filters' => false,
                'fields' => 'ids',
            )
        );
        if ( ! empty( $query->posts ) ) {
            $lines[] = '';
            $lines[] = '## Recent resources';
            foreach ( $query->posts as $postId ) {
                $postId = (int) $postId;
                $url = get_permalink( $postId );
                if ( ! is_string( $url ) || '' === $url || '1' === (string) get_post_meta( $postId, '_yps_robots_noindex', true ) ) { continue; }
                $md = $locator->markdownUrl( 'post', $postId, $url );
                if ( '' === $md ) { continue; }
                $label = $this->safeLabel( (string) get_the_title( $postId ) );
                if ( '' !== $label ) { $lines[] = '- [' . $label . '](' . $md . ')'; }
            }
        }
        wp_reset_postdata();
        return trim( implode( "\n", $lines ) ) . "\n";
    }

    public function renderSiteIndex(): string {
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $name = trim( (string) ( $settings['site_display_name'] ?? '' ) );
        if ( '' === $name ) { $name = trim( (string) get_bloginfo( 'name' ) ); }
        $description = trim( (string) ( $settings['site_description'] ?? '' ) );
        if ( '' === $description ) { $description = trim( (string) get_bloginfo( 'description' ) ); }
        $locator = new AgentResourceLocator();
        $lines = array( '# ' . ( '' !== $name ? $name : __( 'Site index', 'yablonsky-seo' ) ), '' );
        $lines[] = 'Canonical: ' . home_url( '/' );
        $lines[] = 'Resource type: site index';
        $language = $this->resourceLanguage( 'home', (int) get_option( 'page_on_front', 0 ), home_url( '/' ) );
        if ( '' !== $language ) { $lines[] = 'Language: ' . $language; }
        $modified = $this->mysqlUtcToW3c( ( new FreshnessResolver() )->siteLastmod() );
        if ( '' !== $modified ) { $lines[] = 'Last modified: ' . $modified; }
        $lines[] = 'Agent orientation: ' . home_url( '/llms.txt' );
        if ( '' !== $description ) { $lines[] = ''; $lines[] = $description; }

        $archiveLines = array();
        foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ), 'objects' ) as $postType => $object ) {
            $archive = get_post_type_archive_link( $postType );
            if ( ! is_string( $archive ) || '' === $archive ) { continue; }
            $md = $locator->markdownUrl( 'post_type_archive', 0, $archive );
            if ( '' === $md ) { continue; }
            $label = isset( $object->labels->name ) ? $this->safeLabel( (string) $object->labels->name ) : sanitize_key( (string) $postType );
            if ( '' !== $label ) { $archiveLines[] = '- [' . $label . '](' . $md . ')'; }
        }
        if ( ! empty( $archiveLines ) ) {
            $lines[] = '';
            $lines[] = '## Main sections';
            foreach ( array_slice( $archiveLines, 0, 30 ) as $line ) { $lines[] = $line; }
        }

        $collectionLines = array();
        $remainingCollections = 30;
        foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy => $taxonomyObject ) {
            if ( $remainingCollections <= 0 ) { break; }
            if ( 'post_format' === $taxonomy ) { continue; }
            $terms = get_terms(
                array(
                    'taxonomy' => $taxonomy,
                    'parent' => 0,
                    'hide_empty' => true,
                    'number' => min( 8, $remainingCollections ),
                    'orderby' => 'count',
                    'order' => 'DESC',
                    'suppress_filter' => false,
                )
            );
            if ( ! is_array( $terms ) ) { continue; }
            foreach ( $terms as $term ) {
                if ( ! $term instanceof \WP_Term || $remainingCollections <= 0 ) { continue; }
                $url = get_term_link( $term );
                if ( is_wp_error( $url ) ) { continue; }
                $md = $locator->markdownUrl( 'term', (int) $term->term_id, (string) $url );
                if ( '' === $md ) { continue; }
                $taxonomyLabel = isset( $taxonomyObject->labels->singular_name ) ? $this->safeLabel( (string) $taxonomyObject->labels->singular_name ) : '';
                $suffix = '' !== $taxonomyLabel ? ' · ' . $taxonomyLabel : '';
                $collectionLines[] = '- [' . $this->safeLabel( (string) $term->name ) . '](' . $md . ')' . $suffix;
                $remainingCollections--;
            }
        }
        if ( ! empty( $collectionLines ) ) {
            $lines[] = '';
            $lines[] = '## Main collections';
            foreach ( $collectionLines as $line ) { $lines[] = $line; }
        }

        $postTypes = array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) );
        $query = new \WP_Query( array( 'post_type' => $postTypes, 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 60, 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'ignore_sticky_posts' => true, 'suppress_filters' => false, 'fields' => 'ids' ) );
        if ( ! empty( $query->posts ) ) {
            $lines[] = '';
            $lines[] = '## Recent resources';
            foreach ( $query->posts as $postId ) {
                $postId = (int) $postId;
                $url = get_permalink( $postId );
                if ( ! is_string( $url ) || '' === $url || '1' === (string) get_post_meta( $postId, '_yps_robots_noindex', true ) ) { continue; }
                $md = $locator->markdownUrl( 'post', $postId, $url );
                if ( '' === $md ) { continue; }
                $label = $this->safeLabel( (string) get_the_title( $postId ) );
                if ( '' !== $label ) { $lines[] = '- [' . $label . '](' . $md . ')'; }
            }
        }
        wp_reset_postdata();
        return trim( implode( "\n", $lines ) ) . "\n";
    }

    public function htmlToMarkdown( string $html ): string {
        if ( '' === trim( $html ) ) { return ''; }
        if ( ! class_exists( '\\DOMDocument' ) ) {
            return $this->fallbackHtmlToMarkdown( $html );
        }

        $previous = libxml_use_internal_errors( true );
        $dom = new \DOMDocument( '1.0', 'UTF-8' );
        $wrapped = '<!doctype html><html><body><div id="yseo-md-root">' . $html . '</div></body></html>';
        $loaded = $dom->loadHTML( '<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) { return $this->fallbackHtmlToMarkdown( $html ); }
        $root = $dom->getElementById( 'yseo-md-root' );
        if ( ! $root ) { return $this->fallbackHtmlToMarkdown( $html ); }

        foreach ( array( 'script', 'style', 'noscript', 'iframe', 'svg', 'form', 'button', 'nav' ) as $tag ) {
            while ( $root->getElementsByTagName( $tag )->length > 0 ) {
                $node = $root->getElementsByTagName( $tag )->item( 0 );
                if ( $node && $node->parentNode ) { $node->parentNode->removeChild( $node ); } else { break; }
            }
        }

        $out = '';
        foreach ( iterator_to_array( $root->childNodes ) as $child ) { $out .= $this->renderNode( $child ); }
        return $this->normalizeMarkdown( $out );
    }


    private function fallbackHtmlToMarkdown( string $html ): string {
        $html = preg_replace( '#<(script|style|noscript|iframe|svg|form|button|nav)\b[^>]*>.*?</\1>#is', '', $html ) ?: $html;
        $html = preg_replace_callback(
            '#<img\b[^>]*>#i',
            static function ( array $match ): string {
                $tag = (string) $match[0];
                $src = '';
                $alt = '';
                if ( preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) { $src = esc_url_raw( html_entity_decode( (string) $m[2], ENT_QUOTES, 'UTF-8' ), array( 'http', 'https' ) ); }
                if ( preg_match( '/\balt\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) { $alt = trim( wp_strip_all_tags( html_entity_decode( (string) $m[2], ENT_QUOTES, 'UTF-8' ), true ) ); }
                return '' !== $src ? '![' . $alt . '](' . $src . ')' : '';
            },
            $html
        ) ?: $html;
        $html = preg_replace_callback(
            '#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function ( array $match ): string {
                $url = esc_url_raw( html_entity_decode( (string) $match[2], ENT_QUOTES, 'UTF-8' ), array( 'http', 'https' ) );
                $label = trim( wp_strip_all_tags( (string) $match[3], true ) );
                return '' !== $url && '' !== $label ? '[' . $label . '](' . $url . ')' : $label;
            },
            $html
        ) ?: $html;
        for ( $level = 1; $level <= 6; $level++ ) {
            $pattern = '#<h' . $level . '\b[^>]*>(.*?)</h' . $level . '>#is';
            $html = preg_replace_callback( $pattern, static function ( array $match ) use ( $level ): string { return "\n\n" . str_repeat( '#', $level ) . ' ' . trim( wp_strip_all_tags( (string) $match[1], true ) ) . "\n\n"; }, $html ) ?: $html;
        }
        $html = preg_replace( '#<br\s*/?>#i', "  \n", $html ) ?: $html;
        $html = preg_replace_callback( '#<li\b[^>]*>(.*?)</li>#is', static function ( array $match ): string { return "\n- " . trim( wp_strip_all_tags( (string) $match[1], true ) ); }, $html ) ?: $html;
        $html = preg_replace_callback( '#<(strong|b)\b[^>]*>(.*?)</\1>#is', static function ( array $match ): string { return '**' . trim( wp_strip_all_tags( (string) $match[2], true ) ) . '**'; }, $html ) ?: $html;
        $html = preg_replace_callback( '#<(em|i)\b[^>]*>(.*?)</\1>#is', static function ( array $match ): string { return '*' . trim( wp_strip_all_tags( (string) $match[2], true ) ) . '*'; }, $html ) ?: $html;
        $html = preg_replace_callback( '#<blockquote\b[^>]*>(.*?)</blockquote>#is', static function ( array $match ): string { $text = trim( wp_strip_all_tags( (string) $match[1], true ) ); return "\n\n> " . str_replace( "\n", "\n> ", $text ) . "\n\n"; }, $html ) ?: $html;
        $html = preg_replace_callback( '#<pre\b[^>]*>(.*?)</pre>#is', static function ( array $match ): string { return "\n\n```\n" . trim( html_entity_decode( wp_strip_all_tags( (string) $match[1], true ), ENT_QUOTES, 'UTF-8' ) ) . "\n```\n\n"; }, $html ) ?: $html;
        $html = preg_replace( '#</?(ul|ol|table|thead|tbody|tfoot|tr)\b[^>]*>#i', "\n", $html ) ?: $html;
        $html = preg_replace( '#</?(th|td)\b[^>]*>#i', ' | ', $html ) ?: $html;
        $html = preg_replace( '#</?(p|div|section|article|main|figure|figcaption)\b[^>]*>#i', "\n\n", $html ) ?: $html;
        $text = html_entity_decode( wp_strip_all_tags( $html, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return $this->normalizeMarkdown( $text );
    }

    private function renderNode( \DOMNode $node ): string {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return preg_replace( '/\s+/u', ' ', (string) $node->nodeValue ) ?: '';
        }
        if ( XML_ELEMENT_NODE !== $node->nodeType ) { return ''; }
        $tag = strtolower( $node->nodeName );
        $inner = '';
        foreach ( iterator_to_array( $node->childNodes ) as $child ) { $inner .= $this->renderNode( $child ); }
        $text = trim( $inner );

        if ( preg_match( '/^h([1-6])$/', $tag, $match ) ) { return "\n\n" . str_repeat( '#', (int) $match[1] ) . ' ' . $text . "\n\n"; }
        if ( 'p' === $tag || 'div' === $tag || 'section' === $tag || 'article' === $tag || 'main' === $tag ) { return '' === $text ? '' : "\n\n" . $text . "\n\n"; }
        if ( 'br' === $tag ) { return "  \n"; }
        if ( in_array( $tag, array( 'strong', 'b' ), true ) ) { return '' === $text ? '' : '**' . $text . '**'; }
        if ( in_array( $tag, array( 'em', 'i' ), true ) ) { return '' === $text ? '' : '*' . $text . '*'; }
        if ( 'code' === $tag && 'pre' !== strtolower( $node->parentNode ? $node->parentNode->nodeName : '' ) ) { return '' === $text ? '' : '`' . str_replace( '`', '\\`', $text ) . '`'; }
        if ( 'pre' === $tag ) { return "\n\n```\n" . trim( (string) $node->textContent ) . "\n```\n\n"; }
        if ( 'blockquote' === $tag ) { return "\n\n> " . str_replace( "\n", "\n> ", $text ) . "\n\n"; }
        if ( 'a' === $tag && $node instanceof \DOMElement ) {
            $href = esc_url_raw( $node->getAttribute( 'href' ), array( 'http', 'https' ) );
            return '' !== $href && '' !== $text ? '[' . $text . '](' . $href . ')' : $text;
        }
        if ( 'img' === $tag && $node instanceof \DOMElement ) {
            $src = esc_url_raw( $node->getAttribute( 'src' ), array( 'http', 'https' ) );
            $alt = trim( wp_strip_all_tags( $node->getAttribute( 'alt' ), true ) );
            return '' !== $src ? '![' . $alt . '](' . $src . ')' : '';
        }
        if ( 'li' === $tag ) { return "\n- " . $text; }
        if ( in_array( $tag, array( 'ul', 'ol' ), true ) ) { return "\n" . trim( $inner ) . "\n"; }
        if ( 'hr' === $tag ) { return "\n\n---\n\n"; }
        if ( 'table' === $tag ) { return "\n\n" . $this->tableText( $node ) . "\n\n"; }
        return $inner;
    }

    private function tableText( \DOMNode $table ): string {
        $rows = array();
        if ( ! $table instanceof \DOMElement ) { return ''; }
        foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
            $cells = array();
            foreach ( $tr->childNodes as $cell ) {
                if ( ! $cell instanceof \DOMElement || ! in_array( strtolower( $cell->tagName ), array( 'th', 'td' ), true ) ) { continue; }
                $cells[] = trim( preg_replace( '/\s+/u', ' ', (string) $cell->textContent ) ?: '' );
            }
            if ( ! empty( $cells ) ) { $rows[] = '| ' . implode( ' | ', $cells ) . ' |'; }
        }
        return implode( "\n", $rows );
    }


    /** @param array<int,string> $lines */
    private function appendLanguageAlternates( array &$lines, string $resourceType, int $objectId, string $canonical ): void {
        $provider = new WordPressLanguageProvider();
        $alternates = $provider->alternates( $resourceType, $objectId );
        if ( ! is_array( $alternates ) || empty( $alternates ) ) { return; }

        $normalized = array();
        foreach ( $alternates as $alternate ) {
            if ( ! is_array( $alternate ) ) { continue; }
            $languageKey = $this->normalizeLanguageKey( (string) ( $alternate['language_key'] ?? '' ) );
            $url = esc_url_raw( (string) ( $alternate['url'] ?? '' ), array( 'http', 'https' ) );
            if ( '' === $languageKey || '' === $url ) { continue; }
            $normalized[ $languageKey ] = $url;
        }
        if ( empty( $normalized ) ) { return; }

        $selfLanguage = $this->normalizeLanguageKey( $provider->currentLanguageKey() );
        $canonical = esc_url_raw( $canonical, array( 'http', 'https' ) );
        if ( '' !== $selfLanguage && '' !== $canonical ) { $normalized[ $selfLanguage ] = $canonical; }
        ksort( $normalized, SORT_STRING );

        $added = 0;
        foreach ( $normalized as $languageKey => $url ) {
            if ( $added >= 12 ) { break; }
            $lines[] = 'Language alternate [' . $this->safeLabel( $languageKey ) . ']: ' . $url;
            $added++;
        }
    }

    private function resourceLanguage( string $resourceType, int $objectId, string $url ): string {
        $resolved = ( new ResourceLanguageResolver() )->resolve( $resourceType, $objectId, $url, true );
        $language = isset( $resolved['language_key'] ) ? (string) $resolved['language_key'] : '';
        return 'auto' === $language || 'x-default' === $language ? '' : $language;
    }

    private function normalizeLanguageKey( string $language ): string {
        $language = strtolower( str_replace( '_', '-', trim( $language ) ) );
        if ( 'x-default' === $language ) { return $language; }
        return preg_match( '/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/', $language ) ? $language : '';
    }

    /** @return array<string,string> */
    private function productFacts( \WP_Post $post ): array {
        if ( 'product' !== $post->post_type ) { return array(); }
        $truth = ( new WooProductTruthResolver() )->resolve( (int) $post->ID );
        if ( null === $truth ) { return array(); }
        $data = $truth->toArray();
        $facts = array( 'Product ID' => (string) (int) ( $data['product_id'] ?? $post->ID ) );

        $type = sanitize_key( (string) ( $data['product_type'] ?? '' ) );
        if ( '' !== $type ) { $facts['Product type'] = $type; }
        $variantCount = (int) ( $data['variant_count'] ?? 0 );
        if ( $variantCount > 0 ) { $facts['Variant count'] = (string) $variantCount; }
        $variesBy = is_array( $data['varies_by'] ?? null ) ? array_filter( array_map( 'strval', $data['varies_by'] ) ) : array();
        if ( ! empty( $variesBy ) ) { $facts['Varies by'] = implode( ', ', array_slice( $variesBy, 0, 8 ) ); }

        foreach ( array( 'sku' => 'SKU', 'gtin' => 'Woo global unique ID', 'mpn' => 'MPN', 'brand' => 'Brand', 'category' => 'Category' ) as $key => $label ) {
            $value = trim( wp_strip_all_tags( (string) ( $data[ $key ] ?? '' ), true ) );
            if ( '' !== $value ) { $facts[ $label ] = $value; }
        }

        $price = trim( (string) ( $data['price'] ?? '' ) );
        if ( '' !== $price ) { $facts['Price'] = $price; }
        $currency = strtoupper( trim( (string) ( $data['currency'] ?? '' ) ) );
        if ( preg_match( '/^[A-Z]{3}$/', $currency ) ) { $facts['Currency'] = $currency; }
        $sale = trim( (string) ( $data['sale_price'] ?? '' ) );
        if ( '' !== $sale ) { $facts['Sale price'] = $sale; }
        $saleUntil = trim( (string) ( $data['sale_valid_until'] ?? '' ) );
        if ( '' !== $saleUntil ) { $facts['Sale valid until'] = $saleUntil; }

        $availability = trim( (string) ( $data['availability'] ?? '' ) );
        $availabilityLabels = array(
            'https://schema.org/InStock' => 'In stock',
            'https://schema.org/OutOfStock' => 'Out of stock',
            'https://schema.org/BackOrder' => 'On backorder',
        );
        if ( isset( $availabilityLabels[ $availability ] ) ) { $facts['Availability'] = $availabilityLabels[ $availability ]; }

        $productUrl = esc_url_raw( (string) ( $data['product_url'] ?? '' ), array( 'http', 'https' ) );
        if ( '' !== $productUrl ) { $facts['Product URL'] = $productUrl; }

        $specs = is_array( $data['specs'] ?? null ) ? $data['specs'] : array();
        $added = 0;
        foreach ( $specs as $label => $value ) {
            if ( $added >= 12 ) { break; }
            $label = trim( wp_strip_all_tags( (string) $label, true ) );
            $value = trim( wp_strip_all_tags( (string) $value, true ) );
            if ( '' === $label || '' === $value ) { continue; }
            $facts['Attribute · ' . $label] = $value;
            $added++;
        }
        return $facts;
    }

    private function mysqlUtcToW3c( string $mysql ): string {
        $mysql = trim( $mysql );
        if ( '' === $mysql ) { return ''; }
        $timestamp = strtotime( $mysql . ' UTC' );
        return false === $timestamp ? '' : gmdate( DATE_W3C, $timestamp );
    }

    private function safeLabel( string $label ): string {
        return str_replace( array( '[', ']' ), '', trim( wp_strip_all_tags( $label, true ) ) );
    }

    private function normalizeWhitespace( string $text ): string {
        $text = preg_replace( "/[ \t]+/u", ' ', $text ) ?: '';
        $text = preg_replace( "/\n{3,}/u", "\n\n", $text ) ?: $text;
        return trim( $text );
    }

    private function normalizeMarkdown( string $text ): string {
        $text = preg_replace( "/[ \t]+\n/u", "\n", $text ) ?: $text;
        $text = preg_replace( "/\n{3,}/u", "\n\n", $text ) ?: $text;
        return trim( $text );
    }
}
