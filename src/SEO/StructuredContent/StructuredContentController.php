<?php
namespace Yablonsky\PerformanceSEO\SEO\StructuredContent;

use Yablonsky\PerformanceSEO\Resource\ResourceIdentity;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Visible FAQ/HowTo helpers with schema derived from saved content; search-surface eligibility is tracked separately. */
final class StructuredContentController {
    private const MAX_FAQ_ITEMS = 30;
    private const MAX_HOWTO_STEPS = 50;
    /** @var bool */ private $schemaEnabled;

    public function __construct( bool $schemaEnabled ) { $this->schemaEnabled = $schemaEnabled; }

    public function register(): void {
        add_shortcode( 'yseo_faq', array( $this, 'renderFaq' ) );
        add_shortcode( 'yseo_qa', array( $this, 'renderFaqItem' ) );
        add_shortcode( 'yseo_howto', array( $this, 'renderHowTo' ) );
        add_shortcode( 'yseo_step', array( $this, 'renderHowToStep' ) );
        if ( $this->schemaEnabled ) {
            add_filter( 'yablonsky_seo_schema_graph_v1', array( $this, 'augmentSchema' ), 24, 3 );
        }
    }

    /** @param array<string,string> $atts */
    public function renderFaq( array $atts, ?string $content = null ): string {
        unset( $atts );
        if ( null === $content ) { return ''; }
        return '<section class="yseo-faq">' . do_shortcode( $content ) . '</section>';
    }

    /** @param array<string,string> $atts */
    public function renderFaqItem( array $atts, ?string $content = null ): string {
        $atts = shortcode_atts( array( 'question' => '' ), $atts, 'yseo_qa' );
        $question = trim( wp_strip_all_tags( (string) $atts['question'], true ) );
        $answer = null === $content ? '' : trim( wp_kses_post( do_shortcode( $content ) ) );
        if ( '' === $question || '' === wp_strip_all_tags( $answer, true ) ) { return ''; }
        return '<div class="yseo-faq-item"><h3>' . esc_html( $question ) . '</h3><div class="yseo-faq-answer">' . $answer . '</div></div>';
    }

    /** @param array<string,string> $atts */
    public function renderHowTo( array $atts, ?string $content = null ): string {
        $atts = shortcode_atts( array( 'name' => '', 'total_time' => '' ), $atts, 'yseo_howto' );
        $name = trim( wp_strip_all_tags( (string) $atts['name'], true ) );
        $content = null === $content ? '' : do_shortcode( $content );
        if ( '' === $content ) { return ''; }
        $heading = '' !== $name ? '<h2>' . esc_html( $name ) . '</h2>' : '';
        return '<section class="yseo-howto">' . $heading . '<div class="yseo-howto-steps">' . wp_kses_post( $content ) . '</div></section>';
    }

    /** @param array<string,string> $atts */
    public function renderHowToStep( array $atts, ?string $content = null ): string {
        $atts = shortcode_atts( array( 'title' => '' ), $atts, 'yseo_step' );
        $title = trim( wp_strip_all_tags( (string) $atts['title'], true ) );
        $body = null === $content ? '' : trim( wp_kses_post( do_shortcode( $content ) ) );
        if ( '' === $title && '' === wp_strip_all_tags( $body, true ) ) { return ''; }
        $heading = '' !== $title ? '<h3>' . esc_html( $title ) . '</h3>' : '';
        return '<div class="yseo-howto-step">' . $heading . '<div>' . $body . '</div></div>';
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    public function augmentSchema( array $payload, array $document, ResourceIdentity $resource ): array {
        unset( $document );
        if ( $resource->objectId() <= 0 || ! in_array( $resource->type(), array( 'post', 'product', 'home', 'posts_page' ), true ) ) { return $payload; }
        $post = get_post( $resource->objectId() );
        if ( ! $post instanceof \WP_Post ) { return $payload; }
        $content = (string) $post->post_content;
        $graph = isset( $payload['@graph'] ) && is_array( $payload['@graph'] ) ? $payload['@graph'] : array();
        $faq = $this->faqSchema( $content, (string) get_permalink( $post ) );
        if ( ! empty( $faq ) ) { $graph[] = $faq; }
        $howTo = $this->howToSchema( $content, (string) get_permalink( $post ) );
        if ( ! empty( $howTo ) ) { $graph[] = $howTo; }
        $payload['@graph'] = $graph;
        return $payload;
    }

    /** @return array<string,mixed> */
    private function faqSchema( string $content, string $canonical ): array {
        if ( false === stripos( $content, '[yseo_faq' ) ) { return array(); }
        $pattern = '/\[yseo_qa\b([^\]]*)\](.*?)\[\/yseo_qa\]/is';
        if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) { return array(); }
        $entities = array();
        foreach ( array_slice( $matches, 0, self::MAX_FAQ_ITEMS ) as $match ) {
            $atts = shortcode_parse_atts( (string) $match[1] );
            $question = is_array( $atts ) && isset( $atts['question'] ) ? trim( wp_strip_all_tags( (string) $atts['question'], true ) ) : '';
            $answer = trim( wp_strip_all_tags( strip_shortcodes( (string) $match[2] ), true ) );
            if ( '' === $question || '' === $answer ) { continue; }
            $entities[] = array( '@type' => 'Question', 'name' => $question, 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $answer ) );
        }
        if ( empty( $entities ) ) { return array(); }
        return array( '@type' => 'FAQPage', '@id' => trailingslashit( $canonical ) . '#faq', 'mainEntity' => $entities );
    }

    /** @return array<string,mixed> */
    private function howToSchema( string $content, string $canonical ): array {
        if ( ! preg_match( '/\[yseo_howto\b([^\]]*)\](.*?)\[\/yseo_howto\]/is', $content, $outer ) ) { return array(); }
        $atts = shortcode_parse_atts( (string) $outer[1] );
        $name = is_array( $atts ) && isset( $atts['name'] ) ? trim( wp_strip_all_tags( (string) $atts['name'], true ) ) : '';
        $totalTime = is_array( $atts ) && isset( $atts['total_time'] ) ? strtoupper( trim( (string) $atts['total_time'] ) ) : '';
        if ( ! preg_match_all( '/\[yseo_step\b([^\]]*)\](.*?)\[\/yseo_step\]/is', (string) $outer[2], $matches, PREG_SET_ORDER ) ) { return array(); }
        $steps = array();
        foreach ( array_slice( $matches, 0, self::MAX_HOWTO_STEPS ) as $match ) {
            $stepAtts = shortcode_parse_atts( (string) $match[1] );
            $title = is_array( $stepAtts ) && isset( $stepAtts['title'] ) ? trim( wp_strip_all_tags( (string) $stepAtts['title'], true ) ) : '';
            $text = trim( wp_strip_all_tags( strip_shortcodes( (string) $match[2] ), true ) );
            if ( '' === $title && '' === $text ) { continue; }
            $step = array( '@type' => 'HowToStep', 'text' => $text );
            if ( '' !== $title ) { $step['name'] = $title; }
            $steps[] = $step;
        }
        if ( empty( $steps ) ) { return array(); }
        $schema = array( '@type' => 'HowTo', '@id' => trailingslashit( $canonical ) . '#howto', 'name' => '' !== $name ? $name : (string) get_the_title(), 'step' => $steps );
        if ( preg_match( '/^P(?=\d|T\d)(?:\d+Y)?(?:\d+M)?(?:\d+D)?(?:T(?=\d)(?:\d+H)?(?:\d+M)?(?:\d+(?:\.\d+)?S)?)?$/', $totalTime ) ) { $schema['totalTime'] = $totalTime; }
        return $schema;
    }
}
