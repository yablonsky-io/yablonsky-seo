<?php
namespace Yablonsky\PerformanceSEO\Visibility;

use Yablonsky\PerformanceSEO\Commerce\WooProductTruthResolver;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ReadinessAudit {
    /**
     * Local configuration/contract audit. It intentionally does not claim achieved
     * citations, rankings, crawler visits or remote index coverage.
     *
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $sitemap
     * @return array<int,array{id:string,label:string,status:string,tone:string,detail:string}>
     */
    public function run( array $settings, bool $publicAuthority, array $sitemap ): array {
        $checks = array();

        $publicSite = '1' === (string) get_option( 'blog_public', '1' );
        $checks[] = $this->check(
            'site-visibility',
            __( 'Search engine visibility', 'yablonsky-seo' ),
            $publicSite ? __( 'Public', 'yablonsky-seo' ) : __( 'Discouraged', 'yablonsky-seo' ),
            $publicSite ? 'good' : 'attention',
            $publicSite ? __( 'WordPress is configured to allow normal public indexing decisions.', 'yablonsky-seo' ) : __( 'WordPress is set to discourage search engines. Review Settings > Reading before expecting search or AI discovery.', 'yablonsky-seo' )
        );

        $physicalRobots = is_file( trailingslashit( ABSPATH ) . 'robots.txt' );
        $checks[] = $this->check(
            'robots-surface',
            __( 'robots.txt control surface', 'yablonsky-seo' ),
            $physicalRobots ? __( 'Physical file', 'yablonsky-seo' ) : __( 'WordPress virtual', 'yablonsky-seo' ),
            $physicalRobots ? 'attention' : 'good',
            $physicalRobots ? __( 'A physical robots.txt file can bypass WordPress filters. Filesystem robots.txt policy remains administrator-managed; review that file separately.', 'yablonsky-seo' ) : __( 'WordPress can apply Yablonsky SEO sitemap and optional verified AI-crawler directives to the virtual robots.txt response.', 'yablonsky-seo' )
        );

        if ( $publicAuthority ) {
            $ready = ! empty( $sitemap['backfill_complete'] );
            $checks[] = $this->check(
                'sitemap',
                __( 'Discovery sitemap', 'yablonsky-seo' ),
                $ready ? __( 'Ready', 'yablonsky-seo' ) : __( 'Warm-up', 'yablonsky-seo' ),
                $ready ? 'good' : 'attention',
                $ready ? __( 'Canonical sitemap_index.xml is eligible for public authority.', 'yablonsky-seo' ) : __( 'WordPress core sitemap remains the safe fallback until the materialized index is complete.', 'yablonsky-seo' )
            );
        } else {
            $checks[] = $this->check( 'sitemap', __( 'Discovery sitemap', 'yablonsky-seo' ), __( 'Provider-owned', 'yablonsky-seo' ), 'neutral', __( 'In Shadow mode, the current SEO provider retains sitemap authority.', 'yablonsky-seo' ) );
        }

        $homeDescription = trim( (string) ( $settings['home_description'] ?? '' ) );
        $siteDescription = trim( (string) ( $settings['site_description'] ?? '' ) );
        $hasDescription = '' !== $homeDescription || '' !== $siteDescription || '' !== trim( (string) get_bloginfo( 'description' ) );
        $checks[] = $this->check(
            'description',
            __( 'Homepage description', 'yablonsky-seo' ),
            $hasDescription ? __( 'Present', 'yablonsky-seo' ) : __( 'Attention needed', 'yablonsky-seo' ),
            $hasDescription ? 'good' : 'attention',
            $hasDescription ? __( 'A server-rendered description is available through explicit settings or the site fallback.', 'yablonsky-seo' ) : __( 'Add a concise homepage description so search and AI systems receive a reliable primary summary.', 'yablonsky-seo' )
        );

        $siteIconId = (int) get_option( 'site_icon', 0 );
        if ( $siteIconId > 0 ) {
            $iconMeta = wp_get_attachment_metadata( $siteIconId );
            $iconWidth = is_array( $iconMeta ) ? (int) ( $iconMeta['width'] ?? 0 ) : 0;
            $iconHeight = is_array( $iconMeta ) ? (int) ( $iconMeta['height'] ?? 0 ) : 0;
            $iconGood = $iconWidth >= 48 && $iconWidth === $iconHeight;
            /* translators: 1: favicon width in pixels, 2: favicon height in pixels. */
            $iconDetail = sprintf( __( 'WordPress site icon is %1$d×%2$d px. Google requires a square icon of at least 8×8 and recommends an icon larger than 48×48 for robust search presentation.', 'yablonsky-seo' ), $iconWidth, $iconHeight );
            $checks[] = $this->check( 'favicon', __( 'Search favicon', 'yablonsky-seo' ), $iconGood ? __( 'Ready', 'yablonsky-seo' ) : __( 'Review', 'yablonsky-seo' ), $iconGood ? 'good' : 'attention', $iconDetail );
        } else {
            $checks[] = $this->check( 'favicon', __( 'Search favicon', 'yablonsky-seo' ), __( 'Attention needed', 'yablonsky-seo' ), 'attention', __( 'Add a WordPress Site Icon to strengthen stable square source recognition in search surfaces.', 'yablonsky-seo' ) );
        }

        $preferredDeployed = PreferredSourceController::isDeployedOnStaticFrontPage();
        $checks[] = $this->check(
            'preferred-source',
            __( 'Google Preferred Source helper', 'yablonsky-seo' ),
            $preferredDeployed ? __( 'Deployed', 'yablonsky-seo' ) : __( 'Available', 'yablonsky-seo' ),
            $preferredDeployed ? 'good' : 'neutral',
            $preferredDeployed
                ? __( 'The static front page includes the local YSEO Preferred Sources link helper. Selection remains a user decision in Google.', 'yablonsky-seo' )
                : __( 'YSEO provides the [yseo_preferred_source] shortcode as an optional static link. It uses zero Google JavaScript; ranking and AI-visibility outcomes remain external.', 'yablonsky-seo' )
        );

        $ownerName = trim( (string) ( $settings['owner_name'] ?? '' ) );
        $ownerDescription = trim( (string) ( $settings['owner_description'] ?? '' ) );
        $checks[] = $this->check(
            'entity',
            __( 'Entity declaration', 'yablonsky-seo' ),
            '' !== $ownerName && '' !== $ownerDescription ? __( 'Declared', 'yablonsky-seo' ) : __( 'Incomplete', 'yablonsky-seo' ),
            '' !== $ownerName && '' !== $ownerDescription ? 'good' : 'attention',
            '' !== $ownerName && '' !== $ownerDescription ? __( 'The site owner has a name and description for the JSON-LD graph.', 'yablonsky-seo' ) : __( 'Add owner name and description to strengthen Organization/Person resolution.', 'yablonsky-seo' )
        );

        $sameAs = $this->sameAsUrls( (string) ( $settings['owner_same_as'] ?? '' ) );
        $external = array_values( array_filter( $sameAs, array( $this, 'isExternalUrl' ) ) );
        $checks[] = $this->check(
            'corroboration',
            __( 'External corroboration', 'yablonsky-seo' ),
            ! empty( $external ) ? __( 'Connected', 'yablonsky-seo' ) : __( 'Attention needed', 'yablonsky-seo' ),
            ! empty( $external ) ? 'good' : 'attention',
            ! empty( $external ) ? __( 'At least one external sameAs profile can corroborate the declared entity.', 'yablonsky-seo' ) : __( 'Add independently maintained profile URLs for useful corroboration beyond the primary website.', 'yablonsky-seo' )
        );

        if ( post_type_exists( 'product' ) && function_exists( 'wc_get_product' ) ) {
            $returnPolicy = esc_url_raw( (string) ( $settings['merchant_return_policy_url'] ?? '' ), array( 'http', 'https' ) );
            $ownerTypeForCommerce = sanitize_key( (string) ( $settings['owner_type'] ?? 'organization' ) );
            $ownerNameForCommerce = trim( (string) ( $settings['owner_name'] ?? '' ) );
            $returnConfigured = '' !== $returnPolicy;
            $organizationOwner = 'person' !== $ownerTypeForCommerce;
            $canAttachReturnPolicy = $returnConfigured && $organizationOwner && '' !== $ownerNameForCommerce;
            $returnPolicyStatus = $canAttachReturnPolicy ? __( 'Connected', 'yablonsky-seo' ) : __( 'Optional', 'yablonsky-seo' );
            $returnPolicyTone = $canAttachReturnPolicy ? 'good' : 'neutral';
            $returnPolicyDetail = __( 'WooCommerce is present. Reference a public standard store return-policy page from the Organization entity when that policy is managed on-site instead of through Merchant Center.', 'yablonsky-seo' );
            if ( $returnConfigured && $organizationOwner && '' === $ownerNameForCommerce ) {
                $returnPolicyStatus = __( 'Review', 'yablonsky-seo' );
                $returnPolicyTone = 'attention';
                $returnPolicyDetail = __( 'A return-policy URL is configured. Add the Organization owner name so an Organization entity can carry MerchantReturnPolicy.', 'yablonsky-seo' );
            } elseif ( $canAttachReturnPolicy ) {
                $returnPolicyDetail = __( 'The Organization graph can reference the standard return-policy page through MerchantReturnPolicy. Product-specific exceptions and Merchant Center remain separate authorities.', 'yablonsky-seo' );
            }
            $checks[] = $this->check(
                'merchant-return-policy',
                __( 'Merchant return policy', 'yablonsky-seo' ),
                $returnPolicyStatus,
                $returnPolicyTone,
                $returnPolicyDetail
            );

            $commerceSample = $this->commerceTruthSample();
            if ( $commerceSample['sample'] > 0 ) {
                /* translators: 1: products with price/currency/availability machine facts, 2: products with at least one durable commerce identifier, 3: products sampled. */
                $commerceDetail = sprintf( __( '%1$d of %3$d recent products expose price, currency and availability through the local WooCommerce truth adapter; %2$d of %3$d expose at least one SKU, WooCommerce global unique ID or MPN.', 'yablonsky-seo' ), $commerceSample['offer_ready'], $commerceSample['identified'], $commerceSample['sample'] );
                $checks[] = $this->check( 'commerce-truth', __( 'Commerce machine truth', 'yablonsky-seo' ), $commerceSample['offer_ready'] === $commerceSample['sample'] ? __( 'Ready', 'yablonsky-seo' ) : __( 'Review', 'yablonsky-seo' ), $commerceSample['offer_ready'] === $commerceSample['sample'] ? 'good' : 'attention', $commerceDetail );
            }
        }

        $hasLanguageContract = false !== has_filter( 'yablonsky_seo_language_alternates_v1' ) || false !== has_filter( 'yablonsky_performance_seo_language_alternates_v1' );
        $checks[] = $this->check(
            'hreflang',
            __( 'Language relationships', 'yablonsky-seo' ),
            $hasLanguageContract ? __( 'Connected', 'yablonsky-seo' ) : __( 'Connect to activate', 'yablonsky-seo' ),
            $hasLanguageContract ? 'good' : 'neutral',
            $hasLanguageContract ? __( 'A versioned multilingual authority can supply reciprocal hreflang relationships.', 'yablonsky-seo' ) : __( 'Single-language sites need nothing here. Multilingual sites should connect Yablonsky Multilingual or another explicit language authority.', 'yablonsky-seo' )
        );

        $altSample = $this->imageAltSample();
        $sampleCount = (int) $altSample['sample'];
        $missingAlt = (int) $altSample['missing'];
        if ( $sampleCount > 0 ) {
            /* translators: 1: number of images missing alt text, 2: number of images sampled. */
            $altDetail = sprintf( __( '%1$d of the latest %2$d image attachments have empty alt text. Decorative images may be intentionally empty.', 'yablonsky-seo' ), $missingAlt, $sampleCount );
            $checks[] = $this->check( 'image-alt', __( 'Image semantics sample', 'yablonsky-seo' ), 0 === $missingAlt ? __( 'Complete', 'yablonsky-seo' ) : __( 'Review', 'yablonsky-seo' ), 0 === $missingAlt ? 'good' : 'attention', $altDetail );
        }

        $agentMarkdown = ! empty( $settings['agent_markdown_enabled'] );
        $scopedLlms = $agentMarkdown && ! empty( $settings['agent_scoped_llms_enabled'] );
        $agentSitemap = $agentMarkdown && ! empty( $settings['agent_sitemap_extension_enabled'] );
        if ( ! $publicAuthority ) {
            $checks[] = $this->check( 'agent-markdown', __( 'Agent-readable Markdown', 'yablonsky-seo' ), $agentMarkdown ? __( 'Prepared', 'yablonsky-seo' ) : __( 'Off', 'yablonsky-seo' ), 'neutral', __( 'Markdown alternates remain non-public while another SEO provider owns authority.', 'yablonsky-seo' ) );
        } elseif ( $agentMarkdown ) {
            $checks[] = $this->check( 'agent-markdown', __( 'Agent-readable Markdown', 'yablonsky-seo' ), __( 'Enabled', 'yablonsky-seo' ), 'good', __( 'Yablonsky SEO can expose root/scoped llms.txt orientation plus noindex Markdown alternates while HTML remains canonical.', 'yablonsky-seo' ) );
        } else {
            $checks[] = $this->check( 'agent-markdown', __( 'Agent-readable Markdown', 'yablonsky-seo' ), __( 'Optional', 'yablonsky-seo' ), 'neutral', __( 'HTML remains the canonical machine-readable page. Enable Markdown alternates only if you want an additional agent-oriented representation.', 'yablonsky-seo' ) );
        }
        if ( $agentMarkdown ) {
            $checks[] = $this->check( 'agent-scopes', __( 'Scoped llms.txt', 'yablonsky-seo' ), $scopedLlms ? __( 'Enabled', 'yablonsky-seo' ) : __( 'Root only', 'yablonsky-seo' ), $scopedLlms ? 'good' : 'neutral', $scopedLlms ? __( 'Resolvable sections can publish their own llms.txt orientation files, and the most specific scope can describe pages beneath it.', 'yablonsky-seo' ) : __( 'Only the root llms.txt orientation file is configured.', 'yablonsky-seo' ) );
            $checks[] = $this->check( 'agent-sitemap', __( 'Agent sitemap extension', 'yablonsky-seo' ), $agentSitemap ? __( 'Enabled', 'yablonsky-seo' ) : __( 'Off', 'yablonsky-seo' ), $agentSitemap ? 'good' : 'neutral', $agentSitemap ? __( 'Canonical sitemap URLs can advertise .md and llms.txt relations through an experimental custom XML namespace.', 'yablonsky-seo' ) : __( 'Standard XML sitemap output stays unchanged until the experimental agent-discovery extension is enabled.', 'yablonsky-seo' ) );
        }

        $indexNow = ! empty( $settings['indexnow_enabled'] );
        if ( ! $publicAuthority ) {
            $checks[] = $this->check( 'indexnow', __( 'IndexNow freshness push', 'yablonsky-seo' ), $indexNow ? __( 'Prepared', 'yablonsky-seo' ) : __( 'Off', 'yablonsky-seo' ), 'neutral', __( 'IndexNow sends when YSEO owns public authority.', 'yablonsky-seo' ) );
        } elseif ( ! $indexNow ) {
            $checks[] = $this->check( 'indexnow', __( 'IndexNow freshness push', 'yablonsky-seo' ), __( 'Optional', 'yablonsky-seo' ), 'neutral', __( 'XML sitemap discovery is complete on its own. Enable IndexNow push when you want material public changes batched asynchronously to the IndexNow network.', 'yablonsky-seo' ) );
        } else {
            $queue = get_option( 'yseo_indexnow_queue_v1', array() );
            $queued = is_array( $queue ) ? count( $queue ) : 0;
            $status = get_option( 'yseo_indexnow_status_v1', array() );
            $state = is_array( $status ) ? sanitize_key( (string) ( $status['state'] ?? '' ) ) : '';
            /* translators: %d: number of URLs currently queued for IndexNow submission. */
            $label = $queued > 0 ? sprintf( __( '%d queued', 'yablonsky-seo' ), $queued ) : __( 'Ready', 'yablonsky-seo' );
            $detail = 'rejected' === $state
                ? __( 'The most recent IndexNow batch was rejected by the remote endpoint. New changes can still queue; inspect the stored status before relying on push delivery.', 'yablonsky-seo' )
                : __( 'Material changes queue locally first and are sent later in bounded batches. Sitemap lastmod remains the durable pull-based freshness authority.', 'yablonsky-seo' );
            $checks[] = $this->check( 'indexnow', __( 'IndexNow freshness push', 'yablonsky-seo' ), $label, 'rejected' === $state ? 'attention' : 'good', $detail );
        }

        $appleOptOuts = $this->countPostMetaFlag( '_yps_apple_ai_answer_opt_out' );
        $restrictedPages = $this->countPostMetaFlag( '_yps_schema_access_restricted' );
        /* translators: 1: pages with Apple AI answer opt-out, 2: pages marked access restricted. */
        $appleDetail = sprintf( __( 'Apple Search, AI-answer context and foundation-model training are separate controls. The bounded local audit sampled up to 100 recent public resources for each policy and found %1$d Apple AI answer-context opt-outs and %2$d access-restricted resources with page-level structured data.', 'yablonsky-seo' ), $appleOptOuts, $restrictedPages );
        $checks[] = $this->check( 'apple-ai-policy', __( 'Apple AI usage policy', 'yablonsky-seo' ), __( 'Supported', 'yablonsky-seo' ), 'good', $appleDetail );

        $crawlerCoverage = $this->crawlerCoverage();
        $crawlerEnabled = ! empty( $settings['ai_crawler_policy_enabled'] );
        $permission = CrawlerPermissionPolicy::fromSettings( $settings );
        if ( ! $publicAuthority ) {
            $checks[] = $this->check( 'crawlers', __( 'Machine-use permission policy', 'yablonsky-seo' ), __( 'Shadow', 'yablonsky-seo' ), 'neutral', __( 'Crawler and content-use adapters remain provider/site-owned while Yablonsky SEO is in Shadow mode.', 'yablonsky-seo' ) );
        } elseif ( ! $crawlerEnabled ) {
            $checks[] = $this->check( 'crawlers', __( 'Machine-use permission policy', 'yablonsky-seo' ), __( 'Inherited', 'yablonsky-seo' ), 'neutral', __( 'Yablonsky SEO leaves existing crawler/content-use policy untouched until the administrator explicitly enables verified adapters.', 'yablonsky-seo' ) );
        } else {
            $values = $permission->all();
            $tone = count( array_filter( $values, static function ( string $value ): bool { return 'disallow' === $value; } ) ) > 0 ? 'attention' : 'good';
            /* translators: 1: verified search identities, 2: verified agent identities, 3: verified training/data-use controls. */
            $crawlerDetail = sprintf( __( 'Search discovery, AI answers, user-directed agents and model training are separate policy intents. The bundled registry currently maps %1$d search, %2$d agent/retrieval and %3$d training/data-use identities; AI-answer participation can also require provider-side controls.', 'yablonsky-seo' ), $crawlerCoverage['search'], $crawlerCoverage['agent'], $crawlerCoverage['training'] );
            $checks[] = $this->check( 'crawlers', __( 'Machine-use permission policy', 'yablonsky-seo' ), __( 'Managed', 'yablonsky-seo' ), $tone, $crawlerDetail );
        }

        $interop = CrawlerInteroperabilityAudit::build( $settings );
        if ( ! empty( $interop['cloudflare_detected'] ) && ! empty( $interop['mixed_use_review'] ) ) {
            $checks[] = $this->check( 'crawler-cloudflare', __( 'Cloudflare crawler interoperability', 'yablonsky-seo' ), __( 'Review edge policy', 'yablonsky-seo' ), 'attention', __( 'This request shows Cloudflare edge evidence while local intent allows Search and disallows Training. Verify Cloudflare uses Disallow AI Training rather than Training=Block or Block on pages with ads, because those modes can also block mixed-use Googlebot, Bingbot and Applebot search crawling.', 'yablonsky-seo' ) );
        } elseif ( ! empty( $interop['cloudflare_detected'] ) ) {
            $checks[] = $this->check( 'crawler-cloudflare', __( 'Cloudflare crawler interoperability', 'yablonsky-seo' ), __( 'Edge detected', 'yablonsky-seo' ), 'neutral', __( 'Cloudflare is visible on this request. YSEO can audit local intent, but Cloudflare zone policy remains an external authority and must be reviewed there.', 'yablonsky-seo' ) );
        }

        return $checks;
    }

    /** @return array{sample:int,missing:int} */
    private function imageAltSample(): array {
        $cached = get_transient( 'yseo_geo_image_alt_sample_v1' );
        if ( is_array( $cached ) && isset( $cached['sample'], $cached['missing'] ) ) {
            return array( 'sample' => (int) $cached['sample'], 'missing' => (int) $cached['missing'] );
        }
        $imageIds = get_posts( array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => 50,
            'orderby' => 'ID',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ) );
        $missing = 0;
        foreach ( is_array( $imageIds ) ? $imageIds : array() as $imageId ) {
            if ( '' === trim( (string) get_post_meta( (int) $imageId, '_wp_attachment_image_alt', true ) ) ) { $missing++; }
        }
        $sample = is_array( $imageIds ) ? count( $imageIds ) : 0;
        $result = array( 'sample' => $sample, 'missing' => $missing );
        set_transient( 'yseo_geo_image_alt_sample_v1', $result, 15 * MINUTE_IN_SECONDS );
        return $result;
    }

    private function countPostMetaFlag( string $metaKey ): int {
        $postTypes = get_post_types( array( 'public' => true ), 'names' );
        if ( empty( $postTypes ) || ! is_array( $postTypes ) ) { return 0; }
        $ids = get_posts(
            array(
                'post_type' => array_values( $postTypes ),
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'orderby' => 'modified',
                'order' => 'DESC',
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_key' => $metaKey, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded readiness sample over explicit YSEO boolean metadata.
                'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded readiness sample over explicit YSEO boolean metadata.
            )
        );
        return is_array( $ids ) ? count( $ids ) : 0;
    }

    /** @return array{sample:int,offer_ready:int,identified:int} */
    private function commerceTruthSample(): array {
        $cached = get_transient( 'yseo_commerce_truth_sample_v1' );
        if ( is_array( $cached ) && isset( $cached['sample'], $cached['offer_ready'], $cached['identified'] ) ) {
            return array( 'sample' => (int) $cached['sample'], 'offer_ready' => (int) $cached['offer_ready'], 'identified' => (int) $cached['identified'] );
        }
        $ids = get_posts(
            array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => 30,
                'orderby' => 'modified',
                'order' => 'DESC',
                'fields' => 'ids',
                'no_found_rows' => true,
            )
        );
        $resolver = new WooProductTruthResolver();
        $sample = 0;
        $offerReady = 0;
        $identified = 0;
        foreach ( is_array( $ids ) ? $ids : array() as $id ) {
            $truth = $resolver->resolve( (int) $id );
            if ( null === $truth ) { continue; }
            $sample++;
            $data = $truth->toArray();
            if ( '' !== trim( (string) ( $data['price'] ?? '' ) ) && preg_match( '/^[A-Z]{3}$/', (string) ( $data['currency'] ?? '' ) ) && '' !== trim( (string) ( $data['availability'] ?? '' ) ) ) { $offerReady++; }
            if ( '' !== trim( (string) ( $data['sku'] ?? '' ) ) || '' !== trim( (string) ( $data['gtin'] ?? '' ) ) || '' !== trim( (string) ( $data['mpn'] ?? '' ) ) ) { $identified++; }
        }
        $result = array( 'sample' => $sample, 'offer_ready' => $offerReady, 'identified' => $identified );
        set_transient( 'yseo_commerce_truth_sample_v1', $result, 15 * MINUTE_IN_SECONDS );
        return $result;
    }

    /** @return array{search:int,retrieval:int,training:int} */
    private function crawlerCoverage(): array {
        $coverage = array( 'search' => 0, 'agent' => 0, 'training' => 0 );
        try {
            $registry = CrawlerRegistry::fromJsonFile( YPS_DIR . 'data/ai-crawlers.json' );
            foreach ( $registry->all() as $crawler ) {
                $purpose = sanitize_key( (string) ( $crawler['purpose'] ?? '' ) );
                if ( 'retrieval' === $purpose ) { $purpose = 'agent'; }
                if ( isset( $coverage[ $purpose ] ) ) { $coverage[ $purpose ]++; }
            }
        } catch ( \RuntimeException $error ) {
            unset( $error );
        }
        return $coverage;
    }

    /** @return array{id:string,label:string,status:string,tone:string,detail:string} */
    private function check( string $id, string $label, string $status, string $tone, string $detail ): array {
        return compact( 'id', 'label', 'status', 'tone', 'detail' );
    }

    /** @return array<int,string> */
    private function sameAsUrls( string $raw ): array {
        $parts = preg_split( '/[\r\n,]+/', $raw );
        if ( ! is_array( $parts ) ) { return array(); }
        $out = array();
        foreach ( $parts as $part ) {
            $url = esc_url_raw( trim( $part ), array( 'http', 'https' ) );
            if ( '' !== $url ) { $out[] = $url; }
        }
        return array_values( array_unique( $out ) );
    }

    private function isExternalUrl( string $url ): bool {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $siteHost = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( '' === $host || '' === $siteHost ) { return false; }
        return $host !== $siteHost && substr( $host, -1 * ( strlen( $siteHost ) + 1 ) ) !== '.' . $siteHost;
    }
}
