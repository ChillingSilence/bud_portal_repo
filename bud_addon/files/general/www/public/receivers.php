<?php
require_once 'config.php';

$message = '';
$message_error = false;

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_receiver') {
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $licence = trim($_POST['licence_number'] ?? '');

        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO verified_receivers (name, contact_person, address, phone, notes, licence_number, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $contact_person, $address, $phone, $notes, $licence, bud_now()]);
            $message = "Added " . $name . " to your verified receivers.";
        } else {
            $message = "A receiver needs a name.";
            $message_error = true;
        }
    } elseif ($action === 'retire_receiver' || $action === 'delete_receiver') {
        // Receivers are retired, never deleted: past transfers link to them,
        // and their address belongs on those shipping manifests and reports.
        // ('delete_receiver' came from BUD 0.x pages and is treated the same.)
        $id = intval($_POST['receiver_id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE verified_receivers SET is_active = 0 WHERE id = ?")->execute([$id]);
            $message = "Retired the receiver. It won't be offered for new transfers, and past transfers keep it.";
        }
    } elseif ($action === 'restore_receiver') {
        $id = intval($_POST['receiver_id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE verified_receivers SET is_active = 1 WHERE id = ?")->execute([$id]);
            $message = "Brought the receiver back. It's offered for new transfers again.";
        }
    } elseif ($action === 'edit_receiver') {
        $id = intval($_POST['receiver_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $licence = trim($_POST['licence_number'] ?? '');

        if ($id && $name) {
            $stmt = $pdo->prepare("UPDATE verified_receivers SET name=?, contact_person=?, address=?, phone=?, notes=?, licence_number=? WHERE id=?");
            $stmt->execute([$name, $contact_person, $address, $phone, $notes, $licence, $id]);
            $message = "Saved the changes to " . $name . ".";
        } else {
            $message = "A receiver needs a name.";
            $message_error = true;
        }
    }
}

// Current receivers first, retired ones after
$receivers = $pdo->query("SELECT * FROM verified_receivers ORDER BY is_active DESC, name ASC")->fetchAll();

$pageTitle = 'Verified receivers';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Verified receivers</h1>
            <p>The pharmacies and places you send controlled stock to. Their address goes on shipping manifests and the monthly report.</p>
        </hgroup>

        <?= bud_message($message, $message_error) ?>

        <div class="page-actions">
            <button type="button" onclick="openReceiver(null)">Add receiver</button>
        </div>

        <?php if (empty($receivers)): ?>
            <p class="empty-note">No receivers yet. Add one before starting a chain of custody transfer.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="wide-table">
                    <thead>
                        <tr>
                            <th>Receiver</th>
                            <th>Contact person</th>
                            <th>Address</th>
                            <th>Phone</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($receivers as $r):
                            $active = (int) $r['is_active'] === 1; ?>
                            <tr id="row-<?= h($r['id']) ?>">
                                <td>
                                    <strong><?= h($r['name']) ?></strong>
                                    <?php if (!$active): ?><span class="badge badge--neutral">Retired</span><?php endif; ?>
                                    <?php if (!empty($r['licence_number'])): ?><small>Licence <?= h($r['licence_number']) ?></small><?php endif; ?>
                                </td>
                                <td><?= h($r['contact_person']) ?></td>
                                <td class="cell-long"><?= h($r['address']) ?></td>
                                <td class="nowrap"><?= h($r['phone']) ?></td>
                                <td class="cell-long"><?= h($r['notes']) ?></td>
                                <td class="row-actions">
                                    <button type="button" class="secondary outline" data-receiver="<?= h(json_encode($r)) ?>" onclick="openReceiver(JSON.parse(this.dataset.receiver))">Edit</button>
                                    <?php if ($active): ?>
                                        <form method="POST"
                                            data-confirm-title="Retire <?= h($r['name']) ?>?"
                                            data-confirm-message="It won't be offered for new transfers or Section 29 uploads. Past transfers keep it, with its address. You can bring it back at any time."
                                            data-confirm-label="Retire">
                                            <input type="hidden" name="action" value="retire_receiver">
                                            <input type="hidden" name="receiver_id" value="<?= h($r['id']) ?>">
                                            <button type="submit" class="secondary outline">Retire</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="restore_receiver">
                                            <input type="hidden" name="receiver_id" value="<?= h($r['id']) ?>">
                                            <button type="submit" class="secondary outline">Bring back</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <!-- Add or edit a receiver -->
    <dialog id="receiverDialog" class="bud-modal bud-modal--wide">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3 id="receiver_title">Add receiver</h3>
            </header>
            <form method="POST" id="receiverForm">
                <input type="hidden" name="action" id="receiver_action" value="add_receiver">
                <input type="hidden" name="receiver_id" id="edit_id">
                <div class="form-grid form-grid--2">
                    <label>Pharmacy or destination name
                        <input type="text" name="name" id="edit_name" placeholder="For example, Joe's Pharmacy" required>
                    </label>
                    <label>Contact person
                        <input type="text" name="contact_person" id="edit_contact" placeholder="For example, Jo Bloggs">
                    </label>
                </div>
                <small class="field-hint">The contact person is filled in as the receiver when a transfer arrives.</small>
                <label>Licence number
                    <input type="text" name="licence_number" id="edit_licence" placeholder="Optional, printed on the shipping manifest">
                </label>
                <label>Address
                    <input type="text" name="address" id="edit_address" placeholder="For example, 10 Example Street, Hamilton">
                </label>
                <small class="field-hint">Shown on shipping manifests and the monthly report.</small>
                <div class="form-grid form-grid--2">
                    <label>Phone
                        <input type="text" name="phone" id="edit_phone">
                    </label>
                    <label>Notes
                        <input type="text" name="notes" id="edit_notes">
                    </label>
                </div>
                <footer>
                    <button type="button" class="secondary outline" data-close-dialog>Cancel</button>
                    <button type="submit" id="receiver_submit">Add receiver</button>
                </footer>
            </form>
        </article>
    </dialog>

    <script>
        // receiver is null for a new one
        function openReceiver(receiver) {
            const editing = !!receiver;
            const r = receiver || {};
            document.getElementById('receiverForm').reset();
            document.getElementById('receiver_title').textContent = editing ? 'Edit receiver' : 'Add receiver';
            document.getElementById('receiver_submit').textContent = editing ? 'Save changes' : 'Add receiver';
            document.getElementById('receiver_action').value = editing ? 'edit_receiver' : 'add_receiver';
            document.getElementById('edit_id').value = r.id ?? '';
            document.getElementById('edit_name').value = r.name ?? '';
            document.getElementById('edit_contact').value = r.contact_person ?? '';
            document.getElementById('edit_licence').value = r.licence_number ?? '';
            document.getElementById('edit_address').value = r.address ?? '';
            document.getElementById('edit_phone').value = r.phone ?? '';
            document.getElementById('edit_notes').value = r.notes ?? '';
            openDialog('receiverDialog');
        }
    </script>
<?php require 'includes/footer.php'; ?>
