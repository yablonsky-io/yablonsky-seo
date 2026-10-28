<?php
namespace Yablonsky\PerformanceSEO\SEO\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Metadata {
    /** @var string */ private $title;
    /** @var string */ private $description;

    public function __construct( string $title, string $description ) {
        $this->title = trim( $title );
        $this->description = trim( $description );
    }

    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
}
