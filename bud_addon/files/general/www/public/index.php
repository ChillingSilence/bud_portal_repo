<?php
require_once 'config.php';
require_once 'includes/stock.php';

// First Run Check
try {
    // Try to select 1 record from suppliers to see if table exists
    // SQLite throws exception if table doesn't exist
    $result = $pdo->query("SELECT 1 FROM suppliers LIMIT 1");
    $result = null;
} catch (PDOException $e) {
    // Table likely doesn't exist, try to import schema
    if (file_exists('database/schema.sql')) {
        $sql = file_get_contents('database/schema.sql');
        try {
            // exec() allows multiple statements
            $pdo->exec($sql);
        } catch (PDOException $ex) {
            die("First Run Setup Failed (Schema Import): " . $ex->getMessage());
        }
    } else {
        die("Database not initialized and schema.sql not found.");
    }
}

// Everything on the Dashboard is a read-only summary
$stats = [
    'in_progress' => 0,
    'to_invoice' => 0,
    'low_stock' => 0,
    'completed_this_month' => 0,
];
$low_items = [];
try {
    $stats['in_progress'] = (int) $pdo->query("SELECT COUNT(*) FROM chain_of_custody WHERE status = 'In Progress'")->fetchColumn();
    $stats['to_invoice'] = (int) $pdo->query("SELECT COUNT(*) FROM chain_of_custody WHERE status = 'Completed' AND invoiced_at IS NULL")->fetchColumn();

    // Completed transfers dated this month, as the monthly report counts them
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM chain_of_custody WHERE status = 'Completed' AND form_date BETWEEN ? AND ?");
    $stmt->execute([date('Y-m-01'), date('Y-m-t')]);
    $stats['completed_this_month'] = (int) $stmt->fetchColumn();
    $stmt = null;

    // Retired items are out of use, so they never count as low
    foreach ($pdo->query("SELECT * FROM stock_items WHERE is_active = 1 ORDER BY name ASC")->fetchAll() as $item) {
        if (bud_is_low_stock($item)) {
            $low_items[] = $item;
        }
    }
    $stats['low_stock'] = count($low_items);
} catch (PDOException $e) {
    error_log('BUD dashboard: ' . $e->getMessage());
}

$pageTitle = 'Dashboard';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Dashboard</h1>
            <p>Your stock and transfers at a glance.</p>
        </hgroup>

        <div class="stat-grid">
            <a class="stat-card" href="custody.php">
                <span class="stat-value"><?= $stats['in_progress'] ?></span>
                <span class="stat-label">Transfers in progress</span>
            </a>
            <a class="stat-card" href="custody.php">
                <span class="stat-value"><?= $stats['to_invoice'] ?></span>
                <span class="stat-label">Transfers to invoice</span>
            </a>
            <a class="stat-card" href="stock.php">
                <span class="stat-value"><?= $stats['low_stock'] ?></span>
                <span class="stat-label">Low-stock items</span>
            </a>
            <a class="stat-card" href="reports.php">
                <span class="stat-value"><?= $stats['completed_this_month'] ?></span>
                <span class="stat-label">Completed this month</span>
            </a>
        </div>

        <section>
            <div class="panel-head">
                <div>
                    <h2>Low stock</h2>
                    <p>Items at or under their reorder level.</p>
                </div>
                <div class="panel-head-actions">
                    <a href="stock.php" role="button" class="secondary outline">Open stock</a>
                </div>
            </div>
            <?php if (empty($low_items)): ?>
                <p class="empty-note">Nothing is at or under its reorder level.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="wide-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>SKU</th>
                                <th class="num">On hand</th>
                                <th class="num">Reorder level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($low_items as $item): ?>
                                <tr>
                                    <td class="cell-long">
                                        <?= h($item['name']) ?>
                                        <?php if ($item['is_controlled']): ?><span class="badge">Controlled</span><?php endif; ?>
                                    </td>
                                    <td class="nowrap"><?= h($item['sku']) ?></td>
                                    <td class="num"><?= h(bud_qty($item['quantity'])) ?> <?= h($item['unit']) ?></td>
                                    <td class="num"><?= h(bud_qty($item['reorder_level'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
<?php require 'includes/footer.php'; ?>
