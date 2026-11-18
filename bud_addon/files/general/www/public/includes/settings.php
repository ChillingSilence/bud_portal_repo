<?php
// includes/settings.php
// BUD's settings, kept in the settings table (name and value). For now that's
// your company's details, printed on every shipping manifest.

const BUD_COMPANY_FIELDS = ['company_name', 'company_licence', 'company_address', 'company_email'];

/** One setting's value, or $default when it hasn't been set. */
function bud_setting(PDO $pdo, $name, $default = '')
{
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = ?");
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();
    $stmt = null;
    return $value === false || $value === null ? $default : $value;
}

/** Save one setting. */
function bud_set_setting(PDO $pdo, $name, $value)
{
    $pdo->prepare("INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)
        ON CONFLICT(name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
        ->execute([$name, $value, bud_now()]);
}

/** Your company's details: name, licence, address and email (blank when unset). */
function bud_company(PDO $pdo)
{
    $company = [];
    foreach (BUD_COMPANY_FIELDS as $field) {
        $company[substr($field, strlen('company_'))] = bud_setting($pdo, $field);
    }
    return $company;
}
