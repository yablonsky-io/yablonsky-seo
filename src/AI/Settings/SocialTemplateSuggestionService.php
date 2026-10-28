<?php
namespace Yablonsky\PerformanceSEO\AI\Settings;

use Yablonsky\PerformanceSEO\AI\AiClientResolver;
use Yablonsky\PerformanceSEO\AI\Config\AiSettings;
use Yablonsky\PerformanceSEO\AI\Contract\ComputeRequest;
use Yablonsky\PerformanceSEO\AI\Contract\ServiceError;
use Yablonsky\PerformanceSEO\AI\Contract\YablonskyAiClientInterface;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Review-only AI drafts for reusable Open Graph / X template families. */
final class SocialTemplateSuggestionService {
    /** @var YablonskyAiClientInterface */ private $client;
    /** @var ResourceLanguageResolver */ private $languageResolver;

    public function __construct( ?YablonskyAiClientInterface $client = null ) {
        $this->client = $client instanceof YablonskyAiClientInterface ? $client : ( new AiClientResolver() )->resolve();
        $this->languageResolver = new ResourceLanguageResolver();
    }

    /** @param array<int,string> $requestedFields @return array<string,string> */
    public function suggest( string $group, string $key, array $requestedFields ): array {
        if ( ! $this->client->isConfigured() ) {
            throw new ServiceError( esc_html__( 'Connect and verify a Yablonsky AI User API key before requesting site setup suggestions.', 'yablonsky-seo' ) );
        }
        $family = SettingsAuthoringRegistry::socialTemplateFamily( $group, $key );
        if ( null === $family ) {
            throw new ServiceError( esc_html__( 'This social template family is retired.', 'yablonsky-seo' ) );
        }
        $fields = array();
        foreach ( $requestedFields as $field ) {
            $field = sanitize_key( (string) $field );
            if ( SettingsAuthoringRegistry::supportsSocialTemplateText( $field ) && ! in_array( $field, $fields, true ) ) { $fields[] = $field; }
        }
        $fields = array_slice( $fields, 0, 4 );
        if ( empty( $fields ) ) {
            throw new ServiceError( esc_html__( 'AI-eligible social template fields are ready when requested.', 'yablonsky-seo' ) );
        }

        $language = $this->languageResolver->normalize( (string) get_bloginfo( 'language' ) );
        if ( '' === $language || 'auto' === $language || 'x-default' === $language ) { $language = 'en'; }
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $row = isset( $settings['social_templates'][ $family['group'] ][ $family['key'] ] ) && is_array( $settings['social_templates'][ $family['group'] ][ $family['key'] ] )
            ? $settings['social_templates'][ $family['group'] ][ $family['key'] ] : array();

        $payload = array(
            'contract' => 'yseo.social-template.suggest.v1',
            'language' => $language,
            'site' => array(
                'name' => $this->boundedText( (string) ( $settings['site_display_name'] ?? get_bloginfo( 'name' ) ), 240 ),
                'description' => $this->boundedText( (string) ( $settings['site_description'] ?? get_bloginfo( 'description' ) ), 700 ),
                'home_url' => esc_url_raw( home_url( '/' ), array( 'http', 'https' ) ),
            ),
            'family' => $family,
            'current_template' => array(
                'og_title' => $this->boundedText( (string) ( $row['og_title'] ?? '' ), 300 ),
                'twitter_title' => $this->boundedText( (string) ( $row['twitter_title'] ?? '' ), 300 ),
                'og_description' => $this->boundedText( (string) ( $row['og_description'] ?? '' ), 700 ),
                'twitter_description' => $this->boundedText( (string) ( $row['twitter_description'] ?? '' ), 700 ),
            ),
            'requested_fields' => $fields,
            'allowed_template_variables' => array( '%%title%%', '%%archive_title%%', '%%sitename%%', '%%sitedesc%%', '%%sep%%', '%%page%%', '%%excerpt%%', '%%excerpt_only%%', '%%term_title%%', '%%term_description%%', '%%category%%', '%%tag%%', '%%primary_category%%', '%%name%%', '%%user_description%%', '%%pt_single%%', '%%pt_plural%%' ),
            'constraints' => array(
                'human_review_required' => true,
                'no_auto_save' => true,
                'write_reusable_templates_not_one_specific_resource' => true,
                'prefer_supported_template_variables_where_the_value_changes_per_resource' => true,
                'do_not_invent_prices_reviews_awards_or_claims' => true,
                'do_not_generate_urls' => true,
                'preserve_site_language' => true,
                'titles_should_be_concise' => true,
                'descriptions_should_be_useful_social_copy' => true,
            ),
        );
        $result = $this->client->compute( new ComputeRequest( 'seo.social-template.suggest.v1', $this->modelId(), $payload ) );
        return $this->sanitizeResult( $result->data(), $fields, $language );
    }

    /** @param array<string,mixed> $data @param array<int,string> $fields @return array<string,string> */
    private function sanitizeResult( array $data, array $fields, string $expectedLanguage ): array {
        $outerLanguage = isset( $data['language'] ) && is_scalar( $data['language'] ) ? $this->languageResolver->normalize( (string) $data['language'] ) : '';
        if ( isset( $data['template'] ) && is_array( $data['template'] ) ) { $data = $data['template']; }
        elseif ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) { $data = $data['settings']; }
        $returnedLanguage = isset( $data['language'] ) && is_scalar( $data['language'] ) ? $this->languageResolver->normalize( (string) $data['language'] ) : $outerLanguage;
        if ( '' !== $returnedLanguage && ! $this->languageResolver->equivalent( $returnedLanguage, $expectedLanguage ) ) {
            throw new ServiceError( esc_html__( 'Yablonsky AI returned social template suggestions in a different language. Nothing was accepted.', 'yablonsky-seo' ) );
        }
        $out = array();
        foreach ( $fields as $field ) {
            if ( ! isset( $data[ $field ] ) || ! is_scalar( $data[ $field ] ) ) { continue; }
            $max = false !== strpos( $field, 'description' ) ? 500 : 220;
            $value = trim( wp_strip_all_tags( (string) $data[ $field ], true ) );
            $value = $this->removeUnsupportedTemplateVariables( $value );
            if ( '' === $value ) { continue; }
            $out[ $field ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
        }
        if ( empty( $out ) ) {
            throw new ServiceError( esc_html__( 'Yablonsky AI returned no usable social template suggestions.', 'yablonsky-seo' ) );
        }
        return $out;
    }

    private function removeUnsupportedTemplateVariables( string $value ): string {
        $allowed = array( '%%title%%', '%%archive_title%%', '%%sitename%%', '%%sitedesc%%', '%%sep%%', '%%page%%', '%%excerpt%%', '%%excerpt_only%%', '%%term_title%%', '%%term_description%%', '%%category%%', '%%tag%%', '%%primary_category%%', '%%name%%', '%%user_description%%', '%%pt_single%%', '%%pt_plural%%' );
        return (string) preg_replace_callback( '/%%[A-Za-z0-9_-]+%%/', static function ( array $match ) use ( $allowed ): string { return in_array( $match[0], $allowed, true ) ? $match[0] : ''; }, $value );
    }

    private function modelId(): string {
        $models = $this->client->models();
        $selected = ( new AiSettings() )->metadataModelId();
        $requested = apply_filters( 'yablonsky_seo_ai_settings_model', $selected, $models );
        $requested = is_string( $requested ) ? sanitize_text_field( $requested ) : 'auto';
        foreach ( $models as $model ) { if ( $model->id() === $requested ) { return $model->id(); } }
        return 'auto';
    }

    private function boundedText( string $text, int $max ): string {
        $text = trim( wp_strip_all_tags( $text, true ) );
        $text = (string) preg_replace( '/\s+/u', ' ', $text );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
    }
}
