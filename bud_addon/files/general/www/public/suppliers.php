<?php
require_once 'config.php';
require_once 'includes/audit.php';

$message = '';
$message_error = false;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = $_POST['name'] ?? '';
        $contact = $_POST['contact'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $address = $_POST['address'] ?? '';
        $notes = $_POST['notes'] ?? '';

        if ($name) {
            try {
                $now = bud_now();
                $stmt = $pdo->prepare("INSERT INTO suppliers (name, contact_person, email, phone, address, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $contact, $email, $phone, $address, $notes, $now, $now]);
                $id = $pdo->lastInsertId();

                Audit::log($pdo, 'suppliers', $id, 'INSERT', null, $_POST);
                $message = "Added " . $name . " to your suppliers.";
            } catch (Exception $e) {
                $message = "Couldn't add the supplier: " . $e->getMessage();
                $message_error = true;
            }
        } else {
            $message = "A supplier needs a name.";
            $message_error = true;
        }
    } elseif ($action === 'edit') {
        $id = $_POST['id'] ?? 0;
        $name = $_POST['name'] ?? '';
        $contact = $_POST['contact'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $address = $_POST['address'] ?? '';
        $notes = $_POST['notes'] ?? '';

        // Fetch old values for audit
        $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        $stmt = null;

        if ($old) {
            $stmt = $pdo->prepare("UPDATE suppliers SET name=?, contact_person=?, email=?, phone=?, address=?, notes=?, updated_at=? WHERE id=?");
            $stmt->execute([$name, $contact, $email, $phone, $address, $notes, bud_now(), $id]);

            Audit::log($pdo, 'suppliers', $id, 'UPDATE', $old, $_POST);
            $message = "Saved the changes to " . $name . ".";
        } else {
            $message = "That supplier wasn't found.";
            $message_error = true;
        }
    }
}

// Fetch Suppliers
$suppliers = $pdo->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

$pageTitle = 'Suppliers';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Suppliers</h1>
            <p>Contact details for the companies you buy from.</p>
        </hgroup>

        <?= bud_message($message, $message_error) ?>

        <div class="page-actions">
            <button type="button" onclick="openSupplier(null)">Add supplier</button>
        </div>

        <?php if (empty($suppliers)): ?>
            <p class="empty-note">No suppliers yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="wide-table">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Contact</th>
                            <th>Email and phone</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $s): ?>
                            <tr>
                                <td class="cell-long">
                                    <strong><?= h($s['name']) ?></strong>
                                    <?php if (!empty($s['address'])): ?><small><?= h($s['address']) ?></small><?php endif; ?>
                                </td>
                                <td><?= h($s['contact_person']) ?></td>
                                <td>
                                    <?php if (!empty($s['email'])): ?><a href="mailto:<?= h($s['email']) ?>"><?= h($s['email']) ?></a><?php endif; ?>
                                    <?php if (!empty($s['phone'])): ?><small><?= h($s['phone']) ?></small><?php endif; ?>
                                </td>
                                <td class="row-actions">
                                    <button type="button" class="secondary outline" data-supplier="<?= h(json_encode($s)) ?>" onclick="openSupplier(JSON.parse(this.dataset.supplier))">Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <!-- Add or edit a supplier -->
    <dialog id="supplierDialog" class="bud-modal bud-modal--wide">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3 id="supplier_title">Add supplier</h3>
            </header>
            <form method="POST" id="supplierForm">
                <input type="hidden" name="action" id="supplier_action" value="create">
                <input type="hidden" name="id" id="edit_id">
                <div class="form-grid form-grid--2">
                    <label>Name
                        <input type="text" name="name" id="edit_name" required>
                    </label>
                    <label>Contact person
                        <input type="text" name="contact" id="edit_contact">
                    </label>
                    <label>Email
                        <input type="email" name="email" id="edit_email">
                    </label>
                    <label>Phone
                        <input type="text" name="phone" id="edit_phone">
                    </label>
                </div>
                <label>Address
                    <textarea name="address" id="edit_address" rows="2"></textarea>
                </label>
                <label>Notes
                    <textarea name="notes" id="edit_notes" rows="2"></textarea>
                </label>
                <footer>
                    <button type="button" class="secondary outline" data-close-dialog>Cancel</button>
                    <button type="submit" id="supplier_submit">Add supplier</button>
                </footer>
            </form>
        </article>
    </dialog>

    <script>
        // supplier is null for a new one
        function openSupplier(supplier) {
            const editing = !!supplier;
            const s = supplier || {};
            document.getElementById('supplierForm').reset();
            document.getElementById('supplier_title').textContent = editing ? 'Edit supplier' : 'Add supplier';
            document.getElementById('supplier_submit').textContent = editing ? 'Save changes' : 'Add supplier';
            document.getElementById('supplier_action').value = editing ? 'edit' : 'create';
            document.getElementById('edit_id').value = s.id ?? '';
            document.getElementById('edit_name').value = s.name ?? '';
            document.getElementById('edit_contact').value = s.contact_person ?? '';
            document.getElementById('edit_email').value = s.email ?? '';
            document.getElementById('edit_phone').value = s.phone ?? '';
            document.getElementById('edit_address').value = s.address ?? '';
            document.getElementById('edit_notes').value = s.notes ?? '';
            openDialog('supplierDialog');
        }
    </script>
<?php require 'includes/footer.php'; ?>
