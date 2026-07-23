<?php
/**
 * WordPress runtime for exact staged-preview content fidelity.
 *
 * The public Staged Preview Capability must render the immutable artifact even
 * after the canonical source has been requested. The fixture is temporary and
 * every fixture-owned persisted value is removed in the finally block.
 *
 * Run with: wp eval-file tools/check-staged-preview-render-fidelity-wordpress-runtime.php
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$fixture_suffix = strtolower( wp_generate_password( 10, false, false ) );
$job_id         = 'srj_preview_fidelity_' . $fixture_suffix;
$run_id         = 'r_preview_fidelity_' . $fixture_suffix;
$artifact_id    = 'sra_preview_fidelity_' . $fixture_suffix;
$claim_secret   = wp_generate_password( 48, false, false );
$expires        = time() + 300;
$source_id      = 0;
$missing_option = new stdClass();

$canonical_marker = 'canonical-preview-fidelity-' . $fixture_suffix;
$staged_marker    = 'staged-preview-fidelity-' . $fixture_suffix;
$canonical_id     = 'canonical-' . $fixture_suffix;
$staged_grid_id   = 'staged-grid-' . $fixture_suffix;
$canonical_content = '<!-- wp:paragraph --><p>' . esc_html( $canonical_marker ) . '</p><!-- /wp:paragraph -->';
$staged_content = '<!-- wp:generateblocks/grid {"uniqueId":"' . $staged_grid_id . '","blockVersion":3,"horizontalGap":24,"horizontalGapTablet":24,"horizontalGapMobile":0} -->'
	. '<!-- wp:generateblocks/container {"uniqueId":"staged-left-' . $fixture_suffix . '","isGrid":true,"blockVersion":4,"sizing":{"width":"66%","widthTablet":"66%","widthMobile":"100%"}} -->'
	. '<!-- wp:paragraph --><p>' . esc_html( $staged_marker ) . '</p><!-- /wp:paragraph --><!-- /wp:generateblocks/container -->'
	. '<!-- wp:generateblocks/container {"uniqueId":"staged-right-' . $fixture_suffix . '","isGrid":true,"blockVersion":4,"sizing":{"width":"34%","widthTablet":"34%","widthMobile":"100%"}} -->'
	. '<!-- wp:paragraph --><p>Second staged column.</p><!-- /wp:paragraph --><!-- /wp:generateblocks/container -->'
	. '<!-- /wp:generateblocks/grid -->';

$option_keys = array(
	'devenia_workflow_source_rewrite_job_' . $job_id,
	'devenia_workflow_source_rewrite_run_' . $run_id,
	'devenia_workflow_source_rewrite_claim_' . $job_id,
	'devenia_workflow_source_rewrite_artifact_' . $artifact_id,
);

try {
	$source_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Staged preview fidelity fixture ' . $fixture_suffix,
			'post_name'    => 'staged-preview-fidelity-' . $fixture_suffix,
			'post_content' => $canonical_content,
		),
		true
	);
	if ( is_wp_error( $source_id ) || 1 > (int) $source_id ) {
		throw new RuntimeException( 'Could not create the staged-preview fidelity fixture.' );
	}
	$source_id = (int) $source_id;

	$claim = array(
		'job_id'                => $job_id,
		'run_id'                => $run_id,
		'coordinator_id'        => 'runtime-preview-fidelity',
		'role'                  => 'quality',
		'previous_status'       => 'quality_pending',
		'submission_generation' => 1,
		'token_hash'            => hash( 'sha256', $claim_secret ),
		'claimed_at'            => gmdate( 'c' ),
		'expires_at'            => gmdate( 'c', $expires ),
	);
	$job = array(
		'job_id'                => $job_id,
		'source_id'             => $source_id,
		'post_type'             => 'page',
		'submission_generation' => 1,
		'status'                => 'quality_claimed',
		'artifact_revision'     => $artifact_id,
		'active_run_id'         => $run_id,
	);
	$run = array(
		'run_id' => $run_id,
		'job_id' => $job_id,
		'role'   => 'quality',
		'status' => 'running',
	);
	$artifact = array(
		'artifact_revision' => $artifact_id,
		'proposed'          => array(
			'title'   => 'Exact staged preview title',
			'excerpt' => 'Exact staged preview excerpt.',
			'content' => $staged_content,
		),
	);

	foreach ( array( $job, $run, $claim, $artifact ) as $index => $value ) {
		if ( ! add_option( $option_keys[ $index ], $value, '', false ) ) {
			throw new RuntimeException( 'Could not create staged-preview authority fixture option ' . $index . '.' );
		}
	}

	$token_method = new ReflectionMethod( Devenia_Workflow::class, 'staged_preview_capability_token' );
	$token_method->setAccessible( true );
	$token = (string) $token_method->invoke(
		null,
		'source',
		$job_id,
		$run_id,
		$artifact_id,
		$expires,
		(string) $claim['token_hash'],
		'canonical_source_theme_shell:' . $source_id
	);

	$canonical_url = add_query_arg( 'page_id', (string) $source_id, home_url( '/' ) );
	$preview_url   = add_query_arg( 'devenia_source_rewrite_preview', $token, $canonical_url );
	$http_args     = array(
		'timeout'     => 20,
		'redirection' => 0,
		'headers'     => array( 'User-Agent' => 'Devenia-Workflow-Staged-Preview-Fidelity-Runtime/1.0' ),
	);

	// Warm the canonical source before exercising the capability URL.
	wp_remote_get( get_permalink( $source_id ), $http_args );
	$registry_after_warm = get_option( 'generateblocks_dynamic_css_posts', $missing_option );
	$time_after_warm = get_option( 'generateblocks_dynamic_css_time', $missing_option );
	$meta_after_warm = get_post_meta( $source_id, '_generateblocks_dynamic_css_version', true );
	$css_path = function_exists( 'mcp_abilities_generatepress_generateblocks_css_path' ) ? mcp_abilities_generatepress_generateblocks_css_path( $source_id ) : '';
	$css_after_warm = is_string( $css_path ) && file_exists( $css_path )
		? array( 'exists' => true, 'sha256' => hash_file( 'sha256', $css_path ), 'mtime' => filemtime( $css_path ) )
		: array( 'exists' => false, 'sha256' => '', 'mtime' => 0 );

	$exact_response  = wp_remote_get( $preview_url, $http_args );
	$bypass_response = wp_remote_get( add_query_arg( 'cloudflare_bypass', '1', $preview_url ), $http_args );
	$tampered_token = substr( $token, 0, -1 ) . ( 'a' === substr( $token, -1 ) ? 'b' : 'a' );
	$tampered_response = wp_remote_get( add_query_arg( 'devenia_source_rewrite_preview', $tampered_token, $canonical_url ), $http_args );
	$mixed_response = wp_remote_get( add_query_arg( 'devenia_translation_artifact_preview', 'foreign-translation-capability', $preview_url ), $http_args );
	if ( is_wp_error( $exact_response ) || is_wp_error( $bypass_response ) || is_wp_error( $tampered_response ) || is_wp_error( $mixed_response ) ) {
		throw new RuntimeException( 'The staged-preview HTTP fixture could not be requested.' );
	}

	$measure = static function ( $response ) use ( $canonical_marker, $staged_marker, $canonical_id, $staged_grid_id ): array {
		$body = (string) wp_remote_retrieve_body( $response );
		preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $body, $style_matches );
		$inline_css = html_entity_decode( implode( "\n", (array) ( $style_matches[1] ?? array() ) ), ENT_QUOTES, 'UTF-8' );
		return array(
			'status'           => (int) wp_remote_retrieve_response_code( $response ),
			'sha256'           => hash( 'sha256', $body ),
			'staged_marker'    => false !== strpos( $body, $staged_marker ),
			'canonical_marker' => false !== strpos( $body, $canonical_marker ),
			'column_count'     => substr_count( $body, 'wp-block-column' ),
			'staged_inline_css' => false !== strpos( $inline_css, $staged_grid_id ),
			'canonical_inline_css' => false !== strpos( $inline_css, $canonical_id ),
			'generateblocks_file_link' => (bool) preg_match( '/<link\b[^>]+href=["\'][^"\']*\/generateblocks\/style-[0-9]+\.css/i', $body ),
			'cache_control'    => (string) wp_remote_retrieve_header( $response, 'cache-control' ),
			'location'         => (string) wp_remote_retrieve_header( $response, 'location' ),
		);
	};

	$result = array(
		'exact'     => $measure( $exact_response ),
		'bypass'    => $measure( $bypass_response ),
		'tampered'  => $measure( $tampered_response ),
		'mixed'     => $measure( $mixed_response ),
		'persistent_state_unchanged' => $registry_after_warm === get_option( 'generateblocks_dynamic_css_posts', $missing_option )
			&& $time_after_warm === get_option( 'generateblocks_dynamic_css_time', $missing_option )
			&& $meta_after_warm === get_post_meta( $source_id, '_generateblocks_dynamic_css_version', true )
			&& $css_after_warm === ( is_string( $css_path ) && file_exists( $css_path )
				? array( 'exists' => true, 'sha256' => hash_file( 'sha256', $css_path ), 'mtime' => filemtime( $css_path ) )
				: array( 'exists' => false, 'sha256' => '', 'mtime' => 0 ) ),
	);
	echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

	if (
		200 !== $result['exact']['status']
		|| empty( $result['exact']['staged_marker'] )
		|| ! empty( $result['exact']['canonical_marker'] )
		|| empty( $result['exact']['staged_inline_css'] )
		|| ! empty( $result['exact']['canonical_inline_css'] )
		|| ! empty( $result['exact']['generateblocks_file_link'] )
		|| '' !== $result['exact']['location']
		|| 404 !== $result['tampered']['status']
		|| ! empty( $result['tampered']['staged_marker'] )
		|| ! empty( $result['tampered']['staged_inline_css'] )
		|| 404 !== $result['mixed']['status']
		|| ! empty( $result['mixed']['staged_marker'] )
		|| ! empty( $result['mixed']['staged_inline_css'] )
		|| empty( $result['persistent_state_unchanged'] )
	) {
		throw new RuntimeException( 'The public Staged Preview Capability did not render the exact immutable artifact.' );
	}
} finally {
	foreach ( $option_keys as $option_key ) {
		delete_option( $option_key );
	}
	if ( 0 < $source_id ) {
		if ( function_exists( 'mcp_abilities_generatepress_generateblocks_css_path' ) ) {
			$css_file = mcp_abilities_generatepress_generateblocks_css_path( $source_id );
			if ( is_string( $css_file ) && file_exists( $css_file ) ) {
				wp_delete_file( $css_file );
			}
		}
		wp_delete_post( $source_id, true );
	}
}
