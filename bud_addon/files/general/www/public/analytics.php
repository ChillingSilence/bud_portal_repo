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
// products. Bundles count as the product that was sold, not the controlled
// items in them.
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
// The brand guide allows three series per chart, in chart green, blue and
// orange. The year's three biggest products get those, in that order, and any
// others are added together as "Other products" in grey. The tables below the
// charts still list every product.
$product_totals = [];
foreach ($monthly_product as $prods) {
    foreach ($prods as $p => $q) {
        $product_totals[$p] = ($product_totals[$p] ?? 0) + $q;
    }
}
arsort($product_totals);
$named_products = array_slice(array_keys($product_totals), 0, 3);
$has_other = count($product_totals) > 3;
$series = $named_products;
if ($has_other) {
    $series[] = 'Other products';
}
$series_of = function ($product) use ($named_products) {
    return in_array($product, $named_products, true) ? $product : 'Other products';
};

$month_labels = [];
for ($m = 1; $m <= 12; $m++) {
    $month_labels[] = date('M', mktime(0, 0, 0, $m, 1));
}

$bar_datasets = [];
foreach ($series as $name) {
    $data = array_fill(0, 12, 0);
    for ($m = 1; $m <= 12; $m++) {
        foreach ($monthly_product[$m] ?? [] as $p => $q) {
            if ($series_of($p) === $name) {
                $data[$m - 1] += $q;
            }
        }
    }
    $bar_datasets[] = ['label' => $name, 'data' => array_map(function ($v) { return round($v, 2); }, $data)];
}

// Who's buying what: one bar per buyer, biggest first, split by product
$buyer_totals = [];
foreach ($buyer_product as $buyer => $prods) {
    $buyer_totals[$buyer] = array_sum($prods);
}
arsort($buyer_totals);
$buyer_labels = array_keys($buyer_totals);
$buyer_datasets = [];
foreach ($series as $name) {
    $data = [];
    foreach ($buyer_labels as $buyer) {
        $sum = 0;
        foreach ($buyer_product[$buyer] as $p => $q) {
            if ($series_of($p) === $name) {
                $sum += $q;
            }
        }
        $data[] = round($sum, 2);
    }
    $buyer_datasets[] = ['label' => $name, 'data' => $data];
}
ksort($buyer_product);

// Monthly totals for the table
$monthly_totals = [];
for ($m = 1; $m <= 12; $m++) {
    $monthly_totals[$m] = round(array_sum($monthly_product[$m] ?? []), 2);
}

$has_data = $total_dispatched > 0;
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$pageTitle = 'Analytics';
$extraHead = '<script src="js/vendor/chart.umd.min.js?v=' . h(BUD_VERSION) . '"></script>';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Analytics</h1>
            <p>Every product sent out on a completed chain of custody transfer. A bundle counts as the product sold, and quantities add up as they were recorded, so grams and units are added together.</p>
        </hgroup>

        <form method="GET" class="toolbar">
            <label>Year
                <select name="year">
                    <?php for ($y = $max_year; $y >= $min_year; $y--): ?>
                        <option value="<?= $y ?>"<?= $y == $selected_year ? ' selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <button type="submit">Show</button>
        </form>

        <div class="stat-grid">
            <div class="stat-card">
                <span class="stat-value"><?= $transfer_count ?></span>
                <span class="stat-label">Completed transfers in <?= h($selected_year) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?= h(bud_qty($total_dispatched)) ?></span>
                <span class="stat-label">Total sent out in <?= h($selected_year) ?></span>
            </div>
        </div>

        <?php if (!$has_data): ?>
            <p class="empty-note">No completed transfers in <?= h($selected_year) ?>.</p>
        <?php else: ?>

            <article>
                <h2>Materials out by month, <?= h($selected_year) ?></h2>
                <p class="panel-note">Quantity sent out each month, split by product.</p>
                <div class="chart-box">
                    <canvas id="monthlyChart" role="img" aria-label="Bar chart of quantity sent out each month, split by product. The same figures are in the monthly totals table."></canvas>
                </div>
            </article>

            <article>
                <h2>Who's buying what, <?= h($selected_year) ?></h2>
                <p class="panel-note">Quantity each buyer received, split by product, biggest buyer first.</p>
                <div class="chart-box" style="height: <?= max(14, count($buyer_labels) * 2.6 + 6) ?>rem">
                    <canvas id="buyerChart" role="img" aria-label="Bar chart of the quantity each buyer received, split by product. The same figures are in the buyer breakdown table."></canvas>
                </div>
            </article>

            <section class="hub-section">
                <h2>Buyer breakdown</h2>
                <div class="table-wrap">
                    <table class="wide-table">
                        <thead>
                            <tr>
                                <th>Buyer</th>
                                <th>Product</th>
                                <th class="num">Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($buyer_product as $buyer => $prods):
                                ksort($prods);
                                $first = true; ?>
                                <?php foreach ($prods as $p => $q): ?>
                                    <tr>
                                        <td><?= $first ? '<strong>' . h($buyer) . '</strong>' : '' ?></td>
                                        <td class="cell-long"><?= h($p) ?></td>
                                        <td class="num"><?= h(bud_qty($q)) ?></td>
                                    </tr>
                                    <?php $first = false; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2">Total sent out</td>
                                <td class="num"><?= h(bud_qty($total_dispatched)) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="hub-section">
                <h2>Monthly totals, <?= h($selected_year) ?></h2>
                <div class="table-wrap">
                    <table class="wide-table">
                        <thead>
                            <tr>
                                <?php foreach ($month_labels as $ml): ?>
                                    <th class="num"><?= $ml ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <td class="num"><?= $monthly_totals[$m] > 0 ? h(bud_qty($monthly_totals[$m])) : '-' ?></td>
                                <?php endfor; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <script type="application/json" id="chartData"><?= json_encode([
                'months' => $month_labels,
                'monthly' => $bar_datasets,
                'buyers' => $buyer_labels,
                'byBuyer' => $buyer_datasets,
            ], $json_flags) ?></script>
            <script>
                (function () {
                    const data = JSON.parse(document.getElementById('chartData').textContent);
                    // The brand guide's chart colours, in order, then grey for "Other products"
                    const seriesColours = ['#2F7D4F', '#3B6FB6', '#C05600'];
                    const otherColour = '#C9C8C2';
                    const colourFor = (ds, i) => ds.label === 'Other products' ? otherColour : seriesColours[i];
                    let charts = [];

                    function token(name) {
                        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
                    }

                    // Totals at the end of each bar, in the muted text colour
                    const barTotals = {
                        id: 'barTotals',
                        afterDatasetsDraw(chart) {
                            const ctx = chart.ctx;
                            const horizontal = chart.options.indexAxis === 'y';
                            const count = chart.data.labels.length;
                            ctx.save();
                            ctx.fillStyle = token('--b-text-muted');
                            ctx.font = '600 12px Inter, system-ui, sans-serif';
                            for (let i = 0; i < count; i++) {
                                let total = 0;
                                let end = null;
                                chart.data.datasets.forEach((ds, d) => {
                                    const meta = chart.getDatasetMeta(d);
                                    if (meta.hidden || !meta.data[i]) return;
                                    total += Number(ds.data[i]) || 0;
                                    const el = meta.data[i];
                                    if (ds.data[i]) end = horizontal ? Math.max(end ?? el.x, el.x) : Math.min(end ?? el.y, el.y);
                                });
                                if (!total || end === null) continue;
                                const anchor = chart.getDatasetMeta(0).data[i];
                                if (horizontal) {
                                    ctx.textAlign = 'left';
                                    ctx.textBaseline = 'middle';
                                    ctx.fillText(formatQty(total), end + 6, anchor.y);
                                } else {
                                    ctx.textAlign = 'center';
                                    ctx.textBaseline = 'bottom';
                                    ctx.fillText(formatQty(total), anchor.x, end - 4);
                                }
                            }
                            ctx.restore();
                        },
                    };

                    function datasets(list) {
                        const surface = token('--b-surface');
                        return list.map((ds, i) => ({
                            label: ds.label,
                            data: ds.data,
                            backgroundColor: colourFor(ds, i),
                            hoverBackgroundColor: colourFor(ds, i),
                            // A 2px gap in the page colour between stacked parts
                            borderColor: surface,
                            borderWidth: 2,
                            borderSkipped: false,
                            borderRadius: 4,
                            maxBarThickness: 28,
                        }));
                    }

                    function draw() {
                        charts.forEach(c => c.destroy());
                        const text = token('--b-text-muted');
                        const grid = token('--b-border');
                        Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
                        Chart.defaults.color = text;
                        Chart.defaults.borderColor = grid;
                        const common = {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom', labels: { boxWidth: 12, boxHeight: 12, useBorderRadius: true, borderRadius: 3 } },
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => ` ${ctx.dataset.label}: ${formatQty(ctx.raw)}`,
                                    },
                                },
                            },
                        };
                        charts = [
                            new Chart(document.getElementById('monthlyChart'), {
                                type: 'bar',
                                data: { labels: data.months, datasets: datasets(data.monthly) },
                                options: {
                                    ...common,
                                    layout: { padding: { top: 18 } },
                                    scales: {
                                        x: { stacked: true, grid: { display: false } },
                                        y: { stacked: true, beginAtZero: true, grid: { color: grid }, title: { display: true, text: 'Quantity sent out' } },
                                    },
                                },
                                plugins: [barTotals],
                            }),
                            new Chart(document.getElementById('buyerChart'), {
                                type: 'bar',
                                data: { labels: data.buyers, datasets: datasets(data.byBuyer) },
                                options: {
                                    ...common,
                                    indexAxis: 'y',
                                    layout: { padding: { right: 48 } },
                                    scales: {
                                        x: { stacked: true, beginAtZero: true, grid: { color: grid }, title: { display: true, text: 'Quantity received' } },
                                        y: { stacked: true, grid: { display: false } },
                                    },
                                },
                                plugins: [barTotals],
                            }),
                        ];
                    }

                    draw();
                    document.addEventListener('bud:themechange', draw);
                })();
            </script>
        <?php endif; ?>
    </main>
<?php require 'includes/footer.php'; ?>
