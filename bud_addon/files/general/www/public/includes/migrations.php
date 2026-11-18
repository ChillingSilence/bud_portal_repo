<?php
// includes/migrations.php
// One-off data fixes. Each runs exactly once per database and is recorded in
// the data_migrations table, so a restored older backup gets fixed too.

const BUD_NZ_TIME_MIGRATION = '1.0.0-times-to-nz';

/**
 * Until 1.0.0, BUD stamped times with SQLite's CURRENT_TIMESTAMP, which is
 * UTC: 12 or 13 hours behind NZ. A destruction at 9am on 1 November showed as
 * 31 October, and landed in October's register and report. From 1.0.0 every
 * time is stamped in NZ time by PHP, and this converts the times already
 * stored. Dates people typed (a transfer's date) and dates from S29 files were
 * always NZ dates, so they're left alone.
 */
function bud_convert_times_to_nz(PDO $pdo)
{
    $columns = [
        'audit_log' => ['timestamp'],
        'chain_of_custody' => ['created_at', 'completed_at', 'invoiced_at', 'cancelled_at'],
        'destruction_log' => ['destroyed_at'],
        's29_imports' => ['imported_at'],
        'stock_items' => ['created_at', 'updated_at'],
        'suppliers' => ['created_at', 'updated_at'],
        'product_bundles' => ['created_at', 'updated_at'],
        'verified_receivers' => ['created_at'],
        'products' => ['created_at'],
        'materials_out_reports' => ['generated_at'],
    ];
    $utc = new DateTimeZone('UTC');
    $nz = new DateTimeZone('Pacific/Auckland');

    // IMMEDIATE takes the write lock now: a second request arriving at the
    // same moment waits, then finds the fix already recorded
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS data_migrations (name TEXT PRIMARY KEY, applied_at DATETIME)");
        $stmt = $pdo->prepare("SELECT 1 FROM data_migrations WHERE name = ?");
        $stmt->execute([BUD_NZ_TIME_MIGRATION]);
        $done = $stmt->fetchColumn();
        $stmt = null;

        if (!$done) {
            foreach ($columns as $table => $cols) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
                $stmt->execute([$table]);
                $exists = (int) $stmt->fetchColumn();
                $stmt = null;
                if (!$exists) {
                    continue;
                }
                $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
                foreach ($cols as $col) {
                    if (!in_array($col, $have, true)) {
                        continue;
                    }
                    // Read everything first: an open cursor would lock the table
                    $rows = $pdo->query("SELECT rowid AS rid, $col AS v FROM $table WHERE $col IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
                    $update = $pdo->prepare("UPDATE $table SET $col = ? WHERE rowid = ?");
                    foreach ($rows as $row) {
                        // Only CURRENT_TIMESTAMP's own format, "2026-11-18 03:02:22"
                        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['v'])) {
                            continue;
                        }
                        $time = DateTime::createFromFormat('Y-m-d H:i:s', $row['v'], $utc);
                        if (!$time) {
                            continue;
                        }
                        $time->setTimezone($nz);
                        $update->execute([$time->format('Y-m-d H:i:s'), $row['rid']]);
                    }
                    $update = null;
                }
            }
            $pdo->prepare("INSERT INTO data_migrations (name, applied_at) VALUES (?, ?)")
                ->execute([BUD_NZ_TIME_MIGRATION, date('Y-m-d H:i:s')]);
        }
        $pdo->exec('COMMIT');
    } catch (Exception $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Has this database had its times converted to NZ time? */
function bud_times_are_nz(PDO $pdo)
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'data_migrations'");
    $has_table = (int) $stmt->fetchColumn();
    $stmt = null;
    if (!$has_table) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM data_migrations WHERE name = ?");
    $stmt->execute([BUD_NZ_TIME_MIGRATION]);
    $done = (int) $stmt->fetchColumn();
    $stmt = null;
    return $done > 0;
}
