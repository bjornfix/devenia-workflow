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

route_result="$("$php_bin" "$plugin_root/tools/check-new-page-localized-path-contract.php")"
printf '%s\n' "$route_result"
if [[ "$route_result" != 'New-page localized-path contract passed.' ]]; then
	printf '%s\n' 'New-page localized-path contract did not return the exact success proof.' >&2
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
