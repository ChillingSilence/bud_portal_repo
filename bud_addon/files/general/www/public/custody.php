<?php
require_once 'config.php';
require_once 'includes/audit.php';

$message = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // -------------------------------------------------------
    // PHASE 1: Initiate Transfer (stock deducted, no signature)
    // -------------------------------------------------------
    if ($action === 'create_coc') {
        try {
            $date = $_POST['date'];
            $origin = $_POST['origin'];
            $receiver_id = intval($_POST['receiver_id'] ?? 0);
            $transported_by = $_POST['transported_by'];

            // Resolve destination from verified_receivers
            $dest_stmt = $pdo->prepare("SELECT name, address FROM verified_receivers WHERE id = ?");
            $dest_stmt->execute([$receiver_id]);
            $receiver_row = $dest_stmt->fetch();
            $destination = $receiver_row ? $receiver_row['name'] : 'Unknown';

            // Items
            $item_ids = $_POST['item_id'] ?? [];
            $qtys = $_POST['qty'] ?? [];
            $batches = $_POST['batch'] ?? [];

            $coc_items = [];
            for ($i = 0; $i < count($item_ids); $i++) {
                if ($item_ids[$i] && $qtys[$i] > 0) {
                    $coc_items[] = [
                        'item_id' => $item_ids[$i],
                        'qty' => $qtys[$i],
                        'batch' => $batches[$i]
                    ];

                    $item_id = $item_ids[$i];
                    $qty = $qtys[$i];

                    // Bundle deductions
                    if (strpos($item_id, 'bundle_') === 0) {
                        $bundle_id = str_replace('bundle_', '', $item_id);

                        $b_stmt = $pdo->prepare("SELECT name FROM product_bundles WHERE id = ?");
                        $b_stmt->execute([$bundle_id]);
                        $b_name = $b_stmt->fetchColumn();

                        $bundle_components = $pdo->prepare("SELECT stock_item_id, quantity FROM bundle_items WHERE bundle_id = ?");
                        $bundle_components->execute([$bundle_id]);
                        $components = $bundle_components->fetchAll();

                        foreach ($components as $component) {
                            $component_id = $component['stock_item_id'];
                            $component_qty_per_bundle = $component['quantity'];
                            $total_component_qty = $component_qty_per_bundle * $qty;

                            $old_stock_stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
                            $old_stock_stmt->execute([$component_id]);
                            $old_stock = $old_stock_stmt->fetch();

                            if ($old_stock) {
                                $pdo->prepare("UPDATE stock_items SET quantity = quantity - ? WHERE id = ?")->execute([$total_component_qty, $component_id]);

                                $new_stock_stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
                                $new_stock_stmt->execute([$component_id]);
                                $new_stock = $new_stock_stmt->fetch();
                                $new_stock['adjustment_notes'] = "Deducted via Bundle: $b_name (COC Shipment)";
                                Audit::log($pdo, 'stock_items', $component_id, 'UPDATE', $old_stock, $new_stock);
                            }
                        }
                    } else {
                        // Regular stock item
                        $old_stock_stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
                        $old_stock_stmt->execute([$item_id]);
                        $old_stock = $old_stock_stmt->fetch();

                        if ($old_stock) {
                            $pdo->prepare("UPDATE stock_items SET quantity = quantity - ? WHERE id = ?")->execute([$qty, $item_id]);

                            $new_stock_stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
                            $new_stock_stmt->execute([$item_id]);
                            $new_stock = $new_stock_stmt->fetch();
                            $new_stock['adjustment_notes'] = "Sent via Chain of Custody";
                            Audit::log($pdo, 'stock_items', $item_id, 'UPDATE', $old_stock, $new_stock);
                        }
                    }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO chain_of_custody
                (form_date, origin, destination, receiver_id, transported_by, coc_items, status)
                VALUES (?, ?, ?, ?, ?, ?, 'In Progress')");
            $stmt->execute([$date, $origin, $destination, $receiver_id ?: null, $transported_by, json_encode($coc_items)]);
            $id = $pdo->lastInsertId();

            Audit::log($pdo, 'chain_of_custody', $id, 'INSERT', null, $_POST);
            $message = "Transfer initiated. Stock deducted. Mark as received when the destination signs.";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }

    // -------------------------------------------------------
    // PHASE 2: Complete Transfer (signature + received_by)
    // -------------------------------------------------------
    elseif ($action === 'complete_coc') {
        try {
            $coc_id = intval($_POST['coc_id']);
            $received_by = trim($_POST['received_by'] ?? '');
            $signature = $_POST['signature_data'] ?? '';

            if (!$coc_id || !$received_by || !$signature) {
                $message = "Error: Please provide the receiver name and signature.";
            } else {
                $stmt = $pdo->prepare("UPDATE chain_of_custody
                    SET status = 'Completed', received_by = ?, signature_image = ?, completed_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND status = 'In Progress'");
                $stmt->execute([$received_by, $signature, $coc_id]);
                Audit::log($pdo, 'chain_of_custody', $coc_id, 'UPDATE', null, ['received_by' => $received_by, 'status' => 'Completed']);
                $message = "Transfer marked as received and completed.";
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }

    // -------------------------------------------------------
    // PHASE 3: Mark as Invoiced (completed transfers only)
    // -------------------------------------------------------
    elseif ($action === 'mark_invoiced') {
        try {
            $coc_id = intval($_POST['coc_id']);
            $stmt = $pdo->prepare("UPDATE chain_of_custody
                SET invoiced_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'Completed' AND invoiced_at IS NULL");
            $stmt->execute([$coc_id]);

            if ($stmt->rowCount() > 0) {
                Audit::log($pdo, 'chain_of_custody', $coc_id, 'UPDATE', ['invoiced_at' => null], ['invoiced_at' => date('Y-m-d H:i:s')]);
                $message = "Transfer #$coc_id marked as invoiced.";
            } else {
                $message = "Transfer #$coc_id is not completed or was already invoiced.";
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
}

// Fetch History
$history = $pdo->query("SELECT * FROM chain_of_custody ORDER BY created_at DESC LIMIT 50")->fetchAll();

// Fetch controlled substances only
$stock_options = $pdo->query("SELECT id, name, sku, unit FROM stock_items WHERE quantity > 0 AND is_controlled = 1 ORDER BY name ASC")->fetchAll();

// Fetch active bundles
$bundle_options = $pdo->query("SELECT id, name, sku FROM product_bundles WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Fetch verified receivers for dropdown
$receivers = $pdo->query("SELECT id, name, contact_person, address FROM verified_receivers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Build JS item name lookup map
$item_name_map = [];
foreach ($stock_options as $s) {
    $item_name_map[(string) $s['id']] = $s['name'] . ' (' . $s['sku'] . ')';
}
foreach ($bundle_options as $b) {
    $item_name_map['bundle_' . $b['id']] = $b['name'] . ' (' . $b['sku'] . ')';
}

// Build JS receiver map (id -> {contact_person, address})
$receiver_map = [];
foreach ($receivers as $r) {
    $receiver_map[$r['id']] = [
        'contact' => $r['contact_person'],
        'address' => $r['address'],
        'name' => $r['name'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Chain of Custody</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        #signature-pad,
        #sig-pad-complete {
            border: 2px dashed var(--card-border);
            border-radius: 0.5rem;
            background: rgba(255, 255, 255, 0.5);
            cursor: crosshair;
            width: 100%;
        }

        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-complete {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .status-progress {
            background: rgba(234, 179, 8, 0.2);
            color: #eab308;
        }

        .status-invoice {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
        }
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="container">
        <div
            style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.25rem;">
            <h1 style="margin:0;">Chain of Custody</h1>
            <a href="receivers.php" style="font-size: 0.9rem; color: var(--primary-color);">⚙️ Manage Verified
                Receivers</a>
        </div>

        <?php if ($message): ?>
            <div class="glass-panel" style="margin-bottom: 1rem; border-color: var(--accent-color);">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <button
            onclick="document.getElementById('cocForm').style.display = document.getElementById('cocForm').style.display === 'none' ? 'block' : 'none'"
            class="btn" style="margin-bottom: 1rem;">
            + New Transfer Form
        </button>

        <!-- Phase 1 Form -->
        <div id="cocForm" class="glass-panel" style="display: none; margin-bottom: 2rem;">
            <h3>Initiate Transfer</h3>
            <p style="color: var(--text-muted, #aaa); font-size: 0.9rem;">Stock will be deducted immediately. The
                receiver will sign when you complete the transfer on-site.</p>
            <form method="POST" id="transferForm">
                <input type="hidden" name="action" value="create_coc">

                <div class="grid">
                    <div>
                        <label>Date</label>
                        <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label>Origin</label>
                        <input type="text" name="origin" value="Main Facility" required>
                    </div>
                    <div>
                        <label>Destination <a href="receivers.php" style="font-size:0.8rem; margin-left:0.5rem;">(+ add
                                receiver)</a></label>
                        <?php if (empty($receivers)): ?>
                            <p style="color: #eab308; font-size: 0.9rem;">⚠️ No verified receivers yet. <a
                                    href="receivers.php">Add one first.</a></p>
                        <?php else: ?>
                            <select name="receiver_id" id="receiver_select" onchange="updateReceiverHint(this.value)"
                                required>
                                <option value="">Select Destination...</option>
                                <?php foreach ($receivers as $r): ?>
                                    <option value="<?= $r['id'] ?>">
                                        <?= h($r['name']) ?>        <?= $r['contact_person'] ? ' — ' . h($r['contact_person']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small id="receiver-address-hint" style="color: var(--text-muted, #aaa);"></small>
                        <?php endif; ?>
                    </div>
                </div>

                <label>Transported By (Staff Name)</label>
                <input type="text" name="transported_by" required>

                <h4>Items Transferred</h4>
                <div id="items-container">
                    <div class="item-row grid" style="margin-bottom: 0.5rem;">
                        <div>
                            <select name="item_id[]" required>
                                <option value="">Select Item or Bundle</option>
                                <optgroup label="⚠️ Controlled Substances">
                                    <?php foreach ($stock_options as $opt): ?>
                                        <option value="<?= $opt['id'] ?>"><?= h($opt['name']) ?> (<?= h($opt['sku']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="📦 Bundles">
                                    <?php foreach ($bundle_options as $bundle): ?>
                                        <option value="bundle_<?= $bundle['id'] ?>"><?= h($bundle['name']) ?>
                                            (<?= h($bundle['sku']) ?>)</option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div>
                            <input type="text" name="batch[]" placeholder="Batch/Lot #">
                        </div>
                        <div>
                            <input type="number" step="0.01" name="qty[]" placeholder="Qty" required>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn" onclick="addItemRow()"
                    style="background: transparent; border: 1px solid var(--primary-color); color: var(--text-color);">+
                    Add Another Item</button>

                <div style="margin-top: 2rem;">
                    <button type="submit" class="btn">Initiate Transfer & Deduct Stock</button>
                </div>
            </form>
        </div>

        <!-- Transfers Table -->
        <div class="glass-panel">
            <h3>Recent Transfers</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>To</th>
                            <th>Transported By</th>
                            <th>Status</th>
                            <th>Items</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $row): ?>
                            <tr>
                                <td><?= h($row['form_date']) ?></td>
                                <td><?= h($row['destination']) ?></td>
                                <td><?= h($row['transported_by']) ?></td>
                                <td>
                                    <?php if ($row['status'] === 'Completed'): ?>
                                        <span class="status-badge status-complete">✓ Completed</span>
                                        <?php if (empty($row['invoiced_at'])): ?>
                                            <span class="status-badge status-invoice">🧾 Needs Invoice</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="status-badge status-progress">⏳ In Progress</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $items = json_decode($row['coc_items'], true);
                                    echo count($items) . ' item' . (count($items) !== 1 ? 's' : '');
                                    ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <?php if ($row['status'] === 'In Progress'): ?>
                                        <button onclick='openComplete(<?= json_encode($row) ?>)' class="btn"
                                            style="padding: 0.25rem 0.5rem; font-size: 0.8rem; background: #10b981;">Complete</button>
                                        <button onclick='printPackingSlip(<?= json_encode($row) ?>)' class="btn"
                                            style="padding: 0.25rem 0.5rem; font-size: 0.8rem; background: transparent; border: 1px solid var(--primary-color); color: var(--primary-color);">🖨
                                            Slip</button>
                                    <?php else: ?>
                                        <button onclick='viewCoc(<?= json_encode($row) ?>)' class="btn"
                                            style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">View</button>
                                        <?php if (empty($row['invoiced_at'])): ?>
                                            <button onclick='openInvoice(<?= json_encode($row) ?>)' class="btn"
                                                style="padding: 0.25rem 0.5rem; font-size: 0.8rem; background: #3b82f6;">🧾
                                                Invoiced</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- View Modal (Completed) -->
    <div id="viewModal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 100; backdrop-filter: blur(5px); overflow-y: auto;">
        <div class="glass-panel"
            style="margin: 5vh auto; max-width: 800px; background: white; color: black; position: relative;">
            <button onclick="document.getElementById('viewModal').style.display='none'"
                style="position: absolute; right: 1rem; top: 1rem; background: transparent; color: black; font-weight: bold; border: 1px solid black;">✕
                Close</button>
            <div id="coc-content" style="padding: 2rem;">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    <!-- Complete Modal (In Progress) -->
    <div id="completeModal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 100; backdrop-filter: blur(5px); overflow-y: auto;">
        <div class="glass-panel" style="margin: 5vh auto; max-width: 700px; position: relative;">
            <button onclick="document.getElementById('completeModal').style.display='none'"
                style="position: absolute; right: 1rem; top: 1rem; background: transparent; color: var(--text-color); border: 1px solid var(--card-border);">✕
                Close</button>
            <h3>Complete Transfer</h3>
            <div id="complete-summary"
                style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; font-size: 0.9rem;">
            </div>

            <form method="POST" id="completeForm">
                <input type="hidden" name="action" value="complete_coc">
                <input type="hidden" name="coc_id" id="complete_coc_id">
                <input type="hidden" name="signature_data" id="complete_signature_data">

                <label>Received By (print name)</label>
                <input type="text" name="received_by" id="complete_received_by" required
                    placeholder="Receiver's full name">

                <h4 style="margin-top: 1.5rem;">Receiver Signature</h4>
                <p><small>Sign below to acknowledge receipt.</small></p>
                <canvas id="sig-pad-complete" width="600" height="200"></canvas>
                <button type="button" onclick="clearCompleteSignature()"
                    style="font-size: 0.8rem; margin-top: 0.5rem;">Clear Signature</button>

                <div style="margin-top: 2rem;">
                    <button type="submit" class="btn" style="background: #10b981;"
                        onclick="return saveCompleteSignature()">Mark as Received ✓</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Invoice Confirmation Modal (Completed, not yet invoiced) -->
    <div id="invoiceModal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 100; backdrop-filter: blur(5px); overflow-y: auto;">
        <div class="glass-panel" style="margin: 15vh auto; max-width: 480px; position: relative;">
            <button onclick="document.getElementById('invoiceModal').style.display='none'"
                style="position: absolute; right: 1rem; top: 1rem; background: transparent; color: var(--text-color); border: 1px solid var(--card-border);">✕
                Close</button>
            <h3>🧾 Mark as Invoiced</h3>
            <div id="invoice-summary"
                style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem;">
            </div>
            <p style="font-size: 0.9rem;">Confirm this shipment has been invoiced. The "Invoiced" button will no longer
                be shown for this transfer.</p>
            <form method="POST">
                <input type="hidden" name="action" value="mark_invoiced">
                <input type="hidden" name="coc_id" id="invoice_coc_id">
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                    <button type="button" class="btn"
                        style="background: transparent; border: 1px solid var(--card-border); color: var(--text-color);"
                        onclick="document.getElementById('invoiceModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn" style="background: #3b82f6;">Yes — Mark as Invoiced</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ── Item name lookup map (PHP-injected) ──────────────────────────────
        const itemNameMap = <?= json_encode($item_name_map) ?>;
        const receiverMap = <?= json_encode($receiver_map) ?>;

        function resolveItemName(item_id) {
            return itemNameMap[item_id] || item_id;
        }

        // ── Receiver address hint on form ────────────────────────────────────
        function updateReceiverHint(id) {
            const hint = document.getElementById('receiver-address-hint');
            if (!hint) return;
            const r = receiverMap[id];
            hint.textContent = r && r.address ? '📍 ' + r.address : '';
        }

        // ── Item row repeater ────────────────────────────────────────────────
        function addItemRow() {
            const container = document.getElementById('items-container');
            const row = container.children[0].cloneNode(true);
            row.querySelectorAll('input').forEach(i => i.value = '');
            container.appendChild(row);
        }

        // ── Signature Pad (complete modal) ───────────────────────────────────
        const completeCanvas = document.getElementById('sig-pad-complete');
        const completeCtx = completeCanvas.getContext('2d');
        let completePainting = false;

        function startComplete(e) { completePainting = true; drawComplete(e); }
        function endComplete() { completePainting = false; completeCtx.beginPath(); }
        function drawComplete(e) {
            if (!completePainting) return;
            e.preventDefault();
            const rect = completeCanvas.getBoundingClientRect();
            const scaleX = completeCanvas.width / rect.width;
            const scaleY = completeCanvas.height / rect.height;
            const x = ((e.clientX || e.touches[0].clientX) - rect.left) * scaleX;
            const y = ((e.clientY || e.touches[0].clientY) - rect.top) * scaleY;
            completeCtx.lineWidth = 2;
            completeCtx.lineCap = 'round';
            completeCtx.strokeStyle = '#000';
            completeCtx.lineTo(x, y);
            completeCtx.stroke();
            completeCtx.beginPath();
            completeCtx.moveTo(x, y);
        }
        completeCanvas.addEventListener('mousedown', startComplete);
        completeCanvas.addEventListener('mouseup', endComplete);
        completeCanvas.addEventListener('mousemove', drawComplete);
        completeCanvas.addEventListener('touchstart', startComplete);
        completeCanvas.addEventListener('touchend', endComplete);
        completeCanvas.addEventListener('touchmove', drawComplete);

        function clearCompleteSignature() {
            completeCtx.clearRect(0, 0, completeCanvas.width, completeCanvas.height);
        }
        function saveCompleteSignature() {
            document.getElementById('complete_signature_data').value = completeCanvas.toDataURL();
            return true;
        }

        // ── Complete Modal ───────────────────────────────────────────────────
        function openComplete(data) {
            document.getElementById('complete_coc_id').value = data.id;
            const items = JSON.parse(data.coc_items);
            let itemList = items.map(i => `${resolveItemName(i.item_id)} × ${i.qty}`).join('<br>');
            document.getElementById('complete-summary').innerHTML =
                `<strong>Doc #${data.id}</strong> &nbsp;|&nbsp; ${data.form_date}<br>
                 <strong>To:</strong> ${data.destination} &nbsp;|&nbsp; <strong>By:</strong> ${data.transported_by}<br>
                 <div style="margin-top:0.5rem;">${itemList}</div>`;

            // Pre-fill received_by from receiver map if available
            const r = receiverMap[data.receiver_id];
            document.getElementById('complete_received_by').value = r ? (r.contact || '') : '';
            clearCompleteSignature();
            document.getElementById('completeModal').style.display = 'block';
        }

        // ── Invoice Modal ────────────────────────────────────────────────────
        function openInvoice(data) {
            document.getElementById('invoice_coc_id').value = data.id;
            document.getElementById('invoice-summary').innerHTML =
                `<strong>Doc #${data.id}</strong> &nbsp;|&nbsp; ${data.form_date}<br>
                 <strong>To:</strong> ${data.destination}`;
            document.getElementById('invoiceModal').style.display = 'block';
        }

        // ── View Modal ───────────────────────────────────────────────────────
        function viewCoc(data) {
            const items = JSON.parse(data.coc_items);
            let itemHtml = '<table style="width:100%; border-collapse:collapse; margin-top:1rem;">' +
                '<thead><tr style="border-bottom:2px solid #000;">' +
                '<th style="text-align:left; padding:0.5rem; color:#1a6fba;">Item</th>' +
                '<th style="text-align:left; padding:0.5rem; color:#1a6fba;">Batch</th>' +
                '<th style="text-align:left; padding:0.5rem; color:#1a6fba;">Qty</th>' +
                '</tr></thead><tbody>';
            items.forEach(i => {
                itemHtml += `<tr>
                    <td style="border-bottom:1px solid #ccc; padding:0.5rem;">${resolveItemName(i.item_id)}</td>
                    <td style="border-bottom:1px solid #ccc; padding:0.5rem;">${i.batch || '-'}</td>
                    <td style="border-bottom:1px solid #ccc; padding:0.5rem;">${i.qty}</td>
                </tr>`;
            });
            itemHtml += '</tbody></table>';

            const receivedSection = data.received_by
                ? `<div style="margin-top:1rem;"><strong>Received By:</strong> ${data.received_by}</div>`
                : '';

            const invoiceSection = data.invoiced_at
                ? `<div style="margin-top:0.5rem;"><strong>Invoiced:</strong> ${data.invoiced_at}</div>`
                : '';

            const sigSection = data.signature_image
                ? `<div style="margin-top:2rem;"><p><strong>Receiver Signature:</strong></p>
                   <img src="${data.signature_image}" style="border:1px solid #ccc; max-width:100%; height:auto;"></div>`
                : '';

            const html = `
                <div style="text-align:center; margin-bottom:2rem;">
                    <h2>Chain of Custody Record</h2>
                    <p>Doc ID: #${data.id}</p>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:1rem;">
                    <div><strong>Date:</strong> ${data.form_date}</div>
                    <div><strong>Transported By:</strong> ${data.transported_by}</div>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:2rem;">
                    <div><strong>From:</strong> ${data.origin}</div>
                    <div><strong>To:</strong> ${data.destination}</div>
                </div>
                ${itemHtml}
                ${receivedSection}
                ${invoiceSection}
                ${sigSection}
            `;
            document.getElementById('coc-content').innerHTML = html;
            document.getElementById('viewModal').style.display = 'block';
        }

        // ── Packing Slip ─────────────────────────────────────────────────────
        function printPackingSlip(data) {
            const items = JSON.parse(data.coc_items);
            const receiver = receiverMap[data.receiver_id] || {};
            const address = receiver.address || data.destination;

            let rows = items.map(i => `
                <tr>
                    <td>${resolveItemName(i.item_id)}</td>
                    <td>${i.batch || '-'}</td>
                    <td>${i.qty}</td>
                </tr>`).join('');

            const slip = `<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Packing Slip — COC #${data.id}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12pt; color: #111; padding: 2cm; }
        h1 { font-size: 18pt; margin-bottom: 0.25rem; }
        .subtitle { font-size: 10pt; color: #555; margin-bottom: 1.5rem; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; border: 1px solid #ccc; padding: 1rem; border-radius: 4px; }
        .meta-grid div strong { display: block; font-size: 9pt; text-transform: uppercase; color: #666; margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th { border-bottom: 2px solid #333; padding: 0.4rem 0.5rem; text-align: left; font-size: 10pt; }
        td { border-bottom: 1px solid #ddd; padding: 0.4rem 0.5rem; font-size: 11pt; }
        .footer { margin-top: 3rem; font-size: 9pt; color: #888; border-top: 1px solid #ccc; padding-top: 0.5rem; }
        @media print { body { padding: 1cm; } }
    </style>
</head>
<body>
    <h1>Packing Slip</h1>
    <div class="subtitle">Chain of Custody — Doc #${data.id}</div>

    <div class="meta-grid">
        <div><strong>Date</strong>${data.form_date}</div>
        <div><strong>Doc ID</strong>#${data.id}</div>
        <div><strong>From</strong>${data.origin}</div>
        <div><strong>Transported By</strong>${data.transported_by}</div>
        <div style="grid-column:1/-1;"><strong>Destination</strong>${data.destination}</div>
        <div style="grid-column:1/-1;"><strong>Address</strong>${address}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>Batch / Lot</th>
                <th>Qty</th>
            </tr>
        </thead>
        <tbody>${rows}</tbody>
    </table>

    <div class="footer">Generated by BUD Portal &mdash; ${new Date().toLocaleString()}<br>Status: In Progress &mdash; Awaiting receiver signature.</div>
    <script>window.onload = function() { window.print(); }<\/script>
</body>
</html>`;

            const win = window.open('', '_blank');
            win.document.write(slip);
            win.document.close();
        }
    </script>
</body>

</html>