<?php
namespace Yablonsky\PerformanceSEO\AI\Media;

use Yablonsky\PerformanceSEO\AI\AiClientResolver;
use Yablonsky\PerformanceSEO\AI\Config\AiSettings;
use Yablonsky\PerformanceSEO\AI\Contract\ImageGenerationRequest;
use Yablonsky\PerformanceSEO\AI\Contract\ImageGenerationResult;
use Yablonsky\PerformanceSEO\AI\Contract\ServiceError;
use Yablonsky\PerformanceSEO\AI\Contract\YablonskyAiClientInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generates a page-representative image through Yablonsky AI, then copies the
 * result into the local WordPress Media Library. Remote output is never hotlinked
 * as the durable SEO asset.
 */
final class SeoImageGenerationService {
    private const MAX_IMAGE_BYTES = 12582912; // 12 MiB.

    /** @var YablonskyAiClientInterface */ private $client;

    public function __construct( ?YablonskyAiClientInterface $client = null ) {
        $this->client = $client instanceof YablonskyAiClientInterface ? $client : ( new AiClientResolver() )->resolve();
    }

    public function isConfigured(): bool { return $this->client->isConfigured() && ! empty( $this->client->imageModels() ); }

    /** @return array<string,mixed> */
    public function generateForPost( int $postId, string $prompt = '', string $model = '', string $aspectRatio = '16:9' ): array {
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
            throw new ServiceError( esc_html__( 'The requested WordPress content is not available for SEO image generation.', 'yablonsky-seo' ) );
        }
        if ( ! $this->client->isConfigured() ) {
            throw new ServiceError( esc_html__( 'Connect and verify a Yablonsky AI User API key before generating an SEO image.', 'yablonsky-seo' ) );
        }
        $models = $this->client->imageModels();
        if ( empty( $models ) ) {
            throw new ServiceError( esc_html__( 'Image-generation model: verification required for the current workspace.', 'yablonsky-seo' ) );
        }
        $modelIds = array_map( static function ( $capability ): string { return $capability->id(); }, $models );
        $model = trim( $model );
        if ( '' === $model ) { $model = ( new AiSettings() )->imageModelId(); }
        if ( '' === $model || ! in_array( $model, $modelIds, true ) ) { $model = (string) $modelIds[0]; }

        $prompt = trim( $prompt );
        if ( '' === $prompt ) { $prompt = $this->defaultPrompt( $post ); }
        if ( function_exists( 'mb_substr' ) ) { $prompt = mb_substr( $prompt, 0, 4000, 'UTF-8' ); } else { $prompt = substr( $prompt, 0, 4000 ); }
        if ( ! in_array( $aspectRatio, array( '16:9', '4:3', '1:1' ), true ) ) { $aspectRatio = '16:9'; }

        $result = $this->client->generateImage( new ImageGenerationRequest( $model, $prompt, $aspectRatio ) );
        $attachmentId = $this->copyToMediaLibrary( $result, $post );
        $url = wp_get_attachment_url( $attachmentId );
        if ( ! is_string( $url ) || '' === $url ) {
            throw new ServiceError( esc_html__( 'The generated image was copied locally but WordPress could not resolve its public URL.', 'yablonsky-seo' ) );
        }
        $metadata = wp_get_attachment_metadata( $attachmentId );
        $width = is_array( $metadata ) && isset( $metadata['width'] ) ? (int) $metadata['width'] : $result->width();
        $height = is_array( $metadata ) && isset( $metadata['height'] ) ? (int) $metadata['height'] : $result->height();
        return array(
            'attachment_id' => $attachmentId,
            'url' => esc_url_raw( $url, array( 'http', 'https' ) ),
            'width' => max( 0, $width ),
            'height' => max( 0, $height ),
            'model' => sanitize_text_field( $result->model() ),
            'cost' => $result->cost(),
            'prompt' => $prompt,
        );
    }

    /** @return array<string,mixed> */
    public function generateForSite( string $purpose = 'social', string $prompt = '', string $model = '', string $aspectRatio = '16:9' ): array {
        if ( ! $this->client->isConfigured() ) {
            throw new ServiceError( esc_html__( 'Connect and verify a Yablonsky AI User API key before generating an SEO image.', 'yablonsky-seo' ) );
        }
        $models = $this->client->imageModels();
        if ( empty( $models ) ) {
            throw new ServiceError( esc_html__( 'Image-generation model: verification required for the current workspace.', 'yablonsky-seo' ) );
        }
        $modelIds = array_map( static function ( $capability ): string { return $capability->id(); }, $models );
        $model = trim( $model );
        if ( '' === $model ) { $model = ( new AiSettings() )->imageModelId(); }
        if ( '' === $model || ! in_array( $model, $modelIds, true ) ) { $model = (string) $modelIds[0]; }

        $prompt = trim( $prompt );
        $basePrompt = $this->defaultSitePrompt( $purpose );
        if ( '' !== $prompt ) { $prompt = $basePrompt . ' Editorial context: ' . $prompt; } else { $prompt = $basePrompt; }
        if ( function_exists( 'mb_substr' ) ) { $prompt = mb_substr( $prompt, 0, 4000, 'UTF-8' ); } else { $prompt = substr( $prompt, 0, 4000 ); }
        if ( ! in_array( $aspectRatio, array( '16:9', '4:3', '1:1' ), true ) ) { $aspectRatio = '16:9'; }

        $result = $this->client->generateImage( new ImageGenerationRequest( $model, $prompt, $aspectRatio ) );
        $attachmentId = $this->copySiteImageToMediaLibrary( $result, $purpose );
        $url = wp_get_attachment_url( $attachmentId );
        if ( ! is_string( $url ) || '' === $url ) {
            throw new ServiceError( esc_html__( 'The generated image was copied locally but WordPress could not resolve its public URL.', 'yablonsky-seo' ) );
        }
        $metadata = wp_get_attachment_metadata( $attachmentId );
        $width = is_array( $metadata ) && isset( $metadata['width'] ) ? (int) $metadata['width'] : $result->width();
        $height = is_array( $metadata ) && isset( $metadata['height'] ) ? (int) $metadata['height'] : $result->height();
        return array(
            'attachment_id' => $attachmentId,
            'url' => esc_url_raw( $url, array( 'http', 'https' ) ),
            'width' => max( 0, $width ),
            'height' => max( 0, $height ),
            'model' => sanitize_text_field( $result->model() ),
            'cost' => $result->cost(),
            'prompt' => $prompt,
        );
    }

    private function copySiteImageToMediaLibrary( ImageGenerationResult $result, string $purpose ): int {
        $sourceUrl = esc_url_raw( $result->url(), array( 'https' ) );
        if ( '' === $sourceUrl ) { throw new ServiceError( esc_html__( 'The generated image URL was not a safe HTTPS URL.', 'yablonsky-seo' ) ); }
        $tmp = wp_tempnam( 'yseo-ai-site-image' );
        if ( ! is_string( $tmp ) || '' === $tmp ) { throw new ServiceError( esc_html__( 'WordPress could not allocate temporary storage for the generated image.', 'yablonsky-seo' ) ); }
        $response = wp_safe_remote_get(
            $sourceUrl,
            array(
                'timeout' => 60,
                'redirection' => 2,
                'sslverify' => true,
                'stream' => true,
                'filename' => $tmp,
                'limit_response_size' => self::MAX_IMAGE_BYTES,
            )
        );
        if ( is_wp_error( $response ) ) { wp_delete_file( $tmp ); throw new ServiceError( esc_html__( 'WordPress could not copy the generated image from Yablonsky AI.', 'yablonsky-seo' ) ); }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $contentType = (string) wp_remote_retrieve_header( $response, 'content-type' );
        $size = file_exists( $tmp ) ? (int) filesize( $tmp ) : 0;
        if ( $code < 200 || $code >= 300 || $size <= 0 || $size > self::MAX_IMAGE_BYTES || 0 !== strpos( strtolower( $contentType ), 'image/' ) ) {
            wp_delete_file( $tmp );
            throw new ServiceError( esc_html__( 'The generated image download failed validation before entering the Media Library.', 'yablonsky-seo' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $extension = strtolower( pathinfo( (string) wp_parse_url( $sourceUrl, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        $mimeExtensions = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif' );
        $normalizedType = strtolower( trim( explode( ';', $contentType )[0] ) );
        if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif' ), true ) ) { $extension = (string) ( $mimeExtensions[ $normalizedType ] ?? 'webp' ); }
        $siteName = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ), true ) );
        $slug = sanitize_title( $siteName );
        if ( '' === $slug ) { $slug = 'site'; }
        $purposeKey = sanitize_key( $purpose );
        if ( 'owner_logo_url' === $purposeKey ) { $assetSuffix = 'logo-concept'; }
        elseif ( 'owner_image_url' === $purposeKey ) { $assetSuffix = 'identity-concept'; }
        else { $assetSuffix = 'social'; }
        $file = array( 'name' => $slug . '-' . $assetSuffix . '.' . $extension, 'tmp_name' => $tmp );
        $attachmentId = media_handle_sideload( $file, 0, $siteName );
        if ( is_wp_error( $attachmentId ) ) {
            wp_delete_file( $tmp );
            throw new ServiceError( esc_html__( 'WordPress rejected the generated image while adding it to the Media Library.', 'yablonsky-seo' ) );
        }
        $attachmentId = (int) $attachmentId;
        update_post_meta( $attachmentId, '_wp_attachment_image_alt', function_exists( 'mb_substr' ) ? mb_substr( $siteName, 0, 180, 'UTF-8' ) : substr( $siteName, 0, 180 ) );
        return $attachmentId;
    }

    private function defaultSitePrompt( string $purpose ): string {
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $siteName = trim( (string) ( $settings['site_display_name'] ?? '' ) );
        if ( '' === $siteName ) { $siteName = trim( (string) get_bloginfo( 'name' ) ); }
        $description = trim( (string) ( $settings['site_description'] ?? '' ) );
        if ( '' === $description ) { $description = trim( (string) ( $settings['home_description'] ?? '' ) ); }
        if ( '' === $description ) { $description = trim( (string) get_bloginfo( 'description' ) ); }
        $context = trim( $siteName . '. ' . $description );
        $purposeKey = sanitize_key( $purpose );
        if ( 'owner_logo_url' === $purposeKey ) {
            return 'Create an original brand-mark concept for administrator review. Do not imitate a known logo, trademark or existing company mark. Keep the composition simple, legible and suitable for a square identity asset. The output is a synthetic draft and must not be treated as verified identity evidence until a human intentionally saves it. Site context: ' . $context;
        }
        if ( 'owner_image_url' === $purposeKey ) {
            return 'Create a clearly synthetic editorial avatar or illustration concept for administrator review, not a photorealistic identity claim about a real person. Avoid public-figure likenesses, watermarks and embedded claims. The output must remain review-only until a human intentionally saves it. Site context: ' . $context;
        }
        if ( 'home_search_image' === $purposeKey ) {
            return 'Create a high-resolution representative editorial image for this website homepage that can serve as a preferred image signal for search and discovery surfaces. Make it relevant to the page topic, avoid generic logo-only art, watermarks, UI screenshots and large embedded text, and keep strong crop safety. The output is a review-only draft and search engines may choose another image. Site context: ' . $context;
        }
        return 'Create a high-quality editorial social preview image for this website. Avoid logos, watermarks, UI screenshots and large embedded text unless explicitly required by the site identity. Use a clean 16:9 composition with one clear focal subject and strong crop safety for Open Graph and X/Twitter cards. Purpose: ' . $purposeKey . '. Site context: ' . $context;
    }

    private function copyToMediaLibrary( ImageGenerationResult $result, \WP_Post $post ): int {
        $sourceUrl = esc_url_raw( $result->url(), array( 'https' ) );
        if ( '' === $sourceUrl ) { throw new ServiceError( esc_html__( 'The generated image URL was not a safe HTTPS URL.', 'yablonsky-seo' ) ); }
        $tmp = wp_tempnam( 'yseo-ai-image-' . $post->ID );
        if ( ! is_string( $tmp ) || '' === $tmp ) { throw new ServiceError( esc_html__( 'WordPress could not allocate temporary storage for the generated image.', 'yablonsky-seo' ) ); }
        $response = wp_safe_remote_get(
            $sourceUrl,
            array(
                'timeout' => 60,
                'redirection' => 2,
                'sslverify' => true,
                'stream' => true,
                'filename' => $tmp,
                'limit_response_size' => self::MAX_IMAGE_BYTES,
            )
        );
        if ( is_wp_error( $response ) ) { wp_delete_file( $tmp ); throw new ServiceError( esc_html__( 'WordPress could not copy the generated image from Yablonsky AI.', 'yablonsky-seo' ) ); }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $contentType = (string) wp_remote_retrieve_header( $response, 'content-type' );
        $size = file_exists( $tmp ) ? (int) filesize( $tmp ) : 0;
        if ( $code < 200 || $code >= 300 || $size <= 0 || $size > self::MAX_IMAGE_BYTES || 0 !== strpos( strtolower( $contentType ), 'image/' ) ) {
            wp_delete_file( $tmp );
            throw new ServiceError( esc_html__( 'The generated image download failed validation before entering the Media Library.', 'yablonsky-seo' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $extension = strtolower( pathinfo( (string) wp_parse_url( $sourceUrl, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        $mimeExtensions = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif' );
        $normalizedType = strtolower( trim( explode( ';', $contentType )[0] ) );
        if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif' ), true ) ) { $extension = (string) ( $mimeExtensions[ $normalizedType ] ?? 'webp' ); }
        $slug = sanitize_title( (string) $post->post_title );
        if ( '' === $slug ) { $slug = 'seo-image-' . $post->ID; }
        $file = array(
            'name' => $slug . '-seo.' . $extension,
            'tmp_name' => $tmp,
        );
        $attachmentId = media_handle_sideload( $file, $post->ID, trim( wp_strip_all_tags( (string) $post->post_title, true ) ) );
        if ( is_wp_error( $attachmentId ) ) {
            wp_delete_file( $tmp );
            throw new ServiceError( esc_html__( 'WordPress rejected the generated image while adding it to the Media Library.', 'yablonsky-seo' ) );
        }
        $attachmentId = (int) $attachmentId;
        update_post_meta( $attachmentId, '_wp_attachment_image_alt', $this->altText( $post ) );
        return $attachmentId;
    }

    private function defaultPrompt( \WP_Post $post ): string {
        $title = trim( wp_strip_all_tags( (string) $post->post_title, true ) );
        $excerpt = trim( wp_strip_all_tags( (string) $post->post_excerpt, true ) );
        if ( '' === $excerpt ) {
            $excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ), true ), 55, '…' );
        }
        return 'Create a high-quality editorial image that accurately represents this web page. Avoid logos, watermarks, UI screenshots and large embedded text unless the page itself requires them. The image should work as a search preview, social preview and article primary image. Prefer a clean 16:9 landscape composition with a clear focal subject. Page title: ' . $title . '. Page summary: ' . $excerpt;
    }

    private function altText( \WP_Post $post ): string {
        $title = trim( wp_strip_all_tags( (string) $post->post_title, true ) );
        $keyphrase = trim( (string) get_post_meta( $post->ID, '_yps_focus_keyphrase', true ) );
        $alt = '' !== $title ? $title : $keyphrase;
        if ( function_exists( 'mb_substr' ) ) { return mb_substr( $alt, 0, 180, 'UTF-8' ); }
        return substr( $alt, 0, 180 );
    }
}
