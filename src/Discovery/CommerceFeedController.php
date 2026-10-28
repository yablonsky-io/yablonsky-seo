<?php
namespace Yablonsky\PerformanceSEO\Discovery;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Public delivery boundary for the single canonical commerce feed. */
final class CommerceFeedController {
    public const PATH = 'yablonsky-commerce.xml';
    public const LEGACY_PATH = 'yseo-google-merchant.xml';
    /** @var ProductFeedRepository */ private $repository;

    public function __construct( ProductFeedRepository $repository ) { $this->repository = $repository; }

    public function register(): void { add_action( 'parse_request', array( $this, 'route' ), -900, 0 ); }

    public function route(): void {
        $requestUri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
        $path = trim( (string) wp_parse_url( is_string( $requestUri ) ? $requestUri : '', PHP_URL_PATH ), '/' );
        if ( self::LEGACY_PATH === $path ) {
            wp_safe_redirect( home_url( '/' . self::PATH ), 301 );
            exit;
        }
        if ( self::PATH !== $path ) { return; }
        if ( ! ( new DiscoverySettings() )->commerceEnabled() ) { status_header( 404 ); exit; }
        $snapshot = new CommerceFeedSnapshot( $this->repository );
        if ( ! is_file( $snapshot->path() ) ) {
            status_header( 503 );
            header( 'Retry-After: 300', true );
            header( 'Content-Type: application/xml; charset=UTF-8', true );
            echo '<?xml version="1.0" encoding="UTF-8"?><feed-status>warming-up</feed-status>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed XML.
            exit;
        }
        $this->serveSnapshot( $snapshot );
        exit;
    }

    private function serveSnapshot( CommerceFeedSnapshot $snapshot ): void {
        $state = $snapshot->state();
        $etag = isset( $state['sha256'] ) && '' !== (string) $state['sha256'] ? '"' . (string) $state['sha256'] . '"' : '"yablonsky-commerce-' . $this->repository->revision() . '"';
        $ifNoneMatch = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Optional validator.
        if ( '' !== $ifNoneMatch && hash_equals( $etag, $ifNoneMatch ) ) { status_header( 304 ); header( 'ETag: ' . $etag, true ); return; }
        status_header( 200 );
        header( 'Content-Type: application/xml; charset=UTF-8', true );
        header( 'X-Content-Type-Options: nosniff', true );
        header( 'Cache-Control: public, max-age=300, stale-while-revalidate=300', true );
        header( 'ETag: ' . $etag, true );
        header( 'X-Yablonsky-Commerce-Feed: snapshot-v1', true );
        if ( isset( $state['generated_at'] ) ) { header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) $state['generated_at'] ) . ' GMT', true ); }
        $size = is_file( $snapshot->path() ) ? (int) filesize( $snapshot->path() ) : 0;
        if ( $size > 0 ) { header( 'Content-Length: ' . $size, true ); }
        readfile( $snapshot->path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming immutable generated snapshot.
    }
}
