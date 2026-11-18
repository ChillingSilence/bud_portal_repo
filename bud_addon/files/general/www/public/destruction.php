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
            $message = "Choose the stock item that was destroyed.";
            $message_error = true;
        } elseif ($qty <= 0) {
            $message = "The quantity destroyed has to be more than 0.";
            $message_error = true;
        } elseif ($qty > floatval($item['quantity'])) {
            $message = "You can't destroy " . bud_qty($qty) . ". There's only " . bud_qty($item['quantity']) . " {$item['unit']} of {$item['name']} in stock.";
            $message_error = true;
        } elseif (!$reason || !$method || !$destroyed_by) {
            $message = "The reason, the method and who destroyed it are all needed.";
            $message_error = true;
        } else {
            // 1. Deduct the stock (audit-logged like every other adjustment)
            $old_stock = $item;
            $destroyed_at = bud_now();
            $pdo->prepare("UPDATE stock_items SET quantity = quantity - ?, updated_at = ? WHERE id = ?")->execute([$qty, $destroyed_at, $item_id]);

            $new_stock_stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
            $new_stock_stmt->execute([$item_id]);
            $new_stock = $new_stock_stmt->fetch();

            // 2. Write the destruction register entry.
            //    item_name is denormalised so the record stays intact for the
            //    MCA even if the stock item is later renamed or deleted.
            $item_label = $item['name'] . ($item['sku'] ? ' (' . $item['sku'] . ')' : '');
            $ins = $pdo->prepare("INSERT INTO destruction_log
                (stock_item_id, item_name, batch, quantity, unit, reason, method, destroyed_by, witness, witness_signature, notes, destroyed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
                $destroyed_at,
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

            $message = "Recorded the destruction of " . bud_qty($qty) . " {$item['unit']} of {$item_label} as register entry #$dest_id.";
        }
    } catch (Exception $e) {
        $message = "Couldn't record the destruction: " . $e->getMessage();
        $message_error = true;
    }
}

// ── Register (filter by month, empty = everything) ────────────────────────────
$filter_month = $_GET['month'] ?? '';
if ($filter_month !== '' && !preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
    $filter_month = '';
}

if ($filter_month !== '') {
    $reg_stmt = $pdo->prepare("SELECT * FROM destruction_log WHERE destroyed_at LIKE ? ORDER BY destroyed_at DESC, id DESC");
    $reg_stmt->execute(["$filter_month%"]);
} else {
    $reg_stmt = $pdo->query("SELECT * FROM destruction_log ORDER BY destroyed_at DESC, id DESC");
}
$register = $reg_stmt->fetchAll();

// Stock items for the form (anything in stock; controlled items flagged)
$stock_options = $pdo->query("SELECT id, name, sku, quantity, unit, is_controlled
    FROM stock_items WHERE quantity > 0 ORDER BY is_controlled DESC, name ASC")->fetchAll();

// The CSV export: the same columns and values BUD 0.19 wrote, built from the
// stored data rather than the table on screen
$csv_rows = [['#', 'Date', 'Item', 'Batch', 'Qty', 'Reason', 'Method', 'Destroyed By', 'Witness']];
foreach ($register as $r) {
    $csv_rows[] = [
        (string) $r['id'],
        (string) $r['destroyed_at'],
        $r['item_name'] . ($r['notes'] ? "\n" . $r['notes'] : ''),
        $r['batch'] ?: '-',
        rtrim('-' . (string) $r['quantity'] . ' ' . $r['unit']),
        (string) $r['reason'],
        (string) $r['method'],
        (string) $r['destroyed_by'],
        $r['witness'] ?: '-',
    ];
}

// Reasons and methods: the stored wording stays as it always was, so the
// register reads the same from start to end, and the list shows plainer words
$reasons = [
    'Expired / past use-by date' => 'Expired or past its use-by date',
    'Damaged' => 'Damaged',
    'Contaminated' => 'Contaminated',
    'Quality failure / recall' => 'Failed a quality check, or recalled',
    'Other (see notes)' => 'Other (say what in the notes)',
];
$methods = [
    'Incineration' => 'Incineration',
    'Denatured & disposed (rendered unusable)' => 'Denatured and disposed of (made unusable)',
    'Returned to supplier for destruction' => 'Returned to the supplier for destruction',
    'Other (see notes)' => 'Other (say what in the notes)',
];

$pageTitle = 'Destruction register';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Destruction register</h1>
            <p>A permanent record of destroyed stock (expired, damaged or otherwise unusable) for the Medicinal Cannabis Agency. Entries can't be edited or deleted.</p>
        </hgroup>

        <?= bud_message($message, $message_error) ?>

        <div class="page-actions">
            <button type="button" onclick="openDestroy()">Record destruction</button>
        </div>

        <section>
            <div class="panel-head">
                <div>
                    <h2>Register</h2>
                    <p><?= h(bud_plural(count($register), 'entry', 'entries')) ?><?= $filter_month ? ' in ' . h(bud_month_label($filter_month)) : ', all time' ?></p>
                </div>
                <form method="GET" class="toolbar">
                    <label>Month
                        <input type="month" name="month" value="<?= h($filter_month) ?>">
                    </label>
                    <button type="submit" class="secondary outline">Filter</button>
                    <?php if ($filter_month): ?>
                        <a href="destruction.php" role="button" class="secondary outline">Show all</a>
                    <?php endif; ?>
                    <?php if (!empty($register)): ?>
                        <button type="button" class="secondary outline" onclick="downloadCsv(JSON.parse(document.getElementById('registerCsv').textContent), 'Destruction_Register<?= $filter_month ? '_' . h($filter_month) : '' ?>.csv')">Export CSV</button>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($register)): ?>
                <p class="empty-note">No destruction entries<?= $filter_month ? ' for this month' : ' yet' ?>.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="wide-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Item</th>
                                <th>Batch</th>
                                <th class="num">Quantity</th>
                                <th>Reason and method</th>
                                <th>Destroyed by</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($register as $r): ?>
                                <tr>
                                    <td class="nowrap"><?= h($r['id']) ?></td>
                                    <td class="nowrap"><?= h(bud_datetime($r['destroyed_at'])) ?></td>
                                    <td class="cell-name cell-long">
                                        <?= h($r['item_name']) ?>
                                        <?php if ($r['notes']): ?><small><?= h($r['notes']) ?></small><?php endif; ?>
                                    </td>
                                    <td class="nowrap"><?= h($r['batch'] ?: '-') ?></td>
                                    <td class="num num--out"><?= BUD_MINUS ?><?= h(bud_qty($r['quantity'])) ?> <?= h($r['unit']) ?></td>
                                    <td class="cell-name cell-long">
                                        <?= h($r['reason']) ?>
                                        <small><?= h($r['method']) ?></small>
                                    </td>
                                    <td>
                                        <?= h($r['destroyed_by']) ?>
                                        <small><?= $r['witness'] ? 'Witness ' . h($r['witness']) : 'No witness' ?></small>
                                        <?php if ($r['witness_signature']): ?>
                                            <button type="button" class="secondary outline btn-small witness-sig" data-signature="<?= h($r['witness_signature']) ?>" onclick="viewSignature(this.dataset.signature)">View signature</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Record a destruction -->
    <dialog id="destroyDialog" class="bud-modal bud-modal--wide">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3>Record destruction</h3>
            </header>
            <p class="modal-text">The stock comes off as soon as you save, and the register entry is permanent.</p>
            <form method="POST" id="destructionForm">
                <input type="hidden" name="action" value="destroy">
                <input type="hidden" name="signature_data" id="destroy_signature_data">

                <div class="form-grid form-grid--3">
                    <label class="field--wide">Item
                        <select name="item_id" required>
                            <option value="">Choose the stock item</option>
                            <?php foreach ($stock_options as $opt): ?>
                                <option value="<?= h($opt['id']) ?>"><?= $opt['is_controlled'] ? 'Controlled: ' : '' ?><?= h($opt['name']) ?><?= $opt['sku'] ? ' (' . h($opt['sku']) . ')' : '' ?>, <?= h(bud_qty($opt['quantity'])) ?> <?= h($opt['unit']) ?> in stock</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Quantity destroyed
                        <input type="number" step="0.01" min="0.01" name="qty" required>
                    </label>
                    <label>Batch or lot
                        <input type="text" name="batch" placeholder="For example, B2611-01">
                    </label>
                </div>
                <div class="form-grid form-grid--2">
                    <label>Reason
                        <select name="reason" required>
                            <option value="">Choose a reason</option>
                            <?php foreach ($reasons as $value => $label): ?>
                                <option value="<?= h($value) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Method of destruction
                        <select name="method" required>
                            <option value="">Choose a method</option>
                            <?php foreach ($methods as $value => $label): ?>
                                <option value="<?= h($value) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Destroyed by
                        <input type="text" name="destroyed_by" placeholder="Staff member's name" required>
                    </label>
                    <label>Witness
                        <input type="text" name="witness" placeholder="A second staff member or an authorised person">
                    </label>
                </div>
                <label>Notes
                    <input type="text" name="notes" placeholder="Anything else worth recording">
                </label>

                <h4>Witness signature</h4>
                <p class="modal-text">Optional, but recommended. The witness signs to confirm they saw it destroyed.</p>
                <canvas id="sig-pad-destroy" class="signature-pad" width="600" height="180" aria-label="Witness signature box"></canvas>
                <button type="button" class="secondary outline btn-small" onclick="destroyPad.clear()">Clear signature</button>

                <footer>
                    <button type="button" class="secondary outline" data-close-dialog>Cancel</button>
                    <button type="submit" class="danger">Destroy and record</button>
                </footer>
            </form>
        </article>
    </dialog>

    <!-- A witness's signature -->
    <dialog id="sigDialog" class="bud-modal bud-modal--wide">
        <article>
            <header>
                <button type="button" rel="prev" aria-label="Close" data-close-dialog></button>
                <h3>Witness signature</h3>
            </header>
            <img id="sig-image" class="signature-image" src="" alt="Witness signature">
        </article>
    </dialog>

    <script type="application/json" id="registerCsv"><?= json_encode($csv_rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script>
        const destroyPad = createSignaturePad(document.getElementById('sig-pad-destroy'));

        function openDestroy() {
            document.getElementById('destructionForm').reset();
            destroyPad.clear();
            openDialog('destroyDialog');
        }

        // Ask before anything is destroyed, then save with the signature (if any)
        document.getElementById('destructionForm').addEventListener('submit', (e) => {
            const form = e.target;
            if (form.dataset.confirmed) return;
            e.preventDefault();
            confirmAction({
                title: 'Destroy this stock?',
                message: 'The stock comes off now and a permanent register entry is made. This can\'t be undone.',
                confirmLabel: 'Destroy and record',
                danger: true,
            }).then((ok) => {
                if (!ok) return;
                document.getElementById('destroy_signature_data').value = destroyPad.hasDrawn() ? destroyPad.toDataURL() : '';
                form.dataset.confirmed = '1';
                submitOnce(form);
            });
        });

        function viewSignature(dataUrl) {
            document.getElementById('sig-image').src = dataUrl;
            openDialog('sigDialog');
        }
    </script>
<?php require 'includes/footer.php'; ?>
