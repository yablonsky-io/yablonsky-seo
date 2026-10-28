<?php
namespace Yablonsky\PerformanceSEO\Migration\Provider;

use Yablonsky\PerformanceSEO\Migration\Contract\MigrationProviderAdapterInterface;
use Yablonsky\PerformanceSEO\Migration\Portability\PortableProviderAdapter;
use Yablonsky\PerformanceSEO\Migration\Portability\PortableSourceRegistry;
use Yablonsky\PerformanceSEO\Runtime\Provider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ProviderAdapterRegistry {
    /** @var array<string,MigrationProviderAdapterInterface> */ private $adapters = array();

    public function __construct() {

        $this->register( new YoastProviderAdapter() );
        $portable = new PortableSourceRegistry();
        foreach ( $portable->all() as $profile ) { $this->register( new PortableProviderAdapter( $profile ) ); }
    }

    public function register( MigrationProviderAdapterInterface $adapter ): void { $this->adapters[ $adapter->id() ] = $adapter; }
    /** @return array<string,MigrationProviderAdapterInterface> */ public function all(): array { return $this->adapters; }
    public function get( string $id ): ?MigrationProviderAdapterInterface { return $this->adapters[ $id ] ?? null; }
    public function forProvider( Provider $provider ): ?MigrationProviderAdapterInterface { return $this->get( $provider->id() ); }
}
