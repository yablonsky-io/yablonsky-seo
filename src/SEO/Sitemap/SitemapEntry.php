<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SitemapEntry {
    /** @var string */ private $url;
    /** @var string */ private $lastModified;

    public function __construct( string $url, string $lastModified = '' ) {
        $this->url = $url;
        $this->lastModified = $lastModified;
    }

    public function url(): string { return $this->url; }
    public function lastModified(): string { return $this->lastModified; }
}
