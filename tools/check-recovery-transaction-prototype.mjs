#!/usr/bin/env node

import assert from "node:assert/strict";

const tables = ["wp_posts", "wp_postmeta", "wp_terms", "wp_term_taxonomy", "wp_term_relationships", "wp_termmeta", "wp_options"];
const normalize = (value) => /^[A-Za-z0-9_$]+$/.test(String(value).trim()) ? String(value).trim().toLowerCase() : "";
const isolationSql = Object.freeze({
	"READ-UNCOMMITTED": "READ UNCOMMITTED",
	"READ-COMMITTED": "READ COMMITTED",
	"REPEATABLE-READ": "REPEATABLE READ",
	SERIALIZABLE: "SERIALIZABLE",
});
const prepareIdentifier = (value) => /^[a-z0-9_]{1,64}$/.test(String(value)) ? `\`${value}\`` : null;

function begin(input = {}) {
	const trace = [];
	if (input.owned === true) return { ok: false, phase: "preexisting_check", code: "owned_transaction_already_active", trace };
	const expected = new Map(tables.map((table) => [normalize(table), table]));
	const engines = new Map();
	for (const row of Array.isArray(input.primary) ? input.primary : []) {
		const name = normalize(row.TABLE_NAME);
		if (expected.has(name)) engines.set(name, String(row.ENGINE || "").trim().toUpperCase());
	}
	for (const [name, engine] of [...engines]) {
		if (!engine) engines.delete(name);
		else if (engine !== "INNODB") return { ok: false, phase: "metadata_primary", code: "core_table_non_transactional", trace };
	}
	for (const [name, table] of expected) {
		if (engines.has(name)) continue;
		trace.push(`SHOW:${table}`);
		const rows = input.fallback?.[name];
		if (!Array.isArray(rows) || rows.length !== 1) return { ok: false, phase: "metadata_fallback", code: "core_table_metadata_unavailable", trace };
		const row = rows[0];
		if (normalize(row.Name) !== name) return { ok: false, phase: "metadata_fallback", code: "core_table_identity_mismatch", trace };
		if (String(row.Engine || "").trim().toUpperCase() !== "INNODB") return { ok: false, phase: "metadata_fallback", code: row.Engine ? "core_table_non_transactional" : "core_table_engine_unknown", trace };
		engines.set(name, "INNODB");
	}
	if (engines.size !== 7) return { ok: false, phase: "metadata_complete", code: "core_table_set_unproven", trace };
	const before = input.before ?? { connection: 7, sessionIsolation: "REPEATABLE-READ" };
	if (!before) return { ok: false, phase: "preexisting_check", code: "transaction_metadata_unavailable", trace };
	trace.push("DISABLE_WPDB_RECONNECT_RETRIES");
	if (input.reconnectGuardOk === false) return { ok: false, phase: "reconnect_guard", code: "reconnect_guard_unavailable", trace };
	trace.push("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
	if (input.guardOk === false) return { ok: false, phase: "preexisting_check", code: "preexisting_or_unknown_transaction_refused", trace: [...trace, "RESTORE_WPDB_RECONNECT_RETRIES"] };
	trace.push("START TRANSACTION");
	if (input.startOk === false) {
		const resetIsolation = isolationSql[before.sessionIsolation];
		if (resetIsolation) trace.push(`SET TRANSACTION ISOLATION LEVEL ${resetIsolation}`);
		return { ok: false, phase: "start", code: input.clearOk === false ? "start_transaction_failed_next_isolation_clear_failed" : "start_transaction_failed", trace: [...trace, "RESTORE_WPDB_RECONNECT_RETRIES"] };
	}
	const active = input.active ?? before;
	if (!active || active.connection !== before.connection || active.sessionIsolation !== before.sessionIsolation) {
		trace.push("ROLLBACK AND NO CHAIN NO RELEASE", "RESTORE_WPDB_RECONNECT_RETRIES");
		return { ok: false, phase: "verify_start", code: !active ? "transaction_metadata_unavailable" : active.connection !== before.connection ? "connection_identity_changed" : "session_isolation_changed", trace };
	}
	const savepoint = `devenia_workflow_recovery_${input.ownerHash ?? "0123456789abcdef01234567"}`;
	const preparedSavepoint = prepareIdentifier(savepoint);
	trace.push(`SAVEPOINT ${preparedSavepoint}`);
	if (input.savepointOk === false) {
		trace.push("ROLLBACK AND NO CHAIN NO RELEASE", "RESTORE_WPDB_RECONNECT_RETRIES");
		return { ok: false, phase: "ownership", code: "savepoint_create_failed", trace };
	}
	return { ok: true, trace, receipt: { owned: true, savepoint, reconnectGuard: true, connection: before.connection, sessionIsolation: before.sessionIsolation, transactionIsolation: "SERIALIZABLE" } };
}

function rollback(receipt, input = {}) {
	const trace = [];
	if (!receipt?.owned || !receipt.reconnectGuard) return { success: false, rolledBack: false, code: "transaction_not_owned", trace };
	const state = input.state ?? { connection: receipt.connection, sessionIsolation: receipt.sessionIsolation };
	if (!state || state.connection !== receipt.connection || state.sessionIsolation !== receipt.sessionIsolation || receipt.transactionIsolation !== "SERIALIZABLE" || input.savepointOk === false) {
		return { success: false, rolledBack: false, code: "transaction_ownership_lost", trace };
	}
	trace.push(`ROLLBACK TO SAVEPOINT ${prepareIdentifier(receipt.savepoint)}`, "ROLLBACK AND NO CHAIN NO RELEASE", "RESTORE_WPDB_RECONNECT_RETRIES");
	if (input.rollbackOk === false) return { success: false, rolledBack: false, code: "rollback_failed", trace };
	if (input.guardRestoreOk === false) return { success: false, rolledBack: true, code: "rollback_reconnect_guard_restore_failed", trace };
	return { success: true, rolledBack: true, code: "transaction_rolled_back", trace };
}

function commit(receipt, input = {}) {
	const trace = [];
	if (!receipt?.owned || !receipt.reconnectGuard) return { success: false, committed: null, code: "transaction_not_owned", trace };
	const state = input.state ?? { connection: receipt.connection, sessionIsolation: receipt.sessionIsolation };
	if (!state || state.connection !== receipt.connection || state.sessionIsolation !== receipt.sessionIsolation || receipt.transactionIsolation !== "SERIALIZABLE") {
		return { success: false, committed: false, code: "transaction_ownership_lost", trace };
	}
	trace.push(`RELEASE SAVEPOINT ${prepareIdentifier(receipt.savepoint)}`, `SAVEPOINT ${prepareIdentifier(receipt.savepoint)}`);
	if (input.refreshOk === false) {
		trace.push("ROLLBACK AND NO CHAIN NO RELEASE");
		return { success: false, committed: false, code: "ownership_receipt_refresh_failed", rollback: { success: input.rollbackOk !== false, rolledBack: input.rollbackOk !== false }, trace };
	}
	trace.push("COMMIT AND NO CHAIN NO RELEASE");
	if (input.commitOk === false) {
		const terminal = rollback(receipt, input);
		return { success: false, committed: terminal.success ? false : null, code: terminal.success ? "commit_failed_rolled_back" : "commit_outcome_unknown", rollback: terminal, trace: [...trace, ...terminal.trace] };
	}
	const afterCommit = Object.hasOwn(input, "afterCommit") ? input.afterCommit : { connection: receipt.connection, sessionIsolation: receipt.sessionIsolation };
	if (!afterCommit || afterCommit.connection !== receipt.connection || afterCommit.sessionIsolation !== receipt.sessionIsolation) {
		trace.push("POST_COMMIT_CONNECTION_PROOF_FAILED");
		return { success: false, committed: null, code: "commit_outcome_unknown", trace };
	}
	trace.push("RESTORE_WPDB_RECONNECT_RETRIES");
	if (input.guardRestoreOk === false) return { success: false, committed: true, code: "transaction_committed_reconnect_guard_restore_failed", trace };
	return { success: true, committed: true, code: "transaction_committed", trace };
}

function midWrite(receipt) {
	return receipt?.owned && receipt.reconnectGuard
		? { success: false, reissued: false, code: "mid_write_reconnect_blocked" }
		: { success: true, reissued: true, code: "mid_write_reissued" };
}

const primary = tables.map((TABLE_NAME) => ({ TABLE_NAME, ENGINE: "InnoDB" }));
const fallbackAll = Object.fromEntries(tables.map((table) => [normalize(table), [{ Name: table.toUpperCase(), Engine: "InnoDB" }]]));
assert.equal(begin({ primary }).ok, true, "complete primary proof must pass");
assert.equal(begin({ primary: null, fallback: fallbackAll }).ok, true, "fallback must prove all seven tables");
assert.equal(begin({ primary: primary.slice(0, 6), fallback: fallbackAll }).ok, true, "fallback must fill only missing metadata");
assert.equal(begin({ primary: primary.slice(0, 6), fallback: {} }).code, "core_table_metadata_unavailable");
assert.equal(begin({ primary: primary.map((row, i) => i === 2 ? { ...row, ENGINE: "MyISAM" } : row) }).code, "core_table_non_transactional");
assert.equal(begin({ primary, guardOk: false }).code, "preexisting_or_unknown_transaction_refused", "portable guard must refuse an active or unknown outer transaction");
assert.equal(begin({ primary, owned: true }).code, "owned_transaction_already_active");
const startFailure = begin({ primary, startOk: false });
assert.ok(startFailure.trace.includes("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ"), "failed START must clear the pending next-transaction override through fixed literal SQL");
for (const [sessionIsolation, sqlLevel] of Object.entries(isolationSql)) {
	assert.ok(begin({ primary, before: { connection: 7, sessionIsolation }, startOk: false }).trace.includes(`SET TRANSACTION ISOLATION LEVEL ${sqlLevel}`), `fixed isolation reset missing for ${sessionIsolation}`);
}
assert.equal(begin({ primary, startOk: false, clearOk: false }).code, "start_transaction_failed_next_isolation_clear_failed");
assert.equal(begin({ primary, active: { connection: 8, sessionIsolation: "REPEATABLE-READ" } }).code, "connection_identity_changed");
assert.equal(begin({ primary, active: { connection: 7, sessionIsolation: "READ-COMMITTED" } }).code, "session_isolation_changed");
const owned = begin({ primary });
assert.ok(owned.trace.includes(`SAVEPOINT \`${owned.receipt.savepoint}\``), "savepoint creation must use identifier quoting equivalent to wpdb %i");
assert.equal(commit(owned.receipt).success, true);
assert.ok(commit(owned.receipt).trace.includes(`RELEASE SAVEPOINT \`${owned.receipt.savepoint}\``), "savepoint release must use identifier quoting equivalent to wpdb %i");
assert.ok(commit(owned.receipt).trace.includes(`SAVEPOINT \`${owned.receipt.savepoint}\``), "savepoint refresh must use identifier quoting equivalent to wpdb %i");
assert.ok(commit(owned.receipt).trace.includes("COMMIT AND NO CHAIN NO RELEASE"), "commit must override CHAIN and RELEASE completion modes");
assert.equal(commit(owned.receipt, { afterCommit: { connection: 8, sessionIsolation: "REPEATABLE-READ" } }).committed, null, "truthy COMMIT retried on a changed connection must remain unknown");
assert.equal(commit(owned.receipt, { afterCommit: null }).committed, null, "truthy COMMIT without post-COMMIT metadata must remain unknown");
assert.deepEqual(midWrite(owned.receipt), { success: false, reissued: false, code: "mid_write_reconnect_blocked" }, "owned mid-write disconnect must never reissue on autocommit");
assert.equal(commit(owned.receipt, { guardRestoreOk: false }).committed, true, "guard restore failure must preserve committed database truth");
assert.equal(commit(owned.receipt, { guardRestoreOk: false }).success, false, "guard restore failure must fail the overall terminal cleanup");
assert.equal(rollback(owned.receipt, { guardRestoreOk: false }).rolledBack, true, "guard restore failure must preserve rollback database truth");
const lost = rollback(owned.receipt, { state: { connection: 8, sessionIsolation: "REPEATABLE-READ" } });
assert.equal(lost.rolledBack, false, "lost ownership must not affect an unknown transaction");
assert.ok(rollback(owned.receipt).trace.includes(`ROLLBACK TO SAVEPOINT \`${owned.receipt.savepoint}\``), "savepoint rollback proof must use identifier quoting equivalent to wpdb %i");
const commitFailure = commit(owned.receipt, { commitOk: false });
assert.equal(commitFailure.rollback.success, true, "failed commit must propagate the exact rollback outcome");
assert.ok(commitFailure.trace.includes("ROLLBACK AND NO CHAIN NO RELEASE"));
const rollbackFailure = commit(owned.receipt, { commitOk: false, rollbackOk: false });
assert.equal(rollbackFailure.rollback.rolledBack, false, "caller truth must not claim a failed rollback");
assert.equal(rollbackFailure.committed, null, "failed COMMIT plus failed rollback must preserve an unknown commit outcome");
assert.equal(commit(owned.receipt, { refreshOk: false }).rollback.success, true, "receipt refresh failure must have a structured terminal rollback outcome");
assert.ok(!owned.trace.some((step) => step.startsWith("SET SESSION")), "the owned boundary must never mutate SESSION isolation");

console.log(JSON.stringify({ success: true, scenarios: 22 }));
