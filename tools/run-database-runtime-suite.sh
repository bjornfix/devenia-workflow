#!/usr/bin/env bash

set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_path="${1:-${WP_PATH:-}}"
wp_cli_bin="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"
database_expectation="${DATABASE_EXPECTATION:-mariadb}"

if [[ -z "$wp_path" ]]; then
	printf '%s\n' 'Usage: tools/run-database-runtime-suite.sh /path/to/wordpress' >&2
	exit 2
fi
if [[ ! -d "$wp_path" ]]; then
	printf 'WordPress path does not exist: %s\n' "$wp_path" >&2
	exit 2
fi
if ! command -v "$wp_cli_bin" >/dev/null 2>&1; then
	printf 'WP-CLI executable is unavailable: %s\n' "$wp_cli_bin" >&2
	exit 2
fi
if ! command -v "$php_bin" >/dev/null 2>&1; then
	printf 'PHP executable is unavailable: %s\n' "$php_bin" >&2
	exit 2
fi

parser_result="$("$php_bin" "$plugin_root/tools/check-primary-navigation-parser.php")"
printf '%s\n' "$parser_result"
if [[ "$parser_result" != 'Primary navigation parser contract passed.' ]]; then
	printf '%s\n' 'Primary navigation parser did not return the exact success proof.' >&2
	exit 1
fi

batch_result="$("$php_bin" "$plugin_root/tools/check-frontend-cache-batch.php")"
printf '%s\n' "$batch_result"
if [[ "$batch_result" != 'Frontend cache batch contract passed.' ]]; then
	printf '%s\n' 'Frontend cache batch did not return the exact success proof.' >&2
	exit 1
fi

page_route_result="$("$php_bin" "$plugin_root/tools/check-new-page-localized-path-contract.php")"
printf '%s\n' "$page_route_result"
if [[ "$page_route_result" != 'New-page localized-path contract passed.' ]]; then
	printf '%s\n' 'New-page localized-path contract did not return the exact success proof.' >&2
	exit 1
fi

if "$wp_cli_bin" option get devenia_workflow_version --path="$wp_path" --skip-plugins --skip-themes >/dev/null 2>&1; then
	"$wp_cli_bin" option delete devenia_workflow_version --path="$wp_path" --skip-plugins --skip-themes >/dev/null
fi

bootstrap_result="$("$wp_cli_bin" eval-file \
	"$plugin_root/tools/check-wp-cli-upgrade-bootstrap-runtime.php" \
	--path="$wp_path" \
	--skip-themes)"
printf '%s\n' "$bootstrap_result"
if [[ "$bootstrap_result" != '{"success":true,"context":"wp-cli","fresh_upgrade":true}' ]]; then
	printf '%s\n' 'Fresh WP-CLI upgrade bootstrap did not return the exact success proof.' >&2
	exit 1
fi

database_version="$("$wp_cli_bin" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );' --path="$wp_path" --skip-themes)"
case "$database_expectation" in
	mariadb)
		if [[ ! "$database_version" =~ ^10\.11\..*MariaDB ]]; then
			printf 'Expected the MariaDB 10.11 production baseline, got: %s\n' "$database_version" >&2
			exit 1
		fi
		;;
	mysql-8.4)
		if [[ ! "$database_version" =~ ^8\.4\. ]]; then
			printf 'Expected optional MySQL 8.4 compatibility, got: %s\n' "$database_version" >&2
			exit 1
		fi
		;;
	*)
		printf 'Unsupported database expectation: %s\n' "$database_expectation" >&2
		exit 2
		;;
esac

recovery_result="$("$wp_cli_bin" eval-file \
	"$plugin_root/tools/check-recovery-transaction-portability-runtime.php" \
	--path="$wp_path" \
	--skip-themes)"
printf '%s\n' "$recovery_result"
if [[ "$recovery_result" != '{"success":true,"scenarios":22}' ]]; then
	printf '%s\n' 'Recovery runtime did not return the exact 22-scenario success proof.' >&2
	exit 1
fi

focused_header_result="$(WP_CLI_BIN="$wp_cli_bin" \
	"$plugin_root/tools/run-public-header-first-enrollment-cleanup-runtime.sh" "$wp_path")"
printf '%s\n' "$focused_header_result"
RESULT="$focused_header_result" "$php_bin" -r '
	$result = json_decode((string) getenv("RESULT"), true);
	$interfaces = is_array($result["public_interfaces"] ?? null) ? $result["public_interfaces"] : [];
	if (!is_array($result) || true !== ($result["success"] ?? null) || !in_array("devenia-workflow/activate-public-header-projection", $interfaces, true) || !in_array("devenia-workflow/verify-public-header-projection", $interfaces, true) || true !== ($result["schema_enforced"] ?? null) || true !== ($result["permission_enforced"] ?? null) || true !== ($result["existing_identities_pre_state_restored"] ?? null) || true !== ($result["rollback_cache_retryable"] ?? null) || true !== ($result["owned_staging_cleaned"] ?? null) || 1 > (int) ($result["projection_count"] ?? 0) || 1 > (int) ($result["batch_calls"] ?? 0)) {
		fwrite(STDERR, "Focused Public Header first-enrollment cleanup proof failed.\n");
		exit(1);
	}
'

header_result="$("$wp_cli_bin" eval-file \
	"$plugin_root/tools/check-public-header-projection-wordpress-runtime.php" \
	--path="$wp_path" \
	--skip-themes)"
printf '%s\n' "$header_result"
RESULT="$header_result" "$php_bin" -r '
	$result = json_decode((string) getenv("RESULT"), true);
	$required = [
		"success",
		"activation_receipt_binds_exact_pending_manifest",
		"missing_activation_receipt_failed_without_mutation",
		"stale_activation_receipt_rejected",
		"concurrent_pending_replacement_rejected_before_staging",
		"raw_normalization_equivalent_pending_rejected_before_staging",
		"raw_key_reorder_pending_rejected_before_staging",
		"raw_key_reorder_pending_rejected_at_locked_boundary",
		"exact_activation_receipt_activated",
		"content_publication_preserved_header_authority",
		"first_enrollment_staged_before_explicit_activation",
		"explicit_activation_is_the_only_header_mutation_path",
		"missing_target_translation_rejected_before_pending_mutation",
		"missing_relation_receipts_failed_without_raw_state_mutation",
		"internal_custom_route_drift_rolled_back_exactly",
		"separate_connection_post_lock_blocked_writer",
		"separate_connection_meta_predicate_lock_blocked_writer",
		"relation_receipts_not_persisted_in_active_manifest",
	];
	foreach ($required as $key) {
		if (!is_array($result) || true !== ($result[$key] ?? null)) {
			fwrite(STDERR, "Public Header relation runtime missing exact proof: {$key}\n");
			exit(1);
		}
	}
'

job_result="$("$wp_cli_bin" eval-file \
	"$plugin_root/tools/check-translation-job-runtime.php" \
	--path="$wp_path" \
	--skip-themes)"
printf '%s\n' "$job_result"
RESULT="$job_result" "$php_bin" -r '
	$result = json_decode((string) getenv("RESULT"), true);
	if (!is_array($result) || true !== ($result["success"] ?? null) || true !== ($result["translation_job_publication_is_content_only"] ?? null) || true !== ($result["new_page_localized_path_established_before_surface_verification"] ?? null) || true !== ($result["legacy_new_page_localized_path_derived_from_signed_route_inputs"] ?? null)) {
		fwrite(STDERR, "Translation Job runtime did not prove content-only publication and new-page route authority.\n");
		exit(1);
	}
'

DATABASE_FAMILY="$database_expectation" DATABASE_VERSION="$database_version" "$php_bin" -r '
	echo json_encode(
		array(
			"success" => true,
			"database" => (string) getenv("DATABASE_FAMILY"),
			"database_version" => (string) getenv("DATABASE_VERSION"),
			"runtime_suite" => "repository-owned",
		),
		JSON_UNESCAPED_SLASHES
	), PHP_EOL;
'
