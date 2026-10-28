<?php
namespace Yablonsky\PerformanceSEO\Visibility;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Vendor-neutral intent layer for crawler/content-use permissions.
 *
 * The layer intentionally separates human/search discovery, generative-answer
 * participation, user-directed agents and model training. Concrete providers
 * remain adapters: robots.txt tokens, provider consoles, meta/X-Robots controls
 * or future portable standards can map onto the same intent without changing
 * the admin model.
 */
final class CrawlerPermissionPolicy {
    /** @var array<string,string> */
    private $values;

    /** @param array<string,string> $values */
    private function __construct( array $values ) { $this->values = $values; }

    /** @param array<string,mixed> $settings */
    public static function fromSettings( array $settings ): self {
        $legacyAgent = self::normalize( $settings['ai_retrieval_access'] ?? 'allow', 'allow' );
        $values = array(
            'search'   => self::normalize( $settings['ai_search_access'] ?? 'allow', 'allow' ),
            'answers'  => self::normalize( $settings['ai_answers_access'] ?? 'inherit', 'inherit' ),
            'agent'    => self::normalize( $settings['ai_agent_access'] ?? $legacyAgent, $legacyAgent ),
            'training' => self::normalize( $settings['ai_training_access'] ?? 'allow', 'allow' ),
            'ads'      => self::normalize( $settings['ai_ads_access'] ?? 'inherit', 'inherit' ),
        );
        $filtered = apply_filters( 'yablonsky_seo_crawler_permission_policy_v1', $values, $settings );
        if ( is_array( $filtered ) ) {
            foreach ( array_keys( $values ) as $dimension ) {
                if ( array_key_exists( $dimension, $filtered ) ) {
                    $values[ $dimension ] = self::normalize( $filtered[ $dimension ], $values[ $dimension ] );
                }
            }
        }
        return new self( $values );
    }

    /** @return array<string,string> */
    public function all(): array { return $this->values; }

    public function value( string $dimension ): string {
        $dimension = sanitize_key( $dimension );
        if ( 'retrieval' === $dimension ) { $dimension = 'agent'; }
        return $this->values[ $dimension ] ?? 'inherit';
    }

    public function permits( string $dimension ): bool { return 'allow' === $this->value( $dimension ); }
    public function denies( string $dimension ): bool { return 'disallow' === $this->value( $dimension ); }

    /** @param mixed $value */
    private static function normalize( $value, string $fallback ): string {
        $value = sanitize_key( (string) $value );
        return in_array( $value, array( 'allow', 'disallow', 'inherit' ), true ) ? $value : $fallback;
    }
}
