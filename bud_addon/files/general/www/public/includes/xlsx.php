<?php
// includes/xlsx.php
// Minimal native .xlsx reader (no third-party libraries). An .xlsx file is a
// ZIP of XML parts; this reads the first worksheet into arrays of cell
// strings. Handles shared strings, inline strings and numeric cells — enough
// for pharmacy report imports. Requires the zip and simplexml extensions
// (installed in the add-on image).

function readXlsxRows($path)
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception("Could not open the Excel file (is it a valid .xlsx?).");
    }

    // Shared strings lookup (some generators use inline strings instead)
    $shared = [];
    $ss_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss_xml !== false) {
        $ss = simplexml_load_string($ss_xml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                    $shared[] = $text;
                }
            }
        }
    }

    // First worksheet (sheet1.xml by convention, else whatever exists)
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet_xml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheet_xml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();

    if ($sheet_xml === false) {
        throw new Exception("No worksheet found in the Excel file.");
    }
    $sheet = simplexml_load_string($sheet_xml);
    if ($sheet === false) {
        throw new Exception("Could not parse the Excel worksheet.");
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $col = xlsxColIndex((string) $c['r']);
            if ($col < 0) {
                continue;
            }
            $type = (string) $c['t'];
            if ($type === 's') {
                $val = $shared[intval((string) $c->v)] ?? '';
            } elseif ($type === 'inlineStr') {
                if (isset($c->is->t)) {
                    $val = (string) $c->is->t;
                } else {
                    $val = '';
                    foreach ($c->is->r as $run) {
                        $val .= (string) $run->t;
                    }
                }
            } else {
                $val = isset($c->v) ? (string) $c->v : '';
            }
            $cells[$col] = $val;
        }
        if ($cells) {
            $out = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $i => $v) {
                $out[$i] = $v;
            }
            $rows[] = $out;
        }
    }
    return $rows;
}

// "C7" -> 2 (zero-based column index)
function xlsxColIndex($ref)
{
    if (!preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
        return -1;
    }
    $idx = 0;
    foreach (str_split($m[1]) as $ch) {
        $idx = $idx * 26 + (ord($ch) - 64);
    }
    return $idx - 1;
}
