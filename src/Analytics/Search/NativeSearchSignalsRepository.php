<?php
namespace Yablonsky\PerformanceSEO\Analytics\Search;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local-only, privacy-minimised aggregate store for organic search entrances.
 *
 * No IP addresses, cookies, user identifiers, full referrer URLs or search queries
 * are stored. Only day, local post ID, coarse search-engine label and an aggregate
 * entrance counter are persisted.
 */
final class NativeSearchSignalsRepository {
    private const SCHEMA_VERSION = '1';
    private const OPTION_SCHEMA = 'yseo_native_search_signals_schema_version';
    /** @var string */ private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'yseo_search_signals_daily';
    }

    public function ensureSchema(): void {
        if ( self::SCHEMA_VERSION === (string) get_option( self::OPTION_SCHEMA, '' ) ) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table} (
            signal_date date NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            engine varchar(32) NOT NULL,
            entrances bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (signal_date,post_id,engine),
            KEY date_entrances (signal_date,entrances),
            KEY post_date (post_id,signal_date),
            KEY engine_date (engine,signal_date)
        ) {$charset};";
        dbDelta( $sql );
        update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
    }

    public function record( int $postId, string $engine ): void {
        $engine = sanitize_key( $engine );
        if ( '' === $engine ) { return; }
        $postId = max( 0, $postId );
        $date = gmdate( 'Y-m-d' );
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic increment in plugin-owned aggregate table; no row-level visitor data is stored.
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (signal_date,post_id,engine,entrances) VALUES (%s,%d,%s,1) ON DUPLICATE KEY UPDATE entrances=entrances+1',
                $this->table,
                $date,
                $postId,
                $engine
            )
        );
    }

    /** @return array<string,mixed> */
    public function summary( int $days = 28 ): array {
        $days = max( 1, min( 180, $days ) );
        $end = gmdate( 'Y-m-d' );
        $start = gmdate( 'Y-m-d', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
        return $this->summaryRange( $start, $end );
    }

    /** @return array<string,mixed> */
    public function summaryRange( string $start, string $end ): array {
        $start = $this->normalizeDate( $start );
        $end = $this->normalizeDate( $end );
        if ( '' === $start || '' === $end || $start > $end ) {
            return $this->summary( 28 );
        }
        $startTs = strtotime( $start . ' 00:00:00 UTC' );
        $endTs = strtotime( $end . ' 00:00:00 UTC' );
        if ( false === $startTs || false === $endTs ) { return $this->summary( 28 ); }
        $days = (int) floor( ( $endTs - $startTs ) / DAY_IN_SECONDS ) + 1;
        if ( $days > 180 ) {
            $days = 180;
            $startTs = $endTs - ( ( $days - 1 ) * DAY_IN_SECONDS );
            $start = gmdate( 'Y-m-d', $startTs );
        }
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded aggregate read from plugin-owned table for administrator reporting.
        $dailyRows = (array) $wpdb->get_results(
            $wpdb->prepare( 'SELECT signal_date,SUM(entrances) AS entrances FROM %i WHERE signal_date BETWEEN %s AND %s GROUP BY signal_date ORDER BY signal_date ASC', $this->table, $start, $end ),
            ARRAY_A
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded aggregate read from plugin-owned table for administrator reporting.
        $engineRows = (array) $wpdb->get_results(
            $wpdb->prepare( 'SELECT engine,SUM(entrances) AS entrances FROM %i WHERE signal_date BETWEEN %s AND %s GROUP BY engine ORDER BY entrances DESC,engine ASC LIMIT 12', $this->table, $start, $end ),
            ARRAY_A
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded aggregate read from plugin-owned table for administrator reporting.
        $pageRows = (array) $wpdb->get_results(
            $wpdb->prepare( 'SELECT post_id,SUM(entrances) AS entrances FROM %i WHERE signal_date BETWEEN %s AND %s GROUP BY post_id ORDER BY entrances DESC,post_id ASC LIMIT 10', $this->table, $start, $end ),
            ARRAY_A
        );

        $dailyMap = array();
        foreach ( $dailyRows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $date = isset( $row['signal_date'] ) ? sanitize_text_field( (string) $row['signal_date'] ) : '';
            if ( '' !== $date ) { $dailyMap[ $date ] = max( 0, (int) ( $row['entrances'] ?? 0 ) ); }
        }
        $daily = array();
        $total = 0;
        for ( $offset = 0; $offset < $days; $offset++ ) {
            $date = gmdate( 'Y-m-d', $startTs + ( $offset * DAY_IN_SECONDS ) );
            $count = isset( $dailyMap[ $date ] ) ? (int) $dailyMap[ $date ] : 0;
            $daily[] = array( 'date' => $date, 'entrances' => $count );
            $total += $count;
        }

        $engines = array();
        foreach ( $engineRows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $engine = sanitize_key( (string) ( $row['engine'] ?? '' ) );
            if ( '' === $engine ) { continue; }
            $engines[] = array( 'engine' => $engine, 'entrances' => max( 0, (int) ( $row['entrances'] ?? 0 ) ) );
        }

        $pages = array();
        foreach ( $pageRows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $postId = max( 0, (int) ( $row['post_id'] ?? 0 ) );
            $count = max( 0, (int) ( $row['entrances'] ?? 0 ) );
            $label = __( 'Homepage / non-singular landing', 'yablonsky-seo' );
            $url = home_url( '/' );
            if ( $postId > 0 ) {
                $post = get_post( $postId );
                if ( $post instanceof \WP_Post ) {
                    /* translators: %d: WordPress content ID. */
                    $label = get_the_title( $post ) ?: sprintf( __( 'Content #%d', 'yablonsky-seo' ), $postId );
                    $permalink = get_permalink( $postId );
                    $url = is_string( $permalink ) ? $permalink : $url;
                }
            }
            $pages[] = array( 'post_id' => $postId, 'label' => $label, 'url' => esc_url_raw( $url ), 'entrances' => $count );
        }

        return array(
            'enabled' => '0' !== (string) get_option( 'yseo_native_search_signals_enabled', '1' ),
            'start_date' => $start,
            'end_date' => $end,
            'entrances' => $total,
            'engine_count' => count( $engines ),
            'daily' => $daily,
            'engines' => $engines,
            'pages' => $pages,
        );
    }

    private function normalizeDate( string $date ): string {
        $date = trim( sanitize_text_field( $date ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { return ''; }
        $parts = array_map( 'intval', explode( '-', $date ) );
        return 3 === count( $parts ) && checkdate( $parts[1], $parts[2], $parts[0] ) ? $date : '';
    }

    public function cleanup( int $retentionDays = 180 ): void {
        $retentionDays = max( 30, min( 730, $retentionDays ) );
        $cutoff = gmdate( 'Y-m-d', time() - ( $retentionDays * DAY_IN_SECONDS ) );
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention cleanup for plugin-owned derived aggregate table.
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE signal_date < %s', $this->table, $cutoff ) );
    }
}
