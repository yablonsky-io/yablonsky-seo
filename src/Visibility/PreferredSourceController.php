<?php
namespace Yablonsky\PerformanceSEO\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Explicit, privacy-light helper for Google's Preferred Sources selector.
 *
 * The shortcode emits only a normal outbound link. It does not load Google's
 * JavaScript library, phone home, or claim eligibility/selection by a user.
 */
final class PreferredSourceController {
    public const SHORTCODE = 'yseo_preferred_source';

    public function register(): void {
        add_shortcode( self::SHORTCODE, array( $this, 'renderShortcode' ) );
    }

    /** @param array<string,mixed>|string $attributes */
    public function renderShortcode( $attributes = array() ): string {
        if ( '1' !== (string) get_option( 'blog_public', '1' ) ) { return ''; }
        $host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( '' === $host ) { return ''; }

        $attributes = shortcode_atts(
            array(
                'label' => __( 'Add as Preferred Source', 'yablonsky-seo' ),
                'class' => '',
            ),
            is_array( $attributes ) ? $attributes : array(),
            self::SHORTCODE
        );
        $label = trim( wp_strip_all_tags( (string) $attributes['label'], true ) );
        if ( '' === $label ) { $label = __( 'Add as Preferred Source', 'yablonsky-seo' ); }
        $class = sanitize_html_class( trim( (string) $attributes['class'] ) );
        $classes = trim( 'yseo-preferred-source-link ' . $class );
        $url = add_query_arg( 'q', $host, 'https://www.google.com/preferences/source' );

        return sprintf(
            '<a class="%1$s" href="%2$s" rel="nofollow noopener">%3$s</a>',
            esc_attr( $classes ),
            esc_url( $url ),
            esc_html( $label )
        );
    }

    public static function isDeployedOnStaticFrontPage(): bool {
        if ( 'page' !== (string) get_option( 'show_on_front', 'posts' ) ) { return false; }
        $frontId = (int) get_option( 'page_on_front', 0 );
        if ( $frontId <= 0 ) { return false; }
        $content = (string) get_post_field( 'post_content', $frontId );
        return '' !== $content && has_shortcode( $content, self::SHORTCODE );
    }
}
