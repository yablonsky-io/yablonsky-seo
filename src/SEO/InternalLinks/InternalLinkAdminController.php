<?php
namespace Yablonsky\PerformanceSEO\SEO\InternalLinks;

use Yablonsky\PerformanceSEO\Language\ResourceLanguageIndex;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class InternalLinkAdminController {
    // YSEO_222_COMPACT_KPI=PASS — five KPI cards share one desktop row with responsive fallbacks.
    private const PAGE = 'yablonsky-seo-internal-links';
    private const PER_PAGE = 25;
    private const PER_PAGE_OPTIONS = array( 25, 50, 100 );
    /** @var InternalLinkRepository */ private $repository;
    /** @var InternalLinkIndexer */ private $indexer;

    public function __construct( ?InternalLinkRepository $repository = null, ?InternalLinkIndexer $indexer = null ) {
        $this->repository = $repository instanceof InternalLinkRepository ? $repository : new InternalLinkRepository();
        $this->indexer = $indexer instanceof InternalLinkIndexer ? $indexer : new InternalLinkIndexer( $this->repository );
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_post_yseo_internal_links_rebuild', array( $this, 'rebuild' ) );
        add_action( 'admin_post_yseo_internal_links_bulk', array( $this, 'bulk' ) );
    }

    public function menu(): void {
        add_submenu_page(
            'yablonsky-seo',
            esc_html__( 'Internal Linking', 'yablonsky-seo' ),
            esc_html__( 'Internal Links', 'yablonsky-seo' ),
            'manage_options',
            self::PAGE,
            array( $this, 'render' )
        );
    }

    public function assets( string $hook ): void {
        if ( 'yablonsky-seo_page_' . self::PAGE !== $hook ) { return; }
        wp_enqueue_style( 'yps-admin', YPS_URL . 'assets/admin.css', array(), YPS_VERSION );
        wp_enqueue_script( 'yps-admin-editor', YPS_URL . 'assets/admin.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
    }

    public function rebuild(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Rebuilding internal link evidence requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        check_admin_referer( 'yseo_internal_links_rebuild' );
        $this->indexer->resetAndSchedule();
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'rebuild' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function bulk(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Managing internal link workflows requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        check_admin_referer( 'yseo_internal_links_bulk' );
        $action = sanitize_key( (string) filter_input( INPUT_POST, 'bulk_action', FILTER_UNSAFE_RAW ) );
        $rawIds = filter_input( INPUT_POST, 'yseo_ids', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY );
        $ids = is_array( $rawIds ) ? array_slice( array_values( array_unique( array_filter( array_map( 'absint', $rawIds ) ) ) ), 0, 100 ) : array();
        if ( empty( $ids ) || ! in_array( $action, array( 'cornerstone', 'uncornerstone', 'reindex', 'bulk-editor' ), true ) ) { $this->backToReferer( 'bulk-none' ); }
        if ( 'bulk-editor' === $action ) {
            $url = add_query_arg( array( 'page' => 'yablonsky-seo-bulk-editor', 'ids' => implode( ',', $ids ), 'per_page' => max( 25, min( 100, count( $ids ) ) ) ), admin_url( 'admin.php' ) );
            wp_safe_redirect( $url );
            exit;
        }
        $count = 0;
        foreach ( $ids as $id ) {
            if ( ! current_user_can( 'edit_post', $id ) ) { continue; }
            if ( 'cornerstone' === $action ) { update_post_meta( $id, '_yps_is_cornerstone', '1' ); }
            if ( 'uncornerstone' === $action ) { delete_post_meta( $id, '_yps_is_cornerstone' ); }
            if ( $this->indexer->indexPost( $id, true ) || in_array( $action, array( 'cornerstone', 'uncornerstone' ), true ) ) { ++$count; }
        }
        $this->backToReferer( 'bulk-' . $action . '-' . $count );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accessing this page requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        $view = sanitize_key( (string) filter_input( INPUT_GET, 'yseo_view', FILTER_UNSAFE_RAW ) );
        if ( ! in_array( $view, array( 'orphaned', 'cornerstone', 'inventory' ), true ) ) { $view = 'orphaned'; }
        $search = sanitize_text_field( (string) filter_input( INPUT_GET, 'yseo_search', FILTER_UNSAFE_RAW ) );
        $postType = sanitize_key( (string) filter_input( INPUT_GET, 'yseo_post_type', FILTER_UNSAFE_RAW ) );
        $language = sanitize_text_field( (string) filter_input( INPUT_GET, 'yseo_language', FILTER_UNSAFE_RAW ) );
        $dateFrom = sanitize_text_field( (string) filter_input( INPUT_GET, 'date_from', FILTER_UNSAFE_RAW ) );
        $dateTo = sanitize_text_field( (string) filter_input( INPUT_GET, 'date_to', FILTER_UNSAFE_RAW ) );
        $sort = sanitize_key( (string) filter_input( INPUT_GET, 'sort', FILTER_UNSAFE_RAW ) );
        if ( ! in_array( $sort, array( 'modified_desc', 'modified_asc', 'title_asc', 'title_desc' ), true ) ) { $sort = 'cornerstone' === $view ? 'modified_asc' : 'modified_desc'; }
        $perPage = (int) filter_input( INPUT_GET, 'per_page', FILTER_VALIDATE_INT );
        if ( ! in_array( $perPage, self::PER_PAGE_OPTIONS, true ) ) { $perPage = self::PER_PAGE; }
        $languageIndex = new ResourceLanguageIndex();
        $languageCatalog = $languageIndex->catalog( true );
        if ( '' !== $language && ! isset( $languageCatalog[ $language ] ) ) { $language = ''; }
        $page = max( 1, (int) filter_input( INPUT_GET, 'yseo_paged', FILTER_VALIDATE_INT ) );
        $diag = $this->repository->diagnostics();
        if ( 'cornerstone' === $view ) {
            $data = $this->repository->staleCornerstonesPage( $page, $perPage, $search, $postType, $language, $dateFrom, $dateTo, $sort );
        } elseif ( 'inventory' === $view ) {
            $data = $this->repository->inventoryPage( $page, $perPage, $search, $postType, $language, $dateFrom, $dateTo, $sort );
        } else {
            $data = $this->repository->orphanedPage( $page, $perPage, $search, $postType, $language, $dateFrom, $dateTo, $sort );
        }
        $viewMeta = $this->viewMeta( $view );
        ?>
        <div class="wrap yps-wrap yps-product-shell yseo-internal-links">
            <header class="yps-product-header"><div class="yps-product-title"><h1><?php echo esc_html__( 'Internal Linking', 'yablonsky-seo' ); ?></h1><p><?php echo esc_html__( 'Local link evidence, inbound/outbound counters, orphaned-content discovery and contextual suggestions using on-site analysis.', 'yablonsky-seo' ); ?></p></div><div class="yps-product-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo' ) ); ?>"><?php echo esc_html__( 'Dashboard', 'yablonsky-seo' ); ?></a><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yseo_internal_links_rebuild' ), 'yseo_internal_links_rebuild' ) ); ?>"><?php echo esc_html__( 'Rebuild link index', 'yablonsky-seo' ); ?></a></div></header>
            <?php $notice = sanitize_text_field( (string) filter_input( INPUT_GET, 'yseo_notice', FILTER_UNSAFE_RAW ) ); if ( '' !== $notice ) : ?><div class="notice notice-success inline"><p><?php echo esc_html( $this->noticeText( $notice ) ); ?></p></div><?php endif; ?>
            <div class="yps-kpi-grid yseo-kpi-grid-five">
                <?php $this->kpi( __( 'Indexed resources', 'yablonsky-seo' ), $diag['indexed_sources'] . ' / ' . $diag['eligible_sources'], __( 'Local saved-content index', 'yablonsky-seo' ) ); ?>
                <?php $this->kpi( __( 'Internal links', 'yablonsky-seo' ), (string) $diag['link_occurrences'], __( 'Observed link occurrences', 'yablonsky-seo' ) ); ?>
                <?php $this->kpi( __( 'Linked targets', 'yablonsky-seo' ), (string) $diag['linked_targets'], __( 'Resources receiving links', 'yablonsky-seo' ) ); ?>
                <?php $this->kpi( __( 'Orphaned content', 'yablonsky-seo' ), (string) $diag['orphaned'], __( 'Published, indexable, zero inbound links', 'yablonsky-seo' ) ); ?>
                <?php $this->kpi( __( 'Stale cornerstone', 'yablonsky-seo' ), (string) $diag['stale_cornerstones'], __( 'Pillar content unchanged for 6+ months', 'yablonsky-seo' ) ); ?>
            </div>
            <?php if ( $diag['unindexed_sources'] > 0 ) : ?>
                <?php
                /* translators: %d: remaining unindexed resource count. */
                $remainingMessage = sprintf( __( '%d eligible resources remain. Saved content is indexed in bounded background batches and on every future update.', 'yablonsky-seo' ), $diag['unindexed_sources'] );
                ?>
                <div class="yps-callout"><strong><?php echo esc_html__( 'Background indexing is still running', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html( $remainingMessage ); ?></span></div>
            <?php endif; ?>

            <nav class="yseo-workflow-tabs" aria-label="<?php echo esc_attr__( 'Internal linking views', 'yablonsky-seo' ); ?>">
                <?php $this->viewLink( 'orphaned', __( 'Orphaned', 'yablonsky-seo' ), (int) $diag['orphaned'], $view ); ?>
                <?php $this->viewLink( 'cornerstone', __( 'Cornerstone', 'yablonsky-seo' ), (int) $diag['stale_cornerstones'], $view ); ?>
                <?php $this->viewLink( 'inventory', __( 'Link inventory', 'yablonsky-seo' ), (int) $diag['indexed_sources'], $view ); ?>
            </nav>

            <article class="yps-card yps-card-wide yseo-workflow-card">
                <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html( (string) $viewMeta['kicker'] ); ?></span><h2><?php echo esc_html( (string) $viewMeta['title'] ); ?></h2></div><span class="yps-mini-status <?php echo (int) $data['total'] > 0 && 'inventory' !== $view ? 'attention' : 'good'; ?>"><?php echo esc_html( (string) $data['total'] ); ?></span></div>
                <p class="yps-muted"><?php echo esc_html( (string) $viewMeta['description'] ); ?></p>
                <?php $this->filterToolbar( $view, $search, $postType, $language, $dateFrom, $dateTo, $sort, $perPage, $languageCatalog ); ?>
                <?php $this->renderTable( $view, $data['items'], $languageIndex, $languageCatalog ); ?>
                <?php $this->pagination( $view, $search, $postType, $language, $dateFrom, $dateTo, $sort, $perPage, $data ); ?>
            </article>
        </div>
        <?php
    }

    /** @return array<string,string> */
    private function viewMeta( string $view ): array {
        if ( 'cornerstone' === $view ) {
            return array(
                'kicker' => __( 'Cornerstone workflow', 'yablonsky-seo' ),
                'title' => __( 'Pillar content due for review', 'yablonsky-seo' ),
                'description' => __( 'Owner-selected cornerstone resources unchanged for at least six months. Review is explicit; content changes remain editor-controlled.', 'yablonsky-seo' ),
            );
        }
        if ( 'inventory' === $view ) {
            return array(
                'kicker' => __( 'Link evidence', 'yablonsky-seo' ),
                'title' => __( 'Indexed content and internal link counts', 'yablonsky-seo' ),
                'description' => __( 'Browse the local saved-content graph with bounded pagination. Inbound and outbound counts help identify isolated or over-linked resources.', 'yablonsky-seo' ),
            );
        }
        return array(
            'kicker' => __( 'Orphaned workflow', 'yablonsky-seo' ),
            'title' => __( 'Published resources ready for inbound internal links', 'yablonsky-seo' ),
            'description' => __( 'An orphan is a published, indexable resource with zero observed inbound links in saved post content. Menus, widgets and runtime-generated links remain outside this inference.', 'yablonsky-seo' ),
        );
    }

    private function filterToolbar( string $view, string $search, string $postType, string $language, string $dateFrom, string $dateTo, string $sort, int $perPage, array $languageCatalog ): void {
        $types = get_post_types( array( 'public' => true ), 'objects' );
        ?>
        <form class="yseo-list-toolbar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
            <input type="hidden" name="yseo_view" value="<?php echo esc_attr( $view ); ?>" />
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'Search', 'yablonsky-seo' ); ?></span><input class="regular-text" type="search" name="yseo_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search by title', 'yablonsky-seo' ); ?>" /></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'Content type', 'yablonsky-seo' ); ?></span><select name="yseo_post_type"><option value=""><?php echo esc_html__( 'All content types', 'yablonsky-seo' ); ?></option><?php foreach ( $types as $type => $object ) : if ( 'attachment' === $type || ! $object instanceof \WP_Post_Type ) { continue; } ?><option value="<?php echo esc_attr( (string) $type ); ?>" <?php selected( $postType, (string) $type ); ?>><?php echo esc_html( (string) $object->labels->name ); ?></option><?php endforeach; ?></select></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'Language', 'yablonsky-seo' ); ?></span><select name="yseo_language" data-yps-search-select data-yps-search-placeholder="<?php echo esc_attr__( 'Search languages…', 'yablonsky-seo' ); ?>"><option value=""><?php echo esc_html__( 'Any language', 'yablonsky-seo' ); ?></option><?php foreach ( $languageCatalog as $key => $label ) : ?><option value="<?php echo esc_attr( (string) $key ); ?>" <?php selected( $language, (string) $key ); ?>><?php echo esc_html( (string) $label ); ?></option><?php endforeach; ?></select></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'From', 'yablonsky-seo' ); ?></span><input type="date" name="date_from" value="<?php echo esc_attr( $dateFrom ); ?>"></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'To', 'yablonsky-seo' ); ?></span><input type="date" name="date_to" value="<?php echo esc_attr( $dateTo ); ?>"></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'Sort', 'yablonsky-seo' ); ?></span><select name="sort"><option value="modified_desc" <?php selected($sort,'modified_desc'); ?>><?php echo esc_html__( 'Most recent', 'yablonsky-seo' ); ?></option><option value="modified_asc" <?php selected($sort,'modified_asc'); ?>><?php echo esc_html__( 'Oldest modified', 'yablonsky-seo' ); ?></option><option value="title_asc" <?php selected($sort,'title_asc'); ?>><?php echo esc_html__( 'Title A–Z', 'yablonsky-seo' ); ?></option><option value="title_desc" <?php selected($sort,'title_desc'); ?>><?php echo esc_html__( 'Title Z–A', 'yablonsky-seo' ); ?></option></select></label>
            <input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $perPage ); ?>" />
            <div class="yseo-filter-actions"><button class="button button-primary" type="submit"><?php echo esc_html__( 'Filter', 'yablonsky-seo' ); ?></button><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'yseo_view' => $view ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Reset', 'yablonsky-seo' ); ?></a></div>
        </form>
        <?php
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function renderTable( string $view, array $rows, ResourceLanguageIndex $languageIndex, ?array $languageCatalog = null ): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-yseo-bulk-form data-yseo-bulk-confirm="<?php echo esc_attr__( 'Apply this bulk action to the selected resources?', 'yablonsky-seo' ); ?>">
            <input type="hidden" name="action" value="yseo_internal_links_bulk" /><?php wp_nonce_field( 'yseo_internal_links_bulk' ); ?>
            <div class="yseo-table-actionbar">
                <label><span class="screen-reader-text"><?php echo esc_html__( 'Bulk action', 'yablonsky-seo' ); ?></span><select name="bulk_action" data-yseo-bulk-action><option value=""><?php echo esc_html__( 'Bulk actions', 'yablonsky-seo' ); ?></option><option value="cornerstone"><?php echo esc_html__( 'Mark selected as cornerstone', 'yablonsky-seo' ); ?></option><option value="uncornerstone"><?php echo esc_html__( 'Remove cornerstone from selected', 'yablonsky-seo' ); ?></option><option value="reindex"><?php echo esc_html__( 'Reindex selected link evidence', 'yablonsky-seo' ); ?></option><option value="bulk-editor"><?php echo esc_html__( 'Open selected in Bulk SEO Editor', 'yablonsky-seo' ); ?></option></select></label>
                <button class="button button-primary" type="submit" data-yseo-bulk-apply><?php echo esc_html__( 'Apply', 'yablonsky-seo' ); ?></button>
                <span class="yseo-selection-count" data-yseo-selection-count data-yseo-empty-label="<?php echo esc_attr__( 'Select an option', 'yablonsky-seo' ); ?>" data-yseo-selected-label="<?php echo esc_attr__( 'selected', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'Select an option', 'yablonsky-seo' ); ?></span>
                <span class="description"><?php echo esc_html__( 'Bulk actions operate on at most 100 selected resources. Content rewriting remains editor-controlled.', 'yablonsky-seo' ); ?></span>
            </div>
            <div class="yseo-list-table-wrap"><table class="widefat striped"><thead><tr>
                <th class="check-column"><input type="checkbox" data-yseo-check-all aria-label="<?php echo esc_attr__( 'Select visible rows', 'yablonsky-seo' ); ?>"></th>
                <th><?php echo esc_html__( 'Content', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Language', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Type', 'yablonsky-seo' ); ?></th>
                <?php if ( 'inventory' === $view ) : ?><th><?php echo esc_html__( 'Inbound links', 'yablonsky-seo' ); ?></th><?php endif; ?>
                <th><?php echo esc_html__( 'Outbound links', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Modified', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Action', 'yablonsky-seo' ); ?></th>
            </tr></thead><tbody>
            <?php if ( empty( $rows ) ) : ?><tr><td colspan="<?php echo esc_attr( 'inventory' === $view ? '8' : '7' ); ?>"><?php echo esc_html__( 'Adjust the search or filters to find matching resources.', 'yablonsky-seo' ); ?></td></tr><?php endif; ?>
            <?php foreach ( $rows as $row ) : $id = (int) ( $row['ID'] ?? 0 ); ?>
                <tr>
                    <th class="check-column"><input type="checkbox" name="yseo_ids[]" value="<?php echo esc_attr( (string) $id ); ?>" data-yseo-row-check aria-label="<?php echo esc_attr__( 'Select resource', 'yablonsky-seo' ); ?>"></th>
                    <td><strong><?php echo esc_html( '' !== (string) ( $row['post_title'] ?? '' ) ? (string) $row['post_title'] : __( '(untitled)', 'yablonsky-seo' ) ); ?></strong><?php if ( ! empty( $row['is_cornerstone'] ) ) : ?> <span class="yps-mini-status good"><?php echo esc_html__( 'Cornerstone', 'yablonsky-seo' ); ?></span><?php endif; ?></td>
                    <td><span class="yseo-language-badge"><?php echo esc_html( $languageIndex->label( (string) ( $row['language_key'] ?? 'auto' ), $languageCatalog ) ); ?></span><code class="yseo-language-code"><?php echo esc_html( (string) ( $row['language_key'] ?? 'auto' ) ); ?></code></td>
                    <td><?php echo esc_html( (string) ( $row['post_type'] ?? '' ) ); ?></td>
                    <?php if ( 'inventory' === $view ) : ?><td><?php echo esc_html( (string) (int) ( $row['inbound_links'] ?? 0 ) ); ?> <span class="yps-muted">/ <?php echo esc_html( (string) (int) ( $row['inbound_sources'] ?? 0 ) ); ?></span></td><?php endif; ?>
                    <td><?php echo esc_html( (string) (int) ( $row['outbound_links'] ?? 0 ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $row['post_modified_gmt'] ?? '' ) ); ?> UTC</td>
                    <td><a href="<?php echo esc_url( get_edit_post_link( $id, 'raw' ) ?: '' ); ?>"><?php echo esc_html__( 'Edit & review suggestions', 'yablonsky-seo' ); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </form>
        <?php
    }

    /** @param array{total:int,page:int,pages:int,per_page:int} $data */
    private function pagination( string $view, string $search, string $postType, string $language, string $dateFrom, string $dateTo, string $sort, int $perPageFilter, array $data ): void {
        $total = (int) $data['total'];
        $page = max( 1, (int) $data['page'] );
        $pages = max( 1, (int) $data['pages'] );
        $perPage = max( 1, (int) $data['per_page'] );
        if ( $total <= 0 ) { return; }
        $from = ( ( $page - 1 ) * $perPage ) + 1;
        $to = min( $total, $page * $perPage );
        ?>
        <div class="yseo-list-pagination">
            <?php
            /* translators: 1: first visible item number, 2: last visible item number, 3: total item count. */
            $summary = sprintf( __( 'Showing %1$d–%2$d of %3$d', 'yablonsky-seo' ), $from, $to, $total );
            ?>
            <span class="yps-muted"><?php echo esc_html( $summary ); ?></span><div class="yseo-pagination-controls">
            <form class="yseo-pagination-size-form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" /><input type="hidden" name="yseo_view" value="<?php echo esc_attr( $view ); ?>" /><input type="hidden" name="yseo_search" value="<?php echo esc_attr( $search ); ?>" /><input type="hidden" name="yseo_post_type" value="<?php echo esc_attr( $postType ); ?>" /><input type="hidden" name="yseo_language" value="<?php echo esc_attr( $language ); ?>" /><input type="hidden" name="date_from" value="<?php echo esc_attr( $dateFrom ); ?>" /><input type="hidden" name="date_to" value="<?php echo esc_attr( $dateTo ); ?>" /><input type="hidden" name="sort" value="<?php echo esc_attr( $sort ); ?>" /><label><span><?php echo esc_html__( 'Rows per page', 'yablonsky-seo' ); ?></span><select name="per_page" data-yseo-page-size><?php foreach ( self::PER_PAGE_OPTIONS as $n ) : ?><option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $perPage, $n ); ?>><?php echo esc_html( (string) $n ); ?></option><?php endforeach; ?></select></label></form>
            <?php if ( $pages > 1 ) : ?>
                <div class="tablenav-pages"><?php
                    $base = add_query_arg(
                        array_filter(
                            array(
                                'page' => self::PAGE,
                                'yseo_view' => $view,
                                'yseo_search' => $search,
                                'yseo_post_type' => $postType,
                                'yseo_language' => $language,
                                'date_from' => $dateFrom,
                                'date_to' => $dateTo,
                                'sort' => $sort,
                                'per_page' => $perPageFilter,
                                'yseo_paged' => 'YSEO_PAGE_NUMBER',
                            ),
                            static function ( $value ): bool { return '' !== (string) $value; }
                        ),
                        admin_url( 'admin.php' )
                    );
                    $base = str_replace( 'YSEO_PAGE_NUMBER', '%#%', $base );
                    echo wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $pages, 'mid_size' => 2, 'prev_text' => '‹', 'next_text' => '›' ) ) );
                ?></div>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function noticeText( string $notice ): string {
        if ( 'bulk-none' === $notice ) { return __( 'Select at least one resource and a bulk action.', 'yablonsky-seo' ); }
        if ( preg_match( '/^bulk-(cornerstone|uncornerstone|reindex)-(\d+)$/', $notice, $matches ) ) {
            $count = max( 0, (int) $matches[2] );
            /* translators: %d: number of resources affected by an internal-links bulk action. */
            return sprintf( __( 'Bulk internal-link action completed for %d resources.', 'yablonsky-seo' ), $count );
        }
        return __( 'Internal link workflow updated.', 'yablonsky-seo' );
    }

    private function backToReferer( string $notice ): void {
        $referer = wp_get_referer();
        $fallback = admin_url( 'admin.php?page=' . self::PAGE );
        $url = $referer ? wp_validate_redirect( $referer, $fallback ) : $fallback;
        $url = add_query_arg( 'yseo_notice', sanitize_text_field( $notice ), $url );
        wp_safe_redirect( $url );
        exit;
    }

    private function viewLink( string $target, string $label, int $count, string $current ): void {
        $url = add_query_arg( array( 'page' => self::PAGE, 'yseo_view' => $target ), admin_url( 'admin.php' ) );
        $class = $target === $current ? ' is-active' : '';
        ?><a class="yseo-workflow-tab<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>"<?php if ( $target === $current ) : ?> aria-current="page"<?php endif; ?>><?php echo esc_html( $label ); ?><span><?php echo esc_html( (string) $count ); ?></span></a><?php
    }

    private function kpi( string $label, string $value, string $help ): void {
        ?><article class="yps-kpi"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ); ?></strong><small><?php echo esc_html( $help ); ?></small></article><?php
    }
}
