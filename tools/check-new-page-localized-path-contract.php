<?php
/**
 * Contract: a newly saved page translation establishes the exact staged route
 * before the publication surface is verified.
 */

$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/devenia-workflow.php' );
$authority = file_get_contents( $root . '/includes/trait-translation-job-quality-authority.php' );

if ( false === $main || false === $authority ) {
	fwrite( STDERR, "Could not read the localized publication implementation.\n" );
	exit( 1 );
}

$implementation = $main . "\n" . $authority;

$required = array(
	"private static function establish_page_localized_path_after_save(",
	"private static function expected_localized_path_for_new_page(",
	"self::expected_localized_path_for_post( \$translation_id, \$language )",
	"update_post_meta( \$translation_id, self::META_LOCALIZED_PATH, \$expected_path )",
	"'code'                    => 'localized_page_path_mismatch'",
	"self::rollback_new_translation_after_upsert_failure( \$translation_id, \$creating_translation, \$page_path_result )",
	"\$route['localized_path'] = \$expected_path",
	"'localized_path' => (string) ( \$staged_route_surface['localized_path'] ?? '' )",
);

foreach ( $required as $needle ) {
	if ( false === strpos( $implementation, $needle ) ) {
		fwrite( STDERR, "Missing new-page localized-path publication contract: {$needle}\n" );
		exit( 1 );
	}
}

echo "New-page localized-path contract passed.\n";
