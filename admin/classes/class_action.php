<?php
// admin/classes/class_action.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/db.php';
if (!isset($conn)) {
    echo json_encode(['success' => false, 'message' => 'DB connection not available']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'fetch': fetchClasses($conn); break;
    case 'get': getClass($conn); break;
    case 'save': saveClass($conn); break;
    case 'delete': deleteClass($conn); break;
    case 'fetch_subjects': fetchSubjects($conn); break;
    case 'fetch_teachers': fetchTeachers($conn); break;
    case 'assign_subject': assignSubject($conn); break;
    case 'fetch_unassigned_students': fetchUnassignedStudents($conn); break;
    case 'assign_students': assignStudents($conn); break;
    case 'fetch_students_for_class': fetchStudentsForClass($conn); break;
    case 'promote_transfer': promoteTransfer($conn); break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

/* ---------- Functions ---------- */

function fetchClasses($conn) {
    $sql = "SELECT c.id, c.class_name, c.stream, c.year_id, ay.year_label
            FROM classes c
            LEFT JOIN academic_years ay ON c.year_id = ay.id
            ORDER BY c.class_name ASC, c.stream ASC";
    $res = $conn->query($sql);
    if ($res === false) return jsonError('DB error fetching classes');

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $cid = (int)$row['id'];

        // subjects
        $subjects = [];
        $stmt = $conn->prepare("SELECT s.id, s.name FROM subject_classes sc JOIN subjects s ON s.id = sc.subject_id WHERE sc.class_id = ? ORDER BY s.name");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $sr = $stmt->get_result();
        while ($s = $sr->fetch_assoc()) $subjects[] = $s['name'];
        $stmt->close();

        // teachers
        $teachers = [];
        $stmt = $conn->prepare("SELECT DISTINCT t.id, t.first_name, t.last_name
                               FROM subject_classes sc
                               JOIN subject_teachers st ON st.subject_id = sc.subject_id
                               JOIN teachers t ON t.id = st.teacher_id
                               WHERE sc.class_id = ? ORDER BY t.first_name, t.last_name");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $tr = $stmt->get_result();
        while ($t = $tr->fetch_assoc()) $teachers[] = trim($t['first_name'] . ' ' . ($t['last_name'] ?? ''));
        $stmt->close();

        // students count
        $count = 0;
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM students WHERE class_id = ?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $cr = $stmt->get_result();
        if ($cr && ($c = $cr->fetch_assoc())) $count = (int)$c['cnt'];
        $stmt->close();

        $out[] = [
            'id' => $cid,
            'class_name' => $row['class_name'],
            'stream' => $row['stream'],
            'year_label' => $row['year_label'] ?? '',
            'students_count' => $count,
            'subjects' => $subjects,
            'teachers' => $teachers
        ];
    }

    echo json_encode(['success' => true, 'data' => $out]);
}

function getClass($conn) {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) return jsonError('Invalid id');
    $stmt = $conn->prepare("SELECT id, class_name, stream, year_id FROM classes WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || $res->num_rows === 0) return jsonError('Class not found');
    echo json_encode(['success' => true, 'data' => $res->fetch_assoc()]);
}

function saveClass($conn) {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['class_name'] ?? '');
    $stream = trim($_POST['stream'] ?? '');
    $year_id = intval($_POST['year_id'] ?? 0);
    if ($name === '') return jsonError('Class name required');

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE classes SET class_name = ?, stream = ?, year_id = ? WHERE id = ?");
        $stmt->bind_param('ssii', $name, $stream, $year_id, $id);
        $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Class updated' : 'Update failed']);
    } else {
        $stmt = $conn->prepare("INSERT INTO classes (class_name, stream, year_id) VALUES (?, ?, ?)");
        $stmt->bind_param('ssi', $name, $stream, $year_id);
        $ok = $stmt->execute(); $newId = $stmt->insert_id ?? 0; $stmt->close();
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Class created' : 'Insert failed', 'id' => (int)$newId]);
    }
}

function deleteClass($conn) {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) return jsonError('Invalid id');
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM subject_classes WHERE class_id = ?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();

        $stmt = $conn->prepare("UPDATE students SET class_id = NULL WHERE class_id = ?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();

        $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Class deleted']);
    } catch (Exception $e) {
        $conn->rollback();
        return jsonError('Failed to delete class');
    }
}

function fetchSubjects($conn) {
    $res = $conn->query("SELECT id, name FROM subjects ORDER BY name");
    if ($res === false) return jsonError('DB error');
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = ['id' => (int)$r['id'], 'name' => $r['name']];
    echo json_encode(['success' => true, 'data' => $out]);
}

function fetchTeachers($conn) {
    $subject_id = intval($_GET['subject_id'] ?? 0);
    $res = $conn->query("SELECT id, first_name, last_name FROM teachers ORDER BY first_name, last_name");
    if ($res === false) return jsonError('DB error fetching teachers');
    $assigned = [];
    if ($subject_id > 0) {
        $stmt = $conn->prepare("SELECT teacher_id FROM subject_teachers WHERE subject_id = ?");
        $stmt->bind_param('i', $subject_id); $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $assigned[(int)$row['teacher_id']] = 1;
        $stmt->close();
    }
    $out = [];
    while ($t = $res->fetch_assoc()) $out[] = ['id' => (int)$t['id'], 'first_name' => $t['first_name'], 'last_name' => $t['last_name'], 'assigned' => isset($assigned[(int)$t['id']]) ? 1 : 0];
    echo json_encode(['success' => true, 'data' => $out]);
}

function assignSubject($conn) {
    $class_id = intval($_POST['class_id'] ?? 0);
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $teachers = isset($_POST['teachers']) ? (array)$_POST['teachers'] : [];
    if ($class_id <= 0 || $subject_id <= 0) return jsonError('Missing data');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id FROM subject_classes WHERE class_id = ? AND subject_id = ? LIMIT 1");
        $stmt->bind_param('ii', $class_id, $subject_id); $stmt->execute();
        $res = $stmt->get_result();
        if (!$res || $res->num_rows === 0) {
            $ins = $conn->prepare("INSERT INTO subject_classes (class_id, subject_id) VALUES (?, ?)");
            $ins->bind_param('ii', $class_id, $subject_id); $ins->execute(); $ins->close();
        }
        $stmt->close();

        if (!empty($teachers)) {
            $insT = $conn->prepare("INSERT IGNORE INTO subject_teachers (subject_id, teacher_id) VALUES (?, ?)");
            foreach ($teachers as $trid) {
                $tid = intval($trid);
                if ($tid <= 0) continue;
                $insT->bind_param('ii', $subject_id, $tid); $insT->execute();
            }
            $insT->close();
        }
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Assigned']);
    } catch (Exception $e) {
        $conn->rollback();
        return jsonError('Failed to assign');
    }
}

function fetchUnassignedStudents($conn) {
    $res = $conn->query("SELECT id, admission_no, first_name, last_name FROM students WHERE class_id IS NULL ORDER BY first_name, last_name");
    if ($res === false) return jsonError('DB error');
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r;
    echo json_encode(['success' => true, 'data' => $out]);
}

function assignStudents($conn) {
    $class_id = intval($_POST['class_id'] ?? 0);
    $students = isset($_POST['students']) ? (array)$_POST['students'] : [];
    if ($class_id <= 0 || empty($students)) return jsonError('Invalid data');
    $stmt = $conn->prepare("UPDATE students SET class_id = ? WHERE id = ?");
    foreach ($students as $sraw) {
        $sid = intval($sraw);
        if ($sid <= 0) continue;
        $stmt->bind_param('ii', $class_id, $sid); $stmt->execute();
    }
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Students assigned']);
}

/* ---------- Promotion / Transfer ---------- */

function fetchStudentsForClass($conn) {
    $class_id = intval($_GET['class_id'] ?? 0);
    if ($class_id <= 0) return jsonError('Invalid class id');
    $stmt = $conn->prepare("SELECT id, admission_no, first_name, last_name FROM students WHERE class_id = ? ORDER BY first_name, last_name");
    $stmt->bind_param('i', $class_id); $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r;
    echo json_encode(['success' => true, 'data' => $out]);
}

function promoteTransfer($conn) {
    // params: from_class_id, to_class_id, promote_all (1/0), student_ids[] optionally, promotion_type, promoted_by (session user)
    $from = intval($_POST['from_class_id'] ?? 0);
    $to = intval($_POST['to_class_id'] ?? 0);
    $promote_all = isset($_POST['promote_all']) && $_POST['promote_all'] ? 1 : 0;
    $student_ids = isset($_POST['student_ids']) ? (array)$_POST['student_ids'] : [];
    $ptype = in_array($_POST['promotion_type'] ?? '', ['promotion','transfer']) ? $_POST['promotion_type'] : 'promotion';
    $promoted_by = $_SESSION['user_id'] ?? null;

    if ($from <= 0 || $to <= 0) return jsonError('Invalid class selection');
    if ($to === $from) return jsonError('Destination must be a different class');

    // build list to operate on
    if ($promote_all && empty($student_ids)) {
        // fetch all students in from class
        $stmt = $conn->prepare("SELECT id FROM students WHERE class_id = ?");
        $stmt->bind_param('i', $from); $stmt->execute();
        $r = $stmt->get_result();
        $student_ids = [];
        while ($row = $r->fetch_assoc()) $student_ids[] = (int)$row['id'];
        $stmt->close();
    } else {
        // sanitize provided ids
        $student_ids = array_map('intval', $student_ids);
        $student_ids = array_filter($student_ids, function ($v) { return $v > 0; });
    }

    if (empty($student_ids)) return jsonError('No students selected for promotion/transfer');

    // create promotion_history table if not exists (lightweight)
    $create_sql = "
    CREATE TABLE IF NOT EXISTS promotion_history (
      id INT AUTO_INCREMENT PRIMARY KEY,
      student_id INT NOT NULL,
      old_class_id INT NOT NULL,
      new_class_id INT NOT NULL,
      promoted_by INT NULL,
      promotion_type ENUM('promotion','transfer') DEFAULT 'promotion',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $conn->query($create_sql); // ignore errors here

    // perform updates in transaction and log history
    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("UPDATE students SET class_id = ? WHERE id = ?");
        $insLog = $conn->prepare("INSERT INTO promotion_history (student_id, old_class_id, new_class_id, promoted_by, promotion_type) VALUES (?, ?, ?, ?, ?)");
        foreach ($student_ids as $sid) {
            // fetch old class to be safe
            $stmt = $conn->prepare("SELECT class_id FROM students WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $sid); $stmt->execute();
            $r = $stmt->get_result();
            $old_class = ($r && ($rr = $r->fetch_assoc())) ? intval($rr['class_id']) : $from;
            $stmt->close();

            $upd->bind_param('ii', $to, $sid); $upd->execute();

            // log
            $insLog->bind_param('iiiis', $sid, $old_class, $to, $promoted_by, $ptype);
            $insLog->execute();
        }
        $upd->close();
        $insLog->close();
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Promotion/Transfer completed', 'count' => count($student_ids)]);
    } catch (Exception $e) {
        $conn->rollback();
        return jsonError('Failed to complete promotion/transfer');
    }
}

/* ---------- Helpers ---------- */
function jsonError($msg) {
    echo json_encode(['success' => false, 'message' => $msg]);
}
