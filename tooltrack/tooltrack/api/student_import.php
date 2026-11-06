<?php
// Student masterlist: authenticated Admin/Staff, CSV/XLSX upload -> mapping ->
// shared per-row validation -> read-only preview -> transactional revalidation/commit.
// Program/year/section columns override optional class defaults. IDs remain text.
// Matches existing Student IDs, preserves loan/history identities and duplicate policy.
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/student_validation.php';

const SMI_COURSE    = 'FPST';
const SMI_MAX_BYTES = 5242880;   // 5 MB upload cap
const SMI_MAX_ROWS  = 5000;      // data rows per import
// Section names are free-form and independent of year level.

// Header names are matched after lower-casing and replacing punctuation with spaces.
// Covers typical registrar exports and teacher class lists; Admin can still re-map in the preview.
const SMI_HEADER_ALIASES = [
    'student_id'  => ['student id', 'student no', 'student number', 'student id number', 'student identification',
                      'id number', 'id no', 'id num', 'id', 'studentid', 'stud no', 'student code', 'school id', 'school id number'],
    'full_name'   => ['full name', 'fullname', 'name', 'student name', 'complete name', 'name of student',
                      'student full name', 'learner name', 'full name last first'],
    'last_name'   => ['last name', 'lastname', 'surname', 'family name', 'lname', 'last'],
    'first_name'  => ['first name', 'firstname', 'given name', 'fname', 'first'],
    'middle_name' => ['middle name', 'middlename', 'middle initial', 'mi', 'm i', 'mname', 'middle'],
    'year_level'  => ['year level', 'year', 'yr level', 'yr', 'year lvl', 'yr lvl', 'yearlevel', 'level', 'grade level'],
    'section'     => ['section', 'batch', 'training batch', 'sec', 'class section', 'section name', 'block', 'year and section', 'year section',
                      'year sec', 'yr sec', 'yr and section', 'year and sec'],
    'course'      => ['course strand', 'course program', 'course', 'program', 'program course', 'degree program', 'degree',
                      'course name', 'program name', 'programme', 'course and program'],
    'phone'       => ['phone number', 'phone', 'phone no', 'phone num', 'contact number', 'contact no', 'contact',
                      'mobile number', 'mobile no', 'mobile', 'mobile phone', 'cellphone number', 'cellphone', 'cellphone no',
                      'cell no', 'cp number', 'cp no'],
];
// Academic columns are read per row; optional defaults support ID/name-only lists.
const SMI_READ_FIELDS = ['student_id', 'full_name', 'last_name', 'first_name', 'middle_name','year_level','section','course','phone'];
const SMI_FIELD_LABELS = [
    'student_id' => 'Student ID', 'full_name' => 'Full Name', 'last_name' => 'Last Name', 'first_name' => 'First Name',
    'middle_name' => 'Middle Name', 'year_level' => 'Year Level', 'section' => 'Section',
    'course' => 'Program', 'phone' => 'Phone Number',
];

class SmiInputError extends RuntimeException {}

// ────────────────────────────────────────────────────────────────
//  Small text helpers
// ────────────────────────────────────────────────────────────────
function smi_lower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
function smi_len(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}
function smi_cut(string $s, int $n): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

// Strips control characters / NBSP, collapses whitespace, trims.
function smi_clean($v): string {
    $s = str_replace("\xC2\xA0", ' ', (string)$v);
    $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s);
    $t = preg_replace('/\s+/u', ' ', (string)$s);
    return trim($t === null ? '' : $t);
}

// Excel hands numbers over as "2.0240012E7" or "9171234567.0" — turn them back into plain digits.
function smi_numeric_text(string $s): string {
    if (preg_match('/^\d+(\.\d+)?[eE]\+?\d+$/', $s)) {
        $f = (float)$s;
        if ($f < 1e15) return sprintf('%.0f', $f);
    }
    if (preg_match('/^(\d+)\.0+$/', $s, $m)) return $m[1];
    return $s;
}

function smi_norm_header($s): string {
    $s = strtolower((string)$s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim((string)$s);
}

function smi_mask_phone(string $p): string {
    $n = strlen($p);
    if ($n < 8) return $p === '' ? '' : str_repeat('*', $n);
    return substr($p, 0, 4) . str_repeat('*', $n - 7) . substr($p, -3);
}

// ────────────────────────────────────────────────────────────────
//  File readers  → list of ['n' => spreadsheet row number, 'c' => [col index => text]]
// ────────────────────────────────────────────────────────────────
function smi_load_upload($f): array {
    if (!is_array($f) || !isset($f['error'])) throw new SmiInputError('No file was uploaded.');
    if ($f['error'] !== UPLOAD_ERR_OK) {
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new SmiInputError('The file is too large (limit 5 MB).');
        }
        throw new SmiInputError('Upload failed (code ' . (int)$f['error'] . '). Please try again.');
    }
    if (!is_uploaded_file($f['tmp_name'])) throw new SmiInputError('Invalid upload.');
    if ((int)$f['size'] > SMI_MAX_BYTES)   throw new SmiInputError('The file is too large (limit 5 MB).');

    $ext  = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 4);

    if ($ext === 'xlsx') {
        if (substr($head, 0, 2) !== 'PK') throw new SmiInputError('This does not look like a valid .xlsx file.');
        return smi_read_xlsx($f['tmp_name']);
    }
    if ($ext === 'csv') {
        if (substr($head, 0, 2) === 'PK' || strpos($head, "\0") !== false) {
            throw new SmiInputError('This does not look like a plain-text CSV. In Excel choose Save As → "CSV UTF-8".');
        }
        return smi_read_csv($f['tmp_name']);
    }
    if ($ext === 'xls') {
        throw new SmiInputError('Old .xls files are not supported. In Excel choose Save As → .xlsx or CSV.');
    }
    throw new SmiInputError('Only .csv and .xlsx files are accepted.');
}

function smi_read_csv(string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false) throw new SmiInputError('Could not read the uploaded file.');
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);          // UTF-8 BOM
    if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
        $raw = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($raw, 'UTF-8', 'Windows-1252')                 // Excel "CSV (Comma delimited)"
            : (string)@iconv('Windows-1252', 'UTF-8//IGNORE', $raw);
    }

    // Excel in some regions saves with ; or tab — pick whichever is most common in the first lines.
    $lines = array_slice(preg_split('/\r\n|\n|\r/', $raw, 8), 0, 8);
    $delim = ','; $best = -1;
    foreach ([',', ';', "\t"] as $d) {
        $cnt = 0;
        foreach ($lines as $ln) $cnt += substr_count($ln, $d);
        if ($cnt > $best) { $best = $cnt; $delim = $d; }
    }

    $h = fopen('php://temp', 'r+');
    fwrite($h, $raw);
    rewind($h);
    $out = [];
    $n = 0;
    while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
        $n++;
        if ($r === [null]) continue;                                             // blank line
        $cells = [];
        foreach ($r as $i => $v) $cells[$i] = (string)$v;
        $out[] = ['n' => $n, 'c' => $cells];
        if (count($out) > SMI_MAX_ROWS + 30) {
            fclose($h);
            throw new SmiInputError('The file has more than ' . number_format(SMI_MAX_ROWS) . ' rows. Split it into smaller files.');
        }
    }
    fclose($h);
    return $out;
}

function smi_col_index(string $letters): int {
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

function smi_si_text($si): string {
    if (isset($si->t)) return (string)$si->t;
    $out = '';
    foreach ($si->r as $run) $out .= (string)$run->t;                            // rich-text runs
    return $out;
}

function smi_read_xlsx(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new SmiInputError('This server cannot read .xlsx files (PHP zip extension is off). Save the sheet as CSV instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new SmiInputError('The .xlsx file could not be opened. It may be corrupt or password-protected.');

    try {
        // Zip-bomb guard: refuse anything that expands to something silly.
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $total += (int)($st['size'] ?? 0);
        }
        if ($total > 80 * 1024 * 1024) throw new SmiInputError('The .xlsx file is too large once unpacked.');

        // First worksheet = first <sheet> in workbook.xml, resolved through its rels file.
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wbXml  = $zip->getFromName('xl/workbook.xml');
        $relXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wbXml !== false && $relXml !== false) {
            $wb = simplexml_load_string($wbXml, 'SimpleXMLElement', LIBXML_NONET);
            if ($wb !== false && isset($wb->sheets->sheet[0])) {
                $rid = (string)$wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $rels = simplexml_load_string($relXml, 'SimpleXMLElement', LIBXML_NONET);
                if ($rels !== false && $rid !== '') {
                    foreach ($rels->Relationship as $rel) {
                        if ((string)$rel['Id'] === $rid) {
                            $t = (string)$rel['Target'];
                            $sheetPath = ($t !== '' && $t[0] === '/') ? ltrim($t, '/') : 'xl/' . $t;
                            break;
                        }
                    }
                }
            }
        }

        // Number formats like 00000000 pad IDs with leading zeros on screen; the stored value has none.
        $pad = [];
        $stXml = $zip->getFromName('xl/styles.xml');
        if ($stXml !== false) {
            $stx = simplexml_load_string($stXml, 'SimpleXMLElement', LIBXML_NONET);
            if ($stx !== false) {
                $codes = [];
                if (isset($stx->numFmts->numFmt)) foreach ($stx->numFmts->numFmt as $nf) $codes[(int)$nf['numFmtId']] = (string)$nf['formatCode'];
                $xi = 0;
                if (isset($stx->cellXfs->xf)) foreach ($stx->cellXfs->xf as $xf) {
                    $code = $codes[(int)$xf['numFmtId']] ?? '';
                    if (preg_match('/^0{2,15}$/', $code)) $pad[$xi] = strlen($code);
                    $xi++;
                }
            }
        }

        $sst = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $ss = simplexml_load_string($ssXml, 'SimpleXMLElement', LIBXML_NONET);
            if ($ss !== false) foreach ($ss->si as $si) $sst[] = smi_si_text($si);
        }

        $stat = $zip->statName($sheetPath);
        if ($stat === false) throw new SmiInputError('The first worksheet could not be found in this .xlsx file.');
        if ((int)$stat['size'] > 40 * 1024 * 1024) throw new SmiInputError('The worksheet is too large. Save only the student list and try again.');
        $sheetXml = $zip->getFromName($sheetPath);
        $sx = ($sheetXml === false) ? false : simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
        if ($sx === false) throw new SmiInputError('The worksheet could not be read.');
    } finally {
        $zip->close();
    }

    $out = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        $auto = 0;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            if ($ref !== '' && preg_match('/^([A-Za-z]+)/', $ref, $m)) $ci = smi_col_index($m[1]);
            else $ci = $auto;
            $auto = $ci + 1;

            $t = (string)$c['t'];
            if ($t === 's')              $val = $sst[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr')  $val = smi_si_text($c->is);
            elseif ($t === 'b')          $val = ((string)$c->v === '1') ? 'TRUE' : 'FALSE';
            elseif ($t === 'e')          $val = '';
            else {
                $val = smi_numeric_text((string)$c->v);
                $w = $pad[(int)$c['s']] ?? 0;                                    // restore zeros hidden by the cell's number format
                if ($w && preg_match('/^\d+$/', $val) && strlen($val) < $w) $val = str_pad($val, $w, '0', STR_PAD_LEFT);
            }
            $cells[$ci] = (string)$val;
        }
        if (!$cells) continue;
        $rn = (int)$row['r'];
        $out[] = ['n' => $rn > 0 ? $rn : count($out) + 1, 'c' => $cells];
        if (count($out) > SMI_MAX_ROWS + 30) {
            throw new SmiInputError('The file has more than ' . number_format(SMI_MAX_ROWS) . ' rows. Split it into smaller files.');
        }
    }
    return $out;
}

// ────────────────────────────────────────────────────────────────
//  Header detection + column mapping
//  Auto-detects the header row and which column holds which field; the browser can
//  override any of it (options.mapping = {field: header text | ''}). Missing required
//  columns are REPORTED (not thrown) so the Admin can fix them in the mapping panel.
// ────────────────────────────────────────────────────────────────
// browser field key → internal field name
const SMI_KEYMAP = [
    'id' => 'student_id', 'name' => 'full_name', 'last' => 'last_name', 'first' => 'first_name',
    'middle' => 'middle_name', 'year' => 'year_level', 'section' => 'section', 'course' => 'course', 'phone' => 'phone',
];

function smi_find_header(array $table, array $override = []): array {
    $aliases = array_intersect_key(SMI_HEADER_ALIASES, array_flip(SMI_READ_FIELDS));   // only whitelisted columns are read
    $limit = min(count($table), 15);                    // title rows above the headers are fine
    $bestI = -1; $bestN = 0;
    for ($i = 0; $i < $limit; $i++) {
        $n = 0;
        foreach ($table[$i]['c'] as $cell) {
            $h = smi_norm_header($cell);
            if ($h === '') continue;
            foreach ($aliases as $list) {
                if (in_array($h, $list, true)) { $n++; break; }
            }
        }
        if ($n > $bestN) { $bestN = $n; $bestI = $i; }
    }
    if ($bestI < 0 || $bestN < 2) {
        throw new SmiInputError('No header row found. The first rows must contain the headers Student ID and Full Name.');
    }

    $cols = [];                                          // column index → header text
    foreach ($table[$bestI]['c'] as $idx => $cell) {
        $t = smi_cut(smi_clean($cell), 60);
        if ($t !== '') $cols[$idx] = $t;
    }

    $map = []; $taken = [];
    foreach ($aliases as $field => $list) {
        foreach ($cols as $idx => $t) {
            if (isset($taken[$idx])) continue;
            if (in_array(smi_norm_header($t), $list, true)) { $map[$field] = $idx; $taken[$idx] = true; break; }
        }
    }
    foreach ($override as $key => $headerText) {         // Admin's manual choices win
        $field = SMI_KEYMAP[$key] ?? null;
        if ($field === null || !in_array($field, SMI_READ_FIELDS, true)) continue;
        if ($headerText === '') { unset($map[$field]); continue; }
        $idx = array_search($headerText, $cols, true);
        if ($idx !== false) $map[$field] = $idx;
    }

    $missing = [];
    if (!isset($map['student_id'])) $missing[] = 'Student ID';
    if (!isset($map['full_name']) && !(isset($map['first_name']) && isset($map['last_name']))) {
        $missing[] = 'Full Name (or Last Name + First Name)';
    }

    // Names of columns we will NOT read (headers only — never their data).
    $used = array_values($map);
    $ignored = [];
    foreach ($cols as $idx => $t) if (!in_array($idx, $used, true)) $ignored[] = $t;

    $mapping = [];                                       // browser key → header text (for the dropdowns)
    foreach (SMI_KEYMAP as $key => $field) {
        if (in_array($field, SMI_READ_FIELDS, true) && isset($map[$field], $cols[$map[$field]])) $mapping[$key] = $cols[$map[$field]];
    }

    return ['index' => $bestI, 'map' => $map, 'mapping' => $mapping, 'headers' => array_values(array_unique($cols)),
            'missing' => $missing, 'ignored' => array_slice($ignored, 0, 20), 'ignored_total' => count($ignored)];
}

// ────────────────────────────────────────────────────────────────
//  Field parsers — each returns the clean value and pushes problems
//  onto $err (row is rejected) or $warn (row still importable).
// ────────────────────────────────────────────────────────────────
function smi_parse_phone(string $raw, array &$warn): string {
    $p = smi_numeric_text(smi_clean($raw));
    if ($p === '') return '';
    $d = (string)preg_replace('/[\s\-().]/', '', $p);
    if (strpos($d, '+63') === 0)               $d = '0' . substr($d, 3);
    elseif (preg_match('/^63\d{10}$/', $d))    $d = '0' . substr($d, 2);
    elseif (preg_match('/^9\d{9}$/', $d))      $d = '0' . $d;                 // Excel dropped the leading 0
    if (!preg_match('/^09\d{9}$/', $d)) {
        $warn[] = 'Phone number not recognised (expected e.g. 09171234567) — not imported.';
        return '';
    }
    return $d;
}


function smi_parse_row(int $n,array $cells,array $map,array $opt): array {
    $get=fn(string $field):string=>isset($map[$field])?(string)($cells[$map[$field]]??''):'';
    $warnings=[];
    if(isset($map['full_name'])){
        $name=smi_clean($get('full_name'));
        if($opt['name_format']==='last_first'&&substr_count($name,',')===1){$parts=array_map('trim',explode(',',$name));if($parts[0]!==''&&$parts[1]!=='')$name=$parts[1].' '.$parts[0];}
    }else{$name=smi_clean($get('first_name').' '.$get('middle_name').' '.$get('last_name'));}
    // A mapped column is authoritative, including blank TESDA academic cells.
    // Defaults support the existing ID/name-only class lists.
    $course=isset($map['course'])?$get('course'):$opt['course'];
    $sameProgram=studentProgram($course)===$opt['course'];
    $s=normalizeStudent(['id_number'=>$get('student_id'),'full_name'=>$name,
        'year_level'=>isset($map['year_level'])?$get('year_level'):($sameProgram?$opt['year']:0),
        'section_name'=>isset($map['section'])?$get('section'):($sameProgram?$opt['section']:''),
        'course'=>$course,'phone'=>$get('phone')]);
    return ['n'=>$n,'raw_id'=>smi_cut(smi_clean($get('student_id')),20),'id'=>$s['id_number'],'name'=>$s['full_name'],'phone'=>$s['phone'],
        'course'=>$s['course'],'course_from_file'=>true,'section'=>$s['section_name'],'year'=>$s['year'],
        'errors'=>$s['errors'],'warnings'=>$warnings,'state'=>$s['errors']?'invalid':'candidate',
        'fields'=>null,'current'=>null,'db_id'=>0,'db_active'=>true];
}
function smi_analyze(PDO $db, array $table, array $opt): array {
    $hdr  = smi_find_header($table, $opt['mapping']);
    $map  = $hdr['map'];
    $rows = [];

    $out = function (array $rows) use ($hdr, $map): array {
        $comma = 0;
        foreach ($rows as $r) {
            foreach ($r['warnings'] as $w) { if (strpos($w, 'comma') !== false) { $comma++; break; } }
        }
        return [
            'rows' => $rows, 'columns_used' => array_map(function ($f) { return SMI_FIELD_LABELS[$f]; }, array_keys($map)),
            'ignored' => $hdr['ignored'], 'ignored_total' => $hdr['ignored_total'], 'comma_names' => $comma,
            'headers' => $hdr['headers'], 'mapping' => $hdr['mapping'], 'missing' => $hdr['missing'],
        ];
    };
    if ($hdr['missing']) return $out([]);                 // nothing can be read yet — the UI shows the mapping panel

    for ($i = $hdr['index'] + 1; $i < count($table); $i++) {
        $cells = $table[$i]['c'];
        $blank = true;
        foreach ($map as $col) {
            if (smi_clean($cells[$col] ?? '') !== '') { $blank = false; break; }
        }
        if ($blank) continue;
        $rows[] = smi_parse_row((int)$table[$i]['n'], $cells, $map, $opt);
    }
    if (!$rows) throw new SmiInputError('No student rows were found below the header row.');
    if (count($rows) > SMI_MAX_ROWS) {
        throw new SmiInputError('The file has more than ' . number_format(SMI_MAX_ROWS) . ' student rows. Split it into smaller files.');
    }

    // ── Duplicate Student IDs inside the file ────────────────────
    $byId = [];
    foreach ($rows as $k => $r) {
        if ($r['id'] !== '') $byId[$r['id']][] = $k;
    }
    foreach ($byId as $id => $keys) {
        if (count($keys) < 2) continue;
        $sigs = [];
        foreach ($keys as $k) {
            $r = $rows[$k];
            $sigs[smi_lower($r['name']) . '|' . $r['phone'] . '|' . $r['section'] . '|' . $r['year'] . '|' . smi_lower($r['course'])] = true;
        }
        $rowNums = [];
        foreach ($keys as $k) $rowNums[] = $rows[$k]['n'];
        if (count($sigs) === 1) {                          // exact repeats: keep the first, skip the rest
            foreach (array_slice($keys, 1) as $k) {
                $rows[$k]['state']    = 'duplicate';
                $rows[$k]['errors'][] = 'Same Student ID as row ' . $rows[$keys[0]]['n'] . ' (identical details) — skipped.';
            }
        } else {                                           // same ID, different details: ambiguous → skip all
            foreach ($keys as $k) {
                $rows[$k]['state']    = 'conflict';
                $rows[$k]['errors'][] = 'Student ID appears in rows ' . implode(', ', $rowNums) . ' with different details — fix the file.';
            }
        }
    }

    // ── Match against borrowers by id_number (the unique key) ────
    $ids = [];
    foreach ($rows as $r) if ($r['state'] === 'candidate') $ids[(string)$r['id']] = true;
    $existing = [];
    foreach (array_chunk(array_map('strval', array_keys($ids)), 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT id, id_number, full_name, phone, type, is_active FROM borrowers WHERE id_number IN ($ph)");
        $st->execute($chunk);
        foreach ($st->fetchAll() as $b) $existing[(string)$b['id_number']] = $b;
    }

    // Each matched student's CURRENT course/section — same preference rule borrowers.php uses (manual row first).
    $enr = [];
    $bids = [];
    foreach ($existing as $b) $bids[] = (int)$b['id'];
    foreach (array_chunk($bids, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT borrower_id, course, section_name, year_level FROM borrower_enrollments
                            WHERE is_active = 1 AND borrower_id IN ($ph)
                            ORDER BY (cms_subject_id = 0) DESC, id ASC");
        $st->execute($chunk);
        foreach ($st->fetchAll() as $e) {
            $key = (int)$e['borrower_id'];
            if (!isset($enr[$key])) $enr[$key] = $e;
        }
    }

    $newNames = [];
    foreach ($rows as $k => $r) {
        if ($r['state'] !== 'candidate') continue;
        $b = $existing[(string)$r['id']] ?? null;

        if ($b === null) {
            if (($r['section'] === '' && $r['course']!=='TESDA') || $r['course'] === '') {
                $rows[$k]['state']    = 'invalid';
                $rows[$k]['errors'][] = 'A new student needs a Section (or Year Level + Section) and a Course/Program.';
                continue;
            }
            $rows[$k]['state'] = 'new';
            $newNames[smi_lower($r['name'])] = true;
            continue;
        }
        if ($b['type'] !== 'Student') {
            $rows[$k]['state']    = 'blocked';
            $rows[$k]['errors'][] = 'This ID already belongs to a ' . $b['type'] . ' record — left untouched.';
            continue;
        }

        $e         = $enr[(int)$b['id']] ?? null;
        $curName   = smi_clean($b['full_name']);
        $curPhone  = smi_clean($b['phone'] ?? '');
        $curSec    = $e ? smi_clean($e['section_name'] ?? '') : '';
        $curCourse = $e ? smi_clean($e['course'] ?? '') : '';

        // name
        if ($curName === '')                                         $fName = 'fill';
        elseif (smi_lower($curName) === smi_lower($r['name']))       $fName = 'same';
        else {
            $fName = 'change';
            similar_text(smi_lower($curName), smi_lower($r['name']), $pct);
            if ($pct < 60) $rows[$k]['warnings'][] = 'Name is very different from the existing record — make sure this is the same person.';
        }
        // phone (an empty/invalid incoming phone never erases an existing one)
        if ($r['phone'] === '')                                      $fPhone = 'same';
        elseif ($curPhone === '')                                    $fPhone = 'fill';
        else {
            $w = [];
            $curNorm = smi_parse_phone($curPhone, $w);
            $fPhone = ($curNorm === $r['phone'] || $curPhone === $r['phone']) ? 'same' : 'change';
        }
        // Compare school year independently of the section name; TESDA may clear both.
        if ($r['section'] === '' && $r['course']==='TESDA')            $fSec = ($curSec!==''||studentYearNumber($e['year_level']??'')!==0)?'change':'same';
        elseif ($r['section'] === '')                                $fSec = 'same';
        elseif ($curSec === '')                                      $fSec = 'fill';
        elseif (strcasecmp($curSec, $r['section']) === 0 && studentYearNumber($e['year_level']??'')===$r['year'])            $fSec = 'same';
        else                                                         $fSec = 'change';
        // Program changes follow the selected update policy, including explicit class defaults.
        if (!$r['course_from_file'])                                 $fCourse = ($curCourse === '' && $r['course'] !== '') ? 'fill' : 'same';
        elseif ($curCourse === '')                                   $fCourse = 'fill';
        elseif (strcasecmp($curCourse, $r['course']) === 0)          $fCourse = 'same';
        else                                                         $fCourse = 'change';

        $rows[$k]['state']     = 'existing';
        $rows[$k]['db_id']     = (int)$b['id'];
        $rows[$k]['db_active'] = ((int)$b['is_active'] === 1);
        $rows[$k]['fields']    = ['name' => $fName, 'phone' => $fPhone, 'section' => $fSec, 'course' => $fCourse];
        $rows[$k]['current']   = ['name' => $curName, 'phone' => smi_mask_phone($curPhone), 'section' => $curSec, 'course' => $curCourse, 'year'=>studentYearNumber($e['year_level']??'')];
        // A partial overwrite must not combine a TVL program with an FPST year (or vice versa).
        $mayAcademic=function(string $field)use($opt,$rows,$k):bool{
            if($opt['policy']==='skip')return false;
            $state=$rows[$k]['fields'][$field]??'same';
            return $state==='fill'||($state==='change'&&$opt['policy']==='overwrite'&&in_array($field,$opt['overwrite_fields'],true));
        };
        $changeSection=$mayAcademic('section');$changeCourse=$mayAcademic('course');
        if($changeSection||$changeCourse){
            $effective=normalizeStudent(['id_number'=>$r['id'],'full_name'=>$r['name'],'phone'=>$r['phone'],
                'course'=>$changeCourse?$r['course']:$curCourse,'section_name'=>$changeSection?$r['section']:$curSec,
                'year_level'=>$changeSection?$r['year']:($rows[$k]['current']['year']??0)]);
            if($effective['errors']){$rows[$k]['state']='invalid';$rows[$k]['errors'][]='The chosen update options produce invalid student details: '.implode(' ',$effective['errors']).' Update both Course/Program and Year Level/Section together.';}
        }
        if (!$rows[$k]['db_active']) $rows[$k]['warnings'][] = 'This student is currently inactive.';
    }

    // ── Same name, different ID (possible duplicate person) — warning only ──
    if ($newNames) {
        $byName = [];
        foreach (array_chunk(array_keys($newNames), 500) as $chunk) {
            $chunk = array_map('strval', $chunk);
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("SELECT id_number, full_name FROM borrowers WHERE type = 'Student' AND full_name IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll() as $b) $byName[smi_lower((string)$b['full_name'])][] = (string)$b['id_number'];
        }
        foreach ($rows as $k => $r) {
            if ($r['state'] !== 'new') continue;
            $hit = $byName[smi_lower($r['name'])] ?? null;
            if ($hit) {
                $rows[$k]['warnings'][] = 'A student with this name already exists (ID ending ' . substr($hit[0], -4) . ') — check it is not the same person.';
            }
        }
    }

    return $out($rows);
}

function smi_preview_payload(array $a): array {
    $counts = ['total' => 0, 'new' => 0, 'existing' => 0, 'invalid' => 0, 'duplicate' => 0, 'conflict' => 0, 'blocked' => 0];
    $out = [];
    foreach ($a['rows'] as $r) {
        $counts['total']++;
        $counts[$r['state']]++;
        $out[] = [
            'row'      => $r['n'],
            'id'       => $r['id'] !== '' ? $r['id'] : $r['raw_id'],
            'name'     => $r['name'],
            'phone'    => smi_mask_phone($r['phone']),
            'course'   => $r['course'],
            'section'  => $r['section'],
            'year'     => $r['year'],
            'state'    => $r['state'],
            'errors'   => $r['errors'],
            'warnings' => $r['warnings'],
            'fields'   => $r['fields'],
            'current'  => $r['current'],
            'active'   => $r['db_active'],
        ];
    }
    return [
        'counts'        => $counts,
        'rows'          => $out,
        'columns_used'  => $a['columns_used'],
        'ignored'       => $a['ignored'],
        'ignored_total' => $a['ignored_total'],
        'comma_names'   => $a['comma_names'],
        'headers'       => $a['headers'],
        'mapping'       => $a['mapping'],
        'missing'       => $a['missing'],
    ];
}

// ────────────────────────────────────────────────────────────────
//  Apply (commit)
// ────────────────────────────────────────────────────────────────

// Mirrors saveManualEnrollment() in borrowers.php: one MANUAL enrollment row
// (cms_subject_id = 0) per student, CMS-synced rows are left alone.
function smi_year_label(int $y): string {return studentYearLabel($y);}

// $year = 0 → leave the stored year level alone.
function smi_save_enrollment(PDO $db, int $borrowerId, string $course, string $section, int $year = 0): void {
    $yl = smi_year_label($year);
    $find = $db->prepare('SELECT id FROM borrower_enrollments WHERE borrower_id = ? AND cms_subject_id = 0 LIMIT 1');
    $find->execute([$borrowerId]);
    $eid = (int)$find->fetchColumn();
    if ($eid) {
        if($course==='TESDA'){
            $db->prepare('UPDATE borrower_enrollments SET course=?,section_name=?,year_level=NULL,cms_section_id=0,is_active=1,synced_at=NOW() WHERE id=?')->execute([$course,$section,$eid]);
            return;
        }
        $db->prepare('UPDATE borrower_enrollments
                      SET course = ?, section_name = ?, year_level = COALESCE(NULLIF(?, \'\'), year_level),
                          cms_section_id = 0, is_active = 1, synced_at = NOW()
                      WHERE id = ?')
           ->execute([$course, $section, $yl, $eid]);
    } else {
        $db->prepare('INSERT INTO borrower_enrollments
                        (borrower_id, cms_section_id, cms_subject_id, course, section_name, year_level, subject_code, subject_name, is_active)
                      VALUES (?, 0, 0, ?, ?, ?, "", "Manual Section Enrollment", 1)')
           ->execute([$borrowerId, $course, $section, $yl === '' ? null : $yl]);
    }
}

function smi_apply(PDO $db, array $rows, array $opt): array {
    $s = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_existing_policy' => 0,
          'skipped_problem' => 0, 'skipped_race' => 0, 'reactivated' => 0];
    $policy = $opt['policy'];

    $ins = $db->prepare("INSERT INTO borrowers (full_name, id_number, type, email, phone) VALUES (?, ?, 'Student', '', ?)");

    foreach ($rows as $r) {
        if ($r['state'] === 'new') {
            try {
                $ins->execute([$r['name'], $r['id'], $r['phone']]);
            } catch (PDOException $e) {
                // Someone added the same ID between the analysis and this insert → never overwrite, just skip.
                if ((string)$e->getCode() === '23000') { $s['skipped_race']++; continue; }
                throw $e;
            }
            $bid = (int)$db->lastInsertId();
            smi_save_enrollment($db, $bid, $r['course'], $r['section'], (int)$r['year']);
            $s['created']++;
            continue;
        }

        if ($r['state'] === 'existing') {
            if ($policy === 'skip') { $s['skipped_existing_policy']++; continue; }

            $may = function (string $f) use ($r, $policy, $opt): bool {
                $st = $r['fields'][$f] ?? 'same';
                if ($st === 'fill')   return true;
                if ($st === 'change') return $policy === 'overwrite' && in_array($f, $opt['overwrite_fields'], true);
                return false;
            };

            $set = []; $vals = [];
            if ($may('name'))  { $set[] = 'full_name = ?'; $vals[] = $r['name']; }
            if ($may('phone')) { $set[] = 'phone = ?';     $vals[] = $r['phone']; }
            $react = ($opt['reactivate'] && !$r['db_active']);
            if ($react)        { $set[] = 'is_active = 1'; }
            $doSection = $may('section');
            $doCourse  = $may('course');

            if (!$set && !$doSection && !$doCourse) { $s['unchanged']++; continue; }

            if ($set) {
                $vals[] = $r['db_id'];
                $db->prepare("UPDATE borrowers SET " . implode(', ', $set) . " WHERE id = ? AND type = 'Student'")->execute($vals);
            }
            if ($doSection || $doCourse) {
                // Write the final pair: the new value where it is being updated, otherwise what the student has now.
                $curCourse = (string)($r['current']['course'] ?? '');
                $curSec    = (string)($r['current']['section'] ?? '');
                $fc = $doCourse  ? $r['course']  : ($curCourse !== '' ? $curCourse : $r['course']);
                $fs = $doSection ? $r['section'] : $curSec;
                if ($fc !== '' && ($fs !== '' || $fc==='TESDA')) smi_save_enrollment($db, $r['db_id'], $fc, $fs, $doSection ? (int)$r['year'] : 0);
            }
            if ($react) $s['reactivated']++;
            $s['updated']++;
            continue;
        }

        $s['skipped_problem']++;     // invalid / duplicate / conflict / blocked
    }
    return $s;
}

// ────────────────────────────────────────────────────────────────
//  Options (sent by the browser as JSON; whitelisted here)
// ────────────────────────────────────────────────────────────────
function smi_options($json): array {
    $d = json_decode((string)$json, true);
    if (!is_array($d)) $d = [];
    $policy = (isset($d['policy']) && is_string($d['policy']) && in_array($d['policy'], ['skip', 'fill_blank', 'overwrite'], true))
        ? $d['policy'] : 'fill_blank';
    $fields = (isset($d['overwrite_fields']) && is_array($d['overwrite_fields']))
        ? array_values(array_intersect(['name', 'phone', 'section', 'course'], $d['overwrite_fields'])) : [];

    // Optional defaults for absent columns; every row is validated after mapping.
    $courseRaw=$d['course']??$d['default_course']??'';
    $course = is_string($courseRaw)?studentProgram($courseRaw):'';
    if ($course!==''&&!in_array($course,studentPrograms(),true)) throw new SmiInputError('Invalid Program "'.$course.'". Choose FPST or TVL or TESDA.');
    $yearRaw = $d['year_level'] ?? '';
    $year = (is_string($yearRaw) || is_int($yearRaw)) ? studentYearNumber($yearRaw) : 0;
    if ($year!==0&&$course === 'FPST' && !in_array($year, [1, 2], true)) throw new SmiInputError('FPST Year Level must be 1st Year or 2nd Year.');
    if ($year!==0&&$course === 'TVL' && !in_array($year, [11, 12], true)) throw new SmiInputError('TVL Year Level must be Grade 11 or Grade 12.');
    if($course==='TESDA')$year=0;
    $section = (isset($d['section']) && is_string($d['section'])) ? studentText($d['section']) : '';
    if (smi_len($section) > 80) throw new SmiInputError('Section must be at most 80 characters.');
    $mapping = [];
    if (isset($d['mapping']) && is_array($d['mapping'])) {
        foreach ($d['mapping'] as $k => $v) {
            if (is_string($k) && isset(SMI_KEYMAP[$k]) && is_string($v)) $mapping[$k] = smi_cut($v, 60);
        }
    }
    $ay = isset($d['academic_year']) && is_string($d['academic_year'])
        ? smi_cut((string)preg_replace('/[^0-9A-Za-z\-\/ ]/', '', $d['academic_year']), 20) : '';

    return [
        'policy'           => $policy,
        'overwrite_fields' => $fields,
        'name_format'      => (isset($d['name_format']) && $d['name_format'] === 'last_first') ? 'last_first' : 'as_is',
        'reactivate'       => !empty($d['reactivate']) && $policy !== 'skip',
        'course'           => $course,
        'year'             => $year,
        'section'          => $section,
        'mapping'          => $mapping,
        'academic_year'    => trim($ay),
    ];
}

// ────────────────────────────────────────────────────────────────
//  Request handler (skipped when this file is include()d by a test)
// ────────────────────────────────────────────────────────────────
function smi_handle(): void {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    requireRole([ROLE_ADMIN,ROLE_STAFF]);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'GET' && $action === 'template') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="fpst_masterlist_template.csv"');
        echo "\xEF\xBB\xBF" . "Student ID,Full Name,Year Level,Section,Course / Strand,Phone Number\r\n";
        exit;
    }

    if ($method !== 'POST') fail('Method not allowed.', 405);
    // A plain cross-site <form> cannot add custom headers, so this blocks drive-by CSRF uploads.
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'FPSTToolTrack') fail('Invalid request.', 400);
    if (!in_array($action, ['preview', 'commit'], true)) fail('Unknown action.', 400);

    @set_time_limit(120);


    try {
        $opt = smi_options($_POST['options'] ?? '');
        $table = smi_load_upload($_FILES['file'] ?? null);

        if ($action === 'preview') {
            $analysis = smi_analyze($db, $table, $opt);
            ok(smi_preview_payload($analysis));
        }

        // ── commit ──
        $got = (int)$db->query("SELECT GET_LOCK('fpst_student_import', 10)")->fetchColumn();
        if (!$got) fail('Another import is running. Please try again in a moment.', 409);

        $stats = null; $total = 0;
        try {
            $db->beginTransaction();
            try {
                $analysis = smi_analyze($db, $table, $opt);   // fresh, inside the lock
                if ($analysis['missing']) {
                    throw new SmiInputError('Required column(s) not mapped: ' . implode(', ', $analysis['missing']) . '.');
                }
                $total    = count($analysis['rows']);
                $stats    = smi_apply($db, $analysis['rows'], $opt);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        } finally {
            $db->query("SELECT RELEASE_LOCK('fpst_student_import')");
        }

        // Counts only — no names, IDs or phone numbers in the audit trail.
        logAudit($stats['created'] > 0 ? 'borrower_create' : 'borrower_edit',
            "Student masterlist import [{$opt['course']} " . studentYearLabel($opt['year']) . "]" . ($opt['academic_year'] !== '' ? " (AY {$opt['academic_year']})" : '')
            . ": {$stats['created']} added, {$stats['updated']} updated, {$stats['unchanged']} unchanged, "
            . ($stats['skipped_existing_policy'] + $stats['skipped_problem'] + $stats['skipped_race']) . " skipped of $total rows (policy: {$opt['policy']})");

        $stats['total'] = $total;
        ok($stats);

    } catch (SmiInputError $e) {
        fail($e->getMessage(), 422);
    } catch (Throwable $e) {
        error_log('student_import: ' . $e->getMessage());
        fail('The import could not be completed and nothing was changed. Please try again, or ask the system administrator to check the server log.', 500);
    }
}

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    smi_handle();
}
