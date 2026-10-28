<?php
namespace Yablonsky\PerformanceSEO\SEO\Rss;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RssEnhancementController {
    /** @var array<string,mixed> */
    private $settings;

    public function __construct() {
        $settings = get_option( 'yps_public_settings', array() );
        $this->settings = is_array( $settings ) ? $settings : array();
    }

    public function register(): void {
        if ( '' === trim( (string) ( $this->settings['rss_before_content'] ?? '' ) ) && '' === trim( (string) ( $this->settings['rss_after_content'] ?? '' ) ) ) {
            return;
        }
        add_filter( 'the_content_feed', array( $this, 'filterContent' ), 20, 2 );
        add_filter( 'the_excerpt_rss', array( $this, 'filterExcerpt' ), 20 );
    }

    public function filterContent( string $content, string $feedType = '' ): string {
        unset( $feedType );
        return $this->decorate( $content );
    }

    public function filterExcerpt( string $content ): string {
        return $this->decorate( $content );
    }

    private function decorate( string $content ): string {
        $post = get_post();
        if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) { return $content; }
        $before = $this->renderTemplate( (string) ( $this->settings['rss_before_content'] ?? '' ), $post );
        $after  = $this->renderTemplate( (string) ( $this->settings['rss_after_content'] ?? '' ), $post );
        return $before . $content . $after;
    }

    private function renderTemplate( string $template, \WP_Post $post ): string {
        $template = trim( $template );
        if ( '' === $template ) { return ''; }
        $permalink = get_permalink( $post );
        $permalink = is_string( $permalink ) ? $permalink : '';
        $siteUrl = home_url( '/' );
        $authorUrl = get_author_posts_url( (int) $post->post_author );
        $postTitle = trim( wp_strip_all_tags( (string) get_the_title( $post ), true ) );
        $siteName = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ), true ) );
        $authorName = trim( wp_strip_all_tags( (string) get_the_author_meta( 'display_name', (int) $post->post_author ), true ) );

        $replacements = array(
            '%%POSTLINK%%' => '' !== $permalink ? '<a href="' . esc_url( $permalink ) . '">' . esc_html( $postTitle ) . '</a>' : esc_html( $postTitle ),
            '%%BLOGLINK%%' => '<a href="' . esc_url( $siteUrl ) . '">' . esc_html( $siteName ) . '</a>',
            '%%AUTHORLINK%%' => '<a href="' . esc_url( $authorUrl ) . '">' . esc_html( $authorName ) . '</a>',
            '%%BLOGDESCLINK%%' => '<a href="' . esc_url( $siteUrl ) . '">' . esc_html( trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ), true ) ) ) . '</a>',
        );
        $rendered = strtr( wp_kses_post( $template ), $replacements );
        return '' === trim( wp_strip_all_tags( $rendered, true ) ) ? '' : '<p class="yseo-rss-credit">' . wp_kses_post( $rendered ) . '</p>';
    }
}
