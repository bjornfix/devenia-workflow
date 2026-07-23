#!/usr/bin/env bash

set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_path="${1:-${WP_PATH:-}}"
wp_cli_bin="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"

if [[ -z "$wp_path" || ! -d "$wp_path" ]]; then
	printf '%s\n' 'Usage: tools/run-first-translation-publication-runtime.sh /path/to/wordpress' >&2
	exit 2
fi

runtime_result="$("$wp_cli_bin" eval-file \
	"$plugin_root/tools/check-translation-job-runtime.php" \
	--path="$wp_path" \
	--skip-themes)"
printf '%s\n' "$runtime_result"
RESULT="$runtime_result" "$php_bin" -r '
	$result = json_decode((string) getenv("RESULT"), true);
	$required = [
		"success",
		"first_translation_publish_verify_preserved_zero_bound_authority",
		"first_translation_wrong_relation_rejected",
	];
	foreach ($required as $key) {
		if (!is_array($result) || true !== ($result[$key] ?? null)) {
			fwrite(STDERR, "First-translation public lifecycle missing exact proof: {$key}\n");
			exit(1);
		}
	}
'

printf '%s\n' '{"success":true,"runtime_suite":"first-translation-publication"}'
