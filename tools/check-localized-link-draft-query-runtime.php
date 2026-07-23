<?php
/**
 * Dev runtime contract for draft-query and ambiguous localized link identities.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Localized Link runtime requires WP-CLI with WordPress loaded.' );
}
if ( ! class_exists( 'Devenia_Workflow' ) ) {
	throw new RuntimeException( 'Devenia Workflow was not loaded.' );
}

$call = static function ( string $method, ...$arguments ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};

$token = strtolower( wp_generate_password( 10, false, false ) );
$post_ids = array();
$target_ids = array();
$failure = null;

try {
	$baseline_map = (array) $call( 'localized_link_expected_target_map', 'en', true );
	$baseline_homepage_target = $call( 'localized_internal_link_target', home_url( '/' ), $baseline_map );
	$sources = array();
	$targets = array();
	$shared_localized_path = 'nb/runtime-link-collision-' . $token;
	$source_ids = array_values(
		array_filter(
			array_map( 'absint', get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 100, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) ) ),
			static function ( int $post_id ): bool {
				return '' === (string) get_post_meta( $post_id, '_devenia_translation_source_id', true )
					&& false === strpos( (string) get_permalink( $post_id ), '?page_id=' );
			}
		)
	);
	$source_ids = array_slice( $source_ids, 0, 2 );
	if ( 2 !== count( $source_ids ) ) {
		throw new RuntimeException( 'The dev runtime needs two established published source fixtures.' );
	}
	foreach ( $source_ids as $index => $source_id ) {
		$source = get_post( $source_id );
		if ( ! $source instanceof WP_Post || 'publish' !== (string) $source->post_status || '' === (string) get_permalink( $source_id ) ) {
			throw new RuntimeException( 'An established published source fixture became unavailable.' );
		}
		$label = 0 === $index ? 'first' : 'second';

		$target_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Localized Link target ' . $label . ' ' . $token,
				'post_name'    => 'localized-link-target-' . $label . '-' . $token,
				'post_content' => '<!-- wp:paragraph --><p>Exact draft target identity.</p><!-- /wp:paragraph -->',
			),
			true
		);
		if ( is_wp_error( $target_id ) || $target_id < 1 ) {
			throw new RuntimeException( 'Could not create the Localized Link draft fixture.' );
		}
		$target_id = (int) $target_id;
		$post_ids[] = $target_id;
		$target_ids[] = $target_id;
		update_post_meta( $target_id, '_devenia_translation_source_id', $source_id );
		update_post_meta( $target_id, '_devenia_translation_language', 'nb' );
		update_post_meta( $target_id, '_devenia_translation_status', 'draft' );
		update_post_meta( $target_id, '_devenia_translation_localized_path', $shared_localized_path );
		clean_post_cache( $target_id );
		if ( ! $call( 'sync_translation_index_row', $target_id ) ) {
			throw new RuntimeException( 'Could not index the Localized Link draft fixture.' );
		}

		$sources[] = array( 'id' => $source_id, 'url' => (string) get_permalink( $source_id ) );
		$targets[] = array( 'id' => $target_id, 'url' => (string) get_permalink( $target_id ) );
	}

	$map = (array) $call( 'localized_link_expected_target_map', 'en', true );
	$homepage_target = $call( 'localized_internal_link_target', home_url( '/' ), $map );
	if ( $call( 'normalized_comparable_url', (string) $homepage_target ) !== $call( 'normalized_comparable_url', (string) $baseline_homepage_target ) ) {
		throw new RuntimeException( 'A draft query permalink changed the authoritative public-homepage mapping.' );
	}

	foreach ( $targets as $index => $target ) {
		if ( false === strpos( (string) $target['url'], '?page_id=' ) ) {
			throw new RuntimeException( 'The draft fixture did not expose the WordPress query-permalink pattern.' );
		}
		$resolved = $call( 'localized_internal_link_target', (string) $target['url'], $map );
		if ( $call( 'normalized_comparable_url', (string) $resolved ) !== $call( 'normalized_comparable_url', (string) $sources[ $index ]['url'] ) ) {
			throw new RuntimeException( 'An exact draft query identity no longer resolves to its owning source.' );
		}
	}

	$ambiguous_target = $call( 'localized_internal_link_target', home_url( '/' . $shared_localized_path . '/' ), $map );
	if ( null !== $ambiguous_target ) {
		throw new RuntimeException( 'A shared non-query variant selected one source instead of failing closed.' );
	}
} catch ( Throwable $caught ) {
	$failure = $caught;
} finally {
	foreach ( $target_ids as $target_id ) {
		$call( 'delete_translation_index_row', $target_id );
	}
	foreach ( array_reverse( $post_ids ) as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	$call( 'localized_link_expected_target_map', 'en', true );
}

if ( $failure instanceof Throwable ) {
	throw $failure;
}

fwrite( STDOUT, "Workflow Localized Link draft-query runtime passed.\n" );
