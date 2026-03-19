<?php
$conn = new mysqli("localhost", "Hepagk", "Akoben210252", "exam_report_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
} else {
    echo "✅ Connected successfully<br>";
    $res = $conn->query("SHOW GRANTS FOR CURRENT_USER");
    while ($r = $res->fetch_row()) {
        echo htmlspecialchars($r[0]) . "<br>";
    }
}
?>
