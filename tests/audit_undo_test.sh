#!/usr/bin/env bash
# Regression test for Audit::undo() — the Admin Dashboard's "Undo Last
# Action" button. Verifies each reversal path against a real schema.sql
# database, that every undo is itself audit-logged (changed_by = 'UNDO'),
# and that unsafe undos are rejected rather than applied.
#
# Requires: php-cli with pdo_sqlite.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC="$PWD/bud_addon/files/general/www/public"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

export BUD_DB_PATH="$TMP/undo.db"
export BUD_PUBLIC="$PUBLIC"

echo "== Audit undo test =="

cat > "$TMP/test.php" <<'PHP'
<?php
$public = getenv('BUD_PUBLIC');
require_once $public . '/includes/audit.php';

$pdo = new PDO('sqlite:' . getenv('BUD_DB_PATH'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(file_get_contents($public . '/database/schema.sql'));

$fail = 0;
function check($cond, $label)
{
    global $fail;
    if ($cond) {
        echo "  ok: $label\n";
    } else {
        echo "FAIL: $label\n";
        $fail = 1;
    }
}
function lastLogId($pdo)
{
    return $pdo->query("SELECT id FROM audit_log ORDER BY id DESC LIMIT 1")->fetchColumn();
}

// ── Undo INSERT: record should be deleted ─────────────────────────────────────
$pdo->exec("INSERT INTO suppliers (name) VALUES ('Undo Test Supplier')");
$sid = $pdo->lastInsertId();
Audit::log($pdo, 'suppliers', $sid, 'INSERT', null, ['name' => 'Undo Test Supplier']);
Audit::undo($pdo, lastLogId($pdo));

$count = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE id = $sid")->fetchColumn();
check($count == 0, "undo INSERT deletes the record");

$rev = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 1")->fetch();
check($rev['action'] === 'DELETE' && $rev['changed_by'] === 'UNDO', "undo of INSERT is logged as DELETE by UNDO");

// ── Undo UPDATE: old values restored (extra context keys ignored) ─────────────
$pdo->exec("INSERT INTO stock_items (name, sku, quantity) VALUES ('Widget', 'W-1', 100)");
$item = $pdo->lastInsertId();
$old = $pdo->query("SELECT * FROM stock_items WHERE id = $item")->fetch();
$pdo->exec("UPDATE stock_items SET quantity = 40 WHERE id = $item");
$new = $pdo->query("SELECT * FROM stock_items WHERE id = $item")->fetch();
$new['adjustment_notes'] = 'Sent via Chain of Custody'; // synthetic key, as custody.php adds
Audit::log($pdo, 'stock_items', $item, 'UPDATE', $old, $new);
Audit::undo($pdo, lastLogId($pdo));

$qty = $pdo->query("SELECT quantity FROM stock_items WHERE id = $item")->fetchColumn();
check(floatval($qty) == 100.0, "undo UPDATE restores previous quantity");

// ── Undo the undo: quantity goes back to 40 ───────────────────────────────────
Audit::undo($pdo, lastLogId($pdo));
$qty = $pdo->query("SELECT quantity FROM stock_items WHERE id = $item")->fetchColumn();
check(floatval($qty) == 40.0, "undoing the undo re-applies the change");

// ── Undo DELETE: record re-inserted with its original id ──────────────────────
$row = $pdo->query("SELECT * FROM stock_items WHERE id = $item")->fetch();
$pdo->exec("DELETE FROM stock_items WHERE id = $item");
Audit::log($pdo, 'stock_items', $item, 'DELETE', $row, null);
Audit::undo($pdo, lastLogId($pdo));

$back = $pdo->query("SELECT * FROM stock_items WHERE id = $item")->fetch();
check($back && $back['name'] === 'Widget', "undo DELETE re-inserts the record with its original id");

// ── Undo a CoC completion: custody.php records old values since v0.18, so
//    the Admin "Undo" can revert a completion back to In Progress ─────────────
$pdo->exec("INSERT INTO chain_of_custody (form_date, destination, transported_by, coc_items, status)
    VALUES ('2026-08-15', 'Undo Test Pharmacy', 'Tester', '[]', 'In Progress')");
$cid = $pdo->lastInsertId();
$old_coc = ['received_by' => null, 'signature_image' => null, 'status' => 'In Progress', 'completed_at' => null];
$pdo->exec("UPDATE chain_of_custody SET status='Completed', received_by='Test Receiver',
    signature_image='data:image/png;base64,TESTSIG', completed_at=CURRENT_TIMESTAMP WHERE id = $cid");
Audit::log($pdo, 'chain_of_custody', $cid, 'UPDATE', $old_coc,
    ['received_by' => 'Test Receiver', 'signature_image' => 'data:image/png;base64,TESTSIG', 'status' => 'Completed']);
Audit::undo($pdo, lastLogId($pdo));

$coc = $pdo->query("SELECT status, received_by, signature_image, completed_at FROM chain_of_custody WHERE id = $cid")->fetch();
check($coc['status'] === 'In Progress' && $coc['received_by'] === null && $coc['signature_image'] === null,
    "undo completion reverts to In Progress and clears receiver + signature");

// ── Refusals: bad table, missing old_values, missing record ───────────────────
Audit::log($pdo, 'audit_log', 1, 'UPDATE', ['x' => 1], ['x' => 2]);
try {
    Audit::undo($pdo, lastLogId($pdo));
    check(false, "undo refuses non-application tables");
} catch (Exception $e) {
    check(true, "undo refuses non-application tables");
}

Audit::log($pdo, 'chain_of_custody', 999, 'UPDATE', null, ['status' => 'Completed']);
try {
    Audit::undo($pdo, lastLogId($pdo));
    check(false, "undo refuses UPDATE with no old_values");
} catch (Exception $e) {
    check(true, "undo refuses UPDATE with no old_values");
}

try {
    Audit::undo($pdo, 999999);
    check(false, "undo refuses a missing log entry");
} catch (Exception $e) {
    check(true, "undo refuses a missing log entry");
}

exit($fail);
PHP

if php "$TMP/test.php"; then
    echo "Audit undo test OK"
else
    echo "Audit undo test FAILED" >&2
    exit 1
fi
