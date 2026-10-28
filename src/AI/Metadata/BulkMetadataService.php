<?php
namespace Yablonsky\PerformanceSEO\AI\Metadata;

use Yablonsky\PerformanceSEO\Language\ResourceLanguageIndex;
use Yablonsky\PerformanceSEO\Migration\Mirror\MetadataMirrorRepository;
use Yablonsky\PerformanceSEO\Migration\Mirror\MetadataMirrorService;
use Yablonsky\PerformanceSEO\Runtime\ProviderDetector;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable bounded site-wide AI metadata campaign.
 *
 * No request attempts to process the whole site. The campaign first inventories
 * the selected language scope in keyset batches, then processes at most five
 * resource writes/AI drafts per generation step. State is durable in wp_options,
 * so browser closure, PHP request timeout, cron delay or a transient provider
 * failure cannot lose the cursor or corrupt already completed resources.
 */
final class BulkMetadataService {
    /** @var BulkMetadataRepository */ private $repository;
    /** @var MetadataSuggestionService */ private $suggestions;
    /** @var BulkMetadataPostReader */ private $posts;
    /** @var MetadataMirrorService */ private $mirror;
    /** @var ProviderDetector */ private $detector;
    /** @var ResourceLanguageIndex */ private $languages;

    public function __construct( BulkMetadataRepository $repository, ?MetadataSuggestionService $suggestions = null, ?ProviderDetector $detector = null ) {
        $this->repository = $repository;
        $this->suggestions = null === $suggestions ? new MetadataSuggestionService() : $suggestions;
        $this->posts = new BulkMetadataPostReader();
        $this->mirror = new MetadataMirrorService( new MetadataMirrorRepository() );
        $this->detector = null === $detector ? new ProviderDetector() : $detector;
        $this->languages = new ResourceLanguageIndex();
    }

    /** @param array<int,string> $fields @param array<int,string> $languages */
    public function start( string $policy, array $fields = array(), array $languages = array( 'all' ) ): BulkMetadataState {
        if ( ! $this->suggestions->isConfigured() ) {
            $state = new BulkMetadataState( array( 'status' => BulkMetadataState::STATUS_BLOCKED, 'message' => __( 'Connect and verify a Yablonsky AI User API key in Yablonsky SEO Settings before starting remote compute.', 'yablonsky-seo' ) ) );
            $this->repository->save( $state );
            return $state;
        }

        $allowedPolicies = array( BulkMetadataState::POLICY_FILL_MISSING, BulkMetadataState::POLICY_AI_GENERATED, BulkMetadataState::POLICY_REPLACE );
        $policy = in_array( $policy, $allowedPolicies, true ) ? $policy : BulkMetadataState::POLICY_FILL_MISSING;
        $fields = $this->sanitizeFields( $fields );
        $languages = $this->sanitizeLanguages( $languages );

        if ( BulkMetadataState::POLICY_REPLACE === $policy && $this->detector->hasProvider( 'yoast' ) ) {
            $state = new BulkMetadataState( array(
                'status' => BulkMetadataState::STATUS_BLOCKED,
                'policy' => $policy,
                'fields' => $fields,
                'languages' => $languages,
                'message' => __( 'Bulk replacement is locked while Yoast owns public authority. Mirror exact source metadata and complete a verified takeover first, or review an individual AI suggestion inside the editor.', 'yablonsky-seo' ),
            ) );
            $this->repository->save( $state );
            return $state;
        }

        $sourceActive = $this->detector->hasProvider( 'yoast' );
        if ( BulkMetadataState::POLICY_REPLACE === $policy ) {
            $mode = 'Explicit AI replacement';
        } elseif ( BulkMetadataState::POLICY_AI_GENERATED === $policy ) {
            $mode = $sourceActive ? 'AI draft refresh' : 'AI-generated-only refresh';
        } else {
            $mode = $sourceActive ? 'AI fill-missing staging' : 'AI fill-missing';
        }
        $languageSummary = in_array( 'all', $languages, true ) ? 'all resource languages' : implode( ', ', $languages );
        $now = gmdate( 'c' );
        $snapshotMaxId = $this->posts->maxCandidateId();
        $state = new BulkMetadataState( array(
            'policy' => $policy,
            'fields' => $fields,
            'languages' => $languages,
            'snapshot_max_id' => $snapshotMaxId,
            'candidate_total' => $this->posts->countCandidates( $snapshotMaxId ),
            'phase' => BulkMetadataState::PHASE_INVENTORY,
            'started_at' => $now,
            'phase_started_at' => $now,
            'message' => sprintf( '%s campaign is preparing a durable %s scope. No site-wide PHP request is used.', $mode, $languageSummary ),
        ) );
        $this->repository->save( $state );
        BulkMetadataRunner::schedule();
        return $state;
    }

    public function pause(): BulkMetadataState {
        $state = $this->repository->load();
        if ( null === $state ) { return new BulkMetadataState( array( 'status' => BulkMetadataState::STATUS_PAUSED, 'message' => __( 'AI metadata campaign ready to start.', 'yablonsky-seo' ) ) ); }
        $paused = $state->merge( array( 'status' => BulkMetadataState::STATUS_PAUSED, 'message' => __( 'AI metadata campaign paused safely. Durable cursor and pending resources were preserved.', 'yablonsky-seo' ) ) );
        $this->repository->save( $paused );
        BulkMetadataRunner::clearSchedule();
        return $paused;
    }

    public function resume(): BulkMetadataState {
        $state = $this->repository->load();
        if ( null === $state ) { return $this->start( BulkMetadataState::POLICY_FILL_MISSING ); }
        if ( BulkMetadataState::STATUS_COMPLETE === $state->get( 'status' ) || BulkMetadataState::STATUS_BLOCKED === $state->get( 'status' ) ) { return $state; }
        $next = $state->merge( array( 'status' => BulkMetadataState::STATUS_RUNNING, 'consecutive_errors' => 0, 'last_error' => '', 'recommended_delay_ms' => 250, 'message' => __( 'AI metadata campaign resumed from its durable cursor.', 'yablonsky-seo' ) ) );
        $this->repository->save( $next );
        BulkMetadataRunner::schedule();
        return $next;
    }

    public function step(): BulkMetadataState {
        $state = $this->repository->load();
        if ( null === $state ) { return $this->start( BulkMetadataState::POLICY_FILL_MISSING ); }
        if ( BulkMetadataState::STATUS_RUNNING !== $state->get( 'status' ) ) { return $state; }
        if ( BulkMetadataState::PHASE_INVENTORY === $state->get( 'phase' ) ) { return $this->inventoryStep( $state ); }
        if ( BulkMetadataState::PHASE_GENERATE === $state->get( 'phase' ) ) { return $this->generationStep( $state ); }
        return $state;
    }

    private function inventoryStep( BulkMetadataState $state ): BulkMetadataState {
        $ids = $this->posts->next( (int) $state->get( 'cursor' ), (int) $state->get( 'scan_batch_size' ), (int) $state->get( 'snapshot_max_id' ) );
        if ( empty( $ids ) ) {
            $total = (int) $state->get( 'total_posts' );
            if ( $total < 1 ) {
                $done = $state->merge( array( 'status' => BulkMetadataState::STATUS_COMPLETE, 'phase' => BulkMetadataState::PHASE_COMPLETE, 'message' => __( 'AI metadata campaign complete: 0 resources matched the selected language scope.', 'yablonsky-seo' ) ) );
                $this->repository->save( $done );
                BulkMetadataRunner::clearSchedule();
                return $done;
            }
            $next = $state->merge( array(
                'phase' => BulkMetadataState::PHASE_GENERATE,
                'cursor' => 0,
                'pending_ids' => array(),
                'phase_started_at' => gmdate( 'c' ),
                'recommended_delay_ms' => 250,
                'message' => sprintf( 'Language scope prepared: %d resources. Generation is resumable and processes at most five resources per write step.', $total ),
            ) );
            $this->repository->save( $next );
            BulkMetadataRunner::schedule();
            return $next;
        }

        $selectedLanguages = $this->stateLanguages( $state );
        $matched = 0;
        $skipped = 0;
        foreach ( $ids as $postId ) {
            $language = $this->languages->languageForPost( (int) $postId, true );
            if ( $this->matchesLanguage( (string) ( $language['language_key'] ?? 'auto' ), $selectedLanguages ) ) { ++$matched; }
            else { ++$skipped; }
        }
        $next = $state->merge( array(
            'cursor' => max( $ids ),
            'candidate_scanned' => min( (int) $state->get( 'candidate_total' ), (int) $state->get( 'candidate_scanned' ) + count( $ids ) ),
            'total_posts' => (int) $state->get( 'total_posts' ) + $matched,
            'language_skipped_posts' => (int) $state->get( 'language_skipped_posts' ) + $skipped,
            'message' => __( 'Preparing multilingual scope in bounded batches. Content language is resolved per resource; administrator locale is ignored.', 'yablonsky-seo' ),
        ) );
        $this->repository->save( $next );
        BulkMetadataRunner::schedule();
        return $next;
    }

    private function generationStep( BulkMetadataState $state ): BulkMetadataState {
        $pending = array_values( array_unique( array_filter( array_map( 'absint', (array) $state->get( 'pending_ids' ) ) ) ) );
        $cursor = (int) $state->get( 'cursor' );
        $selectedLanguages = $this->stateLanguages( $state );

        // Refill a durable pending queue from a larger read batch. This lets a
        // 200k-resource site move through sparse language scopes without losing
        // matching IDs merely because AI writes stay bounded to five at a time.
        while ( count( $pending ) < (int) $state->get( 'batch_size' ) ) {
            $scanIds = $this->posts->next( $cursor, (int) $state->get( 'scan_batch_size' ), (int) $state->get( 'snapshot_max_id' ) );
            if ( empty( $scanIds ) ) { break; }
            $cursor = max( $scanIds );
            foreach ( $scanIds as $postId ) {
                $language = $this->languages->languageForPost( (int) $postId, true );
                if ( $this->matchesLanguage( (string) ( $language['language_key'] ?? 'auto' ), $selectedLanguages ) ) { $pending[] = (int) $postId; }
            }
            if ( count( $scanIds ) < (int) $state->get( 'scan_batch_size' ) ) { break; }
        }

        if ( empty( $pending ) ) {
            $done = $state->merge( array(
                'status' => BulkMetadataState::STATUS_COMPLETE,
                'phase' => BulkMetadataState::PHASE_COMPLETE,
                'cursor' => $cursor,
                'pending_ids' => array(),
                'message' => sprintf(
                    'AI metadata campaign complete: %d resources processed, %d generated, %d fields applied, %d review drafts, %d fields preserved, %d source values mirrored, %d resource-level failures.',
                    (int) $state->get( 'processed_posts' ), (int) $state->get( 'generated_posts' ), (int) $state->get( 'updated_fields' ), (int) $state->get( 'drafted_fields' ), (int) $state->get( 'preserved_fields' ), (int) $state->get( 'mirrored_source_values' ), (int) $state->get( 'failed_posts' )
                ),
            ) );
            $this->repository->save( $done );
            BulkMetadataRunner::clearSchedule();
            return $done;
        }

        $ids = array_slice( $pending, 0, (int) $state->get( 'batch_size' ) );
        $selectedMap = $this->fieldMap( $this->stateFields( $state ) );
        $eligible = array();
        $preserved = (int) $state->get( 'preserved_fields' );
        $mirrored = (int) $state->get( 'mirrored_source_values' );
        $policy = (string) $state->get( 'policy' );
        $sourceActive = $this->detector->hasProvider( 'yoast' );

        foreach ( $ids as $postId ) {
            if ( BulkMetadataState::POLICY_FILL_MISSING === $policy && $sourceActive ) {
                $mirrorResult = $this->mirror->mirrorPost( $postId, 'search' );
                $mirrored += isset( $mirrorResult['copied_values'] ) ? (int) $mirrorResult['copied_values'] : 0;
            }
            if ( BulkMetadataState::POLICY_FILL_MISSING === $policy ) {
                $missing = false;
                foreach ( $selectedMap as $metaKey ) {
                    if ( '' === trim( (string) get_post_meta( $postId, $metaKey, true ) ) ) { $missing = true; }
                    else { ++$preserved; }
                }
                if ( ! $missing ) { continue; }
            } elseif ( BulkMetadataState::POLICY_AI_GENERATED === $policy ) {
                $refreshable = $sourceActive ? $this->draftFieldsForPost( $postId, array_keys( $selectedMap ) ) : $this->aiGeneratedFieldsForPost( $postId, array_keys( $selectedMap ) );
                if ( empty( $refreshable ) ) { continue; }
            }
            $eligible[] = $postId;
        }

        $generated = (int) $state->get( 'generated_posts' );
        $updated = (int) $state->get( 'updated_fields' );
        $drafted = (int) $state->get( 'drafted_fields' );
        $failed = (int) $state->get( 'failed_posts' );
        $suggestions = array();

        if ( ! empty( $eligible ) ) {
            try {
                $suggestions = $this->suggestions->suggestBatch( $eligible, array_keys( $selectedMap ) );
            } catch ( \Throwable $error ) {
                $errors = (int) $state->get( 'consecutive_errors' ) + 1;
                $delay = min( 60000, 1000 * ( 2 ** min( 6, max( 0, $errors - 1 ) ) ) );
                $paused = $errors >= 5;
                $next = $state->merge( array(
                    'cursor' => $cursor,
                    'pending_ids' => $pending,
                    'preserved_fields' => $preserved,
                    'mirrored_source_values' => $mirrored,
                    'consecutive_errors' => $errors,
                    'recommended_delay_ms' => $delay,
                    'last_error' => sanitize_text_field( $error->getMessage() ),
                    'status' => $paused ? BulkMetadataState::STATUS_PAUSED : BulkMetadataState::STATUS_RUNNING,
                    'message' => $paused ? 'Remote AI failed repeatedly. Campaign paused without advancing the durable queue; use Resume after the provider/server issue is resolved.' : 'Remote AI step failed transiently. The same resources remain queued and will be retried with backoff.',
                ) );
                $this->repository->save( $next );
                if ( $paused ) { BulkMetadataRunner::clearSchedule(); } else { BulkMetadataRunner::schedule( (int) ceil( $delay / 1000 ) ); }
                return $next;
            }

            foreach ( $eligible as $postId ) {
                if ( ! isset( $suggestions[ $postId ] ) ) { ++$failed; continue; }
                ++$generated;
                $written = array();
                $draftValues = array();
                $aiGeneratedAllowed = BulkMetadataState::POLICY_AI_GENERATED === $policy
                    ? ( $sourceActive ? $this->draftFieldsForPost( $postId, array_keys( $selectedMap ) ) : $this->aiGeneratedFieldsForPost( $postId, array_keys( $selectedMap ) ) )
                    : array();
                foreach ( $selectedMap as $field => $metaKey ) {
                    if ( empty( $suggestions[ $postId ][ $field ] ) ) { continue; }
                    $existing = (string) get_post_meta( $postId, $metaKey, true );
                    if ( BulkMetadataState::POLICY_FILL_MISSING === $policy && '' !== trim( $existing ) ) { ++$preserved; continue; }
                    if ( BulkMetadataState::POLICY_AI_GENERATED === $policy && ! in_array( $field, $aiGeneratedAllowed, true ) ) { ++$preserved; continue; }
                    $value = (string) $suggestions[ $postId ][ $field ];
                    if ( $sourceActive ) { $draftValues[ $field ] = $value; ++$drafted; continue; }
                    update_post_meta( $postId, $metaKey, $value );
                    if ( (string) get_post_meta( $postId, $metaKey, true ) === $value ) { ++$updated; $written[] = $field; }
                }
                if ( ! empty( $draftValues ) ) { $this->mergeAiDraft( $postId, $policy, $draftValues ); }
                if ( ! empty( $written ) ) { $this->mergeAiProvenance( $postId, $policy, $written ); }
            }
        }

        $remaining = array_values( array_slice( $pending, count( $ids ) ) );
        $next = $state->merge( array(
            'cursor' => $cursor,
            'pending_ids' => $remaining,
            'processed_posts' => min( (int) $state->get( 'total_posts' ), (int) $state->get( 'processed_posts' ) + count( $ids ) ),
            'generated_posts' => $generated,
            'updated_fields' => $updated,
            'drafted_fields' => $drafted,
            'preserved_fields' => $preserved,
            'mirrored_source_values' => $mirrored,
            'failed_posts' => $failed,
            'consecutive_errors' => 0,
            'last_error' => '',
            'recommended_delay_ms' => 250,
            'message' => __( 'Campaign checkpoint saved. Closing the browser or a PHP timeout between steps is safe; cron/browser resume continues from this cursor.', 'yablonsky-seo' ),
        ) );
        $this->repository->save( $next );
        BulkMetadataRunner::schedule();
        return $next;
    }

    /** @param array<int,string> $fields @return array<int,string> */
    private function sanitizeFields( array $fields ): array {
        $allowed = array_keys( $this->fieldMap() ); $clean = array();
        foreach ( $fields as $field ) { $field = sanitize_key( (string) $field ); if ( in_array( $field, $allowed, true ) && ! in_array( $field, $clean, true ) ) { $clean[] = $field; } }
        return empty( $clean ) ? $allowed : $clean;
    }

    /** @param array<int,string> $languages @return array<int,string> */
    private function sanitizeLanguages( array $languages ): array {
        $catalog = array_keys( $this->languages->catalog( true ) );
        $clean = array();
        foreach ( $languages as $language ) {
            $language = trim( sanitize_text_field( (string) $language ) );
            if ( 'all' === $language ) { return array( 'all' ); }
            if ( in_array( $language, $catalog, true ) && ! in_array( $language, $clean, true ) ) { $clean[] = $language; }
        }
        return empty( $clean ) ? array( 'all' ) : $clean;
    }

    /** @return array<int,string> */
    private function stateFields( BulkMetadataState $state ): array { $fields = $state->get( 'fields' ); return $this->sanitizeFields( is_array( $fields ) ? $fields : array() ); }
    /** @return array<int,string> */
    private function stateLanguages( BulkMetadataState $state ): array { $languages = $state->get( 'languages' ); return $this->sanitizeLanguages( is_array( $languages ) ? $languages : array( 'all' ) ); }
    /** @param array<int,string> $selected */
    private function matchesLanguage( string $language, array $selected ): bool { return in_array( 'all', $selected, true ) || in_array( $language, $selected, true ); }

    /** @param array<int,string> $selected @return array<string,string> */
    private function fieldMap( array $selected = array() ): array {
        $map = array( 'focus_keyphrase' => '_yps_focus_keyphrase', 'seo_title' => '_yps_seo_title', 'meta_description' => '_yps_meta_description' );
        return empty( $selected ) ? $map : array_intersect_key( $map, array_fill_keys( $selected, true ) );
    }

    /** @param array<int,string> $selected @return array<int,string> */
    private function draftFieldsForPost( int $postId, array $selected ): array {
        $raw = get_post_meta( $postId, '_yps_ai_metadata_draft', true );
        if ( ! is_string( $raw ) || '' === $raw ) { return array(); }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || ! isset( $data['values'] ) || ! is_array( $data['values'] ) ) { return array(); }
        $fields = array(); foreach ( array_keys( $data['values'] ) as $rawField ) { $field = sanitize_key( (string) $rawField ); if ( in_array( $field, $selected, true ) ) { $fields[] = $field; } }
        return array_values( array_unique( $fields ) );
    }

    /** @param array<string,string> $values */
    private function mergeAiDraft( int $postId, string $policy, array $values ): void {
        $raw = get_post_meta( $postId, '_yps_ai_metadata_draft', true );
        $data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
        $existing = is_array( $data ) && isset( $data['values'] ) && is_array( $data['values'] ) ? $data['values'] : array();
        foreach ( $values as $field => $value ) { $existing[ sanitize_key( $field ) ] = (string) $value; }
        update_post_meta( $postId, '_yps_ai_metadata_draft', wp_json_encode( array( 'schema' => 'yseo.ai-metadata-draft.v1', 'policy' => $policy, 'values' => $existing, 'generated_at' => gmdate( 'c' ) ) ) );
    }

    /** @param array<int,string> $selected @return array<int,string> */
    private function aiGeneratedFieldsForPost( int $postId, array $selected ): array {
        $raw = get_post_meta( $postId, '_yps_ai_metadata_provenance', true );
        if ( ! is_string( $raw ) || '' === $raw ) { return array(); }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || ! isset( $data['fields'], $data['value_hashes'] ) || ! is_array( $data['fields'] ) || ! is_array( $data['value_hashes'] ) ) { return array(); }
        $map = $this->fieldMap(); $allowed = array();
        foreach ( $data['fields'] as $rawField ) { $field = sanitize_key( (string) $rawField ); if ( ! in_array( $field, $selected, true ) || ! isset( $map[ $field ], $data['value_hashes'][ $field ] ) ) { continue; } $current = (string) get_post_meta( $postId, $map[ $field ], true ); if ( hash_equals( (string) $data['value_hashes'][ $field ], hash( 'sha256', $current ) ) ) { $allowed[] = $field; } }
        return array_values( array_unique( $allowed ) );
    }

    /** @param array<int,string> $written */
    private function mergeAiProvenance( int $postId, string $policy, array $written ): void {
        $map = $this->fieldMap(); $existingFields = $this->aiGeneratedFieldsForPost( $postId, array_keys( $map ) ); $fields = array_values( array_unique( array_merge( $existingFields, $written ) ) ); $hashes = array();
        foreach ( $fields as $field ) { if ( isset( $map[ $field ] ) ) { $hashes[ $field ] = hash( 'sha256', (string) get_post_meta( $postId, $map[ $field ], true ) ); } }
        update_post_meta( $postId, '_yps_ai_metadata_provenance', wp_json_encode( array( 'schema' => 'yseo.ai-metadata.v1', 'policy' => $policy, 'fields' => $fields, 'value_hashes' => $hashes, 'generated_at' => gmdate( 'c' ) ) ) );
    }
}
