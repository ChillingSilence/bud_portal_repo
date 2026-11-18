<?php
require_once 'config.php';
require_once 'includes/audit.php';

$message = '';
$message_error = false;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $name = $_POST['name'];
            $sku = $_POST['sku'] ?? '';
            $description = $_POST['description'] ?? '';

            $now = bud_now();
            $stmt = $pdo->prepare("INSERT INTO product_bundles (name, sku, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $sku, $description, $now, $now]);
            $bundle_id = $pdo->lastInsertId();

            // Add bundle items
            $item_ids = $_POST['bundle_item_id'] ?? [];
            $item_qtys = $_POST['bundle_item_qty'] ?? [];

            for ($i = 0; $i < count($item_ids); $i++) {
                if ($item_ids[$i] && ($item_qtys[$i] ?? 0) > 0) {
                    $stmt = $pdo->prepare("INSERT INTO bundle_items (bundle_id, stock_item_id, quantity) VALUES (?, ?, ?)");
                    $stmt->execute([$bundle_id, $item_ids[$i], $item_qtys[$i]]);
                }
            }

            Audit::log($pdo, 'product_bundles', $bundle_id, 'INSERT', null, $_POST);
            $message = "Created the bundle " . $name . ".";
        } catch (Exception $e) {
            $message = "Couldn't create the bundle: " . $e->getMessage();
            $message_error = true;
        }
    } elseif ($action === 'edit') {
        try {
            $id = $_POST['id'];
            $name = $_POST['name'];
            $sku = $_POST['sku'] ?? '';
            $description = $_POST['description'] ?? '';

            // Get old data for audit
            $old_stmt = $pdo->prepare("SELECT * FROM product_bundles WHERE id = ?");
            $old_stmt->execute([$id]);
            $old = $old_stmt->fetch();
            $old_stmt = null;

            // Update bundle
            $stmt = $pdo->prepare("UPDATE product_bundles SET name=?, sku=?, description=?, updated_at=? WHERE id=?");
            $stmt->execute([$name, $sku, $description, bud_now(), $id]);

            // Delete old bundle items
            $pdo->prepare("DELETE FROM bundle_items WHERE bundle_id = ?")->execute([$id]);

            // Add new bundle items
            $item_ids = $_POST['bundle_item_id'] ?? [];
            $item_qtys = $_POST['bundle_item_qty'] ?? [];

            for ($i = 0; $i < count($item_ids); $i++) {
                if ($item_ids[$i] && ($item_qtys[$i] ?? 0) > 0) {
                    $stmt = $pdo->prepare("INSERT INTO bundle_items (bundle_id, stock_item_id, quantity) VALUES (?, ?, ?)");
                    $stmt->execute([$id, $item_ids[$i], $item_qtys[$i]]);
                }
            }

            Audit::log($pdo, 'product_bundles', $id, 'UPDATE', $old, $_POST);
            $message = "Saved the changes to " . $name . ".";
        } catch (Exception $e) {
            $message = "Couldn't save the bundle: " . $e->getMessage();
            $message_error = true;
        }
    } elseif ($action === 'delete') {
        try {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("UPDATE product_bundles SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            $message = "Bundle deleted.";
        } catch (Exception $e) {
            $message = "Couldn't delete the bundle: " . $e->getMessage();
            $message_error = true;
        }
    }
}

// Fetch bundles and stock items
$bundles = $pdo->query("SELECT * FROM product_bundles WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$stock_items = $pdo->query("SELECT id, name, sku, unit FROM stock_items ORDER BY name ASC")->fetchAll();

$items_stmt = $pdo->prepare("
    SELECT bi.quantity, si.name, si.unit
    FROM bundle_items bi
    JOIN stock_items si ON bi.stock_item_id = si.id
    WHERE bi.bundle_id = ?
");

$pageTitle = 'Bundles';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Bundles</h1>
            <p>Packs made of several stock items. Sending a bundle on a transfer takes each of its items out of stock.</p>
        </hgroup>

        <?= bud_message($message, $message_error) ?>

        <div class="page-actions">
            <button type="button" onclick="openBundle(null)">New bundle</button>
        </div>

        <?php if (empty($bundles)): ?>
            <p class="empty-note">No bundles yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="wide-table">
                    <thead>
                        <tr>
                            <th>Bundle</th>
                            <th>SKU</th>
                            <th>Contents</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bundles as $bundle):
                            $items_stmt->execute([$bundle['id']]);
                            $bundle_items = $items_stmt->fetchAll(); ?>
                            <tr>
                                <td class="cell-long">
                                    <strong><?= h($bundle['name']) ?></strong>
                                    <?php if (!empty($bundle['description'])): ?><small><?= h($bundle['description']) ?></small><?php endif; ?>
                                </td>
                                <td><?= h($bundle['sku']) ?></td>
                                <td class="cell-long">
                                    <?php if (empty($bundle_items)): ?>
                                        <span class="badge badge--neutral">No items</span>
                                    <?php endif; ?>
                                    <?php foreach ($bundle_items as $item): ?>
                                        <?= h(bud_qty($item['quantity'])) ?> × <?= h($item['name']) ?><br>
                                    <?php endforeach; ?>
                                </td>
                                <td class="row-actions">
                                    <button type="button" class="secondary outline" data-bundle="<?= h(json_encode($bundle)) ?>" onclick="openBundle(JSON.parse(this.dataset.bundle))">Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <!-- New or edit bundle -->
    <dialog id="bundleDialog" class="bud-modal bud-modal--wide">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3 id="bundle_title">New bundle</h3>
            </header>
            <form method="POST" id="bundleForm">
                <input type="hidden" name="action" id="bundle_action" value="create">
                <input type="hidden" name="id" id="bundle_id">
                <div class="form-grid form-grid--2">
                    <label>Name
                        <input type="text" name="name" id="bundle_name" required>
                    </label>
                    <label>SKU
                        <input type="text" name="sku" id="bundle_sku">
                    </label>
                </div>
                <label>Description
                    <textarea name="description" id="bundle_description" rows="2"></textarea>
                </label>

                <h4>Items in the bundle</h4>
                <div id="bundle_rows"></div>
                <button type="button" class="secondary outline add-row" onclick="addBundleRow()">Add another item</button>

                <footer>
                    <button type="button" class="secondary outline" data-close-dialog>Cancel</button>
                    <button type="submit" id="bundle_submit">Create bundle</button>
                </footer>
            </form>
        </article>
    </dialog>

    <template id="bundleRowTemplate">
        <div class="repeat-row repeat-row--2">
            <label>Item
                <select name="bundle_item_id[]">
                    <option value="">Choose an item</option>
                    <?php foreach ($stock_items as $item): ?>
                        <option value="<?= h($item['id']) ?>"><?= h($item['name']) ?><?= $item['sku'] ? ' (' . h($item['sku']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Quantity in each bundle
                <input type="number" step="0.01" min="0" name="bundle_item_qty[]">
            </label>
            <button type="button" class="secondary outline remove-row" onclick="removeBundleRow(this)" aria-label="Take this item out of the bundle">Remove</button>
        </div>
    </template>

    <script>
        function addBundleRow(stockItemId, quantity) {
            const row = document.getElementById('bundleRowTemplate').content.firstElementChild.cloneNode(true);
            if (stockItemId) row.querySelector('select').value = String(stockItemId);
            if (quantity !== undefined) row.querySelector('input').value = quantity;
            document.getElementById('bundle_rows').appendChild(row);
            return row;
        }

        function removeBundleRow(button) {
            const rows = document.getElementById('bundle_rows');
            button.closest('.repeat-row').remove();
            if (!rows.children.length) addBundleRow();
        }

        // bundle is null for a new bundle
        function openBundle(bundle) {
            const form = document.getElementById('bundleForm');
            form.reset();
            const rows = document.getElementById('bundle_rows');
            rows.innerHTML = '';
            const editing = !!bundle;
            document.getElementById('bundle_title').textContent = editing ? 'Edit bundle' : 'New bundle';
            document.getElementById('bundle_submit').textContent = editing ? 'Save changes' : 'Create bundle';
            document.getElementById('bundle_action').value = editing ? 'edit' : 'create';
            document.getElementById('bundle_id').value = editing ? bundle.id : '';
            document.getElementById('bundle_name').value = editing ? (bundle.name ?? '') : '';
            document.getElementById('bundle_sku').value = editing ? (bundle.sku ?? '') : '';
            document.getElementById('bundle_description').value = editing ? (bundle.description ?? '') : '';
            const submit = document.getElementById('bundle_submit');
            submit.disabled = false;
            openDialog('bundleDialog');

            if (!editing) {
                addBundleRow();
                return;
            }
            // Saving replaces the bundle's items, so it waits until they've loaded
            submit.disabled = true;
            fetch('get_bundle_items.php?id=' + encodeURIComponent(bundle.id))
                .then(r => r.json())
                .then(items => {
                    rows.innerHTML = '';
                    items.forEach(item => addBundleRow(item.stock_item_id, item.quantity));
                    if (!items.length) addBundleRow();
                    submit.disabled = false;
                })
                .catch(() => {
                    showToast('The bundle\'s items couldn\'t be loaded. Close it and try again.', 'error');
                });
        }
    </script>
<?php require 'includes/footer.php'; ?>
