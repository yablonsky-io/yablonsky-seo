<?php
namespace Yablonsky\PerformanceSEO\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves canonical WordPress resources to their agent-readable alternates.
 *
 * The locator is intentionally read-only. HTML remains canonical; these URLs
 * are discovery hints and alternate representations only.
 */
final class AgentResourceLocator {
    public const SITEMAP_NAMESPACE = 'https://yablonsky.io/ns/agent-sitemap/1.0';

    public function enabled(): bool {
        $settings = get_option( 'yps_public_settings', array() );
        return '1' === (string) get_option( 'blog_public', '1' ) && is_array( $settings ) && ! empty( $settings['agent_markdown_enabled'] );
    }

    public function scopedLlmsEnabled(): bool {
        if ( ! $this->enabled() ) { return false; }
        $settings = get_option( 'yps_public_settings', array() );
        return is_array( $settings ) && ! empty( $settings['agent_scoped_llms_enabled'] );
    }

    public function sitemapExtensionEnabled(): bool {
        if ( ! $this->enabled() ) { return false; }
        $settings = get_option( 'yps_public_settings', array() );
        return is_array( $settings ) && ! empty( $settings['agent_sitemap_extension_enabled'] );
    }

    public function markdownUrl( string $type, int $objectId, string $canonical = '' ): string {
        if ( ! $this->enabled() ) { return ''; }
        if ( 'post' === $type && $objectId > 0 ) { return $this->postMarkdownUrl( $objectId, $canonical ); }
        if ( 'term' === $type && $objectId > 0 ) { return $this->termMarkdownUrl( $objectId, $canonical ); }
        if ( 'author' === $type && $objectId > 0 ) { return $this->authorMarkdownUrl( $objectId, $canonical ); }
        if ( 'post_type_archive' === $type ) { return $this->postTypeArchiveMarkdownUrl( $canonical ); }
        if ( 'site' === $type ) { return $this->homeMarkdownUrl(); }
        return '';
    }

    public function homeMarkdownUrl(): string {
        return $this->enabled() ? home_url( '/index.md' ) : '';
    }

    public function describedByUrl( string $type, int $objectId, string $canonical = '' ): string {
        if ( ! $this->enabled() ) { return ''; }
        $root = home_url( '/llms.txt' );
        if ( 'site' === $type ) { return $root; }
        if ( ! $this->scopedLlmsEnabled() ) { return $root; }
        if ( 'post_type_archive' === $type ) {
            $postType = $this->resolvePostTypeArchiveByUrl( $canonical );
            $archive = '' !== $postType ? get_post_type_archive_link( $postType ) : '';
            return is_string( $archive ) && '' !== $archive ? trailingslashit( $archive ) . 'llms.txt' : $root;
        }

        if ( 'term' === $type ) {
            $url = $this->canonicalTermUrl( $objectId, $canonical );
            return '' !== $url ? trailingslashit( $url ) . 'llms.txt' : $root;
        }
        if ( 'author' === $type ) {
            $url = $this->canonicalAuthorUrl( $objectId, $canonical );
            return '' !== $url ? trailingslashit( $url ) . 'llms.txt' : $root;
        }
        if ( 'post' === $type ) {
            $post = get_post( $objectId );
            if ( ! $post instanceof \WP_Post ) { return $root; }
            $frontId = (int) get_option( 'page_on_front', 0 );
            if ( $frontId > 0 && $objectId === $frontId ) { return $root; }

            if ( is_post_type_hierarchical( $post->post_type ) && (int) $post->post_parent > 0 ) {
                $parentUrl = get_permalink( (int) $post->post_parent );
                if ( is_string( $parentUrl ) && '' !== $parentUrl ) { return trailingslashit( $parentUrl ) . 'llms.txt'; }
            }

            $archive = get_post_type_archive_link( $post->post_type );
            $canonicalUrl = $this->canonicalPostUrl( $objectId, $canonical );
            if ( is_string( $archive ) && '' !== $archive && '' !== $canonicalUrl && $this->urlIsWithinScope( $canonicalUrl, $archive ) ) {
                return trailingslashit( $archive ) . 'llms.txt';
            }
        }
        return $root;
    }

    public function preferredImageUrl( string $type, int $objectId ): string {
        if ( 'post' === $type ) {
            $url = trim( (string) get_post_meta( $objectId, '_yps_og_image', true ) );
            if ( '' === $url && has_post_thumbnail( $objectId ) ) {
                $featured = get_the_post_thumbnail_url( $objectId, 'full' );
                $url = is_string( $featured ) ? $featured : '';
            }
            return esc_url_raw( $url, array( 'http', 'https' ) );
        }
        if ( 'term' === $type ) {
            $term = get_term( $objectId );
            if ( $term instanceof \WP_Term && 'product_cat' === $term->taxonomy ) {
                $attachmentId = (int) get_term_meta( $objectId, 'thumbnail_id', true );
                if ( $attachmentId > 0 ) {
                    $url = wp_get_attachment_image_url( $attachmentId, 'full' );
                    return is_string( $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
                }
            }
        }
        return '';
    }

    /** @return array{type:string,id:int,url:string}|null */
    public function resolveCanonicalUrl( string $url ): ?array {
        $url = esc_url_raw( $url, array( 'http', 'https' ) );
        if ( '' === $url || ! $this->sameHost( $url, home_url( '/' ) ) ) { return null; }

        $postId = (int) url_to_postid( $url );
        if ( $postId > 0 && '' !== $this->postMarkdownUrl( $postId, $url ) ) {
            return array( 'type' => 'post', 'id' => $postId, 'url' => $this->canonicalPostUrl( $postId, $url ) );
        }

        $term = $this->resolveTermByUrl( $url );
        if ( $term instanceof \WP_Term ) {
            return array( 'type' => 'term', 'id' => (int) $term->term_id, 'url' => (string) get_term_link( $term ) );
        }

        $author = $this->resolveAuthorByUrl( $url );
        if ( $author instanceof \WP_User ) {
            return array( 'type' => 'author', 'id' => (int) $author->ID, 'url' => get_author_posts_url( (int) $author->ID ) );
        }

        $postType = $this->resolvePostTypeArchiveByUrl( $url );
        if ( '' !== $postType ) {
            return array( 'type' => 'post_type_archive', 'id' => 0, 'url' => (string) get_post_type_archive_link( $postType ) );
        }
        if ( $this->sameCanonicalUrl( home_url( '/' ), $url ) ) {
            return array( 'type' => 'site', 'id' => 0, 'url' => home_url( '/' ) );
        }
        return null;
    }

    public function resolvePostTypeArchiveByUrl( string $url ): string {
        foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ), 'names' ) as $postType ) {
            $archive = get_post_type_archive_link( $postType );
            if ( is_string( $archive ) && $this->sameCanonicalUrl( $archive, $url ) ) { return (string) $postType; }
        }
        return '';
    }

    private function postMarkdownUrl( int $postId, string $canonical = '' ): string {
        $post = get_post( $postId );
        $object = $post instanceof \WP_Post ? get_post_type_object( $post->post_type ) : null;
        if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || 'attachment' === $post->post_type || ! $object || ! is_post_type_viewable( $object ) || '' !== (string) $post->post_password ) { return ''; }
        if ( '1' === (string) get_post_meta( $postId, '_yps_robots_noindex', true ) ) { return ''; }
        $url = $this->canonicalPostUrl( $postId, $canonical );
        if ( '' === $url || false !== strpos( $url, '?' ) ) { return ''; }
        $frontId = (int) get_option( 'page_on_front', 0 );
        if ( $frontId > 0 && $postId === $frontId ) { return home_url( '/index.md' ); }
        return untrailingslashit( $url ) . '.md';
    }

    private function termMarkdownUrl( int $termId, string $canonical = '' ): string {
        $term = get_term( $termId );
        if ( ! $term instanceof \WP_Term ) { return ''; }
        $taxonomy = get_taxonomy( $term->taxonomy );
        if ( ! $taxonomy || empty( $taxonomy->public ) || '1' === (string) get_term_meta( $termId, '_yps_robots_noindex', true ) ) { return ''; }
        $url = $this->canonicalTermUrl( $termId, $canonical );
        return '' !== $url && false === strpos( $url, '?' ) ? untrailingslashit( $url ) . '.md' : '';
    }

    private function authorMarkdownUrl( int $userId, string $canonical = '' ): string {
        $user = get_userdata( $userId );
        if ( ! $user instanceof \WP_User || '1' === (string) get_user_meta( $userId, '_yps_author_robots_noindex', true ) ) { return ''; }
        $publicTypes = array_values( get_post_types( array( 'public' => true ), 'names' ) );
        if ( count_user_posts( $userId, $publicTypes, true ) <= 0 ) { return ''; }
        $url = $this->canonicalAuthorUrl( $userId, $canonical );
        return '' !== $url && false === strpos( $url, '?' ) ? untrailingslashit( $url ) . '.md' : '';
    }

    private function postTypeArchiveMarkdownUrl( string $canonical = '' ): string {
        $postType = $this->resolvePostTypeArchiveByUrl( $canonical );
        if ( '' === $postType ) { return ''; }
        $object = get_post_type_object( $postType );
        if ( ! $object || empty( $object->public ) || empty( $object->has_archive ) ) { return ''; }
        $url = get_post_type_archive_link( $postType );
        if ( ! is_string( $url ) || '' === $url || false !== strpos( $url, '?' ) ) { return ''; }
        return untrailingslashit( $url ) . '.md';
    }

    private function canonicalPostUrl( int $postId, string $candidate = '' ): string {
        if ( '' !== $candidate ) { return esc_url_raw( $candidate, array( 'http', 'https' ) ); }
        $url = get_permalink( $postId );
        return is_string( $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
    }

    private function canonicalTermUrl( int $termId, string $candidate = '' ): string {
        if ( '' !== $candidate ) { return esc_url_raw( $candidate, array( 'http', 'https' ) ); }
        $term = get_term( $termId );
        if ( ! $term instanceof \WP_Term ) { return ''; }
        $url = get_term_link( $term );
        return is_wp_error( $url ) ? '' : esc_url_raw( (string) $url, array( 'http', 'https' ) );
    }

    private function canonicalAuthorUrl( int $userId, string $candidate = '' ): string {
        if ( '' !== $candidate ) { return esc_url_raw( $candidate, array( 'http', 'https' ) ); }
        return esc_url_raw( get_author_posts_url( $userId ), array( 'http', 'https' ) );
    }

    private function resolveTermByUrl( string $url ): ?\WP_Term {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $slug = sanitize_title( basename( untrailingslashit( $path ) ) );
        if ( '' === $slug ) { return null; }
        foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( ! $term instanceof \WP_Term ) { continue; }
            $termUrl = get_term_link( $term );
            if ( ! is_wp_error( $termUrl ) && $this->sameCanonicalUrl( (string) $termUrl, $url ) ) { return $term; }
        }
        return null;
    }

    private function resolveAuthorByUrl( string $url ): ?\WP_User {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $slug = sanitize_title( basename( untrailingslashit( $path ) ) );
        if ( '' === $slug ) { return null; }
        $user = get_user_by( 'slug', $slug );
        if ( $user instanceof \WP_User && $this->sameCanonicalUrl( get_author_posts_url( (int) $user->ID ), $url ) ) { return $user; }
        return null;
    }

    private function urlIsWithinScope( string $url, string $scope ): bool {
        $urlPath = trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        $scopePath = trailingslashit( (string) wp_parse_url( $scope, PHP_URL_PATH ) );
        return '' !== $scopePath && 0 === strpos( $urlPath, $scopePath );
    }

    private function sameCanonicalUrl( string $a, string $b ): bool {
        return untrailingslashit( strtolower( $a ) ) === untrailingslashit( strtolower( $b ) );
    }

    private function sameHost( string $a, string $b ): bool {
        return strtolower( (string) wp_parse_url( $a, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( $b, PHP_URL_HOST ) );
    }
}
