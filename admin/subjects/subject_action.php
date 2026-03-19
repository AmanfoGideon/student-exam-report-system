<?php
// admin/subjects/subject_action.php
session_start();
require_once __DIR__ . '/../../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

// Check login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

function respond($arr) {
    echo json_encode($arr);
    exit;
}

/**
 * Check if a table exists in the current database.
 */
function table_exists($conn, $tableName) {
    $db = $conn->real_escape_string($conn->query("SELECT DATABASE()")->fetch_row()[0]);
    $tn = $conn->real_escape_string($tableName);
    $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ss', $db, $tn);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = ($res && $res->num_rows > 0);
    $stmt->close();
    return $exists;
}

// helper to fetch classes list (id, name)
function fetch_classes_list($conn) {
    $out = [];
    $qr = $conn->query("SELECT id, class_name, stream FROM classes ORDER BY class_name, stream");
    while ($r = $qr->fetch_assoc()) {
        $out[] = ['id' => (int)$r['id'], 'name' => trim($r['class_name'] . (!empty($r['stream']) ? ' ' . $r['stream'] : ''))];
    }
    return $out;
}

// helper to fetch teachers list (id, full_name)
function fetch_teachers_list($conn) {
    $out = [];
    $tr = $conn->query("SELECT id, first_name, last_name FROM teachers ORDER BY first_name, last_name");
    while ($t = $tr->fetch_assoc()) {
        $out[] = ['id' => (int)$t['id'], 'full_name' => trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''))];
    }
    return $out;
}

// ----------------- LOAD LISTS (classes & teachers) -----------------
if ($action === 'load_lists') {
    $classes = fetch_classes_list($conn);
    $teachers = fetch_teachers_list($conn);
    respond(['success' => true, 'data' => ['classes' => $classes, 'teachers' => $teachers]]);
}

// ----------------- LIST TEACHERS (for assign modal) -----------------
if ($action === 'list_teachers') {
    $subject_id = (int)($_GET['subject_id'] ?? 0);
    $teachers = fetch_teachers_list($conn);

    // optional: return selected teacher ids for provided subject
    $selected = [];
    if ($subject_id > 0) {
        $stmt = $conn->prepare("SELECT teacher_id FROM subject_teachers WHERE subject_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $subject_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $selected[] = (int)$r['teacher_id'];
            $stmt->close();
        }
    }

    respond(['success' => true, 'data' => $teachers, 'selected_ids' => $selected]);
}

// ----------------- LIST CLASSES (for assign modal) -----------------
if ($action === 'list_classes') {
    $subject_id = (int)($_GET['subject_id'] ?? 0);
    $classes = fetch_classes_list($conn);

    $selected = [];
    if ($subject_id > 0) {
        $stmt = $conn->prepare("SELECT class_id FROM subject_classes WHERE subject_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $subject_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $selected[] = (int)$r['class_id'];
            $stmt->close();
        }
    }

    respond(['success' => true, 'data' => $classes, 'selected_ids' => $selected]);
}

// ----------------- FETCH (datatable) -----------------
if ($action === 'fetch') {
    $subjects = [];
    $res = $conn->query("SELECT id, subject_code, name, description FROM subjects ORDER BY name");
    while ($r = $res->fetch_assoc()) {
        $sid = (int)$r['id'];

        // classes
        $classes = []; $class_ids = [];
        $cres = $conn->prepare("SELECT sc.class_id, c.class_name, c.stream FROM subject_classes sc JOIN classes c ON sc.class_id = c.id WHERE sc.subject_id = ? ORDER BY c.class_name");
        if ($cres) {
            $cres->bind_param('i', $sid);
            $cres->execute();
            $cres_r = $cres->get_result();
            while ($c = $cres_r->fetch_assoc()) {
                $classes[] = trim($c['class_name'] . (!empty($c['stream']) ? ' ' . $c['stream'] : ''));
                $class_ids[] = (int)$c['class_id'];
            }
            $cres->close();
        }

        // teachers
        $teachers = []; $teacher_ids = [];
        $tr = $conn->prepare("SELECT st.teacher_id, t.first_name, t.last_name FROM subject_teachers st JOIN teachers t ON st.teacher_id = t.id WHERE st.subject_id = ? ORDER BY t.first_name, t.last_name");
        if ($tr) {
            $tr->bind_param('i', $sid);
            $tr->execute();
            $tr_r = $tr->get_result();
            while ($t = $tr_r->fetch_assoc()) {
                $teachers[] = trim($t['first_name'] . ' ' . ($t['last_name'] ?? ''));
                $teacher_ids[] = (int)$t['teacher_id'];
            }
            $tr->close();
        }

        $r['classes'] = $classes;
        $r['class_ids'] = $class_ids;
        $r['teachers'] = $teachers;
        $r['teacher_ids'] = $teacher_ids;
        $r['id'] = $sid;

        $subjects[] = $r;
    }
    respond(['data' => $subjects]);
}

// ----------------- GET single subject -----------------
if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) respond(['success' => false, 'message' => 'Invalid id']);

    $stmt = $conn->prepare("SELECT id, subject_code, name, description FROM subjects WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) respond(['success' => false, 'message' => 'Subject not found']);
    $row = $res->fetch_assoc();
    $stmt->close();

    // classes
    $row['class_ids'] = [];
    $cres = $conn->prepare("SELECT class_id FROM subject_classes WHERE subject_id = ?");
    if ($cres) {
        $cres->bind_param('i', $id);
        $cres->execute();
        $cres_r = $cres->get_result();
        while ($c = $cres_r->fetch_assoc()) $row['class_ids'][] = (int)$c['class_id'];
        $cres->close();
    }

    // teachers
    $row['teacher_ids'] = [];
    $tres = $conn->prepare("SELECT teacher_id FROM subject_teachers WHERE subject_id = ?");
    if ($tres) {
        $tres->bind_param('i', $id);
        $tres->execute();
        $tres_r = $tres->get_result();
        while ($t = $tres_r->fetch_assoc()) $row['teacher_ids'][] = (int)$t['teacher_id'];
        $tres->close();
    }

    respond(['success' => true, 'data' => $row]);
}

// ----------------- SAVE -----------------
if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $code = trim((string)($_POST['subject_code'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $classes = isset($_POST['classes']) && is_array($_POST['classes']) ? array_map('intval', $_POST['classes']) : [];
    $teachers = isset($_POST['teachers']) && is_array($_POST['teachers']) ? array_map('intval', $_POST['teachers']) : [];

    if ($name === '') respond(['success' => false, 'message' => 'Subject name required']);

    $legacyTeachersSubjects = table_exists($conn, 'teachers_subjects'); // optional legacy mapping
    $conn->begin_transaction();
    try {
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE subjects SET subject_code = ?, name = ?, description = ? WHERE id = ?");
            $stmt->bind_param('sssi', $code, $name, $desc, $id);
            $stmt->execute();
            $stmt->close();

            // clear existing mappings for this subject
            $del1 = $conn->prepare("DELETE FROM subject_classes WHERE subject_id = ?");
            $del1->bind_param('i', $id); $del1->execute(); $del1->close();

            $del2 = $conn->prepare("DELETE FROM subject_teachers WHERE subject_id = ?");
            $del2->bind_param('i', $id); $del2->execute(); $del2->close();

            if ($legacyTeachersSubjects) {
                $del3 = $conn->prepare("DELETE FROM teachers_subjects WHERE subject_id = ?");
                $del3->bind_param('i', $id); $del3->execute(); $del3->close();
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO subjects (subject_code, name, description) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $code, $name, $desc);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        // insert class mappings
        if (!empty($classes)) {
            $insClass = $conn->prepare("INSERT IGNORE INTO subject_classes (subject_id, class_id) VALUES (?, ?)");
            foreach ($classes as $c) {
                $insClass->bind_param('ii', $id, $c);
                $insClass->execute();
            }
            $insClass->close();
        }

        // insert teacher mappings
        if (!empty($teachers)) {
            $insTeacher = $conn->prepare("INSERT IGNORE INTO subject_teachers (subject_id, teacher_id) VALUES (?, ?)");
            foreach ($teachers as $t) {
                $insTeacher->bind_param('ii', $id, $t);
                $insTeacher->execute();
            }
            $insTeacher->close();
        }

        // optional: create rows in legacy teachers_subjects for teacher-class-subject combos
        if ($legacyTeachersSubjects && !empty($teachers) && !empty($classes)) {
            $insLegacy = $conn->prepare("INSERT IGNORE INTO teachers_subjects (teacher_id, class_id, subject_id) VALUES (?, ?, ?)");
            foreach ($classes as $c) {
                foreach ($teachers as $t) {
                    $insLegacy->bind_param('iii', $t, $c, $id);
                    $insLegacy->execute();
                }
            }
            $insLegacy->close();
        }

        $conn->commit();
        respond(['success' => true, 'message' => 'Subject saved']);
    } catch (Exception $e) {
        $conn->rollback();
        respond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ----------------- ASSIGN TEACHER(S) -----------------
if ($action === 'assign_teacher') {
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $teachers = isset($_POST['teachers']) && is_array($_POST['teachers']) ? array_map('intval', $_POST['teachers']) : [];

    if ($subject_id <= 0 || empty($teachers)) respond(['success' => false, 'message' => 'Please select teacher(s)']);

    // get class ids currently assigned to this subject (from subject_classes)
    $classIds = [];
    $cres = $conn->prepare("SELECT class_id FROM subject_classes WHERE subject_id = ?");
    if ($cres) {
        $cres->bind_param('i', $subject_id);
        $cres->execute();
        $cres_r = $cres->get_result();
        while ($c = $cres_r->fetch_assoc()) $classIds[] = (int)$c['class_id'];
        $cres->close();
    }

    if (empty($classIds)) respond(['success' => false, 'message' => 'No classes assigned. Assign classes first.']);

    $legacyTeachersSubjects = table_exists($conn, 'teachers_subjects');
    $conn->begin_transaction();
    try {
        // insert into subject_teachers
        $ins = $conn->prepare("INSERT IGNORE INTO subject_teachers (subject_id, teacher_id) VALUES (?, ?)");
        foreach ($teachers as $t) {
            $ins->bind_param('ii', $subject_id, $t);
            $ins->execute();
        }
        $ins->close();

        // optionally create entries in teachers_subjects for each (teacher,class) combo
        if ($legacyTeachersSubjects) {
            $ins2 = $conn->prepare("INSERT IGNORE INTO teachers_subjects (teacher_id, class_id, subject_id) VALUES (?, ?, ?)");
            foreach ($teachers as $t) {
                foreach ($classIds as $c) {
                    $ins2->bind_param('iii', $t, $c, $subject_id);
                    $ins2->execute();
                }
            }
            $ins2->close();
        }

        $conn->commit();
        respond(['success' => true, 'message' => 'Teacher(s) assigned to subject']);
    } catch (Exception $e) {
        $conn->rollback();
        respond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ----------------- ASSIGN CLASS -----------------
if ($action === 'assign_class') {
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $class_id = (int)($_POST['class_id'] ?? 0);
    if ($subject_id <= 0 || $class_id <= 0) respond(['success' => false, 'message' => 'Invalid data']);

    // get teacher ids currently assigned to this subject
    $teacherIds = [];
    $tres = $conn->prepare("SELECT teacher_id FROM subject_teachers WHERE subject_id = ?");
    if ($tres) {
        $tres->bind_param('i', $subject_id);
        $tres->execute();
        $tres_r = $tres->get_result();
        while ($t = $tres_r->fetch_assoc()) $teacherIds[] = (int)$t['teacher_id'];
        $tres->close();
    }

    $legacyTeachersSubjects = table_exists($conn, 'teachers_subjects');
    $conn->begin_transaction();
    try {
        // insert into subject_classes
        $ins = $conn->prepare("INSERT IGNORE INTO subject_classes (subject_id, class_id) VALUES (?, ?)");
        $ins->bind_param('ii', $subject_id, $class_id);
        $ins->execute();
        $ins->close();

        // optionally create entries in teachers_subjects for each teacher assigned
        if ($legacyTeachersSubjects && !empty($teacherIds)) {
            $ins2 = $conn->prepare("INSERT IGNORE INTO teachers_subjects (teacher_id, class_id, subject_id) VALUES (?, ?, ?)");
            foreach ($teacherIds as $tid) {
                $ins2->bind_param('iii', $tid, $class_id, $subject_id);
                $ins2->execute();
            }
            $ins2->close();
        }

        $conn->commit();
        respond(['success' => true, 'message' => 'Class assigned successfully']);
    } catch (Exception $e) {
        $conn->rollback();
        respond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ----------------- DELETE -----------------
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) respond(['success' => false, 'message' => 'Invalid id']);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM subject_teachers WHERE subject_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM subject_classes WHERE subject_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }

        // optionally remove legacy mappings
        if (table_exists($conn, 'teachers_subjects')) {
            $stmt = $conn->prepare("DELETE FROM teachers_subjects WHERE subject_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }
        }

        $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        respond(['success' => true, 'message' => 'Subject deleted']);
    } catch (Exception $e) {
        $conn->rollback();
        respond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

respond(['success' => false, 'message' => 'Invalid action']);
