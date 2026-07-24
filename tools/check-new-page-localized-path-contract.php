<?php
/**
 * Contract: a newly saved page translation establishes the exact staged route
 * before the publication surface is verified.
 */

$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/devenia-workflow.php' );
$jobs = file_get_contents( $root . '/includes/trait-translation-job.php' );
$authority = file_get_contents( $root . '/includes/trait-translation-job-quality-authority.php' );
$publication = file_get_contents( $root . '/includes/trait-localized-presentation-publication.php' );

if ( false === $main || false === $jobs || false === $authority || false === $publication ) {
	fwrite( STDERR, "Could not read the localized publication implementation.\n" );
	exit( 1 );
}

$implementation = $main . "\n" . $jobs . "\n" . $authority . "\n" . $publication;

$publication_owner = "private static function translation_job_effective_staged_route_surface(";

if ( false === strpos( $publication, $publication_owner ) ) {
	fwrite( STDERR, "Localized Presentation Publication must own the staged-route compatibility Interface.\n" );
	exit( 1 );
}

if ( false !== strpos( $authority, $publication_owner ) ) {
	fwrite( STDERR, "Quality Authority must not own the staged-route compatibility Interface.\n" );
	exit( 1 );
}

$required = array(
	"private static function establish_page_localized_path_after_save(",
	"private static function expected_localized_path_for_new_page(",
	"private static function is_front_page_source(",
	"private static function translation_language_root_route_issue(",
	"'code' => 'localized_language_root_missing'",
	"'code' => 'localized_language_root_slug_required'",
	"\$requires_language_root_bootstrap = \$is_language_root && ( empty( \$existing_route ) || empty( \$existing_route['route_locked'] ) )",
	"'language_root_bootstrap' => \$requires_language_root_bootstrap",
	"'required_localized_slug' => \$requires_language_root_bootstrap ? self::language_prefix( \$language ) : ''",
	"'required_localized_path' => \$requires_language_root_bootstrap ? self::language_prefix( \$language ) : ''",
	"\$route_locked = \$existing instanceof WP_Post && 'publish' === (string) \$existing->post_status",
	"'translation_id'       => \$existing instanceof WP_Post ? (int) \$existing->ID : 0",
	"self::expected_localized_path_for_post( \$translation_id, \$language )",
	"update_post_meta( \$translation_id, self::META_LOCALIZED_PATH, \$expected_path )",
	"'code'                    => 'localized_page_path_mismatch'",
	"self::rollback_new_translation_after_upsert_failure( \$translation_id, \$creating_translation, \$page_path_result )",
	"\$route['localized_path'] = \$expected_path",
	"self::translation_job_effective_staged_route_surface( \$source, (string) \$job['target_language']",
	"self::translation_job_effective_staged_route_surface( \$source, (string) ( \$manifest['language'] ?? '' )",
);

foreach ( $required as $needle ) {
	if ( false === strpos( $implementation, $needle ) ) {
		fwrite( STDERR, "Missing new-page localized-path publication contract: {$needle}\n" );
		exit( 1 );
	}
}

echo "New-page localized-path contract passed.\n";
