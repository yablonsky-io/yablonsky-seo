<?php
namespace Yablonsky\PerformanceSEO\SEO\Developer;

use Yablonsky\PerformanceSEO\Language\WordPressLanguageProvider;
use Yablonsky\PerformanceSEO\Resource\WordPressResourceResolver;
use Yablonsky\PerformanceSEO\SEO\PublicSeoController;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only PHP Surfaces API for themes/plugins/headless adapters. */
final class MetaSurfaceRepository {
    /** @var ResourceSurfaceResolver */ private $resolver;
    /** @var PublicSeoController */ private $seo;
    public function __construct() { $this->resolver = new ResourceSurfaceResolver(); $this->seo = new PublicSeoController(); }

    public function forCurrentPage(): ?MetaSurface {
        $resource = ( new WordPressResourceResolver( new WordPressLanguageProvider() ) )->resolve();
        return null === $resource ? null : new MetaSurface( $this->seo->projectResource( $resource ) );
    }
    public function forPost( int $postId ): ?MetaSurface { return $this->wrap( $this->resolver->forPost( $postId ) ); }
    public function forSingular( int $postId ): ?MetaSurface { return $this->forPost( $postId ); }
    public function forUrl( string $url ): ?MetaSurface { return $this->wrap( $this->resolver->forUrl( $url ) ); }
    public function forTerm( string $taxonomy, int $termId ): ?MetaSurface { return $this->wrap( $this->resolver->forTerm( $taxonomy, $termId ) ); }
    public function forAuthor( int $userId ): ?MetaSurface { return $this->wrap( $this->resolver->forAuthor( $userId ) ); }
    public function forPostTypeArchive( string $postType ): ?MetaSurface { return $this->wrap( $this->resolver->forPostTypeArchive( $postType ) ); }
    public function forHome(): MetaSurface { return new MetaSurface( $this->seo->projectResource( $this->resolver->forHome() ) ); }
    public function forPostsPage(): MetaSurface { return new MetaSurface( $this->seo->projectResource( $this->resolver->forPostsPage() ) ); }

    // Familiar snake_case aliases for copy/paste-friendly WordPress integrations.
    public function for_current_page(): ?MetaSurface { return $this->forCurrentPage(); }
    public function for_post( int $postId ): ?MetaSurface { return $this->forPost( $postId ); }
    public function for_singular( int $postId ): ?MetaSurface { return $this->forSingular( $postId ); }
    public function for_url( string $url ): ?MetaSurface { return $this->forUrl( $url ); }
    public function for_term( string $taxonomy, int $termId ): ?MetaSurface { return $this->forTerm( $taxonomy, $termId ); }
    public function for_author( int $userId ): ?MetaSurface { return $this->forAuthor( $userId ); }
    public function for_post_type_archive( string $postType ): ?MetaSurface { return $this->forPostTypeArchive( $postType ); }
    public function for_home(): MetaSurface { return $this->forHome(); }
    public function for_posts_page(): MetaSurface { return $this->forPostsPage(); }

    private function wrap( $resource ): ?MetaSurface {
        return null === $resource ? null : new MetaSurface( $this->seo->projectResource( $resource ) );
    }
}
