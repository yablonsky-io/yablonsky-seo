<?php
namespace Yablonsky\PerformanceSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class JobCheckpoint {
    /** @var string */ private $jobId;
    /** @var int */ private $cursor;
    /** @var int */ private $batchSize;
    /** @var int */ private $attempt;
    /** @var string */ private $status;

    public function __construct( string $jobId, int $cursor = 0, int $batchSize = 100, int $attempt = 0, string $status = 'queued' ) {
        $this->jobId = $jobId;
        $this->cursor = max( 0, $cursor );
        $this->batchSize = max( 1, $batchSize );
        $this->attempt = max( 0, $attempt );
        $this->status = $status;
    }

    public function jobId(): string { return $this->jobId; }
    public function cursor(): int { return $this->cursor; }
    public function batchSize(): int { return $this->batchSize; }
    public function attempt(): int { return $this->attempt; }
    public function status(): string { return $this->status; }

    public function advance( int $processed ): self {
        return new self( $this->jobId, $this->cursor + max( 0, $processed ), $this->batchSize, $this->attempt, 'running' );
    }

    public function failed(): self {
        return new self( $this->jobId, $this->cursor, $this->batchSize, $this->attempt + 1, 'failed' );
    }
}
