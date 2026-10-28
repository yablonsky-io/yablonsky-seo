<?php
namespace Yablonsky\PerformanceSEO\Migration\Portability;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Verified, source-read-only metadata contract for a competing SEO provider.
 *
 * Profiles deliberately describe only fields whose storage contract has been
 * established. Missing fields remain with the source provider and are never
 * guessed from labels, rendered HTML or undocumented defaults.
 */
final class PortableSourceProfile {
    /** @var string */ private $id;
    /** @var string */ private $label;
    /** @var string */ private $storage;
    /** @var array<string,string> */ private $postFields;
    /** @var array<string,string> */ private $termFields;
    /** @var array<int,string> */ private $evidence;

    /**
     * @param array<string,string> $postFields
     * @param array<string,string> $termFields
     * @param array<int,string>    $evidence
     */
    public function __construct( string $id, string $label, string $storage, array $postFields, array $termFields, array $evidence ) {
        $this->id = sanitize_key( $id );
        $this->label = $label;
        $this->storage = $storage;
        $this->postFields = $postFields;
        $this->termFields = $termFields;
        $this->evidence = $evidence;
    }

    public function id(): string { return $this->id; }
    public function label(): string { return $this->label; }
    public function storage(): string { return $this->storage; }
    /** @return array<string,string> */ public function postFields(): array { return $this->postFields; }
    /** @return array<string,string> */ public function termFields(): array { return $this->termFields; }
    /** @return array<int,string> */ public function evidence(): array { return $this->evidence; }
    public function supportsPosts(): bool { return ! empty( $this->postFields ) || in_array( $this->storage, array( 'slim-array', 'aioseo-table' ), true ); }
    public function supportsTerms(): bool { return ! empty( $this->termFields ) || 'slim-array' === $this->storage; }
}
