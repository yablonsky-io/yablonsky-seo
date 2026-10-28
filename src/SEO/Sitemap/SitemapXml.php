<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SitemapXml {
    public static function text( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    }

    public static function url( string $value ): string {
        $url = esc_url_raw( $value, array( 'http', 'https' ) );
        return '' === $url ? '' : self::text( $url );
    }

    public static function attr( string $value ): string {
        return self::text( $value );
    }
}
