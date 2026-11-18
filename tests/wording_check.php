<?php
/**
 * Wording and consistency check (1.0.0), run by tests/wording_test.sh against
 * a running BUD. The Chill Division brand guide, as GRACe applies it:
 *
 *   - every page's browser tab is "BUD - " and its heading
 *   - every menu item and every card on Tracking, Reporting and
 *     Administration has the same name as the page it opens
 *   - sentence case for headings, buttons, labels, table headers and cards
 *     (names keep their capitals: BUD, S29, CSV, the Medicinal Cannabis Agency)
 *   - no em or en dashes, no semicolons and no emoji in anything people read
 *   - nothing loads from the internet (BUD has to work on a Home Assistant
 *     box with no connection)
 *
 * Usage: php tests/wording_check.php http://127.0.0.1:8095
 */

$base = rtrim($argv[1] ?? '', '/');
if ($base === '') {
    fwrite(STDERR, "Usage: php wording_check.php BASE_URL\n");
    exit(2);
}

$pages = [
    'index.php', 'tracking.php', 'reporting.php', 'administration.php',
    'stock.php', 'custody.php', 'destruction.php',
    'reports.php', 's29.php', 'analytics.php',
    'company.php', 'suppliers.php', 'receivers.php', 'bundles.php', 'admin.php',
];

// Words that keep their capitals in the middle of a heading or label
$names = [
    'BUD', 'S29', 'CSV', 'SKU', 'INN', 'NZ', 'JSON', 'Section', 'Medicinal', 'Cannabis',
    'Agency', 'Medicines', 'Act', 'Chill', 'Division', 'Home', 'Assistant', 'GRACe', 'Excel',
    'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August',
    'September', 'October', 'November', 'December',
    'Jan', 'Feb', 'Mar', 'Apr', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

$fail = 0;
$docs = [];

function fail_with($message)
{
    global $fail;
    fwrite(STDERR, "FAIL: $message\n");
    $fail = 1;
}

function load_page($url)
{
    $html = @file_get_contents($url);
    if ($html === false) {
        return null;
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    return $doc;
}

function text_of($node)
{
    return trim(preg_replace('/\s+/u', ' ', $node->textContent));
}

// The words of an element's own text, skipping nested badges, small print and
// values (a name in a heading is data, not wording)
function own_words($node)
{
    $text = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $text .= ' ' . $child->nodeValue;
        } elseif ($child->nodeType === XML_ELEMENT_NODE && in_array($child->nodeName, ['strong', 'em', 'span'], true)
            && !preg_match('/\b(badge|nav-brand)/', $child->getAttribute('class'))) {
            $text .= ' ' . $child->textContent;
        }
    }
    return trim(preg_replace('/\s+/u', ' ', $text));
}

foreach ($pages as $page) {
    $doc = load_page("$base/$page");
    if (!$doc) {
        fail_with("$page didn't load");
        continue;
    }
    $docs[$page] = $doc;
    $xp = new DOMXPath($doc);

    // Tab title and heading
    $title = text_of($xp->query('//title')->item(0));
    $h1 = $xp->query('//h1')->item(0);
    $h1 = $h1 ? text_of($h1) : '';
    if ($title !== "BUD - $h1") {
        fail_with("$page: the tab says '$title' but the heading is '$h1'");
    }

    // Everything people read: text, and the attributes that show on screen
    $visible = [];
    foreach ($xp->query('//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::title)]') as $t) {
        $visible[] = $t->nodeValue;
    }
    foreach ($xp->query('//@placeholder | //@aria-label | //@alt | //@data-confirm-title | //@data-confirm-message | //@data-confirm-label | //@label') as $a) {
        $visible[] = $a->nodeValue;
    }
    // Pop-ups built in JavaScript (confirmAction titles and messages)
    foreach ($xp->query('//script[not(@type)]') as $s) {
        if (preg_match_all("/(?:title|message|confirmLabel):\s*'((?:[^'\\\\]|\\\\.)*)'/", $s->textContent, $m)) {
            foreach ($m[1] as $msg) {
                $visible[] = stripcslashes($msg);
            }
        }
    }
    $all = implode("\n", $visible);
    if (preg_match('/[\x{2014}\x{2013}]/u', $all, $m)) {
        fail_with("$page has an em or en dash");
    }
    if (preg_match('/;/', $all)) {
        foreach ($visible as $v) {
            if (strpos($v, ';') !== false) {
                fail_with("$page has a semicolon: " . trim(substr($v, 0, 80)));
                break;
            }
        }
    }
    if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', $all, $m)) {
        fail_with("$page has an emoji (" . $m[0] . ")");
    }

    // Nothing from the internet
    foreach ($xp->query('//*[@src] | //link[@href]') as $el) {
        $url = $el->getAttribute('src') ?: $el->getAttribute('href');
        if (preg_match('#^(https?:)?//#i', $url)) {
            fail_with("$page loads $url from the internet");
        }
    }

    // Sentence case
    $query = '//h1 | //h2 | //h3 | //h4 | //button | //th | //label | //a[@role="button"] | //summary'
        . ' | //*[contains(@class, "nav-card-body")]/strong | //*[contains(@class, "stat-label")] | //*[contains(@class, "nav-menu")]//a';
    foreach ($xp->query($query) as $el) {
        $words = preg_split('/\s+/u', own_words($el), -1, PREG_SPLIT_NO_EMPTY);
        foreach (array_slice($words, 1) as $word) {
            $clean = trim($word, "(),.?!:\"'");
            if ($clean !== '' && preg_match('/^\p{Lu}\p{Ll}/u', $clean) && !in_array($clean, $names, true)) {
                fail_with("$page: '" . own_words($el) . "' isn't sentence case ('$clean')");
                break;
            }
        }
    }
}

// Menu items and cards have the same name as the page they open
function h1_of($page)
{
    global $docs;
    if (!isset($docs[$page])) {
        return null;
    }
    $h1 = (new DOMXPath($docs[$page]))->query('//h1')->item(0);
    return $h1 ? text_of($h1) : null;
}

$xp = new DOMXPath($docs['index.php']);
foreach ($xp->query('//*[contains(@class, "nav-menu")]//a[not(@id)]') as $a) {
    $target = $a->getAttribute('href');
    $label = text_of($a);
    if (h1_of($target) !== $label) {
        fail_with("the menu's '$label' opens $target, whose heading is '" . h1_of($target) . "'");
    } else {
        echo "  ok: menu item '$label' matches its page\n";
    }
}

foreach (['tracking.php', 'reporting.php', 'administration.php'] as $hub) {
    $xp = new DOMXPath($docs[$hub]);
    foreach ($xp->query('//a[contains(@class, "nav-card")]') as $card) {
        $target = $card->getAttribute('href');
        $name = text_of($xp->query('.//strong', $card)->item(0));
        if (h1_of($target) !== $name) {
            fail_with("$hub's card '$name' opens $target, whose heading is '" . h1_of($target) . "'");
        } else {
            echo "  ok: $hub card '$name' matches its page\n";
        }
    }
}

if (!$fail) {
    echo "  ok: tab titles match headings on " . count($docs) . " pages\n";
    echo "  ok: sentence case, no dashes, semicolons or emoji, nothing loaded from the internet\n";
}
exit($fail);
