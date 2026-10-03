<?php
// Follow / unfollow an artist (AJAX). Place next to artist-profile.php.
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');

function out($a, $code = 200) { http_response_code($code); echo json_encode($a); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Invalid request.'], 405);
if (!isset($_SESSION['user_id'])) out(['ok' => false, 'login' => true, 'error' => 'Please log in to follow artists.'], 401);

$uid      = (int)$_SESSION['user_id'];
$artistId = (int)($_POST['artist_id'] ?? 0);
$action   = $_POST['action'] ?? 'toggle';   // follow | unfollow | toggle

if (!$artistId || $artistId === $uid) out(['ok' => false, 'error' => 'You cannot follow this profile.'], 400);

// Target must be an active artist
$st = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'artist' AND status = 'active'");
$st->bind_param('i', $artistId); $st->execute();
if (!$st->get_result()->fetch_assoc()) out(['ok' => false, 'error' => 'Artist not found.'], 404);

// Artists can't follow (they have no "following" page)
$st = $conn->prepare("SELECT role FROM users WHERE id = ?");
$st->bind_param('i', $uid); $st->execute();
$me = $st->get_result()->fetch_assoc();
if (!$me || $me['role'] === 'artist') out(['ok' => false, 'error' => 'Only buyer accounts can follow artists.'], 403);

$st = $conn->prepare("SELECT id FROM artist_followers WHERE buyer_id = ? AND artist_id = ?");
$st->bind_param('ii', $uid, $artistId); $st->execute();
$already = (bool)$st->get_result()->fetch_assoc();

if ($action === 'toggle') $action = $already ? 'unfollow' : 'follow';

if ($action === 'follow' && !$already) {
    $st = $conn->prepare("INSERT IGNORE INTO artist_followers (buyer_id, artist_id, created_at) VALUES (?, ?, NOW())");
    $st->bind_param('ii', $uid, $artistId); $st->execute();
} elseif ($action === 'unfollow' && $already) {
    $st = $conn->prepare("DELETE FROM artist_followers WHERE buyer_id = ? AND artist_id = ?");
    $st->bind_param('ii', $uid, $artistId); $st->execute();
}

$st = $conn->prepare("SELECT COUNT(*) AS c FROM artist_followers WHERE artist_id = ?");
$st->bind_param('i', $artistId); $st->execute();
$count = (int)$st->get_result()->fetch_assoc()['c'];

out(['ok' => true, 'following' => $action === 'follow', 'count' => $count]);
