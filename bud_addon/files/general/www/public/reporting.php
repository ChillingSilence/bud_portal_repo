<?php
require_once 'config.php';

$pageTitle = 'Reporting';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Reporting</h1>
            <p>Monthly figures for the Medicinal Cannabis Agency, the Section 29 register, and who's buying what.</p>
        </hgroup>

        <section class="hub-section">
            <h2>Reports and registers</h2>
            <div class="card-grid">
                <a class="nav-card" href="reports.php">
                    <span class="nav-card-icon"><?= bud_icon('file-text') ?></span>
                    <span class="nav-card-body">
                        <strong>Reports</strong>
                        <small>Controlled stock in and out each month, the CSV for the Agency, and a 12-month overview</small>
                    </span>
                </a>
                <a class="nav-card" href="s29.php">
                    <span class="nav-card-icon"><?= bud_icon('clipboard-list') ?></span>
                    <span class="nav-card-body">
                        <strong>Section 29 register</strong>
                        <small>Upload the monthly pharmacy files, then search and export the supply records</small>
                    </span>
                </a>
                <a class="nav-card" href="analytics.php">
                    <span class="nav-card-icon"><?= bud_icon('chart-column') ?></span>
                    <span class="nav-card-body">
                        <strong>Analytics</strong>
                        <small>Materials out by month, with product and buyer breakdowns</small>
                    </span>
                </a>
            </div>
        </section>
    </main>
<?php require 'includes/footer.php'; ?>
