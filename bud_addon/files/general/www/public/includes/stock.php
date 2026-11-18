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

/**
 * Is this stock item or bundle in use? A retired one (is_active 0, 1.1.0)
 * isn't offered for anything new, and is never deleted: history, reports
 * and past transfers keep it.
 */
function bud_is_active(array $row)
{
    return (int) ($row['is_active'] ?? 1) === 1;
}

/** "A", "A and B", "A, B and C" */
function bud_and_list(array $names)
{
    $names = array_values($names);
    if (count($names) < 2) {
        return (string) ($names[0] ?? '');
    }
    $last = array_pop($names);
    return implode(', ', $names) . ' and ' . $last;
}

/**
 * The bundles still offered on New transfer that each stock item is in, as
 * [stock item id => [bundle names]]. One query for the whole Stock page.
 */
function bud_active_bundle_names(PDO $pdo)
{
    $map = [];
    $rows = $pdo->query("
        SELECT DISTINCT bi.stock_item_id, pb.name
        FROM bundle_items bi
        JOIN product_bundles pb ON pb.id = bi.bundle_id
        WHERE pb.is_active = 1
        ORDER BY pb.name ASC
    ")->fetchAll();
    foreach ($rows as $row) {
        $map[(int) $row['stock_item_id']][] = $row['name'];
    }
    return $map;
}

/**
 * Why a stock item can't be retired yet, or '' when it can. Controlled stock
 * has to be at zero first, so it never drops out of sight while it's on
 * site. An item in a bundle that's still offered can't go either, or sending
 * that bundle would take stock from a retired item.
 *
 * @param array $bundleNames this item's active bundles (bud_active_bundle_names)
 */
function bud_retire_blocker(array $item, array $bundleNames)
{
    if ((int) $item['is_controlled'] === 1 && abs((float) $item['quantity']) >= 0.005) {
        $onHand = trim(bud_qty($item['quantity']) . ' ' . $item['unit']);
        return $item['name'] . ' still has ' . $onHand . ' on hand. Controlled stock can only be retired at zero, so send it on a transfer or record it in the destruction register first.';
    }
    if (count($bundleNames) === 1) {
        return $item['name'] . ' is in the bundle ' . $bundleNames[0] . '. Retire the bundle first, or take the item out of it.';
    }
    if ($bundleNames) {
        return $item['name'] . ' is in ' . count($bundleNames) . ' bundles, ' . bud_and_list($bundleNames) . '. Retire them first, or take the item out of them.';
    }
    return '';
}

/**
 * Why a retired bundle can't come back yet, or '' when it can: every item
 * in it has to be in use, or sending it would take stock from a retired one.
 */
function bud_bundle_restore_blocker(PDO $pdo, array $bundle)
{
    $stmt = $pdo->prepare("
        SELECT si.name
        FROM bundle_items bi
        JOIN stock_items si ON si.id = bi.stock_item_id
        WHERE bi.bundle_id = ? AND si.is_active = 0
        ORDER BY si.name ASC
    ");
    $stmt->execute([$bundle['id']]);
    $retired = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt = null;
    if (!$retired) {
        return '';
    }
    $what = count($retired) === 1 ? 'a retired item in it, ' : 'retired items in it, ';
    $them = count($retired) === 1 ? 'it' : 'them';
    return $bundle['name'] . ' has ' . $what . bud_and_list($retired) . '. Bring ' . $them . ' back on the Stock page first.';
}

/**
 * Checks a new transfer's lines before any stock comes off. Returns why one
 * can't be sent (a retired item or bundle, or a bundle with a retired item
 * in it), or '' when they're all in use. Covers a form opened before
 * something was retired.
 */
function bud_retired_transfer_line(PDO $pdo, array $itemIds)
{
    $item = $pdo->prepare("SELECT name, is_active FROM stock_items WHERE id = ?");
    $bundle = $pdo->prepare("SELECT id, name, is_active FROM product_bundles WHERE id = ?");
    foreach ($itemIds as $itemId) {
        $itemId = (string) $itemId;
        if ($itemId === '') {
            continue;
        }
        if (strpos($itemId, 'bundle_') === 0) {
            $bundle->execute([(int) substr($itemId, 7)]);
            $row = $bundle->fetch();
            $bundle->closeCursor();
            if (!$row) {
                continue;
            }
            if (!bud_is_active($row)) {
                return 'The bundle ' . $row['name'] . ' is retired. Bring it back on the Bundles page first.';
            }
            $blocker = bud_bundle_restore_blocker($pdo, $row);
            if ($blocker !== '') {
                return $blocker;
            }
        } else {
            $item->execute([(int) $itemId]);
            $row = $item->fetch();
            $item->closeCursor();
            if ($row && !bud_is_active($row)) {
                return $row['name'] . ' is retired. Bring it back on the Stock page first.';
            }
        }
    }
    return '';
}
