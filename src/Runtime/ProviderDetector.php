<?php
namespace Yablonsky\PerformanceSEO\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ProviderDetector {
    /** @return array<int,Provider> */
    public function detectActiveProviders(): array {
        $providers = array();

        if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options', false ) ) {
            $providers[] = new Provider( 'yoast', 'Yoast SEO', defined( 'WPSEO_VERSION' ) ? (string) WPSEO_VERSION : '' );
        }

        if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
            $providers[] = new Provider( 'rank-math', 'Rank Math', defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : '' );
        }

        if ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\\Plugin\\AIOSEO', false ) ) {
            $providers[] = new Provider( 'aioseo', 'All in One SEO', defined( 'AIOSEO_VERSION' ) ? (string) AIOSEO_VERSION : '' );
        }

        if ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) ) {
            $providers[] = new Provider( 'seopress', 'SEOPress', defined( 'SEOPRESS_VERSION' ) ? (string) SEOPRESS_VERSION : '' );
        }

        if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' ) ) {
            $providers[] = new Provider( 'tsf', 'The SEO Framework', defined( 'THE_SEO_FRAMEWORK_VERSION' ) ? (string) THE_SEO_FRAMEWORK_VERSION : '' );
        }

        if ( defined( 'SLIM_SEO_VER' ) || defined( 'SLIM_SEO_VERSION' ) ) {
            $version = defined( 'SLIM_SEO_VERSION' ) ? (string) SLIM_SEO_VERSION : ( defined( 'SLIM_SEO_VER' ) ? (string) SLIM_SEO_VER : '' );
            $providers[] = new Provider( 'slim-seo', 'Slim SEO', $version );
        }

        if ( defined( 'SURERANK_VERSION' ) || class_exists( 'SureRank\\Inc\\Plugin', false ) ) {
            $providers[] = new Provider( 'surerank', 'SureRank', defined( 'SURERANK_VERSION' ) ? (string) SURERANK_VERSION : '' );
        }

        return $providers;
    }

    public function hasProvider( string $providerId ): bool {
        foreach ( $this->detectActiveProviders() as $provider ) {
            if ( $providerId === $provider->id() ) {
                return true;
            }
        }
        return false;
    }
}
