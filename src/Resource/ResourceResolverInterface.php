<?php
namespace Yablonsky\PerformanceSEO\Resource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface ResourceResolverInterface {
    public function resolve(): ?ResourceIdentity;
}
