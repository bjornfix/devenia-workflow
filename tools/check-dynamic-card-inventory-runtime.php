<?php
/**
 * Standalone runtime regression for Workflow's dynamic child-card inventory.
 *
 * @package Devenia_Workflow
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Post {
	public int $ID;
	public string $post_type = 'page';
	public string $post_status = 'publish';
	public string $post_content = '';
	public string $post_excerpt = '';
	public int $post_parent = 0;

	/** @param array<string,mixed> $values */
	public function __construct( array $values ) {
		foreach ( $values as $key => $value ) {
			$this->{$key} = $value;
		}
	}
}

$GLOBALS['devenia_card_inventory_filters'] = array();
$GLOBALS['devenia_card_inventory_posts'] = array();
$GLOBALS['devenia_card_inventory_translation_map'] = array();
$GLOBALS['devenia_card_inventory_blocks'] = array();

function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['devenia_card_inventory_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function apply_filters( string $hook, $value, ...$arguments ) {
	$filters = $GLOBALS['devenia_card_inventory_filters'][ $hook ] ?? array();
	ksort( $filters );
	foreach ( $filters as $callbacks ) {
		foreach ( $callbacks as list( $callback, $accepted_args ) ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $arguments ), 0, $accepted_args ) );
		}
	}
	return $value;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function sanitize_key( $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ?? '' );
}

function wp_strip_all_tags( $value ): string {
	return strip_tags( (string) $value );
}

function parse_blocks( $content ): array {
	unset( $content );
	return $GLOBALS['devenia_card_inventory_blocks'];
}

function get_post( $post_id ) {
	return $GLOBALS['devenia_card_inventory_posts'][ absint( $post_id ) ] ?? null;
}

function get_posts( array $arguments ): array {
	$ids = array();
	$post_types = array_map( 'strval', (array) ( $arguments['post_type'] ?? array( 'post' ) ) );
	foreach ( $GLOBALS['devenia_card_inventory_posts'] as $post ) {
		if (
			$post instanceof WP_Post
			&& in_array( $post->post_type, $post_types, true )
			&& 'publish' === $post->post_status
			&& (int) ( $arguments['post_parent'] ?? 0 ) === $post->post_parent
		) {
			$ids[] = $post->ID;
		}
	}
	sort( $ids, SORT_NUMERIC );
	return $ids;
}

require_once dirname( __DIR__ ) . '/includes/trait-translation-job.php';
require_once dirname( __DIR__, 2 ) . '/mcp-abilities-generatepress/includes/class-generateblocks-card-projection.php';

final class Devenia_Workflow_Dynamic_Card_Inventory_Runtime_Test {
	use Devenia_Workflow_Translation_Job;

	/** @return array<string,mixed> */
	public static function validate( WP_Post $source, string $language ): array {
		return self::translation_job_dynamic_inventory_policy( $source, $language );
	}

	private static function normalize_gutenberg_content_for_storage( string $content ): string {
		return $content;
	}

	/** @return array<int,int> */
	private static function batch_translation_index_ids( array $source_ids, string $language, array $post_status = array() ): array {
		unset( $post_status );
		$map = $GLOBALS['devenia_card_inventory_translation_map'][ $language ] ?? array();
		return array_intersect_key( $map, array_flip( array_map( 'absint', $source_ids ) ) );
	}

	private static function find_translation_id( int $source_id, string $language, array $post_status = array() ): int {
		unset( $post_status );
		return absint( $GLOBALS['devenia_card_inventory_translation_map'][ $language ][ $source_id ] ?? 0 );
	}

	/** @return array<int,string> */
	private static function translation_workflow_post_statuses( bool $include_future = true ): array {
		unset( $include_future );
		return array( 'publish' );
	}
}

function devenia_card_inventory_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$summary_block = array(
	'blockName' => 'generateblocks/text',
	'attrs' => array( 'htmlAttributes' => array( 'data-devenia-card-summary' => 'explicit', 'data-devenia-card-summary-max' => '120' ) ),
	'innerHTML' => '<p>{{post_excerpt}}</p>',
);
$action_block = array(
	'blockName' => 'generateblocks/text',
	'attrs' => array( 'htmlAttributes' => array( 'data-devenia-card-action' => 'plugin-details', 'href' => '{{post_permalink}}', 'aria-label' => 'See if {{post_title}} fits your site' ) ),
	'innerHTML' => '<a href="{{post_permalink}}">See if it fits →</a>',
);
$GLOBALS['devenia_card_inventory_blocks'] = array(
	array(
		'blockName' => 'generateblocks/query',
		'attrs' => array(
			'query' => array( 'post_type' => array( 'page' ), 'posts_per_page' => -1, 'post_parent__in' => array( 'current' ) ),
			'htmlAttributes' => array( 'data-devenia-card-inventory' => 'plugin-pages', 'data-devenia-card-summary-max' => '120' ),
		),
		'innerBlocks' => array(
			array(
				'blockName' => 'generateblocks/loop-item',
				'attrs' => array(),
				'innerBlocks' => array( $summary_block, $action_block ),
			),
		),
	),
);

$source = new WP_Post( array( 'ID' => 100, 'post_content' => '<!-- dynamic inventory -->' ) );
foreach (
	array(
		$source,
		new WP_Post( array( 'ID' => 101, 'post_parent' => 100 ) ),
		new WP_Post( array( 'ID' => 102, 'post_parent' => 100 ) ),
		new WP_Post( array( 'ID' => 200 ) ),
		new WP_Post( array( 'ID' => 201, 'post_parent' => 200, 'post_excerpt' => 'First useful outcome.' ) ),
		new WP_Post( array( 'ID' => 202, 'post_parent' => 200, 'post_excerpt' => 'Second useful outcome.' ) ),
	) as $post
) {
	$GLOBALS['devenia_card_inventory_posts'][ $post->ID ] = $post;
}
$GLOBALS['devenia_card_inventory_translation_map']['nb'] = array( 100 => 200, 101 => 201, 102 => 202 );

MCP_Abilities_GeneratePress_GenerateBlocks_Card_Projection::register();

$valid = Devenia_Workflow_Dynamic_Card_Inventory_Runtime_Test::validate( $source, 'nb' );
devenia_card_inventory_assert( true === (bool) ( $valid['success'] ?? false ), 'Complete translated child inventory was rejected.' );

$GLOBALS['devenia_card_inventory_blocks'][0]['attrs']['query']['post_type'] = array( 'product' );
foreach ( array( 101, 102, 201, 202 ) as $product_id ) {
	$GLOBALS['devenia_card_inventory_posts'][ $product_id ]->post_type = 'product';
}
$custom_type = Devenia_Workflow_Dynamic_Card_Inventory_Runtime_Test::validate( $source, 'nb' );
devenia_card_inventory_assert( true === (bool) ( $custom_type['success'] ?? false ), 'Declared hierarchical custom content inventory was rejected.' );
$GLOBALS['devenia_card_inventory_blocks'][0]['attrs']['query']['post_type'] = array( 'page' );
foreach ( array( 101, 102, 201, 202 ) as $page_id ) {
	$GLOBALS['devenia_card_inventory_posts'][ $page_id ]->post_type = 'page';
}

$GLOBALS['devenia_card_inventory_posts'][202]->post_parent = 0;
$missing = Devenia_Workflow_Dynamic_Card_Inventory_Runtime_Test::validate( $source, 'nb' );
devenia_card_inventory_assert(
	false === (bool) ( $missing['success'] ?? true )
	&& 'dynamic_inventory_incomplete' === (string) ( $missing['code'] ?? '' )
	&& array( 202 ) === (array) ( $missing['validation']['validation']['missing_ids'] ?? array() ),
	'A translated child outside the localized overview was not rejected.'
);

$GLOBALS['devenia_card_inventory_posts'][202]->post_parent = 200;
$GLOBALS['devenia_card_inventory_posts'][202]->post_excerpt = '';
$empty = Devenia_Workflow_Dynamic_Card_Inventory_Runtime_Test::validate( $source, 'nb' );
devenia_card_inventory_assert(
	false === (bool) ( $empty['success'] ?? true )
	&& array( 202 ) === (array) ( $empty['validation']['validation']['empty_excerpt_ids'] ?? array() ),
	'Localized child without an explicit card summary was not rejected.'
);

$GLOBALS['devenia_card_inventory_posts'][202]->post_excerpt = str_repeat( 'x', 121 );
$long = Devenia_Workflow_Dynamic_Card_Inventory_Runtime_Test::validate( $source, 'nb' );
devenia_card_inventory_assert(
	false === (bool) ( $long['success'] ?? true )
	&& array( 202 ) === (array) ( $long['validation']['validation']['long_excerpt_ids'] ?? array() ),
	'Localized child with an overlong card summary was not rejected.'
);

$GLOBALS['devenia_card_inventory_posts'][202]->post_excerpt = 'Second useful outcome.';
unset( $GLOBALS['devenia_card_inventory_translation_map']['nb'][102] );
$untranslated = Devenia_Workflow_Dynamic_Card_Inventory_Runtime_Test::validate( $source, 'nb' );
devenia_card_inventory_assert(
	false === (bool) ( $untranslated['success'] ?? true )
	&& array( 102 ) === (array) ( $untranslated['missing_source_ids'] ?? array() ),
	'A source child without a published localized counterpart was not rejected.'
);

$GLOBALS['devenia_card_inventory_corrupt_contract_mode'] = 'row';
add_filter(
	'devenia_workflow_dynamic_inventory_contracts',
	static function ( $contracts ) {
		if ( 'nonarray' === $GLOBALS['devenia_card_inventory_corrupt_contract_mode'] ) {
			return null;
		}
		return 'unknown' === $GLOBALS['devenia_card_inventory_corrupt_contract_mode']
			? array( array( 'type' => 'translated_direct_child_typo' ) )
			: array( array() );
	},
	20,
	1
);
$malformed_row = Devenia_Workflow_Dynamic_Card_Inventory_Runtime_Test::validate( $source, 'nb' );
devenia_card_inventory_assert( false === (bool) ( $malformed_row['success'] ?? true ) && 'dynamic_inventory_contract_invalid' === (string) ( $malformed_row['code'] ?? '' ), 'Malformed Adapter contract row failed open.' );
$GLOBALS['devenia_card_inventory_corrupt_contract_mode'] = 'nonarray';
$non_array_contracts = Devenia_Workflow_Dynamic_Card_Inventory_Runtime_Test::validate( $source, 'nb' );
devenia_card_inventory_assert( false === (bool) ( $non_array_contracts['success'] ?? true ) && 'dynamic_inventory_contract_invalid' === (string) ( $non_array_contracts['code'] ?? '' ), 'Non-array Adapter discovery failed open.' );
$GLOBALS['devenia_card_inventory_corrupt_contract_mode'] = 'unknown';
$unknown_contract = Devenia_Workflow_Dynamic_Card_Inventory_Runtime_Test::validate( $source, 'nb' );
devenia_card_inventory_assert( false === (bool) ( $unknown_contract['success'] ?? true ) && 'dynamic_inventory_contract_invalid' === (string) ( $unknown_contract['code'] ?? '' ), 'Unsupported dynamic inventory contract type failed open.' );

echo "Dynamic card inventory runtime OK\n";
