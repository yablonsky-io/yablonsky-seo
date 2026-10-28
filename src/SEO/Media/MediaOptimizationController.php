<?php
namespace Yablonsky\PerformanceSEO\SEO\Media;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MediaOptimizationController {
    /** @var array<string,mixed> */
    private $settings;

    public function __construct() {
        $settings = get_option( 'yps_public_settings', array() );
        $this->settings = is_array( $settings ) ? $settings : array();
    }

    public function register(): void {
        if ( empty( $this->settings['media_redirect_attachment_pages'] ) ) { return; }
        add_action( 'template_redirect', array( $this, 'maybeRedirectAttachment' ), 2 );
    }

    public function maybeRedirectAttachment(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_attachment() ) { return; }
        $id = (int) get_queried_object_id();
        if ( $id < 1 ) { return; }
        $mode = (string) ( $this->settings['media_redirect_target'] ?? 'file' );
        $target = '';
        if ( 'parent' === $mode ) {
            $parentId = (int) wp_get_post_parent_id( $id );
            if ( $parentId > 0 && 'publish' === get_post_status( $parentId ) ) {
                $parent = get_permalink( $parentId );
                $target = is_string( $parent ) ? $parent : '';
            }
        }
        if ( '' === $target ) {
            $file = wp_get_attachment_url( $id );
            $target = is_string( $file ) ? $file : '';
        }
        if ( '' === $target ) { return; }
        wp_safe_redirect( $target, 301, 'Yablonsky SEO' );
        exit;
    }
}
