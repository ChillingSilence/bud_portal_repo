<?php
require_once 'config.php';
require_once 'includes/audit.php';
require_once 'includes/settings.php';
require_once 'includes/documents.php';
require_once 'includes/stock.php';

$message = '';
$message_error = false;

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

            // How it travels: our staff, or a courier (printed on the manifest).
            // Checked before any stock comes off.
            $delivery_method = ($_POST['delivery_method'] ?? '') === 'courier' ? 'courier' : 'staff';
            $courier_company = $delivery_method === 'courier' ? trim($_POST['courier_company'] ?? '') : '';
            $tracking_number = $delivery_method === 'courier' ? trim($_POST['tracking_number'] ?? '') : '';
            if ($delivery_method === 'courier' && $courier_company === '') {
                throw new Exception("Say which courier is taking it. Nothing was changed.");
            }

            // Nothing retired goes out. The form may have been open since
            // before something was retired.
            $retired_line = bud_retired_transfer_line($pdo, (array) ($_POST['item_id'] ?? []));
            if ($retired_line !== '') {
                throw new Exception($retired_line . " Nothing was changed.");
            }

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
                (form_date, origin, destination, receiver_id, transported_by, coc_items, status, created_at,
                 delivery_method, courier_company, tracking_number)
                VALUES (?, ?, ?, ?, ?, ?, 'In Progress', ?, ?, ?, ?)");
            $stmt->execute([$date, $origin, $destination, $receiver_id ?: null, $transported_by, json_encode($coc_items), bud_now(),
                $delivery_method, $courier_company ?: null, $tracking_number ?: null]);
            $id = $pdo->lastInsertId();

            Audit::log($pdo, 'chain_of_custody', $id, 'INSERT', null, $_POST);
            $message = $delivery_method === 'courier'
                ? "Started transfer #$id and took its stock off. Print its shipping manifest to go with the courier, and upload the signed copy when it comes back."
                : "Started transfer #$id and took its stock off. Print its shipping manifest, and complete it when it's signed for.";
        } catch (Exception $e) {
            $message = "Couldn't start the transfer: " . $e->getMessage();
            $message_error = true;
        }
    }

    // -------------------------------------------------------
    // PHASE 2: Complete Transfer (signature + received_by)
    // -------------------------------------------------------
    elseif ($action === 'complete_coc') {
        try {
            $coc_id = intval($_POST['coc_id']);
            $received_by = trim($_POST['received_by'] ?? '');
            // Signed here on screen, or the manifest the receiver signed,
            // photographed or scanned and uploaded (a courier delivery)
            $sign_method = ($_POST['sign_method'] ?? '') === 'document' ? 'document' : 'screen';
            $signature = $sign_method === 'screen' ? ($_POST['signature_data'] ?? '') : '';
            $has_upload = isset($_FILES['signed_document']) && ($_FILES['signed_document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

            if (!$coc_id || !$received_by) {
                $message = "The receiver's name is needed.";
                $message_error = true;
            } elseif ($sign_method === 'screen' && !$signature) {
                $message = "The receiver's name and signature are both needed.";
                $message_error = true;
            } elseif ($sign_method === 'document' && !$has_upload) {
                $message = "Upload the photo or scan of the signed manifest.";
                $message_error = true;
            } else {
                $old_stmt = $pdo->prepare("SELECT received_by, signature_image, signed_document, signed_document_name,
                    tracking_number, status, completed_at
                    FROM chain_of_custody WHERE id = ? AND status = 'In Progress'");
                $old_stmt->execute([$coc_id]);
                $old_coc = $old_stmt->fetch();
                $old_stmt = null;

                if (!$old_coc) {
                    $message = "Transfer #$coc_id wasn't found, or it's no longer in progress.";
                    $message_error = true;
                } else {
                    $document = ['file' => null, 'name' => null];
                    if ($sign_method === 'document') {
                        // Throws with a message for the person uploading it
                        $document = bud_store_document($_FILES['signed_document'], $coc_id);
                    }
                    $tracking = trim($_POST['tracking_number'] ?? '');
                    $tracking = $tracking !== '' ? $tracking : $old_coc['tracking_number'];

                    $stmt = $pdo->prepare("UPDATE chain_of_custody
                        SET status = 'Completed', received_by = ?, signature_image = ?, signed_document = ?,
                            signed_document_name = ?, tracking_number = ?, completed_at = ?
                        WHERE id = ? AND status = 'In Progress'");
                    $stmt->execute([$received_by, $signature ?: null, $document['file'], $document['name'], $tracking, bud_now(), $coc_id]);
                    // Old values recorded so the Admin "Undo Last Action" can revert a completion
                    Audit::log($pdo, 'chain_of_custody', $coc_id, 'UPDATE', $old_coc, [
                        'received_by' => $received_by,
                        'signature_image' => $signature ?: null,
                        'signed_document' => $document['file'],
                        'status' => 'Completed',
                    ]);
                    $message = $sign_method === 'document'
                        ? "Transfer #$coc_id is complete, with the signed manifest kept on its record."
                        : "Transfer #$coc_id is complete and signed for.";
                }
            }
        } catch (Exception $e) {
            $message = "Couldn't complete the transfer: " . $e->getMessage();
            $message_error = true;
        }
    }

    // -------------------------------------------------------
    // CANCEL: Reverse a transfer (restores stock). In Progress transfers
    // can always be cancelled; Completed ones only until they are invoiced
    // (for duplicates / mistakes — a reason is required).
    // -------------------------------------------------------
    elseif ($action === 'cancel_coc') {
        try {
            $coc_id = intval($_POST['coc_id']);
            $cancel_reason = trim($_POST['cancel_reason'] ?? '');

            $coc_stmt = $pdo->prepare("SELECT * FROM chain_of_custody
                WHERE id = ? AND (status = 'In Progress' OR (status = 'Completed' AND invoiced_at IS NULL))");
            $coc_stmt->execute([$coc_id]);
            $coc = $coc_stmt->fetch();
            $coc_stmt = null;

            if (!$coc) {
                $message = "Transfer #$coc_id wasn't found, or it's already cancelled or invoiced.";
                $message_error = true;
            } elseif ($coc['status'] === 'Completed' && $cancel_reason === '') {
                $message = "Give a reason for cancelling a completed transfer.";
                $message_error = true;
            } else {
                // Restore the stock that was deducted at initiation
                $items = json_decode($coc['coc_items'], true) ?: [];
                foreach ($items as $item) {
                    $item_id = $item['item_id'];
                    $qty = floatval($item['qty'] ?? 0);
                    if ($qty <= 0) {
                        continue;
                    }

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
                            $restore_qty = $component['quantity'] * $qty;

                            $old_stock_stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
                            $old_stock_stmt->execute([$component_id]);
                            $old_stock = $old_stock_stmt->fetch();

                            if ($old_stock) {
                                $pdo->prepare("UPDATE stock_items SET quantity = quantity + ? WHERE id = ?")->execute([$restore_qty, $component_id]);

                                $new_stock_stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
                                $new_stock_stmt->execute([$component_id]);
                                $new_stock = $new_stock_stmt->fetch();
                                $new_stock['adjustment_notes'] = "Restored via Bundle: $b_name (Cancelled COC #$coc_id)";
                                $new_stock['movement'] = 'transfer_cancelled';
                                Audit::log($pdo, 'stock_items', $component_id, 'UPDATE', $old_stock, $new_stock);
                            }
                        }
                    } else {
                        $old_stock_stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
                        $old_stock_stmt->execute([$item_id]);
                        $old_stock = $old_stock_stmt->fetch();

                        if ($old_stock) {
                            $pdo->prepare("UPDATE stock_items SET quantity = quantity + ? WHERE id = ?")->execute([$qty, $item_id]);

                            $new_stock_stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
                            $new_stock_stmt->execute([$item_id]);
                            $new_stock = $new_stock_stmt->fetch();
                            $new_stock['adjustment_notes'] = "Restored via Cancelled COC #$coc_id";
                            $new_stock['movement'] = 'transfer_cancelled';
                            Audit::log($pdo, 'stock_items', $item_id, 'UPDATE', $old_stock, $new_stock);
                        }
                    }
                }

                $pdo->prepare("UPDATE chain_of_custody
                    SET status = 'Cancelled', cancelled_at = ?, cancel_reason = ?
                    WHERE id = ? AND status = ?")->execute([bud_now(), $cancel_reason !== '' ? $cancel_reason : null, $coc_id, $coc['status']]);
                Audit::log($pdo, 'chain_of_custody', $coc_id, 'UPDATE',
                    ['status' => $coc['status'], 'cancel_reason' => null],
                    ['status' => 'Cancelled', 'cancel_reason' => $cancel_reason]);
                $message = "Cancelled transfer #$coc_id and put its stock back.";
            }
        } catch (Exception $e) {
            $message = "Couldn't cancel the transfer: " . $e->getMessage();
            $message_error = true;
        }
    }

    // -------------------------------------------------------
    // PHASE 3: Mark as Invoiced (completed transfers only)
    // -------------------------------------------------------
    elseif ($action === 'mark_invoiced') {
        try {
            $coc_id = intval($_POST['coc_id']);
            $invoiced_at = bud_now();
            $stmt = $pdo->prepare("UPDATE chain_of_custody
                SET invoiced_at = ?
                WHERE id = ? AND status = 'Completed' AND invoiced_at IS NULL");
            $stmt->execute([$invoiced_at, $coc_id]);

            if ($stmt->rowCount() > 0) {
                Audit::log($pdo, 'chain_of_custody', $coc_id, 'UPDATE', ['invoiced_at' => null], ['invoiced_at' => $invoiced_at]);
                $message = "Marked transfer #$coc_id as invoiced.";
            } else {
                $message = "Transfer #$coc_id isn't complete, or it's already invoiced.";
                $message_error = true;
            }
        } catch (Exception $e) {
            $message = "Couldn't mark the transfer as invoiced: " . $e->getMessage();
            $message_error = true;
        }
    }
}

// Fetch History
$history = $pdo->query("SELECT * FROM chain_of_custody ORDER BY id DESC LIMIT 50")->fetchAll();

// Fetch controlled substances only
$stock_options = $pdo->query("SELECT id, name, sku, unit FROM stock_items WHERE quantity > 0 AND is_controlled = 1 AND is_active = 1 ORDER BY name ASC")->fetchAll();

// Fetch active bundles
$bundle_options = $pdo->query("SELECT id, name, sku FROM product_bundles WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Fetch verified receivers for dropdown
$receivers = $pdo->query("SELECT id, name, contact_person, address FROM verified_receivers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Item names for the pop-ups, shipping manifests and records. Every item and
// bundle, not only the ones on offer, so an old transfer still shows its
// item's name after that stock runs out or the bundle is retired.
$item_name_map = [];
foreach ($pdo->query("SELECT id, name, sku FROM stock_items")->fetchAll() as $s) {
    $item_name_map[(string) $s['id']] = $s['name'] . ($s['sku'] ? ' (' . $s['sku'] . ')' : '');
}
foreach ($pdo->query("SELECT id, name, sku FROM product_bundles")->fetchAll() as $b) {
    $item_name_map['bundle_' . $b['id']] = $b['name'] . ($b['sku'] ? ' (' . $b['sku'] . ')' : '');
}

// Receivers by id (contact person, address), retired ones too, so a slip
// for a receiver retired since still has the address
$receiver_map = [];
foreach ($pdo->query("SELECT id, name, contact_person, address, licence_number FROM verified_receivers")->fetchAll() as $r) {
    $receiver_map[$r['id']] = [
        'contact' => $r['contact_person'],
        'address' => $r['address'],
        'name' => $r['name'],
        'licence' => $r['licence_number'],
    ];
}

// Your company's details, printed on every shipping manifest
$company = bud_company($pdo);
$company_missing = trim($company['name']) === '' || trim($company['email']) === '';

// Every transfer on the page, by id, for the pop-ups (a signature can be
// large, so it's written once here rather than into every button)
$transfers_by_id = [];
foreach ($history as $row) {
    $transfers_by_id[$row['id']] = $row;
}
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$pageTitle = 'Chain of custody';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Chain of custody</h1>
            <p>Transfers of controlled stock. Stock comes off when a transfer starts, a shipping manifest goes with it, and the receiver signs for it when it arrives.</p>
        </hgroup>

        <?= bud_message($message, $message_error) ?>

        <?php if ($company_missing): ?>
            <div class="callout callout--note">
                <p>Your company's name and the email for arrival notices aren't set yet, so shipping manifests print blank lines for them. <a href="company.php">Add your company's details</a>.</p>
            </div>
        <?php endif; ?>

        <div class="page-actions">
            <button type="button" onclick="openNewTransfer()">New transfer</button>
            <a href="receivers.php" role="button" class="secondary outline">Verified receivers</a>
        </div>

        <h2 class="section-title">Recent transfers</h2>
        <?php if (empty($history)): ?>
            <p class="empty-note">No transfers yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="wide-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>To</th>
                            <th>Sent by</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $row):
                            $items = json_decode($row['coc_items'], true) ?: [];
                            $id = (int) $row['id']; ?>
                            <tr>
                                <td class="nowrap"><?= $id ?></td>
                                <td class="nowrap"><?= h(bud_date($row['form_date'])) ?></td>
                                <td class="cell-name">
                                    <?= h($row['destination']) ?>
                                    <small><?= h(bud_plural(count($items), 'item')) ?></small>
                                </td>
                                <td>
                                    <?= h($row['transported_by']) ?>
                                    <?php if (($row['delivery_method'] ?? '') === 'courier'): ?><small>Courier, <?= h($row['courier_company']) ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'Completed'): ?>
                                        <span class="badge">Completed</span>
                                        <?php if (empty($row['invoiced_at'])): ?>
                                            <span class="badge badge--todo">Needs invoice</span>
                                        <?php endif; ?>
                                    <?php elseif ($row['status'] === 'Cancelled'): ?>
                                        <span class="badge badge--danger">Cancelled</span>
                                    <?php else: ?>
                                        <span class="badge badge--progress">In progress</span>
                                    <?php endif; ?>
                                </td>
                                <td class="row-actions row-actions--wrap">
                                    <?php if ($row['status'] === 'In Progress'): ?>
                                        <button type="button" data-transfer-action="complete" data-id="<?= $id ?>">Complete</button>
                                        <button type="button" class="secondary outline" data-transfer-action="manifest" data-id="<?= $id ?>">Shipping manifest</button>
                                        <button type="button" class="danger outline" data-transfer-action="cancel" data-id="<?= $id ?>">Cancel</button>
                                    <?php elseif ($row['status'] === 'Cancelled'): ?>
                                        <button type="button" class="secondary outline" data-transfer-action="view" data-id="<?= $id ?>">View</button>
                                    <?php else: ?>
                                        <button type="button" class="secondary outline" data-transfer-action="view" data-id="<?= $id ?>">View</button>
                                        <?php if (empty($row['invoiced_at'])): ?>
                                            <button type="button" class="secondary outline" data-transfer-action="invoice" data-id="<?= $id ?>">Mark as invoiced</button>
                                            <button type="button" class="danger outline" data-transfer-action="cancel" data-id="<?= $id ?>">Cancel</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <!-- New transfer -->
    <dialog id="newTransferDialog" class="bud-modal bud-modal--wide">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3>New transfer</h3>
            </header>
            <p class="modal-text">The stock comes off as soon as you start the transfer. Print its shipping manifest to go with it, and the receiver signs for it when it arrives.</p>
            <?php if (empty($receivers)): ?>
                <div class="callout callout--warning">
                    <p>There are no verified receivers yet. <a href="receivers.php">Add one first</a>, then come back to start the transfer.</p>
                </div>
            <?php else: ?>
                <form method="POST" id="transferForm">
                    <input type="hidden" name="action" value="create_coc">
                    <div class="form-grid form-grid--2">
                        <label>Date
                            <input type="date" name="date" value="<?= h(date('Y-m-d')) ?>" required>
                        </label>
                        <label>Sent by
                            <input type="text" name="transported_by" placeholder="The staff member sending it" required>
                        </label>
                        <label>From
                            <input type="text" name="origin" value="Main Facility" required>
                        </label>
                        <label>To
                            <select name="receiver_id" id="receiver_select" required>
                                <option value="">Choose a verified receiver</option>
                                <?php foreach ($receivers as $r): ?>
                                    <option value="<?= h($r['id']) ?>"><?= h($r['name']) ?><?= $r['contact_person'] ? ' (' . h($r['contact_person']) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <small class="field-hint" id="receiver-address-hint"></small>

                    <fieldset class="choice-row">
                        <legend>Delivery</legend>
                        <label><input type="radio" name="delivery_method" value="staff" checked> Our staff take it</label>
                        <label><input type="radio" name="delivery_method" value="courier"> A courier takes it</label>
                    </fieldset>
                    <div class="form-grid form-grid--2" id="courier-fields" hidden>
                        <label>Courier company
                            <input type="text" name="courier_company" id="courier_company" placeholder="The courier company's name">
                        </label>
                        <label>Tracking number
                            <input type="text" name="tracking_number" placeholder="If you have it yet">
                        </label>
                    </div>

                    <h4>Items</h4>
                    <div id="items-container"></div>
                    <button type="button" class="secondary outline add-row" onclick="addItemRow()">Add another item</button>

                    <footer>
                        <button type="button" class="secondary outline" data-close-dialog>Cancel</button>
                        <button type="submit">Start transfer</button>
                    </footer>
                </form>
            <?php endif; ?>
        </article>
    </dialog>

    <template id="itemRowTemplate">
        <div class="repeat-row">
            <label>Item or bundle
                <select name="item_id[]" required>
                    <option value="">Choose controlled stock or a bundle</option>
                    <optgroup label="Controlled stock">
                        <?php foreach ($stock_options as $opt): ?>
                            <option value="<?= h($opt['id']) ?>"><?= h($opt['name']) ?><?= $opt['sku'] ? ' (' . h($opt['sku']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Bundles">
                        <?php foreach ($bundle_options as $bundle): ?>
                            <option value="bundle_<?= h($bundle['id']) ?>"><?= h($bundle['name']) ?><?= $bundle['sku'] ? ' (' . h($bundle['sku']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </label>
            <label>Batch or lot
                <input type="text" name="batch[]" placeholder="For example, B2611-01">
            </label>
            <label>Quantity
                <input type="number" step="0.01" min="0.01" name="qty[]" required>
            </label>
            <button type="button" class="secondary outline remove-row" onclick="removeItemRow(this)" aria-label="Take this item off the transfer">Remove</button>
        </div>
    </template>

    <!-- Complete a transfer: who received it and how they signed, then a review -->
    <dialog id="completeDialog" class="bud-modal bud-modal--wide">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3>Complete transfer</h3>
            </header>
            <div id="complete-summary" class="modal-summary"></div>
            <form method="POST" id="completeForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="complete_coc">
                <input type="hidden" name="coc_id" id="complete_coc_id">
                <input type="hidden" name="signature_data" id="complete_signature_data">

                <div id="complete-entry">
                    <fieldset class="choice-row">
                        <legend>How it was signed for</legend>
                        <label><input type="radio" name="sign_method" value="screen" checked> Signed here on screen</label>
                        <label><input type="radio" name="sign_method" value="document"> Signed manifest, uploaded</label>
                    </fieldset>

                    <label>Received by (print name)
                        <input type="text" name="received_by" id="complete_received_by" placeholder="The receiver's full name" required>
                    </label>

                    <div id="sign-screen">
                        <p class="modal-text">The receiver signs below to acknowledge receipt.</p>
                        <canvas id="sig-pad-complete" class="signature-pad" width="600" height="200" aria-label="Signature box"></canvas>
                        <button type="button" class="secondary outline btn-small" onclick="clearCompleteSignature()">Clear signature</button>
                    </div>

                    <div id="sign-document" hidden>
                        <p class="modal-text">For a courier delivery: the shipping manifest the receiver signed, photographed or scanned. Put the name they printed on it above.</p>
                        <label>Signed manifest
                            <input type="file" name="signed_document" id="signed_document" accept="image/jpeg,image/png,image/webp,application/pdf,.pdf">
                        </label>
                        <small class="field-hint">A photo (JPG, PNG or WebP) or a PDF, up to 20 MB. A big phone photo is made smaller before it uploads.</small>
                        <label>Tracking number
                            <input type="text" name="tracking_number" id="complete_tracking" placeholder="Optional">
                        </label>
                    </div>

                    <p class="modal-text field-note" id="complete-submit-hint"></p>
                    <footer>
                        <button type="button" class="secondary outline" data-close-dialog>Cancel</button>
                        <button type="button" id="complete-submit" disabled onclick="reviewCompletion()">Review and save</button>
                    </footer>
                </div>

                <!-- Review: exactly what will be saved, before it's committed -->
                <div id="complete-review" hidden>
                    <p class="modal-text">Received by</p>
                    <p id="review-name" class="review-name"></p>
                    <p class="modal-text" id="review-what">Signature to be saved</p>
                    <img id="review-signature" class="signature-image" alt="What will be saved with the transfer">
                    <div id="review-file" class="modal-summary" hidden></div>
                    <p class="modal-text field-note" id="review-note"></p>
                    <footer>
                        <button type="button" class="secondary outline" onclick="backToCompleteEntry()">Go back</button>
                        <button type="submit">Confirm and save</button>
                    </footer>
                </div>
            </form>
        </article>
    </dialog>

    <!-- Cancel a transfer (in progress, or completed until it's invoiced) -->
    <dialog id="cancelDialog" class="bud-modal">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3>Cancel transfer</h3>
            </header>
            <div id="cancel-summary" class="modal-summary"></div>
            <p id="cancel-completed-warning" class="modal-warning" hidden>
                This transfer has already been <strong>completed and signed for</strong>. Only cancel it if it was a
                duplicate or a mistake. The record and signature are kept, marked as cancelled.
            </p>
            <p class="modal-text">The stock this transfer took off goes back into stock. The record stays, marked as
                cancelled, and it's left out of reports. This can't be reversed here.</p>
            <form method="POST">
                <input type="hidden" name="action" value="cancel_coc">
                <input type="hidden" name="coc_id" id="cancel_coc_id">
                <label for="cancel_reason">Reason<span id="cancel-reason-required" hidden> (needed for a completed transfer)</span></label>
                <textarea name="cancel_reason" id="cancel_reason" rows="2" placeholder="For example, a duplicate opened by mistake"></textarea>
                <footer>
                    <button type="button" class="secondary outline" data-close-dialog>Keep transfer</button>
                    <button type="submit" class="danger">Cancel and put stock back</button>
                </footer>
            </form>
        </article>
    </dialog>

    <!-- Mark a completed transfer as invoiced -->
    <dialog id="invoiceDialog" class="bud-modal">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3>Mark as invoiced</h3>
            </header>
            <div id="invoice-summary" class="modal-summary"></div>
            <p class="modal-text">Confirm this shipment has been invoiced. It will stop showing as needing an invoice.</p>
            <form method="POST">
                <input type="hidden" name="action" value="mark_invoiced">
                <input type="hidden" name="coc_id" id="invoice_coc_id">
                <footer>
                    <button type="button" class="secondary outline" data-close-dialog>Cancel</button>
                    <button type="submit">Mark as invoiced</button>
                </footer>
            </form>
        </article>
    </dialog>

    <!-- A transfer's record -->
    <dialog id="viewDialog" class="bud-modal bud-modal--wide">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3 id="view-title">Chain of custody record</h3>
            </header>
            <div id="coc-content"></div>
            <footer>
                <button type="button" class="secondary outline" data-close-dialog>Close</button>
                <button type="button" id="view-print">Print record</button>
            </footer>
        </article>
    </dialog>

    <script src="js/print-transfer.js?v=<?= h(BUD_VERSION) ?>"></script>
    <script type="application/json" id="transferData"><?= json_encode($transfers_by_id, $json_flags) ?></script>
    <script type="application/json" id="itemNames"><?= json_encode($item_name_map, $json_flags) ?></script>
    <script type="application/json" id="receiverData"><?= json_encode($receiver_map, $json_flags) ?></script>
    <script type="application/json" id="companyData"><?= json_encode($company, $json_flags) ?></script>
    <script>
        const transfers = JSON.parse(document.getElementById('transferData').textContent);
        const itemNameMap = JSON.parse(document.getElementById('itemNames').textContent);
        const receiverMap = JSON.parse(document.getElementById('receiverData').textContent);
        const company = JSON.parse(document.getElementById('companyData').textContent);

        function resolveItemName(itemId) {
            return itemNameMap[itemId] || ('Item #' + itemId);
        }

        function transferItems(data) {
            try {
                return JSON.parse(data.coc_items) || [];
            } catch (e) {
                return [];
            }
        }

        // What a pop-up is about: number, date, where to and the items
        // "Our staff", or "Courier, Fast Freight, tracking AB123"
        function deliveryText(data) {
            if (data.delivery_method !== 'courier') return 'Our staff';
            return 'Courier, ' + (data.courier_company || 'company not given') + (data.tracking_number ? ', tracking ' + data.tracking_number : '');
        }

        function summaryHtml(data, withCarrier) {
            const items = transferItems(data);
            return '<dl>' +
                '<dt>Transfer</dt><dd>#' + escapeHtml(data.id) + ', ' + escapeHtml(formatDate(data.form_date)) + '</dd>' +
                '<dt>To</dt><dd>' + escapeHtml(data.destination) + '</dd>' +
                (withCarrier ? '<dt>Sent by</dt><dd>' + escapeHtml(data.transported_by) + '</dd>' +
                    '<dt>Delivery</dt><dd>' + escapeHtml(deliveryText(data)) + '</dd>' : '') +
                '</dl>' +
                (items.length ? '<ul>' + items.map(i => '<li>' + escapeHtml(resolveItemName(i.item_id)) + ' × ' + escapeHtml(i.qty) + '</li>').join('') + '</ul>' : '');
        }

        document.addEventListener('click', (e) => {
            const button = e.target.closest('[data-transfer-action]');
            if (!button) return;
            const data = transfers[button.dataset.id];
            if (!data) return;
            const action = button.dataset.transferAction;
            if (action === 'complete') openComplete(data);
            else if (action === 'manifest') printTransfer(data, 'manifest', resolveItemName, receiverMap, company);
            else if (action === 'cancel') openCancel(data);
            else if (action === 'invoice') openInvoice(data);
            else if (action === 'view') viewTransfer(data);
        });

        // ── New transfer ─────────────────────────────────────────────────────
        function addItemRow() {
            const row = document.getElementById('itemRowTemplate').content.firstElementChild.cloneNode(true);
            document.getElementById('items-container').appendChild(row);
        }

        function removeItemRow(button) {
            const container = document.getElementById('items-container');
            button.closest('.repeat-row').remove();
            if (!container.children.length) addItemRow();
        }

        function openNewTransfer() {
            const form = document.getElementById('transferForm');
            if (form) {
                form.reset();
                document.getElementById('items-container').innerHTML = '';
                addItemRow();
                updateReceiverHint('');
                showCourierFields();
            }
            openDialog('newTransferDialog');
        }

        // A courier delivery needs the courier's name (and a tracking number if known)
        function showCourierFields() {
            const courier = !!document.querySelector('input[name="delivery_method"][value="courier"]:checked');
            const fields = document.getElementById('courier-fields');
            if (!fields) return;
            fields.hidden = !courier;
            document.getElementById('courier_company').required = courier;
        }
        document.querySelectorAll('input[name="delivery_method"]').forEach(r => r.addEventListener('change', showCourierFields));

        function updateReceiverHint(id) {
            const hint = document.getElementById('receiver-address-hint');
            if (!hint) return;
            const r = receiverMap[id];
            hint.textContent = r && r.address ? 'Goes to ' + r.address : '';
        }

        const receiverSelect = document.getElementById('receiver_select');
        if (receiverSelect) {
            receiverSelect.addEventListener('change', () => updateReceiverHint(receiverSelect.value));
        }

        // ── Complete ─────────────────────────────────────────────────────────
        // Two ways to sign for it: on screen, or (a courier delivery) the
        // manifest the receiver signed, photographed or scanned and uploaded
        const completeName = document.getElementById('complete_received_by');
        const completePad = createSignaturePad(document.getElementById('sig-pad-complete'), updateCompleteSubmit);
        const documentInput = document.getElementById('signed_document');
        const maxDocumentBytes = 20 * 1024 * 1024;
        let reviewUrl = null;

        function signMethod() {
            const checked = document.querySelector('input[name="sign_method"]:checked');
            return checked ? checked.value : 'screen';
        }

        function setSignMethod(method) {
            document.querySelectorAll('input[name="sign_method"]').forEach(r => { r.checked = r.value === method; });
            showSignMethod();
        }

        function showSignMethod() {
            const onScreen = signMethod() === 'screen';
            document.getElementById('sign-screen').hidden = !onScreen;
            document.getElementById('sign-document').hidden = onScreen;
            document.getElementById('complete-submit-hint').textContent = onScreen
                ? 'Enter the receiver\'s name and have them sign to carry on.'
                : 'Enter the name on the signed manifest and choose its photo or scan to carry on.';
            updateCompleteSubmit();
        }
        document.querySelectorAll('input[name="sign_method"]').forEach(r => r.addEventListener('change', showSignMethod));

        // Hard block: no completion without a receiver's name and either a
        // drawn signature or the signed manifest
        function updateCompleteSubmit() {
            const ready = signMethod() === 'screen' ? completePad.hasDrawn() : documentInput.files.length > 0;
            document.getElementById('complete-submit').disabled = !(completeName.value.trim() && ready);
        }
        completeName.addEventListener('input', updateCompleteSubmit);

        // Check the file, and make a big phone photo smaller before it uploads
        documentInput.addEventListener('change', async () => {
            const file = documentInput.files[0];
            if (file) {
                const okType = /^(image\/(jpeg|png|webp)|application\/pdf)$/.test(file.type) || /\.pdf$/i.test(file.name);
                if (!okType) {
                    showToast('The signed manifest has to be a photo (JPG, PNG or WebP) or a PDF.', 'error');
                    documentInput.value = '';
                } else {
                    const smaller = await shrinkPhoto(file);
                    if (smaller !== file && typeof DataTransfer !== 'undefined') {
                        const list = new DataTransfer();
                        list.items.add(smaller);
                        documentInput.files = list.files;
                    }
                    if (documentInput.files[0] && documentInput.files[0].size > maxDocumentBytes) {
                        showToast('That file is too big. Keep it under 20 MB.', 'error');
                        documentInput.value = '';
                    }
                }
            }
            updateCompleteSubmit();
        });

        function clearCompleteSignature() {
            completePad.clear();
        }

        // Step 1 to 2: show exactly what will be saved before it's committed
        function reviewCompletion() {
            const name = completeName.value.trim();
            const onScreen = signMethod() === 'screen';
            const picture = document.getElementById('review-signature');
            const fileBox = document.getElementById('review-file');
            if (!name) return;
            if (reviewUrl) {
                URL.revokeObjectURL(reviewUrl);
                reviewUrl = null;
            }
            if (onScreen) {
                if (!completePad.hasDrawn()) return;
                const image = completePad.toDataURL();
                document.getElementById('complete_signature_data').value = image;
                picture.src = image;
                picture.classList.remove('document-preview');
                picture.hidden = false;
                fileBox.hidden = true;
                document.getElementById('review-what').textContent = 'Signature to be saved';
                document.getElementById('review-note').textContent = 'Check the name is spelled right and the signature is right. A completed record can only be fixed by cancelling the whole transfer.';
            } else {
                const file = documentInput.files[0];
                if (!file) return;
                document.getElementById('complete_signature_data').value = '';
                document.getElementById('review-what').textContent = 'Signed manifest to be kept with the transfer';
                if (file.type.startsWith('image/')) {
                    reviewUrl = URL.createObjectURL(file);
                    picture.src = reviewUrl;
                    picture.classList.add('document-preview');
                    picture.hidden = false;
                    fileBox.hidden = true;
                } else {
                    picture.hidden = true;
                    picture.removeAttribute('src');
                    fileBox.innerHTML = '<strong>' + escapeHtml(file.name) + '</strong><br>PDF, ' + escapeHtml(formatFileSize(file.size));
                    fileBox.hidden = false;
                }
                document.getElementById('review-note').textContent = 'Check it\'s the right transfer\'s manifest, signed, and the name matches the one printed on it. A completed record can only be fixed by cancelling the whole transfer.';
            }
            document.getElementById('review-name').textContent = name;
            document.getElementById('complete-entry').hidden = true;
            document.getElementById('complete-review').hidden = false;
        }

        function backToCompleteEntry() {
            document.getElementById('complete-review').hidden = true;
            document.getElementById('complete-entry').hidden = false;
        }

        function openComplete(data) {
            document.getElementById('complete_coc_id').value = data.id;
            document.getElementById('complete-summary').innerHTML = summaryHtml(data, true);
            // The receiver's usual contact person, as a starting point
            const r = receiverMap[data.receiver_id];
            completeName.value = r ? (r.contact || '') : '';
            completePad.clear();
            documentInput.value = '';
            document.getElementById('complete_tracking').value = data.tracking_number || '';
            // A courier delivery comes back as a signed manifest
            setSignMethod(data.delivery_method === 'courier' ? 'document' : 'screen');
            backToCompleteEntry();
            openDialog('completeDialog');
        }

        // ── Cancel ───────────────────────────────────────────────────────────
        function openCancel(data) {
            document.getElementById('cancel_coc_id').value = data.id;
            document.getElementById('cancel-summary').innerHTML = summaryHtml(data, false);
            // A completed transfer gets a stronger warning and needs a reason
            const isCompleted = data.status === 'Completed';
            document.getElementById('cancel-completed-warning').hidden = !isCompleted;
            document.getElementById('cancel-reason-required').hidden = !isCompleted;
            const reason = document.getElementById('cancel_reason');
            reason.value = '';
            reason.required = isCompleted;
            openDialog('cancelDialog');
        }

        // ── Invoice ──────────────────────────────────────────────────────────
        function openInvoice(data) {
            document.getElementById('invoice_coc_id').value = data.id;
            document.getElementById('invoice-summary').innerHTML = summaryHtml(data, false);
            openDialog('invoiceDialog');
        }

        // ── View ─────────────────────────────────────────────────────────────
        function viewTransfer(data) {
            const items = transferItems(data);
            const receiver = receiverMap[data.receiver_id] || {};
            let html = '<div class="modal-summary"><dl>' +
                '<dt>Transfer</dt><dd>#' + escapeHtml(data.id) + '</dd>' +
                '<dt>Date</dt><dd>' + escapeHtml(formatDate(data.form_date)) + '</dd>' +
                '<dt>From</dt><dd>' + escapeHtml(data.origin) + '</dd>' +
                '<dt>To</dt><dd>' + escapeHtml(data.destination) + (receiver.address ? '<br>' + escapeHtml(receiver.address) : '') + '</dd>' +
                '<dt>Sent by</dt><dd>' + escapeHtml(data.transported_by) + '</dd>' +
                '<dt>Delivery</dt><dd>' + escapeHtml(deliveryText(data)) + '</dd>' +
                (data.received_by ? '<dt>Received by</dt><dd>' + escapeHtml(data.received_by) + '</dd>' : '') +
                (data.completed_at ? '<dt>Completed</dt><dd>' + escapeHtml(formatDateTime(data.completed_at)) + '</dd>' : '') +
                (data.invoiced_at ? '<dt>Invoiced</dt><dd>' + escapeHtml(formatDateTime(data.invoiced_at)) + '</dd>' : '') +
                '</dl></div>';

            if (data.status === 'Cancelled') {
                html += '<div class="callout callout--danger"><p><strong>Cancelled' +
                    (data.cancelled_at ? ' on ' + escapeHtml(formatDateTime(data.cancelled_at)) : '') + '.</strong> ' +
                    (data.cancel_reason ? escapeHtml(data.cancel_reason) + ' ' : '') +
                    'The stock it took off was put back when it was cancelled.</p></div>';
            }

            html += '<div class="table-wrap"><table class="wide-table"><thead><tr><th>Item</th><th>Batch</th><th class="num">Quantity</th></tr></thead><tbody>' +
                items.map(i => '<tr><td>' + escapeHtml(resolveItemName(i.item_id)) + '</td><td>' + escapeHtml(i.batch || '-') + '</td><td class="num">' + escapeHtml(i.qty) + '</td></tr>').join('') +
                '</tbody></table></div>';

            if (data.signature_image) {
                html += '<p class="modal-text">Receiver\'s signature</p><img class="signature-image" src="' + escapeHtml(data.signature_image) + '" alt="Receiver\'s signature">';
            }
            if (data.signed_document) {
                const url = 'coc_document.php?id=' + encodeURIComponent(data.id);
                const isPdf = /\.pdf$/i.test(data.signed_document);
                html += '<p class="modal-text">Signed manifest</p>' +
                    (isPdf ? '' : '<a href="' + url + '" target="_blank" rel="noopener"><img class="signature-image document-preview" src="' + url + '" alt="The signed manifest"></a>') +
                    '<p><a href="' + url + '" target="_blank" rel="noopener" role="button" class="secondary outline btn-small">Open the signed manifest</a> ' +
                    '<small>' + escapeHtml(data.signed_document_name || '') + '</small></p>';
            }

            document.getElementById('view-title').textContent = 'Chain of custody record #' + data.id;
            document.getElementById('coc-content').innerHTML = html;
            document.getElementById('view-print').onclick = () => printTransfer(data, 'record', resolveItemName, receiverMap, company);
            openDialog('viewDialog');
        }
    </script>
<?php require 'includes/footer.php'; ?>
