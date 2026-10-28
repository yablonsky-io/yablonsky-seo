<?php
namespace Yablonsky\PerformanceSEO\SEO\Local;

use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapPresentation;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LocalSeoController {
    private const POST_TYPE = 'yseo_location';
    private const NONCE = 'yseo_location_meta_save';
    /** @var bool */ private $emitPublicSeo;

    public function __construct( bool $emitPublicSeo = true ) { $this->emitPublicSeo = $emitPublicSeo; }

    public function register(): void {
        add_action( 'init', array( $this, 'registerPostType' ), 5 );
        add_action( 'add_meta_boxes', array( $this, 'registerMetaBox' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( $this, 'saveMeta' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'adminAssets' ) );
        add_action( 'admin_notices', array( $this, 'locationOnboarding' ) );
        add_action( 'admin_menu', array( $this, 'normalizeSeoMenuOrder' ), 999 );
        if ( $this->emitPublicSeo ) {
            add_action( 'template_redirect', array( $this, 'route' ), 0 );
            add_filter( 'yablonsky_seo_schema_graph_v1', array( $this, 'augmentSchema' ), 20, 3 );
            add_filter( 'yablonsky_seo_sitemap_index_extra_v1', array( $this, 'sitemapIndexEntry' ) );
        }
        add_shortcode( 'yseo_store_locator', array( $this, 'storeLocatorShortcode' ) );
        add_shortcode( 'yseo_location_map', array( $this, 'locationMapShortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'registerAssets' ) );
    }

    /** @param array<int,array<string,string>> $items @return array<int,array<string,string>> */
    public function sitemapIndexEntry( array $items ): array {
        $latest = $this->locationPosts();
        if ( ! empty( $latest ) && $latest[0] instanceof \WP_Post ) {
            $lastmod = (string) get_post_modified_time( 'c', true, (int) $latest[0]->ID );
            $items[] = array( 'loc' => home_url( '/geo-sitemap.xml' ), 'lastmod' => $lastmod );
        }
        return $items;
    }

    public function registerPostType(): void {
        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => array(
                    'name' => __( 'Locations', 'yablonsky-seo' ),
                    'singular_name' => __( 'Location', 'yablonsky-seo' ),
                    'add_new_item' => __( 'Add location', 'yablonsky-seo' ),
                    'edit_item' => __( 'Edit location', 'yablonsky-seo' ),
                    'view_item' => __( 'View location', 'yablonsky-seo' ),
                    'search_items' => __( 'Search locations', 'yablonsky-seo' ),
                ),
                'public' => true,
                'show_ui' => true,
                'show_in_menu' => 'yablonsky-seo',
                'show_in_rest' => true,
                'has_archive' => true,
                'rewrite' => array( 'slug' => 'locations', 'with_front' => false ),
                'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
                'menu_icon' => 'dashicons-location-alt',
            )
        );
    }

    public function registerAssets(): void {
        wp_register_style( 'yseo-local', YPS_URL . 'assets/local.css', array(), YPS_VERSION );
        wp_register_script( 'yseo-local', YPS_URL . 'assets/local.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
    }

    public function adminAssets(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen instanceof \WP_Screen || self::POST_TYPE !== (string) $screen->post_type ) { return; }
        wp_enqueue_style( 'yps-admin', YPS_URL . 'assets/admin.css', array(), YPS_VERSION );
        wp_enqueue_script( 'yps-admin-editor', YPS_URL . 'assets/admin.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
    }

    public function locationOnboarding(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen instanceof \WP_Screen || 'edit' !== (string) $screen->base || self::POST_TYPE !== (string) $screen->post_type ) { return; }
        $counts = wp_count_posts( self::POST_TYPE, 'readable' );
        $total = 0;
        if ( is_object( $counts ) ) {
            foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $status ) {
                if ( isset( $counts->{$status} ) ) { $total += (int) $counts->{$status}; }
            }
        }
        if ( $total > 0 ) { return; }
        $addUrl = admin_url( 'post-new.php?post_type=' . self::POST_TYPE );
        ?>
        <div class="yseo-location-onboarding" role="region" aria-label="<?php echo esc_attr__( 'Local SEO setup', 'yablonsky-seo' ); ?>">
            <div class="yseo-location-onboarding-copy">
                <span class="yps-card-kicker"><?php echo esc_html__( 'Local SEO', 'yablonsky-seo' ); ?></span>
                <h2><?php echo esc_html__( 'Add a real-world business location only when your site needs local search visibility', 'yablonsky-seo' ); ?></h2>
                <p><?php echo esc_html__( 'A Location is a public page for a shop, office, restaurant, clinic, branch or other physical place. YSEO turns the saved address, contact details, coordinates and opening hours into LocalBusiness Schema and local discovery files.', 'yablonsky-seo' ); ?></p>
                <p class="yps-muted"><?php echo esc_html__( 'Customer-facing physical locations use this section. Other sites can leave it empty while ordinary site SEO remains complete.', 'yablonsky-seo' ); ?></p>
                <div class="yseo-location-actions"><a class="button button-primary" href="<?php echo esc_url( $addUrl ); ?>"><?php echo esc_html__( 'Add first location', 'yablonsky-seo' ); ?></a></div>
            </div>
            <div class="yseo-location-output-grid">
                <div><strong><?php echo esc_html__( 'Structured data', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'LocalBusiness-family Schema is added through YSEO’s shared Schema graph.', 'yablonsky-seo' ); ?></span></div>
                <div><strong><?php echo esc_html__( 'Machine discovery', 'yablonsky-seo' ); ?></strong><span><code>/geo-sitemap.xml</code> · <code>/locations.kml</code></span></div>
                <div><strong><?php echo esc_html__( 'Store locator', 'yablonsky-seo' ); ?></strong><span><code>[yseo_store_locator]</code></span></div>
                <div><strong><?php echo esc_html__( 'Location map', 'yablonsky-seo' ); ?></strong><span><code>[yseo_location_map]</code></span></div>
            </div>
        </div>
        <?php
    }

    public function normalizeSeoMenuOrder(): void {
        global $submenu;
        if ( ! isset( $submenu['yablonsky-seo'] ) || ! is_array( $submenu['yablonsky-seo'] ) ) { return; }
        $priority = array(
            'yablonsky-seo' => 10,
            'yablonsky-seo-settings' => 20,
            'yablonsky-seo-redirects' => 30,
            'yablonsky-seo-internal-links' => 40,
            'edit.php?post_type=' . self::POST_TYPE => 50,
            'yablonsky-seo-sitemaps' => 60,
            'yablonsky-seo-404-monitor' => 70,
            'yablonsky-seo-discovery' => 80,
            'yablonsky-seo-bulk-editor' => 90,
            'yablonsky-seo-search-performance' => 100,
        );
        usort(
            $submenu['yablonsky-seo'],
            static function ( array $a, array $b ) use ( $priority ): int {
                $aSlug = isset( $a[2] ) ? (string) $a[2] : '';
                $bSlug = isset( $b[2] ) ? (string) $b[2] : '';
                $aRank = $priority[ $aSlug ] ?? 100;
                $bRank = $priority[ $bSlug ] ?? 100;
                return $aRank === $bRank ? 0 : ( $aRank < $bRank ? -1 : 1 );
            }
        );
    }

    public function registerMetaBox(): void {
        add_meta_box( 'yseo-location-details', __( 'Yablonsky Local SEO', 'yablonsky-seo' ), array( $this, 'renderMetaBox' ), self::POST_TYPE, 'normal', 'high' );
    }

    public function renderMetaBox( \WP_Post $post ): void {
        wp_nonce_field( self::NONCE, 'yseo_location_nonce' );
        $fields = $this->fieldDefinitions();
        echo '<div class="yps-location-grid">';
        foreach ( $fields as $key => $field ) {
            $value = (string) get_post_meta( $post->ID, '_yps_local_' . $key, true );
            $wide = ! empty( $field['wide'] ) ? ' yps-field-wide' : '';
            echo '<div class="yps-setting-field' . esc_attr( $wide ) . '"><label for="yseo-local-' . esc_attr( $key ) . '">' . esc_html( (string) $field['label'] ) . '</label>';
            if ( 'textarea' === (string) $field['type'] ) {
                echo '<textarea rows="4" id="yseo-local-' . esc_attr( $key ) . '" name="yseo_local_' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';
            } elseif ( 'select' === (string) $field['type'] ) {
                echo '<select id="yseo-local-' . esc_attr( $key ) . '" name="yseo_local_' . esc_attr( $key ) . '">';
                foreach ( (array) $field['options'] as $optionValue => $label ) {
                    echo '<option value="' . esc_attr( (string) $optionValue ) . '" ' . selected( $value, (string) $optionValue, false ) . '>' . esc_html( (string) $label ) . '</option>';
                }
                echo '</select>';
            } else {
                echo '<input id="yseo-local-' . esc_attr( $key ) . '" name="yseo_local_' . esc_attr( $key ) . '" type="' . esc_attr( (string) $field['type'] ) . '" value="' . esc_attr( $value ) . '" />';
            }
            if ( ! empty( $field['help'] ) ) { echo '<small>' . esc_html( (string) $field['help'] ) . '</small>'; }
            echo '</div>';
        }
        echo '</div>';
    }

    public function saveMeta( int $postId, \WP_Post $post ): void {
        unset( $post );
        if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) || ! current_user_can( 'edit_post', $postId ) ) { return; }
        $rawNonce = filter_input( INPUT_POST, 'yseo_location_nonce', FILTER_UNSAFE_RAW );
        if ( ! is_string( $rawNonce ) || ! wp_verify_nonce( sanitize_text_field( $rawNonce ), self::NONCE ) ) { return; }
        foreach ( $this->fieldDefinitions() as $key => $field ) {
            $raw = filter_input( INPUT_POST, 'yseo_local_' . $key, FILTER_UNSAFE_RAW );
            $value = is_string( $raw ) ? $raw : '';
            if ( 'url' === (string) $field['type'] ) { $value = esc_url_raw( $value, array( 'http', 'https' ) ); }
            elseif ( in_array( $key, array( 'latitude', 'longitude' ), true ) ) { $value = $this->coordinate( $value, 'latitude' === $key ); }
            elseif ( 'textarea' === (string) $field['type'] ) { $value = sanitize_textarea_field( $value ); }
            else { $value = sanitize_text_field( $value ); }
            if ( '' === $value ) { delete_post_meta( $postId, '_yps_local_' . $key ); }
            else { update_post_meta( $postId, '_yps_local_' . $key, $value ); }
        }
    }

    public function route(): void {
        $requestUri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
        $path = trim( (string) wp_parse_url( is_string( $requestUri ) ? $requestUri : '', PHP_URL_PATH ), '/' );
        if ( 'geo-sitemap.xml' === $path ) { $this->renderGeoSitemap(); exit; }
        if ( 'locations.kml' === $path ) { $this->renderKml(); exit; }
    }

    /** @param array<string,mixed> $graph @param array<string,mixed> $document @return array<string,mixed> */
    public function augmentSchema( array $graph, array $document, $resource ): array {
        if ( ! is_object( $resource ) || ! method_exists( $resource, 'objectId' ) || ! method_exists( $resource, 'subtype' ) ) { return $graph; }
        if ( self::POST_TYPE !== (string) $resource->subtype() ) { return $graph; }
        $postId = (int) $resource->objectId();
        if ( $postId <= 0 ) { return $graph; }
        $local = $this->locationData( $postId );
        if ( empty( $local['name'] ) ) { return $graph; }
        $canonical = isset( $document['canonical'] ) ? (string) $document['canonical'] : (string) get_permalink( $postId );
        $piece = array(
            '@type' => (string) $local['type'],
            '@id' => $canonical . '#localbusiness',
            'name' => (string) $local['name'],
            'url' => $canonical,
        );
        if ( '' !== (string) $local['phone'] ) { $piece['telephone'] = (string) $local['phone']; }
        if ( '' !== (string) $local['email'] ) { $piece['email'] = (string) $local['email']; }
        if ( '' !== (string) $local['price_range'] ) { $piece['priceRange'] = (string) $local['price_range']; }
        $address = $this->schemaAddress( $local );
        if ( ! empty( $address ) ) { $piece['address'] = $address; }
        if ( '' !== (string) $local['latitude'] && '' !== (string) $local['longitude'] ) {
            $piece['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => (float) $local['latitude'], 'longitude' => (float) $local['longitude'] );
        }
        $hours = $this->openingHours( (string) $local['opening_hours'] );
        if ( ! empty( $hours ) ) { $piece['openingHours'] = $hours; }
        if ( has_post_thumbnail( $postId ) ) {
            $image = get_the_post_thumbnail_url( $postId, 'full' );
            if ( is_string( $image ) && '' !== $image ) { $piece['image'] = esc_url_raw( $image, array( 'http', 'https' ) ); }
        }
        $graph['@graph'] = isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ? $graph['@graph'] : array();
        $graph['@graph'][] = $piece;
        foreach ( $graph['@graph'] as &$candidate ) {
            if ( is_array( $candidate ) && isset( $candidate['@id'] ) && $canonical . '#webpage' === (string) $candidate['@id'] ) { $candidate['mainEntity'] = array( '@id' => $canonical . '#localbusiness' ); }
        }
        unset( $candidate );
        return $graph;
    }

    /** @param array<string,mixed> $atts */
    public function storeLocatorShortcode( array $atts = array() ): string {
        $atts = shortcode_atts( array( 'limit' => 100, 'search' => '1' ), $atts, 'yseo_store_locator' );
        $limit = min( 250, max( 1, (int) $atts['limit'] ) );
        $posts = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => $limit, 'orderby' => 'menu_order title', 'order' => 'ASC', 'no_found_rows' => true ) );
        if ( empty( $posts ) ) { return '<div class="yseo-store-locator-empty">' . esc_html__( 'Published locations will appear here after a location is published.', 'yablonsky-seo' ) . '</div>'; }
        wp_enqueue_style( 'yseo-local' );
        wp_enqueue_script( 'yseo-local' );
        ob_start();
        ?>
        <div class="yseo-store-locator" data-yseo-store-locator>
            <?php if ( '0' !== (string) $atts['search'] ) : ?><label class="yseo-store-search"><span><?php echo esc_html__( 'Find a location', 'yablonsky-seo' ); ?></span><input type="search" data-yseo-location-search placeholder="<?php echo esc_attr__( 'Search by name, city or address', 'yablonsky-seo' ); ?>" /></label><?php endif; ?>
            <div class="yseo-store-grid">
            <?php foreach ( $posts as $post ) : if ( ! $post instanceof \WP_Post ) { continue; } $local = $this->locationData( (int) $post->ID ); $haystack = strtolower( implode( ' ', array( $local['name'], $local['street'], $local['city'], $local['region'], $local['postal_code'], $local['country'] ) ) ); ?>
                <article class="yseo-store-card" data-yseo-location-card data-yseo-location-text="<?php echo esc_attr( $haystack ); ?>">
                    <h3><a href="<?php echo esc_url( (string) get_permalink( $post ) ); ?>"><?php echo esc_html( (string) $local['name'] ); ?></a></h3>
                    <?php $addressText = $this->addressText( $local ); if ( '' !== $addressText ) : ?><p><?php echo esc_html( $addressText ); ?></p><?php endif; ?>
                    <?php if ( '' !== (string) $local['phone'] ) : ?><p><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', (string) $local['phone'] ) ); ?>"><?php echo esc_html( (string) $local['phone'] ); ?></a></p><?php endif; ?>
                    <?php if ( '' !== (string) $local['opening_hours'] ) : ?><p class="yseo-store-hours"><?php echo wp_kses_post( nl2br( esc_html( (string) $local['opening_hours'] ) ) ); ?></p><?php endif; ?>
                    <?php $map = $this->mapLink( $local ); if ( '' !== $map ) : ?><a class="yseo-store-map-link" href="<?php echo esc_url( $map ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open map', 'yablonsky-seo' ); ?></a><?php endif; ?>
                </article>
            <?php endforeach; ?>
            </div>
            <p class="yseo-store-no-results" data-yseo-location-empty hidden><?php echo esc_html__( 'Adjust the search to find matching locations.', 'yablonsky-seo' ); ?></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $atts */
    public function locationMapShortcode( array $atts = array() ): string {
        $atts = shortcode_atts( array( 'id' => 0, 'height' => 360 ), $atts, 'yseo_location_map' );
        $postId = max( 0, (int) $atts['id'] );
        if ( 0 === $postId && is_singular( self::POST_TYPE ) ) { $postId = (int) get_queried_object_id(); }
        if ( $postId <= 0 ) { return ''; }
        $local = $this->locationData( $postId );
        if ( '' === (string) $local['latitude'] || '' === (string) $local['longitude'] ) { return ''; }
        $lat = (float) $local['latitude']; $lng = (float) $local['longitude'];
        $delta = 0.012;
        $bbox = implode( '%2C', array( rawurlencode( (string) ( $lng - $delta ) ), rawurlencode( (string) ( $lat - $delta ) ), rawurlencode( (string) ( $lng + $delta ) ), rawurlencode( (string) ( $lat + $delta ) ) ) );
        $src = 'https://www.openstreetmap.org/export/embed.html?bbox=' . $bbox . '&layer=mapnik&marker=' . rawurlencode( (string) $lat ) . '%2C' . rawurlencode( (string) $lng );
        $height = min( 800, max( 180, (int) $atts['height'] ) );
        return '<iframe class="yseo-location-map" title="' . esc_attr__( 'Location map', 'yablonsky-seo' ) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="' . esc_url( $src ) . '" style="border:0;width:100%;height:' . esc_attr( (string) $height ) . 'px"></iframe>';
    }

    private function renderGeoSitemap(): void {
        $posts = $this->locationPosts();
        SitemapPresentation::sendXmlHeaders();
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ( $posts as $post ) {
            $url = get_permalink( $post ); if ( ! is_string( $url ) || '' === $url ) { continue; }
            echo '<url><loc>' . esc_url( $url ) . '</loc><lastmod>' . esc_html( (string) get_post_modified_time( 'c', true, $post ) ) . '</lastmod></url>' . "\n";
        }
        echo '</urlset>' . "\n";
    }

    private function renderKml(): void {
        $posts = $this->locationPosts();
        nocache_headers(); header( 'Content-Type: application/vnd.google-earth.kml+xml; charset=UTF-8' );
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<kml xmlns="http://www.opengis.net/kml/2.2"><Document>' . "\n";
        foreach ( $posts as $post ) {
            $local = $this->locationData( (int) $post->ID );
            if ( '' === (string) $local['latitude'] || '' === (string) $local['longitude'] ) { continue; }
            echo '<Placemark><name>' . esc_html( (string) $local['name'] ) . '</name><description>' . esc_html( $this->addressText( $local ) ) . '</description><Point><coordinates>' . esc_html( (string) $local['longitude'] . ',' . (string) $local['latitude'] . ',0' ) . '</coordinates></Point></Placemark>' . "\n";
        }
        echo '</Document></kml>' . "\n";
    }

    /** @return array<int,\WP_Post> */
    private function locationPosts(): array {
        $items = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 1000, 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'update_post_meta_cache' => true, 'suppress_filters' => false ) );
        if ( ! is_array( $items ) ) { return array(); }
        return array_values(
            array_filter(
                $items,
                static function ( $post ): bool {
                    return $post instanceof \WP_Post && '1' !== (string) get_post_meta( (int) $post->ID, '_yps_robots_noindex', true );
                }
            )
        );
    }

    /** @return array<string,mixed> */
    private function locationData( int $postId ): array {
        $get = static function ( string $key ) use ( $postId ): string { return trim( (string) get_post_meta( $postId, '_yps_local_' . $key, true ) ); };
        $type = $get( 'business_type' );
        $allowed = array( 'LocalBusiness', 'Store', 'ProfessionalService', 'Restaurant', 'Hotel', 'MedicalBusiness', 'AutomotiveBusiness', 'FinancialService', 'RealEstateAgent', 'TravelAgency' );
        if ( ! in_array( $type, $allowed, true ) ) { $type = 'LocalBusiness'; }
        return array(
            'name' => '' !== $get( 'business_name' ) ? $get( 'business_name' ) : (string) get_the_title( $postId ),
            'type' => $type,
            'street' => $get( 'street' ), 'city' => $get( 'city' ), 'region' => $get( 'region' ), 'postal_code' => $get( 'postal_code' ), 'country' => $get( 'country' ),
            'phone' => $get( 'phone' ), 'email' => sanitize_email( $get( 'email' ) ), 'price_range' => $get( 'price_range' ),
            'latitude' => $this->coordinate( $get( 'latitude' ), true ), 'longitude' => $this->coordinate( $get( 'longitude' ), false ),
            'opening_hours' => $get( 'opening_hours' ), 'map_url' => esc_url_raw( $get( 'map_url' ), array( 'http', 'https' ) ),
        );
    }

    /** @param array<string,mixed> $local @return array<string,mixed> */
    private function schemaAddress( array $local ): array {
        $address = array( '@type' => 'PostalAddress' );
        foreach ( array( 'street' => 'streetAddress', 'city' => 'addressLocality', 'region' => 'addressRegion', 'postal_code' => 'postalCode', 'country' => 'addressCountry' ) as $key => $schemaKey ) {
            if ( ! empty( $local[ $key ] ) ) { $address[ $schemaKey ] = (string) $local[ $key ]; }
        }
        return count( $address ) > 1 ? $address : array();
    }

    /** @param array<string,mixed> $local */
    private function addressText( array $local ): string {
        return implode( ', ', array_filter( array_map( 'trim', array( (string) $local['street'], (string) $local['city'], (string) $local['region'], (string) $local['postal_code'], (string) $local['country'] ) ) ) );
    }

    /** @param array<string,mixed> $local */
    private function mapLink( array $local ): string {
        if ( ! empty( $local['map_url'] ) ) { return (string) $local['map_url']; }
        if ( '' !== (string) $local['latitude'] && '' !== (string) $local['longitude'] ) { return 'https://www.openstreetmap.org/?mlat=' . rawurlencode( (string) $local['latitude'] ) . '&mlon=' . rawurlencode( (string) $local['longitude'] ) . '#map=16/' . rawurlencode( (string) $local['latitude'] ) . '/' . rawurlencode( (string) $local['longitude'] ); }
        $address = $this->addressText( $local );
        return '' !== $address ? 'https://www.openstreetmap.org/search?query=' . rawurlencode( $address ) : '';
    }

    /** @return array<int,string> */
    private function openingHours( string $raw ): array {
        $lines = preg_split( '/[\r\n]+/', $raw ); if ( ! is_array( $lines ) ) { return array(); }
        $out = array(); foreach ( $lines as $line ) { $line = trim( wp_strip_all_tags( $line, true ) ); if ( '' !== $line && strlen( $line ) <= 80 ) { $out[] = $line; } }
        return array_slice( $out, 0, 14 );
    }

    private function coordinate( string $value, bool $latitude ): string {
        $value = str_replace( ',', '.', trim( $value ) );
        if ( ! preg_match( '/^-?\d{1,3}(?:\.\d{1,8})?$/', $value ) ) { return ''; }
        $number = (float) $value; $limit = $latitude ? 90.0 : 180.0;
        return abs( $number ) <= $limit ? rtrim( rtrim( sprintf( '%.8F', $number ), '0' ), '.' ) : '';
    }

    /** @return array<string,array<string,mixed>> */
    private function fieldDefinitions(): array {
        return array(
            'business_name' => array( 'label' => __( 'Business / location name', 'yablonsky-seo' ), 'type' => 'text' ),
            'business_type' => array( 'label' => __( 'Business type', 'yablonsky-seo' ), 'type' => 'select', 'options' => array( 'LocalBusiness' => 'LocalBusiness', 'Store' => 'Store', 'ProfessionalService' => 'ProfessionalService', 'Restaurant' => 'Restaurant', 'Hotel' => 'Hotel', 'MedicalBusiness' => 'MedicalBusiness', 'AutomotiveBusiness' => 'AutomotiveBusiness', 'FinancialService' => 'FinancialService', 'RealEstateAgent' => 'RealEstateAgent', 'TravelAgency' => 'TravelAgency' ) ),
            'street' => array( 'label' => __( 'Street address', 'yablonsky-seo' ), 'type' => 'text' ),
            'city' => array( 'label' => __( 'City / locality', 'yablonsky-seo' ), 'type' => 'text' ),
            'region' => array( 'label' => __( 'Region / state', 'yablonsky-seo' ), 'type' => 'text' ),
            'postal_code' => array( 'label' => __( 'Postal code', 'yablonsky-seo' ), 'type' => 'text' ),
            'country' => array( 'label' => __( 'Country code', 'yablonsky-seo' ), 'type' => 'text', 'help' => __( 'Prefer the two-letter country code, for example LT, DE or US.', 'yablonsky-seo' ) ),
            'phone' => array( 'label' => __( 'Telephone', 'yablonsky-seo' ), 'type' => 'text' ),
            'email' => array( 'label' => __( 'Public email', 'yablonsky-seo' ), 'type' => 'email' ),
            'price_range' => array( 'label' => __( 'Price range', 'yablonsky-seo' ), 'type' => 'text', 'help' => __( 'Optional public shorthand such as €, €€ or €€€.', 'yablonsky-seo' ) ),
            'latitude' => array( 'label' => __( 'Latitude', 'yablonsky-seo' ), 'type' => 'text' ),
            'longitude' => array( 'label' => __( 'Longitude', 'yablonsky-seo' ), 'type' => 'text' ),
            'map_url' => array( 'label' => __( 'Preferred map URL', 'yablonsky-seo' ), 'type' => 'url', 'wide' => true ),
            'opening_hours' => array( 'label' => __( 'Opening hours', 'yablonsky-seo' ), 'type' => 'textarea', 'wide' => true, 'help' => __( 'One Schema.org opening-hours expression per line, for example Mo-Fr 09:00-17:00.', 'yablonsky-seo' ) ),
        );
    }
}
