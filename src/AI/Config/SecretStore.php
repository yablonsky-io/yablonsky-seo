<?php
namespace Yablonsky\PerformanceSEO\AI\Config;

use Yablonsky\PerformanceSEO\AI\Contract\ServiceError;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Server-side encrypted storage for the account-scoped Yablonsky AI API key.
 * The raw credential is never stored in the public SEO settings array or sent to admin JavaScript.
 */
final class SecretStore {
    private const OPTION = 'yseo_ai_api_secret';

    public function has(): bool {
        return '' !== $this->get();
    }

    public function set( string $secret ): void {
        $secret = trim( $secret );
        if ( '' === $secret ) {
            $this->clear();
            return;
        }
        $key = $this->encryptionKey();
        $envelope = array();
        if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = sodium_crypto_secretbox( $secret, $nonce, $key );
            $envelope = array(
                'v' => 1,
                'alg' => 'sodium-secretbox',
                'nonce' => base64_encode( $nonce ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary envelope encoding, not obfuscation.
                'cipher' => base64_encode( $cipher ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary envelope encoding, not obfuscation.
            );
        } elseif ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) ) {
            $iv = random_bytes( 12 );
            $tag = '';
            $cipher = openssl_encrypt( $secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
            if ( false === $cipher ) {
                throw new ServiceError( esc_html__( 'Yablonsky AI API key encryption failed.', 'yablonsky-seo' ) );
            }
            $envelope = array(
                'v' => 1,
                'alg' => 'aes-256-gcm',
                'iv' => base64_encode( $iv ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary envelope encoding, not obfuscation.
                'tag' => base64_encode( $tag ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary envelope encoding, not obfuscation.
                'cipher' => base64_encode( $cipher ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary envelope encoding, not obfuscation.
            );
        } else {
            throw new ServiceError( esc_html__( 'This server does not provide a supported encryption primitive for storing the Yablonsky AI API key.', 'yablonsky-seo' ) );
        }
        update_option( self::OPTION, wp_json_encode( $envelope ), false );
    }

    public function get(): string {
        $raw = get_option( self::OPTION, '' );
        if ( ! is_string( $raw ) || '' === $raw ) {
            return '';
        }
        $envelope = json_decode( $raw, true );
        if ( ! is_array( $envelope ) || empty( $envelope['alg'] ) || empty( $envelope['cipher'] ) ) {
            return '';
        }
        $key = $this->encryptionKey();
        $cipher = base64_decode( (string) $envelope['cipher'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary envelope decoding.
        if ( false === $cipher ) {
            return '';
        }
        if ( 'sodium-secretbox' === $envelope['alg'] && function_exists( 'sodium_crypto_secretbox_open' ) ) {
            $nonce = isset( $envelope['nonce'] ) ? base64_decode( (string) $envelope['nonce'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary envelope decoding.
            if ( false === $nonce ) { return ''; }
            $plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
            return false === $plain ? '' : (string) $plain;
        }
        if ( 'aes-256-gcm' === $envelope['alg'] && function_exists( 'openssl_decrypt' ) ) {
            $iv = isset( $envelope['iv'] ) ? base64_decode( (string) $envelope['iv'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary envelope decoding.
            $tag = isset( $envelope['tag'] ) ? base64_decode( (string) $envelope['tag'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary envelope decoding.
            if ( false === $iv || false === $tag ) { return ''; }
            $plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
            return false === $plain ? '' : (string) $plain;
        }
        return '';
    }

    public function mask(): string {
        $secret = $this->get();
        if ( '' === $secret ) { return ''; }
        $length = strlen( $secret );
        if ( $length <= 8 ) { return str_repeat( '•', max( 4, $length ) ); }
        return substr( $secret, 0, 4 ) . str_repeat( '•', 8 ) . substr( $secret, -4 );
    }

    public function clear(): void {
        delete_option( self::OPTION );
    }

    private function encryptionKey(): string {
        $material = function_exists( 'wp_salt' ) ? trim( (string) wp_salt( 'auth' ) ) . '|' . trim( (string) wp_salt( 'secure_auth' ) ) : '';
        $material = trim( $material, "| \t\n\r\0\x0B" );
        if ( '' === $material && defined( 'AUTH_KEY' ) ) {
            $material = trim( (string) AUTH_KEY );
        }
        if ( '' === $material ) {
            throw new ServiceError( esc_html__( 'WordPress security salts are unavailable; Yablonsky AI credentials cannot be stored safely.', 'yablonsky-seo' ) );
        }
        return hash( 'sha256', 'yseo-ai-secret|' . $material, true );
    }
}
