<?php
namespace Yablonsky\PerformanceSEO\Visibility;

use Yablonsky\PerformanceSEO\SEO\Freshness\FreshnessResolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Optional agent-readable web surface.
 *
 * HTML remains canonical. Markdown alternates are noindex representations for
 * retrieval/agent use and public routes are registered only while YSEO owns
 * public authority.
 */
final class AgentContentController {
    /** @var MarkdownRenderer */ private $renderer;
    /** @var AgentResourceLocator */ private $locator;
    /** @var AgentDocumentStore */ private $store;
    /** @var FreshnessResolver */ private $freshness;

    public function __construct( ?MarkdownRenderer $renderer = null, ?AgentResourceLocator $locator = null, ?AgentDocumentStore $store = null ) {
        $this->renderer = $renderer instanceof MarkdownRenderer ? $renderer : new MarkdownRenderer();
        $this->locator = $locator instanceof AgentResourceLocator ? $locator : new AgentResourceLocator();
        $this->store = $store instanceof AgentDocumentStore ? $store : new AgentDocumentStore();
        $this->freshness = new FreshnessResolver();
    }

    public function register(): void {
        if ( ! $this->locator->enabled() ) { return; }
        add_action( 'parse_request', array( $this, 'maybeServeAgentResource' ), 1 );
        add_action( 'wp_head', array( $this, 'renderDiscoveryLinks' ), 3 );
        add_filter( 'wp_headers', array( $this, 'addDiscoveryHeaders' ), 20 );
        add_action( 'save_post', array( $this, 'clearPostCaches' ), 40, 1 );
        // Purge while WordPress still exposes post type, parent, author and terms.
        // deleted_post fires after row removal and cannot invalidate those scopes.
        add_action( 'before_delete_post', array( $this, 'clearPostCaches' ), 40, 1 );
        add_action( 'deleted_post', array( $this, 'clearPostCaches' ), 40, 1 );
        add_action( 'added_post_meta', array( $this, 'clearRelevantPostMetaCache' ), 40, 4 );
        add_action( 'updated_post_meta', array( $this, 'clearRelevantPostMetaCache' ), 40, 4 );
        add_action( 'deleted_post_meta', array( $this, 'clearRelevantPostMetaCache' ), 40, 4 );
        add_action( 'created_term', array( $this, 'clearTermCaches' ), 40, 3 );
        add_action( 'edited_term', array( $this, 'clearTermCaches' ), 40, 3 );
        add_action( 'delete_term', array( $this, 'clearDeletedTermCaches' ), 40, 4 );
        add_action( 'profile_update', array( $this, 'clearAuthorCaches' ), 40, 1 );
        add_action( 'updated_option', array( $this, 'maybeClearCachesForOption' ), 40, 1 );
    }

    public function maybeServeAgentResource(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || 'GET' !== $this->requestMethod() ) { return; }
        $path = $this->relativeRequestPath();
        if ( 'llms.txt' === $path ) { $this->serveRootLlms(); }

        if ( $this->locator->scopedLlmsEnabled() && strlen( $path ) > 9 && 'llms.txt' === substr( $path, -8 ) ) {
            $scopePath = trim( substr( $path, 0, -8 ), '/' );
            if ( '' !== $scopePath ) {
                $scopeUrl = home_url( '/' . $scopePath . '/' );
                $resource = $this->locator->resolveCanonicalUrl( $scopeUrl );
                if ( is_array( $resource ) ) { $this->serveScopedLlms( $resource ); }
                status_header( 404 );
                exit;
            }
        }

        if ( 'index.md' === $path ) {
            $frontId = (int) get_option( 'page_on_front', 0 );
            if ( $frontId > 0 ) {
                $this->serveResourceMarkdown( array( 'type' => 'post', 'id' => $frontId, 'url' => (string) get_permalink( $frontId ) ) );
            }
            $this->serveResourceMarkdown( array( 'type' => 'site', 'id' => 0, 'url' => home_url( '/' ) ) );
        }

        if ( strlen( $path ) <= 3 || '.md' !== substr( $path, -3 ) ) { return; }
        $htmlPath = trim( substr( $path, 0, -3 ), '/' );
        if ( '' === $htmlPath ) { return; }
        $candidate = home_url( '/' . $htmlPath . '/' );
        $resource = $this->locator->resolveCanonicalUrl( $candidate );
        if ( is_array( $resource ) && in_array( $resource['type'], array( 'post', 'term', 'author', 'post_type_archive' ), true ) ) {
            $this->serveResourceMarkdown( $resource );
        }
        status_header( 404 );
        exit;
    }

    public function renderDiscoveryLinks(): void {
        $resource = $this->currentResource();
        if ( ! is_array( $resource ) ) { return; }
        $markdown = $this->locator->markdownUrl( (string) $resource['type'], (int) $resource['id'], (string) $resource['url'] );
        if ( '' === $markdown ) { return; }
        $llms = $this->locator->describedByUrl( (string) $resource['type'], (int) $resource['id'], (string) $resource['url'] );
        printf( '<link rel="alternate" type="text/markdown" href="%s" />' . "\n", esc_url( $markdown ) );
        if ( '' !== $llms ) { printf( '<link rel="describedby" href="%s" type="text/plain" />' . "\n", esc_url( $llms ) ); }
    }

    /** @param array<string,string> $headers @return array<string,string> */
    public function addDiscoveryHeaders( array $headers ): array {
        $resource = $this->currentResource();
        if ( ! is_array( $resource ) ) { return $headers; }
        $markdown = $this->locator->markdownUrl( (string) $resource['type'], (int) $resource['id'], (string) $resource['url'] );
        if ( '' === $markdown ) { return $headers; }
        $links = array( '<' . esc_url_raw( $markdown ) . '>; rel="alternate"; type="text/markdown"' );
        $llms = $this->locator->describedByUrl( (string) $resource['type'], (int) $resource['id'], (string) $resource['url'] );
        if ( '' !== $llms ) { $links[] = '<' . esc_url_raw( $llms ) . '>; rel="describedby"; type="text/plain"'; }
        $discovery = implode( ', ', $links );
        $headers['Link'] = isset( $headers['Link'] ) && '' !== trim( (string) $headers['Link'] ) ? (string) $headers['Link'] . ', ' . $discovery : $discovery;
        return $headers;
    }

    public function clearPostCaches( int $postId ): void {
        if ( $postId <= 0 || wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) { return; }
        $this->store->purgeKey( 'md:post:' . $postId );
        $this->store->purgeKey( 'llms:root' );
        $post = get_post( $postId );
        if ( $post instanceof \WP_Post ) {
            $this->store->purgeKey( 'llms:archive:' . sanitize_key( $post->post_type ) );
            $this->store->purgeKey( 'md:archive:' . sanitize_key( $post->post_type ) );
            $this->store->purgeKey( 'md:site' );
            if ( (int) $post->post_parent > 0 ) { $this->store->purgeKey( 'llms:post:' . (int) $post->post_parent ); }
            $this->store->purgeKey( 'llms:author:' . (int) $post->post_author );
            foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
                $terms = get_the_terms( $postId, $taxonomy );
                if ( ! is_array( $terms ) ) { continue; }
                foreach ( $terms as $term ) {
                    if ( $term instanceof \WP_Term ) { $this->store->purgeKey( 'llms:term:' . (int) $term->term_id ); }
                }
            }
        }
    }

    public function clearRelevantPostMetaCache( $metaId, int $postId, string $metaKey, $metaValue = null ): void {
        unset( $metaId, $metaValue );
        if ( ! in_array( $metaKey, array( '_yps_meta_description', '_yps_og_image', '_thumbnail_id', '_sku', '_price', '_stock_status' ), true ) ) { return; }
        $this->store->purgeKey( 'md:post:' . $postId );
    }

    public function clearTermCaches( int $termId, int $ttId = 0, string $taxonomy = '' ): void {
        unset( $ttId, $taxonomy );
        if ( $termId <= 0 ) { return; }
        $this->store->purgeKey( 'md:term:' . $termId );
        $this->store->purgeKey( 'llms:term:' . $termId );
        $this->store->purgeKey( 'llms:root' );
    }

    public function clearDeletedTermCaches( int $termId, int $ttId = 0, string $taxonomy = '', $deletedTerm = null ): void {
        unset( $ttId, $taxonomy, $deletedTerm );
        $this->clearTermCaches( $termId );
    }

    public function clearAuthorCaches( int $userId ): void {
        if ( $userId <= 0 ) { return; }
        $this->store->purgeKey( 'md:author:' . $userId );
        $this->store->purgeKey( 'llms:author:' . $userId );
        $this->store->purgeKey( 'llms:root' );
    }

    public function maybeClearCachesForOption( string $option ): void {
        if ( in_array( $option, array( 'yps_public_settings', 'page_on_front', 'show_on_front', 'blogname', 'blogdescription', 'permalink_structure', 'home', 'siteurl' ), true ) ) {
            $this->store->purgeAll();
        }
    }

    /** @param array{type:string,id:int,url:string} $resource */
    private function serveResourceMarkdown( array $resource ): void {
        $type = (string) $resource['type'];
        $id = (int) $resource['id'];
        $canonical = (string) $resource['url'];
        $markdown = $this->locator->markdownUrl( $type, $id, $canonical );
        if ( '' === $markdown ) { status_header( 404 ); exit; }

        $fingerprint = $this->resourceFingerprint( $type, $id, $canonical );
        $cacheKey = 'md:' . $type . ':' . $id;
        if ( 'post_type_archive' === $type ) {
            $cacheKey = 'md:archive:' . sanitize_key( $this->locator->resolvePostTypeArchiveByUrl( $canonical ) );
        } elseif ( 'site' === $type ) {
            $cacheKey = 'md:site';
        }
        $result = $this->store->remember(
            $cacheKey,
            $fingerprint,
            function () use ( $type, $id, $canonical ): string {
                if ( 'post' === $type ) {
                    $post = get_post( $id );
                    return $post instanceof \WP_Post ? $this->renderer->renderPost( $post ) : '';
                }
                if ( 'term' === $type ) {
                    $term = get_term( $id );
                    return $term instanceof \WP_Term ? $this->renderer->renderTerm( $term ) : '';
                }
                if ( 'author' === $type ) {
                    $user = get_userdata( $id );
                    return $user instanceof \WP_User ? $this->renderer->renderAuthor( $user ) : '';
                }
                if ( 'post_type_archive' === $type ) {
                    $postType = $this->locator->resolvePostTypeArchiveByUrl( $canonical );
                    return '' !== $postType ? $this->renderer->renderPostTypeArchive( $postType ) : '';
                }
                if ( 'site' === $type ) { return $this->renderer->renderSiteIndex(); }
                return '';
            },
            'post' === $type ? 0 : 10 * MINUTE_IN_SECONDS
        );
        if ( '' === $result['body'] ) { status_header( 404 ); exit; }
        $llms = $this->locator->describedByUrl( $type, $id, $canonical );
        $link = '<' . esc_url_raw( $canonical ) . '>; rel="canonical"';
        if ( '' !== $llms ) { $link .= ', <' . esc_url_raw( $llms ) . '>; rel="describedby"; type="text/plain"'; }
        $this->sendText( $result, 'text/markdown', $link );
    }

    private function serveRootLlms(): void {
        $settings = get_option( 'yps_public_settings', array() );
        $fingerprint = YPS_VERSION . '|' . hash( 'sha256', wp_json_encode( array( 'settings' => is_array( $settings ) ? $settings : array(), 'front' => get_option( 'page_on_front', 0 ) ) ) ?: '' );
        $result = $this->store->remember( 'llms:root', $fingerprint, function (): string { return $this->renderRootLlms(); }, 10 * MINUTE_IN_SECONDS );
        $this->sendText( $result, 'text/plain', '<' . esc_url_raw( home_url( '/' ) ) . '>; rel="canonical"' );
    }

    /** @param array{type:string,id:int,url:string} $resource */
    private function serveScopedLlms( array $resource ): void {
        $type = (string) $resource['type'];
        $id = (int) $resource['id'];
        $key = 'post_type_archive' === $type ? 'llms:archive:' . sanitize_key( $this->locator->resolvePostTypeArchiveByUrl( (string) $resource['url'] ) ) : 'llms:' . $type . ':' . $id;
        $fingerprint = $this->resourceFingerprint( $type, $id, (string) $resource['url'] );
        $result = $this->store->remember( $key, $fingerprint, function () use ( $resource ): string { return $this->renderScopedLlms( $resource ); }, 10 * MINUTE_IN_SECONDS );
        if ( '' === $result['body'] ) { status_header( 404 ); exit; }
        $this->sendText( $result, 'text/plain' );
    }

    private function renderRootLlms(): string {
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $siteName = trim( (string) ( $settings['site_display_name'] ?? '' ) );
        if ( '' === $siteName ) { $siteName = (string) get_bloginfo( 'name' ); }
        $description = trim( (string) ( $settings['site_description'] ?? '' ) );
        if ( '' === $description ) { $description = (string) get_bloginfo( 'description' ); }
        $lines = array( '# ' . $siteName );
        if ( '' !== $description ) { $lines[] = ''; $lines[] = '> ' . str_replace( "\n", ' ', $description ); }
        $lines[] = '';
        $lines[] = 'Canonical site: ' . home_url( '/' );
        $lines[] = 'Sitemap: ' . home_url( '/sitemap_index.xml' );
        $frontId = (int) get_option( 'page_on_front', 0 );
        $frontMarkdown = $frontId > 0
            ? $this->locator->markdownUrl( 'post', $frontId, (string) get_permalink( $frontId ) )
            : $this->locator->homeMarkdownUrl();
        if ( '' !== $frontMarkdown ) { $lines[] = 'Homepage Markdown: ' . $frontMarkdown; }

        if ( $this->locator->scopedLlmsEnabled() ) {
            $scopeLines = $this->rootScopeLinks();
            if ( ! empty( $scopeLines ) ) {
                $lines[] = '';
                $lines[] = '## Scoped indexes';
                foreach ( $scopeLines as $line ) { $lines[] = $line; }
            }
        }

        $lines[] = '';
        $lines[] = '## Recent and important content';
        $postTypes = array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) );
        $query = new \WP_Query(
            array(
                'post_type' => $postTypes,
                'post_status' => 'publish',
                'has_password' => false,
                'posts_per_page' => 40,
                'orderby' => 'modified',
                'order' => 'DESC',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'suppress_filters' => false,
                'fields' => 'ids',
            )
        );
        foreach ( $query->posts as $postId ) {
            $postId = (int) $postId;
            $url = $this->locator->markdownUrl( 'post', $postId, (string) get_permalink( $postId ) );
            if ( '' === $url ) { continue; }
            $title = $this->safeLabel( (string) get_the_title( $postId ) );
            if ( '' === $title ) { continue; }
            $excerpt = trim( wp_strip_all_tags( (string) get_the_excerpt( $postId ), true ) );
            if ( function_exists( 'mb_substr' ) ) { $excerpt = mb_substr( $excerpt, 0, 180, 'UTF-8' ); } else { $excerpt = substr( $excerpt, 0, 180 ); }
            $lines[] = '- [' . $title . '](' . $url . ')' . ( '' !== $excerpt ? ': ' . $excerpt : '' );
        }
        wp_reset_postdata();
        $lines[] = '';
        $lines[] = 'Markdown alternates are machine-readable representations of canonical HTML pages. Follow canonical URLs for the authoritative public resource.';
        return implode( "\n", $lines ) . "\n";
    }

    /** @param array{type:string,id:int,url:string} $resource */
    private function renderScopedLlms( array $resource ): string {
        $type = (string) $resource['type'];
        $id = (int) $resource['id'];
        $scopeUrl = trailingslashit( (string) $resource['url'] );
        $title = '';
        $summary = '';
        $lines = array();

        if ( 'post_type_archive' === $type ) {
            $postType = $this->locator->resolvePostTypeArchiveByUrl( $scopeUrl );
            $object = '' !== $postType ? get_post_type_object( $postType ) : null;
            if ( ! $object ) { return ''; }
            $title = (string) $object->labels->name;
            $summary = 'Scoped orientation for the ' . $title . ' section.';
            $lines = $this->postTypeScopeLinks( $postType, $scopeUrl );
        } elseif ( 'post' === $type ) {
            $post = get_post( $id );
            if ( ! $post instanceof \WP_Post ) { return ''; }
            $title = (string) get_the_title( $post );
            $summary = trim( wp_strip_all_tags( (string) get_the_excerpt( $post ), true ) );
            $lines = $this->postScopeLinks( $post, $scopeUrl );
        } elseif ( 'term' === $type ) {
            $term = get_term( $id );
            if ( ! $term instanceof \WP_Term ) { return ''; }
            $title = (string) $term->name;
            $summary = trim( wp_strip_all_tags( (string) $term->description, true ) );
            $lines = $this->termScopeLinks( $term );
        } elseif ( 'author' === $type ) {
            $user = get_userdata( $id );
            if ( ! $user instanceof \WP_User ) { return ''; }
            $title = (string) $user->display_name;
            $summary = trim( wp_strip_all_tags( (string) get_user_meta( $id, 'description', true ), true ) );
            $lines = $this->authorScopeLinks( $user );
        }
        if ( '' === trim( $title ) ) { return ''; }

        $document = array( '# ' . $this->safeLabel( $title ) );
        if ( '' !== $summary ) { $document[] = ''; $document[] = '> ' . str_replace( "\n", ' ', $summary ); }
        $document[] = '';
        $document[] = 'Scope: ' . $scopeUrl;
        $document[] = 'Root llms.txt: ' . home_url( '/llms.txt' );
        if ( ! empty( $lines ) ) {
            $document[] = '';
            $document[] = '## Resources';
            foreach ( $lines as $line ) { $document[] = $line; }
        }
        return implode( "\n", $document ) . "\n";
    }

    /** @return array<int,string> */
    private function rootScopeLinks(): array {
        $lines = array();
        foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ), 'objects' ) as $postType => $object ) {
            $archive = get_post_type_archive_link( $postType );
            if ( ! is_string( $archive ) || '' === $archive ) { continue; }
            $lines[] = '- [' . $this->safeLabel( (string) $object->labels->name ) . '](' . trailingslashit( $archive ) . 'llms.txt): scoped content index';
        }
        $remaining = 24;
        foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy => $object ) {
            if ( $remaining <= 0 ) { break; }
            $terms = get_terms( array( 'taxonomy' => $taxonomy, 'parent' => 0, 'hide_empty' => true, 'number' => min( 8, $remaining ), 'orderby' => 'count', 'order' => 'DESC', 'suppress_filter' => false ) );
            if ( ! is_array( $terms ) ) { continue; }
            foreach ( $terms as $term ) {
                if ( ! $term instanceof \WP_Term || $remaining <= 0 ) { continue; }
                $url = get_term_link( $term );
                if ( is_wp_error( $url ) ) { continue; }
                $lines[] = '- [' . $this->safeLabel( (string) $term->name ) . '](' . trailingslashit( (string) $url ) . 'llms.txt): ' . $this->safeLabel( (string) $object->labels->singular_name ) . ' scope';
                $remaining--;
            }
        }
        return array_slice( $lines, 0, 36 );
    }

    /** @return array<int,string> */
    private function postTypeScopeLinks( string $postType, string $scopeUrl ): array {
        $lines = array();
        $query = new \WP_Query( array( 'post_type' => $postType, 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 60, 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'ignore_sticky_posts' => true, 'suppress_filters' => false, 'fields' => 'ids' ) );
        foreach ( $query->posts as $postId ) {
            $postId = (int) $postId;
            $canonical = get_permalink( $postId );
            if ( ! is_string( $canonical ) || ! $this->urlWithinScope( $canonical, $scopeUrl ) ) { continue; }
            $md = $this->locator->markdownUrl( 'post', $postId, $canonical );
            if ( '' !== $md ) { $lines[] = '- [' . $this->safeLabel( (string) get_the_title( $postId ) ) . '](' . $md . ')'; }
        }
        wp_reset_postdata();
        return $lines;
    }

    /** @return array<int,string> */
    private function postScopeLinks( \WP_Post $post, string $scopeUrl ): array {
        $lines = array();
        $self = $this->locator->markdownUrl( 'post', (int) $post->ID, (string) get_permalink( $post ) );
        if ( '' !== $self ) { $lines[] = '- [' . $this->safeLabel( (string) get_the_title( $post ) ) . '](' . $self . '): scope landing page'; }
        if ( is_post_type_hierarchical( $post->post_type ) ) {
            $children = get_pages( array( 'child_of' => (int) $post->ID, 'post_type' => $post->post_type, 'post_status' => 'publish', 'sort_column' => 'menu_order,post_title', 'number' => 60 ) );
            foreach ( $children as $child ) {
                if ( ! $child instanceof \WP_Post ) { continue; }
                $canonical = get_permalink( $child );
                if ( ! is_string( $canonical ) || ! $this->urlWithinScope( $canonical, $scopeUrl ) ) { continue; }
                $md = $this->locator->markdownUrl( 'post', (int) $child->ID, $canonical );
                if ( '' !== $md ) { $lines[] = '- [' . $this->safeLabel( (string) get_the_title( $child ) ) . '](' . $md . ')'; }
            }
        }
        return $lines;
    }

    /** @return array<int,string> */
    private function termScopeLinks( \WP_Term $term ): array {
        $lines = array();
        $self = $this->locator->markdownUrl( 'term', (int) $term->term_id, (string) get_term_link( $term ) );
        if ( '' !== $self ) { $lines[] = '- [' . $this->safeLabel( (string) $term->name ) . '](' . $self . '): category or taxonomy landing page'; }
        $children = get_terms( array( 'taxonomy' => $term->taxonomy, 'parent' => (int) $term->term_id, 'hide_empty' => true, 'number' => 30, 'orderby' => 'name', 'order' => 'ASC', 'suppress_filter' => false ) );
        if ( is_array( $children ) ) {
            foreach ( $children as $child ) {
                if ( ! $child instanceof \WP_Term ) { continue; }
                $url = get_term_link( $child );
                if ( is_wp_error( $url ) ) { continue; }
                $md = $this->locator->markdownUrl( 'term', (int) $child->term_id, (string) $url );
                if ( '' !== $md ) { $lines[] = '- [' . $this->safeLabel( (string) $child->name ) . '](' . $md . '): subsection'; }
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
                'fields' => 'ids',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Bounded to 40 IDs for one resolved public term; required for scoped llms navigation.
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
        foreach ( $query->posts as $postId ) {
            $postId = (int) $postId;
            $md = $this->locator->markdownUrl( 'post', $postId, (string) get_permalink( $postId ) );
            if ( '' !== $md ) { $lines[] = '- [' . $this->safeLabel( (string) get_the_title( $postId ) ) . '](' . $md . '): related content'; }
        }
        wp_reset_postdata();
        return array_slice( array_values( array_unique( $lines ) ), 0, 70 );
    }

    /** @return array<int,string> */
    private function authorScopeLinks( \WP_User $user ): array {
        $lines = array();
        $self = $this->locator->markdownUrl( 'author', (int) $user->ID, get_author_posts_url( (int) $user->ID ) );
        if ( '' !== $self ) { $lines[] = '- [' . $this->safeLabel( (string) $user->display_name ) . '](' . $self . '): author overview'; }
        $query = new \WP_Query( array( 'author' => (int) $user->ID, 'post_type' => array_values( get_post_types( array( 'public' => true ), 'names' ) ), 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 50, 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'ignore_sticky_posts' => true, 'suppress_filters' => false, 'fields' => 'ids' ) );
        foreach ( $query->posts as $postId ) {
            $postId = (int) $postId;
            $md = $this->locator->markdownUrl( 'post', $postId, (string) get_permalink( $postId ) );
            if ( '' !== $md ) { $lines[] = '- [' . $this->safeLabel( (string) get_the_title( $postId ) ) . '](' . $md . ')'; }
        }
        wp_reset_postdata();
        return $lines;
    }

    /** @return array{type:string,id:int,url:string}|null */
    private function currentResource(): ?array {
        if ( is_front_page() && (int) get_option( 'page_on_front', 0 ) <= 0 ) {
            return array( 'type' => 'site', 'id' => 0, 'url' => home_url( '/' ) );
        }
        if ( is_singular() ) {
            $id = (int) get_queried_object_id();
            $url = get_permalink( $id );
            return $id > 0 && is_string( $url ) ? array( 'type' => 'post', 'id' => $id, 'url' => $url ) : null;
        }
        if ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            if ( $term instanceof \WP_Term ) {
                $url = get_term_link( $term );
                return ! is_wp_error( $url ) ? array( 'type' => 'term', 'id' => (int) $term->term_id, 'url' => (string) $url ) : null;
            }
        }
        if ( is_author() ) {
            $id = (int) get_queried_object_id();
            return $id > 0 ? array( 'type' => 'author', 'id' => $id, 'url' => get_author_posts_url( $id ) ) : null;
        }
        if ( is_post_type_archive() ) {
            $postType = get_query_var( 'post_type' );
            if ( is_array( $postType ) ) { $postType = reset( $postType ) ?: ''; }
            $postType = sanitize_key( (string) $postType );
            $url = '' !== $postType ? get_post_type_archive_link( $postType ) : '';
            return is_string( $url ) && '' !== $url ? array( 'type' => 'post_type_archive', 'id' => 0, 'url' => $url ) : null;
        }
        return null;
    }

    private function resourceFingerprint( string $type, int $id, string $canonical = '' ): string {
        if ( 'post' === $type ) {
            $post = get_post( $id );
            if ( ! $post instanceof \WP_Post ) { return YPS_VERSION . '|post|' . $id; }
            $bits = array( YPS_VERSION, 'post', $id, $this->freshness->postLastmod( $id ), get_post_meta( $id, '_yps_meta_description', true ), get_post_meta( $id, '_yps_og_image', true ), get_post_thumbnail_id( $id ) );
            if ( 'product' === $post->post_type ) {
                $bits[] = get_post_meta( $id, '_sku', true );
                $bits[] = get_post_meta( $id, '_price', true );
                $bits[] = get_post_meta( $id, '_regular_price', true );
                $bits[] = get_post_meta( $id, '_sale_price', true );
                $bits[] = get_post_meta( $id, '_sale_price_dates_to', true );
                $bits[] = get_post_meta( $id, '_stock_status', true );
            }
            return hash( 'sha256', wp_json_encode( $bits ) ?: '' );
        }
        if ( 'term' === $type ) {
            $term = get_term( $id );
            $bits = $term instanceof \WP_Term ? array( YPS_VERSION, 'term', $id, $term->name, $term->description, $term->parent, $this->freshness->termLastmod( $id, (string) $term->taxonomy ) ) : array( YPS_VERSION, 'term', $id );
            return hash( 'sha256', wp_json_encode( $bits ) ?: '' );
        }
        if ( 'author' === $type ) {
            $user = get_userdata( $id );
            $bits = $user instanceof \WP_User ? array( YPS_VERSION, 'author', $id, $user->display_name, get_user_meta( $id, 'description', true ), $this->freshness->authorLastmod( $id ) ) : array( YPS_VERSION, 'author', $id );
            return hash( 'sha256', wp_json_encode( $bits ) ?: '' );
        }
        if ( 'post_type_archive' === $type ) {
            $postType = $this->locator->resolvePostTypeArchiveByUrl( $canonical );
            return hash( 'sha256', YPS_VERSION . '|archive|' . $postType . '|' . $this->freshness->postTypeArchiveLastmod( $postType ) );
        }
        if ( 'site' === $type ) {
            return hash( 'sha256', YPS_VERSION . '|site|' . (string) get_option( 'blogname', '' ) . '|' . (string) get_option( 'blogdescription', '' ) . '|' . $this->freshness->siteLastmod() );
        }
        return hash( 'sha256', YPS_VERSION . '|' . $type . '|' . $id );
    }

    /** @param array{body:string,etag:string,mtime:int,cache:string} $document */
    private function sendText( array $document, string $mime, string $linkHeader = '' ): void {
        $body = (string) $document['body'];
        $etag = '"' . (string) $document['etag'] . '"';
        $mtime = max( 1, (int) $document['mtime'] );
        $this->sendCommonHeaders( $mime, $etag, $mtime, $linkHeader, (string) $document['cache'] );
        if ( $this->notModified( $etag, $mtime ) ) { status_header( 304 ); exit; }
        status_header( 200 );
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Body is locally generated Markdown/plain text from sanitized public WordPress resources, not HTML.
        exit;
    }

    private function sendCommonHeaders( string $mime, string $etag, int $mtime, string $linkHeader, string $cacheState ): void {
        header( 'Content-Type: ' . $mime . '; charset=' . get_bloginfo( 'charset' ), true );
        header( 'X-Robots-Tag: noindex, follow', true );
        header( 'Cache-Control: public, max-age=600, s-maxage=3600, stale-while-revalidate=86400', true );
        header( 'ETag: ' . $etag, true );
        header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT', true );
        header( 'X-YSEO-Agent-Cache: ' . ( 'HIT' === $cacheState ? 'HIT' : 'MISS' ), true );
        if ( '' !== $linkHeader ) { header( 'Link: ' . $linkHeader, true ); }
    }

    private function notModified( string $etag, int $mtime ): bool {
        $ifNoneMatch = filter_input( INPUT_SERVER, 'HTTP_IF_NONE_MATCH', FILTER_UNSAFE_RAW );
        if ( is_string( $ifNoneMatch ) && '' !== trim( $ifNoneMatch ) && trim( $ifNoneMatch ) === $etag ) { return true; }
        $ifModifiedSince = filter_input( INPUT_SERVER, 'HTTP_IF_MODIFIED_SINCE', FILTER_UNSAFE_RAW );
        if ( is_string( $ifModifiedSince ) && '' !== trim( $ifModifiedSince ) ) {
            $since = strtotime( $ifModifiedSince );
            if ( false !== $since && $since >= $mtime ) { return true; }
        }
        return false;
    }

    private function requestMethod(): string {
        $method = filter_input( INPUT_SERVER, 'REQUEST_METHOD', FILTER_UNSAFE_RAW );
        return is_string( $method ) ? strtoupper( sanitize_text_field( $method ) ) : '';
    }

    private function relativeRequestPath(): string {
        $request = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW );
        if ( ! is_string( $request ) || '' === $request ) { return ''; }
        $path = wp_parse_url( $request, PHP_URL_PATH );
        $path = is_string( $path ) ? $path : '';
        $homePath = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $homePath = is_string( $homePath ) ? '/' . trim( $homePath, '/' ) : '';
        if ( '/' === $homePath ) { $homePath = ''; }
        if ( '' !== $homePath && 0 === strpos( $path, $homePath ) ) { $path = substr( $path, strlen( $homePath ) ); }
        return trim( rawurldecode( $path ), '/' );
    }

    private function urlWithinScope( string $url, string $scope ): bool {
        $urlPath = trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        $scopePath = trailingslashit( (string) wp_parse_url( $scope, PHP_URL_PATH ) );
        return '' !== $scopePath && 0 === strpos( $urlPath, $scopePath );
    }

    private function safeLabel( string $label ): string {
        return str_replace( array( '[', ']' ), '', trim( wp_strip_all_tags( $label, true ) ) );
    }
}
