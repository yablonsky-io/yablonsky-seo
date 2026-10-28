<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Deterministic public child-sitemap routes shared by delivery and admin UI. */
final class SitemapRouteMap {
    /**
     * @param array<int,array<string,mixed>> $manifest
     * @return array{by_resource:array<string,string>,by_slug:array<string,array{type:string,subtype:string}>}
     */
    public function build( array $manifest ): array {
        $resources = array();
        foreach ( $manifest as $row ) {
            $type = sanitize_key( (string) ( $row['resource_type'] ?? '' ) );
            $subtype = sanitize_key( (string) ( $row['resource_subtype'] ?? '' ) );
            $key = $this->resourceKey( $type, $subtype );
            if ( '' === $type || '' === $subtype || isset( $resources[ $key ] ) ) { continue; }
            $base = 'author' === $type ? 'author' : $subtype;
            $resources[ $key ] = array( 'type' => $type, 'subtype' => $subtype, 'base' => $base );
        }

        $baseCounts = array();
        foreach ( $resources as $resource ) {
            $base = (string) $resource['base'];
            $baseCounts[ $base ] = isset( $baseCounts[ $base ] ) ? $baseCounts[ $base ] + 1 : 1;
        }

        $byResource = array();
        $bySlug = array();
        foreach ( $resources as $key => $resource ) {
            $base = (string) $resource['base'];
            $type = (string) $resource['type'];
            $subtype = (string) $resource['subtype'];
            $slug = 1 === (int) $baseCounts[ $base ] ? $base : ( 'post' === $type ? 'post-' . $base : ( 'term' === $type ? 'term-' . $base : 'author-archive' ) );
            if ( isset( $bySlug[ $slug ] ) ) { $slug .= '-' . substr( hash( 'sha256', $key ), 0, 8 ); }
            $byResource[ $key ] = $slug;
            $bySlug[ $slug ] = array( 'type' => $type, 'subtype' => $subtype );
        }
        return array( 'by_resource' => $byResource, 'by_slug' => $bySlug );
    }

    public function resourceKey( string $type, string $subtype ): string {
        return sanitize_key( $type ) . ':' . sanitize_key( $subtype );
    }
}
