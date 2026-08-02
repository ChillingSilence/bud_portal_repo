<?php
require_once 'config.php';
require_once 'includes/audit.php';

$message = '';
$message_error = false;

/**
 * Parse the pharmacy export "Date time" values. NZ day-first formats seen in
 * the wild: "dd/mm/yy hh:mm", "d/mm/yyyy h:mm" (optionally with seconds),
 * plus ISO as a fallback. Returns a DateTime or null.
 */
function parseS29Date($str)
{
    $str = trim($str);
    if ($str === '') {
        return null;
    }
    // Excel serial date (days since 1899-12-30) — appears when .xlsx cells
    // are true date cells rather than text
    if (is_numeric($str) && floatval($str) > 20000 && floatval($str) < 80000) {
        $days = floor(floatval($str));
        $secs = intval(round((floatval($str) - $days) * 86400));
        $dt = new DateTime('1899-12-30 00:00:00');
        $dt->modify("+$days days");
        if ($secs > 0) {
            $dt->modify("+$secs seconds");
        }
        return $dt;
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $str, $m)) {
        $year = intval($m[3]);
        if ($year < 100) {
            $year += 2000;
        }
        if (!checkdate(intval($m[2]), intval($m[1]), $year)) {
            return null;
        }
        $dt = new DateTime();
        $dt->setDate($year, intval($m[2]), intval($m[1]));
        $dt->setTime(intval($m[4] ?? 0), intval($m[5] ?? 0), intval($m[6] ?? 0));
        return $dt;
    }
    // ISO-ish fallback (e.g. 2026-03-31 14:23)
    $ts = strtotime($str);
    return $ts !== false ? (new DateTime())->setTimestamp($ts) : null;
}

// ── Handle actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Upload + parse a monthly S29 CSV. Only the Section 29 record fields are
    // kept — every other column (NHI, DOB, addresses, pricing, ...) is
    // discarded and the uploaded file itself is never stored.
    if ($action === 'upload_s29') {
        try {
            $pharmacy = trim($_POST['pharmacy'] ?? '');
            if ($pharmacy === '__other__') {
                $pharmacy = trim($_POST['pharmacy_other'] ?? '');
            }
            $default_product_id = intval($_POST['default_product_id'] ?? 0) ?: null;

            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $message = "Upload failed — please choose a CSV or Excel (.xlsx) file.";
                $message_error = true;
            } elseif ($pharmacy === '') {
                $message = "Please select or enter the pharmacy / place supplied to.";
                $message_error = true;
            } else {
                $orig_name = basename($_FILES['csv_file']['name']);
                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

                if ($ext === 'xls') {
                    throw new Exception("Legacy .xls files are not supported — please save the file as .xlsx or CSV first.");
                } elseif ($ext === 'xlsx') {
                    require_once 'includes/xlsx.php';
                    $all_rows = readXlsxRows($_FILES['csv_file']['tmp_name']);
                } else {
                    $all_rows = [];
                    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
                    while (($r = fgetcsv($handle)) !== false) {
                        $all_rows[] = $r;
                    }
                    fclose($handle);
                }

                if (!$all_rows) {
                    throw new Exception("File appears to be empty.");
                }
                $headers = array_shift($all_rows);
                // Strip UTF-8 BOM from the first header cell
                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);

                $col = [];
                foreach ($headers as $i => $h) {
                    $col[strtolower(trim((string) $h))] = $i;
                }
                // Known pharmacy export layouts use different header names
                $find = function (...$names) use ($col) {
                    foreach ($names as $n) {
                        if (isset($col[$n])) {
                            return $col[$n];
                        }
                    }
                    return null;
                };
                $c_date      = $find('date time', 'dispensing_date');
                $c_qty       = $find('quantity');
                $c_med       = $find('med name', 'product_name');
                $c_plu       = $find('med plu', 'medicine_id');
                $c_inst      = $find('institution');
                $c_fac       = $find('prescriber facility name');
                $c_pre_full  = $find('prescriber_name');
                $c_pre_last  = $find('prescriber last name');
                $c_pre_first = $find('prescriber first names');
                $c_pat_full  = $find('patient_name');
                $c_pat_last  = $find('patient last name');
                $c_pat_first = $find('patient first names');

                $missing = [];
                if ($c_date === null) $missing[] = 'date';
                if ($c_qty === null) $missing[] = 'quantity';
                if ($c_med === null) $missing[] = 'med/product name';
                if ($c_pre_full === null && $c_pre_last === null) $missing[] = 'prescriber name';
                if ($c_pat_full === null && $c_pat_last === null) $missing[] = 'patient name';
                if ($missing) {
                    throw new Exception("Unrecognised file — could not find column(s): " . implode(', ', $missing));
                }

                $cell = function ($row, $i) {
                    return $i !== null && isset($row[$i]) ? trim((string) $row[$i]) : '';
                };

                // Product matching: rows whose Med name contains a known
                // product name get that product; everything else gets the
                // product selected on the form.
                $products = $pdo->query("SELECT id, name FROM products WHERE is_active = 1")->fetchAll();

                $pdo->exec("BEGIN TRANSACTION");
                $ins_import = $pdo->prepare("INSERT INTO s29_imports (filename, pharmacy, default_product_id) VALUES (?, ?, ?)");
                $ins_import->execute([basename($_FILES['csv_file']['name']), $pharmacy, $default_product_id]);
                $import_id = $pdo->lastInsertId();

                $ins_row = $pdo->prepare("INSERT INTO s29_supplies
                    (import_id, supplied_at, supply_month, prescriber, prescriber_facility, patient, med_name, med_plu, quantity, product_id, pharmacy)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $count = 0;
                $total_qty = 0;
                $bad_dates = 0;
                $months = [];

                foreach ($all_rows as $row) {
                    if (count($row) === 1 && trim((string) $row[0]) === '') {
                        continue; // blank line
                    }

                    $med_name = $cell($row, $c_med);
                    $qty = floatval($cell($row, $c_qty));
                    if ($med_name === '' && $qty == 0) {
                        continue; // not a data row
                    }

                    $dt = parseS29Date($cell($row, $c_date));
                    if (!$dt) {
                        $bad_dates++;
                    } else {
                        $months[$dt->format('Y-m')] = true;
                    }

                    $facility = $cell($row, $c_fac);
                    if ($c_pre_full !== null) {
                        $prescriber = $cell($row, $c_pre_full);
                        // "Dr Jane Doe (Clinic Name)" — facility from the parentheses
                        if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $prescriber, $pm)) {
                            $prescriber = trim($pm[1]);
                            if ($facility === '') {
                                $facility = trim($pm[2]);
                            }
                        }
                    } else {
                        $prescriber = trim($cell($row, $c_pre_last) . ', ' . $cell($row, $c_pre_first), ', ');
                    }

                    if ($c_pat_full !== null) {
                        $patient = $cell($row, $c_pat_full);
                    } else {
                        $patient = trim($cell($row, $c_pat_last) . ', ' . $cell($row, $c_pat_first), ', ');
                    }

                    $product_id = $default_product_id;
                    foreach ($products as $p) {
                        if ($p['name'] !== '' && stripos($med_name, $p['name']) !== false) {
                            $product_id = $p['id'];
                            break;
                        }
                    }

                    // Per-row Institution (e.g. a clinic supplied directly)
                    // overrides the pharmacy selected for the file
                    $row_place = $cell($row, $c_inst) ?: $pharmacy;

                    $ins_row->execute([
                        $import_id,
                        $dt ? $dt->format('Y-m-d H:i:s') : null,
                        $dt ? $dt->format('Y-m') : null,
                        $prescriber,
                        $facility,
                        $patient,
                        $med_name,
                        $cell($row, $c_plu),
                        $qty,
                        $product_id,
                        $row_place,
                    ]);
                    $count++;
                    $total_qty += $qty;
                }

                $pdo->prepare("UPDATE s29_imports SET row_count = ?, total_quantity = ? WHERE id = ?")
                    ->execute([$count, $total_qty, $import_id]);
                $pdo->exec("COMMIT");

                Audit::log($pdo, 's29_imports', $import_id, 'INSERT', null, [
                    'filename' => basename($_FILES['csv_file']['name']),
                    'pharmacy' => $pharmacy,
                    'rows'     => $count,
                    'quantity' => $total_qty,
                ]);

                $month_list = implode(', ', array_keys($months));
                $message = "Imported $count records ($total_qty units) covering $month_list — Import #$import_id.";
                if ($bad_dates > 0) {
                    $message .= " ⚠️ $bad_dates row(s) had unreadable dates and were saved without one.";
                }
            }
        } catch (Exception $e) {
            try {
                $pdo->exec("ROLLBACK");
            } catch (Exception $ignored) {
            }
            $message = "Import failed: " . $e->getMessage();
            $message_error = true;
        }
    }

    // Delete an entire import batch (its supply rows cascade)
    elseif ($action === 'delete_import') {
        try {
            $import_id = intval($_POST['import_id'] ?? 0);
            $imp_stmt = $pdo->prepare("SELECT * FROM s29_imports WHERE id = ?");
            $imp_stmt->execute([$import_id]);
            $imp = $imp_stmt->fetch();
            $imp_stmt = null;

            if (!$imp) {
                $message = "Import #$import_id not found.";
                $message_error = true;
            } else {
                $pdo->prepare("DELETE FROM s29_imports WHERE id = ?")->execute([$import_id]);
                Audit::log($pdo, 's29_imports', $import_id, 'DELETE', $imp, null);
                $message = "Import #$import_id ({$imp['filename']}, {$imp['row_count']} rows) deleted.";
            }
        } catch (Exception $e) {
            $message = "Error deleting import: " . $e->getMessage();
            $message_error = true;
        }
    }

    // Add / update a product
    elseif ($action === 'save_product') {
        try {
            $pid = intval($_POST['product_id'] ?? 0);
            $vals = [
                trim($_POST['name'] ?? ''),
                trim($_POST['inn_generic'] ?? ''),
                trim($_POST['dose_form'] ?? ''),
                trim($_POST['pack_size'] ?? ''),
                trim($_POST['strength'] ?? ''),
                isset($_POST['is_active']) ? 1 : 0,
            ];
            if ($vals[0] === '') {
                $message = "Product name is required.";
                $message_error = true;
            } elseif ($pid) {
                $old_stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
                $old_stmt->execute([$pid]);
                $old = $old_stmt->fetch();
                $pdo->prepare("UPDATE products SET name = ?, inn_generic = ?, dose_form = ?, pack_size = ?, strength = ?, is_active = ? WHERE id = ?")
                    ->execute(array_merge($vals, [$pid]));
                Audit::log($pdo, 'products', $pid, 'UPDATE', $old, array_combine(['name', 'inn_generic', 'dose_form', 'pack_size', 'strength', 'is_active'], $vals));
                $message = "Product '{$vals[0]}' updated.";
            } else {
                $pdo->prepare("INSERT INTO products (name, inn_generic, dose_form, pack_size, strength, is_active) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute($vals);
                $pid = $pdo->lastInsertId();
                Audit::log($pdo, 'products', $pid, 'INSERT', null, array_combine(['name', 'inn_generic', 'dose_form', 'pack_size', 'strength', 'is_active'], $vals));
                $message = "Product '{$vals[0]}' added.";
            }
        } catch (Exception $e) {
            $message = "Error saving product: " . $e->getMessage();
            $message_error = true;
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_month   = $_GET['month'] ?? '';
$filter_place   = trim($_GET['place'] ?? '');
$filter_product = intval($_GET['product'] ?? 0);
$filter_q       = trim($_GET['q'] ?? '');

if ($filter_month !== '' && !preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
    $filter_month = '';
}

$where = [];
$params = [];
if ($filter_month !== '') {
    $where[] = "s.supply_month = ?";
    $params[] = $filter_month;
}
if ($filter_place !== '') {
    $where[] = "s.pharmacy LIKE ?";
    $params[] = "%$filter_place%";
}
if ($filter_product) {
    $where[] = "s.product_id = ?";
    $params[] = $filter_product;
}
if ($filter_q !== '') {
    $where[] = "(s.patient LIKE ? OR s.prescriber LIKE ?)";
    $params[] = "%$filter_q%";
    $params[] = "%$filter_q%";
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── CSV export (server-side, full Section 29 record format) ──────────────────
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $exp = $pdo->prepare("
        SELECT s.*, p.name AS product_name, p.inn_generic, p.dose_form, p.pack_size, p.strength
        FROM s29_supplies s
        LEFT JOIN products p ON p.id = s.product_id
        $where_sql
        ORDER BY s.supplied_at ASC
    ");
    $exp->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="S29_Register' . ($filter_month ? '_' . $filter_month : '') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Practitioner', 'Practitioner facility', 'Patient', 'INN / generic name', 'Trade name', 'Dose form', 'Pack size', 'Strength', 'Quantity supplied', 'Date supplied', 'Month supplied', 'Place supplied to']);
    foreach ($exp->fetchAll() as $r) {
        fputcsv($out, [
            $r['prescriber'],
            $r['prescriber_facility'],
            $r['patient'],
            $r['inn_generic'] ?? '',
            $r['product_name'] ?? $r['med_name'],
            $r['dose_form'] ?? '',
            $r['pack_size'] ?? '',
            $r['strength'] ?? '',
            $r['quantity'],
            $r['supplied_at'],
            $r['supply_month'],
            $r['pharmacy'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Data for the page ─────────────────────────────────────────────────────────
$months = $pdo->query("SELECT DISTINCT supply_month FROM s29_supplies WHERE supply_month IS NOT NULL ORDER BY supply_month DESC")->fetchAll(PDO::FETCH_COLUMN);

// Default to the latest month on first view (no explicit filter in the URL)
if ($filter_month === '' && !isset($_GET['month']) && $months) {
    $filter_month = $months[0];
    $where[] = "s.supply_month = ?";
    $params[] = $filter_month;
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

$supplies_stmt = $pdo->prepare("
    SELECT s.*, p.name AS product_name
    FROM s29_supplies s
    LEFT JOIN products p ON p.id = s.product_id
    $where_sql
    ORDER BY s.supplied_at DESC
    LIMIT 1000
");
$supplies_stmt->execute($params);
$supplies = $supplies_stmt->fetchAll();

$summary_stmt = $pdo->prepare("
    SELECT COALESCE(p.name, s.med_name) AS product, s.pharmacy,
           COUNT(*) AS supplies, SUM(s.quantity) AS total_qty
    FROM s29_supplies s
    LEFT JOIN products p ON p.id = s.product_id
    $where_sql
    GROUP BY COALESCE(p.name, s.med_name), s.pharmacy
    ORDER BY total_qty DESC
");
$summary_stmt->execute($params);
$summary = $summary_stmt->fetchAll();

$imports = $pdo->query("SELECT i.*, p.name AS product_name FROM s29_imports i
    LEFT JOIN products p ON p.id = i.default_product_id
    ORDER BY i.imported_at DESC LIMIT 50")->fetchAll();

$all_products = $pdo->query("SELECT * FROM products ORDER BY is_active DESC, name ASC")->fetchAll();
$receivers = $pdo->query("SELECT name FROM verified_receivers WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Section 29</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="container">
        <h1>Section 29 Register</h1>
        <p style="color: var(--text-muted, #aaa); font-size: 0.9rem; margin-bottom: 1.5rem;">
            Monthly pharmacy supply data (Medicines Act s29). Uploaded files are parsed and discarded —
            only the Section 29 record fields are kept. This data is confidential and lives only in this
            add-on's database.
        </p>

        <?php if ($message): ?>
            <div class="glass-panel"
                style="margin-bottom: 1rem; border-color: <?= $message_error ? '#ef4444' : 'var(--accent-color)' ?>;">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
            <button onclick="togglePanel('uploadPanel')" class="btn">⬆ Upload S29 File</button>
            <button onclick="togglePanel('importsPanel')" class="btn"
                style="background: transparent; border: 1px solid var(--primary-color); color: var(--primary-color);">🗂
                Imports</button>
            <button onclick="togglePanel('productsPanel')" class="btn"
                style="background: transparent; border: 1px solid var(--primary-color); color: var(--primary-color);">💊
                Products</button>
        </div>

        <!-- Upload -->
        <div id="uploadPanel" class="glass-panel" style="display: none; margin-bottom: 2rem;">
            <h3>Upload Monthly S29 File</h3>
            <p style="font-size: 0.9rem; color: var(--text-muted, #aaa);">CSV or Excel (.xlsx) exports from the
                pharmacy. Columns are detected automatically across the known layouts; anything beyond the Section 29
                fields (NHI numbers, addresses, pricing, …) is discarded, and the file itself is not kept. Legacy
                .xls files should be saved as .xlsx or CSV first.</p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_s29">
                <div class="grid">
                    <div>
                        <label>CSV or Excel (.xlsx) File</label>
                        <input type="file" name="csv_file" accept=".csv,.xlsx,text/csv" required>
                    </div>
                    <div>
                        <label>Pharmacy / Place Supplied To</label>
                        <select name="pharmacy" id="pharmacy_select" onchange="toggleOtherPharmacy(this.value)" required>
                            <option value="">Select...</option>
                            <?php foreach ($receivers as $rname): ?>
                                <option value="<?= h($rname) ?>"><?= h($rname) ?></option>
                            <?php endforeach; ?>
                            <option value="__other__">Other (type below)</option>
                        </select>
                        <input type="text" name="pharmacy_other" id="pharmacy_other" placeholder="Pharmacy name"
                            style="display: none; margin-top: 0.5rem;">
                    </div>
                    <div>
                        <label>Product (for rows that don't auto-match)</label>
                        <select name="default_product_id">
                            <option value="">— none —</option>
                            <?php foreach ($all_products as $p): ?>
                                <?php if ($p['is_active']): ?>
                                    <option value="<?= $p['id'] ?>"><?= h($p['name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p style="font-size: 0.8rem; color: var(--text-muted, #aaa);">Rows whose "Med name" contains a known
                    product name are matched automatically; per-row "Institution" values override the pharmacy above.</p>
                <button type="submit" class="btn">Upload &amp; Import</button>
            </form>
        </div>

        <!-- Imports -->
        <div id="importsPanel" class="glass-panel" style="display: none; margin-bottom: 2rem;">
            <h3>🗂 Imports</h3>
            <p><small>Deleting an import removes all of its supply records (use for re-uploads or mistakes).</small></p>
            <?php if (empty($imports)): ?>
                <p>No imports yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table style="font-size: 0.9rem;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Imported</th>
                                <th>File</th>
                                <th>Pharmacy</th>
                                <th>Default Product</th>
                                <th>Rows</th>
                                <th>Total Qty</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($imports as $imp): ?>
                                <tr>
                                    <td><?= $imp['id'] ?></td>
                                    <td style="white-space: nowrap;"><?= h($imp['imported_at']) ?></td>
                                    <td><?= h($imp['filename']) ?></td>
                                    <td><?= h($imp['pharmacy']) ?></td>
                                    <td><?= h($imp['product_name'] ?? '-') ?></td>
                                    <td><?= h($imp['row_count']) ?></td>
                                    <td><?= h($imp['total_quantity']) ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;"
                                            onsubmit="return confirm('Delete import #<?= $imp['id'] ?> and all <?= $imp['row_count'] ?> of its records?');">
                                            <input type="hidden" name="action" value="delete_import">
                                            <input type="hidden" name="import_id" value="<?= $imp['id'] ?>">
                                            <button type="submit" class="btn"
                                                style="padding: 0.2rem 0.5rem; font-size: 0.75rem; background: #ef4444;">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Products -->
        <div id="productsPanel" class="glass-panel" style="display: none; margin-bottom: 2rem;">
            <h3>💊 Products</h3>
            <p><small>The Section 29 constants for each verified product. Rows in uploaded files are matched to
                    products by name.</small></p>
            <div class="table-responsive">
                <table style="font-size: 0.9rem;">
                    <thead>
                        <tr>
                            <th>Trade Name</th>
                            <th>INN / Generic</th>
                            <th>Dose Form</th>
                            <th>Pack Size</th>
                            <th>Strength</th>
                            <th>Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_products as $p): ?>
                            <tr>
                                <td><strong><?= h($p['name']) ?></strong></td>
                                <td><?= h($p['inn_generic'] ?: '-') ?></td>
                                <td><?= h($p['dose_form'] ?: '-') ?></td>
                                <td><?= h($p['pack_size'] ?: '-') ?></td>
                                <td><?= h($p['strength'] ?: '-') ?></td>
                                <td><?= $p['is_active'] ? '✓' : '✕' ?></td>
                                <td>
                                    <button onclick='editProduct(<?= json_encode($p) ?>)' class="btn"
                                        style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h4 id="product-form-title" style="margin-top: 1.5rem;">Add Product</h4>
            <form method="POST" id="productForm">
                <input type="hidden" name="action" value="save_product">
                <input type="hidden" name="product_id" id="product_id" value="">
                <div class="grid">
                    <div>
                        <label>Trade Name</label>
                        <input type="text" name="name" id="p_name" required placeholder="e.g. BB11">
                    </div>
                    <div>
                        <label>INN / Generic Name</label>
                        <input type="text" name="inn_generic" id="p_inn" placeholder="e.g. Cannabis sativa dried flower">
                    </div>
                    <div>
                        <label>Dose Form</label>
                        <input type="text" name="dose_form" id="p_form" placeholder="e.g. Dried flower">
                    </div>
                </div>
                <div class="grid">
                    <div>
                        <label>Pack Size</label>
                        <input type="text" name="pack_size" id="p_pack" placeholder="e.g. 10 g">
                    </div>
                    <div>
                        <label>Strength</label>
                        <input type="text" name="strength" id="p_strength" placeholder="e.g. THC 200 mg/g + CBD 10 mg/g">
                    </div>
                    <div style="display: flex; align-items: flex-end; gap: 0.5rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; margin: 0;">
                            <input type="checkbox" name="is_active" id="p_active" checked style="width: auto;"> Active
                        </label>
                    </div>
                </div>
                <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                    <button type="submit" class="btn">Save Product</button>
                    <button type="button" class="btn" onclick="resetProductForm()"
                        style="background: transparent; border: 1px solid var(--card-border); color: var(--text-color);">Clear</button>
                </div>
            </form>
        </div>

        <!-- Filters -->
        <div class="glass-panel" style="margin-bottom: 2rem;">
            <form method="GET" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                <div>
                    <label>Month</label>
                    <select name="month">
                        <option value="">All months</option>
                        <?php foreach ($months as $m): ?>
                            <option value="<?= h($m) ?>" <?= $m === $filter_month ? 'selected' : '' ?>><?= h($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Place</label>
                    <input type="text" name="place" value="<?= h($filter_place) ?>" placeholder="Pharmacy / clinic">
                </div>
                <div>
                    <label>Product</label>
                    <select name="product">
                        <option value="">All</option>
                        <?php foreach ($all_products as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $p['id'] == $filter_product ? 'selected' : '' ?>><?= h($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Search (patient / prescriber)</label>
                    <input type="text" name="q" value="<?= h($filter_q) ?>" placeholder="Name...">
                </div>
                <button type="submit" class="btn">Filter</button>
                <a href="s29.php?action=export_csv&month=<?= urlencode($filter_month) ?>&place=<?= urlencode($filter_place) ?>&product=<?= $filter_product ?>&q=<?= urlencode($filter_q) ?>"
                    class="btn"
                    style="background: transparent; border: 1px solid var(--primary-color); color: var(--primary-color) !important;">⬇
                    Export CSV</a>
            </form>
        </div>

        <!-- Summary -->
        <?php if (!empty($summary)): ?>
            <div class="glass-panel" style="margin-bottom: 2rem;">
                <h3>📊 Summary<?= $filter_month ? ' — ' . h($filter_month) : '' ?></h3>
                <div class="table-responsive">
                    <table style="font-size: 0.9rem;">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Place Supplied To</th>
                                <th>Supplies</th>
                                <th>Total Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summary as $s): ?>
                                <tr>
                                    <td><?= h($s['product']) ?></td>
                                    <td><?= h($s['pharmacy']) ?></td>
                                    <td><?= h($s['supplies']) ?></td>
                                    <td><strong><?= h($s['total_qty']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Register -->
        <div class="glass-panel">
            <h3>📋 Supply Records<?= $filter_month ? ' — ' . h($filter_month) : '' ?></h3>
            <p><small><?= count($supplies) ?> record<?= count($supplies) !== 1 ? 's' : '' ?><?= count($supplies) === 1000 ? ' (showing first 1000 — narrow the filters)' : '' ?></small></p>
            <?php if (empty($supplies)): ?>
                <p>No supply records<?= $filter_month || $filter_place || $filter_product || $filter_q ? ' matching these filters' : ' yet — upload an S29 file to get started' ?>.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table style="font-size: 0.85rem;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Prescriber</th>
                                <th>Patient</th>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Place Supplied To</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($supplies as $s): ?>
                                <tr>
                                    <td style="white-space: nowrap;"><?= h($s['supplied_at'] ? substr($s['supplied_at'], 0, 16) : '?') ?></td>
                                    <td><?= h($s['prescriber']) ?><?= $s['prescriber_facility'] ? '<br><small style="color: var(--text-muted, #aaa);">' . h($s['prescriber_facility']) . '</small>' : '' ?></td>
                                    <td><?= h($s['patient']) ?></td>
                                    <td><?= h($s['product_name'] ?? $s['med_name']) ?></td>
                                    <td><?= h($s['quantity']) ?></td>
                                    <td><?= h($s['pharmacy']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function togglePanel(id) {
            const el = document.getElementById(id);
            el.style.display = el.style.display === 'none' ? 'block' : 'none';
        }

        function toggleOtherPharmacy(value) {
            document.getElementById('pharmacy_other').style.display = value === '__other__' ? 'block' : 'none';
        }

        function editProduct(p) {
            document.getElementById('productsPanel').style.display = 'block';
            document.getElementById('product-form-title').textContent = 'Edit Product: ' + p.name;
            document.getElementById('product_id').value = p.id;
            document.getElementById('p_name').value = p.name || '';
            document.getElementById('p_inn').value = p.inn_generic || '';
            document.getElementById('p_form').value = p.dose_form || '';
            document.getElementById('p_pack').value = p.pack_size || '';
            document.getElementById('p_strength').value = p.strength || '';
            document.getElementById('p_active').checked = p.is_active == 1;
            document.getElementById('productForm').scrollIntoView({ behavior: 'smooth' });
        }

        function resetProductForm() {
            document.getElementById('product-form-title').textContent = 'Add Product';
            document.getElementById('product_id').value = '';
            document.getElementById('productForm').reset();
        }
    </script>
</body>

</html>
