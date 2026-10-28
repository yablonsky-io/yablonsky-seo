<?php
namespace Yablonsky\PerformanceSEO\Resource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Yablonsky\PerformanceSEO\Language\LanguageProviderInterface;

final class RequestContextFactory {
    /** @var ResourceResolverInterface */ private $resolver;
    /** @var LanguageProviderInterface */ private $languageProvider;
    /** @var SEORequestContext|null */ private $current;
    /** @var bool */ private $resolved = false;

    public function __construct( ResourceResolverInterface $resolver, LanguageProviderInterface $languageProvider ) {
        $this->resolver = $resolver;
        $this->languageProvider = $languageProvider;
    }

    public function current(): ?SEORequestContext {
        if ( $this->resolved ) {
            return $this->current;
        }
        $this->resolved = true;
        $resource = $this->resolver->resolve();
        if ( $resource === null ) {
            return null;
        }

        $paged = max( 1, (int) get_query_var( 'paged', 1 ) );
        $page = max( 1, (int) get_query_var( 'page', 1 ) );
        $indexable = ! is_404() && ! is_search();
        $alternates = $this->languageProvider->alternates( $resource->type(), $resource->objectId() );

        $this->current = new SEORequestContext(
            $resource->type(),
            $resource,
            $resource,
            $alternates,
            $indexable,
            array( 'paged' => $paged, 'page' => $page ),
            'yps:' . $resource->key(),
            'yps:' . $resource->key()
        );
        return $this->current;
    }
}
