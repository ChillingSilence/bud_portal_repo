<?php
require_once 'config.php';

$pageTitle = 'Tracking';
require 'includes/header.php';
?>
    <main class="container">
        <hgroup class="page-header">
            <h1>Tracking</h1>
            <p>Day-to-day stock movements. Every change to your stock starts here.</p>
        </hgroup>

        <section class="hub-section">
            <h2>Stock movements</h2>
            <div class="card-grid">
                <a class="nav-card" href="stock.php">
                    <span class="nav-card-icon"><?= bud_icon('package') ?></span>
                    <span class="nav-card-body">
                        <strong>Stock</strong>
                        <small>What's on hand, adding and removing stock, and each item's history</small>
                    </span>
                </a>
                <a class="nav-card" href="custody.php">
                    <span class="nav-card-icon"><?= bud_icon('truck') ?></span>
                    <span class="nav-card-body">
                        <strong>Chain of custody</strong>
                        <small>Start a transfer, print its shipping manifest, and have it signed for when it arrives</small>
                    </span>
                </a>
                <a class="nav-card" href="destruction.php">
                    <span class="nav-card-icon"><?= bud_icon('flame') ?></span>
                    <span class="nav-card-body">
                        <strong>Destruction register</strong>
                        <small>Record destroyed stock with a witness, and export the register</small>
                    </span>
                </a>
            </div>
        </section>
    </main>
<?php require 'includes/footer.php'; ?>
