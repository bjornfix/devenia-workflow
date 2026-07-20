<?php
/** Runtime contract for the multilingual artifact storage envelope. */

function wp_json_encode( $value ) {
	return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/trait-wordpress-storage-adapter.php';
require_once __DIR__ . '/../includes/trait-translation-job.php';

final class Devenia_Workflow_Artifact_Storage_Envelope_Test {
	use Devenia_Workflow_WordPress_Storage_Adapter;
	use Devenia_Workflow_Translation_Job;

	public static function pack( array $record ): array {
		return self::translation_job_pack_artifact_record( $record );
	}

	public static function unpack( $record ): array {
		return self::translation_job_unpack_artifact_record( $record );
	}

	public static function storage_safe( $value ) {
		return self::wordpress_utf8mb3_safe_storage_value( $value );
	}
}

$record = array(
	'artifact_revision' => 'a_test',
	'artifact' => array(
		'title' => 'CSS og SEO 🔍',
	),
	'surface_manifest' => array(
		'content' => array(
			'gutenberg' => '<h2>Dette kan CSS gjøre 🎨</h2>',
		),
		'presentation' => array(
			'localized_fragments' => array( '<h2>Hvorfor reglene bygger på hverandre 🌊</h2>' ),
		),
	),
);

$packed = Devenia_Workflow_Artifact_Storage_Envelope_Test::pack( $record );
if ( isset( $packed['artifact'] ) || isset( $packed['surface_manifest'] ) ) {
	throw new RuntimeException( 'Multilingual payloads must not remain directly serialized in the option record.' );
}
$has_non_ascii = preg_match( '/[^\x00-\x7F]/', serialize( $packed ) );
if ( 1 === $has_non_ascii ) {
	throw new RuntimeException( 'The packed storage envelope must be ASCII-safe.' );
}

$unpacked = Devenia_Workflow_Artifact_Storage_Envelope_Test::unpack( $packed );
if (
	$record['artifact_revision'] !== ( $unpacked['artifact_revision'] ?? '' )
	|| $record['artifact'] !== ( $unpacked['artifact'] ?? null )
	|| $record['surface_manifest'] !== ( $unpacked['surface_manifest'] ?? null )
) {
	throw new RuntimeException( 'Artifact and Surface Manifest must round-trip byte-for-byte at the value level.' );
}

$legacy = array(
	'artifact_revision' => 'a_legacy',
	'artifact' => array( 'title' => 'Legacy' ),
	'surface_manifest' => array( 'content' => array( 'gutenberg' => '<p>Legacy</p>' ) ),
);
if ( $legacy !== Devenia_Workflow_Artifact_Storage_Envelope_Test::unpack( $legacy ) ) {
	throw new RuntimeException( 'Previously stored unpacked records must remain readable.' );
}

$corrupt = $packed;
$corrupt['surface_manifest_payload'] = 'not-base64!';
if ( array() !== Devenia_Workflow_Artifact_Storage_Envelope_Test::unpack( $corrupt ) ) {
	throw new RuntimeException( 'A corrupt Surface Manifest envelope must fail closed.' );
}

$storage_safe = Devenia_Workflow_Artifact_Storage_Envelope_Test::storage_safe(
	array( 'html' => '<h2>CSS og SEO 🔍</h2>', 'plain' => 'Tekst', 'number' => 4 )
);
if ( '<h2>CSS og SEO &#x1F50D;</h2>' !== $storage_safe['html'] || 'Tekst' !== $storage_safe['plain'] || 4 !== $storage_safe['number'] ) {
	throw new RuntimeException( 'The WordPress Storage Adapter must encode every supplementary code point without changing ordinary values.' );
}

echo "Translation artifact storage envelope: 5 assertions passed.\n";
