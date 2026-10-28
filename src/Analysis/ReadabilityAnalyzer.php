<?php
namespace Yablonsky\PerformanceSEO\Analysis;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local, language-aware readability evidence.
 *
 * Sentence/paragraph structure is available for every registered language where
 * Unicode word boundaries are usable. Reading-ease remains deliberately limited
 * to calibrated EN/RU formulas so YSEO never invents unsupported science.
 */
final class ReadabilityAnalyzer {
    /** Languages that normally do not use whitespace word segmentation reliably. */
    private const COMPACT_SCRIPT_FAMILIES = array( 'zh-hans', 'zh-hant', 'ja', 'ko' );

    /** @return array<int,array<string,mixed>> */
    public function checks( string $text, string $language ): array {
        $family = $this->family( $language );
        $plain = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text, true ) ) ?? '' );
        if ( '' === $plain ) {
            return array(
                $this->check( 'readability_sample', 'attention', __( 'Readability sample', 'yablonsky-seo' ), __( 'Add more text to calculate readability evidence.', 'yablonsky-seo' ), 6 ),
            );
        }

        $sentences = $this->sentences( $plain );
        $sentenceCount = count( $sentences );
        $checks = array();

        if ( in_array( $family, self::COMPACT_SCRIPT_FAMILIES, true ) ) {
            $checks[] = $this->compactScriptCheck( $plain, $sentenceCount, $family );
        } else {
            $words = $this->words( $plain );
            $wordCount = count( $words );
            if ( 0 === $wordCount || 0 === $sentenceCount ) {
                $checks[] = $this->check( 'readability_sentence_sample', 'attention', __( 'Sentence structure', 'yablonsky-seo' ), __( 'YSEO needs complete sentence-level text before it can evaluate sentence structure.', 'yablonsky-seo' ), 6 );
            } else {
                $checks = array_merge( $checks, $this->wordSpacedSentenceChecks( $sentences, $wordCount, $family ) );
            }
        }

        $checks[] = $this->paragraphRhythmCheck( $text, $family );
        $checks[] = $this->sectionVarietyCheck( $text );

        if ( in_array( $family, array( 'en', 'ru' ), true ) ) {
            $checks[] = $this->readingEaseCheck( $plain, $sentences, $family );
        } else {
            $checks[] = $this->check(
                'readability_ease_coverage',
                'good',
                __( 'Reading-ease coverage', 'yablonsky-seo' ),
                __( 'YSEO evaluates sentence and paragraph structure for this language. Reading-ease scoring activates when a calibrated local formula is bundled.', 'yablonsky-seo' ),
                0
            );
        }

        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function wordSpacedSentenceChecks( array $sentences, int $wordCount, string $family ): array {
        $sentenceCount = count( $sentences );
        $avgSentence = $sentenceCount > 0 ? $wordCount / $sentenceCount : 0.0;
        $goodMax = 'ru' === $family ? 18.0 : 20.0;
        $attentionMax = 'ru' === $family ? 26.0 : 28.0;
        $longThreshold = 'ru' === $family ? 30 : 28;

        $sentenceStatus = $avgSentence <= $goodMax ? 'good' : ( $avgSentence <= $attentionMax ? 'attention' : 'critical' );
        /* translators: 1: average words per sentence, 2: detected sentence count. */
        $sentenceMessage = sprintf( __( 'Average sentence length is %1$.1f words across %2$d detected sentences.', 'yablonsky-seo' ), $avgSentence, $sentenceCount );

        $longCount = 0;
        foreach ( $sentences as $sentence ) {
            if ( count( $this->words( (string) $sentence ) ) > $longThreshold ) { ++$longCount; }
        }
        $longRatio = $sentenceCount > 0 ? $longCount / $sentenceCount : 0.0;
        $longStatus = $longRatio <= 0.15 ? 'good' : ( $longRatio <= 0.30 ? 'attention' : 'critical' );
        /* translators: 1: long sentence count, 2: total sentence count, 3: word threshold. */
        $longMessage = sprintf( __( '%1$d of %2$d sentences exceed the local %3$d-word long-sentence threshold.', 'yablonsky-seo' ), $longCount, $sentenceCount, $longThreshold );

        return array(
            $this->check( 'readability_sentence_length', $sentenceStatus, __( 'Sentence length', 'yablonsky-seo' ), $sentenceMessage, 10 ),
            $this->check( 'readability_long_sentences', $longStatus, __( 'Long sentences', 'yablonsky-seo' ), $longMessage, 8 ),
        );
    }

    /** @return array<string,mixed> */
    private function compactScriptCheck( string $plain, int $sentenceCount, string $family ): array {
        $characters = preg_replace( '/\s+/u', '', $plain );
        $characterCount = is_string( $characters ) ? $this->length( $characters ) : 0;
        if ( 0 === $sentenceCount ) {
            return $this->check( 'readability_compact_script_sample', 'attention', __( 'Sentence structure', 'yablonsky-seo' ), __( 'YSEO needs sentence punctuation before it can evaluate compact-script sentence structure.', 'yablonsky-seo' ), 6 );
        }
        $average = $characterCount / $sentenceCount;
        $goodMax = 'ja' === $family ? 55.0 : 60.0;
        $attentionMax = 'ja' === $family ? 85.0 : 95.0;
        $status = $average <= $goodMax ? 'good' : ( $average <= $attentionMax ? 'attention' : 'critical' );
        /* translators: 1: average non-space characters per sentence, 2: detected sentence count. */
        $message = sprintf( __( 'Average sentence span is about %1$.1f non-space characters across %2$d detected sentences.', 'yablonsky-seo' ), $average, $sentenceCount );
        return $this->check( 'readability_compact_script_sentences', $status, __( 'Sentence span', 'yablonsky-seo' ), $message, 10 );
    }

    /** @return array<string,mixed> */
    private function paragraphRhythmCheck( string $text, string $family ): array {
        $normalized = preg_replace( '/<(?:br\s*\/?>|\/p\s*>|\/li\s*>|\/blockquote\s*>)/iu', "\n", $text );
        $normalized = wp_strip_all_tags( is_string( $normalized ) ? $normalized : $text, true );
        $parts = preg_split( '/\n+/u', (string) $normalized, -1, PREG_SPLIT_NO_EMPTY );
        $parts = is_array( $parts ) ? $parts : array();
        $paragraphs = 0;
        $long = 0;
        foreach ( $parts as $part ) {
            $part = trim( (string) $part );
            if ( '' === $part ) { continue; }
            ++$paragraphs;
            if ( in_array( $family, self::COMPACT_SCRIPT_FAMILIES, true ) ) {
                if ( $this->length( preg_replace( '/\s+/u', '', $part ) ?? '' ) > 260 ) { ++$long; }
            } elseif ( count( $this->words( $part ) ) > 120 ) {
                ++$long;
            }
        }
        if ( 0 === $paragraphs ) {
            return $this->check( 'readability_paragraph_rhythm', 'attention', __( 'Paragraph rhythm', 'yablonsky-seo' ), __( 'Paragraph analysis activates when paragraph boundaries are present in the current text.', 'yablonsky-seo' ), 5 );
        }
        $ratio = $long / $paragraphs;
        $status = 0 === $long ? 'good' : ( $ratio <= 0.25 ? 'attention' : 'critical' );
        /* translators: 1: long paragraph count, 2: paragraph count. */
        $message = sprintf( __( '%1$d of %2$d detected paragraphs are unusually long for screen reading.', 'yablonsky-seo' ), $long, $paragraphs );
        return $this->check( 'readability_paragraph_rhythm', $status, __( 'Paragraph rhythm', 'yablonsky-seo' ), $message, 6 );
    }

    /** @return array<string,mixed> */
    private function sectionVarietyCheck( string $text ): array {
        $hasHeading = (bool) preg_match( '/<h[2-4]\b/iu', $text );
        $hasList = (bool) preg_match( '/<(?:ul|ol)\b/iu', $text );
        $plainWords = count( $this->words( wp_strip_all_tags( $text, true ) ) );
        if ( $plainWords < 250 ) {
            return $this->check( 'readability_section_variety', 'good', __( 'Section variety', 'yablonsky-seo' ), __( 'This is short-form content; extra structural elements are optional rather than required.', 'yablonsky-seo' ), 2 );
        }
        if ( $hasHeading && $hasList ) {
            return $this->check( 'readability_section_variety', 'good', __( 'Section variety', 'yablonsky-seo' ), __( 'The current content uses headings and list structure to support scanning.', 'yablonsky-seo' ), 4 );
        }
        return $this->check( 'readability_section_variety', 'attention', __( 'Section variety', 'yablonsky-seo' ), __( 'For longer content, consider headings and lists where they genuinely improve scanning. Keep them purposeful and reader-led.', 'yablonsky-seo' ), 4 );
    }

    /** @return array<string,mixed> */
    private function readingEaseCheck( string $plain, array $sentences, string $family ): array {
        $words = $this->words( $plain );
        $wordCount = count( $words );
        $sentenceCount = count( $sentences );
        if ( $wordCount < 80 || 0 === $sentenceCount ) {
            return $this->check( 'readability_ease_sample', 'attention', __( 'Reading ease', 'yablonsky-seo' ), __( 'At least about 80 words are needed before YSEO shows a reading-ease estimate.', 'yablonsky-seo' ), 3 );
        }
        $avgSentence = $wordCount / $sentenceCount;
        $syllables = 0;
        foreach ( $words as $word ) { $syllables += $this->syllables( (string) $word, $family ); }
        $avgSyllables = $wordCount > 0 ? $syllables / $wordCount : 0.0;
        $ease = 'ru' === $family
            ? 206.835 - ( 1.3 * $avgSentence ) - ( 60.1 * $avgSyllables )
            : 206.835 - ( 1.015 * $avgSentence ) - ( 84.6 * $avgSyllables );
        $ease = max( 0.0, min( 100.0, $ease ) );
        $status = $ease >= 55 ? 'good' : ( $ease >= 35 ? 'attention' : 'critical' );
        /* translators: %s: approximate local reading-ease score from 0 to 100. */
        $message = sprintf( __( 'Approximate language-aware reading-ease score: %s / 100. Use it as an editorial clue; quality and ranking depend on broader evidence.', 'yablonsky-seo' ), number_format_i18n( $ease, 1 ) );
        return $this->check( 'readability_ease', $status, __( 'Reading ease', 'yablonsky-seo' ), $message, 8 );
    }

    /** @return array<int,string> */
    private function sentences( string $text ): array {
        $parts = preg_split( '/(?<=[.!?…。！？])\s*/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $parts ) ? array_values( array_filter( $parts, static function ( $value ): bool { return '' !== trim( (string) $value ); } ) ) : array();
    }

    /** @return array<int,string> */
    private function words( string $text ): array {
        $count = preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\x{2019}\x{0027}\-]*/u', $text, $matches );
        return $count && isset( $matches[0] ) && is_array( $matches[0] ) ? $matches[0] : array();
    }

    private function syllables( string $word, string $family ): int {
        $word = $this->lower( $word );
        $vowels = 'ru' === $family ? 'аеёиоуыэюя' : 'aeiouy';
        $pattern = '/[' . preg_quote( $vowels, '/' ) . ']+/u';
        $count = preg_match_all( $pattern, $word, $matches );
        if ( 'en' === $family && $count > 1 && preg_match( '/e$/u', $word ) && ! preg_match( '/le$/u', $word ) ) { --$count; }
        return max( 1, (int) $count );
    }

    private function family( string $language ): string {
        $language = strtolower( trim( str_replace( '_', '-', $language ) ) );
        if ( 0 === strpos( $language, 'zh-hans' ) || 0 === strpos( $language, 'zh-cn' ) || 0 === strpos( $language, 'zh-sg' ) ) { return 'zh-hans'; }
        if ( 0 === strpos( $language, 'zh-hant' ) || 0 === strpos( $language, 'zh-tw' ) || 0 === strpos( $language, 'zh-hk' ) ) { return 'zh-hant'; }
        $parts = explode( '-', $language );
        return isset( $parts[0] ) ? (string) $parts[0] : $language;
    }

    private function lower( string $value ): string {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
    }

    private function length( string $value ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
    }

    /** @return array<string,mixed> */
    private function check( string $id, string $status, string $title, string $message, int $weight ): array {
        return array( 'id' => $id, 'status' => $status, 'title' => $title, 'message' => $message, 'weight' => $weight );
    }
}
