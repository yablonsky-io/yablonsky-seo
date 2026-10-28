<?php
namespace Yablonsky\PerformanceSEO\SEO\Image;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Resolve a public image URL to bounded, machine-readable metadata when WordPress owns the attachment. */
final class ImageMetadataResolver {
    /** @return array<string,mixed> */
    public function resolve( string $url ): array {
        $url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
        if ( '' === $url ) { return array(); }

        $out = array( 'url' => $url );
        $attachmentId = (int) attachment_url_to_postid( $url );
        if ( $attachmentId <= 0 ) { return $out; }

        $out['id'] = $attachmentId;
        $metadata = wp_get_attachment_metadata( $attachmentId );
        if ( is_array( $metadata ) ) {
            $width = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
            $height = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;
            if ( $width > 0 ) { $out['width'] = $width; }
            if ( $height > 0 ) { $out['height'] = $height; }
        }
        $alt = trim( wp_strip_all_tags( (string) get_post_meta( $attachmentId, '_wp_attachment_image_alt', true ), true ) );
        if ( '' !== $alt ) { $out['alt'] = $alt; }
        $mime = sanitize_mime_type( (string) get_post_mime_type( $attachmentId ) );
        if ( '' !== $mime ) { $out['mime_type'] = $mime; }
        return $out;
    }

    /** @return array<string,mixed> */
    public function schemaObject( string $url ): array {
        $image = $this->resolve( $url );
        if ( empty( $image['url'] ) ) { return array(); }
        $object = array( '@type' => 'ImageObject', 'url' => (string) $image['url'], 'contentUrl' => (string) $image['url'] );
        foreach ( array( 'width', 'height' ) as $key ) {
            if ( ! empty( $image[ $key ] ) ) { $object[ $key ] = (int) $image[ $key ]; }
        }
        if ( ! empty( $image['alt'] ) ) { $object['caption'] = (string) $image['alt']; }
        return $object;
    }
}
