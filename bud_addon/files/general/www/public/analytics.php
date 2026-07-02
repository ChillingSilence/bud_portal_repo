<?php
require_once 'config.php';

// ── Filters ───────────────────────────────────────────────────────────────────
$selected_year = $_GET['year'] ?? date('Y');
if (!preg_match('/^\d{4}$/', $selected_year)) {
    $selected_year = date('Y');
}

// Year dropdown range: earliest completed transfer through current year
$min_year_stmt = $pdo->query("SELECT MIN(strftime('%Y', form_date)) FROM chain_of_custody WHERE status = 'Completed'");
$min_year = intval($min_year_stmt->fetchColumn() ?: date('Y'));
$max_year = intval(date('Y'));

// ── Name maps for stock items and bundles ─────────────────────────────────────
$stock_name_map = [];
foreach ($pdo->query("SELECT id, name, sku FROM stock_items")->fetchAll() as $s) {
    $stock_name_map[$s['id']] = $s['name'] . ($s['sku'] ? ' (' . $s['sku'] . ')' : '');
}
$bundle_name_map = [];
foreach ($pdo->query("SELECT id, name, sku FROM product_bundles")->fetchAll() as $b) {
    $bundle_name_map[$b['id']] = $b['name'] . ($b['sku'] ? ' (' . $b['sku'] . ')' : '');
}

function resolveProductName($item_id, $stock_name_map, $bundle_name_map)
{
    if (strpos($item_id, 'bundle_') === 0) {
        $bid = (int) str_replace('bundle_', '', $item_id);
        return $bundle_name_map[$bid] ?? ('Bundle #' . $bid);
    }
    return $stock_name_map[(int) $item_id] ?? ('Item #' . $item_id);
}

// ── Aggregate completed transfers for the selected year ───────────────────────
// Unlike the compliance report (reports.php), this view covers ALL dispatched
// products — bundles are counted as the product that was sold, not expanded
// into their controlled components.
$stmt = $pdo->prepare("
    SELECT form_date, destination, coc_items
    FROM chain_of_custody
    WHERE status = 'Completed' AND form_date LIKE ?
    ORDER BY form_date ASC
");
$stmt->execute(["$selected_year-%"]);

$monthly_product = [];      // [month 1-12][product] => qty
$buyer_product   = [];      // [buyer][product] => qty
$total_dispatched = 0;
$transfer_count   = 0;

foreach ($stmt->fetchAll() as $t) {
    $items = json_decode($t['coc_items'], true);
    if (!$items) {
        continue;
    }
    $transfer_count++;
    $month = intval(substr($t['form_date'], 5, 2));
    $buyer = $t['destination'] ?: 'Unknown';

    foreach ($items as $item) {
        $qty = floatval($item['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $product = resolveProductName($item['item_id'], $stock_name_map, $bundle_name_map);

        $monthly_product[$month][$product] = ($monthly_product[$month][$product] ?? 0) + $qty;
        $buyer_product[$buyer][$product]   = ($buyer_product[$buyer][$product] ?? 0) + $qty;
        $total_dispatched += $qty;
    }
}

// ── Shape data for the charts ─────────────────────────────────────────────────
$products = [];
foreach ($monthly_product as $prods) {
    foreach ($prods as $p => $q) {
        $products[$p] = true;
    }
}
$products = array_keys($products);
sort($products);

$month_labels = [];
for ($m = 1; $m <= 12; $m++) {
    $month_labels[] = date('M', mktime(0, 0, 0, $m, 1));
}

$bar_datasets = [];
foreach ($products as $p) {
    $data = [];
    for ($m = 1; $m <= 12; $m++) {
        $data[] = round($monthly_product[$m][$p] ?? 0, 2);
    }
    $bar_datasets[] = ['label' => $p, 'data' => $data];
}

// Pie: one slice per buyer + product combination ("who bought what")
$pie_labels = [];
$pie_data   = [];
ksort($buyer_product);
foreach ($buyer_product as $buyer => $prods) {
    ksort($prods);
    foreach ($prods as $p => $q) {
        $pie_labels[] = $buyer . ' — ' . $p;
        $pie_data[]   = round($q, 2);
    }
}

// Monthly totals for the table
$monthly_totals = [];
for ($m = 1; $m <= 12; $m++) {
    $monthly_totals[$m] = round(array_sum($monthly_product[$m] ?? []), 2);
}

$has_data = $total_dispatched > 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Analytics</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="container">
        <h1>Analytics</h1>

        <!-- Controls -->
        <div class="glass-panel" style="margin-bottom: 2rem;">
            <form method="GET" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                <div>
                    <label>Year</label>
                    <select name="year">
                        <?php for ($y = $max_year; $y >= $min_year; $y--): ?>
                            <option value="<?= $y ?>" <?= $y == $selected_year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="btn">Update View</button>
                <div style="margin-left: auto; text-align: right;">
                    <div style="font-size: 0.85rem; color: var(--text-muted, #aaa);">Completed transfers in <?= h($selected_year) ?></div>
                    <div style="font-size: 1.5rem; font-weight: 800;"><?= $transfer_count ?></div>
                </div>
            </form>
        </div>

        <p style="color: var(--text-muted, #aaa); font-size: 0.85rem; margin-bottom: 1.5rem; margin-top: -1rem;">
            ℹ️ Covers <strong>all products</strong> dispatched via completed Chain of Custody transfers.
            Bundles are counted as the product sold (not expanded into components).
            Quantities are summed as recorded, so mixed units (e.g. grams vs units) are added together as-is.
        </p>

        <?php if (!$has_data): ?>
            <div class="glass-panel">
                <p>No completed transfers recorded for <?= h($selected_year) ?>.</p>
            </div>
        <?php else: ?>

            <!-- Materials Out by Month -->
            <div class="glass-panel" style="margin-bottom: 2rem;">
                <h3>📦 Materials Out by Month (<?= h($selected_year) ?>)</h3>
                <p><small>Quantity dispatched per product, stacked by month</small></p>
                <div style="position: relative; height: 340px;">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 2rem;">

                <!-- Who's buying what -->
                <div class="glass-panel">
                    <h3>🥧 Who's Buying What (<?= h($selected_year) ?>)</h3>
                    <p><small>Share of dispatched quantity by buyer and product</small></p>
                    <div style="position: relative; height: 360px;">
                        <canvas id="buyerPie"></canvas>
                    </div>
                </div>

                <!-- Buyer × product table -->
                <div class="glass-panel">
                    <h3>🧾 Buyer Breakdown</h3>
                    <p><small>Total quantity received per buyer and product</small></p>
                    <div class="table-responsive">
                        <table style="font-size: 0.9rem;">
                            <thead>
                                <tr>
                                    <th>Buyer</th>
                                    <th>Product</th>
                                    <th style="text-align: right;">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($buyer_product as $buyer => $prods): ?>
                                    <?php $first = true; ?>
                                    <?php foreach ($prods as $p => $q): ?>
                                        <tr>
                                            <td><?= $first ? '<strong>' . h($buyer) . '</strong>' : '' ?></td>
                                            <td><?= h($p) ?></td>
                                            <td style="text-align: right;"><?= h(round($q, 2)) ?></td>
                                        </tr>
                                        <?php $first = false; ?>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                <tr>
                                    <td colspan="2" style="text-align: right;"><strong>Total dispatched</strong></td>
                                    <td style="text-align: right;"><strong><?= h(round($total_dispatched, 2)) ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Monthly totals table -->
            <div class="glass-panel" style="margin-top: 2rem;">
                <h3>📅 Monthly Totals (<?= h($selected_year) ?>)</h3>
                <div class="table-responsive">
                    <table style="text-align: center;">
                        <thead>
                            <tr>
                                <?php foreach ($month_labels as $ml): ?>
                                    <th><?= $ml ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <td><?= $monthly_totals[$m] > 0 ? h($monthly_totals[$m]) : '—' ?></td>
                                <?php endfor; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <script>
                const monthLabels = <?= json_encode($month_labels) ?>;
                const barDatasets = <?= json_encode($bar_datasets) ?>;
                const pieLabels = <?= json_encode($pie_labels) ?>;
                const pieData = <?= json_encode($pie_data) ?>;

                // Distinct, theme-independent colours for any number of series
                function seriesColor(i, total, alpha = 0.85) {
                    const hue = Math.round((360 / Math.max(total, 1)) * i);
                    return `hsla(${hue}, 70%, 55%, ${alpha})`;
                }

                // Match chart text to the current theme
                Chart.defaults.color = getComputedStyle(document.body).color;
                Chart.defaults.borderColor = 'rgba(128, 128, 128, 0.2)';

                new Chart(document.getElementById('monthlyChart'), {
                    type: 'bar',
                    data: {
                        labels: monthLabels,
                        datasets: barDatasets.map((ds, i) => ({
                            ...ds,
                            backgroundColor: seriesColor(i, barDatasets.length),
                            borderWidth: 0
                        }))
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { stacked: true },
                            y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Quantity dispatched' } }
                        },
                        plugins: {
                            legend: { position: 'bottom' }
                        }
                    }
                });

                new Chart(document.getElementById('buyerPie'), {
                    type: 'doughnut',
                    data: {
                        labels: pieLabels,
                        datasets: [{
                            data: pieData,
                            backgroundColor: pieLabels.map((_, i) => seriesColor(i, pieLabels.length)),
                            borderWidth: 1,
                            borderColor: 'rgba(0, 0, 0, 0.2)'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 12 } },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => {
                                        const total = pieData.reduce((a, b) => a + b, 0);
                                        const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                        return ` ${ctx.parsed} (${pct}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            </script>
        <?php endif; ?>
    </div>
</body>

</html>
