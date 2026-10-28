<?php
namespace Yablonsky\PerformanceSEO\Localization;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ProductLocaleRegistry {
    /** @var array<string,mixed>|null */
    private static $data = null;
    /** @var array<string,mixed>|null */
    private static $translationStatus = null;
    /** @var array<string,array<string,mixed>>|null Per-request alias lookup, first registered match wins. */
    private static $wordpressLocaleIndex = null;
    /** @var array<string,array<string,mixed>>|null Per-request translation-status lookup. */
    private static $translationStatusIndex = null;

    /** @return array<string,mixed> */
    public static function data(): array {
        if ( is_array( self::$data ) ) {
            return self::$data;
        }
        $path = YPS_DIR . 'data/product-locales.json';
        if ( ! is_readable( $path ) ) {
            self::$data = array( 'schema' => 'yablonsky.product-locales.v1', 'version' => '', 'locales' => array() );
            return self::$data;
        }
        $decoded = json_decode( (string) file_get_contents( $path ), true );
        self::$data = is_array( $decoded ) ? $decoded : array( 'schema' => 'yablonsky.product-locales.v1', 'version' => '', 'locales' => array() );
        return self::$data;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array {
        $data = self::data();
        return isset( $data['locales'] ) && is_array( $data['locales'] ) ? array_values( $data['locales'] ) : array();
    }

    /** @return array<int,array<string,mixed>> */
    public static function core(): array {
        return array_values( array_filter( self::all(), static function ( array $locale ): bool {
            return isset( $locale['tier'] ) && 'core' === $locale['tier'];
        } ) );
    }

    /** @return array<string,mixed>|null */
    public static function resolveWordPressLocale( string $wpLocale ): ?array {
        if ( ! is_array( self::$wordpressLocaleIndex ) ) {
            self::$wordpressLocaleIndex = array();
            foreach ( self::all() as $locale ) {
                $aliases = isset( $locale['wordpress'] ) && is_array( $locale['wordpress'] ) ? $locale['wordpress'] : array();
                foreach ( $aliases as $alias ) {
                    $alias = (string) $alias;
                    // Preserve original first-match precedence if two definitions share an alias.
                    if ( '' !== $alias && ! isset( self::$wordpressLocaleIndex[ $alias ] ) ) {
                        self::$wordpressLocaleIndex[ $alias ] = $locale;
                    }
                }
            }
        }
        return self::$wordpressLocaleIndex[ $wpLocale ] ?? null;
    }

    /** @return array<string,mixed> */
    public static function translationStatusData(): array {
        if ( is_array( self::$translationStatus ) ) { return self::$translationStatus; }
        $path = YPS_DIR . 'data/product-translation-status.json';
        if ( ! is_readable( $path ) ) {
            self::$translationStatus = array( 'schema' => 'yablonsky.product-translation-status.v1', 'locales' => array() );
            return self::$translationStatus;
        }
        $decoded = json_decode( (string) file_get_contents( $path ), true );
        self::$translationStatus = is_array( $decoded ) ? $decoded : array( 'schema' => 'yablonsky.product-translation-status.v1', 'locales' => array() );
        return self::$translationStatus;
    }

    /** @return array<string,mixed>|null */
    public static function translationStatusForCode( string $code ): ?array {
        if ( ! is_array( self::$translationStatusIndex ) ) {
            self::$translationStatusIndex = array();
            $data = self::translationStatusData();
            $rows = isset( $data['locales'] ) && is_array( $data['locales'] ) ? $data['locales'] : array();
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) || ! isset( $row['code'] ) ) { continue; }
                $key = (string) $row['code'];
                if ( '' !== $key && ! isset( self::$translationStatusIndex[ $key ] ) ) {
                    self::$translationStatusIndex[ $key ] = $row;
                }
            }
        }
        return self::$translationStatusIndex[ $code ] ?? null;
    }

    public static function hasBundledPack( string $wpLocale ): bool {
        $row = ProductLocalePackAudit::rowForWordPressLocale( $wpLocale );
        return is_array( $row ) && ! empty( $row['ready'] );
    }

    /** @return array<string,int> */
    public static function coverage(): array {
        $audit = ProductLocalePackAudit::report();
        return array(
            'core_total' => isset( $audit['core_total'] ) ? (int) $audit['core_total'] : 0,
            'core_complete' => isset( $audit['core_ready'] ) ? (int) $audit['core_ready'] : 0,
            'registered_total' => isset( $audit['registered_total'] ) ? (int) $audit['registered_total'] : 0,
            'registered_complete' => isset( $audit['registered_ready'] ) ? (int) $audit['registered_ready'] : 0,
            'catalog_verified' => ! empty( $audit['catalog_verified'] ) ? 1 : 0,
        );
    }

    /** @return array<string,mixed> */
    public static function currentStatus(): array {
        $wpLocale = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : (string) get_locale();
        $locale = self::resolveWordPressLocale( $wpLocale );
        $known = is_array( $locale );
        $source = $known && ! empty( $locale['source'] );
        $status = $known ? self::translationStatusForCode( isset( $locale['code'] ) ? (string) $locale['code'] : '' ) : null;
        $pack = is_array( $status ) && isset( $status['pack'] ) ? (string) $status['pack'] : '';
        $auditRow = $known ? ProductLocalePackAudit::rowForWordPressLocale( $wpLocale ) : null;
        $complete = is_array( $auditRow ) && ! empty( $auditRow['ready'] );
        return array(
            'wordpress_locale' => $wpLocale,
            'language_code'    => $known && isset( $locale['code'] ) ? (string) $locale['code'] : '',
            'language_name'    => $known && isset( $locale['name'] ) ? (string) $locale['name'] : $wpLocale,
            'tier'             => $known && isset( $locale['tier'] ) ? (string) $locale['tier'] : 'outside-registry',
            'known'            => $known,
            'complete'         => $complete,
            'source'           => $source,
            'pack'             => $pack,
        );
    }
}
