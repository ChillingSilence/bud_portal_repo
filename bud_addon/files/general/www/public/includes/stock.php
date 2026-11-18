<?php
// includes/stock.php
// Stock rules shared by the Dashboard and the Stock page.

/**
 * An item is low when it has a reorder level and is at or under it. An item
 * with no reorder level (left blank) is never low.
 */
function bud_is_low_stock(array $item)
{
    $level = $item['reorder_level'] ?? null;
    if ($level === null || $level === '' || !is_numeric($level)) {
        return false;
    }
    return (float) $item['quantity'] <= (float) $level;
}

/**
 * Was this stock change the stock a cancelled transfer put back? It isn't
 * stock received, so the monthly report's Materials in leaves it out.
 * From 1.0.0 the change is tagged 'movement' => 'transfer_cancelled'. BUD
 * 0.15 to 0.19 only left a note, "Restored via Cancelled COC #12" or
 * "Restored via Bundle: Retail pack (Cancelled COC #12)".
 */
function bud_is_cancel_restore($new_values)
{
    if (!is_array($new_values)) {
        return false;
    }
    if (($new_values['movement'] ?? '') === 'transfer_cancelled') {
        return true;
    }
    $notes = (string) ($new_values['adjustment_notes'] ?? '');
    return (bool) preg_match('/^Restored via (Cancelled COC #\d+|Bundle: .*\(Cancelled COC #\d+\))$/s', $notes);
}
