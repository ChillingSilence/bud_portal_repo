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
    } elseif ($action === 'upgrade_schema_v013') {
        try {
            require_once 'migrate_v0.13.php';
            $message = "✅ Schema upgraded to v0.13 successfully. Receiver fields are now active.";
        } catch (Exception $e) {
            $message = "Error during migration: " . $e->getMessage();
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
        'time_logs',
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

        <!-- Schema Upgrades -->
        <div class="glass-panel" style="margin-top: 2rem; border-color: rgba(234, 179, 8, 0.4);">
            <h3>⬆️ Schema Upgrades <small style="font-size:0.8rem; font-weight:400; color: #eab308;">(interim — remove
                    in future build)</small></h3>
            <div style="display: flex; gap: 2rem; align-items: flex-start; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 250px;">
                    <h4>v0.13 — Verified Receivers + Two-Phase Chain of Custody</h4>
                    <p style="font-size: 0.9rem;">Adds <code>receiver_id</code> and <code>received_by</code> columns to
                        Chain of Custody records, creates the <code>verified_receivers</code> table, and backfills
                        existing completed records with <em>"Samantha"</em> as the receiver name.</p>
                    <p style="font-size: 0.85rem; color: #eab308;">⚠️ Safe to run multiple times — idempotent.</p>
                    <form method="POST"
                        onsubmit="return confirm('Run v0.13 schema upgrade? This is safe to run on an existing database.');">
                        <input type="hidden" name="action" value="upgrade_schema_v013">
                        <button type="submit" class="btn" style="background: #eab308; color: #000;">Upgrade to v0.13
                            Schema</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>

</html>