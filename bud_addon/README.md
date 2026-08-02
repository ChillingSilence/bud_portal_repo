# BUD Portal

## Overview
The **BUD Portal** is a comprehensive Business Utility Dashboard for stock management, chain of custody tracking, and destruction records.

**Features:**
-   **Inventory Control**: Track stock levels and suppliers.
-   **Chain of Custody**: Log controlled substance shipments with digital signatures; cancel mistaken transfers with automatic stock restoration.
-   **Destruction Register**: Permanent, MCA-ready record of destroyed stock with witness signatures and CSV export.
-   **Section 29 Register**: Import monthly pharmacy S29 CSVs (only the s29 record fields are kept), browse/search supplies, and export.
-   **Analytics**: Materials-out by month with product and buyer breakdown charts.
-   **Admin Dashboard**: Undo actions, backup/restore database, and export the full database as JSON for debugging.

## Installation
1.  Add the **Chill Division Addons** repository to your Home Assistant Addon Store.
2.  Install the **BUD Portal** addon.
3.  Start the addon.
4.  Open the Web UI.

## Configuration
No manual configuration is required. The addon automatically sets up the database in `/data/bud.db`.

## Versioning
Home Assistant detects addon updates by comparing the `version` field in `config.yaml`. When releasing a new version:
1.  Bump the `version` string in `bud_addon/config.yaml` (e.g., `"0.13.4"` to `"0.13.5"`).
2.  Add a matching entry in `CHANGELOG.md`.
3.  Open a pull request — `main` is protected and direct commits are not allowed. Once CI passes and the PR is merged, Home Assistant will pick up the new version automatically.

## Documentation & Support
For advanced documentation, developer notes, and troubleshooting (including database schema details), please refer to the **[Project Repository](https://github.com/Chill-Division/bud_portal_repo)**.
