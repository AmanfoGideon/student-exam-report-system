<?php
// includes/db.php

// Prefer environment variables with safe fallbacks for local dev
$host = getenv('DB_HOST') ?: "localhost"; 
$user = getenv('DB_USER') ?: "Hepagk";      // change if needed
$pass = getenv('DB_PASS') ?: "Akoben210252"; // your MySQL password if set
$db   = getenv('DB_NAME') ?: "exam_report_db"; // your database name

$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    // Avoid leaking sensitive connection details
    die("Database connection failed.");
}

// Optional: set charset to avoid encoding issues
$conn->set_charset("utf8mb4");
?>
