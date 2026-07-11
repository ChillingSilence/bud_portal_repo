<?php
// includes/audit.php

class Audit
{
    public static function log($pdo, $table, $record_id, $action, $old_values = null, $new_values = null, $changed_by = 'SYSTEM')
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO audit_log (table_name, record_id, action, changed_by, old_values, new_values) VALUES (?, ?, ?, ?, ?, ?)");

            $old_json = $old_values ? json_encode($old_values) : null;
            $new_json = $new_values ? json_encode($new_values) : null;

            $stmt->execute([$table, $record_id, $action, $changed_by, $old_json, $new_json]);
        } catch (PDOException $e) {
            // Silently fail or log to file? For now, we want strict, so maybe throw?
            // But we don't want to break the app if audit fails?
            // User requirement: "All changes... atomic... replay everything".
            // So we should probably die if audit fails to ensure integrity.
            die("Audit Log Failure: " . $e->getMessage());
        }
    }

    /**
     * Reverse the change recorded in a single audit_log entry:
     *   INSERT  -> delete the record
     *   UPDATE  -> restore old_values
     *   DELETE  -> re-insert old_values
     *
     * The reversal is applied in a transaction and is itself written to the
     * audit log (changed_by = 'UNDO'), so the chain stays complete and an
     * undo can in turn be undone. Throws on anything that cannot be safely
     * reversed (missing record, no old_values recorded, unknown table).
     */
    public static function undo($pdo, $log_id)
    {
        // Table names come from stored log rows, not user input, but they are
        // interpolated into SQL — allow only the application's own tables.
        $allowed_tables = [
            'suppliers',
            'stock_items',
            'cleaning_schedules',
            'cleaning_logs',
            'chain_of_custody',
            'product_bundles',
            'bundle_items',
            'verified_receivers',
        ];

        $stmt = $pdo->prepare("SELECT * FROM audit_log WHERE id = ?");
        $stmt->execute([$log_id]);
        $log = $stmt->fetch();
        $stmt = null;

        if (!$log) {
            throw new Exception("Audit log entry #$log_id not found.");
        }

        $table = $log['table_name'];
        if (!in_array($table, $allowed_tables, true)) {
            throw new Exception("Changes to '$table' cannot be undone.");
        }

        $record_id = $log['record_id'];
        $old = $log['old_values'] ? json_decode($log['old_values'], true) : null;

        // Audit rows may carry extra context keys (e.g. adjustment_notes) or
        // raw form data — only real columns can be written back.
        $columns = [];
        foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $col) {
            $columns[] = $col['name'];
        }
        $restore = $old ? array_intersect_key($old, array_flip($columns)) : [];

        $pdo->beginTransaction();
        try {
            switch ($log['action']) {
                case 'INSERT':
                    $cur_stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
                    $cur_stmt->execute([$record_id]);
                    $current = $cur_stmt->fetch();
                    $cur_stmt = null;
                    if (!$current) {
                        throw new Exception("Record #$record_id no longer exists in $table.");
                    }
                    $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$record_id]);
                    self::log($pdo, $table, $record_id, 'DELETE', $current, null, 'UNDO');
                    break;

                case 'UPDATE':
                    unset($restore['id']);
                    if (!$restore) {
                        throw new Exception("No previous values were recorded — this update cannot be undone.");
                    }
                    $cur_stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
                    $cur_stmt->execute([$record_id]);
                    $current = $cur_stmt->fetch();
                    $cur_stmt = null;
                    if (!$current) {
                        throw new Exception("Record #$record_id no longer exists in $table.");
                    }
                    $set = implode(', ', array_map(function ($c) {
                        return "$c = ?";
                    }, array_keys($restore)));
                    $pdo->prepare("UPDATE $table SET $set WHERE id = ?")
                        ->execute(array_merge(array_values($restore), [$record_id]));
                    self::log($pdo, $table, $record_id, 'UPDATE', $current, $restore, 'UNDO');
                    break;

                case 'DELETE':
                    if (!$restore) {
                        throw new Exception("No previous values were recorded — this deletion cannot be undone.");
                    }
                    $col_list = implode(', ', array_keys($restore));
                    $placeholders = implode(', ', array_fill(0, count($restore), '?'));
                    $pdo->prepare("INSERT INTO $table ($col_list) VALUES ($placeholders)")
                        ->execute(array_values($restore));
                    self::log($pdo, $table, $record_id, 'INSERT', null, $restore, 'UNDO');
                    break;

                default:
                    throw new Exception("Unknown action '{$log['action']}'.");
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
?>