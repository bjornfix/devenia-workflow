<?php
/** Real WordPress proof for the owned sharing public Interface Adapter. */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$call = static function ( string $method, ...$arguments ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$codes = static function ( array $issues ): array {
	return array_values( array_filter( array_map( static function ( $issue ): string { return is_array( $issue ) ? (string) ( $issue['code'] ?? '' ) : ''; }, $issues ) ) );
};

$owner_preexisting = function_exists( 'devenia_social_sharing_get_setting' ) || function_exists( 'devenia_social_sharing_get_surface_manifest' );
$inactive_owner_is_not_required = null;
if ( ! $owner_preexisting ) {
	$inactive = $call( 'social_sharing_runtime_presentation_readiness', 'nb', 'post' );
	$inactive_owner_is_not_required = empty( $inactive['required'] ) && ! empty( $inactive['configured'] ) && 'inactive' === (string) ( $inactive['owner_state'] ?? '' );
	$assert( $inactive_owner_is_not_required, 'An inactive sharing owner was treated as required.' );
}

// Conditional owner fixtures model only the documented public Interface.
if ( ! function_exists( 'devenia_social_sharing_get_setting' ) ) {
	function devenia_social_sharing_get_setting( string $key = '' ) {
		$settings = (array) ( $GLOBALS['devenia_social_sharing_fixture_settings'] ?? array() );
		return '' === $key ? $settings : ( $settings[ $key ] ?? null );
	}
}
if ( ! function_exists( 'devenia_social_sharing_get_surface_manifest' ) ) {
	function devenia_social_sharing_get_surface_manifest( $post, ?string $default_heading = null ): array {
		$language = (string) apply_filters( 'devenia_social_sharing_current_language', (string) ( $GLOBALS['devenia_social_sharing_fixture_language'] ?? 'en' ), $post, 'manifest', '' );
		$default_heading = null === $default_heading ? devenia_social_sharing_get_setting( 'heading' ) : $default_heading;
		$default_heading = apply_filters( 'devenia_social_sharing_heading', $default_heading, $post, 'manifest', $language );
		$keys = array(
			'share_text.social_sharing_heading',
			'share_text.social_sharing_accessible_label.email',
			'share_text.social_sharing_network.email',
			'share_text.social_sharing_email_subject',
			'share_text.social_sharing_email_body',
		);
		$applicable_post_types = (array) ( $GLOBALS['devenia_social_sharing_fixture_post_types'] ?? array( 'post' ) );
		if ( null === $post ) {
			return array( 'state' => 'conservative', 'error' => null, 'applicable' => true, 'headings' => array(), 'default_heading' => is_scalar( $default_heading ) ? (string) $default_heading : '', 'default_heading_occurrences' => 0, 'automatic_before' => false, 'automatic_after' => false, 'embedded_count' => 0, 'runtime_text_keys' => $keys, 'applicable_post_types' => $applicable_post_types );
		}
		if ( ! $post instanceof WP_Post ) {
			return array( 'state' => 'error', 'error' => 'invalid_post', 'applicable' => false, 'headings' => array(), 'default_heading' => '', 'default_heading_occurrences' => 0, 'automatic_before' => false, 'automatic_after' => false, 'embedded_count' => 0, 'runtime_text_keys' => array(), 'applicable_post_types' => array() );
		}
		if ( 'page' === (string) $post->post_type ) {
			return array( 'state' => 'ready', 'error' => null, 'applicable' => false, 'headings' => array(), 'default_heading' => is_scalar( $default_heading ) ? (string) $default_heading : '', 'default_heading_occurrences' => 0, 'automatic_before' => false, 'automatic_after' => false, 'embedded_count' => 0, 'runtime_text_keys' => array(), 'applicable_post_types' => array() );
		}
		if ( null === $default_heading || '' === trim( (string) $default_heading ) ) {
			return array( 'state' => 'error', 'error' => 'missing_runtime_text', 'applicable' => false, 'headings' => array(), 'default_heading' => '', 'default_heading_occurrences' => 0, 'automatic_before' => false, 'automatic_after' => false, 'embedded_count' => 0, 'runtime_text_keys' => $keys, 'applicable_post_types' => array() );
		}
		return array( 'state' => 'ready', 'error' => null, 'applicable' => true, 'headings' => array( (string) $default_heading ), 'default_heading' => (string) $default_heading, 'default_heading_occurrences' => 1, 'automatic_before' => false, 'automatic_after' => true, 'embedded_count' => 0, 'runtime_text_keys' => $keys, 'applicable_post_types' => array( (string) $post->post_type ) );
	}
}

$missing_marker = '__devenia_social_sharing_missing_' . wp_generate_password( 10, false, false );
$languages_before = get_option( Devenia_Workflow::OPTION_LANGUAGES, $missing_marker );
$post_ids = array();
$runtime_result = null;
$runtime_error = null;

try {
	$GLOBALS['devenia_social_sharing_fixture_settings'] = array( 'heading' => 'Configured owner heading' );
	$GLOBALS['devenia_social_sharing_fixture_language'] = 'en';
	$GLOBALS['devenia_social_sharing_fixture_post_types'] = array( 'post' );
	$languages = Devenia_Workflow::languages( true );
	$languages['en']['share_text'] = array_merge( (array) ( $languages['en']['share_text'] ?? array() ), array(
		'social_sharing_accessible_label.email' => 'Runtime EN accessible email',
		'social_sharing_network.email' => 'Runtime EN email label',
		'social_sharing_email_subject' => 'Runtime EN email subject',
		'social_sharing_email_body' => 'Runtime EN email body {url}',
	) );
	$languages['nb']['share_text'] = array_merge( (array) ( $languages['nb']['share_text'] ?? array() ), array(
		'scriptless_email_subject_prefix' => 'Runtime NB legacy subject',
		'scriptless_email_body' => 'Runtime NB legacy body {url}',
		'social_sharing_heading' => 'Runtime NB heading',
		'social_sharing_accessible_label.email' => 'Runtime NB accessible email',
		'social_sharing_accessible_label.arbitrary-network' => 'Target positional %42$s',
		'social_sharing_accessible_label.bare-placeholder' => 'Target bare %s',
		'social_sharing_accessible_label.space-width-placeholder' => 'Target space width % 20s',
		'social_sharing_accessible_label.dot-pad-placeholder' => 'Target dot pad %\'.20s',
		'social_sharing_accessible_label.positional-hash-pad-placeholder' => 'Target hash pad %1$\'#20s',
		'social_sharing_accessible_label.leading-zero-position-placeholder' => 'Target leading zero %01$s',
		'social_sharing_accessible_label.many-leading-zero-position-placeholder' => 'Target many leading zeroes %0001$s',
		'social_sharing_accessible_label.invalid-zero-position' => 'Target invalid zero %0$s',
		'social_sharing_accessible_label.invalid-double-zero-position' => 'Target invalid double zero %00$s',
		'social_sharing_accessible_label.ordinary-percent' => 'Target is 99% complete',
		'social_sharing_network.email' => 'Runtime NB email label',
		'social_sharing_email_subject' => 'Runtime NB email subject',
		'social_sharing_email_body' => 'Runtime NB email body {url}',
	) );
	$languages['es']['share_text'] = array_merge( (array) ( $languages['es']['share_text'] ?? array() ), array(
		'social_sharing_accessible_label.email' => 'Runtime ES accessible email',
		'social_sharing_network.email' => 'Runtime ES email label',
		'social_sharing_email_subject' => 'Runtime ES email subject',
		'social_sharing_email_body' => 'Runtime ES email body {url}',
	) );
	unset( $languages['es']['share_text']['social_sharing_heading'] );
	update_option( Devenia_Workflow::OPTION_LANGUAGES, $languages, false );
	Devenia_Workflow::languages( true );

	$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Owned sharing post fixture', 'post_content' => '<p>Fixture.</p>' ), true );
	$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Owned sharing page fixture', 'post_content' => '<p>Fixture.</p>' ), true );
	$assert( ! is_wp_error( $post_id ) && ! is_wp_error( $page_id ), 'Could not create sharing fixture posts.' );
	$post_ids = array( absint( $post_id ), absint( $page_id ) );
	$post = get_post( $post_id );
	$page = get_post( $page_id );
	$assert( 'Configured owner heading' === apply_filters( 'devenia_social_sharing_heading', 'Configured owner heading', $post, 'automatic', 'en' ), 'Configured source heading was not preserved.' );
	$assert( 'Runtime EN email subject' === apply_filters( 'devenia_social_sharing_email_subject', null, $post, 'automatic', 'en' ), 'Source email subject did not require explicit runtime data.' );
	$assert( null === $call( 'localized_social_sharing_runtime_value', null, 'en', 'share_text.social_sharing_accessible_label.unconfigured' ), 'Missing source runtime text did not fail closed.' );

	$GLOBALS['devenia_social_sharing_fixture_language'] = 'nb';
	$ready = $call( 'social_sharing_runtime_presentation_readiness', 'nb', 'post', $post );
	$assert( ! empty( $ready['required'] ) && ! empty( $ready['configured'] ) && empty( $ready['missing'] ), 'Complete NB runtime data was not ready.' );
	$exact = $call( 'social_sharing_surface_manifest', $post, 'Runtime NB heading' );
	$assert( 'ready' === $exact['state'] && array( 'Runtime NB heading' ) === $exact['headings'] && ! empty( $exact['automatic_after'] ), 'The public manifest did not describe exactly one after-post heading.' );
	$page_manifest = $call( 'social_sharing_surface_manifest', $page, 'Runtime NB heading' );
	$assert( 'ready' === $page_manifest['state'] && empty( $page_manifest['applicable'] ) && empty( $page_manifest['headings'] ), 'A page with no sharing surface was applicable.' );
	$page_readiness = $call( 'social_sharing_runtime_presentation_readiness', 'nb', 'page', $page );
	$assert( empty( $page_readiness['required'] ) && ! empty( $page_readiness['configured'] ), 'A non-applicable page required sharing runtime text.' );
	$post_global_readiness = $call( 'social_sharing_runtime_presentation_readiness', 'nb', 'post' );
	$page_global_readiness = $call( 'social_sharing_runtime_presentation_readiness', 'nb', 'page' );
	$assert( ! empty( $post_global_readiness['required'] ) && empty( $page_global_readiness['required'] ), 'Conservative readiness ignored the owner post-type list.' );
	$GLOBALS['devenia_social_sharing_fixture_post_types'] = array( 'post', 'page' );
	$page_configured_global_readiness = $call( 'social_sharing_runtime_presentation_readiness', 'nb', 'page' );
	$assert( ! empty( $page_configured_global_readiness['required'] ), 'A configured page surface was omitted from conservative readiness.' );
	$assert( 'Runtime NB heading' === apply_filters( 'devenia_social_sharing_heading', 'Configured owner heading', $post, 'automatic', 'nb' ), 'NB heading did not use runtime data.' );
	$assert( 'Runtime NB accessible email' === apply_filters( 'devenia_social_sharing_accessible_label', null, $post, 'automatic', 'nb', 'email', null ), 'NB accessible label did not use runtime data.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', 'Source positional %37$s', $post, 'automatic', 'en', 'source-arbitrary-network', null ), 'A positional source-language sprintf placeholder passed.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', 'Source space width % 20s', $post, 'automatic', 'en', 'source-space-width', null ), 'A space-flag source-language sprintf placeholder passed.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', 'Source dot pad %\'.20s', $post, 'automatic', 'en', 'source-dot-pad', null ), 'An arbitrary dot-padding source-language sprintf placeholder passed.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', 'Source hash pad %1$\'#20s', $post, 'automatic', 'en', 'source-hash-pad', null ), 'A positional arbitrary hash-padding source-language sprintf placeholder passed.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', 'Source leading zero %01$s', $post, 'automatic', 'en', 'source-leading-zero-position', null ), 'A leading-zero positional source-language sprintf placeholder passed.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', 'Source many leading zeroes %0001$s', $post, 'automatic', 'en', 'source-many-leading-zero-position', null ), 'A many-leading-zero positional source-language sprintf placeholder passed.' );
	$assert( 'Source invalid zero %0$s' === apply_filters( 'devenia_social_sharing_accessible_label', 'Source invalid zero %0$s', $post, 'automatic', 'en', 'source-invalid-zero-position', null ), 'An invalid zero positional source-language sequence was classified as executable.' );
	$assert( 'Source invalid double zero %00$s' === apply_filters( 'devenia_social_sharing_accessible_label', 'Source invalid double zero %00$s', $post, 'automatic', 'en', 'source-invalid-double-zero-position', null ), 'An invalid double-zero positional source-language sequence was classified as executable.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', null, $post, 'automatic', 'nb', 'arbitrary-network', null ), 'An arbitrary-position target-language sprintf placeholder passed.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', null, $post, 'automatic', 'nb', 'bare-placeholder', null ), 'A bare target-language sprintf placeholder passed.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', null, $post, 'automatic', 'nb', 'space-width-placeholder', null ), 'A space-flag target-language sprintf placeholder passed.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', null, $post, 'automatic', 'nb', 'dot-pad-placeholder', null ), 'An arbitrary dot-padding target-language sprintf placeholder passed.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', null, $post, 'automatic', 'nb', 'positional-hash-pad-placeholder', null ), 'A positional arbitrary hash-padding target-language sprintf placeholder passed.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', null, $post, 'automatic', 'nb', 'leading-zero-position-placeholder', null ), 'A leading-zero positional target-language sprintf placeholder passed.' );
	$assert( null === apply_filters( 'devenia_social_sharing_accessible_label', null, $post, 'automatic', 'nb', 'many-leading-zero-position-placeholder', null ), 'A many-leading-zero positional target-language sprintf placeholder passed.' );
	$assert( 'Target invalid zero %0$s' === apply_filters( 'devenia_social_sharing_accessible_label', null, $post, 'automatic', 'nb', 'invalid-zero-position', null ), 'An invalid zero positional target-language sequence was classified as executable.' );
	$assert( 'Target invalid double zero %00$s' === apply_filters( 'devenia_social_sharing_accessible_label', null, $post, 'automatic', 'nb', 'invalid-double-zero-position', null ), 'An invalid double-zero positional target-language sequence was classified as executable.' );
	$assert( 'Target is 99% complete' === apply_filters( 'devenia_social_sharing_accessible_label', null, $post, 'automatic', 'nb', 'ordinary-percent', null ), 'Ordinary target-language percent prose was rejected.' );
	$assert( 'Literal %%s text' === apply_filters( 'devenia_social_sharing_accessible_label', 'Literal %%s text', $post, 'automatic', 'en', 'escaped-percent', null ), 'An escaped source-language percent sequence was rejected.' );
	$assert( $call( 'social_sharing_has_sprintf_string_placeholder', 'Width %20s' ), 'A width-qualified sprintf string placeholder passed.' );
	$assert( $call( 'social_sharing_has_sprintf_string_placeholder', 'Position %9$05.2s' ), 'A positional flagged/precision sprintf string placeholder passed.' );
	$assert( $call( 'social_sharing_has_sprintf_string_placeholder', 'Space flag % 20s' ), 'A space-flag sprintf string placeholder passed.' );
	$assert( $call( 'social_sharing_has_sprintf_string_placeholder', 'Dot padding %\'.20s' ), 'An arbitrary dot-padding sprintf string placeholder passed.' );
	$assert( $call( 'social_sharing_has_sprintf_string_placeholder', 'Hash padding %1$\'#20s' ), 'A positional arbitrary hash-padding sprintf string placeholder passed.' );
	$assert( $call( 'social_sharing_has_sprintf_string_placeholder', 'Leading zero position %01$s' ), 'A leading-zero positional sprintf string placeholder passed.' );
	$assert( $call( 'social_sharing_has_sprintf_string_placeholder', 'Many leading zeroes %0001$s' ), 'A many-leading-zero positional sprintf string placeholder passed.' );
	$assert( $call( 'social_sharing_has_sprintf_string_placeholder', 'Highest supported position %2147483646$s' ), 'The highest PHP-supported positional sprintf string placeholder passed.' );
	$assert( ! $call( 'social_sharing_has_sprintf_string_placeholder', 'Invalid zero position %0$s' ), 'An invalid zero positional sequence was classified as executable.' );
	$assert( ! $call( 'social_sharing_has_sprintf_string_placeholder', 'Invalid double zero position %00$s' ), 'An invalid double-zero positional sequence was classified as executable.' );
	$assert( ! $call( 'social_sharing_has_sprintf_string_placeholder', 'Above supported position %2147483647$s' ), 'An above-bound positional sequence was classified as executable.' );
	$assert( ! $call( 'social_sharing_has_sprintf_string_placeholder', 'Ordinary savings: 25% today' ), 'Ordinary percent prose was classified as a sprintf placeholder.' );
	$assert( 'Runtime NB email label' === apply_filters( 'devenia_social_sharing_network_label', null, $post, 'automatic', 'nb', 'email', null ), 'NB network label did not use runtime data.' );
	$assert( 'Runtime NB email subject' === apply_filters( 'devenia_social_sharing_email_subject', null, $post, 'automatic', 'nb' ), 'NB email subject did not use runtime data.' );
	$localized_email_body = apply_filters( 'devenia_social_sharing_email_body', null, $post, 'automatic', 'nb' );
	$assert( 'Runtime NB email body {url}' === $localized_email_body && 1 === substr_count( $localized_email_body, '{url}' ), 'NB email body did not provide exactly one canonical URL placeholder.' );
	$legacy_runtime_value = new ReflectionMethod( Devenia_Workflow::class, 'legacy_scriptless_social_sharing_runtime_value' );
	$legacy_runtime_value->setAccessible( true );
	$legacy_subject = (string) $legacy_runtime_value->invoke( null, 'Source legacy subject', 'scriptless_email_subject_prefix', 'nb' );
	$legacy_body_with_placeholder = (string) $legacy_runtime_value->invoke( null, 'Source legacy body', 'scriptless_email_body', 'nb' );
	$assert( 'Runtime NB legacy subject' === $legacy_subject, 'Legacy Scriptless email subject did not use semantic runtime text.' );
	$assert( 'Runtime NB legacy body {url}' === $legacy_body_with_placeholder, 'Legacy Scriptless email body did not use semantic runtime text.' );
	$assert( 'Runtime NB legacy body' === trim( str_replace( '{url}', '', $legacy_body_with_placeholder ) ), 'Legacy Scriptless body Adapter did not preserve exactly one owner-appended URL slot.' );
	$assert( 'Intrinsic Brand' === apply_filters( 'devenia_social_sharing_network_label', 'Intrinsic Brand', $post, 'automatic', 'nb', 'brand', null ), 'An intrinsic protocol brand label was replaced by runtime copy.' );

	$valid_html = '<h3 class="devenia-social-sharing__heading">Runtime NB heading</h3>';
	$valid = $call( 'social_sharing_runtime_presentation_assertions', $valid_html, 'nb', home_url( '/?p=' . $post_id ), 'translation_origin', $post_id );
	$missing = $call( 'social_sharing_runtime_presentation_assertions', '', 'nb', home_url( '/?p=' . $post_id ), 'translation_origin', $post_id );
	$duplicate = $call( 'social_sharing_runtime_presentation_assertions', $valid_html . $valid_html, 'nb', home_url( '/?p=' . $post_id ), 'translation_canonical', $post_id );
	$wrong = $call( 'social_sharing_runtime_presentation_assertions', '<h3 class="devenia-social-sharing__heading">Wrong heading</h3>', 'nb', home_url( '/?p=' . $post_id ), 'translation_origin', $post_id );
	$assert( array() === $codes( $valid ), 'The exact heading was rejected.' );
	$assert( in_array( 'frontend_social_sharing_heading_missing', $codes( $missing ), true ), 'A missing heading passed.' );
	$assert( in_array( 'frontend_social_sharing_heading_cardinality', $codes( $duplicate ), true ), 'Duplicate headings passed.' );
	$assert( in_array( 'frontend_social_sharing_heading_mismatch', $codes( $wrong ), true ), 'A wrong heading passed.' );

	$GLOBALS['devenia_social_sharing_fixture_language'] = 'es';
	$es_readiness = $call( 'social_sharing_runtime_presentation_readiness', 'es', 'post', $post );
	$assert( empty( $es_readiness['configured'] ) && in_array( 'social_sharing.owner_interface', (array) $es_readiness['missing'], true ) && in_array( 'share_text.social_sharing_heading', (array) $es_readiness['missing'], true ), 'Missing ES runtime data did not fail closed with its exact semantic key.' );
	$assert( null === apply_filters( 'devenia_social_sharing_heading', 'Configured owner heading', $post, 'automatic', 'es' ), 'Missing ES heading fell back to owner copy.' );

	update_post_meta( $post_id, Devenia_Workflow::META_CANONICAL_ROUTE, array( 'path' => 'nb/eid-deling' ) );
	$canonical = Devenia_Workflow::canonicalize_social_sharing_permalink( home_url( '/NB/Old/' ), $post, 'automatic', 'nb' );
	$assert( home_url( '/nb/eid-deling/' ) === $canonical, 'Owned sharing did not receive the Canonical Route URL.' );
	$runtime_result = array( 'success' => true, 'inactive_owner_is_not_required' => $inactive_owner_is_not_required, 'exact_one_after_post' => true, 'page_surface_is_not_applicable' => true, 'localized_nb_runtime_strings' => true, 'legacy_scriptless_email_runtime_adapter' => true, 'source_and_target_sprintf_placeholders_rejected' => true, 'ordinary_percent_text_preserved' => true, 'missing_es_runtime_fails_closed' => true, 'duplicate_heading_rejected' => true, 'missing_heading_rejected' => true, 'wrong_heading_rejected' => true, 'canonical_permalink_applied' => true );
} catch ( Throwable $error ) {
	$runtime_error = $error;
} finally {
	foreach ( $post_ids as $fixture_id ) {
		wp_delete_post( $fixture_id, true );
	}
	if ( $missing_marker === $languages_before ) {
		delete_option( Devenia_Workflow::OPTION_LANGUAGES );
	} else {
		update_option( Devenia_Workflow::OPTION_LANGUAGES, $languages_before, false );
	}
	Devenia_Workflow::languages( true );
	unset( $GLOBALS['devenia_social_sharing_fixture_settings'], $GLOBALS['devenia_social_sharing_fixture_language'], $GLOBALS['devenia_social_sharing_fixture_post_types'] );
}
if ( $runtime_error instanceof Throwable ) {
	throw $runtime_error;
}
echo wp_json_encode( $runtime_result ) . PHP_EOL;
