<?php
// coc_document.php?id=12
// Shows the signed shipping manifest uploaded for transfer #12 (a photo or a
// PDF), from BUD's storage beside the database. Only a transfer's own file,
// by its stored name, never a path from the address bar.
require_once 'config.php';
require_once 'includes/documents.php';

$id = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT signed_document, signed_document_name FROM chain_of_custody WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();
$stmt = null;

$path = $row ? bud_document_path($row['signed_document']) : null;
if (!$path || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "There's no signed manifest for transfer #$id.";
    exit;
}

$type = bud_document_type($path);
$ext = pathinfo($path, PATHINFO_EXTENSION);
$download = 'signed-manifest-transfer-' . $id . '.' . $ext;

header('Content-Type: ' . (BUD_DOCUMENT_TYPES[$type] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $download . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0');
readfile($path);
