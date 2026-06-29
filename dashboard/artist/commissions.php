<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// ── Auth guard ───────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'artist') {
    header('Location: ../../login.php');
    exit;
}
$__userStatus = $conn->query("SELECT status, status_reason FROM users WHERE id = {$_SESSION['user_id']}")->fetch_assoc();
if ($__userStatus['status'] === 'blocked') {
    session_destroy();
    header('Location: ../../login.php?blocked=1&reason=' . urlencode($__userStatus['status_reason'] ?? ''));
    exit;
}
if ($__userStatus['status'] === 'pending') {
    session_destroy();
    $__pendingEmail = $conn->query("SELECT email FROM users WHERE id={$_SESSION['user_id']}")->fetch_assoc()['email'] ?? '';
header('Location: ../../login.php?pending=1&email=' . urlencode($__pendingEmail));
    exit;
}

$artistId = (int) $_SESSION['user_id'];  // ← whatever comes next in the file

 $artistId   = (int) $_SESSION['user_id'];
 $artistName = $_SESSION['name'] ?? 'Artist';
 $successMsg = '';
 $errorMsg = '';

// ── Contact info filter function ─────────────────────────
function containsContactInfo(string $text): bool {
    $patterns = [
        '/\b[\w.+-]+@[\w-]+\.[a-z]{2,}\b/i',           
        '/(\+92[-\s]?[0-9]{3}[-\s]?[0-9]{7}|(?<!\d)0[0-9]{2,3}[-\s]?[0-9]{6,8})/',   
        '/\b(instagram|insta|ig|whatsapp|wa|facebook|fb|twitter|tiktok|snapchat)\s*[:\-@]?\s*\w+/i',
        '/@[a-zA-Z0-9._]{2,30}/',                        
        '/\b(iban|account\s*no|bank|easypaisa|jazzcash|sadapay|nayapay)\b/i',
        '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/', 
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $text)) return true;
    }
    return false;
}

// ── Fetch artist avatar ───────────────────────────────
 $artistInfo = $conn->query("SELECT profile_picture FROM users WHERE id = $artistId")->fetch_assoc();
 $avatarUrl  = $artistInfo['profile_picture'] ? '../../' . ltrim($artistInfo['profile_picture'], './') : null;

// ── Handle Suggest Price POST action ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'suggest_price') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $proposedPrice = $_POST['proposed_price'] ?? '';
    $proposedWeight = $_POST['proposed_weight_kg'] ?? '';

    // Verify this commission belongs to the artist
    $check = $conn->prepare("
        SELECT o.id, o.order_status, o.price_status FROM orders o
        JOIN commission_requests cr ON cr.order_id = o.id
        WHERE o.id = ? AND cr.artist_id = ? AND o.order_type = 'commission'
    ");
    $check->bind_param('ii', $orderId, $artistId);
    $check->execute();
    $orderRow = $check->get_result()->fetch_assoc();

    if (!$orderRow) {
        $errorMsg = 'Invalid commission request.';
    } elseif ($proposedPrice === '' || $proposedPrice === null || !is_numeric($proposedPrice) || floatval($proposedPrice) <= 0) {
        $errorMsg = 'Please enter a valid price greater than 0.';
    } elseif ($proposedWeight === '' || $proposedWeight === null || !is_numeric($proposedWeight) || floatval($proposedWeight) <= 0) {
        $errorMsg = 'Please enter a valid expected weight greater than 0.';
    } elseif (!in_array($orderRow['order_status'], ['assigned', 'price_proposed'])) {
    $errorMsg = 'Price can only be suggested while the commission is assigned or awaiting buyer response.';
    } elseif (($orderRow['price_status'] ?? 'none') === 'accepted') {
        $errorMsg = 'Price has already been accepted by the buyer. No further changes allowed.';
    } else {
        $priceVal = floatval($proposedPrice);
        $weightVal = floatval($proposedWeight);
        $newOrderStatus = 'price_proposed';
        $newPriceStatus = 'proposed';

        $stmt = $conn->prepare("
            UPDATE orders 
            SET proposed_price = ?, 
                price_status = ?, 
                order_status = ?,
                subtotal = ?,
                total = ?,
                proposed_weight_kg = ?,
                updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param('dssdddi', $priceVal, $newPriceStatus, $newOrderStatus, $priceVal, $priceVal, $weightVal, $orderId);
        $stmt->execute();

        // Log status change in history
        $adminId = $artistId; // artist is making the change
        $note = "Artist proposed price: PKR " . number_format($priceVal) . " (Est. weight: " . $weightVal . "kg)";
        $stmtH = $conn->prepare("
            INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) 
            VALUES (?, ?, 'price_proposed', 'artist', ?, ?)
        ");
        $stmtH->bind_param('isis', $orderId, $orderRow['order_status'], $adminId, $note);
        $stmtH->execute();

        $successMsg = 'Price of PKR ' . number_format($priceVal) . ' (Est. weight: ' . $weightVal . 'kg) proposed to buyer. Waiting for their response.';
    }
}

// ── Handle status update POST action ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    // Change 6: Remove 'confirmed' from allowed statuses (security)
    $allowedStatuses = ['processing', 'ready_to_ship', 'shipped', 'delivered', 'cancelled', 'payment_confirmed'];
    
    // Verify this commission belongs to the artist
    $check = $conn->prepare("
        SELECT o.id, o.order_status FROM orders o
        JOIN commission_requests cr ON cr.order_id = o.id
        WHERE o.id = ? AND cr.artist_id = ? AND o.order_type = 'commission'
    ");
    $check->bind_param('ii', $orderId, $artistId);
    $check->execute();
    $orderRow = $check->get_result()->fetch_assoc();
    
    if (!$orderRow) {
        $errorMsg = 'Invalid commission request.';
    } elseif (!in_array($newStatus, $allowedStatuses)) {
        $errorMsg = 'Invalid status selected.';
    } else {
        $oldStatus = $orderRow['order_status'];
        $stmt = $conn->prepare("UPDATE orders SET order_status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $newStatus, $orderId);
        $stmt->execute();

        // Log status change
        $note = "Status changed from " . ucfirst(str_replace('_', ' ', $oldStatus)) . " to " . ucfirst(str_replace('_', ' ', $newStatus));
        $stmtH = $conn->prepare("
            INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) 
            VALUES (?, ?, ?, 'artist', ?, ?)
        ");
        $stmtH->bind_param('issis', $orderId, $oldStatus, $newStatus, $artistId, $note);
        $stmtH->execute();

        $successMsg = 'Commission status updated to ' . ucfirst(str_replace('_', ' ', $newStatus)) . '.';
    }
}

// ── Handle send message POST action ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    // Verify this commission belongs to the artist
    $check = $conn->prepare("
        SELECT o.id FROM orders o
        JOIN commission_requests cr ON cr.order_id = o.id
        WHERE o.id = ? AND cr.artist_id = ? AND o.order_type = 'commission'
    ");
    $check->bind_param('ii', $orderId, $artistId);
    $check->execute();

    $hasAttachment = isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK;

    if ($check->get_result()->num_rows === 0) {
        $errorMsg = 'Invalid commission request.';
    } elseif ($message && containsContactInfo($message)) {
        $errorMsg = 'Message blocked: Contact information (phone, email, social handles, bank details) cannot be shared.';
    } elseif (!$message && !$hasAttachment) {
        $errorMsg = 'Please enter a message or attach an image.';
    } else {
        $attachmentPath = null;
        $messageType = 'text';

        if ($hasAttachment) {
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowedExt)) {
                $errorMsg = 'Invalid image type. Allowed: JPG, PNG, WEBP.';
            } elseif ($_FILES['attachment']['size'] > 10 * 1024 * 1024) {
                $errorMsg = 'Image must be under 10MB.';
            } else {
                $chatDir = __DIR__ . '/../../uploads/commission_chat/';
                if (!is_dir($chatDir)) {
                    mkdir($chatDir, 0755, true);
                }
                $fileName = 'chat_' . $orderId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $chatDir . $fileName)) {
                    chmod($chatDir . $fileName, 0644);
                    $attachmentPath = 'uploads/commission_chat/' . $fileName;
                    $messageType = 'image';
                } else {
                    $errorMsg = 'Failed to upload image. Please try again.';
                }
            }
        }

        if (empty($errorMsg)) {
            $stmt = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message, attachment_path, message_type) VALUES (?, 'artist', ?, ?, ?, ?, ?)");
            $stmt->bind_param('iissss', $orderId, $artistId, $artistName, $message, $attachmentPath, $messageType);
            $stmt->execute();
            $successMsg = 'Message sent.';
        }
    }
}

// ── Handle final digital deliverable upload (only after buyer approval) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_final_file') {
    $orderId = (int)($_POST['order_id'] ?? 0);

    $check = $conn->prepare("
        SELECT o.id, o.commission_final_approved, c.slug AS category_slug
        FROM orders o
        JOIN commission_requests cr ON cr.order_id = o.id
        LEFT JOIN categories c ON o.commission_category_id = c.id
        WHERE o.id = ? AND cr.artist_id = ? AND o.order_type = 'commission'
    ");
    $check->bind_param('ii', $orderId, $artistId);
    $check->execute();
    $orderRow = $check->get_result()->fetch_assoc();

    $hasFile = isset($_FILES['final_file']) && $_FILES['final_file']['error'] === UPLOAD_ERR_OK;
    $allowedFinalExt = ['zip', 'psd', 'ai', 'png', 'jpg', 'jpeg', 'pdf'];

    if (!$orderRow) {
        $errorMsg = 'Invalid commission request.';
    } elseif (!$orderRow['commission_final_approved']) {
        $errorMsg = 'The buyer must approve the final artwork before you can upload the deliverable.';
    } elseif (($orderRow['category_slug'] ?? '') !== 'digital-art') {
        $errorMsg = 'This upload is only for digital commissions.';
    } elseif (!$hasFile) {
        $errorMsg = 'Please choose a file to upload.';
    } else {
        $ext = strtolower(pathinfo($_FILES['final_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedFinalExt)) {
            $errorMsg = 'Invalid file type. Allowed: ZIP, PSD, AI, PNG, JPG, PDF.';
        } elseif ($_FILES['final_file']['size'] > 50 * 1024 * 1024) {
            $errorMsg = 'File must be under 50MB.';
        } else {
            $digitalDir = __DIR__ . '/../../uploads/digital_files/';
            if (!is_dir($digitalDir)) {
                mkdir($digitalDir, 0755, true);
            }
            $digitalName = 'commission_' . $orderId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($_FILES['final_file']['tmp_name'], $digitalDir . $digitalName)) {
                chmod($digitalDir . $digitalName, 0644);
                $dbPath = 'uploads/digital_files/' . $digitalName;
                $stmt = $conn->prepare("UPDATE orders SET commission_digital_file_path = ?, order_status = 'delivered', updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('si', $dbPath, $orderId);
                $stmt->execute();

                $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'payment_confirmed', 'delivered', 'artist', ?, 'Final digital artwork delivered.')");
                $stmtH->bind_param('ii', $orderId, $artistId);
                $stmtH->execute();

                $successMsg = 'Final artwork delivered to buyer.';
            } else {
                $errorMsg = 'Failed to upload file. Please try again.';
            }
        }
    }
}

// ── Fetch commission requests assigned to this artist ──

// ── Fetch commission requests assigned to this artist ──
 $sql = "
    SELECT o.id, o.order_number, o.order_status AS status, o.created_at,
           o.buyer_id, o.guest_name, o.guest_email, o.guest_phone,
           o.commission_category_id, o.budget_min, o.budget_max, o.commission_deadline AS deadline,
           o.commission_description AS description, o.commission_reference_image AS reference_image, 
           o.commission_size,
           o.commission_framed,
           o.commission_quantity,
           o.commission_delivery_city,
           o.commission_final_approved, o.commission_digital_file_path,
           o.admin_notes, o.total AS agreed_price,
           o.proposed_price, o.price_status, o.proposed_weight_kg,
           o.shipping_address, o.shipping_city, o.shipping_phone, 
           o.tracking_number, o.payment_method, o.payment_status,
           u.name AS buyer_name_real, u.email AS buyer_email_real, u.phone AS buyer_phone_real,
           c.name AS category_name, c.slug AS category_slug
    FROM commission_requests cr
    JOIN orders o ON cr.order_id = o.id
    LEFT JOIN users u ON o.buyer_id = u.id
    LEFT JOIN categories c ON o.commission_category_id = c.id
    WHERE cr.artist_id = ? AND o.order_type = 'commission' AND o.order_status NOT IN ('pending')
    ORDER BY
        CASE o.order_status
    WHEN 'payment_confirmed' THEN 1
    WHEN 'pending'           THEN 2
    WHEN 'price_proposed'    THEN 3
    WHEN 'assigned'          THEN 4
    WHEN 'payment_review'    THEN 5
    WHEN 'processing'        THEN 6
    WHEN 'shipped'           THEN 7
    WHEN 'delivered'         THEN 8
    WHEN 'cancelled'         THEN 9
END,
        o.created_at DESC
";
 $stmt = $conn->prepare($sql);
 $stmt->bind_param('i', $artistId);
 $stmt->execute();
 $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Map real user data over guest data for consistency
foreach ($requests as &$req) {
    $req['buyer_name'] = $req['buyer_name_real'] ?: $req['guest_name'];
    $req['buyer_email'] = $req['buyer_email_real'] ?: $req['guest_email'];
    $req['buyer_phone'] = $req['buyer_phone_real'] ?: $req['guest_phone'];
}
unset($req);
// ── Fetch unread message counts per commission ───────────
$unreadByOrder = [];
$totalUnread = 0;
if (!empty($requests)) {
    $orderIds = array_column($requests, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $unreadSql = "
        SELECT order_id, COUNT(*) AS unread_count
        FROM order_messages
        WHERE order_id IN ($placeholders)
          AND sender_role != 'artist'
          AND is_read_by_artist = 0
        GROUP BY order_id
    ";
    $unreadStmt = $conn->prepare($unreadSql);
    $unreadStmt->bind_param(str_repeat('i', count($orderIds)), ...$orderIds);
    $unreadStmt->execute();
    $unreadResult = $unreadStmt->get_result();
    while ($row = $unreadResult->fetch_assoc()) {
        $unreadByOrder[$row['order_id']] = (int)$row['unread_count'];
        $totalUnread += (int)$row['unread_count'];
    }
}

$pendingQCount = (int)$conn->query("
    SELECT COUNT(*) FROM artwork_questions aq
    JOIN artworks a ON aq.artwork_id = a.id
    WHERE a.artist_id = $artistId AND aq.answer IS NULL
")->fetch_row()[0];

$unseenOrderCount = (int)$conn->query("
    SELECT COUNT(DISTINCT o.id) FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = $artistId
      AND o.order_type = 'artwork'
      AND o.order_status NOT IN ('pending', 'payment_review')
      AND o.seen_by_artist = 0
")->fetch_row()[0];

$unreadOrderMsgs = (int)$conn->query("
    SELECT COUNT(DISTINCT om.id) FROM order_messages om
    JOIN orders o ON o.id = om.order_id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = $artistId
      AND o.order_type = 'artwork'
      AND om.sender_role != 'artist'
      AND om.is_read_by_artist = 0
")->fetch_row()[0];
// ── Fetch specific request for modal ─────────────────
 $viewRequest = null;
 $viewMessages = [];
if (isset($_GET['view'])) {
    $viewId = (int) $_GET['view'];
    foreach ($requests as $r) {
        if ($r['id'] == $viewId) {
            $viewRequest = $r;
            // Fetch messages for this commission from the unified order_messages table
            $msgStmt = $conn->prepare("SELECT * FROM order_messages WHERE order_id = ? ORDER BY created_at ASC");
            $msgStmt->bind_param('i', $viewId);
            $msgStmt->execute();
            $viewMessages = $msgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $markReadStmt = $conn->prepare("UPDATE order_messages SET is_read_by_artist = 1 WHERE order_id = ? AND sender_role != 'artist' AND is_read_by_artist = 0");
            $markReadStmt->bind_param('i', $viewId);
            $markReadStmt->execute();
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Commission Requests — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --bg:#F6EDDE; --card:#F6EDDE; --sand:#DDCDAE; --border:#0C3F30;
    --ink:#0C3F30; --body:#0C3F30; --muted:#0C3F30; --light:#0C3F30;
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }

/* Sidebar */
.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: var(--ink); border-right: 1px solid rgba(246,237,222,.1); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid rgba(246,237,222,.1); }
.sidebar-brand .logo-tag  { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--bg); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); font-weight: 400; margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--bg); text-decoration: none; border-left: 2px solid transparent; transition: all .15s; }
.nav-item:hover { color: var(--bg); background: rgba(255,255,255,0.05); border-left-color: rgba(255,255,255,0.2); }
.nav-item.active { color: var(--ink); background: var(--sand); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .7; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid rgba(246,237,222,.1); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: rgba(255,255,255,0.1); color: var(--bg); }

/* Topbar */
.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--ink); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.artist-chip { display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); padding: 5px 12px 5px 5px; border-radius: 30px; }
.artist-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--sand); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--ink); font-weight: 600; overflow: hidden; }
.artist-chip .avatar img { width: 100%; height: 100%; object-fit: cover; }
.artist-chip .name { font-size: 12px; font-weight: 500; color: var(--ink); }
.artist-chip .arrow { font-size: 12px; color: var(--muted); margin-left: 4px; }

/* Main Layout */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; max-width: 1200px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; margin-bottom: 20px; }

.success-msg { background: var(--sand); color: var(--ink); padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; border: 1px solid var(--border); }
.error-msg { background: var(--sand); color: var(--ink); padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; border: 1px solid var(--border); }

.info-box { background: var(--sand); border: 1px solid var(--border); border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; display: flex; gap: 12px; align-items: flex-start; }
.info-box svg { flex-shrink: 0; color: var(--ink); margin-top: 2px; }
.info-box-content h4 { font-size: 13px; font-weight: 500; color: var(--ink); margin-bottom: 4px; }
.info-box-content p  { font-size: 12px; color: var(--body); line-height: 1.5; }

.card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 10px; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); font-weight: 500; padding: 14px 20px; text-align: left; border-bottom: 1px solid var(--border); background: var(--sand); }
td { padding: 16px 20px; border-bottom: 1px solid var(--border); vertical-align: middle; font-size: 13px; color: var(--ink); }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--bg); box-shadow: 0 4px 12px rgba(12,63,48,.06); }

.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
.pill.pending         { background: var(--sand); color: var(--ink); border: 1px solid var(--border); }
.pill.price_proposed  { background: var(--sand); color: var(--ink); }
.pill.price_proposed { background: #FFF8E1; color: #856404; border: 1px solid #E8D5A0; }
.pill.payment_confirmed { background: #d4edda; color: #155724; border: 1px solid #28a745; font-weight: 700; }
.pill.processing       { background: #d4edda; color: #155724; border: 1px solid #28a745; }
.pill.shipped         { background: var(--sand); color: var(--ink); }
.pill.delivered       { background: var(--ink); color: var(--bg); }
.pill.cancelled       { background: var(--sand); color: var(--ink); }
.new-msg-pill{display:inline-flex;align-items:center;gap:6px;font-size:10px;font-weight:600;color:#c0392b;}
.red-dot{width:8px;height:8px;border-radius:50%;background:#c0392b;display:inline-block;animation:pulse-dot 1.5s infinite;}
@keyframes pulse-dot{0%{box-shadow:0 0 0 0 rgba(192,57,43,.5);}70%{box-shadow:0 0 0 5px rgba(192,57,43,0);}100%{box-shadow:0 0 0 0 rgba(192,57,43,0);}}

.price-status-badge { display: inline-block; font-size: 8px; letter-spacing: .3px; text-transform: uppercase; font-weight: 600; padding: 2px 7px; border-radius: 20px; white-space: nowrap; }
.price-status-badge.none     { background: #F4F4F4; color: #888; }
.price-status-badge.proposed { background: var(--sand); color: var(--ink); border: 1px solid var(--border); }
.price-status-badge.accepted { background: var(--ink); color: var(--bg); }
.price-status-badge.rejected { background: var(--sand); color: var(--ink); }

.view-btn { padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 500; border: 1px solid var(--border); background: #fff; color: var(--ink); cursor: pointer; text-decoration: none; transition: all .15s; display: inline-block; }
.view-btn:hover { border-color: var(--ink); background: var(--bg); }

.empty-state { padding: 60px 20px; text-align: center; }
.empty-state h3 { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--ink); margin-bottom: 8px; }
.empty-state p  { font-size: 13px; color: var(--muted); }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all .2s; }
.modal-overlay.active { opacity: 1; visibility: visible; }
.modal { background: var(--card); width: 100%; max-width: 750px; max-height: 90vh; border-radius: 20px; overflow-y: auto; position: relative; transform: translateY(20px); transition: transform .2s; border: 1px solid var(--border); }
.modal-overlay.active .modal { transform: translateY(0); }
.modal-header { padding: 24px 28px 16px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: flex-start; }
.modal-header h2 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--ink); }
.close-modal { background: none; border: none; cursor: pointer; color: var(--muted); padding: 4px; font-size: 22px; line-height: 1; }
.close-modal:hover { color: var(--ink); }
.modal-body { padding: 28px; }
.detail-grid { display: grid; grid-template-columns: 140px 1fr; gap: 14px; margin-bottom: 20px; }
.detail-label { color: var(--body); font-weight: 500; font-size: 12px; }
.detail-value { color: var(--ink); font-size: 13px; line-height: 1.5; }
.detail-full { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
.detail-full h5 { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: var(--body); margin-bottom: 10px; }
.detail-full p { font-size: 13px; line-height: 1.6; color: var(--body); background: var(--sand); padding: 14px; border-radius: 10px; border: 1px solid var(--border); }
.ref-image { margin-top: 8px; max-width: 100%; max-height: 300px; border-radius: 6px; border: 1px solid var(--border); object-fit: contain; }
.admin-note { margin-top: 20px; background: var(--sand); padding: 14px; border-radius: 10px; border: 1px solid var(--border); }
.admin-note h5 { font-size: 10px; color: var(--ink); text-transform: uppercase; margin-bottom: 6px; }
.admin-note p { font-size: 12px; color: var(--ink); line-height: 1.5; margin: 0; background: none; padding: 0; }

/* SUGGEST PRICE BOX */
.suggest-price-box { margin-top: 20px; background: var(--sand); padding: 20px; border-radius: 8px; border: 1px solid var(--border); }
.suggest-price-box h5 { font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); font-weight: 600; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
.suggest-price-box h5 svg { width: 16px; height: 16px; color: var(--ink); }
.suggest-price-info { font-size: 11.5px; color: var(--body); line-height: 1.5; margin-bottom: 14px; }
.suggest-price-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.suggest-price-row input { width: 200px; padding: 10px 14px; border: 1.5px solid var(--sand); border-radius: 9px; font-size: 14px; font-family: 'DM Sans', sans-serif; color: var(--ink); outline: none; font-weight: 500; background: var(--bg); }
.suggest-price-row input:focus { border-color: var(--ink); }
.suggest-price-row .currency-label { font-size: 13px; color: var(--body); font-weight: 500; }
.suggest-price-btn { padding: 10px 20px; background: var(--ink); color: var(--bg); border: none; border-radius: 9px; font-size: 12px; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background .15s; display: inline-flex; align-items: center; gap: 6px; }
.suggest-price-btn:hover { background: #1a503f; }
.suggest-price-btn svg { width: 14px; height: 14px; }
.suggest-price-current { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.suggest-price-current .label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
.suggest-price-current .amount { font-family: 'Playfair Display', serif; font-size: 22px; font-weight: 500; color: var(--ink); }
.suggest-price-waiting { margin-top: 10px; font-size: 11.5px; color: var(--ink); display: flex; align-items: center; gap: 6px; }
.suggest-price-accepted { margin-top: 10px; font-size: 11.5px; color: var(--ink); display: flex; align-items: center; gap: 6px; }
.suggest-price-rejected { margin-top: 10px; font-size: 11.5px; color: var(--ink); display: flex; align-items: center; gap: 6px; }
.suggest-price-locked { font-size: 11.5px; color: var(--muted); margin-top: 8px; }

.status-update-box { margin-top: 20px; background: var(--bg); padding: 16px; border-radius: 12px; border: 1px solid var(--border); }
.status-update-box h5 { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: var(--muted); margin-bottom: 12px; }
.status-select-group { display: flex; gap: 10px; align-items: center; }
.status-select-group select { flex: 1; padding: 10px 14px; border: 1.5px solid var(--sand); border-radius: 9px; font-size: 13px; font-family: 'DM Sans', sans-serif; color: var(--ink); background: var(--bg); outline: none; }
.status-select-group select:focus { border-color: var(--ink); }
.status-btn { padding: 10px 18px; background: var(--ink); color: var(--bg); border: none; border-radius: 9px; font-size: 12px; font-weight: 500; cursor: pointer; transition: background .15s; font-family: 'DM Sans', sans-serif; }
.status-btn:hover { background: #1a503f; }

.checkout-box { margin-top: 20px; background: var(--sand); padding: 16px; border-radius: 12px; border: 1px solid var(--border); }
.checkout-box h5 { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: var(--ink); margin-bottom: 12px; }
.checkout-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.checkout-item .cl { font-size: 9px; letter-spacing: 1px; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; }
.checkout-item .cv { font-size: 13px; font-weight: 500; color: var(--ink); }

.chat-section { margin-top: 24px; padding-top: 20px; border-top: 2px solid var(--border); }
.chat-title { font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; margin-bottom: 12px; }
.chat-messages { background: var(--bg); border-radius: 12px; height: 300px; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px; border: 1px solid var(--border); }
.message { display: flex; flex-direction: column; max-width: 80%; }
.message.artist { align-self: flex-end; }
.message.admin { align-self: flex-start; }
.message.buyer { align-self: flex-start; }
.message-bubble { padding: 10px 14px; border-radius: 18px; font-size: 13px; line-height: 1.5; word-break: break-word; }
.message.artist .message-bubble { background: var(--ink); color: white; border-bottom-right-radius: 4px; }
.message.admin .message-bubble { background: var(--sand); color: var(--ink); border-bottom-left-radius: 4px; }
.message.buyer .message-bubble { background: var(--sand); color: var(--ink); border-bottom-left-radius: 4px; }
.message-meta { font-size: 10px; color: var(--muted); margin-top: 4px; padding: 0 6px; display: flex; gap: 8px; align-items: center; }
.message.artist .message-meta { justify-content: flex-end; }
.chat-input-area { margin-top: 16px; display: flex; gap: 10px; }
.chat-input-area input { flex: 1; padding: 12px 16px; border: 1.5px solid var(--sand); border-radius: 30px; font-size: 13px; font-family: 'DM Sans', sans-serif; outline: none; background: var(--bg); color: var(--ink); }
.chat-input-area input:focus { border-color: var(--ink); }
.chat-input-area button { background: var(--ink); color: white; border: none; border-radius: 30px; padding: 0 20px; font-weight: 500; cursor: pointer; transition: background .2s; }
.chat-input-area button:hover { background: #1a503f; }
.chat-warning { font-size: 10px; color: var(--muted); margin-top: 8px; text-align: center; }

/* Drawer (Hamburger) */
#nav-drawer{display:none; position:fixed; top:0; right:0; bottom:0; width:260px; background:var(--ink); z-index:200; padding:20px; transform:translateX(100%); transition:transform .3s ease; flex-direction:column; border-left:1px solid rgba(246,237,222,.1);}
#nav-drawer.open{transform:translateX(0); display:flex;}
#nav-overlay{display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:199;}
#nav-overlay.open{display:block;}
.ham-btn{display:none; flex-direction:column; gap:4px; background:none; border:none; cursor:pointer; padding:4px;}
.ham-btn span{width:22px; height:2px; background:var(--bg); border-radius:2px; transition:.2s;}
.drawer-top{display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; border-bottom:1px solid rgba(246,237,222,.1); padding-bottom:15px;}
.drawer-logo{font-family:'Playfair Display',serif; font-size:18px; color:var(--bg); font-weight:400;}
.drawer-close{background:none; border:none; color:var(--bg); font-size:24px; cursor:pointer;}
.drawer-links a{display:block; color:var(--bg); text-decoration:none; padding:12px 0; border-bottom:1px solid rgba(246,237,222,.05); font-size:14px;}
.drawer-links a:hover{color:var(--sand);}
.drawer-actions{margin-top:auto; padding-top:20px; border-top:1px solid rgba(246,237,222,.1);}
.drawer-actions a{display:block; padding:10px 0; color:var(--bg); text-decoration:none; font-size:13px;}

/* Responsive */
@media (max-width: 1080px) {
    .checkout-grid { grid-template-columns: 1fr; }
}

@media (max-width: 768px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; padding: 0 16px; }
    .content { padding: 16px; }
    .detail-grid { grid-template-columns: 1fr; gap: 8px; }
    .message { max-width: 95%; }
    .suggest-price-row { flex-direction: column; align-items: stretch; }
    .suggest-price-row input { width: 100%; }
    .ref-image { width: 100%; }
    .ham-btn { display: flex; }
    .artist-chip { display: none; }

    /* Table → card layout on mobile (same as orders page) */
    thead { display: none; }
    table, tbody, tr, td { display: block; width: 100%; }
    tr { margin-bottom: 16px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(12,63,48,.06); }
    td { border-bottom: 1px solid var(--border); padding: 12px 16px; text-align: left; position: relative; padding-left: 42%; }
    td::before { position: absolute; top: 50%; left: 16px; transform: translateY(-50%); width: 35%; padding-right: 10px; white-space: nowrap; font-weight: 600; font-size: 11px; color: var(--ink); opacity: 0.7; }
    td:nth-child(1)::before { content: "Buyer"; }
    td:nth-child(2)::before { content: "Project Type"; }
    td:nth-child(3)::before { content: "Agreed Price"; }
    td:nth-child(4)::before { content: "Status"; }
    td:last-child { border-bottom: none; text-align: center; padding-left: 16px; }
    td:last-child::before { display: none; }
    .view-btn { padding: 10px 20px; font-size: 13px; width: 100%; text-align: center; display: block; }
}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="logo-tag">Art Bazaar</div>
        <div class="logo-name">Dashboard</div>
        <span class="logo-badge">Artist</span>
    </div>
    <div class="sidebar-section">Overview</div>
    <a href="index.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Overview
    </a>
    <div class="sidebar-section">My Work</div>
    <a href="upload-artwork.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Upload Artwork
    </a>
    <a href="my-artworks.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
        My Artworks
        <?php if ($pendingQCount > 0): ?><span class="badge" style="background:#c0392b;color:#fff;display:flex;align-items:center;gap:4px;"><span style="background:#fff;width:6px;height:6px;border-radius:50%;display:inline-block;"></span><?= $pendingQCount ?></span><?php endif; ?>
    </a>
    <a href="commissions.php" class="nav-item active">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Commission Requests
        <?php if ($totalUnread > 0): ?><span class="badge" style="background:#c0392b;color:#fff;display:flex;align-items:center;gap:4px;"><span style="background:#fff;width:6px;height:6px;border-radius:50%;display:inline-block;"></span><?= $totalUnread ?></span><?php endif; ?>
    </a>
    <a href="orders.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        Orders
        <?php if ($unreadOrderMsgs > 0): ?><span class="badge" style="background:#c0392b;color:#fff;display:flex;align-items:center;gap:4px;"><span style="background:#fff;width:6px;height:6px;border-radius:50%;display:inline-block;"></span><?= $unreadOrderMsgs ?></span><?php elseif ($unseenOrderCount > 0): ?><span class="badge"><?= $unseenOrderCount ?> New</span><?php endif; ?>
    </a>
    <div class="sidebar-section">Account</div>
    <a href="profile.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        My Profile
    </a>
    <div class="sidebar-bottom">
        <a href="../../logout.php" class="signout-btn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign out
        </a>
    </div>
</aside>

<header class="topbar">
    <div class="topbar-left"><h1>Commission Orders</h1></div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:12px;">
        <div class="artist-chip">
            <div class="avatar"><?php if ($avatarUrl): ?><img src="<?= htmlspecialchars($avatarUrl) ?>" alt=""><?php else: ?><?= strtoupper(substr($artistName, 0, 1)) ?><?php endif; ?></div>
            <span class="name"><?= htmlspecialchars($artistName) ?></span>
            <span class="arrow">∨</span>
        </div>
        <button class="ham-btn" onclick="openDrawer()"><span></span><span></span><span></span></button>
    </div>
</header>

<main class="main">
<div class="content">
    <div class="section-title">Incoming Requests</div>

    <?php if ($successMsg): ?>
        <div class="success-msg"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="error-msg"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="info-box">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <div class="info-box-content"><h4>How it works</h4><p>Commissions are assigned to you by the admin. Review the brief, suggest a price, and chat with the buyer. Once the buyer accepts your price and completes checkout, you can update the status as you work. Please do not share contact information — all communication stays here.</p></div>
    </div>

    <?php if (empty($requests)): ?>
        <div class="card empty-state"><h3>No commission requests yet</h3><p>When a buyer requests custom work from you, it will appear here.</p></div>
    <?php else: ?>
        <div class="card">
            <table><thead><tr><th>Buyer</th><th>Project Type</th><th>Agreed Price</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                <?php foreach ($requests as $req): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($req['buyer_name']) ?></strong><div style="font-size:11px;color:var(--muted);margin-top:2px;"><?= date('M j, Y', strtotime($req['created_at'])) ?></div></td>
                    <td><?= htmlspecialchars($req['category_name'] ?: 'Custom Artwork') ?></td>
                    <td style="font-size:12px;"><?= !empty($req['agreed_price']) ? '<strong style="color:var(--ink)">PKR '.number_format((float)$req['agreed_price']).'</strong>' : '<span style="color:var(--muted)">Pending</span>' ?></td>
                    <td><span class="pill <?= $req['status'] ?>"><?= ucfirst(str_replace('_', ' ', $req['status'])) ?></span>
                        <?php if (!empty($unreadByOrder[$req['id']])): ?>
                          <span class="new-msg-pill" style="margin-left:8px;">
                            <span class="red-dot"></span>
                            New Message<?= $unreadByOrder[$req['id']] > 1 ? 's' : '' ?>
                          </span>
                        <?php endif; ?>
                    </td>
                    <td><a href="?view=<?= $req['id'] ?>" class="view-btn">View & Chat</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody></table>
        </div>
    <?php endif; ?>
</div>
</main>

<?php if ($viewRequest): 
    // ── FIX 1: Lock price suggestion once accepted ──
    $canSuggestPrice = in_array($viewRequest['status'], ['assigned', 'price_proposed'])
                       && ($viewRequest['price_status'] ?? 'none') !== 'accepted';
    
    // Change 3: Logic for Payment Approved / Awaiting Review
    $isPaymentApproved = $viewRequest['status'] === 'payment_confirmed';
$isAwaitingPaymentReview = $viewRequest['status'] === 'payment_review';

    // ── FIX 2: Derive display price-status from order_status when it's moved past negotiation ──
    $currentPriceStatus = $viewRequest['price_status'] ?? 'none';
    if (in_array($viewRequest['status'], ['assigned', 'payment_confirmed', 'processing', 'shipped', 'delivered', 'payment_review'])
    && $currentPriceStatus !== 'rejected') {
    $currentPriceStatus = 'accepted';
}
?>
<div class="modal-overlay active" id="modalOverlay">
    <div class="modal">
        <div class="modal-header">
            <h2>Commission #<?= $viewRequest['order_number'] ?: $viewRequest['id'] ?></h2>
            <button class="close-modal" onclick="window.location.href='commissions.php'">&times;</button>
        </div>
        <div class="modal-body">
            <div class="detail-grid">
                <div class="detail-label">Status</div><div class="detail-value"><span class="pill <?= $viewRequest['status'] ?>"><?= ucfirst(str_replace('_', ' ', $viewRequest['status'])) ?></span></div>
                <div class="detail-label">Buyer Name</div><div class="detail-value"><strong><?= htmlspecialchars($viewRequest['buyer_name']) ?></strong></div>
                <div class="detail-label">Artwork Type</div><div class="detail-value"><?= htmlspecialchars($viewRequest['category_name'] ?: 'Custom / Not specified') ?></div>
                <div class="detail-label">Budget Range</div><div class="detail-value"><?= ($viewRequest['budget_min'] || $viewRequest['budget_max']) ? 'PKR '.number_format($viewRequest['budget_min'] ?? 0).' – '.number_format($viewRequest['budget_max'] ?? 0) : 'Not specified' ?></div>
                <div class="detail-label">Agreed Price</div><div class="detail-value"><?= !empty($viewRequest['agreed_price']) ? '<strong style="color:var(--ink)">PKR '.number_format($viewRequest['agreed_price']).'</strong>' : 'Not set yet' ?></div>
                <div class="detail-label">Deadline</div><div class="detail-value"><?= $viewRequest['deadline'] ? date('F j, Y', strtotime($viewRequest['deadline'])) : 'No specific deadline' ?></div>
                
                <!-- NEW FIELDS -->
                <div class="detail-label">Artwork Size</div><div class="detail-value"><?= !empty($viewRequest['commission_size']) ? htmlspecialchars($viewRequest['commission_size']) : 'Not specified' ?></div>
                <div class="detail-label">Framed / Unframed</div><div class="detail-value"><?= !empty($viewRequest['commission_framed']) && $viewRequest['commission_framed'] !== 'not_specified' ? ucfirst(htmlspecialchars($viewRequest['commission_framed'])) : 'Not specified' ?></div>
                <div class="detail-label">Quantity</div><div class="detail-value"><?= !empty($viewRequest['commission_quantity']) ? (int)$viewRequest['commission_quantity'] : 1 ?></div>
                <div class="detail-label">Delivery City</div><div class="detail-value"><?= !empty($viewRequest['commission_delivery_city']) ? htmlspecialchars($viewRequest['commission_delivery_city']) : 'Not specified' ?></div>
                <!-- END NEW FIELDS -->

                <div class="detail-label">Payment Method</div><div class="detail-value"><?= !empty($viewRequest['payment_method']) ? ucfirst(str_replace('_', ' ', $viewRequest['payment_method'])) : 'Not set' ?></div>
                <div class="detail-label">Payment Status</div><div class="detail-value"><?= !empty($viewRequest['payment_status']) ? ucfirst($viewRequest['payment_status']) : 'Pending' ?></div>
                <div class="detail-label">Submitted On</div><div class="detail-value"><?= date('F j, Y \a\t g:i A', strtotime($viewRequest['created_at'])) ?></div>
            </div>

            <?php 
            $shippingAddress = $viewRequest['shipping_address'] ?? '';
            $shippingCity = $viewRequest['shipping_city'] ?? '';
            $trackingNumber = $viewRequest['tracking_number'] ?? '';
            // $shippingPhone removed from variables as requested
            if ($shippingAddress || $shippingCity): 
            ?>
            <div class="checkout-box">
                <h5>📦 Checkout & Shipping Details</h5>
                <div class="checkout-grid">
                    <?php if ($shippingAddress): ?><div class="checkout-item"><div class="cl">Address</div><div class="cv"><?= htmlspecialchars($shippingAddress) ?></div></div><?php endif; ?>
                    <?php if ($shippingCity): ?><div class="checkout-item"><div class="cl">City</div><div class="cv"><?= htmlspecialchars($shippingCity) ?></div></div><?php endif; ?>
                    <!-- PHONE BLOCK REMOVED -->
                    <div class="checkout-item"><div class="cl">Tracking</div><div class="cv"><?= $trackingNumber ? htmlspecialchars($trackingNumber) : 'Not available' ?></div></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($isPaymentApproved): ?>
            <div style="background:#d4edda;border:1px solid #28a745;border-radius:12px;padding:16px 20px;margin-bottom:20px;display:flex;gap:12px;align-items:center;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#155724" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <div>
                    <strong style="color:#155724;font-size:13px;">Payment Approved — Start Working!</strong>
                    <div style="font-size:12px;color:#155724;margin-top:2px;">The admin has verified the buyer's payment. You can now begin this commission.</div>
                </div>
            </div>
            <?php elseif ($isAwaitingPaymentReview): ?>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:12px;padding:16px 20px;margin-bottom:20px;display:flex;gap:12px;align-items:center;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#856404" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <div>
                    <strong style="color:#856404;font-size:13px;">Buyer Has Paid — Awaiting Admin Approval</strong>
                    <div style="font-size:12px;color:#856404;margin-top:2px;">The buyer submitted their payment screenshot. Wait for the admin to verify and approve before starting work.</div>
                </div>
            </div>
            <?php endif; ?>

            <div class="detail-full"><h5>Project Description</h5><p><?= nl2br(htmlspecialchars($viewRequest['description'] ?? '')) ?></p></div>

            <?php if ($viewRequest['reference_image']): ?>
                <div class="detail-full"><h5>Reference Image</h5><img src="../../uploads/commissions/<?= htmlspecialchars($viewRequest['reference_image']) ?>" class="ref-image" alt=""></div>
            <?php endif; ?>

            <?php if ($viewRequest['admin_notes']): ?>
                <div class="admin-note"><h5>📝 Admin Note</h5><p><?= nl2br(htmlspecialchars($viewRequest['admin_notes'])) ?></p></div>
            <?php endif; ?>

            <!-- SUGGEST PRICE BOX -->
            <div class="suggest-price-box">
                <h5>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    Suggest Your Price
                </h5>
                <div class="suggest-price-info">
                    Set the price you want to charge for this commission. The buyer will see your proposed price and can accept (proceeds to payment) or reject (sends it back for renegotiation).
                </div>

                <?php if ($canSuggestPrice): ?>
                <form method="POST" class="suggest-price-row" onsubmit="return confirm('Submit this price and weight to the buyer?')">
                    <input type="hidden" name="action" value="suggest_price">
                    <input type="hidden" name="order_id" value="<?= $viewRequest['id'] ?>">
                    <span class="currency-label">PKR</span>
                    <input type="number" name="proposed_price" value="<?= !empty($viewRequest['proposed_price']) ? $viewRequest['proposed_price'] : '' ?>" placeholder="e.g. 12000" min="1" step="0.01" required>
                    <input type="number" name="proposed_weight_kg" value="<?= !empty($viewRequest['proposed_weight_kg']) ? $viewRequest['proposed_weight_kg'] : '' ?>" placeholder="Weight (kg)" min="0.01" step="0.01" required style="width:140px;">
                    <span class="currency-label">kg</span>
                    <button type="submit" class="suggest-price-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        <?= !empty($viewRequest['proposed_price']) ? 'Update Price & Weight' : 'Propose Price & Weight' ?>
                    </button>
                </form>
                <?php else: ?>
                <div class="suggest-price-locked">
                    ⚙️ Price suggestion is only available when the commission is <strong>Pending</strong> or <strong>Price Proposed</strong> and the price hasn't been accepted yet. Current status: <strong><?= ucfirst(str_replace('_', ' ', $viewRequest['status'])) ?></strong>
                </div>
                <?php endif; ?>

                <?php if (!empty($viewRequest['proposed_price'])): ?>
                <div class="suggest-price-current">
                    <span class="label">Your Proposed Price:</span>
                    <span class="amount">PKR <?= number_format((float)$viewRequest['proposed_price']) ?></span>
                    <?php if (!empty($viewRequest['proposed_weight_kg'])): ?>
                    <span class="label" style="margin-left:8px;">Est. Weight:</span>
                    <span class="amount" style="font-size:16px;"><?= rtrim(rtrim(number_format((float)$viewRequest['proposed_weight_kg'], 2), '0'), '.') ?> kg</span>
                    <?php endif; ?>
                    <span class="price-status-badge <?= $currentPriceStatus ?>"><?= ucfirst($currentPriceStatus) ?></span>
                </div>
                <?php endif; ?>

                <?php if ($currentPriceStatus === 'proposed'): ?>
                <div class="suggest-price-waiting">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Waiting for buyer to accept or reject your price…
                </div>
                <?php elseif ($currentPriceStatus === 'accepted'): ?>
                <div class="suggest-price-accepted">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Buyer accepted your price! Awaiting payment/checkout.
                </div>
                <?php elseif ($currentPriceStatus === 'rejected'): ?>
                <div class="suggest-price-rejected">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    Buyer rejected your price. You can suggest a new price above.
                </div>
                <?php endif; ?>
            </div>

            <div class="status-update-box">
                <h5>⚙️ Update Commission Status</h5>
                <?php if (in_array($viewRequest['status'], ['pending', 'price_proposed', 'assigned', 'payment_review'])): ?>
    <p style="font-size:12px;color:var(--muted);">Status updates are available once payment is approved by admin.</p>
<?php elseif ($viewRequest['status'] === 'payment_confirmed'): ?>
    <p style="font-size:12px;color:#2E7D32;font-weight:500;">✓ Payment confirmed! Move this to <strong>Processing</strong> when you begin work.</p>
    <form method="POST" class="status-select-group" style="margin-top:10px;">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="order_id" value="<?= $viewRequest['id'] ?>">
        <input type="hidden" name="new_status" value="processing">
        <button type="submit" class="status-btn" style="background:#2E7D32;">▶ Start Working (Mark as Processing)</button>
    </form>
<?php elseif ($viewRequest['status'] === 'processing'): ?>
    <p style="font-size:12px;color:#1565C0;font-weight:500;">🎨 In progress. Mark it <strong>Ready to Ship</strong> once the artwork is finished and packed.</p>
    <form method="POST" class="status-select-group" style="margin-top:10px;" onsubmit="return confirm('Mark this commission as ready to ship? Admin will be notified to book the courier.')">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="order_id" value="<?= $viewRequest['id'] ?>">
        <input type="hidden" name="new_status" value="ready_to_ship">
        <button type="submit" class="status-btn" style="background:#1565C0;">📦 Mark as Ready to Ship</button>
    </form>
<?php elseif ($viewRequest['status'] === 'ready_to_ship'): ?>
    <p style="font-size:12px;color:#6A1B9A;font-weight:500;">✓ Marked ready. Waiting for admin to book the courier.</p>
<?php else: ?>
                <form method="POST" class="status-select-group">
                    <input type="hidden" name="action" value="update_status"><input type="hidden" name="order_id" value="<?= $viewRequest['id'] ?>">
                    <select name="new_status">
                        <?php foreach (['processing', 'shipped', 'delivered', 'cancelled'] as $s): ?>
                            <option value="<?= $s ?>" <?= $viewRequest['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="status-btn">Update</button>
                </form>
                <?php endif; ?>
            </div>

            <?php if (!empty($viewRequest['commission_final_approved']) && ($viewRequest['category_slug'] ?? '') === 'digital-art' && empty($viewRequest['commission_digital_file_path'])): ?>
            <div class="status-update-box" style="border-color:#2E7D32;">
                <h5>✅ Buyer Approved — Upload Final Artwork</h5>
                <p style="font-size:12px;color:var(--muted);margin-bottom:10px;">The buyer has approved the design. Upload the final high-resolution file to deliver this commission.</p>
                <form method="POST" enctype="multipart/form-data" class="status-select-group">
                    <input type="hidden" name="action" value="upload_final_file">
                    <input type="hidden" name="order_id" value="<?= $viewRequest['id'] ?>">
                    <input type="file" name="final_file" accept=".zip,.psd,.ai,.png,.jpg,.jpeg,.pdf" required>
                    <button type="submit" class="status-btn" style="background:#2E7D32;">Upload &amp; Deliver</button>
                </form>
            </div>
            <?php elseif (!empty($viewRequest['commission_digital_file_path'])): ?>
            <div class="status-update-box" style="border-color:#2E7D32;">
                <h5>✅ Final Artwork Delivered</h5>
                <p style="font-size:12px;color:var(--muted);">The buyer can now download the final file from their order page.</p>
            </div>
            <?php endif; ?>

            <div class="chat-section">
                <div class="chat-title">💬 Conversation</div>
                <div class="chat-messages" id="chatMessages">
                    <?php if (empty($viewMessages)): ?><div style="text-align:center;padding:20px;color:var(--muted);">No messages yet.</div><?php else: ?>
                    <?php foreach ($viewMessages as $msg): $roleClass = ($msg['sender_role'] === 'artist') ? 'artist' : (($msg['sender_role'] === 'admin') ? 'admin' : 'buyer'); ?>
                    <div class="message <?= $roleClass ?>">
                        <?php if (($msg['message_type'] ?? 'text') === 'image' && !empty($msg['attachment_path'])): ?>
                            <img src="../../<?= htmlspecialchars($msg['attachment_path']) ?>" alt="Attachment" style="max-width:220px;border-radius:8px;display:block;margin-bottom:<?= $msg['message'] ? '6px' : '0' ?>;">
                        <?php endif; ?>
                        <?php if (!empty($msg['message'])): ?><div class="message-bubble"><?= htmlspecialchars($msg['message']) ?></div><?php endif; ?>
                        <div class="message-meta"><span><?= htmlspecialchars($msg['sender_name']) ?></span><span>•</span><span><?= date('M j, g:i A', strtotime($msg['created_at'])) ?></span></div>
                    </div>
                    <?php endforeach; ?><?php endif; ?>
                </div>
                <div id="chatAttachPreview" style="display:none;align-items:center;gap:10px;margin-top:10px;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:8px 12px;">
                    <img id="chatAttachPreviewImg" src="" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid var(--border);">
                    <span style="font-size:11px;color:var(--muted);flex:1;">Image attached</span>
                    <button type="button" id="chatAttachRemoveBtn" style="background:none;border:none;color:var(--ink);font-size:16px;cursor:pointer;line-height:1;">×</button>
                </div>
                <form method="POST" enctype="multipart/form-data" class="chat-input-area" id="chatForm" onsubmit="return validateAndSubmit(this)">
                    <input type="hidden" name="action" value="send_message"><input type="hidden" name="order_id" value="<?= $viewRequest['id'] ?>">
                    <label style="cursor:pointer;display:flex;align-items:center;padding:0 4px;" title="Attach image">
                        📎<input type="file" name="attachment" id="chatAttachInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
                    </label>
                    <input type="text" name="message" placeholder="Type message... (No phone/email/socials)" autocomplete="off">
                    <button type="submit">Send</button>
                </form>
                <div class="chat-warning">⚠️ Contact info is automatically blocked.</div>
            </div>
        </div>
    </div>
</div>

<script>
function validateAndSubmit(f){const m=f.querySelector('input[name="message"]');const t=m.value.trim();const p=[/[\w.+-]+@[\w-]+\.[a-z]{2,}/i,/(\+92[\s-]?[0-9]{3}[\s-]?[0-9]{7}|(?<!\d)0[0-9]{2,3}[\s-]?[0-9]{6,8})/,/\b(instagram|insta|whatsapp|facebook|twitter|tiktok|snapchat|ig|fb|wa)\b(\s*[:\-@]\s*|\s+(?:is|id|number|no|me|on|at)\s+)\w+/i,/@[a-zA-Z0-9._]{2,30}/,/(iban|bank|easypaisa|jazzcash)/i];for(let r of p){if(r.test(t)){alert('Contact info blocked.');m.value='';return false;}}return true;}
const c=document.getElementById('chatMessages');if(c)c.scrollTop=c.scrollHeight;
document.addEventListener('keydown',function(e){if(e.key==='Escape'){window.location.href='commissions.php';}});

// ── Chat image attachment preview ──────────────────────
const chatAttachInput = document.getElementById('chatAttachInput');
const chatAttachPreview = document.getElementById('chatAttachPreview');
const chatAttachPreviewImg = document.getElementById('chatAttachPreviewImg');
const chatAttachRemoveBtn = document.getElementById('chatAttachRemoveBtn');

if (chatAttachInput) {
    chatAttachInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) { chatAttachPreview.style.display = 'none'; return; }
        const reader = new FileReader();
        reader.onload = function(ev) {
            chatAttachPreviewImg.src = ev.target.result;
            chatAttachPreview.style.display = 'flex';
        };
        reader.readAsDataURL(file);
    });
}
if (chatAttachRemoveBtn) {
    chatAttachRemoveBtn.addEventListener('click', function() {
        chatAttachInput.value = '';
        chatAttachPreview.style.display = 'none';
    });
}
</script>

<?php endif; ?>

<!-- NAV DRAWER (Mobile) -->
<div id="nav-overlay" onclick="closeDrawer()"></div>
<div id="nav-drawer">
    <div class="drawer-top">
        <div class="drawer-logo">Art Bazaar</div>
        <button class="drawer-close" onclick="closeDrawer()">&times;</button>
    </div>
    <div class="drawer-links">
        <a href="index.php">Dashboard</a>
        <a href="upload-artwork.php">Upload Artwork</a>
        <a href="my-artworks.php">My Artworks
            <?php if ($pendingQCount > 0): ?><span style="background:#c0392b;color:#fff;font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $pendingQCount ?></span><?php endif; ?>
        </a>
        <a href="commissions.php">Commissions
            <?php if ($totalUnread > 0): ?><span style="background:#c0392b;color:#fff;font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $totalUnread ?></span><?php endif; ?>
        </a>
        <a href="orders.php">Orders
            <?php if ($unreadOrderMsgs > 0): ?><span style="background:#c0392b;color:#fff;font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $unreadOrderMsgs ?></span><?php elseif ($unseenOrderCount > 0): ?><span style="background:var(--sand);color:var(--ink);font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $unseenOrderCount ?> New</span><?php endif; ?>
        </a>
        <a href="profile.php">Profile</a>
    </div>
    <div class="drawer-actions">
        <a href="../../logout.php">Logout</a>
    </div>
</div>

<script>
function openDrawer() {
    document.getElementById('nav-drawer').classList.add('open');
    document.getElementById('nav-overlay').classList.add('open');
}
function closeDrawer() {
    document.getElementById('nav-drawer').classList.remove('open');
    document.getElementById('nav-overlay').classList.remove('open');
}
</script>

</body>
</html>