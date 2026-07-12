<?php
/**
 * Dependency-light contract for builder-aware source editor selection.
 */

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Post {
	public int $ID;
	public string $post_type;

	public function __construct( int $id, string $post_type ) {
		$this->ID        = $id;
		$this->post_type = $post_type;
	}
}

$test_filters = array();
$test_meta    = array();

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $test_filters;
	$test_filters[ $hook ][] = $callback;
}

function apply_filters( string $hook, $value, ...$args ) {
	global $test_filters;
	foreach ( $test_filters[ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function add_action(): void {}
function did_action(): int { return 1; }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function get_post_meta( int $post_id, string $key ) {
	global $test_meta;
	return $test_meta[ $post_id ][ $key ] ?? '';
}
function mcp_abilities_elementor_load_document(): array { return array( 'success' => true ); }

require_once dirname( __DIR__ ) . '/includes/trait-source-editor-adapter.php';
require_once dirname( __DIR__ ) . '/addons/elementor.php';

final class Devenia_AI_Translations_Source_Editor_Contract_Test {
	use Devenia_AI_Translations_Source_Editor_Adapter;

	public static function contract( WP_Post $source ): array {
		return self::source_editor_contract( $source );
	}
}

$failures = array();
$page     = new WP_Post( 10, 'page' );
$default  = Devenia_AI_Translations_Source_Editor_Contract_Test::contract( $page );
if ( 'wordpress' !== ( $default['editor'] ?? '' ) || 'content/update-page' !== ( $default['content_write_ability'] ?? '' ) ) {
	$failures[] = array( 'case' => 'wordpress_page_adapter', 'actual' => $default );
}

$test_meta[10]['_elementor_edit_mode'] = 'builder';
$test_meta[10]['_elementor_data']      = '[{"id":"hero"}]';
AI_Translation_Workflow_Elementor_Addon::maybe_register_hooks();
$elementor = Devenia_AI_Translations_Source_Editor_Contract_Test::contract( $page );
if ( 'elementor' !== ( $elementor['editor'] ?? '' ) || 'elementor/get-data' !== ( $elementor['read_ability'] ?? '' ) ) {
	$failures[] = array( 'case' => 'elementor_adapter_selected', 'actual' => $elementor );
}
if ( 'elementor/merge-element-settings' !== ( $elementor['content_write_ability'] ?? '' ) ) {
	$failures[] = array( 'case' => 'elementor_native_write', 'actual' => $elementor );
}
if ( empty( $elementor['native_controls_only'] ) || empty( $elementor['public_route_immutable'] ) ) {
	$failures[] = array( 'case' => 'elementor_guardrails', 'actual' => $elementor );
}

if ( $failures ) {
	fwrite( STDERR, json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo json_encode( array( 'success' => true, 'cases' => 4 ), JSON_PRETTY_PRINT ) . PHP_EOL;
