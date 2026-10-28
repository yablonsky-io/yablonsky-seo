<?php
namespace Yablonsky\PerformanceSEO\Migration\Contract;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AdapterCompatibility {
    public const VERIFIED = 'verified';
    public const SIGNATURE_COMPATIBLE = 'signature-compatible';
    public const AUDIT_ONLY = 'audit-only';
    public const UNKNOWN_VERSION = 'unknown-version';
    public const UNSUPPORTED = 'unsupported';
}
