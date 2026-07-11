<?php
require_once 'config.php';
require_once 'includes/audit.php';

$message = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'undo') {
        $log_id = $_POST['log_id'] ?? 0;
        try {
            Audit::undo($pdo, $log_id);
            $message = "Action undone successfully.";
            // Audit::undo writes the reversal to the audit log itself
            // (changed_by = 'UNDO'), so the chain stays complete.
        } catch (Exception $e) {
            $message = "Error undoing action: " . $e->getMessage();
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
                    $message = "Database restored successfully. PLEASE REFRESH.";
                    // Reconnect
                    header("Refresh: 2");
                } else {
                    $message = "Failed to copy database file. Check permissions.";
                }
            } else {
                $message = "Invalid SQLite file uploaded.";
            }
        } else {
            $message = "Upload failed.";
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
        $message = "Database file not found.";
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
        'cleaning_schedules',
        'cleaning_logs',
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
        $message = "Verify error: " . $e->getMessage();
    }
}

// Fetch Last Action
$last_action = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 1")->fetch();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?= APP_NAME ?> - Admin
    </title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .danger-zone {
            border: 1px solid #ef4444;
            background: rgba(239, 68, 68, 0.1);
        }
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="container">
        <h1>Admin Dashboard</h1>

        <?php if ($message): ?>
            <div class="glass-panel" style="margin-bottom: 1rem; border-color: var(--accent-color);">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 2rem;">
            <!-- Undo Section -->
            <div class="glass-panel">
                <h3>⏮️ Last Action</h3>
                <?php if ($last_action): ?>
                    <div style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                        <p><strong>Action:</strong>
                            <?= h($last_action['action']) ?> on
                            <?= h($last_action['table_name']) ?>
                        </p>
                        <p><small>Time:
                                <?= h($last_action['timestamp']) ?>
                            </small></p>
                        <details>
                            <summary>Details</summary>
                            <pre
                                style="font-size: 0.75rem; text-align: left; overflow-x: auto;"><?= h($last_action['new_values'] ?: $last_action['old_values']) ?></pre>
                        </details>
                    </div>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to undo this action?');">
                        <input type="hidden" name="action" value="undo">
                        <input type="hidden" name="log_id" value="<?= $last_action['id'] ?>">
                        <button type="submit" class="btn" style="background: #ef4444;">Undo Last Action</button>
                    </form>
                <?php else: ?>
                    <p>No actions recorded.</p>
                <?php endif; ?>
            </div>

            <!-- Database Management -->
            <div class="glass-panel">
                <h3>💾 Database Management</h3>

                <div style="margin-bottom: 2rem;">
                    <h4>Backup</h4>
                    <p>Download a snapshot of the current database.</p>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <a href="?action=download" class="btn" style="background: var(--success-color, #10b981);">Download
                            .db File</a>
                        <a href="?action=export_json" class="btn" style="background: var(--primary-color, #3b82f6);">Export
                            as JSON</a>
                    </div>
                </div>

                <hr style="border-color: rgba(255,255,255,0.1); margin: 1.5rem 0;">

                <div>
                    <h4>Restore</h4>
                    <p class="text-danger" style="color: #ef4444;">⚠️ Warning: This will overwrite the current database
                        immediately.</p>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="restore">
                        <input type="file" name="db_file" accept=".db,.sqlite,.sqlite3" required
                            style="margin-bottom: 1rem;">
                        <br>
                        <button type="submit" class="btn" style="background: #ef4444;"
                            onclick="return confirm('EXTREME DANGER: This will wipe the current data and replace it with the uploaded file. Are you absolutely sure?');">Replace
                            Database</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Stock Integrity Check -->
        <div class="glass-panel" style="margin-top: 2rem;">
            <h3>🔍 Stock Integrity Check</h3>
            <p style="font-size: 0.9rem; color: var(--text-muted, #aaa); margin-bottom: 1rem;">
                Recalculates expected stock quantities from audit history and COC deductions, then compares with actual values.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="verify_stock">
                <button type="submit" class="btn">Run Verification</button>
            </form>

            <?php if ($verify_results !== null): ?>
                <div class="table-responsive" style="margin-top: 1.5rem;">
                    <table style="font-size: 0.85rem;">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Starting</th>
                                <th>Added</th>
                                <th>Shipped</th>
                                <th>Expected</th>
                                <th>Actual</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($verify_results as $vr): ?>
                                <tr>
                                    <td><?= h($vr['name']) ?> <small style="color: var(--text-muted, #888);">(<?= h($vr['sku']) ?>)</small></td>
                                    <td><?= $vr['start'] !== null ? h($vr['start']) : '<em>?</em>' ?></td>
                                    <td><?= $vr['added'] > 0 ? '+' . h($vr['added']) : '0' ?></td>
                                    <td><?= $vr['shipped'] > 0 ? '-' . h($vr['shipped']) : '0' ?></td>
                                    <td><strong><?= $vr['expected'] !== null ? h($vr['expected']) : '?' ?></strong></td>
                                    <td><strong><?= h($vr['actual']) ?></strong> <?= h($vr['unit']) ?></td>
                                    <td>
                                        <?php if ($vr['status'] === 'OK'): ?>
                                            <span style="color: #10b981; font-weight: 600;">OK</span>
                                        <?php elseif ($vr['status'] === 'MISMATCH'): ?>
                                            <span style="color: #ef4444; font-weight: 600;">MISMATCH</span>
                                        <?php else: ?>
                                            <span style="color: #eab308;">NO AUDIT</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>