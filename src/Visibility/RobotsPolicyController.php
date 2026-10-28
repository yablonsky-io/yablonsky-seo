<?php
namespace Yablonsky\PerformanceSEO\Visibility;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RobotsPolicyController {
    /** @var CrawlerRegistry */ private $registry;

    public function __construct( CrawlerRegistry $registry ) { $this->registry = $registry; }

    public function register(): void {
        add_filter( 'robots_txt', array( $this, 'filterRobotsTxt' ), 40, 2 );
    }

    public function filterRobotsTxt( string $output, bool $public ): string {
        unset( $public );
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        if ( empty( $settings['ai_crawler_policy_enabled'] ) ) { return $output; }

        $policy = CrawlerPermissionPolicy::fromSettings( $settings );
        $blocks = array();
        foreach ( $this->registry->all() as $crawler ) {
            $purpose = (string) $crawler['purpose'];
            $decision = $policy->value( $purpose );
            if ( 'inherit' === $decision ) { continue; }
            $agent = (string) $crawler['agent'];
            if ( '' === $agent || $this->hasAgentBlock( $output, $agent ) ) { continue; }
            $blocks[] = "User-agent: {$agent}\n" . ( 'allow' === $decision ? "Allow: /\n" : "Disallow: /\n" );
        }
        if ( empty( $blocks ) ) { return $output; }
        $suffix = "\n# Yablonsky SEO AI crawler policy · registry " . $this->registry->version() . "\n" . implode( "\n", $blocks );
        return rtrim( $output ) . "\n" . $suffix;
    }


    private function hasAgentBlock( string $robots, string $agent ): bool {
        return 1 === preg_match( '/^\s*User-agent\s*:\s*' . preg_quote( $agent, '/' ) . '\s*$/mi', $robots );
    }
}
