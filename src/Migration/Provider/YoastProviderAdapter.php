<?php
namespace Yablonsky\PerformanceSEO\Migration\Provider;

use Yablonsky\PerformanceSEO\Migration\Contract\AdapterCompatibility;
use Yablonsky\PerformanceSEO\Migration\Contract\MigrationProviderAdapterInterface;
use Yablonsky\PerformanceSEO\Runtime\Provider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class YoastProviderAdapter implements MigrationProviderAdapterInterface {
    public function id(): string { return 'yoast'; }
    public function label(): string { return 'Yoast SEO'; }

    public function compatibility( Provider $provider ): string {
        if ( 'yoast' !== $provider->id() ) {
            return AdapterCompatibility::UNSUPPORTED;
        }
        if ( in_array( $provider->version(), array( '26.8', '28.4' ), true ) ) {
            return AdapterCompatibility::VERIFIED;
        }
        if ( '' === $provider->version() ) {
            return AdapterCompatibility::UNKNOWN_VERSION;
        }
        // Storage compatibility for newer/older releases must be proven explicitly.
        // A version string alone is not a storage-signature verification.
        return AdapterCompatibility::UNKNOWN_VERSION;
    }

    public function canExecuteShadowImport( Provider $provider ): bool {
        return AdapterCompatibility::VERIFIED === $this->compatibility( $provider );
    }

    /** @return array<string,string> */
    public function capabilityMatrix(): array {
        return array(
            'inventory'              => 'implemented',
            'post_meta'              => 'implemented',
            'taxonomy'               => 'implemented',
            'global_settings'        => 'implemented-shadow',
            'social'                 => 'implemented-shadow',
            'schema'                 => 'implemented-shadow',
            'primary_terms'          => 'implemented',
            'redirects'              => 'post-redirects-mapped; premium-storage-gated',
            'addons'                 => 'detected-and-gated',
            'derived_tables'         => 'inventory-validation',
            'provenance'             => 'implemented',
            'semantic_parity'        => 'core-ready',
            'sitemap_parity'         => 'core-ready',
            'backup_export'          => 'pre-cutover-required',
            'rollback'               => 'source-authority-preserved',
            'cutover'                => 'locked',
        );
    }

    /** @return array<int,string> */
    public function sourceSignatures(): array {
        return array(
            'option:wpseo',
            'option:wpseo_titles',
            'option:wpseo_social',
            'option:wpseo_taxonomy_meta',
            'option:wpseo_llmstxt',
            'meta:_yoast_wpseo_*',
            'table:*_yoast_indexable',
            'table:*_yoast_primary_term',
        );
    }
}
