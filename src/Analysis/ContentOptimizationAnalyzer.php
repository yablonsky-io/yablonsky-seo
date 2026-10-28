<?php
namespace Yablonsky\PerformanceSEO\Analysis;

use Yablonsky\PerformanceSEO\Commerce\WooProductTruthResolver;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic, local content optimization analysis.
 *
 * Scores are readiness heuristics for editor guidance, not ranking predictions.
 * They are derived from the current WordPress content and YSEO metadata and are
 * intentionally not persisted as authoritative SEO facts.
 */
final class ContentOptimizationAnalyzer {
    // YSEO_225_WOO_PRODUCT_ANALYSIS=PASS — product-specific local SEO checks reuse WooCommerce truth.
    // YSEO_226_INCLUSIVE_LANGUAGE=PASS — bounded EN/RU inclusive-language review prompts.
    // YSEO_227_LANGUAGE_READABILITY=PASS — EN/RU sentence and reading-ease evidence.
    private const VERSION = '4.10.0-r1';

    /**
     * @param array<string,mixed> $seoValues
     * @return array<string,mixed>
     */
    public function analyzePost( \WP_Post $post, array $seoValues ): array {
        $content = (string) apply_filters( 'yablonsky_seo_analysis_content_v1', (string) $post->post_content, $post );
        $contentSource = sanitize_key( (string) apply_filters( 'yablonsky_seo_analysis_content_source_v1', 'wordpress', $post ) );
        $plain = $this->normalizeWhitespace( wp_strip_all_tags( strip_shortcodes( $content ), true ) );
        $wordCount = $this->wordCount( $plain );
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $aeoPolicy = AeoPolicy::fromSettings( $settings );
        $title = trim( wp_strip_all_tags( (string) $post->post_title, true ) );
        $focus = trim( (string) ( $seoValues['focus_keyphrase'] ?? '' ) );
        $relatedKeyphrases = $this->normalizeRelatedKeyphrases( $seoValues['focus_keyphrases'] ?? array(), $focus );
        // Authored metadata and rendered fallback metadata are deliberately separate.
        // A WordPress/title fallback keeps the public document technically valid, but it
        // must never be mistaken for completed SEO optimization in the editor score.
        $seoTitle = trim( (string) ( $seoValues['seo_title'] ?? '' ) );
        $metaDescription = trim( (string) ( $seoValues['meta_description'] ?? '' ) );
        $effectiveTitle = trim( (string) ( $seoValues['effective_seo_title'] ?? $seoTitle ) );
        if ( '' === $effectiveTitle ) {
            $effectiveTitle = $title;
        }
        $excerpt = $this->normalizeWhitespace( wp_strip_all_tags( (string) $post->post_excerpt, true ) );
        $effectiveDescription = trim( (string) ( $seoValues['effective_meta_description'] ?? $metaDescription ) );
        if ( '' === $effectiveDescription ) {
            $effectiveDescription = $excerpt;
        }
        $summary = '' !== $effectiveDescription ? $effectiveDescription : $excerpt;
        $intro = $this->firstWords( $plain, 120 );
        $introWordCount = $this->wordCount( $intro );
        $answerOpening = $this->firstMeaningfulParagraph( $content, $plain, $aeoPolicy->introAttentionMax() );
        $answerOpeningWordCount = $this->wordCount( $answerOpening );
        $resourceLanguageEvidence = ( new ResourceLanguageResolver() )->forPost( $post->ID );
        $resourceLanguage = (string) ( $resourceLanguageEvidence['language_key'] ?? '' );

        $structure = $this->inspectHtml( $content, $aeoPolicy->paragraphWordLimit() );
        $authorId = (int) $post->post_author;
        $authorSameAs = trim( (string) get_user_meta( $authorId, '_yps_author_same_as', true ) );
        $authorJobTitle = trim( (string) get_user_meta( $authorId, '_yps_author_job_title', true ) );
        $authorDescription = trim( (string) get_user_meta( $authorId, 'description', true ) );
        $ownerSameAs = trim( (string) ( $settings['owner_same_as'] ?? '' ) );
        $ownerName = trim( (string) ( $settings['owner_name'] ?? '' ) );
        $ownerDescription = trim( (string) ( $settings['owner_description'] ?? '' ) );

        return array(
            'version'    => self::VERSION,
            'generated'  => time(),
            'facts'      => array(
                'word_count'             => $wordCount,
                'analysis_content_source' => $contentSource,
                'intro_word_count'       => $introWordCount,
                'answer_opening_word_count' => $answerOpeningWordCount,
                'resource_language'          => $resourceLanguage,
                'related_keyphrase_count'     => count( $relatedKeyphrases ),
                'aeo_profile'              => $aeoPolicy->profile(),
                'aeo_paragraph_word_limit' => $aeoPolicy->paragraphWordLimit(),
                'heading_count'          => $structure['heading_count'],
                'question_heading_count' => $structure['question_heading_count'],
                'internal_link_count'    => $structure['internal_link_count'],
                'external_link_count'    => $structure['external_link_count'],
                'image_count'            => $structure['image_count'],
                'images_missing_alt'     => $structure['images_missing_alt'],
                'paragraph_count'        => $structure['paragraph_count'],
                'long_paragraph_count'   => $structure['long_paragraph_count'],
                'has_list_or_table'      => $structure['has_list_or_table'],
                'interactive_control_count' => $structure['interactive_control_count'],
                'unlabeled_interactive_control_count' => $structure['unlabeled_interactive_control_count'],
                'interactive_link_count' => $structure['interactive_link_count'],
                'form_control_count' => $structure['form_control_count'],
                'custom_role_control_count' => $structure['custom_role_control_count'],
                'stateful_role_issue_count' => $structure['stateful_role_issue_count'],
            ),
            'dimensions' => array(
                'search' => $this->searchChecks( $post, $focus, $relatedKeyphrases, $seoTitle, $effectiveTitle, $metaDescription, $effectiveDescription, $intro, $plain, $structure, $wordCount, $seoValues ),
                'answer' => $this->answerChecks( $focus, $summary, $answerOpening, $structure, $wordCount, $seoValues, $aeoPolicy ),
                'ai'     => $this->aiChecks( $post, $structure, $wordCount, $seoValues, $ownerName, $ownerDescription, $ownerSameAs, $authorSameAs, $authorJobTitle, $authorDescription ),
                'language' => $this->dimension( __( 'Language', 'yablonsky-seo' ), __( 'Readability, sentence flow & inclusive-language review', 'yablonsky-seo' ), array_merge( ( new ReadabilityAnalyzer() )->checks( $content, $resourceLanguage ), ( new SentenceFlowAnalyzer() )->checks( $plain, $resourceLanguage ), ( new InclusiveLanguageAnalyzer() )->checks( $plain, $resourceLanguage ) ) ),
            ),
        );
    }

    /** @return array<string,mixed> */
    private function searchChecks( \WP_Post $post, string $focus, array $relatedKeyphrases, string $seoTitle, string $effectiveTitle, string $metaDescription, string $effectiveDescription, string $intro, string $plain, array $structure, int $wordCount, array $seoValues ): array {
        $checks = array();

        // The SEO score is an optimization-readiness score, not a Google ranking prediction.
        // Critical editorial prerequisites can cap the final score even when technical
        // fallbacks make the rendered page valid. This prevents false "97/100" outcomes.
        $focusStatus = '' !== $focus ? 'good' : 'critical';
        $checks[] = $this->check(
            'focus_keyphrase',
            $focusStatus,
            __( 'Focus keyphrase', 'yablonsky-seo' ),
            '' !== $focus
                ? __( 'A focus keyphrase is set, so topical placement can be evaluated.', 'yablonsky-seo' )
                : __( 'Set a focus keyphrase before treating this resource as fully optimized.', 'yablonsky-seo' ),
            12,
            '' !== $focus ? 100 : 49,
            '' !== $focus ? 9 : 1
        );

        if ( '' !== $seoTitle ) {
            $checks[] = $this->check( 'seo_title', 'good', __( 'SEO title', 'yablonsky-seo' ), __( 'An explicit SEO title is configured.', 'yablonsky-seo' ), 10 );
        } else {
            $checks[] = $this->check(
                'seo_title',
                'critical',
                __( 'SEO title', 'yablonsky-seo' ),
                __( 'Add an explicit SEO title to complete SEO title optimization. WordPress continues to provide a technical title fallback.', 'yablonsky-seo' ),
                10,
                49,
                1
            );
        }

        $titleForAnalysis = '' !== $seoTitle ? $effectiveTitle : '';
        $titleLength = $this->stringLength( $titleForAnalysis );
        if ( '' === $seoTitle ) {
            $titleStatus = 'critical';
            $titleLengthMessage = __( 'Provide an explicit SEO title to evaluate title-length optimization.', 'yablonsky-seo' );
        } else {
            $titleStatus = ( $titleLength >= 30 && $titleLength <= 60 ) ? 'good' : ( ( $titleLength >= 20 && $titleLength <= 70 ) ? 'attention' : 'critical' );
            /* translators: %d: character count. */
            $titleLengthMessage = sprintf( __( 'SEO title is %d characters. Google uses flexible title display; this is a practical display-fit guideline.', 'yablonsky-seo' ), $titleLength );
        }
        $checks[] = $this->check( 'title_length', $titleStatus, __( 'SEO title display fit', 'yablonsky-seo' ), $titleLengthMessage, 5, 100, '' === $seoTitle ? 1 : null );

        if ( '' !== $focus ) {
            $keyphraseWords = $this->wordCount( $focus );
            $keyphraseLengthStatus = $keyphraseWords <= 6 ? 'good' : ( $keyphraseWords <= 9 ? 'attention' : 'critical' );
            /* translators: %d: number of words in the focus keyphrase. */
            $keyphraseLengthMessage = sprintf( __( 'The focus keyphrase contains %d words.', 'yablonsky-seo' ), $keyphraseWords );
            $checks[] = $this->check( 'keyphrase_length', $keyphraseLengthStatus, __( 'Keyphrase length', 'yablonsky-seo' ), $keyphraseLengthMessage, 4 );

            if ( '' === $seoTitle ) {
                $checks[] = $this->check( 'keyphrase_title', 'critical', __( 'Keyphrase in SEO title', 'yablonsky-seo' ), __( 'Add an explicit SEO title before keyphrase placement in the title can pass.', 'yablonsky-seo' ), 6, 100, 2 );
            } else {
                $titleOccurrences = $this->phraseOccurrenceCount( $titleForAnalysis, $focus );
                $titlePosition = $this->phrasePositionRatio( $titleForAnalysis, $focus );
                if ( 0 === $titleOccurrences || null === $titlePosition ) {
                    $status = 'critical';
                    $message = __( 'Rendered SEO title needs the focus keyphrase.', 'yablonsky-seo' );
                } elseif ( $titleOccurrences > 1 ) {
                    $status = 'attention';
                    $message = __( 'The focus keyphrase appears more than once in the rendered SEO title. Keep the title natural and avoid repetition.', 'yablonsky-seo' );
                } elseif ( $titlePosition <= 0.35 ) {
                    $status = 'good';
                    $message = __( 'The focus keyphrase appears early in the rendered SEO title.', 'yablonsky-seo' );
                } else {
                    $status = 'attention';
                    $message = __( 'The focus keyphrase appears in the SEO title, but moving it earlier may make the topic clearer to searchers.', 'yablonsky-seo' );
                }
                $checks[] = $this->check( 'keyphrase_title', $status, __( 'Keyphrase in SEO title', 'yablonsky-seo' ), $message, 6, 100, 'critical' === $status ? 2 : null );
            }
        }

        if ( '' !== $metaDescription ) {
            $checks[] = $this->check( 'meta_description', 'good', __( 'Meta description', 'yablonsky-seo' ), __( 'An explicit meta description is configured.', 'yablonsky-seo' ), 10 );
        } else {
            $checks[] = $this->check(
                'meta_description',
                'critical',
                __( 'Meta description', 'yablonsky-seo' ),
                __( 'Add an explicit meta description to complete the editorial search snippet. Google may still generate a snippet from page content.', 'yablonsky-seo' ),
                10,
                69,
                1
            );
        }

        $descriptionForAnalysis = '' !== $metaDescription ? $effectiveDescription : '';
        $descriptionLength = $this->stringLength( $descriptionForAnalysis );
        if ( '' === $metaDescription ) {
            $descriptionStatus = 'critical';
            $descriptionLengthMessage = __( 'Provide an explicit description to activate description-length optimization.', 'yablonsky-seo' );
        } else {
            $descriptionStatus = ( $descriptionLength >= 80 && $descriptionLength <= 155 ) ? 'good' : ( ( $descriptionLength >= 50 && $descriptionLength <= 170 ) ? 'attention' : 'critical' );
            /* translators: %d: character count. */
            $descriptionLengthMessage = sprintf( __( 'Meta description is %d characters. Google adapts snippet length to the device and may truncate the displayed text.', 'yablonsky-seo' ), $descriptionLength );
        }
        $checks[] = $this->check( 'description_length', $descriptionStatus, __( 'Meta description display fit', 'yablonsky-seo' ), $descriptionLengthMessage, 5, 100, '' === $metaDescription ? 1 : null );

        if ( '' !== $focus ) {
            if ( '' === $metaDescription ) {
                $checks[] = $this->check( 'keyphrase_description', 'critical', __( 'Keyphrase in meta description', 'yablonsky-seo' ), __( 'Add an explicit meta description before keyphrase relevance in the description can pass.', 'yablonsky-seo' ), 5, 100, 3 );
            } else {
                $descriptionOccurrences = $this->phraseOccurrenceCount( $descriptionForAnalysis, $focus );
                if ( 1 === $descriptionOccurrences ) {
                    $status = 'good';
                    $message = __( 'The focus keyphrase appears once in the meta description.', 'yablonsky-seo' );
                } elseif ( $descriptionOccurrences > 1 ) {
                    $status = 'attention';
                    $message = __( 'The focus keyphrase appears multiple times in the meta description. Keep the snippet natural and avoid repetition.', 'yablonsky-seo' );
                } else {
                    $status = 'attention';
                    $message = __( 'Consider using the focus keyphrase naturally in the meta description when it accurately reflects the page.', 'yablonsky-seo' );
                }
                $checks[] = $this->check( 'keyphrase_description', $status, __( 'Keyphrase in meta description', 'yablonsky-seo' ), $message, 5 );
            }

            $introStatus = $this->contains( $intro, $focus ) ? 'good' : 'attention';
            $checks[] = $this->check( 'keyphrase_intro', $introStatus, __( 'Keyphrase in introduction', 'yablonsky-seo' ), $introStatus === 'good' ? __( 'The focus keyphrase appears early in the content.', 'yablonsky-seo' ) : __( 'Strengthen the opening with a natural use of the focus keyphrase when appropriate.', 'yablonsky-seo' ), 8 );

            $permalinkPath = rawurldecode( (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH ) );
            $permalinkEvidence = $this->normalizeWhitespace( str_replace( array( '-', '_', '/' ), ' ', $permalinkPath ) );
            $checks[] = $this->check( 'keyphrase_url', $this->contains( $permalinkEvidence, $focus ) ? 'good' : 'attention', __( 'Keyphrase in URL', 'yablonsky-seo' ), $this->contains( $permalinkEvidence, $focus ) ? __( 'The focus keyphrase is represented in the resource URL.', 'yablonsky-seo' ) : __( 'Consider aligning the resource URL with the focus keyphrase when the editorial benefit justifies the redirect.', 'yablonsky-seo' ), 4 );

            $bodyOccurrences = $this->phraseOccurrenceCount( $plain, $focus );
            $focusWordCount = max( 1, $this->wordCount( $focus ) );
            $density = $wordCount > 0 ? ( 100.0 * $bodyOccurrences * $focusWordCount / $wordCount ) : 0.0;
            if ( $bodyOccurrences < 1 ) {
                $densityStatus = 'critical';
            } elseif ( $density >= 0.5 && $density <= 3.0 ) {
                $densityStatus = 'good';
            } elseif ( $density >= 0.2 && $density <= 4.0 ) {
                $densityStatus = 'attention';
            } else {
                $densityStatus = 'critical';
            }
            /* translators: 1: exact focus-keyphrase occurrence count, 2: approximate percentage of content words occupied by those occurrences. */
            $densityMessage = sprintf( __( 'Exact keyphrase evidence: %1$d occurrence(s), about %2$s%% of content words. Treat this as a topical and repetition guardrail; ranking outcomes remain external.', 'yablonsky-seo' ), $bodyOccurrences, number_format_i18n( $density, 1 ) );
            $checks[] = $this->check( 'keyphrase_density', $densityStatus, __( 'Keyphrase use in content', 'yablonsky-seo' ), $densityMessage, 7 );

            $checks[] = $this->check( 'keyphrase_body', $bodyOccurrences > 0 ? 'good' : 'critical', __( 'Keyphrase in content', 'yablonsky-seo' ), $bodyOccurrences > 0 ? __( 'The current visible content contains the focus keyphrase.', 'yablonsky-seo' ) : __( 'Focus keyphrase coverage: add the keyphrase to the visible content where it fits naturally.', 'yablonsky-seo' ), 6 );

            if ( $structure['heading_count'] > 0 ) {
                $headingMatches = $this->countTextMatches( (array) ( $structure['headings'] ?? array() ), $focus );
                $headingRatio = $structure['heading_count'] > 0 ? $headingMatches / (int) $structure['heading_count'] : 0.0;
                if ( $headingMatches > 0 && $headingRatio <= 0.75 ) {
                    $headingStatus = 'good';
                } elseif ( $headingMatches > 0 ) {
                    $headingStatus = 'attention';
                } else {
                    $headingStatus = 'attention';
                }
                /* translators: 1: matching H2-H4 heading count, 2: total H2-H4 heading count. */
                $headingKeyphraseMessage = sprintf( __( '%1$d of %2$d H2–H4 headings use the focus keyphrase. Use it where it helps readers and keep headings natural.', 'yablonsky-seo' ), $headingMatches, (int) $structure['heading_count'] );
                $checks[] = $this->check( 'keyphrase_subheading', $headingStatus, __( 'Keyphrase in subheadings', 'yablonsky-seo' ), $headingKeyphraseMessage, 4 );
            }

            if ( (int) $structure['image_count'] > 0 ) {
                $altMatches = $this->countTextMatches( (array) ( $structure['image_alt_texts'] ?? array() ), $focus );
                /* translators: %d: number of image alt texts containing the focus keyphrase. */
                $altKeyphraseMessage = sprintf( __( '%d image alt text(s) contain the focus keyphrase. Only use the phrase when it genuinely describes the image.', 'yablonsky-seo' ), $altMatches );
                $checks[] = $this->check( 'keyphrase_image_alt', $altMatches > 0 ? 'good' : 'attention', __( 'Keyphrase in image alt text', 'yablonsky-seo' ), $altKeyphraseMessage, 2 );
            }
        }

        if ( ! empty( $relatedKeyphrases ) ) {
            $covered = 0;
            $prominent = 0;
            $missing = array();
            foreach ( $relatedKeyphrases as $relatedKeyphrase ) {
                $bodyCount = $this->phraseOccurrenceCount( $plain, $relatedKeyphrase );
                if ( $bodyCount > 0 ) { ++$covered; }
                else { $missing[] = $relatedKeyphrase; }
                if ( $this->contains( $effectiveTitle, $relatedKeyphrase ) || $this->contains( $effectiveDescription, $relatedKeyphrase ) || $this->contains( $intro, $relatedKeyphrase ) || $this->countTextMatches( (array) ( $structure['headings'] ?? array() ), $relatedKeyphrase ) > 0 ) {
                    ++$prominent;
                }
            }
            $totalRelated = count( $relatedKeyphrases );
            if ( $covered === $totalRelated ) { $relatedStatus = 'good'; }
            elseif ( $covered > 0 ) { $relatedStatus = 'attention'; }
            else { $relatedStatus = 'critical'; }
            if ( empty( $missing ) ) {
                /* translators: 1: number of related keyphrases found in current content, 2: number of related keyphrases with supporting placement evidence. */
                $relatedMessage = sprintf( __( 'All %1$d related keyphrases appear in current content; %2$d also have supporting placement in the title, description, opening or headings.', 'yablonsky-seo' ), $covered, $prominent );
            } else {
                /* translators: 1: number of related keyphrases found in current content, 2: total related keyphrase count, 3: comma-separated examples that are missing from current content. */
                $relatedMessage = sprintf( __( '%1$d of %2$d related keyphrases appear in current content. Missing from current content: %3$s.', 'yablonsky-seo' ), $covered, $totalRelated, implode( ', ', array_slice( $missing, 0, 3 ) ) );
            }
            $checks[] = $this->check( 'related_keyphrase_coverage', $relatedStatus, __( 'Related keyphrase coverage', 'yablonsky-seo' ), $relatedMessage, 5 );
        }

        /* translators: %d: word count. */
        $contentDepthMessage = sprintf( __( 'Current content contains about %d words.', 'yablonsky-seo' ), $wordCount );
        $checks[] = $this->check( 'content_depth', $wordCount >= 300 ? 'good' : ( $wordCount >= 150 ? 'attention' : 'critical' ), __( 'Content depth', 'yablonsky-seo' ), $contentDepthMessage, 5 );

        if ( $structure['heading_count'] > 0 ) {
            /* translators: %d: heading count. */
            $headingMessage = sprintf( __( '%d H2–H4 headings make the page easier to scan.', 'yablonsky-seo' ), $structure['heading_count'] );
        } else {
            $headingMessage = __( 'Add an H2–H4 heading where it genuinely improves structure and scanning.', 'yablonsky-seo' );
        }
        $checks[] = $this->check( 'headings', $structure['heading_count'] > 0 ? 'good' : ( $wordCount < 200 ? 'attention' : 'critical' ), __( 'Heading structure', 'yablonsky-seo' ), $headingMessage, 4 );

        if ( $structure['internal_link_count'] > 0 ) {
            /* translators: %d: internal link count. */
            $internalLinkMessage = sprintf( __( '%d internal links were found.', 'yablonsky-seo' ), $structure['internal_link_count'] );
        } else {
            $internalLinkMessage = __( 'Add internal links where they genuinely help readers navigate related content.', 'yablonsky-seo' );
        }
        $checks[] = $this->check( 'internal_links', $structure['internal_link_count'] > 0 ? 'good' : 'attention', __( 'Internal links', 'yablonsky-seo' ), $internalLinkMessage, 3 );

        if ( $structure['external_link_count'] > 0 ) {
            /* translators: %d: external link count. */
            $externalLinkMessage = sprintf( __( '%d outbound evidence links were found.', 'yablonsky-seo' ), $structure['external_link_count'] );
        } else {
            $externalLinkMessage = __( 'Add outbound evidence links when external sources genuinely strengthen the content.', 'yablonsky-seo' );
        }
        $checks[] = $this->check( 'outbound_links', $structure['external_link_count'] > 0 ? 'good' : 'attention', __( 'Outbound evidence links', 'yablonsky-seo' ), $externalLinkMessage, 2 );

        if ( $structure['image_count'] > 0 ) {
            $describedImages = max( 0, (int) $structure['image_count'] - (int) $structure['images_missing_alt'] );
            /* translators: 1: images with non-empty alt text, 2: total images. */
            $altMessage = sprintf( __( '%1$d of %2$d images have non-empty alt text.', 'yablonsky-seo' ), $describedImages, (int) $structure['image_count'] );
            $altStatus = 0 === (int) $structure['images_missing_alt'] ? 'good' : ( $describedImages > 0 ? 'attention' : 'critical' );
            $checks[] = $this->check( 'image_alt_coverage', $altStatus, __( 'Image alt coverage', 'yablonsky-seo' ), $altMessage, 2 );
        }

        $isNoindex = ! empty( $seoValues['noindex'] );
        $checks[] = $this->check(
            'indexability',
            $isNoindex ? 'critical' : 'good',
            __( 'Indexability', 'yablonsky-seo' ),
            $isNoindex ? __( 'This content is explicitly set to noindex, so search optimization readiness is intentionally capped.', 'yablonsky-seo' ) : __( 'Resource-level indexing remains enabled.', 'yablonsky-seo' ),
            10,
            $isNoindex ? 20 : 100
        );

        if ( 'product' === $post->post_type ) {
            $checks = array_merge( $checks, $this->productSearchChecks( $post->ID ) );
        }

        return $this->searchDimension( __( 'SEO', 'yablonsky-seo' ), __( 'Search discovery & snippet readiness', 'yablonsky-seo' ), $checks );
    }

    /** @param mixed $raw @return array<int,string> */
    private function normalizeRelatedKeyphrases( $raw, string $primary ): array {
        $items = array();
        if ( is_array( $raw ) ) { $items = $raw; }
        elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $walker = function ( $value ) use ( &$items, &$walker ): void {
                    if ( is_scalar( $value ) ) { $items[] = (string) $value; return; }
                    if ( is_array( $value ) ) { foreach ( $value as $child ) { $walker( $child ); } }
                };
                $walker( $decoded );
            } else { $items = preg_split( '/[\r\n,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: array(); }
        }
        $primaryKey = $this->normalizeSearchText( $primary );
        $clean = array(); $seen = array();
        foreach ( $items as $item ) {
            if ( ! is_scalar( $item ) ) { continue; }
            $value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $item, true ) ) ?? '' );
            if ( '' === $value ) { continue; }
            $key = $this->normalizeSearchText( $value );
            if ( '' === $key || $key === $primaryKey || isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true; $clean[] = $value;
            if ( count( $clean ) >= 4 ) { break; }
        }
        return $clean;
    }

    /** @return array<int,array<string,mixed>> */
    private function productSearchChecks( int $postId ): array {
        $truth = ( new WooProductTruthResolver() )->resolve( $postId );
        if ( null === $truth ) { return array(); }
        $checks = array();
        $sku = trim( (string) $truth->get( 'sku' ) );
        $gtin = trim( (string) $truth->get( 'gtin' ) );
        $mpn = trim( (string) $truth->get( 'mpn' ) );
        $identifierCount = ( '' !== $sku ? 1 : 0 ) + ( '' !== $gtin ? 1 : 0 ) + ( '' !== $mpn ? 1 : 0 );
        $checks[] = $this->check( 'commerce_identifier', $identifierCount > 0 ? 'good' : 'critical', __( 'Product identifiers', 'yablonsky-seo' ), $identifierCount > 0 ? __( 'WooCommerce exposes at least one stable product identifier (SKU, GTIN or MPN).', 'yablonsky-seo' ) : __( 'Add a stable SKU, GTIN/global unique ID or MPN when the product has one.', 'yablonsky-seo' ), 10 );
        $price = $truth->get( 'price' );
        $currency = trim( (string) $truth->get( 'currency' ) );
        $availability = trim( (string) $truth->get( 'availability' ) );
        $commercialComplete = null !== $price && '' !== (string) $price && '' !== $currency && '' !== $availability;
        $checks[] = $this->check( 'commerce_offer', $commercialComplete ? 'good' : 'critical', __( 'Product offer truth', 'yablonsky-seo' ), $commercialComplete ? __( 'Price, currency and availability are available from WooCommerce for search and Schema surfaces.', 'yablonsky-seo' ) : __( 'A complete WooCommerce product offer requires price, currency and availability.', 'yablonsky-seo' ), 12 );
        $brand = trim( (string) $truth->get( 'brand' ) );
        $checks[] = $this->check( 'commerce_brand', '' !== $brand ? 'good' : 'attention', __( 'Product brand', 'yablonsky-seo' ), '' !== $brand ? __( 'A product brand is available from the WooCommerce brand taxonomy or attribute.', 'yablonsky-seo' ) : __( 'Add a product brand when brand identity is meaningful for this catalog.', 'yablonsky-seo' ), 5 );
        $images = $truth->get( 'images' );
        $imageCount = is_array( $images ) ? count( $images ) : 0;
        /* translators: %d: number of WooCommerce product images available. */
        $imageMessage = $imageCount > 0 ? sprintf( _n( '%d WooCommerce product image is available.', '%d WooCommerce product images are available.', $imageCount, 'yablonsky-seo' ), $imageCount ) : __( 'Add a WooCommerce product image when a representative product image is available.', 'yablonsky-seo' );
        $checks[] = $this->check( 'commerce_images', $imageCount > 0 ? 'good' : 'critical', __( 'Product imagery', 'yablonsky-seo' ), $imageMessage, 8 );
        $category = trim( (string) $truth->get( 'category' ) );
        $specs = $truth->get( 'specs' );
        $hasSpecs = is_array( $specs ) && ! empty( $specs );
        $checks[] = $this->check( 'commerce_taxonomy_specs', '' !== $category && $hasSpecs ? 'good' : ( '' !== $category || $hasSpecs ? 'attention' : 'critical' ), __( 'Product classification & attributes', 'yablonsky-seo' ), '' !== $category && $hasSpecs ? __( 'The product has both category context and structured WooCommerce attributes.', 'yablonsky-seo' ) : __( 'Add meaningful product categories and attributes so search and shoppers can understand the item more precisely.', 'yablonsky-seo' ), 7 );
        if ( 'variable' === (string) $truth->get( 'product_type' ) ) {
            $variantCount = max( 0, (int) $truth->get( 'variant_count' ) );
            $variesBy = $truth->get( 'varies_by' );
            $variantReady = $variantCount > 0 && is_array( $variesBy ) && ! empty( $variesBy );
            $checks[] = $this->check( 'commerce_variants', $variantReady ? 'good' : 'attention', __( 'Variation structure', 'yablonsky-seo' ), $variantReady ? __( 'Variation count and differentiating attributes are available from WooCommerce.', 'yablonsky-seo' ) : __( 'Complete the variable product structure with differentiating variation attributes.', 'yablonsky-seo' ), 5 );
        }
        return $checks;
    }

    /** @return array<string,mixed> */
    private function answerChecks( string $focus, string $summary, string $opening, array $structure, int $wordCount, array $seoValues, AeoPolicy $policy ): array {
        $checks = array();
        $openingWords = $this->wordCount( $opening );
        $checks[] = $this->check( 'answer_summary', '' !== $summary ? 'good' : 'attention', __( 'Extractable summary', 'yablonsky-seo' ), '' !== $summary ? __( 'A concise summary is available through the meta description or excerpt.', 'yablonsky-seo' ) : __( 'Add a meta description or excerpt that can act as a concise summary.', 'yablonsky-seo' ), 12 );
        /* translators: 1: first meaningful paragraph word count, 2: active AEO guidance profile. */
        /* translators: 1: opening paragraph word count, 2: active AEO guidance profile. */
        $introMessage = sprintf( __( 'The first meaningful paragraph contains about %1$d words under the %2$s AEO guidance profile.', 'yablonsky-seo' ), $openingWords, $policy->profile() );
        $introStatus = ( $openingWords >= $policy->introGoodMin() && $openingWords <= $policy->introGoodMax() ) ? 'good' : ( ( $openingWords >= $policy->introAttentionMin() && $openingWords <= $policy->introAttentionMax() ) ? 'attention' : 'critical' );
        $checks[] = $this->check( 'answer_intro', $introStatus, __( 'Opening answer window', 'yablonsky-seo' ), $introMessage, 12 );
        /* translators: %d: heading count. */
        $answerHeadingMessage = sprintf( __( '%d H2–H4 headings were found.', 'yablonsky-seo' ), $structure['heading_count'] );
        $checks[] = $this->check( 'answer_headings', $structure['heading_count'] >= 2 ? 'good' : ( 1 === $structure['heading_count'] ? 'attention' : 'critical' ), __( 'Section structure', 'yablonsky-seo' ), $answerHeadingMessage, 14 );
        if ( $policy->questionHeadingGuidance() ) {
            if ( $structure['question_heading_count'] > 0 ) {
                /* translators: %d: question heading count. */
                $questionHeadingMessage = sprintf( __( '%d headings are phrased as direct questions.', 'yablonsky-seo' ), $structure['question_heading_count'] );
            } else {
                $questionHeadingMessage = __( 'A direct-question heading is optional. Use one for answer-oriented resources when it reads naturally.', 'yablonsky-seo' );
            }
            $checks[] = $this->check( 'question_headings', $structure['question_heading_count'] > 0 ? 'good' : 'attention', __( 'Question-led sections', 'yablonsky-seo' ), $questionHeadingMessage, 8 );
        }
        if ( $policy->compactStructureGuidance() ) {
            $checks[] = $this->check( 'answer_structure', $structure['has_list_or_table'] ? 'good' : 'attention', __( 'Extractable structure', 'yablonsky-seo' ), $structure['has_list_or_table'] ? __( 'A list or table provides a compact machine-readable answer structure.', 'yablonsky-seo' ) : __( 'Use a list or table when it genuinely improves the answer; prose remains appropriate elsewhere.', 'yablonsky-seo' ), 8 );
        }
        if ( 0 === $structure['long_paragraph_count'] ) {
            /* translators: %d: active dense-paragraph word threshold. */
            $paragraphMessage = sprintf( __( 'Saved paragraphs stay within the active %d-word density threshold.', 'yablonsky-seo' ), $policy->paragraphWordLimit() );
        } else {
            /* translators: 1: paragraph count, 2: active dense-paragraph word threshold. */
            /* translators: 1: dense paragraph count, 2: active paragraph word threshold. */
            $paragraphMessage = sprintf( __( '%1$d paragraphs exceed the active %2$d-word density threshold and may be harder to extract.', 'yablonsky-seo' ), $structure['long_paragraph_count'], $policy->paragraphWordLimit() );
        }
        $checks[] = $this->check( 'paragraph_density', 0 === $structure['long_paragraph_count'] ? 'good' : ( $structure['long_paragraph_count'] <= 2 ? 'attention' : 'critical' ), __( 'Paragraph density', 'yablonsky-seo' ), $paragraphMessage, 10 );
        /* translators: 1: word count, 2: active AEO target word count. */
        /* translators: 1: saved visible word count, 2: active AEO depth guidance target. */
        $answerDepthMessage = sprintf( __( 'Saved content contains about %1$d words; the active AEO guidance target is %2$d or more where that depth is genuinely useful.', 'yablonsky-seo' ), $wordCount, $policy->depthGood() );
        $checks[] = $this->check( 'answer_depth', $wordCount >= $policy->depthGood() ? 'good' : ( $wordCount >= $policy->depthAttention() ? 'attention' : 'critical' ), __( 'Answer depth', 'yablonsky-seo' ), $answerDepthMessage, 10 );
        if ( '' !== $focus ) {
            $checks[] = $this->check( 'answer_focus', $this->contains( $opening, $focus ) ? 'good' : 'attention', __( 'Topic clarity', 'yablonsky-seo' ), $this->contains( $opening, $focus ) ? __( 'The focus topic is explicit in the first meaningful paragraph.', 'yablonsky-seo' ) : __( 'Make the main topic explicit near the beginning if that reads naturally.', 'yablonsky-seo' ), 10 );
        }
        $schemaPageType = trim( (string) ( $seoValues['schema_page_type'] ?? '' ) );
        if ( 'faqpage' === $schemaPageType ) {
            if ( $policy->faqGuardrail() && 0 === $structure['question_heading_count'] ) {
                $checks[] = $this->check( 'answer_schema', 'attention', __( 'FAQPage guardrail', 'yablonsky-seo' ), __( 'FAQPage is selected. Verify that saved content includes a genuine question-led FAQ structure before keeping this schema type.', 'yablonsky-seo' ), 6 );
            } else {
                $checks[] = $this->check( 'answer_schema', 'good', __( 'FAQPage guardrail', 'yablonsky-seo' ), __( 'FAQPage is explicitly selected and matches visible FAQ content. Keep it for semantic accuracy; Google Search rich-result visibility is limited to eligible authoritative government and health sites.', 'yablonsky-seo' ), 6 );
            }
        }
        $checks[] = $this->check( 'answer_indexable', ! empty( $seoValues['noindex'] ) ? 'critical' : 'good', __( 'Answer discoverability', 'yablonsky-seo' ), ! empty( $seoValues['noindex'] ) ? __( 'Noindex prevents normal search discovery of this page.', 'yablonsky-seo' ) : __( 'The resource is eligible for normal search discovery.', 'yablonsky-seo' ), 12 );

        return $this->dimension( __( 'AEO', 'yablonsky-seo' ), __( 'Answer extraction readiness', 'yablonsky-seo' ), $checks );
    }

    /** @return array<string,mixed> */
    private function aiChecks( \WP_Post $post, array $structure, int $wordCount, array $seoValues, string $ownerName, string $ownerDescription, string $ownerSameAs, string $authorSameAs, string $authorJobTitle, string $authorDescription ): array {
        $checks = array();
        $entityType = trim( (string) ( $seoValues['schema_entity_type'] ?? '' ) );
        $entityName = trim( (string) ( $seoValues['schema_entity_name'] ?? '' ) );
        $hasEntity = '' !== $entityType && '' !== $entityName;
        $hasSiteIdentity = '' !== $ownerName && '' !== $ownerDescription;
        $hasExternalIdentity = $this->hasExternalSameAs( $ownerSameAs ) || $this->hasExternalSameAs( $authorSameAs );
        $hasAuthorContext = '' !== $authorJobTitle || '' !== $authorDescription || '' !== $authorSameAs;

        $checks[] = $this->check( 'ai_indexable', ! empty( $seoValues['noindex'] ) ? 'critical' : 'good', __( 'Discovery eligibility', 'yablonsky-seo' ), ! empty( $seoValues['noindex'] ) ? __( 'This resource is explicitly noindex.', 'yablonsky-seo' ) : __( 'This resource is eligible for normal search and retrieval discovery.', 'yablonsky-seo' ), 12 );
        $checks[] = $this->check( 'ai_entity', ( $hasEntity || $hasSiteIdentity ) ? 'good' : 'critical', __( 'Entity declaration', 'yablonsky-seo' ), $hasEntity ? __( 'This page declares a primary entity.', 'yablonsky-seo' ) : ( $hasSiteIdentity ? __( 'A site-level owner entity is configured and can anchor this page.', 'yablonsky-seo' ) : __( 'Configure a site owner identity or a page-level primary entity.', 'yablonsky-seo' ) ), 16 );
        $checks[] = $this->check( 'ai_corroboration', $hasExternalIdentity ? 'good' : 'attention', __( 'External corroboration', 'yablonsky-seo' ), $hasExternalIdentity ? __( 'External sameAs identity evidence is available.', 'yablonsky-seo' ) : __( 'Add external sameAs identity evidence for the owner or author when authoritative profiles are available.', 'yablonsky-seo' ), 14 );
        $checks[] = $this->check( 'ai_author', $hasAuthorContext ? 'good' : 'attention', __( 'Author context', 'yablonsky-seo' ), $hasAuthorContext ? __( 'Author profile context is available for entity resolution.', 'yablonsky-seo' ) : __( 'Add useful author description, role or external profile data when authorship matters.', 'yablonsky-seo' ), 10 );
        $checks[] = $this->check( 'ai_schema', ( '' !== trim( (string) ( $seoValues['schema_page_type'] ?? '' ) ) || 'post' === $post->post_type || $hasEntity ) ? 'good' : 'attention', __( 'Structured data context', 'yablonsky-seo' ), __( 'Yablonsky SEO can emit a connected WebPage graph; explicit page/entity types add detail when appropriate.', 'yablonsky-seo' ), 10 );
        if ( $structure['external_link_count'] > 0 ) {
            /* translators: %d: external link count. */
            $externalLinkMessage = sprintf( __( '%d external links provide potential evidence or corroboration paths.', 'yablonsky-seo' ), $structure['external_link_count'] );
        } else {
            $externalLinkMessage = __( 'Add external evidence links where they genuinely support claims.', 'yablonsky-seo' );
        }
        $checks[] = $this->check( 'ai_citations', $structure['external_link_count'] > 0 ? 'good' : 'attention', __( 'External evidence links', 'yablonsky-seo' ), $externalLinkMessage, 10 );
        if ( $structure['image_count'] > 0 ) {
            if ( 0 === $structure['images_missing_alt'] ) {
                $imageMessage = __( 'All saved content images have non-empty alt text.', 'yablonsky-seo' );
            } else {
                /* translators: 1: missing alt count, 2: image count. */
                /* translators: 1: images missing alt text, 2: total saved-content images. */
                $imageMessage = sprintf( __( '%1$d of %2$d saved content images have empty alt text. Decorative images may intentionally be empty.', 'yablonsky-seo' ), $structure['images_missing_alt'], $structure['image_count'] );
            }
            $checks[] = $this->check( 'ai_images', 0 === $structure['images_missing_alt'] ? 'good' : ( $structure['images_missing_alt'] < $structure['image_count'] ? 'attention' : 'critical' ), __( 'Image semantics', 'yablonsky-seo' ), $imageMessage, 10 );
        } else {
            $checks[] = $this->check( 'ai_images', 'attention', __( 'Image semantics', 'yablonsky-seo' ), __( 'Inline content images are optional. Add them where they genuinely improve the page.', 'yablonsky-seo' ), 4 );
        }
        $preferredImage = trim( (string) ( $seoValues['og_image'] ?? '' ) );
        $checks[] = $this->check( 'ai_preferred_image', '' !== $preferredImage ? 'good' : 'attention', __( 'Preferred page image', 'yablonsky-seo' ), '' !== $preferredImage ? __( 'A preferred Search/Social image is configured for Open Graph and structured-data image signals.', 'yablonsky-seo' ) : __( 'A preferred Search/Social image is optional. Add a representative high-quality image when it strengthens machine understanding and presentation.', 'yablonsky-seo' ), 6 );
        $publicSettings = get_option( 'yps_public_settings', array() );
        $agentMarkdown = is_array( $publicSettings ) && ! empty( $publicSettings['agent_markdown_enabled'] );
        $scopedLlms = $agentMarkdown && ! empty( $publicSettings['agent_scoped_llms_enabled'] );
        $agentSitemap = $agentMarkdown && ! empty( $publicSettings['agent_sitemap_extension_enabled'] );
        $checks[] = $this->check( 'ai_markdown_alternate', $agentMarkdown ? 'good' : 'attention', __( 'Agent-readable alternate', 'yablonsky-seo' ), $agentMarkdown ? __( 'Agent-readable Markdown alternates are enabled for public content when Yablonsky SEO owns authority.', 'yablonsky-seo' ) : __( 'Optional Markdown alternates are inactive. HTML remains fully usable; enable them when you want a dedicated agent-readable representation.', 'yablonsky-seo' ), 4 );
        $checks[] = $this->check( 'ai_scoped_llms', $scopedLlms ? 'good' : 'attention', __( 'Scoped agent orientation', 'yablonsky-seo' ), $scopedLlms ? __( 'Path-level llms.txt indexes can guide agents into resolvable site sections in addition to the root orientation file.', 'yablonsky-seo' ) : __( 'Only the root llms.txt orientation is configured. Scoped indexes are optional for larger or strongly segmented sites.', 'yablonsky-seo' ), 3 );
        $checks[] = $this->check( 'ai_agent_sitemap', $agentSitemap ? 'good' : 'attention', __( 'Agent sitemap discovery', 'yablonsky-seo' ), $agentSitemap ? __( 'Canonical sitemap entries can advertise their Markdown alternate and covering llms.txt through the experimental namespaced agent extension.', 'yablonsky-seo' ) : __( 'Experimental sitemap agent extension: Standby. Standard sitemap discovery remains active.', 'yablonsky-seo' ), 3 );
        if ( $structure['interactive_control_count'] > 0 ) {
            $unlabeled = (int) $structure['unlabeled_interactive_control_count'];
            $totalControls = (int) $structure['interactive_control_count'];
            if ( 0 === $unlabeled ) {
                /* translators: %d: total detected interactive element count. */
                $interactionMessage = sprintf( __( 'All %d detected saved-content interactive elements expose a local naming signal in saved markup for user-directed agents and assistive technology.', 'yablonsky-seo' ), $totalControls );
            } else {
                /* translators: 1: unlabeled interactive element count, 2: total interactive element count. */
                /* translators: 1: unlabeled interactive element count, 2: total interactive element count. */
                $interactionMessage = sprintf( __( '%1$d of %2$d detected saved-content interactive elements lack visible text or a local naming signal in saved markup. Review links, buttons and form fields for visible labels, aria-label/aria-labelledby, associated label elements, title text or other native naming.', 'yablonsky-seo' ), $unlabeled, $totalControls );
            }
            $checks[] = $this->check( 'ai_interaction_semantics', 0 === $unlabeled ? 'good' : 'attention', __( 'Agent interaction semantics', 'yablonsky-seo' ), $interactionMessage, 6 );
        }
        if ( $structure['custom_role_control_count'] > 0 ) {
            $stateIssues = (int) $structure['stateful_role_issue_count'];
            $customRoles = (int) $structure['custom_role_control_count'];
            if ( 0 === $stateIssues ) {
                /* translators: %d: detected custom ARIA interactive-role count. */
                $stateMessage = sprintf( __( '%d detected custom ARIA interactive roles expose the high-confidence state semantics YSEO can verify locally.', 'yablonsky-seo' ), $customRoles );
            } else {
                /* translators: 1: state-semantic issue count, 2: custom ARIA interactive-role count. */
                /* translators: 1: ARIA interactive-role state issues, 2: total detected custom ARIA roles. */
                $stateMessage = sprintf( __( '%1$d of %2$d detected custom ARIA interactive roles use checkbox/radio/switch semantics without a valid aria-checked state in saved markup.', 'yablonsky-seo' ), $stateIssues, $customRoles );
            }
            $checks[] = $this->check( 'ai_interaction_state', 0 === $stateIssues ? 'good' : 'attention', __( 'Agent interaction state', 'yablonsky-seo' ), $stateMessage, 4 );
        }
        $checks[] = $this->check( 'ai_structure', ( $structure['heading_count'] > 0 && ( $structure['has_list_or_table'] || $structure['paragraph_count'] > 1 ) ) ? 'good' : 'attention', __( 'Machine-readable structure', 'yablonsky-seo' ), __( 'Headings, short paragraphs, lists and tables can make visible content easier to parse without changing its meaning.', 'yablonsky-seo' ), 8 );
        $checks[] = $this->check( 'ai_freshness', '' !== (string) $post->post_modified_gmt ? 'good' : 'attention', __( 'Freshness signal', 'yablonsky-seo' ), '' !== (string) $post->post_modified_gmt ? __( 'A WordPress modified timestamp is available for this resource.', 'yablonsky-seo' ) : __( 'Modified timestamp will appear when the resource provides one.', 'yablonsky-seo' ), 5 );
        /* translators: %d: word count. */
        $aiDepthMessage = sprintf( __( 'Current content contains about %d words of visible text.', 'yablonsky-seo' ), $wordCount );
        $checks[] = $this->check( 'ai_depth', $wordCount >= 250 ? 'good' : ( $wordCount >= 120 ? 'attention' : 'critical' ), __( 'Context depth', 'yablonsky-seo' ), $aiDepthMessage, 5 );

        return $this->dimension( __( 'GEO', 'yablonsky-seo' ), __( 'AI understanding & retrieval readiness', 'yablonsky-seo' ), $checks );
    }

    /**
     * Search scoring follows a Yoast-informed assessment model: each applicable
     * check contributes a 1–9 assessment score and the mean is normalized to
     * 100. YSEO keeps explicit product gates for authored metadata/indexability
     * so a strong body cannot hide a missing critical editorial prerequisite.
     *
     * @param array<int,array<string,mixed>> $checks
     * @return array<string,mixed>
     */
    private function searchDimension( string $label, string $subtitle, array $checks ): array {
        $earned = 0.0;
        $possible = 0.0;
        $scoreCap = 100;
        foreach ( $checks as $check ) {
            $assessmentScore = isset( $check['assessment_score'] ) ? (float) $check['assessment_score'] : 0.0;
            $assessmentScore = max( 0.0, min( 9.0, $assessmentScore ) );
            $earned += $assessmentScore;
            $possible += 9.0;
            if ( isset( $check['score_cap'] ) ) {
                $scoreCap = min( $scoreCap, max( 0, min( 100, (int) $check['score_cap'] ) ) );
            }
        }
        $rawScore = $possible > 0 ? (int) round( 100 * $earned / $possible ) : 0;
        $score = min( $rawScore, $scoreCap );
        $status = $score >= 90 ? 'good' : ( $score >= 60 ? 'attention' : 'critical' );
        return array(
            'label'     => $label,
            'subtitle'  => $subtitle,
            'score'     => $score,
            'raw_score' => $rawScore,
            'earned_points' => $earned,
            'possible_points' => $possible,
            'score_cap' => $scoreCap,
            'scoring_model' => 'assessment-9-v1',
            'status'    => $status,
            'checks'    => $checks,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @return array<string,mixed>
     */
    private function dimension( string $label, string $subtitle, array $checks ): array {
        $earned = 0.0;
        $possible = 0.0;
        $scoreCap = 100;
        foreach ( $checks as $check ) {
            $weight = (float) $check['weight'];
            $possible += $weight;
            if ( 'good' === $check['status'] ) {
                $earned += $weight;
            } elseif ( 'attention' === $check['status'] ) {
                $earned += $weight * 0.5;
            }
            if ( isset( $check['score_cap'] ) ) {
                $scoreCap = min( $scoreCap, max( 0, min( 100, (int) $check['score_cap'] ) ) );
            }
        }
        $rawScore = $possible > 0 ? (int) round( 100 * $earned / $possible ) : 0;
        $score = min( $rawScore, $scoreCap );
        $status = $score >= 80 ? 'good' : ( $score >= 55 ? 'attention' : 'critical' );
        return array(
            'label'     => $label,
            'subtitle'  => $subtitle,
            'score'     => $score,
            'raw_score' => $rawScore,
            'earned_points' => $earned,
            'possible_points' => $possible,
            'score_cap' => $scoreCap,
            'status'    => $status,
            'checks'    => $checks,
        );
    }

    /** @return array<string,mixed> */
    private function check( string $id, string $status, string $title, string $message, int $weight, int $scoreCap = 100, ?int $assessmentScore = null ): array {
        if ( null === $assessmentScore ) {
            $assessmentScore = 'good' === $status ? 9 : ( 'attention' === $status ? 6 : 3 );
        }
        return array(
            'id'        => $id,
            'status'    => $status,
            'title'     => $title,
            'message'   => $message,
            'weight'    => $weight,
            'assessment_score' => max( 0, min( 9, $assessmentScore ) ),
            'score_cap' => max( 0, min( 100, $scoreCap ) ),
        );
    }

    /** @return array<string,mixed> */
    private function inspectHtml( string $html, int $longParagraphWordLimit = 120 ): array {
        $headings = array();
        if ( preg_match_all( '/<h[2-4]\b[^>]*>(.*?)<\/h[2-4]>/is', $html, $matches ) ) {
            foreach ( $matches[1] as $headingHtml ) {
                $heading = $this->normalizeWhitespace( wp_strip_all_tags( (string) $headingHtml, true ) );
                if ( '' !== $heading ) {
                    $headings[] = $heading;
                }
            }
        }
        $questionHeadings = 0;
        foreach ( $headings as $heading ) {
            if ( false !== strpos( $heading, '?' ) ) {
                ++$questionHeadings;
            }
        }

        $internalLinks = 0;
        $externalLinks = 0;
        $homeHost = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( preg_match_all( '/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1/is', $html, $linkMatches ) ) {
            foreach ( $linkMatches[2] as $href ) {
                $href = html_entity_decode( trim( (string) $href ), ENT_QUOTES, 'UTF-8' );
                if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
                    continue;
                }
                $host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
                if ( '' === $host || $host === $homeHost ) {
                    ++$internalLinks;
                } else {
                    ++$externalLinks;
                }
            }
        }

        $imageCount = 0;
        $missingAlt = 0;
        $imageAltTexts = array();
        if ( preg_match_all( '/<img\b[^>]*>/is', $html, $imageMatches ) ) {
            foreach ( $imageMatches[0] as $imageTag ) {
                ++$imageCount;
                if ( ! preg_match( '/\balt\s*=\s*(["\'])(.*?)\1/is', (string) $imageTag, $altMatch ) ) {
                    ++$missingAlt;
                    continue;
                }
                $altText = $this->normalizeWhitespace( html_entity_decode( (string) $altMatch[2], ENT_QUOTES, 'UTF-8' ) );
                if ( '' === $altText ) {
                    ++$missingAlt;
                } else {
                    $imageAltTexts[] = $altText;
                }
            }
        }

        $paragraphCount = 0;
        $longParagraphs = 0;
        if ( preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $html, $paragraphMatches ) ) {
            foreach ( $paragraphMatches[1] as $paragraphHtml ) {
                $paragraph = $this->normalizeWhitespace( wp_strip_all_tags( (string) $paragraphHtml, true ) );
                if ( '' === $paragraph ) {
                    continue;
                }
                ++$paragraphCount;
                if ( $this->wordCount( $paragraph ) > max( 40, $longParagraphWordLimit ) ) {
                    ++$longParagraphs;
                }
            }
        }

        $labelledIds = array();
        $wrappedLabelTags = array();
        if ( preg_match_all( '/<label\b([^>]*)>(.*?)<\/label>/is', $html, $labelMatches, PREG_SET_ORDER ) ) {
            foreach ( $labelMatches as $labelMatch ) {
                $labelAttributes = (string) $labelMatch[1];
                $labelBody = (string) $labelMatch[2];
                $labelText = $this->normalizeWhitespace( wp_strip_all_tags( $labelBody, true ) );
                if ( '' === $labelText ) { continue; }
                if ( preg_match( '/\bfor\s*=\s*(["\'])(.*?)\1/is', $labelAttributes, $forMatch ) ) {
                    $id = trim( html_entity_decode( (string) $forMatch[2], ENT_QUOTES, 'UTF-8' ) );
                    if ( '' !== $id ) { $labelledIds[ $id ] = true; }
                }
                if ( preg_match_all( '/<(?:input|select|textarea)\b[^>]*>/is', $labelBody, $wrappedMatches ) ) {
                    foreach ( $wrappedMatches[0] as $wrappedTag ) {
                        $wrappedLabelTags[ md5( (string) $wrappedTag ) ] = true;
                    }
                }
            }
        }

        $interactiveCount = 0;
        $unlabeledInteractive = 0;
        $interactiveLinks = 0;
        $formControls = 0;
        $customRoleControls = 0;
        $statefulRoleIssues = 0;

        if ( preg_match_all( '/<button\b[^>]*>(.*?)<\/button>/is', $html, $buttonMatches, PREG_SET_ORDER ) ) {
            foreach ( $buttonMatches as $buttonMatch ) {
                ++$interactiveCount;
                $fullTag = (string) $buttonMatch[0];
                $openingTag = preg_match( '/^<button\b[^>]*>/is', $fullTag, $openingMatch ) ? (string) $openingMatch[0] : $fullTag;
                $innerHtml = (string) $buttonMatch[1];
                $visible = $this->normalizeWhitespace( wp_strip_all_tags( $innerHtml, true ) );
                if ( ! $this->hasObservableInteractiveName( $openingTag, $visible, $labelledIds, $wrappedLabelTags, false, $innerHtml ) ) { ++$unlabeledInteractive; }
            }
        }

        if ( preg_match_all( '/<input\b[^>]*>/is', $html, $inputMatches ) ) {
            foreach ( $inputMatches[0] as $inputTag ) {
                $tag = (string) $inputTag;
                $type = 'text';
                if ( preg_match( '/\btype\s*=\s*(["\'])(.*?)\1/is', $tag, $typeMatch ) ) { $type = sanitize_key( (string) $typeMatch[2] ); }
                if ( 'hidden' === $type ) { continue; }
                ++$interactiveCount;
                ++$formControls;
                $allowValue = in_array( $type, array( 'button', 'submit', 'reset' ), true );
                $innerHtml = 'image' === $type ? $tag : '';
                if ( ! $this->hasObservableInteractiveName( $tag, '', $labelledIds, $wrappedLabelTags, $allowValue, $innerHtml ) ) { ++$unlabeledInteractive; }
            }
        }

        if ( preg_match_all( '/<(select|textarea)\b[^>]*>/is', $html, $fieldMatches ) ) {
            foreach ( $fieldMatches[0] as $fieldTag ) {
                ++$interactiveCount;
                ++$formControls;
                if ( ! $this->hasObservableInteractiveName( (string) $fieldTag, '', $labelledIds, $wrappedLabelTags, false, '' ) ) { ++$unlabeledInteractive; }
            }
        }

        if ( preg_match_all( '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $html, $anchorMatches, PREG_SET_ORDER ) ) {
            foreach ( $anchorMatches as $anchorMatch ) {
                $href = trim( html_entity_decode( (string) $anchorMatch[2], ENT_QUOTES, 'UTF-8' ) );
                if ( '' === $href ) { continue; }
                ++$interactiveCount;
                ++$interactiveLinks;
                $fullTag = (string) $anchorMatch[0];
                $openingTag = preg_match( '/^<a\b[^>]*>/is', $fullTag, $openingMatch ) ? (string) $openingMatch[0] : $fullTag;
                $innerHtml = (string) $anchorMatch[3];
                $visible = $this->normalizeWhitespace( wp_strip_all_tags( $innerHtml, true ) );
                if ( ! $this->hasObservableInteractiveName( $openingTag, $visible, $labelledIds, $wrappedLabelTags, false, $innerHtml ) ) { ++$unlabeledInteractive; }
            }
        }

        if ( preg_match_all( '/<summary\b[^>]*>(.*?)<\/summary>/is', $html, $summaryMatches, PREG_SET_ORDER ) ) {
            foreach ( $summaryMatches as $summaryMatch ) {
                ++$interactiveCount;
                $fullTag = (string) $summaryMatch[0];
                $openingTag = preg_match( '/^<summary\b[^>]*>/is', $fullTag, $openingMatch ) ? (string) $openingMatch[0] : $fullTag;
                $innerHtml = (string) $summaryMatch[1];
                $visible = $this->normalizeWhitespace( wp_strip_all_tags( $innerHtml, true ) );
                if ( ! $this->hasObservableInteractiveName( $openingTag, $visible, $labelledIds, $wrappedLabelTags, false, $innerHtml ) ) { ++$unlabeledInteractive; }
            }
        }

        if ( preg_match_all( '/<([a-z][a-z0-9:-]*)\b[^>]*\brole\s*=\s*(["\'])(button|link|checkbox|radio|switch)\2[^>]*>(.*?)<\/\1>/is', $html, $roleMatches, PREG_SET_ORDER ) ) {
            foreach ( $roleMatches as $roleMatch ) {
                $tagName = strtolower( (string) $roleMatch[1] );
                if ( in_array( $tagName, array( 'a', 'button', 'input', 'select', 'textarea', 'summary' ), true ) ) { continue; }
                $role = strtolower( (string) $roleMatch[3] );
                $fullTag = (string) $roleMatch[0];
                $openingTag = preg_match( '/^<' . preg_quote( $tagName, '/' ) . '\b[^>]*>/is', $fullTag, $openingMatch ) ? (string) $openingMatch[0] : $fullTag;
                $innerHtml = (string) $roleMatch[4];
                $visible = $this->normalizeWhitespace( wp_strip_all_tags( $innerHtml, true ) );
                ++$interactiveCount;
                ++$customRoleControls;
                if ( ! $this->hasObservableInteractiveName( $openingTag, $visible, array(), array(), false, $innerHtml ) ) { ++$unlabeledInteractive; }
                if ( in_array( $role, array( 'checkbox', 'radio', 'switch' ), true ) && ! preg_match( '/\baria-checked\s*=\s*(["\'])(?:true|false|mixed)\1/is', $openingTag ) ) {
                    ++$statefulRoleIssues;
                }
            }
        }

        return array(
            'heading_count'          => count( $headings ),
            'question_heading_count' => $questionHeadings,
            'heading_text'            => implode( ' ', $headings ),
            'headings'                => $headings,
            'internal_link_count'    => $internalLinks,
            'external_link_count'    => $externalLinks,
            'image_count'            => $imageCount,
            'images_missing_alt'     => $missingAlt,
            'image_alt_texts'         => $imageAltTexts,
            'paragraph_count'        => $paragraphCount,
            'long_paragraph_count'   => $longParagraphs,
            'has_list_or_table'      => (bool) preg_match( '/<(?:ul|ol|table)\b/i', $html ),
            'interactive_control_count' => $interactiveCount,
            'unlabeled_interactive_control_count' => $unlabeledInteractive,
            'interactive_link_count' => $interactiveLinks,
            'form_control_count' => $formControls,
            'custom_role_control_count' => $customRoleControls,
            'stateful_role_issue_count' => $statefulRoleIssues,
        );
    }

    /**
     * @param array<string,bool> $labelledIds IDs referenced by a non-empty native <label for>.
     * @param array<string,bool> $wrappedLabelTags exact opening tags wrapped by a non-empty native <label>.
     */
    private function hasObservableInteractiveName( string $tag, string $visible, array $labelledIds, array $wrappedLabelTags, bool $allowValue, string $innerHtml ): bool {
        if ( '' !== trim( $visible ) ) { return true; }
        if ( preg_match( '/\baria-(?:label|labelledby)\s*=\s*(["\'])(?!\s*\1).+?\1/is', $tag ) ) { return true; }
        if ( preg_match( '/\btitle\s*=\s*(["\'])(?!\s*\1).+?\1/is', $tag ) ) { return true; }
        if ( '' !== $innerHtml && preg_match( '/(?:<img\b[^>]*\balt|\balt)\s*=\s*(["\'])(?!\s*\1).+?\1/is', $innerHtml ) ) { return true; }
        if ( $allowValue && preg_match( '/\bvalue\s*=\s*(["\'])(?!\s*\1).+?\1/is', $tag ) ) { return true; }
        if ( isset( $wrappedLabelTags[ md5( $tag ) ] ) ) { return true; }
        if ( preg_match( '/\bid\s*=\s*(["\'])(.*?)\1/is', $tag, $idMatch ) ) {
            $id = trim( html_entity_decode( (string) $idMatch[2], ENT_QUOTES, 'UTF-8' ) );
            if ( '' !== $id && isset( $labelledIds[ $id ] ) ) { return true; }
        }
        return false;
    }

    private function hasExternalSameAs( string $raw ): bool {
        if ( '' === trim( $raw ) ) {
            return false;
        }
        $homeHost = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $parts = preg_split( '/[\s,]+/u', trim( $raw ) );
        if ( ! is_array( $parts ) ) {
            return false;
        }
        foreach ( $parts as $candidate ) {
            $candidate = trim( (string) $candidate );
            if ( '' === $candidate ) {
                continue;
            }
            $host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
            if ( '' !== $host && $host !== $homeHost ) {
                return true;
            }
        }
        return false;
    }

    private function phraseOccurrenceCount( string $haystack, string $needle ): int {
        $needle = $this->normalizeSearchText( $needle );
        $haystack = $this->normalizeSearchText( $haystack );
        if ( '' === $needle || '' === $haystack ) {
            return 0;
        }
        $pattern = '/(?<![\\p{L}\\p{N}])' . preg_quote( $needle, '/' ) . '(?![\\p{L}\\p{N}])/u';
        $matched = preg_match_all( $pattern, $haystack, $matches );
        return false === $matched ? 0 : (int) $matched;
    }

    private function phrasePositionRatio( string $haystack, string $needle ): ?float {
        $haystack = $this->normalizeSearchText( $haystack );
        $needle = $this->normalizeSearchText( $needle );
        if ( '' === $haystack || '' === $needle ) {
            return null;
        }
        if ( function_exists( 'mb_stripos' ) ) {
            $position = mb_stripos( $haystack, $needle, 0, 'UTF-8' );
            if ( false === $position ) {
                return null;
            }
            return (float) $position / max( 1, $this->stringLength( $haystack ) );
        }
        $position = stripos( $haystack, $needle );
        if ( false === $position ) {
            return null;
        }
        return (float) $position / max( 1, strlen( $haystack ) );
    }

    /** @param array<int,string> $values */
    private function countTextMatches( array $values, string $needle ): int {
        $matches = 0;
        foreach ( $values as $value ) {
            if ( $this->contains( (string) $value, $needle ) ) {
                ++$matches;
            }
        }
        return $matches;
    }

    private function normalizeSearchText( string $value ): string {
        $value = $this->normalizeWhitespace( wp_strip_all_tags( $value, true ) );
        if ( function_exists( 'mb_strtolower' ) ) {
            $value = mb_strtolower( $value, 'UTF-8' );
        } else {
            $value = strtolower( $value );
        }
        $value = preg_replace( '/[^\\p{L}\\p{N}]+/u', ' ', $value );
        return is_string( $value ) ? $this->normalizeWhitespace( $value ) : '';
    }

    private function contains( string $haystack, string $needle ): bool {
        if ( '' === $needle ) {
            return false;
        }
        if ( function_exists( 'mb_stripos' ) ) {
            return false !== mb_stripos( $haystack, $needle, 0, 'UTF-8' );
        }
        return false !== stripos( $haystack, $needle );
    }

    private function stringLength( string $value ): int {
        return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value, 'UTF-8' ) : strlen( $value );
    }

    private function normalizeWhitespace( string $value ): string {
        $value = preg_replace( '/\s+/u', ' ', trim( $value ) );
        return is_string( $value ) ? $value : '';
    }

    private function wordCount( string $value ): int {
        if ( '' === trim( $value ) ) {
            return 0;
        }
        if ( preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\p{M}\'’\-]*/u', $value, $matches ) ) {
            return count( $matches[0] );
        }
        return str_word_count( $value );
    }

    private function firstMeaningfulParagraph( string $html, string $plainFallback, int $fallbackLimit ): string {
        if ( preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches ) ) {
            foreach ( $matches[1] as $paragraphHtml ) {
                $paragraph = $this->normalizeWhitespace( wp_strip_all_tags( (string) $paragraphHtml, true ) );
                if ( '' !== $paragraph ) {
                    return $paragraph;
                }
            }
        }
        return $this->firstWords( $plainFallback, max( 1, $fallbackLimit ) );
    }

    private function firstWords( string $value, int $limit ): string {
        if ( '' === $value ) {
            return '';
        }
        if ( preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\p{M}\'’\-]*/u', $value, $matches, PREG_OFFSET_CAPTURE ) && count( $matches[0] ) > $limit ) {
            $last = $matches[0][ $limit - 1 ];
            $end = (int) $last[1] + strlen( (string) $last[0] );
            return substr( $value, 0, $end );
        }
        return $value;
    }
}
