<?php
/**
 * Standalone regression for page sources crossing the Source Design Gate.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Post {
	public int $ID;
	public string $post_type;
	public string $post_modified_gmt = '2026-07-22 00:00:00';
	public string $post_content = '<!-- page source -->';

	/** @param array<string,mixed> $values */
	public function __construct( array $values ) {
		foreach ( $values as $key => $value ) {
			$this->{$key} = $value;
		}
	}
}

$GLOBALS['devenia_page_gate_calls'] = 0;

function apply_filters( string $name, $value, ...$args ) {
	if ( 'devenia_source_content_design_validation' !== $name ) {
		return $value;
	}

	$GLOBALS['devenia_page_gate_calls']++;
	$source = $args[0] ?? null;
	if ( ! $source instanceof WP_Post || 'page' !== $source->post_type ) {
		throw new RuntimeException( 'The Source Design Adapter received the wrong source.' );
	}

	return array(
		'available'   => true,
		'passed'      => false,
		'adapter'     => 'fixture-site-presentation',
		'issue_codes' => array( 'page_inner_total_width_mismatch' ),
	);
}

require_once dirname( __DIR__ ) . '/includes/trait-source-design-inheritance.php';

final class Devenia_Workflow_Page_Source_Design_Gate_Runtime_Test {
	use Devenia_Workflow_Translation_Source_Design_Inheritance;

	/** @return array<string,mixed> */
	public static function validate( WP_Post $source ): array {
		return self::source_editorial_design_validation( $source, $source->post_content );
	}

	private static function normalize_gutenberg_content_for_storage( string $content ): string {
		return $content;
	}

	private static function request_analysis_cache_get( string $namespace, array $parts ) {
		unset( $namespace, $parts );
		return null;
	}

	private static function request_analysis_cache_set( string $namespace, array $parts, array $value ): array {
		unset( $namespace, $parts );
		return $value;
	}
}

$page = new WP_Post( array( 'ID' => 1906, 'post_type' => 'page' ) );
$result = Devenia_Workflow_Page_Source_Design_Gate_Runtime_Test::validate( $page );

if ( 1 !== $GLOBALS['devenia_page_gate_calls'] ) {
	throw new RuntimeException( 'A page source did not cross the registered Source Design Adapter seam exactly once.' );
}
if ( ! empty( $result['passed'] ) || 'fixture-site-presentation' !== (string) ( $result['adapter'] ?? '' ) ) {
	throw new RuntimeException( 'Workflow did not preserve the page Adapter failure.' );
}

fwrite( STDOUT, "Workflow page Source Design Gate runtime passed.\n" );
