<?php
/** Dependency-light runtime for the early WordPress query lifecycle boundary. */

define( 'ABSPATH', __DIR__ );

final class WP_Query {}

$query_var_calls = 0;

function get_query_var( string $key ) {
	global $query_var_calls;
	++$query_var_calls;
	if ( 'devenia_translation_artifact_preview' !== $key ) {
		throw new RuntimeException( 'Unexpected query variable.' );
	}
	return '';
}

require_once dirname( __DIR__ ) . '/includes/trait-translation-job-quality-authority.php';

final class Devenia_Workflow_Preview_Context_Lifecycle_Harness {
	use Devenia_Workflow_Translation_Job_Quality_Authority;

	public static function active_preview_context(): array {
		$method = new ReflectionMethod( self::class, 'translation_job_active_preview_context' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}
}

$wp_query = null;
$early_context = Devenia_Workflow_Preview_Context_Lifecycle_Harness::active_preview_context();
if ( array() !== $early_context || 0 !== $query_var_calls ) {
	fwrite( STDERR, "Preview context touched query state before WordPress established WP_Query.\n" );
	exit( 1 );
}

$wp_query = new WP_Query();
$normal_context = Devenia_Workflow_Preview_Context_Lifecycle_Harness::active_preview_context();
if ( array() !== $normal_context || 1 !== $query_var_calls ) {
	fwrite( STDERR, "Preview context did not resume normal query-variable inspection after WP_Query was established.\n" );
	exit( 1 );
}

echo "Translation Preview Context lifecycle runtime passed.\n";
