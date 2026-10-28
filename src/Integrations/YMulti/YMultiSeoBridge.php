<?php
namespace Yablonsky\PerformanceSEO\Integrations\YMulti;

use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Native consumer bridge for the public YMulti multilingual SEO provider.
 *
 * Boundary:
 * - YMulti owns language / translation graph truth.
 * - YSEO owns sitemap membership plus page-head hreflang, canonical/noindex and SEO output.
 * - Sitemap XML intentionally does not duplicate hreflang; public HTML head is the hreflang authority.
 *
 * The bridge intentionally consumes only the versioned public provider contract.
 * It never reads YMulti options, taxonomies, tables or internal classes.
 */
final class YMultiSeoBridge {
    public const CONTRACT = 'yseo-multilingual-provider/v1';
    private const OWNER = 'yps';

    /** @var SitemapRepository */
    private $sitemapRepository;
    /** @var object|null */
    private $provider = null;
    /** @var array<string,mixed> */
    private $capabilities = array();
    /** @var bool */
    private $resolved = false;
    /** @var bool */
    private $canEmitPublicSeo = false;

    public function __construct( ?SitemapRepository $sitemapRepository = null, bool $canEmitPublicSeo = false ) {
        $this->sitemapRepository = $sitemapRepository instanceof SitemapRepository ? $sitemapRepository : new SitemapRepository();
        $this->canEmitPublicSeo = $canEmitPublicSeo;
    }

    public function register(): void {
        add_filter( 'yablonsky_seo_resource_language_v1', array( $this, 'resourceLanguage' ), 5, 4 );
        add_filter( 'yablonsky_seo_sitemap_language_map_v1', array( $this, 'sitemapLanguageMap' ), 5, 2 );
        add_filter( 'yablonsky_seo_sitemap_bulk_alternates_v1', array( $this, 'bulkAlternates' ), 5, 2 );
        add_filter( 'yablonsky_seo_language_alternates_v1', array( $this, 'singleAlternates' ), 5, 3 );
        add_filter( 'yablonsky_seo_request_language_v1', array( $this, 'requestLanguage' ), 5, 2 );
        add_action( 'yablonsky_multilingual_seo_graph_invalidated_v1', array( $this, 'graphInvalidated' ), 10, 1 );

        if ( $this->canEmitPublicSeo && $this->connected() ) {
            add_filter( 'yablonsky_multilingual_sitemap_output_owner', array( $this, 'claimOutputOwner' ), 20, 1 );
            add_filter( 'yablonsky_multilingual_hreflang_output_owner', array( $this, 'claimOutputOwner' ), 20, 1 );
        }
    }

    /** @param mixed $current @return mixed */
    public function claimOutputOwner( $current ) {
        return $this->canEmitPublicSeo && $this->connected() ? self::OWNER : $current;
    }

    public function connected(): bool {
        $this->resolveProvider();
        return is_object( $this->provider ) && self::CONTRACT === (string) ( $this->capabilities['contract'] ?? '' );
    }

    /** @return array<string,mixed> */
    public function diagnostics(): array {
        $this->resolveProvider();
        return array(
            'connected'               => $this->connected(),
            'contract'                => (string) ( $this->capabilities['contract'] ?? '' ),
            'resource_language'       => ! empty( $this->capabilities['resource_language'] ),
            'resource_languages_bulk' => ! empty( $this->capabilities['resource_languages_bulk'] ),
            'bulk_alternates'         => ! empty( $this->capabilities['bulk_alternates'] ),
            'taxonomy_alternates_bulk'=> ! empty( $this->capabilities['taxonomy_alternates_bulk'] ),
            'request_context'         => ! empty( $this->capabilities['request_context'] ),
            'domain_per_language'     => ! empty( $this->capabilities['domain_per_language'] ),
            'sitemap_owner'           => ! empty( $this->capabilities['sitemap_owner'] ),
        );
    }

    /** @param mixed $current @return mixed */
    public function resourceLanguage( $current, string $resourceType, int $objectId, string $url ) {
        unset( $url );
        if ( is_scalar( $current ) && '' !== trim( (string) $current ) ) { return $current; }
        if ( ! $this->connected() || empty( $this->capabilities['resource_language'] ) ) { return $current; }
        $provider = $this->provider;
        try {
            if ( 'term' === $resourceType && method_exists( $provider, 'get_term_language' ) ) {
                $term = get_term( $objectId );
                if ( $term instanceof \WP_Term ) {
                    $language = $provider->get_term_language( $objectId, (string) $term->taxonomy );
                    return $this->normalizeLanguage( is_scalar( $language ) ? (string) $language : '' );
                }
                return $current;
            }
            if ( method_exists( $provider, 'get_resource_language' ) ) {
                $language = $provider->get_resource_language( $objectId, 'post' );
                $language = $this->normalizeLanguage( is_scalar( $language ) ? (string) $language : '' );
                return '' !== $language ? $language : $current;
            }
        } catch ( \Throwable $error ) {
            unset( $error );
        }
        return $current;
    }

    /**
     * @param mixed $current
     * @param array<int,array<string,mixed>> $requests
     * @return array<string,array{language_key:string,source:string}>
     */
    public function sitemapLanguageMap( $current, array $requests ): array {
        $out = is_array( $current ) ? $current : array();
        if ( ! $this->connected() ) { return $out; }

        $postIds = array();
        $termRows = array();
        foreach ( $requests as $request ) {
            if ( ! is_array( $request ) ) { continue; }
            $type = sanitize_key( (string) ( $request['resource_type'] ?? '' ) );
            $id = (int) ( $request['object_id'] ?? 0 );
            if ( $id <= 0 ) { continue; }
            if ( 'post' === $type || 'product' === $type ) {
                $postIds[] = $id;
            } elseif ( 'term' === $type ) {
                $taxonomy = sanitize_key( (string) ( $request['resource_subtype'] ?? $request['taxonomy'] ?? '' ) );
                if ( '' === $taxonomy ) {
                    $term = get_term( $id );
                    $taxonomy = $term instanceof \WP_Term ? sanitize_key( (string) $term->taxonomy ) : '';
                }
                if ( '' !== $taxonomy ) { $termRows[] = array( 'term_id' => $id, 'taxonomy' => $taxonomy ); }
            }
        }

        $postIds = array_values( array_unique( array_filter( array_map( 'intval', $postIds ) ) ) );
        if ( ! empty( $postIds ) ) {
            try {
                if ( ! empty( $this->capabilities['resource_languages_bulk'] ) && method_exists( $this->provider, 'get_resource_languages_bulk' ) ) {
                    $languages = $this->provider->get_resource_languages_bulk( $postIds, 'post' );
                    if ( is_array( $languages ) ) {
                        foreach ( $languages as $id => $language ) {
                            $language = $this->normalizeLanguage( is_scalar( $language ) ? (string) $language : '' );
                            if ( '' !== $language ) { $out[ 'post:' . (int) $id ] = array( 'language_key' => $language, 'source' => 'ymulti-provider' ); }
                        }
                    }
                } else {
                    foreach ( $postIds as $id ) {
                        $language = method_exists( $this->provider, 'get_resource_language' ) ? $this->provider->get_resource_language( $id, 'post' ) : null;
                        $language = $this->normalizeLanguage( is_scalar( $language ) ? (string) $language : '' );
                        if ( '' !== $language ) { $out[ 'post:' . $id ] = array( 'language_key' => $language, 'source' => 'ymulti-provider' ); }
                    }
                }
            } catch ( \Throwable $error ) { unset( $error ); }
        }

        if ( ! empty( $termRows ) ) {
            try {
                if ( ! empty( $this->capabilities['taxonomy_alternates_bulk'] ) && method_exists( $this->provider, 'get_term_translations_bulk' ) ) {
                    $families = $this->provider->get_term_translations_bulk( $termRows );
                    if ( is_array( $families ) ) {
                        foreach ( $termRows as $row ) {
                            $id = (int) $row['term_id'];
                            $taxonomy = (string) $row['taxonomy'];
                            $family = $this->termFamilyFromBulk( $families, $id, $taxonomy );
                            $language = $this->selfLanguageFromFamily( $family, $id, 'term_id' );
                            if ( '' === $language && method_exists( $this->provider, 'get_term_language' ) ) {
                                $candidate = $this->provider->get_term_language( $id, $taxonomy );
                                $language = $this->normalizeLanguage( is_scalar( $candidate ) ? (string) $candidate : '' );
                            }
                            if ( '' !== $language ) { $out[ 'term:' . $id ] = array( 'language_key' => $language, 'source' => 'ymulti-provider' ); }
                        }
                    }
                } elseif ( method_exists( $this->provider, 'get_term_language' ) ) {
                    foreach ( $termRows as $row ) {
                        $language = $this->provider->get_term_language( (int) $row['term_id'], (string) $row['taxonomy'] );
                        $language = $this->normalizeLanguage( is_scalar( $language ) ? (string) $language : '' );
                        if ( '' !== $language ) { $out[ 'term:' . (int) $row['term_id'] ] = array( 'language_key' => $language, 'source' => 'ymulti-provider' ); }
                    }
                }
            } catch ( \Throwable $error ) { unset( $error ); }
        }

        return $out;
    }

    /**
     * @param mixed $current
     * @param array<int,array<string,mixed>> $requests
     * @return array<string,array<int,array{language_key:string,url:string}>>
     */
    public function bulkAlternates( $current, array $requests ): array {
        $out = is_array( $current ) ? $current : array();
        if ( ! $this->connected() || empty( $this->capabilities['bulk_alternates'] ) ) { return $out; }

        $postIds = array();
        $termRows = array();
        foreach ( $requests as $request ) {
            if ( ! is_array( $request ) ) { continue; }
            $type = sanitize_key( (string) ( $request['resource_type'] ?? '' ) );
            $id = (int) ( $request['object_id'] ?? 0 );
            if ( $id <= 0 ) { continue; }
            if ( 'post' === $type || 'product' === $type ) {
                $postIds[] = $id;
            } elseif ( 'term' === $type ) {
                $taxonomy = sanitize_key( (string) ( $request['resource_subtype'] ?? $request['taxonomy'] ?? '' ) );
                if ( '' === $taxonomy ) {
                    $term = get_term( $id );
                    $taxonomy = $term instanceof \WP_Term ? sanitize_key( (string) $term->taxonomy ) : '';
                }
                if ( '' !== $taxonomy ) { $termRows[] = array( 'term_id' => $id, 'taxonomy' => $taxonomy ); }
            }
        }

        $postIds = array_values( array_unique( array_filter( array_map( 'intval', $postIds ) ) ) );
        if ( ! empty( $postIds ) && method_exists( $this->provider, 'get_translations_bulk' ) ) {
            try {
                if ( function_exists( '_prime_post_caches' ) ) { _prime_post_caches( $postIds, false, false ); }
                $families = $this->provider->get_translations_bulk( $postIds, 'post' );
                if ( is_array( $families ) ) {
                    foreach ( $postIds as $id ) {
                        $family = isset( $families[ $id ] ) && is_array( $families[ $id ] ) ? $families[ $id ] : array();
                        $out[ 'post:' . $id ] = $this->normalizeFamily( $family );
                    }
                }
            } catch ( \Throwable $error ) { unset( $error ); }
        }

        if ( ! empty( $termRows ) && ! empty( $this->capabilities['taxonomy_alternates_bulk'] ) && method_exists( $this->provider, 'get_term_translations_bulk' ) ) {
            try {
                $families = $this->provider->get_term_translations_bulk( $termRows );
                if ( is_array( $families ) ) {
                    foreach ( $termRows as $row ) {
                        $id = (int) $row['term_id'];
                        $family = $this->termFamilyFromBulk( $families, $id, (string) $row['taxonomy'] );
                        $out[ 'term:' . $id ] = $this->normalizeFamily( $family );
                    }
                }
            } catch ( \Throwable $error ) { unset( $error ); }
        }
        return $out;
    }

    /** @param mixed $current @return array<int,array{language_key:string,url:string}> */
    public function singleAlternates( $current, string $resourceType, int $objectId ): array {
        $out = is_array( $current ) ? $current : array();
        if ( ! $this->connected() ) { return $out; }
        try {
            if ( 'term' === $resourceType && method_exists( $this->provider, 'get_term_translations' ) ) {
                $term = get_term( $objectId );
                if ( $term instanceof \WP_Term ) {
                    return array_merge( $out, $this->normalizeFamily( $this->provider->get_term_translations( $objectId, (string) $term->taxonomy ) ) );
                }
            }
            if ( method_exists( $this->provider, 'get_translations' ) ) {
                return array_merge( $out, $this->normalizeFamily( $this->provider->get_translations( $objectId, 'post' ) ) );
            }
        } catch ( \Throwable $error ) { unset( $error ); }
        return $out;
    }

    /** @param mixed $current @return mixed */
    public function requestLanguage( $current, string $origin ) {
        unset( $origin );
        if ( is_scalar( $current ) && '' !== trim( (string) $current ) ) { return $current; }
        if ( ! $this->connected() || empty( $this->capabilities['request_context'] ) || ! method_exists( $this->provider, 'get_request_context' ) ) { return $current; }
        try {
            $context = $this->provider->get_request_context();
            if ( ! is_array( $context ) || ! empty( $context['is_system'] ) ) { return $current; }
            $language = $this->normalizeLanguage( is_scalar( $context['language'] ?? null ) ? (string) $context['language'] : '' );
            return '' !== $language ? $language : $current;
        } catch ( \Throwable $error ) {
            unset( $error );
            return $current;
        }
    }

    /** @param mixed $event */
    public function graphInvalidated( $event ): void {
        if ( ! is_array( $event ) || self::CONTRACT !== (string) ( $event['contract'] ?? '' ) ) { return; }
        $type = sanitize_key( (string) ( $event['resource_type'] ?? '' ) );
        $reason = sanitize_key( (string) ( $event['reason'] ?? '' ) );
        if ( 'global' === $type || 'language_configuration_changed' === $reason ) {
            do_action( 'yablonsky_seo_sitemap_rebuild_v1' );
            return;
        }
        if ( 'post' === $type ) {
            $ids = array( (int) ( $event['resource_id'] ?? 0 ) );
            if ( isset( $event['affected_resource_ids'] ) && is_array( $event['affected_resource_ids'] ) ) {
                $ids = array_merge( $ids, array_map( 'intval', $event['affected_resource_ids'] ) );
            }
            foreach ( array_values( array_unique( array_filter( $ids ) ) ) as $id ) { $this->sitemapRepository->invalidateObject( 'post', (int) $id ); }
            return;
        }
        if ( 'term' === $type ) {
            $ids = array( (int) ( $event['resource_id'] ?? 0 ) );
            if ( isset( $event['affected_terms'] ) && is_array( $event['affected_terms'] ) ) {
                foreach ( $event['affected_terms'] as $term ) {
                    if ( is_array( $term ) ) { $ids[] = (int) ( $term['term_id'] ?? 0 ); }
                }
            }
            foreach ( array_values( array_unique( array_filter( $ids ) ) ) as $id ) { $this->sitemapRepository->invalidateObject( 'term', (int) $id ); }
        }
    }

    private function resolveProvider(): void {
        if ( $this->resolved ) { return; }
        $this->resolved = true;
        $provider = apply_filters( 'yablonsky_seo_multilingual_provider_v1', null );
        $capabilities = apply_filters( 'yablonsky_multilingual_seo_capabilities_v1', array() );
        if ( is_object( $provider ) && is_array( $capabilities ) && self::CONTRACT === (string) ( $capabilities['contract'] ?? '' ) ) {
            $this->provider = $provider;
            $this->capabilities = $capabilities;
        }
    }

    /** @param array<string,mixed> $family @return array<int,array{language_key:string,url:string}> */
    private function normalizeFamily( array $family ): array {
        $out = array();
        $defaultUrl = '';
        $defaultLanguage = '';
        try {
            if ( method_exists( $this->provider, 'get_default_language' ) ) {
                $defaultLanguage = $this->normalizeLanguage( (string) $this->provider->get_default_language() );
            }
        } catch ( \Throwable $error ) { unset( $error ); }
        foreach ( $family as $language => $member ) {
            if ( ! is_array( $member ) || empty( $member['public'] ) ) { continue; }
            $url = esc_url_raw( (string) ( $member['url'] ?? '' ), array( 'http', 'https' ) );
            if ( '' === $url ) { continue; }
            $hreflang = $this->hreflang( (string) $language );
            if ( '' === $hreflang ) { continue; }
            $out[ $hreflang ] = $url;
            if ( '' !== $defaultLanguage && $this->normalizeLanguage( (string) $language ) === $defaultLanguage ) { $defaultUrl = $url; }
        }
        if ( '' !== $defaultUrl ) { $out['x-default'] = $defaultUrl; }
        ksort( $out, SORT_STRING );
        $normalized = array();
        foreach ( $out as $language => $url ) { $normalized[] = array( 'language_key' => $language, 'url' => $url ); }
        return $normalized;
    }

    /** @param array<string,mixed> $families @return array<string,mixed> */
    private function termFamilyFromBulk( array $families, int $termId, string $taxonomy ): array {
        foreach ( array( $termId, (string) $termId, $taxonomy . ':' . $termId, $termId . ':' . $taxonomy ) as $key ) {
            if ( isset( $families[ $key ] ) && is_array( $families[ $key ] ) ) { return $families[ $key ]; }
        }
        return array();
    }

    /** @param array<string,mixed> $family */
    private function selfLanguageFromFamily( array $family, int $id, string $idField ): string {
        foreach ( $family as $language => $member ) {
            if ( is_array( $member ) && (int) ( $member[ $idField ] ?? 0 ) === $id ) { return $this->normalizeLanguage( (string) $language ); }
        }
        return '';
    }

    private function hreflang( string $language ): string {
        $language = $this->normalizeLanguage( $language );
        if ( '' === $language ) { return ''; }
        try {
            if ( method_exists( $this->provider, 'get_hreflang_code' ) ) {
                $candidate = $this->provider->get_hreflang_code( $language );
                $candidate = is_scalar( $candidate ) ? (string) $candidate : '';
                if ( preg_match( '/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $candidate ) ) { return $candidate; }
            }
        } catch ( \Throwable $error ) { unset( $error ); }
        return $language;
    }

    private function normalizeLanguage( string $language ): string {
        $language = trim( str_replace( '_', '-', $language ) );
        if ( 'x-default' === strtolower( $language ) ) { return 'x-default'; }
        if ( ! preg_match( '/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $language ) ) { return ''; }
        $parts = explode( '-', $language );
        $parts[0] = strtolower( $parts[0] );
        foreach ( array_slice( $parts, 1 ) as $index => $part ) {
            if ( 4 === strlen( $part ) && ctype_alpha( $part ) ) { $parts[ $index + 1 ] = ucfirst( strtolower( $part ) ); }
            elseif ( ( 2 === strlen( $part ) && ctype_alpha( $part ) ) || ( 3 === strlen( $part ) && ctype_digit( $part ) ) ) { $parts[ $index + 1 ] = strtoupper( $part ); }
            else { $parts[ $index + 1 ] = strtolower( $part ); }
        }
        return implode( '-', $parts );
    }
}
