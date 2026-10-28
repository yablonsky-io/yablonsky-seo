<?php
namespace Yablonsky\PerformanceSEO\Settings;

use Yablonsky\PerformanceSEO\AI\Config\AiSettings;
use Yablonsky\PerformanceSEO\SEO\Social\SocialTemplateResolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Portable, secret-free YSEO site configuration.
 *
 * The contract intentionally excludes content metadata, redirects, 404 evidence,
 * migration/takeover authority, caches, jobs and all API credentials.
 */
final class SettingsPortability {
    public const SCHEMA = 'yablonsky.seo.settings-portable.v1';
    public const MAX_JSON_BYTES = 262144;

    private const BACKUP_OPTION = 'yseo_settings_portability_backup_v1';
    private const LAST_IMPORT_OPTION = 'yseo_settings_portability_last_import_v1';

    /** @var array<int,string> */
    private const TEXT_KEYS = array(
        'site_display_name',
        'owner_name',
        'owner_job_title',
        'home_title',
        'home_og_title',
        'home_twitter_title',
        'search_title_template',
        'error_404_title_template',
        'date_archive_title_template',
        'post_format_archive_title_template',
    );

    /** @var array<int,string> */
    private const TEXTAREA_KEYS = array(
        'site_description',
        'owner_description',
        'owner_same_as',
        'owner_knows_about',
        'home_description',
        'home_og_description',
        'home_twitter_description',
    );

    /** @var array<int,string> */
    private const URL_KEYS = array(
        'owner_logo_url',
        'owner_image_url',
        'default_social_image',
        'home_search_image',
        'home_og_image',
        'home_twitter_image',
        'merchant_return_policy_url',
    );

    /** @var array<int,string> */
    private const VERIFICATION_KEYS = array(
        'verification_google',
        'verification_bing',
        'verification_pinterest',
        'verification_yandex',
        'verification_baidu',
    );

    /** @var array<int,string> */
    private const BOOLEAN_KEYS = array(
        'aeo_question_heading_guidance',
        'aeo_compact_structure_guidance',
        'aeo_faq_guardrail',
        'ai_crawler_policy_enabled',
        'agent_markdown_enabled',
        'agent_scoped_llms_enabled',
        'agent_sitemap_extension_enabled',
        'crawl_remove_shortlinks',
        'crawl_remove_rest_links',
        'crawl_remove_rsd_wlw',
        'crawl_remove_oembed_links',
        'crawl_remove_generator',
        'crawl_remove_pingback_header',
        'crawl_remove_powered_by_header',
        'crawl_remove_emoji',
        'crawl_disable_global_feeds',
        'crawl_disable_comments_feeds',
        'crawl_disable_post_comments_feeds',
        'crawl_disable_author_feeds',
        'crawl_disable_post_type_feeds',
        'crawl_disable_category_feeds',
        'crawl_disable_tag_feeds',
        'crawl_disable_taxonomy_feeds',
        'crawl_disable_search_feeds',
        'crawl_disable_atom_rdf_feeds',
        'crawl_disallow_wp_json',
        'crawl_search_noindex',
        'crawl_search_redirect_pretty',
        'crawl_search_filter_special',
        'crawl_search_filter_spam',
        'crawl_clean_tracking_params',
        'date_archives_noindex',
        'post_format_archives_noindex',
        'media_redirect_attachment_pages',
        'indexnow_enabled',
    );

    /** @return array<string,mixed> */
    public function exportPackage(): array {
        $payload = $this->currentPortablePayload();
        $encoded = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        return array(
            'schema'          => self::SCHEMA,
            'schema_version'  => 1,
            'plugin_version'  => defined( 'YSEO_VERSION' ) ? (string) YSEO_VERSION : '',
            'exported_at_utc' => gmdate( 'c' ),
            'source_site'     => home_url( '/' ),
            'payload_sha256'  => is_string( $encoded ) ? hash( 'sha256', $encoded ) : '',
            'payload'         => $payload,
            'excluded'        => array(
                'api_credentials',
                'output_authority_and_migration_state',
                'post_term_user_metadata',
                'redirect_inventory',
                '404_monitor_evidence',
                'background_jobs_caches_and_runtime_evidence',
                'local_location_content',
            ),
        );
    }

    /** @return array<string,mixed> */
    public function currentPortablePayload(): array {
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $ai = ( new AiSettings() )->all();

        return array(
            'public_settings' => $this->sanitizePublicSettings( $settings ),
            'native_search_signals_enabled' => '0' !== (string) get_option( 'yseo_native_search_signals_enabled', '1' ),
            'ai_generation_preferences' => array(
                'reasoning'  => $this->sanitizeReasoning( (string) ( $ai['reasoning'] ?? 'auto' ) ),
                'creativity' => $this->sanitizeCreativity( $ai['creativity'] ?? 0.4 ),
            ),
        );
    }

    /**
     * @return array{changed:int,source_site:string,source_version:string,imported_at_utc:string}
     */
    public function importJson( string $json ): array {
        $json = trim( $json );
        if ( '' === $json || strlen( $json ) > self::MAX_JSON_BYTES ) {
            throw new \RuntimeException( 'invalid-size' );
        }

        $package = json_decode( $json, true, 64 );
        if ( ! is_array( $package ) || self::SCHEMA !== (string) ( $package['schema'] ?? '' ) ) {
            throw new \RuntimeException( 'invalid-schema' );
        }
        // Reject future/unknown export contracts before any backup or site write.
        if ( 1 !== ( $package['schema_version'] ?? null ) ) {
            throw new \RuntimeException( 'invalid-schema' );
        }
        $payload = isset( $package['payload'] ) && is_array( $package['payload'] ) ? $package['payload'] : null;
        if ( ! is_array( $payload ) ) {
            throw new \RuntimeException( 'missing-payload' );
        }

        // A portable v1 export always carries these three sections. Reject
        // truncated or wrong-typed payloads before taking a backup or writing
        // any site options; silently accepting them creates partial imports.
        if ( ! isset( $payload['public_settings'] ) || ! is_array( $payload['public_settings'] )
            || ! array_key_exists( 'native_search_signals_enabled', $payload ) || ! is_bool( $payload['native_search_signals_enabled'] )
            || ! isset( $payload['ai_generation_preferences'] ) || ! is_array( $payload['ai_generation_preferences'] )
            || ! array_key_exists( 'reasoning', $payload['ai_generation_preferences'] ) || ! is_string( $payload['ai_generation_preferences']['reasoning'] )
            || ! array_key_exists( 'creativity', $payload['ai_generation_preferences'] ) || ! is_numeric( $payload['ai_generation_preferences']['creativity'] ) ) {
            throw new \RuntimeException( 'invalid-payload' );
        }

        // Portable public settings are scalar except for the documented social
        // templates map. Reject nested values early rather than converting
        // arrays to the literal string "Array" during sanitization.
        foreach ( $payload['public_settings'] as $key => $value ) {
            if ( 'social_templates' === $key ) {
                if ( ! is_array( $value ) ) {
                    throw new \RuntimeException( 'invalid-payload' );
                }
                // Social templates are the only nested public setting. Validate
                // their documented group > scope > field shape before sanitizing:
                // malformed rows must not be silently dropped on import.
                foreach ( $value as $group => $scopes ) {
                    if ( ! in_array( $group, array( 'singular', 'archive', 'taxonomy', 'special' ), true ) || ! is_array( $scopes ) ) {
                        throw new \RuntimeException( 'invalid-payload' );
                    }
                    foreach ( $scopes as $scope => $fields ) {
                        if ( ! is_string( $scope ) || '' === $scope || sanitize_key( $scope ) !== $scope || ! is_array( $fields ) ) {
                            throw new \RuntimeException( 'invalid-payload' );
                        }
                        foreach ( $fields as $fieldName => $fieldValue ) {
                            if ( ! is_string( $fieldName ) || ! in_array( $fieldName, array( 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image' ), true ) || ! is_scalar( $fieldValue ) ) {
                                throw new \RuntimeException( 'invalid-payload' );
                            }
                        }
                    }
                }
            } elseif ( ! is_scalar( $value ) && null !== $value ) {
                throw new \RuntimeException( 'invalid-payload' );
            }
        }

        $expectedHash = strtolower( trim( (string) ( $package['payload_sha256'] ?? '' ) ) );
        // Every v1 export includes a digest. A missing or malformed digest must
        // not silently disable integrity verification during import.
        if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expectedHash ) ) {
            throw new \RuntimeException( 'integrity-mismatch' );
        }
        $encoded = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $actualHash = is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
        if ( '' === $actualHash || ! hash_equals( $expectedHash, $actualHash ) ) {
            throw new \RuntimeException( 'integrity-mismatch' );
        }

        $before = $this->currentPortablePayload();
        $this->saveBackup( $before );
        try {
            $after = $this->applyPayload( $payload, $before );
            $changed = $this->changedLeafCount( $before, $after );
            $meta = array(
                'changed'         => $changed,
                'source_site'     => esc_url_raw( (string) ( $package['source_site'] ?? '' ), array( 'http', 'https' ) ),
                'source_version'  => sanitize_text_field( (string) ( $package['plugin_version'] ?? '' ) ),
                'imported_at_utc' => gmdate( 'c' ),
            );
            update_option( self::LAST_IMPORT_OPTION, $meta, false );
            if ( get_option( self::LAST_IMPORT_OPTION, array() ) !== $meta ) {
                throw new \RuntimeException( 'write-failed' );
            }
            return $meta;
        } catch ( \RuntimeException $error ) {
            // Best-effort immediate recovery. If storage remains unavailable,
            // the verified backup is retained for a later manual retry.
            try {
                $this->applyPayload( $before, $this->currentPortablePayload(), true );
            } catch ( \RuntimeException $recoveryError ) {
                // Preserve the original write error and the rollback snapshot.
            }
            throw $error;
        }
    }

    public function restoreLastBackup(): bool {
        $backup = get_option( self::BACKUP_OPTION, array() );
        if ( ! is_array( $backup ) || empty( $backup['payload'] ) || ! is_array( $backup['payload'] ) ) {
            return false;
        }
        $current = $this->currentPortablePayload();
        try {
            $this->applyPayload( $backup['payload'], $current, true );
        } catch ( \RuntimeException $error ) {
            // Keep the last-known-good snapshot available for another restore
            // attempt if even one WordPress option write was rejected.
            return false;
        }
        delete_option( self::BACKUP_OPTION );
        return ! $this->hasBackup();
    }

    public function hasBackup(): bool {
        $backup = get_option( self::BACKUP_OPTION, array() );
        return is_array( $backup ) && ! empty( $backup['payload'] ) && is_array( $backup['payload'] );
    }

    /** @return array<string,mixed> */
    public function backupMeta(): array {
        $backup = get_option( self::BACKUP_OPTION, array() );
        if ( ! is_array( $backup ) ) {
            return array();
        }
        return array(
            'created_at_utc' => sanitize_text_field( (string) ( $backup['created_at_utc'] ?? '' ) ),
            'plugin_version' => sanitize_text_field( (string) ( $backup['plugin_version'] ?? '' ) ),
        );
    }

    /** @return array<string,mixed> */
    public function lastImportMeta(): array {
        $meta = get_option( self::LAST_IMPORT_OPTION, array() );
        return is_array( $meta ) ? $meta : array();
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $before @return array<string,mixed> */
    private function applyPayload( array $payload, array $before, bool $restore = false ): array {
        // Import may only touch whitelisted portable keys. Preserve any other
        // site-specific/third-party values already stored in the same option.
        $public = get_option( 'yps_public_settings', array() );
        $public = is_array( $public ) ? $public : array();
        $incomingPublic = isset( $payload['public_settings'] ) && is_array( $payload['public_settings'] ) ? $this->sanitizePublicSettings( $payload['public_settings'] ) : array();
        if ( $restore ) {
            // A proper rollback removes newly imported portable fields that
            // were absent before import, without removing unrelated keys.
            foreach ( array_keys( $this->sanitizePublicSettings( $public ) ) as $portableKey ) {
                unset( $public[ $portableKey ] );
            }
        }
        $public = array_merge( $public, $incomingPublic );
        update_option( 'yps_public_settings', $public, false );
        // update_option() returning false can mean "unchanged" or "SQL failed".
        // Confirm the actual stored value rather than relying on that boolean.
        if ( get_option( 'yps_public_settings', array() ) !== $public ) {
            throw new \RuntimeException( 'write-failed' );
        }

        if ( array_key_exists( 'native_search_signals_enabled', $payload ) ) {
            $enabled = ! empty( $payload['native_search_signals_enabled'] ) ? '1' : '0';
            update_option( 'yseo_native_search_signals_enabled', $enabled, false );
            if ( (string) get_option( 'yseo_native_search_signals_enabled', '1' ) !== $enabled ) {
                throw new \RuntimeException( 'write-failed' );
            }
        }

        if ( isset( $payload['ai_generation_preferences'] ) && is_array( $payload['ai_generation_preferences'] ) ) {
            $aiSettings = new AiSettings();
            $current = $aiSettings->all();
            $prefs = $payload['ai_generation_preferences'];
            $newPreferences = array(
                'workspace_id'      => (string) ( $current['workspace_id'] ?? '' ),
                'model_id'          => (string) ( $current['model_id'] ?? 'auto' ),
                'metadata_model_id' => (string) ( $current['metadata_model_id'] ?? '' ),
                'image_model_id'    => (string) ( $current['image_model_id'] ?? '' ),
                'reasoning'         => $this->sanitizeReasoning( (string) ( $prefs['reasoning'] ?? ( $current['reasoning'] ?? 'auto' ) ) ),
                'creativity'        => $this->sanitizeCreativity( $prefs['creativity'] ?? ( $current['creativity'] ?? 0.4 ) ),
            );
            $aiSettings->savePreferences( $newPreferences );
            $storedPreferences = $aiSettings->all();
            foreach ( $newPreferences as $name => $expected ) {
                if ( ! array_key_exists( $name, $storedPreferences ) || $storedPreferences[ $name ] !== $expected ) {
                    throw new \RuntimeException( 'write-failed' );
                }
            }
        }

        return $this->currentPortablePayload();
    }

    /** @param array<string,mixed> $payload */
    private function saveBackup( array $payload ): void {
        $backup = array(
            'created_at_utc' => gmdate( 'c' ),
            'plugin_version' => defined( 'YSEO_VERSION' ) ? (string) YSEO_VERSION : '',
            'payload'        => $payload,
        );
        update_option( self::BACKUP_OPTION, $backup, false );
        if ( get_option( self::BACKUP_OPTION, array() ) !== $backup ) {
            // Never begin a partial import without a verified rollback snapshot.
            throw new \RuntimeException( 'write-failed' );
        }
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function sanitizePublicSettings( array $raw ): array {
        $out = array();
        foreach ( self::TEXT_KEYS as $key ) {
            if ( array_key_exists( $key, $raw ) ) {
                $out[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
            }
        }
        foreach ( self::TEXTAREA_KEYS as $key ) {
            if ( array_key_exists( $key, $raw ) ) {
                $out[ $key ] = sanitize_textarea_field( (string) $raw[ $key ] );
            }
        }
        foreach ( self::URL_KEYS as $key ) {
            if ( array_key_exists( $key, $raw ) ) {
                $out[ $key ] = esc_url_raw( (string) $raw[ $key ], array( 'http', 'https' ) );
            }
        }
        foreach ( self::VERIFICATION_KEYS as $key ) {
            if ( array_key_exists( $key, $raw ) ) {
                $value = sanitize_text_field( (string) $raw[ $key ] );
                $out[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 512, 'UTF-8' ) : substr( $value, 0, 512 );
            }
        }
        foreach ( self::BOOLEAN_KEYS as $key ) {
            if ( array_key_exists( $key, $raw ) ) {
                $out[ $key ] = $this->sanitizeBooleanFlag( $raw[ $key ] ) ? '1' : '';
            }
        }

        if ( array_key_exists( 'owner_type', $raw ) ) {
            $out['owner_type'] = 'person' === sanitize_key( (string) $raw['owner_type'] ) ? 'person' : 'organization';
        }
        if ( array_key_exists( 'aeo_analysis_profile', $raw ) ) {
            $profile = sanitize_key( (string) $raw['aeo_analysis_profile'] );
            $out['aeo_analysis_profile'] = in_array( $profile, array( 'balanced', 'concise', 'extended' ), true ) ? $profile : 'balanced';
        }
        foreach ( array( 'ai_search_access' => 'allow', 'ai_answers_access' => 'inherit', 'ai_agent_access' => 'allow', 'ai_retrieval_access' => 'allow', 'ai_training_access' => 'allow', 'ai_ads_access' => 'inherit' ) as $key => $fallback ) {
            if ( array_key_exists( $key, $raw ) ) {
                $policy = sanitize_key( (string) $raw[ $key ] );
                $out[ $key ] = in_array( $policy, array( 'allow', 'disallow', 'inherit' ), true ) ? $policy : $fallback;
            }
        }
        if ( array_key_exists( 'crawl_search_max_chars', $raw ) ) {
            $out['crawl_search_max_chars'] = (string) min( 200, max( 20, (int) $raw['crawl_search_max_chars'] ) );
        }
        if ( array_key_exists( 'media_redirect_target', $raw ) ) {
            $out['media_redirect_target'] = 'parent' === sanitize_key( (string) $raw['media_redirect_target'] ) ? 'parent' : 'file';
        }
        foreach ( array( 'rss_before_content', 'rss_after_content' ) as $key ) {
            if ( array_key_exists( $key, $raw ) ) {
                $out[ $key ] = trim( wp_kses_post( (string) $raw[ $key ] ) );
            }
        }
        if ( isset( $raw['social_templates'] ) && is_array( $raw['social_templates'] ) ) {
            $out['social_templates'] = SocialTemplateResolver::sanitize( $raw['social_templates'] );
        }

        ksort( $out );
        return $out;
    }

    /** @param mixed $value */
    private function sanitizeBooleanFlag( $value ): bool {
        if ( true === $value || 1 === $value || 1.0 === $value ) {
            return true;
        }
        if ( is_string( $value ) ) {
            return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
        }
        return false;
    }

    private function sanitizeReasoning( string $value ): string {
        $value = sanitize_key( $value );
        return in_array( $value, array( 'auto', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max' ), true ) ? $value : 'auto';
    }

    /** @param mixed $value */
    private function sanitizeCreativity( $value ): float {
        $number = is_numeric( $value ) ? (float) $value : 0.4;
        return max( 0.0, min( 2.0, round( $number, 1 ) ) );
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function changedLeafCount( array $before, array $after ): int {
        $flatBefore = $this->flatten( $before );
        $flatAfter = $this->flatten( $after );
        $keys = array_unique( array_merge( array_keys( $flatBefore ), array_keys( $flatAfter ) ) );
        $changed = 0;
        foreach ( $keys as $key ) {
            if ( ( $flatBefore[ $key ] ?? null ) !== ( $flatAfter[ $key ] ?? null ) ) {
                ++$changed;
            }
        }
        return $changed;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function flatten( array $value, string $prefix = '' ): array {
        $out = array();
        foreach ( $value as $key => $item ) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;
            if ( is_array( $item ) ) {
                $out = array_merge( $out, $this->flatten( $item, $path ) );
            } else {
                $out[ $path ] = $item;
            }
        }
        return $out;
    }
}
