<?php
require_once 'config.php';

// First Run Check
try {
    // Try to select 1 record from suppliers to see if table exists
    // SQLite throws exception if table doesn't exist
    $result = $pdo->query("SELECT 1 FROM suppliers LIMIT 1");
} catch (PDOException $e) {
    // Table likely doesn't exist, try to import schema
    if (file_exists('database/schema.sql')) {
        $sql = file_get_contents('database/schema.sql');
        try {
            // exec() allows multiple statements
            $pdo->exec($sql);

            // Log success (optional)
        } catch (PDOException $ex) {
            die("First Run Setup Failed (Schema Import): " . $ex->getMessage());
        }
    } else {
        die("Database not initialized and schema.sql not found.");
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="container">
        <header style="margin-bottom: 2rem;">
            <h1>Dashboard</h1>
            <p>Welcome to <strong><?= APP_NAME ?></strong>. What would you like to do?</p>
        </header>

        <div class="grid">
            <div class="glass-panel">
                <h3>📦 Stock Management</h3>
                <p>View current inventory levels, receive new stock, or update items.</p>
                <a href="stock.php" class="btn">Manage Stock</a>
            </div>

            <div class="glass-panel">
                <h3>🚚 Chain of Custody</h3>
                <p>Generate transfer forms and capture signatures for transport.</p>
                <a href="custody.php" class="btn">New Transfer</a>
            </div>

            <div class="glass-panel">
                <h3>🔥 Destruction</h3>
                <p>Record destroyed stock and view the destruction register.</p>
                <a href="destruction.php" class="btn">Destruction Register</a>
            </div>

            <div class="glass-panel">
                <h3>👥 Suppliers</h3>
                <p>Manage supplier contact details.</p>
                <a href="suppliers.php" class="btn">Suppliers</a>
            </div>

            <div class="glass-panel">
                <h3>📊 Reports</h3>
                <p>Generate monthly materials out reports.</p>
                <a href="reports.php" class="btn">View Reports</a>
            </div>

            <div class="glass-panel">
                <h3>📈 Analytics</h3>
                <p>Materials-out by month, with product and buyer breakdowns.</p>
                <a href="analytics.php" class="btn">View Analytics</a>
            </div>
        </div>
    </div>
</body>

</html>