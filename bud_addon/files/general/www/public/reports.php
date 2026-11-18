<?php
require_once 'config.php';
require_once 'includes/stock.php';

$message = '';
$selected_month = $_GET['month'] ?? date('Y-m');
$selected_year = $_GET['year'] ?? date('Y');
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}
if (!preg_match('/^\d{4}$/', (string) $selected_year)) {
    $selected_year = date('Y');
}

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

    // Stock a cancelled transfer put back wasn't received from anywhere
    if (bud_is_cancel_restore($new))
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
        if (bud_is_cancel_restore($nv))
            continue;
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

$month_label = bud_month_label($selected_month);
$year_in = 0;
$year_out = 0;
foreach ($yearly_stats as $stats) {
    $year_in += $stats['in'];
    $year_out += $stats['out'];
}

// "+120", "-30" or "0"
function signed_qty($value, $sign)
{
    return $value == 0 ? '0' : $sign . bud_qty($value);
}

$pageTitle = 'Reports';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Reports</h1>
            <p>Controlled stock in and out each month, for the Medicinal Cannabis Agency. A bundle counts as the controlled items in it.</p>
        </hgroup>

        <form method="GET" class="toolbar">
            <label>Month
                <input type="month" name="month" value="<?= h($selected_month) ?>">
            </label>
            <label>Overview year
                <select name="year">
                    <?php
                    $curr_y = (int) date('Y');
                    for ($y = $curr_y; $y >= $curr_y - 2; $y--): ?>
                        <option value="<?= $y ?>"<?= $y == $selected_year ? ' selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <button type="submit">Show</button>
        </form>

        <!-- Materials in -->
        <section class="hub-section">
            <div class="panel-head">
                <div>
                    <h2>Materials in, <?= h($month_label) ?></h2>
                    <p>Controlled stock received into stock.</p>
                </div>
            </div>
            <?php if (empty($materials_in)): ?>
                <p class="empty-note">No controlled stock came in this month.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="wide-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Item</th>
                                <th class="num">Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materials_in as $in): ?>
                                <tr>
                                    <td class="nowrap"><?= h(bud_datetime($in['date'])) ?></td>
                                    <td class="cell-long"><?= h($in['name']) ?> <small><?= h($in['sku']) ?></small></td>
                                    <td class="num num--in">+<?= h(bud_qty($in['qty'])) ?> <?= h($in['unit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- Materials out, in the Agency's format -->
        <section class="hub-section">
            <div class="panel-head">
                <div>
                    <h2>Materials out, <?= h($month_label) ?></h2>
                    <p>Completed chain of custody transfers. Export the CSV for the Agency's monthly report.</p>
                </div>
                <?php if (!empty($mca_rows)): ?>
                    <div class="panel-head-actions">
                        <button type="button" class="secondary outline" onclick="exportTableCsv('mca-table', 'MCA_Report_<?= h($selected_month) ?>.csv')">Export CSV</button>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (empty($mca_rows)): ?>
                <p class="empty-note">No completed transfers of controlled stock this month.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <?php // data-csv keeps the export exactly as BUD 0.19 wrote it ?>
                    <table id="mca-table" class="wide-table">
                        <thead>
                            <tr>
                                <th data-csv="Date">Date</th>
                                <th data-csv="Destination">Destination</th>
                                <th data-csv="Address">Address</th>
                                <th data-csv="Product">Product</th>
                                <th class="num" data-csv="Qty">Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mca_rows as $row): ?>
                                <tr>
                                    <td class="nowrap" data-csv="<?= h($row['date']) ?>"><?= h(bud_date($row['date'])) ?></td>
                                    <td><?= h($row['pharmacy']) ?></td>
                                    <td class="cell-long"><?= h($row['address']) ?></td>
                                    <td class="cell-long"><?= h($row['product']) ?></td>
                                    <td class="num num--out" data-csv="-<?= h((string) $row['qty']) ?>"><?= BUD_MINUS ?><?= h(bud_qty($row['qty'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- 12-month overview -->
        <section class="hub-section">
            <h2>12-month overview, <?= h($selected_year) ?></h2>
            <div class="table-wrap">
                <table class="wide-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th class="num">Total in</th>
                            <th class="num">Total out</th>
                            <th class="num">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($yearly_stats as $m => $stats):
                            $net = $stats['in'] - $stats['out']; ?>
                            <tr>
                                <td><?= date('F', mktime(0, 0, 0, $m, 1)) ?></td>
                                <td class="num<?= $stats['in'] > 0 ? ' num--in' : '' ?>"><?= h(signed_qty($stats['in'], '+')) ?></td>
                                <td class="num<?= $stats['out'] > 0 ? ' num--out' : '' ?>"><?= h(signed_qty($stats['out'], BUD_MINUS)) ?></td>
                                <td class="num"><?= h($net > 0 ? '+' . bud_qty($net) : bud_qty($net)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Year</td>
                            <td class="num"><?= h(signed_qty($year_in, '+')) ?></td>
                            <td class="num"><?= h(signed_qty($year_out, BUD_MINUS)) ?></td>
                            <td class="num"><?= h(($year_in - $year_out) > 0 ? '+' . bud_qty($year_in - $year_out) : bud_qty($year_in - $year_out)) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </main>
<?php require 'includes/footer.php'; ?>
