<?php
namespace Yablonsky\PerformanceSEO\Admin\Bulk;

use Yablonsky\PerformanceSEO\AI\Metadata\MetadataSuggestionService;
use Yablonsky\PerformanceSEO\AI\Contract\ServiceError;
use Yablonsky\PerformanceSEO\AI\Metadata\BulkMetadataRepository;
use Yablonsky\PerformanceSEO\AI\Metadata\BulkMetadataState;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageIndex;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Production-oriented bounded editor for the three core editable Search metadata fields. */
final class BulkSeoEditorController {
    private const PAGE = 'yablonsky-seo-bulk-editor';
    private const NONCE = 'yseo_bulk_editor_save';
    private const DEFAULT_PER_PAGE = 25;
    private const PER_PAGE_OPTIONS = array( 10, 25, 50, 100 );
    private const MAX_ROWS = 100;
    private const AI_NONCE = 'yseo_bulk_ai';

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'registerPage' ), 30 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
        add_action( 'admin_post_yseo_bulk_editor_save', array( $this, 'save' ) );
        add_action( 'wp_ajax_yseo_bulk_ai_suggest', array( $this, 'ajaxAiSuggest' ) );
        add_action( 'wp_ajax_yseo_bulk_ai_batch', array( $this, 'ajaxAiBatch' ) );
        foreach ( array( 'post', 'page', 'product' ) as $postType ) {
            add_filter( 'bulk_actions-edit-' . $postType, array( $this, 'bulkActions' ) );
            add_filter( 'handle_bulk_actions-edit-' . $postType, array( $this, 'handleBulkAction' ), 10, 3 );
        }
    }

    public function registerPage(): void {
        add_submenu_page( 'yablonsky-seo', esc_html__( 'Bulk SEO Editor', 'yablonsky-seo' ), esc_html__( 'Bulk Editor', 'yablonsky-seo' ), 'edit_posts', self::PAGE, array( $this, 'render' ) );
    }

    public function enqueueAssets( string $hook ): void {
        if ( false === strpos( $hook, self::PAGE ) ) { return; }
        wp_enqueue_style( 'yps-admin', YPS_URL . 'assets/admin.css', array(), YPS_VERSION );
        wp_enqueue_script( 'yps-admin-editor', YPS_URL . 'assets/admin.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
        $ai = new MetadataSuggestionService();
        wp_localize_script( 'yps-admin-editor', 'YSEO_BULK', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( self::AI_NONCE ),
            'jobNonce' => wp_create_nonce( 'yps_migration_action' ),
            'aiConfigured' => $ai->isConfigured(),
            'jobState' => ( static function () { $state = ( new BulkMetadataRepository() )->load(); return null === $state ? null : $state->toArray(); } )(),
            'labels' => array(
                'unavailable' => esc_html__( 'Connect and verify Yablonsky AI in SEO Settings before requesting remote drafts.', 'yablonsky-seo' ),
                'working' => esc_html__( 'Drafting with Yablonsky AI…', 'yablonsky-seo' ),
                'review' => esc_html__( 'AI draft inserted into this unsaved page. Review it before Save this page.', 'yablonsky-seo' ),
                'failed' => esc_html__( 'AI drafting stopped safely.', 'yablonsky-seo' ),
                'pageWorking' => esc_html__( 'Drafting metadata for this page…', 'yablonsky-seo' ),
                'pageReview' => esc_html__( 'Page AI draft complete. Review every suggestion before saving.', 'yablonsky-seo' ),
                'pageNothing' => esc_html__( 'Every visible metadata field is already filled.', 'yablonsky-seo' ),
                'elapsed' => esc_html__( 'Elapsed', 'yablonsky-seo' ),
                'remaining' => esc_html__( 'Estimated remaining', 'yablonsky-seo' ),
                'preparing' => esc_html__( 'Preparing language scope', 'yablonsky-seo' ),
                'processing' => esc_html__( 'Processing resources', 'yablonsky-seo' ),
                'paused' => esc_html__( 'Paused safely', 'yablonsky-seo' ),
                'noCampaign' => esc_html__( 'Campaign ready to start', 'yablonsky-seo' ),
                'candidates' => esc_html__( 'candidates', 'yablonsky-seo' ),
                'resources' => esc_html__( 'resources', 'yablonsky-seo' ),
                'aiGenerated' => esc_html__( 'AI generated', 'yablonsky-seo' ),
                'applied' => esc_html__( 'Applied', 'yablonsky-seo' ),
                'notApplied' => esc_html__( 'Ready to apply', 'yablonsky-seo' ),
                'allLanguages' => esc_html__( 'All resource languages', 'yablonsky-seo' ),
                'selected' => esc_html__( 'Selected', 'yablonsky-seo' ),
                'selectField' => esc_html__( 'Select at least one metadata field.', 'yablonsky-seo' ),
                'removeLanguage' => esc_html__( 'Remove language', 'yablonsky-seo' ),
                'noLanguages' => esc_html__( 'Select one or more languages', 'yablonsky-seo' ),
            ),
        ) );
    }

    /** @param array<string,string> $actions @return array<string,string> */
    public function bulkActions( array $actions ): array {
        $actions['yseo_edit_metadata'] = __( 'Edit Yablonsky SEO metadata', 'yablonsky-seo' );
        return $actions;
    }

    /** @param array<int,int|string> $postIds */
    public function handleBulkAction( string $redirectUrl, string $action, array $postIds ): string {
        if ( 'yseo_edit_metadata' !== $action ) { return $redirectUrl; }
        $ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $postIds ) ) ) ), 0, self::MAX_ROWS );
        return add_query_arg( array( 'page' => self::PAGE, 'ids' => implode( ',', $ids ), 'per_page' => max( self::DEFAULT_PER_PAGE, min( self::MAX_ROWS, count( $ids ) ) ) ), admin_url( 'admin.php' ) );
    }

    public function render(): void {
        if ( ! current_user_can( 'edit_posts' ) ) { wp_die( esc_html__( 'Editing SEO metadata requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        $pageNumber = max( 1, (int) filter_input( INPUT_GET, 'paged', FILTER_VALIDATE_INT ) );
        $postType = sanitize_key( (string) filter_input( INPUT_GET, 'post_type_filter', FILTER_UNSAFE_RAW ) );
        $postStatus = sanitize_key( (string) filter_input( INPUT_GET, 'post_status_filter', FILTER_UNSAFE_RAW ) );
        $search = sanitize_text_field( (string) filter_input( INPUT_GET, 's', FILTER_UNSAFE_RAW ) );
        $languageFilter = sanitize_text_field( (string) filter_input( INPUT_GET, 'language_filter', FILTER_UNSAFE_RAW ) );
        $languageIndex = new ResourceLanguageIndex();
        $languageCatalog = $languageIndex->catalog( true );
        if ( '' !== $languageFilter && ! isset( $languageCatalog[ $languageFilter ] ) ) { $languageFilter = ''; }
        $perPage = $this->sanitizePerPage( (int) filter_input( INPUT_GET, 'per_page', FILTER_VALIDATE_INT ) );
        $idsRaw = sanitize_text_field( (string) filter_input( INPUT_GET, 'ids', FILTER_UNSAFE_RAW ) );
        $selectedIds = array_slice( array_values( array_unique( array_filter( array_map( 'intval', explode( ',', $idsRaw ) ) ) ) ), 0, self::MAX_ROWS );

        $publicTypes = get_post_types( array( 'public' => true ), 'objects' );
        unset( $publicTypes['attachment'] );
        $allowedNames = array_keys( $publicTypes );
        if ( ! in_array( $postType, $allowedNames, true ) ) { $postType = ''; }
        $allowedStatuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
        if ( ! in_array( $postStatus, $allowedStatuses, true ) ) { $postStatus = ''; }

        $args = array(
            'post_type' => '' !== $postType ? $postType : $allowedNames,
            'post_status' => '' !== $postStatus ? array( $postStatus ) : $allowedStatuses,
            'posts_per_page' => $perPage,
            'paged' => $pageNumber,
            'orderby' => 'modified',
            'order' => 'DESC',
            's' => $search,
            'ignore_sticky_posts' => true,
        );
        if ( '' !== $languageFilter ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Explicit user-selected admin filter over one plugin-owned derived language key; the result remains paginated and bounded.
            $args['meta_query'] = array( array( 'key' => ResourceLanguageIndex::META_LANGUAGE, 'value' => $languageFilter, 'compare' => '=' ) );
        }
        if ( ! empty( $selectedIds ) ) {
            $args['post__in'] = $selectedIds;
            $args['orderby'] = 'post__in';
            $args['posts_per_page'] = self::MAX_ROWS;
            $args['paged'] = 1;
            $pageNumber = 1;
        }
        $query = new \WP_Query( $args );
        $filterArgs = array( 'page' => self::PAGE, 's' => $search, 'post_type_filter' => $postType, 'post_status_filter' => $postStatus, 'language_filter' => $languageFilter, 'per_page' => $perPage );
        ?>
        <div class="wrap yps-wrap yseo-bulk-editor">
            <header class="yps-product-header">
                <div class="yps-product-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Workspace', 'yablonsky-seo' ); ?></span><h1><?php echo esc_html__( 'Bulk SEO Editor', 'yablonsky-seo' ); ?></h1><p><?php echo esc_html__( 'Review and edit a bounded page directly, or run a durable site-wide AI campaign with explicit language and metadata scope.', 'yablonsky-seo' ); ?></p></div>
                <div class="yps-product-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo' ) ); ?>"><?php echo esc_html__( 'Dashboard', 'yablonsky-seo' ); ?></a></div>
            </header>

            <?php if ( '1' === sanitize_text_field( (string) filter_input( INPUT_GET, 'updated', FILTER_UNSAFE_RAW ) ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'SEO metadata for this page was saved.', 'yablonsky-seo' ); ?></p></div><?php endif; ?>

            <section class="yseo-bulk-toolbar-card" aria-label="<?php echo esc_attr__( 'Bulk editor filters', 'yablonsky-seo' ); ?>">
                <form method="get" class="yseo-bulk-filter-form">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
                    <label class="yseo-bulk-filter-search"><span class="screen-reader-text"><?php echo esc_html__( 'Search content', 'yablonsky-seo' ); ?></span><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search title or content', 'yablonsky-seo' ); ?>" /></label>
                    <label><span class="screen-reader-text"><?php echo esc_html__( 'Content type', 'yablonsky-seo' ); ?></span><select name="post_type_filter"><option value=""><?php echo esc_html__( 'All content types', 'yablonsky-seo' ); ?></option><?php foreach ( $publicTypes as $name => $object ) : ?><option value="<?php echo esc_attr( $name ); ?>" <?php selected( $postType, $name ); ?>><?php echo esc_html( (string) $object->labels->name ); ?></option><?php endforeach; ?></select></label>
                    <label><span class="screen-reader-text"><?php echo esc_html__( 'Status', 'yablonsky-seo' ); ?></span><select name="post_status_filter"><option value=""><?php echo esc_html__( 'All statuses', 'yablonsky-seo' ); ?></option><?php foreach ( $allowedStatuses as $status ) : $statusObject = get_post_status_object( $status ); ?><option value="<?php echo esc_attr( $status ); ?>" <?php selected( $postStatus, $status ); ?>><?php echo esc_html( $statusObject ? (string) $statusObject->label : ucfirst( $status ) ); ?></option><?php endforeach; ?></select></label>
                    <label><span class="screen-reader-text"><?php echo esc_html__( 'Content language', 'yablonsky-seo' ); ?></span><select name="language_filter" data-yps-search-select data-yps-search-placeholder="<?php echo esc_attr__( 'Search languages…', 'yablonsky-seo' ); ?>"><option value=""><?php echo esc_html__( 'All content languages', 'yablonsky-seo' ); ?></option><?php foreach ( $languageCatalog as $languageKey => $languageLabel ) : ?><option value="<?php echo esc_attr( (string) $languageKey ); ?>" <?php selected( $languageFilter, (string) $languageKey ); ?>><?php echo esc_html( (string) $languageLabel ); ?> · <?php echo esc_html( (string) $languageKey ); ?></option><?php endforeach; ?></select></label>
                    <input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $perPage ); ?>" />
                    <button class="button button-primary"><?php echo esc_html__( 'Apply', 'yablonsky-seo' ); ?></button>
                    <a class="button yseo-bulk-reset" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Reset', 'yablonsky-seo' ); ?></a>
                </form>
            </section>

            <?php $this->renderPagination( $query, $pageNumber, $filterArgs, $selectedIds, 'top' ); ?>

            <div class="yseo-bulk-ai-bar" data-yseo-bulk-ai-bar>
                <div><strong><?php echo esc_html__( 'AI assist', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Draft metadata for fields ready for completion on the visible page in bounded batches of five. Saving remains editor-controlled.', 'yablonsky-seo' ); ?></span></div>
                <div class="yseo-bulk-ai-bar-actions"><button type="button" class="button button-primary" data-yseo-bulk-ai-page><?php echo esc_html__( 'AI draft open fields on this page', 'yablonsky-seo' ); ?></button><span data-yseo-bulk-ai-page-status aria-live="polite"></span></div>
            </div>
            <div class="yseo-bulk-progress" data-yseo-bulk-page-progress hidden><div class="yseo-bulk-progress-head"><strong data-yseo-bulk-page-progress-label><?php echo esc_html__( 'Preparing page AI draft…', 'yablonsky-seo' ); ?></strong><span data-yseo-bulk-page-progress-percent>0%</span></div><div class="yseo-bulk-progress-track"><span data-yseo-bulk-page-progress-bar style="width:0%"></span></div><div class="yseo-bulk-progress-meta"><span data-yseo-bulk-page-elapsed></span><span data-yseo-bulk-page-eta></span></div></div>

            <?php if ( current_user_can( 'manage_options' ) ) : $campaignState = ( new BulkMetadataRepository() )->load(); ?>
            <section class="yseo-bulk-campaign" data-yseo-bulk-campaign>
                <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Durable site-wide campaign', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'AI metadata by language', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Select one, several or all resource languages. YSEO inventories the scope in bounded batches, checkpoints every step and can resume after browser closure, PHP timeout or a transient provider error.', 'yablonsky-seo' ); ?></p></div><span class="yps-mini-status neutral"><?php echo esc_html__( 'RESUMABLE', 'yablonsky-seo' ); ?></span></div>
                <div class="yseo-bulk-campaign-grid">
                    <div class="yseo-language-scope" data-yseo-language-scope>
                        <div class="yseo-campaign-settings-head"><strong><?php echo esc_html__( 'Languages', 'yablonsky-seo' ); ?></strong><span class="yps-mini-status good" data-yseo-language-state><?php echo esc_html__( 'Applied', 'yablonsky-seo' ); ?></span></div>
                        <div class="yseo-language-multi" data-yseo-language-multi>
                            <button type="button" class="yps-model-picker-button" data-yseo-language-toggle aria-haspopup="dialog" aria-expanded="false"><span data-yseo-language-current><?php echo esc_html__( 'All resource languages', 'yablonsky-seo' ); ?></span><span class="yps-model-picker-caret" aria-hidden="true">⌄</span></button>
                            <div class="yps-model-popover yseo-language-popover" data-yseo-language-popover hidden>
                                <label class="yps-model-search"><span class="screen-reader-text"><?php echo esc_html__( 'Search languages', 'yablonsky-seo' ); ?></span><input type="search" placeholder="<?php echo esc_attr__( 'Search languages…', 'yablonsky-seo' ); ?>" autocomplete="off" data-yseo-language-search></label>
                                <div class="yseo-language-multi-list" data-yseo-language-list>
                                    <label class="yseo-language-multi-option is-all" data-yseo-language-option data-language-search="all resource languages"><input type="checkbox" value="all" data-yseo-job-language checked><span><strong><?php echo esc_html__( 'All resource languages', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Process every detected or unknown language.', 'yablonsky-seo' ); ?></small></span></label>
                                    <?php foreach ( $languageCatalog as $languageKey => $languageLabel ) : ?><label class="yseo-language-multi-option" data-yseo-language-option data-language-search="<?php echo esc_attr( strtolower( (string) $languageLabel . ' ' . (string) $languageKey ) ); ?>"><input type="checkbox" value="<?php echo esc_attr( (string) $languageKey ); ?>" data-yseo-job-language><span><strong><?php echo esc_html( (string) $languageLabel ); ?></strong><small><?php echo esc_html( (string) $languageKey ); ?></small></span></label><?php endforeach; ?>
                                </div>
                                <div class="yseo-picker-actions"><button type="button" class="button button-primary" data-yseo-language-apply><?php echo esc_html__( 'Apply languages', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yseo-language-cancel><?php echo esc_html__( 'Cancel', 'yablonsky-seo' ); ?></button></div>
                            </div>
                        </div>
                        <div class="yseo-scope-state"><small data-yseo-language-summary><?php echo esc_html__( 'All resource languages', 'yablonsky-seo' ); ?></small></div>
                        <div class="yseo-language-chip-list" data-yseo-language-chips aria-live="polite"></div>
                    </div>
                    <div class="yseo-campaign-settings" data-yseo-metadata-scope>
                        <div class="yseo-campaign-settings-head"><strong><?php echo esc_html__( 'Metadata fields', 'yablonsky-seo' ); ?></strong><span class="yps-mini-status good" data-yseo-metadata-state><?php echo esc_html__( 'Applied', 'yablonsky-seo' ); ?></span></div>
                        <div class="yseo-metadata-field-options"><label><input type="checkbox" value="focus_keyphrase" data-yseo-job-field checked> <?php echo esc_html__( 'Focus keyphrase', 'yablonsky-seo' ); ?></label><label><input type="checkbox" value="seo_title" data-yseo-job-field checked> <?php echo esc_html__( 'SEO title', 'yablonsky-seo' ); ?></label><label><input type="checkbox" value="meta_description" data-yseo-job-field checked> <?php echo esc_html__( 'Meta description', 'yablonsky-seo' ); ?></label></div>
                        <label class="yseo-policy-label"><span><?php echo esc_html__( 'Update policy', 'yablonsky-seo' ); ?></span><select data-yseo-job-policy><option value="fill-missing"><?php echo esc_html__( 'Complete metadata gaps only', 'yablonsky-seo' ); ?></option><option value="ai-generated"><?php echo esc_html__( 'Refresh AI-generated metadata only', 'yablonsky-seo' ); ?></option></select></label>
                        <div class="yseo-picker-actions is-inline"><button type="button" class="button button-primary" data-yseo-metadata-apply><?php echo esc_html__( 'Apply metadata scope', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yseo-metadata-cancel><?php echo esc_html__( 'Cancel changes', 'yablonsky-seo' ); ?></button></div>
                        <small><?php echo esc_html__( 'Resumable site-wide automation supports idempotent fill/refresh policies. Reviewed replacement remains available in the page editor.', 'yablonsky-seo' ); ?></small>
                    </div>
                </div>
                <div class="yseo-campaign-actions"><button type="button" class="button button-primary" data-yseo-job-start><?php echo esc_html__( 'Start site-wide campaign', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yseo-job-resume><?php echo esc_html__( 'Resume', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yseo-job-pause><?php echo esc_html__( 'Pause safely', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yseo-job-reset><?php echo esc_html__( 'Reset campaign', 'yablonsky-seo' ); ?></button></div>
                <div class="yseo-bulk-progress is-campaign" data-yseo-job-progress><div class="yseo-bulk-progress-head"><strong data-yseo-job-progress-label><?php echo esc_html__( 'Campaign ready to start', 'yablonsky-seo' ); ?></strong><span data-yseo-job-progress-percent>0%</span></div><div class="yseo-bulk-progress-track"><span data-yseo-job-progress-bar style="width:0%"></span></div><div class="yseo-bulk-progress-meta"><span data-yseo-job-counts></span><span data-yseo-job-times></span></div><p data-yseo-job-message><?php if ( null !== $campaignState ) { echo esc_html( (string) $campaignState->get( 'message' ) ); } ?></p></div>
            </section>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yseo-bulk-edit-form" data-yseo-bulk-form>
                <input type="hidden" name="action" value="yseo_bulk_editor_save" /><?php wp_nonce_field( self::NONCE ); ?>
                <input type="hidden" name="return_post_type" value="<?php echo esc_attr( $postType ); ?>" /><input type="hidden" name="return_language" value="<?php echo esc_attr( $languageFilter ); ?>" /><input type="hidden" name="return_post_status" value="<?php echo esc_attr( $postStatus ); ?>" /><input type="hidden" name="return_search" value="<?php echo esc_attr( $search ); ?>" /><input type="hidden" name="return_paged" value="<?php echo esc_attr( (string) $pageNumber ); ?>" /><input type="hidden" name="return_per_page" value="<?php echo esc_attr( (string) $perPage ); ?>" />
                <div class="yseo-table-actionbar yseo-bulk-editor-actionbar"><button type="button" class="button" data-yseo-bulk-ai-selected data-yseo-bulk-apply><?php echo esc_html__( 'AI draft selected open fields', 'yablonsky-seo' ); ?></button><button type="submit" class="button button-primary" name="save_scope" value="selected" data-yseo-bulk-apply><?php echo esc_html__( 'Save selected', 'yablonsky-seo' ); ?></button><span class="yseo-selection-count" data-yseo-selection-count data-yseo-empty-label="<?php echo esc_attr__( 'Select an option', 'yablonsky-seo' ); ?>" data-yseo-selected-label="<?php echo esc_attr__( 'selected', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'Select an option', 'yablonsky-seo' ); ?></span><span class="description"><?php echo esc_html__( 'Selection actions affect only checked rows. Save this page below remains available for all visible rows.', 'yablonsky-seo' ); ?></span></div>
                <div class="yseo-bulk-table-wrap"><table class="widefat yseo-bulk-table"><thead><tr><th class="check-column"><input type="checkbox" data-yseo-check-all aria-label="<?php echo esc_attr__( 'Select visible rows', 'yablonsky-seo' ); ?>"></th><th class="yseo-bulk-col-content"><?php echo esc_html__( 'Content', 'yablonsky-seo' ); ?></th><th class="yseo-bulk-col-language"><?php echo esc_html__( 'Language', 'yablonsky-seo' ); ?></th><th class="yseo-bulk-col-focus"><?php echo esc_html__( 'Focus keyphrase', 'yablonsky-seo' ); ?></th><th class="yseo-bulk-col-title"><?php echo esc_html__( 'SEO title', 'yablonsky-seo' ); ?></th><th class="yseo-bulk-col-description"><?php echo esc_html__( 'Meta description', 'yablonsky-seo' ); ?></th></tr></thead><tbody>
                <?php foreach ( $query->posts as $post ) : if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) { continue; } $statusObject = get_post_status_object( $post->post_status ); ?>
                    <tr data-yseo-bulk-row="<?php echo esc_attr( (string) $post->ID ); ?>"><th class="check-column"><input type="checkbox" name="selected_rows[]" value="<?php echo esc_attr( (string) $post->ID ); ?>" data-yseo-row-check aria-label="<?php echo esc_attr__( 'Select content row', 'yablonsky-seo' ); ?>"></th><td class="yseo-bulk-content-cell"><input type="hidden" name="rows[<?php echo esc_attr( (string) $post->ID ); ?>][id]" value="<?php echo esc_attr( (string) $post->ID ); ?>" /><strong><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ?: __( 'Untitled', 'yablonsky-seo' ) ); ?></a></strong><div class="yseo-bulk-row-meta"><span><?php echo esc_html( isset( $publicTypes[ $post->post_type ] ) ? (string) $publicTypes[ $post->post_type ]->labels->singular_name : (string) $post->post_type ); ?></span><span><?php echo esc_html( $statusObject ? (string) $statusObject->label : (string) $post->post_status ); ?></span><span>#<?php echo esc_html( (string) $post->ID ); ?></span></div><div class="yseo-bulk-row-actions"><button type="button" class="button button-small yseo-bulk-ai-row" data-yseo-bulk-ai-row><?php echo esc_html__( 'AI draft open fields', 'yablonsky-seo' ); ?></button><span data-yseo-bulk-ai-status aria-live="polite"></span></div></td><?php $rowLanguage = $languageIndex->languageForPost( $post->ID, true ); $rowLanguageKey = (string) ( $rowLanguage['language_key'] ?? 'auto' ); $rowLanguageLabel = $languageIndex->label( $rowLanguageKey ); $rowLanguageParts = explode( '-', $rowLanguageKey ); $rowLanguageShort = 'auto' === strtolower( $rowLanguageKey ) ? 'AUTO' : strtoupper( (string) ( $rowLanguageParts[0] ?? $rowLanguageKey ) ); ?><td class="yseo-bulk-language-cell"><span class="yseo-language-badge" title="<?php echo esc_attr( $rowLanguageLabel . ' · ' . (string) ( $rowLanguage['source'] ?? '' ) ); ?>" aria-label="<?php echo esc_attr( $rowLanguageLabel ); ?>"><?php echo esc_html( $rowLanguageShort ); ?></span></td><td><div class="yseo-bulk-field"><input type="text" data-yseo-bulk-field="focus_keyphrase" name="rows[<?php echo esc_attr( (string) $post->ID ); ?>][focus_keyphrase]" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, '_yps_focus_keyphrase', true ) ); ?>" /><button type="button" class="button button-small yseo-ai-mini-button yseo-bulk-ai-field" data-yseo-bulk-ai-field="focus_keyphrase" title="<?php echo esc_attr__( 'Draft focus keyphrase with Yablonsky AI', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'AI', 'yablonsky-seo' ); ?></button></div></td><td><div class="yseo-bulk-field"><input type="text" data-yseo-bulk-field="seo_title" name="rows[<?php echo esc_attr( (string) $post->ID ); ?>][seo_title]" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, '_yps_seo_title', true ) ); ?>" /><button type="button" class="button button-small yseo-ai-mini-button yseo-bulk-ai-field" data-yseo-bulk-ai-field="seo_title" title="<?php echo esc_attr__( 'Draft SEO title with Yablonsky AI', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'AI', 'yablonsky-seo' ); ?></button></div></td><td><div class="yseo-bulk-field"><textarea rows="2" data-yseo-bulk-field="meta_description" name="rows[<?php echo esc_attr( (string) $post->ID ); ?>][meta_description]"><?php echo esc_textarea( (string) get_post_meta( $post->ID, '_yps_meta_description', true ) ); ?></textarea><button type="button" class="button button-small yseo-ai-mini-button yseo-bulk-ai-field" data-yseo-bulk-ai-field="meta_description" title="<?php echo esc_attr__( 'Draft meta description with Yablonsky AI', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'AI', 'yablonsky-seo' ); ?></button></div></td></tr>
                <?php endforeach; if ( empty( $query->posts ) ) : ?><tr><td colspan="6" class="yseo-bulk-empty"><?php echo esc_html__( 'Adjust the filters to find editable content.', 'yablonsky-seo' ); ?></td></tr><?php endif; ?>
                </tbody></table></div>
                <div class="yseo-bulk-savebar"><div><button type="submit" class="button button-primary" name="save_scope" value="page"><?php echo esc_html__( 'Save this page', 'yablonsky-seo' ); ?></button><span class="description"><?php echo esc_html__( 'Only visible form values are saved. Clearing a value removes the corresponding Yablonsky SEO override.', 'yablonsky-seo' ); ?></span></div></div>
            </form>
            <?php $this->renderPagination( $query, $pageNumber, $filterArgs, $selectedIds, 'bottom' ); ?>
        </div>
        <?php
        wp_reset_postdata();
    }

    /** @param array<string,mixed> $filterArgs @param array<int,int> $selectedIds */
    private function renderPagination( \WP_Query $query, int $pageNumber, array $filterArgs, array $selectedIds, string $position ): void {
        if ( ! empty( $selectedIds ) ) {
            /* translators: %d: selected resource count. */
            $label = sprintf( __( '%d selected resources', 'yablonsky-seo' ), count( $selectedIds ) );
            echo '<div class="yseo-bulk-pagination is-' . esc_attr( $position ) . '"><span>' . esc_html( $label ) . '</span></div>';
            return;
        }
        $total = (int) $query->found_posts;
        $pages = max( 1, (int) $query->max_num_pages );
        /* translators: 1: current page, 2: total pages, 3: matching resource count. */
        $summary = sprintf( __( 'Page %1$d of %2$d · %3$d resources', 'yablonsky-seo' ), $pageNumber, $pages, $total );
        $base = add_query_arg( array_merge( $filterArgs, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
        $links = $pages > 1 ? paginate_links( array( 'base' => $base, 'format' => '', 'current' => $pageNumber, 'total' => $pages, 'type' => 'plain', 'prev_text' => '‹', 'next_text' => '›' ) ) : '';
        echo '<div class="yseo-bulk-pagination is-' . esc_attr( $position ) . '"><span>' . esc_html( $summary ) . '</span><div class="yseo-pagination-controls"><form class="yseo-pagination-size-form" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
        foreach ( $filterArgs as $key => $value ) { if ( 'per_page' !== (string) $key && '' !== (string) $value ) { echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '">'; } }
        echo '<label><span>' . esc_html__( 'Rows per page', 'yablonsky-seo' ) . '</span><select name="per_page" data-yseo-page-size>';
        foreach ( self::PER_PAGE_OPTIONS as $option ) { echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( (int) ( $filterArgs['per_page'] ?? self::DEFAULT_PER_PAGE ), $option, false ) . '>' . esc_html( (string) $option ) . '</option>'; }
        echo '</select></label></form><div class="tablenav-pages">' . wp_kses_post( (string) $links ) . '</div></div></div>';
    }

    public function ajaxAiBatch(): void {
        check_ajax_referer( self::AI_NONCE, 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Requesting bulk AI drafts requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $idsInput = filter_input( INPUT_POST, 'post_ids', FILTER_UNSAFE_RAW );
        $ids = is_string( $idsInput ) ? array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $idsInput ) ) ) ) ), 0, 5 ) : array();
        $fieldsInput = filter_input( INPUT_POST, 'fields', FILTER_UNSAFE_RAW );
        $requested = $this->sanitizeAiFields( is_string( $fieldsInput ) ? explode( ',', $fieldsInput ) : array() );
        $editable = array();
        foreach ( $ids as $postId ) { if ( current_user_can( 'edit_post', $postId ) ) { $editable[] = $postId; } }
        if ( empty( $editable ) ) { wp_send_json_error( array( 'message' => esc_html__( 'Supply editable resources to start AI drafting.', 'yablonsky-seo' ) ), 400 ); }
        try {
            $service = new MetadataSuggestionService();
            $suggestions = $service->suggestBatch( $editable, $requested );
            $meta = $service->lastComputeMeta();
            $message = esc_html__( 'Bounded AI batch ready for review. Nothing was saved automatically.', 'yablonsky-seo' );
            if ( isset( $meta['mode'] ) && 'provider-failover-chat' === $meta['mode'] ) {
                /* translators: %s: alternate AI model identifier. */
                $message .= ' ' . sprintf( esc_html__( 'Primary provider billing was unavailable, so YSEO used the enabled alternate model %s for this draft.', 'yablonsky-seo' ), esc_html( (string) ( $meta['fallback_to'] ?? $meta['model'] ?? '' ) ) );
            }
            wp_send_json_success( array( 'suggestions' => $suggestions, 'message' => $message ) );
        } catch ( ServiceError $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 400 );
        } catch ( \Throwable $error ) {
            unset( $error );
            wp_send_json_error( array( 'message' => esc_html__( 'Bulk AI drafting stopped safely.', 'yablonsky-seo' ) ), 500 );
        }
    }

    public function ajaxAiSuggest(): void {
        check_ajax_referer( self::AI_NONCE, 'nonce' );
        $postId = absint( filter_input( INPUT_POST, 'post_id', FILTER_SANITIZE_NUMBER_INT ) );
        if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Editing this content requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $fieldInput = filter_input( INPUT_POST, 'field', FILTER_UNSAFE_RAW );
        $field = is_string( $fieldInput ) ? sanitize_key( $fieldInput ) : '';
        $fieldsInput = filter_input( INPUT_POST, 'fields', FILTER_UNSAFE_RAW );
        $requested = in_array( $field, array( 'focus_keyphrase', 'seo_title', 'meta_description' ), true )
            ? array( $field )
            : $this->sanitizeAiFields( is_string( $fieldsInput ) ? explode( ',', $fieldsInput ) : array() );
        try {
            $service = new MetadataSuggestionService();
            $suggestions = $service->suggestPost( $postId, $requested );
            $meta = $service->lastComputeMeta();
            $message = esc_html__( 'AI draft ready for review. Nothing was saved automatically.', 'yablonsky-seo' );
            if ( isset( $meta['mode'] ) && 'provider-failover-chat' === $meta['mode'] ) {
                /* translators: %s: alternate AI model identifier. */
                $message .= ' ' . sprintf( esc_html__( 'Primary provider billing was unavailable, so YSEO used the enabled alternate model %s for this draft.', 'yablonsky-seo' ), esc_html( (string) ( $meta['fallback_to'] ?? $meta['model'] ?? '' ) ) );
            }
            wp_send_json_success( array( 'suggestions' => $suggestions, 'message' => $message ) );
        } catch ( ServiceError $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 400 );
        } catch ( \Throwable $error ) {
            unset( $error );
            wp_send_json_error( array( 'message' => esc_html__( 'AI drafting stopped safely.', 'yablonsky-seo' ) ), 500 );
        }
    }

    public function save(): void {
        if ( ! current_user_can( 'edit_posts' ) ) { wp_die( esc_html__( 'Editing SEO metadata requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        check_admin_referer( self::NONCE );
        $perPage = $this->sanitizePerPage( (int) filter_input( INPUT_POST, 'return_per_page', FILTER_VALIDATE_INT ) );
        $rawRows = filter_input( INPUT_POST, 'rows', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY );
        $rows = is_array( $rawRows ) ? array_slice( $rawRows, 0, min( self::MAX_ROWS, $perPage ), true ) : array();
        $saveScope = sanitize_key( (string) filter_input( INPUT_POST, 'save_scope', FILTER_UNSAFE_RAW ) );
        if ( 'selected' === $saveScope ) {
            $rawSelected = filter_input( INPUT_POST, 'selected_rows', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY );
            $selected = is_array( $rawSelected ) ? array_slice( array_values( array_unique( array_filter( array_map( 'absint', $rawSelected ) ) ) ), 0, self::MAX_ROWS ) : array();
            $allowed = array_fill_keys( $selected, true );
            $rows = array_filter( $rows, static function ( $id ) use ( $allowed ): bool { return isset( $allowed[ (int) $id ] ); }, ARRAY_FILTER_USE_KEY );
        }
        foreach ( $rows as $id => $row ) {
            $postId = (int) $id;
            if ( $postId <= 0 || ! is_array( $row ) || ! current_user_can( 'edit_post', $postId ) ) { continue; }
            $this->saveField( $postId, '_yps_focus_keyphrase', $row['focus_keyphrase'] ?? '', 200 );
            $this->saveField( $postId, '_yps_seo_title', $row['seo_title'] ?? '', 200 );
            $this->saveField( $postId, '_yps_meta_description', $row['meta_description'] ?? '', 500 );
        }
        $return = add_query_arg( array(
            'page' => self::PAGE,
            'post_type_filter' => sanitize_key( (string) filter_input( INPUT_POST, 'return_post_type', FILTER_UNSAFE_RAW ) ),
            'post_status_filter' => sanitize_key( (string) filter_input( INPUT_POST, 'return_post_status', FILTER_UNSAFE_RAW ) ),
            'language_filter' => sanitize_text_field( (string) filter_input( INPUT_POST, 'return_language', FILTER_UNSAFE_RAW ) ),
            's' => sanitize_text_field( (string) filter_input( INPUT_POST, 'return_search', FILTER_UNSAFE_RAW ) ),
            'paged' => max( 1, (int) filter_input( INPUT_POST, 'return_paged', FILTER_VALIDATE_INT ) ),
            'per_page' => $perPage,
            'updated' => 1,
        ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $return );
        exit;
    }

    /** @param array<int|string,mixed> $fields @return array<int,string> */
    private function sanitizeAiFields( array $fields ): array {
        $allowed = array( 'focus_keyphrase', 'seo_title', 'meta_description' );
        $clean = array();
        foreach ( $fields as $field ) {
            $field = sanitize_key( is_scalar( $field ) ? (string) $field : '' );
            if ( in_array( $field, $allowed, true ) && ! in_array( $field, $clean, true ) ) { $clean[] = $field; }
        }
        return $clean;
    }

    private function sanitizePerPage( int $value ): int { return in_array( $value, self::PER_PAGE_OPTIONS, true ) ? $value : self::DEFAULT_PER_PAGE; }

    /** @param mixed $raw */
    private function saveField( int $postId, string $metaKey, $raw, int $max ): void {
        $value = is_scalar( $raw ) ? sanitize_text_field( wp_unslash( (string) $raw ) ) : '';
        $value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
        if ( '' === $value ) { delete_post_meta( $postId, $metaKey ); } else { update_post_meta( $postId, $metaKey, $value ); }
    }
}
