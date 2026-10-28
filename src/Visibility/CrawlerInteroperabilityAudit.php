<?php
namespace Yablonsky\PerformanceSEO\Visibility;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only interoperability evidence for crawler policy layers.
 *
 * This class does not call Cloudflare or any other remote API. It only reports
 * local intent plus edge evidence visible on the current request, so Settings
 * never silently performs a network request.
 */
final class CrawlerInteroperabilityAudit {
    /** @param array<string,mixed> $settings @return array<string,mixed> */
    public static function build( array $settings ): array {
        $policy = CrawlerPermissionPolicy::fromSettings( $settings );
        $cfRay = self::serverHeader( 'HTTP_CF_RAY' );
        $cfConnectingIp = self::serverHeader( 'HTTP_CF_CONNECTING_IP' );
        // Client-provided request headers alone cannot authenticate a CDN/WAF hop.
        $cloudflareDetected = false;
        $mixedUseRisk = 'allow' === $policy->value( 'search' ) && 'disallow' === $policy->value( 'training' );

        return array(
            'policy' => $policy->all(),
            'cloudflare_detected' => $cloudflareDetected,
            'cloudflare_ray' => '', // Untrusted evidence is not exposed as verification.
            'external_reachability' => 'unknown_external',
            'mixed_use_review' => $mixedUseRisk,
            'ai_prefs_state' => 'emerging',
        );
    }

    private static function serverHeader( string $key ): string {
        $value = filter_input( INPUT_SERVER, $key, FILTER_UNSAFE_RAW );
        if ( ! is_string( $value ) && isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below; server evidence only.
            $value = wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below; server evidence only.
        }
        return is_string( $value ) ? sanitize_text_field( $value ) : '';
    }
}
