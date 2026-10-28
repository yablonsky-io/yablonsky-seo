<?php
namespace Yablonsky\PerformanceSEO\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OutputAuthorityGate {
    /** @var array<int,Provider> */ private $providers;

    /** @param array<int,Provider> $providers */
    public function __construct( array $providers ) {
        $this->providers = $providers;
    }

    public function mode(): string {
        if ( ! empty( $this->providers ) ) {
            return 'shadow';
        }

        $mode = (string) get_option( 'yps_output_authority', 'active' );
        return 'active' === $mode ? 'active' : 'shadow';
    }

    public function canEmitPublicSeo(): bool {
        if ( ! empty( $this->providers ) ) {
            return false;
        }

        return 'active' === $this->mode();
    }

    /** @return array<string,mixed> */
    public function status(): array {
        return array(
            'mode'             => $this->mode(),
            'public_output'    => $this->canEmitPublicSeo(),
            'provider_count'   => count( $this->providers ),
            'provider_details' => array_map(
                static function ( Provider $provider ): array {
                    return $provider->toArray();
                },
                $this->providers
            ),
        );
    }
}
