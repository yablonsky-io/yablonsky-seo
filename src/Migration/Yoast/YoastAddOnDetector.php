<?php
namespace Yablonsky\PerformanceSEO\Migration\Yoast;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class YoastAddOnDetector {
    /** @return array<int,array<string,string>> */
    public function detect(): array {
        $active = get_option( 'active_plugins', array() );
        $active = is_array( $active ) ? array_map( 'strval', $active ) : array();
        $known = array(
            'premium'     => array( 'wordpress-seo-premium/wp-seo-premium.php', 'Yoast SEO Premium', 'requires-exact-redirect-parity' ),
            'woocommerce' => array( 'wpseo-woocommerce/wpseo-woocommerce.php', 'Yoast WooCommerce SEO', 'requires-addon-parity' ),
            'local'       => array( 'wpseo-local/local-seo.php', 'Yoast Local SEO', 'requires-addon-parity' ),
            'news'        => array( 'wpseo-news/wpseo-news.php', 'Yoast News SEO', 'requires-addon-parity' ),
            'video'       => array( 'wpseo-video/video-seo.php', 'Yoast Video SEO', 'requires-addon-parity' ),
        );
        $found = array();
        foreach ( $known as $id => $definition ) {
            if ( in_array( $definition[0], $active, true ) ) {
                $found[] = array(
                    'id'     => (string) $id,
                    'name'   => (string) $definition[1],
                    'plugin' => (string) $definition[0],
                    'state'  => (string) $definition[2],
                );
            }
        }

        foreach ( $active as $pluginFile ) {
            $lower = strtolower( $pluginFile );
            if ( false === strpos( $lower, 'yoast' ) && false === strpos( $lower, 'wpseo' ) ) {
                continue;
            }
            $isKnown = 'wordpress-seo/wp-seo.php' === $pluginFile;
            foreach ( $known as $definition ) {
                if ( $pluginFile === $definition[0] ) {
                    $isKnown = true;
                    break;
                }
            }
            if ( ! $isKnown ) {
                $found[] = array(
                    'id'     => 'unknown',
                    'name'   => 'Unknown Yoast-related add-on',
                    'plugin' => $pluginFile,
                    'state'  => 'blocking-unknown-addon',
                );
            }
        }
        return $found;
    }
}
