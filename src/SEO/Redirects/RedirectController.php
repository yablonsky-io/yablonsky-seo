<?php
namespace Yablonsky\PerformanceSEO\SEO\Redirects;

use Yablonsky\PerformanceSEO\Language\ResourceLanguageIndex;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Fast runtime redirect router backed by an indexed plugin-owned table. */
final class RedirectController {
    /** @var RedirectRepository */ private $repository;
    /** @var array<string,string> */ private $termBefore = array();

    public function __construct( ?RedirectRepository $repository = null ) {
        $this->repository = $repository instanceof RedirectRepository ? $repository : new RedirectRepository();
    }

    public function register(): void {
        add_action( 'template_redirect', array( $this, 'route' ), -20 );
        add_action( 'post_updated', array( $this, 'autoPostMove' ), 90, 3 );
        add_action( 'edit_terms', array( $this, 'captureTermBefore' ), 5, 2 );
        add_action( 'edited_term', array( $this, 'autoTermMove' ), 90, 3 );
    }

    public function route(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) { return; }
        $origin = $this->currentOrigin();
        if ( '' === $origin || RedirectSafetyPolicy::isProtectedPath( $origin ) ) { return; }
        $requestLanguage = $this->requestLanguage( $origin );
        $rule = $this->repository->plain( $origin, $requestLanguage );
        $matches = array();
        if ( null === $rule ) {
            foreach ( $this->repository->regexRules( $requestLanguage ) as $candidate ) {
                $pattern = (string) ( $candidate['origin'] ?? '' );
                $compiled = $this->compileRegex( $pattern );
                if ( '' === $compiled ) { continue; }
                $matched = @preg_match( $compiled, $origin, $matches );
                if ( 1 === $matched ) { $rule = $candidate; break; }
            }
        }
        if ( ! is_array( $rule ) ) { return; }
        $status = (int) ( $rule['status_code'] ?? 301 );
        $this->repository->recordHit( (int) ( $rule['id'] ?? 0 ) );
        if ( in_array( $status, array( 410, 451 ), true ) ) {
            status_header( $status );
            nocache_headers();
            header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
            $message = 410 === $status ? esc_html__( 'This content is no longer available.', 'yablonsky-seo' ) : esc_html__( 'This content is unavailable for legal reasons.', 'yablonsky-seo' );
            echo '<!doctype html><html><head><meta name="robots" content="noindex,follow"></head><body><main><h1>' . esc_html( (string) $status ) . '</h1><p>' . esc_html( $message ) . '</p></main></body></html>';
            exit;
        }
        $target = (string) ( $rule['target'] ?? '' );
        if ( 'regex' === (string) ( $rule['match_type'] ?? '' ) ) {
            $compiled = $this->compileRegex( (string) $rule['origin'] );
            if ( '' !== $compiled ) {
                $replaced = @preg_replace( $compiled, $target, $origin, 1 );
                if ( is_string( $replaced ) ) { $target = $replaced; }
            }
        }
        $target = $this->normalizeTarget( $target );
        if ( '' === $target || $this->sameCurrentTarget( $origin, $target ) ) { return; }
        wp_redirect( $target, $status, 'Yablonsky SEO Redirect Manager' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External HTTP(S) redirects are an explicit user-created Redirect Manager capability.
        exit;
    }

    public function autoPostMove( int $postId, \WP_Post $postAfter, \WP_Post $postBefore ): void {
        if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) { return; }
        if ( 'publish' !== $postBefore->post_status || 'publish' !== $postAfter->post_status ) { return; }
        $type = get_post_type_object( (string) $postAfter->post_type );
        if ( ! $type || empty( $type->public ) ) { return; }
        $old = (string) get_permalink( $postBefore );
        $new = (string) get_permalink( $postAfter );
        if ( '' === $old || '' === $new || untrailingslashit( $old ) === untrailingslashit( $new ) ) { return; }
        $origin = $this->normalizeOrigin( $old );
        $target = $this->normalizeTarget( $new );
        if ( '' !== $origin && '' !== $target && ! RedirectSafetyPolicy::isProtectedPath( $origin ) ) {
            $language = ( new ResourceLanguageIndex() )->languageForPost( $postId, true );
            $this->repository->save( array( 'origin' => $origin, 'target' => $target, 'status_code' => 301, 'match_type' => 'plain', 'language_key' => (string) ( $language['language_key'] ?? 'any' ), 'enabled' => 1, 'source' => 'auto-post-move' ) );
        }
    }

    public function captureTermBefore( int $termId, string $taxonomy ): void {
        $term = get_term( $termId, $taxonomy );
        if ( ! $term instanceof \WP_Term ) { return; }
        $url = get_term_link( $term );
        if ( is_wp_error( $url ) ) { return; }
        $this->termBefore[ sanitize_key( $taxonomy ) . ':' . $termId ] = (string) $url;
    }

    public function autoTermMove( int $termId, int $ttId, string $taxonomy ): void {
        unset( $ttId );
        $key = sanitize_key( $taxonomy ) . ':' . $termId;
        $old = (string) ( $this->termBefore[ $key ] ?? '' );
        unset( $this->termBefore[ $key ] );
        if ( '' === $old ) { return; }
        $term = get_term( $termId, $taxonomy );
        if ( ! $term instanceof \WP_Term ) { return; }
        $new = get_term_link( $term );
        if ( is_wp_error( $new ) || untrailingslashit( $old ) === untrailingslashit( (string) $new ) ) { return; }
        $origin = $this->normalizeOrigin( $old );
        $target = $this->normalizeTarget( (string) $new );
        if ( '' !== $origin && '' !== $target && ! RedirectSafetyPolicy::isProtectedPath( $origin ) ) {
            $this->repository->save( array( 'origin' => $origin, 'target' => $target, 'status_code' => 301, 'match_type' => 'plain', 'enabled' => 1, 'source' => 'auto-term-move' ) );
        }
    }

    public function normalizeOrigin( string $origin ): string {
        $origin = trim( $origin );
        if ( '' === $origin ) { return ''; }
        if ( preg_match( '#^https?://#i', $origin ) ) {
            $home = wp_parse_url( home_url( '/' ) );
            $parts = wp_parse_url( $origin );
            if ( ! is_array( $home ) || ! is_array( $parts ) || strtolower( (string) ( $home['host'] ?? '' ) ) !== strtolower( (string) ( $parts['host'] ?? '' ) ) ) { return ''; }
            $origin = (string) ( $parts['path'] ?? '/' );
        }
        $origin = '/' . trim( $origin, '/' );
        return strlen( $origin ) <= 2048 ? $origin : '';
    }

    public function normalizeTarget( string $target ): string {
        $target = trim( $target );
        if ( '' === $target ) { return ''; }
        if ( 0 === strpos( $target, '/' ) ) { return esc_url_raw( home_url( $target ), array( 'http', 'https' ) ); }
        return esc_url_raw( $target, array( 'http', 'https' ) );
    }

    public function validRegex( string $pattern ): bool { return '' !== $this->compileRegex( $pattern ); }

    private function requestLanguage( string $origin ): string {
        // Runtime language must come from the multilingual request owner. Admin/site
        // locale is never used for public routing. Without authority, only `any`
        // redirect rules are eligible (repository fail-closed behavior).
        $language = apply_filters( 'yablonsky_seo_request_language_v1', '', $origin );
        $language = apply_filters( 'yablonsky_performance_seo_request_language_v1', $language, $origin );
        $language = is_scalar( $language ) ? trim( sanitize_text_field( (string) $language ) ) : '';
        return 'auto' === $language || 'any' === $language ? '' : $language;
    }

    private function currentOrigin(): string {
        global $wp;
        $request = isset( $wp->request ) ? (string) $wp->request : '';
        return '/' . trim( $request, '/' );
    }

    private function sameCurrentTarget( string $origin, string $target ): bool {
        $targetPath = (string) wp_parse_url( $target, PHP_URL_PATH );
        return untrailingslashit( $origin ) === untrailingslashit( '/' . ltrim( $targetPath, '/' ) );
    }

    private function compileRegex( string $pattern ): string {
        $pattern = trim( $pattern );
        if ( '' === $pattern || strlen( $pattern ) > 512 ) { return ''; }
        foreach ( array( '~', '#', '%' ) as $delimiter ) {
            if ( false !== strpos( $pattern, $delimiter ) ) { continue; }
            // Admin-defined regex redirects run on public requests. Inline PCRE limits keep a
            // syntactically valid but pathological expression from exhausting the request.
            $compiled = $delimiter . '(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)' . $pattern . $delimiter . 'u';
            if ( false !== @preg_match( $compiled, '' ) ) { return $compiled; }
        }
        return '';
    }
}
