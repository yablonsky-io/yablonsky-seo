<?php
namespace Yablonsky\PerformanceSEO\AI\Settings;

use Yablonsky\PerformanceSEO\AI\AiClientResolver;
use Yablonsky\PerformanceSEO\AI\Config\AiSettings;
use Yablonsky\PerformanceSEO\AI\Contract\ComputeRequest;
use Yablonsky\PerformanceSEO\AI\Contract\ServiceError;
use Yablonsky\PerformanceSEO\AI\Contract\YablonskyAiClientInterface;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Review-only AI assistance for global site identity and homepage settings.
 * Suggestions never persist here; the admin form remains the save authority.
 */
final class SiteSettingsSuggestionService {
    /** @var YablonskyAiClientInterface */ private $client;
    /** @var ResourceLanguageResolver */ private $languageResolver;

    public function __construct( ?YablonskyAiClientInterface $client = null ) {
        $this->client = $client instanceof YablonskyAiClientInterface ? $client : ( new AiClientResolver() )->resolve();
        $this->languageResolver = new ResourceLanguageResolver();
    }

    public function isConfigured(): bool { return $this->client->isConfigured(); }

    /** @param array<int,string> $requestedFields @return array<string,string> */
    public function suggest( array $requestedFields ): array {
        if ( ! $this->client->isConfigured() ) {
            throw new ServiceError( esc_html__( 'Connect and verify a Yablonsky AI User API key before requesting site setup suggestions.', 'yablonsky-seo' ) );
        }
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $requestedFields = $this->sanitizeRequestedFields( $requestedFields, $settings );
        if ( empty( $requestedFields ) ) {
            throw new ServiceError( esc_html__( 'AI-eligible site settings are ready when requested.', 'yablonsky-seo' ) );
        }
        $language = $this->languageResolver->normalize( (string) get_bloginfo( 'language' ) );
        if ( '' === $language || 'auto' === $language || 'x-default' === $language ) { $language = 'en'; }

        $payload = array(
            'contract' => 'yseo.settings.suggest.v1',
            'language' => $language,
            'site' => array(
                'wordpress_name' => $this->boundedText( (string) get_bloginfo( 'name' ), 240 ),
                'wordpress_tagline' => $this->boundedText( (string) get_bloginfo( 'description' ), 500 ),
                'home_url' => esc_url_raw( home_url( '/' ), array( 'http', 'https' ) ),
                'homepage' => $this->homepageEvidence(),
                'recent_public_content' => $this->recentContentEvidence(),
            ),
            'current_settings' => $this->currentEvidence( $settings ),
            'requested_fields' => $requestedFields,
            'constraints' => array(
                'human_review_required' => true,
                'no_auto_save' => true,
                'evidence_only' => true,
                'do_not_invent_owner_identity' => true,
                'do_not_generate_external_urls' => true,
                'do_not_generate_verification_tokens' => true,
                'preserve_site_language' => true,
                'template_fields_use_only_supported_variables' => true,
                'search_title_variables' => array( '%%searchphrase%%', '%%sitename%%', '%%sitedesc%%', '%%sep%%', '%%page%%' ),
                'archive_title_variables' => array( '%%archive%%', '%%sitename%%', '%%sitedesc%%', '%%sep%%', '%%page%%' ),
                'rss_variables' => array( '%%POSTLINK%%', '%%BLOGLINK%%', '%%AUTHORLINK%%', '%%BLOGDESCLINK%%' ),
            ),
        );
        $result = $this->client->compute( new ComputeRequest( 'seo.settings.suggest.v1', $this->modelId(), $payload ) );
        return $this->sanitizeResult( $result->data(), $requestedFields, $language );
    }

    /** @param array<string,mixed> $settings @return array<int,string> */
    private function sanitizeRequestedFields( array $fields, array $settings ): array {
        $allowed = SettingsAuthoringRegistry::textFields();
        $clean = array();
        foreach ( $fields as $field ) {
            $field = sanitize_key( (string) $field );
            if ( in_array( $field, $allowed, true ) && ! in_array( $field, $clean, true ) ) { $clean[] = $field; }
        }
        $ownerType = isset( $settings['owner_type'] ) && 'person' === (string) $settings['owner_type'] ? 'person' : 'organization';
        if ( 'person' === $ownerType && empty( $settings['owner_name'] ) ) {
            $clean = array_values( array_diff( $clean, array( 'owner_name', 'owner_job_title' ) ) );
        }
        if ( 'organization' === $ownerType && empty( $settings['owner_name'] ) && '' === trim( (string) get_bloginfo( 'name' ) ) ) {
            $clean = array_values( array_diff( $clean, array( 'owner_name' ) ) );
        }
        if ( empty( $settings['owner_job_title'] ) ) {
            $clean = array_values( array_diff( $clean, array( 'owner_job_title' ) ) );
        }
        return array_slice( $clean, 0, 12 );
    }

    /** @param array<string,mixed> $data @param array<int,string> $requestedFields @return array<string,string> */
    private function sanitizeResult( array $data, array $requestedFields, string $expectedLanguage ): array {
        $outerLanguage = isset( $data['language'] ) && is_scalar( $data['language'] ) ? $this->languageResolver->normalize( (string) $data['language'] ) : '';
        if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) { $data = $data['settings']; }
        $aliases = array(
            'site_display_name' => array( 'site_display_name', 'site_name', 'display_name' ),
            'site_description' => array( 'site_description', 'description', 'tagline' ),
            'owner_name' => array( 'owner_name', 'entity_name', 'organization_name', 'person_name' ),
            'owner_job_title' => array( 'owner_job_title', 'job_title', 'role' ),
            'owner_description' => array( 'owner_description', 'entity_description', 'about' ),
            'owner_knows_about' => array( 'owner_knows_about', 'knows_about', 'expertise', 'topics' ),
            'home_title' => array( 'home_title', 'homepage_title', 'seo_title' ),
            'home_description' => array( 'home_description', 'homepage_description', 'meta_description' ),
            'home_og_title' => array( 'home_og_title', 'open_graph_title', 'og_title', 'social_title' ),
            'home_og_description' => array( 'home_og_description', 'open_graph_description', 'og_description', 'social_description' ),
            'home_twitter_title' => array( 'home_twitter_title', 'twitter_title', 'x_title', 'social_title' ),
            'home_twitter_description' => array( 'home_twitter_description', 'twitter_description', 'x_description', 'social_description' ),
            'search_title_template' => array( 'search_title_template', 'internal_search_title_template' ),
            'error_404_title_template' => array( 'error_404_title_template', 'not_found_title_template', '404_title_template' ),
            'date_archive_title_template' => array( 'date_archive_title_template', 'date_title_template' ),
            'post_format_archive_title_template' => array( 'post_format_archive_title_template', 'post_format_title_template' ),
            'rss_before_content' => array( 'rss_before_content', 'rss_before', 'feed_before_content' ),
            'rss_after_content' => array( 'rss_after_content', 'rss_after', 'feed_after_content' ),
        );
        $returnedLanguage = isset( $data['language'] ) && is_scalar( $data['language'] ) ? $this->languageResolver->normalize( (string) $data['language'] ) : $outerLanguage;
        if ( '' !== $returnedLanguage && ! $this->languageResolver->equivalent( $returnedLanguage, $expectedLanguage ) ) {
            throw new ServiceError( esc_html__( 'Yablonsky AI returned site setup suggestions in a different language. Nothing was accepted.', 'yablonsky-seo' ) );
        }
        $limits = array( 'site_display_name' => 240, 'site_description' => 700, 'owner_name' => 240, 'owner_job_title' => 240, 'owner_description' => 1200, 'owner_knows_about' => 1200, 'home_title' => 200, 'home_description' => 500, 'home_og_title' => 200, 'home_og_description' => 500, 'home_twitter_title' => 200, 'home_twitter_description' => 500, 'search_title_template' => 240, 'error_404_title_template' => 240, 'date_archive_title_template' => 240, 'post_format_archive_title_template' => 240, 'rss_before_content' => 900, 'rss_after_content' => 900 );
        $clean = array();
        foreach ( $requestedFields as $field ) {
            foreach ( $aliases[ $field ] as $alias ) {
                if ( ! isset( $data[ $alias ] ) || ! is_scalar( $data[ $alias ] ) ) { continue; }
                $value = trim( wp_strip_all_tags( (string) $data[ $alias ], true ) );
                if ( '' === $value ) { break; }
                $max = $limits[ $field ];
                $value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
                $clean[ $field ] = $this->sanitizeAuthoringTemplate( $field, $value );
                if ( '' === $clean[ $field ] ) { unset( $clean[ $field ] ); }
                break;
            }
        }
        if ( empty( $clean ) ) {
            throw new ServiceError( esc_html__( 'Yablonsky AI returned no evidence-safe site setup suggestions.', 'yablonsky-seo' ) );
        }
        return $clean;
    }

    private function sanitizeAuthoringTemplate( string $field, string $value ): string {
        $allowed = array();
        if ( 'search_title_template' === $field ) {
            $allowed = array( '%%searchphrase%%', '%%sitename%%', '%%sitedesc%%', '%%sep%%', '%%page%%' );
        } elseif ( in_array( $field, array( 'error_404_title_template', 'date_archive_title_template', 'post_format_archive_title_template' ), true ) ) {
            $allowed = array( '%%archive%%', '%%sitename%%', '%%sitedesc%%', '%%sep%%', '%%page%%' );
        } elseif ( in_array( $field, array( 'rss_before_content', 'rss_after_content' ), true ) ) {
            $allowed = array( '%%POSTLINK%%', '%%BLOGLINK%%', '%%AUTHORLINK%%', '%%BLOGDESCLINK%%' );
        } else {
            return $value;
        }
        return trim( (string) preg_replace_callback( '/%%[A-Za-z0-9_-]+%%/', static function ( array $match ) use ( $allowed ): string { return in_array( $match[0], $allowed, true ) ? $match[0] : ''; }, $value ) );
    }

    /** @param array<string,mixed> $settings @return array<string,string> */
    private function currentEvidence( array $settings ): array {
        $out = array();
        foreach ( array_merge( array( 'owner_type' ), SettingsAuthoringRegistry::textFields() ) as $key ) {
            $out[ $key ] = $this->boundedText( (string) ( $settings[ $key ] ?? '' ), 1200 );
        }
        return $out;
    }

    /** @return array<string,string|int> */
    private function homepageEvidence(): array {
        $pageId = (int) get_option( 'page_on_front', 0 );
        $post = $pageId > 0 ? get_post( $pageId ) : null;
        if ( ! $post instanceof \WP_Post ) {
            return array( 'id' => 0, 'title' => '', 'excerpt' => '', 'content' => '' );
        }
        return array(
            'id' => (int) $post->ID,
            'title' => $this->boundedText( (string) $post->post_title, 300 ),
            'excerpt' => $this->boundedText( (string) $post->post_excerpt, 800 ),
            'content' => $this->boundedText( strip_shortcodes( (string) $post->post_content ), 6000 ),
        );
    }

    /** @return array<int,array<string,string>> */
    private function recentContentEvidence(): array {
        $posts = get_posts( array(
            'post_type' => get_post_types( array( 'public' => true ), 'names' ),
            'post_status' => 'publish',
            'posts_per_page' => 8,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'suppress_filters' => false,
        ) );
        $rows = array();
        foreach ( $posts as $post ) {
            if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) { continue; }
            $rows[] = array( 'post_type' => (string) $post->post_type, 'title' => $this->boundedText( (string) $post->post_title, 240 ), 'excerpt' => $this->boundedText( (string) $post->post_excerpt, 420 ) );
            if ( count( $rows ) >= 8 ) { break; }
        }
        return $rows;
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
