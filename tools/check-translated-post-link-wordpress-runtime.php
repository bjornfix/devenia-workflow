<?php
/** Real WordPress proof that translated post links resolve without recursion. */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$missing_marker = '__devenia_translated_post_link_missing_' . wp_generate_password( 10, false, false );
$languages_before = get_option( Devenia_Workflow::OPTION_LANGUAGES, $missing_marker );
$posts_page_before = get_option( 'page_for_posts', $missing_marker );
$fixture_ids = array();
$translated_post_id = 0;
$post_link_filter_calls = 0;
$recursion_guard = static function ( string $permalink, WP_Post $post ) use ( &$post_link_filter_calls, &$translated_post_id ): string {
	if ( $translated_post_id > 0 && (int) $post->ID === $translated_post_id ) {
		++$post_link_filter_calls;
		if ( $post_link_filter_calls > 4 ) {
			throw new RuntimeException( 'translated_post_link_recursed' );
		}
	}
	return $permalink;
};

try {
	$languages = Devenia_Workflow::languages( true );
	foreach ( $languages as &$language_config ) {
		if ( is_array( $language_config ) ) {
			$language_config['source'] = '0';
		}
	}
	unset( $language_config );
	$languages['fr']['source'] = '1';
	$languages['en']['source'] = '0';
	$languages['en']['prefix'] = 'en';
	update_option( Devenia_Workflow::OPTION_LANGUAGES, $languages, false );
	Devenia_Workflow::languages( true );
	update_option( 'page_for_posts', 0 );

	$source_id = wp_insert_post(
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Article source française fixture',
			'post_name'   => 'article-source-francaise-fixture',
		),
		true
	);
	$translated_post_id = wp_insert_post(
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'English translated link fixture',
			'post_name'   => 'english-translated-link-fixture',
		),
		true
	);
	if ( ! is_wp_error( $source_id ) ) {
		$fixture_ids[] = absint( $source_id );
	}
	if ( ! is_wp_error( $translated_post_id ) ) {
		$fixture_ids[] = absint( $translated_post_id );
	}
	$assert( ! is_wp_error( $source_id ) && ! is_wp_error( $translated_post_id ), 'Could not create translated-post fixtures.' );
	update_post_meta( $translated_post_id, Devenia_Workflow::META_SOURCE_ID, $source_id );
	update_post_meta( $translated_post_id, Devenia_Workflow::META_LANGUAGE, 'en' );
	delete_post_meta( $translated_post_id, Devenia_Workflow::META_CANONICAL_ROUTE );
	delete_post_meta( $translated_post_id, Devenia_Workflow::META_LOCALIZED_PATH );

	add_filter( 'post_link', $recursion_guard, 1, 2 );
	$no_posts_page_url = get_permalink( $translated_post_id );
	$assert( home_url( '/en/english-translated-link-fixture/' ) === $no_posts_page_url, 'No-posts-page fallback did not use the configured English target prefix and slug.' );
	$assert( 1 === $post_link_filter_calls, 'Translated post link resolution was not bounded to one filter pass.' );

	update_post_meta( $translated_post_id, Devenia_Workflow::META_LOCALIZED_PATH, 'en/stored-link-fixture' );
	$stored_url = get_permalink( $translated_post_id );
	$assert( home_url( '/en/stored-link-fixture/' ) === $stored_url, 'Stored localized path was not authoritative.' );

	update_post_meta( $translated_post_id, Devenia_Workflow::META_CANONICAL_ROUTE, array( 'path' => 'en/canonical-link-fixture' ) );
	$canonical_url = get_permalink( $translated_post_id );
	$assert( home_url( '/en/canonical-link-fixture/' ) === $canonical_url, 'Canonical Route Contract was not authoritative.' );
	delete_post_meta( $translated_post_id, Devenia_Workflow::META_CANONICAL_ROUTE );
	delete_post_meta( $translated_post_id, Devenia_Workflow::META_LOCALIZED_PATH );

	$posts_page_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Blog source française fixture',
			'post_name'   => 'blog-source-francaise-fixture',
		),
		true
	);
	$language_root_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'English runtime root',
			'post_name'   => 'en-runtime-root',
		),
		true
	);
	$translated_posts_page_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_parent' => is_wp_error( $language_root_id ) ? 0 : absint( $language_root_id ),
			'post_title'  => 'English posts runtime',
			'post_name'   => 'posts-runtime',
		),
		true
	);
	if ( ! is_wp_error( $posts_page_id ) ) {
		$fixture_ids[] = absint( $posts_page_id );
	}
	if ( ! is_wp_error( $language_root_id ) ) {
		$fixture_ids[] = absint( $language_root_id );
	}
	if ( ! is_wp_error( $translated_posts_page_id ) ) {
		$fixture_ids[] = absint( $translated_posts_page_id );
	}
	$assert( ! is_wp_error( $posts_page_id ) && ! is_wp_error( $language_root_id ) && ! is_wp_error( $translated_posts_page_id ), 'Could not create localized blog-base fixtures.' );
	update_post_meta( $translated_posts_page_id, Devenia_Workflow::META_SOURCE_ID, $posts_page_id );
	update_post_meta( $translated_posts_page_id, Devenia_Workflow::META_LANGUAGE, 'en' );
	update_option( 'page_for_posts', $posts_page_id );

	$blog_base = trim( (string) wp_parse_url( get_permalink( $translated_posts_page_id ), PHP_URL_PATH ), '/' );
	$source_blog_base = trim( (string) wp_parse_url( get_permalink( $posts_page_id ), PHP_URL_PATH ), '/' );
	$blog_url = get_permalink( $translated_post_id );
	$assert( '' !== $blog_base && $source_blog_base !== $blog_base, 'English translated posts-page base was not distinct from the French source base.' );
	$assert( home_url( '/' . $blog_base . '/english-translated-link-fixture/' ) === $blog_url, 'English translated post did not use the English posts-page translation as its blog base.' );

	echo wp_json_encode(
		array(
			'success'                              => true,
			'no_posts_page_prefix_slug_bounded'    => true,
			'stored_localized_path_authoritative'  => true,
			'canonical_route_authoritative'         => true,
			'localized_blog_base_slug'              => true,
			'non_english_source_english_target_blog_base' => true,
			'post_link_filter_calls'                => $post_link_filter_calls,
		)
	) . PHP_EOL;
} finally {
	remove_filter( 'post_link', $recursion_guard, 1 );
	foreach ( array_reverse( $fixture_ids ) as $fixture_id ) {
		wp_delete_post( $fixture_id, true );
	}
	if ( $missing_marker === $posts_page_before ) {
		delete_option( 'page_for_posts' );
	} else {
		update_option( 'page_for_posts', $posts_page_before );
	}
	if ( $missing_marker === $languages_before ) {
		delete_option( Devenia_Workflow::OPTION_LANGUAGES );
	} else {
		update_option( Devenia_Workflow::OPTION_LANGUAGES, $languages_before, false );
	}
	Devenia_Workflow::languages( true );
}
