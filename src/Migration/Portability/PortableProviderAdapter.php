<?php
namespace Yablonsky\PerformanceSEO\Migration\Portability;

use Yablonsky\PerformanceSEO\Migration\Contract\AdapterCompatibility;
use Yablonsky\PerformanceSEO\Migration\Contract\MigrationProviderAdapterInterface;
use Yablonsky\PerformanceSEO\Runtime\Provider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Advertises a verified preserve-only portability surface without pretending
 * that the provider has a full takeover/rollback adapter.
 */
final class PortableProviderAdapter implements MigrationProviderAdapterInterface {
    /** @var PortableSourceProfile */ private $profile;
    public function __construct( PortableSourceProfile $profile ) { $this->profile = $profile; }
    public function id(): string { return $this->profile->id(); }
    public function label(): string { return $this->profile->label(); }
    public function compatibility( Provider $provider ): string {
        return $provider->id() === $this->id() ? AdapterCompatibility::SIGNATURE_COMPATIBLE : AdapterCompatibility::UNSUPPORTED;
    }
    public function canExecuteShadowImport( Provider $provider ): bool { unset( $provider ); return false; }
    /** @return array<string,string> */
    public function capabilityMatrix(): array {
        return array(
            'inventory'       => 'implemented',
            'post_meta'       => $this->profile->supportsPosts() ? 'preserve-only-portable' : 'audit-only',
            'taxonomy'        => $this->profile->supportsTerms() ? 'preserve-only-portable' : 'audit-only',
            'global_settings' => 'audit-only',
            'social'          => 'portable-when-explicit',
            'schema'          => 'source-preserved',
            'redirects'       => 'source-preserved',
            'provenance'      => 'local-job-evidence',
            'parity'          => 'manual-before-cutover',
            'rollback'        => 'source-authority-preserved',
            'cutover'         => 'blocked-until-full-adapter-verified',
        );
    }
    /** @return array<int,string> */ public function sourceSignatures(): array { return $this->profile->evidence(); }
}
