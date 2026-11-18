/**
 * print-transfer.js
 *
 * The printed shipping manifest and chain of custody record (custody.php),
 * in the Chill Division look: the logo top left, Inter, grey capital labels,
 * hairlines and NZ dates, on one A4 page.
 *
 * The manifest is the Universal shipping manifest from the security SOPs,
 * filled in from the transfer. It goes with every shipment, and has what the
 * SOPs ask for: the person it's sent to and their address, the controlled
 * drug (cannabis), the quantity, how it travels (our staff, or the courier
 * company), and lines for the sender, the courier and the receiver to sign.
 * The receiver sends a photo or scan of the signed copy back, and it's
 * uploaded to complete the transfer.
 *
 * The record is the same page for a completed transfer, with who received
 * it, when, and their signature (or a note that the signed manifest is kept
 * with the transfer).
 *
 * @param {Object} data the transfer, as stored
 * @param {string} kind 'manifest' or 'record'
 * @param {Function} resolveItemName item id to its name
 * @param {Object} receiverMap receiver id to its name, contact, address and licence
 * @param {Object} company your company's name, licence, address and email
 */
function printTransfer(data, kind, resolveItemName, receiverMap, company) {
    const isRecord = kind === 'record';
    const receiver = receiverMap[data.receiver_id] || {};
    const firm = company || {};
    const courier = data.delivery_method === 'courier';
    let items = [];
    try {
        items = JSON.parse(data.coc_items) || [];
    } catch (e) { /* a damaged record prints with no items */ }

    const asset = (path) => new URL(path, document.baseURI).href;
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const printed = pad(now.getDate()) + '/' + pad(now.getMonth() + 1) + '/' + now.getFullYear() + ' at ' + pad(now.getHours()) + ':' + pad(now.getMinutes());
    const title = isRecord ? 'Chain of custody record' : 'Universal shipping manifest';

    // A value, or a line to write it on by hand
    const fill = (value) => value && String(value).trim() !== ''
        ? escapeHtml(value).replace(/\n/g, '<br>')
        : '<span class="blank"></span>';
    const row = (label, value) => '<div class="row"><span class="label">' + escapeHtml(label) + '</span><span class="value">' + fill(value) + '</span></div>';

    const total = items.reduce((sum, i) => sum + (Number(i.qty) || 0), 0);
    const itemRows = items.map(i => '<tr><td>' + escapeHtml(resolveItemName(i.item_id)) + '</td><td>' + escapeHtml(i.batch || '-') + '</td><td class="num">' + escapeHtml(formatQty(i.qty)) + '</td></tr>').join('');

    const sending = row('Company', firm.name) +
        row('Licence number', firm.licence) +
        row('Site address', firm.address) +
        row('Sent from', data.origin) +
        row('Arrival notices to', firm.email);
    const receiving = row('Company', data.destination) +
        row('Licence number', receiver.licence) +
        row('Attention', receiver.contact) +
        row('Address', receiver.address);

    const delivery = courier
        ? row('Method', 'Courier, ' + (data.courier_company || '')) + row('Tracking number', data.tracking_number)
        : row('Method', 'Our staff, ' + (data.transported_by || ''));

    // Signature blocks: sender, courier (if a courier takes it), receiver
    const line = (label, value) => '<div class="sign-line"><span>' + escapeHtml(label) + '</span><div class="ink">' + (value ? escapeHtml(value) : '') + '</div></div>';
    const blocks = [];
    // The record shows what BUD knows. The manifest has lines to fill in.
    blocks.push('<div class="block"><h3>Sent by</h3>' +
        line('Name', data.transported_by) +
        (isRecord ? line('Date sent', formatDate(data.form_date)) : line('Signature', '') + line('Date and time', '')) + '</div>');
    if (courier && !isRecord) {
        blocks.push('<div class="block"><h3>Collected by the courier</h3>' +
            line('Name', '') +
            line('Signature or tracking number', '') +
            line('Date and time', '') +
            line('Items collected', '') + '</div>');
    }
    if (isRecord && data.status === 'Completed') {
        let signed = '';
        if (data.signature_image) {
            signed = '<div class="sign-line"><span>Signature</span><img class="sig" src="' + escapeHtml(data.signature_image) + '" alt=""></div>';
        } else if (data.signed_document) {
            signed = '<div class="sign-line"><span>Signature</span><div class="note">On the signed manifest kept with this transfer in BUD</div></div>';
        }
        blocks.push('<div class="block"><h3>Received by</h3>' +
            line('Name', data.received_by) + signed +
            line('Date and time', formatDateTime(data.completed_at)) + '</div>');
    } else {
        blocks.push('<div class="block"><h3>Received by</h3>' +
            line('Name', '') +
            line('Signature', '') +
            line('Date and time', '') +
            line('Items received', '') +
            line('Weight, net / gross', '') + '</div>');
    }

    let status = '';
    if (data.status === 'Cancelled') {
        status = 'Cancelled' + (data.cancelled_at ? ' on ' + formatDateTime(data.cancelled_at) : '') + '. ' + (data.cancel_reason || '') + ' The stock was put back.';
    } else if (isRecord && data.status !== 'Completed') {
        status = 'In progress, waiting for the receiver to sign for it.';
    }

    const instructions = isRecord ? '' : '<p class="instructions">When it arrives, check the items and weight against this manifest, then sign it and send a photo or scan of this page to <strong>' +
        (firm.email ? escapeHtml(firm.email) : 'the sender') + '</strong>.</p>';

    const doc = `<!doctype html>
<html lang="en-NZ">
<head>
<meta charset="utf-8">
<meta name="color-scheme" content="light">
<title>${escapeHtml(title)} #${escapeHtml(data.id)}</title>
<style>
@font-face { font-family: "Inter"; font-weight: 100 900; src: url("${asset('fonts/inter/inter-latin-wght-normal.woff2')}") format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
@font-face { font-family: "Inter"; font-weight: 100 900; src: url("${asset('fonts/inter/inter-latin-ext-wght-normal.woff2')}") format("woff2"); unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+1E00-1E9F; }
@page { size: A4; margin: 13mm 14mm; }
* { box-sizing: border-box; }
html { background: #ffffff; color-scheme: light; }
body { margin: 0; background: #ffffff; font-family: "Inter", Arial, sans-serif; font-size: 9pt; line-height: 1.35; color: #2c2c2a; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
@media screen { body { max-width: 182mm; margin: 0 auto; padding: 13mm 0; } }
.top { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 4mm; margin-bottom: 4mm; border-bottom: 1px solid #0d0d0d; }
.top img { height: 12mm; }
.top .doc { text-align: right; }
h1 { margin: 0 0 1mm; font-size: 17pt; font-weight: 800; letter-spacing: -0.02em; color: #0d0d0d; }
.top .doc p { margin: 0; color: #64645f; }
h2 { margin: 0 0 2mm; font-size: 7.5pt; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: #64645f; }
h3 { margin: 0 0 2.5mm; font-size: 9.5pt; font-weight: 700; color: #0d0d0d; }
.parties { display: grid; grid-template-columns: 1fr 1fr; gap: 8mm; margin-bottom: 4mm; }
.parties section + section { margin-top: 4mm; }
.row { display: grid; grid-template-columns: 28mm 1fr; gap: 3mm; padding: 1.1mm 0; border-bottom: 1px solid #e4e3de; }
.label { color: #64645f; }
.value { color: #0d0d0d; }
.blank { display: inline-block; width: 100%; height: 4.5mm; border-bottom: 1px solid #9a9a93; }
section { margin-bottom: 4mm; break-inside: avoid; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 7.5pt; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #64645f; padding: 1.5mm 2mm; border-bottom: 1px solid #0d0d0d; }
td { padding: 1.6mm 2mm; border-bottom: 1px solid #e4e3de; color: #0d0d0d; }
tfoot td { font-weight: 700; border-bottom: 0; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.drug { margin: 0 0 1.5mm; font-weight: 600; color: #0d0d0d; }
.weight { display: grid; grid-template-columns: 28mm 1fr 1fr; gap: 3mm; align-items: end; margin-top: 2mm; }
.weight .label { padding-bottom: 0.5mm; }
.weight .blank { width: 100%; }
.weight small { display: block; font-size: 7pt; color: #64645f; }
.blocks { display: grid; grid-template-columns: repeat(${blocks.length}, 1fr); gap: 5mm; }
.block { padding: 3mm; border: 1px solid #e4e3de; border-radius: 3mm; }
.sign-line { margin-bottom: 2mm; }
.sign-line:last-child { margin-bottom: 0; }
.sign-line span { display: block; font-size: 7pt; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #64645f; }
.sign-line .ink { min-height: 7mm; padding-top: 1.8mm; border-bottom: 1px solid #0d0d0d; color: #0d0d0d; }
.sign-line .sig { display: block; max-width: 100%; max-height: 18mm; margin-top: 1mm; border-bottom: 1px solid #0d0d0d; }
.sign-line .note { padding-top: 1.5mm; color: #2c2c2a; }
.instructions { margin: 4mm 0 0; padding: 2.5mm 4mm; border-left: 3px solid #2f6b45; background: #f5f5f2; }
.status { margin: 0 0 4mm; padding: 2.5mm 4mm; border-left: 3px solid #b4541a; background: #f5f5f2; }
.foot { margin-top: 4mm; padding-top: 2mm; border-top: 1px solid #e4e3de; font-size: 7.5pt; color: #64645f; display: flex; justify-content: space-between; }
</style>
</head>
<body>
<div class="top">
<img src="${asset('icons/chill-division-logo.png')}" alt="Chill Division">
<div class="doc"><h1>${escapeHtml(title)}</h1><p>Chain of custody transfer #${escapeHtml(data.id)}, sent ${escapeHtml(formatDate(data.form_date))}</p></div>
</div>
${status ? '<p class="status">' + escapeHtml(status) + '</p>' : ''}
<div class="parties">
<div><section><h2>Sending party</h2>${sending}</section></div>
<div><section><h2>Receiving party</h2>${receiving}</section><section><h2>Delivery</h2>${delivery}</section></div>
</div>
<section>
<h2>Contents</h2>
<p class="drug">Controlled drug: cannabis</p>
<table>
<thead><tr><th>Product</th><th>Batch or lot</th><th class="num">Quantity</th></tr></thead>
<tbody>${itemRows}</tbody>
<tfoot><tr><td colspan="2">Items sent</td><td class="num">${escapeHtml(formatQty(total))}</td></tr></tfoot>
</table>
${isRecord ? '' : '<div class="weight"><span class="label">Shipment weight</span><span><span class="blank"></span><small>Net</small></span><span><span class="blank"></span><small>Gross</small></span></div>'}
</section>
<section>
<h2>Chain of custody</h2>
<div class="blocks">${blocks.join('')}</div>
${instructions}
</section>
<div class="foot"><span>Printed from BUD on ${escapeHtml(printed)}</span><span>Transfer #${escapeHtml(data.id)}</span></div>
<script>
window.addEventListener('load', function () {
    (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () { window.print(); });
});
<\/script>
</body>
</html>`;

    const win = window.open('', '_blank');
    if (!win) {
        showToast('Your browser blocked the print window. Allow pop-ups for BUD and try again.', 'error');
        return;
    }
    win.document.write(doc);
    win.document.close();
}
