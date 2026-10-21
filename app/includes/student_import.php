<?php
/**
 * CSV parsing/validation for bulk student registration. No database access (easy to test).
 * Columns: surname, other_names, gender, phone, date_of_birth, level, subjects
 *   subjects = up to 9 subject names separated by "|" (optional if default subjects are ticked on the page)
 */
require_once __DIR__ . '/csv_import.php';
require_once __DIR__ . '/levels.php';

const STUDENT_IMPORT_MAX_ROWS = 1000;

function student_template_csv($junior = false)
{
    if ($junior) {
        return "surname,other_names,gender,phone,date_of_birth,level,subjects\n"
             . "Adeyemi,Grace Ayomide,Female,08031234567,2012-03-14,J.S.S 2,Mathematics|English Studies|Basic Science|Basic Technology|Social Studies\n"
             . "Okafor,Chidi Emmanuel,Male,08098765432,15/11/2011,J.S.S 3,\n";
    }
    return "surname,other_names,gender,phone,date_of_birth,level,subjects\n"
         . "Adeyemi,Grace Ayomide,Female,08031234567,2008-03-14,S.S.S 2,Biology|Chemistry|Physics|Mathematics|Use of English\n"
         . "Okafor,Chidi Emmanuel,Male,08098765432,15/11/2007,S.S.S 3,\n";
}

function normalize_dob($v)
{
    $v = trim((string) $v);
    // Day/month/year is assumed for dd/mm/yyyy and dd-mm-yyyy (Nigerian convention).
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'j/n/Y', 'j-n-Y'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $v);
        $err = DateTime::getLastErrors();
        if ($d && (!$err || ($err['warning_count'] == 0 && $err['error_count'] == 0))) {
            $y = (int) $d->format('Y');
            if ($y >= 1950 && $y <= (int) date('Y')) {
                return $d->format('Y-m-d');
            }
            return null;
        }
    }
    return null;
}

function normalize_level($v, $junior = false)
{
    return canonical_level($v, $junior);
}

/**
 * @param array $subjectMap  lowercased subject name => canonical subject name (for the chosen class)
 * @param array $defaults    canonical names used when a row's subjects cell is blank
 * @param bool  $junior      true when importing into the Junior class (levels J.S.S 1-3 instead of S.S.S 1-3)
 * @return array [rows, errors]
 */
function parse_student_csv($path, array $subjectMap, array $defaults = [], $junior = false)
{
    $errors = [];
    $rows = [];
    [$matrix, $readErr] = read_csv_matrix($path);
    if ($readErr) {
        return [[], [$readErr]];
    }

    foreach ($matrix as $line => $cells) {
        if ($line === 1 && strtolower(trim((string) $cells[0])) === 'surname') {
            continue;
        }
        if (count($rows) + count($errors) >= STUDENT_IMPORT_MAX_ROWS + 50) {
            $errors[] = 'Too many rows. Import at most ' . STUDENT_IMPORT_MAX_ROWS . ' students at a time.';
            break;
        }
        if (count($cells) < 6) {
            $errors[] = "Row $line: expected at least 6 columns (surname, other_names, gender, phone, date_of_birth, level) but found " . count($cells) . '.';
            continue;
        }
        $surname = trim(strip_tags((string) $cells[0]));
        $other   = trim(strip_tags((string) $cells[1]));
        if ($surname === '' || $other === '' || mb_strlen($surname) > 100 || mb_strlen($other) > 150) {
            $errors[] = "Row $line: surname and other names are required.";
            continue;
        }
        $g = strtolower(trim((string) $cells[2]));
        $gender = in_array($g, ['male', 'm'], true) ? 'Male' : (in_array($g, ['female', 'f'], true) ? 'Female' : null);
        if (!$gender) { $errors[] = "Row $line: gender must be Male or Female."; continue; }

        $phone = preg_replace('/[\s\-()]/', '', (string) $cells[3]);
        if (preg_match('/^[789]\d{9}$/', $phone)) { $phone = '0' . $phone; } // Excel drops the leading zero
        if (!preg_match('/^\+?\d{7,15}$/', $phone)) { $errors[] = "Row $line: invalid phone number '" . mb_substr((string) $cells[3], 0, 20) . "'."; continue; }

        $dob = normalize_dob($cells[4]);
        if (!$dob) { $errors[] = "Row $line: date of birth '" . mb_substr((string) $cells[4], 0, 20) . "' not understood. Use YYYY-MM-DD or DD/MM/YYYY."; continue; }

        $level = normalize_level($cells[5], $junior);
        if (!$level) { $errors[] = "Row $line: level must be " . implode(', ', levels_for_section($junior)) . ' for this class.'; continue; }

        $subjCell = trim((string) ($cells[6] ?? ''));
        $subjects = [];
        if ($subjCell === '') {
            $subjects = $defaults;
        } else {
            $bad = null;
            foreach (preg_split('/\s*[|;]\s*/', $subjCell) as $name) {
                if ($name === '') { continue; }
                $key = strtolower(trim($name));
                if (!isset($subjectMap[$key])) { $bad = $name; break; }
                $subjects[$subjectMap[$key]] = $subjectMap[$key];
            }
            if ($bad !== null) { $errors[] = "Row $line: subject '" . mb_substr($bad, 0, 40) . "' does not exist in this class."; continue; }
            $subjects = array_values($subjects);
        }
        if (!$subjects) { $errors[] = "Row $line: no subjects. Fill the subjects column or tick default subjects on the page."; continue; }
        if (count($subjects) > 9) { $errors[] = "Row $line: more than 9 subjects (" . count($subjects) . ')'; continue; }

        $rows[] = compact('line', 'surname', 'other', 'gender', 'phone', 'dob', 'level', 'subjects');
    }
    if (!$errors && !$rows) { $errors[] = 'No students found in the file.'; }
    return [$rows, $errors];
}
