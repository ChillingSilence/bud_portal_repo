<?php
require_once 'config.php';
require_once 'includes/settings.php';

$message = '';
$message_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_company') {
    $name = trim($_POST['company_name'] ?? '');
    $email = trim($_POST['company_email'] ?? '');
    if ($email !== '' && !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
        $message = "That email address doesn't look right. Check it and save again.";
        $message_error = true;
    } else {
        foreach (BUD_COMPANY_FIELDS as $field) {
            bud_set_setting($pdo, $field, trim($_POST[$field] ?? ''));
        }
        $message = "Saved your company's details. They'll print on every shipping manifest.";
    }
}

$company = bud_company($pdo);
if ($message_error) {
    // Keep what was typed, so it can be fixed rather than typed again
    foreach (BUD_COMPANY_FIELDS as $field) {
        $company[substr($field, strlen('company_'))] = trim($_POST[$field] ?? '');
    }
}

$pageTitle = 'Your company';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Your company</h1>
            <p>Printed on every shipping manifest, so the receiver and anyone carrying it knows who sent it and where to send the signed copy.</p>
        </hgroup>

        <?= bud_message($message, $message_error) ?>

        <article class="form-narrow">
            <form method="POST">
                <input type="hidden" name="action" value="save_company">
                <div class="form-grid form-grid--2">
                    <label>Company name
                        <input type="text" name="company_name" value="<?= h($company['name']) ?>" placeholder="Your company's legal name">
                    </label>
                    <label>Licence number
                        <input type="text" name="company_licence" value="<?= h($company['licence']) ?>" placeholder="Your Medicinal Cannabis Agency licence number">
                    </label>
                </div>
                <label>Site address
                    <textarea name="company_address" rows="3" placeholder="The site shipments leave from"><?= h($company['address']) ?></textarea>
                </label>
                <label>Email for arrival notices
                    <input type="email" name="company_email" value="<?= h($company['email']) ?>" placeholder="For example, dispatch@example.co.nz">
                </label>
                <small class="field-hint">The manifest asks the receiver to send a photo or scan of the signed copy here.</small>
                <div class="form-actions">
                    <button type="submit">Save company details</button>
                </div>
            </form>
        </article>
    </main>
<?php require 'includes/footer.php'; ?>
