<?php
namespace Yablonsky\PerformanceSEO\SEO\Special;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SpecialPagesController {
    // YSEO_224_ARCHIVE_APPEARANCE=PASS — explicit date/post-format title and indexability controls.
    /** @var array<string,mixed> */
    private $settings;

    public function __construct() {
        $settings = get_option( 'yps_public_settings', array() );
        $this->settings = is_array( $settings ) ? $settings : array();
    }

    public function register(): void {
        add_filter( 'pre_get_document_title', array( $this, 'filterTitle' ), 15 );
        add_filter( 'wp_robots', array( $this, 'filterRobots' ), 15 );
    }

    public function filterTitle( string $title ): string {
        if ( is_search() ) {
            $template = trim( (string) ( $this->settings['search_title_template'] ?? '' ) );
            return '' !== $template ? $this->resolve( $template, get_search_query( false ), '' ) : $title;
        }
        if ( is_404() ) {
            $template = trim( (string) ( $this->settings['error_404_title_template'] ?? '' ) );
            return '' !== $template ? $this->resolve( $template, '', '' ) : $title;
        }
        if ( is_date() ) {
            $template = trim( (string) ( $this->settings['date_archive_title_template'] ?? '' ) );
            return '' !== $template ? $this->resolve( $template, '', wp_strip_all_tags( (string) get_the_archive_title(), true ) ) : $title;
        }
        if ( is_tax( 'post_format' ) ) {
            $template = trim( (string) ( $this->settings['post_format_archive_title_template'] ?? '' ) );
            return '' !== $template ? $this->resolve( $template, '', wp_strip_all_tags( (string) single_term_title( '', false ), true ) ) : $title;
        }
        return $title;
    }

    /** @param array<string,bool|string> $robots @return array<string,bool|string> */
    public function filterRobots( array $robots ): array {
        $archiveNoindex = ( is_date() && ! empty( $this->settings['date_archives_noindex'] ) ) || ( is_tax( 'post_format' ) && ! empty( $this->settings['post_format_archives_noindex'] ) );
        if ( is_404() || $archiveNoindex || ( is_search() && ! empty( $this->settings['crawl_search_noindex'] ) ) ) {
            $robots['noindex'] = true;
            unset( $robots['index'] );
        }
        return $robots;
    }

    private function resolve( string $template, string $searchPhrase, string $archiveLabel ): string {
        $paged = max( 1, (int) get_query_var( 'paged', 1 ) );
        $pageLabel = '';
        if ( $paged > 1 ) {
            /* translators: %d: current archive/search pagination page number. */
            $pageLabel = sprintf( __( 'Page %d', 'yablonsky-seo' ), $paged );
        }
        $values = array(
            '%%searchphrase%%' => trim( wp_strip_all_tags( $searchPhrase, true ) ),
            '%%archive%%' => trim( wp_strip_all_tags( $archiveLabel, true ) ),
            '%%sitename%%' => trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ), true ) ),
            '%%sitedesc%%' => trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ), true ) ),
            '%%sep%%' => '-',
            '%%page%%' => $pageLabel,
        );
        $resolved = strtr( wp_strip_all_tags( $template, true ), $values );
        $resolved = (string) preg_replace( '/%%[A-Za-z0-9_\-]+%%/', '', $resolved );
        $resolved = (string) preg_replace( '/\s+/u', ' ', $resolved );
        return trim( $resolved );
    }
}
