<?php
// teacher/score_action.php
session_start();
require_once __DIR__ . '/../../includes/db.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function reply_json($payload, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Teacher') {
    reply_json(['status'=>'error','message'=>'Unauthorized'], 401);
}
$user_id = (int)$_SESSION['user_id'];

// resolve teacher.id
$tstmt = $conn->prepare("SELECT id FROM teachers WHERE user_id = ? LIMIT 1");
$tstmt->bind_param('i', $user_id);
$tstmt->execute();
$trow = $tstmt->get_result()->fetch_assoc() ?: [];
$tstmt->close();
$teacherId = (int)($trow['id'] ?? 0);

// CSRF helper
function check_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_REQUEST['csrf_token'] ?? null;
        if (!$token || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            reply_json(['status'=>'error','message'=>'Invalid CSRF token'], 400);
        }
    }
}

// fetch all rows helper
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

// grade lookup
function grade_for_total(mysqli $conn, int $total) {
    $sql = "SELECT grade, remark FROM grading_scale WHERE ? BETWEEN min_score AND max_score ORDER BY rank_order ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return ['grade'=>null,'remark'=>null];
    $stmt->bind_param('i', $total);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) return ['grade'=>$row['grade'],'remark'=>$row['remark']];
    return ['grade'=>null,'remark'=>null];
}

// rebuild summary (same as admin)
function rebuild_scores_summary(mysqli $conn, int $class_id, int $term_id, int $year_id) {
    $del = $conn->prepare("DELETE FROM scores_summary WHERE class_id=? AND term_id=? AND year_id=?");
    if ($del) { $del->bind_param('iii', $class_id, $term_id, $year_id); $del->execute(); $del->close(); }

    $sql = "SELECT s.id AS student_id, SUM(sc.class_score + sc.exam_score) AS total_marks, AVG(sc.class_score + sc.exam_score) AS average_score
            FROM scores sc
            JOIN students s ON sc.student_id = s.id
            WHERE s.class_id = ? AND sc.term_id = ? AND sc.year_id = ?
            GROUP BY sc.student_id
            ORDER BY total_marks DESC, average_score DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('iii', $class_id, $term_id, $year_id);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
    $stmt->close();

    $insert = $conn->prepare("INSERT INTO scores_summary (student_id, class_id, term_id, year_id, total_marks, average, position, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                              ON DUPLICATE KEY UPDATE total_marks=VALUES(total_marks), average=VALUES(average), position=VALUES(position), updated_at=NOW()");
    if (!$insert) return false;
    $pos = 1;
    foreach ($rows as $r) {
        $student_id = (int)$r['student_id'];
        $total_marks = (int)$r['total_marks'];
        $average = round(floatval($r['average_score']), 2);
        $insert->bind_param('iiiiiii', $student_id, $class_id, $term_id, $year_id, $total_marks, $average, $pos);
        $insert->execute();
        $pos++;
    }
    $insert->close();
    return true;
}

// check teacher owns subject+class
function teacher_owns_subject_class(mysqli $conn, int $teacherId, int $subject_id, int $class_id) {
    $sql = "SELECT COUNT(*) FROM subject_teachers st JOIN subject_classes sc ON st.subject_id = sc.subject_id
            WHERE st.teacher_id = ? AND st.subject_id = ? AND sc.class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $teacherId, $subject_id, $class_id);
    $stmt->execute();
    $cnt = $stmt->get_result()->fetch_row()[0] ?? 0;
    $stmt->close();
    return $cnt > 0;
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        case 'fetch_students':
            check_csrf();
            $class_id = (int)($_POST['class_id'] ?? 0);
            $subject_id = (int)($_POST['subject_id'] ?? 0);
            $year_id = (int)($_POST['year_id'] ?? 0);
            $term_id = (int)($_POST['term_id'] ?? 0);
            if (!$class_id || !$subject_id || !$year_id || !$term_id) reply_json(['status'=>'error','message'=>'Missing parameters'], 400);

            if (!teacher_owns_subject_class($conn, $teacherId, $subject_id, $class_id)) {
                reply_json(['status'=>'error','message'=>'Access denied for this subject/class'], 403);
            }

            $sql = "SELECT st.id AS student_id, CONCAT(COALESCE(st.first_name,''),' ',COALESCE(st.last_name,'')) AS name,
                           COALESCE(sc.class_score,0) AS class_score, COALESCE(sc.exam_score,0) AS exam_score
                    FROM students st
                    LEFT JOIN scores sc ON sc.student_id = st.id AND sc.subject_id = ? AND sc.term_id = ? AND sc.year_id = ?
                    WHERE st.class_id = ?
                    ORDER BY st.first_name, st.last_name";
            $stmt = $conn->prepare($sql);
            if (!$stmt) reply_json(['status'=>'error','message'=>'DB prepare failed'], 500);
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
            if (!teacher_owns_subject_class($conn, $teacherId, $subject_id, $class_id)) reply_json(['status'=>'error','message'=>'Access denied'], 403);

            define('MAX_CA', 50);
            define('MAX_EXAM', 50);

            $conn->begin_transaction();
            $insert_sql = "INSERT INTO scores (student_id, subject_id, class_score, exam_score, grade, remark, term_id, year_id, uploaded_by, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                           ON DUPLICATE KEY UPDATE class_score=VALUES(class_score), exam_score=VALUES(exam_score), grade=VALUES(grade), remark=VALUES(remark), uploaded_by=VALUES(uploaded_by), updated_at=NOW()";
            $stmt = $conn->prepare($insert_sql);
            if (!$stmt) { $conn->rollback(); reply_json(['status'=>'error','message'=>'DB prepare failed'],500); }

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

                // ensure student belongs to class (additional safety)
                $r = $conn->query("SELECT class_id FROM students WHERE id = ".intval($student_id)." LIMIT 1")->fetch_assoc();
                if (!$r || (int)$r['class_id'] !== $class_id) continue;

                $total = $class_score + $exam_score;
                $g = grade_for_total($conn, $total);
                $grade = $g['grade'] ?? null;
                $remark = $g['remark'] ?? null;

                $stmt->bind_param('iiiissiii', $student_id, $subject_id, $class_score, $exam_score, $grade, $remark, $term_id, $year_id, $user_id);
                $stmt->execute();
                if (!$stmt->errno) $rowsInserted++;
            }
            $stmt->close();

            // rebuild summary for this class/term/year
            rebuild_scores_summary($conn, $class_id, $term_id, $year_id);

            $conn->commit();
            reply_json(['status'=>'success','inserted_rows'=>$rowsInserted]);
            break;

        case 'load_scores':
            check_csrf();
            $class_id = (int)($_POST['class_id'] ?? 0);
            $subject_id = (int)($_POST['subject_id'] ?? 0);
            $year_id = (int)($_POST['year_id'] ?? 0);
            $term_id = (int)($_POST['term_id'] ?? 0);

            // build query but ensure teacher owns subject/class when provided
            if ($subject_id && $class_id && !teacher_owns_subject_class($conn, $teacherId, $subject_id, $class_id)) {
                reply_json(['status'=>'error','message'=>'Access denied'],403);
            }

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
            if (!$stmt) reply_json(['status'=>'error','message'=>'DB prepare failed'],500);

            if (!empty($params)) {
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
            reply_json(['status'=>'ok','data'=>$data]);
            break;

        case 'edit_score':
            check_csrf();
            $id = (int)($_POST['id'] ?? 0);
            $class_score = isset($_POST['class_score']) ? (int)$_POST['class_score'] : null;
            $exam_score = isset($_POST['exam_score']) ? (int)$_POST['exam_score'] : null;

            if ($id <= 0 || $class_score === null || $exam_score === null) reply_json(['status'=>'error','message'=>'Invalid input'],400);
            if ($class_score > 50 || $exam_score > 50) reply_json(['status'=>'error','message'=>'Scores exceed allowed maximum'],400);

            // ensure this score row belongs to a subject/class that the teacher owns
            $stmt = $conn->prepare("SELECT sc.subject_id, st.class_id, sc.term_id, sc.year_id FROM scores sc JOIN students st ON sc.student_id = st.id WHERE sc.id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $info = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            if (empty($info)) reply_json(['status'=>'error','message'=>'Score row not found'],404);
            if (!teacher_owns_subject_class($conn, $teacherId, (int)$info['subject_id'], (int)$info['class_id'])) reply_json(['status'=>'error','message'=>'Access denied'],403);

            $total = $class_score + $exam_score;
            $g = grade_for_total($conn, $total);
            $grade = $g['grade'] ?? null; $remark = $g['remark'] ?? null;

            $up = $conn->prepare("UPDATE scores SET class_score=?, exam_score=?, grade=?, remark=?, uploaded_by=?, updated_at=NOW() WHERE id=?");
            $up->bind_param('iissii', $class_score, $exam_score, $grade, $remark, $user_id, $id);
            $up->execute();
            $up->close();

            // rebuild summary for that student's class/term/year
            $studentRow = $conn->query("SELECT student_id, term_id, year_id FROM scores WHERE id = ".intval($id)." LIMIT 1")->fetch_assoc();
            if ($studentRow) {
                $student_id = (int)$studentRow['student_id'];
                $term_id = (int)$studentRow['term_id'];
                $year_id = (int)$studentRow['year_id'];
                $cl = $conn->query("SELECT class_id FROM students WHERE id = ".intval($student_id)." LIMIT 1")->fetch_assoc();
                if ($cl) rebuild_scores_summary($conn, (int)$cl['class_id'], $term_id, $year_id);
            }
            reply_json(['status'=>'success']);
            break;

        case 'export_scores':
            // GET-based CSV export. Enforce teacher ownership
            $class_id = (int)($_GET['class_id'] ?? 0);
            $subject_id = (int)($_GET['subject_id'] ?? 0);
            $year_id = (int)($_GET['year_id'] ?? 0);
            $term_id = (int)($_GET['term_id'] ?? 0);
            if ($subject_id && $class_id && !teacher_owns_subject_class($conn, $teacherId, $subject_id, $class_id)) {
                http_response_code(403); echo "Access denied"; exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="scores_export.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Student','Class','Subject','Term','Year','Class Score','Exam Score','Total','Grade','Remark']);

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
            break;

        default:
            reply_json(['status'=>'error','message'=>'Invalid action'],400);
    }
} catch (Throwable $e) {
    // safe error response (avoid leaking sensitive info)
    reply_json(['status'=>'error','message'=>'Server error: '.$e->getMessage()], 500);
}
