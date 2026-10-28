<?php
namespace Yablonsky\PerformanceSEO\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Provider {
    /** @var string */ private $id;
    /** @var string */ private $name;
    /** @var string */ private $version;

    public function __construct( string $id, string $name, string $version = '' ) {
        $this->id = $id;
        $this->name = $name;
        $this->version = $version;
    }

    public function id(): string { return $this->id; }
    public function name(): string { return $this->name; }
    public function version(): string { return $this->version; }

    /** @return array<string,string> */
    public function toArray(): array {
        return array(
            'id'      => $this->id,
            'name'    => $this->name,
            'version' => $this->version,
        );
    }
}
