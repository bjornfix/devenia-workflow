<?php
/** Dependency-light behavior contract for submitted Translation Artifact fragment values. */

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Post {
	public int $ID;

	public function __construct( int $id ) {
		$this->ID = $id;
	}
}

function get_post( int $post_id ) {
	return 848 === $post_id ? new WP_Post( $post_id ) : null;
}

function strip_shortcodes( string $value ): string {
	return $value;
}

function wp_strip_all_tags( string $value ): string {
	return strip_tags( $value );
}

function apply_filters( string $hook, $value, ...$args ) {
	unset( $hook, $args );
	return $value;
}

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? '';
}

require_once dirname( __DIR__ ) . '/includes/trait-translation-job.php';

final class Devenia_Workflow_Translation_Artifact_Fragment_Value_Contract {
	use Devenia_Workflow_Translation_Job;

	private static function source_design_contract( WP_Post $source ): array {
		unset( $source );
		return array(
			'fragments' => array(
				array( 'key' => 'faq-question', 'source_html' => 'What should the page answer?' ),
				array( 'key' => 'body-copy', 'source_html' => '<p>Useful source copy.</p>' ),
			),
		);
	}

	public static function coverage( array $localized_fragments ): array {
		return self::translation_job_fragment_coverage(
			array( 'source_id' => 848 ),
			$localized_fragments
		);
	}

	public static function artifact_schema(): array {
		return self::translation_job_artifact_schema();
	}
}

$valid = array(
	array( 'key' => 'faq-question', 'text' => 'Hva bør siden svare på?' ),
	array( 'key' => 'body-copy', 'html' => '<p>Nyttig tekst for leseren.</p>' ),
);

$cases = array(
	'valid_complete_artifact' => array( $valid, true, '' ),
	'literal_undefined' => array(
		array_replace( $valid, array( 0 => array( 'key' => 'faq-question', 'text' => 'undefined' ) ) ),
		false,
		'artifact_fragment_value_invalid',
	),
	'markup_wrapped_undefined' => array(
		array_replace( $valid, array( 1 => array( 'key' => 'body-copy', 'html' => '<p> undefined </p>' ) ) ),
		false,
		'artifact_fragment_value_invalid',
	),
	'empty_nonempty_source' => array(
		array_replace( $valid, array( 0 => array( 'key' => 'faq-question', 'text' => '   ' ) ) ),
		false,
		'artifact_fragment_value_invalid',
	),
	'missing_value_field' => array(
		array_replace( $valid, array( 0 => array( 'key' => 'faq-question' ) ) ),
		false,
		'artifact_fragment_value_invalid',
	),
	'ambiguous_text_and_html' => array(
		array_replace( $valid, array( 0 => array( 'key' => 'faq-question', 'text' => 'Spørsmål', 'html' => '<p>Spørsmål</p>' ) ) ),
		false,
		'artifact_fragment_value_invalid',
	),
	'ordinary_word_containing_sentinel' => array(
		array_replace( $valid, array( 1 => array( 'key' => 'body-copy', 'text' => 'Undefined behavior is a technical term in this sentence.' ) ) ),
		true,
		'',
	),
);

$failures = array();
$schema = Devenia_Workflow_Translation_Artifact_Fragment_Value_Contract::artifact_schema();
$fragment_item_schema = $schema['properties']['artifact']['properties']['localized_fragments']['items'] ?? array();
if ( 2 !== count( (array) ( $fragment_item_schema['oneOf'] ?? array() ) ) ) {
	$failures[] = array( 'case' => 'schema_requires_exactly_one_value_field' );
}
foreach ( $cases as $name => $case ) {
	list( $fragments, $expected_success, $expected_code ) = $case;
	$result = Devenia_Workflow_Translation_Artifact_Fragment_Value_Contract::coverage( $fragments );
	$actual_success = ! empty( $result['success'] );
	$actual_code = (string) ( $result['code'] ?? '' );
	if ( $expected_success !== $actual_success || $expected_code !== $actual_code ) {
		$failures[] = array(
			'case' => $name,
			'expected_success' => $expected_success,
			'actual_success' => $actual_success,
			'expected_code' => $expected_code,
			'actual_code' => $actual_code,
		);
	}
}

if ( $failures ) {
	fwrite( STDERR, json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo json_encode( array( 'success' => true, 'cases' => count( $cases ) ), JSON_PRETTY_PRINT ) . PHP_EOL;
