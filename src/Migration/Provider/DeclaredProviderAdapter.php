<?php
namespace Yablonsky\PerformanceSEO\Migration\Provider;

use Yablonsky\PerformanceSEO\Migration\Contract\AdapterCompatibility;
use Yablonsky\PerformanceSEO\Migration\Contract\MigrationProviderAdapterInterface;
use Yablonsky\PerformanceSEO\Runtime\Provider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class DeclaredProviderAdapter implements MigrationProviderAdapterInterface {
    /** @var string */ private $id;
    /** @var string */ private $label;
    /** @var array<string,string> */ private $capabilities;
    /** @var array<int,string> */ private $signatures;

    /**
     * @param array<string,string> $capabilities
     * @param array<int,string>    $signatures
     */
    public function __construct( string $id, string $label, array $capabilities, array $signatures ) {
        $this->id = $id;
        $this->label = $label;
        $this->capabilities = $capabilities;
        $this->signatures = $signatures;
    }

    public function id(): string { return $this->id; }
    public function label(): string { return $this->label; }

    public function compatibility( Provider $provider ): string {
        return $provider->id() === $this->id ? AdapterCompatibility::AUDIT_ONLY : AdapterCompatibility::UNSUPPORTED;
    }

    public function canExecuteShadowImport( Provider $provider ): bool {
        unset( $provider );
        return false;
    }

    /** @return array<string,string> */
    public function capabilityMatrix(): array { return $this->capabilities; }

    /** @return array<int,string> */
    public function sourceSignatures(): array { return $this->signatures; }
}
