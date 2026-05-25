<?php
// ============================================
// config/db.php — Central DB Connection
// ============================================

$host     = "localhost";
$port     = 3307;
$dbname   = "artplatform";
$username = "root";
$password = "";

$conn = new mysqli($host, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>