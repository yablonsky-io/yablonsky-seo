<?php
namespace Yablonsky\PerformanceSEO\Localization;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Release-evidence audit for bundled product UI locale packs.
 *
 * This is deliberately separate from public content-language authority.
 * It validates only local YSEO product/admin localization evidence.
 */
final class ProductLocalePackAudit {
    /** @var array<string,mixed>|null */
    private static $report = null;

    /** @return array<string,mixed> */
    public static function report(): array {
        if ( is_array( self::$report ) ) { return self::$report; }
        $registry = ProductLocaleRegistry::data();
        $statusData = ProductLocaleRegistry::translationStatusData();
        $progressData = self::progressData();
        $progressByCode = array();
        if ( isset( $progressData['locales'] ) && is_array( $progressData['locales'] ) ) {
            foreach ( $progressData['locales'] as $progressRow ) {
                if ( is_array( $progressRow ) && ! empty( $progressRow['code'] ) ) {
                    $progressByCode[ (string) $progressRow['code'] ] = $progressRow;
                }
            }
        }
        $pot = YPS_DIR . 'languages/yablonsky-seo.pot';
        $actualCatalogHash = is_readable( $pot ) ? (string) hash_file( 'sha256', $pot ) : '';
        $expectedCatalogHash = isset( $statusData['source_catalog_sha256'] ) ? strtolower( trim( (string) $statusData['source_catalog_sha256'] ) ) : '';
        $catalogVerified = '' !== $actualCatalogHash && '' !== $expectedCatalogHash && hash_equals( $expectedCatalogHash, strtolower( $actualCatalogHash ) );
        $rows = array();
        $readyTotal = 0;
        $readyCore = 0;
        $coreTotal = 0;

        foreach ( ProductLocaleRegistry::all() as $locale ) {
            $code = isset( $locale['code'] ) ? (string) $locale['code'] : '';
            $tier = isset( $locale['tier'] ) ? (string) $locale['tier'] : '';
            $source = ! empty( $locale['source'] );
            if ( 'core' === $tier ) {
                ++$coreTotal;
            }
            $status = ProductLocaleRegistry::translationStatusForCode( $code );
            $declaredComplete = $source || ( is_array( $status ) && ! empty( $status['complete'] ) );
            $pack = is_array( $status ) && isset( $status['pack'] ) ? sanitize_file_name( (string) $status['pack'] ) : '';
            $poReadable = $source || ( '' !== $pack && is_readable( YPS_DIR . 'languages/yablonsky-seo-' . $pack . '.po' ) );
            $moReadable = $source || ( '' !== $pack && is_readable( YPS_DIR . 'languages/yablonsky-seo-' . $pack . '.mo' ) );
            $ready = $source ? $catalogVerified : ( $catalogVerified && $declaredComplete && $poReadable && $moReadable );
            if ( $ready ) {
                ++$readyTotal;
                if ( 'core' === $tier ) {
                    ++$readyCore;
                }
            }
            $state = $ready ? 'ready' : ( $declaredComplete ? 'invalid' : 'pending' );
            $progress = isset( $progressByCode[ $code ] ) && is_array( $progressByCode[ $code ] ) ? $progressByCode[ $code ] : array();
            $progressCatalogHash = isset( $progressData['source_catalog_sha256'] ) ? strtolower( trim( (string) $progressData['source_catalog_sha256'] ) ) : '';
            $progressVerified = '' !== $progressCatalogHash && '' !== $actualCatalogHash && hash_equals( $progressCatalogHash, strtolower( $actualCatalogHash ) );
            $translatedMessages = $progressVerified && isset( $progress['translated_messages'] ) ? max( 0, (int) $progress['translated_messages'] ) : 0;
            $sourceMessages = $progressVerified && isset( $progress['source_messages'] ) ? max( 0, (int) $progress['source_messages'] ) : 0;
            $progressPercent = $sourceMessages > 0 ? round( min( 100, ( $translatedMessages / $sourceMessages ) * 100 ), 1 ) : 0.0;
            $rows[] = array(
                'code'              => $code,
                'name'              => isset( $locale['name'] ) ? (string) $locale['name'] : $code,
                'tier'              => $tier,
                'wordpress_locales' => isset( $locale['wordpress'] ) && is_array( $locale['wordpress'] ) ? array_values( array_map( 'strval', $locale['wordpress'] ) ) : array(),
                'source'            => $source,
                'pack'              => $pack,
                'declared_complete' => $declaredComplete,
                'po_readable'       => $poReadable,
                'mo_readable'       => $moReadable,
                'ready'             => $ready,
                'state'             => $state,
                'progress_state'    => $progressVerified && isset( $progress['state'] ) ? (string) $progress['state'] : '',
                'translated_messages' => $translatedMessages,
                'source_messages'   => $sourceMessages,
                'progress_percent'  => $progressPercent,
            );
        }

        self::$report = array(
            'schema'                       => 'yablonsky.product-localization-audit.v1',
            'plugin_version'               => defined( 'YSEO_VERSION' ) ? YSEO_VERSION : '',
            'registry_version'             => isset( $registry['version'] ) ? (string) $registry['version'] : '',
            'status_version'               => isset( $statusData['version'] ) ? (string) $statusData['version'] : '',
            'catalog'                      => isset( $statusData['catalog'] ) ? (string) $statusData['catalog'] : '',
            'catalog_expected_sha256'      => $expectedCatalogHash,
            'catalog_actual_sha256'        => strtolower( $actualCatalogHash ),
            'catalog_verified'             => $catalogVerified,
            'progress_catalog_verified'    => isset( $progressVerified ) ? $progressVerified : false,
            'progress_version'             => isset( $progressData['version'] ) ? (string) $progressData['version'] : '',
            'registered_total'             => count( $rows ),
            'registered_ready'             => $readyTotal,
            'core_total'                   => $coreTotal,
            'core_ready'                   => $readyCore,
            'locales'                      => $rows,
        );
        return self::$report;
    }

    /** @return array<string,mixed> */
    private static function progressData(): array {
        $path = YPS_DIR . 'data/product-translation-progress.json';
        if ( ! is_readable( $path ) ) {
            return array( 'schema' => 'yablonsky.product-translation-progress.v1', 'locales' => array() );
        }
        $decoded = json_decode( (string) file_get_contents( $path ), true );
        return is_array( $decoded ) ? $decoded : array( 'schema' => 'yablonsky.product-translation-progress.v1', 'locales' => array() );
    }

    /** @return array<string,mixed>|null */
    public static function rowForWordPressLocale( string $wpLocale ): ?array {
        $definition = ProductLocaleRegistry::resolveWordPressLocale( $wpLocale );
        if ( ! is_array( $definition ) || empty( $definition['code'] ) ) {
            return null;
        }
        $code = (string) $definition['code'];
        $report = self::report();
        foreach ( $report['locales'] as $row ) {
            if ( is_array( $row ) && isset( $row['code'] ) && $code === (string) $row['code'] ) {
                return $row;
            }
        }
        return null;
    }
}
