<?php
namespace Yablonsky\PerformanceSEO\Language;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WordPressLanguageProvider implements LanguageProviderInterface {
    public function currentLanguageKey(): string {
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        return strtolower( str_replace( '_', '-', (string) $locale ) );
    }

    public function alternates( string $resourceType, int $objectId ): array {
        // WordPress core has no canonical multilingual content-family authority.
        // Cross-product integrations must provide explicit language/url pairs through
        // this versioned capability hook; display names and plugin slugs are not used
        // as integration contracts.
        $alternates = apply_filters(
            'yablonsky_seo_language_alternates_v1',
            array(),
            $resourceType,
            $objectId
        );
        $alternates = apply_filters(
            'yablonsky_performance_seo_language_alternates_v1',
            is_array( $alternates ) ? $alternates : array(),
            $resourceType,
            $objectId
        );
        return is_array( $alternates ) ? $alternates : array();
    }
}
