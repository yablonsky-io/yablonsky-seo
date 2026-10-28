<?php
namespace Yablonsky\PerformanceSEO\SEO\Redirects;

use Yablonsky\PerformanceSEO\Language\ResourceLanguageIndex;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RedirectAdminController {
    private const PAGE = 'yablonsky-seo-redirects';
    private const PER_PAGE = 25;
    private const PER_PAGE_OPTIONS = array( 25, 50, 100 );
    /** @var RedirectRepository */ private $repository;
    /** @var RedirectController */ private $validator;

    public function __construct( ?RedirectRepository $repository = null ) {
        $this->repository = $repository instanceof RedirectRepository ? $repository : new RedirectRepository();
        $this->validator = new RedirectController( $this->repository );
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_post_yseo_redirect_save', array( $this, 'save' ) );
        add_action( 'admin_post_yseo_redirect_delete', array( $this, 'delete' ) );
        add_action( 'admin_post_yseo_redirect_toggle', array( $this, 'toggle' ) );
        add_action( 'admin_post_yseo_redirect_bulk', array( $this, 'bulk' ) );
        add_action( 'admin_post_yseo_redirect_import', array( $this, 'importCsv' ) );
        add_action( 'admin_post_yseo_redirect_export', array( $this, 'exportCsv' ) );
    }

    public function menu(): void {
        add_submenu_page(
            'yablonsky-seo',
            esc_html__( 'Redirect Manager', 'yablonsky-seo' ),
            esc_html__( 'Redirects', 'yablonsky-seo' ),
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

    public function save(): void {
        $this->guard( 'yseo_redirect_save' );
        $match = sanitize_key( (string) filter_input( INPUT_POST, 'match_type', FILTER_UNSAFE_RAW ) );
        $match = in_array( $match, array( 'plain', 'regex' ), true ) ? $match : 'plain';
        $originRaw = trim( (string) filter_input( INPUT_POST, 'origin', FILTER_UNSAFE_RAW ) );
        $status = (int) filter_input( INPUT_POST, 'status_code', FILTER_VALIDATE_INT );
        $targetRaw = trim( (string) filter_input( INPUT_POST, 'target', FILTER_UNSAFE_RAW ) );
        $language = $this->sanitizeLanguage( (string) filter_input( INPUT_POST, 'language_key', FILTER_UNSAFE_RAW ), true );
        if ( ! in_array( $status, array( 301,302,307,410,451 ), true ) ) { $status = 301; }
        $origin = 'regex' === $match ? $originRaw : $this->validator->normalizeOrigin( $originRaw );
        $target = in_array( $status, array( 410,451 ), true ) ? '' : $this->validator->normalizeTarget( $targetRaw );
        $error = '';
        if ( '' === $origin || ( 'regex' === $match && ! $this->validator->validRegex( $origin ) ) || ( 'plain' === $match && RedirectSafetyPolicy::isProtectedPath( $origin ) ) ) {
            $error = 'invalid_origin';
        } elseif ( ! in_array( $status, array( 410,451 ), true ) && '' === $target ) {
            $error = 'invalid_target';
        }
        if ( '' === $error ) {
            $this->repository->save( array( 'origin' => $origin, 'target' => $target, 'status_code' => $status, 'match_type' => $match, 'language_key' => $language, 'enabled' => 1, 'source' => 'manual' ) );
        }
        $return = array();
        if ( '404' === sanitize_key( (string) filter_input( INPUT_POST, 'yseo_from', FILTER_UNSAFE_RAW ) ) ) {
            $return['yseo_from'] = '404';
        }
        $this->back( '' === $error ? 'saved' : $error, $return );
    }

    public function delete(): void {
        $this->guard( 'yseo_redirect_delete' );
        $id = (int) filter_input( INPUT_GET, 'id', FILTER_VALIDATE_INT );
        if ( $id > 0 ) { $this->repository->delete( $id ); }
        $this->back( 'deleted', $this->returnArgs() );
    }

    public function toggle(): void {
        $this->guard( 'yseo_redirect_toggle' );
        $id = (int) filter_input( INPUT_GET, 'id', FILTER_VALIDATE_INT );
        if ( $id > 0 ) { $this->repository->toggle( $id ); }
        $this->back( 'updated', $this->returnArgs() );
    }

    public function bulk(): void {
        $this->guard( 'yseo_redirect_bulk' );
        $action = sanitize_key( (string) filter_input( INPUT_POST, 'bulk_action', FILTER_UNSAFE_RAW ) );
        $rawIds = filter_input( INPUT_POST, 'yseo_ids', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY );
        $ids = is_array( $rawIds ) ? array_slice( array_values( array_unique( array_filter( array_map( 'absint', $rawIds ) ) ) ), 0, 100 ) : array();
        if ( empty( $ids ) || ! in_array( $action, array( 'enable', 'disable', 'delete' ), true ) ) { $this->backToReferer( 'bulk-none' ); }
        $count = 0;
        foreach ( $ids as $id ) {
            if ( 'delete' === $action ) { $this->repository->delete( $id ); ++$count; continue; }
            if ( $this->repository->setEnabled( $id, 'enable' === $action ) ) { ++$count; }
        }
        $this->backToReferer( 'bulk-' . $action . '-' . $count );
    }

    public function importCsv(): void {
        $this->guard( 'yseo_redirect_import' );
        $csv = (string) filter_input( INPUT_POST, 'redirect_csv', FILTER_UNSAFE_RAW );
        if ( strlen( $csv ) > 1048576 ) { $this->back( 'import_too_large' ); }
        $lines = preg_split( '/\r\n|\r|\n/', trim( $csv ) );
        $imported = 0;
        foreach ( array_slice( is_array( $lines ) ? $lines : array(), 0, 1001 ) as $index => $line ) {
            if ( '' === trim( $line ) ) { continue; }
            $row = str_getcsv( $line, ',', '"', '' );
            if ( 0 === $index && isset( $row[0] ) && 'origin' === strtolower( trim( (string) $row[0] ) ) ) { continue; }
            if ( count( $row ) < 4 ) { continue; }
            $originRaw = trim( (string) $row[0] );
            $targetRaw = trim( (string) $row[1] );
            $status = (int) $row[2];
            $match = 'regex' === strtolower( trim( (string) $row[3] ) ) ? 'regex' : 'plain';
            $language = $this->sanitizeLanguage( isset( $row[4] ) ? (string) $row[4] : 'any', true );
            if ( ! in_array( $status, array( 301,302,307,410,451 ), true ) ) { continue; }
            $origin = 'regex' === $match ? $originRaw : $this->validator->normalizeOrigin( $originRaw );
            $target = in_array( $status, array( 410,451 ), true ) ? '' : $this->validator->normalizeTarget( $targetRaw );
            if ( '' === $origin || ( 'regex' === $match && ! $this->validator->validRegex( $origin ) ) || ( 'plain' === $match && RedirectSafetyPolicy::isProtectedPath( $origin ) ) ) { continue; }
            if ( ! in_array( $status, array( 410,451 ), true ) && '' === $target ) { continue; }
            $this->repository->save( array( 'origin' => $origin, 'target' => $target, 'status_code' => $status, 'match_type' => $match, 'language_key' => $language, 'enabled' => 1, 'source' => 'csv' ) );
            ++$imported;
        }
        $this->back( 'imported-' . $imported );
    }

    public function exportCsv(): void {
        $this->guard( 'yseo_redirect_export' );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="yablonsky-seo-redirects-' . gmdate( 'Ymd-His' ) . '.csv"' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $this->csvLine( array( 'Origin', 'Target', 'Type', 'Format', 'Language' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV fields are quoted/escaped by csvLine().
        $afterId = 0;
        do {
            $rules = $this->repository->exportBatch( $afterId, 500 );
            foreach ( $rules as $rule ) {
                echo $this->csvLine( array( (string) $rule['origin'], (string) $rule['target'], (string) (int) $rule['status_code'], (string) $rule['match_type'], (string) ( $rule['language_key'] ?? 'any' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV fields are quoted/escaped by csvLine().
                $afterId = max( $afterId, (int) $rule['id'] );
            }
        } while ( 500 === count( $rules ) );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accessing this page requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        $search = trim( sanitize_text_field( (string) filter_input( INPUT_GET, 'yseo_search', FILTER_UNSAFE_RAW ) ) );
        $originPrefill = $this->validator->normalizeOrigin( (string) filter_input( INPUT_GET, 'origin', FILTER_UNSAFE_RAW ) );
        if ( RedirectSafetyPolicy::isProtectedPath( $originPrefill ) ) { $originPrefill = ''; }
        $from404 = '404' === sanitize_key( (string) filter_input( INPUT_GET, 'yseo_from', FILTER_UNSAFE_RAW ) );
        $match = sanitize_key( (string) filter_input( INPUT_GET, 'match', FILTER_UNSAFE_RAW ) );
        $status = (int) filter_input( INPUT_GET, 'status', FILTER_VALIDATE_INT );
        $state = sanitize_key( (string) filter_input( INPUT_GET, 'state', FILTER_UNSAFE_RAW ) );
        $language = $this->sanitizeLanguage( (string) filter_input( INPUT_GET, 'language', FILTER_UNSAFE_RAW ), false );
        $source = sanitize_key( (string) filter_input( INPUT_GET, 'source', FILTER_UNSAFE_RAW ) );
        $source = in_array( $source, array( 'manual', 'csv', 'auto-post-move', 'auto-term-move', '404-bulk' ), true ) ? $source : '';
        $dateFrom = sanitize_text_field( (string) filter_input( INPUT_GET, 'date_from', FILTER_UNSAFE_RAW ) );
        $dateTo = sanitize_text_field( (string) filter_input( INPUT_GET, 'date_to', FILTER_UNSAFE_RAW ) );
        $perPage = (int) filter_input( INPUT_GET, 'per_page', FILTER_VALIDATE_INT );
        if ( ! in_array( $perPage, self::PER_PAGE_OPTIONS, true ) ) { $perPage = self::PER_PAGE; }
        $languageCatalog = $this->languageCatalog();
        $page = max( 1, (int) filter_input( INPUT_GET, 'yseo_paged', FILTER_VALIDATE_INT ) );
        $data = $this->repository->page( $page, $perPage, $search, $match, $status, $state, $language, $source, $dateFrom, $dateTo );
        $diag = $this->repository->diagnostics();
        $notice = sanitize_text_field( (string) filter_input( INPUT_GET, 'yseo_notice', FILTER_UNSAFE_RAW ) );
        ?>
        <div class="wrap yps-wrap yps-product-shell yseo-redirect-manager">
            <header class="yps-product-header"><div class="yps-product-title"><h1><?php echo esc_html__( 'Redirect Manager', 'yablonsky-seo' ); ?></h1><p><?php echo esc_html__( 'Fast indexed redirects with plain and REGEX rules, explicit language scope, automatic move redirects, HTTP status control and CSV portability.', 'yablonsky-seo' ); ?></p></div><div class="yps-product-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo' ) ); ?>"><?php echo esc_html__( 'Dashboard', 'yablonsky-seo' ); ?></a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-404-monitor' ) ); ?>"><?php echo esc_html__( '404 Monitor', 'yablonsky-seo' ); ?></a><span class="yps-pill"><?php echo esc_html( (string) $diag['enabled'] ); ?> <?php echo esc_html__( 'active', 'yablonsky-seo' ); ?></span></div></header>
            <?php if ( '' !== $notice ) : ?><div class="notice notice-success inline"><p><?php echo esc_html( $this->noticeText( $notice ) ); ?></p></div><?php endif; ?>
            <div class="yseo-redirect-grid">
                <article class="yps-card"><span class="yps-card-kicker"><?php echo esc_html__( 'Create rule', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Redirect or removal response', 'yablonsky-seo' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="yseo_redirect_save" /><?php wp_nonce_field( 'yseo_redirect_save' ); ?><?php if ( $from404 ) : ?><input type="hidden" name="yseo_from" value="404" /><?php endif; ?>
                        <p class="yseo-field"><label><strong><?php echo esc_html__( 'Origin', 'yablonsky-seo' ); ?></strong><input class="widefat" name="origin" type="text" placeholder="/old-path/" value="<?php echo esc_attr( $originPrefill ); ?>" required></label></p>
                        <div class="yseo-redirect-form-row"><label><strong><?php echo esc_html__( 'Status', 'yablonsky-seo' ); ?></strong><select name="status_code"><?php foreach ( array(301,302,307,410,451) as $code ) : ?><option value="<?php echo esc_attr( (string) $code ); ?>"><?php echo esc_html( (string) $code ); ?></option><?php endforeach; ?></select></label><label><strong><?php echo esc_html__( 'Match', 'yablonsky-seo' ); ?></strong><select name="match_type"><option value="plain"><?php echo esc_html__( 'Plain URL', 'yablonsky-seo' ); ?></option><option value="regex"><?php echo esc_html__( 'REGEX', 'yablonsky-seo' ); ?></option></select></label></div>
                        <p class="yseo-field"><label><strong><?php echo esc_html__( 'Language', 'yablonsky-seo' ); ?></strong><select class="widefat" name="language_key" data-yps-search-select data-yps-search-placeholder="<?php echo esc_attr__( 'Search languages…', 'yablonsky-seo' ); ?>"><option value="any"><?php echo esc_html__( 'Any language / language-neutral', 'yablonsky-seo' ); ?></option><?php foreach ( $languageCatalog as $languageKey => $languageLabel ) : ?><option value="<?php echo esc_attr( (string) $languageKey ); ?>"><?php echo esc_html( (string) $languageLabel ); ?> · <?php echo esc_html( (string) $languageKey ); ?></option><?php endforeach; ?></select></label><span class="description"><?php echo esc_html__( 'Language-specific rules are used only when a multilingual request-language authority proves the current request language. Otherwise YSEO fails closed to Any language rules.', 'yablonsky-seo' ); ?></span></p>
                        <p class="yseo-field"><label><strong><?php echo esc_html__( 'Target', 'yablonsky-seo' ); ?></strong><input class="widefat" name="target" type="text" placeholder="/new-path/ or https://example.com/"></label><span class="description"><?php echo esc_html__( '410 and 451 are target-free. REGEX replacements may use capture groups such as $1.', 'yablonsky-seo' ); ?></span></p>
                        <p><button class="button button-primary" type="submit"><?php echo esc_html__( 'Save redirect', 'yablonsky-seo' ); ?></button><?php if ( $from404 ) : ?> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-404-monitor' ) ); ?>"><?php echo esc_html__( '404 Monitor', 'yablonsky-seo' ); ?></a><?php endif; ?></p>
                    </form>
                </article>
                <article class="yps-card"><span class="yps-card-kicker"><?php echo esc_html__( 'Portability', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Import / export CSV', 'yablonsky-seo' ); ?></h2><p class="yps-muted"><?php echo esc_html__( 'Format: Origin,Target,Type,Format,Language. Language is optional on import and defaults to any for backward compatibility. Compatible status codes: 301, 302, 307, 410, 451. Import is bounded to 1000 rows per operation; export includes the complete redirect inventory.', 'yablonsky-seo' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="yseo_redirect_import" /><?php wp_nonce_field( 'yseo_redirect_import' ); ?><textarea class="widefat" name="redirect_csv" rows="8" placeholder="Origin,Target,Type,Format,Language&#10;/old,/new,301,plain,en"></textarea><div class="yseo-redirect-csv-actions"><button class="button" type="submit"><?php echo esc_html__( 'Import CSV text', 'yablonsky-seo' ); ?></button><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yseo_redirect_export' ), 'yseo_redirect_export' ) ); ?>"><?php echo esc_html__( 'Export CSV', 'yablonsky-seo' ); ?></a></div></form>
                </article>
            </div>
            <article class="yps-card yseo-redirect-list"><div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Rules', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Redirect inventory', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status good"><?php echo esc_html( (string) $diag['total'] ); ?> <?php echo esc_html__( 'stored', 'yablonsky-seo' ); ?></span></div>
                <p class="yps-muted"><?php echo esc_html__( 'Search and filter the inventory with bounded browser loading.', 'yablonsky-seo' ); ?></p>
                <?php $this->filterToolbar( $search, $match, $status, $state, $language, $source, $dateFrom, $dateTo, $perPage, $languageCatalog ); ?>
                <?php $this->renderTable( $data['items'], $search, $match, $status, $state, $language, (int) $data['page'] ); ?>
                <?php $this->pagination( $search, $match, $status, $state, $language, $source, $dateFrom, $dateTo, $perPage, $data ); ?>
            </article>
        </div>
        <?php
    }

    private function filterToolbar( string $search, string $match, int $status, string $state, string $language, string $source, string $dateFrom, string $dateTo, int $perPage, array $languageCatalog ): void {
        ?>
        <form class="yseo-list-toolbar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'Search', 'yablonsky-seo' ); ?></span><input class="regular-text" type="search" name="yseo_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search origin or target', 'yablonsky-seo' ); ?>" /></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'Match', 'yablonsky-seo' ); ?></span><select name="match"><option value=""><?php echo esc_html__( 'All match types', 'yablonsky-seo' ); ?></option><option value="plain" <?php selected( $match, 'plain' ); ?>><?php echo esc_html__( 'Plain URL', 'yablonsky-seo' ); ?></option><option value="regex" <?php selected( $match, 'regex' ); ?>><?php echo esc_html__( 'REGEX', 'yablonsky-seo' ); ?></option></select></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'Status', 'yablonsky-seo' ); ?></span><select name="status"><option value="0"><?php echo esc_html__( 'All statuses', 'yablonsky-seo' ); ?></option><?php foreach ( array(301,302,307,410,451) as $code ) : ?><option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( $status, $code ); ?>><?php echo esc_html( (string) $code ); ?></option><?php endforeach; ?></select></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'State', 'yablonsky-seo' ); ?></span><select name="state"><option value=""><?php echo esc_html__( 'All states', 'yablonsky-seo' ); ?></option><option value="enabled" <?php selected( $state, 'enabled' ); ?>><?php echo esc_html__( 'Enabled', 'yablonsky-seo' ); ?></option><option value="disabled" <?php selected( $state, 'disabled' ); ?>><?php echo esc_html__( 'Standby', 'yablonsky-seo' ); ?></option></select></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'Language', 'yablonsky-seo' ); ?></span><select name="language" data-yps-search-select data-yps-search-placeholder="<?php echo esc_attr__( 'Search languages…', 'yablonsky-seo' ); ?>"><option value=""><?php echo esc_html__( 'All rule languages', 'yablonsky-seo' ); ?></option><option value="any" <?php selected( $language, 'any' ); ?>><?php echo esc_html__( 'Any language / language-neutral', 'yablonsky-seo' ); ?></option><?php foreach ( $languageCatalog as $languageKey => $languageLabel ) : ?><option value="<?php echo esc_attr( (string) $languageKey ); ?>" <?php selected( $language, (string) $languageKey ); ?>><?php echo esc_html( (string) $languageLabel ); ?></option><?php endforeach; ?></select></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'Source', 'yablonsky-seo' ); ?></span><select name="source"><option value=""><?php echo esc_html__( 'All sources', 'yablonsky-seo' ); ?></option><option value="manual" <?php selected($source,'manual'); ?>><?php echo esc_html__( 'Manual', 'yablonsky-seo' ); ?></option><option value="csv" <?php selected($source,'csv'); ?>>CSV</option><option value="auto-post-move" <?php selected($source,'auto-post-move'); ?>><?php echo esc_html__( 'Post move', 'yablonsky-seo' ); ?></option><option value="auto-term-move" <?php selected($source,'auto-term-move'); ?>><?php echo esc_html__( 'Term move', 'yablonsky-seo' ); ?></option><option value="404-bulk" <?php selected($source,'404-bulk'); ?>><?php echo esc_html__( '404 bulk action', 'yablonsky-seo' ); ?></option></select></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'From', 'yablonsky-seo' ); ?></span><input type="date" name="date_from" value="<?php echo esc_attr($dateFrom); ?>"></label>
            <label class="yseo-filter-field"><span><?php echo esc_html__( 'To', 'yablonsky-seo' ); ?></span><input type="date" name="date_to" value="<?php echo esc_attr($dateTo); ?>"></label>
            <input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $perPage ); ?>" />
            <div class="yseo-filter-actions"><button class="button button-primary" type="submit"><?php echo esc_html__( 'Filter', 'yablonsky-seo' ); ?></button><a class="button" href="<?php echo esc_url(add_query_arg('page',self::PAGE,admin_url('admin.php'))); ?>"><?php echo esc_html__( 'Reset', 'yablonsky-seo' ); ?></a></div>
        </form>
        <?php
    }

    /** @param array<int,array<string,mixed>> $rules */
    private function renderTable( array $rules, string $search, string $match, int $status, string $state, string $language, int $page ): void {
        $returnArgs = $this->returnArgs();
        // Resolve the locale catalog once for the entire table, including the
        // original auto/fallback labels. Rows must not rebuild it individually.
        $languageIndex = new ResourceLanguageIndex();
        $languageLabels = $languageIndex->catalog( true );
        unset( $search, $match, $status, $state, $language, $page );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-yseo-bulk-form data-yseo-bulk-confirm="<?php echo esc_attr__( 'Apply this bulk action to the selected redirect rules?', 'yablonsky-seo' ); ?>">
            <input type="hidden" name="action" value="yseo_redirect_bulk" /><?php wp_nonce_field( 'yseo_redirect_bulk' ); ?>
            <div class="yseo-table-actionbar">
                <label><span class="screen-reader-text"><?php echo esc_html__( 'Bulk action', 'yablonsky-seo' ); ?></span><select name="bulk_action" data-yseo-bulk-action><option value=""><?php echo esc_html__( 'Bulk actions', 'yablonsky-seo' ); ?></option><option value="enable"><?php echo esc_html__( 'Enable selected', 'yablonsky-seo' ); ?></option><option value="disable"><?php echo esc_html__( 'Disable selected', 'yablonsky-seo' ); ?></option><option value="delete"><?php echo esc_html__( 'Delete selected', 'yablonsky-seo' ); ?></option></select></label>
                <button class="button button-primary" type="submit" data-yseo-bulk-apply><?php echo esc_html__( 'Apply', 'yablonsky-seo' ); ?></button>
                <span class="yseo-selection-count" data-yseo-selection-count data-yseo-empty-label="<?php echo esc_attr__( 'Select an option', 'yablonsky-seo' ); ?>" data-yseo-selected-label="<?php echo esc_attr__( 'selected', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'Select an option', 'yablonsky-seo' ); ?></span>
                <span class="description"><?php echo esc_html__( 'Up to 100 rules per operation. Existing row-level actions remain available for one-off changes.', 'yablonsky-seo' ); ?></span>
            </div>
            <div class="yseo-list-table-wrap"><table class="widefat striped"><thead><tr><th class="check-column"><input type="checkbox" data-yseo-check-all aria-label="<?php echo esc_attr__( 'Select visible rows', 'yablonsky-seo' ); ?>"></th><th><?php echo esc_html__( 'Origin', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Target', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Type', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Language', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Hits', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Source', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Actions', 'yablonsky-seo' ); ?></th></tr></thead><tbody>
            <?php if ( empty( $rules ) ) : ?><tr><td colspan="8"><?php echo esc_html__( 'Adjust the current filters to find redirect rules.', 'yablonsky-seo' ); ?></td></tr><?php endif; ?>
            <?php foreach ( $rules as $rule ) : $id=(int)$rule['id']; $actionArgs = array_merge( array( 'id' => $id ), $returnArgs ); ?>
                <tr>
                    <th class="check-column"><input type="checkbox" name="yseo_ids[]" value="<?php echo esc_attr( (string) $id ); ?>" data-yseo-row-check aria-label="<?php echo esc_attr__( 'Select redirect rule', 'yablonsky-seo' ); ?>"></th>
                    <td><code><?php echo esc_html( (string) $rule['origin'] ); ?></code></td>
                    <td><?php if ( '' !== (string) $rule['target'] ) : ?><code><?php echo esc_html( (string) $rule['target'] ); ?></code><?php else : ?><span aria-hidden="true">—</span><span class="screen-reader-text"><?php echo esc_html__( 'This response status is target-free.', 'yablonsky-seo' ); ?></span><?php endif; ?></td>
                    <td><strong><?php echo esc_html( (string) $rule['status_code'] ); ?></strong> · <?php echo esc_html( (string) $rule['match_type'] ); ?><?php if ( empty( $rule['enabled'] ) ) : ?> · <em><?php echo esc_html__( 'disabled', 'yablonsky-seo' ); ?></em><?php endif; ?></td>
                    <td><?php $ruleLanguage = (string) ( $rule['language_key'] ?? 'any' ); ?><span class="yseo-language-badge"><?php echo esc_html( 'any' === $ruleLanguage ? __( 'Any language', 'yablonsky-seo' ) : $languageIndex->label( $ruleLanguage, $languageLabels ) ); ?></span><code class="yseo-language-code"><?php echo esc_html( $ruleLanguage ); ?></code></td>
                    <td><?php echo esc_html( (string) (int) $rule['hits'] ); ?></td>
                    <td><?php echo esc_html( (string) $rule['source'] ); ?></td>
                    <td><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'yseo_redirect_toggle' ), $actionArgs ), admin_url( 'admin-post.php' ) ), 'yseo_redirect_toggle' ) ); ?>"><?php echo esc_html( ! empty( $rule['enabled'] ) ? __( 'Disable', 'yablonsky-seo' ) : __( 'Enable', 'yablonsky-seo' ) ); ?></a> · <a class="submitdelete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'yseo_redirect_delete' ), $actionArgs ), admin_url( 'admin-post.php' ) ), 'yseo_redirect_delete' ) ); ?>"><?php echo esc_html__( 'Delete', 'yablonsky-seo' ); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </form>
        <?php
    }

    /** @param array{total:int,page:int,pages:int,per_page:int} $data */
    private function pagination( string $search, string $match, int $status, string $state, string $language, string $source, string $dateFrom, string $dateTo, int $perPage, array $data ): void {
        $total = (int) $data['total'];
        if ( $total <= 0 ) { return; }
        $page = max( 1, (int) $data['page'] ); $pages = max( 1, (int) $data['pages'] ); $perPage = max( 1, (int) $data['per_page'] );
        $from = ( ( $page - 1 ) * $perPage ) + 1; $to = min( $total, $page * $perPage );
        /* translators: 1: first visible item number, 2: last visible item number, 3: total item count. */
        $summary = sprintf( __( 'Showing %1$d–%2$d of %3$d', 'yablonsky-seo' ), $from, $to, $total );
        ?>
        <div class="yseo-list-pagination"><span class="yps-muted"><?php echo esc_html( $summary ); ?></span><div class="yseo-pagination-controls">
            <form class="yseo-pagination-size-form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" /><input type="hidden" name="yseo_search" value="<?php echo esc_attr( $search ); ?>" /><input type="hidden" name="match" value="<?php echo esc_attr( $match ); ?>" /><input type="hidden" name="status" value="<?php echo esc_attr( (string) $status ); ?>" /><input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>" /><input type="hidden" name="language" value="<?php echo esc_attr( $language ); ?>" /><input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>" /><input type="hidden" name="date_from" value="<?php echo esc_attr( $dateFrom ); ?>" /><input type="hidden" name="date_to" value="<?php echo esc_attr( $dateTo ); ?>" /><label><span><?php echo esc_html__( 'Rows per page', 'yablonsky-seo' ); ?></span><select name="per_page" data-yseo-page-size><?php foreach ( self::PER_PAGE_OPTIONS as $n ) : ?><option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $perPage, $n ); ?>><?php echo esc_html( (string) $n ); ?></option><?php endforeach; ?></select></label></form>
        <?php if ( $pages > 1 ) : ?><div class="tablenav-pages"><?php
            $base = add_query_arg( array_filter( array( 'page' => self::PAGE, 'yseo_search' => $search, 'match' => $match, 'status' => $status, 'state' => $state, 'language' => $language, 'source' => $source, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'per_page' => $perPage, 'yseo_paged' => 'YSEO_PAGE_NUMBER' ), static function ( $value ): bool { return '' !== (string) $value && '0' !== (string) $value; } ), admin_url( 'admin.php' ) );
            $base = str_replace( 'YSEO_PAGE_NUMBER', '%#%', $base );
            echo wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $pages, 'mid_size' => 2, 'prev_text' => '‹', 'next_text' => '›' ) ) );
        ?></div><?php endif; ?></div></div>
        <?php
    }

    /** @return array<string,mixed> */
    private function returnArgs(): array {
        return array_filter(
            array(
                'yseo_search' => sanitize_text_field( (string) filter_input( INPUT_GET, 'yseo_search', FILTER_UNSAFE_RAW ) ),
                'match' => sanitize_key( (string) filter_input( INPUT_GET, 'match', FILTER_UNSAFE_RAW ) ),
                'status' => (int) filter_input( INPUT_GET, 'status', FILTER_VALIDATE_INT ),
                'state' => sanitize_key( (string) filter_input( INPUT_GET, 'state', FILTER_UNSAFE_RAW ) ),
                'language' => $this->sanitizeLanguage( (string) filter_input( INPUT_GET, 'language', FILTER_UNSAFE_RAW ), false ),
                'source' => sanitize_key( (string) filter_input( INPUT_GET, 'source', FILTER_UNSAFE_RAW ) ),
                'date_from' => sanitize_text_field( (string) filter_input( INPUT_GET, 'date_from', FILTER_UNSAFE_RAW ) ),
                'date_to' => sanitize_text_field( (string) filter_input( INPUT_GET, 'date_to', FILTER_UNSAFE_RAW ) ),
                'per_page' => (int) filter_input( INPUT_GET, 'per_page', FILTER_VALIDATE_INT ),
                'yseo_paged' => max( 1, (int) filter_input( INPUT_GET, 'yseo_paged', FILTER_VALIDATE_INT ) ),
            ),
            static function ( $value ): bool { return '' !== (string) $value && '0' !== (string) $value; }
        );
    }

    /** @return array<string,string> */
    private function languageCatalog(): array {
        $catalog = ( new ResourceLanguageIndex() )->catalog( false );
        unset( $catalog['auto'] );
        return $catalog;
    }

    private function sanitizeLanguage( string $language, bool $defaultAny ): string {
        $language = trim( sanitize_text_field( $language ) );
        if ( 'any' === $language ) { return 'any'; }
        $catalog = $this->languageCatalog();
        if ( isset( $catalog[ $language ] ) ) { return $language; }
        return $defaultAny ? 'any' : '';
    }

    private function noticeText( string $notice ): string {
        if ( 'saved' === $notice ) { return __( 'Redirect saved.', 'yablonsky-seo' ); }
        if ( 'deleted' === $notice ) { return __( 'Redirect deleted.', 'yablonsky-seo' ); }
        if ( 'updated' === $notice ) { return __( 'Redirect status updated.', 'yablonsky-seo' ); }
        if ( 'bulk-none' === $notice ) { return __( 'Select at least one redirect rule and a bulk action.', 'yablonsky-seo' ); }
        if ( preg_match( '/^bulk-(enable|disable|delete)-(\d+)$/', $notice, $matches ) ) {
            $count = max( 0, (int) $matches[2] );
            /* translators: %d: number of redirect rules affected by a bulk action. */
            return sprintf( __( 'Bulk redirect action completed for %d rules.', 'yablonsky-seo' ), $count );
        }
        if ( 'invalid_origin' === $notice ) { return __( 'The redirect origin is invalid.', 'yablonsky-seo' ); }
        if ( 'invalid_target' === $notice ) { return __( 'The redirect target is invalid.', 'yablonsky-seo' ); }
        if ( 'import_too_large' === $notice ) { return __( 'CSV import is too large for one operation.', 'yablonsky-seo' ); }
        if ( 0 === strpos( $notice, 'imported-' ) ) {
            $count = max( 0, (int) substr( $notice, 9 ) );
            /* translators: %d: number of imported redirect rules. */
            return sprintf( __( 'Imported %d redirect rules.', 'yablonsky-seo' ), $count );
        }
        return $notice;
    }

    /** @param array<int,string> $fields */
    private function csvLine( array $fields ): string {
        $quoted = array();
        foreach ( $fields as $field ) {
            $value = trim( str_replace( array( "\r", "\n" ), ' ', (string) $field ) );
            // Prevent spreadsheet formula execution when exported redirect data is opened locally.
            if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t" ), true ) ) { $value = "'" . $value; }
            $quoted[] = '"' . str_replace( '"', '""', $value ) . '"';
        }
        return implode( ',', $quoted ) . "\n";
    }

    private function guard( string $action ): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Managing redirects requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        check_admin_referer( $action );
    }

    private function backToReferer( string $notice ): void {
        $referer = wp_get_referer();
        $fallback = admin_url( 'admin.php?page=' . self::PAGE );
        $url = $referer ? wp_validate_redirect( $referer, $fallback ) : $fallback;
        $url = add_query_arg( 'yseo_notice', sanitize_text_field( $notice ), $url );
        wp_safe_redirect( $url );
        exit;
    }

    /** @param array<string,mixed> $extra */
    private function back( string $notice, array $extra = array() ): void {
        $args = array_merge( array( 'page' => self::PAGE, 'yseo_notice' => sanitize_text_field( $notice ) ), $extra );
        $url = add_query_arg( $args, admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }
}
