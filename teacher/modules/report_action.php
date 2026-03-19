<?php
// teacher/report_action.php (optimized + teacher_id fix)
declare(strict_types=1);
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';

// Auth
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Teacher') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'msg'=>'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$csrf_session = $_SESSION['csrf_token'] ?? '';

// Helpers
function i($v){ return intval($v ?? 0); }
function e($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function json_exit($data, int $http = 200): void {
    if (ob_get_length()) ob_end_clean();
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function check_csrf(): bool {
    $token = $_POST['csrf_token'] ?? $_REQUEST['csrf_token'] ?? '';
    if (!$token) $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return $token && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ---------------------------------------------------------
// Resolve teacher_id from user_id
// ---------------------------------------------------------
$teacher_id = null;
try {
    $stmt = $conn->prepare("SELECT id FROM teachers WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($r = $res->fetch_assoc()) $teacher_id = (int)$r['id'];
    $stmt->close();
} catch (Throwable $e) {
    file_put_contents(__DIR__.'/../logs/teacher_reports.log', '['.date('c').'] teacher_id lookup failed: '.$e->getMessage().PHP_EOL, FILE_APPEND);
}
if (!$teacher_id) json_exit(['success'=>false,'msg'=>'Teacher record not found'],403);

// ---------------------------------------------------------
// Build accessible class list
// ---------------------------------------------------------
$teacher_class_ids = [];
try {
    $sql = "SELECT DISTINCT sc.class_id
            FROM subject_teachers st
            JOIN subject_classes sc ON st.subject_id = sc.subject_id
            WHERE st.teacher_id = ?";
    $stm = $conn->prepare($sql);
    $stm->bind_param('i', $teacher_id);
    $stm->execute();
    $res = $stm->get_result();
    while ($r = $res->fetch_assoc()) $teacher_class_ids[] = (int)$r['class_id'];
    $stm->close();
} catch (Throwable $e) {
    file_put_contents(__DIR__.'/../logs/teacher_reports.log', '['.date('c').'] class fetch failed: '.$e->getMessage().PHP_EOL, FILE_APPEND);
    json_exit(['success'=>false,'msg'=>'Server error'],500);
}

function teacher_has_class(array $classes, int $class_id): bool {
    return $class_id && in_array($class_id, $classes, true);
}

// ---------------------------------------------------------
// Routing
// ---------------------------------------------------------
$action = $_REQUEST['action'] ?? '';

try {

    // list_students
    if ($action === 'list_students') {
        $class_id = i($_REQUEST['class_id'] ?? 0);
        $start = i($_POST['start'] ?? 0);
        $length = i($_POST['length'] ?? 10);
        $search = trim($_POST['search']['value'] ?? '');

        if ($class_id && !teacher_has_class($teacher_class_ids, $class_id)) {
            json_exit(['draw'=>i($_POST['draw'] ?? 1),'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[]]);
        }

        $where=[]; $params=[]; $types='';
        if ($class_id) { $where[]='s.class_id=?'; $params[]=$class_id; $types.='i'; }
        if ($search!=='') {
            $where[]="(s.first_name LIKE CONCAT('%',?,'%') OR s.last_name LIKE CONCAT('%',?,'%') OR s.admission_no LIKE CONCAT('%',?,'%'))";
            $params=array_merge($params,[$search,$search,$search]); $types.='sss';
        }
        if (!$class_id && !empty($teacher_class_ids)) {
            $ph=implode(',',array_fill(0,count($teacher_class_ids),'?'));
            $where[]="s.class_id IN ($ph)";
            foreach($teacher_class_ids as $cid){$params[]=$cid;$types.='i';}
        }
        $whereSql=$where?'WHERE '.implode(' AND ',$where):'';

        $total=$conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c']??0;
        $stmt=$conn->prepare("SELECT COUNT(*) AS c FROM students s $whereSql");
        if($params)$stmt->bind_param($types,...$params);
        $stmt->execute();$filtered=$stmt->get_result()->fetch_assoc()['c']??0;$stmt->close();

        $sql="SELECT s.id,s.admission_no,CONCAT(s.first_name,' ',s.last_name) AS name,s.gender,s.dob,COALESCE(s.photo_path,'') AS photo,c.class_name,s.class_id
              FROM students s LEFT JOIN classes c ON s.class_id=c.id
              $whereSql ORDER BY s.first_name,s.last_name LIMIT ?,?";
        $params[]=$start;$params[]=$length;$types.='ii';
        $stmt=$conn->prepare($sql);
        if($params)$stmt->bind_param($types,...$params);
        $stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();

        $data=[];$idx=$start+1;
        foreach($rows as $r){
            $photo=$r['photo']?('/uploads/'.ltrim($r['photo'],'/')):'/assets/images/placeholder.png';
            $data[]=[
              'idx'=>$idx++,
              'id'=>(int)$r['id'],
              'admission_no'=>e($r['admission_no']),
              'photo'=>e($photo),
              'name'=>e($r['name']),
              'gender'=>e($r['gender']),
              'dob'=>e($r['dob']),
              'class_name'=>e($r['class_name']),
              'class_id'=>(int)$r['class_id'],
            ];
        }
        json_exit(['draw'=>i($_POST['draw']??1),'recordsTotal'=>(int)$total,'recordsFiltered'=>(int)$filtered,'data'=>$data]);
    }

    // list_scores_preview
    if ($action === 'list_scores_preview') {
        $class_id=i($_REQUEST['class_id']??0);
        $term_id=i($_REQUEST['term_id']??0);
        $year_id=i($_REQUEST['year_id']??0);
        if(!$class_id||!$term_id||!$year_id||!teacher_has_class($teacher_class_ids,$class_id)){
            json_exit(['success'=>true,'data'=>[]]);
        }

        $sql="SELECT s.id AS student_id,CONCAT(s.first_name,' ',s.last_name) AS student,
                     ss.total_marks AS total,ss.position,c.class_name,t.term_name AS term,ay.year_label AS year
              FROM scores_summary ss
              JOIN students s ON ss.student_id=s.id
              LEFT JOIN classes c ON ss.class_id=c.id
              LEFT JOIN terms t ON ss.term_id=t.id
              LEFT JOIN academic_years ay ON ss.year_id=ay.id
              WHERE ss.class_id=? AND ss.term_id=? AND ss.year_id=? ORDER BY ss.position ASC";
        $stmt=$conn->prepare($sql);
        $stmt->bind_param('iii',$class_id,$term_id,$year_id);
        $stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();

        $data=[];$idx=1;
        foreach($rows as $r){
            $data[]=[
              'idx'=>$idx++,
              'student'=>e($r['student']),
              'subject'=>'—',
              'total'=>(float)($r['total']??0),
              'position'=>$r['position']??'',
              'class_name'=>e($r['class_name']),
              'term'=>e($r['term']),
              'year'=>e($r['year'])
            ];
        }
        json_exit(['success'=>true,'data'=>$data]);
    }

    // get_meta
    if ($action==='get_meta'){
        $sid=i($_REQUEST['student_id']??0);
        $cid=i($_REQUEST['class_id']??0);
        $tid=i($_REQUEST['term_id']??0);
        $yid=i($_REQUEST['year_id']??0);
        if(!$sid||!$cid||!$tid||!$yid||!teacher_has_class($teacher_class_ids,$cid))
            json_exit(['success'=>false,'data'=>null]);

        $st=$conn->prepare("SELECT present_days,total_days,attendance_percent,class_teacher_remark,head_teacher_remark,attitude,interest,promotion_status,vacation_date,next_term_begins FROM report_meta WHERE student_id=? AND class_id=? AND term_id=? AND year_id=? LIMIT 1");
        $st->bind_param('iiii',$sid,$cid,$tid,$yid);
        $st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
        json_exit(['success'=>true,'data'=>$row?:null]);
    }

    // save_meta
    if ($action==='save_meta'){
        if($_SERVER['REQUEST_METHOD']!=='POST')json_exit(['success'=>false,'msg'=>'Invalid method'],405);
        if(!check_csrf())json_exit(['success'=>false,'msg'=>'Invalid CSRF token'],403);

        $sid=i($_POST['student_id']??0);$cid=i($_POST['class_id']??0);$tid=i($_POST['term_id']??0);$yid=i($_POST['year_id']??0);
        if(!$sid||!$cid||!$tid||!$yid||!teacher_has_class($teacher_class_ids,$cid))
            json_exit(['success'=>false,'msg'=>'Access denied'],403);

        $p=i($_POST['present_days']??0);$t=i($_POST['total_days']??0);
        $percent=$t?round(($p/$t)*100,2):0;
        $ctr=trim($_POST['class_teacher_remark']??'');
        $hdr=trim($_POST['head_teacher_remark']??'');
        $att=trim($_POST['attitude']??'');
        $int=trim($_POST['interest']??'');
        $pro=trim($_POST['promotion_status']??'');
        $vac=$_POST['vacation_date']??null;
        $nxt=$_POST['next_term_begins']??null;

        $sql="INSERT INTO report_meta (student_id,class_id,term_id,year_id,present_days,total_days,attendance_percent,
              class_teacher_remark,head_teacher_remark,attitude,interest,promotion_status,vacation_date,next_term_begins)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE present_days=VALUES(present_days),total_days=VALUES(total_days),
              attendance_percent=VALUES(attendance_percent),class_teacher_remark=VALUES(class_teacher_remark),
              head_teacher_remark=VALUES(head_teacher_remark),attitude=VALUES(attitude),interest=VALUES(interest),
              promotion_status=VALUES(promotion_status),vacation_date=VALUES(vacation_date),
              next_term_begins=VALUES(next_term_begins),updated_at=CURRENT_TIMESTAMP";
        $st=$conn->prepare($sql);
        $st->bind_param('iiiiddssssssss',$sid,$cid,$tid,$yid,$p,$t,$percent,$ctr,$hdr,$att,$int,$pro,$vac,$nxt);
        $ok=$st->execute();$st->close();
        json_exit(['success'=>$ok,'msg'=>$ok?'Saved successfully':'Save failed']);
    }

    // generate_class_pdf
    if ($action==='generate_class_pdf'){
        if($_SERVER['REQUEST_METHOD']!=='POST')json_exit(['success'=>false,'msg'=>'Invalid method'],405);
        if(!check_csrf())json_exit(['success'=>false,'msg'=>'Invalid CSRF token'],403);

       require_once __DIR__.'/render_student_report.php';
        $class_id=i($_POST['class_id']??0);
        $term_id=i($_POST['term_id']??0);
        $year_id=i($_POST['year_id']??0);
        if(!$class_id||!$term_id||!$year_id||!teacher_has_class($teacher_class_ids,$class_id))
            json_exit(['success'=>false,'msg'=>'Access denied'],403);

        $st=$conn->prepare("SELECT id FROM students WHERE class_id=? ORDER BY first_name,last_name");
        $st->bind_param('i',$class_id);
        $st->execute();$students=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
        if(!$students)json_exit(['success'=>false,'msg'=>'No students found'],404);

        require_once __DIR__.'/../vendor/autoload.php';
        $mpdf=new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4','margin_top'=>35,'margin_bottom'=>25,'tempDir'=>__DIR__.'/../tmp']);
        $mpdf->SetHTMLHeader('<div style="border-bottom:2px solid #003;padding-bottom:4px;"><b>Class Reports</b></div>');
        $mpdf->SetHTMLFooter('<div style="border-top:1px solid #aaa;font-size:10;text-align:center">Generated '.date('d M Y H:i').' | Page {PAGENO}/{nbpg}</div>');

        $first=true;
        foreach($students as $s){
            $html=render_student_html($conn,(int)$s['id'],$class_id,$term_id,$year_id,['for_pdf'=>true]);
            if(!$first)$mpdf->AddPage();
            $mpdf->WriteHTML($html);
            $first=false;
        }
        if(ob_get_length())ob_end_clean();
        $className=$conn->query("SELECT class_name FROM classes WHERE id=".intval($class_id))->fetch_assoc()['class_name']??'class';
        $filename=preg_replace('/[^A-Za-z0-9_-]/','_',$className)."_Reports.pdf";
        $mpdf->Output($filename,'I');
        exit;
    }

    json_exit(['success'=>false,'msg'=>'Unknown action'],400);

} catch (Throwable $e) {
    file_put_contents(__DIR__.'/../logs/teacher_reports.log', '['.date('c').'] '.$e->getMessage().PHP_EOL, FILE_APPEND);
    json_exit(['success'=>false,'msg'=>'Server error: '.$e->getMessage()],500);
}
