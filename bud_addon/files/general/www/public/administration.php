<?php
require_once 'config.php';

$pageTitle = 'Administration';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Administration</h1>
            <p>Your company's details, and the suppliers, receivers and bundles that BUD's forms draw on.</p>
        </hgroup>

        <section class="hub-section">
            <h2>Contacts and products</h2>
            <div class="card-grid">
                <a class="nav-card" href="company.php">
                    <span class="nav-card-icon"><?= bud_icon('building') ?></span>
                    <span class="nav-card-body">
                        <strong>Your company</strong>
                        <small>Your name, licence number and address, printed on every shipping manifest</small>
                    </span>
                </a>
                <a class="nav-card" href="suppliers.php">
                    <span class="nav-card-icon"><?= bud_icon('factory') ?></span>
                    <span class="nav-card-body">
                        <strong>Suppliers</strong>
                        <small>Contact details for the companies you buy from</small>
                    </span>
                </a>
                <a class="nav-card" href="receivers.php">
                    <span class="nav-card-icon"><?= bud_icon('store') ?></span>
                    <span class="nav-card-body">
                        <strong>Verified receivers</strong>
                        <small>The pharmacies and places you send to, with the address for shipping manifests</small>
                    </span>
                </a>
                <a class="nav-card" href="bundles.php">
                    <span class="nav-card-icon"><?= bud_icon('boxes') ?></span>
                    <span class="nav-card-body">
                        <strong>Bundles</strong>
                        <small>Packs made of several stock items, sent as one line on a transfer</small>
                    </span>
                </a>
            </div>
        </section>
    </main>
<?php require 'includes/footer.php'; ?>
