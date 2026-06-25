<?php
// ============================================
// config/db.php — Central DB Connection
// ============================================

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$host     = "localhost";
$port     = 3307;
$dbname   = "artplatform";
$username = "root";
$password = "";

$conn = new mysqli($host, $username, $password, $dbname, $port);