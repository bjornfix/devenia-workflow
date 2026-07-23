#!/usr/bin/env bash

set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_path="${1:-${WP_PATH:-}}"
wp_cli_bin="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"

if [[ -z "$wp_path" || ! -d "$wp_path" ]]; then
	printf '%s\n' 'Usage: tools/run-translation-job-runtime-suite.sh /path/to/wordpress' >&2
	exit 2
fi

preview_lifecycle_result="$("$php_bin" "$plugin_root/tools/check-translation-preview-context-lifecycle-runtime.php")"
printf '%s\n' "$preview_lifecycle_result"
if [[ "$preview_lifecycle_result" != 'Translation Preview Context lifecycle runtime passed.' ]]; then
	printf '%s\n' 'Translation Preview Context lifecycle runtime did not return the exact success proof.' >&2
	exit 1
fi

route_result="$("$php_bin" "$plugin_root/tools/check-new-page-localized-path-contract.php")"
printf '%s\n' "$route_result"
if [[ "$route_result" != 'New-page localized-path contract passed.' ]]; then
	printf '%s\n' 'New-page localized-path contract did not return the exact success proof.' >&2
	exit 1
fi

preview_result="$("$wp_cli_bin" eval-file \
	"$plugin_root/tools/check-new-translation-staged-preview-wordpress-runtime.php" \
	--path="$wp_path" \
	--skip-themes)"
printf '%s\n' "$preview_result"
RESULT="$preview_result" "$php_bin" -r '
	$result = json_decode((string) getenv("RESULT"), true);
	$required = ["success", "target_runtime_language", "localized_presentation_context", "exact_staged_content", "zero_source_mutation", "existing_translation_preview", "host_relation_change_denied"];
	foreach ($required as $key) {
		if (!is_array($result) || true !== ($result[$key] ?? null)) {
			fwrite(STDERR, "Staged preview runtime missing exact proof: {$key}\n");
			exit(1);
		}
	}
'

identity_result="$($php_bin "$plugin_root/tools/check-translation-first-publication-identity-runtime.php")"
printf '%s\n' "$identity_result"
if [[ "$identity_result" != 'First-publication Translation Identity Authority runtime passed.' ]]; then
	printf '%s\n' 'First-publication Translation Identity Authority did not return the exact success proof.' >&2
	exit 1
fi

job_result="$(DEVENIA_WORKFLOW_RUNTIME_SCOPE=new-page-route "$wp_cli_bin" eval-file \
	"$plugin_root/tools/check-translation-job-runtime.php" \
	--path="$wp_path" \
	--skip-themes)"
printf '%s\n' "$job_result"
RESULT="$job_result" "$php_bin" -r '
	$result = json_decode((string) getenv("RESULT"), true);
	$required = [
		"success",
		"new_page_localized_path_established_before_surface_verification",
		"legacy_new_page_localized_path_derived_from_signed_route_inputs",
	];
	foreach ($required as $key) {
		if (!is_array($result) || true !== ($result[$key] ?? null)) {
			fwrite(STDERR, "Translation Job runtime missing exact proof: {$key}\n");
			exit(1);
		}
	}
'

printf '%s\n' '{"success":true,"runtime_suite":"translation-job-change-scoped"}'
