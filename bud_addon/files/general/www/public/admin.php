<?php
require_once 'config.php';
require_once 'includes/audit.php';

$message = '';
$message_error = false;

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'undo') {
        $log_id = $_POST['log_id'] ?? 0;
        try {
            Audit::undo($pdo, $log_id);
            $message = "Undid the last action.";
            // Audit::undo writes the reversal to the audit log itself
            // (changed_by = 'UNDO'), so the chain stays complete.
        } catch (Exception $e) {
            $message = "Couldn't undo it: " . $e->getMessage();
            $message_error = true;
        }
    } elseif ($action === 'restore') {
        if (isset($_FILES['db_file']) && $_FILES['db_file']['error'] === UPLOAD_ERR_OK) {
            $uploadPath = $_FILES['db_file']['tmp_name'];
            $targetPath = $db_file; // From config.php (e.g. database/bud.db)

            // Validate it's a sqlite file (magical header check or extension)
            // Header for SQLite 3 is "SQLite format 3\000"
            $handle = fopen($uploadPath, 'rb');
            $header = fread($handle, 16);
            fclose($handle);

            if ($header === "SQLite format 3\0") {
                // Perform the swap
                // Close current PDO connection to release lock?
                $pdo = null;
                gc_collect_cycles(); // Force cleanup

                if (copy($uploadPath, $targetPath)) {
                    $message = "Restored the database. The page reloads in a moment.";
                    // Reconnect
                    header("Refresh: 2");
                } else {
                    $message = "Couldn't copy the database file into place. Check the app's storage permissions.";
                    $message_error = true;
                }
            } else {
                $message = "That file isn't a BUD database (an SQLite file).";
                $message_error = true;
            }
        } else {
            $message = "The upload didn't work. Choose the .db file and try again.";
            $message_error = true;
        }
    }
} elseif (isset($_GET['action']) && $_GET['action'] === 'download') {
    $file = $db_file;
    if (file_exists($file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="bud_backup_' . date('Y-m-d_H-i') . '.db"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    } else {
        $message = "The database file wasn't found.";
        $message_error = true;
    }
} elseif (isset($_GET['action']) && $_GET['action'] === 'export_json') {
    // Export entire database as JSON for debugging
    $tables = [
        'stock_items',
        'product_bundles',
        'bundle_items',
        'chain_of_custody',
        'verified_receivers',
        'suppliers',
        'destruction_log',
        'products',
        's29_imports',
        's29_supplies',
        'settings',
    ];

    $export = ['exported_at' => date('Y-m-d H:i:s T'), 'tables' => []];

    foreach ($tables as $table) {
        try {
            $rows = $pdo->query("SELECT * FROM $table")->fetchAll();
            $export['tables'][$table] = $rows;
        } catch (Exception $e) {
            $export['tables'][$table] = ['error' => $e->getMessage()];
        }
    }

    // Audit log: last 100 entries (can be large)
    try {
        $rows = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 100")->fetchAll();
        $export['tables']['audit_log_last_100'] = $rows;
    } catch (Exception $e) {
        $export['tables']['audit_log_last_100'] = ['error' => $e->getMessage()];
    }

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="bud_export_' . date('Y-m-d_H-i') . '.json"');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Stock Integrity Check ─────────────────────────────────────────────────────
$verify_results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_stock') {
    try {
        // 1. Current stock
        $stock_rows = $pdo->query("SELECT id, name, sku, quantity, unit FROM stock_items")->fetchAll();
        $stock_map = [];
        foreach ($stock_rows as $s) {
            $stock_map[$s['id']] = $s;
        }

        // 2. Bundle component lookup
        $bundle_comp_rows = $pdo->query("SELECT bundle_id, stock_item_id, quantity FROM bundle_items")->fetchAll();
        $bundle_comps = [];
        foreach ($bundle_comp_rows as $bc) {
            $bundle_comps[$bc['bundle_id']][] = $bc;
        }

        // 3. Total COC deductions per stock item (stock is deducted at initiation, all statuses count)
        $coc_rows = $pdo->query("SELECT coc_items FROM chain_of_custody")->fetchAll();
        $deductions = []; // stock_item_id => total qty deducted
        foreach ($coc_rows as $coc) {
            $items = json_decode($coc['coc_items'], true) ?: [];
            foreach ($items as $item) {
                $dispatch_qty = floatval($item['qty'] ?? 0);
                if (strpos($item['item_id'], 'bundle_') === 0) {
                    $bid = (int) str_replace('bundle_', '', $item['item_id']);
                    foreach ($bundle_comps[$bid] ?? [] as $comp) {
                        $sid = (int) $comp['stock_item_id'];
                        $deductions[$sid] = ($deductions[$sid] ?? 0) + (floatval($comp['quantity']) * $dispatch_qty);
                    }
                } else {
                    $sid = (int) $item['item_id'];
                    $deductions[$sid] = ($deductions[$sid] ?? 0) + $dispatch_qty;
                }
            }
        }

        // 4. Original quantities from first INSERT audit entry per stock item
        $insert_rows = $pdo->query("
            SELECT record_id, new_values FROM audit_log
            WHERE table_name = 'stock_items' AND action = 'INSERT'
            ORDER BY id ASC
        ")->fetchAll();
        $original_qty = [];
        foreach ($insert_rows as $r) {
            $rid = (int) $r['record_id'];
            if (!isset($original_qty[$rid])) {
                $nv = json_decode($r['new_values'], true);
                $original_qty[$rid] = floatval($nv['quantity'] ?? 0);
            }
        }

        // 5. Stock additions (UPDATE where qty increased, excluding COC deductions)
        $update_rows = $pdo->query("
            SELECT record_id, old_values, new_values FROM audit_log
            WHERE table_name = 'stock_items' AND action = 'UPDATE'
            ORDER BY id ASC
        ")->fetchAll();
        $additions = [];
        foreach ($update_rows as $r) {
            $rid = (int) $r['record_id'];
            $ov = json_decode($r['old_values'], true);
            $nv = json_decode($r['new_values'], true);
            $old_q = floatval($ov['quantity'] ?? 0);
            $new_q = floatval($nv['quantity'] ?? 0);
            if ($new_q > $old_q) {
                $additions[$rid] = ($additions[$rid] ?? 0) + ($new_q - $old_q);
            }
        }

        // 6. Build results table
        $verify_results = [];
        foreach ($stock_map as $id => $s) {
            $start    = $original_qty[$id] ?? null;
            $added    = $additions[$id] ?? 0;
            $shipped  = $deductions[$id] ?? 0;
            $expected = ($start !== null) ? ($start + $added - $shipped) : null;
            $actual   = floatval($s['quantity']);
            $status   = ($expected !== null && abs($expected - $actual) < 0.01) ? 'OK' : (($expected === null) ? 'NO AUDIT' : 'MISMATCH');

            $verify_results[] = [
                'name'     => $s['name'],
                'sku'      => $s['sku'],
                'unit'     => $s['unit'],
                'start'    => $start,
                'added'    => $added,
                'shipped'  => $shipped,
                'expected' => $expected,
                'actual'   => $actual,
                'status'   => $status,
            ];
        }
    } catch (Exception $e) {
        $message = "The check didn't finish: " . $e->getMessage();
        $message_error = true;
    }
}

// Fetch Last Action
$last_action = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 1")->fetch();

$pageTitle = 'Admin tools';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Admin tools</h1>
            <p>For fixing a mistake or moving BUD's data. Home Assistant's own backups already include everything, so you'll rarely need these.</p>
        </hgroup>

        <?= bud_message($message, $message_error) ?>

        <div class="grid">
            <!-- Undo -->
            <article>
                <h2>Last action</h2>
                <?php if ($last_action): ?>
                    <div class="modal-summary">
                        <dl>
                            <dt>Action</dt><dd><?= h($last_action['action']) ?> on <?= h($last_action['table_name']) ?></dd>
                            <dt>When</dt><dd><?= h(bud_datetime($last_action['timestamp'])) ?></dd>
                            <?php if ($last_action['changed_by'] && $last_action['changed_by'] !== 'SYSTEM'): ?>
                                <dt>By</dt><dd><?= h($last_action['changed_by']) ?></dd>
                            <?php endif; ?>
                        </dl>
                        <details>
                            <summary>Details</summary>
                            <pre><?= h($last_action['new_values'] ?: $last_action['old_values']) ?></pre>
                        </details>
                    </div>
                    <form method="POST"
                        data-confirm-title="Undo the last action?"
                        data-confirm-message="It puts the record back the way it was before. The undo is saved in the audit log too."
                        data-confirm-label="Undo"
                        data-confirm-danger>
                        <input type="hidden" name="action" value="undo">
                        <input type="hidden" name="log_id" value="<?= h($last_action['id']) ?>">
                        <button type="submit" class="danger">Undo last action</button>
                    </form>
                <?php else: ?>
                    <p class="empty-note">Nothing has been recorded yet.</p>
                <?php endif; ?>
            </article>

            <!-- Backup and restore -->
            <article>
                <h2>Backup and restore</h2>
                <h3>Backup</h3>
                <p class="panel-note">Download a copy of the database as it is now.</p>
                <div class="form-actions">
                    <a href="?action=download" role="button">Download .db file</a>
                    <a href="?action=export_json" role="button" class="secondary outline">Export as JSON</a>
                </div>

                <hr>

                <h3>Restore</h3>
                <div class="callout callout--danger">
                    <p>Restoring replaces everything in BUD with the file you upload, straight away.</p>
                </div>
                <form method="POST" enctype="multipart/form-data"
                    data-confirm-title="Replace the whole database?"
                    data-confirm-message="Everything in BUD now is replaced by the file you chose. Download a backup first if you might need today's data."
                    data-confirm-label="Replace database"
                    data-confirm-danger>
                    <input type="hidden" name="action" value="restore">
                    <label>Database file
                        <input type="file" name="db_file" accept=".db,.sqlite,.sqlite3" required>
                    </label>
                    <button type="submit" class="danger">Replace database</button>
                </form>
            </article>
        </div>

        <!-- Stock integrity check -->
        <article>
            <h2>Stock integrity check</h2>
            <p class="panel-note">Works out what each item should have on hand from its history and the chain of custody transfers, then compares it with what BUD shows.</p>
            <form method="POST">
                <input type="hidden" name="action" value="verify_stock">
                <button type="submit" class="secondary outline">Run the check</button>
            </form>

            <?php if ($verify_results !== null): ?>
                <div class="table-wrap">
                    <table class="wide-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="num">Starting</th>
                                <th class="num">Added</th>
                                <th class="num">Shipped</th>
                                <th class="num">Expected</th>
                                <th class="num">On hand</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($verify_results as $vr): ?>
                                <tr>
                                    <td class="cell-long"><?= h($vr['name']) ?> <small><?= h($vr['sku']) ?></small></td>
                                    <td class="num"><?= $vr['start'] !== null ? h(bud_qty($vr['start'])) : '?' ?></td>
                                    <td class="num"><?= $vr['added'] > 0 ? '+' . h(bud_qty($vr['added'])) : '0' ?></td>
                                    <td class="num"><?= $vr['shipped'] > 0 ? BUD_MINUS . h(bud_qty($vr['shipped'])) : '0' ?></td>
                                    <td class="num"><strong><?= $vr['expected'] !== null ? h(bud_qty($vr['expected'])) : '?' ?></strong></td>
                                    <td class="num"><strong><?= h(bud_qty($vr['actual'])) ?></strong> <?= h($vr['unit']) ?></td>
                                    <td>
                                        <?php if ($vr['status'] === 'OK'): ?>
                                            <span class="badge">Matches</span>
                                        <?php elseif ($vr['status'] === 'MISMATCH'): ?>
                                            <span class="badge badge--danger">Doesn't match</span>
                                        <?php else: ?>
                                            <span class="badge badge--todo">No history</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
    </main>
<?php require 'includes/footer.php'; ?>
