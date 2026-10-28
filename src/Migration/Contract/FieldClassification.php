<?php
namespace Yablonsky\PerformanceSEO\Migration\Contract;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FieldClassification {
    public const MAPPED = 'MAPPED';
    public const DEFAULT_EQUIVALENT = 'DEFAULT_EQUIVALENT';
    public const REBUILT = 'REBUILT';
    public const IGNORED_DERIVED = 'IGNORED_DERIVED';
    public const UNSUPPORTED = 'UNSUPPORTED';
    public const CONFLICT = 'CONFLICT';
    public const INVALID_SOURCE = 'INVALID_SOURCE';

    /** @return array<int,string> */
    public static function all(): array {
        return array(
            self::MAPPED,
            self::DEFAULT_EQUIVALENT,
            self::REBUILT,
            self::IGNORED_DERIVED,
            self::UNSUPPORTED,
            self::CONFLICT,
            self::INVALID_SOURCE,
        );
    }

    /** @return array<string,int> */
    public static function zeroCounts(): array {
        return array_fill_keys( self::all(), 0 );
    }
}
