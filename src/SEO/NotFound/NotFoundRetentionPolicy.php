<?php
namespace Yablonsky\PerformanceSEO\SEO\NotFound;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded retention contract for privacy-light 404 aggregates.
 *
 * YSEO keeps SEO candidates long enough for editorial work while aggressively
 * expiring low-value scanner churn. Repeated non-SEO signals remain available
 * longer so a dedicated security product can correlate them.
 */
final class NotFoundRetentionPolicy {
    public const LOW_VALUE_MAX_HITS = 2;
    public const SEO_DAYS = 90;
    public const ACCESS_DAYS = 14;
    public const TECHNICAL_DAYS = 14;
    public const PROBE_DAYS = 7;
    public const ACCESS_LOW_VALUE_DAYS = 3;
    public const TECHNICAL_LOW_VALUE_DAYS = 2;
    public const PROBE_LOW_VALUE_DAYS = 1;
    public const NON_SEO_SOFT_CAP = 10000;
    public const NON_SEO_TARGET = 8000;
    public const PRESSURE_TRIM_MAX = 5000;
    public const NON_SEO_HARD_CAP = 20000;
    public const NON_SEO_HARD_TARGET = 12000;
    public const HARD_TRIM_MAX = 20000;

    /** @return array<string,array{days:int,low_value_days:int}> */
    public static function rules(): array {
        return array(
            NotFoundSignalClassifier::SEO => array( 'days' => self::SEO_DAYS, 'low_value_days' => self::SEO_DAYS ),
            NotFoundSignalClassifier::ACCESS => array( 'days' => self::ACCESS_DAYS, 'low_value_days' => self::ACCESS_LOW_VALUE_DAYS ),
            NotFoundSignalClassifier::TECHNICAL => array( 'days' => self::TECHNICAL_DAYS, 'low_value_days' => self::TECHNICAL_LOW_VALUE_DAYS ),
            NotFoundSignalClassifier::PROBE => array( 'days' => self::PROBE_DAYS, 'low_value_days' => self::PROBE_LOW_VALUE_DAYS ),
        );
    }

    /** @return array<string,int> */
    public static function publicSummary(): array {
        return array(
            'low_value_max_hits' => self::LOW_VALUE_MAX_HITS,
            'seo_days' => self::SEO_DAYS,
            'access_days' => self::ACCESS_DAYS,
            'technical_days' => self::TECHNICAL_DAYS,
            'probe_days' => self::PROBE_DAYS,
            'access_low_value_days' => self::ACCESS_LOW_VALUE_DAYS,
            'technical_low_value_days' => self::TECHNICAL_LOW_VALUE_DAYS,
            'probe_low_value_days' => self::PROBE_LOW_VALUE_DAYS,
            'nonseo_soft_cap' => self::NON_SEO_SOFT_CAP,
            'nonseo_target' => self::NON_SEO_TARGET,
            'nonseo_hard_cap' => self::NON_SEO_HARD_CAP,
            'nonseo_hard_target' => self::NON_SEO_HARD_TARGET,
        );
    }
}
