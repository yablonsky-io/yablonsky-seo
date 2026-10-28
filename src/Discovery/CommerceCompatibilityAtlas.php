<?php
namespace Yablonsky\PerformanceSEO\Discovery;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Curated compatibility registry for the single canonical commerce feed. */
final class CommerceCompatibilityAtlas {
    /** @var array<string,array{name:string,region:string}>|null Cached for one request/instance. */
    private $countryRecordsCache = null;

    /** @return array<string,array<string,mixed>> */
    public function platforms(): array {
        $path = YPS_DIR . 'data/commerce-platforms.php';
        $platforms = is_file( $path ) ? require $path : array();
        return is_array( $platforms ) ? $platforms : array();
    }

    /** @return array<string,string> */
    public function countries(): array {
        $records = $this->countryRecords();
        $countries = array();
        foreach ( $records as $code => $record ) { $countries[ $code ] = (string) ( $record['name'] ?? $code ); }
        asort( $countries );
        return $countries;
    }

    /** @return array<string,array{name:string,region:string}> */
    public function countryRecords(): array {
        if ( is_array( $this->countryRecordsCache ) ) { return $this->countryRecordsCache; }
        $path = YPS_DIR . 'data/commerce-countries.php';
        $records = is_file( $path ) ? require $path : array();
        $this->countryRecordsCache = is_array( $records ) ? $records : array();
        return $this->countryRecordsCache;
    }

    public function regionForCountry( string $country ): string {
        $records = $this->countryRecords();
        $country = strtoupper( sanitize_key( $country ) );
        return isset( $records[ $country ]['region'] ) ? sanitize_key( (string) $records[ $country ]['region'] ) : '';
    }

    /** @param array<string,mixed> $platform */
    public function supportsCountry( array $platform, string $country ): bool {
        $countries = isset( $platform['countries'] ) && is_array( $platform['countries'] ) ? $platform['countries'] : array();
        return in_array( '*', $countries, true ) || in_array( strtoupper( $country ), $countries, true );
    }

    /** @param array<string,mixed> $platform @return array<int,string> */
    public function regionsForPlatform( array $platform ): array {
        $countries = isset( $platform['countries'] ) && is_array( $platform['countries'] ) ? $platform['countries'] : array();
        if ( in_array( '*', $countries, true ) ) { return array( 'AMERICAS','EUROPE','AFRICA','ASIA','OCEANIA' ); }
        $regions = array();
        foreach ( $countries as $country ) {
            $region = strtoupper( $this->regionForCountry( (string) $country ) );
            if ( '' !== $region ) { $regions[ $region ] = true; }
        }
        return array_keys( $regions );
    }
}
