<?php
namespace Yablonsky\PerformanceSEO\Visibility;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use RuntimeException;

final class CrawlerRegistry {
    /** @var string */ private $version;
    /** @var array<int,array<string,mixed>> */ private $crawlers;

    /** @param array<int,array<string,mixed>> $crawlers */
    private function __construct( string $version, array $crawlers ) {
        $this->version = $version;
        $this->crawlers = $crawlers;
    }

    public static function fromJsonFile( string $file ): self {
        if ( ! is_readable( $file ) ) {
            throw new RuntimeException( 'AI crawler registry file is not readable.' );
        }
        $decoded = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['registry_version'], $decoded['crawlers'] ) || ! is_array( $decoded['crawlers'] ) ) {
            throw new RuntimeException( 'Invalid AI crawler registry JSON.' );
        }
        $items = array();
        foreach ( $decoded['crawlers'] as $row ) {
            if ( ! is_array( $row ) || empty( $row['agent'] ) || empty( $row['purpose'] ) ) { continue; }
            $purpose = sanitize_key( (string) $row['purpose'] );
            if ( ! in_array( $purpose, array( 'search', 'answers', 'agent', 'retrieval', 'training', 'ads' ), true ) ) { continue; }
            $items[] = array(
                'agent' => sanitize_text_field( (string) $row['agent'] ),
                'operator' => sanitize_text_field( (string) ( $row['operator'] ?? '' ) ),
                'purpose' => $purpose,
                'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
                'evidence_level' => sanitize_key( (string) ( $row['evidence_level'] ?? 'opaque' ) ),
                'source' => esc_url_raw( (string) ( $row['source'] ?? '' ), array( 'http', 'https' ) ),
                'last_verified_at' => sanitize_text_field( (string) ( $row['last_verified_at'] ?? '' ) ),
                'mixed_use' => ! empty( $row['mixed_use'] ),
                'control_note' => sanitize_text_field( (string) ( $row['control_note'] ?? '' ) ),
                'accountable' => ! empty( $row['accountable'] ),
            );
        }
        return new self( sanitize_text_field( (string) $decoded['registry_version'] ), $items );
    }

    public function version(): string { return $this->version; }
    public function count(): int { return count( $this->crawlers ); }
    /** @return array<int,array<string,mixed>> */ public function all(): array { return $this->crawlers; }
    /** @return array<int,array<string,mixed>> */
    public function byPurpose( string $purpose ): array {
        $purpose = sanitize_key( $purpose );
        if ( 'retrieval' === $purpose ) { $purpose = 'agent'; }
        return array_values( array_filter( $this->crawlers, static function ( array $row ) use ( $purpose ): bool {
            $rowPurpose = sanitize_key( (string) ( $row['purpose'] ?? '' ) );
            if ( 'retrieval' === $rowPurpose ) { $rowPurpose = 'agent'; }
            return $purpose === $rowPurpose;
        } ) );
    }
}
