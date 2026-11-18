<?php
/**
 * Shared page header: <head>, the app bar and the menu.
 *
 * Set these before including (config.php must already be loaded):
 *   $pageTitle  the page's name, shown in the browser tab as "BUD - Stock".
 *               It matches the page's heading and the card that opens it.
 *   $extraHead  optional, more tags for <head> (Analytics loads Chart.js)
 */
require_once __DIR__ . '/format.php';
require_once __DIR__ . '/icons.php';

$pageTitle = $pageTitle ?? 'Dashboard';
$assetVersion = defined('BUD_VERSION') ? BUD_VERSION : '0';
?>
<!doctype html>
<html lang="en-NZ" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238b5cf6' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z'/%3E%3Cpath d='M12 22V12'/%3E%3Cpath d='m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7'/%3E%3Cpath d='m7.5 4.27 9 5.15'/%3E%3C/svg%3E">
    <script>
        // Apply the saved theme before first paint, so the page doesn't flash
        // the wrong one. Light is the default. The sun/moon button saves the
        // choice as 'bud-theme' (every app opened through Home Assistant
        // shares one storage, so the key carries BUD's name).
        (function () {
            try {
                var theme = localStorage.getItem('bud-theme');
                if (theme !== 'dark' && theme !== 'light') {
                    // BUD 0.x saved the choice as 'theme': carry it over once
                    var old = localStorage.getItem('theme');
                    if (old === 'dark' || old === 'light') {
                        theme = old;
                        localStorage.setItem('bud-theme', old);
                    }
                }
                document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
            } catch (e) { /* stay on the default light theme */ }
        })();
    </script>
    <!-- Pico, Inter, Chart.js and the scripts are served by the app itself, so BUD works on a Home Assistant box with no internet -->
    <link rel="stylesheet" href="css/vendor/pico.min.css?v=<?= h($assetVersion) ?>">
    <link rel="stylesheet" href="css/bud.css?v=<?= h($assetVersion) ?>">
    <script src="js/bud.js?v=<?= h($assetVersion) ?>"></script>
    <?= $extraHead ?? '' ?>
    <title><?= h(APP_NAME . ' - ' . $pageTitle) ?></title>
</head>
<body>
    <header class="app-header">
        <?php require __DIR__ . '/nav.php'; ?>
    </header>
