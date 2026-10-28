<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'yablonsky_seo' ) ) {
    /** Public read-only PHP developer surface. */
    function yablonsky_seo(): \Yablonsky\PerformanceSEO\SEO\Developer\DeveloperSurface {
        static $surface = null;
        if ( ! $surface instanceof \Yablonsky\PerformanceSEO\SEO\Developer\DeveloperSurface ) {
            $surface = new \Yablonsky\PerformanceSEO\SEO\Developer\DeveloperSurface();
        }
        return $surface;
    }
}
