<?php
// includes/nav.php
// The app bar: BUD's name and version, "by Chill Division", and the menu.
// Each menu item is a section, lit up on its own page and every page under it.

$navCurrent = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
$navSections = [
    'index.php' => [
        'label' => 'Dashboard',
        'pages' => ['index'],
    ],
    'tracking.php' => [
        'label' => 'Tracking',
        'pages' => ['tracking', 'stock', 'custody', 'destruction'],
    ],
    'reporting.php' => [
        'label' => 'Reporting',
        'pages' => ['reporting', 'reports', 's29', 'analytics'],
    ],
    'administration.php' => [
        'label' => 'Administration',
        'pages' => ['administration', 'company', 'suppliers', 'receivers', 'bundles', 'admin'],
    ],
];
?>
        <nav class="app-nav">
            <a class="nav-brand nav-brand-home" href="index.php">
                <span class="nav-brand-mark"><?= bud_icon('package') ?></span>
                <span class="nav-brand-text">
                    <strong><?= h(APP_NAME) ?></strong>
                    <?php // Clicking the version three times opens the admin tools (js/bud.js) ?>
                    <span class="nav-brand-version" data-admin-trigger>v<?= h(BUD_VERSION) ?></span>
                </span>
                <span class="nav-brand-maker">
                    <span class="nav-brand-by">by</span>
                    <img class="nav-maker-logo" src="icons/chill-division-logo.png?v=<?= h(BUD_VERSION) ?>" alt="Chill Division" width="235" height="120">
                </span>
            </a>
            <input type="checkbox" id="nav-toggle" class="nav-toggle">
            <label for="nav-toggle" class="nav-toggle-label" aria-label="Open or close the menu">
                <span class="hamburger"></span>
            </label>
            <ul class="nav-menu">
                <?php foreach ($navSections as $href => $section):
                    $isActive = in_array($navCurrent, $section['pages'], true); ?>
                <li><a href="<?= $href ?>"<?= $isActive ? ' class="active" aria-current="page"' : '' ?>><?= $section['label'] ?></a></li>
                <?php endforeach; unset($href, $section, $isActive); // loop variables must not leak into the page ?>
                <li><a href="#" id="theme_switcher" class="theme-toggle" role="button" aria-label="Switch between light and dark"></a></li>
            </ul>
        </nav>
