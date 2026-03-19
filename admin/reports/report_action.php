<?php
// admin/reports/report_action.php (final optimized build)
// ✅ Fixes included:
//   - Header-aware CSRF verification
//   - Clean JSON outputs (no "Invalid JSON response" or Ajax error)
//   - Proper DataTables structure for both students & scores
//   - Lazy include for render_student_report.php (no stray output)
//   - Safe debug mode (__debug_output) for diagnosing issues

declare(strict_types=1);
ob_start();

session_start();
require_once __DIR__ . '/../../includes/db.php';
use Mpdf\Mpdf;

// --------------------------------------------------
// Utility Functions
// --------------------------------------------------
function json_exit($data, int $http = 200): void {
    $extra = '';
    if (ob_get_length() !== false) {
        $extra = (string) ob_get_clean();
    }
    if ($extra !== '') {
        $debug = trim($extra);
        if (strlen($debug) > 4000) $debug = substr($debug, 0, 4000) . '... (truncated)';
        $data['__debug_output'] = $debug;
    }
    if (!headers_sent()) {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function i($v){ return intval($v ?? 0); }
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function fmt($v){ return is_numeric($v) ? rtrim(rtrim(number_format((float)$v,2,'.',''),'0'),'.') : $v; }

// CSRF check with header support
function check_csrf(): bool {
    $token = $_POST['csrf_token'] ?? $_REQUEST['csrf_token'] ?? '';
    if (empty($token)) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) && function_exists('getallheaders')) {
            $h = getallheaders();
            $token = $h['X-CSRF-Token'] ?? $h['X-Csrf-Token'] ?? '';
        }
    }
    return !empty($token) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// --------------------------------------------------
// Security Check
// --------------------------------------------------
if (empty($_SESSION['user_id'])) json_exit(['success'=>false,'msg'=>'Unauthorized'],403);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !check_csrf())
    json_exit(['success'=>false,'msg'=>'Invalid CSRF token'],403);

$action = $_REQUEST['action'] ?? '';
$user_id = (int)$_SESSION['user_id'];

// --------------------------------------------------
// 1️⃣ list_students
// --------------------------------------------------
if ($action === 'list_students') {
    $class_id = i($_REQUEST['class_id'] ?? 0);
    $search = trim($_POST['search']['value'] ?? '');
    $start = i($_POST['start'] ?? 0);
    $length = i($_POST['length'] ?? 10);

    $where = [];
    $params = [];
    $types = '';

    if ($class_id) { $where[] = 's.class_id=?'; $params[] = $class_id; $types .= 'i'; }
    if ($search !== '') {
        $where[] = "(s.first_name LIKE CONCAT('%',?,'%') OR s.last_name LIKE CONCAT('%',?,'%') OR s.admission_no LIKE CONCAT('%',?,'%'))";
        $params = array_merge($params, [$search, $search, $search]);
        $types .= 'sss';
    }
    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

    $total = $conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'] ?? 0;

    $sqlCount = "SELECT COUNT(*) AS c FROM students s LEFT JOIN classes c ON s.class_id=c.id $whereSql";
    $stmt = $conn->prepare($sqlCount);
    if ($params && $types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $filtered = $stmt->get_result()->fetch_assoc()['c'] ?? 0;
    $stmt->close();

    $sql = "SELECT s.id, s.admission_no, CONCAT(s.first_name,' ',s.last_name) AS name,
                   s.gender, s.dob, COALESCE(s.photo_path,'') AS photo,
                   c.class_name, s.class_id
            FROM students s
            LEFT JOIN classes c ON s.class_id=c.id
            $whereSql
            ORDER BY s.first_name, s.last_name
            LIMIT ?, ?";
    $params[] = $start; $params[] = $length; $types .= 'ii';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $data = [];
    $idx = $start + 1;
    foreach ($rows as $r) {
        $data[] = [
            'idx' => $idx++,
            'id' => (int)$r['id'],
            'admission_no' => e($r['admission_no']),
            // return a thumbnail URL (safe) to reduce request size in the UI
            'photo' => e($r['photo'] ? '/admin/reports/thumbnail.php?f=' . urlencode($r['photo']) . '&w=96&h=96' : '/assets/images/placeholder.png'),
            'name' => e($r['name']),
            'gender' => e($r['gender']),
            'dob' => e($r['dob']),
            'class_name' => e($r['class_name']),
            'class_id' => (int)$r['class_id']
        ];
    }

    json_exit([
        'draw' => i($_POST['draw'] ?? 1),
        'recordsTotal' => (int)$total,
        'recordsFiltered' => (int)$filtered,
        'data' => $data
    ]);
}

// --------------------------------------------------
// 2️⃣ list_scores_preview (fixed for subject + Ajax error)
// --------------------------------------------------
if ($action === 'list_scores_preview') {
    $class_id = i($_REQUEST['class_id']);
    $term_id  = i($_REQUEST['term_id']);
    $year_id  = i($_REQUEST['year_id']);

    // If required filters are not provided, return an empty data array (DataTables expects valid JSON)
    if (!$class_id || !$term_id || !$year_id) {
        json_exit(['success' => true, 'data' => []]);
    }

    $sql = "
        SELECT s.id AS student_id, CONCAT(s.first_name,' ',s.last_name) AS student,
               c.class_name, ss.total_marks AS total, ss.position,
               t.term_name AS term, ay.year_label AS year
        FROM scores_summary ss
        JOIN students s ON ss.student_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN terms t ON ss.term_id = t.id
        LEFT JOIN academic_years ay ON ss.year_id = ay.id
        WHERE ss.class_id=? AND ss.term_id=? AND ss.year_id=?
        ORDER BY ss.position ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $class_id, $term_id, $year_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $data = [];
    $idx = 1;
    foreach ($rows as $r) {
        $data[] = [
            'idx' => $idx++,
            'student' => e($r['student']),
            'subject' => '—',
            'total' => isset($r['total']) ? (float)$r['total'] : 0,
            'position' => $r['position'] ?? '',
            'class_name' => e($r['class_name']),
            'term' => e($r['term']),
            'year' => e($r['year'])
        ];
    }

    json_exit(['success'=>true,'data'=>$data]);
}

// --------------------------------------------------
// 3️⃣ get_meta
// --------------------------------------------------
if ($action === 'get_meta') {
    $sid = i($_REQUEST['student_id']); $cid=i($_REQUEST['class_id']);
    $tid=i($_REQUEST['term_id']); $yid=i($_REQUEST['year_id']);
    $st=$conn->prepare("SELECT present_days,total_days,attendance_percent,class_teacher_remark,head_teacher_remark,attitude,interest,promotion_status,vacation_date,next_term_begins FROM report_meta WHERE student_id=? AND class_id=? AND term_id=? AND year_id=? LIMIT 1");
    $st->bind_param('iiii',$sid,$cid,$tid,$yid); $st->execute();
    $row=$st->get_result()->fetch_assoc(); $st->close();
    json_exit(['success'=>true,'data'=>$row ?: null]);
}

// --------------------------------------------------
// 4️⃣ save_meta
// --------------------------------------------------
if ($action === 'save_meta') {
    $sid=i($_POST['student_id']); $cid=i($_POST['class_id']);
    $tid=i($_POST['term_id']); $yid=i($_POST['year_id']);
    if(!$sid||!$cid||!$tid||!$yid) json_exit(['success'=>false,'msg'=>'Missing IDs'],400);

    $p=i($_POST['present_days']); $t=i($_POST['total_days']);
    $percent=$t?round(($p/$t)*100,2):0;
    $ctr=trim($_POST['class_teacher_remark']??''); $hdr=trim($_POST['head_teacher_remark']??'');
    $att=trim($_POST['attitude']??''); $int=trim($_POST['interest']??'');
    $pro=trim($_POST['promotion_status']??''); $vac=$_POST['vacation_date']??null; $nxt=$_POST['next_term_begins']??null;

    $sql="INSERT INTO report_meta (student_id,class_id,term_id,year_id,present_days,total_days,attendance_percent,
          class_teacher_remark,head_teacher_remark,attitude,interest,promotion_status,vacation_date,next_term_begins)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
          present_days=VALUES(present_days),total_days=VALUES(total_days),
          attendance_percent=VALUES(attendance_percent),class_teacher_remark=VALUES(class_teacher_remark),
          head_teacher_remark=VALUES(head_teacher_remark),attitude=VALUES(attitude),interest=VALUES(interest),
          promotion_status=VALUES(promotion_status),vacation_date=VALUES(vacation_date),
          next_term_begins=VALUES(next_term_begins),updated_at=CURRENT_TIMESTAMP";
    $st=$conn->prepare($sql);
    $st->bind_param('iiiiddssssssss',$sid,$cid,$tid,$yid,$p,$t,$percent,$ctr,$hdr,$att,$int,$pro,$vac,$nxt);
    $ok=$st->execute(); $st->close();
    json_exit(['success'=>$ok,'msg'=>$ok?'Saved successfully':'Database error']);
}

// --------------------------------------------------
// 5️⃣ generate_class_pdf
// --------------------------------------------------
if ($action === 'generate_class_pdf') {
    require_once __DIR__ . '/render_student_report.php'; // ✅ Lazy include
    $class_id=i($_POST['class_id']); $term_id=i($_POST['term_id']); $year_id=i($_POST['year_id']);
    if(!$class_id||!$term_id||!$year_id) json_exit(['success'=>false,'msg'=>'Missing parameters'],400);

    $st=$conn->prepare("SELECT id FROM students WHERE class_id=? ORDER BY first_name,last_name");
    $st->bind_param('i',$class_id); $st->execute();
    $students=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

    if(!$students) json_exit(['success'=>false,'msg'=>'No students found'],404);

    $mpdf = new Mpdf(['mode'=>'utf-8','format'=>'A4','margin_top'=>35,'margin_bottom'=>25,'tempDir'=>__DIR__.'/../../tmp']);
    // try to shrink tables/content to fit on each A4 page
    $mpdf->shrink_tables_to_fit = 1;
    $mpdf->packTableData = true;
    $header = '<div style="border-bottom:2px solid #003;padding-bottom:4px;"><b>Class Reports</b></div>';
    $footer = '<div style="border-top:1px solid #aaa;font-size:10;text-align:center">Generated '.date('d M Y H:i').' | Page {PAGENO}/{nbpg}</div>';
    $mpdf->SetHTMLHeader($header); $mpdf->SetHTMLFooter($footer);

    $first = true;
    foreach ($students as $s) {
        $html = render_student_html($conn, (int)$s['id'], $class_id, $term_id, $year_id, ['for_pdf'=>true]);
        if(!$first) $mpdf->AddPage();
        $mpdf->WriteHTML($html);
        $first=false;
    }

    if (ob_get_length()) ob_end_clean();
    $className = $conn->query("SELECT class_name FROM classes WHERE id=$class_id")->fetch_assoc()['class_name'] ?? 'class';
    $filename = preg_replace('/[^A-Za-z0-9_-]/','_',$className)."_Reports.pdf";
    $mpdf->Output($filename,'I');
    exit;
}

// --------------------------------------------------
// 6️⃣ class_preview
// --------------------------------------------------
if ($action === 'class_preview') {
    $class_id=i($_REQUEST['class_id']); $term_id=i($_REQUEST['term_id']); $year_id=i($_REQUEST['year_id']);
    if(!$class_id||!$term_id||!$year_id) json_exit(['success'=>false,'msg'=>'Missing params'],400);
    $url=sprintf('admin/reports/render_student_report.php?preview=class&class_id=%d&term_id=%d&year_id=%d',$class_id,$term_id,$year_id);
    json_exit(['success'=>true,'preview_url'=>$url]);
}

// --------------------------------------------------
// Unknown action
// --------------------------------------------------
json_exit(['success'=>false,'msg'=>'Unknown action'],400);

// Helper: produce a web-ready thumbnail path for the front-end
function photo_web(string $photo_val): string {
    $default = '/assets/images/default_user.png';
    $uploads_rel = '/uploads/';
    $photo_val = trim((string)$photo_val);
    if ($photo_val === '') return $default;
    if (preg_match('~^https?://~i', $photo_val)) return $photo_val;
    if (str_starts_with($photo_val, '/')) return $photo_val;
    // otherwise treat as uploads filename
    return $uploads_rel . ltrim($photo_val, '/\\');
}
