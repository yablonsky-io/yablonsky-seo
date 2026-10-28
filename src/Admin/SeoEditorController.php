<?php
namespace Yablonsky\PerformanceSEO\Admin;

use Yablonsky\PerformanceSEO\Runtime\ProviderDetector;
use Yablonsky\PerformanceSEO\Analysis\ContentOptimizationAnalyzer;
use Yablonsky\PerformanceSEO\Analysis\AeoPolicy;
use Yablonsky\PerformanceSEO\Analysis\AeoReadinessAudit;
use Yablonsky\PerformanceSEO\AI\Metadata\MetadataSuggestionService;
use Yablonsky\PerformanceSEO\AI\Content\ContentAssistService;
use Yablonsky\PerformanceSEO\AI\AiClientResolver;
use Yablonsky\PerformanceSEO\AI\Config\AiSettings;
use Yablonsky\PerformanceSEO\AI\Contract\ServiceError;
use Yablonsky\PerformanceSEO\AI\Contract\ComputeRequest;
use Yablonsky\PerformanceSEO\AI\Media\SeoImageGenerationService;
use Yablonsky\PerformanceSEO\AI\Settings\SiteSettingsSuggestionService;
use Yablonsky\PerformanceSEO\AI\Settings\SettingsAuthoringRegistry;
use Yablonsky\PerformanceSEO\AI\Settings\SocialTemplateSuggestionService;
use Yablonsky\PerformanceSEO\Migration\Mirror\MetadataMirrorRepository;
use Yablonsky\PerformanceSEO\Migration\Mirror\MetadataMirrorService;
use Yablonsky\PerformanceSEO\Migration\Mirror\MetadataMirrorState;
use Yablonsky\PerformanceSEO\Visibility\CrawlerRegistry;
use Yablonsky\PerformanceSEO\Visibility\CrawlerPermissionPolicy;
use Yablonsky\PerformanceSEO\Visibility\CrawlerInteroperabilityAudit;
use Yablonsky\PerformanceSEO\Localization\ProductLocaleRegistry;
use Yablonsky\PerformanceSEO\Localization\ProductLocalePackAudit;
use Yablonsky\PerformanceSEO\SEO\InternalLinks\InternalLinkRepository;
use Yablonsky\PerformanceSEO\SEO\InternalLinks\InternalLinkSuggestionService;
use Yablonsky\PerformanceSEO\SEO\Template\TemplateResolver;
use Yablonsky\PerformanceSEO\SEO\Schema\SearchSurfacePolicy;
use Yablonsky\PerformanceSEO\Resource\ResourceIdentity;
use Yablonsky\PerformanceSEO\Settings\SettingsPortability;
use Yablonsky\PerformanceSEO\SEO\Social\SocialTemplateResolver;
use Yablonsky\PerformanceSEO\SEO\Developer\DeveloperApiContract;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SeoEditorController {
    private const SETTINGS_PAGE = 'yablonsky-seo-settings';
    private const SETTINGS_OPTION = 'yps_public_settings';
    private const SETTINGS_NONCE = 'yps_public_settings_save';
    private const POST_NONCE = 'yps_seo_post_meta_save';
    private const TERM_NONCE = 'yps_seo_term_meta_save';
    private const USER_NONCE = 'yps_seo_user_meta_save';
    private const EDITOR_AJAX_NONCE = 'yseo_editor_assist';

    /** @var ProviderDetector */
    private $detector;

    public function __construct( ProviderDetector $detector ) {
        $this->detector = $detector;
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'registerSettingsPage' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueSettingsAssets' ) );
        add_action( 'admin_post_yps_save_public_settings', array( $this, 'saveSettings' ) );
        add_action( 'admin_post_yseo_localization_export', array( $this, 'exportLocalizationEvidence' ) );
        add_action( 'admin_post_yseo_settings_export', array( $this, 'exportPortableSettings' ) );
        add_action( 'admin_post_yseo_settings_import', array( $this, 'importPortableSettings' ) );
        add_action( 'admin_post_yseo_settings_restore', array( $this, 'restorePortableSettings' ) );
        add_action( 'add_meta_boxes', array( $this, 'registerMetaBoxes' ) );
        add_action( 'save_post', array( $this, 'savePostMeta' ), 10, 2 );
        add_action( 'init', array( $this, 'registerTaxonomyHooks' ), 20 );
        add_action( 'show_user_profile', array( $this, 'renderUserFields' ) );
        add_action( 'edit_user_profile', array( $this, 'renderUserFields' ) );
        add_action( 'personal_options_update', array( $this, 'saveUserFields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'saveUserFields' ) );
        add_action( 'wp_ajax_yseo_mirror_post_metadata', array( $this, 'ajaxMirrorPostMetadata' ) );
        add_action( 'wp_ajax_yseo_ai_suggest_post', array( $this, 'ajaxAiSuggestPost' ) );
        add_action( 'wp_ajax_yseo_refresh_post_analysis', array( $this, 'ajaxRefreshPostAnalysis' ) );
        add_action( 'wp_ajax_yseo_ai_content_assist', array( $this, 'ajaxAiContentAssist' ) );
        add_action( 'wp_ajax_yseo_ai_discard_draft', array( $this, 'ajaxAiDiscardDraft' ) );
        add_action( 'wp_ajax_yseo_ai_test_connection', array( $this, 'ajaxAiTestConnection' ) );
        add_action( 'wp_ajax_yseo_ai_test_compute', array( $this, 'ajaxAiTestCompute' ) );
        add_action( 'wp_ajax_yseo_ai_suggest_settings', array( $this, 'ajaxAiSuggestSettings' ) );
        add_action( 'wp_ajax_yseo_ai_suggest_social_template', array( $this, 'ajaxAiSuggestSocialTemplate' ) );
        add_action( 'wp_ajax_yseo_ai_generate_seo_image', array( $this, 'ajaxAiGenerateSeoImage' ) );
        add_action( 'wp_ajax_yseo_ai_generate_site_image', array( $this, 'ajaxAiGenerateSiteImage' ) );
        add_action( 'wp_ajax_yseo_ai_generate_social_template_image', array( $this, 'ajaxAiGenerateSocialTemplateImage' ) );
    }


    public function enqueueSettingsAssets( string $hook ): void {
        $isSettings = false !== strpos( $hook, self::SETTINGS_PAGE );
        $isEditor   = in_array( $hook, array( 'post.php', 'post-new.php' ), true );
        $isTermUi   = in_array( $hook, array( 'edit-tags.php', 'term.php' ), true );
        $isUserUi   = in_array( $hook, array( 'profile.php', 'user-edit.php' ), true );
        if ( ! $isSettings && ! $isEditor && ! $isTermUi && ! $isUserUi ) {
            return;
        }
        wp_enqueue_style( 'yps-admin', YPS_URL . 'assets/admin.css', array(), YPS_VERSION );
        if ( $isSettings || $isEditor ) {
            wp_enqueue_media();
            wp_enqueue_script( 'yps-admin-editor', YPS_URL . 'assets/admin.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
            wp_localize_script( 'yps-admin-editor', 'YPS_MEDIA', array( 'title' => esc_html__( 'Select image', 'yablonsky-seo' ), 'button' => esc_html__( 'Use image', 'yablonsky-seo' ) ) );
        }
        if ( $isSettings ) {
            wp_localize_script(
                'yps-admin-editor',
                'YPS_AI_SETTINGS',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce' => wp_create_nonce( 'yseo_ai_settings' ),
                    'labels' => array(
                        'testing' => esc_html__( 'Testing Yablonsky AI connection…', 'yablonsky-seo' ),
                        'connected' => esc_html__( 'Connection verified. Reloading account capabilities…', 'yablonsky-seo' ),
                        'testingCompute' => esc_html__( 'Running a small Writer compute canary…', 'yablonsky-seo' ),
                        'computeReady' => esc_html__( 'Writer compute verified. Structured AI generation is ready.', 'yablonsky-seo' ),
                        'failed' => esc_html__( 'Connection test needs attention. Review the connection details and try again.', 'yablonsky-seo' ),
                        'invalidResponse' => esc_html__( 'Invalid server response.', 'yablonsky-seo' ),
                        'draftingSettings' => esc_html__( 'Drafting evidence-safe site settings with Yablonsky AI…', 'yablonsky-seo' ),
                        'settingsReview' => esc_html__( 'AI suggestions were inserted into this unsaved form. Review them and Save changes to keep them.', 'yablonsky-seo' ),
                        'settingsUnavailable' => esc_html__( 'Connect and verify Yablonsky AI before requesting site setup suggestions.', 'yablonsky-seo' ),
                        'generatingImage' => esc_html__( 'Generating image with Yablonsky AI…', 'yablonsky-seo' ),
                        'imageFailed' => esc_html__( 'Image generation failed.', 'yablonsky-seo' ),
                        'imageGeneratedReview' => esc_html__( 'Generated · review before Update', 'yablonsky-seo' ),
                        'confirmSocialTemplateBatch' => esc_html__( 'Draft reusable social template text where fields are ready for completion? This can use several Yablonsky AI requests. Existing text is preserved.', 'yablonsky-seo' ),
                    ),
                )
            );
        }
        if ( $isEditor ) {
            $ai = new MetadataSuggestionService();
            wp_localize_script(
                'yps-admin-editor',
                'YPS_EDITOR',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce' => wp_create_nonce( self::EDITOR_AJAX_NONCE ),
                    'aiConfigured' => $ai->isConfigured(),
                    'labels' => array(
                        'working' => esc_html__( 'Working…', 'yablonsky-seo' ),
                        'mirrorDone' => esc_html__( 'Yoast metadata copied into empty YSEO fields. Reloading…', 'yablonsky-seo' ),
                        'aiUnavailable' => esc_html__( 'Connect and verify a Yablonsky AI User API key in SEO Settings to enable remote metadata generation.', 'yablonsky-seo' ),
                        'aiReview' => esc_html__( 'AI suggestions were inserted into the form only. Review them and Update the post to save.', 'yablonsky-seo' ),
                        'aiContentReview' => esc_html__( 'AI content assistance is shown for human review. Saved WordPress content remains unchanged.', 'yablonsky-seo' ),
                        'draftLoaded' => esc_html__( 'The staged AI draft was loaded into the form only. Review it and Update the post to save.', 'yablonsky-seo' ),
                        'draftDiscarded' => esc_html__( 'The staged AI draft was discarded. Saved YSEO and Yoast metadata remain unchanged.', 'yablonsky-seo' ),
                        'failed' => esc_html__( 'The operation stopped safely.', 'yablonsky-seo' ),
                        'invalidResponse' => esc_html__( 'Invalid server response.', 'yablonsky-seo' ),
                        'generatingImage' => esc_html__( 'Generating image with Yablonsky AI…', 'yablonsky-seo' ),
                        'imageFailed' => esc_html__( 'Image generation failed.', 'yablonsky-seo' ),
                        'imageNoUrl' => esc_html__( 'Image generation returned no usable local URL.', 'yablonsky-seo' ),
                        'imageGeneratedReview' => esc_html__( 'Generated · review before Update', 'yablonsky-seo' ),
                        'imageSavedReview' => esc_html__( 'Image generated. Review and Update the post to save the preferred image.', 'yablonsky-seo' ),
                        'serpEmpty' => esc_html__( 'Ready for input', 'yablonsky-seo' ),
                        'serpTooShort' => esc_html__( 'Too short', 'yablonsky-seo' ),
                        'serpShort' => esc_html__( 'Short', 'yablonsky-seo' ),
                        'serpGood' => esc_html__( 'Good fit', 'yablonsky-seo' ),
                        'serpLong' => esc_html__( 'Long', 'yablonsky-seo' ),
                        'serpTooLong' => esc_html__( 'Too long', 'yablonsky-seo' ),
                        'analysisRefreshing' => esc_html__( 'Refreshing local analysis…', 'yablonsky-seo' ),
                        'analysisPreview' => esc_html__( 'Live analysis preview updated. Unsaved editor content and SEO fields are included; Update the post to publish them.', 'yablonsky-seo' ),
                        'analysisRefreshFailed' => esc_html__( 'Live analysis refresh stopped safely. The saved analysis remains available.', 'yablonsky-seo' ),
                        'options' => esc_html__( 'Options', 'yablonsky-seo' ),
                    ),
                )
            );
        }
    }

    public function exportLocalizationEvidence(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accessing this page requires the corresponding WordPress capability.', 'yablonsky-seo' ) );
        }
        check_admin_referer( 'yseo_localization_export' );
        $payload = ProductLocalePackAudit::report();
        $payload['generated_at_utc'] = gmdate( 'c' );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="yablonsky-seo-localization-evidence-' . gmdate( 'Ymd-His' ) . '.json"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download response encoded by wp_json_encode().
        exit;
    }

    public function exportPortableSettings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accessing this page requires the corresponding WordPress capability.', 'yablonsky-seo' ) );
        }
        check_admin_referer( 'yseo_settings_export' );
        $payload = ( new SettingsPortability() )->exportPackage();
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="yablonsky-seo-settings-' . gmdate( 'Ymd-His' ) . '.json"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download response encoded by wp_json_encode().
        exit;
    }

    public function importPortableSettings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Changing these settings requires the corresponding WordPress capability.', 'yablonsky-seo' ) );
        }
        check_admin_referer( 'yseo_settings_import' );
        $raw = filter_input( INPUT_POST, 'yseo_settings_json', FILTER_UNSAFE_RAW );
        if ( ! is_string( $raw ) ) {
            $this->redirectPortability( 'invalid' );
        }
        try {
            $result = ( new SettingsPortability() )->importJson( (string) $raw );
            $this->redirectPortability( 'imported', (int) ( $result['changed'] ?? 0 ) );
        } catch ( \RuntimeException $error ) {
            $code = sanitize_key( $error->getMessage() );
            $allowed = array( 'invalid-size', 'invalid-schema', 'missing-payload', 'integrity-mismatch', 'write-failed' );
            $this->redirectPortability( in_array( $code, $allowed, true ) ? $code : 'invalid' );
        }
    }

    public function restorePortableSettings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Changing these settings requires the corresponding WordPress capability.', 'yablonsky-seo' ) );
        }
        check_admin_referer( 'yseo_settings_restore' );
        $restored = ( new SettingsPortability() )->restoreLastBackup();
        $this->redirectPortability( $restored ? 'restored' : ( ( new SettingsPortability() )->hasBackup() ? 'restore-failed' : 'no-backup' ) );
    }

    private function redirectPortability( string $status, int $changed = 0 ): void {
        $url = add_query_arg(
            array(
                'page'        => self::SETTINGS_PAGE,
                'portability' => sanitize_key( $status ),
                'changed'     => max( 0, $changed ),
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url . '#portability' );
        exit;
    }

    public function registerSettingsPage(): void {
        add_submenu_page(
            'yablonsky-seo',
            esc_html__( 'Yablonsky SEO Settings', 'yablonsky-seo' ),
            esc_html__( 'Settings', 'yablonsky-seo' ),
            'manage_options',
            self::SETTINGS_PAGE,
            array( $this, 'renderSettingsPage' )
        );
    }

    public function renderSettingsPage(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accessing this page requires the corresponding WordPress capability.', 'yablonsky-seo' ) );
        }
        $settings = get_option( self::SETTINGS_OPTION, array() );
        $settings = is_array( $settings ) ? $settings : array();
        $aeoPolicy = AeoPolicy::fromSettings( $settings );
        $aeoAudit = ( new AeoReadinessAudit() )->report();
        $providers = $this->detector->detectActiveProviders();
        $authority = (string) get_option( 'yps_output_authority', 'active' );
        $updatedFlag = filter_input( INPUT_GET, 'updated', FILTER_UNSAFE_RAW );
        $localeStatus = ProductLocaleRegistry::currentStatus();
        $localeCoverage = ProductLocaleRegistry::coverage();
        $localeAudit = ProductLocalePackAudit::report();
        $localeProgressRows = isset( $localeAudit['locales'] ) && is_array( $localeAudit['locales'] ) ? $localeAudit['locales'] : array();
        $localeRegisteredTotal = max( 0, (int) ( $localeAudit['registered_total'] ?? 0 ) );
        $localeRegisteredReady = max( 0, (int) ( $localeAudit['registered_ready'] ?? 0 ) );
        $localeProgressPercent = $localeRegisteredTotal > 0 ? round( min( 100, ( $localeRegisteredReady / $localeRegisteredTotal ) * 100 ), 1 ) : 0.0;
        $portability = new SettingsPortability();
        $portabilityStatus = filter_input( INPUT_GET, 'portability', FILTER_UNSAFE_RAW );
        $portabilityStatus = is_string( $portabilityStatus ) ? sanitize_key( $portabilityStatus ) : '';
        $portabilityChangedRaw = filter_input( INPUT_GET, 'changed', FILTER_VALIDATE_INT );
        $portabilityChanged = is_int( $portabilityChangedRaw ) ? max( 0, $portabilityChangedRaw ) : 0;
        $portabilityBackup = $portability->backupMeta();
        $aiSettings = new AiSettings();
        $aiClient = ( new AiClientResolver() )->resolve();
        $aiModels = $aiClient->models();
        $aiImageModels = $aiClient->imageModels();
        $aiUsage = $aiClient->usage()->data();
        $aiWorkspaces = $aiSettings->workspaces();
        $crawlerRegistryRows = array();
        $crawlerRegistryVersion = '';
        $crawlerInterop = CrawlerInteroperabilityAudit::build( $settings );
        $crawlerPermission = CrawlerPermissionPolicy::fromSettings( $settings );
        try {
            $crawlerRegistry = CrawlerRegistry::fromJsonFile( YPS_DIR . 'data/ai-crawlers.json' );
            $crawlerRegistryRows = $crawlerRegistry->all();
            $crawlerRegistryVersion = $crawlerRegistry->version();
        } catch ( \RuntimeException $error ) {
            unset( $error );
        }
        $aiCreditLabel = '';
        if ( null !== ( $aiUsage['credit_count'] ?? null ) ) {
            /* translators: %s: remaining Yablonsky AI credits for the selected workspace. */
            $aiCreditLabel = sprintf( __( 'Credits: %s', 'yablonsky-seo' ), (string) $aiUsage['credit_count'] );
        }
        $aiUsageLabel = '';
        if ( null !== ( $aiUsage['usage_count'] ?? null ) ) {
            /* translators: %s: Yablonsky AI usage count for the selected workspace. */
            $aiUsageLabel = sprintf( __( 'Usage: %s', 'yablonsky-seo' ), (string) $aiUsage['usage_count'] );
        }
        /* translators: %s: Yablonsky AI billing/usage mode for the selected workspace. */
        $aiUsageModeLabel = '' !== (string) ( $aiUsage['usage_mode'] ?? '' ) ? sprintf( __( 'Mode: %s', 'yablonsky-seo' ), sanitize_text_field( (string) $aiUsage['usage_mode'] ) ) : '';
        /* translators: %s: next Yablonsky AI session usage reset time when exposed by the account API. */
        $aiResetLabel = '' !== (string) ( $aiUsage['session_reset_at'] ?? '' ) ? sprintf( __( 'Reset: %s', 'yablonsky-seo' ), sanitize_text_field( (string) $aiUsage['session_reset_at'] ) ) : '';
        /* translators: 1: number of selectable text models, 2: number of selectable image models. */
        /* translators: 1: selectable text model count, 2: selectable image model count. */
        $aiModelCountLabel = sprintf( __( '%1$d text · %2$d image models', 'yablonsky-seo' ), max( 0, count( $aiModels ) - 1 ), count( $aiImageModels ) );
        ?>
        <div class="wrap yps-wrap yps-settings-shell">
            <header class="yps-product-header">
                <div class="yps-product-title">
                    <h1><?php echo esc_html__( 'Yablonsky SEO Settings', 'yablonsky-seo' ); ?></h1>
                    <p><?php echo esc_html__( 'Global foundations for SEO, AEO and GEO, followed by the optional Yablonsky AI compute layer. Content-specific decisions remain inside each editor.', 'yablonsky-seo' ); ?></p>
                </div>
                <div class="yps-product-actions">
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo' ) ); ?>"><?php echo esc_html__( 'Back to Dashboard', 'yablonsky-seo' ); ?></a>
                    <span class="yps-pill">v<?php echo esc_html( YPS_VERSION ); ?></span>
                </div>
            </header>
            <?php if ( is_string( $updatedFlag ) && '1' === sanitize_text_field( $updatedFlag ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'SEO settings saved.', 'yablonsky-seo' ); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="yps_save_public_settings" />
                <?php wp_nonce_field( self::SETTINGS_NONCE ); ?>
                <input type="hidden" id="yps-settings-active-tab" name="yps_settings_tab" value="general" />
                <nav class="yps-settings-nav" aria-label="<?php echo esc_attr__( 'Yablonsky SEO settings sections', 'yablonsky-seo' ); ?>">
                    <button type="button" class="yps-settings-nav-item is-active" data-yps-settings-tab="general" aria-selected="true" aria-controls="yps-settings-panel-general"><?php echo esc_html__( 'General', 'yablonsky-seo' ); ?></button>
                    <button type="button" class="yps-settings-nav-item is-seo" data-yps-settings-tab="search" aria-selected="false" aria-controls="yps-settings-panel-search"><?php echo esc_html__( 'SEO', 'yablonsky-seo' ); ?></button>
                    <button type="button" class="yps-settings-nav-item is-social" data-yps-settings-tab="social" aria-selected="false" aria-controls="yps-settings-panel-social"><?php echo esc_html__( 'Social', 'yablonsky-seo' ); ?></button>
                    <button type="button" class="yps-settings-nav-item is-aeo" data-yps-settings-tab="answer" aria-selected="false" aria-controls="yps-settings-panel-answer"><?php echo esc_html__( 'AEO', 'yablonsky-seo' ); ?></button>
                    <button type="button" class="yps-settings-nav-item is-geo" data-yps-settings-tab="crawlers" aria-selected="false" aria-controls="yps-settings-panel-crawlers"><?php echo esc_html__( 'GEO', 'yablonsky-seo' ); ?></button>
                    <button type="button" class="yps-settings-nav-item is-ai" data-yps-settings-tab="ai" aria-selected="false" aria-controls="yps-settings-panel-ai"><?php echo esc_html__( 'Yablonsky AI', 'yablonsky-seo' ); ?></button>
                    <button type="button" class="yps-settings-nav-item" data-yps-settings-tab="portability" aria-selected="false" aria-controls="yps-settings-panel-portability"><?php echo esc_html__( 'Import / Export', 'yablonsky-seo' ); ?></button>
                    <button type="button" class="yps-settings-nav-item" data-yps-settings-tab="developer" aria-selected="false" aria-controls="yps-settings-panel-developer"><?php echo esc_html__( 'Developer', 'yablonsky-seo' ); ?></button>
                </nav>

                <div class="yps-settings-panels">
                    <section class="yps-settings-panel" id="yps-settings-panel-general" data-yps-settings-panel="general">
                        <div class="yps-settings-panel-head">
                            <div><span class="yps-card-kicker"><?php echo esc_html__( 'General', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Site identity & authority', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Define who the site represents and who currently owns public SEO output.', 'yablonsky-seo' ); ?></p></div>
                        </div>

                        <section class="yps-setting-card yps-setting-card-wide yps-authority-card">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Authority', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Public SEO ownership', 'yablonsky-seo' ); ?></h2></div>
                                <?php if ( ! empty( $providers ) ) : ?><span class="yps-mini-status neutral">SHADOW</span><?php else : ?><span class="yps-mini-status <?php echo 'active' === $authority ? 'good' : 'neutral'; ?>"><?php echo esc_html( 'active' === $authority ? 'ACTIVE' : 'SHADOW' ); ?></span><?php endif; ?>
                            </div>
                            <?php if ( ! empty( $providers ) ) : ?>
                                <div class="yps-authority-box"><span class="dashicons dashicons-shield"></span><div><strong><?php echo esc_html__( 'Safe coexistence is enforced', 'yablonsky-seo' ); ?></strong><p><?php echo esc_html__( 'Another supported SEO provider is active, so Yablonsky SEO stays in Shadow and keeps frontend output single-owner.', 'yablonsky-seo' ); ?></p></div></div>
                                <input type="hidden" name="output_authority" value="shadow" />
                            <?php else : ?>
                                <div class="yps-authority-box"><input type="checkbox" id="yps-output-authority" name="output_authority" value="active" <?php checked( 'active', $authority ); ?> /><div><label for="yps-output-authority"><strong><?php echo esc_html__( 'Let Yablonsky SEO own public SEO output', 'yablonsky-seo' ); ?></strong></label><p><?php echo esc_html__( 'Turn this off only when you intentionally need manual Shadow mode.', 'yablonsky-seo' ); ?></p></div></div>
                            <?php endif; ?>
                        </section>

                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Identity', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Who is this site?', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Use a clear site identity first. Entity details below feed structured data and GEO readiness.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yseo-ai-settings-toolbar">
                                <div><strong><?php echo esc_html__( 'AI Setup Copilot', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Draft identity fields that are ready for completion from saved site evidence. Suggestions stay in this form until you review and save them.', 'yablonsky-seo' ); ?></span></div>
                                <div class="yps-inline-actions"><button type="button" class="button yseo-ai-settings-scope" data-yseo-ai-settings-scope="identity"><?php echo esc_html__( 'AI draft open Identity fields', 'yablonsky-seo' ); ?></button><button type="button" class="button button-primary yseo-ai-settings-scope" data-yseo-ai-settings-scope="all"><?php echo esc_html__( 'AI draft all safe open fields', 'yablonsky-seo' ); ?></button></div>
                            </div>
                            <div class="yps-status yseo-ai-settings-status" data-yseo-ai-settings-status aria-live="polite"></div>
                            <div class="yps-field-grid yps-field-grid-two">
                                <?php $this->settingField( 'site_display_name', __( 'Site display name', 'yablonsky-seo' ), $settings, 'text', true ); ?>
                                <div class="yps-setting-field"><label for="yps-owner-type"><?php echo esc_html__( 'Owner type', 'yablonsky-seo' ); ?></label><select id="yps-owner-type" name="owner_type"><option value="organization" <?php selected( (string) ( $settings['owner_type'] ?? '' ), 'organization' ); ?>><?php echo esc_html__( 'Organization', 'yablonsky-seo' ); ?></option><option value="person" <?php selected( (string) ( $settings['owner_type'] ?? '' ), 'person' ); ?>><?php echo esc_html__( 'Person', 'yablonsky-seo' ); ?></option></select></div>
                                <div class="yps-setting-field yps-field-wide"><?php $this->settingFieldContents( 'site_description', __( 'Site description', 'yablonsky-seo' ), $settings, 'textarea', true ); ?></div>
                                <?php $this->settingField( 'owner_name', __( 'Owner name', 'yablonsky-seo' ), $settings, 'text', true ); ?>
                                <div class="yps-setting-field yps-owner-person-only"><?php $this->settingFieldContents( 'owner_job_title', __( 'Job title', 'yablonsky-seo' ), $settings, 'text', true ); ?></div>
                                <div class="yps-setting-field yps-field-wide"><?php $this->settingFieldContents( 'owner_description', __( 'Owner / entity description', 'yablonsky-seo' ), $settings, 'textarea', true ); ?></div>
                                <div class="yps-setting-field yps-owner-organization-only"><?php $this->settingMediaFieldContents( 'owner_logo_url', __( 'Organization logo URL', 'yablonsky-seo' ), $settings ); ?></div>
                                <div class="yps-setting-field yps-owner-person-only"><?php $this->settingMediaFieldContents( 'owner_image_url', __( 'Person image URL', 'yablonsky-seo' ), $settings ); ?></div>
                                <div class="yps-setting-field yps-field-wide"><?php $this->settingFieldContents( 'owner_same_as', __( 'External sameAs URLs · one per line', 'yablonsky-seo' ), $settings, 'textarea' ); ?><small><?php echo esc_html__( 'Use independent profiles or sources that corroborate the entity. External corroboration requires independent sources.', 'yablonsky-seo' ); ?></small></div>
                                <div class="yps-setting-field yps-field-wide"><?php $this->settingFieldContents( 'owner_knows_about', __( 'knowsAbout topics · comma or line separated', 'yablonsky-seo' ), $settings, 'textarea', true ); ?></div>
                            </div>
                        </section>

                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Connections', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Site verification', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Store only the verification content value. You may paste either the content token or a complete verification meta tag.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yps-field-grid yps-field-grid-two">
                                <?php $this->settingField( 'verification_google', __( 'Google verification', 'yablonsky-seo' ), $settings ); ?>
                                <?php $this->settingField( 'verification_bing', __( 'Bing / Microsoft verification', 'yablonsky-seo' ), $settings ); ?>
                                <?php $this->settingField( 'verification_pinterest', __( 'Pinterest verification', 'yablonsky-seo' ), $settings ); ?>
                                <?php $this->settingField( 'verification_yandex', __( 'Yandex verification', 'yablonsky-seo' ), $settings ); ?>
                                <?php $this->settingField( 'verification_baidu', __( 'Baidu verification', 'yablonsky-seo' ), $settings ); ?>
                            </div>
                            <p class="yps-form-note"><?php echo esc_html__( 'YSEO emits verification meta tags only while it owns public SEO output. Verification-tag rendering remains local.', 'yablonsky-seo' ); ?></p>
                        </section>

                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Commerce identity', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Merchant policy authority', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Keep stable store-level policies attached to the Organization entity instead of repeating them across every product.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yps-field-grid yps-field-grid-two">
                                <div class="yps-setting-field yps-field-wide"><?php $this->settingFieldContents( 'merchant_return_policy_url', __( 'Standard return policy URL', 'yablonsky-seo' ), $settings, 'url' ); ?><small><?php echo esc_html__( 'Optional. When the site represents an Organization, YSEO can reference this page from Organization-level MerchantReturnPolicy structured data. Product-level exceptions and Merchant Center remain separate authorities.', 'yablonsky-seo' ); ?></small></div>
                            </div>
                        </section>

                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-card-head">
                                <div><span class="yps-card-kicker"><?php echo esc_html__( 'Product localization', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Product interface language', 'yablonsky-seo' ); ?></h2></div>
                                <div class="yps-product-actions">
                                    <span class="yps-mini-status <?php echo ! empty( $localeStatus['complete'] ) ? 'good' : 'attention'; ?>"><?php echo esc_html( ! empty( $localeStatus['complete'] ) ? __( 'Complete', 'yablonsky-seo' ) : __( 'English fallback', 'yablonsky-seo' ) ); ?></span>
                                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yseo_localization_export' ), 'yseo_localization_export' ) ); ?>"><?php echo esc_html__( 'Export evidence', 'yablonsky-seo' ); ?></a>
                                </div>
                            </div>
                            <p class="yps-muted"><?php echo esc_html__( 'YSEO follows your WordPress user language. Language selection remains aligned with WordPress.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-readiness-grid">
                                <div class="yps-readiness-item yps-tone-<?php echo ! empty( $localeStatus['complete'] ) ? 'good' : 'attention'; ?>"><div><strong><?php echo esc_html__( 'Current admin locale', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html( (string) $localeStatus['language_name'] ); ?></span></div><b><?php echo esc_html( (string) $localeStatus['wordpress_locale'] ); ?></b></div>
                                <div class="yps-readiness-item yps-tone-<?php echo ! empty( $localeStatus['complete'] ) ? 'good' : 'attention'; ?>"><div><strong><?php echo esc_html__( 'Translation pack', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html( ! empty( $localeStatus['source'] ) ? __( 'English source strings', 'yablonsky-seo' ) : ( ! empty( $localeStatus['complete'] ) ? __( 'Bundled and complete', 'yablonsky-seo' ) : __( 'English fallback active until this language pack is bundled.', 'yablonsky-seo' ) ) ); ?></span></div><b><?php echo esc_html( ! empty( $localeStatus['complete'] ) ? __( 'Ready', 'yablonsky-seo' ) : __( 'Target', 'yablonsky-seo' ) ); ?></b></div>
                                <div class="yps-readiness-item yps-tone-<?php echo ! empty( $localeAudit['catalog_verified'] ) ? 'good' : 'attention'; ?>"><div><strong><?php echo esc_html__( 'Catalog integrity', 'yablonsky-seo' ); ?></strong><span><code><?php echo esc_html( substr( (string) ( $localeAudit['catalog_actual_sha256'] ?? '' ), 0, 12 ) ); ?></code></span></div><b><?php echo esc_html( ! empty( $localeAudit['catalog_verified'] ) ? __( 'Verified', 'yablonsky-seo' ) : __( 'Needs attention', 'yablonsky-seo' ) ); ?></b></div>
                                <div class="yps-readiness-item yps-tone-<?php echo (int) ( $localeAudit['registered_ready'] ?? 0 ) === (int) ( $localeAudit['registered_total'] ?? 0 ) ? 'good' : 'attention'; ?>"><div><strong><?php echo esc_html__( 'Product languages', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html( sprintf( '%d / %d', (int) ( $localeAudit['registered_ready'] ?? 0 ), (int) ( $localeAudit['registered_total'] ?? 0 ) ) ); ?></span><div class="yseo-localization-overall-progress" role="progressbar" aria-label="<?php echo esc_attr__( 'Product languages', 'yablonsky-seo' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $localeProgressPercent ); ?>"><span style="width:<?php echo esc_attr( (string) $localeProgressPercent ); ?>%"></span></div></div><b><?php echo esc_html( number_format_i18n( $localeProgressPercent, 1 ) . '%' ); ?></b></div>
                            </div>
                            <?php
                            /* translators: 1: complete core product language count, 2: total core product language count. */
                            $coverageMessage = sprintf( __( '%1$d of %2$d core product languages currently have complete bundled UI coverage.', 'yablonsky-seo' ), (int) $localeCoverage['core_complete'], (int) $localeCoverage['core_total'] );
                            ?>
                            <p class="yps-form-note"><?php echo esc_html( $coverageMessage ); ?> <?php echo esc_html__( 'Translation coverage is release evidence. Yablonsky Multilingual remains the language and URL authority for public content.', 'yablonsky-seo' ); ?></p>
                            <details class="yseo-localization-readiness">
                                <summary><?php echo esc_html__( 'View language readiness', 'yablonsky-seo' ); ?></summary>
                                <div class="yseo-localization-grid">
                                    <?php foreach ( (array) ( $localeAudit['locales'] ?? array() ) as $row ) : ?>
                                        <?php if ( ! is_array( $row ) ) { continue; } ?>
                                        <?php $rowProgressPercent = ! empty( $row['ready'] ) ? 100.0 : (float) ( $row['progress_percent'] ?? 0.0 ); ?>
                                        <div class="yseo-localization-row">
                                            <div><strong><?php echo esc_html( (string) ( $row['name'] ?? $row['code'] ?? '' ) ); ?></strong><span><code><?php echo esc_html( (string) ( $row['code'] ?? '' ) ); ?></code><?php if ( empty( $row['ready'] ) && ! empty( $row['source_messages'] ) && ! empty( $row['translated_messages'] ) ) : ?> · <?php echo esc_html( (string) (int) $row['translated_messages'] . '/' . (string) (int) $row['source_messages'] ); ?><?php endif; ?> · <?php echo esc_html( ! empty( $row['ready'] ) ? __( 'Ready', 'yablonsky-seo' ) : ( 'invalid' === (string) ( $row['state'] ?? '' ) ? __( 'Needs attention', 'yablonsky-seo' ) : __( 'Target', 'yablonsky-seo' ) ) ); ?></span><div class="yseo-localization-row-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $rowProgressPercent ); ?>"><span style="width:<?php echo esc_attr( (string) $rowProgressPercent ); ?>%"></span></div></div>
                                            <span class="yps-mini-status <?php echo ! empty( $row['ready'] ) ? 'good' : ( 'invalid' === (string) ( $row['state'] ?? '' ) ? 'attention' : '' ); ?>"><?php echo esc_html( number_format_i18n( $rowProgressPercent, 1 ) . '%' ); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        </section>
                    </section>

                    <section class="yps-settings-panel" id="yps-settings-panel-search" data-yps-settings-panel="search">
                        <div class="yps-settings-panel-head"><div><span class="yps-card-kicker">SEO</span><h2><?php echo esc_html__( 'Homepage search appearance', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Set the homepage title and description while seeing the resulting search presentation beside the fields.', 'yablonsky-seo' ); ?></p></div><button type="button" class="button yseo-ai-settings-scope" data-yseo-ai-settings-scope="homepage"><?php echo esc_html__( 'AI draft open Homepage fields', 'yablonsky-seo' ); ?></button></div>
                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-split">
                                <div>
                                    <?php $this->settingField( 'home_title', __( 'SEO title', 'yablonsky-seo' ), $settings, 'text', true ); ?>
                                    <?php $this->settingField( 'home_description', __( 'Meta description', 'yablonsky-seo' ), $settings, 'textarea', true ); ?>
                                    <div class="yps-setting-field yps-field-wide"><?php $this->settingMediaFieldContents( 'home_search_image', __( 'Preferred search image', 'yablonsky-seo' ), $settings, true ); ?><small><?php echo esc_html__( 'A representative homepage image for Google Search/Discover eligibility and structured data. Google chooses thumbnails automatically; treat this as a preferred signal while Google retains final thumbnail selection.', 'yablonsky-seo' ); ?></small></div>
                                    <p class="yps-form-note"><?php echo esc_html__( 'Blank title or description fields use the safe WordPress/site fallback. The preferred image is distinct from the organization logo and favicon; Social can override Open Graph and X images.', 'yablonsky-seo' ); ?></p>
                                </div>
                                <div class="yps-settings-preview-wrap">
                                    <span class="yps-card-kicker"><?php echo esc_html__( 'Preview', 'yablonsky-seo' ); ?></span>
                                    <?php $siteIconUrl = (string) get_site_icon_url( 96 ); $preferredSearchImage = trim( (string) ( $settings['home_search_image'] ?? '' ) ); ?>
                                    <div class="yps-search-preview yps-settings-search-preview">
                                        <div class="yps-search-preview-main">
                                            <div class="yps-preview-site-row"><?php if ( '' !== $siteIconUrl ) : ?><img class="yps-preview-favicon" src="<?php echo esc_url( $siteIconUrl ); ?>" alt="" /><?php endif; ?><div><div class="yps-preview-domain"><?php echo esc_html( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ?: home_url( '/' ) ); ?></div><div class="yps-preview-url"><?php echo esc_html( home_url( '/' ) ); ?></div></div></div>
                                            <div class="yps-preview-title" id="yps-home-preview-title" data-fallback="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"><?php echo esc_html( (string) ( $settings['home_title'] ?? '' ) ?: get_bloginfo( 'name' ) ); ?></div>
                                            <div class="yps-preview-description" id="yps-home-preview-description" data-fallback="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>"><?php echo esc_html( (string) ( $settings['home_description'] ?? '' ) ?: get_bloginfo( 'description' ) ); ?></div>
                                        </div>
                                        <img class="yps-preview-search-image" data-yps-home-search-preview-image src="<?php echo esc_url( $preferredSearchImage ); ?>" alt="" <?php if ( '' === $preferredSearchImage ) : ?>hidden<?php endif; ?> />
                                    </div>
                                    <p class="yps-preview-help"><?php echo esc_html__( 'Preview is illustrative; search engines may rewrite displayed snippets.', 'yablonsky-seo' ); ?></p>
                                </div>
                            </div>
                        </section>

                        <?php $siteIconUrl = (string) get_site_icon_url( 96 ); $organizationLogo = trim( (string) ( $settings['owner_logo_url'] ?? '' ) ); $preferredSearchImage = trim( (string) ( $settings['home_search_image'] ?? '' ) ); ?>
                        <section class="yps-setting-card yps-setting-card-wide yps-search-visual-signals">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Search visuals', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Visual signal readiness', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Three separate sources serve different jobs: the preferred page image can influence search thumbnails, the Site Icon supplies favicon identity, and the Organization logo represents the publisher/entity.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yps-readiness-grid yps-search-visual-grid">
                                <div class="yps-readiness-item <?php echo '' !== $preferredSearchImage ? 'yps-tone-good' : 'yps-tone-attention'; ?>"><div><strong><?php echo esc_html__( 'Preferred homepage image', 'yablonsky-seo' ); ?></strong><span><?php echo '' !== $preferredSearchImage ? esc_html__( 'Representative image signal is set.', 'yablonsky-seo' ) : esc_html__( 'Preferred image ready to set.', 'yablonsky-seo' ); ?></span></div><b><?php echo '' !== $preferredSearchImage ? esc_html__( 'Ready', 'yablonsky-seo' ) : esc_html__( 'Review', 'yablonsky-seo' ); ?></b></div>
                                <div class="yps-readiness-item <?php echo '' !== $siteIconUrl ? 'yps-tone-good' : 'yps-tone-attention'; ?>"><div><strong><?php echo esc_html__( 'Search favicon / Site Icon', 'yablonsky-seo' ); ?></strong><span><?php echo '' !== $siteIconUrl ? esc_html__( 'Using the native WordPress Site Icon.', 'yablonsky-seo' ) : esc_html__( 'WordPress Site Icon: Setup required.', 'yablonsky-seo' ); ?></span></div><b><?php echo '' !== $siteIconUrl ? esc_html__( 'Detected', 'yablonsky-seo' ) : esc_html__( 'Attention needed', 'yablonsky-seo' ); ?></b></div>
                                <div class="yps-readiness-item <?php echo '' !== $organizationLogo ? 'yps-tone-good' : 'yps-tone-attention'; ?>"><div><strong><?php echo esc_html__( 'Organization logo', 'yablonsky-seo' ); ?></strong><span><?php echo '' !== $organizationLogo ? esc_html__( 'Entity logo is available to structured data.', 'yablonsky-seo' ) : esc_html__( 'Set in General when this site represents an organization.', 'yablonsky-seo' ); ?></span></div><b><?php echo '' !== $organizationLogo ? esc_html__( 'Ready', 'yablonsky-seo' ) : esc_html__( 'Optional', 'yablonsky-seo' ); ?></b></div>
                            </div>
                            <p class="yps-form-note"><?php echo esc_html__( 'These fields represent distinct signals. YSEO keeps one authority for each signal and shows its status here so Search, Social and Schema remain consistent.', 'yablonsky-seo' ); ?> <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"><?php echo esc_html__( 'Open WordPress Site Icon settings', 'yablonsky-seo' ); ?></a></p>
                        </section>

                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Freshness delivery', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'IndexNow push notifications', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Use IndexNow as an optional push channel when public resources materially change. XML sitemap lastmod remains the durable pull authority.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yps-authority-box yps-crawler-toggle"><input type="checkbox" id="yps-indexnow-enabled" name="indexnow_enabled" value="1" <?php checked( ! empty( $settings['indexnow_enabled'] ) ); ?> /><div><label for="yps-indexnow-enabled"><strong><?php echo esc_html__( 'Notify IndexNow when public resources materially change', 'yablonsky-seo' ); ?></strong></label><p><?php echo esc_html__( 'Saves only local canonical URLs to a deduplicated queue. A later WP-Cron job sends bounded URL-only batches to the official IndexNow endpoint; opening or saving Settings remains local.', 'yablonsky-seo' ); ?></p></div></div>
                        </section>

                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Crawl optimization', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Reduce low-value WordPress crawl surfaces', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'All controls are opt-in. Apply them to surfaces your site can safely retire; the WordPress REST API remains available.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yps-crawl-settings-grid">
                                <div class="yps-crawl-settings-group"><h3><?php echo esc_html__( 'Head metadata', 'yablonsky-seo' ); ?></h3>
                                    <?php $this->crawlToggle( 'crawl_remove_shortlinks', __( 'Remove shortlink discovery', 'yablonsky-seo' ), __( 'Removes duplicate ?p= shortlink hints from normal frontend responses.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_remove_rest_links', __( 'Remove REST API discovery links', 'yablonsky-seo' ), __( 'Hides REST discovery links/headers; the REST API itself continues to work.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_remove_rsd_wlw', __( 'Remove RSD / WLW links', 'yablonsky-seo' ), __( 'Removes legacy Really Simple Discovery and Windows Live Writer hints.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_remove_oembed_links', __( 'Remove oEmbed discovery and host script', 'yablonsky-seo' ), __( 'Use when your frontend operates independently of WordPress oEmbed discovery and host JavaScript.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_remove_generator', __( 'Remove generator metadata', 'yablonsky-seo' ), __( 'Removes WordPress generator output from frontend metadata/feed generator fields.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_remove_pingback_header', __( 'Remove X-Pingback header', 'yablonsky-seo' ), __( 'Use when XML-RPC pingbacks sit outside your publishing workflow.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_remove_powered_by_header', __( 'Remove X-Powered-By header', 'yablonsky-seo' ), __( 'Removes the PHP technology disclosure header when the server allows WordPress to remove it.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_remove_emoji', __( 'Remove legacy emoji detection resources', 'yablonsky-seo' ), __( 'Modern browsers render emoji natively; remove WordPress fallback detection resources when they are unnecessary.', 'yablonsky-seo' ), $settings ); ?>
                                </div>
                                <div class="yps-crawl-settings-group"><h3><?php echo esc_html__( 'Feeds', 'yablonsky-seo' ); ?></h3>
                                    <?php $this->crawlToggle( 'crawl_disable_global_feeds', __( 'Disable global post feeds', 'yablonsky-seo' ), __( 'Returns 410 for normal post feeds. Enable this for sites where RSS has been retired for readers, syndicators and integrations.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_disable_comments_feeds', __( 'Disable global comment feeds', 'yablonsky-seo' ), __( 'Returns 410 for site-wide comment feeds.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_disable_post_comments_feeds', __( 'Disable per-post comment feeds', 'yablonsky-seo' ), __( 'Returns 410 for comment feeds attached to singular resources.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_disable_author_feeds', __( 'Disable author feeds', 'yablonsky-seo' ), __( 'Removes low-value author-specific feed endpoints when unused.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_disable_post_type_feeds', __( 'Disable post-type archive feeds', 'yablonsky-seo' ), __( 'Returns 410 for public post-type archive feeds when unused.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_disable_category_feeds', __( 'Disable category feeds', 'yablonsky-seo' ), __( 'Returns 410 for category-specific feeds when unused.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_disable_tag_feeds', __( 'Disable tag feeds', 'yablonsky-seo' ), __( 'Returns 410 for tag-specific feeds when unused.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_disable_taxonomy_feeds', __( 'Disable custom-taxonomy feeds', 'yablonsky-seo' ), __( 'Returns 410 for custom-taxonomy feeds when unused.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_disable_search_feeds', __( 'Disable search-result feeds', 'yablonsky-seo' ), __( 'Prevents crawler expansion through feed variants of internal search.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_disable_atom_rdf_feeds', __( 'Disable Atom / RDF feed formats', 'yablonsky-seo' ), __( 'Removes legacy feed-format variants while RSS2 can remain available.', 'yablonsky-seo' ), $settings ); ?>
                                </div>
                                <div class="yps-crawl-settings-group"><h3><?php echo esc_html__( 'Search & machine endpoints', 'yablonsky-seo' ); ?></h3>
                                    <?php $this->crawlToggle( 'crawl_disallow_wp_json', __( 'Discourage crawling /wp-json/', 'yablonsky-seo' ), __( 'Adds robots.txt disallow hints for REST discovery URLs while REST functionality remains available.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_search_noindex', __( 'Noindex and discourage internal search crawling', 'yablonsky-seo' ), __( 'Adds noindex plus robots.txt hints for WordPress internal search result URLs.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_search_redirect_pretty', __( 'Canonicalize pretty search URLs', 'yablonsky-seo' ), __( 'Redirects /search/query/ to WordPress ?s=query form to reduce duplicate search URL shapes.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_search_filter_special', __( 'Reject symbol-only searches', 'yablonsky-seo' ), __( 'Returns a 404 state for searches that contain only symbols or whitespace.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_search_filter_spam', __( 'Reject common search-spam patterns', 'yablonsky-seo' ), __( 'Blocks a small, explicit set of high-confidence automated search-spam patterns.', 'yablonsky-seo' ), $settings ); ?>
                                    <?php $this->crawlToggle( 'crawl_clean_tracking_params', __( 'Remove common tracking parameters', 'yablonsky-seo' ), __( 'Redirects public GET/HEAD URLs after removing common UTM and ad-click identifiers while preserving functional query parameters.', 'yablonsky-seo' ), $settings ); ?>
                                    <div class="yps-setting-field"><label for="yps-crawl-search-max-chars"><?php echo esc_html__( 'Maximum internal-search query length', 'yablonsky-seo' ); ?></label><input id="yps-crawl-search-max-chars" name="crawl_search_max_chars" type="number" min="20" max="200" value="<?php echo esc_attr( (string) min( 200, max( 20, (int) ( $settings['crawl_search_max_chars'] ?? 80 ) ) ) ); ?>"><small><?php echo esc_html__( 'Overlong search queries return a 404 state. Default: 80 characters.', 'yablonsky-seo' ); ?></small></div>
                                </div>
                            </div>
                            <div class="yps-settings-callout"><span class="dashicons dashicons-performance"></span><div><strong><?php echo esc_html__( 'Crawl efficiency with search-neutral controls', 'yablonsky-seo' ); ?></strong><p><?php echo esc_html__( 'These controls reduce duplicate or unused WordPress surfaces. They start in Standby. Keep every feed, REST or discovery surface that real users or integrations depend on; ranking outcomes remain determined by external search systems.', 'yablonsky-seo' ); ?></p></div></div>
                        </section>

                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Special surfaces', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Search, archives, media & RSS controls', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Control low-level WordPress SEO surfaces separately from post-specific metadata.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yps-field-grid yps-field-grid-two">
                                <?php /* translators: %%searchphrase%%, %%sitename%%, %%sitedesc%%, %%sep%% and %%page%% are literal YSEO template variables. */ ?>
                                <div class="yps-setting-field"><label for="yps-search-title-template"><?php echo esc_html__( 'Internal search title template', 'yablonsky-seo' ); ?></label><div class="yseo-ai-control-row"><input class="large-text" id="yps-search-title-template" name="search_title_template" value="<?php echo esc_attr( (string) ( $settings['search_title_template'] ?? 'Search results for %%searchphrase%% %%sep%% %%sitename%%' ) ); ?>" /><button type="button" class="button yseo-ai-mini-button yseo-ai-setting-field" data-yseo-ai-setting-field="search_title_template" title="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>" aria-label="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'AI', 'yablonsky-seo' ); ?></button></div><small><?php echo esc_html__( 'Supported: %%searchphrase%%, %%sitename%%, %%sitedesc%%, %%sep%% and %%page%%.', 'yablonsky-seo' ); ?></small></div>
                                <div class="yps-setting-field"><label for="yps-error-404-title-template"><?php echo esc_html__( '404 title template', 'yablonsky-seo' ); ?></label><div class="yseo-ai-control-row"><input class="large-text" id="yps-error-404-title-template" name="error_404_title_template" value="<?php echo esc_attr( (string) ( $settings['error_404_title_template'] ?? 'Page not found %%sep%% %%sitename%%' ) ); ?>" /><button type="button" class="button yseo-ai-mini-button yseo-ai-setting-field" data-yseo-ai-setting-field="error_404_title_template" title="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>" aria-label="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'AI', 'yablonsky-seo' ); ?></button></div><small><?php echo esc_html__( '404 pages are noindexed automatically; this controls their human-visible document title.', 'yablonsky-seo' ); ?></small></div>
                                <?php /* translators: %%archive%%, %%sitename%%, %%sitedesc%%, %%sep%% and %%page%% are literal YSEO template variables. */ ?>
                                <div class="yps-setting-field"><label for="yps-date-archive-title-template"><?php echo esc_html__( 'Date archive title template', 'yablonsky-seo' ); ?></label><div class="yseo-ai-control-row"><input class="large-text" id="yps-date-archive-title-template" name="date_archive_title_template" value="<?php echo esc_attr( (string) ( $settings['date_archive_title_template'] ?? '%%archive%% %%sep%% %%sitename%%' ) ); ?>" /><button type="button" class="button yseo-ai-mini-button yseo-ai-setting-field" data-yseo-ai-setting-field="date_archive_title_template" title="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>" aria-label="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'AI', 'yablonsky-seo' ); ?></button></div><small><?php echo esc_html__( 'Supported: %%archive%%, %%sitename%%, %%sitedesc%%, %%sep%% and %%page%%.', 'yablonsky-seo' ); ?></small></div>
                                <div class="yps-setting-field"><label for="yps-post-format-title-template"><?php echo esc_html__( 'Post format archive title template', 'yablonsky-seo' ); ?></label><div class="yseo-ai-control-row"><input class="large-text" id="yps-post-format-title-template" name="post_format_archive_title_template" value="<?php echo esc_attr( (string) ( $settings['post_format_archive_title_template'] ?? '%%archive%% %%sep%% %%sitename%%' ) ); ?>" /><button type="button" class="button yseo-ai-mini-button yseo-ai-setting-field" data-yseo-ai-setting-field="post_format_archive_title_template" title="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>" aria-label="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'AI', 'yablonsky-seo' ); ?></button></div><small><?php echo esc_html__( 'Controls the human-visible title for WordPress post-format archives when the theme exposes them.', 'yablonsky-seo' ); ?></small></div>
                                <label class="yps-agent-option"><input type="checkbox" name="date_archives_noindex" value="1" <?php checked( ! empty( $settings['date_archives_noindex'] ) ); ?> /><span><strong><?php echo esc_html__( 'Noindex date archives', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Useful when date archives duplicate normal article discovery and editorial value resides in the primary articles.', 'yablonsky-seo' ); ?></small></span></label>
                                <label class="yps-agent-option"><input type="checkbox" name="post_format_archives_noindex" value="1" <?php checked( ! empty( $settings['post_format_archives_noindex'] ) ); ?> /><span><strong><?php echo esc_html__( 'Noindex post format archives', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Useful when format archives are thin duplicates rather than meaningful landing pages.', 'yablonsky-seo' ); ?></small></span></label>
                                <div class="yps-setting-field"><label for="yps-media-redirect-target"><?php echo esc_html__( 'Attachment-page redirect target', 'yablonsky-seo' ); ?></label><select id="yps-media-redirect-target" name="media_redirect_target"><option value="file" <?php selected( (string) ( $settings['media_redirect_target'] ?? 'file' ), 'file' ); ?>><?php echo esc_html__( 'Media file', 'yablonsky-seo' ); ?></option><option value="parent" <?php selected( (string) ( $settings['media_redirect_target'] ?? 'file' ), 'parent' ); ?>><?php echo esc_html__( 'Parent content when available', 'yablonsky-seo' ); ?></option></select><small><?php echo esc_html__( 'Parent mode uses the media file when a public parent is unavailable.', 'yablonsky-seo' ); ?></small></div>
                                <label class="yps-agent-option yps-field-wide"><input type="checkbox" name="media_redirect_attachment_pages" value="1" <?php checked( ! empty( $settings['media_redirect_attachment_pages'] ) ); ?> /><span><strong><?php echo esc_html__( 'Redirect attachment pages', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Keeps thin standalone media attachment pages out of the public publishing surface when they are outside your publishing model.', 'yablonsky-seo' ); ?></small></span></label>
                                <div class="yps-setting-field yps-field-wide"><label for="yps-rss-before-content"><?php echo esc_html__( 'RSS text before each item', 'yablonsky-seo' ); ?></label><div class="yseo-ai-control-row is-textarea"><textarea class="large-text" rows="3" id="yps-rss-before-content" name="rss_before_content"><?php echo esc_textarea( (string) ( $settings['rss_before_content'] ?? '' ) ); ?></textarea><button type="button" class="button yseo-ai-mini-button yseo-ai-setting-field" data-yseo-ai-setting-field="rss_before_content" title="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>" aria-label="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'AI', 'yablonsky-seo' ); ?></button></div><small><?php echo esc_html__( 'Optional attribution. Supported: %%POSTLINK%%, %%BLOGLINK%%, %%AUTHORLINK%%, %%BLOGDESCLINK%%.', 'yablonsky-seo' ); ?></small></div>
                                <div class="yps-setting-field yps-field-wide"><label for="yps-rss-after-content"><?php echo esc_html__( 'RSS text after each item', 'yablonsky-seo' ); ?></label><div class="yseo-ai-control-row is-textarea"><textarea class="large-text" rows="3" id="yps-rss-after-content" name="rss_after_content"><?php echo esc_textarea( (string) ( $settings['rss_after_content'] ?? '' ) ); ?></textarea><button type="button" class="button yseo-ai-mini-button yseo-ai-setting-field" data-yseo-ai-setting-field="rss_after_content" title="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>" aria-label="<?php echo esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'AI', 'yablonsky-seo' ); ?></button></div><small><?php echo esc_html__( 'Useful for source attribution while preserving the canonical HTML page.', 'yablonsky-seo' ); ?></small></div>
                            </div>
                        </section>

                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Audience signal', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Google Preferred Sources', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Preferred Sources is a user-controlled Google feature. YSEO provides a privacy-light static link helper so publishers can invite readers to choose the current domain while normal page views stay free of Google JavaScript.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yps-settings-callout"><span class="dashicons dashicons-star-filled"></span><div><strong><?php echo esc_html__( 'Use the shortcode where the invitation makes sense', 'yablonsky-seo' ); ?></strong><p><code>[yseo_preferred_source]</code></p><p><?php echo esc_html__( 'Optional attributes: label for custom link text and class for one additional CSS class. Selection is completed by the reader on Google; ranking, Top Stories inclusion and AI visibility remain under external platform control.', 'yablonsky-seo' ); ?></p></div></div>
                        </section>
                    </section>

                    <section class="yps-settings-panel" id="yps-settings-panel-social" data-yps-settings-panel="social">
                        <div class="yps-settings-panel-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Social', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Homepage social appearance', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Control the title, description and image used when the homepage is shared on Facebook, X and other Open Graph consumers.', 'yablonsky-seo' ); ?></p></div><div class="yps-inline-actions"><button type="button" class="button yseo-ai-settings-scope" data-yseo-ai-settings-scope="social"><?php echo esc_html__( 'AI draft open Homepage fields', 'yablonsky-seo' ); ?></button><button type="button" class="button button-primary yseo-ai-settings-scope" data-yseo-ai-settings-scope="all"><?php echo esc_html__( 'AI draft all safe open fields', 'yablonsky-seo' ); ?></button></div></div>
                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-social-preview-grid" data-yps-home-social-previews>
                                <?php
                                $homeSocialTitle = trim( (string) ( $settings['home_og_title'] ?? '' ) );
                                if ( '' === $homeSocialTitle ) { $homeSocialTitle = trim( (string) ( $settings['home_title'] ?? '' ) ); }
                                if ( '' === $homeSocialTitle ) { $homeSocialTitle = (string) get_bloginfo( 'name' ); }
                                $homeSocialDescription = trim( (string) ( $settings['home_og_description'] ?? '' ) );
                                if ( '' === $homeSocialDescription ) { $homeSocialDescription = trim( (string) ( $settings['home_description'] ?? '' ) ); }
                                if ( '' === $homeSocialDescription ) { $homeSocialDescription = (string) get_bloginfo( 'description' ); }
                                $homeSocialImage = trim( (string) ( $settings['home_og_image'] ?? '' ) );
                                if ( '' === $homeSocialImage ) { $homeSocialImage = trim( (string) ( $settings['default_social_image'] ?? '' ) ); }
                                $homeXTitle = trim( (string) ( $settings['home_twitter_title'] ?? '' ) );
                                if ( '' === $homeXTitle ) { $homeXTitle = $homeSocialTitle; }
                                $homeXDescription = trim( (string) ( $settings['home_twitter_description'] ?? '' ) );
                                if ( '' === $homeXDescription ) { $homeXDescription = $homeSocialDescription; }
                                $homeXImage = trim( (string) ( $settings['home_twitter_image'] ?? '' ) );
                                if ( '' === $homeXImage ) { $homeXImage = $homeSocialImage; }
                                ?>
                                <article class="yps-social-preview-card is-facebook"><span class="yps-card-kicker"><?php echo esc_html__( 'Facebook preview', 'yablonsky-seo' ); ?></span><div class="yps-social-preview-image" data-yps-home-social-image="facebook"><?php if ( '' !== $homeSocialImage ) : ?><img src="<?php echo esc_url( $homeSocialImage ); ?>" alt="" /><?php endif; ?></div><div class="yps-social-preview-copy"><small><?php echo esc_html( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ); ?></small><strong data-yps-home-social-title="facebook"><?php echo esc_html( $homeSocialTitle ); ?></strong><span data-yps-home-social-description="facebook"><?php echo esc_html( $homeSocialDescription ); ?></span></div></article>
                                <article class="yps-social-preview-card is-x"><span class="yps-card-kicker"><?php echo esc_html__( 'X / Twitter preview', 'yablonsky-seo' ); ?></span><div class="yps-social-preview-image" data-yps-home-social-image="x"><?php if ( '' !== $homeXImage ) : ?><img src="<?php echo esc_url( $homeXImage ); ?>" alt="" /><?php endif; ?></div><div class="yps-social-preview-copy"><strong data-yps-home-social-title="x"><?php echo esc_html( $homeXTitle ); ?></strong><span data-yps-home-social-description="x"><?php echo esc_html( $homeXDescription ); ?></span></div></article>
                            </div>
                            <div class="yps-field-grid yps-field-grid-two">
                                <div class="yps-setting-field yps-field-wide"><?php $this->settingMediaFieldContents( 'default_social_image', __( 'Default social image', 'yablonsky-seo' ), $settings, true ); ?><small><?php echo esc_html__( 'Used when a public resource relies on the fallback social image.', 'yablonsky-seo' ); ?></small></div>
                                <?php $this->settingField( 'home_og_title', __( 'Open Graph title', 'yablonsky-seo' ), $settings, 'text', true ); ?>
                                <div class="yps-setting-field"><?php $this->settingMediaFieldContents( 'home_og_image', __( 'Open Graph image URL', 'yablonsky-seo' ), $settings, true ); ?></div>
                                <div class="yps-setting-field yps-field-wide"><?php $this->settingFieldContents( 'home_og_description', __( 'Open Graph description', 'yablonsky-seo' ), $settings, 'textarea', true ); ?></div>
                                <?php $this->settingField( 'home_twitter_title', __( 'Twitter/X title', 'yablonsky-seo' ), $settings, 'text', true ); ?>
                                <div class="yps-setting-field"><?php $this->settingMediaFieldContents( 'home_twitter_image', __( 'Twitter/X image URL', 'yablonsky-seo' ), $settings, true ); ?></div>
                                <div class="yps-setting-field yps-field-wide"><?php $this->settingFieldContents( 'home_twitter_description', __( 'Twitter/X description', 'yablonsky-seo' ), $settings, 'textarea', true ); ?></div>
                            </div>
                            <div class="yps-editor-note"><?php echo esc_html__( 'Social fields use the search title/description and the resource image as fallbacks when available. The previews are editorial simulations; social networks retain final presentation control.', 'yablonsky-seo' ); ?></div>
                        </section>

                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Reusable defaults', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Social templates by content family', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Set reusable Open Graph and X defaults for public content types and archives. Per-resource Social fields still win. Template variables such as %%title%%, %%sitename%% and taxonomy variables use the same local resolver as Search metadata.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yps-status yseo-ai-social-template-status" data-yseo-ai-social-template-status aria-live="polite"></div>
                            <?php $this->renderSocialTemplateSettings( $settings ); ?>
                        </section>
                    </section>

                    <section class="yps-settings-panel" id="yps-settings-panel-answer" data-yps-settings-panel="answer">
                        <div class="yps-settings-panel-head"><div><span class="yps-card-kicker">AEO</span><h2><?php echo esc_html__( 'Answer extraction guidance', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Set only site-wide analysis defaults here. Page-specific answer quality, schema choice and editorial judgment remain inside each content editor.', 'yablonsky-seo' ); ?></p></div><span class="yps-mini-status neutral"><?php echo esc_html( strtoupper( $aeoPolicy->profile() ) ); ?></span></div>

                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Analysis policy', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Global AEO guidance profile', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Profiles tune transparent local heuristics only. Content changes, hidden-answer behavior and ranking/citation outcomes remain outside these profiles.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yps-field-grid yps-field-grid-two">
                                <div class="yps-setting-field"><label for="yps-aeo-analysis-profile"><?php echo esc_html__( 'Analysis guidance profile', 'yablonsky-seo' ); ?></label><select id="yps-aeo-analysis-profile" name="aeo_analysis_profile"><option value="balanced" data-intro="30–120" data-paragraph="120" data-depth="250" <?php selected( $aeoPolicy->profile(), 'balanced' ); ?>><?php echo esc_html__( 'Balanced · recommended default', 'yablonsky-seo' ); ?></option><option value="concise" data-intro="20–90" data-paragraph="90" data-depth="180" <?php selected( $aeoPolicy->profile(), 'concise' ); ?>><?php echo esc_html__( 'Concise · short answer-first pages', 'yablonsky-seo' ); ?></option><option value="extended" data-intro="40–160" data-paragraph="150" data-depth="400" <?php selected( $aeoPolicy->profile(), 'extended' ); ?>><?php echo esc_html__( 'Extended · long-form resources', 'yablonsky-seo' ); ?></option></select><small><?php echo esc_html__( 'Choose the editorial shape you usually publish. This changes analysis thresholds while public output remains unchanged.', 'yablonsky-seo' ); ?></small></div>
                                <div class="yps-aeo-threshold-card"><span><?php echo esc_html__( 'Current thresholds', 'yablonsky-seo' ); ?></span><strong><span id="yps-aeo-threshold-opening"><?php echo esc_html( (string) $aeoPolicy->introGoodMin() . '–' . (string) $aeoPolicy->introGoodMax() ); ?></span> <?php echo esc_html__( 'opening words', 'yablonsky-seo' ); ?></strong><small><span id="yps-aeo-threshold-paragraph"><?php echo esc_html( (string) $aeoPolicy->paragraphWordLimit() ); ?></span> <?php echo esc_html__( 'words / dense paragraph · depth target', 'yablonsky-seo' ); ?> <span id="yps-aeo-threshold-depth"><?php echo esc_html( (string) $aeoPolicy->depthGood() ); ?></span>+</small></div>
                            </div>
                            <div class="yps-aeo-guidance-grid">
                                <label class="yps-agent-option"><input type="checkbox" name="aeo_question_heading_guidance" value="1" <?php checked( $aeoPolicy->questionHeadingGuidance() ); ?> /><span><strong><?php echo esc_html__( 'Question-led section guidance', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Include direct-question headings as an optional positive AEO signal when they fit the content naturally.', 'yablonsky-seo' ); ?></small></span></label>
                                <label class="yps-agent-option"><input type="checkbox" name="aeo_compact_structure_guidance" value="1" <?php checked( $aeoPolicy->compactStructureGuidance() ); ?> /><span><strong><?php echo esc_html__( 'Compact structure guidance', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Use useful lists or tables as an advisory extractability signal where they improve clarity; prose remains appropriate elsewhere.', 'yablonsky-seo' ); ?></small></span></label>
                                <label class="yps-agent-option"><input type="checkbox" name="aeo_faq_guardrail" value="1" <?php checked( $aeoPolicy->faqGuardrail() ); ?> /><span><strong><?php echo esc_html__( 'FAQPage guardrail', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'When FAQPage is selected, flag obviously weak visible FAQ evidence. FAQPage selection remains editor-controlled and evidence-led.', 'yablonsky-seo' ); ?></small></span></label>
                            </div>
                            <div class="yps-settings-callout"><span class="dashicons dashicons-editor-help"></span><div><strong><?php echo esc_html__( 'Human-visible content remains the authority', 'yablonsky-seo' ); ?></strong><p><?php echo esc_html__( 'AEO guidance evaluates the first meaningful paragraph, section structure, density, summary availability and discoverability. Guidance stays within visible content and preserves natural page formats.', 'yablonsky-seo' ); ?></p></div></div>
                        </section>

                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Local evidence', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Recent-content AEO sample', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'A bounded sample of recently modified public resources shows how the active guidance profile behaves on real saved content. Treat it as local optimization-readiness evidence.', 'yablonsky-seo' ); ?></p></div>
                            <?php if ( (int) $aeoAudit['sampled'] > 0 ) : ?>
                                <div class="yps-aeo-audit-grid">
                                    <div><span><?php echo esc_html__( 'Sampled', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $aeoAudit['sampled'] ) ); ?></strong></div>
                                    <div><span><?php echo esc_html__( 'Ready', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $aeoAudit['good'] ) ); ?></strong></div>
                                    <div><span><?php echo esc_html__( 'Improve', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $aeoAudit['attention'] ) ); ?></strong></div>
                                    <div><span><?php echo esc_html__( 'Problems', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $aeoAudit['critical'] ) ); ?></strong></div>
                                    <div><span><?php echo esc_html__( 'With summary', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $aeoAudit['with_summary'] ) ); ?></strong></div>
                                    <div><span><?php echo esc_html__( '2+ sections', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $aeoAudit['with_sections'] ) ); ?></strong></div>
                                </div>
                                <p class="yps-form-note"><?php echo esc_html__( 'The sample is capped at the 20 most recently modified public resources to keep Settings predictable on large sites.', 'yablonsky-seo' ); ?></p>
                            <?php else : ?>
                                <div class="yps-empty-state"><strong><?php echo esc_html__( 'Public content sample will appear after publishable content is available.', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Publish content first; per-resource AEO guidance remains available in the editor.', 'yablonsky-seo' ); ?></span></div>
                            <?php endif; ?>
                        </section>
                    </section>

                    <section class="yps-settings-panel" id="yps-settings-panel-crawlers" data-yps-settings-panel="crawlers">
                        <div class="yps-settings-panel-head"><div><span class="yps-card-kicker">GEO</span><h2><?php echo esc_html__( 'Crawler permissions & machine-readable web', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Keep Search discovery, AI answers, user-directed agents and model training separate instead of treating every automated use as one switch.', 'yablonsky-seo' ); ?></p></div></div>
                        <section class="yps-setting-card yps-setting-card-wide">
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Vendor-neutral permission layer', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'One intent model, provider-specific adapters', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'YSEO stores four separate policy intents. robots.txt tokens, provider consoles, meta/X-Robots controls, CDN/WAF policy and future standards can map onto those intents while the administrator-facing model remains stable.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yps-authority-box yps-crawler-toggle"><input type="checkbox" id="yps-ai-crawler-policy" name="ai_crawler_policy_enabled" value="1" <?php checked( ! empty( $settings['ai_crawler_policy_enabled'] ) ); ?> /><div><label for="yps-ai-crawler-policy"><strong><?php echo esc_html__( 'Manage verified crawler policy adapters', 'yablonsky-seo' ); ?></strong></label><p><?php echo esc_html__( 'Starts inactive. Existing named robots.txt blocks retain priority; Yablonsky SEO preserves them and leaves external provider/CDN settings under their existing authority.', 'yablonsky-seo' ); ?></p></div></div>
                            <div class="yps-crawler-grid">
                                <div class="yps-setting-field"><label for="yps-ai-search-access"><?php echo esc_html__( 'Search discovery', 'yablonsky-seo' ); ?></label><select id="yps-ai-search-access" name="ai_search_access"><option value="allow" <?php selected( $crawlerPermission->value( 'search' ), 'allow' ); ?>><?php echo esc_html__( 'Allow', 'yablonsky-seo' ); ?></option><option value="disallow" <?php selected( $crawlerPermission->value( 'search' ), 'disallow' ); ?>><?php echo esc_html__( 'Disallow', 'yablonsky-seo' ); ?></option><option value="inherit" <?php selected( $crawlerPermission->value( 'search' ), 'inherit' ); ?>><?php echo esc_html__( 'Inherit external policy', 'yablonsky-seo' ); ?></option></select><small><?php echo esc_html__( 'Traditional and AI-oriented search discovery/indexing. Blocking mixed-use crawlers at an edge can also remove ordinary search access.', 'yablonsky-seo' ); ?></small></div>
                                <div class="yps-setting-field"><label for="yps-ai-answers-access"><?php echo esc_html__( 'AI answers / generative search', 'yablonsky-seo' ); ?></label><select id="yps-ai-answers-access" name="ai_answers_access"><option value="allow" <?php selected( $crawlerPermission->value( 'answers' ), 'allow' ); ?>><?php echo esc_html__( 'Allow / participate', 'yablonsky-seo' ); ?></option><option value="disallow" <?php selected( $crawlerPermission->value( 'answers' ), 'disallow' ); ?>><?php echo esc_html__( 'Prefer exclusion where supported', 'yablonsky-seo' ); ?></option><option value="inherit" <?php selected( $crawlerPermission->value( 'answers' ), 'inherit' ); ?>><?php echo esc_html__( 'Provider-managed / inherit', 'yablonsky-seo' ); ?></option></select><small><?php echo esc_html__( 'Policy intent for AI-generated search answers. Some providers use console-side controls; YSEO keeps those provider controls authoritative alongside robots.txt.', 'yablonsky-seo' ); ?></small></div>
                                <div class="yps-setting-field"><label for="yps-ai-agent-access"><?php echo esc_html__( 'AI agents / user-directed retrieval', 'yablonsky-seo' ); ?></label><select id="yps-ai-agent-access" name="ai_agent_access"><option value="allow" <?php selected( $crawlerPermission->value( 'agent' ), 'allow' ); ?>><?php echo esc_html__( 'Allow', 'yablonsky-seo' ); ?></option><option value="disallow" <?php selected( $crawlerPermission->value( 'agent' ), 'disallow' ); ?>><?php echo esc_html__( 'Disallow', 'yablonsky-seo' ); ?></option><option value="inherit" <?php selected( $crawlerPermission->value( 'agent' ), 'inherit' ); ?>><?php echo esc_html__( 'Inherit external policy', 'yablonsky-seo' ); ?></option></select><small><?php echo esc_html__( 'User-directed agents and retrieval bots acting on behalf of a person.', 'yablonsky-seo' ); ?></small></div>
                                <div class="yps-setting-field"><label for="yps-ai-training-access"><?php echo esc_html__( 'Model training', 'yablonsky-seo' ); ?></label><select id="yps-ai-training-access" name="ai_training_access"><option value="inherit" <?php selected( $crawlerPermission->value( 'training' ), 'inherit' ); ?>><?php echo esc_html__( 'Inherit external policy', 'yablonsky-seo' ); ?></option><option value="allow" <?php selected( $crawlerPermission->value( 'training' ), 'allow' ); ?>><?php echo esc_html__( 'Allow', 'yablonsky-seo' ); ?></option><option value="disallow" <?php selected( $crawlerPermission->value( 'training' ), 'disallow' ); ?>><?php echo esc_html__( 'Disallow', 'yablonsky-seo' ); ?></option></select><small><?php echo esc_html__( 'Foundation-model training/data use, kept independent from search discovery and agents.', 'yablonsky-seo' ); ?></small></div>
                                <div class="yps-setting-field"><label for="yps-ai-ads-access"><?php echo esc_html__( 'ChatGPT ads crawler', 'yablonsky-seo' ); ?></label><select id="yps-ai-ads-access" name="ai_ads_access"><option value="inherit" <?php selected( $crawlerPermission->value( 'ads' ), 'inherit' ); ?>><?php echo esc_html__( 'Inherit external policy', 'yablonsky-seo' ); ?></option><option value="allow" <?php selected( $crawlerPermission->value( 'ads' ), 'allow' ); ?>><?php echo esc_html__( 'Allow', 'yablonsky-seo' ); ?></option><option value="disallow" <?php selected( $crawlerPermission->value( 'ads' ), 'disallow' ); ?>><?php echo esc_html__( 'Disallow', 'yablonsky-seo' ); ?></option></select><small><?php echo esc_html__( 'Independent OAI-AdsBot policy for advertisement landing-page validation.', 'yablonsky-seo' ); ?></small></div>
                            </div>
                            <div class="yps-settings-callout <?php echo ! empty( $crawlerInterop['mixed_use_review'] ) ? 'is-attention' : ''; ?>"><span class="dashicons dashicons-cloud"></span><div><strong><?php echo esc_html__( 'Cloudflare mixed-use crawler guard', 'yablonsky-seo' ); ?></strong><p><?php echo esc_html( ! empty( $crawlerInterop['cloudflare_detected'] ) ? __( 'Cloudflare edge identity is not verified by this local request. When Search is allowed and Training is disallowed, use Cloudflare Disallow AI Training. On pages with ads, Training=Block or Block can also block mixed-use Googlebot, Bingbot and Applebot and affect ordinary search crawling.', 'yablonsky-seo' ) : __( 'External CDN/WAF policy remains an edge-layer source of truth. If this site uses Cloudflare and you want Search allowed while Training is refused, verify Cloudflare uses Disallow AI Training instead of Training=Block or Block on pages with ads.', 'yablonsky-seo' ) ); ?></p></div></div>
                            <div class="yps-settings-callout"><span class="dashicons dashicons-admin-generic"></span><div><strong><?php echo esc_html__( 'ai-prefs readiness', 'yablonsky-seo' ); ?></strong><p><?php echo esc_html__( 'The IETF AI Preferences work is still evolving. YSEO keeps a vendor-neutral permission model now and will emit a standardized directive when the interoperable syntax is sufficiently mature.', 'yablonsky-seo' ); ?></p></div></div>
                            <div class="yps-settings-section-title"><span class="yps-card-kicker"><?php echo esc_html__( 'How the agent surface fits together', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Orientation can be site-level or path-level; content detail stays resource-level', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'YSEO can publish a root /llms.txt plus optional scoped /section/llms.txt files. Each eligible resource keeps its own clean .md alternate, while the normal HTML URL remains canonical.', 'yablonsky-seo' ); ?></p></div>
                            <div class="yps-agent-flow yps-agent-flow-settings yps-agent-flow-four"><div><code>/llms.txt</code><strong><?php echo esc_html__( 'Site map for agents', 'yablonsky-seo' ); ?></strong></div><b>→</b><div><code>/section/llms.txt</code><strong><?php echo esc_html__( 'Scoped orientation', 'yablonsky-seo' ); ?></strong></div><b>→</b><div><code>/page.md</code><strong><?php echo esc_html__( 'Cheap clean content', 'yablonsky-seo' ); ?></strong></div><b>→</b><div><code>/page/</code><strong><?php echo esc_html__( 'Canonical public page', 'yablonsky-seo' ); ?></strong></div></div>
                            <?php if ( ! empty( $crawlerRegistryRows ) ) : ?>
                                <details class="yps-crawler-evidence">
                                    <summary><strong><?php echo esc_html__( 'Verified crawler / control identity coverage', 'yablonsky-seo' ); ?></strong><?php if ( '' !== $crawlerRegistryVersion ) : ?> <span class="yps-mini-status neutral"><?php echo esc_html( $crawlerRegistryVersion ); ?></span><?php endif; ?></summary>
                                    <p class="yps-form-note"><?php echo esc_html__( 'Bundled evidence is local and versioned. Search indexing, user-directed retrieval and model-training/data-use identities remain separate even when they belong to the same operator.', 'yablonsky-seo' ); ?></p>
                                    <div class="yps-crawler-evidence-grid">
                                        <?php foreach ( $crawlerRegistryRows as $crawlerRow ) : ?>
                                            <?php
                                            $crawlerPurpose = sanitize_key( (string) ( $crawlerRow['purpose'] ?? '' ) );
                                            if ( 'retrieval' === $crawlerPurpose ) { $crawlerPurpose = 'agent'; }
                                            $crawlerPolicyKey = array( 'search' => 'ai_search_access', 'answers' => 'ai_answers_access', 'agent' => 'ai_agent_access', 'training' => 'ai_training_access', 'ads' => 'ai_ads_access' )[ $crawlerPurpose ] ?? '';
                                            $crawlerPolicyEnabled = ! empty( $settings['ai_crawler_policy_enabled'] );
                                            $crawlerDecision = $crawlerPolicyEnabled && '' !== $crawlerPolicyKey ? $crawlerPermission->value( $crawlerPurpose ) : 'inactive';
                                            if ( ! in_array( $crawlerDecision, array( 'allow', 'disallow', 'inherit', 'inactive' ), true ) ) { $crawlerDecision = 'inherit'; }
                                            $crawlerDecisionTone = 'allow' === $crawlerDecision ? 'good' : ( 'disallow' === $crawlerDecision ? 'attention' : 'neutral' );
                                            ?>
                                            <div class="yps-crawler-evidence-row">
                                                <div><strong><?php echo esc_html( (string) ( $crawlerRow['operator'] ?? '' ) ); ?></strong><code><?php echo esc_html( (string) ( $crawlerRow['agent'] ?? '' ) ); ?></code></div>
                                                <span class="yps-mini-status neutral"><?php echo esc_html( strtoupper( $crawlerPurpose ) ); ?></span>
                                                <?php if ( ! empty( $crawlerRow['mixed_use'] ) ) : ?><span class="yps-mini-status attention"><?php echo esc_html__( 'MIXED-USE', 'yablonsky-seo' ); ?></span><?php endif; ?>
                                                <span class="yps-mini-status <?php echo esc_attr( $crawlerDecisionTone ); ?>"><?php echo esc_html( strtoupper( $crawlerDecision ) ); ?></span>
                                                <span><?php echo esc_html( (string) ( $crawlerRow['label'] ?? '' ) ); ?><?php if ( ! empty( $crawlerRow['control_note'] ) ) : ?><small><?php echo esc_html( ' ' . (string) $crawlerRow['control_note'] ); ?></small><?php endif; ?></span>
                                                <?php if ( ! empty( $crawlerRow['source'] ) ) : ?><a href="<?php echo esc_url( (string) $crawlerRow['source'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Official source', 'yablonsky-seo' ); ?></a><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                            <div class="yps-settings-callout"><span class="dashicons dashicons-visibility"></span><div><strong><?php echo esc_html__( 'Apple Search, AI answers and training are separate', 'yablonsky-seo' ); ?></strong><p><?php echo esc_html__( 'Applebot follows the Search policy. Applebot-Extended follows the training policy and controls foundation-model data use while Apple Search eligibility remains unchanged. A page-level Apple AI answer-context opt-out is available in each content editor and emits an Applebot-specific nosnippet directive.', 'yablonsky-seo' ); ?></p></div></div>
                            <div class="yps-authority-box yps-crawler-toggle yps-agent-readable-toggle"><input type="checkbox" id="yps-agent-markdown-enabled" name="agent_markdown_enabled" value="1" <?php checked( ! empty( $settings['agent_markdown_enabled'] ) ); ?> /><div><label for="yps-agent-markdown-enabled"><strong><?php echo esc_html__( 'Publish agent-readable Markdown alternates', 'yablonsky-seo' ); ?></strong></label><p><?php echo esc_html__( 'When Yablonsky SEO owns public authority, expose root /llms.txt plus clean .md alternates for public resources. HTML remains canonical and Markdown responses are noindex.', 'yablonsky-seo' ); ?></p></div></div>
                            <div class="yps-settings-callout"><span class="dashicons dashicons-database"></span><div><strong><?php echo esc_html__( 'Local Markdown generation', 'yablonsky-seo' ); ?></strong><p><?php echo esc_html__( 'Markdown and llms.txt are generated locally by YSEO from saved WordPress resource truth and the materialized document cache. They operate independently of Yablonsky AI and API credentials.', 'yablonsky-seo' ); ?></p></div></div>
                            <div class="yps-agent-option-grid" data-yps-agent-options>
                                <label class="yps-agent-option"><input type="checkbox" name="agent_scoped_llms_enabled" value="1" <?php checked( ! empty( $settings['agent_scoped_llms_enabled'] ) ); ?> /><span><strong><?php echo esc_html__( 'Publish scoped llms.txt indexes', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Adds path-level orientation such as /product/llms.txt, /services/llms.txt or /category/example/llms.txt where YSEO can resolve a real public scope.', 'yablonsky-seo' ); ?></small></span></label>
                                <label class="yps-agent-option"><input type="checkbox" name="agent_sitemap_extension_enabled" value="1" <?php checked( ! empty( $settings['agent_sitemap_extension_enabled'] ) ); ?> /><span><strong><?php echo esc_html__( 'Advertise agent alternates in XML sitemaps', 'yablonsky-seo' ); ?> <em><?php echo esc_html__( 'Experimental', 'yablonsky-seo' ); ?></em></strong><small><?php echo esc_html__( 'Uses a namespaced sitemap extension to attach each canonical URL to its .md alternate and covering llms.txt. Standard sitemap loc URLs stay canonical HTML.', 'yablonsky-seo' ); ?></small></span></label>
                            </div>
                            <p class="yps-form-note"><?php echo esc_html__( 'Crawler controls, Markdown alternates and sitemap discovery improve machine access and orientation. Search engines retain authority over sitemap namespace support, citations, rankings and model inclusion.', 'yablonsky-seo' ); ?></p>
                        </section>
                    </section>
                    <section class="yps-settings-panel" id="yps-settings-panel-ai" data-yps-settings-panel="ai">
                        <div class="yps-settings-panel-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Yablonsky AI', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Connected intelligence', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Connect once, choose the account context, then use AI only where you explicitly request it.', 'yablonsky-seo' ); ?></p></div>
                            <?php if ( $aiSettings->isConfigured() && $aiSettings->lastVerifiedAt() > 0 ) : ?><span class="yps-mini-status good"><?php echo esc_html__( 'VERIFIED', 'yablonsky-seo' ); ?></span><?php elseif ( $aiSettings->isConfigured() ) : ?><span class="yps-mini-status attention"><?php echo esc_html__( 'VERIFY TO ACTIVATE', 'yablonsky-seo' ); ?></span><?php else : ?><span class="yps-mini-status neutral"><?php echo esc_html__( 'CONNECT TO ACTIVATE', 'yablonsky-seo' ); ?></span><?php endif; ?>
                        </div>

                        <div class="yps-ai-setup-steps" aria-label="<?php echo esc_attr__( 'Yablonsky AI connection steps', 'yablonsky-seo' ); ?>">
                            <div class="yps-ai-step <?php echo $aiSettings->isConfigured() ? 'is-complete' : 'is-current'; ?>"><b>1</b><div><strong><?php echo esc_html__( 'Connect account', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Save your User API key.', 'yablonsky-seo' ); ?></span></div></div>
                            <div class="yps-ai-step <?php echo $aiSettings->lastVerifiedAt() > 0 ? 'is-complete' : ( $aiSettings->isConfigured() ? 'is-current' : '' ); ?>"><b>2</b><div><strong><?php echo esc_html__( 'Verify access', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Load workspace, credits and models.', 'yablonsky-seo' ); ?></span></div></div>
                            <div class="yps-ai-step <?php echo $aiSettings->lastVerifiedAt() > 0 ? 'is-current' : ''; ?>"><b>3</b><div><strong><?php echo esc_html__( 'Generate', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Use AI in editors or bounded jobs.', 'yablonsky-seo' ); ?></span></div></div>
                        </div>

                        <section class="yps-setting-card yps-setting-card-wide" id="yps-ai-connection-card">
                            <div class="yps-ai-connect-grid">
                                <div>
                                    <span class="yps-card-kicker"><?php echo esc_html__( 'Connection', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Yablonsky AI account', 'yablonsky-seo' ); ?></h2>
                                    <p><?php echo esc_html__( 'The API key authenticates optional paid remote compute. Local SEO, AEO, GEO, schema and migration features remain available independently.', 'yablonsky-seo' ); ?></p>
                                    <div class="yps-setting-field"><label for="yseo-ai-api-key"><?php echo esc_html__( 'User API Key', 'yablonsky-seo' ); ?></label><input type="password" id="yseo-ai-api-key" name="yseo_ai_api_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $aiSettings->maskedKey() ? $aiSettings->maskedKey() . ' · leave blank to keep' : __( 'Paste User API key', 'yablonsky-seo' ) ); ?>" /><small><?php echo esc_html__( 'Encrypted server-side. Save a new or changed key before testing the connection.', 'yablonsky-seo' ); ?></small></div>
                                    <div class="yps-actions yps-ai-connection-actions"><button type="button" class="button button-secondary" id="yseo-ai-test-connection" <?php disabled( ! $aiSettings->isConfigured() ); ?>><?php echo esc_html__( 'Test / refresh connection', 'yablonsky-seo' ); ?></button><button type="button" class="button" id="yseo-ai-test-compute" <?php disabled( ! $aiSettings->isConfigured() ); ?>><?php echo esc_html__( 'Run Writer compute canary', 'yablonsky-seo' ); ?></button><?php if ( $aiSettings->isConfigured() ) : ?><label class="yps-inline-check"><input type="checkbox" name="yseo_ai_disconnect" value="1" /> <?php echo esc_html__( 'Disconnect on Save', 'yablonsky-seo' ); ?></label><?php endif; ?></div>
                                    <div class="yps-status yps-ai-connection-status" id="yseo-ai-connection-status" aria-live="polite"><?php echo esc_html( $aiSettings->isConfigured() ? ( $aiSettings->lastVerifiedAt() > 0 ? __( 'Connection evidence is cached locally. Testing is performed only when you explicitly request it.', 'yablonsky-seo' ) : __( 'API key is stored. Run Test / refresh connection to verify account access.', 'yablonsky-seo' ) ) : __( 'Paste a key, save settings, then return here to test the connection. Saving stays local; remote verification runs only when you test.', 'yablonsky-seo' ) ); ?></div>
                                </div>
                                <aside class="yps-ai-privacy-card"><span class="dashicons dashicons-lock"></span><div><strong><?php echo esc_html__( 'Local-first by default', 'yablonsky-seo' ); ?></strong><p><?php echo esc_html__( 'Activation, dashboard load, frontend rendering, sitemap generation, migration mirror and local content analysis stay local.', 'yablonsky-seo' ); ?></p><div class="yps-chip-row"><span class="yps-chip">SEO</span><span class="yps-chip">AEO</span><span class="yps-chip">GEO</span><span class="yps-chip">Schema</span></div></div></aside>
                            </div>

                            <?php if ( $aiSettings->lastVerifiedAt() > 0 ) : ?>
                                <div class="yps-ai-account-summary"><strong><?php echo esc_html__( 'Verified account', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html( trim( (string) ( $aiSettings->cachedAccount()['first_name'] ?? '' ) . ' ' . (string) ( $aiSettings->cachedAccount()['last_name'] ?? '' ) ) ?: (string) ( $aiSettings->cachedAccount()['email'] ?? '' ) ); ?></span><span><?php echo esc_html( (string) ( $aiUsage['workspace_name'] ?? '' ) ); ?></span><?php if ( '' !== (string) ( $aiUsage['plan_title'] ?? '' ) ) : ?><span><?php echo esc_html( (string) $aiUsage['plan_title'] ); ?></span><?php endif; ?><?php if ( '' !== $aiCreditLabel ) : ?><span><?php echo esc_html( $aiCreditLabel ); ?></span><?php endif; ?><?php if ( '' !== $aiUsageLabel ) : ?><span><?php echo esc_html( $aiUsageLabel ); ?></span><?php endif; ?><?php if ( '' !== $aiUsageModeLabel ) : ?><span><?php echo esc_html( $aiUsageModeLabel ); ?></span><?php endif; ?><?php if ( '' !== $aiResetLabel ) : ?><span><?php echo esc_html( $aiResetLabel ); ?></span><?php endif; ?><span><?php echo esc_html( $aiModelCountLabel ); ?></span></div>
                                <div class="yps-ai-preference-panel">
                                    <div class="yps-ai-preference-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Generation preferences', 'yablonsky-seo' ); ?></span><h3><?php echo esc_html__( 'Choose context, models and generation behavior', 'yablonsky-seo' ); ?></h3><p><?php echo esc_html__( 'These preferences affect only explicit Yablonsky AI generation. Local SEO, AEO and GEO analysis remains unchanged.', 'yablonsky-seo' ); ?></p></div><span class="yps-mini-status neutral"><?php echo esc_html__( 'ACCOUNT-SCOPED', 'yablonsky-seo' ); ?></span></div>
                                    <div class="yps-ai-preference-grid">
                                        <div class="yps-setting-field yps-ai-pref-workspace">
                                            <label for="yseo-ai-workspace"><?php echo esc_html__( 'Workspace', 'yablonsky-seo' ); ?></label>
                                            <select id="yseo-ai-workspace" name="yseo_ai_workspace_id">
                                                <option value=""><?php echo esc_html__( 'Current workspace · Auto', 'yablonsky-seo' ); ?></option>
                                                <?php foreach ( $aiWorkspaces as $workspace ) : ?>
                                                    <?php
                                                    $wid = (string) ( $workspace['id'] ?? '' );
                                                    if ( '' === $wid ) { continue; }
                                                    $wsub = isset( $workspace['subscription'] ) && is_array( $workspace['subscription'] ) ? $workspace['subscription'] : array();
                                                    $wplan = isset( $wsub['plan']['title'] ) ? (string) $wsub['plan']['title'] : '';
                                                    $wcredits = $workspace['credit_count'] ?? ( $wsub['credit_count'] ?? null );
                                                    $wlabel = (string) ( $workspace['name'] ?? $wid );
                                                    if ( '' !== $wplan ) { $wlabel .= ' · ' . $wplan; }
                                                    if ( null !== $wcredits && is_numeric( $wcredits ) ) {
                                                        /* translators: %s: remaining credits for a selectable Yablonsky AI workspace. */
                                                        $wlabel .= ' · ' . sprintf( __( 'Credits %s', 'yablonsky-seo' ), (string) (float) $wcredits );
                                                    }
                                                    ?>
                                                    <option value="<?php echo esc_attr( $wid ); ?>" <?php selected( $aiSettings->workspaceId(), $wid ); ?>><?php echo esc_html( $wlabel ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small><?php echo esc_html__( 'Only workspaces returned for this account are available.', 'yablonsky-seo' ); ?></small>
                                        </div>
                                        <div class="yps-setting-field yps-ai-pref-reasoning"><label for="yseo-ai-reasoning"><?php echo esc_html__( 'Reasoning effort', 'yablonsky-seo' ); ?></label><select id="yseo-ai-reasoning" name="yseo_ai_reasoning"><option value="auto" <?php selected( $aiSettings->reasoning(), 'auto' ); ?>><?php echo esc_html__( 'Auto', 'yablonsky-seo' ); ?></option><option value="minimal" <?php selected( $aiSettings->reasoning(), 'minimal' ); ?>><?php echo esc_html__( 'Minimal', 'yablonsky-seo' ); ?></option><option value="low" <?php selected( $aiSettings->reasoning(), 'low' ); ?>><?php echo esc_html__( 'Low', 'yablonsky-seo' ); ?></option><option value="medium" <?php selected( $aiSettings->reasoning(), 'medium' ); ?>><?php echo esc_html__( 'Medium', 'yablonsky-seo' ); ?></option><option value="high" <?php selected( $aiSettings->reasoning(), 'high' ); ?>><?php echo esc_html__( 'High', 'yablonsky-seo' ); ?></option><option value="xhigh" <?php selected( $aiSettings->reasoning(), 'xhigh' ); ?>><?php echo esc_html__( 'Extra high', 'yablonsky-seo' ); ?></option><option value="max" <?php selected( $aiSettings->reasoning(), 'max' ); ?>><?php echo esc_html__( 'Max', 'yablonsky-seo' ); ?></option></select><small><?php echo esc_html__( 'Availability depends on the selected text model. Auto lets the service choose.', 'yablonsky-seo' ); ?></small></div>
                                        <div class="yps-setting-field yps-ai-pref-text"><label for="yseo-ai-model"><?php echo esc_html__( 'Text model', 'yablonsky-seo' ); ?></label><?php $this->renderAiModelPicker( 'yseo-ai-model', 'yseo_ai_model_id', $aiModels, $aiSettings->modelId(), __( 'Filter text models…', 'yablonsky-seo' ), __( 'Filter text models', 'yablonsky-seo' ) ); ?><small><?php echo esc_html__( 'Used for content planning, summaries and optimization guidance. Auto uses the workspace Writer model.', 'yablonsky-seo' ); ?></small></div>
                                        <div class="yps-setting-field yps-ai-pref-metadata"><label for="yseo-ai-metadata-model"><?php echo esc_html__( 'SEO metadata model', 'yablonsky-seo' ); ?></label><?php $this->renderAiModelPicker( 'yseo-ai-metadata-model', 'yseo_ai_metadata_model_id', $aiModels, $aiSettings->metadataModelOverride(), __( 'Filter metadata models…', 'yablonsky-seo' ), __( 'Filter metadata models', 'yablonsky-seo' ), array( array( 'id' => '', 'label' => __( 'Use text model', 'yablonsky-seo' ), 'detail' => __( 'Text model', 'yablonsky-seo' ) ) ) ); ?><small><?php echo esc_html__( 'Used only for keyphrases, SEO titles, meta descriptions and site-setup drafts. Choose a smaller capable model here to reduce routine metadata compute cost while keeping a stronger model for planning and long-form assistance.', 'yablonsky-seo' ); ?></small></div>
                                        <div class="yps-setting-field yps-ai-pref-image"><label for="yseo-ai-image-model"><?php echo esc_html__( 'Image model', 'yablonsky-seo' ); ?></label><?php $this->renderAiModelPicker( 'yseo-ai-image-model', 'yseo_ai_image_model_id', $aiImageModels, $aiSettings->imageModelId(), __( 'Filter image models…', 'yablonsky-seo' ), __( 'Filter image models', 'yablonsky-seo' ), array( array( 'id' => '', 'label' => __( 'Auto · first available image model', 'yablonsky-seo' ), 'detail' => __( 'Auto', 'yablonsky-seo' ) ) ) ); ?><small><?php echo esc_html__( 'Used only for an explicitly generated preferred Search/Social image. Text-generation jobs use their own inputs.', 'yablonsky-seo' ); ?></small></div>
                                        <div class="yps-setting-field yps-ai-pref-creativity"><div class="yps-range-label"><label for="yseo-ai-creativity"><?php echo esc_html__( 'Creativity', 'yablonsky-seo' ); ?></label><output id="yseo-ai-creativity-value" for="yseo-ai-creativity"><?php echo esc_html( number_format( $aiSettings->creativity(), 1, '.', '' ) ); ?></output></div><input type="range" min="0" max="2" step="0.1" id="yseo-ai-creativity" name="yseo_ai_creativity" value="<?php echo esc_attr( number_format( $aiSettings->creativity(), 1, '.', '' ) ); ?>" /><div class="yps-range-scale"><span><?php echo esc_html__( 'Precise', 'yablonsky-seo' ); ?></span><span><?php echo esc_html__( 'Balanced', 'yablonsky-seo' ); ?></span><span><?php echo esc_html__( 'Creative', 'yablonsky-seo' ); ?></span></div><small><?php echo esc_html__( 'Auto models keep their native temperature. With a manual model, this value becomes an explicit generation target.', 'yablonsky-seo' ); ?></small></div>
                                    </div>
                                </div>
                            <?php elseif ( $aiSettings->isConfigured() ) : ?>
                                <div class="yps-settings-callout"><span class="dashicons dashicons-info-outline"></span><div><strong><?php echo esc_html__( 'One verification step remains', 'yablonsky-seo' ); ?></strong><p><?php echo esc_html__( 'Test the saved key to load the permitted workspace, plan, credits and selectable models. Model controls stay hidden until verification succeeds.', 'yablonsky-seo' ); ?></p></div></div>
                            <?php endif; ?>
                        </section>
                    </section>

                </div>

                <div class="yps-setting-actions yps-setting-actions-sticky" data-yps-settings-save-actions><div><strong><?php echo esc_html__( 'Save settings', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Saving is local. IndexNow runs later only after content changes when enabled; remote AI requests still require an explicit test or generation action.', 'yablonsky-seo' ); ?></span></div><?php submit_button( __( 'Save changes', 'yablonsky-seo' ), 'primary', 'submit', false ); ?></div>
            </form>

            <section class="yps-settings-panel yps-settings-portability" id="yps-settings-panel-portability" data-yps-settings-panel="portability" hidden>
                <div class="yps-settings-panel-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Settings portability', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Export / import site configuration', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Move a reviewed Yablonsky SEO setup between sites while preserving reviewed settings.', 'yablonsky-seo' ); ?></p></div></div>
                <?php if ( 'imported' === $portabilityStatus ) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php /* translators: %d: number of portable configuration values changed by the import. */ echo esc_html( sprintf( __( 'Portable settings imported. %d values changed.', 'yablonsky-seo' ), $portabilityChanged ) ); ?></p></div>
                <?php elseif ( 'restored' === $portabilityStatus ) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Previous portable settings restored.', 'yablonsky-seo' ); ?></p></div>
                <?php elseif ( 'integrity-mismatch' === $portabilityStatus ) : ?>
                    <div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'Settings import stopped because the JSON integrity hash does not match its payload.', 'yablonsky-seo' ); ?></p></div>
                <?php elseif ( in_array( $portabilityStatus, array( 'invalid', 'invalid-size', 'invalid-schema', 'missing-payload' ), true ) ) : ?>
                    <div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'Settings import stopped safely because the file is invalid, incompatible or too large.', 'yablonsky-seo' ); ?></p></div>
                <?php elseif ( in_array( $portabilityStatus, array( 'write-failed', 'restore-failed' ), true ) ) : ?>
                    <div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'The operation stopped safely.', 'yablonsky-seo' ); ?></p></div>
                <?php elseif ( 'no-backup' === $portabilityStatus ) : ?>
                    <div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'Settings-import rollback snapshot ready after the first import snapshot is created.', 'yablonsky-seo' ); ?></p></div>
                <?php endif; ?>
                <div class="yps-section-grid yps-portability-grid">
                    <article class="yps-card">
                        <span class="yps-card-kicker"><?php echo esc_html__( 'Portable scope', 'yablonsky-seo' ); ?></span>
                        <h2><?php echo esc_html__( 'Export reviewed global settings', 'yablonsky-seo' ); ?></h2>
                        <p class="yps-muted"><?php echo esc_html__( 'The JSON contains global SEO, AEO, GEO and crawl settings, the native Search Performance signal toggle, and non-secret AI generation preferences.', 'yablonsky-seo' ); ?></p>
                        <p class="yps-form-note"><strong><?php echo esc_html__( 'Excluded for safety:', 'yablonsky-seo' ); ?></strong> <?php echo esc_html__( 'API keys, output authority, migration state, content metadata, redirects, 404 evidence, account/workspace bindings and background jobs stay local and are excluded from exports.', 'yablonsky-seo' ); ?></p>
                        <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yseo_settings_export' ), 'yseo_settings_export' ) ); ?>"><?php echo esc_html__( 'Export settings JSON', 'yablonsky-seo' ); ?></a>
                    </article>
                    <article class="yps-card">
                        <span class="yps-card-kicker"><?php echo esc_html__( 'Import configuration', 'yablonsky-seo' ); ?></span>
                        <h2><?php echo esc_html__( 'Apply one reviewed setup', 'yablonsky-seo' ); ?></h2>
                        <p class="yps-muted"><?php echo esc_html__( 'Choose a Yablonsky SEO settings JSON file. Before applying portable fields, YSEO stores one local rollback snapshot of the current configuration.', 'yablonsky-seo' ); ?></p>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-yseo-settings-import>
                            <input type="hidden" name="action" value="yseo_settings_import" />
                            <?php wp_nonce_field( 'yseo_settings_import' ); ?>
                            <label class="yps-setting-field" for="yseo-settings-import-file"><span><?php echo esc_html__( 'Settings JSON file', 'yablonsky-seo' ); ?></span><input type="file" id="yseo-settings-import-file" accept="application/json,.json" data-yseo-settings-import-file /></label>
                            <textarea name="yseo_settings_json" data-yseo-settings-import-json hidden></textarea>
                            <p class="yps-form-note" data-yseo-settings-import-name></p>
                            <button type="submit" class="button button-primary"><?php echo esc_html__( 'Import settings', 'yablonsky-seo' ); ?></button>
                        </form>
                        <?php if ( $portability->hasBackup() ) : ?>
                            <div class="yps-portability-rollback">
                                <p class="yps-form-note"><?php echo esc_html__( 'A rollback snapshot is available from the most recent settings import.', 'yablonsky-seo' ); ?><?php if ( ! empty( $portabilityBackup['created_at_utc'] ) ) : ?> <code><?php echo esc_html( (string) $portabilityBackup['created_at_utc'] ); ?></code><?php endif; ?></p>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="yseo_settings_restore" />
                                    <?php wp_nonce_field( 'yseo_settings_restore' ); ?>
                                    <button type="submit" class="button"><?php echo esc_html__( 'Restore previous settings', 'yablonsky-seo' ); ?></button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </article>
                </div>
            </section>

            <section class="yps-settings-panel yps-developer-panel" id="yps-settings-panel-developer" data-yps-settings-panel="developer" hidden>
                <?php $headExample = rest_url( 'yablonsky-seo/v1/head?url=' . rawurlencode( home_url( '/' ) ) ); $capabilitiesUrl = rest_url( 'yablonsky-seo/v1/capabilities' ); $surfaceExample = rest_url( 'yablonsky-seo/v1/surface?type=home' ); $healthUrl = rest_url( 'yablonsky-seo/v1/health' ); $contractUrl = rest_url( 'yablonsky-seo/v1/contract' ); $machinePolicyUrl = rest_url( 'yablonsky-seo/v1/machine-policy' ); $apiFingerprint = DeveloperApiContract::fingerprint(); $contractFileUrl = plugins_url( 'docs/developer-api-contract-v1.json', YSEO_FILE ); $contractPreview = wp_json_encode( DeveloperApiContract::manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?>
                <div class="yps-settings-panel-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Developer', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Developer API surface', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Read SEO metadata headlessly through public read-only REST, PHP Surfaces and versioned extension hooks. Public metadata reads use credential-free access.', 'yablonsky-seo' ); ?></p></div><span class="yps-mini-status good"><?php echo esc_html__( 'READ-ONLY · V1', 'yablonsky-seo' ); ?></span></div>
                <div class="yps-developer-status-strip"><span><strong><?php echo esc_html__( 'REST by URL', 'yablonsky-seo' ); ?></strong> <?php echo esc_html__( 'Ready', 'yablonsky-seo' ); ?></span><span><strong><?php echo esc_html__( 'Typed resources', 'yablonsky-seo' ); ?></strong> <?php echo esc_html__( 'Ready', 'yablonsky-seo' ); ?></span><span><strong><?php echo esc_html__( 'PHP Surfaces', 'yablonsky-seo' ); ?></strong> <?php echo esc_html__( 'Ready', 'yablonsky-seo' ); ?></span><span><strong><?php echo esc_html__( 'Authentication', 'yablonsky-seo' ); ?></strong> <?php echo esc_html__( 'Public reads use credential-free access', 'yablonsky-seo' ); ?></span><span><strong><?php echo esc_html__( 'Writes', 'yablonsky-seo' ); ?></strong> <?php echo esc_html__( 'Private', 'yablonsky-seo' ); ?></span><span><strong><?php echo esc_html__( 'Contract', 'yablonsky-seo' ); ?></strong> <code>v1 · <?php echo esc_html( substr( $apiFingerprint, 0, 12 ) ); ?></code></span><a class="button" href="<?php echo esc_url( $contractFileUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open contract JSON', 'yablonsky-seo' ); ?></a></div>
                <div class="yps-developer-endpoint-strip"><a href="<?php echo esc_url( $headExample ); ?>" target="_blank" rel="noopener noreferrer"><code>/head</code></a><a href="<?php echo esc_url( $surfaceExample ); ?>" target="_blank" rel="noopener noreferrer"><code>/surface</code></a><a href="<?php echo esc_url( $capabilitiesUrl ); ?>" target="_blank" rel="noopener noreferrer"><code>/capabilities</code></a><a href="<?php echo esc_url( $healthUrl ); ?>" target="_blank" rel="noopener noreferrer"><code>/health</code></a><a href="<?php echo esc_url( $contractUrl ); ?>" target="_blank" rel="noopener noreferrer"><code>/contract</code></a><a href="<?php echo esc_url( $machinePolicyUrl ); ?>" target="_blank" rel="noopener noreferrer"><code>/machine-policy</code></a></div>
                <div class="yps-section-grid">
                    <article class="yps-card yps-card-wide"><div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Headless SEO by URL', 'yablonsky-seo' ); ?></span><h2><code>/wp-json/yablonsky-seo/v1/head</code></h2></div><div class="yps-inline-actions"><button type="button" class="button" data-yseo-copy-value="<?php echo esc_attr( $headExample ); ?>"><?php echo esc_html__( 'Copy example', 'yablonsky-seo' ); ?></button><a class="button button-primary" href="<?php echo esc_url( $headExample ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open JSON', 'yablonsky-seo' ); ?></a></div></div><p class="yps-muted"><?php echo esc_html__( 'Returns status, prefabricated head markup and rich JSON metadata for a same-origin URL that YSEO can resolve directly.', 'yablonsky-seo' ); ?></p><pre class="yps-api-example"><code><?php echo esc_html( $headExample ); ?></code></pre></article>
                    <article class="yps-card"><span class="yps-card-kicker"><?php echo esc_html__( 'Typed REST resources', 'yablonsky-seo' ); ?></span><h2><code>/surface</code></h2><p class="yps-muted"><?php echo esc_html__( 'Use typed resources for homepage, posts page, posts/products, terms, authors and post-type archives with explicit resource identity.', 'yablonsky-seo' ); ?></p><div class="yps-inline-actions"><button type="button" class="button" data-yseo-copy-value="<?php echo esc_attr( $surfaceExample ); ?>"><?php echo esc_html__( 'Copy homepage example', 'yablonsky-seo' ); ?></button><a href="<?php echo esc_url( $surfaceExample ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open JSON', 'yablonsky-seo' ); ?></a></div></article>
                    <article class="yps-card"><span class="yps-card-kicker"><?php echo esc_html__( 'WordPress REST fields', 'yablonsky-seo' ); ?></span><h2><code>yseo_head</code> · <code>yseo_head_json</code></h2><p class="yps-muted"><?php echo esc_html__( 'Public REST-enabled post types expose prefabricated head markup and structured metadata directly in native WordPress REST responses.', 'yablonsky-seo' ); ?></p></article>
                    <article class="yps-card"><span class="yps-card-kicker"><?php echo esc_html__( 'PHP Surfaces API', 'yablonsky-seo' ); ?></span><h2><code>yablonsky_seo()->meta</code></h2><p class="yps-muted"><?php echo esc_html__( 'Themes and plugins can request the current page, post, URL, term, author, homepage or post-type archive metadata through the typed metadata surface, keeping rendered HTML parsing unnecessary.', 'yablonsky-seo' ); ?></p><pre class="yps-api-example"><code>$meta = yablonsky_seo()->meta->for_current_page();
echo esc_html( $meta ? $meta->title : '' );</code></pre></article>
                    <article class="yps-card"><span class="yps-card-kicker"><?php echo esc_html__( 'API discovery', 'yablonsky-seo' ); ?></span><h2><code>/capabilities</code></h2><p class="yps-muted"><?php echo esc_html__( 'Machine-readable endpoint and extension-contract inventory for integrations and deployment checks.', 'yablonsky-seo' ); ?></p><div class="yps-inline-actions"><button type="button" class="button" data-yseo-copy-value="<?php echo esc_attr( $capabilitiesUrl ); ?>"><?php echo esc_html__( 'Copy endpoint', 'yablonsky-seo' ); ?></button><a href="<?php echo esc_url( $capabilitiesUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open capabilities', 'yablonsky-seo' ); ?></a></div></article>
                    <article class="yps-card"><span class="yps-card-kicker"><?php echo esc_html__( 'Schema aggregation', 'yablonsky-seo' ); ?></span><h2><code>/schemamap.xml</code></h2><p class="yps-muted"><?php echo esc_html__( 'A cacheable XML discovery map points machines to bounded JSON-LD aggregation endpoints for each public post type. Canonical HTML remains the page-level authority.', 'yablonsky-seo' ); ?></p><a href="<?php echo esc_url( home_url( '/schemamap.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open SchemaMap', 'yablonsky-seo' ); ?></a></article>
                    <article class="yps-card yps-card-wide yps-developer-contract-preview"><div class="yps-card-head"><div><span class="yps-card-kicker">developer-api-contract-v1.json</span><h2><code>v1 · <?php echo esc_html( substr( $apiFingerprint, 0, 12 ) ); ?></code></h2></div><div class="yps-inline-actions"><button type="button" class="button" data-yseo-copy-value="<?php echo esc_attr( is_string( $contractPreview ) ? $contractPreview : '' ); ?>"><?php echo esc_html__( 'Copy example', 'yablonsky-seo' ); ?></button><a class="button" href="<?php echo esc_url( $contractUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open contract JSON', 'yablonsky-seo' ); ?></a></div></div><details><summary><code>{ … }</code></summary><pre class="yps-api-example"><code><?php echo esc_html( is_string( $contractPreview ) ? $contractPreview : '' ); ?></code></pre></details></article>
                    <article class="yps-card yps-card-wide"><span class="yps-card-kicker"><?php echo esc_html__( 'Versioned hooks', 'yablonsky-seo' ); ?></span><h2><code>yablonsky_seo_*_v1</code></h2><div class="yps-chip-row"><span class="yps-chip"><code>yablonsky_seo_document_v1</code></span><span class="yps-chip"><code>yablonsky_seo_head_projection_v1</code></span><span class="yps-chip"><code>yablonsky_seo_schema_graph_v1</code></span><span class="yps-chip"><code>yablonsky_seo_social_template_value_v1</code></span><span class="yps-chip"><code>yablonsky_seo_analysis_content_v1</code></span><span class="yps-chip"><code>yablonsky_seo_breadcrumb_items_v1</code></span><span class="yps-chip"><code>yablonsky_seo_sitemap_images_v1</code></span><span class="yps-chip"><code>yablonsky_seo_resource_language_v1</code></span><span class="yps-chip"><code>yablonsky_seo_multilingual_provider_v1</code></span><span class="yps-chip"><code>yablonsky_seo_crawler_permission_policy_v1</code></span></div><p class="yps-muted"><?php echo esc_html__( 'Bundled developer docs cover REST, PHP Surfaces, metadata projection, schema, social templates, language, analysis adapters and sitemap examples.', 'yablonsky-seo' ); ?></p></article>
                </div>
            </section>
        </div>
        <?php
    }

    public function saveSettings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Changing these settings requires the corresponding WordPress capability.', 'yablonsky-seo' ) );
        }
        check_admin_referer( self::SETTINGS_NONCE );

        $providers = $this->detector->detectActiveProviders();
        $rawAuthority = filter_input( INPUT_POST, 'output_authority', FILTER_UNSAFE_RAW );
        $requestedAuthority = is_string( $rawAuthority ) ? sanitize_key( $rawAuthority ) : 'shadow';
        $authority = empty( $providers ) && 'active' === $requestedAuthority ? 'active' : 'shadow';
        update_option( 'yps_output_authority', $authority, false );

        $agentPolicy = $this->postedPolicy( 'ai_agent_access', 'allow' );
        $answersPolicy = $this->postedPolicy( 'ai_answers_access', 'inherit' );

        $settings = array(
            'site_display_name' => $this->postedText( 'site_display_name' ),
            'site_description'  => $this->postedTextarea( 'site_description' ),
            'owner_type'        => 'person' === $this->postedKey( 'owner_type' ) ? 'person' : 'organization',
            'owner_name'        => $this->postedText( 'owner_name' ),
            'owner_description' => $this->postedTextarea( 'owner_description' ),
            'owner_logo_url'    => esc_url_raw( $this->postedText( 'owner_logo_url' ), array( 'http', 'https' ) ),
            'owner_image_url'   => esc_url_raw( $this->postedText( 'owner_image_url' ), array( 'http', 'https' ) ),
            'owner_job_title'   => $this->postedText( 'owner_job_title' ),
            'owner_same_as'     => $this->postedTextarea( 'owner_same_as' ),
            'owner_knows_about' => $this->postedTextarea( 'owner_knows_about' ),
            'merchant_return_policy_url' => esc_url_raw( $this->postedText( 'merchant_return_policy_url' ), array( 'http', 'https' ) ),
            'verification_google' => $this->postedVerification( 'verification_google' ),
            'verification_bing' => $this->postedVerification( 'verification_bing' ),
            'verification_pinterest' => $this->postedVerification( 'verification_pinterest' ),
            'verification_yandex' => $this->postedVerification( 'verification_yandex' ),
            'verification_baidu' => $this->postedVerification( 'verification_baidu' ),
            'home_title'        => $this->postedText( 'home_title' ),
            'home_description'  => $this->postedTextarea( 'home_description' ),
            'home_search_image' => esc_url_raw( $this->postedText( 'home_search_image' ), array( 'http', 'https' ) ),
            'default_social_image' => esc_url_raw( $this->postedText( 'default_social_image' ), array( 'http', 'https' ) ),
            'home_og_title' => $this->postedText( 'home_og_title' ),
            'home_og_description' => $this->postedTextarea( 'home_og_description' ),
            'home_og_image' => esc_url_raw( $this->postedText( 'home_og_image' ), array( 'http', 'https' ) ),
            'home_twitter_title' => $this->postedText( 'home_twitter_title' ),
            'home_twitter_description' => $this->postedTextarea( 'home_twitter_description' ),
            'home_twitter_image' => esc_url_raw( $this->postedText( 'home_twitter_image' ), array( 'http', 'https' ) ),
            'aeo_analysis_profile' => in_array( $this->postedKey( 'aeo_analysis_profile' ), array( 'balanced', 'concise', 'extended' ), true ) ? $this->postedKey( 'aeo_analysis_profile' ) : AeoPolicy::DEFAULT_PROFILE,
            'aeo_question_heading_guidance' => null !== filter_input( INPUT_POST, 'aeo_question_heading_guidance', FILTER_UNSAFE_RAW ) ? '1' : '',
            'aeo_compact_structure_guidance' => null !== filter_input( INPUT_POST, 'aeo_compact_structure_guidance', FILTER_UNSAFE_RAW ) ? '1' : '',
            'aeo_faq_guardrail' => null !== filter_input( INPUT_POST, 'aeo_faq_guardrail', FILTER_UNSAFE_RAW ) ? '1' : '',
            'ai_crawler_policy_enabled' => null !== filter_input( INPUT_POST, 'ai_crawler_policy_enabled', FILTER_UNSAFE_RAW ) ? '1' : '',
            'ai_search_access'  => $this->postedPolicy( 'ai_search_access', 'allow' ),
            'ai_answers_access' => $answersPolicy,
            'ai_agent_access'   => $agentPolicy,
            'ai_retrieval_access' => $agentPolicy, // Backward-compatible alias for pre-4.69 settings and integrations.
            'ai_training_access'=> $this->postedPolicy( 'ai_training_access', 'allow' ),
            'ai_ads_access' => $this->postedPolicy( 'ai_ads_access', 'inherit' ),
            'agent_markdown_enabled' => null !== filter_input( INPUT_POST, 'agent_markdown_enabled', FILTER_UNSAFE_RAW ) ? '1' : '',
            'agent_scoped_llms_enabled' => null !== filter_input( INPUT_POST, 'agent_scoped_llms_enabled', FILTER_UNSAFE_RAW ) ? '1' : '',
            'agent_sitemap_extension_enabled' => null !== filter_input( INPUT_POST, 'agent_sitemap_extension_enabled', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_remove_shortlinks' => null !== filter_input( INPUT_POST, 'crawl_remove_shortlinks', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_remove_rest_links' => null !== filter_input( INPUT_POST, 'crawl_remove_rest_links', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_remove_rsd_wlw' => null !== filter_input( INPUT_POST, 'crawl_remove_rsd_wlw', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_remove_oembed_links' => null !== filter_input( INPUT_POST, 'crawl_remove_oembed_links', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_remove_generator' => null !== filter_input( INPUT_POST, 'crawl_remove_generator', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_remove_pingback_header' => null !== filter_input( INPUT_POST, 'crawl_remove_pingback_header', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_remove_powered_by_header' => null !== filter_input( INPUT_POST, 'crawl_remove_powered_by_header', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_remove_emoji' => null !== filter_input( INPUT_POST, 'crawl_remove_emoji', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_disable_global_feeds' => null !== filter_input( INPUT_POST, 'crawl_disable_global_feeds', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_disable_comments_feeds' => null !== filter_input( INPUT_POST, 'crawl_disable_comments_feeds', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_disable_post_comments_feeds' => null !== filter_input( INPUT_POST, 'crawl_disable_post_comments_feeds', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_disable_author_feeds' => null !== filter_input( INPUT_POST, 'crawl_disable_author_feeds', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_disable_post_type_feeds' => null !== filter_input( INPUT_POST, 'crawl_disable_post_type_feeds', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_disable_category_feeds' => null !== filter_input( INPUT_POST, 'crawl_disable_category_feeds', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_disable_tag_feeds' => null !== filter_input( INPUT_POST, 'crawl_disable_tag_feeds', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_disable_taxonomy_feeds' => null !== filter_input( INPUT_POST, 'crawl_disable_taxonomy_feeds', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_disable_search_feeds' => null !== filter_input( INPUT_POST, 'crawl_disable_search_feeds', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_disable_atom_rdf_feeds' => null !== filter_input( INPUT_POST, 'crawl_disable_atom_rdf_feeds', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_disallow_wp_json' => null !== filter_input( INPUT_POST, 'crawl_disallow_wp_json', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_search_noindex' => null !== filter_input( INPUT_POST, 'crawl_search_noindex', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_search_redirect_pretty' => null !== filter_input( INPUT_POST, 'crawl_search_redirect_pretty', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_search_filter_special' => null !== filter_input( INPUT_POST, 'crawl_search_filter_special', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_search_filter_spam' => null !== filter_input( INPUT_POST, 'crawl_search_filter_spam', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_clean_tracking_params' => null !== filter_input( INPUT_POST, 'crawl_clean_tracking_params', FILTER_UNSAFE_RAW ) ? '1' : '',
            'crawl_search_max_chars' => (string) min( 200, max( 20, (int) filter_input( INPUT_POST, 'crawl_search_max_chars', FILTER_VALIDATE_INT ) ) ),
            'search_title_template' => $this->postedText( 'search_title_template' ),
            'error_404_title_template' => $this->postedText( 'error_404_title_template' ),
            'date_archive_title_template' => $this->postedText( 'date_archive_title_template' ),
            'post_format_archive_title_template' => $this->postedText( 'post_format_archive_title_template' ),
            'date_archives_noindex' => null !== filter_input( INPUT_POST, 'date_archives_noindex', FILTER_UNSAFE_RAW ) ? '1' : '',
            'post_format_archives_noindex' => null !== filter_input( INPUT_POST, 'post_format_archives_noindex', FILTER_UNSAFE_RAW ) ? '1' : '',
            'media_redirect_attachment_pages' => null !== filter_input( INPUT_POST, 'media_redirect_attachment_pages', FILTER_UNSAFE_RAW ) ? '1' : '',
            'media_redirect_target' => 'parent' === $this->postedKey( 'media_redirect_target' ) ? 'parent' : 'file',
            'rss_before_content' => $this->postedRssTemplate( 'rss_before_content' ),
            'rss_after_content' => $this->postedRssTemplate( 'rss_after_content' ),
            'indexnow_enabled' => null !== filter_input( INPUT_POST, 'indexnow_enabled', FILTER_UNSAFE_RAW ) ? '1' : '',
        );
        $rawSocialTemplates = filter_input( INPUT_POST, 'social_templates', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY );
        $settings['social_templates'] = is_array( $rawSocialTemplates ) ? SocialTemplateResolver::sanitize( $rawSocialTemplates ) : array();
        update_option( self::SETTINGS_OPTION, $settings, false );

        $aiSettings = new AiSettings();
        $disconnect = null !== filter_input( INPUT_POST, 'yseo_ai_disconnect', FILTER_UNSAFE_RAW );
        if ( $disconnect ) {
            $aiSettings->disconnect();
        } else {
            $rawApiKey = filter_input( INPUT_POST, 'yseo_ai_api_key', FILTER_UNSAFE_RAW );
            if ( is_string( $rawApiKey ) && '' !== trim( $rawApiKey ) ) {
                try { $aiSettings->saveApiKey( sanitize_text_field( trim( $rawApiKey ) ) ); }
                catch ( ServiceError $error ) { wp_die( esc_html( $error->getMessage() ) ); }
            }
            $rawCreativity = filter_input( INPUT_POST, 'yseo_ai_creativity', FILTER_UNSAFE_RAW );
            $aiSettings->savePreferences(
                array(
                    'workspace_id' => $this->postedText( 'yseo_ai_workspace_id' ),
                    'model_id' => $this->postedText( 'yseo_ai_model_id' ),
                    'metadata_model_id' => $this->postedText( 'yseo_ai_metadata_model_id' ),
                    'image_model_id' => $this->postedText( 'yseo_ai_image_model_id' ),
                    'reasoning' => $this->postedKey( 'yseo_ai_reasoning' ),
                    'creativity' => is_scalar( $rawCreativity ) ? (float) $rawCreativity : 0.4,
                )
            );
        }
        $settingsTab = $this->postedKey( 'yps_settings_tab' );
        if ( ! in_array( $settingsTab, array( 'general', 'search', 'social', 'answer', 'crawlers', 'ai', 'portability', 'developer' ), true ) ) {
            $settingsTab = 'general';
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE . '&updated=1' ) . '#' . rawurlencode( $settingsTab ) );
        exit;
    }

    public function registerMetaBoxes(): void {
        $postTypes = get_post_types( array( 'public' => true ), 'names' );
        foreach ( $postTypes as $postType ) {
            if ( 'attachment' === $postType ) {
                continue;
            }
            add_meta_box(
                'yps-seo-meta',
                esc_html__( 'Yablonsky SEO', 'yablonsky-seo' ),
                array( $this, 'renderPostMetaBox' ),
                $postType,
                'normal',
                'high'
            );
        }
    }

    public function renderPostMetaBox( \WP_Post $post ): void {
        wp_nonce_field( self::POST_NONCE, 'yps_seo_meta_nonce' );
        $fields = $this->postFieldDefinitions();
        $values = array();
        foreach ( $fields as $name => $field ) {
            $values[ $name ] = get_post_meta( $post->ID, $field['meta'], true );
        }
        $fallbackTitle = trim( wp_strip_all_tags( (string) get_the_title( $post->ID ), true ) );
        if ( '' === $fallbackTitle ) {
            $fallbackTitle = __( 'Untitled content', 'yablonsky-seo' );
        }
        $fallbackTitle .= ' - ' . (string) get_bloginfo( 'name' );
        $fallbackDescription = trim( wp_strip_all_tags( (string) $post->post_excerpt, true ) );
        if ( '' === $fallbackDescription ) {
            $fallbackDescription = wp_trim_words( wp_strip_all_tags( (string) $post->post_content, true ), 28, '…' );
        }
        $permalink = get_permalink( $post->ID );
        $permalink = is_string( $permalink ) ? $permalink : home_url( '/' );

        // Preserve imported template variables verbatim in storage, but resolve them for
        // previews and analysis so a migrated Yoast pattern never appears as literal
        // %%page%% / %%sep%% / %%sitename%% text in YSEO's editor.
        $resourceType = 'product' === $post->post_type ? 'product' : 'post';
        $resource = new ResourceIdentity( $resourceType, (int) $post->ID, (string) $post->post_type, '', $permalink );
        $templateResolver = new TemplateResolver();
        $resolvedSeoTitle = '' !== trim( (string) $values['seo_title'] ) ? $templateResolver->resolve( (string) $values['seo_title'], $resource ) : $fallbackTitle;
        $resolvedMetaDescription = '' !== trim( (string) $values['meta_description'] ) ? $templateResolver->resolve( (string) $values['meta_description'], $resource ) : $fallbackDescription;
        $editorSeoTitle = $this->hasTemplateTokens( (string) $values['seo_title'] ) ? $resolvedSeoTitle : (string) $values['seo_title'];
        $editorMetaDescription = $this->hasTemplateTokens( (string) $values['meta_description'] ) ? $resolvedMetaDescription : (string) $values['meta_description'];

        // Authored metadata and effective rendered metadata are deliberately separate.
        // A fallback keeps the public <title>/snippet usable, but it must never make an
        // unfinished SEO field look optimized in the editor score.
        $analysisValues = $values;
        $analysisValues['focus_keyphrases'] = $this->relatedKeyphrasesForPost( (int) $post->ID, (string) ( $values['focus_keyphrase'] ?? '' ) );
        $analysisValues['effective_seo_title'] = $resolvedSeoTitle;
        $analysisValues['effective_meta_description'] = $resolvedMetaDescription;
        $analysis = ( new ContentOptimizationAnalyzer() )->analyzePost( $post, $analysisValues );
        $yoastActive = $this->detector->hasProvider( 'yoast' );
        $sourceInfo = $yoastActive ? ( new MetadataMirrorService( new MetadataMirrorRepository() ) )->inspectPost( $post->ID ) : array();
        $aiConfigured = ( new MetadataSuggestionService() )->isConfigured();
        $aiImageClient = ( new AiClientResolver() )->resolve();
        $aiImageModels = $aiImageClient->imageModels();
        $aiImageSettings = new AiSettings();
        $aiDraft = $this->readAiMetadataDraft( $post->ID );
        $internalLinkRepository = new InternalLinkRepository();
        $internalLinkStats = $internalLinkRepository->statsForPost( $post->ID );
        $internalLinkIndexed = '' !== $internalLinkRepository->sourceHash( $post->ID );
        $internalLinkSuggestions = $internalLinkIndexed ? ( new InternalLinkSuggestionService( $internalLinkRepository ) )->suggestionsForPost( $post->ID, 6 ) : array();
        $schemaSurfacePolicy = SearchSurfacePolicy::exceptions();
        ?>
        <div class="yps-editor yps-seo-editor"
            data-yps-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
            data-yps-fallback-title="<?php echo esc_attr( $fallbackTitle ); ?>"
            data-yps-fallback-description="<?php echo esc_attr( $fallbackDescription ); ?>"
            data-yps-template-title="<?php echo esc_attr( trim( wp_strip_all_tags( (string) get_the_title( $post->ID ), true ) ) ); ?>"
            data-yps-template-sitename="<?php echo esc_attr( trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ), true ) ) ); ?>"
            data-yps-template-sitedesc="<?php echo esc_attr( trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ), true ) ) ); ?>"
            data-yps-template-sep="<?php echo esc_attr( $templateResolver->resolve( '%%sep%%', $resource ) ); ?>">
            <div class="yps-editor-summary"><p><?php echo esc_html__( 'Three working layers in one familiar editor: SEO for search presentation, AEO for answer extraction, and GEO for AI understanding and retrieval. Scores are transparent local readiness heuristics.', 'yablonsky-seo' ); ?></p><span class="yps-mini-status good"><?php echo esc_html__( 'LOCAL', 'yablonsky-seo' ); ?></span></div>
            <?php if ( $yoastActive && ! empty( $sourceInfo['mapped'] ) ) : ?>
                <div class="yps-source-mirror" data-yps-source-mirror>
                    <?php /* translators: 1: mapped Yoast value count, 2: exact YSEO match count, 3: differing preserved YSEO value count. */ ?>
                    <div><strong><?php echo esc_html__( 'Yoast source metadata detected', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html( sprintf( __( '%1$d mapped values · %2$d exact matches · %3$d intentional/different YSEO values', 'yablonsky-seo' ), (int) $sourceInfo['mapped'], (int) $sourceInfo['matched'], (int) $sourceInfo['different'] ) ); ?></span></div>
                    <div class="yps-inline-actions"><button type="button" class="button" data-yps-mirror-post="search"><?php echo esc_html__( 'Copy Search metadata', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yps-mirror-post="all"><?php echo esc_html__( 'Copy all mapped data', 'yablonsky-seo' ); ?></button></div>
                    <p class="yps-assist-status" data-yps-assist-status></p>
                </div>
            <?php endif; ?>
            <?php $this->renderOptimizationSummary( $analysis ); ?>
            <div class="yps-editor-layer-legend"><span class="is-seo"><b>SEO</b><?php echo esc_html__( 'Search result & discovery', 'yablonsky-seo' ); ?></span><span class="is-aeo"><b>AEO</b><?php echo esc_html__( 'Answer extraction', 'yablonsky-seo' ); ?></span><span class="is-geo"><b>GEO</b><?php echo esc_html__( 'AI understanding & retrieval', 'yablonsky-seo' ); ?></span></div>
            <div class="yps-analysis-stale" data-yps-analysis-stale hidden><?php echo esc_html__( 'Live analysis follows unsaved SEO and content changes. Update the post to publish them.', 'yablonsky-seo' ); ?></div>
            <div class="yps-editor-preview<?php echo '' !== trim( (string) $values['og_image'] ) ? ' has-image' : ''; ?>" data-yps-search-preview>
                <div class="yps-editor-preview-copy">
                    <div class="yps-preview-domain"><?php echo esc_html( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ); ?></div>
                    <div class="yps-editor-preview-title" data-yps-preview-title><?php echo esc_html( $resolvedSeoTitle ); ?></div>
                    <div class="yps-editor-preview-url"><?php echo esc_html( $permalink ); ?></div>
                    <div class="yps-editor-preview-description" data-yps-preview-description><?php echo esc_html( $resolvedMetaDescription ); ?></div>
                </div>
                <img class="yps-editor-preview-image" data-yps-preview-image src="<?php echo esc_url( (string) $values['og_image'] ); ?>" alt="" <?php echo '' === trim( (string) $values['og_image'] ) ? 'hidden' : ''; ?> />
            </div>
            <nav class="yps-editor-tabs" aria-label="<?php echo esc_attr__( 'SEO editor sections', 'yablonsky-seo' ); ?>">
                <?php foreach ( array( 'search' => __( 'SEO', 'yablonsky-seo' ), 'answer' => __( 'AEO', 'yablonsky-seo' ), 'ai' => __( 'GEO', 'yablonsky-seo' ), 'language' => __( 'Readability', 'yablonsky-seo' ), 'social' => __( 'Social', 'yablonsky-seo' ), 'vertical' => __( 'Vertical SEO', 'yablonsky-seo' ), 'schema' => __( 'Schema', 'yablonsky-seo' ), 'advanced' => __( 'Advanced', 'yablonsky-seo' ) ) as $key => $label ) : ?>
                    <button type="button" class="yps-editor-tab yps-editor-tab-<?php echo esc_attr( $key ); ?><?php echo 'search' === $key ? ' is-active' : ''; ?>" data-yps-editor-tab="<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo 'search' === $key ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
                <?php endforeach; ?>
            </nav>
            <section class="yps-editor-panel" data-yps-editor-panel="search">
                <?php if ( ! empty( $aiDraft ) ) : ?>
                    <div class="yps-ai-draft-review" data-yps-ai-draft data-yps-ai-draft-values="<?php echo esc_attr( wp_json_encode( $aiDraft ) ); ?>">
                        <div><strong><?php echo esc_html__( 'Site-wide AI review draft available', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'This proposal was staged while source authority was protected. Loading it changes only the open form; nothing is saved until you review and Update the post.', 'yablonsky-seo' ); ?></span></div>
                        <div class="yps-inline-actions"><button type="button" class="button" data-yps-ai-load-draft><?php echo esc_html__( 'Load draft into fields', 'yablonsky-seo' ); ?></button><button type="button" class="button-link-delete" data-yps-ai-discard-draft><?php echo esc_html__( 'Discard draft', 'yablonsky-seo' ); ?></button></div>
                        <p class="yps-assist-status" data-yps-ai-draft-status></p>
                    </div>
                <?php endif; ?>
                <div class="yps-ai-assist-bar">
                    <div><strong><?php echo esc_html__( 'AI metadata assist', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html( $aiConfigured ? __( 'Suggestions stay in review until you choose Update on the post.', 'yablonsky-seo' ) : __( 'Connect and verify your Yablonsky AI User API key in SEO Settings to enable on-demand metadata suggestions.', 'yablonsky-seo' ) ); ?></span></div>
                    <button type="button" class="button" data-yps-ai-all <?php disabled( ! $aiConfigured ); ?>><?php echo esc_html__( 'AI draft Search metadata', 'yablonsky-seo' ); ?></button>
                    <p class="yps-assist-status" data-yps-ai-status></p>
                </div>
                <div class="yps-editor-fields-grid">
                    <?php $this->renderCompactEditorField( 'focus_keyphrase', $fields['focus_keyphrase'], $values['focus_keyphrase'], false, 0, true ); ?>
                    <?php $this->renderRelatedKeyphrasesField( (int) $post->ID, (string) $values['focus_keyphrase'], $aiConfigured ); ?>
                    <?php $this->renderCompactEditorField( 'is_cornerstone', $fields['is_cornerstone'], $values['is_cornerstone'] ); ?>
                    <?php $this->renderCompactEditorField( 'canonical', $fields['canonical'], $values['canonical'] ); ?>
                    <?php $this->renderCompactEditorField( 'seo_title', $fields['seo_title'], $editorSeoTitle, true, 60, true ); ?>
                    <?php $this->renderCompactEditorField( 'meta_description', $fields['meta_description'], $editorMetaDescription, true, 155, true ); ?>
                </div>
                <?php $this->renderOptimizationChecks( $analysis, 'search' ); ?>
                <div class="yps-internal-link-evidence">
                    <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Internal linking', 'yablonsky-seo' ); ?></span><h3><?php echo esc_html__( 'Link evidence & contextual suggestions', 'yablonsky-seo' ); ?></h3></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-internal-links' ) ); ?>"><?php echo esc_html__( 'Open link workspace', 'yablonsky-seo' ); ?></a></div>
                    <div class="yps-link-stats">
                        <span><b><?php echo esc_html( (string) $internalLinkStats['inbound_links'] ); ?></b><?php echo esc_html__( 'Inbound links', 'yablonsky-seo' ); ?></span>
                        <span><b><?php echo esc_html( (string) $internalLinkStats['inbound_sources'] ); ?></b><?php echo esc_html__( 'Linking resources', 'yablonsky-seo' ); ?></span>
                        <span><b><?php echo esc_html( (string) $internalLinkStats['outbound_links'] ); ?></b><?php echo esc_html__( 'Outbound links', 'yablonsky-seo' ); ?></span>
                        <span><b><?php echo esc_html( (string) $internalLinkStats['outbound_targets'] ); ?></b><?php echo esc_html__( 'Linked targets', 'yablonsky-seo' ); ?></span>
                    </div>
                    <?php if ( ! $internalLinkIndexed ) : ?>
                        <p class="yps-form-note"><?php echo esc_html__( 'This saved resource is queued for the local link index. It will be indexed automatically in a bounded background batch or on the next Update.', 'yablonsky-seo' ); ?></p>
                    <?php elseif ( ! empty( $internalLinkSuggestions ) ) : ?>
                        <div class="yps-link-suggestions"><strong><?php echo esc_html__( 'Suggested internal targets', 'yablonsky-seo' ); ?></strong><ul>
                            <?php foreach ( $internalLinkSuggestions as $suggestion ) : ?>
                                <?php
                                /* translators: 1: shared taxonomy term count, 2: shared title-word count. */
                                /* translators: 1: shared taxonomy/topic count, 2: shared title-term count. */
                                $suggestionEvidence = sprintf( __( '%1$d shared topics · %2$d shared title terms', 'yablonsky-seo' ), (int) $suggestion['shared_terms'], (int) $suggestion['shared_title_words'] );
                                ?>
                                <li><a href="<?php echo esc_url( (string) $suggestion['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $suggestion['title'] ); ?></a><span><?php echo esc_html( $suggestionEvidence ); ?></span></li>
                            <?php endforeach; ?>
                        </ul><small><?php echo esc_html__( 'Suggestions are local relevance hints based on shared taxonomy context and title terms. Review them editorially; link insertion remains editor-controlled.', 'yablonsky-seo' ); ?></small></div>
                    <?php else : ?>
                        <p class="yps-form-note"><?php echo esc_html__( 'The bounded local candidate set is clear of high-confidence unused internal targets.', 'yablonsky-seo' ); ?></p>
                    <?php endif; ?>
                </div>
                <div class="yps-editor-note"><?php echo esc_html__( 'The preview uses your explicit title/description when present and safe WordPress fallbacks otherwise. The focus keyphrase guides analysis while output follows current SEO metadata standards. Content-body checks follow the current editor draft and confirm again after Update.', 'yablonsky-seo' ); ?></div>
            </section>
            <section class="yps-editor-panel" data-yps-editor-panel="answer" hidden>
                <div class="yps-content-layer-intro is-aeo"><span class="yps-pillar-badge">AEO</span><div><strong><?php echo esc_html__( 'Global guidance profile:', 'yablonsky-seo' ); ?> <?php echo esc_html( strtoupper( (string) ( $analysis['facts']['aeo_profile'] ?? AeoPolicy::DEFAULT_PROFILE ) ) ); ?></strong><span><?php echo esc_html__( 'The profile tunes local analysis thresholds only. Page-specific editorial decisions stay here.', 'yablonsky-seo' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-settings#answer' ) ); ?>"><?php echo esc_html__( 'AEO Settings', 'yablonsky-seo' ); ?></a></span></div></div>
                <div class="yps-ai-content-assist" data-yps-ai-content-assist>
                    <div><span class="yps-card-kicker"><?php echo esc_html__( 'Yablonsky AI · explicit remote compute', 'yablonsky-seo' ); ?></span><h3><?php echo esc_html__( 'Summarize or optimize this saved content', 'yablonsky-seo' ); ?></h3><p><?php echo esc_html__( 'Generate a concise summary or practical optimization guidance in the proven resource language. Results remain review-only; WordPress content changes only after editor action.', 'yablonsky-seo' ); ?></p></div>
                    <div class="yps-inline-actions"><button type="button" class="button" data-yps-ai-content="summarize" <?php disabled( ! $aiConfigured ); ?>><?php echo esc_html__( 'AI summarize', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yps-ai-content="optimize" <?php disabled( ! $aiConfigured ); ?>><?php echo esc_html__( 'AI optimization guidance', 'yablonsky-seo' ); ?></button></div>
                    <p class="yps-assist-status" data-yps-ai-content-status><?php echo esc_html( $aiConfigured ? __( 'Nothing is sent until you choose an AI action.', 'yablonsky-seo' ) : __( 'Connect and verify Yablonsky AI in SEO Settings to enable content assistance.', 'yablonsky-seo' ) ); ?></p>
                    <textarea class="large-text" rows="8" data-yps-ai-content-output readonly hidden aria-label="<?php echo esc_attr__( 'AI content-assist review output', 'yablonsky-seo' ); ?>"></textarea>
                </div>
                <?php $this->renderOptimizationChecks( $analysis, 'answer' ); ?>
                <div class="yps-editor-note"><?php echo esc_html__( 'AEO focuses on extractability: a clear opening, useful section structure, concise paragraphs and answer-oriented formatting. Page format remains content-appropriate, with FAQ structure available where it helps.', 'yablonsky-seo' ); ?></div>
            </section>
            <section class="yps-editor-panel" data-yps-editor-panel="ai" hidden>
                <div class="yps-content-layer-intro is-geo"><span class="yps-pillar-badge">GEO</span><div><strong><?php echo esc_html__( 'This page as an AI-readable resource', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'The canonical HTML remains authoritative. When Agent-readable Markdown is enabled and YSEO owns authority, this resource also gets a clean .md alternate discoverable from the page, the applicable llms.txt scope and optionally the XML sitemap agent extension.', 'yablonsky-seo' ); ?></span></div></div>
                <?php $this->renderOptimizationChecks( $analysis, 'ai' ); ?>
                <div class="yps-editor-note"><?php echo esc_html__( 'GEO checks identity, structured context, evidence paths, image semantics, machine-readable alternates and discoverability. Citation and indexing status remain external evidence.', 'yablonsky-seo' ); ?></div>
            </section>
            <section class="yps-editor-panel" data-yps-editor-panel="language" hidden>
                <div class="yps-content-layer-intro"><span class="yps-pillar-badge">TEXT</span><div><strong><?php echo esc_html__( 'Language-aware editorial review', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Inclusive-language matches are conservative review prompts. Supported languages receive analysis; other languages report their unsupported state explicitly.', 'yablonsky-seo' ); ?></span></div></div>
                <?php $this->renderOptimizationChecks( $analysis, 'language' ); ?>
                <div class="yps-editor-note"><?php echo esc_html__( 'This local wording analysis leaves content unchanged and runs entirely on-site.', 'yablonsky-seo' ); ?></div>
            </section>
            <section class="yps-editor-panel" data-yps-editor-panel="social" hidden>
                <div class="yps-seo-image-studio" data-yps-seo-image-studio>
                    <div class="yps-seo-image-copy"><span class="yps-card-kicker"><?php echo esc_html__( 'Preferred Search / Social image', 'yablonsky-seo' ); ?></span><h3><?php echo esc_html__( 'Generate a page image with Yablonsky AI', 'yablonsky-seo' ); ?></h3><p><?php echo esc_html__( 'Generate an editorial image, copy it into the WordPress Media Library, then review it before Update. YSEO uses the saved URL for Open Graph, X/Twitter and structured-data primary image signals. Search engines may still choose another image.', 'yablonsky-seo' ); ?></p></div>
                    <div class="yps-seo-image-layout">
                        <div class="yps-seo-image-controls">
                            <div class="yps-setting-field"><label for="yps-seo-image-model"><?php echo esc_html__( 'Image model', 'yablonsky-seo' ); ?></label><select id="yps-seo-image-model" data-yps-seo-image-model data-yps-search-select data-yps-search-placeholder="<?php echo esc_attr__( 'Search image models…', 'yablonsky-seo' ); ?>"><option value=""><?php echo esc_html__( 'Default image model', 'yablonsky-seo' ); ?></option><?php foreach ( $aiImageModels as $model ) : ?><option value="<?php echo esc_attr( $model->id() ); ?>" <?php selected( $aiImageSettings->imageModelId(), $model->id() ); ?>><?php echo esc_html( $model->label() ); ?></option><?php endforeach; ?></select></div>
                            <div class="yps-setting-field yps-field-wide"><label for="yps-seo-image-prompt"><?php echo esc_html__( 'Image brief', 'yablonsky-seo' ); ?></label><textarea rows="4" id="yps-seo-image-prompt" data-yps-seo-image-prompt placeholder="<?php echo esc_attr__( 'The saved page title and content build the brief automatically when this field is blank.', 'yablonsky-seo' ); ?>"></textarea></div>
                            <div class="yps-seo-image-row"><div class="yps-setting-field"><label for="yps-seo-image-ratio"><?php echo esc_html__( 'Aspect ratio', 'yablonsky-seo' ); ?></label><select id="yps-seo-image-ratio" data-yps-seo-image-ratio><option value="16:9">16:9</option><option value="4:3">4:3</option><option value="1:1">1:1</option></select></div><button type="button" class="button button-primary" data-yps-seo-image-generate <?php disabled( empty( $aiImageModels ) ); ?>><?php echo esc_html__( 'Generate image', 'yablonsky-seo' ); ?></button></div>
                            <p class="yps-assist-status" data-yps-seo-image-status><?php echo empty( $aiImageModels ) ? esc_html__( 'Connect or verify an image-generation model for this workspace.', 'yablonsky-seo' ) : esc_html__( 'Nothing is generated or saved until you explicitly request it.', 'yablonsky-seo' ); ?></p>
                        </div>
                        <div class="yps-seo-image-preview" data-yps-seo-image-preview><?php if ( '' !== trim( (string) $values['og_image'] ) ) : ?><img src="<?php echo esc_url( (string) $values['og_image'] ); ?>" alt="" /><span><?php echo esc_html__( 'Current preferred image', 'yablonsky-seo' ); ?></span><?php else : ?><div class="yps-seo-image-placeholder"><span class="dashicons dashicons-format-image"></span><strong><?php echo esc_html__( 'Preferred image ready to set', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Generate one or paste an existing image URL below.', 'yablonsky-seo' ); ?></small></div><?php endif; ?></div>
                    </div>
                </div>
                <div class="yps-social-preview-grid" data-yps-social-previews>
                    <?php
                    $resolvedOgTitle = '' !== trim( (string) $values['og_title'] ) ? $templateResolver->resolve( (string) $values['og_title'], $resource ) : $resolvedSeoTitle;
                    $resolvedOgDescription = '' !== trim( (string) $values['og_description'] ) ? $templateResolver->resolve( (string) $values['og_description'], $resource ) : $resolvedMetaDescription;
                    $resolvedTwitterTitle = '' !== trim( (string) $values['twitter_title'] ) ? $templateResolver->resolve( (string) $values['twitter_title'], $resource ) : $resolvedSeoTitle;
                    $resolvedTwitterDescription = '' !== trim( (string) $values['twitter_description'] ) ? $templateResolver->resolve( (string) $values['twitter_description'], $resource ) : $resolvedMetaDescription;
                    ?>
                    <article class="yps-social-preview-card is-facebook"><span class="yps-card-kicker"><?php echo esc_html__( 'Facebook preview', 'yablonsky-seo' ); ?></span><div class="yps-social-preview-image" data-yps-social-image="facebook"><?php if ( '' !== trim( (string) $values['og_image'] ) ) : ?><img src="<?php echo esc_url( (string) $values['og_image'] ); ?>" alt="" /><?php endif; ?></div><div class="yps-social-preview-copy"><small><?php echo esc_html( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ); ?></small><strong data-yps-social-title="facebook"><?php echo esc_html( $resolvedOgTitle ); ?></strong><span data-yps-social-description="facebook"><?php echo esc_html( $resolvedOgDescription ); ?></span></div></article>
                    <article class="yps-social-preview-card is-x"><span class="yps-card-kicker"><?php echo esc_html__( 'X / Twitter preview', 'yablonsky-seo' ); ?></span><div class="yps-social-preview-image" data-yps-social-image="x"><?php $xImage = '' !== trim( (string) $values['twitter_image'] ) ? (string) $values['twitter_image'] : (string) $values['og_image']; if ( '' !== trim( $xImage ) ) : ?><img src="<?php echo esc_url( $xImage ); ?>" alt="" /><?php endif; ?></div><div class="yps-social-preview-copy"><strong data-yps-social-title="x"><?php echo esc_html( $resolvedTwitterTitle ); ?></strong><span data-yps-social-description="x"><?php echo esc_html( $resolvedTwitterDescription ); ?></span></div></article>
                </div>
                <div class="yps-editor-fields-grid">
                    <?php foreach ( array( 'og_type', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image' ) as $name ) { $this->renderCompactEditorField( $name, $fields[ $name ], $values[ $name ], in_array( $name, array( 'og_description', 'twitter_description' ), true ) ); } ?>
                </div>
                <div class="yps-editor-note"><?php echo esc_html__( 'Social fields use the search title/description and the resource image as fallbacks when available. The previews are editorial simulations; social networks retain final presentation control.', 'yablonsky-seo' ); ?></div>
            </section>
            <section class="yps-editor-panel" data-yps-editor-panel="vertical" hidden>
                <div class="yps-content-layer-intro is-seo"><span class="yps-pillar-badge">SEO</span><div><strong><?php echo esc_html__( 'Video & News SEO', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Use explicit controls only where the content really represents a video or a news story. YSEO also performs bounded local video detection for supported embeds.', 'yablonsky-seo' ); ?></span></div></div>
                <div class="yps-editor-fields-grid">
                    <?php foreach ( array( 'video_exclude', 'video_content_url', 'video_kind', 'video_title', 'video_description', 'video_thumbnail', 'video_duration' ) as $name ) { $this->renderCompactEditorField( $name, $fields[ $name ], $values[ $name ], in_array( $name, array( 'video_content_url', 'video_description', 'video_thumbnail' ), true ) ); } ?>
                    <?php if ( 'post' === $post->post_type ) : ?>
                        <?php foreach ( array( 'news_enabled', 'news_exclude', 'news_stock_tickers' ) as $name ) { $this->renderCompactEditorField( $name, $fields[ $name ], $values[ $name ], 'news_stock_tickers' === $name ); } ?>
                    <?php endif; ?>
                </div>
                <div class="yps-editor-note"><?php echo esc_html__( 'Video SEO emits VideoObject and a dedicated video sitemap only when a usable video and thumbnail are available. News SEO includes only explicitly enabled, recent public posts with a proven resource language in the Google News sitemap.', 'yablonsky-seo' ); ?></div>
            </section>
            <section class="yps-editor-panel" data-yps-editor-panel="schema" hidden>
                <div class="yps-content-layer-intro is-seo"><span class="yps-pillar-badge">Schema</span><div><strong><?php echo esc_html__( 'Schema validity and search appearance are separate', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'YSEO keeps Schema.org semantics distinct from the features currently supported by individual search surfaces. Valid markup expresses semantic correctness; Google rich-result eligibility follows Google’s supported feature set.', 'yablonsky-seo' ); ?></span></div></div>
                <div class="yps-readiness-grid">
                    <?php if ( isset( $schemaSurfacePolicy['claimreview'] ) ) : ?><div class="yps-readiness-item yps-tone-attention"><div><strong>ClaimReview</strong><span><?php echo esc_html__( 'Google Search: phasing out · Fact Check Explorer: supported', 'yablonsky-seo' ); ?></span></div><b><?php echo esc_html__( 'SPECIALIZED', 'yablonsky-seo' ); ?></b></div><?php endif; ?>
                    <?php if ( isset( $schemaSurfacePolicy['faqpage'] ) ) : ?><div class="yps-readiness-item yps-tone-attention"><div><strong>FAQPage</strong><span><?php echo esc_html__( 'Google Search rich results are limited to eligible well-known government and health sites.', 'yablonsky-seo' ); ?></span></div><b><?php echo esc_html__( 'LIMITED', 'yablonsky-seo' ); ?></b></div><?php endif; ?>
                    <?php if ( isset( $schemaSurfacePolicy['howto'] ) ) : ?><div class="yps-readiness-item yps-tone-neutral"><div><strong>HowTo</strong><span><?php echo esc_html__( 'Schema.org-valid semantics remain usable. Google Search presentation currently excludes How-to rich results.', 'yablonsky-seo' ); ?></span></div><b><?php echo esc_html__( 'SEMANTIC', 'yablonsky-seo' ); ?></b></div><?php endif; ?>
                </div>
                <div class="yps-editor-note"><?php echo esc_html__( 'ClaimReview generation is reserved for genuine fact-check workflows where another consumer, such as Fact Check Explorer, still uses it.', 'yablonsky-seo' ); ?></div>
                <div class="yps-editor-fields-grid">
                    <?php $this->renderCompactEditorField( 'schema_page_type', $fields['schema_page_type'], $values['schema_page_type'] ); ?>
                    <?php if ( 'post' === $post->post_type ) : ?>
                        <?php $this->renderCompactEditorField( 'schema_article_type', $fields['schema_article_type'], $values['schema_article_type'] ); ?>
                    <?php endif; ?>
                    <?php $this->renderCompactEditorField( 'schema_entity_type', $fields['schema_entity_type'], $values['schema_entity_type'] ); ?>
                    <?php $this->renderCompactEditorField( 'schema_entity_name', $fields['schema_entity_name'], $values['schema_entity_name'] ); ?>
                    <?php $this->renderCompactEditorField( 'schema_entity_description', $fields['schema_entity_description'], $values['schema_entity_description'], true ); ?>
                    <?php $this->renderCompactEditorField( 'schema_person_job_title', $fields['schema_person_job_title'], $values['schema_person_job_title'] ); ?>
                    <?php $this->renderCompactEditorField( 'schema_person_image_url', $fields['schema_person_image_url'], $values['schema_person_image_url'] ); ?>
                    <?php $this->renderCompactEditorField( 'schema_person_same_as', $fields['schema_person_same_as'], $values['schema_person_same_as'], true ); ?>
                    <?php $this->renderCompactEditorField( 'schema_person_knows_about', $fields['schema_person_knows_about'], $values['schema_person_knows_about'], true ); ?>
                    <?php $this->renderCompactEditorField( 'schema_service_type', $fields['schema_service_type'], $values['schema_service_type'] ); ?>
                    <?php $this->renderCompactEditorField( 'schema_area_served', $fields['schema_area_served'], $values['schema_area_served'] ); ?>
                    <?php $this->renderCompactEditorField( 'schema_application_category', $fields['schema_application_category'], $values['schema_application_category'] ); ?>
                    <?php $this->renderCompactEditorField( 'schema_operating_system', $fields['schema_operating_system'], $values['schema_operating_system'] ); ?>
                    <?php $this->renderCompactEditorField( 'schema_offer_price', $fields['schema_offer_price'], $values['schema_offer_price'] ); ?>
                    <?php $this->renderCompactEditorField( 'schema_offer_currency', $fields['schema_offer_currency'], $values['schema_offer_currency'] ); ?>
                    <?php $this->renderCompactEditorField( 'schema_access_restricted', $fields['schema_access_restricted'], $values['schema_access_restricted'], true ); ?>
                </div>
                <div class="yps-editor-note"><?php echo esc_html__( 'Person, Service and SoftwareApplication primary entities are optional. Mark access restricted only when the whole page is genuinely behind a paywall, meter or subscription; YSEO then emits page-level isAccessibleForFree=false. Entity data should match visible page content.', 'yablonsky-seo' ); ?></div>
            </section>
            <section class="yps-editor-panel" data-yps-editor-panel="advanced" hidden>
                <div class="yps-editor-fields-grid">
                    <?php foreach ( array( 'noindex', 'nofollow', 'robots_advanced', 'apple_ai_answer_opt_out', 'redirect_target' ) as $name ) { $this->renderCompactEditorField( $name, $fields[ $name ], $values[ $name ], in_array( $name, array( 'robots_advanced', 'apple_ai_answer_opt_out', 'redirect_target' ), true ) ); } ?>
                </div>
                <div class="yps-editor-note"><?php echo esc_html__( 'Apple AI answer opt-out emits an Applebot-specific nosnippet directive while preserving page indexability unless separate robots settings change it. Redirect targets are restricted to the same site. Indexability changes also affect sitemap membership when Yablonsky SEO owns authority.', 'yablonsky-seo' ); ?></div>
            </section>
        </div>
        <?php
    }

    public function savePostMeta( int $postId, \WP_Post $post ): void {
        if ( wp_is_post_autosave( $postId ) || wp_is_post_revision( $postId ) ) {
            return;
        }
        $rawNonce = filter_input( INPUT_POST, 'yps_seo_meta_nonce', FILTER_UNSAFE_RAW );
        if ( ! is_string( $rawNonce ) || '' === $rawNonce ) {
            return;
        }
        $nonce = sanitize_text_field( $rawNonce );
        if ( ! wp_verify_nonce( $nonce, self::POST_NONCE ) || ! current_user_can( 'edit_post', $postId ) ) {
            return;
        }
        if ( 'attachment' === $post->post_type ) {
            return;
        }
        foreach ( $this->postFieldDefinitions() as $name => $field ) {
            $this->saveObjectMetaValue( 'post', $postId, $name, $field );
        }
        $this->saveRelatedKeyphrases( $postId );
    }

    public function ajaxAiSuggestSettings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Requesting site setup suggestions requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        check_ajax_referer( 'yseo_ai_settings', 'nonce' );
        $rawFields = filter_input( INPUT_POST, 'fields', FILTER_UNSAFE_RAW );
        $fields = is_string( $rawFields ) ? array_values( array_filter( array_map( 'sanitize_key', explode( ',', $rawFields ) ) ) ) : array();
        try {
            $suggestions = ( new SiteSettingsSuggestionService() )->suggest( $fields );
            wp_send_json_success( array( 'suggestions' => $suggestions, 'message' => esc_html__( 'Review the AI suggestions before saving site settings.', 'yablonsky-seo' ) ) );
        } catch ( ServiceError $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 400 );
        } catch ( \Throwable $error ) {
            unset( $error );
            wp_send_json_error( array( 'message' => esc_html__( 'Site setup suggestions stopped safely.', 'yablonsky-seo' ) ), 500 );
        }
    }

    public function ajaxAiSuggestSocialTemplate(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Requesting site setup suggestions requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        check_ajax_referer( 'yseo_ai_settings', 'nonce' );
        $rawGroup = filter_input( INPUT_POST, 'group', FILTER_UNSAFE_RAW );
        $rawKey = filter_input( INPUT_POST, 'key', FILTER_UNSAFE_RAW );
        $rawFields = filter_input( INPUT_POST, 'fields', FILTER_UNSAFE_RAW );
        $group = is_string( $rawGroup ) ? sanitize_key( $rawGroup ) : '';
        $key = is_string( $rawKey ) ? sanitize_key( $rawKey ) : '';
        $fields = is_string( $rawFields ) ? array_values( array_filter( array_map( 'sanitize_key', explode( ',', $rawFields ) ) ) ) : array();
        try {
            $suggestions = ( new SocialTemplateSuggestionService() )->suggest( $group, $key, $fields );
            wp_send_json_success( array( 'suggestions' => $suggestions, 'message' => esc_html__( 'AI suggestions were inserted into this unsaved form. Review them and Save changes to keep them.', 'yablonsky-seo' ) ) );
        } catch ( ServiceError $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 400 );
        } catch ( \Throwable $error ) {
            unset( $error );
            wp_send_json_error( array( 'message' => esc_html__( 'Site setup suggestions stopped safely.', 'yablonsky-seo' ) ), 500 );
        }
    }

    public function ajaxAiTestCompute(): void {
        check_ajax_referer( 'yseo_ai_settings', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Running this compute canary requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        try {
            $client = ( new AiClientResolver() )->resolve();
            $result = $client->compute( new ComputeRequest( 'seo.compute.canary.v1', 'auto', array( 'instruction' => 'Return result exactly as ok.', 'expected' => array( 'result' => 'ok' ) ) ) );
            $data = $result->data();
            if ( 'ok' !== strtolower( trim( (string) ( $data['result'] ?? '' ) ) ) ) {
                throw new ServiceError( esc_html__( 'Writer compute returned an unexpected canary payload.', 'yablonsky-seo' ) );
            }
            $meta = $result->meta();
            $mode = sanitize_key( (string) ( $meta['mode'] ?? '' ) );
            if ( 'writer-auto-stream-recovered' === $mode ) {
                $message = esc_html__( 'Writer compute verified through structured SSE recovery. The live stream did not expose the expected final document marker, but the complete JSON payload was recovered and validated without a second AI request.', 'yablonsky-seo' );
            } elseif ( 'writer-auto' === $mode ) {
                $message = esc_html__( 'Writer compute verified. The final document resource and structured JSON path both passed.', 'yablonsky-seo' );
            } else {
                $message = esc_html__( 'Writer compute verified. Structured AI generation is ready.', 'yablonsky-seo' );
            }
            wp_send_json_success( array(
                'message' => $message,
                'model' => sanitize_text_field( (string) ( $meta['model'] ?? '' ) ),
                'mode' => $mode,
                'cost' => $meta['cost'] ?? null,
            ) );
        } catch ( \RuntimeException $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 409 );
        }
    }

    public function ajaxAiTestConnection(): void {
        check_ajax_referer( 'yseo_ai_settings', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Testing this connection requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        try {
            $client = ( new AiClientResolver() )->resolve();
            $client->testConnection();
            $models = $client->models();
            $imageModels = $client->imageModels();
            $usage = $client->usage()->data();
            wp_send_json_success(
                array(
                    'message' => esc_html__( 'Yablonsky AI connection verified. Account capabilities were refreshed locally.', 'yablonsky-seo' ),
                    'workspace' => sanitize_text_field( (string) ( $usage['workspace_name'] ?? '' ) ),
                    'plan' => sanitize_text_field( (string) ( $usage['plan_title'] ?? '' ) ),
                    'credits' => $usage['credit_count'] ?? null,
                    'usage' => $usage['usage_count'] ?? null,
                    'usage_percentage' => $usage['usage_percentage'] ?? null,
                    'usage_mode' => sanitize_text_field( (string) ( $usage['usage_mode'] ?? '' ) ),
                    'session_reset_at' => sanitize_text_field( (string) ( $usage['session_reset_at'] ?? '' ) ),
                    'models' => max( 0, count( $models ) - 1 ),
                    'image_models' => count( $imageModels ),
                )
            );
        } catch ( \RuntimeException $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 409 );
        }
    }

    public function ajaxAiGenerateSiteImage(): void {
        check_ajax_referer( 'yseo_ai_settings', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Requesting site setup suggestions requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $rawField = filter_input( INPUT_POST, 'field', FILTER_UNSAFE_RAW );
        $field = is_string( $rawField ) ? sanitize_key( $rawField ) : '';
        if ( ! SettingsAuthoringRegistry::supportsImage( $field ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Image generation failed.', 'yablonsky-seo' ) ), 400 );
        }
        $prompt = $this->postedTextarea( 'prompt' );
        $aspectRatio = in_array( $field, array( 'owner_logo_url', 'owner_image_url' ), true ) ? '1:1' : '16:9';
        try {
            $result = ( new SeoImageGenerationService() )->generateForSite( $field, $prompt, '', $aspectRatio );
        } catch ( \RuntimeException $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 409 );
        }
        wp_send_json_success( array(
            'message' => esc_html__( 'AI suggestions were inserted into this unsaved form. Review them and Save changes to keep them.', 'yablonsky-seo' ),
            'attachment_id' => (int) ( $result['attachment_id'] ?? 0 ),
            'url' => esc_url_raw( (string) ( $result['url'] ?? '' ), array( 'http', 'https' ) ),
            'width' => (int) ( $result['width'] ?? 0 ),
            'height' => (int) ( $result['height'] ?? 0 ),
            'model' => sanitize_text_field( (string) ( $result['model'] ?? '' ) ),
            'cost' => $result['cost'] ?? null,
        ) );
    }

    public function ajaxAiGenerateSocialTemplateImage(): void {
        check_ajax_referer( 'yseo_ai_settings', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Requesting site setup suggestions requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $rawGroup = filter_input( INPUT_POST, 'group', FILTER_UNSAFE_RAW );
        $rawKey = filter_input( INPUT_POST, 'key', FILTER_UNSAFE_RAW );
        $rawSlot = filter_input( INPUT_POST, 'slot', FILTER_UNSAFE_RAW );
        $group = is_string( $rawGroup ) ? sanitize_key( $rawGroup ) : '';
        $key = is_string( $rawKey ) ? sanitize_key( $rawKey ) : '';
        $slot = is_string( $rawSlot ) ? sanitize_key( $rawSlot ) : '';
        $family = SettingsAuthoringRegistry::socialTemplateFamily( $group, $key );
        if ( null === $family || ! SettingsAuthoringRegistry::supportsSocialTemplateImage( $slot ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Image generation failed.', 'yablonsky-seo' ) ), 400 );
        }
        $prompt = $this->postedTextarea( 'prompt' );
        $purpose = 'social_template_' . $family['group'] . '_' . $family['key'] . '_' . $slot;
        $context = trim( (string) $family['label'] . '. ' . $prompt );
        try {
            $result = ( new SeoImageGenerationService() )->generateForSite( $purpose, $context, '', '16:9' );
        } catch ( \RuntimeException $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 409 );
        }
        wp_send_json_success( array(
            'message' => esc_html__( 'AI suggestions were inserted into this unsaved form. Review them and Save changes to keep them.', 'yablonsky-seo' ),
            'attachment_id' => (int) ( $result['attachment_id'] ?? 0 ),
            'url' => esc_url_raw( (string) ( $result['url'] ?? '' ), array( 'http', 'https' ) ),
            'width' => (int) ( $result['width'] ?? 0 ),
            'height' => (int) ( $result['height'] ?? 0 ),
            'model' => sanitize_text_field( (string) ( $result['model'] ?? '' ) ),
            'cost' => $result['cost'] ?? null,
        ) );
    }

    public function ajaxAiGenerateSeoImage(): void {
        check_ajax_referer( self::EDITOR_AJAX_NONCE, 'nonce' );
        $postIdRaw = filter_input( INPUT_POST, 'post_id', FILTER_VALIDATE_INT );
        $postId = is_int( $postIdRaw ) ? $postIdRaw : (int) $postIdRaw;
        if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Editing this content requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $model = $this->postedText( 'model' );
        $prompt = $this->postedTextarea( 'prompt' );
        $ratioRaw = filter_input( INPUT_POST, 'aspect_ratio', FILTER_UNSAFE_RAW );
        $ratio = is_string( $ratioRaw ) ? sanitize_text_field( $ratioRaw ) : '16:9';
        if ( ! in_array( $ratio, array( '16:9', '4:3', '1:1' ), true ) ) { $ratio = '16:9'; }
        try {
            $result = ( new SeoImageGenerationService() )->generateForPost( $postId, $prompt, $model, $ratio );
        } catch ( 
untimeException $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 409 );
        }
        wp_send_json_success(
            array(
                'message' => esc_html__( 'Image generated and copied into the WordPress Media Library. Review it, then Update the post to save it as the preferred image.', 'yablonsky-seo' ),
                'attachment_id' => (int) ( $result['attachment_id'] ?? 0 ),
                'url' => esc_url_raw( (string) ( $result['url'] ?? '' ), array( 'http', 'https' ) ),
                'width' => (int) ( $result['width'] ?? 0 ),
                'height' => (int) ( $result['height'] ?? 0 ),
                'model' => sanitize_text_field( (string) ( $result['model'] ?? '' ) ),
                'cost' => $result['cost'] ?? null,
            )
        );
    }

    public function ajaxRefreshPostAnalysis(): void {
        check_ajax_referer( self::EDITOR_AJAX_NONCE, 'nonce' );
        $postIdRaw = filter_input( INPUT_POST, 'post_id', FILTER_VALIDATE_INT );
        $postId = is_int( $postIdRaw ) ? $postIdRaw : (int) $postIdRaw;
        if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Editing this content requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Live analysis requires an analyzable resource.', 'yablonsky-seo' ) ), 404 );
        }

        // Analyze the current editor draft, not only the last database save. Gutenberg
        // keeps title/content/excerpt in its data store while the legacy meta box keeps
        // YSEO fields in the form. The browser sends both snapshots here so one local
        // analyzer remains authoritative for live and saved scoring.
        $analysisPost = clone $post;
        $rawDraftTitle = filter_input( INPUT_POST, 'draft_title', FILTER_UNSAFE_RAW );
        if ( is_string( $rawDraftTitle ) ) {
            $analysisPost->post_title = sanitize_text_field( wp_unslash( $rawDraftTitle ) );
        }
        $rawDraftExcerpt = filter_input( INPUT_POST, 'draft_excerpt', FILTER_UNSAFE_RAW );
        if ( is_string( $rawDraftExcerpt ) ) {
            $analysisPost->post_excerpt = sanitize_textarea_field( wp_unslash( $rawDraftExcerpt ) );
        }
        $rawDraftContent = filter_input( INPUT_POST, 'draft_content', FILTER_UNSAFE_RAW );
        if ( is_string( $rawDraftContent ) ) {
            $draftContent = wp_unslash( $rawDraftContent );
            if ( strlen( $draftContent ) <= 2097152 ) {
                $analysisPost->post_content = $draftContent;
            }
        }

        $fields = $this->postFieldDefinitions();
        $values = array();
        foreach ( $fields as $name => $field ) {
            $values[ $name ] = get_post_meta( $postId, $field['meta'], true );
        }

        foreach ( array( 'focus_keyphrase', 'seo_title' ) as $name ) {
            $raw = filter_input( INPUT_POST, $name, FILTER_UNSAFE_RAW );
            if ( is_string( $raw ) ) {
                $values[ $name ] = sanitize_text_field( wp_unslash( $raw ) );
            }
        }
        $rawDescription = filter_input( INPUT_POST, 'meta_description', FILTER_UNSAFE_RAW );
        if ( is_string( $rawDescription ) ) {
            $values['meta_description'] = sanitize_textarea_field( wp_unslash( $rawDescription ) );
        }
        $rawNoindex = filter_input( INPUT_POST, 'noindex', FILTER_UNSAFE_RAW );
        if ( is_string( $rawNoindex ) ) {
            $values['noindex'] = in_array( strtolower( trim( $rawNoindex ) ), array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '';
        }
        $rawSchemaPageType = filter_input( INPUT_POST, 'schema_page_type', FILTER_UNSAFE_RAW );
        if ( is_string( $rawSchemaPageType ) ) {
            $values['schema_page_type'] = sanitize_key( wp_unslash( $rawSchemaPageType ) );
        }

        $rawRelated = filter_input( INPUT_POST, 'focus_keyphrases', FILTER_UNSAFE_RAW );
        $relatedRaw = is_string( $rawRelated ) ? wp_unslash( $rawRelated ) : '';
        $analysisValues = $values;
        $analysisValues['focus_keyphrases'] = $this->relatedKeyphrasesFromRaw( $relatedRaw, (string) ( $values['focus_keyphrase'] ?? '' ) );

        $fallbackTitle = trim( wp_strip_all_tags( (string) $analysisPost->post_title, true ) );
        if ( '' === $fallbackTitle ) { $fallbackTitle = __( 'Untitled content', 'yablonsky-seo' ); }
        $fallbackTitle .= ' - ' . (string) get_bloginfo( 'name' );
        $fallbackDescription = trim( wp_strip_all_tags( (string) $analysisPost->post_excerpt, true ) );
        if ( '' === $fallbackDescription ) { $fallbackDescription = wp_trim_words( wp_strip_all_tags( (string) $analysisPost->post_content, true ), 28, '…' ); }
        $permalink = get_permalink( $postId );
        $permalink = is_string( $permalink ) ? $permalink : home_url( '/' );
        $resourceType = 'product' === $analysisPost->post_type ? 'product' : 'post';
        $resource = new ResourceIdentity( $resourceType, $postId, (string) $analysisPost->post_type, '', $permalink );
        $templateResolver = new TemplateResolver();
        $analysisValues['effective_seo_title'] = '' !== trim( (string) ( $values['seo_title'] ?? '' ) ) ? $templateResolver->resolve( (string) $values['seo_title'], $resource ) : $fallbackTitle;
        $analysisValues['effective_meta_description'] = '' !== trim( (string) ( $values['meta_description'] ?? '' ) ) ? $templateResolver->resolve( (string) $values['meta_description'], $resource ) : $fallbackDescription;
        $analysis = ( new ContentOptimizationAnalyzer() )->analyzePost( $analysisPost, $analysisValues );

        ob_start();
        $this->renderOptimizationSummary( $analysis );
        $summaryHtml = (string) ob_get_clean();
        $dimensions = array();
        foreach ( array( 'search', 'answer', 'ai', 'language' ) as $dimensionKey ) {
            ob_start();
            $this->renderOptimizationChecks( $analysis, $dimensionKey );
            $dimensions[ $dimensionKey ] = (string) ob_get_clean();
        }
        wp_send_json_success( array( 'summary_html' => $summaryHtml, 'dimensions' => $dimensions ) );
    }

    public function ajaxMirrorPostMetadata(): void {
        check_ajax_referer( self::EDITOR_AJAX_NONCE, 'nonce' );
        $postIdRaw = filter_input( INPUT_POST, 'post_id', FILTER_VALIDATE_INT );
        $postId = is_int( $postIdRaw ) ? $postIdRaw : (int) $postIdRaw;
        if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Editing this content requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        if ( ! $this->detector->hasProvider( 'yoast' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Yoast mirroring activates when Yoast SEO is active and provides live source metadata.', 'yablonsky-seo' ) ), 409 );
        }
        $rawScope = filter_input( INPUT_POST, 'scope', FILTER_UNSAFE_RAW );
        $scope = is_string( $rawScope ) && MetadataMirrorState::SCOPE_ALL === sanitize_key( $rawScope ) ? MetadataMirrorState::SCOPE_ALL : MetadataMirrorState::SCOPE_SEARCH;
        $result = ( new MetadataMirrorService( new MetadataMirrorRepository() ) )->mirrorPost( $postId, $scope );
        /* translators: 1: copied metadata value count, 2: exact match count, 3: preserved existing YSEO value count. */
        /* translators: 1: copied value count, 2: already matched count, 3: preserved YSEO value count. */
        $message = sprintf( esc_html__( '%1$d values copied, %2$d already matched, %3$d existing YSEO values preserved.', 'yablonsky-seo' ), (int) $result['copied_values'], (int) $result['matched_values'], (int) $result['preserved_values'] );
        wp_send_json_success( array( 'message' => $message, 'counts' => $result ) );
    }

    public function ajaxAiSuggestPost(): void {
        check_ajax_referer( self::EDITOR_AJAX_NONCE, 'nonce' );
        $postIdRaw = filter_input( INPUT_POST, 'post_id', FILTER_VALIDATE_INT );
        $postId = is_int( $postIdRaw ) ? $postIdRaw : (int) $postIdRaw;
        if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Editing this content requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $rawField = filter_input( INPUT_POST, 'field', FILTER_UNSAFE_RAW );
        $field = is_string( $rawField ) ? sanitize_key( $rawField ) : '';
        $requested = in_array( $field, array( 'focus_keyphrase', 'focus_keyphrases', 'seo_title', 'meta_description' ), true ) ? array( $field ) : array();
        try {
            $service = new MetadataSuggestionService();
            $suggestions = $service->suggestPost( $postId, $requested );
            $meta = $service->lastComputeMeta();
        } catch ( \RuntimeException $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 409 );
        }
        $message = esc_html__( 'AI suggestions are ready for review. Nothing was saved automatically.', 'yablonsky-seo' );
        if ( isset( $meta['mode'] ) && 'provider-failover-chat' === $meta['mode'] ) {
            /* translators: %s: alternate AI model identifier. */
            $message .= ' ' . sprintf( esc_html__( 'Primary provider billing was unavailable, so YSEO used the enabled alternate model %s for this draft.', 'yablonsky-seo' ), esc_html( (string) ( $meta['fallback_to'] ?? $meta['model'] ?? '' ) ) );
        }
        wp_send_json_success( array( 'suggestions' => $suggestions, 'message' => $message ) );
    }

    /** YSEO_228_AI_CONTENT_ASSIST=PASS — explicit, language-safe and review-only. */
    public function ajaxAiContentAssist(): void {
        check_ajax_referer( self::EDITOR_AJAX_NONCE, 'nonce' );
        $postId = absint( filter_input( INPUT_POST, 'post_id', FILTER_SANITIZE_NUMBER_INT ) );
        if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Requesting AI assistance for this content requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $modeInput = filter_input( INPUT_POST, 'mode', FILTER_UNSAFE_RAW );
        $mode = is_string( $modeInput ) ? sanitize_key( $modeInput ) : '';
        try {
            $result = ( new ContentAssistService() )->assistPost( $postId, $mode );
            wp_send_json_success( array(
                'mode' => (string) $result['mode'],
                'language' => (string) $result['language'],
                'text' => (string) $result['text'],
                'message' => esc_html__( 'AI content assistance is ready for human review. Saved WordPress content remains unchanged.', 'yablonsky-seo' ),
            ) );
        } catch ( ServiceError $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 400 );
        } catch ( \Throwable $error ) {
            wp_send_json_error( array( 'message' => esc_html__( 'AI content assistance stopped safely.', 'yablonsky-seo' ) ), 500 );
        }
    }

    public function ajaxAiDiscardDraft(): void {
        check_ajax_referer( self::EDITOR_AJAX_NONCE, 'nonce' );
        $postIdRaw = filter_input( INPUT_POST, 'post_id', FILTER_VALIDATE_INT );
        $postId = is_int( $postIdRaw ) ? $postIdRaw : (int) $postIdRaw;
        if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Editing this content requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        delete_post_meta( $postId, '_yps_ai_metadata_draft' );
        wp_send_json_success( array( 'message' => esc_html__( 'AI review draft discarded. Saved SEO metadata remains unchanged.', 'yablonsky-seo' ) ) );
    }

    public function registerTaxonomyHooks(): void {
        $taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
        foreach ( $taxonomies as $taxonomy ) {
            add_action( $taxonomy . '_add_form_fields', array( $this, 'renderTermAddFields' ) );
            add_action( $taxonomy . '_edit_form_fields', array( $this, 'renderTermEditFields' ), 10, 2 );
            add_action( 'created_' . $taxonomy, array( $this, 'saveTermFields' ) );
            add_action( 'edited_' . $taxonomy, array( $this, 'saveTermFields' ) );
        }
    }

    public function renderTermAddFields(): void {
        wp_nonce_field( self::TERM_NONCE, 'yps_seo_term_nonce' );
        foreach ( $this->termFieldDefinitions() as $name => $field ) {
            echo '<div class="form-field yps-taxonomy-seo-field">';
            $this->renderEditorField( $name, $field, '' );
            echo '</div>';
        }
    }

    public function renderTermEditFields( \WP_Term $term, string $taxonomy ): void {
        unset( $taxonomy );
        wp_nonce_field( self::TERM_NONCE, 'yps_seo_term_nonce' );
        foreach ( $this->termFieldDefinitions() as $name => $field ) {
            $value = get_term_meta( $term->term_id, $field['meta'], true );
            echo '<tr class="form-field yps-taxonomy-seo-field"><th scope="row"><label for="' . esc_attr( 'yps-' . $name ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';
            $this->renderEditorControl( $name, $field, $value );
            echo '</td></tr>';
        }
    }

    public function saveTermFields( int $termId ): void {
        $rawNonce = filter_input( INPUT_POST, 'yps_seo_term_nonce', FILTER_UNSAFE_RAW );
        if ( ! is_string( $rawNonce ) || '' === $rawNonce ) {
            return;
        }
        $nonce = sanitize_text_field( $rawNonce );
        if ( ! wp_verify_nonce( $nonce, self::TERM_NONCE ) ) {
            return;
        }
        $term = get_term( $termId );
        if ( ! $term || is_wp_error( $term ) ) {
            return;
        }
        $taxonomy = get_taxonomy( (string) $term->taxonomy );
        $capability = is_object( $taxonomy ) && isset( $taxonomy->cap->edit_terms ) ? (string) $taxonomy->cap->edit_terms : 'manage_categories';
        if ( ! current_user_can( $capability ) ) {
            return;
        }
        foreach ( $this->termFieldDefinitions() as $name => $field ) {
            $this->saveObjectMetaValue( 'term', $termId, $name, $field );
        }
    }

    public function renderUserFields( \WP_User $user ): void {
        if ( ! current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }
        wp_nonce_field( self::USER_NONCE, 'yps_seo_user_nonce' );
        ?>
        <div class="yps-profile-seo-surface">
        <h2><?php echo esc_html__( 'Yablonsky SEO', 'yablonsky-seo' ); ?></h2>
        <table class="form-table yps-profile-seo-table" role="presentation">
            <tr><th><label for="yps-author-title"><?php echo esc_html__( 'Author SEO title', 'yablonsky-seo' ); ?></label></th><td><input class="regular-text" id="yps-author-title" name="yps_author_title" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, '_yps_author_seo_title', true ) ); ?>" /></td></tr>
            <tr><th><label for="yps-author-description"><?php echo esc_html__( 'Author meta description', 'yablonsky-seo' ); ?></label></th><td><textarea class="large-text" rows="3" id="yps-author-description" name="yps_author_description"><?php echo esc_textarea( (string) get_user_meta( $user->ID, '_yps_author_meta_description', true ) ); ?></textarea></td></tr>
            <tr><th><label for="yps-author-job-title"><?php echo esc_html__( 'Job title', 'yablonsky-seo' ); ?></label></th><td><input class="regular-text" id="yps-author-job-title" name="yps_author_job_title" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, '_yps_author_job_title', true ) ); ?>" /></td></tr>
            <tr><th><label for="yps-author-image-url"><?php echo esc_html__( 'Person image URL', 'yablonsky-seo' ); ?></label></th><td><input class="regular-text" type="url" id="yps-author-image-url" name="yps_author_image_url" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, '_yps_author_image_url', true ) ); ?>" /></td></tr>
            <tr><th><label for="yps-author-same-as"><?php echo esc_html__( 'External sameAs URLs', 'yablonsky-seo' ); ?></label></th><td><textarea class="large-text" rows="3" id="yps-author-same-as" name="yps_author_same_as"><?php echo esc_textarea( (string) get_user_meta( $user->ID, '_yps_author_same_as', true ) ); ?></textarea><p class="description"><?php echo esc_html__( 'One URL per line. Migrated Yoast social profile fields are also included automatically.', 'yablonsky-seo' ); ?></p></td></tr>
            <tr><th><label for="yps-author-knows-about"><?php echo esc_html__( 'knowsAbout topics', 'yablonsky-seo' ); ?></label></th><td><textarea class="large-text" rows="3" id="yps-author-knows-about" name="yps_author_knows_about"><?php echo esc_textarea( (string) get_user_meta( $user->ID, '_yps_author_knows_about', true ) ); ?></textarea></td></tr>
            <tr><th><?php echo esc_html__( 'Indexability', 'yablonsky-seo' ); ?></th><td><label><input type="checkbox" name="yps_author_noindex" value="1" <?php checked( '1', (string) get_user_meta( $user->ID, '_yps_author_robots_noindex', true ) ); ?> /> <?php echo esc_html__( 'Noindex this author archive', 'yablonsky-seo' ); ?></label></td></tr>
        </table>
        </div>
        <?php
    }

    public function saveUserFields( int $userId ): void {
        $rawNonce = filter_input( INPUT_POST, 'yps_seo_user_nonce', FILTER_UNSAFE_RAW );
        if ( ! is_string( $rawNonce ) || '' === $rawNonce ) {
            return;
        }
        $nonce = sanitize_text_field( $rawNonce );
        if ( ! wp_verify_nonce( $nonce, self::USER_NONCE ) || ! current_user_can( 'edit_user', $userId ) ) {
            return;
        }
        $this->updateOrDeleteUserMeta( $userId, '_yps_author_seo_title', $this->postedText( 'yps_author_title' ) );
        $this->updateOrDeleteUserMeta( $userId, '_yps_author_meta_description', $this->postedTextarea( 'yps_author_description' ) );
        $this->updateOrDeleteUserMeta( $userId, '_yps_author_job_title', $this->postedText( 'yps_author_job_title' ) );
        $this->updateOrDeleteUserMeta( $userId, '_yps_author_image_url', esc_url_raw( $this->postedText( 'yps_author_image_url' ), array( 'http', 'https' ) ) );
        $this->updateOrDeleteUserMeta( $userId, '_yps_author_same_as', $this->postedTextarea( 'yps_author_same_as' ) );
        $this->updateOrDeleteUserMeta( $userId, '_yps_author_knows_about', $this->postedTextarea( 'yps_author_knows_about' ) );
        $this->updateOrDeleteUserMeta( $userId, '_yps_author_robots_noindex', null !== filter_input( INPUT_POST, 'yps_author_noindex', FILTER_UNSAFE_RAW ) ? '1' : '' );
    }

    /** @return array<string,array{meta:string,label:string,type:string}> */
    private function postFieldDefinitions(): array {
        return array(
            'seo_title'           => array( 'meta' => '_yps_seo_title', 'label' => __( 'SEO title', 'yablonsky-seo' ), 'type' => 'text' ),
            'meta_description'    => array( 'meta' => '_yps_meta_description', 'label' => __( 'Meta description', 'yablonsky-seo' ), 'type' => 'textarea' ),
            'canonical'           => array( 'meta' => '_yps_canonical', 'label' => __( 'Canonical URL', 'yablonsky-seo' ), 'type' => 'url' ),
            'noindex'             => array( 'meta' => '_yps_robots_noindex', 'label' => __( 'Noindex', 'yablonsky-seo' ), 'type' => 'checkbox' ),
            'nofollow'            => array( 'meta' => '_yps_robots_nofollow', 'label' => __( 'Nofollow', 'yablonsky-seo' ), 'type' => 'checkbox' ),
            'robots_advanced'     => array( 'meta' => '_yps_robots_advanced', 'label' => __( 'Advanced robots directives', 'yablonsky-seo' ), 'type' => 'text' ),
            'apple_ai_answer_opt_out' => array( 'meta' => '_yps_apple_ai_answer_opt_out', 'label' => __( 'Exclude from Apple AI answer context', 'yablonsky-seo' ), 'type' => 'checkbox' ),
            'focus_keyphrase'     => array( 'meta' => '_yps_focus_keyphrase', 'label' => __( 'Focus keyphrase', 'yablonsky-seo' ), 'type' => 'text' ),
            'og_title'            => array( 'meta' => '_yps_og_title', 'label' => __( 'Open Graph title', 'yablonsky-seo' ), 'type' => 'text' ),
            'og_description'      => array( 'meta' => '_yps_og_description', 'label' => __( 'Open Graph description', 'yablonsky-seo' ), 'type' => 'textarea' ),
            'og_image'            => array( 'meta' => '_yps_og_image', 'label' => __( 'Open Graph image URL', 'yablonsky-seo' ), 'type' => 'url' ),
            'og_type'             => array( 'meta' => '_yps_og_type', 'label' => __( 'Open Graph type', 'yablonsky-seo' ), 'type' => 'og_type' ),
            'twitter_title'       => array( 'meta' => '_yps_twitter_title', 'label' => __( 'Twitter/X title', 'yablonsky-seo' ), 'type' => 'text' ),
            'twitter_description' => array( 'meta' => '_yps_twitter_description', 'label' => __( 'Twitter/X description', 'yablonsky-seo' ), 'type' => 'textarea' ),
            'twitter_image'       => array( 'meta' => '_yps_twitter_image', 'label' => __( 'Twitter/X image URL', 'yablonsky-seo' ), 'type' => 'url' ),
            'redirect_target'     => array( 'meta' => '_yps_redirect_target', 'label' => __( '301 redirect target (same site)', 'yablonsky-seo' ), 'type' => 'text' ),
            'schema_page_type'    => array( 'meta' => '_yps_schema_page_type', 'label' => __( 'Schema page type', 'yablonsky-seo' ), 'type' => 'schema_page' ),
            'schema_article_type' => array( 'meta' => '_yps_schema_article_type', 'label' => __( 'Schema article type', 'yablonsky-seo' ), 'type' => 'schema_article' ),
            'schema_entity_type'  => array( 'meta' => '_yps_schema_entity_type', 'label' => __( 'Primary entity', 'yablonsky-seo' ), 'type' => 'schema_entity' ),
            'schema_entity_name'  => array( 'meta' => '_yps_schema_entity_name', 'label' => __( 'Entity name', 'yablonsky-seo' ), 'type' => 'text' ),
            'schema_entity_description' => array( 'meta' => '_yps_schema_entity_description', 'label' => __( 'Entity description', 'yablonsky-seo' ), 'type' => 'textarea' ),
            'schema_person_job_title' => array( 'meta' => '_yps_schema_person_job_title', 'label' => __( 'Person job title', 'yablonsky-seo' ), 'type' => 'text' ),
            'schema_person_image_url' => array( 'meta' => '_yps_schema_person_image_url', 'label' => __( 'Person image URL', 'yablonsky-seo' ), 'type' => 'url' ),
            'schema_person_same_as' => array( 'meta' => '_yps_schema_person_same_as', 'label' => __( 'Person external sameAs URLs', 'yablonsky-seo' ), 'type' => 'textarea' ),
            'schema_person_knows_about' => array( 'meta' => '_yps_schema_person_knows_about', 'label' => __( 'Person knowsAbout topics', 'yablonsky-seo' ), 'type' => 'textarea' ),
            'schema_service_type' => array( 'meta' => '_yps_schema_service_type', 'label' => __( 'Service type', 'yablonsky-seo' ), 'type' => 'text' ),
            'schema_area_served'  => array( 'meta' => '_yps_schema_area_served', 'label' => __( 'Area served', 'yablonsky-seo' ), 'type' => 'text' ),
            'schema_application_category' => array( 'meta' => '_yps_schema_application_category', 'label' => __( 'Application category', 'yablonsky-seo' ), 'type' => 'text' ),
            'schema_operating_system' => array( 'meta' => '_yps_schema_operating_system', 'label' => __( 'Operating system / platform', 'yablonsky-seo' ), 'type' => 'text' ),
            'schema_offer_price'  => array( 'meta' => '_yps_schema_offer_price', 'label' => __( 'Offer price', 'yablonsky-seo' ), 'type' => 'text' ),
            'schema_offer_currency' => array( 'meta' => '_yps_schema_offer_currency', 'label' => __( 'Offer currency (ISO 4217)', 'yablonsky-seo' ), 'type' => 'text' ),
            'is_cornerstone'       => array( 'meta' => '_yps_is_cornerstone', 'label' => __( 'Cornerstone / pillar content', 'yablonsky-seo' ), 'type' => 'checkbox' ),
            'video_exclude'        => array( 'meta' => '_yps_video_exclude', 'label' => __( 'Exclude this resource from Video SEO', 'yablonsky-seo' ), 'type' => 'checkbox' ),
            'video_content_url'    => array( 'meta' => '_yps_video_content_url', 'label' => __( 'Primary video URL', 'yablonsky-seo' ), 'type' => 'url' ),
            'video_kind'           => array( 'meta' => '_yps_video_kind', 'label' => __( 'Video URL type', 'yablonsky-seo' ), 'type' => 'video_kind' ),
            'video_title'          => array( 'meta' => '_yps_video_title', 'label' => __( 'Video title', 'yablonsky-seo' ), 'type' => 'text' ),
            'video_description'    => array( 'meta' => '_yps_video_description', 'label' => __( 'Video description', 'yablonsky-seo' ), 'type' => 'textarea' ),
            'video_thumbnail'      => array( 'meta' => '_yps_video_thumbnail', 'label' => __( 'Video thumbnail URL', 'yablonsky-seo' ), 'type' => 'url' ),
            'video_duration'       => array( 'meta' => '_yps_video_duration', 'label' => __( 'Video duration in seconds', 'yablonsky-seo' ), 'type' => 'text' ),
            'news_enabled'         => array( 'meta' => '_yps_news_enabled', 'label' => __( 'Include in News SEO', 'yablonsky-seo' ), 'type' => 'checkbox' ),
            'news_exclude'         => array( 'meta' => '_yps_news_exclude', 'label' => __( 'Exclude from Google News sitemap', 'yablonsky-seo' ), 'type' => 'checkbox' ),
            'news_stock_tickers'   => array( 'meta' => '_yps_news_stock_tickers', 'label' => __( 'News stock tickers', 'yablonsky-seo' ), 'type' => 'textarea' ),
            'schema_access_restricted' => array( 'meta' => '_yps_schema_access_restricted', 'label' => __( 'Access restricted / paywalled content', 'yablonsky-seo' ), 'type' => 'checkbox' ),
        );
    }

    /** @return array<string,array{meta:string,label:string,type:string}> */
    private function termFieldDefinitions(): array {
        return array(
            'seo_title'        => array( 'meta' => '_yps_seo_title', 'label' => __( 'SEO title', 'yablonsky-seo' ), 'type' => 'text' ),
            'meta_description' => array( 'meta' => '_yps_meta_description', 'label' => __( 'Meta description', 'yablonsky-seo' ), 'type' => 'textarea' ),
            'canonical'        => array( 'meta' => '_yps_canonical', 'label' => __( 'Canonical URL', 'yablonsky-seo' ), 'type' => 'url' ),
            'noindex'          => array( 'meta' => '_yps_robots_noindex', 'label' => __( 'Noindex', 'yablonsky-seo' ), 'type' => 'checkbox' ),
            'og_title'         => array( 'meta' => '_yps_og_title', 'label' => __( 'Open Graph title', 'yablonsky-seo' ), 'type' => 'text' ),
            'og_description'   => array( 'meta' => '_yps_og_description', 'label' => __( 'Open Graph description', 'yablonsky-seo' ), 'type' => 'textarea' ),
            'og_image'         => array( 'meta' => '_yps_og_image', 'label' => __( 'Open Graph image URL', 'yablonsky-seo' ), 'type' => 'url' ),
        );
    }

    /** @param array<string,mixed> $analysis */
    private function renderOptimizationSummary( array $analysis ): void {
        $dimensions = isset( $analysis['dimensions'] ) && is_array( $analysis['dimensions'] ) ? $analysis['dimensions'] : array();
        echo '<div class="yps-optimization-summary" data-yps-analysis-summary aria-label="' . esc_attr__( 'Content optimization readiness', 'yablonsky-seo' ) . '">';
        foreach ( array( 'search', 'answer', 'ai', 'language' ) as $key ) {
            if ( empty( $dimensions[ $key ] ) || ! is_array( $dimensions[ $key ] ) ) {
                continue;
            }
            $dimension = $dimensions[ $key ];
            $status = in_array( (string) ( $dimension['status'] ?? '' ), array( 'good', 'attention', 'critical' ), true ) ? (string) $dimension['status'] : 'attention';
            $score = max( 0, min( 100, (int) ( $dimension['score'] ?? 0 ) ) );
            echo '<button type="button" class="yps-optimization-score is-' . esc_attr( $status ) . '" data-yps-score-jump="' . esc_attr( $key ) . '">';
            echo '<span class="yps-score-dot" aria-hidden="true"></span><span class="yps-score-copy"><strong>' . esc_html( (string) ( $dimension['label'] ?? ucfirst( $key ) ) ) . '</strong><small>' . esc_html( (string) ( $dimension['subtitle'] ?? '' ) ) . '</small></span><b>' . esc_html( (string) $score ) . '</b>';
            echo '</button>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $analysis */
    private function renderOptimizationChecks( array $analysis, string $dimensionKey ): void {
        $dimension = isset( $analysis['dimensions'][ $dimensionKey ] ) && is_array( $analysis['dimensions'][ $dimensionKey ] ) ? $analysis['dimensions'][ $dimensionKey ] : array();
        $checks = isset( $dimension['checks'] ) && is_array( $dimension['checks'] ) ? $dimension['checks'] : array();
        echo '<div class="yps-analysis-dimension" data-yps-analysis-dimension="' . esc_attr( $dimensionKey ) . '">';
        echo '<div class="yps-analysis-head"><div><span class="yps-card-kicker">' . esc_html__( 'Optimization analysis', 'yablonsky-seo' ) . '</span><h3>' . esc_html( (string) ( $dimension['subtitle'] ?? '' ) ) . '</h3></div><span class="yps-analysis-score is-' . esc_attr( (string) ( $dimension['status'] ?? 'attention' ) ) . '">' . esc_html( (string) (int) ( $dimension['score'] ?? 0 ) ) . '/100</span></div>';
        $scoreCap = max( 0, min( 100, (int) ( $dimension['score_cap'] ?? 100 ) ) );
        $rawScore = max( 0, min( 100, (int) ( $dimension['raw_score'] ?? ( $dimension['score'] ?? 0 ) ) ) );
        if ( 'search' === $dimensionKey ) {
            echo '<div class="yps-score-method">' . esc_html__( 'Scoring: each applicable SEO check is rated on a 1–9 assessment scale and normalized to 100. Critical prerequisites can cap the displayed score.', 'yablonsky-seo' ) . '</div>';
        } else {
            echo '<div class="yps-score-method">' . esc_html__( 'Scoring: good = full check weight, improvement = half, problem = zero. Critical prerequisites can cap the displayed score.', 'yablonsky-seo' ) . '</div>';
        }
        if ( $scoreCap < 100 ) {
            if ( 'search' === $dimensionKey ) {
                /* translators: 1: normalized assessment readiness score, 2: displayed score ceiling while a critical prerequisite is missing. */
                echo '<div class="yps-score-ceiling"><strong>' . esc_html__( 'Critical gate', 'yablonsky-seo' ) . '</strong><span>' . esc_html( sprintf( __( 'Assessment readiness is %1$d/100. The displayed score is capped at %2$d/100 while the critical prerequisite below remains open; the cap acts as a gate while field point values remain separate.', 'yablonsky-seo' ), $rawScore, $scoreCap ) ) . '</span></div>';
            } else {
                /* translators: 1: weighted raw readiness score, 2: displayed score ceiling while a critical prerequisite is missing. */
                echo '<div class="yps-score-ceiling"><strong>' . esc_html__( 'Critical gate', 'yablonsky-seo' ) . '</strong><span>' . esc_html( sprintf( __( 'Weighted readiness is %1$d/100, but the displayed score is capped at %2$d/100 until the critical prerequisite below is fixed. The cap represents a readiness gate across prerequisites.', 'yablonsky-seo' ), $rawScore, $scoreCap ) ) . '</span></div>';
            }
        }
        $groups = array(
            'critical'  => array( 'label' => __( 'Problems', 'yablonsky-seo' ), 'checks' => array() ),
            'attention' => array( 'label' => __( 'Improvements', 'yablonsky-seo' ), 'checks' => array() ),
            'good'      => array( 'label' => __( 'Good results', 'yablonsky-seo' ), 'checks' => array() ),
        );
        foreach ( $checks as $check ) {
            if ( ! is_array( $check ) ) {
                continue;
            }
            $status = in_array( (string) ( $check['status'] ?? '' ), array( 'good', 'attention', 'critical' ), true ) ? (string) $check['status'] : 'attention';
            $groups[ $status ]['checks'][] = $check;
        }
        echo '<div class="yps-analysis-groups">';
        foreach ( $groups as $status => $group ) {
            if ( empty( $group['checks'] ) ) {
                continue;
            }
            echo '<section class="yps-analysis-group is-' . esc_attr( $status ) . '"><h4><span class="yps-analysis-dot" aria-hidden="true"></span>' . esc_html( (string) $group['label'] ) . ' <b>' . esc_html( (string) count( $group['checks'] ) ) . '</b></h4><div class="yps-analysis-list">';
            foreach ( $group['checks'] as $check ) {
                if ( 'search' === $dimensionKey && isset( $check['assessment_score'] ) ) {
                    $earned = max( 0.0, min( 9.0, (float) $check['assessment_score'] ) );
                    $weight = 9.0;
                } else {
                    $weight = max( 0.0, (float) ( $check['weight'] ?? 0 ) );
                    $earned = 'good' === $status ? $weight : ( 'attention' === $status ? $weight * 0.5 : 0.0 );
                }
                $earnedLabel = 0.0 === fmod( $earned, 1.0 ) ? (string) (int) $earned : number_format_i18n( $earned, 1 );
                $weightLabel = 0.0 === fmod( $weight, 1.0 ) ? (string) (int) $weight : number_format_i18n( $weight, 1 );
                /* translators: 1: earned points for this check, 2: maximum points for this check. */
                $pointsLabel = sprintf( __( '%1$s/%2$s pts', 'yablonsky-seo' ), $earnedLabel, $weightLabel );
                echo '<div class="yps-analysis-item is-' . esc_attr( $status ) . '"><span class="yps-analysis-dot" aria-hidden="true"></span><div><strong>' . esc_html( (string) ( $check['title'] ?? '' ) ) . '</strong><span>' . esc_html( (string) ( $check['message'] ?? '' ) ) . '</span></div><em class="yps-analysis-points">' . esc_html( $pointsLabel ) . '</em></div>';
            }
            echo '</div></section>';
        }
        echo '</div>';
        if ( isset( $analysis['facts'] ) && is_array( $analysis['facts'] ) ) {
            $facts = $analysis['facts'];
            echo '<div class="yps-analysis-facts">';
            /* translators: %d: word count. */
            echo '<span>' . esc_html( sprintf( __( '%d words', 'yablonsky-seo' ), (int) ( $facts['word_count'] ?? 0 ) ) ) . '</span>';
            /* translators: %d: heading count. */
            echo '<span>' . esc_html( sprintf( __( '%d headings', 'yablonsky-seo' ), (int) ( $facts['heading_count'] ?? 0 ) ) ) . '</span>';
            /* translators: %d: internal link count. */
            echo '<span>' . esc_html( sprintf( __( '%d internal links', 'yablonsky-seo' ), (int) ( $facts['internal_link_count'] ?? 0 ) ) ) . '</span>';
            /* translators: %d: external link count. */
            echo '<span>' . esc_html( sprintf( __( '%d external links', 'yablonsky-seo' ), (int) ( $facts['external_link_count'] ?? 0 ) ) ) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }

    /** @param array{meta:string,label:string,type:string} $field @param mixed $value */
    private function renderEditorField( string $name, array $field, $value ): void {
        printf( '<p class="yps-taxonomy-field-content"><label for="%1$s"><strong>%2$s</strong></label><br />', esc_attr( 'yps-' . $name ), esc_html( $field['label'] ) );
        $this->renderEditorControl( $name, $field, $value );
        echo '</p>';
    }

    /** @param array{meta:string,label:string,type:string} $field @param mixed $value */
    private function renderEditorControl( string $name, array $field, $value ): void {
        $id = 'yps-' . $name;
        $inputName = 'yps_' . $name;
        if ( 'textarea' === $field['type'] ) {
            printf( '<textarea class="large-text" rows="3" id="%1$s" name="%2$s">%3$s</textarea>', esc_attr( $id ), esc_attr( $inputName ), esc_textarea( (string) $value ) );
        } elseif ( 'checkbox' === $field['type'] ) {
            printf( '<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>', esc_attr( $id ), esc_attr( $inputName ), checked( '1', (string) $value, false ), esc_html__( 'Enabled', 'yablonsky-seo' ) );
        } elseif ( in_array( $field['type'], array( 'schema_page', 'schema_article', 'schema_entity', 'og_type', 'video_kind' ), true ) ) {
            if ( 'schema_page' === $field['type'] ) {
                $options = $this->schemaPageTypes();
            } elseif ( 'schema_article' === $field['type'] ) {
                $options = $this->schemaArticleTypes();
            } elseif ( 'schema_entity' === $field['type'] ) {
                $options = $this->schemaEntityTypes();
            } elseif ( 'video_kind' === $field['type'] ) {
                $options = array( '' => __( 'Auto-detect', 'yablonsky-seo' ), 'content' => __( 'Direct video file', 'yablonsky-seo' ), 'player' => __( 'Embedded player URL', 'yablonsky-seo' ) );
            } else {
                $options = $this->openGraphTypes();
            }
            echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $inputName ) . '">';
            foreach ( $options as $optionValue => $optionLabel ) {
                echo '<option value="' . esc_attr( $optionValue ) . '" ' . selected( (string) $value, $optionValue, false ) . '>' . esc_html( $optionLabel ) . '</option>';
            }
            echo '</select>';
        } else {
            printf( '<input class="large-text" type="%1$s" id="%2$s" name="%3$s" value="%4$s" />', esc_attr( 'url' === $field['type'] ? 'url' : 'text' ), esc_attr( $id ), esc_attr( $inputName ), esc_attr( (string) $value ) );
        }
    }

    /** @param array{meta:string,label:string,type:string} $field @param mixed $value */
    private function renderCompactEditorField( string $name, array $field, $value, bool $wide = false, int $recommended = 0, bool $aiAssist = false ): void {
        $class = 'yps-editor-field' . ( $wide ? ' is-wide' : '' );
        $controlClass = 'yseo-ai-control-row' . ( 'textarea' === $field['type'] ? ' is-textarea' : '' );
        echo '<div class="' . esc_attr( $class ) . '" data-yps-field="' . esc_attr( $name ) . '"><label class="yps-label" for="' . esc_attr( 'yps-' . $name ) . '">' . esc_html( $field['label'] ) . '</label>';
        echo '<div class="' . esc_attr( $controlClass ) . '">';
        $this->renderEditorControl( $name, $field, $value );
        if ( in_array( $name, array( 'og_image', 'twitter_image' ), true ) ) {
            echo '<button type="button" class="button button-primary yps-media-inline-button" data-yps-media-upload data-yps-media-target="' . esc_attr( 'yps-' . $name ) . '">' . esc_html__( 'Upload image', 'yablonsky-seo' ) . '</button> '; echo '<button type="button" class="button yps-media-inline-button" data-yps-media-select data-yps-media-target="' . esc_attr( 'yps-' . $name ) . '">' . esc_html__( 'Media Library', 'yablonsky-seo' ) . '</button>';
            echo '<button type="button" class="button yps-media-inline-button" data-yps-media-clear data-yps-media-target="' . esc_attr( 'yps-' . $name ) . '">' . esc_html__( 'Clear', 'yablonsky-seo' ) . '</button>';
        }
        if ( $aiAssist ) {
            echo '<button type="button" class="button yseo-ai-mini-button yps-ai-field-button" data-yps-ai-field="' . esc_attr( $name ) . '" title="' . esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ) . '" aria-label="' . esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ) . '">' . esc_html__( 'AI', 'yablonsky-seo' ) . '</button>';
        }
        echo '</div>';
        if ( in_array( $name, array( 'seo_title', 'meta_description' ), true ) ) {
            if ( 'seo_title' === $name ) {
                $low = 20; $min = 30; $idealMax = 60; $max = 70;
                $fitHelp = __( 'Practical display-fit target: 30–60 characters. Google adapts title display to device width and may truncate the visible text.', 'yablonsky-seo' );
            } else {
                $low = 50; $min = 80; $idealMax = 155; $max = 170;
                $fitHelp = __( 'Practical display-fit target: 80–155 characters. Google adapts snippet display to device width and may truncate the visible text.', 'yablonsky-seo' );
            }
            echo '<div class="yps-serp-meter is-critical" data-yps-serp-meter data-yps-count-for="' . esc_attr( 'yps_' . $name ) . '" data-yps-low="' . esc_attr( (string) $low ) . '" data-yps-min="' . esc_attr( (string) $min ) . '" data-yps-ideal-max="' . esc_attr( (string) $idealMax ) . '" data-yps-max="' . esc_attr( (string) $max ) . '">';
            echo '<div class="yps-serp-meter-head"><span data-yps-serp-label>' . esc_html__( 'Ready for input', 'yablonsky-seo' ) . '</span><strong><span data-yps-serp-count>0</span> ' . esc_html__( 'characters', 'yablonsky-seo' ) . '</strong></div>';
            echo '<div class="yps-serp-meter-track" role="progressbar" aria-valuemin="0" aria-valuemax="' . esc_attr( (string) $max ) . '" aria-valuenow="0"><i></i></div>';
            echo '<small>' . esc_html( $fitHelp ) . '</small></div>';
        } elseif ( $recommended > 0 ) {
            /* translators: %d: recommended maximum character count for the SEO field. */
            $recommendation = sprintf( __( 'Recommended ≤ %d characters', 'yablonsky-seo' ), $recommended );
            echo '<div class="yps-field-meta"><span>' . esc_html( $recommendation ) . '</span><span><span data-yps-count-for="' . esc_attr( 'yps_' . $name ) . '">0</span></span></div>';
        }
        echo '</div>';
    }

    /** @return array<string,string> */
    private function readAiMetadataDraft( int $postId ): array {
        $raw = get_post_meta( $postId, '_yps_ai_metadata_draft', true );
        if ( ! is_string( $raw ) || '' === $raw ) { return array(); }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || ! isset( $data['values'] ) || ! is_array( $data['values'] ) ) { return array(); }
        $allowed = array( 'focus_keyphrase' => 200, 'focus_keyphrases' => 800, 'seo_title' => 200, 'meta_description' => 500 );
        $clean = array();
        foreach ( $allowed as $field => $max ) {
            if ( ! isset( $data['values'][ $field ] ) ) { continue; }
            if ( 'focus_keyphrases' === $field && is_array( $data['values'][ $field ] ) ) {
                $parts = array();
                foreach ( $data['values'][ $field ] as $item ) { if ( is_scalar( $item ) ) { $parts[] = (string) $item; } }
                $rawValue = implode( "\n", $parts );
            } elseif ( is_scalar( $data['values'][ $field ] ) ) { $rawValue = (string) $data['values'][ $field ]; }
            else { continue; }
            $value = trim( wp_strip_all_tags( $rawValue, true ) );
            if ( '' === $value ) { continue; }
            $clean[ $field ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
        }
        return $clean;
    }

    /** @return array<int,string> */
    private function relatedKeyphrasesFromRaw( string $raw, string $primary = '' ): array {
        $parts = preg_split( '/[\r\n,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
        $primaryKey = $this->normalizedKeyphraseKey( $primary );
        $clean = array();
        $seen = array();
        foreach ( $parts as $part ) {
            $value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $part, true ) ) ?? '' );
            if ( '' === $value ) { continue; }
            $key = $this->normalizedKeyphraseKey( $value );
            if ( '' === $key || $key === $primaryKey || isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $clean[] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 200, 'UTF-8' ) : substr( $value, 0, 200 );
            if ( count( $clean ) >= 4 ) { break; }
        }
        return $clean;
    }

    /** @return array<int,string> */
    private function relatedKeyphrasesForPost( int $postId, string $primary = '' ): array {
        $raw = get_post_meta( $postId, '_yps_focus_keyphrases_json', true );
        $items = array();
        if ( is_string( $raw ) && '' !== trim( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $walker = function ( $value ) use ( &$items, &$walker ): void {
                    if ( is_scalar( $value ) ) {
                        $text = trim( wp_strip_all_tags( (string) $value, true ) );
                        if ( '' !== $text ) { $items[] = $text; }
                        return;
                    }
                    if ( is_array( $value ) ) {
                        foreach ( $value as $child ) { $walker( $child ); }
                    }
                };
                $walker( $decoded );
            } else {
                $items = preg_split( '/[\r\n,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
            }
        } elseif ( is_array( $raw ) ) {
            foreach ( $raw as $value ) { if ( is_scalar( $value ) ) { $items[] = (string) $value; } }
        }
        $primaryKey = $this->normalizedKeyphraseKey( $primary );
        $clean = array();
        $seen = array();
        foreach ( $items as $item ) {
            $item = trim( wp_strip_all_tags( (string) $item, true ) );
            if ( '' === $item ) { continue; }
            $key = $this->normalizedKeyphraseKey( $item );
            if ( '' === $key || $key === $primaryKey || isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $clean[] = function_exists( 'mb_substr' ) ? mb_substr( $item, 0, 200, 'UTF-8' ) : substr( $item, 0, 200 );
            if ( count( $clean ) >= 4 ) { break; }
        }
        return $clean;
    }

    private function renderRelatedKeyphrasesField( int $postId, string $primary, bool $aiConfigured ): void {
        $value = implode( "\n", $this->relatedKeyphrasesForPost( $postId, $primary ) );
        echo '<div class="yps-editor-field is-wide" data-yps-field="focus_keyphrases">';
        echo '<label class="yps-label" for="yps-focus-keyphrases">' . esc_html__( 'Related keyphrases (up to 4)', 'yablonsky-seo' ) . '</label>';
        echo '<div class="yseo-ai-control-row is-textarea">';
        echo '<textarea class="large-text" rows="3" id="yps-focus-keyphrases" name="yps_focus_keyphrases" placeholder="' . esc_attr__( 'One related keyphrase per line', 'yablonsky-seo' ) . '">' . esc_textarea( $value ) . '</textarea>';
        echo '<button type="button" class="button yseo-ai-mini-button yps-ai-field-button" data-yps-ai-field="focus_keyphrases" title="' . esc_attr__( 'Draft related keyphrases with Yablonsky AI', 'yablonsky-seo' ) . '" aria-label="' . esc_attr__( 'Draft related keyphrases with Yablonsky AI', 'yablonsky-seo' ) . '" ' . disabled( ! $aiConfigured, true, false ) . '>' . esc_html__( 'AI', 'yablonsky-seo' ) . '</button>';
        echo '</div>';
        echo '<small class="yps-field-help">' . esc_html__( 'The primary keyphrase remains the scoring anchor. Add up to four supporting phrases so YSEO can review topical coverage while keeping title wording editorially natural.', 'yablonsky-seo' ) . '</small>';
        echo '</div>';
    }

    private function saveRelatedKeyphrases( int $postId ): void {
        $raw = filter_input( INPUT_POST, 'yps_focus_keyphrases', FILTER_UNSAFE_RAW );
        $raw = is_string( $raw ) ? wp_unslash( $raw ) : '';
        $primary = (string) get_post_meta( $postId, '_yps_focus_keyphrase', true );
        $primaryKey = $this->normalizedKeyphraseKey( $primary );
        $parts = preg_split( '/[\r\n,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
        $clean = array();
        $seen = array();
        foreach ( $parts as $part ) {
            $value = trim( wp_strip_all_tags( (string) $part, true ) );
            if ( '' === $value ) { continue; }
            $key = $this->normalizedKeyphraseKey( $value );
            if ( '' === $key || $key === $primaryKey || isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $clean[] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 200, 'UTF-8' ) : substr( $value, 0, 200 );
            if ( count( $clean ) >= 4 ) { break; }
        }
        if ( empty( $clean ) ) { delete_post_meta( $postId, '_yps_focus_keyphrases_json' ); return; }
        update_post_meta( $postId, '_yps_focus_keyphrases_json', wp_json_encode( array_values( $clean ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    }

    private function normalizedKeyphraseKey( string $value ): string {
        $value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value, true ) ) ?? '' );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
    }

    /** @return array<string,string> */
    private function schemaPageTypes(): array {
        return array(
            '' => __( 'Default — WebPage', 'yablonsky-seo' ),
            'webpage' => 'WebPage',
            'aboutpage' => 'AboutPage',
            'contactpage' => 'ContactPage',
            'collectionpage' => 'CollectionPage',
            'checkoutpage' => 'CheckoutPage',
            'faqpage' => 'FAQPage',
            'itempage' => 'ItemPage',
            'medicalwebpage' => 'MedicalWebPage',
            'profilepage' => 'ProfilePage',
            'searchresultspage' => 'SearchResultsPage',
        );
    }

    /** @return array<string,string> */
    private function schemaEntityTypes(): array {
        return array(
            '' => __( 'Default', 'yablonsky-seo' ),
            'service' => 'Service',
            'software_application' => 'SoftwareApplication',
            'person' => 'Person',
        );
    }

    /** @return array<string,string> */
    private function openGraphTypes(): array {
        return array(
            '' => __( 'Auto', 'yablonsky-seo' ),
            'website' => 'website',
            'article' => 'article',
        );
    }

    /** @return array<string,string> */
    private function schemaArticleTypes(): array {
        return array(
            '' => __( 'Default — Article', 'yablonsky-seo' ),
            'article' => 'Article',
            'newsarticle' => 'NewsArticle',
            'blogposting' => 'BlogPosting',
            'report' => 'Report',
            'scholarlyarticle' => 'ScholarlyArticle',
            'socialmediaposting' => 'SocialMediaPosting',
            'techarticle' => 'TechArticle',
        );
    }

    /** @param array{meta:string,label:string,type:string} $field */
    private function saveObjectMetaValue( string $objectType, int $objectId, string $name, array $field ): void {
        $inputName = 'yps_' . $name;
        if ( 'checkbox' === $field['type'] ) {
            $value = null !== filter_input( INPUT_POST, $inputName, FILTER_UNSAFE_RAW ) ? '1' : '';
        } elseif ( 'textarea' === $field['type'] ) {
            $value = $this->postedTextarea( $inputName );
        } else {
            $value = $this->postedText( $inputName );
            if ( 'url' === $field['type'] && '' !== $value ) {
                $value = esc_url_raw( $value, array( 'http', 'https' ) );
            }
        }
        if ( 'post' === $objectType ) {
            if ( in_array( $name, array( 'seo_title', 'meta_description' ), true ) ) {
                $value = $this->preserveTemplateSourceWhenUnchanged( $objectId, $field['meta'], $value );
            }
            $this->updateOrDeletePostMeta( $objectId, $field['meta'], $value );
        } else {
            $this->updateOrDeleteTermMeta( $objectId, $field['meta'], $value );
        }
    }

    private function hasTemplateTokens( string $value ): bool {
        return 1 === preg_match( '/%%[A-Za-z0-9_-]+%%/', $value );
    }

    /**
     * Keep a migrated dynamic template in storage when the human-visible resolved value
     * was submitted unchanged. Any actual edit intentionally replaces the template with
     * explicit authored metadata.
     */
    private function preserveTemplateSourceWhenUnchanged( int $postId, string $metaKey, string $submittedValue ): string {
        $storedValue = (string) get_post_meta( $postId, $metaKey, true );
        if ( ! $this->hasTemplateTokens( $storedValue ) ) {
            return $submittedValue;
        }
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post ) {
            return $submittedValue;
        }
        $permalink = get_permalink( $postId );
        $permalink = is_string( $permalink ) ? $permalink : home_url( '/' );
        $resourceType = 'product' === $post->post_type ? 'product' : 'post';
        $resource = new ResourceIdentity( $resourceType, $postId, (string) $post->post_type, '', $permalink );
        $resolved = ( new TemplateResolver() )->resolve( $storedValue, $resource );
        return trim( $submittedValue ) === trim( $resolved ) ? $storedValue : $submittedValue;
    }

    /** @param array<string,mixed> $settings */
    /** @param array<string,mixed> $settings */
    private function crawlToggle( string $name, string $label, string $description, array $settings ): void {
        $id = 'yps-' . str_replace( '_', '-', $name );
        ?>
        <label class="yps-crawl-toggle" for="<?php echo esc_attr( $id ); ?>"><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $settings[ $name ] ) ); ?>><span><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html( $description ); ?></small></span></label>
        <?php
    }

    private function settingField( string $key, string $label, array $settings, string $type = 'text', bool $aiAssist = false ): void {
        echo '<div class="yps-setting-field">';
        $this->settingFieldContents( $key, $label, $settings, $type, $aiAssist );
        echo '</div>';
    }

    /** @param array<string,mixed> $settings */
    private function settingFieldContents( string $key, string $label, array $settings, string $type = 'text', bool $aiAssist = false ): void {
        $aiAssist = $aiAssist || SettingsAuthoringRegistry::supportsText( $key );
        $value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
        echo '<label for="' . esc_attr( 'yps-' . $key ) . '">' . esc_html( $label ) . '</label>';
        echo '<div class="yseo-ai-control-row' . ( 'textarea' === $type ? ' is-textarea' : '' ) . '">';
        if ( 'textarea' === $type ) {
            echo '<textarea rows="3" id="' . esc_attr( 'yps-' . $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';
        } else {
            echo '<input type="' . esc_attr( 'url' === $type ? 'url' : 'text' ) . '" id="' . esc_attr( 'yps-' . $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
        }
        if ( $aiAssist ) {
            echo '<button type="button" class="button yseo-ai-mini-button yseo-ai-setting-field" data-yseo-ai-setting-field="' . esc_attr( $key ) . '" title="' . esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ) . '" aria-label="' . esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ) . '">' . esc_html__( 'AI', 'yablonsky-seo' ) . '</button>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $settings */
    private function renderSocialTemplateSettings( array $settings ): void {
        $templates = isset( $settings['social_templates'] ) && is_array( $settings['social_templates'] ) ? $settings['social_templates'] : array();
        $groups = array();
        $postTypes = get_post_types( array( 'public' => true ), 'objects' );
        foreach ( $postTypes as $postType => $object ) {
            if ( 'attachment' === $postType || ! is_object( $object ) ) { continue; }
            $label = isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : (string) $postType;
            $groups['singular'][ $postType ] = $label;
            if ( ! empty( $object->has_archive ) ) {
                $groups['archive'][ $postType ] = isset( $object->labels->name ) ? (string) $object->labels->name : $label;
            }
        }
        $taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
        foreach ( $taxonomies as $taxonomy => $object ) {
            if ( ! is_object( $object ) ) { continue; }
            $groups['taxonomy'][ $taxonomy ] = isset( $object->labels->name ) ? (string) $object->labels->name : (string) $taxonomy;
        }
        $groups['special'] = array(
            'posts_page' => __( 'Posts page', 'yablonsky-seo' ),
            'author' => __( 'Author archives', 'yablonsky-seo' ),
            'date' => __( 'Date archives', 'yablonsky-seo' ),
        );
        $groupLabels = array(
            'singular' => __( 'Singular content', 'yablonsky-seo' ),
            'archive' => __( 'Post type archives', 'yablonsky-seo' ),
            'taxonomy' => __( 'Taxonomy archives', 'yablonsky-seo' ),
            'special' => __( 'Special archives', 'yablonsky-seo' ),
        );
        $familyCount = 0;
        $configuredCount = 0;
        foreach ( $groups as $group => $rows ) {
            foreach ( $rows as $key => $label ) {
                unset( $label );
                ++$familyCount;
                $row = isset( $templates[ $group ][ $key ] ) && is_array( $templates[ $group ][ $key ] ) ? $templates[ $group ][ $key ] : array();
                if ( ! empty( array_filter( $row, static function ( $value ): bool { return is_scalar( $value ) && '' !== trim( (string) $value ); } ) ) ) { ++$configuredCount; }
            }
        }
        echo '<div class="yps-social-template-workspace" data-yseo-social-template-list><div class="yps-social-template-workspace-summary"><div><strong>' . esc_html__( 'Reusable template workspace', 'yablonsky-seo' ) . '</strong>';
        /* translators: 1: number of available template families, 2: number with at least one configured value. */
        echo '<span>' . esc_html( sprintf( __( '%1$d families · %2$d configured', 'yablonsky-seo' ), $familyCount, $configuredCount ) ) . '</span></div><div class="yps-inline-actions"><button type="button" class="button button-primary" data-yseo-ai-social-template-all>' . esc_html__( 'AI draft all open text fields', 'yablonsky-seo' ) . '</button><button type="button" class="button" data-yseo-social-expand>' . esc_html__( 'Expand all', 'yablonsky-seo' ) . '</button><button type="button" class="button" data-yseo-social-collapse>' . esc_html__( 'Collapse all', 'yablonsky-seo' ) . '</button></div></div><p>' . esc_html__( 'Open any family to review or override its Open Graph and X defaults. AI drafts remain unsaved until you review and save this Settings form.', 'yablonsky-seo' ) . '</p>';
        foreach ( $groups as $group => $rows ) {
            if ( empty( $rows ) ) { continue; }
            echo '<div class="yps-social-template-group"><h3>' . esc_html( $groupLabels[ $group ] ?? $group ) . '</h3>';
            foreach ( $rows as $key => $label ) {
                $row = isset( $templates[ $group ][ $key ] ) && is_array( $templates[ $group ][ $key ] ) ? $templates[ $group ][ $key ] : array();
                $base = 'social_templates[' . $group . '][' . $key . ']';
                $idBase = 'yps-social-template-' . sanitize_html_class( $group . '-' . $key );
                echo '<details class="yps-social-template-row" data-yseo-ai-social-row><summary><strong>' . esc_html( $label ) . '</strong><code>' . esc_html( $key ) . '</code></summary><div class="yps-social-template-row-actions"><button type="button" class="button yseo-ai-social-template-row" data-yseo-ai-social-template-row data-yseo-ai-social-group="' . esc_attr( $group ) . '" data-yseo-ai-social-key="' . esc_attr( (string) $key ) . '">' . esc_html__( 'AI draft open text', 'yablonsky-seo' ) . '</button></div><div class="yps-field-grid yps-field-grid-two">';
                $this->socialTemplateTextField( $idBase . '-og-title', $base . '[og_title]', __( 'Open Graph title template', 'yablonsky-seo' ), (string) ( $row['og_title'] ?? '' ), $group, (string) $key, 'og_title' );
                $this->socialTemplateTextField( $idBase . '-x-title', $base . '[twitter_title]', __( 'X title template', 'yablonsky-seo' ), (string) ( $row['twitter_title'] ?? '' ), $group, (string) $key, 'twitter_title' );
                $this->socialTemplateTextField( $idBase . '-og-description', $base . '[og_description]', __( 'Open Graph description template', 'yablonsky-seo' ), (string) ( $row['og_description'] ?? '' ), $group, (string) $key, 'og_description' );
                $this->socialTemplateTextField( $idBase . '-x-description', $base . '[twitter_description]', __( 'X description template', 'yablonsky-seo' ), (string) ( $row['twitter_description'] ?? '' ), $group, (string) $key, 'twitter_description' );
                $this->socialTemplateMediaField( $idBase . '-og-image', $base . '[og_image]', __( 'Open Graph default image', 'yablonsky-seo' ), (string) ( $row['og_image'] ?? '' ), $group, (string) $key, 'og_image' );
                $this->socialTemplateMediaField( $idBase . '-x-image', $base . '[twitter_image]', __( 'X default image', 'yablonsky-seo' ), (string) ( $row['twitter_image'] ?? '' ), $group, (string) $key, 'twitter_image' );
                echo '</div></details>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private function socialTemplateTextField( string $id, string $name, string $label, string $value, string $group, string $key, string $slot ): void {
        echo '<div class="yps-setting-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><div class="yseo-ai-control-row"><input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" /><button type="button" class="button yseo-ai-mini-button yseo-ai-social-template-field" data-yseo-ai-social-template-field data-yseo-ai-social-group="' . esc_attr( $group ) . '" data-yseo-ai-social-key="' . esc_attr( $key ) . '" data-yseo-ai-social-slot="' . esc_attr( $slot ) . '" data-yseo-ai-social-target="' . esc_attr( $id ) . '" title="' . esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ) . '" aria-label="' . esc_attr__( 'Draft this field with Yablonsky AI', 'yablonsky-seo' ) . '">' . esc_html__( 'AI', 'yablonsky-seo' ) . '</button></div></div>';
    }

    private function socialTemplateMediaField( string $id, string $name, string $label, string $value, string $group, string $key, string $slot ): void {
        echo '<div class="yps-setting-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><div class="yps-media-field" data-yps-media-field><div class="yps-media-field-preview" data-yps-media-preview>';
        if ( '' !== trim( $value ) ) { echo '<img src="' . esc_url( $value ) . '" alt="" />'; }
        echo '</div><div class="yps-media-field-controls"><input type="url" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" data-yps-media-input /><div class="yps-inline-actions"><button type="button" class="button button-primary" data-yps-media-upload data-yps-media-target="' . esc_attr( $id ) . '">' . esc_html__( 'Upload image', 'yablonsky-seo' ) . '</button><button type="button" class="button" data-yps-media-select data-yps-media-target="' . esc_attr( $id ) . '">' . esc_html__( 'Media Library', 'yablonsky-seo' ) . '</button><button type="button" class="button yseo-ai-social-template-image" data-yseo-ai-social-template-image data-yseo-ai-social-group="' . esc_attr( $group ) . '" data-yseo-ai-social-key="' . esc_attr( $key ) . '" data-yseo-ai-social-slot="' . esc_attr( $slot ) . '" data-yps-media-target="' . esc_attr( $id ) . '">' . esc_html__( 'Generate image', 'yablonsky-seo' ) . '</button><button type="button" class="button" data-yps-media-clear data-yps-media-target="' . esc_attr( $id ) . '">' . esc_html__( 'Clear', 'yablonsky-seo' ) . '</button></div></div></div></div>';
    }

    /** @param array<string,mixed> $settings */
    private function settingMediaFieldContents( string $key, string $label, array $settings, bool $aiImageAssist = false ): void {
        $aiImageAssist = $aiImageAssist || SettingsAuthoringRegistry::supportsImage( $key );
        $value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
        $id = 'yps-' . $key;
        echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
        echo '<div class="yps-media-field" data-yps-media-field>';
        echo '<div class="yps-media-field-preview" data-yps-media-preview>';
        if ( '' !== trim( $value ) ) { echo '<img src="' . esc_url( $value ) . '" alt="" />'; }
        echo '</div><div class="yps-media-field-controls">';
        echo '<input type="url" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" data-yps-media-input />';
        echo '<div class="yps-inline-actions"><button type="button" class="button button-primary" data-yps-media-upload data-yps-media-target="' . esc_attr( $id ) . '">' . esc_html__( 'Upload image', 'yablonsky-seo' ) . '</button><button type="button" class="button" data-yps-media-select data-yps-media-target="' . esc_attr( $id ) . '">' . esc_html__( 'Media Library', 'yablonsky-seo' ) . '</button>';
        if ( $aiImageAssist ) {
            echo '<button type="button" class="button yseo-ai-setting-image" data-yseo-ai-setting-image="' . esc_attr( $key ) . '" data-yps-media-target="' . esc_attr( $id ) . '">' . esc_html__( 'Generate image', 'yablonsky-seo' ) . '</button>';
        }
        echo '<button type="button" class="button" data-yps-media-clear data-yps-media-target="' . esc_attr( $id ) . '">' . esc_html__( 'Clear', 'yablonsky-seo' ) . '</button></div>';
        echo '</div></div>';
    }

    /** @param array<string,mixed> $settings */
    private function textRow( string $key, string $label, array $settings, bool $textarea = false ): void {
        $value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
        echo '<tr><th scope="row"><label for="' . esc_attr( 'yps-' . $key ) . '">' . esc_html( $label ) . '</label></th><td>';
        if ( $textarea ) {
            echo '<textarea class="large-text" rows="3" id="' . esc_attr( 'yps-' . $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';
        } else {
            echo '<input class="regular-text" id="' . esc_attr( 'yps-' . $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
        }
        echo '</td></tr>';
    }

    /**
     * @param array<int,object> $models
     * @param array<int,array{id:string,label:string,detail:string}> $leading
     */
    private function renderAiModelPicker( string $id, string $name, array $models, string $selected, string $placeholder, string $searchLabel, array $leading = array() ): void {
        $options = array();
        foreach ( $leading as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $options[] = array(
                'id' => isset( $item['id'] ) ? (string) $item['id'] : '',
                'label' => isset( $item['label'] ) ? (string) $item['label'] : '',
                'detail' => isset( $item['detail'] ) ? (string) $item['detail'] : '',
            );
        }
        foreach ( $models as $model ) {
            if ( ! is_object( $model ) || ! method_exists( $model, 'id' ) || ! method_exists( $model, 'label' ) ) { continue; }
            $modelId = (string) $model->id();
            $options[] = array( 'id' => $modelId, 'label' => (string) $model->label(), 'detail' => $modelId );
        }
        if ( empty( $options ) ) { return; }

        $selectedIndex = 0;
        foreach ( $options as $index => $option ) {
            if ( (string) $option['id'] === $selected ) { $selectedIndex = (int) $index; break; }
        }
        $selectedOption = $options[ $selectedIndex ];
        $listId = $id . '-list';
        $popoverId = $id . '-popover';
        ?>
        <div class="yps-ai-model-picker" data-yps-model-picker>
            <input type="hidden" id="<?php echo esc_attr( $id . '-value' ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $selectedOption['id'] ); ?>" data-yps-model-value>
            <button type="button" class="yps-model-picker-button" id="<?php echo esc_attr( $id ); ?>" data-yps-model-toggle aria-haspopup="listbox" aria-expanded="false" aria-controls="<?php echo esc_attr( $popoverId ); ?>">
                <span data-yps-model-current><?php echo esc_html( (string) $selectedOption['label'] ); ?></span><span class="yps-model-picker-caret" aria-hidden="true">⌄</span>
            </button>
            <div class="yps-model-popover" id="<?php echo esc_attr( $popoverId ); ?>" data-yps-model-popover hidden>
                <label class="yps-model-search"><span class="screen-reader-text"><?php echo esc_html( $searchLabel ); ?></span><input type="search" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off" data-yps-model-search></label>
                <div class="yps-model-list" id="<?php echo esc_attr( $listId ); ?>" role="listbox" data-yps-model-list aria-label="<?php echo esc_attr( $searchLabel ); ?>">
                    <?php foreach ( $options as $index => $option ) : $isSelected = $index === $selectedIndex; ?>
                        <button type="button" role="option" class="yps-model-option<?php echo $isSelected ? ' is-selected' : ''; ?>" data-yps-model-option data-model-id="<?php echo esc_attr( (string) $option['id'] ); ?>" data-model-label="<?php echo esc_attr( (string) $option['label'] ); ?>" data-model-search="<?php echo esc_attr( strtolower( (string) $option['label'] . ' ' . (string) $option['detail'] ) ); ?>" data-model-order="<?php echo esc_attr( (string) $index ); ?>" aria-selected="<?php echo $isSelected ? 'true' : 'false'; ?>">
                            <strong><?php echo esc_html( (string) $option['label'] ); ?></strong><?php if ( '' !== (string) $option['detail'] ) : ?><small><?php echo esc_html( (string) $option['detail'] ); ?></small><?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                    <div class="yps-model-empty" data-yps-model-empty hidden><?php echo esc_html__( 'Adjust the search or filters to find matching resources.', 'yablonsky-seo' ); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    private function postedVerification( string $key ): string {
        $raw = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );
        if ( ! is_string( $raw ) ) { return ''; }
        $raw = trim( wp_unslash( $raw ) );
        if ( '' === $raw ) { return ''; }
        if ( false !== stripos( $raw, '<meta' ) && preg_match( '/\bcontent\s*=\s*(["\'])(.*?)\1/is', $raw, $match ) ) {
            $raw = (string) $match[2];
        }
        $raw = sanitize_text_field( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 512, 'UTF-8' ) : substr( $raw, 0, 512 );
    }

    private function postedRssTemplate( string $key ): string {
        $raw = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );
        if ( ! is_string( $raw ) ) { return ''; }
        return trim( wp_kses_post( wp_unslash( $raw ) ) );
    }

    private function postedText( string $key ): string {
        $value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );
        return is_string( $value ) ? sanitize_text_field( $value ) : '';
    }

    private function postedTextarea( string $key ): string {
        $value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );
        return is_string( $value ) ? sanitize_textarea_field( $value ) : '';
    }

    private function postedKey( string $key ): string {
        $value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );
        return is_string( $value ) ? sanitize_key( $value ) : '';
    }

    private function postedPolicy( string $key, string $fallback ): string {
        $value = $this->postedKey( $key );
        return in_array( $value, array( 'allow', 'disallow', 'inherit' ), true ) ? $value : $fallback;
    }

    private function updateOrDeletePostMeta( int $postId, string $key, string $value ): void {
        if ( '' === $value ) {
            delete_post_meta( $postId, $key );
        } else {
            update_post_meta( $postId, $key, $value );
        }
    }

    private function updateOrDeleteTermMeta( int $termId, string $key, string $value ): void {
        if ( '' === $value ) {
            delete_term_meta( $termId, $key );
        } else {
            update_term_meta( $termId, $key, $value );
        }
    }

    private function updateOrDeleteUserMeta( int $userId, string $key, string $value ): void {
        if ( '' === $value ) {
            delete_user_meta( $userId, $key );
        } else {
            update_user_meta( $userId, $key, $value );
        }
    }
}
