<?php
// admin/students/student_action.php
session_start();
require_once __DIR__ . '/../../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'fetch': server_side_fetch($conn); break;
    case 'get': get_student($conn); break;
    case 'save': save_student($conn); break;
    case 'delete': delete_student($conn); break;
    case 'fetch_classes': fetch_classes($conn); break;
    case 'fetch_unassigned_students': fetch_unassigned_students($conn); break;
    case 'promote': bulk_promote($conn); break;
    case 'transfer': bulk_transfer($conn); break;
    case 'bulk_import': handle_import($conn); break;
    default: echo json_encode(['success'=>false,'message'=>'Invalid action']); break;
}

/* ---------------------------
   Helpers & Implementations
   --------------------------- */

function server_side_fetch($conn) {
    // DataTables server-side params
    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 10);
    $search = trim($_GET['search']['value'] ?? '');
    $orderColIndex = intval($_GET['order'][0]['column'] ?? 0);
    $orderDir = $_GET['order'][0]['dir'] === 'desc' ? 'DESC' : 'ASC';

    // Map order column index to actual column names (matches columns in client DataTable)
    $columnsMap = [
        0 => 's.id',
        2 => 's.admission_no',
        3 => "CONCAT(s.first_name,' ',s.last_name)",
        4 => 's.gender',
        5 => 's.dob',
        6 => 's.address',
        7 => "COALESCE(c.class_name,'')",
        8 => 's.guardian_name',
        9 => 's.guardian_phone'
    ];
    $orderBy = $columnsMap[$orderColIndex] ?? 's.id';

    // count total
    $totalRes = $conn->query("SELECT COUNT(*) AS cnt FROM students");
    $recordsTotal = (int)$totalRes->fetch_assoc()['cnt'];

    // base query with joins
    $baseWhere = "1=1";
    $params = [];
    $types = '';

    if ($search !== '') {
        $baseWhere .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_no LIKE ? OR s.guardian_name LIKE ? OR s.guardian_phone LIKE ? OR s.address LIKE ?)";
        $like = "%$search%";
        for ($i = 0; $i < 6; $i++) { $params[] = $like; $types .= 's'; }
    }

    // total filtered
    $countSql = "SELECT COUNT(*) AS cnt FROM students s LEFT JOIN classes c ON s.class_id = c.id WHERE $baseWhere";
    $stmtCount = $conn->prepare($countSql);
    if ($types) $stmtCount->bind_param($types, ...$params);
    $stmtCount->execute();
    $recordsFiltered = (int)$stmtCount->get_result()->fetch_assoc()['cnt'];
    $stmtCount->close();

    // fetch page
    $sql = "SELECT s.id, s.admission_no, s.first_name, s.last_name, s.gender, s.dob, s.address, s.guardian_name, s.guardian_phone, s.photo_path, c.class_name, c.stream, s.class_id
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE $baseWhere
            ORDER BY $orderBy $orderDir
            LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    // bind params + start,length
    $bindParams = $params;
    $bindTypes = $types . 'ii';
    $bindParams[] = $start;
    $bindParams[] = $length;
    $stmt->bind_param($bindTypes, ...$bindParams);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = [];
    while ($r = $res->fetch_assoc()) {
        $class_label = $r['class_name'] ? trim($r['class_name'] . ' ' . ($r['stream'] ?? '')) : '';
        $photo = $r['photo_path'] ? '../../uploads/students/' . $r['photo_path'] : '../../assets/images/avatar.png';
        $data[] = [
            'id' => (int)$r['id'],
            'admission_no' => $r['admission_no'] ?? '',
            'first_name' => $r['first_name'],
            'last_name' => $r['last_name'],
            'full_name' => trim($r['first_name'] . ' ' . ($r['last_name'] ?? '')),
            'gender' => $r['gender'],
            'dob' => $r['dob'],
            'address' => $r['address'],
            'guardian_name' => $r['guardian_name'],
            'guardian_phone' => $r['guardian_phone'],
            'class_label' => $class_label,
            'class_id' => $r['class_id'] ?? null,
            'photo' => $photo
        ];
    }
    $stmt->close();

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data
    ]);
}

function get_student($conn) {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); return; }
    $stmt = $conn->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Student not found']); return; }
    $row = $res->fetch_assoc();
    $row['photo_url'] = $row['photo_path'] ? '../../uploads/students/' . $row['photo_path'] : '../../assets/images/avatar.png';
    echo json_encode(['success'=>true,'data'=>$row]);
}

function save_student($conn) {
    $id = intval($_POST['id'] ?? 0);
    $admission_no = trim($_POST['admission_no'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $gender = $_POST['gender'] ?? 'Male';
    $dob = $_POST['dob'] ?? null;
    $class_id = intval($_POST['class_id'] ?? 0) ?: null;
    $address = trim($_POST['address'] ?? '');
    $guardian_name = trim($_POST['guardian_name'] ?? '');
    $guardian_phone = trim($_POST['guardian_phone'] ?? '');
    $existing_photo = trim($_POST['existing_photo'] ?? '');

    // Server-side validation
    $errors = [];
    if ($first_name === '') $errors[] = 'First name is required';
    if (!in_array($gender, ['Male','Female','Other'])) $errors[] = 'Invalid gender';
    if ($dob && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $errors[] = 'DOB must be YYYY-MM-DD';
    if ($guardian_name === '') $errors[] = 'Guardian name required';
    if ($guardian_phone === '' || !preg_match('/^\d{6,15}$/', $guardian_phone)) $errors[] = 'Valid guardian phone required';

    if ($errors) { echo json_encode(['success'=>false,'message'=>implode('; ', $errors)]); return; }

    // handle upload
    $photo_name = $existing_photo;
    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $up = $_FILES['photo'];
        if ($up['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($up['name'], PATHINFO_EXTENSION);
            $ext = strtolower($ext);
            if (!in_array($ext, ['jpg','jpeg','png','gif'])) { echo json_encode(['success'=>false,'message'=>'Invalid photo format']); return; }
            $safe = bin2hex(random_bytes(8)) . '.' . $ext;
            $destDir = __DIR__ . '/../../uploads/students/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $dest = $destDir . $safe;
            if (move_uploaded_file($up['tmp_name'], $dest)) {
                $photo_name = $safe;
                if ($existing_photo && $existing_photo !== $photo_name) {
                    $old = $destDir . $existing_photo;
                    if (is_file($old)) @unlink($old);
                }
            } else {
                echo json_encode(['success'=>false,'message'=>'Failed to move photo']); return;
            }
        } else {
            echo json_encode(['success'=>false,'message'=>'Photo upload error']); return;
        }
    }

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE students SET admission_no=?, first_name=?, last_name=?, gender=?, dob=?, class_id=?, photo_path=?, address=?, guardian_name=?, guardian_phone=? WHERE id=?");
        $stmt->bind_param('ssssisssssi', $admission_no, $first_name, $last_name, $gender, $dob, $class_id, $photo_name, $address, $guardian_name, $guardian_phone, $id);
        $ok = $stmt->execute();
        echo json_encode(['success'=>$ok,'message'=>$ok ? 'Student updated' : 'Update failed: '.$conn->error]);
    } else {
        if ($admission_no === '') { $admission_no = 'ADM' . time() . rand(10,99); }
        $stmt = $conn->prepare("INSERT INTO students (admission_no, first_name, last_name, gender, dob, class_id, photo_path, address, guardian_name, guardian_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssisssss', $admission_no, $first_name, $last_name, $gender, $dob, $class_id, $photo_name, $address, $guardian_name, $guardian_phone);
        $ok = $stmt->execute();
        echo json_encode(['success'=>$ok,'message'=>$ok ? 'Student added' : 'Insert failed: '.$conn->error]);
    }
}

function delete_student($conn) {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); return; }

    $stmt = $conn->prepare("SELECT photo_path FROM students WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute(); $r = $stmt->get_result()->fetch_assoc();
    $photo = $r['photo_path'] ?? '';

    $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    if ($ok && $photo) {
        $path = __DIR__ . '/../../uploads/students/' . $photo;
        if (is_file($path)) @unlink($path);
    }
    echo json_encode(['success'=>$ok,'message'=>$ok ? 'Student deleted' : 'Delete failed']);
}

function fetch_classes($conn) {
    $res = $conn->query("SELECT id, class_name, stream FROM classes ORDER BY class_name, stream");
    $data = [];
    while ($r = $res->fetch_assoc()) {
        $r['label'] = trim($r['class_name'] . (!empty($r['stream']) ? ' ' . $r['stream'] : ''));
        $data[] = $r;
    }
    echo json_encode(['success'=>true,'data'=>$data]);
}

function fetch_unassigned_students($conn) {
    $res = $conn->query("SELECT id, first_name, last_name FROM students WHERE class_id IS NULL ORDER BY first_name, last_name");
    $data = [];
    while ($r = $res->fetch_assoc()) $data[] = $r;
    echo json_encode(['success'=>true,'data'=>$data]);
}

function bulk_promote($conn) {
    $students = $_POST['students'] ?? [];
    $target = intval($_POST['target_class_id'] ?? 0);
    $user = intval($_SESSION['user_id']);
    if (empty($students) || $target <= 0) { echo json_encode(['success'=>false,'message'=>'Select students and target class']); return; }

    $conn->begin_transaction();
    try {
        $stmtGet = $conn->prepare("SELECT class_id FROM students WHERE id = ? LIMIT 1");
        $stmtUpd = $conn->prepare("UPDATE students SET class_id = ? WHERE id = ?");
        $stmtHist = $conn->prepare("INSERT INTO promotion_history (student_id, old_class_id, new_class_id, promoted_by, promotion_type) VALUES (?, ?, ?, ?, 'promotion')");

        foreach ($students as $sid) {
            $sid = intval($sid);
            if ($sid <= 0) continue;
            $stmtGet->bind_param('i', $sid); $stmtGet->execute();
            $old = $stmtGet->get_result()->fetch_assoc()['class_id'] ?? null;
            $stmtUpd->bind_param('ii', $target, $sid); $stmtUpd->execute();
            $stmtHist->bind_param('iiii', $sid, $old, $target, $user); $stmtHist->execute();
        }

        $conn->commit();
        echo json_encode(['success'=>true,'message'=>'Selected students promoted']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
}

function bulk_transfer($conn) {
    $students = $_POST['students'] ?? [];
    $target = intval($_POST['target_class_id'] ?? 0);
    $user = intval($_SESSION['user_id']);
    if (empty($students) || $target <= 0) { echo json_encode(['success'=>false,'message'=>'Select students and destination class']); return; }

    $conn->begin_transaction();
    try {
        $stmtGet = $conn->prepare("SELECT class_id FROM students WHERE id = ? LIMIT 1");
        $stmtUpd = $conn->prepare("UPDATE students SET class_id = ? WHERE id = ?");
        $stmtHist = $conn->prepare("INSERT INTO promotion_history (student_id, old_class_id, new_class_id, promoted_by, promotion_type) VALUES (?, ?, ?, ?, 'transfer')");

        foreach ($students as $sid) {
            $sid = intval($sid);
            if ($sid <= 0) continue;
            $stmtGet->bind_param('i', $sid); $stmtGet->execute();
            $old = $stmtGet->get_result()->fetch_assoc()['class_id'] ?? null;
            $stmtUpd->bind_param('ii', $target, $sid); $stmtUpd->execute();
            $stmtHist->bind_param('iiii', $sid, $old, $target, $user); $stmtHist->execute();
        }

        $conn->commit();
        echo json_encode(['success'=>true,'message'=>'Selected students transferred']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
}

/* -------------------------
   Import (CSV / XLSX)
   - Accepts a file from $_FILES['file']
   - Optional zip of images in $_FILES['images_zip']
   - CSV columns expected (header): admission_no, first_name, last_name, gender, dob, class_id, guardian_name, guardian_phone, address, photo_filename
------------------------- */
function handle_import($conn) {
    // minimal permission check
    if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); return; }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success'=>false,'message'=>'No file uploaded']); return;
    }

    $uploaded = $_FILES['file'];
    $tmp = $uploaded['tmp_name'];
    $ext = strtolower(pathinfo($uploaded['name'], PATHINFO_EXTENSION));
    $imagesZipPath = null;
    if (!empty($_FILES['images_zip']) && $_FILES['images_zip']['error'] === UPLOAD_ERR_OK) {
        $imagesZipPath = $_FILES['images_zip']['tmp_name'];
    }

    // If images provided as zip, extract to temporary import folder
    $importImgDir = __DIR__ . '/../../uploads/import_images_' . uniqid();
    if ($imagesZipPath) {
        if (!is_dir($importImgDir)) mkdir($importImgDir, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($imagesZipPath) === true) {
            $zip->extractTo($importImgDir);
            $zip->close();
        } else {
            // proceed without images
            $importImgDir = null;
        }
    }

    $rows = [];
    if ($ext === 'csv') {
        if (($handle = fopen($tmp, 'r')) !== false) {
            $header = null;
            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                if (!$header) {
                    $header = array_map('trim', $data);
                    continue;
                }
                $row = [];
                foreach ($header as $i => $col) $row[$col] = $data[$i] ?? '';
                $rows[] = $row;
            }
            fclose($handle);
        } else {
            echo json_encode(['success'=>false,'message'=>'Failed to read CSV']); cleanup_import_dir($importImgDir); return;
        }
    } elseif (in_array($ext, ['xlsx','xls'])) {
        // require PhpSpreadsheet
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            echo json_encode(['success'=>false,'message'=>'XLSX support requires phpoffice/phpspreadsheet library (composer)']); cleanup_import_dir($importImgDir); return;
        }
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
            $sheet = $spreadsheet->getActiveSheet();
            $rowsData = $sheet->toArray(null,true,true,true);
            $header = array_map('trim', array_values($rowsData[1]));
            for ($r = 2; $r <= count($rowsData); $r++) {
                $vals = array_values($rowsData[$r]);
                $row = [];
                foreach ($header as $i => $col) $row[$col] = $vals[$i] ?? '';
                $rows[] = $row;
            }
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Failed to parse XLSX: '.$e->getMessage()]); cleanup_import_dir($importImgDir); return;
        }
    } else {
        echo json_encode(['success'=>false,'message'=>'Unsupported file type']); cleanup_import_dir($importImgDir); return;
    }

    if (empty($rows)) { echo json_encode(['success'=>false,'message'=>'No rows in file']); cleanup_import_dir($importImgDir); return; }

    // process rows — validate and insert
    $destImgDir = __DIR__ . '/../../uploads/students/';
    if (!is_dir($destImgDir)) mkdir($destImgDir, 0755, true);

    $insertStmt = $conn->prepare("INSERT INTO students (admission_no, first_name, last_name, gender, dob, class_id, photo_path, address, guardian_name, guardian_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $errors = [];
    $rowCount = 0;
    foreach ($rows as $idx => $r) {
        $rowCount++;
        // flexible keys (lowercase)
        $get = function($k) use ($r) {
            foreach ($r as $rk=>$rv) {
                if (strcasecmp(trim($rk), $k) === 0) return trim($rv);
            }
            return '';
        };
        $admission_no = $get('admission_no') ?: '';
        $first_name = $get('first_name') ?: '';
        $last_name = $get('last_name') ?: '';
        $gender = in_array(ucfirst(strtolower($get('gender'))), ['Male','Female','Other']) ? ucfirst(strtolower($get('gender'))) : 'Male';
        $dob = $get('dob') ?: null;
        $class_id = intval($get('class_id') ?: 0) ?: null;
        $guardian_name = $get('guardian_name') ?: '';
        $guardian_phone = $get('guardian_phone') ?: '';
        $address = $get('address') ?: '';
        $photo_filename = $get('photo_filename') ?: '';

        // validation
        $rowErrors = [];
        if ($first_name === '') $rowErrors[] = 'first_name required';
        if ($guardian_name === '') $rowErrors[] = 'guardian_name required';
        if ($guardian_phone === '' || !preg_match('/^\d{6,15}$/', $guardian_phone)) $rowErrors[] = 'guardian_phone invalid';
        if ($dob && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $rowErrors[] = 'dob invalid (YYYY-MM-DD)';

        $photo_to_use = null;
        if ($photo_filename) {
            // check in import images dir first
            if (!empty($importImgDir) && file_exists($importImgDir . '/' . $photo_filename)) {
                $safe = bin2hex(random_bytes(8)) . '_' . basename($photo_filename);
                if (rename($importImgDir . '/' . $photo_filename, $destImgDir . $safe)) $photo_to_use = $safe;
            } elseif (file_exists(__DIR__ . '/../../uploads/students/' . $photo_filename)) {
                // already in students upload folder
                $photo_to_use = $photo_filename;
            } else {
                // not found; ignore but record warning
                $rowErrors[] = 'photo file not found: ' . $photo_filename;
            }
        }

        if ($rowErrors) {
            $errors[] = ['row' => $rowCount, 'errors' => $rowErrors];
            continue;
        }

        // ensure admission_no
        if ($admission_no === '') $admission_no = 'FMA' . time() . rand(10,99);

        $insertStmt->bind_param('sssssissss', $admission_no, $first_name, $last_name, $gender, $dob, $class_id, $photo_to_use, $address, $guardian_name, $guardian_phone);
        $ok = $insertStmt->execute();
        if (!$ok) {
            $errors[] = ['row' => $rowCount, 'errors' => ['Insert failed: ' . $conn->error]];
        }
    }

    // cleanup temp import images dir
    cleanup_import_dir($importImgDir);

    $msg = 'Import completed. Rows processed: ' . $rowCount;
    echo json_encode(['success'=>true,'message'=>$msg,'errors'=>$errors]);
}

function cleanup_import_dir($dir) {
    if (!$dir || !is_dir($dir)) return;
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getRealPath());
        else unlink($file->getRealPath());
    }
    @rmdir($dir);
}
