<?php
/** Deterministic contract for host-bound staged-preview capabilities. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? ''; }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function absint( $value ): int { return abs( (int) $value ); }
function wp_salt( string $scheme = 'auth' ): string { return 'preview-runtime-' . $scheme; }

$GLOBALS['staged_preview_removed_actions'] = array();
$GLOBALS['staged_preview_status_headers'] = array();
function remove_action( string $hook, $callback, int $priority = 10 ): bool {
	$GLOBALS['staged_preview_removed_actions'][] = array( $hook, $callback, $priority );
	return true;
}
function status_header( int $code ): void { $GLOBALS['staged_preview_status_headers'][] = $code; }

final class Staged_Preview_Query_Runtime {
	public bool $is_404 = false;
	public function set_404(): void { $this->is_404 = true; }
}
$GLOBALS['wp_query'] = new Staged_Preview_Query_Runtime();

require_once dirname( __DIR__ ) . '/includes/trait-staged-preview-capability.php';

final class Devenia_Workflow_Staged_Preview_Capability_Runtime_Test {
	use Devenia_Workflow_Staged_Preview_Capability;

	public static function issue( string $host_identity ): string {
		return self::staged_preview_capability_token( 'translation', 'job-1', 'run-1', 'artifact-1', 2000000000, 'claim-hash', $host_identity );
	}

	/** @return array<string,mixed> */
	public static function parse( string $token ): array {
		return self::staged_preview_capability_parts( $token, 'translation' );
	}

	public static function apply_response_policy( bool $authorized ): void {
		self::staged_preview_apply_response_policy( $authorized );
	}
}

$source_host = 'canonical_source_theme_shell:15001';
$translation_host = 'existing_translation:25001';
$source_token = Devenia_Workflow_Staged_Preview_Capability_Runtime_Test::issue( $source_host );
$translation_token = Devenia_Workflow_Staged_Preview_Capability_Runtime_Test::issue( $translation_host );
$parts = Devenia_Workflow_Staged_Preview_Capability_Runtime_Test::parse( $source_token );

if (
	hash_equals( $source_token, $translation_token )
	|| $source_host !== (string) ( $parts['host_identity'] ?? '' )
) {
	throw new RuntimeException( 'A staged-preview capability did not bind the resolved preview host identity.' );
}

Devenia_Workflow_Staged_Preview_Capability_Runtime_Test::apply_response_policy( false );
if (
	array() !== $GLOBALS['staged_preview_removed_actions']
	|| array( 404 ) !== $GLOBALS['staged_preview_status_headers']
	|| ! $GLOBALS['wp_query']->is_404
) {
	throw new RuntimeException( 'A denied staged preview did not fail closed while preserving WordPress canonical redirects.' );
}

$GLOBALS['staged_preview_status_headers'] = array();
$GLOBALS['wp_query']->is_404 = false;
Devenia_Workflow_Staged_Preview_Capability_Runtime_Test::apply_response_policy( true );
if ( array( array( 'template_redirect', 'redirect_canonical', 10 ) ) !== $GLOBALS['staged_preview_removed_actions'] ) {
	throw new RuntimeException( 'An authorized staged preview did not disable only the WordPress canonical redirect at its native priority.' );
}
if ( array() !== $GLOBALS['staged_preview_status_headers'] || $GLOBALS['wp_query']->is_404 ) {
	throw new RuntimeException( 'An authorized staged preview was incorrectly marked not found.' );
}

echo "Staged Preview Capability host-binding and canonical-redirect runtime passed.\n";
