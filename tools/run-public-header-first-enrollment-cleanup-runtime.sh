#!/usr/bin/env bash

set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_path="${1:-${WP_PATH:-}}"
wp_cli_bin="${WP_CLI_BIN:-wp}"
wp_cli=( "$wp_cli_bin" --allow-root )

if [[ -z "$wp_path" || ! -d "$wp_path" ]]; then
	printf '%s\n' 'Usage: tools/run-public-header-first-enrollment-cleanup-runtime.sh /path/to/wordpress' >&2
	exit 2
fi

fixture_ids=()
cleanup() {
	if (( ${#fixture_ids[@]} > 0 )); then
		"${wp_cli[@]}" post delete "${fixture_ids[@]}" --force --path="$wp_path" --skip-themes >/dev/null
	fi
}
trap cleanup EXIT

target_languages="$("${wp_cli[@]}" eval '
$registry = get_option( "devenia_workflow_language_registry", array() );
foreach ( $registry as $language => $config ) {
	if ( is_array( $config ) && empty( $config["source"] ) ) { echo sanitize_key( (string) $language ) . PHP_EOL; }
}
' --path="$wp_path" --skip-themes)"

while IFS= read -r language; do
	[[ -n "$language" ]] || continue
	existing_id="$(DEVENIA_FIXTURE_LANGUAGE="$language" "${wp_cli[@]}" eval '
$language = sanitize_key( (string) getenv( "DEVENIA_FIXTURE_LANGUAGE" ) );
$source_id = absint( get_option( "page_for_posts" ) );
$ids = get_posts( array(
	"post_type" => "page",
	"post_status" => "publish",
	"numberposts" => -1,
	"fields" => "ids",
	"meta_query" => array(
		array( "key" => "_devenia_translation_source_id", "value" => $source_id ),
		array( "key" => "_devenia_translation_language", "value" => $language ),
	),
) );
foreach ( $ids as $id ) {
	$path = trim( (string) wp_parse_url( (string) get_permalink( (int) $id ), PHP_URL_PATH ), "/" );
	if ( "" !== $path ) { echo (int) $id; break; }
}
' --path="$wp_path" --skip-themes)"
	if [[ -n "$existing_id" ]]; then
		continue
	fi
	fixture_id="$(DEVENIA_FIXTURE_LANGUAGE="$language" "${wp_cli[@]}" eval '
$language = sanitize_key( (string) getenv( "DEVENIA_FIXTURE_LANGUAGE" ) );
$source_id = absint( get_option( "page_for_posts" ) );
$token = strtolower( wp_generate_password( 8, false, false ) );
$id = wp_insert_post( array( "post_type" => "page", "post_status" => "draft", "post_title" => "Focused blog route " . $language . " " . $token, "post_name" => "focused-blog-route-" . $language . "-" . $token ), true );
if ( is_wp_error( $id ) ) { fwrite( STDERR, $id->get_error_message() . PHP_EOL ); exit( 1 ); }
update_post_meta( (int) $id, "_devenia_translation_source_id", $source_id );
update_post_meta( (int) $id, "_devenia_translation_language", $language );
$published = wp_update_post( array( "ID" => (int) $id, "post_status" => "publish" ), true );
$path = trim( (string) wp_parse_url( (string) get_permalink( (int) $id ), PHP_URL_PATH ), "/" );
if ( is_wp_error( $published ) || "publish" !== get_post_status( (int) $id ) || "" === $path ) {
	wp_delete_post( (int) $id, true );
	fwrite( STDERR, "Could not establish a published Public Header blog-route fixture for {$language}.\n" );
	exit( 1 );
}
echo (int) $id;
' --path="$wp_path" --skip-themes)"
	[[ "$fixture_id" =~ ^[1-9][0-9]*$ ]] || { printf 'Invalid fixture ID for %s: %s\n' "$language" "$fixture_id" >&2; exit 1; }
	fixture_ids+=( "$fixture_id" )
done <<<"$target_languages"

set +e
focused_result="$("${wp_cli[@]}" eval-file "$plugin_root/tools/check-public-header-first-enrollment-cleanup-runtime.php" --path="$wp_path" --skip-themes 2>&1)"
focused_status=$?
set -e
printf '%s\n' "$focused_result"
exit "$focused_status"
