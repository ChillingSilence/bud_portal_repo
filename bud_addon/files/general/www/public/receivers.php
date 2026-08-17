<?php
require_once 'config.php';

$message = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_receiver') {
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO verified_receivers (name, contact_person, address, phone, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $contact_person, $address, $phone, $notes]);
            $message = "Receiver \"$name\" added successfully.";
        } else {
            $message = "Error: Receiver name is required.";
        }
    } elseif ($action === 'delete_receiver') {
        $id = intval($_POST['receiver_id'] ?? 0);
        if ($id) {
            $pdo->prepare("DELETE FROM verified_receivers WHERE id = ?")->execute([$id]);
            $message = "Receiver deleted.";
        }
    } elseif ($action === 'edit_receiver') {
        $id = intval($_POST['receiver_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($id && $name) {
            $stmt = $pdo->prepare("UPDATE verified_receivers SET name=?, contact_person=?, address=?, phone=?, notes=? WHERE id=?");
            $stmt->execute([$name, $contact_person, $address, $phone, $notes, $id]);
            $message = "Receiver updated.";
        }
    }
}

$receivers = $pdo->query("SELECT * FROM verified_receivers ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?= APP_NAME ?> - Verified Receivers
    </title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="container">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.5rem;">
            <a href="custody.php" style="color: var(--primary-color); text-decoration: none; font-size: 0.9rem;">← Back
                to Chain of Custody</a>
        </div>
        <h1>Verified Receivers</h1>
        <p style="color: var(--text-muted, #aaa); margin-bottom: 1.5rem;">Pre-registered destinations for Chain of
            Custody transfers and packing slips.</p>

        <?php if ($message): ?>
            <div class="glass-panel" style="margin-bottom: 1rem; border-color: var(--accent-color);">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Add Receiver Form -->
        <div class="glass-panel" style="margin-bottom: 2rem;">
            <h3>+ Add New Receiver</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_receiver">
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label>Pharmacy / Destination Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="name" placeholder="e.g. Westmere Pharmacy" required>
                    </div>
                    <div>
                        <label>Contact Person (default receiver name)</label>
                        <input type="text" name="contact_person" placeholder="e.g. Samantha">
                    </div>
                </div>
                <div style="margin-top: 1rem;">
                    <label>Address (shown on packing slips)</label>
                    <input type="text" name="address" placeholder="e.g. 123 Main St, Westmere, Auckland">
                </div>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                    <div>
                        <label>Phone</label>
                        <input type="text" name="phone" placeholder="Optional">
                    </div>
                    <div>
                        <label>Notes</label>
                        <input type="text" name="notes" placeholder="Optional">
                    </div>
                </div>
                <div style="margin-top: 1.5rem;">
                    <button type="submit" class="btn">Save Receiver</button>
                </div>
            </form>
        </div>

        <!-- Receivers List -->
        <div class="glass-panel">
            <h3>Registered Receivers</h3>
            <?php if (empty($receivers)): ?>
                <p>No receivers registered yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Contact Person</th>
                                <th>Address</th>
                                <th>Phone</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($receivers as $r): ?>
                                <tr id="row-<?= $r['id'] ?>">
                                    <td><strong>
                                            <?= h($r['name']) ?>
                                        </strong></td>
                                    <td>
                                        <?= h($r['contact_person']) ?>
                                    </td>
                                    <td>
                                        <?= h($r['address']) ?>
                                    </td>
                                    <td>
                                        <?= h($r['phone']) ?>
                                    </td>
                                    <td>
                                        <?= h($r['notes']) ?>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <button onclick="openEdit(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-outline-primary"
                                            style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">Edit</button>
                                        <form method="POST" style="display:inline;"
                                            onsubmit="return confirm('Delete this receiver?');">
                                            <input type="hidden" name="action" value="delete_receiver">
                                            <input type="hidden" name="receiver_id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn"
                                                style="padding: 0.25rem 0.5rem; font-size: 0.8rem; background: #ef4444;">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:100; backdrop-filter:blur(5px); overflow-y:auto;">
        <div class="glass-panel" style="margin: 5vh auto; max-width: 600px; position:relative;">
            <button onclick="document.getElementById('editModal').style.display='none'"
                style="position:absolute; right:1rem; top:1rem; background:transparent; color:var(--text-color); border:1px solid var(--card-border);">✕
                Close</button>
            <h3>Edit Receiver</h3>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="edit_receiver">
                <input type="hidden" name="receiver_id" id="edit_id">
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label>Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="name" id="edit_name" required>
                    </div>
                    <div>
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" id="edit_contact">
                    </div>
                </div>
                <div style="margin-top:1rem;">
                    <label>Address</label>
                    <input type="text" name="address" id="edit_address">
                </div>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; margin-top:1rem;">
                    <div>
                        <label>Phone</label>
                        <input type="text" name="phone" id="edit_phone">
                    </div>
                    <div>
                        <label>Notes</label>
                        <input type="text" name="notes" id="edit_notes">
                    </div>
                </div>
                <div style="margin-top:1.5rem;">
                    <button type="submit" class="btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEdit(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_name').value = data.name || '';
            document.getElementById('edit_contact').value = data.contact_person || '';
            document.getElementById('edit_address').value = data.address || '';
            document.getElementById('edit_phone').value = data.phone || '';
            document.getElementById('edit_notes').value = data.notes || '';
            document.getElementById('editModal').style.display = 'block';
        }
    </script>
</body>

</html>
