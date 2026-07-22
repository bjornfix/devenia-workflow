<?php
/**
 * Standalone behavioral contract for staged Source Rewrite Quality Authority.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Post {
	public int $ID;
	public string $post_type = 'page';
	public string $post_status = 'publish';
	public string $post_title = '';
	public string $post_excerpt = '';
	public string $post_content = '';
	public int $post_parent = 0;
	public string $post_modified_gmt = '2026-07-23 00:00:00';

	/** @param array<string,mixed> $values */
	public function __construct( array $values ) {
		foreach ( $values as $key => $value ) {
			$this->{$key} = $value;
		}
	}
}

final class WP_Error {
	private string $code;
	/** @var mixed */
	private $data;

	/** @param mixed $data */
	public function __construct( string $code, string $message, $data = null ) {
		unset( $message );
		$this->code = $code;
		$this->data = $data;
	}
	public function get_error_code(): string { return $this->code; }
	/** @return mixed */
	public function get_error_data() { return $this->data; }
}

$GLOBALS['srq_posts']   = array();
$GLOBALS['srq_options'] = array();
$GLOBALS['srq_token']   = 0;
$GLOBALS['srq_meta']    = array();
$GLOBALS['srq_policy_contexts'] = array();
$GLOBALS['srq_policy_revision_fixture'] = 'initial';

function absint( $value ): int { return abs( (int) $value ); }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? ''; }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
function strip_shortcodes( string $value ): string { return $value; }
function wp_json_encode( $value ): string { return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: ''; }
function wp_generate_password( int $length ): string {
	$GLOBALS['srq_token']++;
	return substr( str_repeat( hash( 'sha256', 'token-' . $GLOBALS['srq_token'] ), 2 ), 0, $length );
}
function get_current_user_id(): int { return 7; }
function get_post( int $post_id ): ?WP_Post { return $GLOBALS['srq_posts'][ $post_id ] ?? null; }
function get_option( string $key ) { return $GLOBALS['srq_options'][ $key ] ?? false; }
function update_option( string $key, $value, bool $autoload = false ): bool { unset( $autoload ); $GLOBALS['srq_options'][ $key ] = $value; return true; }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function esc_url_raw( $value ): string { return trim( (string) $value ); }
function get_permalink( $post ): string { $post_id = $post instanceof WP_Post ? $post->ID : (int) $post; return 'https://example.test/plugins/source-' . $post_id . '/'; }
function home_url( string $path = '/' ): string { return 'https://example.test' . $path; }
function get_post_meta( int $post_id, string $key, bool $single = false ) { unset( $single ); return $GLOBALS['srq_meta'][ $post_id ][ $key ] ?? ''; }
function update_post_meta( int $post_id, string $key, $value ): bool { $GLOBALS['srq_meta'][ $post_id ][ $key ] = $value; return true; }
function delete_post_meta( int $post_id, string $key ): bool { unset( $GLOBALS['srq_meta'][ $post_id ][ $key ] ); return true; }
function mcp_expose_validate_content_write_policy( ?WP_Post $post, string $post_type, string $target_status, string $content, array $input, string $ability ) {
	return apply_filters(
		'mcp_content_write_preflight',
		true,
		array(
			'post' => $post,
			'post_type' => $post_type,
			'target_status' => $target_status,
			'content' => $content,
			'input' => $input,
			'ability' => $ability,
			'operation' => sanitize_key( (string) ( $input['content_write_operation'] ?? ( null === $post ? 'create' : 'update' ) ) ),
			'write_mode' => sanitize_key( (string) ( $input['content_write_mode'] ?? 'guarded' ) ),
		)
	);
}
function apply_filters( string $hook, $value, ...$args ) {
	if ( 'devenia_workflow_copy_quality_site_policy' === $hook ) {
		$GLOBALS['srq_policy_contexts'][] = $args[0] ?? array();
		$source_title = (string) ( $args[0]['source_title'] ?? '' );
		return array(
			'purpose' => 'Help a regulated buyer distinguish a verified publication from an unchecked draft for ' . $source_title . '. Policy ' . $GLOBALS['srq_policy_revision_fixture'] . '.',
			'facts' => array( 'Every approval is bound to one exact artifact revision.' ),
			'contrasts' => array(
				array(
					'reject' => 'Transform content with powerful automation.',
					'accept_direction' => 'An unchecked draft can look finished. Bind approval to the exact revision that may go live.',
					'why' => 'The stronger direction names the risk and the mechanism.',
				),
			),
		);
	}
	if ( 'mcp_content_write_preflight' === $hook ) {
		return Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::preflight( $value, (array) ( $args[0] ?? array() ) );
	}
	if ( 'devenia_workflow_frontend_cache_invalidation_result' === $hook ) {
		return array( 'success' => true, 'adapter' => 'runtime-cache' );
	}
	return $value;
}
function wp_update_post( array $data, bool $wp_error = false ) {
	$post_id = absint( $data['ID'] ?? 0 );
	$current = get_post( $post_id );
	if ( ! $current instanceof WP_Post ) { return $wp_error ? new WP_Error( 'missing', 'missing' ) : 0; }
	$filtered = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::save_guard( $data, $data, $data, true );
	$current->post_title = (string) ( $filtered['post_title'] ?? $current->post_title );
	$current->post_excerpt = (string) ( $filtered['post_excerpt'] ?? $current->post_excerpt );
	$current->post_content = (string) ( $filtered['post_content'] ?? $current->post_content );
	$current->post_status = (string) ( $filtered['post_status'] ?? $current->post_status );
	$current->post_modified_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $current->post_modified_gmt ) + 1 );
	return $post_id;
}

require_once dirname( __DIR__ ) . '/includes/trait-copy-quality-priming.php';
require_once dirname( __DIR__ ) . '/includes/trait-source-rewrite-quality-authority.php';

final class Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test {
	use Devenia_Workflow_Copy_Quality_Priming;
	use Devenia_Workflow_Source_Rewrite_Quality_Authority;
	public const META_SOURCE_CONTENT_INTEGRITY_REVIEW_HASH = '_devenia_translation_source_content_integrity_review_hash';
	public const META_SOURCE_CONTENT_INTEGRITY_REVIEWED_AT = '_devenia_translation_source_content_integrity_reviewed_at';
	public const META_SOURCE_CONTENT_INTEGRITY_REVIEWER = '_devenia_translation_source_content_integrity_reviewer';
	public const META_SOURCE_CONTENT_INTEGRITY_REVIEW_NOTE = '_devenia_translation_source_content_integrity_review_note';
	public const META_SOURCE_CONTENT_INTEGRITY_REVIEW_EVIDENCE = '_devenia_translation_source_content_integrity_review_evidence';

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function discover( array $input ): array { return self::source_rewrite_discover( $input ); }
	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function claim( array $input ): array { return self::source_rewrite_claim( $input ); }
	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function fetch( array $input ): array { return self::source_rewrite_fetch_packet( $input ); }
	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function submit_artifact( array $input ): array { return self::source_rewrite_submit_artifact( $input ); }
	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function decide( array $input ): array { return self::source_rewrite_submit_quality_decision( $input ); }
	/** @param mixed $result @param array<string,mixed> $context */
	public static function preflight( $result, array $context ) { return self::validate_source_rewrite_quality_preflight( $result, $context ); }
	/** @param array<string,mixed> $data @param array<string,mixed> $postarr @param array<string,mixed> $unsanitized */
	public static function save_guard( array $data, array $postarr, array $unsanitized = array(), bool $update = true ): array { return self::guard_unapproved_source_rewrite_before_save( $data, $postarr, $unsanitized, $update ); }
	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function publish( array $input ): array { return self::source_rewrite_publish( $input ); }
	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function verify( array $input ): array { return self::source_rewrite_verify_live( $input ); }
	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function status( array $input ): array { return self::source_rewrite_status( $input ); }

	private static function is_translation_post( int $post_id ): bool { return 99 === $post_id; }
	private static function is_translatable_post_type( string $post_type ): bool { return in_array( $post_type, array( 'page', 'post' ), true ); }
	private static function normalize_gutenberg_content_for_storage( string $content ): string { return trim( $content ); }
	private static function normalize_review_text( string $text ): string { return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) ?? '' ); }
	private static function source_language_code(): string { return 'en'; }
	private static function update_json_post_meta( int $post_id, string $key, array $value ): void { update_post_meta( $post_id, $key, $value ); }
	private static function source_content_integrity_validation( WP_Post $source ): array { unset( $source ); return array( 'passed' => true, 'issue_count' => 0, 'issues' => array() ); }
	private static function publication_experience_readiness_for_post( WP_Post $source, string $language, string $context ): array { unset( $source, $language, $context ); return array( 'passed' => true, 'issues' => array() ); }
	private static function frontend_public_surface_integrity_for_url( string $url, string $language, int $timeout, string $surface ): array {
		unset( $language, $timeout, $surface );
		return array( 'success' => true, 'passed' => true, 'url' => $url, 'issues' => array() );
	}
	private static function fetch_frontend_cache_surface( string $url, int $timeout, string $surface ): array {
		unset( $url, $timeout, $surface );
		$post = get_post( 41811 );
		return array( 'success' => true, 'status_code' => 200, 'final_url' => get_permalink( 41811 ), 'body' => '<html lang="en"><body><h1>' . $post->post_title . '</h1>' . $post->post_content . '</body></html>' );
	}

	/** @return array<int,array<string,mixed>> */
	private static function text_fragments_for_copy_quality( string $content ): array {
		$fragments = array();
		if ( preg_match_all( '/<(h[1-6]|p|li|a)(?:\s[^>]*)?>(.*?)<\/\1>/s', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $index => $match ) {
				$fragments[] = array(
					'block'     => 'fixture/' . $match[1],
					'heading'   => str_starts_with( $match[1], 'h' ),
					'unique_id' => 'fragment-' . $index,
					'text'      => self::normalize_review_text( $match[2] ),
				);
			}
		}
		return $fragments;
	}

	private static function source_hash( WP_Post $post ): string {
		return hash( 'sha256', $post->post_title . "\n" . $post->post_excerpt . "\n" . self::normalize_gutenberg_content_for_storage( $post->post_content ) );
	}
	private static function source_publication_surface_revision( WP_Post $post ): string {
		return hash( 'sha256', 'surface:' . self::source_hash( $post ) );
	}
	private static function atomic_create_option( string $key, $value ): bool {
		if ( array_key_exists( $key, $GLOBALS['srq_options'] ) ) { return false; }
		$GLOBALS['srq_options'][ $key ] = $value;
		return true;
	}
	private static function atomic_replace_option_value( string $key, $expected, $replacement ): bool {
		if ( ! array_key_exists( $key, $GLOBALS['srq_options'] ) || $GLOBALS['srq_options'][ $key ] !== $expected ) { return false; }
		$GLOBALS['srq_options'][ $key ] = $replacement;
		return true;
	}
	private static function atomic_delete_option_value( string $key, $expected ): bool {
		if ( ! array_key_exists( $key, $GLOBALS['srq_options'] ) || $GLOBALS['srq_options'][ $key ] !== $expected ) { return false; }
		unset( $GLOBALS['srq_options'][ $key ] );
		return true;
	}
}

function source_fixture( string $body_word ): string {
	return implode(
		"\n",
		array(
			'<!-- wp:heading --><h2>The work readers never see is what keeps every language trustworthy</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>' . trim( str_repeat( $body_word . ' ', 220 ) ) . '</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>AI moves quickly. Independent judgment keeps it honest.</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Every artifact binds the writer, the reviewer, the source facts, and the exact public result.</p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph --><p><a href="https://example.test/start">See what the workflow protects</a></p><!-- /wp:paragraph -->',
		)
	);
}

function preservation_brief(): array {
	return array(
		'buyer'             => 'WordPress teams using AI who cannot afford fluent-looking errors or forgotten pages.',
		'problem'           => 'Fast generation hides omissions, weak claims, stale routes, and copy that sounds complete while saying very little.',
		'desired_result'    => 'Every public source and translation should remain useful, persuasive, reviewable, and provably complete.',
		'promise'           => 'Devenia Workflow turns AI output into controlled WordPress publication with independent judgment before release.',
		'proof'             => array( 'Exact artifact revisions bind decisions.', 'Independent run principals separate writing from review.', 'Exhaustion Proof accounts for every obligation.' ),
		'offer'             => 'A WordPress-native workflow for source improvement, multilingual publication, evidence, recovery, and verification.',
		'capabilities'      => array( 'Source inventory', 'Bounded artifacts', 'Independent Quality', 'Stable routes', 'Live verification' ),
		'boundaries'        => array( 'The plugin governs the workflow but does not pretend PHP can judge prose.', 'Human or agent judgment remains explicit evidence.' ),
		'next_action'       => 'Inspect the workflow and decide whether controlled AI publishing fits the site.',
		'page_purpose'      => 'Make the hidden risk of ungoverned AI publication tangible, then show why the control layer deserves trust.',
		'emotional_intent'  => 'Move the reader from unease about plausible-looking AI output to relief that every claim and page can be accounted for.',
		'intentional_changes' => array( 'Replace generic feature labels with a causal narrative.', 'Keep every verified technical capability and boundary.' ),
	);
}

function quality_evidence( string $decision, string $finding ): array {
	$long = static fn ( string $text ): string => $text . ' ' . str_repeat( 'This assessment names the actual reader movement and the words responsible for it. ', 2 );
	return array(
		'decision'                         => $decision,
		'whole_page_purpose_assessment'    => $long( 'The page creates one coherent movement from hidden AI risk to controlled publication and a credible next step.' ),
		'emotional_connection_assessment' => $long( 'The tension is recognizable rather than manufactured, and relief is earned through concrete controls and evidence.' ),
		'literary_craft_assessment'        => $long( 'Concrete verbs, varied cadence, human voice, tension and release carry the argument without ornamental metaphor.' ),
		'buyer_problem_result_assessment'  => $long( 'The buyer, operational fear, desired confidence, and decision are explicit and connected across the page.' ),
		'promise_proof_assessment'         => $long( 'Claims are followed by exact mechanisms, revision binding, role separation, and completeness evidence.' ),
		'capability_complexity_assessment' => $long( 'The page preserves the product breadth and explains why each difficult mechanism exists for the buyer.' ),
		'boundaries_assessment'            => $long( 'The copy distinguishes software-enforced facts from reviewer judgment and avoids pretending automation can feel.' ),
		'next_action_assessment'           => $long( 'The action offers understanding and fit rather than a generic click or contact mechanic.' ),
		'natural_non_slop_assessment'      => $long( 'No interchangeable agency phrases, template rhythm, abstract noun chains, or filler sections remain.' ),
		'reviewed_sections'                => array( 'Hero promise and lead', 'AI risk and reader tension', 'Capability and proof sequence', 'Boundaries and next action' ),
		'findings'                         => array( $finding, 'The complete before-and-after copy surfaces were read rather than inferred from counts or excerpts.' ),
	);
}

$source = new WP_Post(
	array(
		'ID'           => 41811,
		'post_title'   => 'Devenia Workflow',
		'post_excerpt' => 'Controlled AI-assisted WordPress publishing.',
		'post_content' => source_fixture( 'evidence' ),
	)
);
$GLOBALS['srq_posts'][41811] = $source;

$bounded_source = new WP_Post(
	array(
		'ID'           => 41812,
		'post_title'   => 'Bounded source rewrite fixture',
		'post_excerpt' => 'The lifecycle must stop after its finite budget.',
		'post_content' => source_fixture( 'current' ),
	)
);
$GLOBALS['srq_posts'][41812] = $bounded_source;
$bounded_discovered = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::discover( array( 'source_id' => 41812 ) );
$bounded_job_id = (string) ( $bounded_discovered['job']['job_id'] ?? '' );
$bounded_job_key = 'devenia_workflow_source_rewrite_job_' . $bounded_job_id;
$GLOBALS['srq_options'][ $bounded_job_key ]['submission_generation'] = 4;
$bounded_claim = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::claim(
	array( 'job_id' => $bounded_job_id, 'run_id' => 'sr_bounded_writer', 'coordinator_id' => 'coordinator_bounded', 'role' => 'source_writer' )
);
if ( ! empty( $bounded_claim['success'] ) || 'submission_generation_exhausted' !== (string) ( $bounded_claim['code'] ?? '' ) ) {
	throw new RuntimeException( 'Source Rewrite claims were not bounded by the finite submission-generation budget.' );
}

$discovered = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::discover( array( 'source_id' => 41811 ) );
if ( empty( $discovered['success'] ) || 'queued' !== (string) ( $discovered['job']['status'] ?? '' ) ) {
	throw new RuntimeException( 'Source Rewrite Job discovery did not create a queued baseline-bound job.' );
}
$job_id = (string) $discovered['job']['job_id'];

$writer = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::claim(
	array( 'job_id' => $job_id, 'run_id' => 'sr_writer_1', 'coordinator_id' => 'coordinator_1', 'role' => 'source_writer' )
);
if ( empty( $writer['success'] ) || empty( $writer['claim_token'] ) ) {
	throw new RuntimeException( 'The source-writer Run could not claim the Job.' );
}
$writer_run_key = 'devenia_workflow_source_rewrite_run_sr_writer_1';
$writer_run_record = $GLOBALS['srq_options'][ $writer_run_key ];
foreach (
	array(
		'cross_job' => array_merge( $writer_run_record, array( 'job_id' => 'srj_foreign' ) ),
		'terminal' => array_merge( $writer_run_record, array( 'status' => 'completed' ) ),
		'cross_generation' => array_merge( $writer_run_record, array( 'submission_generation' => 99 ) ),
	) as $binding_case => $invalid_run
) {
	$GLOBALS['srq_options'][ $writer_run_key ] = $invalid_run;
	$invalid_access = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::fetch(
		array( 'job_id' => $job_id, 'run_id' => 'sr_writer_1', 'claim_token' => $writer['claim_token'] )
	);
	if ( ! empty( $invalid_access['success'] ) || 'claim_record_binding_mismatch' !== (string) ( $invalid_access['code'] ?? '' ) ) {
		throw new RuntimeException( 'Source Rewrite claim access accepted a ' . $binding_case . ' Run record.' );
	}
}
$GLOBALS['srq_options'][ $writer_run_key ] = $writer_run_record;
$writer_packet = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::fetch(
	array( 'job_id' => $job_id, 'run_id' => 'sr_writer_1', 'claim_token' => $writer['claim_token'] )
);
if ( (string) ( $writer_packet['packet']['source']['content'] ?? '' ) !== $source->post_content ) {
	throw new RuntimeException( 'The writer packet did not expose the complete source.' );
}
$writer_priming = $writer_packet['packet']['role_priming'] ?? array();
if (
	'source_writer' !== (string) ( $writer_priming['role'] ?? '' )
	|| empty( $writer_priming['priming_revision'] )
	|| count( (array) ( $writer_priming['ogilvy_examples'] ?? array() ) ) < 4
	|| count( (array) ( $writer_priming['primary_reading_library'] ?? array() ) ) < 4
	|| false === strpos( wp_json_encode( $writer_priming ), 'electric clock' )
	|| false === strpos( wp_json_encode( $writer_priming ), 'Ogilvy on Advertising' )
	|| false === strpos( wp_json_encode( $writer_priming ), 'An unchecked draft can look finished' )
	|| false !== strpos( wp_json_encode( $writer_priming ), 'Devenia Workflow binds' )
	|| 41811 !== (int) ( $GLOBALS['srq_policy_contexts'][0]['source_id'] ?? 0 )
	|| 'source_writer' !== (string) ( $GLOBALS['srq_policy_contexts'][0]['role'] ?? '' )
) {
	throw new RuntimeException( 'The fresh source writer did not receive vendor-neutral craft priming plus source-scoped Adapter policy.' );
}
$writer_priming_revision = (string) $writer_priming['priming_revision'];

$slop_content = source_fixture( 'generic' );
$slop_artifact = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::submit_artifact(
	array(
		'job_id'             => $job_id,
		'run_id'             => 'sr_writer_1',
		'claim_token'        => $writer['claim_token'],
		'proposed_title'     => 'Devenia Workflow',
		'proposed_excerpt'   => 'Controlled AI-assisted WordPress publishing.',
		'proposed_content'   => $slop_content,
		'preservation_brief' => preservation_brief(),
		'priming_revision'   => $writer_priming_revision,
	)
);
if ( empty( $slop_artifact['success'] ) || 'quality_pending' !== (string) ( $slop_artifact['job']['status'] ?? '' ) ) {
	throw new RuntimeException( 'The writer submission did not create an immutable Quality-pending artifact.' );
}
$slop_revision = (string) $slop_artifact['artifact_revision'];

$quality = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::claim(
	array( 'job_id' => $job_id, 'run_id' => 'sr_quality_1', 'coordinator_id' => 'coordinator_1_q', 'role' => 'quality' )
);
if ( empty( $quality['success'] ) || empty( $quality['claim_token'] ) ) {
	throw new RuntimeException( 'The independent Quality Run could not claim the staged artifact.' );
}
$quality_packet = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::fetch(
	array( 'job_id' => $job_id, 'run_id' => 'sr_quality_1', 'claim_token' => $quality['claim_token'] )
);
if (
	(string) ( $quality_packet['packet']['current']['content'] ?? '' ) !== $source->post_content
	|| (string) ( $quality_packet['packet']['proposed']['content'] ?? '' ) !== $slop_content
	|| count( (array) ( $quality_packet['packet']['current_copy_surface']['fragments'] ?? array() ) ) < 5
) {
	throw new RuntimeException( 'Quality did not receive both complete copy surfaces without a fragment cap.' );
}
$quality_priming = $quality_packet['packet']['role_priming'] ?? array();
if (
	'quality' !== (string) ( $quality_priming['role'] ?? '' )
	|| empty( $quality_priming['priming_revision'] )
	|| count( (array) ( $quality_priming['ogilvy_examples'] ?? array() ) ) < 4
	|| count( (array) ( $quality_priming['primary_reading_library'] ?? array() ) ) < 4
	|| false === strpos( wp_json_encode( $quality_priming ), 'Reject the page if' )
) {
	throw new RuntimeException( 'The fresh Quality reviewer was not placed in the independent Ogilvy judgment mode.' );
}
$quality_priming_revision = (string) $quality_priming['priming_revision'];

$revised = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::decide(
	array_merge(
		array(
			'job_id'           => $job_id,
			'run_id'           => 'sr_quality_1',
			'claim_token'      => $quality['claim_token'],
			'artifact_revision'=> $slop_revision,
			'priming_revision' => $quality_priming_revision,
		),
		quality_evidence( 'revise', 'The replacement keeps the length but destroys voice, meaning, specificity, emotional movement, and product credibility.' )
	)
);
if ( empty( $revised['success'] ) || 'changes_requested' !== (string) ( $revised['job']['status'] ?? '' ) ) {
	throw new RuntimeException( 'Quality could not reject equal-length slop and return the Job to the writer.' );
}

$writer2 = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::claim(
	array( 'job_id' => $job_id, 'run_id' => 'sr_writer_2', 'coordinator_id' => 'coordinator_2', 'role' => 'source_writer' )
);
$writer2_packet = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::fetch(
	array( 'job_id' => $job_id, 'run_id' => 'sr_writer_2', 'claim_token' => $writer2['claim_token'] ?? '' )
);
$strong_content = source_fixture( 'proof' ) . "\n<!-- wp:paragraph --><p>The words must make the risk felt before the mechanism can make relief believable.</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p><a href=\"https://example.test/start/\">See whether Workflow fits.</a></p><!-- /wp:paragraph -->";
$strong_artifact = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::submit_artifact(
	array(
		'job_id'             => $job_id,
		'run_id'             => 'sr_writer_2',
		'claim_token'        => $writer2['claim_token'] ?? '',
		'proposed_title'     => 'AI can write the page. Devenia Workflow makes it worth trusting.',
		'proposed_excerpt'   => 'Keep AI speed without publishing fluent-looking omissions, weak claims, or unreviewed translations.',
		'proposed_content'   => $strong_content,
		'preservation_brief' => preservation_brief(),
		'priming_revision'   => (string) ( $writer2_packet['packet']['role_priming']['priming_revision'] ?? '' ),
	)
);
if ( empty( $strong_artifact['success'] ) ) {
	throw new RuntimeException( 'A corrected writer generation could not submit a fresh artifact.' );
}
$strong_revision = (string) $strong_artifact['artifact_revision'];

$quality2 = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::claim(
	array( 'job_id' => $job_id, 'run_id' => 'sr_quality_2', 'coordinator_id' => 'coordinator_2_q', 'role' => 'quality' )
);
$quality2_packet = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::fetch(
	array( 'job_id' => $job_id, 'run_id' => 'sr_quality_2', 'claim_token' => $quality2['claim_token'] ?? '' )
);
$passed = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::decide(
	array_merge(
		array(
			'job_id'            => $job_id,
			'run_id'            => 'sr_quality_2',
			'claim_token'       => $quality2['claim_token'] ?? '',
			'artifact_revision' => $strong_revision,
			'priming_revision'  => (string) ( $quality2_packet['packet']['role_priming']['priming_revision'] ?? '' ),
		),
		quality_evidence( 'pass', 'The revised page preserves the full product contract while giving the buyer a concrete tension, earned relief, and memorable reason to act.' )
	)
);
if ( empty( $passed['success'] ) || 'ready_to_publish' !== (string) ( $passed['job']['status'] ?? '' ) ) {
	throw new RuntimeException( 'Independent semantic Quality could not authorize the exact corrected artifact.' );
}

$context = array(
	'post'          => $source,
	'post_type'     => 'page',
	'target_status' => 'publish',
	'operation'     => 'update',
	'write_mode'    => 'full_rebuild',
	'ability'       => 'content/update-page',
	'content'       => $strong_content,
	'input'         => array(
		'title'              => 'AI can write the page. Devenia Workflow makes it worth trusting.',
		'excerpt'            => 'Keep AI speed without publishing fluent-looking omissions, weak claims, or unreviewed translations.',
		'content_write_mode' => 'full_rebuild',
	),
);
if ( ! is_wp_error( Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::preflight( true, $context ) ) ) {
	throw new RuntimeException( 'The exact Quality-approved artifact bypassed the owning publish ability.' );
}
$quality_record_key = 'devenia_workflow_source_rewrite_quality_' . (string) $passed['quality_revision'];
$quality_record = $GLOBALS['srq_options'][ $quality_record_key ];
$GLOBALS['srq_options'][ $quality_record_key ]['job_id'] = 'srj_foreign';
$foreign_quality_publish = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::publish( array( 'job_id' => $job_id ) );
if ( ! empty( $foreign_quality_publish['success'] ) || 'source_rewrite_authority_binding_mismatch' !== (string) ( $foreign_quality_publish['code'] ?? '' ) ) {
	throw new RuntimeException( 'A cross-Job Quality record was accepted as Source Rewrite publication authority.' );
}
$GLOBALS['srq_options'][ $quality_record_key ] = $quality_record;
$GLOBALS['srq_policy_revision_fixture'] = 'changed-after-quality';
$stale_policy_publish = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::publish( array( 'job_id' => $job_id ) );
if ( ! empty( $stale_policy_publish['success'] ) || 'source_rewrite_priming_stale' !== (string) ( $stale_policy_publish['code'] ?? '' ) ) {
	throw new RuntimeException( 'A changed source-scoped policy did not invalidate the approved Source Rewrite authority chain.' );
}
$GLOBALS['srq_policy_revision_fixture'] = 'initial';
$published = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::publish( array( 'job_id' => $job_id ) );
if (
	empty( $published['success'] )
	|| 'published' !== (string) ( $published['job']['status'] ?? '' )
	|| true !== (bool) ( $published['needs_live_verification'] ?? false )
	|| $source->post_content !== $strong_content
) {
	throw new RuntimeException( 'The owning publish ability did not apply only the exact approved artifact.' );
}

$unauthorized = array(
	'ID'           => 41811,
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => $source->post_title,
	'post_excerpt' => $source->post_excerpt,
	'post_content' => $source->post_content . ' Changed after review.',
);
wp_update_post( $unauthorized, true );
if ( $source->post_content !== $strong_content ) {
	throw new RuntimeException( 'The WordPress save seam allowed unapproved bytes after Source Rewrite publication.' );
}
$unauthorized['post_content'] = str_replace( '/start/', '/wrong/', $strong_content );
wp_update_post( $unauthorized, true );
if ( $source->post_content !== $strong_content ) {
	throw new RuntimeException( 'The WordPress save seam allowed an unapproved customer-action destination.' );
}

$verified = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::verify( array( 'job_id' => $job_id, 'timeout' => 5 ) );
$status   = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::status( array( 'job_id' => $job_id ) );
$status_by_source = Devenia_Workflow_Source_Rewrite_Quality_Authority_Runtime_Test::status( array( 'source_id' => 41811 ) );
$source_policy_titles = array_values( array_unique( array_map(
	static function ( array $context ): string { return (string) ( $context['source_title'] ?? '' ); },
	array_values( array_filter( $GLOBALS['srq_policy_contexts'], static function ( array $context ): bool { return 41811 === (int) ( $context['source_id'] ?? 0 ); } ) )
) ) );
if (
	empty( $verified['success'] )
	|| true !== (bool) ( $status['job']['live_verification_passed'] ?? false )
	|| $job_id !== (string) ( $status_by_source['job']['job_id'] ?? '' )
	|| array( 'Devenia Workflow' ) !== $source_policy_titles
	|| empty( $GLOBALS['srq_meta'][41811]['_devenia_translation_source_content_integrity_review_evidence']['source_rewrite_quality_passed'] )
) {
	throw new RuntimeException( 'Separate live verification did not preserve the discovery-time policy snapshot and complete hash-bound source approval with source-addressable status.' );
}

fwrite( STDOUT, "Workflow Source Rewrite Quality Authority runtime passed.\n" );
