<?php
namespace Yablonsky\PerformanceSEO\Analysis;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Conservative local review prompts for potentially exclusionary wording.
 * Matches are not policy violations; they only ask an editor to review context.
 */
final class InclusiveLanguageAnalyzer {
    private const LEXICONS = array(
        'en' => array(
            'manpower' => 'workforce',
            'chairman' => 'chair / chairperson',
            'crazy' => 'specific description of the situation',
            'lame' => 'specific description of the limitation',
            'the elderly' => 'older people / older adults',
            'illegal alien' => 'undocumented person / non-citizen, when accurate',
            'master/slave' => 'primary/replica or source/replica, when technically accurate',
        ),
        'ru' => array(
            'калека' => 'человек с инвалидностью / точное описание, если оно нужно',
            'сумасшедший' => 'точное описание ситуации или состояния без ярлыка',
            'старики' => 'пожилые люди / люди старшего возраста',
            'мужская работа' => 'описание навыков или требований без привязки к полу',
            'женская работа' => 'описание навыков или требований без привязки к полу',
        ),
        'uk' => array(
            'каліка' => 'людина з інвалідністю / точний опис, якщо він потрібен',
            'божевільний' => 'точний опис ситуації без ярлика',
            'старики' => 'літні люди / люди старшого віку',
        ),
        'de' => array(
            'manpower' => 'Arbeitskraft / Teamkapazität',
            'die alten' => 'ältere Menschen / ältere Erwachsene',
        ),
        'es' => array(
            'los ancianos' => 'personas mayores',
            'mano de obra masculina' => 'personal / equipo con las habilidades requeridas',
        ),
        'fr' => array(
            'les vieux' => 'les personnes âgées / les personnes plus âgées',
            'travail d’homme' => 'travail nécessitant les compétences indiquées',
        ),
        'it' => array(
            'i vecchi' => 'le persone anziane / le persone più anziane',
            'lavoro da uomo' => 'lavoro che richiede le competenze indicate',
        ),
        'pt' => array(
            'os velhos' => 'pessoas idosas / pessoas mais velhas',
            'trabalho de homem' => 'trabalho que exige as competências indicadas',
        ),
        'nl' => array(
            'de bejaarden' => 'oudere mensen / oudere volwassenen',
            'mannenwerk' => 'werk waarvoor de beschreven vaardigheden nodig zijn',
        ),
        'pl' => array(
            'starcy' => 'osoby starsze / starsi dorośli',
            'męska praca' => 'praca wymagająca opisanych umiejętności',
        ),
        'lt' => array(
            'seniai' => 'vyresnio amžiaus žmonės',
            'vyriškas darbas' => 'darbas, kuriam reikia nurodytų įgūdžių',
        ),
        'tr' => array(
            'yaşlılar' => 'ileri yaştaki kişiler / yaşlı yetişkinler',
            'erkek işi' => 'belirtilen becerileri gerektiren iş',
        ),
    );

    /** @return array<int,array<string,mixed>> */
    public function checks( string $text, string $language ): array {
        $family = $this->family( $language );
        if ( ! isset( self::LEXICONS[ $family ] ) ) {
            return array(
                $this->check( 'inclusive_language_coverage', 'good', __( 'Inclusive-language coverage', 'yablonsky-seo' ), __( 'Inclusive-language analysis supports the bundled resource-language lexicons. This resource language currently reports its unsupported state explicitly.', 'yablonsky-seo' ), 0 ),
            );
        }
        $normalized = $this->lower( wp_strip_all_tags( $text, true ) );
        $matches = array();
        foreach ( self::LEXICONS[ $family ] as $term => $alternative ) {
            if ( false !== strpos( $normalized, $this->lower( $term ) ) ) {
                $matches[ $term ] = $alternative;
            }
        }
        if ( empty( $matches ) ) {
            return array(
                $this->check( 'inclusive_language_review', 'good', __( 'Inclusive-language review', 'yablonsky-seo' ), __( 'The conservative local review lexicon found a clear result. Treat this as a bounded wording check; broader inclusive-language review remains human-led.', 'yablonsky-seo' ), 10 ),
            );
        }
        $pairs = array();
        foreach ( array_slice( $matches, 0, 5, true ) as $term => $alternative ) {
            $pairs[] = $term . ' → ' . $alternative;
        }
        /* translators: 1: number of local wording matches, 2: example terms and neutral alternatives. */
        /* translators: 1: number of wording matches, 2: matched wording/replacement pairs. */
        $message = sprintf( _n( '%1$d wording match may deserve contextual review: %2$s', '%1$d wording matches may deserve contextual review: %2$s', count( $matches ), 'yablonsky-seo' ), count( $matches ), implode( '; ', $pairs ) );
        return array(
            $this->check( 'inclusive_language_review', 'attention', __( 'Inclusive-language review', 'yablonsky-seo' ), $message, 10 ),
            $this->check( 'inclusive_language_boundary', 'good', __( 'Human review boundary', 'yablonsky-seo' ), __( 'A match is only a review prompt. Context, quotations, legal terminology and the intended audience still require human judgment.', 'yablonsky-seo' ), 2 ),
        );
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
