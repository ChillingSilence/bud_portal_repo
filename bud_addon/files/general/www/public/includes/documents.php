<?php
// includes/documents.php
// Signed shipping manifests: the photo or scan of the manifest a receiver
// signed, uploaded to complete a courier transfer.
//
// The files live in coc_documents/ beside the database. In the app that's
// /data/coc_documents, BUD's permanent storage, so Home Assistant backups
// include them (the admin tools' .db download doesn't). Never under the web
// folder: coc_document.php hands them out, and only for a transfer that has
// one. tests/check_db_storage.sh and tests/courier_test.sh check this.

const BUD_DOCUMENT_MAX_BYTES = 20 * 1024 * 1024;

// What a signed manifest may be, by the first bytes of the file. The app has
// no fileinfo extension, and a file's name proves nothing.
const BUD_DOCUMENT_TYPES = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

/** The folder the signed manifests are kept in (beside the database). */
function bud_documents_dir()
{
    global $db_file;
    return dirname($db_file) . '/coc_documents';
}

/** The file's type from its first bytes: 'pdf', 'jpg', 'png', 'webp' or null. */
function bud_document_type($path)
{
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return null;
    }
    $head = fread($handle, 16);
    fclose($handle);
    if ($head === false || strlen($head) < 12) {
        return null;
    }
    if (strncmp($head, '%PDF-', 5) === 0) {
        return 'pdf';
    }
    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
        return 'jpg';
    }
    if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
        return 'png';
    }
    if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
        return 'webp';
    }
    return null;
}

/**
 * Check and keep an uploaded signed manifest for transfer $coc_id.
 * @param array $upload one entry of $_FILES
 * @return array ['file' => stored name, 'name' => original name]
 * @throws Exception with a message for the person uploading it
 */
function bud_store_document(array $upload, $coc_id)
{
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new Exception("That file is too big. Keep it under 20 MB, or take the photo again at a smaller size.");
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        throw new Exception("The signed manifest didn't upload. Choose the photo or PDF again.");
    }
    if (($upload['size'] ?? 0) > BUD_DOCUMENT_MAX_BYTES) {
        throw new Exception("That file is too big. Keep it under 20 MB, or take the photo again at a smaller size.");
    }
    $type = bud_document_type($upload['tmp_name']);
    if ($type === null) {
        throw new Exception("The signed manifest has to be a photo (JPG, PNG or WebP) or a PDF.");
    }

    $dir = bud_documents_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new Exception("BUD couldn't make its folder for signed manifests. Check the app's storage.");
    }
    $stored = 'transfer-' . (int) $coc_id . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $type;
    if (!move_uploaded_file($upload['tmp_name'], $dir . '/' . $stored)) {
        throw new Exception("BUD couldn't save the signed manifest. Check the app's storage.");
    }

    // Keep the person's own file name to show, but only its safe characters
    $original = basename((string) ($upload['name'] ?? ''));
    $original = preg_replace('/[^\w .()\-]+/u', '_', $original);
    if ($original === '' || $original === null) {
        $original = 'signed-manifest.' . $type;
    }
    return ['file' => $stored, 'name' => substr($original, 0, 120)];
}

/** The full path of a stored manifest, or null if the name isn't one of ours. */
function bud_document_path($stored)
{
    if (!is_string($stored) || !preg_match('/^transfer-\d+-\d{8}-\d{6}-[0-9a-f]{8}\.(pdf|jpg|png|webp)$/', $stored)) {
        return null;
    }
    return bud_documents_dir() . '/' . $stored;
}
