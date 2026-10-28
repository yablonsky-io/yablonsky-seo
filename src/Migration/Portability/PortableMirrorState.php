<?php
namespace Yablonsky\PerformanceSEO\Migration\Portability;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PortableMirrorState {
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_BLOCKED = 'blocked';

    /** @var array<string,mixed> */ private $data;
    /** @param array<string,mixed> $data */
    public function __construct( array $data = array() ) {
        $this->data = array_merge(
            array(
                'status'                => self::STATUS_RUNNING,
                'provider_id'           => '',
                'provider_label'        => '',
                'phase'                 => 'posts',
                'post_cursor'           => 0,
                'term_cursor'           => 0,
                'processed_posts'       => 0,
                'processed_terms'       => 0,
                'source_values'         => 0,
                'copied_values'         => 0,
                'matched_values'        => 0,
                'preserved_values'      => 0,
                'invalid_values'        => 0,
                'batch_size'            => 40,
                'recommended_delay_ms'  => 120,
                'started_at'            => gmdate( 'c' ),
                'updated_at'            => gmdate( 'c' ),
                'message'               => '',
            ),
            $data
        );
    }
    /** @return mixed */ public function get( string $key ) { return $this->data[ $key ] ?? null; }
    /** @return array<string,mixed> */ public function toArray(): array { return $this->data; }
    /** @param array<string,mixed> $changes */
    public function merge( array $changes ): self {
        $next = array_merge( $this->data, $changes );
        $next['updated_at'] = gmdate( 'c' );
        return new self( $next );
    }
}
