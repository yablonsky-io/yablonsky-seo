<?php
namespace Yablonsky\PerformanceSEO\SEO\NotFound;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Privacy-light aggregate of unresolved 404 paths. No IP, UA, referrer or query data is stored. */
final class NotFoundRepository {
    private const SCHEMA_VERSION = '5';
    private const OPTION_SCHEMA = 'yseo_404_schema_version';
    private const CLASSIFIER_VERSION = NotFoundSignalClassifier::VERSION;
    private const OPTION_CLASSIFIER = 'yseo_404_classifier_version';
    private const OPTION_CLASSIFIER_CURSOR = 'yseo_404_classifier_cursor';
    private const CLASSIFIER_CRON = 'yseo_404_reclassify';
    private const CLASSIFIER_BATCH = 500;
    private const OPTION_RETENTION_REPORT = 'yseo_404_retention_report';
    private const OPTION_INLINE_GUARD_AT = 'yseo_404_inline_guard_at';
    private const INLINE_GUARD_INTERVAL = 300;
    /** @var string */ private $table;

    public function __construct() { global $wpdb; $this->table = $wpdb->prefix . 'yseo_404_monitor'; }

    public function ensureSchema(): void {
        $current = (string) get_option( self::OPTION_SCHEMA, '' );
        if ( self::SCHEMA_VERSION === $current ) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            path text NOT NULL,
            path_hash char(64) NOT NULL,
            language_key varchar(32) NOT NULL DEFAULT '',
            signal_kind varchar(16) NOT NULL DEFAULT 'seo',
            hits bigint(20) unsigned NOT NULL DEFAULT 1,
            first_seen_gmt datetime NOT NULL,
            last_seen_gmt datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY path_language (path_hash,language_key),
            KEY signal_kind (signal_kind),
            KEY last_seen (last_seen_gmt),
            KEY first_seen (first_seen_gmt),
            KEY hits (hits),
            KEY signal_recent (signal_kind,last_seen_gmt,id),
            KEY signal_first (signal_kind,first_seen_gmt,id),
            KEY signal_hits_recent (signal_kind,hits,last_seen_gmt,id)
        ) {$charset};";
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.SchemaChange.dbDelta -- Plugin-owned aggregate table requires a versioned schema upgrade.
        dbDelta( $sql );
        update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
    }

    /** Register bounded background classification maintenance after normal WordPress bootstrap. */
    public function registerMaintenance(): void {
        add_action( self::CLASSIFIER_CRON, array( $this, 'runClassificationMigration' ) );
        $this->scheduleClassificationMigrationIfNeeded();
    }

    public static function clearMaintenanceSchedule(): void {
        wp_clear_scheduled_hook( self::CLASSIFIER_CRON );
    }

    /** @return array{version:string,target:string,pending:bool,cursor:int,batch:int,remaining:int} */
    public function classificationStatus(): array {
        $version = (string) get_option( self::OPTION_CLASSIFIER, '' );
        $cursor = max( 0, (int) get_option( self::OPTION_CLASSIFIER_CURSOR, 0 ) );
        $pending = self::CLASSIFIER_VERSION !== $version;
        $remaining = 0;
        if ( $pending ) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight indexed progress count for plugin-owned migration status.
            $remaining = max( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id>%d', $this->table, $cursor ) ) );
        }
        return array(
            'version' => $version,
            'target'  => self::CLASSIFIER_VERSION,
            'pending' => $pending,
            'cursor'  => $cursor,
            'batch'   => self::CLASSIFIER_BATCH,
            'remaining' => $remaining,
        );
    }

    public function runClassificationMigration(): void {
        if ( self::CLASSIFIER_VERSION === (string) get_option( self::OPTION_CLASSIFIER, '' ) ) {
            delete_option( self::OPTION_CLASSIFIER_CURSOR );
            return;
        }
        global $wpdb;
        $afterId = max( 0, (int) get_option( self::OPTION_CLASSIFIER_CURSOR, 0 ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded plugin-owned classification migration.
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,path,path_hash,language_key,signal_kind,hits,first_seen_gmt,last_seen_gmt FROM %i WHERE id>%d ORDER BY id ASC LIMIT %d', $this->table, $afterId, self::CLASSIFIER_BATCH ), ARRAY_A );
        // A failed SELECT is not an empty table. Otherwise a transient database outage
        // permanently declares the migration complete and leaves old SEO counters stale.
        if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
            $this->scheduleClassificationMigrationIfNeeded();
            return;
        }

        $failed = false;
        foreach ( $rows as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id <= 0 ) { continue; }
            $path = (string) ( $row['path'] ?? '' );
            $kind = NotFoundSignalClassifier::classify( $path );
            $language = (string) ( $row['language_key'] ?? '' );
            // Replaying the corrected v8 migration is read-mostly on sites that
            // completed v7. Only changed classes or non-SEO language remnants
            // need SQL writes, avoiding needless updates of large 404 tables.
            if ( $kind === (string) ( $row['signal_kind'] ?? '' ) && ( NotFoundSignalClassifier::SEO === $kind || '' === $language ) ) {
                $afterId = max( $afterId, $id );
                continue;
            }
            if ( NotFoundSignalClassifier::SEO !== $kind && '' !== $language ) {
                // This branch may MERGE two aggregates. The update and deletion must
                // succeed together; otherwise a later retry could count hits twice.
                // Transactions and row locks also keep concurrent 404 hits intact.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic migration of plugin-owned aggregate rows.
                if ( false === $wpdb->query( 'START TRANSACTION' ) ) { $failed = true; break; }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock source before reading mutable hit totals.
                $source = $wpdb->get_row( $wpdb->prepare( 'SELECT id,path,path_hash,language_key,hits,first_seen_gmt,last_seen_gmt FROM %i WHERE id=%d FOR UPDATE', $this->table, $id ), ARRAY_A );
                if ( ! is_array( $source ) || '' !== (string) $wpdb->last_error ) {
                    $dbError = '' !== (string) $wpdb->last_error;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Undo incomplete row migration.
                    $wpdb->query( 'ROLLBACK' );
                    if ( $dbError ) { $failed = true; break; }
                    // A concurrently deleted aggregate has no classification work left.
                    $afterId = max( $afterId, $id );
                    continue;
                }
                $sourcePath = (string) ( $source['path'] ?? $path );
                $sourceKind = NotFoundSignalClassifier::classify( $sourcePath );
                $sourceLanguage = (string) ( $source['language_key'] ?? '' );
                $okay = true;
                if ( NotFoundSignalClassifier::SEO !== $sourceKind && '' !== $sourceLanguage ) {
                    $hash = (string) ( $source['path_hash'] ?? '' );
                    if ( '' === $hash ) { $hash = hash( 'sha256', $sourcePath ); }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Unique aggregate merge lookup under transaction.
                    $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id,hits,first_seen_gmt,last_seen_gmt FROM %i WHERE path_hash=%s AND language_key=%s AND id<>%d LIMIT 1 FOR UPDATE', $this->table, $hash, '', $id ), ARRAY_A );
                    if ( '' !== (string) $wpdb->last_error ) {
                        $okay = false;
                    } elseif ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
                        $hits = max( 0, (int) ( $existing['hits'] ?? 0 ) ) + max( 0, (int) ( $source['hits'] ?? 0 ) );
                        $first = min( (string) ( $existing['first_seen_gmt'] ?? '' ), (string) ( $source['first_seen_gmt'] ?? '' ) );
                        $last = max( (string) ( $existing['last_seen_gmt'] ?? '' ), (string) ( $source['last_seen_gmt'] ?? '' ) );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic merge inside transaction.
                        $updated = $wpdb->update( $this->table, array( 'path' => $sourcePath, 'signal_kind' => $sourceKind, 'hits' => $hits, 'first_seen_gmt' => $first, 'last_seen_gmt' => $last ), array( 'id' => (int) $existing['id'] ), array( '%s','%s','%d','%s','%s' ), array( '%d' ) );
                        if ( false === $updated ) {
                            $okay = false;
                        } else {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove source only if destination update succeeded.
                            $deleted = $wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
                            $okay = false !== $deleted && 1 === (int) $deleted;
                        }
                    } else {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomically compact non-SEO language.
                        $okay = false !== $wpdb->update( $this->table, array( 'signal_kind' => $sourceKind, 'language_key' => '' ), array( 'id' => $id ), array( '%s','%s' ), array( '%d' ) );
                    }
                } else {
                    // Classification changed or another worker normalized the row.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Update current plugin-owned row state.
                    $okay = false !== $wpdb->update( $this->table, array( 'signal_kind' => $sourceKind ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
                }
                if ( $okay ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit bounded plugin-owned migration transaction.
                    $okay = false !== $wpdb->query( 'COMMIT' );
                }
                if ( ! $okay ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back partial merge; cursor remains before failed row.
                    $wpdb->query( 'ROLLBACK' );
                    $failed = true;
                    break;
                }
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded classification update over plugin-owned rows.
                if ( false === $wpdb->update( $this->table, array( 'signal_kind' => $kind ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ) ) {
                    $failed = true;
                    break;
                }
            }
            $afterId = max( $afterId, $id );
        }
        update_option( self::OPTION_CLASSIFIER_CURSOR, $afterId, false );
        if ( $failed ) {
            // A failed batch stays pending and is retried from the last completed id.
            $this->scheduleClassificationMigrationIfNeeded();
            return;
        }
        if ( count( $rows ) < self::CLASSIFIER_BATCH ) {
            update_option( self::OPTION_CLASSIFIER, self::CLASSIFIER_VERSION, false );
            delete_option( self::OPTION_CLASSIFIER_CURSOR );
            return;
        }
        if ( ! wp_next_scheduled( self::CLASSIFIER_CRON ) ) {
            wp_schedule_single_event( time() + 15, self::CLASSIFIER_CRON );
        }
    }

    private function scheduleClassificationMigrationIfNeeded(): void {
        if ( self::CLASSIFIER_VERSION === (string) get_option( self::OPTION_CLASSIFIER, '' ) ) { return; }
        if ( ! wp_next_scheduled( self::CLASSIFIER_CRON ) ) {
            wp_schedule_single_event( time() + 5, self::CLASSIFIER_CRON );
        }
    }

    public function record( string $path, string $language = '' ): void {
        $path = $this->normalizePath( $path );
        if ( '' === $path ) { return; }
        $language = $this->normalizeLanguage( $language );
        $signal = NotFoundSignalClassifier::classify( $path );
        if ( NotFoundSignalClassifier::SEO !== $signal ) { $language = ''; }
        $hash = hash( 'sha256', $path );
        $now = current_time( 'mysql', true );
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic aggregate update on plugin-owned table; no request-identifying data is stored.
        $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (path,path_hash,language_key,signal_kind,hits,first_seen_gmt,last_seen_gmt) VALUES (%s,%s,%s,%s,1,%s,%s) ON DUPLICATE KEY UPDATE hits=hits+1,last_seen_gmt=VALUES(last_seen_gmt),path=VALUES(path),signal_kind=VALUES(signal_kind)', $this->table, $path, $hash, $language, $signal, $now, $now ) );
        if ( NotFoundSignalClassifier::SEO !== $signal ) { $this->maybeInlinePressureGuard(); }
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function page( int $page = 1, int $perPage = 25, string $search = '', string $language = '', string $dateFrom = '', string $dateTo = '', int $minHits = 0, string $sort = 'hits', string $signal = 'seo' ): array {
        global $wpdb;
        $page = max( 1, $page );
        $perPage = in_array( $perPage, array( 25, 50, 100 ), true ) ? $perPage : 25;
        $search = trim( sanitize_text_field( $search ) );
        $language = $this->normalizeLanguage( $language );
        $dateFrom = $this->normalizeDate( $dateFrom );
        $dateTo = $this->normalizeDate( $dateTo );
        $minHits = min( 1000000000, max( 0, $minHits ) );
        $sort = in_array( $sort, array( 'hits', 'recent', 'oldest', 'first_seen' ), true ) ? $sort : 'hits';
        $signal = in_array( $signal, array( 'seo', 'access', 'technical', 'probe', 'all' ), true ) ? $signal : 'seo';

        $where = array( '1=1' );
        $values = array();
        if ( 'all' !== $signal ) { $where[] = 'signal_kind=%s'; $values[] = $signal; }
        if ( '' !== $search ) { $where[] = 'path LIKE %s'; $values[] = '%' . $wpdb->esc_like( $search ) . '%'; }
        if ( '' !== $language ) { $where[] = 'language_key=%s'; $values[] = $language; }
        if ( '' !== $dateFrom ) { $where[] = 'last_seen_gmt>=%s'; $values[] = $dateFrom . ' 00:00:00'; }
        if ( '' !== $dateTo ) { $where[] = 'last_seen_gmt<%s'; $values[] = gmdate( 'Y-m-d H:i:s', strtotime( $dateTo . ' +1 day' ) ); }
        if ( $minHits > 0 ) { $where[] = 'hits>=%d'; $values[] = $minHits; }
        $whereSql = implode( ' AND ', $where );
        $orders = array(
            'hits' => 'hits DESC,last_seen_gmt DESC,id DESC',
            'recent' => 'last_seen_gmt DESC,hits DESC,id DESC',
            'oldest' => 'last_seen_gmt ASC,id ASC',
            'first_seen' => 'first_seen_gmt DESC,id DESC',
        );
        $orderSql = $orders[ $sort ];
        $countSql = "SELECT COUNT(*) FROM %i WHERE {$whereSql}";
        $countArgs = array_merge( array( $this->table ), $values );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Clauses/order come from fixed allowlists; all user-controlled values and table identifier are prepared.
        $total = (int) $wpdb->get_var( $wpdb->prepare( $countSql, $countArgs ) );
        $pages = max( 1, (int) ceil( $total / $perPage ) );
        $page = min( $page, $pages );
        $offset = ( $page - 1 ) * $perPage;
        $sql = "SELECT * FROM %i WHERE {$whereSql} ORDER BY {$orderSql} LIMIT %d OFFSET %d";
        $args = array_merge( array( $this->table ), $values, array( $perPage, $offset ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Clauses/order come from fixed allowlists; all user-controlled values and table identifier are prepared; result is bounded.
        $items = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        return array( 'items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage );
    }

    /** @return array{seo:int,access:int,technical:int,probe:int,total:int} */
    public function signalCounts(): array {
        global $wpdb;
        $out = array( 'seo' => 0, 'access' => 0, 'technical' => 0, 'probe' => 0, 'total' => 0 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small aggregate over plugin-owned operational table.
        $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT signal_kind,COUNT(*) AS c FROM %i GROUP BY signal_kind', $this->table ), ARRAY_A );
        foreach ( $rows as $row ) {
            $kind = (string) ( $row['signal_kind'] ?? '' );
            $count = (int) ( $row['c'] ?? 0 );
            if ( isset( $out[ $kind ] ) ) { $out[ $kind ] = $count; }
            $out['total'] += $count;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function diagnostics(): array {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compact aggregate diagnostics over plugin-owned operational table.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total_rows,COALESCE(SUM(hits),0) AS total_hits,COALESCE(SUM(CASE WHEN signal_kind<>%s THEN 1 ELSE 0 END),0) AS nonseo_rows,COALESCE(SUM(CASE WHEN signal_kind<>%s AND hits<=1 THEN 1 ELSE 0 END),0) AS one_hit_noise,COALESCE(SUM(CASE WHEN signal_kind<>%s AND hits<=2 THEN 1 ELSE 0 END),0) AS low_value_noise,COALESCE(SUM(CASE WHEN signal_kind<>%s AND hits<=2 THEN hits ELSE 0 END),0) AS low_value_hits,COALESCE(SUM(CASE WHEN first_seen_gmt>=%s THEN 1 ELSE 0 END),0) AS new_24h,COALESCE(SUM(CASE WHEN signal_kind<>%s AND first_seen_gmt>=%s THEN 1 ELSE 0 END),0) AS new_noise_24h FROM %i",
                NotFoundSignalClassifier::SEO,
                NotFoundSignalClassifier::SEO,
                NotFoundSignalClassifier::SEO,
                NotFoundSignalClassifier::SEO,
                $cutoff,
                NotFoundSignalClassifier::SEO,
                $cutoff,
                $this->table
            ),
            ARRAY_A
        );
        $row = is_array( $row ) ? $row : array();
        $totalRows = max( 0, (int) ( $row['total_rows'] ?? 0 ) );
        $nonSeoRows = max( 0, (int) ( $row['nonseo_rows'] ?? 0 ) );
        $oneHitNoise = max( 0, (int) ( $row['one_hit_noise'] ?? 0 ) );
        $lowValueNoise = max( 0, (int) ( $row['low_value_noise'] ?? 0 ) );
        return array(
            'total_rows' => $totalRows,
            'total_hits' => max( 0, (int) ( $row['total_hits'] ?? 0 ) ),
            'nonseo_rows' => $nonSeoRows,
            'storage_pressure_pct' => NotFoundRetentionPolicy::NON_SEO_SOFT_CAP > 0 ? round( ( $nonSeoRows / NotFoundRetentionPolicy::NON_SEO_SOFT_CAP ) * 100, 1 ) : 0.0,
            'hard_pressure_pct' => NotFoundRetentionPolicy::NON_SEO_HARD_CAP > 0 ? round( ( $nonSeoRows / NotFoundRetentionPolicy::NON_SEO_HARD_CAP ) * 100, 1 ) : 0.0,
            'one_hit_noise' => $oneHitNoise, // Compatibility evidence for older integrations.
            'low_value_noise' => $lowValueNoise,
            'low_value_hits' => max( 0, (int) ( $row['low_value_hits'] ?? 0 ) ),
            'new_24h' => max( 0, (int) ( $row['new_24h'] ?? 0 ) ),
            'new_noise_24h' => max( 0, (int) ( $row['new_noise_24h'] ?? 0 ) ),
            'noise_share_pct' => $totalRows > 0 ? round( ( $lowValueNoise / $totalRows ) * 100, 1 ) : 0.0,
            'retention' => NotFoundRetentionPolicy::publicSummary(),
            'last_retention_run' => $this->retentionReport(),
        );
    }

    /**
     * Report only rows that the database confirms it removed. A SELECT count
     * alone is never proof that the following DELETE succeeded. Hits are
     * reported only when the observed count agrees with the affected rows;
     * concurrent writes otherwise make a pre-delete hits estimate unreliable.
     *
     * @return array{rows_removed:int,hits_removed:int}
     */
    private function deleteWithReport( string $countSql, string $deleteSql ): array {
        global $wpdb;
        $empty = array( 'rows_removed' => 0, 'hits_removed' => 0 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL arrives already prepared at every deleteWithReport() call site (including %i table identifier); no raw user input.
        $row = $wpdb->get_row( $countSql, ARRAY_A );
        if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error ) { return $empty; }
        $before = max( 0, (int) ( $row['rows_removed'] ?? 0 ) );
        if ( 0 === $before ) { return $empty; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL arrives already prepared at every deleteWithReport() call site (including %i table identifier); no raw user input.
        $deleted = $wpdb->query( $deleteSql );
        if ( false === $deleted || '' !== (string) $wpdb->last_error ) { return $empty; }
        $removed = max( 0, (int) $deleted );
        return array(
            'rows_removed' => $removed,
            'hits_removed' => $removed === $before ? max( 0, (int) ( $row['hits_removed'] ?? 0 ) ) : 0,
        );
    }

    /** @return array{rows_removed:int,hits_removed:int} */
    public function purgeNonSeoBacklog(): array {
        global $wpdb;
        return $this->deleteWithReport(
            $wpdb->prepare( 'SELECT COUNT(*) AS rows_removed,COALESCE(SUM(hits),0) AS hits_removed FROM %i WHERE signal_kind<>%s', $this->table, NotFoundSignalClassifier::SEO ),
            $wpdb->prepare( 'DELETE FROM %i WHERE signal_kind<>%s', $this->table, NotFoundSignalClassifier::SEO )
        );
    }

    /** @return array{rows_removed:int,hits_removed:int} */
    public function purgeLowValueNoise(): array {
        global $wpdb;
        $signals = array( NotFoundSignalClassifier::ACCESS, NotFoundSignalClassifier::TECHNICAL, NotFoundSignalClassifier::PROBE );
        return $this->deleteWithReport(
            $wpdb->prepare( 'SELECT COUNT(*) AS rows_removed,COALESCE(SUM(hits),0) AS hits_removed FROM %i WHERE signal_kind IN (%s,%s,%s) AND hits<=2', $this->table, $signals[0], $signals[1], $signals[2] ),
            $wpdb->prepare( 'DELETE FROM %i WHERE signal_kind IN (%s,%s,%s) AND hits<=2', $this->table, $signals[0], $signals[1], $signals[2] )
        );
    }

    /** @return array{rows_removed:int,hits_removed:int} */
    public function purgeOneHitNoise(): array { return $this->purgeLowValueNoise(); }

    /** @return array<int,array<string,mixed>> */
    public function securityBatch( int $afterId = 0, int $limit = 100 ): array {
        global $wpdb;
        $afterId = max( 0, $afterId );
        $limit = max( 1, min( 250, $limit ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded internal integration read over privacy-light plugin-owned aggregates.
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id,path,signal_kind,hits,first_seen_gmt,last_seen_gmt FROM %i WHERE id>%d AND signal_kind IN (%s,%s,%s) ORDER BY id ASC LIMIT %d',
                $this->table,
                $afterId,
                NotFoundSignalClassifier::ACCESS,
                NotFoundSignalClassifier::TECHNICAL,
                NotFoundSignalClassifier::PROBE,
                $limit
            ),
            ARRAY_A
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function exportBatch( int $afterId = 0, int $limit = 500 ): array {
        global $wpdb;
        $afterId = max( 0, $afterId );
        $limit = max( 1, min( 500, $limit ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded export over plugin-owned aggregate table.
        return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id,path,signal_kind,language_key,hits,first_seen_gmt,last_seen_gmt FROM %i WHERE id>%d ORDER BY id ASC LIMIT %d', $this->table, $afterId, $limit ), ARRAY_A );
    }

    /** @param array<int,int> $ids @return array<int,array<string,mixed>> */
    public function rowsByIds( array $ids ): array {
        global $wpdb;
        $ids = array_values( array_slice( array_unique( array_filter( array_map( 'absint', $ids ) ) ), 0, 100 ) );
        if ( empty( $ids ) ) { return array(); }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $args = array_merge( array( $this->table ), $ids );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded query over the plugin-owned aggregate table.
        return (array) $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder tokens are generated from a bounded sanitized integer list; values remain arguments to prepare().
            $wpdb->prepare( "SELECT id,path,language_key,signal_kind,hits FROM %i WHERE id IN ({$placeholders}) ORDER BY id ASC", $args ),
            ARRAY_A
        );
    }

    /** @param array<int,int> $ids */
    public function deleteMany( array $ids ): int {
        global $wpdb;
        $ids = array_values( array_slice( array_unique( array_filter( array_map( 'absint', $ids ) ) ), 0, 100 ) );
        if ( empty( $ids ) ) { return 0; }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $args = array_merge( array( $this->table ), $ids );
        // Direct SQL is required for one bounded set-based delete on the plugin-owned aggregate table.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk administrative delete is intentionally uncached and bounded to 100 sanitized IDs.
        $deleted = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder tokens are generated from a bounded sanitized integer list; values remain arguments to prepare().
            $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", $args )
        );
        return max( 0, (int) $deleted );
    }

    public function delete( int $id ): void { if ( $id <= 0 ) { return; } global $wpdb; /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative delete from plugin-owned table. */ $wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) ); }
    public function clear(): void { global $wpdb; /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator-requested reset of plugin-owned aggregate log. */ $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $this->table ) ); }
    /** @return array<string,mixed> */
    public function pruneAdaptive(): array {
        global $wpdb;
        $report = array(
            'ran_at_gmt' => gmdate( 'c' ),
            'rows_removed' => 0,
            'hits_removed' => 0,
            'signals' => array(),
        );
        foreach ( NotFoundRetentionPolicy::rules() as $signal => $rule ) {
            $removedRows = 0;
            $removedHits = 0;
            $days = max( 1, (int) $rule['days'] );
            $lowValueDays = max( 1, min( $days, (int) $rule['low_value_days'] ) );
            if ( $lowValueDays < $days ) {
                $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $lowValueDays * DAY_IN_SECONDS ) );
                $deleted = $this->deleteWithReport(
                    $wpdb->prepare( 'SELECT COUNT(*) AS rows_removed,COALESCE(SUM(hits),0) AS hits_removed FROM %i WHERE signal_kind=%s AND hits<=%d AND last_seen_gmt<%s', $this->table, $signal, NotFoundRetentionPolicy::LOW_VALUE_MAX_HITS, $cutoff ),
                    $wpdb->prepare( 'DELETE FROM %i WHERE signal_kind=%s AND hits<=%d AND last_seen_gmt<%s', $this->table, $signal, NotFoundRetentionPolicy::LOW_VALUE_MAX_HITS, $cutoff )
                );
                $removedRows += $deleted['rows_removed'];
                $removedHits += $deleted['hits_removed'];
            }
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
            $deleted = $this->deleteWithReport(
                $wpdb->prepare( 'SELECT COUNT(*) AS rows_removed,COALESCE(SUM(hits),0) AS hits_removed FROM %i WHERE signal_kind=%s AND last_seen_gmt<%s', $this->table, $signal, $cutoff ),
                $wpdb->prepare( 'DELETE FROM %i WHERE signal_kind=%s AND last_seen_gmt<%s', $this->table, $signal, $cutoff )
            );
            $removedRows += $deleted['rows_removed'];
            $removedHits += $deleted['hits_removed'];
            $report['signals'][ $signal ] = array( 'rows_removed' => $removedRows, 'hits_removed' => $removedHits );
            $report['rows_removed'] += $removedRows;
            $report['hits_removed'] += $removedHits;
        }
        $report['pressure_valve'] = $this->applyPressureValve();
        $report['rows_removed'] += (int) $report['pressure_valve']['rows_removed'];
        update_option( self::OPTION_RETENTION_REPORT, $report, false );
        return $report;
    }

    /** @return array{activated:bool,before:int,target:int,rows_removed:int,hard_activated:bool,hard_before:int,hard_target:int,hard_rows_removed:int} */
    private function applyPressureValve(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small aggregate over plugin-owned operational table for a bounded storage-pressure guard.
        $before = max( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE signal_kind<>%s', $this->table, NotFoundSignalClassifier::SEO ) ) );
        $report = array(
            'activated' => false,
            'before' => $before,
            'target' => NotFoundRetentionPolicy::NON_SEO_TARGET,
            'rows_removed' => 0,
            'hard_activated' => false,
            'hard_before' => $before,
            'hard_target' => NotFoundRetentionPolicy::NON_SEO_HARD_TARGET,
            'hard_rows_removed' => 0,
        );
        if ( $before > NotFoundRetentionPolicy::NON_SEO_SOFT_CAP ) {
            $limit = min( NotFoundRetentionPolicy::PRESSURE_TRIM_MAX, max( 0, $before - NotFoundRetentionPolicy::NON_SEO_TARGET ) );
            if ( $limit > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Soft pressure valve removes only oldest low-value non-SEO rows from plugin-owned aggregate storage.
                $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE signal_kind<>%s AND hits<=%d ORDER BY last_seen_gmt ASC,id ASC LIMIT %d', $this->table, NotFoundSignalClassifier::SEO, NotFoundRetentionPolicy::LOW_VALUE_MAX_HITS, $limit ) );
                $report['activated'] = true;
                $report['rows_removed'] = max( 0, (int) $deleted );
            }
        }
        // Re-count after the low-value trim. The hard ceiling is an emergency product-safety boundary, not a forensic retention promise.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small post-trim aggregate over plugin-owned operational storage.
        $hardBefore = max( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE signal_kind<>%s', $this->table, NotFoundSignalClassifier::SEO ) ) );
        $report['hard_before'] = $hardBefore;
        if ( $hardBefore > NotFoundRetentionPolicy::NON_SEO_HARD_CAP ) {
            $hardLimit = min( NotFoundRetentionPolicy::HARD_TRIM_MAX, max( 0, $hardBefore - NotFoundRetentionPolicy::NON_SEO_HARD_TARGET ) );
            if ( $hardLimit > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Emergency ceiling keeps YSEO non-SEO observer storage bounded; lowest-hit and oldest evidence is discarded first.
                $hardDeleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE signal_kind<>%s ORDER BY hits ASC,last_seen_gmt ASC,id ASC LIMIT %d', $this->table, NotFoundSignalClassifier::SEO, $hardLimit ) );
                $report['hard_activated'] = true;
                $report['hard_rows_removed'] = max( 0, (int) $hardDeleted );
                $report['rows_removed'] += $report['hard_rows_removed'];
            }
        }
        return $report;
    }

    /** Run a lightweight storage guard during sustained non-SEO traffic even when WP-Cron is delayed or disabled. */
    private function maybeInlinePressureGuard(): void {
        $now = time();
        $last = max( 0, (int) get_option( self::OPTION_INLINE_GUARD_AT, 0 ) );
        if ( $last > 0 && ( $now - $last ) < self::INLINE_GUARD_INTERVAL ) { return; }
        update_option( self::OPTION_INLINE_GUARD_AT, $now, false );
        $this->applyPressureValve();
    }

    /** @return array<string,mixed> */
    public function retentionReport(): array {
        $value = get_option( self::OPTION_RETENTION_REPORT, array() );
        return is_array( $value ) ? $value : array();
    }

    /** Backward-compatible explicit age prune for integrations that still call it. */
    public function prune( int $days = 90 ): void {
        global $wpdb;
        $days = max( 7, min( 365, $days ) );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded compatibility retention delete.
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen_gmt<%s', $this->table, $cutoff ) );
    }

    private function normalizePath( string $path ): string { $path=rawurldecode(trim($path)); $path=(string)wp_parse_url($path,PHP_URL_PATH); if(''===$path){$path='/';} $path='/'.ltrim(preg_replace('#/+#','/',$path)?:'','/'); if(strlen($path)>2048||false!==strpos($path,"\0")){return '';} return $path; }
    private function normalizeLanguage( string $language ): string { $language=strtolower(str_replace('_','-',trim(sanitize_text_field($language)))); return preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/',$language)?$language:''; }
    private function normalizeDate( string $date ): string { $date=trim(sanitize_text_field($date)); if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){return '';} [$y,$m,$d]=array_map('intval',explode('-',$date)); return checkdate($m,$d,$y)?$date:''; }
}
