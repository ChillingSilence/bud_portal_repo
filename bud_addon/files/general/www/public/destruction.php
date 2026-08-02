<?php
require_once 'config.php';
require_once 'includes/audit.php';

$message = '';
$message_error = false;

// ── Record a destruction ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'destroy') {
    try {
        $item_id      = intval($_POST['item_id'] ?? 0);
        $qty          = floatval($_POST['qty'] ?? 0);
        $batch        = trim($_POST['batch'] ?? '');
        $reason       = trim($_POST['reason'] ?? '');
        $method       = trim($_POST['method'] ?? '');
        $destroyed_by = trim($_POST['destroyed_by'] ?? '');
        $witness      = trim($_POST['witness'] ?? '');
        $signature    = $_POST['signature_data'] ?? '';
        $notes        = trim($_POST['notes'] ?? '');

        $item_stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
        $item_stmt->execute([$item_id]);
        $item = $item_stmt->fetch();

        if (!$item) {
            $message = "Please select a valid stock item.";
            $message_error = true;
        } elseif ($qty <= 0) {
            $message = "Quantity to destroy must be greater than zero.";
            $message_error = true;
        } elseif ($qty > floatval($item['quantity'])) {
            $message = "Cannot destroy {$qty} — only {$item['quantity']} {$item['unit']} in stock for {$item['name']}.";
            $message_error = true;
        } elseif (!$reason || !$method || !$destroyed_by) {
            $message = "Reason, method and staff name are required.";
            $message_error = true;
        } else {
            // 1. Deduct the stock (audit-logged like every other adjustment)
            $old_stock = $item;
            $pdo->prepare("UPDATE stock_items SET quantity = quantity - ? WHERE id = ?")->execute([$qty, $item_id]);

            $new_stock_stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
            $new_stock_stmt->execute([$item_id]);
            $new_stock = $new_stock_stmt->fetch();

            // 2. Write the destruction register entry.
            //    item_name is denormalised so the record stays intact for the
            //    MCA even if the stock item is later renamed or deleted.
            $item_label = $item['name'] . ($item['sku'] ? ' (' . $item['sku'] . ')' : '');
            $ins = $pdo->prepare("INSERT INTO destruction_log
                (stock_item_id, item_name, batch, quantity, unit, reason, method, destroyed_by, witness, witness_signature, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $item_id,
                $item_label,
                $batch ?: null,
                $qty,
                $item['unit'],
                $reason,
                $method,
                $destroyed_by,
                $witness ?: null,
                $signature ?: null,
                $notes ?: null,
            ]);
            $dest_id = $pdo->lastInsertId();

            $new_stock['adjustment_notes'] = "Destroyed: $reason (Destruction Register #$dest_id)";
            Audit::log($pdo, 'stock_items', $item_id, 'UPDATE', $old_stock, $new_stock);
            Audit::log($pdo, 'destruction_log', $dest_id, 'INSERT', null, [
                'item'         => $item_label,
                'batch'        => $batch,
                'quantity'     => $qty,
                'reason'       => $reason,
                'method'       => $method,
                'destroyed_by' => $destroyed_by,
                'witness'      => $witness,
            ]);

            $message = "Recorded destruction of {$qty} {$item['unit']} of {$item_label} (Register #$dest_id).";
        }
    } catch (Exception $e) {
        $message = "Error recording destruction: " . $e->getMessage();
        $message_error = true;
    }
}

// ── Register (filter by month, empty = everything) ────────────────────────────
$filter_month = $_GET['month'] ?? '';
if ($filter_month !== '' && !preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
    $filter_month = '';
}

if ($filter_month !== '') {
    $reg_stmt = $pdo->prepare("SELECT * FROM destruction_log WHERE destroyed_at LIKE ? ORDER BY destroyed_at DESC");
    $reg_stmt->execute(["$filter_month%"]);
} else {
    $reg_stmt = $pdo->query("SELECT * FROM destruction_log ORDER BY destroyed_at DESC");
}
$register = $reg_stmt->fetchAll();

// Stock items for the form (anything in stock; controlled items flagged)
$stock_options = $pdo->query("SELECT id, name, sku, quantity, unit, is_controlled
    FROM stock_items WHERE quantity > 0 ORDER BY is_controlled DESC, name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Destruction Register</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        #sig-pad-destroy {
            border: 2px dashed var(--card-border);
            border-radius: 0.5rem;
            background: rgba(255, 255, 255, 0.5);
            cursor: crosshair;
            width: 100%;
        }
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="container">
        <h1>Destruction Register</h1>
        <p style="color: var(--text-muted, #aaa); font-size: 0.9rem; margin-bottom: 1.5rem;">
            Permanent record of destroyed stock (expired, damaged or otherwise unusable) for
            Medicinal Cannabis Agency compliance. Register entries cannot be edited or deleted.
        </p>

        <?php if ($message): ?>
            <div class="glass-panel"
                style="margin-bottom: 1rem; border-color: <?= $message_error ? '#ef4444' : 'var(--accent-color)' ?>;">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <button
            onclick="document.getElementById('destroyForm').style.display = document.getElementById('destroyForm').style.display === 'none' ? 'block' : 'none'"
            class="btn" style="margin-bottom: 1rem; background: #ef4444;">
            🔥 Record Destruction
        </button>

        <!-- Destruction Form -->
        <div id="destroyForm" class="glass-panel" style="display: none; margin-bottom: 2rem;">
            <h3>Record Destruction</h3>
            <p style="color: var(--text-muted, #aaa); font-size: 0.9rem;">Stock is deducted immediately and a
                permanent register entry is created.</p>
            <form method="POST" id="destructionForm">
                <input type="hidden" name="action" value="destroy">
                <input type="hidden" name="signature_data" id="destroy_signature_data">

                <div class="grid">
                    <div>
                        <label>Item</label>
                        <select name="item_id" required>
                            <option value="">Select Item...</option>
                            <?php foreach ($stock_options as $opt): ?>
                                <option value="<?= $opt['id'] ?>">
                                    <?= $opt['is_controlled'] ? '⚠️ ' : '' ?><?= h($opt['name']) ?>
                                    (<?= h($opt['sku']) ?>) — <?= h($opt['quantity']) ?> <?= h($opt['unit']) ?> in stock
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Quantity to Destroy</label>
                        <input type="number" step="0.01" min="0.01" name="qty" required>
                    </div>
                    <div>
                        <label>Batch / Lot #</label>
                        <input type="text" name="batch" placeholder="e.g. B2026-041">
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label>Reason</label>
                        <select name="reason" required>
                            <option value="">Select Reason...</option>
                            <option>Expired / past use-by date</option>
                            <option>Damaged</option>
                            <option>Contaminated</option>
                            <option>Quality failure / recall</option>
                            <option>Other (see notes)</option>
                        </select>
                    </div>
                    <div>
                        <label>Method of Destruction</label>
                        <select name="method" required>
                            <option value="">Select Method...</option>
                            <option>Incineration</option>
                            <option>Denatured &amp; disposed (rendered unusable)</option>
                            <option>Returned to supplier for destruction</option>
                            <option>Other (see notes)</option>
                        </select>
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label>Destroyed By (Staff Name)</label>
                        <input type="text" name="destroyed_by" required>
                    </div>
                    <div>
                        <label>Witness Name</label>
                        <input type="text" name="witness" placeholder="Second staff member or authorised person">
                    </div>
                </div>

                <label>Notes</label>
                <input type="text" name="notes" placeholder="Optional additional details">

                <h4 style="margin-top: 1.5rem;">Witness Signature</h4>
                <p><small>Optional but recommended — sign to witness the destruction.</small></p>
                <canvas id="sig-pad-destroy" width="600" height="180"></canvas>
                <button type="button" onclick="clearDestroySignature()"
                    style="font-size: 0.8rem; margin-top: 0.5rem;">Clear Signature</button>

                <div style="margin-top: 2rem;">
                    <button type="submit" class="btn" style="background: #ef4444;"
                        onclick="return confirmDestroy()">🔥 Destroy &amp; Record Permanently</button>
                </div>
            </form>
        </div>

        <!-- Register -->
        <div class="glass-panel">
            <div
                style="display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <h3 style="margin: 0;">📋 Register</h3>
                    <p style="margin: 0;"><small><?= count($register) ?> record<?= count($register) !== 1 ? 's' : '' ?><?= $filter_month ? ' in ' . h($filter_month) : ' (all time)' ?></small></p>
                </div>
                <form method="GET" style="display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap;">
                    <div>
                        <label style="font-size: 0.8rem;">Month</label>
                        <input type="month" name="month" value="<?= h($filter_month) ?>">
                    </div>
                    <button type="submit" class="btn" style="font-size: 0.85rem;">Filter</button>
                    <?php if ($filter_month): ?>
                        <a href="destruction.php" class="btn"
                            style="font-size: 0.85rem; background: transparent; border: 1px solid var(--card-border); color: var(--text-color);">All</a>
                    <?php endif; ?>
                    <button type="button" onclick="exportDestructionCsv()" class="btn"
                        style="font-size: 0.85rem; background: transparent; border: 1px solid var(--primary-color); color: var(--primary-color) !important;">⬇
                        Export CSV</button>
                </form>
            </div>

            <?php if (empty($register)): ?>
                <p>No destruction records<?= $filter_month ? ' for this month' : '' ?>.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="destruction-table" style="font-size: 0.9rem;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Item</th>
                                <th>Batch</th>
                                <th>Qty</th>
                                <th>Reason</th>
                                <th>Method</th>
                                <th>Destroyed By</th>
                                <th>Witness</th>
                                <th data-nocsv>Signature</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($register as $r): ?>
                                <tr>
                                    <td><?= $r['id'] ?></td>
                                    <td style="white-space: nowrap;"><?= h($r['destroyed_at']) ?></td>
                                    <td><?= h($r['item_name']) ?><?= $r['notes'] ? '<br><small style="color: var(--text-muted, #aaa);">' . h($r['notes']) . '</small>' : '' ?></td>
                                    <td><?= h($r['batch'] ?: '-') ?></td>
                                    <td style="color: #ef4444;">-<?= h($r['quantity']) ?> <?= h($r['unit']) ?></td>
                                    <td><?= h($r['reason']) ?></td>
                                    <td><?= h($r['method']) ?></td>
                                    <td><?= h($r['destroyed_by']) ?></td>
                                    <td><?= h($r['witness'] ?: '-') ?></td>
                                    <td data-nocsv>
                                        <?php if ($r['witness_signature']): ?>
                                            <button onclick='viewSignature(<?= json_encode($r['witness_signature']) ?>)'
                                                class="btn" style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">View</button>
                                        <?php else: ?>
                                            -
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

    <!-- Signature View Modal -->
    <div id="sigModal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 100; backdrop-filter: blur(5px);">
        <div class="glass-panel" style="margin: 15vh auto; max-width: 640px; background: white; position: relative;">
            <button onclick="document.getElementById('sigModal').style.display='none'"
                style="position: absolute; right: 1rem; top: 1rem; background: transparent; color: black; border: 1px solid black;">✕
                Close</button>
            <h3 style="color: black;">Witness Signature</h3>
            <img id="sig-image" src="" alt="Witness signature"
                style="max-width: 100%; height: auto; border: 1px solid #ccc;">
        </div>
    </div>

    <script>
        // ── Witness signature pad ────────────────────────────────────────────
        const destroyCanvas = document.getElementById('sig-pad-destroy');
        const destroyCtx = destroyCanvas.getContext('2d');
        let destroyPainting = false;

        function startDestroy(e) { destroyPainting = true; drawDestroy(e); }
        function endDestroy() { destroyPainting = false; destroyCtx.beginPath(); }
        function drawDestroy(e) {
            if (!destroyPainting) return;
            e.preventDefault();
            const rect = destroyCanvas.getBoundingClientRect();
            const scaleX = destroyCanvas.width / rect.width;
            const scaleY = destroyCanvas.height / rect.height;
            const x = ((e.clientX || e.touches[0].clientX) - rect.left) * scaleX;
            const y = ((e.clientY || e.touches[0].clientY) - rect.top) * scaleY;
            destroyCtx.lineWidth = 2;
            destroyCtx.lineCap = 'round';
            destroyCtx.strokeStyle = '#000';
            destroyCtx.lineTo(x, y);
            destroyCtx.stroke();
            destroyCtx.beginPath();
            destroyCtx.moveTo(x, y);
        }
        destroyCanvas.addEventListener('mousedown', startDestroy);
        destroyCanvas.addEventListener('mouseup', endDestroy);
        destroyCanvas.addEventListener('mousemove', drawDestroy);
        destroyCanvas.addEventListener('touchstart', startDestroy);
        destroyCanvas.addEventListener('touchend', endDestroy);
        destroyCanvas.addEventListener('touchmove', drawDestroy);

        function clearDestroySignature() {
            destroyCtx.clearRect(0, 0, destroyCanvas.width, destroyCanvas.height);
        }

        function canvasIsBlank(canvas) {
            const blank = document.createElement('canvas');
            blank.width = canvas.width;
            blank.height = canvas.height;
            return canvas.toDataURL() === blank.toDataURL();
        }

        function confirmDestroy() {
            if (!confirm('Destroy this stock and create a permanent register entry? This cannot be undone.')) {
                return false;
            }
            document.getElementById('destroy_signature_data').value =
                canvasIsBlank(destroyCanvas) ? '' : destroyCanvas.toDataURL();
            return true;
        }

        // ── Signature viewer ─────────────────────────────────────────────────
        function viewSignature(dataUrl) {
            document.getElementById('sig-image').src = dataUrl;
            document.getElementById('sigModal').style.display = 'block';
        }

        // ── CSV export ───────────────────────────────────────────────────────
        function exportDestructionCsv() {
            const table = document.getElementById('destruction-table');
            if (!table) { alert('No data to export.'); return; }

            let csv = [];
            for (const row of table.rows) {
                const cols = Array.from(row.cells)
                    .filter(c => !c.hasAttribute('data-nocsv'))
                    .map(c => '"' + c.innerText.replace(/"/g, '""') + '"');
                csv.push(cols.join(','));
            }

            const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'Destruction_Register<?= $filter_month ? '_' . $filter_month : '' ?>.csv';
            a.click();
            URL.revokeObjectURL(url);
        }
    </script>
</body>

</html>
