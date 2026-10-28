<?php
namespace Yablonsky\PerformanceSEO;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Autoloader {
    /** @var string */
    private static $baseDir = '';

    public static function register( string $baseDir ): void {
        self::$baseDir = rtrim( $baseDir, '/\\' ) . DIRECTORY_SEPARATOR;
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    private static function autoload( string $class ): void {
        $prefix = __NAMESPACE__ . '\\';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        $relative = substr( $class, strlen( $prefix ) );
        if ( false === $relative || '' === $relative ) {
            return;
        }

        $file = self::$baseDir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
}
