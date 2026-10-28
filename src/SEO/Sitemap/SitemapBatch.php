<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use InvalidArgumentException;

final class SitemapBatch {
    /** @var int */ private $maxEntries;
    /** @var array<int,SitemapEntry> */ private $entries = array();

    public function __construct( int $maxEntries = 1000 ) {
        if ( $maxEntries < 1 || $maxEntries > 50000 ) {
            throw new InvalidArgumentException( 'Sitemap batch size must be between 1 and 50000.' );
        }
        $this->maxEntries = $maxEntries;
    }

    public function add( SitemapEntry $entry ): bool {
        if ( count( $this->entries ) >= $this->maxEntries ) {
            return false;
        }
        $this->entries[] = $entry;
        return true;
    }

    public function count(): int { return count( $this->entries ); }
    /** @return array<int,SitemapEntry> */ public function entries(): array { return $this->entries; }
}
