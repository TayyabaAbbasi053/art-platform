<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/smartlane.php';
header('Content-Type: application/json');

// ── Auth guard (mirrors order-detail.php) ──────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$buyerId   = (int) $_SESSION['user_id'];
$buyerName = $_SESSION['name'] ?? 'Buyer';

// ── Contact info filter function (kept identical to order-detail.php / commissions.php) ──
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

// Verifies the order belongs to this buyer and is a commission order; returns the order row or null.
function verifyOwnership(mysqli $conn, int $orderId, int $buyerId): ?array {
    $stmt = $conn->prepare("SELECT id, order_type FROM orders WHERE id = ? AND buyer_id = ?");
    $stmt->bind_param('ii', $orderId, $buyerId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order || $order['order_type'] !== 'commission') {
        return null;
    }
    return $order;
}

$action = $_REQUEST['action'] ?? '';

// ── Poll for new messages ──────────────────────────────────
if ($action === 'poll') {
    $orderId = (int) ($_GET['order_id'] ?? 0);
    $afterId = (int) ($_GET['after_id'] ?? 0);

    if (!verifyOwnership($conn, $orderId, $buyerId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid order.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM order_messages WHERE order_id = ? AND id > ? ORDER BY created_at ASC");
    $stmt->bind_param('ii', $orderId, $afterId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Mark any new artist/admin messages as read now that the buyer is viewing this thread
    $conn->query("UPDATE order_messages SET is_read_by_buyer = 1 WHERE order_id = {$orderId} AND sender_role != 'buyer' AND is_read_by_buyer = 0");

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

    if (!verifyOwnership($conn, $orderId, $buyerId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid order.']);
        exit;
    }
    if ($message && containsContactInfo($message)) {
        echo json_encode(['error' => 'Message blocked: Contact information (phone, email, social handles) cannot be shared.']);
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

    $stmt = $conn->prepare("
        INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message, attachment_path, message_type, is_read_by_admin, is_read_by_artist, is_read_by_buyer)
        VALUES (?, 'buyer', ?, ?, ?, ?, ?, 0, 0, 1)
    ");
    $stmt->bind_param('iissss', $orderId, $buyerId, $buyerName, $message, $attachmentPath, $messageType);
    $stmt->execute();
    $newId = $conn->insert_id;

    echo json_encode([
        'success' => true,
        'message' => [
            'id'              => $newId,
            'sender_role'     => 'buyer',
            'sender_name'     => $buyerName,
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