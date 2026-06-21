<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

$buyerId   = (int) $_SESSION['user_id'];
$orderId   = (int) ($_GET['order_id'] ?? 0);
$artworkId = (int) ($_GET['artwork_id'] ?? 0);

if (!$orderId || !$artworkId) {
    http_response_code(404);
    exit('Not found.');
}

// Verify: this buyer owns this order, it is delivered, contains this artwork,
// and the artwork is a Digital Art item with a file on record.
$stmt = $conn->prepare("
    SELECT a.digital_file_path
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN artworks a ON a.id = oi.item_id AND oi.item_type = 'artwork'
    JOIN categories c ON a.category_id = c.id
    WHERE o.id = ?
      AND o.buyer_id = ?
      AND o.order_status = 'delivered'
      AND a.id = ?
      AND c.slug = 'digital-art'
    LIMIT 1
");
$stmt->bind_param('iii', $orderId, $buyerId, $artworkId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row || empty($row['digital_file_path'])) {
    http_response_code(403);
    exit('You do not have access to this file.');
}

$relativePath = $row['digital_file_path'];
$fullPath = __DIR__ . '/../../' . $relativePath;

if (!file_exists($fullPath)) {
    http_response_code(404);
    exit('File not found. Please contact support.');
}

// Track download count (best-effort, non-blocking)
$conn->query("UPDATE artworks SET digital_download_count = digital_download_count + 1 WHERE id = $artworkId");

$downloadName = basename($fullPath);

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: must-revalidate');
header('Pragma: public');
flush();
readfile($fullPath);
exit;