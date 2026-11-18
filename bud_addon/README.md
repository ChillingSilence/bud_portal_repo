# BUD Portal

BUD (Business Utility Dashboard) keeps track of your stock, chain of custody
transfers, destroyed stock and Section 29 supply records, inside Home
Assistant. Made by [Chill Division](https://www.chilldivision.co.nz).

- **Stock.** What's on hand, with every addition and removal in each item's
  history. Controlled stock only leaves through a transfer or the
  destruction register. Retire what you no longer use.
- **Chain of custody.** Transfers with a shipping manifest, signed for on
  screen or, for a courier delivery, on the manifest itself and uploaded. A
  mistake can be cancelled, and its stock goes back.
- **Destruction register.** A permanent record of destroyed stock, with a
  witness signature and a CSV export.
- **Section 29 register.** Upload the pharmacies' monthly files. Only the
  Section 29 record fields are kept, and the file is thrown away.
- **Reports and analytics.** Controlled stock in and out each month for the
  Medicinal Cannabis Agency, and who's buying what.

## Installing

Home Assistant has called add-ons "apps" since 2026.2, so on an older Home
Assistant read "Add-ons" wherever these steps say "Apps".

1. Add this repository to Home Assistant. The quickest way is
   [this link](https://my.home-assistant.io/redirect/supervisor_add_addon_repository/?repository_url=https%3A%2F%2Fgithub.com%2FChill-Division%2Fbud_portal_repo).
   Or go to **Settings → Apps**, open the app store, choose
   **Repositories** from the 3-dot menu and add
   `https://github.com/Chill-Division/bud_portal_repo`.
2. Install **BUD Portal**, turn on **Show in sidebar**, and start it.
3. Open it. BUD sets up its own database, so there's nothing to configure.

## Your data

Everything BUD records lives in one database in the app's own storage
(`/data/bud.db`). It survives updates and is included in your Home Assistant
backups. The Documentation tab has more, and the
[project repository](https://github.com/Chill-Division/bud_portal_repo) has
the developer notes.
