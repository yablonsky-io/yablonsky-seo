<?php
namespace Yablonsky\PerformanceSEO\AI\Metadata;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BulkMetadataState {
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_PAUSED = 'paused';
    public const PHASE_INVENTORY = 'inventory';
    public const PHASE_GENERATE = 'generate';
    public const PHASE_COMPLETE = 'complete';
    public const POLICY_FILL_MISSING = 'fill-missing';
    public const POLICY_AI_GENERATED = 'ai-generated';
    public const POLICY_REPLACE = 'replace';

    /** @var array<string,mixed> */ private $data;

    /** @param array<string,mixed> $data */
    public function __construct( array $data = array() ) {
        $now = gmdate( 'c' );
        $this->data = array_merge( array(
            'status' => self::STATUS_RUNNING,
            'phase' => self::PHASE_INVENTORY,
            'policy' => self::POLICY_FILL_MISSING,
            'fields' => array( 'focus_keyphrase', 'seo_title', 'meta_description' ),
            'languages' => array( 'all' ),
            'cursor' => 0,
            'snapshot_max_id' => 0,
            'candidate_total' => 0,
            'candidate_scanned' => 0,
            'total_posts' => 0,
            'processed_posts' => 0,
            'language_skipped_posts' => 0,
            'generated_posts' => 0,
            'updated_fields' => 0,
            'drafted_fields' => 0,
            'preserved_fields' => 0,
            'mirrored_source_values' => 0,
            'failed_posts' => 0,
            'pending_ids' => array(),
            'batch_size' => 5,
            'scan_batch_size' => 100,
            'recommended_delay_ms' => 250,
            'consecutive_errors' => 0,
            'last_error' => '',
            'started_at' => $now,
            'phase_started_at' => $now,
            'updated_at' => $now,
            'message' => '',
        ), $data );
    }

    /** @return array<string,mixed> */
    public function toArray(): array {
        $out = $this->data;
        $started = strtotime( (string) ( $out['started_at'] ?? '' ) );
        $elapsed = false === $started ? 0 : max( 0, time() - $started );
        $phase = (string) ( $out['phase'] ?? self::PHASE_INVENTORY );
        $denominator = self::PHASE_INVENTORY === $phase ? (int) ( $out['candidate_total'] ?? 0 ) : (int) ( $out['total_posts'] ?? 0 );
        $done = self::PHASE_INVENTORY === $phase ? (int) ( $out['candidate_scanned'] ?? 0 ) : (int) ( $out['processed_posts'] ?? 0 );
        $percent = $denominator > 0 ? min( 100, max( 0, (int) floor( ( $done / $denominator ) * 100 ) ) ) : ( self::PHASE_COMPLETE === $phase ? 100 : 0 );
        $phaseStarted = strtotime( (string) ( $out['phase_started_at'] ?? '' ) );
        $phaseElapsed = false === $phaseStarted ? 0 : max( 0, time() - $phaseStarted );
        $eta = 0;
        if ( $done > 0 && $denominator > $done && $phaseElapsed > 0 ) {
            $rate = $done / $phaseElapsed;
            if ( $rate > 0 ) { $eta = (int) ceil( ( $denominator - $done ) / $rate ); }
        }
        $out['progress_percent'] = self::STATUS_COMPLETE === (string) ( $out['status'] ?? '' ) ? 100 : $percent;
        $out['elapsed_seconds'] = $elapsed;
        $out['eta_seconds'] = $eta;
        return $out;
    }

    /** @return mixed */ public function get( string $key ) { return $this->data[ $key ] ?? null; }
    /** @param array<string,mixed> $changes */ public function merge( array $changes ): self { $next = array_merge( $this->data, $changes ); $next['updated_at'] = gmdate( 'c' ); return new self( $next ); }
}
