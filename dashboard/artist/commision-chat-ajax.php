<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

// ── Auth guard (mirrors commissions.php) ──────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'artist') {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}
$__userStatus = $conn->query("SELECT status, status_reason FROM users WHERE id = {$_SESSION['user_id']}")->fetch_assoc();
if (!$__userStatus || $__userStatus['status'] === 'blocked' || $__userStatus['status'] === 'pending') {
    http_response_code(403);
    echo json_encode(['error' => 'Account not active.']);
    exit;
}

$artistId   = (int) $_SESSION['user_id'];
$artistName = $_SESSION['name'] ?? 'Artist';

// ── Contact info filter function (kept identical to commissions.php) ──
function containsContactInfo(string $text): bool {
    $patterns = [
        '/\b[\w.+-]+@[\w-]+\.[a-z]{2,}\b/i',
        '/(?<!\d)(\+92[-\s]?3[0-9]{2}[-\s]?[0-9]{7}|03[0-9]{2}[-\s]?[0-9]{7})(?!\d)/',
        '/\b(instagram|insta|whatsapp|facebook|twitter|tiktok|snapchat)\b\s*[:\-@]\s*[a-zA-Z0-9._]{3,30}/i',
        '/@[a-zA-Z][a-zA-Z0-9._]{2,29}\b/',
        '/\b(iban|bank\s*(?:account|details|transfer)|easypaisa|jazzcash|sadapay|nayapay)\b/i',
        '/\b\d{4}[-\s]\d{4}[-\s]\d{4}[-\s]\d{4}\b/',
        '/\bhttps?:\/\/\S+/i',
        '/\b[a-z0-9-]+\.(com|net|org|io|co|pk)\b/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $text)) return true;
    }
    return false;
}

function verifyOwnership(mysqli $conn, int $orderId, int $artistId): bool {
    $check = $conn->prepare("
        SELECT o.id FROM orders o
        JOIN commission_requests cr ON cr.order_id = o.id
        WHERE o.id = ? AND cr.artist_id = ? AND o.order_type = 'commission'
    ");
    $check->bind_param('ii', $orderId, $artistId);
    $check->execute();
    return $check->get_result()->num_rows > 0;
}

$action = $_REQUEST['action'] ?? '';

// ── Poll for new messages ──────────────────────────────────
if ($action === 'poll') {
    $orderId = (int) ($_GET['order_id'] ?? 0);
    $afterId = (int) ($_GET['after_id'] ?? 0);

    if (!verifyOwnership($conn, $orderId, $artistId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid commission request.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM order_messages WHERE order_id = ? AND id > ? ORDER BY created_at ASC");
    $stmt->bind_param('ii', $orderId, $afterId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Mark any new buyer/admin messages as read now that the artist is viewing this thread
    $conn->query("UPDATE order_messages SET is_read_by_artist = 1 WHERE order_id = {$orderId} AND sender_role != 'artist' AND is_read_by_artist = 0");

    $out = [];
    foreach ($rows as $msg) {
        $out[] = [
            'id'              => (int) $msg['id'],
            'sender_role'     => $msg['sender_role'],
            'sender_name'     => $msg['sender_name'],
            'message'         => $msg['message'],
            'attachment_path' => $msg['attachment_path'],
            'message_type'    => $msg['message_type'] ?? 'text',
            'created_at'      => date('M j, g:i A', strtotime($msg['created_at'])),
        ];
    }
    echo json_encode(['messages' => $out]);
    exit;
}

// ── Send a new message ─────────────────────────────────────
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId       = (int) ($_POST['order_id'] ?? 0);
    $message       = trim($_POST['message'] ?? '');
    $hasAttachment = isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK;

    if (!verifyOwnership($conn, $orderId, $artistId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid commission request.']);
        exit;
    }
    if ($message && containsContactInfo($message)) {
        echo json_encode(['error' => 'Message blocked: Contact information (phone, email, social handles, bank details) cannot be shared.']);
        exit;
    }
    if (!$message && !$hasAttachment) {
        echo json_encode(['error' => 'Please enter a message or attach an image.']);
        exit;
    }

    $attachmentPath = null;
    $messageType    = 'text';

    if ($hasAttachment) {
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExt)) {
            echo json_encode(['error' => 'Invalid image type. Allowed: JPG, PNG, WEBP.']);
            exit;
        }
        if ($_FILES['attachment']['size'] > 10 * 1024 * 1024) {
            echo json_encode(['error' => 'Image must be under 10MB.']);
            exit;
        }
        $chatDir = __DIR__ . '/../../uploads/commission_chat/';
        if (!is_dir($chatDir)) {
            mkdir($chatDir, 0755, true);
        }
        $fileName = 'chat_' . $orderId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $chatDir . $fileName)) {
            chmod($chatDir . $fileName, 0644);
            $attachmentPath = 'uploads/commission_chat/' . $fileName;
            $messageType    = 'image';
        } else {
            echo json_encode(['error' => 'Failed to upload image. Please try again.']);
            exit;
        }
    }

    $stmt = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message, attachment_path, message_type) VALUES (?, 'artist', ?, ?, ?, ?, ?)");
    $stmt->bind_param('iissss', $orderId, $artistId, $artistName, $message, $attachmentPath, $messageType);
    $stmt->execute();
    $newId = $conn->insert_id;

    echo json_encode([
        'success' => true,
        'message' => [
            'id'              => $newId,
            'sender_role'     => 'artist',
            'sender_name'     => $artistName,
            'message'         => $message,
            'attachment_path' => $attachmentPath,
            'message_type'    => $messageType,
            'created_at'      => date('M j, g:i A'),
        ],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action.']);