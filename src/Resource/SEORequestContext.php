<?php
namespace Yablonsky\PerformanceSEO\Resource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SEORequestContext {
    /** @var string */ private $requestType;
    /** @var ResourceIdentity */ private $resource;
    /** @var ResourceIdentity */ private $canonicalResource;
    /** @var array<int,array{language_key:string,url:string}> */ private $alternates;
    /** @var bool */ private $indexable;
    /** @var array<string,int|string|bool|null> */ private $requestState;
    /** @var string */ private $schemaIdentity;
    /** @var string */ private $socialIdentity;

    public function __construct(
        string $requestType,
        ResourceIdentity $resource,
        ResourceIdentity $canonicalResource,
        array $alternates,
        bool $indexable,
        array $requestState,
        string $schemaIdentity,
        string $socialIdentity
    ) {
        $this->requestType = $requestType;
        $this->resource = $resource;
        $this->canonicalResource = $canonicalResource;
        $this->alternates = $alternates;
        $this->indexable = $indexable;
        $this->requestState = $requestState;
        $this->schemaIdentity = $schemaIdentity;
        $this->socialIdentity = $socialIdentity;
    }

    public function requestType(): string { return $this->requestType; }
    public function resource(): ResourceIdentity { return $this->resource; }
    public function canonicalResource(): ResourceIdentity { return $this->canonicalResource; }
    public function alternates(): array { return $this->alternates; }
    public function indexable(): bool { return $this->indexable; }
    public function requestState(): array { return $this->requestState; }
    public function schemaIdentity(): string { return $this->schemaIdentity; }
    public function socialIdentity(): string { return $this->socialIdentity; }
}
