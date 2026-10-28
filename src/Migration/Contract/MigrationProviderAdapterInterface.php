<?php
namespace Yablonsky\PerformanceSEO\Migration\Contract;

use Yablonsky\PerformanceSEO\Runtime\Provider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface MigrationProviderAdapterInterface {
    public function id(): string;
    public function label(): string;
    public function compatibility( Provider $provider ): string;
    public function canExecuteShadowImport( Provider $provider ): bool;

    /** @return array<string,string> */
    public function capabilityMatrix(): array;

    /** @return array<int,string> */
    public function sourceSignatures(): array;
}
