<?php
/**
 * CSV parsing/validation for bulk question import. No database access here (easy to test).
 * Columns: question, option_a, option_b, option_c, option_d, correct [, topic]   (correct = A/B/C/D, 1-4, or the answer text)
 */

const IMPORT_MAX_ROWS = 1000;

function import_template_csv()
{
    return "question,option_a,option_b,option_c,option_d,correct,topic\n"
         . "\"What is the basic unit of life?\",Atom,Cell,Organ,Molecule,B,Cell Biology\n"
         . "\"Which organelle carries out photosynthesis?\",Mitochondrion,Nucleus,Chloroplast,Ribosome,C,Cell Biology\n"
         . "\"Solve x<sup>2</sup> = 9 for positive x.\",1,3,6,9,B,Algebra\n"
         . "\"Study the diagram: <img src=\"\"uploads/questions/triangle.png\"\">. What is the triangle called?\",Scalene,Isosceles,Equilateral,Right-angled,C,Geometry\n";
}

/**
 * @return array [rows, errors]  rows: list of ['line'=>n,'question'=>..,'options'=>[1..4],'correct'=>1..4]
 *                               errors: list of "Row N: message". If errors is non-empty nothing should be imported.
 */
function parse_question_csv($path)
{
    $errors = [];
    $rows = [];

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [[], ['The file is empty.']];
    }
    // Excel "CSV UTF-8" adds a BOM; older Excel saves Windows-1252.
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    // Excel in many regions uses ";" as the separator.
    $firstLine = strtok($raw, "\r\n");
    $delim = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);

    $line = 0;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $line++;
        if ($cells === [null] || count(array_filter($cells, fn($c) => trim((string) $c) !== '')) === 0) {
            continue; // blank line
        }
        if ($line === 1 && strtolower(trim((string) $cells[0])) === 'question') {
            continue; // header row
        }
        if (count($rows) + count($errors) >= IMPORT_MAX_ROWS + 50) {
            $errors[] = 'Too many rows. Import at most ' . IMPORT_MAX_ROWS . ' questions at a time.';
            break;
        }
        if (count($cells) < 6) {
            $errors[] = "Row $line: expected 6 columns (question, 4 options, correct) but found " . count($cells) . '.';
            continue;
        }
        $q = trim((string) $cells[0]);
        $opts = [];
        for ($i = 1; $i <= 4; $i++) {
            $opts[$i] = trim(strip_tags((string) $cells[$i]));
        }
        $c = trim((string) $cells[5]);

        if ($q === '') { $errors[] = "Row $line: the question is empty."; continue; }
        if (in_array('', $opts, true)) { $errors[] = "Row $line: all four options are required."; continue; }
        if (mb_strlen($q) > 5000) { $errors[] = "Row $line: question is longer than 5000 characters."; continue; }

        $correct = 0;
        if (preg_match('/^[A-Da-d]$/', $c)) {
            $correct = ord(strtoupper($c)) - 64;
        } elseif (preg_match('/^[1-4]$/', $c)) {
            $correct = (int) $c;
        } else {
            foreach ($opts as $k => $text) {
                if ($c !== '' && strcasecmp($text, $c) === 0) { $correct = $k; break; }
            }
        }
        if ($correct < 1) {
            $errors[] = "Row $line: 'correct' must be A, B, C or D (or match one option exactly). Found '" . mb_substr($c, 0, 30) . "'.";
            continue;
        }
        if (count(array_unique(array_map('strtolower', $opts))) < 4) {
            $errors[] = "Row $line: two options are identical, so the correct answer would be ambiguous.";
            continue;
        }
        $topic = trim(strip_tags((string) ($cells[6] ?? '')));
        if (mb_strlen($topic) > 100) { $errors[] = "Row $line: topic is longer than 100 characters."; continue; }
        $rows[] = ['line' => $line, 'question' => $q, 'options' => $opts, 'correct' => $correct, 'topic' => $topic];
    }
    fclose($fh);

    if (!$errors && !$rows) {
        $errors[] = 'No questions found in the file.';
    }
    if (!$errors && count($rows) > IMPORT_MAX_ROWS) {
        $errors[] = 'Too many rows (' . count($rows) . '). Import at most ' . IMPORT_MAX_ROWS . ' at a time.';
    }
    return [$rows, $errors];
}

/**
 * Reads a CSV (Excel BOM, Windows-1252, ; or , separators) into [lineNumber => cells], skipping blank lines.
 * @return array [matrix, errorMessageOrNull]
 */
function read_csv_matrix($path)
{
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [[], 'The file is empty.'];
    }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    $firstLine = strtok($raw, "\r\n");
    $delim = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $out = [];
    $line = 0;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $line++;
        if (count(array_filter($cells, fn($c) => trim((string) $c) !== '')) === 0) {
            continue;
        }
        $out[$line] = $cells;
    }
    fclose($fh);
    return [$out, null];
}
