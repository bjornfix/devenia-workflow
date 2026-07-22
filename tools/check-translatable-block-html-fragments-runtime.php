<?php
/**
 * Standalone contract for adapter-owned translatable HTML fragments.
 *
 * @package Devenia_Workflow
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['devenia_fragment_filters'] = array();

function add_filter( string $name, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['devenia_fragment_filters'][ $name ][ $priority ][] = array( $callback, $accepted_args );
}

function apply_filters( string $name, $value, ...$args ) {
	$callbacks = $GLOBALS['devenia_fragment_filters'][ $name ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $at_priority ) {
		foreach ( $at_priority as list( $callback, $accepted_args ) ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}
	return $value;
}

function wp_strip_all_tags( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function strip_shortcodes( $value ): string {
	return (string) $value;
}

function wp_kses_post( $value ): string {
	return (string) $value;
}

function esc_html( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_attr( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function absint( $value ): int {
	return abs( (int) $value );
}

require_once dirname( __DIR__ ) . '/includes/trait-source-design-inheritance.php';
require_once dirname( dirname( __DIR__ ) ) . '/mcp-abilities-generatepress/includes/class-generateblocks-card-projection.php';

MCP_Abilities_GeneratePress_GenerateBlocks_Card_Projection::register();

final class Devenia_Workflow_Translatable_Block_HTML_Runtime_Test {
	use Devenia_Workflow_Translation_Source_Design_Inheritance;

	/** @return array<int,array<string,mixed>> */
	public static function collect( array $blocks ): array {
		$fragments = array();
		self::collect_source_design_fragments( $blocks, $fragments );
		return $fragments;
	}

	/** @param array<string,string> $localized */
	public static function project( array $blocks, array $localized ): array {
		$stats = array( 'projected_count' => 0 );
		self::project_source_design_blocks( $blocks, $localized, $stats );
		return array( 'blocks' => $blocks, 'stats' => $stats );
	}

	public static function signature( array $blocks ): array {
		return self::source_design_signature( $blocks );
	}

	private static function copy_quality_text_block_names(): array {
		return array( 'core/paragraph' );
	}

	private static function is_heading_block( string $name, array $attrs ): bool {
		unset( $name, $attrs );
		return false;
	}

	private static function normalize_review_text( string $text ): string {
		$text = preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return trim( is_string( $text ) ? $text : '' );
	}
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$source = array(
	array(
		'blockName'    => 'generateblocks/text',
		'attrs'        => array(
			'tagName'        => 'a',
			'htmlAttributes' => array(
				'href'                     => '{{post_permalink}}',
				'aria-label'               => 'View {{post_title}} plugin details',
				'data-devenia-card-action' => 'plugin-details',
			),
		),
		'innerHTML'    => '<a class="gb-text" href="{{post_permalink}}" aria-label="View {{post_title}} plugin details" data-devenia-card-action="plugin-details">View plugin →</a>',
		'innerContent' => array( '<a class="gb-text" href="{{post_permalink}}" aria-label="View {{post_title}} plugin details" data-devenia-card-action="plugin-details">View plugin →</a>' ),
		'innerBlocks'  => array(),
	),
);

$fragments = Devenia_Workflow_Translatable_Block_HTML_Runtime_Test::collect( $source );
$assert( 2 === count( $fragments ), 'Visible action and accessible name must both enter the Translation Artifact.' );

$localized = array();
foreach ( $fragments as $fragment ) {
	$role = (string) ( $fragment['role'] ?? '' );
	if ( 'devenia_generateblocks_card_action' === $role ) {
		$localized[ (string) $fragment['key'] ] = 'See what it solves →';
	} elseif ( 'devenia_generateblocks_card_accessible_name' === $role ) {
		$localized[ (string) $fragment['key'] ] = 'See how "{{post_title}}" solves the problem';
	}
}

$projection = Devenia_Workflow_Translatable_Block_HTML_Runtime_Test::project( $source, $localized );
$html       = (string) ( $projection['blocks'][0]['innerHTML'] ?? '' );
$assert( 2 === (int) ( $projection['stats']['projected_count'] ?? 0 ), 'Both adapter-owned fragments must project through the shared Interface.' );
$assert( false !== strpos( $html, '>See what it solves →</a>' ), 'Visible card action was not localized.' );
$assert( false !== strpos( $html, 'aria-label="See how &quot;{{post_title}}&quot; solves the problem"' ), 'Accessible card action was not escaped and localized in attribute context.' );
$assert( 'See how "{{post_title}}" solves the problem' === (string) ( $projection['blocks'][0]['attrs']['htmlAttributes']['aria-label'] ?? '' ), 'Native GenerateBlocks aria-label attribute did not retain its raw plain-text value.' );
$assert( false !== strpos( $html, 'href="{{post_permalink}}"' ), 'Permalink token changed during projection.' );
$source_signature    = Devenia_Workflow_Translatable_Block_HTML_Runtime_Test::signature( $source );
$localized_signature = Devenia_Workflow_Translatable_Block_HTML_Runtime_Test::signature( $projection['blocks'] );
$assert( $source_signature === $localized_signature, 'Localized copy changed the source-owned design signature: ' . json_encode( array( $source_signature, $localized_signature ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

$GLOBALS['devenia_fragment_corrupt_mode'] = 'structured';
add_filter( 'devenia_workflow_structured_text_attr_fragments', static function ( $fragments ) { return 'structured' === $GLOBALS['devenia_fragment_corrupt_mode'] ? null : $fragments; }, 20, 1 );
add_filter( 'devenia_workflow_translatable_block_html_fragments', static function ( $fragments ) { return 'html' === $GLOBALS['devenia_fragment_corrupt_mode'] ? null : $fragments; }, 20, 1 );
foreach ( array( 'structured', 'html' ) as $corrupt_mode ) {
	$GLOBALS['devenia_fragment_corrupt_mode'] = $corrupt_mode;
	$failed_closed = false;
	try {
		Devenia_Workflow_Translatable_Block_HTML_Runtime_Test::collect( $source );
	} catch ( UnexpectedValueException $error ) {
		$failed_closed = true;
	}
	$assert( $failed_closed, 'Malformed ' . $corrupt_mode . ' fragment Adapter response failed open.' );
}

echo "Translatable block HTML fragment runtime OK\n";
