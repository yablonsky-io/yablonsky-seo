<?php
namespace Yablonsky\PerformanceSEO\Resource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ResourceIdentity {
    /** @var string */ private $type;
    /** @var int */ private $objectId;
    /** @var string */ private $subtype;
    /** @var string */ private $languageKey;
    /** @var string */ private $url;

    public function __construct( string $type, int $objectId, string $subtype, string $languageKey, string $url ) {
        $this->type = $type;
        $this->objectId = $objectId;
        $this->subtype = $subtype;
        $this->languageKey = $languageKey;
        $this->url = $url;
    }

    public function type(): string { return $this->type; }
    public function objectId(): int { return $this->objectId; }
    public function subtype(): string { return $this->subtype; }
    public function languageKey(): string { return $this->languageKey; }
    public function url(): string { return $this->url; }

    public function key(): string {
        return implode( ':', array( $this->type, (string) $this->objectId, $this->subtype, $this->languageKey ) );
    }
}
