<?php
// config.php
// Database Configuration
date_default_timezone_set('Pacific/Auckland');

// The database MUST live in /data inside the Home Assistant add-on.
// /data is the only directory the Supervisor persists across container
// restarts, rebuilds and add-on updates — anything else is ephemeral.
// BUD_DB_PATH exists so tests and local development can point at a
// throwaway database without touching /data.
$db_file = getenv('BUD_DB_PATH') ?: '/data/bud.db';

try {
    $db_dir = dirname($db_file);
    if (!is_dir($db_dir)) {
        // Under the HA Supervisor /data is always mounted; if it is missing
        // we must never fall back to storage inside the container, because
        // that data would be silently destroyed on the next update.
        if (getenv('SUPERVISOR_TOKEN') !== false || file_exists('/etc/services.d/nginx/run')) {
            die("FATAL: database directory '$db_dir' is not available. "
                . "Refusing to start with ephemeral storage — the database must live in /data.");
        }
        // Fallback for local (non add-on) development only
        $db_file = __DIR__ . '/database/bud_inventory.db';
    }

    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Enable foreign keys for SQLite
    $pdo->exec("PRAGMA foreign_keys = ON;");
    // Set busy timeout to 5 seconds to handle concurrency
    $pdo->exec("PRAGMA busy_timeout = 5000;");

    // Auto-migration: Ensure database schema is up-to-date
    // Check if product_bundles table exists (v0.10)
    $tables_check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='product_bundles'");
    if ($tables_check->rowCount() === 0) {
        // Run v0.10 migration
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS product_bundles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                sku TEXT,
                description TEXT,
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS bundle_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bundle_id INTEGER NOT NULL,
                stock_item_id INTEGER NOT NULL,
                quantity DECIMAL(10, 2) NOT NULL,
                FOREIGN KEY (bundle_id) REFERENCES product_bundles(id) ON DELETE CASCADE,
                FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
                FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
            )
        ");
    }
    // Close the cursor to prevent lock
    $tables_check = null;

    // Check for v0.14 schema (invoicing flag on Chain of Custody)
    $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE name='chain_of_custody'");
    $coc_schema = $stmt->fetchColumn();
    $stmt = null;

    if ($coc_schema && strpos($coc_schema, 'invoiced_at') === false) {
        $pdo->exec("ALTER TABLE chain_of_custody ADD COLUMN invoiced_at DATETIME");
        // Backfill: transfers completed before this feature existed are
        // treated as already invoiced, so staff are only prompted for
        // transfers completed from now on.
        $pdo->exec("UPDATE chain_of_custody
            SET invoiced_at = COALESCE(completed_at, created_at, CURRENT_TIMESTAMP)
            WHERE status = 'Completed'");
    }

    // v0.14: Time Sheet feature removed — drop its table and audit entries
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='time_logs'");
    $has_time_logs = $stmt->fetchColumn();
    $stmt = null;

    if ($has_time_logs) {
        $pdo->exec("DROP TABLE time_logs");
        $pdo->exec("DELETE FROM audit_log WHERE table_name = 'time_logs'");
    }

    // v0.15: Cancelled transfers (cancelled_at on Chain of Custody)
    $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE name='chain_of_custody'");
    $coc_schema = $stmt->fetchColumn();
    $stmt = null;

    if ($coc_schema && strpos($coc_schema, 'cancelled_at') === false) {
        $pdo->exec("ALTER TABLE chain_of_custody ADD COLUMN cancelled_at DATETIME");
    }

    // v0.15: Destruction Register table
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='destruction_log'");
    $has_destruction = $stmt->fetchColumn();
    $stmt = null;

    if (!$has_destruction) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS destruction_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                stock_item_id INTEGER,
                item_name TEXT NOT NULL,
                batch TEXT,
                quantity DECIMAL(10, 2) NOT NULL,
                unit TEXT,
                reason TEXT NOT NULL,
                method TEXT NOT NULL,
                destroyed_by TEXT NOT NULL,
                witness TEXT,
                witness_signature TEXT,
                notes TEXT,
                destroyed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE SET NULL
            )
        ");
    }

    // v0.16: Products + Section 29 reporting tables
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='s29_supplies'");
    $has_s29 = $stmt->fetchColumn();
    $stmt = null;

    if (!$has_s29) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                inn_generic TEXT,
                dose_form TEXT,
                pack_size TEXT,
                strength TEXT,
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS s29_imports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT,
                pharmacy TEXT NOT NULL,
                default_product_id INTEGER,
                row_count INTEGER DEFAULT 0,
                total_quantity DECIMAL(10, 2) DEFAULT 0,
                imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (default_product_id) REFERENCES products(id) ON DELETE SET NULL
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS s29_supplies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                import_id INTEGER NOT NULL,
                supplied_at DATETIME,
                supply_month TEXT,
                prescriber TEXT,
                prescriber_facility TEXT,
                patient TEXT,
                med_name TEXT,
                med_plu TEXT,
                quantity DECIMAL(10, 2) NOT NULL DEFAULT 0,
                product_id INTEGER,
                pharmacy TEXT,
                FOREIGN KEY (import_id) REFERENCES s29_imports(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
            )
        ");
    }

    // Seed the first verified product on empty installs/upgrades
    $stmt = $pdo->query("SELECT COUNT(*) FROM products");
    $product_count = $stmt->fetchColumn();
    $stmt = null;
    if ($product_count == 0) {
        $pdo->exec("INSERT INTO products (name) VALUES ('White Sherb')");
    }

    // v0.15: Scheduling feature removed — drop its tables and audit entries
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cleaning_schedules'");
    $has_cleaning = $stmt->fetchColumn();
    $stmt = null;

    if ($has_cleaning) {
        $pdo->exec("DROP TABLE IF EXISTS cleaning_logs");
        $pdo->exec("DROP TABLE cleaning_schedules");
        $pdo->exec("DELETE FROM audit_log WHERE table_name IN ('cleaning_schedules', 'cleaning_logs')");
    }
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper function to sanitize output
function h($string)
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// App Details
define('APP_NAME', 'BUD');
?>