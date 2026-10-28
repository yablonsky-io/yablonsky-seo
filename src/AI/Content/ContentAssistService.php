<?php
namespace Yablonsky\PerformanceSEO\AI\Content;

use Yablonsky\PerformanceSEO\AI\AiClientResolver;
use Yablonsky\PerformanceSEO\AI\Config\AiSettings;
use Yablonsky\PerformanceSEO\AI\Contract\ComputeRequest;
use Yablonsky\PerformanceSEO\AI\Contract\ServiceError;
use Yablonsky\PerformanceSEO\AI\Contract\YablonskyAiClientInterface;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Explicit, review-only AI content assistance.
 * Remote output is untrusted, language-bound and never persisted here.
 */
final class ContentAssistService {
    /** @var YablonskyAiClientInterface */ private $client;
    /** @var ResourceLanguageResolver */ private $languageResolver;

    public function __construct( ?YablonskyAiClientInterface $client = null, ?ResourceLanguageResolver $languageResolver = null ) {
        $this->client = $client instanceof YablonskyAiClientInterface ? $client : ( new AiClientResolver() )->resolve();
        $this->languageResolver = $languageResolver instanceof ResourceLanguageResolver ? $languageResolver : new ResourceLanguageResolver();
    }

    public function isConfigured(): bool { return $this->client->isConfigured(); }

    /** @return array<string,string> */
    public function assistPost( int $postId, string $mode ): array {
        if ( ! $this->client->isConfigured() ) {
            throw new ServiceError( esc_html__( 'Connect a Yablonsky AI User API key in Yablonsky SEO Settings before requesting remote content assistance.', 'yablonsky-seo' ) );
        }
        $mode = sanitize_key( $mode );
        if ( ! in_array( $mode, array( 'summarize', 'optimize' ), true ) ) {
            throw new ServiceError( esc_html__( 'Unsupported AI content-assist mode.', 'yablonsky-seo' ) );
        }
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) {
            throw new ServiceError( esc_html__( 'The requested WordPress content is not available for AI content assistance.', 'yablonsky-seo' ) );
        }
        $language = $this->languageResolver->forPost( $postId );
        $languageKey = (string) $language['language_key'];
        if ( '' === $languageKey || 'x-default' === $languageKey || 'auto' === $languageKey ) {
            throw new ServiceError( esc_html__( 'A proven resource language is required before AI content assistance can run.', 'yablonsky-seo' ) );
        }
        $payload = array(
            'contract' => 'yseo.content.assist.v1',
            'mode' => $mode,
            'resource' => array(
                'id' => $postId,
                'post_type' => (string) $post->post_type,
                'title' => $this->boundedText( (string) $post->post_title, 300 ),
                'excerpt' => $this->boundedText( (string) $post->post_excerpt, 1200 ),
                'content' => $this->boundedText( strip_shortcodes( (string) $post->post_content ), 8000 ),
                'language' => $languageKey,
                'language_source' => (string) $language['source'],
                'permalink' => (string) get_permalink( $postId ),
            ),
            'seo_context' => array(
                'focus_keyphrase' => $this->boundedText( (string) get_post_meta( $postId, '_yps_focus_keyphrase', true ), 200 ),
                'meta_description' => $this->boundedText( (string) get_post_meta( $postId, '_yps_meta_description', true ), 500 ),
            ),
            'constraints' => array(
                'visible_content_only' => true,
                'preserve_resource_language' => true,
                'ignore_admin_locale_for_output' => true,
                'human_review_required' => true,
                'no_auto_save' => true,
                'summary_max_chars' => 1600,
                'guidance_max_chars' => 5000,
            ),
        );
        $result = $this->client->compute( new ComputeRequest( 'seo.content.assist.v1', $this->modelId(), $payload ) );
        return $this->sanitizeResult( $result->data(), $mode, $languageKey );
    }

    /** @param array<string,mixed> $data @return array<string,string> */
    private function sanitizeResult( array $data, string $mode, string $expectedLanguage ): array {
        $returnedLanguage = isset( $data['language'] ) && is_scalar( $data['language'] ) ? $this->languageResolver->normalize( (string) $data['language'] ) : '';
        $expectedLanguage = $this->languageResolver->normalize( $expectedLanguage );
        if ( '' === $returnedLanguage || ! $this->languageResolver->equivalent( $returnedLanguage, $expectedLanguage ) ) {
            throw new ServiceError( esc_html__( 'Yablonsky AI did not confirm the current resource language. No content assistance was accepted.', 'yablonsky-seo' ) );
        }
        $keys = 'summarize' === $mode ? array( 'summary', 'content', 'text', 'result', 'answer' ) : array( 'guidance', 'optimization_guidance', 'optimization', 'recommendations', 'content', 'text', 'result' );
        $text = '';
        foreach ( $keys as $key ) {
            if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) { $text = trim( wp_strip_all_tags( (string) $data[ $key ], true ) ); break; }
        }
        if ( '' === $text ) {
            throw new ServiceError( esc_html__( 'Yablonsky AI returned no usable content-assist text.', 'yablonsky-seo' ) );
        }
        $max = 'summarize' === $mode ? 1600 : 5000;
        if ( function_exists( 'mb_substr' ) ) { $text = mb_substr( $text, 0, $max, 'UTF-8' ); }
        else { $text = substr( $text, 0, $max ); }
        return array( 'mode' => $mode, 'language' => $returnedLanguage, 'text' => $text );
    }

    private function modelId(): string {
        $models = $this->client->models();
        $selected = ( new AiSettings() )->modelId();
        $requested = apply_filters( 'yablonsky_seo_ai_content_model', $selected, $models );
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
