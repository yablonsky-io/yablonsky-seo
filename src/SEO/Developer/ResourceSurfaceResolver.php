<?php
namespace Yablonsky\PerformanceSEO\SEO\Developer;

use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;
use Yablonsky\PerformanceSEO\Resource\ResourceIdentity;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Deterministic resolver for developer/headless API resources without frontend self-HTTP requests. */
final class ResourceSurfaceResolver {
    /** @var ResourceLanguageResolver */ private $language;

    public function __construct() { $this->language = new ResourceLanguageResolver(); }

    public function forHome(): ResourceIdentity {
        $id = (int) get_option( 'page_on_front', 0 );
        $authority = $this->language->resolve( 'home', $id, home_url( '/' ), false );
        return new ResourceIdentity( 'home', $id, 'front_page', (string) $authority['language_key'], home_url( '/' ) );
    }

    public function forPostsPage(): ResourceIdentity {
        $id = (int) get_option( 'page_for_posts', 0 );
        $url = $id > 0 ? (string) get_permalink( $id ) : home_url( '/' );
        $authority = $this->language->resolve( 'posts_page', $id, $url, false );
        return new ResourceIdentity( 'posts_page', $id, 'posts_page', (string) $authority['language_key'], $url );
    }

    public function forPost( int $postId ): ?ResourceIdentity {
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || 'attachment' === $post->post_type || '' !== (string) $post->post_password ) { return null; }
        $object = get_post_type_object( (string) $post->post_type );
        if ( ! $object || empty( $object->public ) ) { return null; }
        $url = (string) get_permalink( $postId );
        if ( '' === $url ) { return null; }
        $type = 'product' === $post->post_type ? 'product' : 'post';
        $authority = $this->language->resolve( $type, $postId, $url, false );
        return new ResourceIdentity( $type, $postId, (string) $post->post_type, (string) $authority['language_key'], $url );
    }

    public function forTerm( string $taxonomy, int $termId ): ?ResourceIdentity {
        $taxonomy = sanitize_key( $taxonomy );
        $object = get_taxonomy( $taxonomy );
        $term = get_term( $termId, $taxonomy );
        if ( ! $object || empty( $object->public ) || ! $term instanceof \WP_Term ) { return null; }
        $url = get_term_link( $term );
        if ( is_wp_error( $url ) || '' === (string) $url ) { return null; }
        $authority = $this->language->resolve( 'term', $termId, (string) $url, false );
        return new ResourceIdentity( 'term', $termId, $taxonomy, (string) $authority['language_key'], (string) $url );
    }

    public function forAuthor( int $userId ): ?ResourceIdentity {
        $user = get_userdata( $userId );
        if ( ! $user instanceof \WP_User ) { return null; }
        $url = (string) get_author_posts_url( $userId );
        $authority = $this->language->resolve( 'author_archive', $userId, $url, false );
        return new ResourceIdentity( 'author_archive', $userId, 'author', (string) $authority['language_key'], $url );
    }

    public function forPostTypeArchive( string $postType ): ?ResourceIdentity {
        $postType = sanitize_key( $postType );
        $object = get_post_type_object( $postType );
        if ( ! $object || empty( $object->public ) || empty( $object->has_archive ) ) { return null; }
        $url = get_post_type_archive_link( $postType );
        if ( ! is_string( $url ) || '' === $url ) { return null; }
        $authority = $this->language->resolve( 'post_type_archive', 0, $url, false );
        return new ResourceIdentity( 'post_type_archive', 0, $postType, (string) $authority['language_key'], $url );
    }

    public function forUrl( string $url ): ?ResourceIdentity {
        $url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
        if ( '' === $url || ! $this->sameOrigin( $url ) ) { return null; }
        if ( $this->sameUrl( $url, home_url( '/' ) ) ) { return $this->forHome(); }

        $postsPageId = (int) get_option( 'page_for_posts', 0 );
        if ( $postsPageId > 0 && $this->sameUrl( $url, (string) get_permalink( $postsPageId ) ) ) { return $this->forPostsPage(); }

        $post = $this->forPost( (int) url_to_postid( $url ) );
        if ( null !== $post ) { return $post; }

        foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ), 'names' ) as $postType ) {
            $archive = $this->forPostTypeArchive( (string) $postType );
            if ( null !== $archive && $this->sameUrl( $url, $archive->url() ) ) { return $archive; }
        }
        return null;
    }

    private function sameUrl( string $left, string $right ): bool {
        return untrailingslashit( esc_url_raw( $left ) ) === untrailingslashit( esc_url_raw( $right ) );
    }

    private function sameOrigin( string $url ): bool {
        $home = wp_parse_url( home_url( '/' ) );
        $target = wp_parse_url( $url );
        if ( ! is_array( $home ) || ! is_array( $target ) ) { return false; }
        $homeScheme = strtolower( (string) ( $home['scheme'] ?? '' ) );
        $targetScheme = strtolower( (string) ( $target['scheme'] ?? '' ) );
        if ( isset( $target['user'] ) || isset( $target['pass'] ) ) { return false; }
        $homePort = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $homeScheme ? 443 : 80 );
        $targetPort = isset( $target['port'] ) ? (int) $target['port'] : ( 'https' === $targetScheme ? 443 : 80 );
        return $homeScheme === $targetScheme
            && strtolower( (string) ( $home['host'] ?? '' ) ) === strtolower( (string) ( $target['host'] ?? '' ) )
            && $homePort === $targetPort;
    }
}
