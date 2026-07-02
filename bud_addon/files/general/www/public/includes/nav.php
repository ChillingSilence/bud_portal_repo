<?php
// includes/nav.php

$current_page = basename($_SERVER['PHP_SELF']);

function isActive($page, $current)
{
    return $page === $current ? 'active' : '';
}
?>

<nav class="glass-nav">
    <div class="nav-container">
        <div class="logo">
            <a href="index.php" style="color: inherit; text-decoration: none;">BUD</a><span id="secret-dot"
                style="cursor: default; user-select: none;">.</span>
        </div>

        <ul id="navMenu">
            <li><a href="index.php" class="<?= isActive('index.php', $current_page) ?>">Dashboard</a></li>
            <li><a href="suppliers.php" class="<?= isActive('suppliers.php', $current_page) ?>">Suppliers</a></li>
            <li><a href="stock.php" class="<?= isActive('stock.php', $current_page) ?>">Stock</a></li>
            <li><a href="custody.php" class="<?= isActive('custody.php', $current_page) ?>">Chain of Custody</a></li>
            <li><a href="scheduling.php" class="<?= isActive('scheduling.php', $current_page) ?>">Scheduling</a></li>
            <li><a href=" reports.php" class="<?= isActive('reports.php', $current_page) ?>">Reports</a></li>
            <li><a href="analytics.php" class="<?= isActive('analytics.php', $current_page) ?>">Analytics</a></li>
            <li>
                <button id="theme-toggle"
                    style="padding: 0.5rem; background: transparent; border: 1px solid var(--text-color); color: var(--text-color);">
                    <span class="icon"></span>
                </button>
            </li>
        </ul>

        <!-- Mobile Toggle Button -->
        <button class="mobile-toggle" id="navToggle"
            style="background: transparent; color: var(--text-color); font-size: 1.5rem; border: none; cursor: pointer;">
            ☰
        </button>
    </div>
</nav>

<script>
    // Theme Toggle Logic
    const themeBtn = document.getElementById('theme-toggle');
    const icon = themeBtn.querySelector('.icon');
    const html = document.documentElement;

    // Check local storage
    const savedTheme = localStorage.getItem('theme') || 'dark';
    html.setAttribute('data-theme', savedTheme);
    updateIcon(savedTheme);

    themeBtn.addEventListener('click', () => {
        const current = html.getAttribute('data-theme');
        const newTheme = current === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateIcon(newTheme);
    });

    function updateIcon(theme) {
        icon.textContent = theme === 'dark' ? '🌙' : '☀️';
    }

    // Mobile Menu Toggle
    const mobileToggle = document.getElementById('navToggle'); // Changed to navToggle as per original HTML
    const navMenu = document.getElementById('navMenu'); // Changed to navMenu as per original HTML

    if (mobileToggle) {
        mobileToggle.addEventListener('click', () => {
            // Toggle display for mobile menu
            if (navMenu.style.display === 'flex') {
                navMenu.style.display = ''; // Revert to CSS default
            } else {
                navMenu.style.display = 'flex';
                navMenu.style.flexDirection = 'column';
                navMenu.style.position = 'absolute';
                navMenu.style.top = '60px';
                navMenu.style.left = '0';
                navMenu.style.right = '0';
                navMenu.style.background = 'var(--nav-bg)';
                navMenu.style.padding = '1rem';
                navMenu.style.zIndex = '100';
            }
        });
    }

    // SECRET ADMIN TRIGGER
    // Click the dot 3 times to go to admin
    const dot = document.getElementById('secret-dot');
    let clicks = 0;

    if (dot) {
        dot.addEventListener('click', (e) => {
            e.preventDefault();
            clicks++;

            if (clicks === 3) {
                clicks = 0;
                window.location.href = 'admin.php';
            }

            // Reset clicks if idle
            setTimeout(() => { clicks = 0; }, 2000);
        });
    }
</script>