<?php
namespace Yablonsky\PerformanceSEO\Language;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Small local language detector for resource-bound AI safety.
 *
 * This intentionally does not read WordPress/admin locale. It only inspects the
 * resource's own visible text and returns auto when confidence is insufficient.
 */
final class ContentLanguageDetector {
    /** @return array{language_key:string,confidence:float} */
    public function detect( string $text ): array {
        $text = html_entity_decode( wp_strip_all_tags( $text, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = (string) preg_replace( '/\s+/u', ' ', trim( $text ) );
        if ( '' === $text ) { return array( 'language_key' => 'auto', 'confidence' => 0.0 ); }

        $letters = (string) preg_replace( '/[^\p{L}]+/u', '', $text );
        $letterCount = $this->length( $letters );
        if ( $letterCount < 24 ) { return array( 'language_key' => 'auto', 'confidence' => 0.0 ); }

        $script = array(
            'kana'     => $this->countPattern( '/[\x{3040}-\x{30ff}]/u', $text ),
            'hangul'   => $this->countPattern( '/[\x{ac00}-\x{d7af}]/u', $text ),
            'han'      => $this->countPattern( '/[\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}]/u', $text ),
            'arabic'   => $this->countPattern( '/[\x{0600}-\x{06ff}\x{0750}-\x{077f}]/u', $text ),
            'devanagari'=> $this->countPattern( '/[\x{0900}-\x{097f}]/u', $text ),
            'bengali'  => $this->countPattern( '/[\x{0980}-\x{09ff}]/u', $text ),
            'hebrew'   => $this->countPattern( '/[\x{0590}-\x{05ff}]/u', $text ),
            'greek'    => $this->countPattern( '/[\x{0370}-\x{03ff}]/u', $text ),
            'cyrillic' => $this->countPattern( '/[\x{0400}-\x{04ff}]/u', $text ),
            'thai'     => $this->countPattern( '/[\x{0e00}-\x{0e7f}]/u', $text ),
        );

        if ( $script['kana'] >= 3 ) { return $this->result( 'ja', $script['kana'], $letterCount ); }
        if ( $script['hangul'] / $letterCount >= 0.20 ) { return $this->result( 'ko', $script['hangul'], $letterCount ); }
        if ( $script['devanagari'] / $letterCount >= 0.35 ) { return $this->result( 'hi', $script['devanagari'], $letterCount ); }
        if ( $script['bengali'] / $letterCount >= 0.35 ) { return $this->result( 'bn', $script['bengali'], $letterCount ); }
        if ( $script['han'] / $letterCount >= 0.35 ) {
            $traditional = $this->countPattern( '/[體臺灣國學會網頁與為這個來時們開關廣東龍門後發現]/u', $text );
            $simplified  = $this->countPattern( '/[体台湾国学会网页与为这个来时们开关广东龙门后发现]/u', $text );
            return $this->result( $traditional > $simplified ? 'zh-Hant' : 'zh-Hans', $script['han'], $letterCount );
        }
        if ( $script['arabic'] / $letterCount >= 0.35 ) {
            $tokens = $this->tokens( $text );
            $detected = $this->detectByWords( $tokens, array(
                'ur' => 'اور ہے میں کے کی کو سے یہ وہ ایک نہیں پر بھی لیے لئے ساتھ ہیں تھا تھی تھے کرنا کیا جو جب اگر لیکن ہم آپ ان اس',
                'ar' => 'و في من على إلى عن هذا هذه هو هي أن لا ما مع كان كانت يكون التي الذي الذين أو كما بعد بين نحن أنتم هم',
            ), $text, array( 'ur' => 'ٹڈڑںھہےیئےگچپژ', 'ar' => 'ةأإؤئظضثذ' ) );
            if ( 'auto' !== (string) ( $detected['language_key'] ?? 'auto' ) ) { return $detected; }
            return $this->result( 'ar', $script['arabic'], $letterCount );
        }
        if ( $script['hebrew'] / $letterCount >= 0.35 ) { return $this->result( 'he', $script['hebrew'], $letterCount ); }
        if ( $script['greek'] / $letterCount >= 0.35 ) { return $this->result( 'el', $script['greek'], $letterCount ); }
        if ( $script['thai'] / $letterCount >= 0.35 ) { return $this->result( 'th', $script['thai'], $letterCount ); }

        $tokens = $this->tokens( $text );
        if ( count( $tokens ) < 8 ) { return array( 'language_key' => 'auto', 'confidence' => 0.0 ); }

        if ( $script['cyrillic'] / $letterCount >= 0.25 ) {
            return $this->detectByWords( $tokens, array(
                'uk' => 'і ї є ґ та що це як для не на до з у в із але або який яка які його її їх було бути може також при від про після через між ми ви вони цей ця ці',
                'ru' => 'и в во не на я что он она это как для по из от с со к у за но или который которая которые его ее их было быть может также при после через между мы вы они этот эта эти',
                'bg' => 'и в не на че той тя това как за по от с към но или който която които неговата нейната беше бъде може също при след чрез между ние вие те този тази тези',
            ), $text, array(
                'uk' => 'іїєґ',
                'ru' => 'ыэъё',
                'bg' => 'ъ',
            ) );
        }

        return $this->detectByWords( $tokens, array(
            'en' => 'the and of to in is for on with that this are as be or from by an it can will more not your you we they their our which when where how what has have was were into than also',
            'de' => 'der die das und ist von zu in mit auf für den ein eine als auch nicht sich dem des im aus bei oder wird sind werden über nach wie',
            'fr' => 'le la les de des du et est dans pour avec un une que qui sur pas par en au aux ce cette ces sont plus ou comme',
            'es' => 'el la los las de del y es en para con un una que por no se al como más o son su sus este esta estos estas',
            'it' => 'il lo la i gli le di del e è in per con un una che non si al come più o sono suo sua questo questa',
            'pt' => 'o a os as de do da e é em para com um uma que por não se ao como mais ou são seu sua este esta',
            'nl' => 'de het een en van is in op voor met dat die niet als aan om zijn worden bij of uit meer deze dit',
            'pl' => 'i w na z do nie jest że to dla się jak przez po od lub są jego jej ich oraz może ten ta te',
            'cs' => 'a v na z do není je že to pro se jak přes po od nebo jsou jeho její jejich také může tento tato tyto',
            'sk' => 'a v na z do nie je že to pre sa ako cez po od alebo sú jeho jej ich tiež môže tento táto tieto',
            'lt' => 'ir kad yra į su iš kaip tai ne buvo gali daugiau apie šis ši šie savo bei arba nuo dėl pagal tarp mes jūs jie jų',
            'lv' => 'un ka ir uz ar no kā tas tā ne bija var vairāk par šis šī šie savu vai līdz pēc starp mēs jūs viņi',
            'et' => 'ja et on jaoks koos see ei oli võib rohkem kohta seda oma või alates pärast vahel meie teie nad nende',
            'ro' => 'și în de este pentru cu un o că pe nu se la ca mai sau sunt din acest această aceste lor poate',
            'hu' => 'és a az hogy van egy nem mint számára vagy is volt lehet több ezt eztől között után előtt mi ön ők',
            'fi' => 'ja on että varten kanssa tämä ei oli voi enemmän myös tai ovat siitä jälkeen välillä me te he heidän',
            'sv' => 'och är att för med en ett den det inte som på av till eller mer från var kan också efter mellan vi ni de',
            'da' => 'og er at for med en et den det ikke som på af til eller mere fra var kan også efter mellem vi de deres',
            'no' => 'og er at for med en et den det ikke som på av til eller mer fra var kan også etter mellom vi de deres',
            'sl' => 'in je da za z s na ne kot ali so iz do po od ta to ti njihove lahko več med po',
            'hr' => 'i je da za s na ne kao ili su iz do po od ovaj ova ovi njihov može više između nakon',
            'tr' => 've bir bu için ile de da değil olarak olan daha veya gibi çok sonra önce arasında biz siz onlar',
            'id' => 'dan yang untuk dengan ini itu tidak dari pada adalah atau sebagai lebih dalam juga setelah antara kami anda mereka',
            'ga' => 'agus an na is ar le go i don den seo sin nach tá bhí bheidh mar nó ó do faoi idir muid sibh siad a gcuid féidir',
            'mt' => 'u il tal għal ma li hija huwa dan din dawn mhux minn fuq bħala jew ukoll bejn wara qabel tagħna tagħkom tagħhom jista',
        ), $text, array(
            'de' => 'äöüß', 'fr' => 'àâçéèêëîïôùûüÿœ', 'es' => 'ñ¿¡', 'pt' => 'ãõç',
            'pl' => 'ąćęłńóśźż', 'cs' => 'čďěňřšťůž', 'sk' => 'áäčďéíĺľňóôŕšťúýž',
            'lt' => 'ąčęėįšųūž', 'lv' => 'āčēģīķļņšūž', 'et' => 'õäöü', 'ro' => 'ăâîșțşţ',
            'hu' => 'áéíóöőúüű', 'fi' => 'äö', 'sv' => 'åäö', 'da' => 'æøå', 'no' => 'æøå',
            'sl' => 'čšž', 'hr' => 'čćđšž', 'tr' => 'çğıöşü', 'ga' => 'áéíóú', 'mt' => 'ċġħż',
        ) );
    }

    /** @param array<int,string> $tokens @param array<string,string> $dictionaries @param array<string,string> $hints @return array{language_key:string,confidence:float} */
    private function detectByWords( array $tokens, array $dictionaries, string $rawText, array $hints = array() ): array {
        $tokenCounts = array_count_values( $tokens );
        $scores = array();
        foreach ( $dictionaries as $language => $words ) {
            $score = 0.0;
            foreach ( preg_split( '/\s+/u', trim( $words ) ) ?: array() as $word ) {
                if ( '' !== $word && isset( $tokenCounts[ $word ] ) ) { $score += min( 3, (int) $tokenCounts[ $word ] ); }
            }
            if ( isset( $hints[ $language ] ) && '' !== $hints[ $language ] ) {
                foreach ( preg_split( '//u', $hints[ $language ], -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $char ) {
                    if ( false !== $this->ipos( $rawText, $char ) ) { $score += 2.0; }
                }
            }
            $scores[ $language ] = $score;
        }
        arsort( $scores, SORT_NUMERIC );
        $languages = array_keys( $scores );
        if ( empty( $languages ) ) { return array( 'language_key' => 'auto', 'confidence' => 0.0 ); }
        $best = (string) $languages[0];
        $bestScore = (float) $scores[ $best ];
        $second = isset( $languages[1] ) ? (float) $scores[ $languages[1] ] : 0.0;
        $minScore = count( $tokens ) >= 80 ? 5.0 : 3.0;
        if ( $bestScore < $minScore || ( $bestScore - $second ) < 1.5 ) {
            return array( 'language_key' => 'auto', 'confidence' => 0.0 );
        }
        $confidence = min( 0.99, 0.55 + min( 0.30, ( $bestScore - $second ) / 20 ) + min( 0.14, $bestScore / max( 20, count( $tokens ) ) ) );
        return array( 'language_key' => $best, 'confidence' => round( $confidence, 2 ) );
    }

    /** @return array<int,string> */
    private function tokens( string $text ): array {
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        preg_match_all( '/\p{L}+/u', $text, $matches );
        return isset( $matches[0] ) && is_array( $matches[0] ) ? array_values( array_filter( $matches[0], static function ( string $token ): bool { return '' !== $token; } ) ) : array();
    }

    /** @return array{language_key:string,confidence:float} */
    private function result( string $language, int $matched, int $total ): array {
        return array( 'language_key' => $language, 'confidence' => round( min( 0.99, max( 0.70, $matched / max( 1, $total ) ) ), 2 ) );
    }

    private function countPattern( string $pattern, string $text ): int {
        $count = preg_match_all( $pattern, $text, $matches );
        return false === $count ? 0 : (int) $count;
    }

    private function length( string $text ): int {
        if ( function_exists( 'mb_strlen' ) ) {
            return (int) mb_strlen( $text, 'UTF-8' );
        }
        $count = preg_match_all( '/./us', $text, $matches );
        return false === $count ? strlen( $text ) : (int) $count;
    }

    /** @return int|false */
    private function ipos( string $haystack, string $needle ) {
        return function_exists( 'mb_stripos' ) ? mb_stripos( $haystack, $needle, 0, 'UTF-8' ) : stripos( $haystack, $needle );
    }
}
