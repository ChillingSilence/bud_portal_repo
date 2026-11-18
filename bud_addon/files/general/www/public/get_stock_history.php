<?php
require_once 'config.php';

$item_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT old_values, new_values, timestamp, changed_by 
    FROM audit_log 
    WHERE table_name = 'stock_items' AND record_id = ?
    ORDER BY id DESC
    LIMIT 20
");
$stmt->execute([$item_id]);
$logs = $stmt->fetchAll();

$history = [];
foreach ($logs as $log) {
    $old = json_decode($log['old_values'], true);
    $new = json_decode($log['new_values'], true);

    $qty_old = $old['quantity'] ?? 0;
    $qty_new = $new['quantity'] ?? 0;
    $diff = $qty_new - $qty_old;

    // Determine the type of change
    $type = 'Update';
    if ($diff > 0)
        $type = 'Added';
    if ($diff < 0)
        $type = 'Removed';

    // Check for special context in notes
    $notes = $new['adjustment_notes'] ?? ($new['notes'] ?? '');

    $history[] = [
        'timestamp' => $log['timestamp'],
        'type' => $type,
        'diff' => $diff,
        'qty_new' => $qty_new,
        'notes' => $notes
    ];
}

header('Content-Type: application/json');
echo json_encode($history);
