<?php
require_once 'config.php';
require_once 'includes/audit.php';
require_once 'includes/stock.php';

$message = '';
$message_error = false;

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $stmt = $pdo->prepare("INSERT INTO stock_items (name, sku, description, quantity, unit, reorder_level, is_controlled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $now = bud_now();
            $stmt->execute([
                $_POST['name'],
                $_POST['sku'],
                $_POST['description'],
                $_POST['quantity'],
                $_POST['unit'],
                $_POST['reorder_level'],
                isset($_POST['is_controlled']) ? 1 : 0,
                $now,
                $now,
            ]);
            $id = $pdo->lastInsertId();
            Audit::log($pdo, 'stock_items', $id, 'INSERT', null, $_POST);
            $message = "Added " . $_POST['name'] . " to stock.";
        } catch (Exception $e) {
            $message = "Couldn't add the item: " . $e->getMessage();
            $message_error = true;
        }
    } elseif ($action === 'edit') {
        $id = $_POST['id'] ?? 0;
        // Fetch old
        $stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        $stmt = null;

        if ($old) {
            $stmt = $pdo->prepare("UPDATE stock_items SET name=?, sku=?, description=?, quantity=?, unit=?, reorder_level=?, is_controlled=?, updated_at=? WHERE id=?");
            $stmt->execute([
                $_POST['name'],
                $_POST['sku'],
                $_POST['description'],
                $_POST['quantity'],
                $_POST['unit'],
                $_POST['reorder_level'],
                isset($_POST['is_controlled']) ? 1 : 0,
                bud_now(),
                $id
            ]);
            Audit::log($pdo, 'stock_items', $id, 'UPDATE', $old, $_POST);
            $message = "Saved the changes to " . $_POST['name'] . ".";
        } else {
            $message = "That stock item wasn't found.";
            $message_error = true;
        }
    } elseif ($action === 'adjust_stock') {
        $id = $_POST['item_id'] ?? 0;
        $operation = $_POST['operation'] ?? ''; // 'add' or 'subtract'
        $quantity = floatval($_POST['quantity'] ?? 0);
        $notes = $_POST['notes'] ?? '';

        if ($quantity <= 0) {
            $message = "The quantity has to be more than 0.";
            $message_error = true;
        } else {
            // Fetch current item
            $stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            $stmt = null;

            if ($item) {
                // Validation for subtract
                if ($operation === 'subtract') {
                    if ($item['is_controlled']) {
                        $message = "Controlled stock can only leave through a chain of custody transfer or the destruction register.";
                        $message_error = true;
                    } elseif ($item['quantity'] < $quantity) {
                        $message = "You can't remove more than you have (" . bud_qty($item['quantity']) . " " . $item['unit'] . " on hand).";
                        $message_error = true;
                    } else {
                        // Perform subtraction
                        $new_qty = $item['quantity'] - $quantity;
                        $stmt = $pdo->prepare("UPDATE stock_items SET quantity = ?, updated_at = ? WHERE id = ?");
                        $stmt->execute([$new_qty, bud_now(), $id]);

                        // Audit log
                        $new_data = $item;
                        $new_data['quantity'] = $new_qty;
                        $new_data['adjustment_notes'] = $notes;
                        Audit::log($pdo, 'stock_items', $id, 'UPDATE', $item, $new_data);
                        $message = "Removed " . bud_qty($quantity) . " " . $item['unit'] . " of " . $item['name'] . ".";
                    }
                } elseif ($operation === 'add') {
                    // Perform addition
                    $new_qty = $item['quantity'] + $quantity;
                    $stmt = $pdo->prepare("UPDATE stock_items SET quantity = ?, updated_at = ? WHERE id = ?");
                    $stmt->execute([$new_qty, bud_now(), $id]);

                    // Audit log
                    $new_data = $item;
                    $new_data['quantity'] = $new_qty;
                    $new_data['adjustment_notes'] = $notes;
                    Audit::log($pdo, 'stock_items', $id, 'UPDATE', $item, $new_data);
                    $message = "Added " . bud_qty($quantity) . " " . $item['unit'] . " of " . $item['name'] . ".";
                }
            } else {
                $message = "That stock item wasn't found.";
                $message_error = true;
            }
        }
    }
}

// Fetch Data
$stock = $pdo->query("
    SELECT *
    FROM stock_items
    ORDER BY name ASC
")->fetchAll();

$pageTitle = 'Stock';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Stock</h1>
            <p>Everything on hand. Controlled stock leaves through a chain of custody transfer or the destruction register.</p>
        </hgroup>

        <?= bud_message($message, $message_error) ?>

        <div class="page-actions">
            <button type="button" onclick="openDialog('addDialog')">Add stock item</button>
            <a href="bundles.php" role="button" class="secondary outline">Bundles</a>
        </div>

        <?php if (empty($stock)): ?>
            <p class="empty-note">No stock items yet. Add your first one above.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="wide-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>SKU</th>
                            <th class="num">On hand</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stock as $item):
                            $isLow = bud_is_low_stock($item);
                            $itemJson = h(json_encode($item)); ?>
                            <tr<?= $isLow ? ' class="is-low"' : '' ?>>
                                <td class="cell-long">
                                    <strong><?= h($item['name']) ?></strong>
                                    <?php if ($item['is_controlled']): ?><span class="badge">Controlled</span><?php endif; ?>
                                    <?php if ($isLow): ?><span class="badge badge--todo">Low stock</span><?php endif; ?>
                                    <?php if (!empty($item['description'])): ?><small><?= h($item['description']) ?></small><?php endif; ?>
                                </td>
                                <td class="nowrap"><?= h($item['sku']) ?></td>
                                <td class="num"><?= h(bud_qty($item['quantity'])) ?> <?= h($item['unit']) ?></td>
                                <td class="row-actions row-actions--wrap">
                                    <button type="button" data-stock-action="add" data-item="<?= $itemJson ?>">Add</button>
                                    <?php if (!$item['is_controlled']): ?>
                                        <button type="button" class="secondary outline" data-stock-action="remove" data-item="<?= $itemJson ?>">Remove</button>
                                    <?php endif; ?>
                                    <button type="button" class="secondary outline" data-stock-action="edit" data-item="<?= $itemJson ?>">Edit</button>
                                    <button type="button" class="secondary outline" data-stock-action="history" data-item="<?= $itemJson ?>">History</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <!-- Add stock item -->
    <dialog id="addDialog" class="bud-modal bud-modal--wide">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3>Add stock item</h3>
            </header>
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-grid form-grid--2">
                    <label>Name
                        <input type="text" name="name" required>
                    </label>
                    <label>SKU
                        <input type="text" name="sku">
                    </label>
                </div>
                <div class="form-grid form-grid--3">
                    <label>Quantity
                        <input type="number" step="0.01" name="quantity" required>
                    </label>
                    <label>Unit
                        <input type="text" name="unit" placeholder="For example, jars or kg">
                    </label>
                    <label>Reorder level
                        <input type="number" step="0.01" name="reorder_level">
                    </label>
                </div>
                <small class="field-hint">The item shows as low stock at or under its reorder level. Leave it blank to never flag it.</small>
                <label class="check-row">
                    <input type="checkbox" name="is_controlled">
                    Controlled substance (it can only leave through a transfer or the destruction register)
                </label>
                <label>Description
                    <textarea name="description" rows="2"></textarea>
                </label>
                <footer>
                    <button type="button" class="secondary outline" data-close-dialog>Cancel</button>
                    <button type="submit">Add stock item</button>
                </footer>
            </form>
        </article>
    </dialog>

    <!-- Add or remove stock -->
    <dialog id="adjustDialog" class="bud-modal">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3 id="adjust_title">Add stock</h3>
            </header>
            <div class="modal-summary" id="adjust_summary"></div>
            <form method="POST">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="item_id" id="adjust_item_id">
                <input type="hidden" name="operation" id="adjust_operation">
                <label><span id="adjust_qty_label">Quantity to add</span>
                    <input type="number" step="0.01" name="quantity" min="0.01" required>
                </label>
                <label>Notes
                    <textarea name="notes" rows="2" placeholder="For example, a restock or a damaged box"></textarea>
                </label>
                <footer>
                    <button type="button" class="secondary outline" data-close-dialog>Cancel</button>
                    <button type="submit" id="adjust_submit">Add stock</button>
                </footer>
            </form>
        </article>
    </dialog>

    <!-- Edit a stock item -->
    <dialog id="editDialog" class="bud-modal bud-modal--wide">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3>Edit stock item</h3>
            </header>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="form-grid form-grid--2">
                    <label>Name
                        <input type="text" name="name" id="edit_name" required>
                    </label>
                    <label>SKU
                        <input type="text" name="sku" id="edit_sku">
                    </label>
                </div>
                <div class="form-grid form-grid--3">
                    <label>Quantity
                        <input type="number" step="0.01" name="quantity" id="edit_quantity" required>
                    </label>
                    <label>Unit
                        <input type="text" name="unit" id="edit_unit">
                    </label>
                    <label>Reorder level
                        <input type="number" step="0.01" name="reorder_level" id="edit_reorder_level">
                    </label>
                </div>
                <label class="check-row">
                    <input type="checkbox" name="is_controlled" id="edit_is_controlled">
                    Controlled substance (it can only leave through a transfer or the destruction register)
                </label>
                <label>Description
                    <textarea name="description" id="edit_description" rows="2"></textarea>
                </label>
                <footer>
                    <button type="button" class="secondary outline" data-close-dialog>Cancel</button>
                    <button type="submit">Save changes</button>
                </footer>
            </form>
        </article>
    </dialog>

    <!-- An item's history -->
    <dialog id="historyDialog" class="bud-modal bud-modal--wide">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3 id="history_title">History</h3>
            </header>
            <div id="history_content"><p class="empty-note">Loading the history…</p></div>
        </article>
    </dialog>

    <script>
        document.addEventListener('click', (e) => {
            const button = e.target.closest('[data-stock-action]');
            if (!button) return;
            const item = JSON.parse(button.dataset.item);
            const action = button.dataset.stockAction;
            if (action === 'add' || action === 'remove') {
                openAdjust(item, action);
            } else if (action === 'edit') {
                openEdit(item);
            } else if (action === 'history') {
                openHistory(item);
            }
        });

        function openAdjust(item, action) {
            const adding = action === 'add';
            document.getElementById('adjust_title').textContent = adding ? 'Add stock' : 'Remove stock';
            document.getElementById('adjust_qty_label').textContent = adding ? 'Quantity to add' : 'Quantity to remove';
            document.getElementById('adjust_submit').textContent = adding ? 'Add stock' : 'Remove stock';
            document.getElementById('adjust_operation').value = adding ? 'add' : 'subtract';
            document.getElementById('adjust_item_id').value = item.id;
            document.getElementById('adjust_summary').innerHTML =
                '<strong>' + escapeHtml(item.name) + '</strong><br>' +
                escapeHtml(formatQty(item.quantity) + ' ' + (item.unit || '')) + ' on hand';
            const dialog = document.getElementById('adjustDialog');
            dialog.querySelector('form').reset();
            openDialog(dialog);
        }

        function openEdit(item) {
            document.getElementById('edit_id').value = item.id;
            document.getElementById('edit_name').value = item.name ?? '';
            document.getElementById('edit_sku').value = item.sku ?? '';
            document.getElementById('edit_quantity').value = item.quantity ?? '';
            document.getElementById('edit_unit').value = item.unit ?? '';
            document.getElementById('edit_reorder_level').value = item.reorder_level ?? '';
            document.getElementById('edit_description').value = item.description ?? '';
            document.getElementById('edit_is_controlled').checked = item.is_controlled == 1;
            openDialog('editDialog');
        }

        function openHistory(item) {
            const content = document.getElementById('history_content');
            document.getElementById('history_title').textContent = 'History of ' + item.name;
            content.innerHTML = '<p class="empty-note">Loading the history…</p>';
            openDialog('historyDialog');

            fetch('get_stock_history.php?id=' + encodeURIComponent(item.id))
                .then(r => r.json())
                .then(rows => {
                    if (rows.length === 0) {
                        content.innerHTML = '<p class="empty-note">No changes recorded for this item yet.</p>';
                        return;
                    }
                    let html = '<div class="table-wrap"><table class="wide-table"><thead><tr>' +
                        '<th>Date</th><th>Change</th><th class="num">Amount</th><th class="num">On hand after</th><th>Notes</th>' +
                        '</tr></thead><tbody>';
                    rows.forEach(row => {
                        const diff = Number(row.diff);
                        const sign = diff > 0 ? '+' : '';
                        const cls = diff > 0 ? 'num num--in' : (diff < 0 ? 'num num--out' : 'num');
                        const type = diff > 0 ? 'Added' : (diff < 0 ? 'Removed' : 'Edited');
                        html += '<tr>' +
                            '<td>' + escapeHtml(formatDateTime(row.timestamp)) + '</td>' +
                            '<td>' + escapeHtml(type) + '</td>' +
                            '<td class="' + cls + '">' + escapeHtml(sign + formatQty(diff)) + '</td>' +
                            '<td class="num">' + escapeHtml(formatQty(row.qty_new)) + '</td>' +
                            '<td class="cell-long">' + escapeHtml(row.notes || '') + '</td>' +
                            '</tr>';
                    });
                    html += '</tbody></table></div>';
                    content.innerHTML = html;
                })
                .catch(() => {
                    content.innerHTML = '<p class="empty-note">The history couldn\'t be loaded. Try again.</p>';
                });
        }
    </script>
<?php require 'includes/footer.php'; ?>
