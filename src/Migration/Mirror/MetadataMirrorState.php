<?php
namespace Yablonsky\PerformanceSEO\Migration\Mirror;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MetadataMirrorState {
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_BLOCKED = 'blocked';
    public const SCOPE_SEARCH = 'search';
    public const SCOPE_ALL = 'all';

    /** @var array<string,mixed> */
    private $data;

    /** @param array<string,mixed> $data */
    public function __construct( array $data = array() ) {
        $defaults = array(
            'status'              => self::STATUS_RUNNING,
            'scope'               => self::SCOPE_SEARCH,
            'phase'               => 'posts',
            'post_cursor'         => 0,
            'user_meta_cursor'    => 0,
            'taxonomy_cursor'     => '',
            'taxonomy_term_cursor'=> 0,
            'processed_posts'     => 0,
            'processed_users'     => 0,
            'processed_terms'     => 0,
            'source_values'       => 0,
            'copied_values'       => 0,
            'matched_values'      => 0,
            'preserved_values'    => 0,
            'invalid_values'      => 0,
            'batch_size'          => 50,
            'recommended_delay_ms'=> 100,
            'started_at'          => gmdate( 'c' ),
            'updated_at'          => gmdate( 'c' ),
            'message'             => '',
        );
        $this->data = array_merge( $defaults, $data );
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->data; }

    /** @return mixed */
    public function get( string $key ) { return $this->data[ $key ] ?? null; }

    /** @param array<string,mixed> $changes */
    public function merge( array $changes ): self {
        $next = array_merge( $this->data, $changes );
        $next['updated_at'] = gmdate( 'c' );
        return new self( $next );
    }
}
