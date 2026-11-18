<?php
// includes/format.php
// How BUD shows dates, times and quantities on screen: the NZ way, the same
// as GRACe. Stored values stay as they are (2026-11-03, 2026-11-03 14:10:00),
// and CSV exports keep the stored format.

/**
 * The time now in NZ, for stamping records ("2026-11-18 16:02:22").
 * config.php sets PHP's time zone to Pacific/Auckland. Never use SQLite's
 * CURRENT_TIMESTAMP or DATETIME('now'): they're UTC, 12 or 13 hours behind.
 */
function bud_now()
{
    return date('Y-m-d H:i:s');
}

/** A stored date or date and time as 03/11/2026. */
function bud_date($value)
{
    $value = (string) $value;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
        return "$m[3]/$m[2]/$m[1]";
    }
    return $value;
}

/** A stored date and time as 03/11/2026 14:10 (a plain date stays a date). */
function bud_datetime($value)
{
    $value = (string) $value;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $value, $m)) {
        return "$m[3]/$m[2]/$m[1]" . (isset($m[4]) ? " $m[4]:$m[5]" : '');
    }
    return $value;
}

/** "2026-11" as "November 2026". */
function bud_month_label($ym)
{
    $time = strtotime($ym . '-01');
    return $time ? date('F Y', $time) : (string) $ym;
}

/**
 * A quantity with thousands separators and no trailing zeros: 1,150 or 2.5.
 * Negative numbers get a real minus sign (−), which lines up with the digits
 * in Inter's tabular figures. A hyphen there reads as "- 20". CSV exports
 * keep the stored values, hyphen and all.
 */
function bud_qty($value)
{
    $number = (float) $value;
    $text = number_format(abs($number), 2, '.', ',');
    $text = rtrim(rtrim($text, '0'), '.');
    if ($text === '0') {
        return '0';
    }
    return ($number < 0 ? "\u{2212}" : '') . $text;
}

/** The minus sign for quantities going out, to sit in front of bud_qty() */
const BUD_MINUS = "\u{2212}";

/** A plural for counts: bud_plural(1, 'item') is "1 item", 2 is "2 items". */
function bud_plural($count, $word, $plural = null)
{
    return $count . ' ' . ($count == 1 ? $word : ($plural ?? $word . 's'));
}

/** The status message box under a page's heading. */
function bud_message($message, $isError = false)
{
    if ($message === '' || $message === null) {
        return '';
    }
    $class = $isError ? 'error' : 'success';
    $role = $isError ? 'alert' : 'status';
    return '<div class="status-message ' . $class . '" role="' . $role . '">' . h($message) . '</div>';
}
