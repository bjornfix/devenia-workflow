<?php
/** Deterministic contract for host-bound staged-preview capabilities. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? ''; }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function absint( $value ): int { return abs( (int) $value ); }
function wp_salt( string $scheme = 'auth' ): string { return 'preview-runtime-' . $scheme; }

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

echo "Staged Preview Capability host-binding runtime passed.\n";
