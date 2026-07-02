<?php
require_once 'config.php';

$message = '';
$selected_month = $_GET['month'] ?? date('Y-m');
$selected_year = $_GET['year'] ?? date('Y');

$start_date = "$selected_month-01";
$end_date = date("Y-m-t", strtotime($start_date));

// ── 1. Build controlled substance ID set ──────────────────────────────────────
// For plain stock items: filter by is_controlled = 1
// For bundles: expand into individual controlled components when reporting
$controlled_ids_stmt = $pdo->query("SELECT id FROM stock_items WHERE is_controlled = 1");
$controlled_ids = array_column($controlled_ids_stmt->fetchAll(), 'id');
$controlled_id_set = array_flip($controlled_ids);

// Stock item full map (id => details)
$all_stock_stmt = $pdo->query("SELECT id, name, sku, unit FROM stock_items");
$stock_item_map = [];
foreach ($all_stock_stmt->fetchAll() as $s) {
    $stock_item_map[$s['id']] = $s;
}
$stock_name_map = [];
foreach ($stock_item_map as $id => $s) {
    $stock_name_map[$id] = $s['name'];
}

// Bundle lookup: name map, controlled flag, and full component details
// Each bundle row in the JOIN represents one component; we build up per-bundle arrays.
$all_bundles_stmt = $pdo->query("
    SELECT pb.id, pb.name, pb.sku,
           bi.stock_item_id, bi.quantity AS component_qty
    FROM product_bundles pb
    LEFT JOIN bundle_items bi ON bi.bundle_id = pb.id
");
$bundle_name_map   = [];
$bundle_controlled = [];
$bundle_components = []; // bundle_id => [ ['stock_item_id'=>, 'qty'=>, 'is_controlled'=>, 'name'=>], ... ]

foreach ($all_bundles_stmt->fetchAll() as $row) {
    $bid = $row['id'];
    $bundle_name_map[$bid] = $row['name'] . ' (' . $row['sku'] . ')';
    if (!isset($bundle_components[$bid])) {
        $bundle_components[$bid] = [];
        $bundle_controlled[$bid] = false;
    }
    if ($row['stock_item_id']) {
        $sid       = (int) $row['stock_item_id'];
        $is_ctrl   = isset($controlled_id_set[$sid]);
        $comp_name = $stock_name_map[$sid] ?? ('Item #' . $sid);
        $bundle_components[$bid][] = [
            'stock_item_id' => $sid,
            'qty'           => floatval($row['component_qty']),
            'is_controlled' => $is_ctrl,
            'name'          => $comp_name,
        ];
        if ($is_ctrl) {
            $bundle_controlled[$bid] = true;
        }
    }
}

function resolveItemName($item_id, $bundle_name_map, $stock_name_map)
{
    if (strpos($item_id, 'bundle_') === 0) {
        $bid = (int) str_replace('bundle_', '', $item_id);
        return $bundle_name_map[$bid] ?? $item_id;
    }
    return $stock_name_map[(int) $item_id] ?? $item_id;
}

function isItemControlled($item_id, $controlled_id_set, $bundle_controlled)
{
    if (strpos($item_id, 'bundle_') === 0) {
        $bid = (int) str_replace('bundle_', '', $item_id);
        return $bundle_controlled[$bid] ?? false;
    }
    return isset($controlled_id_set[(int) $item_id]);
}

/**
 * Expand a COC line item into individual controlled-substance report rows.
 *
 * For plain controlled stock items this returns a single row.
 * For bundles this returns one row per controlled component, with the
 * component quantity multiplied by the number of bundles dispatched,
 * and a "via bundle" note appended to the product name.
 *
 * Returns: array of ['name' => string, 'qty' => float, 'bundle' => string|null]
 */
function expandControlledComponents($item_id, $dispatch_qty, $controlled_id_set, $bundle_controlled, $bundle_components, $bundle_name_map, $stock_name_map)
{
    $rows = [];

    if (strpos($item_id, 'bundle_') === 0) {
        $bid = (int) str_replace('bundle_', '', $item_id);
        if (!($bundle_controlled[$bid] ?? false)) {
            return $rows; // bundle has no controlled components
        }
        $bname = $bundle_name_map[$bid] ?? ('Bundle #' . $bid);
        foreach ($bundle_components[$bid] ?? [] as $comp) {
            if (!$comp['is_controlled']) continue;
            $rows[] = [
                'name'   => $comp['name'],
                'qty'    => $comp['qty'] * floatval($dispatch_qty),
                'bundle' => $bname,
            ];
        }
    } elseif (isset($controlled_id_set[(int) $item_id])) {
        $rows[] = [
            'name'   => $stock_name_map[(int) $item_id] ?? $item_id,
            'qty'    => floatval($dispatch_qty),
            'bundle' => null,
        ];
    }

    return $rows;
}

// ── 2. Materials Out (Controlled only, completed transfers) ───────────────────
$coc_out = $pdo->prepare("
    SELECT c.*, vr.address AS receiver_address
    FROM chain_of_custody c
    LEFT JOIN verified_receivers vr ON vr.id = c.receiver_id
    WHERE c.form_date BETWEEN ? AND ?
    AND c.status = 'Completed'
    ORDER BY c.form_date ASC
");
$coc_out->execute([$start_date, $end_date]);
$transfers_out = $coc_out->fetchAll();

$report_items = [];
$mca_rows = [];
foreach ($transfers_out as $t) {
    $items = json_decode($t['coc_items'], true);
    if ($items) {
        foreach ($items as $item) {
            if (!isItemControlled($item['item_id'], $controlled_id_set, $bundle_controlled))
                continue;

            // Expand bundles into their individual controlled components
            $expanded = expandControlledComponents(
                $item['item_id'],
                $item['qty'],
                $controlled_id_set,
                $bundle_controlled,
                $bundle_components,
                $bundle_name_map,
                $stock_name_map
            );

            foreach ($expanded as $exp) {
                $report_items[] = [
                    'date'        => $t['form_date'],
                    'destination' => $t['destination'],
                    'item_name'   => $exp['name'],
                    'qty'         => $exp['qty'],
                ];
                $mca_rows[] = [
                    'date'     => $t['form_date'],
                    'pharmacy' => $t['destination'],
                    'address'  => $t['receiver_address'] ?? '',
                    'product'  => $exp['name'],
                    'qty'      => $exp['qty'],
                ];
            }
        }
    }
}

// ── 3. Materials In (Controlled stock items only, audit log) ──────────────────
$audit_in = $pdo->prepare("
    SELECT al.* FROM audit_log al
    WHERE al.table_name = 'stock_items'
    AND al.action IN ('INSERT', 'UPDATE')
    AND al.timestamp BETWEEN ? AND ?
    ORDER BY al.timestamp ASC
");
$audit_in->execute(["$start_date 00:00:00", "$end_date 23:59:59"]);
$logs_in = $audit_in->fetchAll();

$materials_in = [];
foreach ($logs_in as $log) {
    $new = json_decode($log['new_values'], true);
    $old = json_decode($log['old_values'], true);

    // Only include controlled items
    $record_id = $log['record_id'];
    if (!isset($controlled_id_set[$record_id]))
        continue;

    $qty_in = 0;
    if ($log['action'] === 'INSERT') {
        $qty_in = floatval($new['quantity'] ?? 0);
    } elseif ($log['action'] === 'UPDATE') {
        $new_qty = floatval($new['quantity'] ?? 0);
        $old_qty = floatval($old['quantity'] ?? 0);
        if ($new_qty > $old_qty) {
            $qty_in = $new_qty - $old_qty;
        }
    }

    if ($qty_in > 0) {
        $materials_in[] = [
            'date' => date('Y-m-d H:i', strtotime($log['timestamp'])),
            'name' => $new['name'] ?? 'Unknown',
            'sku'  => $new['sku'] ?? '-',
            'qty'  => $qty_in,
            'unit' => $new['unit'] ?? '',
        ];
    }
}

// ── 4. 12-Month Overview (Controlled only) ────────────────────────────────────
$yearly_stats = [];
for ($m = 1; $m <= 12; $m++) {
    $m_str = str_pad($m, 2, '0', STR_PAD_LEFT);
    $ym = "$selected_year-$m_str";

    // Out (COC - controlled items, bundles expanded to component quantities)
    $stmt_out = $pdo->prepare("SELECT coc_items FROM chain_of_custody WHERE form_date LIKE ? AND status = 'Completed'");
    $stmt_out->execute(["$ym%"]);
    $rows_out = $stmt_out->fetchAll();
    $total_out = 0;
    foreach ($rows_out as $r) {
        $its = json_decode($r['coc_items'], true) ?: [];
        foreach ($its as $i) {
            // Expand bundles so only controlled component quantities are counted
            $expanded = expandControlledComponents(
                $i['item_id'],
                $i['qty'] ?? 0,
                $controlled_id_set,
                $bundle_controlled,
                $bundle_components,
                $bundle_name_map,
                $stock_name_map
            );
            foreach ($expanded as $exp) {
                $total_out += $exp['qty'];
            }
        }
    }

    // In (Audit - controlled items only)
    $stmt_in = $pdo->prepare("SELECT al.action, al.old_values, al.new_values, al.record_id
        FROM audit_log al WHERE al.table_name = 'stock_items' AND al.timestamp LIKE ?");
    $stmt_in->execute(["$ym%"]);
    $rows_in = $stmt_in->fetchAll();
    $total_in = 0;
    foreach ($rows_in as $r) {
        if (!isset($controlled_id_set[$r['record_id']]))
            continue;
        $nv = json_decode($r['new_values'], true);
        $ov = json_decode($r['old_values'], true);
        if ($r['action'] === 'INSERT') {
            $total_in += floatval($nv['quantity'] ?? 0);
        } elseif ($r['action'] === 'UPDATE') {
            $nq = floatval($nv['quantity'] ?? 0);
            $oq = floatval($ov['quantity'] ?? 0);
            if ($nq > $oq)
                $total_in += ($nq - $oq);
        }
    }

    $yearly_stats[$m] = ['in' => $total_in, 'out' => $total_out];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Reports</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="container">
        <h1>Reports</h1>

        <!-- Controls -->
        <div class="glass-panel" style="margin-bottom: 2rem;">
            <form method="GET" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                <div>
                    <label>Report Month</label>
                    <input type="month" name="month" value="<?= h($selected_month) ?>">
                </div>
                <div>
                    <label>Yearly Overview</label>
                    <select name="year">
                        <?php
                        $curr_y = date('Y');
                        for ($y = $curr_y; $y >= $curr_y - 2; $y--) {
                            $sel = $y == $selected_year ? 'selected' : '';
                            echo "<option value='$y' $sel>$y</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn">Update View</button>
            </form>
        </div>

        <p style="color: var(--text-muted, #aaa); font-size: 0.85rem; margin-bottom: 1.5rem; margin-top: -1rem;">
            ⚠️ All figures show <strong>controlled substances only</strong>. Bundle quantities are expanded to their individual controlled components.
        </p>

        <!-- Materials IN -->
        <div class="glass-panel" style="margin-bottom: 2rem;">
            <h3>📥 Materials In (<?= date('F Y', strtotime($selected_month)) ?>)</h3>
            <p><small>Controlled substances received into stock</small></p>
            <?php if (empty($materials_in)): ?>
                <p>No incoming controlled materials recorded.</p>
            <?php else: ?>
                <table style="font-size: 0.9rem;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Item</th>
                            <th>Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($materials_in as $in): ?>
                            <tr>
                                <td><?= h($in['date']) ?></td>
                                <td><?= h($in['name']) ?> <small>(<?= h($in['sku']) ?>)</small></td>
                                <td class="text-success">+<?= h($in['qty']) ?> <?= h($in['unit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Materials OUT (MCA format) -->
        <div class="glass-panel" style="margin-bottom: 2rem;">
            <div style="display: flex; justify-content: flex-end; margin-bottom: 0.5rem;">
                <button onclick="exportMcaCsv()" class="btn"
                    style="background: transparent; border: 1px solid var(--primary-color); color: var(--primary-color); font-size: 0.85rem;">
                    ⬇ Export CSV
                </button>
            </div>
            <h3 style="margin:0 0 0.5rem;">📤 Materials Out (<?= date('F Y', strtotime($selected_month)) ?>)</h3>
            <?php if (empty($mca_rows)): ?>
                <p>No completed controlled substance transfers recorded for this month.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="mca-table" style="font-size: 0.9rem;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Destination</th>
                                <th>Address</th>
                                <th>Product</th>
                                <th>Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mca_rows as $row): ?>
                                <tr>
                                    <td><?= h($row['date']) ?></td>
                                    <td><?= h($row['pharmacy']) ?></td>
                                    <td><?= h($row['address']) ?></td>
                                    <td><?= h($row['product']) ?></td>
                                    <td class="text-danger">-<?= h($row['qty']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- 12-Month Overview -->
        <div class="glass-panel" style="margin-top: 2rem;">
            <h3>📊 12-Month Overview (<?= h($selected_year) ?>) — Controlled Substances</h3>
            <table style="text-align: center;">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Total In</th>
                        <th>Total Out</th>
                        <th>Net Flow</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($yearly_stats as $m => $stats): ?>
                        <tr>
                            <td style="text-align: left;"><strong><?= date('F', mktime(0, 0, 0, $m, 1)) ?></strong></td>
                            <td style="color: var(--accent-color);">+<?= h($stats['in']) ?></td>
                            <td style="color: #ef4444;">-<?= h($stats['out']) ?></td>
                            <td>
                                <?php
                                $net = $stats['in'] - $stats['out'];
                                echo ($net > 0 ? '+' : '') . h($net);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

    <script>
        function exportMcaCsv() {
            const table = document.getElementById('mca-table');
            if (!table) { alert('No data to export.'); return; }

            let csv = [];
            for (const row of table.rows) {
                const cols = Array.from(row.cells).map(c => '"' + c.innerText.replace(/"/g, '""') + '"');
                csv.push(cols.join(','));
            }

            const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'MCA_Report_<?= $selected_month ?>.csv';
            a.click();
            URL.revokeObjectURL(url);
        }
    </script>
</body>

</html>
