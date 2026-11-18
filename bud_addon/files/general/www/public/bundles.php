<?php
require_once 'config.php';
require_once 'includes/audit.php';
require_once 'includes/stock.php';

$message = '';
$message_error = false;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // A retired stock item can't go into a bundle. The form may have been
    // open since before it was retired.
    $retired_names = [];
    if ($action === 'create' || $action === 'edit') {
        $picked = array_values(array_filter(array_map('intval', (array) ($_POST['bundle_item_id'] ?? []))));
        if ($picked) {
            $marks = implode(',', array_fill(0, count($picked), '?'));
            $stmt = $pdo->prepare("SELECT DISTINCT name FROM stock_items WHERE is_active = 0 AND id IN ($marks) ORDER BY name ASC");
            $stmt->execute($picked);
            $retired_names = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt = null;
        }
    }

    if ($retired_names) {
        $one = count($retired_names) === 1;
        $message = bud_and_list($retired_names) . ($one ? ' is' : ' are') . ' retired. Bring ' . ($one ? 'it' : 'them') . ' back on the Stock page first, or choose another item.';
        $message_error = true;
    } elseif ($action === 'create') {
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
    } elseif ($action === 'retire_bundle' || $action === 'delete' || $action === 'restore_bundle') {
        // Bundles are retired, never deleted: past transfers name them.
        // ('delete' came from BUD 0.x and only ever hid the bundle too.)
        $stmt = $pdo->prepare("SELECT * FROM product_bundles WHERE id = ?");
        $stmt->execute([intval($_POST['id'] ?? 0)]);
        $bundle = $stmt->fetch();
        $stmt = null;

        if (!$bundle) {
            $message = "That bundle wasn't found.";
            $message_error = true;
        } elseif ($action !== 'restore_bundle') {
            if (!bud_is_active($bundle)) {
                $message = $bundle['name'] . " is already retired.";
            } else {
                $pdo->prepare("UPDATE product_bundles SET is_active = 0, updated_at = ? WHERE id = ?")->execute([bud_now(), $bundle['id']]);
                $new_data = $bundle;
                $new_data['is_active'] = 0;
                Audit::log($pdo, 'product_bundles', $bundle['id'], 'UPDATE', $bundle, $new_data);
                $message = "Retired " . $bundle['name'] . ". It's listed under Retired at the bottom of this page.";
            }
        } else {
            $blocker = bud_bundle_restore_blocker($pdo, $bundle);
            if (bud_is_active($bundle)) {
                $message = $bundle['name'] . " is already in use.";
            } elseif ($blocker !== '') {
                $message = $blocker;
                $message_error = true;
            } else {
                $pdo->prepare("UPDATE product_bundles SET is_active = 1, updated_at = ? WHERE id = ?")->execute([bud_now(), $bundle['id']]);
                $new_data = $bundle;
                $new_data['is_active'] = 1;
                Audit::log($pdo, 'product_bundles', $bundle['id'], 'UPDATE', $bundle, $new_data);
                $message = "Brought " . $bundle['name'] . " back. It's offered on new transfers again.";
            }
        }
    }
}

// Bundles in use, then retired ones. Only stock items in use can go in one.
$bundles = $pdo->query("SELECT * FROM product_bundles WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$retired_bundles = $pdo->query("SELECT * FROM product_bundles WHERE is_active = 0 ORDER BY name ASC")->fetchAll();
$stock_items = $pdo->query("SELECT id, name, sku, unit FROM stock_items WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

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

        <?php if (empty($bundles) && empty($retired_bundles)): ?>
            <p class="empty-note">No bundles yet.</p>
        <?php elseif (empty($bundles)): ?>
            <p class="empty-note">No bundles in use. Bring one back from Retired below, or make a new one.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="wide-table bundle-table">
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
                                    <form method="POST"
                                        data-confirm-title="Retire <?= h($bundle['name']) ?>?"
                                        data-confirm-message="It won't be offered on new transfers. Past transfers keep it, and you can bring it back at any time."
                                        data-confirm-label="Retire">
                                        <input type="hidden" name="action" value="retire_bundle">
                                        <input type="hidden" name="id" value="<?= h($bundle['id']) ?>">
                                        <button type="submit" class="secondary outline">Retire</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if (!empty($retired_bundles)): ?>
            <section class="retired-section" aria-labelledby="retired-heading">
                <div class="panel-head">
                    <div>
                        <h2 id="retired-heading">Retired</h2>
                        <p>Not offered on new transfers. Past transfers keep them.</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="wide-table bundle-table">
                        <thead>
                            <tr>
                                <th>Bundle</th>
                                <th>SKU</th>
                                <th>Contents</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($retired_bundles as $bundle):
                                $items_stmt->execute([$bundle['id']]);
                                $bundle_items = $items_stmt->fetchAll();
                                $blocker = bud_bundle_restore_blocker($pdo, $bundle); ?>
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
                                        <?php if ($blocker !== ''): ?>
                                            <button type="button" class="secondary outline" data-notice-title="<?= h($bundle['name']) ?> can't come back yet" data-notice-message="<?= h($blocker) ?>">Bring back</button>
                                        <?php else: ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="restore_bundle">
                                                <input type="hidden" name="id" value="<?= h($bundle['id']) ?>">
                                                <button type="submit" class="secondary outline">Bring back</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
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
