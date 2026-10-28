<?php
namespace Yablonsky\PerformanceSEO\Language;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface LanguageProviderInterface {
    public function currentLanguageKey(): string;

    /**
     * @param string $resourceType
     * @param int    $objectId
     * @return array<int,array{language_key:string,url:string}>
     */
    public function alternates( string $resourceType, int $objectId ): array;
}
