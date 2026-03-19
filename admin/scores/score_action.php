
<?php
// admin/scores/score_action.php
// PHP-based scoring backend: calculates grade/remark & positions in PHP and writes final values into DB.
// - Enforces MAX_CA / MAX_EXAM
// - Updates scores and scores_summary
// - CSRF checks for POST
// - Client logging endpoint (log_error)
// - Logs server errors to /logs/scores_error.log

session_start();
require_once __DIR__ . '/../../includes/db.php'; // expects $conn (mysqli)

define('MAX_CA', 50);
define('MAX_EXAM', 50);

// logs dir (centralized)
$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
$logFile = $logDir . '/scores_error.log';

function append_log($line) {
    global $logFile;
    @file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] '.$line.PHP_EOL, FILE_APPEND | LOCK_EX);
}

function reply_json(array $payload, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    append_log('DB connection missing');
    reply_json(['status'=>'error','message'=>'Database connection error'], 500);
}

// basic auth
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) reply_json(['status'=>'error','message'=>'Unauthorized'], 401);

// CSRF enforcement for POST
function check_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_REQUEST['csrf_token'] ?? null;
        if (!$token || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            reply_json(['status'=>'error','message'=>'Invalid CSRF token'], 400);
        }
    }
}

// helper: safe fetch all assoc for prepared statements
function stmt_fetch_all_assoc(mysqli_stmt $stmt) {
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    $stmt->store_result();
    $meta = $stmt->result_metadata();
    if (!$meta) return [];
    $fields = []; $row = []; $params = [];
    while ($f = $meta->fetch_field()) { $fields[] = $f->name; $row[$f->name] = null; $params[] = &$row[$f->name]; }
    if ($params) call_user_func_array([$stmt, 'bind_result'], $params);
    $out = [];
    while ($stmt->fetch()) {
        $copy = []; foreach ($fields as $name) $copy[$name] = $row[$name]; $out[] = $copy;
    }
    return $out;
}

// helper: get grade & remark for a given total from grading_scale
function grade_for_total(mysqli $conn, int $total) {
    $sql = "SELECT grade, remark FROM grading_scale WHERE ? BETWEEN min_score AND max_score ORDER BY rank_order ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        append_log('grade_for_total prepare failed: '.$conn->error);
        return ['grade' => null, 'remark' => null];
    }
    $stmt->bind_param('i', $total);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) return ['grade' => $row['grade'], 'remark' => $row['remark']];
    return ['grade' => null, 'remark' => null];
}

// rebuild scores_summary for a given class, term, year (aggregate totals, compute average & position)
function rebuild_scores_summary(mysqli $conn, int $class_id, int $term_id, int $year_id) {
    // remove existing summary entries for this class/term/year
    $del = $conn->prepare("DELETE FROM scores_summary WHERE class_id=? AND term_id=? AND year_id=?");
    if ($del) { $del->bind_param('iii', $class_id, $term_id, $year_id); $del->execute(); $del->close(); }

    // aggregate totals per student (only students in this class)
    $sql = "SELECT s.id AS student_id, SUM(sc.class_score + sc.exam_score) AS total_marks, AVG(sc.class_score + sc.exam_score) AS average_score
            FROM scores sc
            JOIN students s ON sc.student_id = s.id
            WHERE s.class_id = ? AND sc.term_id = ? AND sc.year_id = ?
            GROUP BY sc.student_id
            ORDER BY total_marks DESC, average_score DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { append_log('rebuild prepare failed: '.$conn->error); return false; }
    $stmt->bind_param('iii', $class_id, $term_id, $year_id);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
    $stmt->close();

    // insert/update into scores_summary with positions
    $insert = $conn->prepare("INSERT INTO scores_summary (student_id, class_id, term_id, year_id, total_marks, average, position, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                              ON DUPLICATE KEY UPDATE total_marks=VALUES(total_marks), average=VALUES(average), position=VALUES(position), updated_at=NOW()");
    if (!$insert) { append_log('rebuild insert prepare failed: '.$conn->error); return false; }

    $pos = 1;
    foreach ($rows as $r) {
        $student_id = (int)$r['student_id'];
        $total_marks = (int)$r['total_marks'];
        $average = round(floatval($r['average_score']), 2);
        $insert->bind_param('iiiiiii', $student_id, $class_id, $term_id, $year_id, $total_marks, $average, $pos);
        $insert->execute();
        if ($insert->errno) append_log('scores_summary insert error for student '.$student_id.': '.$insert->error);
        $pos++;
    }
    $insert->close();
    return true;
}

// dispatch
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        case 'log_error':
            // client-side logging (best-effort)
            $context = $_POST['context'] ?? 'client';
            $status = $_POST['status'] ?? 'n/a';
            $message = $_POST['message'] ?? '';
            $payload = json_encode(['time'=>date('c'),'ip'=>$_SERVER['REMOTE_ADDR'] ?? 'n/a','user'=>$user_id,'context'=>$context,'status'=>$status,'message'=>$message]);
            append_log('[client] '.$payload);
            echo json_encode(['status'=>'logged']);
            exit;

        case 'fetch_students':
            check_csrf();
            $class_id = (int)($_POST['class_id'] ?? 0);
            $subject_id = (int)($_POST['subject_id'] ?? 0);
            $year_id = (int)($_POST['year_id'] ?? 0);
            $term_id = (int)($_POST['term_id'] ?? 0);

            if (!$class_id || !$subject_id || !$year_id || !$term_id) reply_json(['status'=>'error','message'=>'Missing parameters'], 400);

            $sql = "SELECT st.id AS student_id, CONCAT(COALESCE(st.first_name,''),' ',COALESCE(st.last_name,'')) AS name,
                           COALESCE(sc.class_score,0) AS class_score, COALESCE(sc.exam_score,0) AS exam_score
                    FROM students st
                    LEFT JOIN scores sc ON sc.student_id = st.id AND sc.subject_id = ? AND sc.term_id = ? AND sc.year_id = ?
                    WHERE st.class_id = ?
                    ORDER BY st.first_name, st.last_name";
            $stmt = $conn->prepare($sql);
            if (!$stmt) { append_log('fetch_students prepare failed: '.$conn->error); reply_json(['status'=>'error','message'=>'DB error'],500); }
            $stmt->bind_param('iiii', $subject_id, $term_id, $year_id, $class_id);
            $stmt->execute();
            $rows = stmt_fetch_all_assoc($stmt);
            $stmt->close();

            $students = [];
            foreach ($rows as $r) {
                $students[] = [
                    'id' => (int)($r['student_id'] ?? 0),
                    'name' => $r['name'] ?? '',
                    'class_score' => (int)($r['class_score'] ?? 0),
                    'exam_score' => (int)($r['exam_score'] ?? 0)
                ];
            }
            reply_json(['status'=>'success','students'=>$students]);
            break;

        case 'save_bulk_scores':
            check_csrf();
            $class_id = (int)($_POST['class_id'] ?? 0);
            $subject_id = (int)($_POST['subject_id'] ?? 0);
            $year_id = (int)($_POST['year_id'] ?? 0);
            $term_id = (int)($_POST['term_id'] ?? 0);
            $scores_json = $_POST['scores'] ?? '[]';
            $scores = json_decode($scores_json, true);

            if (!$class_id || !$subject_id || !$year_id || !$term_id || !is_array($scores)) reply_json(['status'=>'error','message'=>'Invalid input'], 400);

            $conn->begin_transaction();

            $insert_sql = "INSERT INTO scores (student_id, subject_id, class_score, exam_score, grade, remark, term_id, year_id, uploaded_by, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                           ON DUPLICATE KEY UPDATE class_score=VALUES(class_score), exam_score=VALUES(exam_score), grade=VALUES(grade), remark=VALUES(remark), uploaded_by=VALUES(uploaded_by), updated_at=NOW()";
            $stmt = $conn->prepare($insert_sql);
            if (!$stmt) { $conn->rollback(); append_log('save_bulk prepare failed: '.$conn->error); reply_json(['status'=>'error','message'=>'DB prepare failed'],500); }

            $rowsInserted = 0;
            foreach ($scores as $s) {
                $student_id = (int)($s['student_id'] ?? 0);
                $class_score = is_numeric($s['class_score']) ? (int)$s['class_score'] : 0;
                $exam_score = is_numeric($s['exam_score']) ? (int)$s['exam_score'] : 0;

                if ($student_id <= 0) continue;
                if ($class_score < 0 || $exam_score < 0) continue;
                if ($class_score > MAX_CA || $exam_score > MAX_EXAM) {
                    $conn->rollback();
                    reply_json(['status'=>'error','message'=>"Scores exceed allowed maximum (CA: ".MAX_CA.", Exam: ".MAX_EXAM.")"], 400);
                }

                $total = $class_score + $exam_score;
                $g = grade_for_total($conn, $total);
                $grade = $g['grade'] ?? null;
                $remark = $g['remark'] ?? null;

                // bind values: student_id(i), subject_id(i), class_score(i), exam_score(i), grade(s), remark(s), term_id(i), year_id(i), uploaded_by(i)
                $stmt->bind_param('iiiissiii', $student_id, $subject_id, $class_score, $exam_score, $grade, $remark, $term_id, $year_id, $user_id);
                $stmt->execute();
                if ($stmt->errno) {
                    append_log('scores insert error for student '.$student_id.': '.$stmt->error);
                } else {
                    $rowsInserted++;
                }
            }
            $stmt->close();

            // rebuild summary for this class/term/year
            if (!rebuild_scores_summary($conn, $class_id, $term_id, $year_id)) {
                append_log('Failed to rebuild_scores_summary for class '.$class_id.' term '.$term_id.' year '.$year_id);
            }

            $conn->commit();
            reply_json(['status'=>'success','inserted_rows'=>$rowsInserted]);
            break;

        case 'load_scores':
            check_csrf();
            $class_id = (int)($_POST['class_id'] ?? 0);
            $subject_id = (int)($_POST['subject_id'] ?? 0);
            $year_id = (int)($_POST['year_id'] ?? 0);
            $term_id = (int)($_POST['term_id'] ?? 0);

            $where = " WHERE 1=1 ";
            $params = []; $types = '';
            if ($class_id)  { $where .= " AND st.class_id = ? "; $params[] = $class_id; $types .= 'i'; }
            if ($subject_id){ $where .= " AND sc.subject_id = ? "; $params[] = $subject_id; $types .= 'i'; }
            if ($year_id)   { $where .= " AND sc.year_id = ? "; $params[] = $year_id; $types .= 'i'; }
            if ($term_id)   { $where .= " AND sc.term_id = ? "; $params[] = $term_id; $types .= 'i'; }

            $sql = "SELECT sc.id, CONCAT(COALESCE(st.first_name,''),' ',COALESCE(st.last_name,'')) AS student, c.class_name AS class,
                           s.name AS subject, t.term_name AS term, y.year_label AS year,
                           sc.class_score, sc.exam_score, sc.total, sc.grade, sc.remark
                    FROM scores sc
                    JOIN students st ON sc.student_id = st.id
                    JOIN classes c ON st.class_id = c.id
                    JOIN subjects s ON sc.subject_id = s.id
                    JOIN terms t ON sc.term_id = t.id
                    JOIN academic_years y ON sc.year_id = y.id
                    $where
                    ORDER BY st.first_name, st.last_name";
            $stmt = $conn->prepare($sql);
            if (!$stmt) { append_log('load_scores prepare failed: '.$conn->error); reply_json(['status'=>'error','message'=>'DB prepare failed'],500); }

            if (!empty($params)) {
                // bind dynamically
                $bind_names = [];
                $bind_names[] = $types;
                for ($i=0;$i<count($params);$i++) { $bind_name = 'p'.$i; $$bind_name = $params[$i]; $bind_names[] = &$$bind_name; }
                call_user_func_array([$stmt, 'bind_param'], $bind_names);
            }

            $stmt->execute();
            $rows = stmt_fetch_all_assoc($stmt);
            $stmt->close();

            $data = [];
            foreach ($rows as $r) {
                $r['class_score'] = isset($r['class_score']) ? (int)$r['class_score'] : 0;
                $r['exam_score'] = isset($r['exam_score']) ? (int)$r['exam_score'] : 0;
                $r['total'] = isset($r['total']) ? (int)$r['total'] : ($r['class_score'] + $r['exam_score']);
                $row_json = json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT);
                $r['action'] = '<button class="btn btn-sm btn-primary editScoreBtn" data-row=\'' . $row_json . '\'>Edit</button>';
                $data[] = $r;
            }
            reply_json(['data'=>$data]);
            break;

        case 'edit_score':
            check_csrf();
            $id = (int)($_POST['id'] ?? 0);
            $class_score = isset($_POST['class_score']) ? (int)$_POST['class_score'] : null;
            $exam_score = isset($_POST['exam_score']) ? (int)$_POST['exam_score'] : null;

            if ($id <= 0 || $class_score === null || $exam_score === null) reply_json(['status'=>'error','message'=>'Invalid input'],400);
            if ($class_score > MAX_CA || $exam_score > MAX_EXAM) reply_json(['status'=>'error','message'=>"Scores exceed allowed maximum (CA: ".MAX_CA.", Exam: ".MAX_EXAM.")"],400);

            $total = $class_score + $exam_score;
            $g = grade_for_total($conn, $total);
            $grade = $g['grade'] ?? null;
            $remark = $g['remark'] ?? null;

            $stmt = $conn->prepare("UPDATE scores SET class_score=?, exam_score=?, grade=?, remark=?, uploaded_by=?, updated_at=NOW() WHERE id=?");
            if (!$stmt) { append_log('edit_score prepare failed: '.$conn->error); reply_json(['status'=>'error','message'=>'DB prepare failed'],500); }
            $stmt->bind_param('iissii', $class_score, $exam_score, $grade, $remark, $user_id, $id);
            $stmt->execute();
            if ($stmt->errno) append_log('edit_score execute error: '.$stmt->error);
            $stmt->close();

            // find student's class and rebuild summary
            $stmt2 = $conn->prepare("SELECT student_id, term_id, year_id FROM scores WHERE id=? LIMIT 1");
            if ($stmt2) {
                $stmt2->bind_param('i', $id);
                $stmt2->execute();
                $rows2 = stmt_fetch_all_assoc($stmt2);
                $stmt2->close();
                if (!empty($rows2)) {
                    $r = $rows2[0];
                    $student_id = (int)$r['student_id'];
                    $term_id = (int)$r['term_id'];
                    $year_id = (int)$r['year_id'];
                    $res = $conn->query("SELECT class_id FROM students WHERE id = " . $conn->real_escape_string($student_id) . " LIMIT 1");
                    if ($res && $row = $res->fetch_assoc()) {
                        $class_id = (int)$row['class_id'];
                        if (!rebuild_scores_summary($conn, $class_id, $term_id, $year_id)) {
                            append_log('Failed to rebuild summary after edit for class '.$class_id);
                        }
                    }
                }
            }
            reply_json(['status'=>'success']);
            break;

        case 'export_scores':
            // CSV export (GET)
            $class_id = (int)($_GET['class_id'] ?? 0);
            $subject_id = (int)($_GET['subject_id'] ?? 0);
            $year_id = (int)($_GET['year_id'] ?? 0);
            $term_id = (int)($_GET['term_id'] ?? 0);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="scores_export.csv"');

            $out = fopen('php://output', 'w');
            fputcsv($out, ['Student', 'Class', 'Subject', 'Term', 'Year', 'Class Score', 'Exam Score', 'Total', 'Grade', 'Remark']);

            $where = " WHERE 1=1 ";
            $params = []; $types = '';
            if ($class_id)  { $where .= " AND st.class_id = ? "; $params[] = $class_id; $types .= 'i'; }
            if ($subject_id){ $where .= " AND sc.subject_id = ? "; $params[] = $subject_id; $types .= 'i'; }
            if ($year_id)   { $where .= " AND sc.year_id = ? "; $params[] = $year_id; $types .= 'i'; }
            if ($term_id)   { $where .= " AND sc.term_id = ? "; $params[] = $term_id; $types .= 'i'; }

            $sql = "SELECT CONCAT(COALESCE(st.first_name,''),' ',COALESCE(st.last_name,'')) AS student, c.class_name AS class,
                    s.name AS subject, t.term_name AS term, y.year_label AS year,
                    sc.class_score, sc.exam_score, sc.total, sc.grade, sc.remark
                    FROM scores sc
                    JOIN students st ON sc.student_id=st.id
                    JOIN classes c ON st.class_id=c.id
                    JOIN subjects s ON sc.subject_id=s.id
                    JOIN terms t ON sc.term_id=t.id
                    JOIN academic_years y ON sc.year_id=y.id
                    $where
                    ORDER BY st.first_name, st.last_name";

            $stmt = $conn->prepare($sql);
            if (!$stmt) { fputcsv($out, ['error', $conn->error]); fclose($out); exit; }
            if (!empty($params)) {
                $bind_names = [];
                $bind_names[] = $types;
                for ($i=0;$i<count($params);$i++) { $bind_name = 'p'.$i; $$bind_name = $params[$i]; $bind_names[] = &$$bind_name; }
                call_user_func_array([$stmt, 'bind_param'], $bind_names);
            }
            $stmt->execute();
            $rows = stmt_fetch_all_assoc($stmt);
            foreach ($rows as $r) {
                $row = [
                    $r['student'] ?? '', $r['class'] ?? '', $r['subject'] ?? '', $r['term'] ?? '', $r['year'] ?? '',
                    $r['class_score'] ?? 0, $r['exam_score'] ?? 0, $r['total'] ?? 0, $r['grade'] ?? '', $r['remark'] ?? ''
                ];
                fputcsv($out, $row);
            }
            fclose($out);
            exit;

        case 'import_scores':
            check_csrf();
            if (empty($_FILES['scores_file']['tmp_name'])) reply_json(['status'=>'error','message'=>'No file uploaded'],400);
            $file = $_FILES['scores_file']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['scores_file']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'csv') reply_json(['status'=>'error','message'=>'Only CSV supported'],400);

            $handle = fopen($file, 'r');
            if (!$handle) reply_json(['status'=>'error','message'=>'Unable to read uploaded file'],500);

            $header = fgetcsv($handle);
            $expectedCols = 6;
            $rowsProcessed = 0;
            $conn->begin_transaction();

            $insert_sql = "INSERT INTO scores (student_id, subject_id, class_score, exam_score, grade, remark, term_id, year_id, uploaded_by, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                           ON DUPLICATE KEY UPDATE class_score=VALUES(class_score), exam_score=VALUES(exam_score), grade=VALUES(grade), remark=VALUES(remark), uploaded_by=VALUES(uploaded_by), updated_at=NOW()";
            $stmt = $conn->prepare($insert_sql);
            if (!$stmt) { $conn->rollback(); append_log('import prepare failed: '.$conn->error); reply_json(['status'=>'error','message'=>'DB prepare failed'],500); }

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < $expectedCols) continue;
                $student_id = (int)$row[0]; $subject_id = (int)$row[1];
                $class_score = is_numeric($row[2]) ? (int)$row[2] : 0;
                $exam_score = is_numeric($row[3]) ? (int)$row[3] : 0;
                $term_id = (int)$row[4]; $year_id = (int)$row[5];

                if ($student_id <= 0 || $subject_id <= 0 || $term_id <= 0 || $year_id <= 0) continue;
                if ($class_score > MAX_CA || $exam_score > MAX_EXAM) { $conn->rollback(); reply_json(['status'=>'error','message'=>"Row for student {$student_id} exceeds max allowed scores (CA: ".MAX_CA.", Exam: ".MAX_EXAM.")"],400); }

                $total = $class_score + $exam_score;
                $g = grade_for_total($conn, $total);
                $grade = $g['grade'] ?? null; $remark = $g['remark'] ?? null;

                $stmt->bind_param('iiiissiii', $student_id, $subject_id, $class_score, $exam_score, $grade, $remark, $term_id, $year_id, $user_id);
                $stmt->execute();
                if ($stmt->errno) append_log('CSV insert error for '.$student_id.': '.$stmt->error); else $rowsProcessed++;
            }
            if ($stmt) $stmt->close();
            $conn->commit();
            fclose($handle);

            // optional: rebuild summary for classes (omitted to avoid heavy ops). Rebuild happens on save_edit/save_bulk calls.
            reply_json(['status'=>'success','message'=>"Imported {$rowsProcessed} rows"]);
            break;

        default:
            reply_json(['status'=>'error','message'=>'Invalid action'],400);
    }
} catch (Throwable $e) {
    append_log('Exception: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    reply_json(['status'=>'error','message'=>'Server error'],500);
}
