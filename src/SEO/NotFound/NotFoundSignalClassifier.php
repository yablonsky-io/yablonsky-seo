<?php
namespace Yablonsky\PerformanceSEO\SEO\NotFound;

use Yablonsky\PerformanceSEO\SEO\Redirects\RedirectSafetyPolicy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic path-only classifier for 404 monitor triage.
 *
 * It intentionally does not inspect IPs, user agents, referrers or query strings.
 * Classification is a UX/security-handoff signal, not a security verdict.
 */
final class NotFoundSignalClassifier {
    // v8 rechecks historical aggregates after the prior migration could
    // incorrectly mark a failed SQL batch as complete. Classification rules
    // are unchanged; only the maintenance replay is intentionally refreshed.
    public const VERSION = '8';
    public const SEO = 'seo';
    public const ACCESS = 'access';
    public const TECHNICAL = 'technical';
    public const PROBE = 'probe';

    public static function classify( string $path ): string {
        $detail = self::classifyDetailed( $path );
        return (string) $detail['signal'];
    }

    /** @return array{signal:string,reason:string,confidence:string} */
    public static function classifyDetailed( string $path ): array {
        $normalized = self::normalize( $path );
        if ( '' === $normalized ) { return self::detail( self::TECHNICAL, 'invalid-path', 'high' ); }

        // Generic account/authentication and administrative guesses are useful
        // security context but are not safe automatic SEO redirect suggestions.
        if ( preg_match( '#^/(?:account|admin-api|administration|administrator|app|auth|backoffice|backend|controlpanel|cpanel|forgot-password|graphiql|login|manage|management|mail|nacos|oauth|panel|verwaltung|phpmyadmin|portal|reset-password|secure|sign-in|signin|sign-up|signup|webadmin|webmail)/?$#', $normalized ) ) {
            return self::detail( self::ACCESS, 'access-surface', 'high' );
        }
        if ( preg_match( '#^/(?:rest/(?:login|logout)|grafana/login|oauth/authorize|manage|v1/admin|admin\.html|whm)(?:/|$)#', $normalized ) ) {
            return self::detail( self::ACCESS, 'access-surface', 'high' );
        }
        if ( preg_match( '#^/(?:administration|administrator|backend|controlpanel|cpanel|phpmyadmin|webadmin)(?:/|$)#', $normalized ) ) {
            return self::detail( self::ACCESS, 'access-namespace', 'high' );
        }
        if ( preg_match( '#(?:^|/)(?:login|signin|sign-in|auth)\.(?:php\d*|html?)\.?\d*(?:/|$)#', $normalized ) ) {
            return self::detail( self::ACCESS, 'access-file', 'high' );
        }

        // Root-level operational dictionary guesses. These are intentionally
        // non-SEO even when they use ordinary words; path-only triage must not
        // turn monitoring/status guesses into redirect recommendations.
        if ( preg_match( '#^/(?:ip|info|status|stats|system|version|query|resolve|ws|mon|monitor|monitoring|ueberwachung|kontrolle|vhosts|healthcheck|readiness|ready)/?$#', $normalized ) ) {
            return self::detail( self::TECHNICAL, 'operational-endpoint', 'high' );
        }

        // Strong infrastructure namespaces confirmed by field replay.
        if ( preg_match( '#^/(?:_active_tasks|_config|_nodes|_stats|_utils)(?:/|$)#', $normalized ) ) {
            return self::detail( self::PROBE, 'infrastructure-api', 'high' );
        }
        if ( preg_match( '#^/(?:grafana|kibana|prometheus|elasticsearch|terraform|src/main/resources|cvs|_darcs)(?:/|$)#i', $normalized ) ) {
            return self::detail( self::PROBE, 'infrastructure-namespace', 'high' );
        }
        if ( preg_match( '#^/(?:services/(?:mail|email)|mailer|mailgun|sendgrid|smtp)(?:/|$)#', $normalized ) ) {
            return self::detail( self::PROBE, 'credential-service-namespace', 'high' );
        }
        if ( preg_match( '#^/sites/default/files(?:/|$)#', $normalized ) ) {
            return self::detail( self::TECHNICAL, 'foreign-cms-resource', 'high' );
        }
        if ( preg_match( '#(?:^|/)\[(?:\.\.\.)?[^/\]]+\](?:/|$)|(?:^|/)\[\[\.\.\.[^/\]]+\]\](?:/|$)#', $normalized ) ) {
            return self::detail( self::TECHNICAL, 'framework-route-template', 'high' );
        }

        $kind = RedirectSafetyPolicy::classifyPath( $normalized );
        if ( RedirectSafetyPolicy::PROBE === $kind ) { return self::detail( self::PROBE, 'redirect-policy-probe', 'high' ); }
        if ( RedirectSafetyPolicy::TECHNICAL === $kind ) { return self::detail( self::TECHNICAL, 'redirect-policy-technical', 'high' ); }
        return self::detail( self::SEO, 'content-route', 'medium' );
    }

    /** @return array{signal:string,reason:string,confidence:string} */
    private static function detail( string $signal, string $reason, string $confidence ): array {
        return array( 'signal' => $signal, 'reason' => $reason, 'confidence' => $confidence );
    }

    public static function isRedirectable( string $path ): bool {
        return self::SEO === self::classify( $path );
    }

    public static function label( string $kind ): string {
        if ( self::ACCESS === $kind ) { return __( 'Access-like', 'yablonsky-seo' ); }
        if ( self::PROBE === $kind ) { return __( 'Probe-like', 'yablonsky-seo' ); }
        if ( self::TECHNICAL === $kind ) { return __( 'Technical', 'yablonsky-seo' ); }
        return __( 'SEO candidate', 'yablonsky-seo' );
    }

    private static function normalize( string $path ): string {
        $path = trim( $path );
        if ( '' === $path ) { return ''; }
        if ( preg_match( '#^https?://#i', $path ) ) {
            $parts = wp_parse_url( $path );
            if ( ! is_array( $parts ) ) { return ''; }
            $path = (string) ( $parts['path'] ?? '/' );
        }
        $path = rawurldecode( $path );
        if ( false !== strpos( $path, "\0" ) ) { return ''; }
        $path = '/' . ltrim( preg_replace( '#/+#', '/', $path ) ?: '', '/' );
        return strtolower( $path );
    }
}
