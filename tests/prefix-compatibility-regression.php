<?php
/**
 * Isolated legacy-to-canonical YSEO metadata compatibility regression.
 * Executed only in GitHub Actions on a disposable WordPress installation.
 */
if ( ! defined( 'ABSPATH' ) ) { exit( 2 ); }
$checks = 0;
$assert = static function ( $ok, string $message ) use ( &$checks ): void {
    ++$checks;
    if ( ! $ok ) {
        fwrite( STDERR, "FAIL_META #{$checks}: {$message}\n" );
        exit( 2 );
    }
    echo "PASS_META #{$checks}: {$message}\n";
};

$assert( defined( 'YSEO_VERSION' ) && '5.87.2' === YSEO_VERSION, 'Corrective plugin version booted' );
foreach ( array( 'YPS_VERSION', 'YPS_FILE', 'YPS_DIR', 'YPS_URL' ) as $shortGlobal ) {
    $assert( ! defined( $shortGlobal ), 'No three-letter global alias: ' . $shortGlobal );
}
$assert( class_exists( '\Yablonsky\PerformanceSEO\Core\LegacyMetaBridge' ), 'Legacy metadata fallback registered' );
$postId = (int) get_option( 'yseo_ci_seed_post_id' );
$assert( $postId > 0 && get_post_status( $postId ) === 'publish', 'Historical source post still published' );

$testKeys = array(
    '_yps_seo_title' => array( '_yablseo_seo_title', 'Preserved SEO Title 5.86.6' ),
    '_yps_meta_description' => array( '_yablseo_meta_description', 'Legacy summary retained' ),
    '_yps_canonical' => array( '_yablseo_canonical', 'https://example.org/legacy-canonical/' ),
    '_yps_robots_noindex' => array( '_yablseo_robots_noindex', '1' ),
    '_yps_og_title' => array( '_yablseo_og_title', 'Legacy social title' ),
    '_yps_schema_entity_name' => array( '_yablseo_schema_entity_name', 'Legacy named entity' ),
    '_yps_video_title' => array( '_yablseo_video_title', 'Legacy video title' ),
    '_yps_focus_keyphrase' => array( '_yablseo_focus_keyphrase', 'legacy search phrase' ),
);
foreach ( $testKeys as $oldKey => $row ) {
    $newKey = $row[0];
    $expected = $row[1];
    // metadata_exists() calls get_post_metadata filters too; the legacy fallback
    // intentionally makes the canonical name logically exist without a row copy.
    $stored = get_post_meta( $postId );
    $assert( ! array_key_exists( $newKey, $stored ), "No canonical row copied on activation: {$newKey}" );
    $assert( get_post_meta( $postId, $newKey, true ) === $expected, "Read legacy SEO meta through canonical {$newKey}" );
    $assert( get_post_meta( $postId, $oldKey, true ) === $expected, "Legacy SEO record preserved {$oldKey}" );
}

// A user edit uses only the canonical name. It must not mutate historical data.
$assert( false !== update_post_meta( $postId, '_yablseo_seo_title', 'Edited SEO title' ), 'Canonical SEO edit accepted' );
$assert( get_post_meta( $postId, '_yablseo_seo_title', true ) === 'Edited SEO title', 'Edited value supersedes legacy fallback' );
$assert( get_post_meta( $postId, '_yps_seo_title', true ) === 'Preserved SEO Title 5.86.6', 'Old post meta remains untouched' );

// An explicit clear must not make the old SEO title appear again.
$assert( delete_post_meta( $postId, '_yablseo_seo_title' ), 'Explicit canonical field deletion succeeds' );
$assert( get_post_meta( $postId, '_yablseo_seo_title', true ) === '', 'Explicit clear suppresses obsolete SEO title' );
$assert( metadata_exists( 'post', $postId, '_yablseo_seo_title' ), 'Canonical empty marker prevents old fallback resurrection' );
$assert( get_post_meta( $postId, '_yps_seo_title', true ) === 'Preserved SEO Title 5.86.6', 'Clear does not erase historic SEO title' );

$user = get_user_by( 'login', 'admin' );
$assert( $user instanceof WP_User, 'Historical test administrator is available' );
$uid = (int) $user->ID;
$assert( get_user_meta( $uid, '_yablseo_author_meta_description', true ) === 'Legacy author biography', 'User SEO meta fallback' );
$assert( get_user_meta( $uid, '_yps_author_meta_description', true ) === 'Legacy author biography', 'Historic user meta retained' );
$assert( false !== update_user_meta( $uid, '_yablseo_author_meta_description', 'Updated author biography' ), 'Canonical author metadata write' );
$assert( get_user_meta( $uid, '_yablseo_author_meta_description', true ) === 'Updated author biography', 'Canonical author meta precedes legacy' );
$assert( delete_user_meta( $uid, '_yablseo_author_meta_description' ), 'Explicit author clear succeeds' );
$assert( get_user_meta( $uid, '_yablseo_author_meta_description', true ) === '', 'Author legacy does not reappear after clear' );

$termId = (int) get_option( 'yseo_ci_seed_term_id' );
$assert( $termId > 0 && get_term( $termId ) instanceof WP_Term, 'Historical taxonomy term retained' );
$assert( get_term_meta( $termId, '_yablseo_seo_title', true ) === 'Legacy category title', 'Taxonomy SEO meta fallback' );
$assert( false !== update_term_meta( $termId, '_yablseo_seo_title', 'New category title' ), 'Canonical taxonomy metadata write' );
$assert( get_term_meta( $termId, '_yablseo_seo_title', true ) === 'New category title', 'Canonical taxonomy SEO title visible' );
$assert( delete_term_meta( $termId, '_yablseo_seo_title' ), 'Explicit taxonomy clear succeeds' );
$assert( get_term_meta( $termId, '_yablseo_seo_title', true ) === '', 'Old taxonomy value does not reappear' );
$assert( get_term_meta( $termId, '_yps_seo_title', true ) === 'Legacy category title', 'Historic taxonomy meta retained' );

// A completely unrelated meta key must continue to use native WordPress handling.
$assert( false !== update_post_meta( $postId, 'third_party_metadata', 'unrelated' ), 'Third-party metadata still saves normally' );
$assert( get_post_meta( $postId, 'third_party_metadata', true ) === 'unrelated', 'Third-party metadata read unaffected' );
$assert( delete_post_meta( $postId, 'third_party_metadata' ), 'Third-party metadata delete unaffected' );

// Detect WordPress storage regressions: no legacy write was added by the bridge.
$assert( ! metadata_exists( 'post', $postId, '_yps_new_test_key' ), 'No new YPS metadata generated by bridge' );
echo "YSEO_META_COMPAT_PASS {$checks} assertions\n";
