# BUD Portal

BUD (Business Utility Dashboard) keeps track of your stock, chain of custody
transfers, destroyed stock and Section 29 supply records, and works out the
monthly figures for the Medicinal Cannabis Agency.

BUD works the same on a PC, a tablet, or a phone through the Home Assistant
app. The screenshots in this guide use made-up demo data.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-dashboard.png" alt="The BUD Dashboard on a PC" width="900">

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/mobile-dashboard.png" alt="The BUD Dashboard on a phone" width="300"> <img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/mobile-menu-open.png" alt="The phone menu" width="300">

## Finding your way around

The menu has four parts, the same as GRACe: Dashboard, Tracking, Reporting
and Administration. On a phone they're under the menu button in the top
right.

### Dashboard

Transfers in progress, transfers to invoice, low-stock items and this
month's completed transfers, each a click away. Underneath is everything at
or under its reorder level.

### Tracking

Day-to-day stock movements.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-tracking.png" alt="The Tracking page" width="900">

**Stock.** What's on hand. Add or remove stock, edit an item and see its
history. Controlled stock can only leave through a chain of custody transfer
or the destruction register. The Bundles button sets up packs made of
several items.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-stock.png" alt="Stock, with low stock flagged" width="900">

**Chain of custody.** Start a transfer (its stock comes off straight away),
say whether your staff or a courier takes it, and print its shipping
manifest to go with it. When it arrives, the receiver signs for it, here on
screen or, for a courier delivery, on the manifest itself. BUD shows you what
it'll save before it saves it. A transfer can be cancelled while it's in
progress, or once it's completed until it's invoiced. Its stock goes back,
and the record is kept, marked as cancelled.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-chain-of-custody.png" alt="Chain of custody, with transfers in progress, completed and cancelled" width="900">

**Destruction register.** Record destroyed stock with the reason, the
method, who destroyed it and a witness signature. Entries can never be
edited or deleted. Export the register as a CSV for the Agency.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-destruction-register.png" alt="The destruction register" width="900">

### Reporting

Monthly figures for the Agency, the Section 29 register, and who's buying
what.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-reporting.png" alt="The Reporting page" width="900">

**Reports.** Controlled stock in and out for the month, the CSV for the
Agency, and a 12-month overview. A bundle counts as the controlled items in
it, and stock a cancelled transfer put back doesn't count as received.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-reports.png" alt="Reports: materials in and out for the month" width="900">

**Section 29 register.** Upload the pharmacies' monthly files (CSV or
Excel). Only the Section 29 record fields are kept (practitioner, patient,
product, quantity, date and place supplied to), and the file itself is
thrown away. Browse, filter and search the register, see the top orders and
export a CSV. Each product's Section 29 details are kept here too.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-section-29-register.png" alt="The Section 29 register, with made-up patients" width="900">

**Analytics.** What went out each month, and who's buying what.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-analytics.png" alt="Analytics charts" width="900">

### Administration

Your company's details, and the suppliers, receivers and bundles that BUD's
forms draw on.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-administration.png" alt="The Administration page" width="900">

**Your company.** Your company's name, licence number, site address and the
email for arrival notices. They're printed on every shipping manifest, so
the receiver and anyone carrying it knows who sent it and where to send the
signed copy.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-your-company.png" alt="Your company's details" width="900">

**Suppliers.** Contact details for the companies you buy from.

**Verified receivers.** The pharmacies and places you send controlled stock
to, with the address for shipping manifests and the monthly report. A
receiver you no longer send to can be retired. It isn't offered for new
transfers, and past transfers keep it, with its address. You can bring it
back at any time.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-verified-receivers.png" alt="Verified receivers, one of them retired" width="900">

**Bundles.** Packs made of several stock items. Sending a bundle on a
transfer takes each of its items out of stock.

### Light or dark

The sun and moon button in the top right switches between the light and dark
themes. BUD remembers your choice on each device.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-dashboard-dark.png" alt="The Dashboard in the dark theme" width="900">

## Shipping manifests and courier deliveries

Every transfer prints a Universal shipping manifest, the one in the security
SOPs, filled in from the transfer. It has your company's details, the
receiver and their address, the controlled drug (cannabis), the items and
quantities, how it travels, and lines for the sender, the courier and the
receiver to sign. Set your company's details first, under Administration →
Your company.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-shipping-manifest.png" alt="A printed Universal shipping manifest for a courier delivery" width="700">

For a courier delivery:

1. Start the transfer with "A courier takes it", the courier company, and
   the tracking number if you have it.
2. Print its shipping manifest and send it with the parcel.
3. The receiver checks it, signs the manifest, and sends a photo or scan of
   it to the email for arrival notices.
4. Complete the transfer with "Signed manifest, uploaded", the name they
   printed, and the photo or scan (JPG, PNG, WebP or PDF, up to 20 MB). A
   big phone photo is made smaller before it uploads.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-new-transfer-courier.png" alt="Starting a transfer that a courier takes" width="900">

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-complete-signed-manifest.png" alt="Completing a courier transfer with the signed manifest" width="900">

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/mobile-chain-of-custody.png" alt="Chain of custody on a phone" width="300"> <img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/mobile-complete-signed-manifest.png" alt="Completing a courier transfer on a phone" width="300">

The signed manifest stays with the transfer. View its record to open it.

<img src="https://raw.githubusercontent.com/Chill-Division/bud_portal_repo/main/docs/screenshots/desktop-transfer-record.png" alt="A transfer's record, with its signed manifest" width="900">

## Admin tools

Undo the last action, download or restore the database, and check stock
against its history. These are hidden so nobody stumbles on them. To open
them, click the version number under "BUD" in the top left three times.

## Your data and backups

All of your data lives in one SQLite database at **`/data/bud.db`**.

`/data` is the app's permanent storage. Home Assistant keeps it through
restarts, updates and rebuilds, and it's only removed if you **uninstall**
the app. BUD refuses to start if `/data` is ever missing, so your data can
never be written somewhere that's wiped on the next update.

- **Home Assistant backups** include `/data`, and so the whole database,
  whenever a backup covers this app. This is the backup to rely on. Make sure
  automatic backups are turned on and copied somewhere off the box.
- **Admin tools** offer a `.db` file download, a JSON export and a restore
  from a `.db` file, for an extra copy or moving BUD to another machine.
- **Signed manifests** uploaded for courier deliveries are kept as files in
  `/data/coc_documents`, beside the database. Home Assistant backups include
  them. The admin tools' `.db` download and JSON export don't.

## Dates and times

BUD shows dates the NZ way (18/11/2026), and every time it stores is NZ time.
Before 1.0.0, times were stored in UTC, 12 or 13 hours behind, so an entry on
the morning of the 1st landed in the previous month. Updating to 1.0.0
converts the stored times once, by itself. A transfer's date and the dates in
Section 29 files were always NZ dates, so they don't change.

## Support

Issues and source: [Project repository](https://github.com/Chill-Division/bud_portal_repo)
