<?php
namespace Yablonsky\PerformanceSEO\AI\Config;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AiSettings {
    private const OPTION = 'yseo_ai_settings';
    private const ACCOUNT_CACHE_OPTION = 'yseo_ai_account_cache';
    private const ACCOUNT_CACHE_TTL = 21600;

    /** @var SecretStore */ private $secrets;

    public function __construct( ?SecretStore $secrets = null ) {
        $this->secrets = $secrets instanceof SecretStore ? $secrets : new SecretStore();
    }

    public function isConfigured(): bool { return $this->secrets->has(); }
    public function apiKey(): string { return $this->secrets->get(); }
    public function maskedKey(): string { return $this->secrets->mask(); }

    /** @return array<string,mixed> */
    public function all(): array {
        $settings = get_option( self::OPTION, array() );
        return is_array( $settings ) ? $settings : array();
    }

    public function workspaceId(): string { return (string) ( $this->all()['workspace_id'] ?? '' ); }
    public function modelId(): string {
        $model = trim( (string) ( $this->all()['model_id'] ?? 'auto' ) );
        return '' === $model ? 'auto' : $model;
    }
    public function metadataModelOverride(): string {
        return trim( (string) ( $this->all()['metadata_model_id'] ?? '' ) );
    }
    public function metadataModelId(): string {
        $model = $this->metadataModelOverride();
        return '' === $model ? $this->modelId() : $model;
    }
    public function imageModelId(): string {
        return trim( (string) ( $this->all()['image_model_id'] ?? '' ) );
    }
    public function reasoning(): string {
        $reasoning = sanitize_key( (string) ( $this->all()['reasoning'] ?? 'auto' ) );
        return in_array( $reasoning, array( 'auto', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max' ), true ) ? $reasoning : 'auto';
    }
    public function creativity(): float {
        $value = (float) ( $this->all()['creativity'] ?? 0.4 );
        return max( 0.0, min( 2.0, round( $value, 1 ) ) );
    }

    /** @param array<string,mixed> $values */
    public function savePreferences( array $values ): void {
        $workspace = isset( $values['workspace_id'] ) ? sanitize_text_field( (string) $values['workspace_id'] ) : '';
        $model = isset( $values['model_id'] ) ? sanitize_text_field( (string) $values['model_id'] ) : 'auto';
        if ( '' === $model ) { $model = 'auto'; }
        $metadataModel = isset( $values['metadata_model_id'] ) ? sanitize_text_field( (string) $values['metadata_model_id'] ) : '';
        $imageModel = isset( $values['image_model_id'] ) ? sanitize_text_field( (string) $values['image_model_id'] ) : '';
        $reasoning = isset( $values['reasoning'] ) ? sanitize_key( (string) $values['reasoning'] ) : 'auto';
        if ( ! in_array( $reasoning, array( 'auto', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max' ), true ) ) { $reasoning = 'auto'; }
        $creativity = isset( $values['creativity'] ) ? (float) $values['creativity'] : 0.4;
        $creativity = max( 0.0, min( 2.0, round( $creativity, 1 ) ) );
        update_option(
            self::OPTION,
            array(
                'workspace_id' => $workspace,
                'model_id' => $model,
                'metadata_model_id' => $metadataModel,
                'image_model_id' => $imageModel,
                'reasoning' => $reasoning,
                'creativity' => $creativity,
            ),
            false
        );
    }

    public function saveApiKey( string $apiKey ): void {
        $this->secrets->set( $apiKey );
        $this->clearAccountCache();
    }

    public function disconnect(): void {
        $this->secrets->clear();
        delete_option( self::OPTION );
        $this->clearAccountCache();
    }

    /** @param array<string,mixed> $account */
    public function cacheAccount( array $account ): void {
        update_option(
            self::ACCOUNT_CACHE_OPTION,
            array(
                'verified_at' => time(),
                'expires_at' => time() + self::ACCOUNT_CACHE_TTL,
                'account' => $account,
            ),
            false
        );
    }

    /** @return array<string,mixed> */
    public function cachedAccount( bool $allowExpired = true ): array {
        $cache = get_option( self::ACCOUNT_CACHE_OPTION, array() );
        if ( ! is_array( $cache ) || ! isset( $cache['account'] ) || ! is_array( $cache['account'] ) ) { return array(); }
        if ( ! $allowExpired && isset( $cache['expires_at'] ) && (int) $cache['expires_at'] < time() ) { return array(); }
        return $cache['account'];
    }

    public function lastVerifiedAt(): int {
        $cache = get_option( self::ACCOUNT_CACHE_OPTION, array() );
        return is_array( $cache ) && isset( $cache['verified_at'] ) ? (int) $cache['verified_at'] : 0;
    }

    public function cacheIsFresh(): bool {
        $cache = get_option( self::ACCOUNT_CACHE_OPTION, array() );
        return is_array( $cache ) && isset( $cache['expires_at'] ) && (int) $cache['expires_at'] >= time();
    }

    public function clearAccountCache(): void { delete_option( self::ACCOUNT_CACHE_OPTION ); }

    /** @return array<int,array<string,mixed>> */
    public function workspaces(): array {
        $account = $this->cachedAccount();
        $items = array();
        $seen = array();
        foreach ( array( 'workspace', 'workspaces', 'owned_workspaces' ) as $key ) {
            $raw = $account[ $key ] ?? array();
            if ( 'workspace' === $key ) { $raw = empty( $raw ) ? array() : array( $raw ); }
            if ( ! is_array( $raw ) ) { continue; }
            foreach ( $raw as $workspace ) {
                if ( ! is_array( $workspace ) || empty( $workspace['id'] ) ) { continue; }
                $id = (string) $workspace['id'];
                if ( isset( $seen[ $id ] ) ) { continue; }
                $seen[ $id ] = true;
                $items[] = $workspace;
            }
        }
        return $items;
    }

    /** @return array<string,mixed> */
    public function selectedWorkspace(): array {
        $workspaces = $this->workspaces();
        $selected = $this->workspaceId();
        foreach ( $workspaces as $workspace ) {
            if ( '' !== $selected && isset( $workspace['id'] ) && hash_equals( $selected, (string) $workspace['id'] ) ) { return $workspace; }
        }
        $account = $this->cachedAccount();
        if ( isset( $account['workspace'] ) && is_array( $account['workspace'] ) ) { return $account['workspace']; }
        return ! empty( $workspaces ) ? $workspaces[0] : array();
    }
}
