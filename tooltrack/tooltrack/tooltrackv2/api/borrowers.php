<?php
// ================================================================
//  api/borrowers.php  —  Full CRUD for Borrowers
//
//  GET    ?page=1&per_page=10&type=&search=
//         ?id=N  → single borrower
//  POST   { full_name, id_number, type, email, phone }  — any logged-in user
//  PUT    { id, full_name, id_number, type, email, phone }  — Admin only
//  PATCH  { id, is_active }  → activate/deactivate (no hard delete) — Admin only
//  PATCH  { course, sections:[{id, section_name, cms_section_id}] }
//         → bulk "Update Sections" for students — Admin only
//  GET also accepts: status=active|inactive, sort=, dir=asc|desc,
//      (POST/PUT also accept year_level, e.g. "1st Year")
//      course=FPST|TVL|…  and  section=1-A|…  (match the student's current course/section)
// ================================================================
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/student_validation.php';
require_once dirname(__DIR__) . '/includes/missing_lib.php';
require_once __DIR__ . '/replacement_payments.php';
require_once dirname(__DIR__) . '/includes/circulation_lib.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Any logged-in user (Admin or Staff) can look borrowers up — Staff
// need this to select a borrower on the Borrow page, and creating a
// brand-new borrower record is also part of that same Staff workflow
// (registering a walk-in guest mid-transaction) — so POST is open to
// any logged-in user. Editing an existing person's details or
// activating/deactivating them is roster *management*, which stays
// Admin-only.
requireLogin();
if($method==='GET')session_write_close();
if (in_array($method, ['PUT', 'PATCH'], true)) {
    requireRole(ROLE_ADMIN);
}

// School's official student ID format, e.g. 2024-00123. Only enforced
// for type=Student — Faculty/Staff use the school's HR numbering (not
// specified here) and Guest is by definition someone outside that
// system, so neither is forced through the student ID shape.


// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {

    // Single borrower — with full transaction history + on-time/late stats
    if (!empty($_GET['id']) && !empty($_GET['history'])) {
        $id = (int)$_GET['id'];

        $stmt = $db->prepare("SELECT b.*, e.course, e.section_name, e.year_level, e.cms_section_id
            FROM borrowers b
            LEFT JOIN borrower_enrollments e
              ON e.borrower_id = b.id AND e.is_active = 1
            WHERE b.id = ?
            ORDER BY (e.cms_subject_id = 0) DESC, e.id ASC
            LIMIT 1");
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) fail('Borrower not found.', 404);

        // Existing transactions remain the history source; summaries cover all records, not just this page.
        $out=sqlOutstanding('t');$overdue=sqlIsOverdue('t');$due=sqlDueAt('t');
        $q=$db->prepare("SELECT COUNT(DISTINCT CASE WHEN t.type='borrow' THEN t.txn_id END) total_borrows,
            COALESCE(SUM(CASE WHEN t.type='borrow' AND t.status='active' THEN $out ELSE 0 END),0) active_units,
            COUNT(DISTINCT CASE WHEN $overdue THEN t.txn_id END) overdue_now,
            COUNT(DISTINCT CASE WHEN t.type='borrow' AND (t.is_late=1 OR (t.status='returned' AND t.returned_at>$due)) THEN t.txn_id END) late,
            COUNT(DISTINCT CASE WHEN t.type='borrow' AND t.status='returned' AND t.returned_at<=$due AND t.is_late=0
                AND NOT EXISTS(SELECT 1 FROM transactions other_loan WHERE other_loan.type='borrow' AND other_loan.txn_id=t.txn_id
                    AND COALESCE(other_loan.status='returned' AND other_loan.returned_at<=".sqlDueAt('other_loan')." AND other_loan.is_late=0,0)=0)
                THEN t.txn_id END) on_time,
            COALESCE(SUM(t.type='return' AND t.`condition` IN ('minor','damaged','missing')),0) damaged_or_minor
            FROM transactions t WHERE t.borrower_id=?");$q->execute([$id]);$stats=array_map('intval',$q->fetch());
        $q=$db->prepare('SELECT i.incident,COUNT(*) n FROM ('.caseTrackingSource().') i WHERE i.borrower_id=? GROUP BY i.incident');$q->execute([$id]);
        $stats['damaged_incidents']=0;$stats['missing_incidents']=0;
        foreach($q->fetchAll() as $incident)$stats[$incident['incident']==='lost'?'missing_incidents':'damaged_incidents']+=(int)$incident['n'];
        $where='t.borrower_id=?';$params=[$id];$filter=(string)($_GET['history_status']??'all');
        switch($filter){
            case 'all':break;
            case 'active':$where.=" AND t.type='borrow' AND t.status='active' AND $out>0";break;
            case 'overdue':$where.=" AND $overdue";break;
            case 'returned':$where.=" AND t.type='borrow' AND (t.status='returned' OR $out<=0)";break;
            case 'returns':$where.=" AND t.type='return'";break;
            case 'late':$where.=" AND t.type='borrow' AND (t.is_late=1 OR (t.status='returned' AND t.returned_at>$due))";break;
            default:fail('Unknown history filter.',422);
        }
        $search=trim((string)($_GET['history_search']??''));
        if($search!==''){$where.=' AND (t.txn_id LIKE ? OR tl.name LIKE ? OR tl.code LIKE ?)';$like='%'.addcslashes(mb_substr($search,0,150),'%_\\').'%';array_push($params,$like,$like,$like);}
        $from=" FROM transactions t LEFT JOIN tools tl ON tl.id=t.tool_id LEFT JOIN transactions loan ON loan.id=t.borrow_txn_id WHERE $where";
        $q=$db->prepare('SELECT COUNT(*)'.$from);$q->execute($params);$total=(int)$q->fetchColumn();$per=25;$page=max(1,min((int)($_GET['history_page']??1),max(1,(int)ceil($total/$per))));
        $q=$db->prepare("SELECT t.id,t.txn_id,t.type,t.status,t.`condition`,t.notes,t.is_late,
            COALESCE(t.due_date,loan.due_date) due_date,COALESCE(t.due_time,loan.due_time) due_time,t.returned_at,t.created_at,
            CASE WHEN t.type='borrow' THEN t.created_at ELSE loan.created_at END borrowed_date,
            t.qty,t.qty_returned,t.qty_missing,$out outstanding,".sqlOverdueDays('t')." overdue_days,$overdue is_overdue,
            tl.name tool_name,tl.code tool_code".$from.' ORDER BY t.created_at DESC,t.id DESC LIMIT ? OFFSET ?');$q->execute([...$params,$per,($page-1)*$per]);$rows=$q->fetchAll();
        foreach($rows as &$r){$r['circulation_status']=loanCirculationState($r);$r['loan_state']=$r['circulation_status'];$r['flag']=$r['is_overdue']?'overdue':($r['is_late']?'late':($r['status']==='returned'?'on_time':null));}unset($r);
        $incidents=caseTrackingList($db,['borrower_id'=>$id,'page'=>$_GET['incident_page']??1]);
        ok(['borrower' => $b, 'history' => $rows, 'stats' => $stats,'incidents'=>$incidents,'history_pagination'=>['total'=>$total,'page'=>$page,'per_page'=>$per]]);
    }

    // Single borrower — plain (used by the edit form, no history needed)
    if (!empty($_GET['id'])) {
        $stmt = $db->prepare("SELECT b.*, e.course, e.section_name, e.year_level, e.cms_section_id
            FROM borrowers b
            LEFT JOIN borrower_enrollments e
              ON e.borrower_id = b.id AND e.is_active = 1
            WHERE b.id = ?
            ORDER BY (e.cms_subject_id = 0) DESC, e.id ASC
            LIMIT 1");
        $stmt->execute([(int)$_GET['id']]);
        $b = $stmt->fetch();
        if (!$b) fail('Borrower not found.', 404);
        ok($b);
    }

    // List
    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 10)));
    $offset  = ($page - 1) * $perPage;
    $type    = $_GET['type']   ?? '';
    $search  = '%' . trim($_GET['search'] ?? '') . '%';

    $where  = ['1=1'];
    $params = [];

    if ($type) {
        $where[]  = 'type = ?';
        $params[] = $type;
    }
    // Exact ID lookup — used by the Borrow / Return ID scanners.
    $idNumber = trim($_GET['id_number'] ?? '');
    if ($idNumber !== '') {
        $where[]  = 'id_number = ?';
        $params[] = $idNumber;
    }
    if (trim($_GET['search'] ?? '') !== '') {
        $where[]  = '(full_name LIKE ? OR id_number LIKE ? OR email LIKE ?)';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Active / inactive filter (used by the Borrowers page and Update Sections).
    $status = $_GET['status'] ?? '';
    if ($status === 'active')   { $where[] = 'is_active = 1'; }
    if ($status === 'inactive') { $where[] = 'is_active = 0'; }

    // Sorting — whitelisted so the column name can never be injected.
    $sortCols = ['full_name', 'id_number', 'type', 'active_borrows', 'total_borrows'];
    $sort     = in_array($_GET['sort'] ?? '', $sortCols, true) ? $_GET['sort'] : 'full_name';
    $dir      = (strtolower($_GET['dir'] ?? '') === 'desc') ? 'DESC' : 'ASC';
    $orderSql = "b.$sort $dir, b.id ASC";

    // is_active exists on borrower_enrollments too, so it must be qualified here.
    $whereSql = implode(' AND ', array_map(fn($w) => str_replace(
        ['type =','id_number =','full_name LIKE','id_number LIKE','email LIKE','is_active ='],
        ['b.type =','b.id_number =','b.full_name LIKE','b.id_number LIKE','b.email LIKE','b.is_active ='],
        $w
    ), $where));

    // Course/Strand + Section filters. They match the SAME enrollment row that the
    // list displays (manual enrollment first), so what you filter on is what you see.
    $fCourse  = trim($_GET['course']  ?? '');
    $fSection = trim($_GET['section'] ?? '');
    if ($fCourse !== '')  { $whereSql .= ' AND e.course = ?';       $params[] = $fCourse; }
    if ($fSection !== '') { $whereSql .= ' AND e.section_name = ?'; $params[] = $fSection; }

    // Count uses the same join + filters, so paging totals match the filtered list.
    $countSql = 'SELECT COUNT(*) FROM borrowers b
            LEFT JOIN borrower_enrollments e
              ON e.id = (
                  SELECT e2.id
                  FROM borrower_enrollments e2
                  WHERE e2.borrower_id = b.id AND e2.is_active = 1
                  ORDER BY (e2.cms_subject_id = 0) DESC, e2.id ASC
                  LIMIT 1
              )
            WHERE ' . $whereSql;
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = 'SELECT b.*, e.course, e.section_name, e.year_level, e.cms_section_id,
              (SELECT COUNT(DISTINCT t.txn_id) FROM transactions t
                 WHERE t.borrower_id = b.id AND t.type = "borrow"
                   AND t.status = "active" AND t.due_date < CURDATE()) AS overdue_count,
              (SELECT COUNT(*) FROM missing_reports mr
                 WHERE mr.borrower_id = b.id AND mr.status IN ("pending","confirmed")) AS open_missing_count,
              (SELECT COUNT(*) FROM loss_writeoffs w WHERE w.borrower_id=b.id AND w.status="approved" AND w.accountability_status="Review required") AS loss_accountability_review_count,
              (SELECT COUNT(*) FROM transactions t2
                 WHERE t2.borrower_id = b.id AND t2.type = "return"
                   AND t2.`condition` IN ("damaged","missing")) AS flagged_count
            FROM borrowers b
            LEFT JOIN borrower_enrollments e
              ON e.id = (
                  SELECT e2.id
                  FROM borrower_enrollments e2
                  WHERE e2.borrower_id = b.id AND e2.is_active = 1
                  ORDER BY (e2.cms_subject_id = 0) DESC, e2.id ASC
                  LIMIT 1
              )
            WHERE ' . $whereSql . '
            ORDER BY ' . $orderSql . ' LIMIT ? OFFSET ?';
    $params[] = $perPage;
    $params[] = $offset;

    $stmt  = $db->prepare($sql);
    $stmt->execute($params);
    $rows  = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'success'  => true,
        'data'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ]);
    exit;
}

// Writes/updates the borrower's MANUAL enrollment (cms_subject_id = 0).
// CMS-synced enrollments are left alone so the next sync isn't fighting it.
function yearLabel(int $n): string {
    $o = [1 => '1st', 2 => '2nd', 3 => '3rd'];
    return ($o[$n] ?? $n . 'th') . ' Year';
}

function saveManualEnrollment(PDO $db, int $borrowerId, array $b): void {
    $course      = trim($b['course'] ?? '');
    $sectionName = trim($b['section_name'] ?? '');
    // Year level: what the form sent wins. If none was sent, derive it from the
    // section's first digit (1-C → 1st Year) so "Update Sections" keeps it in sync;
    // if the section has no leading digit, the stored value is left alone.
    $yearLevel = trim($b['year_level'] ?? '');
    if ($course!=='TESDA' && $yearLevel === '' && preg_match('/^([1-9])-/', $sectionName, $ym)) $yearLevel = yearLabel((int)$ym[1]);
    $yearLevel = function_exists('mb_substr') ? mb_substr($yearLevel, 0, 30, 'UTF-8') : substr($yearLevel, 0, 30);
    $cmsSectionId = (int)($b['cms_section_id'] ?? 0); // 0 for typed names like "1-A"

    // Find this borrower's existing manual enrollment, if any
    $find = $db->prepare('SELECT id FROM borrower_enrollments WHERE borrower_id = ? AND cms_subject_id = 0 LIMIT 1');
    $find->execute([$borrowerId]);
    $existingId = (int)$find->fetchColumn();

    // Course set to "None" → retire the manual enrollment
    if ($course === '' || ($sectionName === '' && $course!=='TESDA')) {
        if ($existingId) {
            $db->prepare('UPDATE borrower_enrollments SET is_active = 0, synced_at = NOW() WHERE id = ?')
               ->execute([$existingId]);
        }
        return;
    }

    if ($existingId) {
        if($course==='TESDA'){
            $db->prepare('UPDATE borrower_enrollments SET course=?,section_name=?,year_level=NULL,cms_section_id=0,is_active=1,synced_at=NOW() WHERE id=?')->execute([$course,$sectionName,$existingId]);
            return;
        }
        $db->prepare('UPDATE borrower_enrollments
                      SET course = ?, section_name = ?, year_level = COALESCE(NULLIF(?, ""), year_level),
                          cms_section_id = ?, is_active = 1, synced_at = NOW()
                      WHERE id = ?')
           ->execute([$course, $sectionName, $yearLevel, $cmsSectionId, $existingId]);
    } else {
        $db->prepare('INSERT INTO borrower_enrollments
                        (borrower_id, cms_section_id, cms_subject_id, course, section_name, year_level, subject_code, subject_name, is_active)
                      VALUES (?, ?, 0, ?, ?, ?, "", "Manual Section Enrollment", 1)')
           ->execute([$borrowerId, $cmsSectionId, $course, $sectionName, $yearLevel === '' ? null : $yearLevel]);
    }
}

// ── POST (Create) ─────────────────────────────────────────────
if ($method === 'POST') {
    $b = body();

    $full_name = trim($b['full_name'] ?? '');
    $id_number = trim($b['id_number'] ?? '');
    $type      = trim($b['type']      ?? '');
    $email     = trim($b['email']     ?? '');
    $phone     = trim($b['phone']     ?? '');

    if (!$full_name || !$id_number || !$type) {
        fail('Full name, ID number, and type are required.');
    }
    if (!in_array($type, ['Student', 'Faculty', 'Staff', 'Guest'])) {
    fail("Invalid type. Must be Student, Faculty, Guest or Staff.");
}
    if($type==='Student'){
        $student=normalizeStudent(array_merge($b,['full_name'=>$full_name,'id_number'=>$id_number,'phone'=>$phone]));
        if($student['errors'])fail(implode(' ',$student['errors']),422);
        $full_name=$student['full_name'];$id_number=$student['id_number'];$phone=$student['phone'];
        $b=array_merge($b,['course'=>$student['course'],'section_name'=>$student['section_name'],'year_level'=>$student['year_level']]);
    }else{$id_number=validateIdNumber($type,$id_number);}

    // Duplicate ID check
    $check = $db->prepare('SELECT id FROM borrowers WHERE id_number = ?');
    $check->execute([$id_number]);
    if ($check->fetch()) fail("ID number '$id_number' already exists.");

    $db->beginTransaction();
    try{
        $stmt=$db->prepare('INSERT INTO borrowers(full_name,id_number,type,email,phone) VALUES (?,?,?,?,?)');
        $stmt->execute([$full_name,$id_number,$type,$email,$phone]);
        $id=(int)$db->lastInsertId();
        saveManualEnrollment($db,$id,$b);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062)fail('Student ID / ID number already exists. Please use a unique ID.',409);
        error_log('Borrower create failed: '.$e->getMessage());fail('Could not save borrower. Nothing was saved; please try again.',500);
    }
    $stmt = $db->prepare('SELECT * FROM borrowers WHERE id = ?');
    $stmt->execute([$id]);

    logAudit('borrower_create', "Added borrower $full_name ($id_number, $type)");

    ok($stmt->fetch(), 201);
}

// ── PUT (Update) ──────────────────────────────────────────────
if ($method === 'PUT') {
    $b = body();

    $id        = (int)($b['id']        ?? 0);
    $full_name = trim($b['full_name'] ?? '');
    $id_number = trim($b['id_number'] ?? '');
    $phone     = trim($b['phone']     ?? '');

    if (!$id || !$full_name || !$id_number) {
        fail('ID, full name, and ID number are required.');
    }

    // Check exists — and load what's stored, since type is locked
    // after creation and email isn't part of the edit form.
    $existing = $db->prepare('SELECT id, type, email FROM borrowers WHERE id = ?');
    $existing->execute([$id]);
    $current = $existing->fetch();
    if (!$current) fail('Borrower not found.', 404);

    // Type can only be chosen when the borrower is created. Enforced
    // here, not just by disabling the dropdown in the UI, so a direct
    // API call can't change it either.
    $type = $current['type'];
    if (isset($b['type']) && trim($b['type']) !== '' && trim($b['type']) !== $type) {
        fail('Borrower type cannot be changed after creation.');
    }

    // Email was removed from the edit form — keep whatever is stored
    // unless a request explicitly supplies one.
    $email = array_key_exists('email', $b) ? trim($b['email']) : ($current['email'] ?? '');

    if($type==='Student'){
        $student=normalizeStudent(array_merge($b,['full_name'=>$full_name,'id_number'=>$id_number,'phone'=>$phone]));
        if($student['errors'])fail(implode(' ',$student['errors']),422);
        $full_name=$student['full_name'];$id_number=$student['id_number'];$phone=$student['phone'];
        $b=array_merge($b,['course'=>$student['course'],'section_name'=>$student['section_name'],'year_level'=>$student['year_level']]);
    }else{$id_number=validateIdNumber($type,$id_number);}

    // Duplicate ID number (excluding self)
    $check = $db->prepare('SELECT id FROM borrowers WHERE id_number = ? AND id != ?');
    $check->execute([$id_number, $id]);
    if ($check->fetch()) fail("ID number '$id_number' is already used by another borrower.");

    $db->beginTransaction();
    try{
        $stmt=$db->prepare('UPDATE borrowers SET full_name=?,id_number=?,email=?,phone=? WHERE id=?');
        $stmt->execute([$full_name,$id_number,$email,$phone,$id]);
        saveManualEnrollment($db,$id,$b);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062)fail('Student ID / ID number is already used by another borrower.',409);
        error_log('Borrower update failed: '.$e->getMessage());fail('Could not update borrower. Nothing was changed; please try again.',500);
    }
    $stmt = $db->prepare('SELECT * FROM borrowers WHERE id = ?');
    $stmt->execute([$id]);
    logAudit('borrower_edit', "Edited borrower $full_name ($id_number)");
    ok($stmt->fetch());
}

// ── PATCH (Activate / Deactivate) ──────────────────────────────
// Admin-only, manual override of is_active — separate from the
// CMS sync flow (receive_student_deletion.php) which does the same
// thing automatically when a student is deleted in the CMS.
if ($method === 'PATCH') {
    $b  = body();

    // ── Bulk "Update Sections": { course, sections:[{id, section_name, cms_section_id}] }
    // Each student gets their own new section (classmates can split up).
    // Written to the borrower's MANUAL enrollment (cms_subject_id = 0); the
    // list/edit queries prefer that row over CMS-synced ones, so the new
    // section is what actually shows up everywhere.
    if (isset($b['sections']) && is_array($b['sections'])) {
        $course = trim($b['course'] ?? '');
        if ($course === '') fail('Course is required.');
        if (!$b['sections']) fail('No section changes were sent.');

        $find    = $db->prepare('SELECT id, full_name, type FROM borrowers WHERE id = ?');
        $updated = 0;
        $names   = [];

        $db->beginTransaction();
        try {
            foreach ($b['sections'] as $row) {
                $sid  = (int)($row['id'] ?? 0);
                $name = trim($row['section_name'] ?? '');
                if (!$sid || $name === '') continue;

                $find->execute([$sid]);
                $stu = $find->fetch();
                if (!$stu) continue;
                if ($stu['type'] !== 'Student') continue;   // only Students have sections

                saveManualEnrollment($db, $sid, [
                    'course'         => $course,
                    'section_name'   => $name,
                    'cms_section_id' => (int)($row['cms_section_id'] ?? 0),
                ]);
                $updated++;
                $names[] = $stu['full_name'] . " → $name";
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            fail('Could not update sections: ' . $e->getMessage(), 500);
        }

        logAudit('borrower_edit', "Updated sections ($course) for $updated student(s): " . implode(', ', array_slice($names, 0, 10)) . (count($names) > 10 ? '…' : ''));
        ok(['updated' => $updated]);
    }

    $id = (int)($b['id'] ?? 0);
    if (!$id || !array_key_exists('is_active', $b)) {
        fail('id and is_active are required.');
    }
    $isActive = $b['is_active'] ? 1 : 0;

    $existing = $db->prepare('SELECT id, full_name FROM borrowers WHERE id = ?');
    $existing->execute([$id]);
    $existingRow = $existing->fetch();
    if (!$existingRow) fail('Borrower not found.', 404);

    $stmt = $db->prepare('UPDATE borrowers SET is_active = ? WHERE id = ?');
    $stmt->execute([$isActive, $id]);

    $stmt = $db->prepare('SELECT * FROM borrowers WHERE id = ?');
    $stmt->execute([$id]);
    logAudit($isActive ? 'borrower_reactivate' : 'borrower_deactivate', ($isActive ? 'Reactivated' : 'Deactivated') . " borrower {$existingRow['full_name']}");
    ok($stmt->fetch());
}

// Deliberately no DELETE handler — borrower records are never hard-
// deleted, to preserve transaction history (deleting used to NULL
// out borrower_id on every past transaction). Use PATCH above to
// deactivate a borrower instead.

fail('Method not allowed.', 405);
