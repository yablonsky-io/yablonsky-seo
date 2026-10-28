<?php
namespace Yablonsky\PerformanceSEO\AI\Contract;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ComputeResult {
    /** @var array<string,mixed> */ private $data;
    /** @var array<string,mixed> */ private $usage;

    /** @param array<string,mixed> $data @param array<string,mixed> $usage */
    public function __construct( array $data, array $usage = array() ) {
        $this->data = $data;
        $this->usage = $usage;
    }

    /** @return array<string,mixed> */ public function data(): array { return $this->data; }
    /** @return array<string,mixed> */ public function usage(): array { return $this->usage; }

    /**
     * Backwards-compatible transport metadata alias.
     *
     * ComputeResult historically exposed transport/model evidence through usage().
     * Admin diagnostics consume the same bounded metadata as meta() so a successful
     * canary cannot fail after compute merely because the accessor name drifted.
     *
     * @return array<string,mixed>
     */
    public function meta(): array { return $this->usage; }
}
