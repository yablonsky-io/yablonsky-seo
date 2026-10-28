<?php
namespace Yablonsky\PerformanceSEO\SEO\Canonical;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CanonicalRobots {
    /** @var string */ private $canonicalUrl;
    /** @var bool */ private $index;
    /** @var bool */ private $follow;

    public function __construct( string $canonicalUrl, bool $index = true, bool $follow = true ) {
        $this->canonicalUrl = $canonicalUrl;
        $this->index = $index;
        $this->follow = $follow;
    }

    public function canonicalUrl(): string { return $this->canonicalUrl; }
    public function robots(): string { return ( $this->index ? 'index' : 'noindex' ) . ',' . ( $this->follow ? 'follow' : 'nofollow' ); }
}
