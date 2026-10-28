<?php
namespace Yablonsky\PerformanceSEO\SEO;

use Yablonsky\PerformanceSEO\Language\WordPressLanguageProvider;
use Yablonsky\PerformanceSEO\Resource\ResourceIdentity;
use Yablonsky\PerformanceSEO\SEO\Social\SocialTemplateResolver;
use Yablonsky\PerformanceSEO\Resource\WordPressResourceResolver;
use Yablonsky\PerformanceSEO\SEO\Template\TemplateResolver;
use Yablonsky\PerformanceSEO\SEO\Freshness\FreshnessResolver;
use Yablonsky\PerformanceSEO\SEO\Image\ImageMetadataResolver;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapEligibilityPolicy;
use Yablonsky\PerformanceSEO\Discovery\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PublicSeoController {
    /** @var WordPressResourceResolver */
    private $resolver;
    /** @var TemplateResolver */
    private $templates;
    /** @var array<string,mixed>|null */
    private $document;

    public function __construct() {
        $language = new WordPressLanguageProvider();
        $this->resolver = new WordPressResourceResolver( $language );
        $this->templates = new TemplateResolver();
    }

    public function register(): void {
        add_filter( 'pre_get_document_title', array( $this, 'filterDocumentTitle' ), 20 );
        add_filter( 'wp_robots', array( $this, 'filterRobots' ), 20 );
        add_action( 'wp_head', array( $this, 'renderHead' ), 2 );
        add_action( 'template_redirect', array( $this, 'maybeRedirect' ), 1 );
        add_filter( 'wp_sitemaps_posts_entry', array( $this, 'filterPostSitemapEntry' ), 10, 3 );
        add_filter( 'wp_sitemaps_taxonomies_entry', array( $this, 'filterTermSitemapEntry' ), 10, 4 );
        add_filter( 'wp_sitemaps_users_entry', array( $this, 'filterUserSitemapEntry' ), 10, 2 );

        if ( function_exists( 'rel_canonical' ) ) {
            remove_action( 'wp_head', 'rel_canonical' );
        }
    }

    public function filterDocumentTitle( string $title ): string {
        $document = $this->document();
        if ( null === $document || empty( $document['title_explicit'] ) || '' === (string) $document['title'] ) {
            return $title;
        }
        return (string) $document['title'];
    }

    /** @param array<string,bool|string> $robots @return array<string,bool|string> */
    public function filterRobots( array $robots ): array {
        $document = $this->document();
        if ( null === $document ) {
            return $robots;
        }

        if ( ! $document['index'] ) {
            $robots['noindex'] = true;
            unset( $robots['index'] );
        }
        if ( ! $document['follow'] ) {
            $robots['nofollow'] = true;
            unset( $robots['follow'] );
        }

        foreach ( $document['robots_advanced'] as $directive => $value ) {
            $robots[ $directive ] = $value;
        }
        if ( ! isset( $robots['max-image-preview'] ) ) {
            $robots['max-image-preview'] = 'large';
        }
        return $robots;
    }

    public function renderHead(): void {
        $document = $this->document();
        if ( null === $document ) {
            return;
        }

        $description = (string) $document['description'];
        $canonical   = (string) $document['canonical'];
        $title       = (string) $document['title'];
        $ogTitle     = (string) $document['og_title'];
        $ogDesc      = (string) $document['og_description'];
        $ogImage     = (string) $document['og_image'];
        $twitterTitle = (string) $document['twitter_title'];
        $twitterDesc  = (string) $document['twitter_description'];
        $twitterImage = (string) $document['twitter_image'];

        echo "\n<!-- Yablonsky SEO -->\n";
        $publicSettings = get_option( 'yps_public_settings', array() );
        $publicSettings = is_array( $publicSettings ) ? $publicSettings : array();
        $verificationNames = array(
            'verification_google' => 'google-site-verification',
            'verification_bing' => 'msvalidate.01',
            'verification_pinterest' => 'p:domain_verify',
            'verification_yandex' => 'yandex-verification',
            'verification_baidu' => 'baidu-site-verification',
        );
        foreach ( $verificationNames as $settingKey => $metaName ) {
            $verification = isset( $publicSettings[ $settingKey ] ) ? trim( (string) $publicSettings[ $settingKey ] ) : '';
            if ( '' !== $verification ) { printf( '<meta name="%1$s" content="%2$s" />' . "\n", esc_attr( $metaName ), esc_attr( $verification ) ); }
        }
        if ( '' !== $description ) {
            printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
        }
        if ( '' !== $canonical ) {
            printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
        }
        if ( ! empty( $document['apple_ai_answer_opt_out'] ) ) {
            printf( '<meta name="applebot" content="nosnippet" />' . "\n" );
        }
        foreach ( $document['alternates'] as $alternate ) {
            if ( empty( $alternate['language_key'] ) || empty( $alternate['url'] ) ) {
                continue;
            }
            printf(
                '<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
                esc_attr( (string) $alternate['language_key'] ),
                esc_url( (string) $alternate['url'] )
            );
        }

        printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( (string) $document['og_type'] ) );
        if ( '' !== $title ) {
            printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( '' !== $ogTitle ? $ogTitle : $title ) );
        }
        if ( '' !== $ogDesc ) {
            printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $ogDesc ) );
        }
        if ( '' !== $canonical ) {
            printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $canonical ) );
        }
        printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( (string) get_bloginfo( 'name' ) ) );
        if ( '' !== $ogImage ) {
            printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $ogImage ) );
            $ogImageMeta = ( new ImageMetadataResolver() )->resolve( $ogImage );
            if ( ! empty( $ogImageMeta['width'] ) ) { printf( '<meta property="og:image:width" content="%d" />' . "\n", (int) $ogImageMeta['width'] ); }
            if ( ! empty( $ogImageMeta['height'] ) ) { printf( '<meta property="og:image:height" content="%d" />' . "\n", (int) $ogImageMeta['height'] ); }
            if ( ! empty( $ogImageMeta['mime_type'] ) ) { printf( '<meta property="og:image:type" content="%s" />' . "\n", esc_attr( (string) $ogImageMeta['mime_type'] ) ); }
            if ( ! empty( $ogImageMeta['alt'] ) ) { printf( '<meta property="og:image:alt" content="%s" />' . "\n", esc_attr( (string) $ogImageMeta['alt'] ) ); }
        }

        printf( '<meta name="twitter:card" content="%s" />' . "\n", esc_attr( '' !== $twitterImage || '' !== $ogImage ? 'summary_large_image' : 'summary' ) );
        if ( '' !== $twitterTitle || '' !== $title ) {
            printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( '' !== $twitterTitle ? $twitterTitle : $title ) );
        }
        if ( '' !== $twitterDesc || '' !== $description ) {
            printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( '' !== $twitterDesc ? $twitterDesc : $description ) );
        }
        if ( '' !== $twitterImage || '' !== $ogImage ) {
            $twitterImageUrl = '' !== $twitterImage ? $twitterImage : $ogImage;
            printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $twitterImageUrl ) );
            $twitterImageMeta = ( new ImageMetadataResolver() )->resolve( $twitterImageUrl );
            if ( ! empty( $twitterImageMeta['alt'] ) ) { printf( '<meta name="twitter:image:alt" content="%s" />' . "\n", esc_attr( (string) $twitterImageMeta['alt'] ) ); }
        }

        $schema = $this->schemaGraph( $document );
        if ( ! empty( $schema['@graph'] ) ) {
            $json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
            if ( is_string( $json ) ) {
                wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
            }
        }
        echo "<!-- /Yablonsky SEO -->\n";
    }

    public function maybeRedirect(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }
        $document = $this->document();
        if ( null === $document || '' === (string) $document['redirect'] ) {
            return;
        }
        $target = $this->normalizeSameOriginUrl( (string) $document['redirect'] );
        if ( '' === $target ) {
            return;
        }
        wp_safe_redirect( $target, 301, 'Yablonsky SEO' );
        exit;
    }

    /** @param array<string,string> $entry @return array<string,string> */
    public function filterPostSitemapEntry( array $entry, $post = null, string $postType = '' ): array {
        if ( '' !== $postType && ! ( new DiscoverySettings() )->familyEnabled( 'post', $postType ) ) { return array(); }
        if ( $post instanceof \WP_Post ) {
            if ( $this->metaBool( get_post_meta( (int) $post->ID, '_yps_robots_noindex', true ) ) ) { return array(); }
            $url = isset( $entry['loc'] ) ? (string) $entry['loc'] : (string) get_permalink( (int) $post->ID );
            $eligibility = ( new SitemapEligibilityPolicy() )->evaluatePost( $post, $url );
            if ( empty( $eligibility['eligible'] ) ) { return array(); }
        }
        return $entry;
    }

    /** @param array<string,string> $entry @return array<string,string> */
    public function filterTermSitemapEntry( array $entry, int $termId = 0, string $taxonomy = '', $term = null ): array {
        unset( $term );
        if ( '' !== $taxonomy && ! ( new DiscoverySettings() )->familyEnabled( 'term', $taxonomy ) ) { return array(); }
        if ( $termId > 0 && $this->metaBool( get_term_meta( $termId, '_yps_robots_noindex', true ) ) ) {
            return array();
        }
        return $entry;
    }

    /** @param array<string,string> $entry @return array<string,string> */
    public function filterUserSitemapEntry( array $entry, $user = null ): array {
        if ( ! ( new DiscoverySettings() )->familyEnabled( 'author', 'author' ) ) { return array(); }
        if ( is_object( $user ) && isset( $user->ID ) && $this->metaBool( get_user_meta( (int) $user->ID, '_yps_author_robots_noindex', true ) ) ) {
            return array();
        }
        return $entry;
    }

    /** @return array<string,mixed> */
    public function projectResource( ResourceIdentity $resource ): array {
        $document = $this->buildDocument( $resource );
        $settings = isset( $document['settings'] ) && is_array( $document['settings'] ) ? $document['settings'] : array();
        $globals = isset( $document['globals'] ) && is_array( $document['globals'] ) ? $document['globals'] : array();
        $imageResolver = new ImageMetadataResolver();
        $siteIcon = (string) get_site_icon_url( 192 );
        $organizationLogo = $this->resolvedOrganizationLogo( $settings, $globals );
        $ownerType = isset( $settings['owner_type'] ) ? sanitize_key( (string) $settings['owner_type'] ) : sanitize_key( (string) ( $globals['identity.owner.type'] ?? '' ) );
        $ownerType = in_array( $ownerType, array( 'person', 'individual' ), true ) ? 'person' : 'organization';
        $ownerName = $this->firstNonEmpty( array(
            isset( $settings['owner_name'] ) ? (string) $settings['owner_name'] : '',
            'person' === $ownerType ? (string) ( $globals['identity.person.name'] ?? '' ) : (string) ( $globals['identity.organization.name'] ?? '' ),
        ) );
        $personImage = 'person' === $ownerType ? $this->normalizePublicUrl( (string) ( $settings['owner_image_url'] ?? '' ) ) : '';
        $projection = array(
            'api_version' => 1,
            'resource' => array(
                'type' => $resource->type(),
                'id' => $resource->objectId(),
                'subtype' => $resource->subtype(),
                'language' => $resource->languageKey(),
                'url' => $resource->url(),
            ),
            'site' => array(
                'name' => $this->firstNonEmpty( array( isset( $settings['site_display_name'] ) ? (string) $settings['site_display_name'] : '', (string) get_bloginfo( 'name' ) ) ),
                'url' => home_url( '/' ),
                'site_icon' => $imageResolver->resolve( $siteIcon ),
                'organization_logo' => $imageResolver->resolve( $organizationLogo ),
                'owner' => array(
                    'type' => $ownerType,
                    'name' => trim( wp_strip_all_tags( $ownerName, true ) ),
                    'organization_logo' => $imageResolver->resolve( $organizationLogo ),
                    'person_image' => $imageResolver->resolve( $personImage ),
                ),
            ),
            'title' => (string) $document['title'],
            'description' => (string) $document['description'],
            'canonical' => (string) $document['canonical'],
            'robots' => array(
                'index' => ! empty( $document['index'] ),
                'follow' => ! empty( $document['follow'] ),
                'advanced' => (array) $document['robots_advanced'],
            ),
            'open_graph' => array(
                'type' => (string) $document['og_type'],
                'title' => (string) $document['og_title'],
                'description' => (string) $document['og_description'],
                'image' => (string) $document['og_image'],
            ),
            'twitter' => array(
                'title' => (string) $document['twitter_title'],
                'description' => (string) $document['twitter_description'],
                'image' => (string) $document['twitter_image'],
            ),
            'images' => array(
                'preferred' => $imageResolver->resolve( (string) ( $document['preferred_image'] ?? '' ) ),
                'preferred_source' => sanitize_key( (string) ( $document['preferred_image_source'] ?? 'none' ) ),
                'open_graph' => $imageResolver->resolve( (string) $document['og_image'] ),
                'twitter' => $imageResolver->resolve( (string) $document['twitter_image'] ),
                'favicon' => $imageResolver->resolve( $siteIcon ),
                'organization_logo' => $imageResolver->resolve( $organizationLogo ),
            ),
            'alternates' => (array) $document['alternates'],
            'schema' => $this->schemaGraph( $document ),
        );
        /**
         * Filters the stable machine-readable SEO projection.
         *
         * @param array<string,mixed> $projection Resolved public SEO document.
         * @param ResourceIdentity    $resource   Canonical resource identity.
         */
        $filtered = apply_filters( 'yablonsky_seo_head_projection_v1', $projection, $resource );
        return is_array( $filtered ) ? $filtered : $projection;
    }

    /** @param array<string,mixed> $settings @param array<string,mixed> $globals */
    private function resolvedOrganizationLogo( array $settings, array $globals ): string {
        $ownerType = isset( $settings['owner_type'] ) ? sanitize_key( (string) $settings['owner_type'] ) : '';
        if ( '' === $ownerType && isset( $globals['identity.owner.type'] ) ) { $ownerType = sanitize_key( (string) $globals['identity.owner.type'] ); }
        if ( in_array( $ownerType, array( 'person', 'individual' ), true ) ) { return ''; }
        return $this->normalizePublicUrl( $this->firstNonEmpty( array(
            isset( $settings['owner_logo_url'] ) ? (string) $settings['owner_logo_url'] : '',
            isset( $globals['identity.organization.logo_url'] ) ? (string) $globals['identity.organization.logo_url'] : '',
        ) ) );
    }

    /** @return array<string,mixed> */
    public function schemaForResource( ResourceIdentity $resource ): array {
        return $this->schemaGraph( $this->buildDocument( $resource ) );
    }

    /** @return array<string,mixed>|null */
    private function document(): ?array {
        if ( null !== $this->document ) {
            return $this->document;
        }

        $resource = $this->resolver->resolve();
        if ( null === $resource ) {
            return null;
        }
        $this->document = $this->buildDocument( $resource );
        return $this->document;
    }

    /** @return array<string,mixed> */
    private function buildDocument( ResourceIdentity $resource ): array {
        $globals = get_option( 'yps_shadow_global_settings', array() );
        $globals = is_array( $globals ) ? $globals : array();
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $values = $this->resourceValues( $resource );

        $titleTemplate = $this->firstNonEmpty(
            array(
                (string) $values['title'],
                $this->homeSetting( $resource, 'home_title', $settings ),
                $this->globalTemplate( $resource, 'title', $globals ),
            )
        );
        $title = $this->templates->resolve( $titleTemplate, $resource );
        if ( '' === $title ) {
            $title = $this->fallbackTitle( $resource );
        }

        $descriptionTemplate = $this->firstNonEmpty(
            array(
                (string) $values['description'],
                $this->homeSetting( $resource, 'home_description', $settings ),
                $this->globalTemplate( $resource, 'description', $globals ),
            )
        );
        $description = $this->templates->resolve( $descriptionTemplate, $resource );
        if ( '' === $description ) {
            $description = $this->fallbackDescription( $resource );
        }
        $description = $this->limitText( $description, 320 );

        $canonical = $this->normalizePublicUrl( (string) $values['canonical'] );
        if ( '' === $canonical ) {
            $canonical = $this->normalizePublicUrl( $resource->url() );
        }

        $index = ! $this->metaBool( $values['noindex'] );
        $follow = ! $this->metaBool( $values['nofollow'] );
        if ( $this->globalNoindex( $resource, $globals ) ) {
            $index = false;
        }

        $socialTemplates = new SocialTemplateResolver();
        $homeOgImage = $this->homeSetting( $resource, 'home_og_image', $settings );
        $homeSearchImage = $this->homeSetting( $resource, 'home_search_image', $settings );
        $homeTwitterImage = $this->homeSetting( $resource, 'home_twitter_image', $settings );
        $templateOgImage = $socialTemplates->value( $resource, 'og_image', $settings );
        $templateTwitterImage = $socialTemplates->value( $resource, 'twitter_image', $settings );
        $defaultSocialImage = isset( $settings['default_social_image'] ) && is_scalar( $settings['default_social_image'] ) ? (string) $settings['default_social_image'] : '';
        if ( '' !== trim( (string) $values['og_image'] ) ) {
            $ogImage = $this->mediaUrl( (string) $values['og_image'], (int) $values['og_image_id'], $resource );
        } elseif ( '' !== trim( $homeOgImage ) ) {
            $ogImage = $this->normalizePublicUrl( $homeOgImage );
        } elseif ( '' !== trim( $homeSearchImage ) ) {
            $ogImage = $this->normalizePublicUrl( $homeSearchImage );
        } elseif ( '' !== trim( $templateOgImage ) ) {
            $ogImage = $this->normalizePublicUrl( $templateOgImage );
        } else {
            $ogImage = $this->mediaUrl( '', (int) $values['og_image_id'], $resource );
        }
        if ( '' === $ogImage && '' !== trim( $defaultSocialImage ) ) { $ogImage = $this->normalizePublicUrl( $defaultSocialImage ); }
        if ( '' !== trim( (string) $values['twitter_image'] ) ) {
            $twitterImage = $this->mediaUrl( (string) $values['twitter_image'], (int) $values['twitter_image_id'], $resource );
        } elseif ( '' !== trim( $homeTwitterImage ) ) {
            $twitterImage = $this->normalizePublicUrl( $homeTwitterImage );
        } elseif ( '' !== trim( $templateTwitterImage ) ) {
            $twitterImage = $this->normalizePublicUrl( $templateTwitterImage );
        } else {
            $twitterImage = '';
        }
        $ogTitleSource = $this->firstNonEmpty( array( (string) $values['og_title'], $this->homeSetting( $resource, 'home_og_title', $settings ), $socialTemplates->value( $resource, 'og_title', $settings ) ) );
        $ogDescriptionSource = $this->firstNonEmpty( array( (string) $values['og_description'], $this->homeSetting( $resource, 'home_og_description', $settings ), $socialTemplates->value( $resource, 'og_description', $settings ) ) );
        $twitterTitleSource = $this->firstNonEmpty( array( (string) $values['twitter_title'], $this->homeSetting( $resource, 'home_twitter_title', $settings ), $socialTemplates->value( $resource, 'twitter_title', $settings ) ) );
        $twitterDescriptionSource = $this->firstNonEmpty( array( (string) $values['twitter_description'], $this->homeSetting( $resource, 'home_twitter_description', $settings ), $socialTemplates->value( $resource, 'twitter_description', $settings ) ) );
        $ogTitle = $this->templates->resolve( $ogTitleSource, $resource );
        $ogDescription = $this->templates->resolve( $ogDescriptionSource, $resource );
        $twitterTitle = $this->templates->resolve( $twitterTitleSource, $resource );
        $twitterDescription = $this->templates->resolve( $twitterDescriptionSource, $resource );

        $document = array(
            'resource'            => $resource,
            'title'               => $this->limitText( $title, 300 ),
            'title_explicit'      => '' !== $titleTemplate,
            'description'         => $description,
            'canonical'           => $canonical,
            'index'               => $index,
            'follow'              => $follow,
            'robots_advanced'     => $this->advancedRobots( (string) $values['robots_advanced'] ),
            'apple_ai_answer_opt_out' => $this->metaBool( $values['apple_ai_answer_opt_out'] ),
            'redirect'            => (string) $values['redirect'],
            'og_type'             => $this->resolvedOpenGraphType( (string) $values['og_type'], $resource ),
            'og_title'            => '' !== $ogTitle ? $this->limitText( $ogTitle, 300 ) : $this->limitText( $title, 300 ),
            'og_description'      => '' !== $ogDescription ? $this->limitText( $ogDescription, 320 ) : $description,
            'preferred_image'     => $this->firstNonEmpty( array( $this->normalizePublicUrl( $homeSearchImage ), $ogImage ) ),
            'preferred_image_source' => '' !== $this->normalizePublicUrl( $homeSearchImage ) ? 'preferred_search_image' : ( '' !== $ogImage ? 'open_graph_fallback' : 'none' ),
            'og_image'            => $ogImage,
            'twitter_title'       => '' !== $twitterTitle ? $this->limitText( $twitterTitle, 300 ) : $this->limitText( $title, 300 ),
            'twitter_description' => '' !== $twitterDescription ? $this->limitText( $twitterDescription, 320 ) : $description,
            'twitter_image'       => '' !== $twitterImage ? $twitterImage : $ogImage,
            'schema_page_type'    => sanitize_key( (string) $values['schema_page_type'] ),
            'schema_article_type' => sanitize_key( (string) $values['schema_article_type'] ),
            'schema_entity_type'  => sanitize_key( (string) $values['schema_entity_type'] ),
            'schema_entity_name'  => trim( (string) $values['schema_entity_name'] ),
            'schema_entity_description' => trim( (string) $values['schema_entity_description'] ),
            'schema_person_job_title' => trim( (string) $values['schema_person_job_title'] ),
            'schema_person_image_url' => trim( (string) $values['schema_person_image_url'] ),
            'schema_person_same_as' => trim( (string) $values['schema_person_same_as'] ),
            'schema_person_knows_about' => trim( (string) $values['schema_person_knows_about'] ),
            'schema_service_type' => trim( (string) $values['schema_service_type'] ),
            'schema_area_served'  => trim( (string) $values['schema_area_served'] ),
            'schema_application_category' => trim( (string) $values['schema_application_category'] ),
            'schema_operating_system' => trim( (string) $values['schema_operating_system'] ),
            'schema_offer_price'  => trim( (string) $values['schema_offer_price'] ),
            'schema_offer_currency' => strtoupper( trim( (string) $values['schema_offer_currency'] ) ),
            'schema_access_restricted' => $this->metaBool( $values['schema_access_restricted'] ),
            'alternates'          => $this->normalizeAlternates( $resource, $canonical ),
            'settings'            => $settings,
            'globals'             => $globals,
        );
        $filteredTitle = apply_filters( 'yablonsky_seo_title_v1', (string) $document['title'], $resource, $document );
        if ( is_scalar( $filteredTitle ) ) { $document['title'] = $this->limitText( wp_strip_all_tags( (string) $filteredTitle, true ), 300 ); }
        $filteredDescription = apply_filters( 'yablonsky_seo_description_v1', (string) $document['description'], $resource, $document );
        if ( is_scalar( $filteredDescription ) ) { $document['description'] = $this->limitText( wp_strip_all_tags( (string) $filteredDescription, true ), 320 ); }
        $filteredCanonical = apply_filters( 'yablonsky_seo_canonical_v1', (string) $document['canonical'], $resource, $document );
        if ( is_scalar( $filteredCanonical ) ) { $document['canonical'] = $this->normalizePublicUrl( (string) $filteredCanonical ); }

        $robots = apply_filters( 'yablonsky_seo_robots_v1', array( 'index' => (bool) $document['index'], 'follow' => (bool) $document['follow'], 'advanced' => (array) $document['robots_advanced'] ), $resource, $document );
        if ( is_array( $robots ) ) {
            $document['index'] = ! empty( $robots['index'] );
            $document['follow'] = ! empty( $robots['follow'] );
            $document['robots_advanced'] = isset( $robots['advanced'] ) && is_array( $robots['advanced'] ) ? $robots['advanced'] : array();
        }
        $openGraph = apply_filters( 'yablonsky_seo_open_graph_v1', array( 'type' => $document['og_type'], 'title' => $document['og_title'], 'description' => $document['og_description'], 'image' => $document['og_image'] ), $resource, $document );
        if ( is_array( $openGraph ) ) {
            $document['og_type'] = sanitize_key( (string) ( $openGraph['type'] ?? $document['og_type'] ) );
            $document['og_title'] = $this->limitText( wp_strip_all_tags( (string) ( $openGraph['title'] ?? $document['og_title'] ), true ), 300 );
            $document['og_description'] = $this->limitText( wp_strip_all_tags( (string) ( $openGraph['description'] ?? $document['og_description'] ), true ), 320 );
            $document['og_image'] = $this->normalizePublicUrl( (string) ( $openGraph['image'] ?? $document['og_image'] ) );
        }
        $twitter = apply_filters( 'yablonsky_seo_twitter_v1', array( 'title' => $document['twitter_title'], 'description' => $document['twitter_description'], 'image' => $document['twitter_image'] ), $resource, $document );
        if ( is_array( $twitter ) ) {
            $document['twitter_title'] = $this->limitText( wp_strip_all_tags( (string) ( $twitter['title'] ?? $document['twitter_title'] ), true ), 300 );
            $document['twitter_description'] = $this->limitText( wp_strip_all_tags( (string) ( $twitter['description'] ?? $document['twitter_description'] ), true ), 320 );
            $document['twitter_image'] = $this->normalizePublicUrl( (string) ( $twitter['image'] ?? $document['twitter_image'] ) );
        }

        /**
         * Filters the canonical resolved SEO document before HTML, Schema and API projections are finalized.
         *
         * @param array<string,mixed> $document Resolved public SEO document.
         * @param ResourceIdentity    $resource Canonical resource identity.
         */
        $filteredDocument = apply_filters( 'yablonsky_seo_document_v1', $document, $resource );
        return is_array( $filteredDocument ) ? $filteredDocument : $document;
    }

    /** @return array<string,mixed> */
    private function resourceValues( ResourceIdentity $resource ): array {
        $values = array(
            'title'               => '',
            'description'         => '',
            'canonical'           => '',
            'noindex'             => '',
            'nofollow'            => '',
            'robots_advanced'     => '',
            'apple_ai_answer_opt_out' => '',
            'redirect'            => '',
            'og_title'            => '',
            'og_description'      => '',
            'og_image'            => '',
            'og_image_id'         => 0,
            'og_type'             => '',
            'twitter_title'       => '',
            'twitter_description' => '',
            'twitter_image'       => '',
            'twitter_image_id'    => 0,
            'schema_page_type'    => '',
            'schema_article_type' => '',
            'schema_entity_type'  => '',
            'schema_entity_name'  => '',
            'schema_entity_description' => '',
            'schema_person_job_title' => '',
            'schema_person_image_url' => '',
            'schema_person_same_as' => '',
            'schema_person_knows_about' => '',
            'schema_service_type' => '',
            'schema_area_served'  => '',
            'schema_application_category' => '',
            'schema_operating_system' => '',
            'schema_offer_price'  => '',
            'schema_offer_currency' => '',
            'schema_access_restricted' => '',
        );

        $id = $resource->objectId();
        if ( $id > 0 && in_array( $resource->type(), array( 'post', 'product', 'posts_page', 'home' ), true ) ) {
            $map = array(
                'title'               => '_yps_seo_title',
                'description'         => '_yps_meta_description',
                'canonical'           => '_yps_canonical',
                'noindex'             => '_yps_robots_noindex',
                'nofollow'            => '_yps_robots_nofollow',
                'robots_advanced'     => '_yps_robots_advanced',
                'apple_ai_answer_opt_out' => '_yps_apple_ai_answer_opt_out',
                'redirect'            => '_yps_redirect_target',
                'og_title'            => '_yps_og_title',
                'og_description'      => '_yps_og_description',
                'og_image'            => '_yps_og_image',
                'og_image_id'         => '_yps_og_image_id',
                'og_type'             => '_yps_og_type',
                'twitter_title'       => '_yps_twitter_title',
                'twitter_description' => '_yps_twitter_description',
                'twitter_image'       => '_yps_twitter_image',
                'twitter_image_id'    => '_yps_twitter_image_id',
                'schema_page_type'    => '_yps_schema_page_type',
                'schema_article_type' => '_yps_schema_article_type',
                'schema_entity_type'  => '_yps_schema_entity_type',
                'schema_entity_name'  => '_yps_schema_entity_name',
                'schema_entity_description' => '_yps_schema_entity_description',
                'schema_person_job_title' => '_yps_schema_person_job_title',
                'schema_person_image_url' => '_yps_schema_person_image_url',
                'schema_person_same_as' => '_yps_schema_person_same_as',
                'schema_person_knows_about' => '_yps_schema_person_knows_about',
                'schema_service_type' => '_yps_schema_service_type',
                'schema_area_served'  => '_yps_schema_area_served',
                'schema_application_category' => '_yps_schema_application_category',
                'schema_operating_system' => '_yps_schema_operating_system',
                'schema_offer_price'  => '_yps_schema_offer_price',
                'schema_offer_currency' => '_yps_schema_offer_currency',
                'schema_access_restricted' => '_yps_schema_access_restricted',
            );
            foreach ( $map as $key => $metaKey ) {
                $values[ $key ] = get_post_meta( $id, $metaKey, true );
            }
        } elseif ( 'term' === $resource->type() && $id > 0 ) {
            $map = array(
                'title'           => '_yps_seo_title',
                'description'     => '_yps_meta_description',
                'canonical'       => '_yps_canonical',
                'noindex'         => '_yps_robots_noindex',
                'og_title'        => '_yps_og_title',
                'og_description'  => '_yps_og_description',
                'og_image'        => '_yps_og_image',
                'og_image_id'     => '_yps_og_image_id',
                'twitter_title'   => '_yps_twitter_title',
                'twitter_description' => '_yps_twitter_description',
                'twitter_image'   => '_yps_twitter_image',
            );
            foreach ( $map as $key => $metaKey ) {
                $values[ $key ] = get_term_meta( $id, $metaKey, true );
            }
        } elseif ( 'author_archive' === $resource->type() && $id > 0 ) {
            $values['title']       = get_user_meta( $id, '_yps_author_seo_title', true );
            $values['description'] = get_user_meta( $id, '_yps_author_meta_description', true );
            $values['noindex']     = get_user_meta( $id, '_yps_author_robots_noindex', true );
        }
        return $values;
    }

    /** @param array<string,mixed> $globals */
    private function globalTemplate( ResourceIdentity $resource, string $field, array $globals ): string {
        $keys = array();
        if ( 'home' === $resource->type() ) {
            $keys[] = 'templates.home.' . $field;
        } elseif ( 'author_archive' === $resource->type() ) {
            $keys[] = 'templates.author.' . $field;
        } elseif ( 'date_archive' === $resource->type() ) {
            $keys[] = 'templates.date_archive.' . $field;
        } elseif ( 'post_type_archive' === $resource->type() ) {
            $keys[] = 'templates.ptarchive-' . $resource->subtype() . '.' . $field;
            $keys[] = 'templates.' . $resource->subtype() . '-archive.' . $field;
        } elseif ( 'term' === $resource->type() ) {
            $keys[] = 'templates.' . $resource->subtype() . '.' . $field;
        } else {
            $keys[] = 'templates.' . $resource->subtype() . '.' . $field;
        }
        foreach ( $keys as $key ) {
            if ( isset( $globals[ $key ] ) && is_scalar( $globals[ $key ] ) && '' !== trim( (string) $globals[ $key ] ) ) {
                return (string) $globals[ $key ];
            }
        }
        return '';
    }

    /** @param array<string,mixed> $settings */
    private function homeSetting( ResourceIdentity $resource, string $key, array $settings ): string {
        if ( 'home' !== $resource->type() || ! isset( $settings[ $key ] ) || ! is_scalar( $settings[ $key ] ) ) {
            return '';
        }
        return (string) $settings[ $key ];
    }

    /** @param array<string,mixed> $globals */
    private function globalNoindex( ResourceIdentity $resource, array $globals ): bool {
        $keys = array();
        if ( 'author_archive' === $resource->type() ) {
            $keys[] = 'robots.author.noindex';
        } elseif ( 'date_archive' === $resource->type() ) {
            $keys[] = 'robots.date_archive.noindex';
        } elseif ( 'term' === $resource->type() ) {
            $keys[] = 'robots.' . $resource->subtype() . '.noindex';
        } else {
            $keys[] = 'robots.' . $resource->subtype() . '.noindex';
        }
        foreach ( $keys as $key ) {
            if ( isset( $globals[ $key ] ) && $this->metaBool( $globals[ $key ] ) ) {
                return true;
            }
        }
        return false;
    }

    private function fallbackTitle( ResourceIdentity $resource ): string {
        if ( $resource->objectId() > 0 && in_array( $resource->type(), array( 'post', 'product', 'posts_page', 'home' ), true ) ) {
            $name = trim( wp_strip_all_tags( (string) get_the_title( $resource->objectId() ), true ) );
            if ( '' !== $name ) {
                return $name . ' - ' . (string) get_bloginfo( 'name' );
            }
        }
        if ( 'term' === $resource->type() ) {
            $term = get_term( $resource->objectId(), $resource->subtype() );
            if ( $term && ! is_wp_error( $term ) ) {
                return trim( wp_strip_all_tags( (string) $term->name, true ) ) . ' - ' . (string) get_bloginfo( 'name' );
            }
        }
        if ( 'author_archive' === $resource->type() ) {
            return trim( wp_strip_all_tags( (string) get_the_author_meta( 'display_name', $resource->objectId() ), true ) ) . ' - ' . (string) get_bloginfo( 'name' );
        }
        if ( 'post_type_archive' === $resource->type() ) {
            $object = get_post_type_object( $resource->subtype() );
            if ( is_object( $object ) && isset( $object->labels->name ) ) {
                return trim( wp_strip_all_tags( (string) $object->labels->name, true ) ) . ' - ' . (string) get_bloginfo( 'name' );
            }
        }
        return (string) get_bloginfo( 'name' );
    }

    private function fallbackDescription( ResourceIdentity $resource ): string {
        $id = $resource->objectId();
        if ( $id > 0 && in_array( $resource->type(), array( 'post', 'product', 'posts_page', 'home' ), true ) ) {
            $excerpt = trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $id ), true ) );
            if ( '' === $excerpt ) {
                $excerpt = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ), true ), 32, '…' );
            }
            return trim( $excerpt );
        }
        if ( 'term' === $resource->type() ) {
            $term = get_term( $id, $resource->subtype() );
            if ( $term && ! is_wp_error( $term ) ) {
                return trim( wp_strip_all_tags( (string) $term->description, true ) );
            }
        }
        if ( 'author_archive' === $resource->type() ) {
            return trim( wp_strip_all_tags( (string) get_the_author_meta( 'description', $id ), true ) );
        }
        return trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ), true ) );
    }

    private function resolvedOpenGraphType( string $override, ResourceIdentity $resource ): string {
        $override = sanitize_key( $override );
        if ( in_array( $override, array( 'article', 'website' ), true ) ) {
            return $override;
        }
        return 'post' === $resource->subtype() ? 'article' : 'website';
    }

    /** @return array<string,bool|string> */
    private function advancedRobots( string $raw ): array {
        $out = array();
        $directives = preg_split( '/[\s,]+/', strtolower( trim( $raw ) ) );
        if ( ! is_array( $directives ) ) {
            return $out;
        }
        foreach ( $directives as $directive ) {
            if ( in_array( $directive, array( 'noarchive', 'nosnippet', 'noimageindex', 'notranslate' ), true ) ) {
                $out[ $directive ] = true;
            } elseif ( preg_match( '/^(max-snippet|max-video-preview):(-?\d+)$/', $directive, $match ) ) {
                $out[ $match[1] ] = (string) (int) $match[2];
            } elseif ( preg_match( '/^max-image-preview:(none|standard|large)$/', $directive, $match ) ) {
                $out['max-image-preview'] = $match[1];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function schemaGraph( array $document ): array {
        /** @var ResourceIdentity $resource */
        $resource = $document['resource'];
        $canonical = (string) $document['canonical'];
        if ( '' === $canonical ) {
            return array( '@context' => 'https://schema.org', '@graph' => array() );
        }

        $settings = is_array( $document['settings'] ) ? $document['settings'] : array();
        $globals = is_array( $document['globals'] ) ? $document['globals'] : array();
        $siteUrl = trailingslashit( home_url( '/' ) );
        $siteName = $this->firstNonEmpty(
            array(
                isset( $settings['site_display_name'] ) ? (string) $settings['site_display_name'] : '',
                isset( $globals['identity.website.name'] ) ? (string) $globals['identity.website.name'] : '',
                (string) get_bloginfo( 'name' ),
            )
        );
        $ownerType = isset( $settings['owner_type'] ) ? sanitize_key( (string) $settings['owner_type'] ) : '';
        if ( '' === $ownerType && isset( $globals['identity.owner.type'] ) ) {
            $ownerType = sanitize_key( (string) $globals['identity.owner.type'] );
        }
        $ownerName = $this->firstNonEmpty(
            array(
                isset( $settings['owner_name'] ) ? (string) $settings['owner_name'] : '',
                isset( $globals['identity.organization.name'] ) ? (string) $globals['identity.organization.name'] : '',
                isset( $globals['identity.person.name'] ) ? (string) $globals['identity.person.name'] : '',
            )
        );
        $logo = $this->resolvedOrganizationLogo( $settings, $globals );
        $ownerDescription = isset( $settings['owner_description'] ) ? trim( wp_strip_all_tags( (string) $settings['owner_description'], true ) ) : '';
        $ownerImage = isset( $settings['owner_image_url'] ) ? $this->normalizePublicUrl( (string) $settings['owner_image_url'] ) : '';
        $ownerJobTitle = isset( $settings['owner_job_title'] ) ? trim( wp_strip_all_tags( (string) $settings['owner_job_title'], true ) ) : '';
        $ownerSameAs = isset( $settings['owner_same_as'] ) ? $this->urlList( (string) $settings['owner_same_as'] ) : array();
        $ownerKnowsAbout = isset( $settings['owner_knows_about'] ) ? $this->textList( (string) $settings['owner_knows_about'] ) : array();

        $graph = array();
        $websiteId = $siteUrl . '#website';
        $website = array(
            '@type' => 'WebSite',
            '@id'   => $websiteId,
            'url'   => $siteUrl,
            'name'  => $siteName,
        );
        $siteDescription = isset( $settings['site_description'] ) ? trim( wp_strip_all_tags( (string) $settings['site_description'], true ) ) : '';
        if ( '' === $siteDescription ) {
            $siteDescription = trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ), true ) );
        }
        if ( '' !== $siteDescription ) {
            $website['description'] = $siteDescription;
        }
        $graph[] = $website;

        $ownerId = '';
        if ( '' !== $ownerName ) {
            $isPerson = in_array( $ownerType, array( 'person', 'individual' ), true );
            $ownerId = $siteUrl . ( $isPerson ? '#person' : '#organization' );
            $owner = array(
                '@type' => $isPerson ? 'Person' : 'Organization',
                '@id'   => $ownerId,
                'name'  => trim( wp_strip_all_tags( $ownerName, true ) ),
                'url'   => $siteUrl,
            );
            if ( '' !== $ownerDescription ) {
                $owner['description'] = $ownerDescription;
            }
            if ( ! empty( $ownerSameAs ) ) {
                $owner['sameAs'] = $ownerSameAs;
            }
            if ( ! empty( $ownerKnowsAbout ) ) {
                $owner['knowsAbout'] = $ownerKnowsAbout;
            }
            if ( $isPerson ) {
                if ( '' !== $ownerJobTitle ) { $owner['jobTitle'] = $ownerJobTitle; }
                if ( '' !== $ownerImage ) { $owner['image'] = array( '@type' => 'ImageObject', 'url' => $ownerImage ); }
            } else {
                if ( '' !== $logo ) { $owner['logo'] = array( '@type' => 'ImageObject', 'url' => $logo ); }
                $returnPolicyUrl = isset( $settings['merchant_return_policy_url'] ) ? $this->normalizePublicUrl( (string) $settings['merchant_return_policy_url'] ) : '';
                if ( '' !== $returnPolicyUrl ) {
                    $owner['hasMerchantReturnPolicy'] = array(
                        '@type' => 'MerchantReturnPolicy',
                        '@id' => $siteUrl . '#merchant-return-policy',
                        'merchantReturnLink' => $returnPolicyUrl,
                    );
                }
            }
            $graph[] = $owner;
            $graph[0]['publisher'] = array( '@id' => $ownerId );
        }

        $pageType = $this->schemaType( (string) $document['schema_page_type'], $this->defaultSchemaPageType( $resource ) );
        $page = array(
            '@type'      => $pageType,
            '@id'        => $canonical . '#webpage',
            'url'        => $canonical,
            'name'       => (string) $document['title'],
            'isPartOf'   => array( '@id' => $websiteId ),
        );
        $resourceLanguage = strtolower( str_replace( '_', '-', trim( $resource->languageKey() ) ) );
        if ( preg_match( '/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/', $resourceLanguage ) && ! in_array( $resourceLanguage, array( 'auto', 'x-default' ), true ) ) {
            $page['inLanguage'] = $resourceLanguage;
        }
        if ( '' !== (string) $document['description'] ) {
            $page['description'] = (string) $document['description'];
        }
        if ( ! empty( $document['schema_access_restricted'] ) ) {
            $page['isAccessibleForFree'] = false;
        }
        $preferredSchemaImage = $this->firstNonEmpty( array( (string) ( $document['preferred_image'] ?? '' ), (string) $document['og_image'] ) );
        if ( '' !== $preferredSchemaImage ) {
            $imageObject = ( new ImageMetadataResolver() )->schemaObject( $preferredSchemaImage );
            if ( ! empty( $imageObject ) ) { $page['primaryImageOfPage'] = $imageObject; }
        }
        $modified = $this->resourceDateModified( $resource );
        if ( '' !== $modified ) { $page['dateModified'] = $modified; }
        if ( $resource->objectId() > 0 && in_array( $resource->type(), array( 'post', 'product', 'home', 'posts_page' ), true ) ) {
            $published = get_post_time( DATE_W3C, true, $resource->objectId() );
            if ( is_string( $published ) && '' !== $published ) { $page['datePublished'] = $published; }
        }

        $breadcrumb = $this->breadcrumbSchema( $resource, $canonical );
        if ( ! empty( $breadcrumb ) ) {
            $page['breadcrumb'] = array( '@id' => $canonical . '#breadcrumb' );
        }

        $entity = $this->primaryEntity( $document, $canonical, $ownerId );
        if ( ! empty( $entity ) && isset( $entity['@id'] ) ) {
            $page['mainEntity'] = array( '@id' => (string) $entity['@id'] );
        }
        $graph[] = $page;
        if ( ! empty( $breadcrumb ) ) { $graph[] = $breadcrumb; }
        if ( ! empty( $entity ) ) { $graph[] = $entity; }

        if ( 'post' === $resource->subtype() && $resource->objectId() > 0 ) {
            $articleType = $this->schemaType( (string) $document['schema_article_type'], 'Article' );
            $article = array(
                '@type'            => $articleType,
                '@id'              => $canonical . '#article',
                'isPartOf'         => array( '@id' => $canonical . '#webpage' ),
                'mainEntityOfPage' => array( '@id' => $canonical . '#webpage' ),
                'headline'         => (string) $document['title'],
                'datePublished'    => get_post_time( DATE_W3C, true, $resource->objectId() ),
                'dateModified'     => $modified ?: get_post_modified_time( DATE_W3C, true, $resource->objectId() ),
            );
            $authorId = (int) get_post_field( 'post_author', $resource->objectId() );
            if ( $authorId > 0 ) {
                $article['author'] = $this->authorSchema( $authorId );
            }
            if ( '' !== $ownerId ) {
                $article['publisher'] = array( '@id' => $ownerId );
            }
            if ( '' !== (string) $document['og_image'] ) {
                $articleImage = ( new ImageMetadataResolver() )->schemaObject( (string) $document['og_image'] );
                if ( ! empty( $articleImage ) ) { $article['image'] = array( $articleImage ); }
            }
            $graph[] = $article;
        }

        $payload = array( '@context' => 'https://schema.org', '@graph' => $graph );
        $payload = apply_filters( 'yablonsky_seo_schema_graph_v1', $payload, $document, $resource );
        return is_array( $payload ) ? $payload : array( '@context' => 'https://schema.org', '@graph' => $graph );
    }

    private function defaultSchemaPageType( ResourceIdentity $resource ): string {
        if ( 'product' === $resource->type() ) { return 'ItemPage'; }
        if ( 'author_archive' === $resource->type() ) { return 'ProfilePage'; }
        if ( in_array( $resource->type(), array( 'term', 'posts_page', 'post_type_archive', 'date_archive' ), true ) ) { return 'CollectionPage'; }
        return 'WebPage';
    }

    private function resourceDateModified( ResourceIdentity $resource ): string {
        $freshness = new FreshnessResolver();
        $mysql = '';
        if ( in_array( $resource->type(), array( 'post', 'product' ), true ) && $resource->objectId() > 0 ) {
            $mysql = $freshness->postLastmod( $resource->objectId() );
        } elseif ( 'home' === $resource->type() ) {
            $mysql = $resource->objectId() > 0 ? $freshness->postLastmod( $resource->objectId() ) : $freshness->siteLastmod();
        } elseif ( 'posts_page' === $resource->type() ) {
            $mysql = $resource->objectId() > 0 ? $freshness->postLastmod( $resource->objectId() ) : $freshness->siteLastmod();
        } elseif ( 'term' === $resource->type() ) {
            $mysql = $freshness->termLastmod( $resource->objectId(), $resource->subtype() );
        } elseif ( 'author_archive' === $resource->type() ) {
            $mysql = $freshness->authorLastmod( $resource->objectId() );
        } elseif ( 'post_type_archive' === $resource->type() ) {
            $mysql = $freshness->postTypeArchiveLastmod( $resource->subtype() );
        }
        if ( '' === $mysql ) { return ''; }
        $timestamp = strtotime( $mysql . ' UTC' );
        return false === $timestamp ? '' : gmdate( DATE_W3C, $timestamp );
    }

    /** @return array<string,mixed> */
    private function breadcrumbSchema( ResourceIdentity $resource, string $canonical ): array {
        if ( 'home' === $resource->type() || '' === $canonical ) { return array(); }
        $items = array();
        $this->appendBreadcrumb( $items, (string) get_bloginfo( 'name' ), home_url( '/' ) );

        if ( in_array( $resource->type(), array( 'post', 'product' ), true ) && $resource->objectId() > 0 ) {
            $post = get_post( $resource->objectId() );
            if ( $post instanceof \WP_Post ) {
                if ( is_post_type_hierarchical( $post->post_type ) ) {
                    $ancestors = array_reverse( get_post_ancestors( $post ) );
                    foreach ( $ancestors as $ancestorId ) {
                        $this->appendBreadcrumb( $items, (string) get_the_title( $ancestorId ), (string) get_permalink( $ancestorId ) );
                    }
                } elseif ( 'post' === $post->post_type ) {
                    $postsPage = (int) get_option( 'page_for_posts', 0 );
                    if ( $postsPage > 0 ) { $this->appendBreadcrumb( $items, (string) get_the_title( $postsPage ), (string) get_permalink( $postsPage ) ); }
                } else {
                    $archive = get_post_type_archive_link( $post->post_type );
                    $object = get_post_type_object( $post->post_type );
                    if ( is_string( $archive ) && '' !== $archive && $object && ! empty( $object->has_archive ) ) {
                        $label = isset( $object->labels->name ) ? (string) $object->labels->name : $post->post_type;
                        $this->appendBreadcrumb( $items, $label, $archive );
                    }
                }
                $this->appendBreadcrumb( $items, (string) get_the_title( $post ), $canonical );
            }
        } elseif ( 'term' === $resource->type() ) {
            $term = get_term( $resource->objectId(), $resource->subtype() );
            if ( $term instanceof \WP_Term ) {
                $ancestors = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
                foreach ( $ancestors as $ancestorId ) {
                    $ancestor = get_term( (int) $ancestorId, $term->taxonomy );
                    if ( $ancestor instanceof \WP_Term ) {
                        $url = get_term_link( $ancestor );
                        if ( ! is_wp_error( $url ) ) { $this->appendBreadcrumb( $items, (string) $ancestor->name, (string) $url ); }
                    }
                }
                $this->appendBreadcrumb( $items, (string) $term->name, $canonical );
            }
        } elseif ( 'author_archive' === $resource->type() ) {
            $user = get_userdata( $resource->objectId() );
            $this->appendBreadcrumb( $items, $user instanceof \WP_User ? (string) $user->display_name : __( 'Author', 'yablonsky-seo' ), $canonical );
        } elseif ( 'post_type_archive' === $resource->type() ) {
            $object = get_post_type_object( $resource->subtype() );
            $label = $object && isset( $object->labels->name ) ? (string) $object->labels->name : $resource->subtype();
            $this->appendBreadcrumb( $items, $label, $canonical );
        } elseif ( 'posts_page' === $resource->type() ) {
            $label = $resource->objectId() > 0 ? (string) get_the_title( $resource->objectId() ) : __( 'Posts', 'yablonsky-seo' );
            $this->appendBreadcrumb( $items, $label, $canonical );
        } elseif ( 'date_archive' === $resource->type() ) {
            $this->appendBreadcrumb( $items, (string) wp_get_document_title(), $canonical );
        }

        if ( count( $items ) < 2 ) { return array(); }
        $elements = array();
        foreach ( $items as $index => $item ) {
            $elements[] = array(
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            );
        }
        return array(
            '@type' => 'BreadcrumbList',
            '@id' => $canonical . '#breadcrumb',
            'itemListElement' => $elements,
        );
    }

    /** @param array<int,array{name:string,url:string}> $items */
    private function appendBreadcrumb( array &$items, string $name, string $url ): void {
        $name = trim( wp_strip_all_tags( $name, true ) );
        $url = $this->normalizePublicUrl( $url );
        if ( '' === $name || '' === $url ) { return; }
        foreach ( $items as $existing ) {
            if ( untrailingslashit( $existing['url'] ) === untrailingslashit( $url ) ) { return; }
        }
        $items[] = array( 'name' => $name, 'url' => $url );
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function primaryEntity( array $document, string $canonical, string $ownerId ): array {
        $type = sanitize_key( (string) ( $document['schema_entity_type'] ?? '' ) );
        if ( ! in_array( $type, array( 'service', 'software_application', 'person' ), true ) ) { return array(); }
        $name = trim( wp_strip_all_tags( (string) ( $document['schema_entity_name'] ?? '' ), true ) );
        if ( '' === $name ) { $name = trim( wp_strip_all_tags( (string) ( $document['title'] ?? '' ), true ) ); }
        $description = trim( wp_strip_all_tags( (string) ( $document['schema_entity_description'] ?? '' ), true ) );
        if ( '' === $description ) { $description = trim( wp_strip_all_tags( (string) ( $document['description'] ?? '' ), true ) ); }

        if ( 'person' === $type ) {
            $entity = array(
                '@type' => 'Person',
                '@id' => $canonical . '#person',
                'url' => $canonical,
                'name' => $name,
                'mainEntityOfPage' => array( '@id' => $canonical . '#webpage' ),
            );
            if ( '' !== $description ) { $entity['description'] = $description; }
            $jobTitle = trim( wp_strip_all_tags( (string) ( $document['schema_person_job_title'] ?? '' ), true ) );
            $image = $this->normalizePublicUrl( (string) ( $document['schema_person_image_url'] ?? '' ) );
            $sameAs = $this->urlList( (string) ( $document['schema_person_same_as'] ?? '' ) );
            $knowsAbout = $this->textList( (string) ( $document['schema_person_knows_about'] ?? '' ) );
            if ( '' !== $jobTitle ) { $entity['jobTitle'] = $jobTitle; }
            if ( '' !== $image ) { $entity['image'] = array( '@type' => 'ImageObject', 'url' => $image ); }
            if ( ! empty( $sameAs ) ) { $entity['sameAs'] = $sameAs; }
            if ( ! empty( $knowsAbout ) ) { $entity['knowsAbout'] = $knowsAbout; }
            return $entity;
        }

        if ( 'service' === $type ) {
            $entity = array(
                '@type' => 'Service',
                '@id' => $canonical . '#service',
                'url' => $canonical,
                'name' => $name,
                'mainEntityOfPage' => array( '@id' => $canonical . '#webpage' ),
            );
            if ( '' !== $description ) { $entity['description'] = $description; }
            $serviceType = trim( wp_strip_all_tags( (string) ( $document['schema_service_type'] ?? '' ), true ) );
            $areaServed = trim( wp_strip_all_tags( (string) ( $document['schema_area_served'] ?? '' ), true ) );
            if ( '' !== $serviceType ) { $entity['serviceType'] = $serviceType; }
            if ( '' !== $areaServed ) { $entity['areaServed'] = $areaServed; }
            if ( '' !== $ownerId ) { $entity['provider'] = array( '@id' => $ownerId ); }
            return $entity;
        }

        $entity = array(
            '@type' => 'SoftwareApplication',
            '@id' => $canonical . '#software',
            'url' => $canonical,
            'name' => $name,
            'mainEntityOfPage' => array( '@id' => $canonical . '#webpage' ),
        );
        if ( '' !== $description ) { $entity['description'] = $description; }
        $category = trim( wp_strip_all_tags( (string) ( $document['schema_application_category'] ?? '' ), true ) );
        $operatingSystem = trim( wp_strip_all_tags( (string) ( $document['schema_operating_system'] ?? '' ), true ) );
        if ( '' !== $category ) { $entity['applicationCategory'] = $category; }
        if ( '' !== $operatingSystem ) { $entity['operatingSystem'] = $operatingSystem; }
        if ( '' !== $ownerId ) { $entity['publisher'] = array( '@id' => $ownerId ); }
        $price = trim( (string) ( $document['schema_offer_price'] ?? '' ) );
        $currency = strtoupper( trim( (string) ( $document['schema_offer_currency'] ?? '' ) ) );
        if ( preg_match( '/^\d+(?:[.,]\d{1,4})?$/', $price ) && preg_match( '/^[A-Z]{3}$/', $currency ) ) {
            $entity['offers'] = array(
                '@type' => 'Offer',
                'price' => str_replace( ',', '.', $price ),
                'priceCurrency' => $currency,
                'url' => $canonical,
            );
        }
        return $entity;
    }

    /** @return array<string,mixed> */
    private function authorSchema( int $authorId ): array {
        $author = array(
            '@type' => 'Person',
            '@id' => (string) get_author_posts_url( $authorId ) . '#person',
            'name'  => (string) get_the_author_meta( 'display_name', $authorId ),
            'url'   => (string) get_author_posts_url( $authorId ),
        );
        $description = trim( wp_strip_all_tags( (string) get_the_author_meta( 'description', $authorId ), true ) );
        if ( '' !== $description ) { $author['description'] = $description; }
        $jobTitle = trim( wp_strip_all_tags( (string) get_user_meta( $authorId, '_yps_author_job_title', true ), true ) );
        if ( '' !== $jobTitle ) { $author['jobTitle'] = $jobTitle; }
        $image = $this->normalizePublicUrl( (string) get_user_meta( $authorId, '_yps_author_image_url', true ) );
        if ( '' !== $image ) { $author['image'] = array( '@type' => 'ImageObject', 'url' => $image ); }
        $sameAs = $this->urlList( (string) get_user_meta( $authorId, '_yps_author_same_as', true ) );
        foreach ( array( 'facebook','instagram','linkedin','pinterest','twitter','youtube','wikipedia','mastodon' ) as $network ) {
            $url = $this->normalizePublicUrl( (string) get_user_meta( $authorId, '_yps_author_social_' . $network, true ) );
            if ( '' !== $url ) { $sameAs[] = $url; }
        }
        $sameAs = array_values( array_unique( $sameAs ) );
        if ( ! empty( $sameAs ) ) { $author['sameAs'] = $sameAs; }
        $knowsAbout = $this->textList( (string) get_user_meta( $authorId, '_yps_author_knows_about', true ) );
        if ( ! empty( $knowsAbout ) ) { $author['knowsAbout'] = $knowsAbout; }
        return $author;
    }

    /** @return array<int,string> */
    private function urlList( string $raw ): array {
        $parts = preg_split( '/[\r\n,]+/', $raw );
        if ( ! is_array( $parts ) ) { return array(); }
        $out = array();
        foreach ( $parts as $part ) {
            $url = $this->normalizePublicUrl( trim( $part ) );
            if ( '' !== $url ) { $out[] = $url; }
        }
        return array_values( array_unique( $out ) );
    }

    /** @return array<int,string> */
    private function textList( string $raw ): array {
        $parts = preg_split( '/[\r\n,]+/', $raw );
        if ( ! is_array( $parts ) ) { return array(); }
        $out = array();
        foreach ( $parts as $part ) {
            $value = trim( wp_strip_all_tags( $part, true ) );
            if ( '' !== $value ) { $out[] = $value; }
        }
        return array_values( array_unique( $out ) );
    }

    private function schemaType( string $raw, string $fallback ): string {
        $map = array(
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
            'article' => 'Article',
            'newsarticle' => 'NewsArticle',
            'blogposting' => 'BlogPosting',
            'report' => 'Report',
            'scholarlyarticle' => 'ScholarlyArticle',
            'socialmediaposting' => 'SocialMediaPosting',
            'techarticle' => 'TechArticle',
        );
        $key = strtolower( str_replace( array( '-', '_' ), '', $raw ) );
        return isset( $map[ $key ] ) ? $map[ $key ] : $fallback;
    }

    private function mediaUrl( string $url, int $attachmentId, ResourceIdentity $resource ): string {
        $url = $this->normalizePublicUrl( $url );
        if ( '' !== $url ) {
            return $url;
        }
        if ( $attachmentId > 0 ) {
            $attachment = wp_get_attachment_image_url( $attachmentId, 'full' );
            if ( is_string( $attachment ) ) {
                $attachment = $this->normalizePublicUrl( $attachment );
                if ( '' !== $attachment ) {
                    return $attachment;
                }
            }
        }
        if ( $resource->objectId() > 0 && has_post_thumbnail( $resource->objectId() ) ) {
            $featured = get_the_post_thumbnail_url( $resource->objectId(), 'full' );
            return is_string( $featured ) ? $this->normalizePublicUrl( $featured ) : '';
        }
        return '';
    }

    /** @return array<int,array{language_key:string,url:string}> */
    private function normalizeAlternates( ResourceIdentity $resource, string $canonical ): array {
        $language = new WordPressLanguageProvider();
        $items = $language->alternates( $resource->type(), $resource->objectId() );
        if ( empty( $items ) ) { return array(); }

        $byLanguage = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $lang = $this->normalizeLanguageKey( (string) ( $item['language_key'] ?? '' ) );
            $url = $this->normalizePublicUrl( (string) ( $item['url'] ?? '' ) );
            if ( '' === $lang || '' === $url ) { continue; }
            $byLanguage[ $lang ] = $url;
        }
        if ( empty( $byLanguage ) ) { return array(); }

        $selfLanguage = $this->normalizeLanguageKey( $resource->languageKey() );
        if ( '' !== $selfLanguage && '' !== $canonical ) { $byLanguage[ $selfLanguage ] = $canonical; }
        ksort( $byLanguage, SORT_STRING );
        $out = array();
        foreach ( $byLanguage as $lang => $url ) { $out[] = array( 'language_key' => $lang, 'url' => $url ); }
        return $out;
    }

    private function normalizeLanguageKey( string $language ): string {
        $language = strtolower( str_replace( '_', '-', trim( $language ) ) );
        if ( 'x-default' === $language ) { return $language; }
        return preg_match( '/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/', $language ) ? $language : '';
    }

    private function normalizePublicUrl( string $url ): string {
        $url = trim( $url );
        if ( '' === $url ) {
            return '';
        }
        $sanitized = esc_url_raw( $url, array( 'http', 'https' ) );
        return is_string( $sanitized ) ? $sanitized : '';
    }

    private function normalizeSameOriginUrl( string $url ): string {
        $url = trim( $url );
        if ( '' === $url ) {
            return '';
        }
        if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
            $url = home_url( $url );
        }
        $url = $this->normalizePublicUrl( $url );
        if ( '' === $url ) {
            return '';
        }
        $target = wp_parse_url( $url );
        $home = wp_parse_url( home_url( '/' ) );
        if ( ! is_array( $target ) || ! is_array( $home ) || empty( $target['host'] ) || empty( $home['host'] ) ) {
            return '';
        }
        if ( strtolower( (string) $target['host'] ) !== strtolower( (string) $home['host'] ) ) {
            return '';
        }
        return $url;
    }

    /** @param mixed $value */
    private function metaBool( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_int( $value ) || is_float( $value ) ) {
            return 1 === (int) $value;
        }
        $value = strtolower( trim( (string) $value ) );
        return in_array( $value, array( '1', 'true', 'yes', 'on', 'noindex', 'nofollow' ), true );
    }

    /** @param array<int,string> $values */
    private function firstNonEmpty( array $values ): string {
        foreach ( $values as $value ) {
            if ( '' !== trim( $value ) ) {
                return $value;
            }
        }
        return '';
    }

    private function limitText( string $value, int $max ): string {
        $value = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value, true ) ) );
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            return mb_strlen( $value ) > $max ? rtrim( mb_substr( $value, 0, $max ) ) : $value;
        }
        return strlen( $value ) > $max ? rtrim( substr( $value, 0, $max ) ) : $value;
    }
}
