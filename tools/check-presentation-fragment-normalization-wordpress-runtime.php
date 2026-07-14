<?php
/**
 * WordPress runtime regression for Translation Job presentation normalization.
 *
 * Run with `wp eval-file` after installing the exact candidate on dev. The
 * fixture owns two uniquely named posts, records no workflow options, and
 * removes every persistent row in `finally` before reporting success/failure.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$source_id = 0;
$translation_id = 0;
$fixture_token = strtolower( wp_generate_password( 12, false, false ) );
$fixture_slug = 'presentasjonsnormalisering-' . $fixture_token;
$source_fixture_slug = 'workflow-presentation-normalization-source-' . $fixture_token;
$missing_option_marker = '__devenia_missing_' . $fixture_token;
$inventory_dirty_before = get_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_DIRTY, $missing_option_marker );
$runtime_error = null;
$runtime_result = null;

$call = static function ( string $method, ...$arguments ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$update_internal_post = static function ( array $post_data ) use ( $call ) {
	$result = null;
	$call(
		'with_direct_save_storage_guardrails_suspended',
		static function () use ( &$result, $post_data ): void {
			$result = wp_update_post( $post_data, true );
		}
	);
	return $result;
};

try {
	$assert( class_exists( Devenia_Workflow::class ), 'Devenia Workflow is not active.' );
	$assert( '0.1.611' === (string) Devenia_Workflow::VERSION, 'The active dev plugin is not the exact 0.1.611 candidate.' );

	$languages = $call( 'target_languages' );
	$assert( is_array( $languages ) && isset( $languages['nb'] ), 'The dev language registry must include Norwegian Bokmal (nb).' );
	$language = 'nb';

	$source_insert = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Presentation normalization source ' . $fixture_token,
			'post_excerpt' => 'A bounded source fixture for exact presentation verification.',
			'post_name'    => $source_fixture_slug,
			'post_content' => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Storage representation</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Verify mixed presentation fragments without weakening exact values.</p>\n<!-- /wp:paragraph -->",
		),
		true
	);
	$assert( ! is_wp_error( $source_insert ) && absint( $source_insert ) > 0, 'Could not create the source fixture.' );
	$source_id = absint( $source_insert );
	$source = get_post( $source_id );
	$assert( $source instanceof WP_Post, 'The source fixture could not be read.' );

	$source_design = $call( 'source_design_contract', $source );
	$source_fragments = isset( $source_design['fragments'] ) && is_array( $source_design['fragments'] ) ? $source_design['fragments'] : array();
	$assert( count( $source_fragments ) >= 2, 'The source fixture did not expose at least two design fragments.' );

	$mixed_fragments = array();
	foreach ( array_reverse( $source_fragments ) as $index => $fragment ) {
		$key = (string) ( $fragment['key'] ?? '' );
		$assert( '' !== $key, 'The source design exposed an empty fragment key.' );
		if ( 0 === $index % 2 ) {
			$mixed_fragments[] = array(
				'key'  => $key,
				'text' => 'Kontrollert tekst & verdi ' . ( $index + 1 ),
			);
		} else {
			$mixed_fragments[] = array(
				'key'  => $key,
				'html' => '<strong>Kontrollert HTML-verdi ' . ( $index + 1 ) . '</strong>',
			);
		}
	}
	$assert(
		count( array_filter( $mixed_fragments, static function ( array $row ): bool { return array_key_exists( 'text', $row ); } ) ) > 0
		&& count( array_filter( $mixed_fragments, static function ( array $row ): bool { return array_key_exists( 'html', $row ); } ) ) > 0,
		'The fixture did not create both text and HTML fragment representations.'
	);
	$coverage = $call( 'translation_job_fragment_coverage', array( 'source_id' => $source_id ), $mixed_fragments );
	$assert( ! empty( $coverage['success'] ), 'The complete mixed fragment fixture failed exact source coverage.' );
	$missing_fragments = $mixed_fragments;
	array_pop( $missing_fragments );
	$missing_coverage = $call( 'translation_job_fragment_coverage', array( 'source_id' => $source_id ), $missing_fragments );
	$assert(
		empty( $missing_coverage['success'] )
		&& 'artifact_fragment_coverage_invalid' === (string) ( $missing_coverage['code'] ?? '' )
		&& ! empty( $missing_coverage['missing_keys'] ),
		'A missing logical fragment key did not fail exact source coverage.'
	);
	$extra_fragments = $mixed_fragments;
	$extra_fragments[] = array( 'key' => 'unexpected:fragment', 'text' => 'Skal avvises' );
	$extra_coverage = $call( 'translation_job_fragment_coverage', array( 'source_id' => $source_id ), $extra_fragments );
	$assert(
		empty( $extra_coverage['success'] )
		&& 'artifact_fragment_coverage_invalid' === (string) ( $extra_coverage['code'] ?? '' )
		&& in_array( 'unexpected:fragment', (array) ( $extra_coverage['extra_keys'] ?? array() ), true ),
		'An unexpected logical fragment key did not fail exact source coverage.'
	);
	$duplicate_fragments = $mixed_fragments;
	$duplicate_fragments[] = $mixed_fragments[0];
	$duplicate_coverage = $call( 'translation_job_fragment_coverage', array( 'source_id' => $source_id ), $duplicate_fragments );
	$assert(
		empty( $duplicate_coverage['success'] )
		&& 'artifact_fragment_coverage_invalid' === (string) ( $duplicate_coverage['code'] ?? '' )
		&& ! empty( $duplicate_coverage['duplicate_keys'] ),
		'A duplicate logical fragment key did not fail exact source coverage.'
	);

	$job = array(
		'job_id'          => 'tj_runtime_presentation_' . $fixture_token,
		'source_id'       => $source_id,
		'source_revision' => 'runtime-source-' . $fixture_token,
		'target_language' => $language,
		'translation_id'  => 0,
	);
	$artifact = array(
		'title'                  => 'Presentasjonsnormalisering',
		'excerpt'                => 'En avgrenset kontroll av den lagrede presentasjonsformen.',
		'localized_slug'         => $fixture_slug,
		'localized_path'         => $language . '/' . $fixture_slug,
		'localized_parent_path'  => '',
		'localized_fragments'    => $mixed_fragments,
		'seo'                    => array(
			'title'       => 'Presentasjonsnormalisering',
			'description' => 'En avgrenset kontroll av lagringsnormalisering for oversatte fragmenter.',
		),
	);
	$staging = $call( 'translation_job_stage_artifact', $job, $artifact );
	$assert( ! empty( $staging['success'] ), 'Staging failed: ' . wp_json_encode( $staging ) );
	$manifest = isset( $staging['manifest'] ) && is_array( $staging['manifest'] ) ? $staging['manifest'] : array();
	$assert(
		(string) ( $staging['surface_revision'] ?? '' ) === (string) $call( 'translation_job_surface_revision', $manifest ),
		'The staged storage-canonical manifest did not reproduce its surface revision.'
	);
	$staged_fragments = isset( $manifest['presentation']['localized_fragments'] ) && is_array( $manifest['presentation']['localized_fragments'] ) ? $manifest['presentation']['localized_fragments'] : array();
	$staged_keys = array();
	foreach ( $staged_fragments as $row ) {
		$assert(
			is_array( $row )
			&& array( 'key', 'html' ) === array_keys( $row )
			&& '' !== (string) $row['key'],
			'Staging retained a non-storage fragment representation: ' . wp_json_encode( $row )
		);
		$staged_keys[] = (string) $row['key'];
	}
	$sorted_keys = $staged_keys;
	sort( $sorted_keys, SORT_STRING );
	$assert(
		count( $staged_fragments ) === count( $source_fragments ) && $sorted_keys === $staged_keys,
		'Staging did not produce complete key/html rows sorted by logical key.'
	);

	$content = isset( $manifest['content'] ) && is_array( $manifest['content'] ) ? $manifest['content'] : array();
	$route = isset( $manifest['route'] ) && is_array( $manifest['route'] ) ? $manifest['route'] : array();
	$translation_insert = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => (string) ( $content['title'] ?? '' ),
			'post_excerpt' => (string) ( $content['excerpt'] ?? '' ),
			'post_content' => (string) ( $content['gutenberg'] ?? '' ),
			'post_name'    => (string) ( $route['localized_slug'] ?? $fixture_slug ),
			'post_parent'  => absint( $route['localized_parent_id'] ?? 0 ),
		),
		true
	);
	$assert( ! is_wp_error( $translation_insert ) && absint( $translation_insert ) > 0, 'Could not create the translation fixture.' );
	$translation_id = absint( $translation_insert );
	$translation_update = $update_internal_post(
		array(
			'ID'           => $translation_id,
			'post_title'   => (string) ( $content['title'] ?? '' ),
			'post_excerpt' => (string) ( $content['excerpt'] ?? '' ),
			'post_content' => (string) ( $content['gutenberg'] ?? '' ),
			'post_name'    => (string) ( $route['localized_slug'] ?? $fixture_slug ),
			'post_parent'  => absint( $route['localized_parent_id'] ?? 0 ),
		)
	);
	$assert( ! is_wp_error( $translation_update ), 'Could not establish the exact staged translation surface.' );

	update_post_meta( $translation_id, Devenia_Workflow::META_SOURCE_ID, $source_id );
	update_post_meta( $translation_id, Devenia_Workflow::META_LANGUAGE, $language );
	update_post_meta( $translation_id, Devenia_Workflow::META_LOCALIZED_PATH, trim( (string) ( $route['localized_path'] ?? '' ), '/' ) );
	$seo = isset( $manifest['seo'] ) && is_array( $manifest['seo'] ) ? $manifest['seo'] : array();
	update_post_meta( $translation_id, 'rank_math_title', (string) ( $seo['title'] ?? '' ) );
	update_post_meta( $translation_id, 'rank_math_description', (string) ( $seo['description'] ?? '' ) );
	update_post_meta( $translation_id, 'rank_math_focus_keyword', (string) ( $seo['focus_keyword'] ?? '' ) );
	$call( 'store_localized_source_design_fragments', $translation_id, $source, $language, $mixed_fragments, $source_design );

	$stored = $call( 'stored_localized_source_design_fragments', $translation_id );
	$stored_fragments = isset( $stored['fragments'] ) && is_array( $stored['fragments'] ) ? $stored['fragments'] : array();
	$assert( count( $stored_fragments ) === count( $source_fragments ), 'The actual WordPress fragment meta is incomplete.' );
	foreach ( $stored_fragments as $row ) {
		$assert( is_array( $row ) && array( 'key', 'html' ) === array_keys( $row ), 'WordPress meta retained a raw text fragment row.' );
	}

	$legacy_manifest = $manifest;
	$legacy_manifest['presentation']['localized_fragments'] = $mixed_fragments;
	$legacy_result = $call( 'translation_job_verify_applied_surface', $source, $translation_id, $legacy_manifest );
	$assert(
		! empty( $legacy_result['success'] ) && array() === ( $legacy_result['failed'] ?? null ),
		'Legacy raw mixed/reordered manifest did not match actual normalized WordPress meta: ' . wp_json_encode( $legacy_result )
	);

	$changed_value_manifest = $legacy_manifest;
	if ( array_key_exists( 'text', $changed_value_manifest['presentation']['localized_fragments'][0] ) ) {
		$changed_value_manifest['presentation']['localized_fragments'][0]['text'] = 'En faktisk endret fragmentverdi';
	} else {
		$changed_value_manifest['presentation']['localized_fragments'][0]['html'] = '<strong>En faktisk endret fragmentverdi</strong>';
	}
	$changed_value_result = $call( 'translation_job_verify_applied_surface', $source, $translation_id, $changed_value_manifest );
	$assert(
		empty( $changed_value_result['success'] ) && in_array( 'presentation', (array) ( $changed_value_result['failed'] ?? array() ), true ),
		'A genuine fragment value change did not fail presentation verification.'
	);

	$changed_hash_manifest = $legacy_manifest;
	$changed_hash_manifest['presentation']['source_design_hash'] = hash( 'sha256', 'genuine-design-change-' . $fixture_token );
	$changed_hash_result = $call( 'translation_job_verify_applied_surface', $source, $translation_id, $changed_hash_manifest );
	$assert(
		empty( $changed_hash_result['success'] ) && in_array( 'presentation', (array) ( $changed_hash_result['failed'] ?? array() ), true ),
		'A genuine source-design hash change did not fail presentation verification.'
	);

	$runtime_result = array(
		'success'                                   => true,
		'staging_storage_canonical'                 => true,
		'legacy_raw_manifest_compatible'            => true,
		'reordered_equivalent_fragments_accepted'   => true,
		'genuine_fragment_change_rejected'          => true,
		'genuine_design_hash_change_rejected'       => true,
		'missing_extra_duplicate_keys_rejected'     => true,
		'fixture_token'                             => $fixture_token,
	);
} catch ( Throwable $error ) {
	$runtime_error = $error;
} finally {
	foreach ( array_filter( array( $translation_id, $source_id ) ) as $post_id ) {
		if ( get_post( (int) $post_id ) ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
	if ( $missing_option_marker === $inventory_dirty_before ) {
		delete_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_DIRTY );
	} else {
		update_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_DIRTY, $inventory_dirty_before, false );
	}
}

$leaked_ids = get_posts(
	array(
		'post_type'      => 'any',
		'post_status'    => 'any',
		'name'           => $fixture_slug,
		'fields'         => 'ids',
		'posts_per_page' => -1,
	)
);
$leaked_source_ids = get_posts(
	array(
		'post_type'      => 'any',
		'post_status'    => 'any',
		'name'           => $source_fixture_slug,
		'fields'         => 'ids',
		'posts_per_page' => -1,
	)
);
global $wpdb;
$owned_post_ids = array_values( array_filter( array_map( 'absint', array( $source_id, $translation_id ) ) ) );
$orphan_meta_count = 0;
if ( $owned_post_ids ) {
	$placeholders = implode( ',', array_fill( 0, count( $owned_post_ids ), '%d' ) );
	$orphan_meta_count = absint(
		$wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list is generated locally from owned fixture IDs.
				$owned_post_ids
			)
		)
	);
}
$inventory_dirty_after = get_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_DIRTY, $missing_option_marker );
$fixture_leaked = ! empty( $leaked_ids ) || ! empty( $leaked_source_ids ) || $orphan_meta_count > 0 || maybe_serialize( $inventory_dirty_before ) !== maybe_serialize( $inventory_dirty_after ) || ( $source_id && get_post( $source_id ) ) || ( $translation_id && get_post( $translation_id ) );
if ( $fixture_leaked && ! $runtime_error instanceof Throwable ) {
	$runtime_error = new RuntimeException( 'The WordPress presentation-normalization fixture leaked persistent posts.' );
}

if ( $runtime_error instanceof Throwable ) {
	fwrite(
		STDERR,
		wp_json_encode(
			array(
				'success'              => false,
				'error'                => $runtime_error->getMessage(),
				'fixture_cleanup_passed'=> ! $fixture_leaked,
			)
		) . PHP_EOL
	);
	exit( 1 );
}

$runtime_result['fixture_cleanup_passed'] = ! $fixture_leaked;
fwrite( STDERR, wp_json_encode( $runtime_result ) . PHP_EOL );
