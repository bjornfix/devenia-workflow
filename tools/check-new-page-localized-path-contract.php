<?php
/**
 * Contract: a newly saved page translation establishes the exact staged route
 * before the publication surface is verified.
 */

$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/devenia-workflow.php' );
$authority = file_get_contents( $root . '/includes/trait-translation-job-quality-authority.php' );
$publication = file_get_contents( $root . '/includes/trait-localized-presentation-publication.php' );

if ( false === $main || false === $authority || false === $publication ) {
	fwrite( STDERR, "Could not read the localized publication implementation.\n" );
	exit( 1 );
}

$implementation = $main . "\n" . $authority . "\n" . $publication;

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
