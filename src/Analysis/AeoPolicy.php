<?php
namespace Yablonsky\PerformanceSEO\Analysis;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Global AEO analysis guidance.
 *
 * This policy only tunes deterministic local editor heuristics. It never changes
 * public content, schema or crawler output and it is not a ranking/citation model.
 */
final class AeoPolicy {
    public const VERSION = '1.0.0';
    public const DEFAULT_PROFILE = 'balanced';

    /** @var string */ private $profile;
    /** @var bool */ private $questionHeadingGuidance;
    /** @var bool */ private $compactStructureGuidance;
    /** @var bool */ private $faqGuardrail;

    private function __construct( string $profile, bool $questionHeadingGuidance, bool $compactStructureGuidance, bool $faqGuardrail ) {
        $this->profile = $profile;
        $this->questionHeadingGuidance = $questionHeadingGuidance;
        $this->compactStructureGuidance = $compactStructureGuidance;
        $this->faqGuardrail = $faqGuardrail;
    }

    /** @param array<string,mixed> $settings */
    public static function fromSettings( array $settings ): self {
        $profile = isset( $settings['aeo_analysis_profile'] ) && is_scalar( $settings['aeo_analysis_profile'] ) ? strtolower( trim( (string) $settings['aeo_analysis_profile'] ) ) : self::DEFAULT_PROFILE;
        if ( ! isset( self::profiles()[ $profile ] ) ) {
            $profile = self::DEFAULT_PROFILE;
        }

        return new self(
            $profile,
            self::enabledByDefault( $settings, 'aeo_question_heading_guidance' ),
            self::enabledByDefault( $settings, 'aeo_compact_structure_guidance' ),
            self::enabledByDefault( $settings, 'aeo_faq_guardrail' )
        );
    }

    public function profile(): string { return $this->profile; }
    public function questionHeadingGuidance(): bool { return $this->questionHeadingGuidance; }
    public function compactStructureGuidance(): bool { return $this->compactStructureGuidance; }
    public function faqGuardrail(): bool { return $this->faqGuardrail; }

    /** @return array<string,int> */
    public function thresholds(): array { return self::profiles()[ $this->profile ]; }

    public function introGoodMin(): int { return $this->threshold( 'intro_good_min' ); }
    public function introGoodMax(): int { return $this->threshold( 'intro_good_max' ); }
    public function introAttentionMin(): int { return $this->threshold( 'intro_attention_min' ); }
    public function introAttentionMax(): int { return $this->threshold( 'intro_attention_max' ); }
    public function paragraphWordLimit(): int { return $this->threshold( 'paragraph_word_limit' ); }
    public function depthGood(): int { return $this->threshold( 'depth_good' ); }
    public function depthAttention(): int { return $this->threshold( 'depth_attention' ); }

    /** @return array<string,array<string,int>> */
    public static function profiles(): array {
        return array(
            'balanced' => array(
                'intro_good_min' => 30,
                'intro_good_max' => 120,
                'intro_attention_min' => 15,
                'intro_attention_max' => 180,
                'paragraph_word_limit' => 120,
                'depth_good' => 250,
                'depth_attention' => 120,
            ),
            'concise' => array(
                'intro_good_min' => 20,
                'intro_good_max' => 90,
                'intro_attention_min' => 10,
                'intro_attention_max' => 140,
                'paragraph_word_limit' => 90,
                'depth_good' => 180,
                'depth_attention' => 90,
            ),
            'extended' => array(
                'intro_good_min' => 40,
                'intro_good_max' => 160,
                'intro_attention_min' => 20,
                'intro_attention_max' => 220,
                'paragraph_word_limit' => 150,
                'depth_good' => 400,
                'depth_attention' => 200,
            ),
        );
    }

    /** @param array<string,mixed> $settings */
    private static function enabledByDefault( array $settings, string $key ): bool {
        if ( ! array_key_exists( $key, $settings ) ) {
            return true;
        }
        return ! empty( $settings[ $key ] );
    }

    private function threshold( string $key ): int {
        $profile = self::profiles()[ $this->profile ];
        return isset( $profile[ $key ] ) ? (int) $profile[ $key ] : 0;
    }
}
