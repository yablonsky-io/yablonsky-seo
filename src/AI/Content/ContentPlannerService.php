<?php
namespace Yablonsky\PerformanceSEO\AI\Content;

use Yablonsky\PerformanceSEO\AI\AiClientResolver;
use Yablonsky\PerformanceSEO\AI\Config\AiSettings;
use Yablonsky\PerformanceSEO\AI\Contract\ComputeRequest;
use Yablonsky\PerformanceSEO\AI\Contract\ServiceError;
use Yablonsky\PerformanceSEO\AI\Contract\YablonskyAiClientInterface;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded, explicit content planning. Nothing is created or persisted by this service. */
final class ContentPlannerService {
    /** @var YablonskyAiClientInterface */ private $client;
    /** @var ResourceLanguageResolver */ private $languageResolver;

    public function __construct( ?YablonskyAiClientInterface $client = null ) {
        $this->client = $client instanceof YablonskyAiClientInterface ? $client : ( new AiClientResolver() )->resolve();
        $this->languageResolver = new ResourceLanguageResolver();
    }

    /** @return array<string,mixed> */
    public function plan( string $requestedLanguage = '' ): array {
        if ( ! $this->client->isConfigured() ) {
            throw new ServiceError( esc_html__( 'Connect a Yablonsky AI User API key before requesting a content plan.', 'yablonsky-seo' ) );
        }
        $requestedLanguage = trim( $requestedLanguage );
        if ( '' === $requestedLanguage ) { $requestedLanguage = (string) get_bloginfo( 'language' ); }
        $language = $this->languageResolver->normalize( $requestedLanguage );
        if ( '' === $language || 'auto' === $language || 'x-default' === $language ) {
            throw new ServiceError( esc_html__( 'Choose a valid target language for the content plan.', 'yablonsky-seo' ) );
        }
        $posts = get_posts( array(
            'post_type' => get_post_types( array( 'public' => true ), 'names' ),
            'post_status' => 'publish',
            'posts_per_page' => 24,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'suppress_filters' => false,
        ) );
        $contentSample = array();
        foreach ( $posts as $post ) {
            if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) { continue; }
            $resourceLanguage = $this->languageResolver->forPost( (int) $post->ID );
            $sampleLanguage = $this->languageResolver->normalize( (string) ( $resourceLanguage['language_key'] ?? '' ) );
            if ( ! $this->languageResolver->equivalent( $sampleLanguage, $language ) ) { continue; }
            $contentSample[] = array(
                'post_type' => (string) $post->post_type,
                'title' => $this->boundedText( (string) $post->post_title, 240 ),
                'excerpt' => $this->boundedText( (string) $post->post_excerpt, 420 ),
                'language' => $sampleLanguage,
            );
            if ( count( $contentSample ) >= 20 ) { break; }
        }
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $payload = array(
            'contract' => 'yseo.content.plan.v1',
            'site' => array(
                'name' => $this->boundedText( (string) get_bloginfo( 'name' ), 200 ),
                'description' => $this->boundedText( (string) get_bloginfo( 'description' ), 500 ),
                'owner_name' => $this->boundedText( (string) ( $settings['owner_name'] ?? '' ), 240 ),
                'target_language' => $language,
            ),
            'recent_content_sample' => $contentSample,
            'constraints' => array(
                'max_items' => 8,
                'human_review_required' => true,
                'no_auto_create' => true,
                'no_auto_publish' => true,
                'avoid_duplicate_topics' => true,
                'preserve_target_language' => true,
            ),
        );
        $result = $this->client->compute( new ComputeRequest( 'seo.content.plan.v1', $this->modelId(), $payload ) );
        return array( 'language' => $language, 'items' => $this->sanitizeItems( $result->data(), $language ) );
    }

    /** @param array<string,mixed> $data @return array<int,array<string,string>> */
    private function sanitizeItems( array $data, string $expectedLanguage ): array {
        $returnedLanguage = isset( $data['language'] ) && is_scalar( $data['language'] ) ? $this->languageResolver->normalize( (string) $data['language'] ) : '';
        if ( '' === $returnedLanguage || ! $this->languageResolver->equivalent( $returnedLanguage, $expectedLanguage ) ) {
            throw new ServiceError( esc_html__( 'Yablonsky AI did not confirm the requested planning language. No plan was accepted.', 'yablonsky-seo' ) );
        }
        $raw = array();
        foreach ( array( 'items', 'ideas', 'topics', 'results' ) as $listKey ) { if ( isset( $data[ $listKey ] ) && is_array( $data[ $listKey ] ) ) { $raw = $data[ $listKey ]; break; } }
        $items = array();
        foreach ( array_slice( $raw, 0, 8 ) as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $title = $this->field( $item, 'title', 220 );
            if ( '' === $title ) { continue; }
            $items[] = array(
                'title' => $title,
                'intent' => $this->field( $item, 'intent', 160 ),
                'focus_keyphrase' => $this->field( $item, 'focus_keyphrase', 180 ),
                'rationale' => $this->field( $item, 'rationale', 600 ),
            );
        }
        if ( empty( $items ) ) { throw new ServiceError( esc_html__( 'Yablonsky AI returned no usable content-plan items.', 'yablonsky-seo' ) ); }
        return $items;
    }

    /** @param array<string,mixed> $item */
    private function field( array $item, string $key, int $max ): string {
        $aliases = array(
            'title' => array( 'title', 'topic', 'headline' ),
            'intent' => array( 'intent', 'search_intent', 'purpose' ),
            'focus_keyphrase' => array( 'focus_keyphrase', 'focusKeyphrase', 'keyphrase', 'keyword' ),
            'rationale' => array( 'rationale', 'reason', 'why', 'description' ),
        );
        $keys = isset( $aliases[ $key ] ) ? $aliases[ $key ] : array( $key );
        foreach ( $keys as $candidate ) {
            if ( isset( $item[ $candidate ] ) && is_scalar( $item[ $candidate ] ) ) { return $this->boundedText( (string) $item[ $candidate ], $max ); }
        }
        return '';
    }

    private function modelId(): string {
        $models = $this->client->models();
        $selected = ( new AiSettings() )->modelId();
        $requested = apply_filters( 'yablonsky_seo_ai_content_planner_model', $selected, $models );
        $requested = is_string( $requested ) ? sanitize_text_field( $requested ) : 'auto';
        foreach ( $models as $model ) { if ( $model->id() === $requested ) { return $model->id(); } }
        return 'auto';
    }

    private function boundedText( string $text, int $max ): string {
        $text = trim( wp_strip_all_tags( $text, true ) );
        $text = (string) preg_replace( '/\\s+/u', ' ', $text );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
    }
}
