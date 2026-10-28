<?php
namespace Yablonsky\PerformanceSEO\AI\Metadata;

use Yablonsky\PerformanceSEO\AI\AiClientResolver;
use Yablonsky\PerformanceSEO\AI\Contract\ComputeRequest;
use Yablonsky\PerformanceSEO\AI\Contract\ServiceError;
use Yablonsky\PerformanceSEO\AI\Contract\YablonskyAiClientInterface;
use Yablonsky\PerformanceSEO\AI\Config\AiSettings;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validates remote SEO suggestions before they ever reach WordPress fields.
 * Remote output is untrusted data. The service never persists a suggestion itself.
 */
final class MetadataSuggestionService {
    /** @var YablonskyAiClientInterface */ private $client;
    /** @var ResourceLanguageResolver */ private $languageResolver;
    /** @var array<string,mixed> */ private $lastComputeMeta = array();

    public function __construct( ?YablonskyAiClientInterface $client = null, ?ResourceLanguageResolver $languageResolver = null ) {
        $this->client = $client instanceof YablonskyAiClientInterface ? $client : ( new AiClientResolver() )->resolve();
        $this->languageResolver = $languageResolver instanceof ResourceLanguageResolver ? $languageResolver : new ResourceLanguageResolver();
    }

    public function isConfigured(): bool { return $this->client->isConfigured(); }

    /** @return array<string,mixed> */
    public function lastComputeMeta(): array { return $this->lastComputeMeta; }

    /** @return array<string,string> */
    public function suggestPost( int $postId, array $requestedFields = array() ): array {
        if ( ! $this->client->isConfigured() ) {
            throw new ServiceError( esc_html__( 'Connect a Yablonsky AI User API key in Yablonsky SEO Settings before requesting remote compute.', 'yablonsky-seo' ) );
        }
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) {
            throw new ServiceError( esc_html__( 'The requested WordPress content is not available for SEO metadata generation.', 'yablonsky-seo' ) );
        }
        $requestedFields = $this->sanitizeRequestedFields( $requestedFields );
        $resourceLanguage = $this->languageResolver->forPost( $postId );
        $payload = array(
            'contract' => 'yseo.metadata.suggest.v1',
            'resource' => array(
                'id'          => $postId,
                'post_type'   => (string) $post->post_type,
                'title'       => trim( wp_strip_all_tags( (string) $post->post_title, true ) ),
                'excerpt'     => trim( wp_strip_all_tags( (string) $post->post_excerpt, true ) ),
                'content'     => $this->boundedContent( (string) $post->post_content, 6000 ),
                'language'    => (string) $resourceLanguage['language_key'],
                'language_source' => (string) $resourceLanguage['source'],
                'permalink'   => (string) get_permalink( $postId ),
            ),
            'current' => $this->currentMetadata( $postId, $requestedFields ),
            'requested_fields' => $requestedFields,
            'constraints' => array(
                'seo_title_recommended_max' => 60,
                'meta_description_recommended_max' => 160,
                'max_total_keyphrases' => 5,
                'visible_content_only' => true,
                'preserve_resource_language' => true,
                'ignore_admin_locale_for_output' => true,
                'resource_language_is_authoritative' => true,
                'infer_language_only_from_resource_text' => true,
            ),
        );
        $result = $this->client->compute( new ComputeRequest( 'seo.metadata.suggest.v1', $this->modelId(), $payload ) );
        $this->lastComputeMeta = $result->meta();
        return $this->sanitizeSuggestionData( $result->data(), $requestedFields, (string) $resourceLanguage['language_key'] );
    }

    /**
     * @param array<int,int> $postIds
     * @return array<int,array<string,string>>
     */
    public function suggestBatch( array $postIds, array $requestedFields = array() ): array {
        if ( ! $this->client->isConfigured() ) {
            throw new ServiceError( esc_html__( 'Connect a Yablonsky AI User API key in Yablonsky SEO Settings before requesting remote compute.', 'yablonsky-seo' ) );
        }
        $requestedFields = $this->sanitizeRequestedFields( $requestedFields );
        $postIds = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $postIds ) ) ) ), 0, 5 );
        $items = array();
        foreach ( $postIds as $postId ) {
            $post = get_post( $postId );
            if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) { continue; }
            $resourceLanguage = $this->languageResolver->forPost( $postId );
            $items[] = array(
                'id'        => $postId,
                'post_type' => (string) $post->post_type,
                'title'     => trim( wp_strip_all_tags( (string) $post->post_title, true ) ),
                'excerpt'   => trim( wp_strip_all_tags( (string) $post->post_excerpt, true ) ),
                'content'   => $this->boundedContent( (string) $post->post_content, 1800 ),
                'language'  => (string) $resourceLanguage['language_key'],
                'language_source' => (string) $resourceLanguage['source'],
                'current'   => $this->currentMetadata( $postId, $requestedFields ),
            );
        }
        if ( empty( $items ) ) { return array(); }
        $result = $this->client->compute(
            new ComputeRequest(
                'seo.metadata.batch_suggest.v1',
                $this->modelId(),
                array(
                    'contract' => 'yseo.metadata.batch_suggest.v1',
                    'items' => $items,
                    'requested_fields' => $requestedFields,
                    'constraints' => array( 'batch_max' => 5, 'visible_content_only' => true, 'preserve_resource_language' => true, 'ignore_admin_locale_for_output' => true, 'resource_language_is_authoritative' => true, 'infer_language_only_from_resource_text' => true ),
                )
            )
        );
        $this->lastComputeMeta = $result->meta();
        $data = $result->data();
        $rawItems = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
        $suggestions = array();
        $expectedLanguages = array();
        foreach ( $items as $payloadItem ) {
            $expectedLanguages[ (int) $payloadItem['id'] ] = (string) $payloadItem['language'];
        }
        foreach ( $rawItems as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['id'] ) ) { continue; }
            $id = (int) $item['id'];
            if ( $id <= 0 || ! in_array( $id, $postIds, true ) ) { continue; }
            try {
                $suggestions[ $id ] = $this->sanitizeSuggestionData( $item, $requestedFields, (string) ( $expectedLanguages[ $id ] ?? 'auto' ) );
            } catch ( ServiceError $error ) {
                continue;
            }
        }
        return $suggestions;
    }

    /** @param array<string,mixed> $data @return array<string,string> */
    private function sanitizeSuggestionData( array $data, array $requestedFields, string $expectedLanguage = 'auto' ): array {
        $data = $this->normalizeSuggestionData( $data );
        $expectedLanguage = $this->languageResolver->normalize( $expectedLanguage );
        $returnedLanguage = isset( $data['language'] ) && is_scalar( $data['language'] ) ? $this->languageResolver->normalize( (string) $data['language'] ) : '';
        if ( '' === $returnedLanguage || 'x-default' === $returnedLanguage ) {
            throw new ServiceError( esc_html__( 'Yablonsky AI did not confirm the resource language, so no SEO metadata was accepted.', 'yablonsky-seo' ) );
        }
        if ( '' !== $expectedLanguage && 'auto' !== $expectedLanguage && ! $this->languageResolver->equivalent( $returnedLanguage, $expectedLanguage ) ) {
            throw new ServiceError( esc_html__( 'Yablonsky AI returned metadata for a different language than the current resource. Nothing was accepted.', 'yablonsky-seo' ) );
        }
        $allowed = array(
            'focus_keyphrase'  => 200,
            'focus_keyphrases' => 800,
            'seo_title'        => 200,
            'meta_description' => 500,
        );
        $clean = array();
        foreach ( $allowed as $key => $max ) {
            if ( ! in_array( $key, $requestedFields, true ) ) { continue; }
            if ( ! isset( $data[ $key ] ) ) { continue; }
            if ( 'focus_keyphrases' === $key ) {
                $values = is_array( $data[ $key ] ) ? $data[ $key ] : preg_split( '/[\r\n,;]+/u', (string) $data[ $key ], -1, PREG_SPLIT_NO_EMPTY );
                $related = array();
                $seen = array();
                foreach ( is_array( $values ) ? $values : array() as $item ) {
                    if ( ! is_scalar( $item ) ) { continue; }
                    $value = trim( wp_strip_all_tags( (string) $item, true ) );
                    if ( '' === $value ) { continue; }
                    $value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 200, 'UTF-8' ) : substr( $value, 0, 200 );
                    $index = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
                    if ( isset( $seen[ $index ] ) ) { continue; }
                    $seen[ $index ] = true;
                    $related[] = $value;
                    if ( count( $related ) >= 4 ) { break; }
                }
                if ( ! empty( $related ) ) { $clean[ $key ] = implode( "\n", $related ); }
                continue;
            }
            if ( ! is_scalar( $data[ $key ] ) ) { continue; }
            $value = trim( wp_strip_all_tags( (string) $data[ $key ], true ) );
            if ( '' === $value ) { continue; }
            if ( function_exists( 'mb_substr' ) ) { $value = mb_substr( $value, 0, $max, 'UTF-8' ); }
            else { $value = substr( $value, 0, $max ); }
            $clean[ $key ] = $value;
        }
        if ( empty( $clean ) ) { throw new ServiceError( esc_html__( 'Yablonsky AI returned no valid SEO metadata fields.', 'yablonsky-seo' ) ); }
        return $clean;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function normalizeSuggestionData( array $data ): array {
        $aliases = array(
            'language' => array( 'language', 'lang', 'locale' ),
            'focus_keyphrase' => array( 'focus_keyphrase', 'focusKeyphrase', 'keyphrase', 'keyword', 'focus_keyword' ),
            'focus_keyphrases' => array( 'focus_keyphrases', 'focusKeyphrases', 'related_keyphrases', 'relatedKeyphrases', 'secondary_keyphrases', 'secondaryKeyphrases', 'keywords' ),
            'seo_title' => array( 'seo_title', 'seoTitle', 'title', 'meta_title' ),
            'meta_description' => array( 'meta_description', 'metaDescription', 'description', 'meta_desc' ),
        );
        $clean = array();
        foreach ( $aliases as $canonical => $keys ) {
            foreach ( $keys as $key ) {
                if ( array_key_exists( $key, $data ) ) { $clean[ $canonical ] = $data[ $key ]; break; }
            }
        }
        if ( isset( $data['id'] ) ) { $clean['id'] = $data['id']; }
        return $clean;
    }

    /** @param array<int,string> $fields @return array<string,string> */
    private function currentMetadata( int $postId, array $fields ): array {
        $map = array(
            'focus_keyphrase' => '_yps_focus_keyphrase',
            'focus_keyphrases' => '_yps_focus_keyphrases_json',
            'seo_title' => '_yps_seo_title',
            'meta_description' => '_yps_meta_description',
        );
        $current = array();
        foreach ( $fields as $field ) {
            if ( ! isset( $map[ $field ] ) ) { continue; }
            $value = get_post_meta( $postId, $map[ $field ], true );
            if ( 'focus_keyphrases' === $field ) { $current[ $field ] = $this->relatedKeyphrasesText( $value ); }
            else { $current[ $field ] = is_scalar( $value ) ? (string) $value : ''; }
        }
        return $current;
    }

    /** @param array<int,string> $fields @return array<int,string> */
    private function sanitizeRequestedFields( array $fields ): array {
        $allowed = array( 'focus_keyphrase', 'focus_keyphrases', 'seo_title', 'meta_description' );
        $clean = array();
        foreach ( $fields as $field ) {
            $field = sanitize_key( (string) $field );
            if ( in_array( $field, $allowed, true ) && ! in_array( $field, $clean, true ) ) { $clean[] = $field; }
        }
        return empty( $clean ) ? $allowed : $clean;
    }

    /** @param mixed $raw */
    private function relatedKeyphrasesText( $raw ): string {
        $items = array();
        if ( is_string( $raw ) && '' !== trim( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $walk = function ( $value ) use ( &$items, &$walk ): void {
                    if ( is_scalar( $value ) ) { $items[] = (string) $value; return; }
                    if ( is_array( $value ) ) { foreach ( $value as $child ) { $walk( $child ); } }
                };
                $walk( $decoded );
            } else {
                $items = preg_split( '/[\r\n,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
            }
        } elseif ( is_array( $raw ) ) {
            $items = $raw;
        }
        $clean = array(); $seen = array();
        foreach ( $items as $item ) {
            if ( ! is_scalar( $item ) ) { continue; }
            $value = trim( wp_strip_all_tags( (string) $item, true ) );
            if ( '' === $value ) { continue; }
            $key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
            if ( isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true; $clean[] = $value;
            if ( count( $clean ) >= 4 ) { break; }
        }
        return implode( "\n", $clean );
    }

    private function modelId(): string {
        $models = $this->client->models();
        $selected = ( new AiSettings() )->metadataModelId();
        $requested = apply_filters( 'yablonsky_seo_ai_metadata_model', $selected, $models );
        $requested = is_string( $requested ) ? sanitize_text_field( $requested ) : 'auto';
        foreach ( $models as $model ) {
            if ( $model->id() === $requested && in_array( 'seo_metadata', $model->capabilities(), true ) ) { return $model->id(); }
        }
        return 'auto';
    }

    private function boundedContent( string $content, int $max = 6000 ): string {
        $text = trim( wp_strip_all_tags( strip_shortcodes( $content ), true ) );
        $text = (string) preg_replace( '/\s+/u', ' ', $text );
        $max = max( 800, min( 8000, $max ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
    }
}
