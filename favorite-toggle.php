<?php
session_start();
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');

// Not logged in — no favoriting for guests
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'login_required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$artworkId = (int)($_POST['artwork_id'] ?? 0);

if ($artworkId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_artwork']);
    exit;
}

// Make sure the artwork actually exists (avoid orphan favorites / junk input)
$check = $conn->prepare("SELECT id FROM artworks WHERE id = ? LIMIT 1");
$check->bind_param('i', $artworkId);
$check->execute();
$exists = $check->get_result()->fetch_row();
$check->close();

if (!$exists) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

// Is it already favorited?
$stmt = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND artwork_id = ? LIMIT 1");
$stmt->bind_param('ii', $userId, $artworkId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_row();
$stmt->close();

if ($existing) {
    // Already favorited -> remove it
    $del = $conn->prepare("DELETE FROM favorites WHERE id = ?");
    $del->bind_param('i', $existing[0]);
    $del->execute();
    $del->close();
    echo json_encode(['success' => true, 'favorited' => false]);
} else {
    // Not favorited yet -> add it
    $ins = $conn->prepare("INSERT INTO favorites (user_id, artwork_id) VALUES (?, ?)");
    $ins->bind_param('ii', $userId, $artworkId);
    $ins->execute();
    $ins->close();
    echo json_encode(['success' => true, 'favorited' => true]);
}
