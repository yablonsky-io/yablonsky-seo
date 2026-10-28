<?php
namespace Yablonsky\PerformanceSEO\Resource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Yablonsky\PerformanceSEO\Language\LanguageProviderInterface;

final class WordPressResourceResolver implements ResourceResolverInterface {
    /** @var LanguageProviderInterface */ private $languageProvider;

    public function __construct( LanguageProviderInterface $languageProvider ) {
        $this->languageProvider = $languageProvider;
    }

    public function resolve(): ?ResourceIdentity {
        $language = $this->languageProvider->currentLanguageKey();

        if ( is_front_page() ) {
            return new ResourceIdentity( 'home', (int) get_option( 'page_on_front', 0 ), 'front_page', $language, home_url( '/' ) );
        }

        if ( is_home() ) {
            $id = (int) get_option( 'page_for_posts', 0 );
            $url = $id > 0 ? get_permalink( $id ) : home_url( '/' );
            return new ResourceIdentity( 'posts_page', $id, 'posts_page', $language, (string) $url );
        }

        if ( is_singular() ) {
            $id = (int) get_queried_object_id();
            $postType = (string) get_post_type( $id );
            $type = $postType === 'product' ? 'product' : 'post';
            return new ResourceIdentity( $type, $id, $postType, $language, (string) get_permalink( $id ) );
        }

        if ( is_tax() || is_category() || is_tag() ) {
            $term = get_queried_object();
            if ( ! $term || empty( $term->term_id ) ) {
                return null;
            }
            $url = get_term_link( $term );
            if ( is_wp_error( $url ) ) {
                return null;
            }
            return new ResourceIdentity( 'term', (int) $term->term_id, (string) $term->taxonomy, $language, (string) $url );
        }

        if ( is_author() ) {
            $id = (int) get_queried_object_id();
            return new ResourceIdentity( 'author_archive', $id, 'author', $language, (string) get_author_posts_url( $id ) );
        }

        if ( is_post_type_archive() ) {
            $postType = get_query_var( 'post_type' );
            if ( is_array( $postType ) ) {
                $postType = reset( $postType ) ?: '';
            }
            $postType = (string) $postType;
            return new ResourceIdentity( 'post_type_archive', 0, $postType, $language, (string) get_post_type_archive_link( $postType ) );
        }

        if ( is_date() ) {
            $url = home_url( add_query_arg( array_filter( array(
                'year' => (int) get_query_var( 'year' ),
                'monthnum' => (int) get_query_var( 'monthnum' ),
                'day' => (int) get_query_var( 'day' ),
            ) ), '/' ) );
            return new ResourceIdentity( 'date_archive', 0, 'date', $language, $url );
        }

        return null;
    }
}
