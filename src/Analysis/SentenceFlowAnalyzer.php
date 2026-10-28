<?php
namespace Yablonsky\PerformanceSEO\Analysis;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Conservative local sentence-flow evidence. */
final class SentenceFlowAnalyzer {
    private const TRANSITIONS = array(
        'en' => array( 'however', 'therefore', 'because', 'although', 'meanwhile', 'for example', 'in addition', 'instead', 'finally', 'first', 'second', 'also', 'but', 'so' ),
        'ru' => array( 'однако', 'поэтому', 'потому что', 'хотя', 'например', 'кроме того', 'вместо этого', 'наконец', 'во-первых', 'во-вторых', 'также', 'но' ),
        'uk' => array( 'однак', 'тому', 'тому що', 'хоча', 'наприклад', 'крім того', 'натомість', 'нарешті', 'по-перше', 'по-друге', 'також', 'але' ),
        'de' => array( 'jedoch', 'deshalb', 'weil', 'obwohl', 'zum beispiel', 'außerdem', 'stattdessen', 'schließlich', 'erstens', 'zweitens', 'auch', 'aber' ),
        'es' => array( 'sin embargo', 'por lo tanto', 'porque', 'aunque', 'por ejemplo', 'además', 'en cambio', 'finalmente', 'primero', 'segundo', 'también', 'pero' ),
        'fr' => array( 'cependant', 'donc', 'parce que', 'bien que', 'par exemple', 'de plus', 'en revanche', 'finalement', 'premièrement', 'deuxièmement', 'aussi', 'mais' ),
        'it' => array( 'tuttavia', 'quindi', 'perché', 'sebbene', 'per esempio', 'inoltre', 'invece', 'infine', 'primo', 'secondo', 'anche', 'ma' ),
        'pt' => array( 'no entanto', 'portanto', 'porque', 'embora', 'por exemplo', 'além disso', 'em vez disso', 'finalmente', 'primeiro', 'segundo', 'também', 'mas' ),
        'nl' => array( 'echter', 'daarom', 'omdat', 'hoewel', 'bijvoorbeeld', 'bovendien', 'in plaats daarvan', 'ten slotte', 'ten eerste', 'ten tweede', 'ook', 'maar' ),
        'pl' => array( 'jednak', 'dlatego', 'ponieważ', 'chociaż', 'na przykład', 'ponadto', 'zamiast tego', 'wreszcie', 'po pierwsze', 'po drugie', 'także', 'ale' ),
        'lt' => array( 'tačiau', 'todėl', 'nes', 'nors', 'pavyzdžiui', 'be to', 'vietoj to', 'galiausiai', 'pirma', 'antra', 'taip pat', 'bet' ),
    );

    /** @return array<int,array<string,mixed>> */
    public function checks( string $text, string $language ): array {
        $plain = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text, true ) ) ?? '' );
        $sentences = $this->sentences( $plain );
        if ( count( $sentences ) < 4 ) {
            return array( $this->check( 'sentence_flow_sample', 'good', __( 'Sentence flow', 'yablonsky-seo' ), __( 'This sample is short, so sentence-flow heuristics stay in Standby.', 'yablonsky-seo' ), 1 ) );
        }
        $checks = array( $this->openingVarietyCheck( $sentences ) );
        $family = $this->family( $language );
        if ( isset( self::TRANSITIONS[ $family ] ) ) {
            $checks[] = $this->transitionCheck( $sentences, self::TRANSITIONS[ $family ] );
        } else {
            $checks[] = $this->check( 'transition_coverage', 'good', __( 'Transition-word coverage', 'yablonsky-seo' ), __( 'Sentence-opening variety is analyzed for this language. Transition-word scoring activates when a conservative local lexicon is bundled.', 'yablonsky-seo' ), 0 );
        }
        return $checks;
    }

    /** @param array<int,string> $sentences @return array<string,mixed> */
    private function openingVarietyCheck( array $sentences ): array {
        $runs = 1;
        $maxRun = 1;
        $previous = '';
        foreach ( $sentences as $sentence ) {
            $opening = $this->firstWord( (string) $sentence );
            if ( '' !== $opening && $opening === $previous ) { ++$runs; } else { $runs = 1; }
            $maxRun = max( $maxRun, $runs );
            $previous = $opening;
        }
        $status = $maxRun >= 4 ? 'critical' : ( $maxRun >= 3 ? 'attention' : 'good' );
        /* translators: %d: maximum number of consecutive sentences beginning with the same first word. */
        $message = sprintf( __( 'The longest run with the same sentence-opening word is %d sentence(s). Repetition can be intentional; review only when the rhythm feels mechanical.', 'yablonsky-seo' ), $maxRun );
        return $this->check( 'sentence_opening_variety', $status, __( 'Sentence-opening variety', 'yablonsky-seo' ), $message, 4 );
    }

    /** @param array<int,string> $sentences @param array<int,string> $lexicon @return array<string,mixed> */
    private function transitionCheck( array $sentences, array $lexicon ): array {
        $matched = 0;
        foreach ( $sentences as $sentence ) {
            $normalized = $this->wordBoundaryText( (string) $sentence );
            foreach ( $lexicon as $term ) {
                if ( false !== strpos( $normalized, $this->wordBoundaryText( $term ) ) ) { ++$matched; break; }
            }
        }
        $ratio = count( $sentences ) > 0 ? $matched / count( $sentences ) : 0.0;
        $status = $ratio >= 0.25 ? 'good' : ( $ratio >= 0.10 ? 'attention' : 'attention' );
        /* translators: 1: sentences containing a conservative transition marker, 2: total sentence count. */
        $message = sprintf( __( '%1$d of %2$d sentences contain a conservative local transition marker. Treat this as optional flow evidence and keep connector wording natural.', 'yablonsky-seo' ), $matched, count( $sentences ) );
        return $this->check( 'transition_word_evidence', $status, __( 'Transition-word evidence', 'yablonsky-seo' ), $message, 5 );
    }

    /** @return array<int,string> */
    private function sentences( string $text ): array {
        $parts = preg_split( '/(?<=[.!?…。！？])\s*/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $parts ) ? array_values( array_filter( $parts, static function ( $value ): bool { return '' !== trim( (string) $value ); } ) ) : array();
    }

    private function wordBoundaryText( string $value ): string {
        $value = $this->lower( $value );
        $value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value );
        $value = is_string( $value ) ? trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' ) : '';
        return ' ' . $value . ' ';
    }

    private function firstWord( string $sentence ): string {
        if ( preg_match( '/[\p{L}\p{N}][\p{L}\p{N}\x{2019}\x{0027}\-]*/u', $sentence, $match ) ) { return $this->lower( (string) $match[0] ); }
        return '';
    }

    private function family( string $language ): string {
        $language = strtolower( trim( str_replace( '_', '-', $language ) ) );
        $parts = explode( '-', $language );
        return isset( $parts[0] ) ? (string) $parts[0] : $language;
    }

    private function lower( string $value ): string {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
    }

    /** @return array<string,mixed> */
    private function check( string $id, string $status, string $title, string $message, int $weight ): array {
        return array( 'id' => $id, 'status' => $status, 'title' => $title, 'message' => $message, 'weight' => $weight );
    }
}
