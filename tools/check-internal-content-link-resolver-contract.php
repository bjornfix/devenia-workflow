<?php
/**
 * Dependency-light contract for canonical localized content-link resolution.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['resolver_posts'] = array( 1812 => true, 6760 => true, 25186 => true );
$GLOBALS['resolver_core_urls'] = array(
	'https://devenia.test/seo/' => 1812,
);

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( string $path = '/' ): string {
	return 'https://devenia.test/' . ltrim( $path, '/' );
}

function url_to_postid( string $url ): int {
	return (int) ( $GLOBALS['resolver_core_urls'][ $url ] ?? 0 );
}

function get_post( int $post_id ) {
	return ! empty( $GLOBALS['resolver_posts'][ $post_id ] ) ? (object) array( 'ID' => $post_id ) : null;
}

require_once dirname( __DIR__ ) . '/includes/trait-internal-content-link-resolver.php';

final class Devenia_Workflow_Internal_Content_Link_Resolver_Contract {
	use Devenia_Workflow_Internal_Content_Link_Resolver;

	private static function normalized_url_path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? '/' . trim( $path, '/' ) . '/' : '';
	}

	private static function wordpress_content_query_id_from_parts( array $parts ): int {
		if ( empty( $parts['query'] ) ) {
			return 0;
		}
		parse_str( (string) $parts['query'], $params );
		return (int) ( $params['p'] ?? $params['page_id'] ?? $params['post_id'] ?? 0 );
	}

	private static function find_translation_id_by_target_path( string $path, array $post_status ): int {
		return '/ar/almudawana/taswiq-almuhtawa-wa-tahsin-albahth/' === $path && array( 'publish' ) === $post_status ? 25186 : 0;
	}

	public static function resolve( string $url ): int {
		return self::wordpress_content_id_from_internal_url( $url );
	}
}

$cases = array(
	'core_permalink' => array( 'https://devenia.test/seo/', 1812 ),
	'localized_absolute' => array( 'https://devenia.test/ar/almudawana/taswiq-almuhtawa-wa-tahsin-albahth/', 25186 ),
	'localized_relative' => array( '/ar/almudawana/taswiq-almuhtawa-wa-tahsin-albahth/', 25186 ),
	'query_shortlink' => array( 'https://devenia.test/?p=6760', 6760 ),
	'external_url' => array( 'https://example.test/ar/almudawana/taswiq-almuhtawa-wa-tahsin-albahth/', 0 ),
	'missing_internal' => array( 'https://devenia.test/missing/', 0 ),
);
$failures = array();
foreach ( $cases as $case => $values ) {
	$actual = Devenia_Workflow_Internal_Content_Link_Resolver_Contract::resolve( $values[0] );
	if ( $actual !== $values[1] ) {
		$failures[] = array( 'case' => $case, 'expected' => $values[1], 'actual' => $actual );
	}
}

if ( $failures ) {
	fwrite( STDERR, json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo json_encode( array( 'success' => true, 'cases' => count( $cases ) ), JSON_PRETTY_PRINT ) . PHP_EOL;
