<?php
/**
 * Standalone runtime regression for Translation Job presentation normalization.
 *
 * It loads the production traits and supplies only the minimal WordPress surface
 * needed to execute the real normalizer and applied-surface verifier.
 *
 * @package Devenia_Workflow
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Post {
	public int $ID;
	public string $post_type = 'page';
	public string $post_title = '';
	public string $post_excerpt = '';
	public string $post_content = '';
	public string $post_name = '';
	public int $post_parent = 0;

	/** @param array<string,mixed> $values */
	public function __construct( array $values ) {
		foreach ( $values as $key => $value ) {
			$this->{$key} = $value;
		}
	}
}

$GLOBALS['devenia_presentation_test_posts'] = array();
$GLOBALS['devenia_presentation_test_meta'] = array();

function absint( $value ): int {
	return abs( (int) $value );
}

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?: '';
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function wp_kses_post( $value ): string {
	return (string) $value;
}

function esc_html( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function get_bloginfo( $show = '' ): string {
	return 'charset' === $show ? 'UTF-8' : '';
}

function wp_json_encode( $value ) {
	return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

function get_post( $post_id ) {
	return $GLOBALS['devenia_presentation_test_posts'][ (int) $post_id ] ?? null;
}

function get_post_meta( $post_id, $key, $single = false ) {
	$value = $GLOBALS['devenia_presentation_test_meta'][ (int) $post_id ][ (string) $key ] ?? '';
	return $single ? $value : array( $value );
}

function get_post_thumbnail_id( $post_id ): int {
	return 0;
}

require_once dirname( __DIR__ ) . '/includes/trait-source-design-inheritance.php';
require_once dirname( __DIR__ ) . '/includes/trait-translation-job-quality-authority.php';

final class Devenia_Workflow_Presentation_Normalization_Runtime_Test {
	use Devenia_Workflow_Translation_Source_Design_Inheritance;
	use Devenia_Workflow_Translation_Job_Quality_Authority;

	public const META_SOURCE_DESIGN_HASH = '_devenia_translation_source_design_hash';
	public const META_LOCALIZED_FRAGMENTS = '_devenia_translation_localized_fragments';
	public const META_LOCALIZED_PATH = '_devenia_translation_localized_path';
	public const META_CANONICAL_ROUTE = '_devenia_translation_canonical_route_v1';
	public const META_FEATURED_IMAGE_ALT = '_devenia_translation_featured_image_alt';

	/** @return array<int,array{key:string,html:string}> */
	public static function normalize( array $fragments ): array {
		return self::translation_job_normalized_presentation_fragments( $fragments );
	}

	/** @return array<string,mixed> */
	public static function verify( WP_Post $source, int $translation_id, array $manifest ): array {
		return self::translation_job_verify_applied_surface( $source, $translation_id, $manifest );
	}

	private static function normalize_review_text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( is_string( $text ) ? $text : '' );
	}

	private static function translation_job_canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::translation_job_canonicalize( $item );
		}
		return $value;
	}

	/** @return array<string,mixed> */
	private static function json_post_meta_value( int $post_id, string $meta_key ): array {
		$value = get_post_meta( $post_id, $meta_key, true );
		return is_array( $value ) ? $value : array();
	}
}

function devenia_presentation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$source = new WP_Post(
	array(
		'ID'        => 393,
		'post_type' => 'page',
	)
);
$translation = new WP_Post(
	array(
		'ID'           => 41811,
		'post_type'    => 'page',
		'post_title'   => 'Devenia Workflow',
		'post_excerpt' => 'Ein kontrollierter Workflow.',
		'post_content' => '<!-- wp:paragraph --><p>Hallo</p><!-- /wp:paragraph -->',
		'post_name'    => 'devenia-workflow',
	)
);
$GLOBALS['devenia_presentation_test_posts'][41811] = $translation;

$mixed_legacy_fragments = array(
	array( 'key' => 'faq:z', 'text' => 'Antwort & mehr' ),
	array( 'key' => 'body:a', 'html' => '<strong>Hallo</strong>' ),
	array( 'key' => 'faq:a', 'text' => 'Erste Antwort' ),
);
$stored_fragments = array(
	array( 'key' => 'faq:a', 'html' => 'Erste Antwort' ),
	array( 'key' => 'faq:z', 'html' => 'Antwort &amp; mehr' ),
	array( 'key' => 'body:a', 'html' => '<strong>Hallo</strong>' ),
);
$expected_normalized = array(
	array( 'key' => 'body:a', 'html' => '<strong>Hallo</strong>' ),
	array( 'key' => 'faq:a', 'html' => 'Erste Antwort' ),
	array( 'key' => 'faq:z', 'html' => 'Antwort &amp; mehr' ),
);

devenia_presentation_assert(
	$expected_normalized === Devenia_Workflow_Presentation_Normalization_Runtime_Test::normalize( $mixed_legacy_fragments ),
	'Mixed html/text fragments did not normalize to the deterministic storage form.'
);
devenia_presentation_assert(
	$expected_normalized === Devenia_Workflow_Presentation_Normalization_Runtime_Test::normalize( $stored_fragments ),
	'Reordered storage records did not normalize to the same logical identity.'
);

$GLOBALS['devenia_presentation_test_meta'][41811] = array(
	'rank_math_title'                       => '',
	'rank_math_description'                 => '',
	'rank_math_focus_keyword'               => '',
	Devenia_Workflow_Presentation_Normalization_Runtime_Test::META_SOURCE_DESIGN_HASH => 'design-c38d9c',
	Devenia_Workflow_Presentation_Normalization_Runtime_Test::META_LOCALIZED_FRAGMENTS => array(
		'fragments' => $stored_fragments,
	),
);
$manifest = array(
	'schema_version' => 2,
	'language'       => 'de',
	'content'        => array(
		'title'     => $translation->post_title,
		'excerpt'   => $translation->post_excerpt,
		'gutenberg' => $translation->post_content,
	),
	'seo'            => array(
		'title'         => '',
		'description'   => '',
		'focus_keyword' => '',
	),
	'route'          => array(
		'post_name'   => $translation->post_name,
		'post_parent' => 0,
	),
	'media'          => array(
		'featured_image_id' => 0,
	),
	'presentation'   => array(
		'source_design_hash' => 'design-c38d9c',
		'localized_fragments' => $mixed_legacy_fragments,
	),
);

$legacy_result = Devenia_Workflow_Presentation_Normalization_Runtime_Test::verify( $source, 41811, $manifest );
devenia_presentation_assert(
	true === ( $legacy_result['success'] ?? false ) && array() === ( $legacy_result['failed'] ?? null ),
	'Legacy mixed/raw manifest was not accepted after exact normalization.'
);

$changed_manifest = $manifest;
$changed_manifest['presentation']['localized_fragments'][0]['text'] = 'Sachlich geaenderte Antwort';
$changed_result = Devenia_Workflow_Presentation_Normalization_Runtime_Test::verify( $source, 41811, $changed_manifest );
devenia_presentation_assert(
	false === ( $changed_result['success'] ?? true ) && in_array( 'presentation', (array) ( $changed_result['failed'] ?? array() ), true ),
	'A genuine localized fragment value change did not fail presentation verification.'
);

$changed_hash_manifest = $manifest;
$changed_hash_manifest['presentation']['source_design_hash'] = 'design-other';
$changed_hash_result = Devenia_Workflow_Presentation_Normalization_Runtime_Test::verify( $source, 41811, $changed_hash_manifest );
devenia_presentation_assert(
	false === ( $changed_hash_result['success'] ?? true ) && in_array( 'presentation', (array) ( $changed_hash_result['failed'] ?? array() ), true ),
	'A genuine source-design hash change did not fail presentation verification.'
);

echo "Presentation fragment normalization runtime OK\n";
