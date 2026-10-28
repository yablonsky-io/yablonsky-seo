<?php
namespace Yablonsky\PerformanceSEO\Migration\Provenance;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ProvenanceRepository {
    private const POST_KEY = '_yps_migration_provenance';
    private const TERM_KEY = '_yps_migration_provenance';
    private const USER_KEY = '_yps_migration_provenance';
    private const GLOBAL_OPTION = 'yps_migration_global_provenance';

    /** @param array<string,array<string,string>> $records */
    public function mergePostRecords( int $postId, array $records ): bool {
        if ( empty( $records ) ) {
            return true;
        }
        $current = get_post_meta( $postId, self::POST_KEY, true );
        $current = is_array( $current ) ? $current : array();
        $next = array_merge( $current, $records );
        ksort( $next, SORT_STRING );
        update_post_meta( $postId, self::POST_KEY, $next );
        return get_post_meta( $postId, self::POST_KEY, true ) === $next;
    }

    /** @param array<string,array<string,string>> $records */
    public function mergeTermRecords( int $termId, array $records ): bool {
        if ( empty( $records ) ) {
            return true;
        }
        $current = get_term_meta( $termId, self::TERM_KEY, true );
        $current = is_array( $current ) ? $current : array();
        $next = array_merge( $current, $records );
        ksort( $next, SORT_STRING );
        update_term_meta( $termId, self::TERM_KEY, $next );
        return get_term_meta( $termId, self::TERM_KEY, true ) === $next;
    }


    /** @param array<string,array<string,string>> $records */
    public function mergeUserRecords( int $userId, array $records ): bool {
        if ( empty( $records ) ) {
            return true;
        }
        $current = get_user_meta( $userId, self::USER_KEY, true );
        $current = is_array( $current ) ? $current : array();
        $next = array_merge( $current, $records );
        ksort( $next, SORT_STRING );
        update_user_meta( $userId, self::USER_KEY, $next );
        return get_user_meta( $userId, self::USER_KEY, true ) === $next;
    }

    /** @param array<string,array<string,string>> $records */
    public function replaceGlobalRecords( array $records ): bool {
        ksort( $records, SORT_STRING );
        update_option( self::GLOBAL_OPTION, $records, false );
        return get_option( self::GLOBAL_OPTION, array() ) === $records;
    }

    /** @param mixed $value @return array<string,string> */
    public static function record(
        string $provider,
        string $version,
        string $sourceLocator,
        string $classification,
        string $transformation,
        $value
    ): array {
        $encoded = is_scalar( $value ) || null === $value ? (string) $value : self::stableEncode( $value );
        return array(
            'provider'       => $provider,
            'source_version' => $version,
            'source'         => $sourceLocator,
            'classification' => $classification,
            'transform'      => $transformation,
            'value_sha256'   => hash( 'sha256', $encoded ),
            'imported_at'    => gmdate( 'c' ),
        );
    }

    /** @param mixed $value */
    private static function stableEncode( $value ): string {
        if ( is_array( $value ) ) {
            ksort( $value, SORT_STRING );
        }
        if ( function_exists( 'wp_json_encode' ) ) {
            $json = wp_json_encode( $value );
        } else {
            $json = json_encode( $value );
        }
        return is_string( $json ) ? $json : '';
    }
}
