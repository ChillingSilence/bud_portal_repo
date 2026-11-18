<?php
/**
 * Demo data for BUD's screenshots (docs/screenshots) and for trying BUD out.
 *
 * Everything is made up: Example Growers Ltd, Joe's Pharmacy, Example
 * Couriers, "Cultivar A", Patient One. Never real people, receivers,
 * patients or cultivar names, because the screenshots are public.
 *
 * It builds a new database at BUD_DB_PATH, and never touches /data:
 *
 *   BUD_DB_PATH=/tmp/bud-demo/bud.db php tests/seed_demo_data.php
 *   BUD_DB_PATH=/tmp/bud-demo/bud.db php tests/seed_demo_data.php --force   (start again)
 *
 * Then serve it with that same BUD_DB_PATH:
 *
 *   cd bud_addon/files/general/www/public
 *   BUD_DB_PATH=/tmp/bud-demo/bud.db php -S 127.0.0.1:8420
 *
 * Dates are relative to today, so the Dashboard and this month's report
 * always have something on them.
 */

$db = getenv('BUD_DB_PATH');
if (!$db) {
    fwrite(STDERR, "Set BUD_DB_PATH to a throwaway database, for example BUD_DB_PATH=/tmp/bud-demo/bud.db\n");
    exit(1);
}
if (strpos(realpath(dirname($db)) ?: dirname($db), '/data') === 0) {
    fwrite(STDERR, "Refusing to seed demo data into /data. That's a real install's storage.\n");
    exit(1);
}
$force = in_array('--force', $argv, true);
if (file_exists($db) && !$force) {
    fwrite(STDERR, "$db already exists. Add --force to replace it with fresh demo data.\n");
    exit(1);
}
if (!is_dir(dirname($db))) {
    mkdir(dirname($db), 0775, true);
}
if (file_exists($db)) {
    unlink($db);
}
$docs = dirname($db) . '/coc_documents';
foreach (glob($docs . '/transfer-*') ?: [] as $old) {
    unlink($old);
}

$public = dirname(__DIR__) . '/bud_addon/files/general/www/public';
$fresh = new PDO("sqlite:$db");
$fresh->exec(file_get_contents($public . '/database/schema.sql'));
$fresh = null;

chdir($public);
require $public . '/config.php';          // upgrades the new database, sets up $pdo
require_once $public . '/includes/settings.php';

$q = function ($sql, $args = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return $pdo->lastInsertId();
};
// A date and time N days ago, in NZ time
$ago = function ($days, $time = '09:00:00') {
    return date('Y-m-d', strtotime("-$days days")) . ' ' . $time;
};
$day = function ($days) {
    return date('Y-m-d', strtotime("-$days days"));
};
$audit = function ($record, $action, $old, $new, $when) use ($q) {
    $q("INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, timestamp) VALUES ('stock_items', ?, ?, ?, ?, ?)",
        [$record, $action, $old ? json_encode($old) : null, json_encode($new), $when]);
};

// A signature drawn on the signature pad (a made-up scrawl)
$signature = 'data:image/png;base64,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';

// ── Your company ─────────────────────────────────────────────────────────────
bud_set_setting($pdo, 'company_name', 'Example Growers Ltd');
bud_set_setting($pdo, 'company_licence', 'MCL-0000');
bud_set_setting($pdo, 'company_address', "1 Example Road\nChristchurch 8000");
bud_set_setting($pdo, 'company_email', 'dispatch@example.co.nz');

// ── Suppliers ────────────────────────────────────────────────────────────────
$q("INSERT INTO suppliers (name, contact_person, email, phone, address, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
    ['Example Packaging Ltd', 'Alex Example', 'orders@example.co.nz', '09 555 0101', '2 Example Street, Auckland', $ago(60), $ago(60)]);
$q("INSERT INTO suppliers (name, contact_person, email, phone, address, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
    ['Example Labels Ltd', 'Sam Sample', 'hello@example.com', '03 555 0199', '3 Sample Lane, Christchurch', $ago(60), $ago(60)]);

// ── Stock ────────────────────────────────────────────────────────────────────
$items = [
    // name, SKU, controlled, quantity, unit, reorder level
    ['Cultivar A dried flower, 10 g jar', 'CA-10', 1, 300, 'jars', 50],
    ['Cultivar B dried flower, 10 g jar', 'CB-10', 1, 53, 'jars', 50],
    ['Glass jar, 60 ml', 'JAR-60', 0, 1200, 'units', 200],
    ['Child-resistant lid', 'LID-CR', 0, 1150, 'units', 200],
    ['Product label', 'LBL-01', 0, 110, 'units', 300],
    ['Patient information leaflet', 'PIL-01', 0, 800, 'units', 100],
];
$ids = [];
foreach ($items as $it) {
    $id = $q("INSERT INTO stock_items (name, sku, is_controlled, quantity, unit, reorder_level, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, '', ?, ?)",
        [$it[0], $it[1], $it[2], $it[3], $it[4], $it[5], $ago(45), $ago(45)]);
    $ids[] = (int) $id;
    $audit($id, 'INSERT', null, ['name' => $it[0], 'sku' => $it[1], 'quantity' => $it[3], 'unit' => $it[4]], $ago(45));
}
$set_qty = function ($id, $qty) use ($q) {
    $q("UPDATE stock_items SET quantity = ? WHERE id = ?", [$qty, $id]);
};

// A packing run today: 120 more jars of Cultivar A
$audit($ids[0], 'UPDATE', ['name' => $items[0][0], 'sku' => 'CA-10', 'quantity' => 300, 'unit' => 'jars'],
    ['name' => $items[0][0], 'sku' => 'CA-10', 'quantity' => 420, 'unit' => 'jars', 'adjustment_notes' => 'Packed batch B2611-01'],
    date('Y-m-d') . ' 08:30:00');

// Retired (1.1.0): a jar size nobody packs any more, and labels from an old
// design. They sit under Retired at the bottom of Stock, off low stock.
foreach ([
    // name, SKU, unit, reorder level, first stock, left now, note when it was used
    ['Glass jar, 30 ml', 'JAR-30', 'units', 100, 400, 0, 'The last of them used'],
    ['Product label, old design', 'LBL-00', 'units', 0, 500, 85, 'Used on the last batch in the old design'],
] as $it) {
    $id = $q("INSERT INTO stock_items (name, sku, is_controlled, quantity, unit, reorder_level, description, is_active, created_at, updated_at) VALUES (?, ?, 0, ?, ?, ?, '', 0, ?, ?)",
        [$it[0], $it[1], $it[5], $it[2], $it[3], $ago(44), $ago(6)]);
    $row = ['name' => $it[0], 'sku' => $it[1], 'unit' => $it[2]];
    $audit($id, 'INSERT', null, $row + ['quantity' => $it[4]], $ago(44));
    $audit($id, 'UPDATE', $row + ['quantity' => $it[4]], $row + ['quantity' => $it[5], 'adjustment_notes' => $it[6]], $ago(12));
    $audit($id, 'UPDATE', $row + ['quantity' => $it[5], 'is_active' => 1], $row + ['quantity' => $it[5], 'is_active' => 0, 'movement' => 'retired'], $ago(6));
}

// ── A bundle ─────────────────────────────────────────────────────────────────
$pack = $q("INSERT INTO product_bundles (name, sku, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
    ['Cultivar A retail pack', 'PK-CA', 'A jar of Cultivar A with its label and leaflet', $ago(45), $ago(45)]);
foreach ([[$ids[0], 1], [$ids[4], 1], [$ids[5], 1]] as $part) {
    $q("INSERT INTO bundle_items (bundle_id, stock_item_id, quantity) VALUES (?, ?, ?)", [$pack, $part[0], $part[1]]);
}
// And a retired one, a sample pack that's no longer sent
$sample = $q("INSERT INTO product_bundles (name, sku, description, is_active, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)",
    ['Cultivar B sample pack', 'SP-CB', 'Two jars of Cultivar B for new pharmacies', $ago(44), $ago(8)]);
$q("INSERT INTO bundle_items (bundle_id, stock_item_id, quantity) VALUES (?, ?, ?)", [$sample, $ids[1], 2]);

// ── Verified receivers ───────────────────────────────────────────────────────
$joes = $q("INSERT INTO verified_receivers (name, contact_person, address, phone, licence_number, created_at) VALUES (?, ?, ?, ?, ?, ?)",
    ["Joe's Pharmacy", 'Jo Bloggs', '10 Example Street, Hamilton', '07 555 0123', 'PH-0001', $ago(60)]);
$example = $q("INSERT INTO verified_receivers (name, contact_person, address, phone, licence_number, created_at) VALUES (?, ?, ?, ?, ?, ?)",
    ['Example Pharmacy', 'Pat Sample', '5 Example Road, Nelson', '03 555 0456', 'PH-0002', $ago(60)]);
$q("INSERT INTO verified_receivers (name, contact_person, address, is_active, created_at) VALUES (?, ?, ?, 0, ?)",
    ['Old Corner Pharmacy', 'Chris Example', '7 Example Avenue, Dunedin', $ago(200)]);

// ── Chain of custody transfers, oldest first ─────────────────────────────────
$transfer = function ($days, $receiver, $to, $items, $status, $extra = []) use ($q, $ago, $day) {
    $id = $q("INSERT INTO chain_of_custody (form_date, origin, destination, receiver_id, transported_by, coc_items, status, created_at, delivery_method)
        VALUES (?, 'Main Facility', ?, ?, 'Chris Driver', ?, ?, ?, 'staff')",
        [$day($days), $to, $receiver, json_encode($items), $status, $ago($days, '08:45:00')]);
    foreach ($extra as $column => $value) {
        $q("UPDATE chain_of_custody SET $column = ? WHERE id = ?", [$value, $id]);
    }
    return $id;
};
$pk = 'bundle_' . $pack;

// Last month, completed and invoiced
$transfer(37, $joes, "Joe's Pharmacy", [['item_id' => $pk, 'qty' => '40', 'batch' => 'B2610-02']], 'Completed', [
    'received_by' => 'Jo Bloggs', 'signature_image' => $signature,
    'completed_at' => $ago(37, '14:20:00'), 'invoiced_at' => $ago(35, '10:00:00'),
]);
// Signed for on screen, still to invoice
$transfer(15, $example, 'Example Pharmacy', [
    ['item_id' => (string) $ids[0], 'qty' => '20', 'batch' => 'B2611-01'],
    ['item_id' => (string) $ids[1], 'qty' => '10', 'batch' => 'B2610-04'],
], 'Completed', ['received_by' => 'Pat Sample', 'signature_image' => $signature, 'completed_at' => $ago(15, '15:40:00')]);
// Opened twice by mistake, so this one was cancelled
$transfer(13, $example, 'Example Pharmacy', [['item_id' => (string) $ids[1], 'qty' => '5', 'batch' => 'B2610-04']], 'Cancelled', [
    'cancelled_at' => $ago(13, '09:10:00'), 'cancel_reason' => 'Duplicate transfer opened by mistake',
]);
// A courier delivery, completed with the signed manifest uploaded
$courierDone = $transfer(10, $joes, "Joe's Pharmacy", [['item_id' => $pk, 'qty' => '25', 'batch' => 'B2611-01']], 'Completed', [
    'delivery_method' => 'courier', 'courier_company' => 'Example Couriers', 'tracking_number' => 'EC123456789NZ',
    'received_by' => 'Jo Bloggs', 'completed_at' => $ago(8, '11:05:00'),
]);
// Our staff are taking this one
$transfer(2, $joes, "Joe's Pharmacy", [['item_id' => $pk, 'qty' => '12', 'batch' => 'B2611-01']], 'In Progress');
// Booked with the courier today, no tracking number yet
$transfer(0, $example, 'Example Pharmacy', [['item_id' => (string) $ids[0], 'qty' => '8', 'batch' => 'B2611-01']], 'In Progress', [
    'delivery_method' => 'courier', 'courier_company' => 'Example Couriers',
]);

// The signed manifest for the completed courier delivery: a one-page PDF
$pdfText = ['Universal shipping manifest (demo)', 'Chain of custody transfer #' . $courierDone, 'Received by Jo Bloggs, signed'];
$stream = "BT /F1 16 Tf 72 760 Td\n";
foreach ($pdfText as $i => $line) {
    $stream .= ($i ? "0 -28 Td\n" : '') . '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line) . ") Tj\n";
}
$stream .= "ET\n";
$objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
];
$pdf = "%PDF-1.4\n";
$offsets = [];
foreach ($objects as $n => $body) {
    $offsets[] = strlen($pdf);
    $pdf .= ($n + 1) . " 0 obj\n" . $body . "\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
foreach ($offsets as $offset) {
    $pdf .= sprintf("%010d 00000 n \n", $offset);
}
$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
if (!is_dir($docs)) {
    mkdir($docs, 0775, true);
}
$stored = 'transfer-' . $courierDone . '-' . date('Ymd-His', strtotime('-8 days')) . '-' . bin2hex(random_bytes(4)) . '.pdf';
file_put_contents($docs . '/' . $stored, $pdf);
$q("UPDATE chain_of_custody SET signed_document = ?, signed_document_name = ? WHERE id = ?", [$stored, 'signed-manifest-joes-pharmacy.pdf', $courierDone]);

// Stock after those transfers (the bundle takes a jar, a label and a leaflet)
$set_qty($ids[0], 420 - 40 - 20 - 25 - 12 - 8);
$set_qty($ids[1], 53 - 10 - 3);
$set_qty($ids[4], 110 - 40 - 25 - 12 - 20);
$set_qty($ids[5], 800 - 40 - 25 - 12);

// ── Destruction register ─────────────────────────────────────────────────────
$q("INSERT INTO destruction_log (stock_item_id, item_name, batch, quantity, unit, reason, method, destroyed_by, witness, witness_signature, notes, destroyed_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    [$ids[1], 'Cultivar B dried flower, 10 g jar (CB-10)', 'B2609-01', 3, 'jars', 'Damaged', 'Denatured & disposed (rendered unusable)',
     'Chris Driver', 'Pat Sample', $signature, 'Jars cracked in storage', $ago(9, '14:10:00')]);
$q("INSERT INTO destruction_log (stock_item_id, item_name, batch, quantity, unit, reason, method, destroyed_by, witness, notes, destroyed_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    [$ids[4], 'Product label (LBL-01)', 'L-0042', 20, 'units', 'Other (see notes)', 'Incineration',
     'Chris Driver', 'Pat Sample', 'Misprinted batch', $ago(30, '10:30:00')]);

// ── Section 29 register (made-up patients and prescribers) ───────────────────
$product = (int) $pdo->query("SELECT id FROM products ORDER BY id LIMIT 1")->fetchColumn();
$q("UPDATE products SET name = 'Cultivar A', inn_generic = 'Cannabis sativa dried flower', dose_form = 'Dried flower', pack_size = '10 g', strength = 'THC 200 mg/g' WHERE id = ?", [$product]);
$lastMonth = date('Y-m', strtotime('first day of last month'));
$supplies = [
    ['03', 'Dr Alex Example', 'Patient One', 2], ['09', 'Dr Alex Example', 'Patient Two', 3],
    ['15', 'Dr Sam Sample', 'Patient One', 2], ['21', 'Dr Sam Sample', 'Patient Three', 1],
    ['24', 'Dr Alex Example', 'Patient Four', 2],
];
$import = $q("INSERT INTO s29_imports (filename, pharmacy, default_product_id, row_count, total_quantity, imported_at) VALUES (?, ?, ?, ?, ?, ?)",
    ['joes-pharmacy-' . $lastMonth . '.csv', "Joe's Pharmacy", $product, count($supplies), 10, $ago(12, '08:00:00')]);
foreach ($supplies as $s) {
    $q("INSERT INTO s29_supplies (import_id, supplied_at, supply_month, prescriber, prescriber_facility, patient, med_name, quantity, product_id, pharmacy)
        VALUES (?, ?, ?, ?, 'Example Medical Centre', ?, 'Cultivar A', ?, ?, ?)",
        [$import, $lastMonth . '-' . $s[0] . ' 00:00:00', $lastMonth, $s[1], $s[2], $s[3], $product, "Joe's Pharmacy"]);
}

echo "Demo data ready in $db\n";
