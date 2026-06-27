<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// ── Auth guard ───────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

 $buyerId = (int) $_SESSION['user_id'];
 $buyerName = $_SESSION['name'] ?? 'Buyer';
 $orderId = (int) ($_GET['id'] ?? 0);

if (!$orderId) {
    header('Location: orders.php');
    exit;
}

// ── Fetch order details (including commission fields) and verify ownership ──
 $stmt = $conn->prepare("
    SELECT o.*, 
           c.name AS commission_category_name,
           c.slug AS commission_category_slug,
           ua.name AS commission_artist_name,
           (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count
    FROM orders o
    LEFT JOIN categories c ON o.commission_category_id = c.id
    LEFT JOIN commission_requests cr ON cr.order_id = o.id
    LEFT JOIN users ua ON cr.artist_id = ua.id
    WHERE o.id = ? AND o.buyer_id = ?
");
 $stmt->bind_param('ii', $orderId, $buyerId);
 $stmt->execute();
 $order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header('Location: orders.php');
    exit;
}

// ── Determine if this is a commission order ──────────────
 $isCommission = ($order['order_type'] === 'commission');

// ── Fetch order items with artwork details ───────────────
 $items = [];

 $itemQuery = $conn->prepare("
    SELECT oi.*, 
           a.title AS artwork_title, 
           (SELECT ai.image_path FROM artwork_images ai WHERE ai.artwork_id = a.id AND ai.is_cover = 1 LIMIT 1) AS cover_image, 
           a.artist_id,
           a.delivery_status AS artwork_delivery_status,
           u.name AS artist_name,
           c.slug AS category_slug
    FROM order_items oi
    LEFT JOIN artworks a ON oi.item_type = 'artwork' AND oi.item_id = a.id
    LEFT JOIN users u ON a.artist_id = u.id
    LEFT JOIN categories c ON a.category_id = c.id
    WHERE oi.order_id = ?
");
 $itemQuery->bind_param('i', $orderId);
 $itemQuery->execute();
 $itemResult = $itemQuery->get_result();

while ($row = $itemResult->fetch_assoc()) {
    $items[] = $row;
}

// ── Fetch order status history ───────────────────────────
 $history = [];
 $histQuery = $conn->prepare("
    SELECT * FROM order_status_history 
    WHERE order_id = ? 
    ORDER BY created_at DESC
");
 $histQuery->bind_param('i', $orderId);
 $histQuery->execute();
 $history = $histQuery->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch chat messages (ONLY for commission orders) ─────
 $messages = [];
if ($isCommission) {
    $msgQuery = $conn->prepare("
        SELECT * FROM order_messages 
        WHERE order_id = ? 
        ORDER BY created_at ASC
    ");
    $msgQuery->bind_param('i', $orderId);
    $msgQuery->execute();
    $messages = $msgQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    // Mark messages as read by buyer when they open this order
$conn->query("UPDATE order_messages SET is_read_by_buyer = 1 WHERE order_id = $orderId AND sender_role != 'buyer' AND is_read_by_buyer = 0");
}

// ── Contact info filter function ─────────────────────────
function containsContactInfo(string $text): bool {
    $patterns = [
        '/\b[\w.+-]+@[\w-]+\.[a-z]{2,}\b/i',
        '/(\+92[-\s]?[0-9]{3}[-\s]?[0-9]{7}|(?<!\d)0[0-9]{2,3}[-\s]?[0-9]{6,8})/',
        '/\b(instagram|insta|ig|whatsapp|wa|facebook|fb|twitter|tiktok|snapchat)\s*[:\-@]?\s*\w+/i',
        '/@[a-zA-Z0-9._]{2,30}/',
        '/\b(iban|account\s*no|bank|easypaisa|jazzcash|sadapay|nayapay)\b/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $text)) return true;
    }
    return false;
}

// ── Handle chat message submission (ONLY for commissions) ─
 $chatSuccess = false;
 $chatError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message' && $isCommission) {
    $message = trim($_POST['message'] ?? '');
    $hasAttachment = isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK;

    if ($message && containsContactInfo($message)) {
        $chatError = 'Message blocked: Contact information (phone, email, social handles) cannot be shared.';
    } elseif (!$message && !$hasAttachment) {
        $chatError = 'Please enter a message or attach an image.';
    } else {
        $attachmentPath = null;
        $messageType = 'text';

        if ($hasAttachment) {
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowedExt)) {
                $chatError = 'Invalid image type. Allowed: JPG, PNG, WEBP.';
            } elseif ($_FILES['attachment']['size'] > 10 * 1024 * 1024) {
                $chatError = 'Image must be under 10MB.';
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
                    $chatError = 'Failed to upload image. Please try again.';
                }
            }
        }

        if (empty($chatError)) {
            $stmt = $conn->prepare("
                INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message, attachment_path, message_type, is_read_by_admin, is_read_by_artist, is_read_by_buyer)
                VALUES (?, 'buyer', ?, ?, ?, ?, ?, 0, 0, 1)
            ");
            $stmt->bind_param('iissss', $orderId, $buyerId, $buyerName, $message, $attachmentPath, $messageType);
            $stmt->execute();
            $chatSuccess = true;

            // Refresh messages
            $msgQuery = $conn->prepare("SELECT * FROM order_messages WHERE order_id = ? ORDER BY created_at ASC");
            $msgQuery->bind_param('i', $orderId);
            $msgQuery->execute();
            $messages = $msgQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}

// ── Handle "Approve Final Artwork" (commission only) ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_final' && $isCommission) {
    $approvedMessageId = (int)($_POST['approved_message_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE orders SET commission_final_approved = 1, approved_message_id = ? WHERE id = ? AND buyer_id = ?");
    $approvedMessageIdOrNull = $approvedMessageId > 0 ? $approvedMessageId : null;
    $stmt->bind_param('iii', $approvedMessageIdOrNull, $orderId, $buyerId);
    $stmt->execute();

    $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, ?, ?, 'buyer', ?, 'Buyer approved the final design.')");
    $currentStatus = $order['order_status'];
    $stmtH->bind_param('issi', $orderId, $currentStatus, $currentStatus, $buyerId);
    $stmtH->execute();

    $order['commission_final_approved'] = 1;
    header('Location: order-detail.php?id=' . $orderId . '&msg=approved');
    exit;
}

// ── Handle order cancellation request ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order']) && $order['order_status'] === 'pending') {
    $conn->query("UPDATE orders SET order_status = 'cancelled' WHERE id = $orderId");
    
    // Release artworks back to available
    $conn->query("UPDATE artworks SET status = 'approved', reserved_by = NULL WHERE id IN (SELECT item_id FROM order_items WHERE order_id = $orderId AND item_type = 'artwork')");
    
    // Add status history
    $stmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes)
        VALUES (?, 'pending', 'cancelled', 'buyer', ?, 'Order cancelled by buyer')
    ");
    $stmt->bind_param('ii', $orderId, $buyerId);
    $stmt->execute();
    
    $order['order_status'] = 'cancelled';
    header('Location: order-detail.php?id=' . $orderId . '&msg=cancelled');
    exit;
}

function getImageUrl($path, $type = 'artwork') {
    if (!$path) return null;
    $path = ltrim($path, './');
    if (strpos($path, 'uploads/') !== false) return '../../' . $path;
    return $type === 'commission' ? '../../uploads/commissions/' . $path : '../../uploads/artworks/' . $path;
}

function getStatusBadgeClass($status) {
    $classes = [
        'pending' => 'pending',
        'confirmed' => 'confirmed',
        'processing' => 'processing',
        'shipped' => 'shipped',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled'
    ];
    return $classes[$status] ?? 'pending';
}

function getStatusLabel($status) {
    $labels = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled'
    ];
    return $labels[$status] ?? ucfirst($status);
}

function getPaymentMethodLabel($method) {
    $labels = [
        'cod' => 'Cash on Delivery',
        'bank_transfer' => 'Bank Transfer',
        'easypaisa' => 'Easypaisa',
        'jazzcash' => 'JazzCash'
    ];
    return $labels[$method] ?? ucfirst($method);
}

function getPaymentStatusLabel($status) {
    $labels = [
        'pending' => 'Pending',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'refunded' => 'Refunded'
    ];
    return $labels[$status] ?? ucfirst($status);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Order #<?= htmlspecialchars($order['order_number']) ?> — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
  /* Design System Updates */
  --bg:#F6EDDE; --card:#F6EDDE; --sand:#DDCDAE; --border:#0C3F30;
  --ink:#0C3F30; --body:#0C3F30; --muted:#0C3F30; --light:#0C3F30;
  
  --sidebar:260px; --top:60px; --r:12px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.55;}
a{text-decoration:none;color:inherit;}
img{max-width:100%;display:block;}

/* SIDEBAR */
.sidebar{position:fixed;top:0;left:0;width:var(--sidebar);height:100vh;background:var(--ink);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;overflow-y:auto;}
.sidebar-brand{padding:24px 24px 20px;border-bottom:1px solid var(--border);}
.sidebar-brand .logo-text{font-family:'Playfair Display',serif;font-size:20px;font-weight:500;color:var(--bg);}
.sidebar-brand .logo-tag{font-size:9px;letter-spacing:2px;color:var(--sand);margin-top:2px;}
.sidebar-section{padding:20px 20px 8px;font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);font-weight:500;}
.nav-item{display:flex;align-items:center;gap:12px;padding:10px 20px;font-size:13px;color:var(--bg);border-left:2px solid transparent;transition:all .15s;}
.nav-item:hover{color:var(--ink);background:rgba(246,237,222,0.15);border-left-color:var(--sand);}
.nav-item.active{color:var(--ink);background:var(--sand);border-left-color:var(--ink);font-weight:500;}
.nav-item .icon{width:18px;height:18px;opacity:.8;stroke:var(--bg);}
.nav-item.active .icon, .nav-item:hover .icon { stroke: var(--ink); }
.sidebar-bottom{margin-top:auto;padding:20px;border-top:1px solid var(--border);}
.signout-btn{display:flex;align-items:center;gap:10px;padding:10px;font-size:13px;color:var(--bg);border-radius:8px;transition:all .15s;}
.signout-btn:hover{background:var(--sand);color:var(--ink);}

/* TOPBAR */
.topbar{position:fixed;top:0;left:var(--sidebar);right:0;height:var(--top);background:var(--ink);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 32px;z-index:99;}
.topbar-left h1{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;color:var(--bg);}
.buyer-chip{display:flex;align-items:center;gap:10px;background:var(--sand);border:1px solid var(--border);padding:5px 12px 5px 8px;border-radius:30px;}
.buyer-chip .avatar{width:32px;height:32px;border-radius:50%;background:var(--ink);display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--bg);font-weight:600;}
.buyer-chip .name{font-size:13px;font-weight:500;color:var(--ink);}

/* MAIN */
.main{margin-left:var(--sidebar);padding-top:var(--top);min-height:100vh;}
.content{padding:28px 32px;max-width:1100px;}

/* BACK LINK */
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--ink);margin-bottom:20px;}
.back-link span{opacity:0.4;}
.back-link:hover{color:var(--muted);}

/* ORDER HEADER */
.order-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;margin-bottom:24px;}
.order-header h1{font-family:'Playfair Display',serif;font-size:24px;font-weight:400;color:var(--ink);}
.type-badge{display:inline-block;font-size:9px;letter-spacing:.5px;text-transform:uppercase;font-weight:600;padding:2px 8px;border-radius:20px;margin-left:8px;background:var(--sand);color:var(--ink);border:1px solid var(--border);}
.status-badge{display:inline-block;font-size:10px;letter-spacing:.5px;text-transform:uppercase;font-weight:600;padding:4px 12px;border-radius:20px;margin-left:12px;}
.status-badge.pending{background:var(--sand);color:var(--ink);}
.status-badge.confirmed{background:var(--sand);color:var(--ink);}
.status-badge.processing{background:var(--sand);color:var(--ink);}
.status-badge.shipped{background:var(--sand);color:var(--ink);}
.status-badge.delivered{background:var(--ink);color:var(--bg);}
.status-badge.cancelled{background:var(--sand);color:var(--ink);}
.cancel-btn{background:transparent;border:1px solid var(--border);padding:8px 16px;border-radius:8px;font-size:12px;cursor:pointer;transition:all .15s;color:var(--ink);}
.cancel-btn:hover{background:var(--sand);color:var(--ink);border-color:var(--ink);}

/* ORDER INFO GRID */
.info-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px;}
.info-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:16px;}
.info-label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;opacity:0.7;}
.info-value{font-size:14px;font-weight:500;color:var(--ink);}

/* COMMISSION BRIEF */
.commission-brief{background:var(--sand);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:28px;}
.cb-header{padding:16px 20px;border-bottom:1px solid var(--border);font-weight:600;font-size:15px;display:flex;align-items:center;gap:8px;color:var(--ink);}
.cb-body{padding:20px;}
.cb-desc{font-size:13px;color:var(--body);line-height:1.7;margin-bottom:16px;padding:14px;background:var(--card);border-radius:10px;}
.cb-ref-img{max-height:160px;border-radius:8px;margin-bottom:16px;border:1px solid var(--border);}
.cb-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:0;}
.cb-field{padding:10px 14px;background:var(--card);border-radius:8px;}
.cb-field-label{font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin-bottom:4px;opacity:0.7;}
.cb-field-value{font-size:13px;font-weight:500;color:var(--ink);}

/* ORDER ITEMS */
.items-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:28px;}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);font-weight:600;font-size:15px;color:var(--ink);}
.order-item{display:flex;gap:16px;padding:16px 20px;border-bottom:1px solid var(--border);}
.order-item:last-child{border-bottom:none;}
.item-img{width:70px;height:70px;border-radius:8px;object-fit:cover;background:var(--sand);flex-shrink:0;border:1px solid var(--border);}
.item-details{flex:1;}
.item-title{font-weight:600;margin-bottom:4px;color:var(--ink);}
.item-artist{font-size:12px;color:var(--muted);margin-bottom:4px;opacity:0.7;}
.item-meta{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;}
.item-meta span{font-size:11px;background:var(--sand);padding:2px 8px;border-radius:20px;color:var(--ink);}
.item-price{text-align:right;flex-shrink:0;}
.item-price .price{font-weight:600;font-size:15px;color:var(--ink);}
.item-price .qty{font-size:11px;color:var(--muted);opacity:0.7;}

/* STATUS TIMELINE */
.timeline-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:28px;}
.timeline-title{font-weight:600;margin-bottom:16px;color:var(--ink);}
.timeline{display:flex;flex-direction:column;gap:12px;}
.timeline-item{display:flex;gap:12px;}
.timeline-dot{width:10px;height:10px;border-radius:50%;background:var(--sand);margin-top:4px;flex-shrink:0;}
.timeline-dot.completed{background:var(--ink);}
.timeline-content{flex:1;}
.timeline-status{font-weight:500;font-size:13px;color:var(--ink);}
.timeline-date{font-size:11px;color:var(--muted);opacity:0.7;}
.timeline-note{font-size:12px;color:var(--body);margin-top:4px;}

/* CHAT SECTION */
.chat-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
.chat-header{padding:16px 20px;border-bottom:1px solid var(--border);font-weight:600;font-size:15px;color:var(--ink);}
.chat-messages{height:320px;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:14px;background:var(--bg);}
.message{display:flex;flex-direction:column;max-width:75%;}
.message.buyer{align-self:flex-end;}
.message.admin{align-self:flex-start;}
.message.artist{align-self:flex-start;}
.message-bubble{padding:10px 14px;border-radius:18px;font-size:13px;line-height:1.5;}
.message.buyer .message-bubble{background:var(--ink);color:var(--bg);border-bottom-right-radius:4px;}
.message.admin .message-bubble{background:var(--sand);color:var(--ink);border-bottom-left-radius:4px;}
.message.artist .message-bubble{background:var(--sand);color:var(--ink);border-bottom-left-radius:4px;}
.message-meta{font-size:10px;color:var(--muted);margin-top:4px;padding:0 6px;opacity:0.7;}
.message.buyer .message-meta{text-align:right;}
.chat-input-area{display:flex;gap:10px;padding:16px 20px;border-top:1px solid var(--border);}
.chat-input-area input{flex:1;padding:12px 14px;border:1.5px solid var(--border);border-radius:30px;font-size:13px;font-family:'DM Sans',sans-serif;outline:none;background:var(--card);color:var(--ink);}
.chat-input-area input:focus{border-color:var(--ink);}
.chat-input-area button{background:var(--ink);color:var(--bg);border:none;border-radius:30px;padding:0 24px;font-weight:500;cursor:pointer;}
.chat-warning{font-size:10px;color:var(--muted);padding:8px 20px 16px;text-align:center;opacity:0.7;}

/* SUCCESS MESSAGE */
.success-msg{background:var(--sand);color:var(--ink);padding:12px 16px;border-radius:10px;margin-bottom:20px;border:1px solid var(--border);}

/* HAMBURGER DRAWER */
#nav-drawer{display:none;position:fixed;top:0;right:0;width:260px;height:100vh;background:var(--ink);z-index:200;transform:translateX(100%);transition:transform 0.3s ease;padding:24px;display:flex;flex-direction:column;border-left:1px solid var(--border);}
#nav-drawer.open{transform:translateX(0);display:flex;}
#nav-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(12,63,48,0.4);z-index:150;backdrop-filter:blur(2px);}
#nav-overlay.open{display:block;}
.ham-btn{display:none;flex-direction:column;gap:5px;background:none;border:none;cursor:pointer;padding:5px;width:30px;}
.ham-btn span{width:100%;height:2px;background:var(--bg);border-radius:2px;transition:0.2s;}
.d-header{font-family:'Playfair Display',serif;font-size:18px;color:var(--bg);margin-bottom:24px;padding-bottom:12px;border-bottom:1px solid var(--border);}
.d-link{color:var(--bg);text-decoration:none;font-size:14px;padding:12px 0;display:block;border-bottom:1px solid rgba(246,237,222,0.1);font-family:'DM Sans',sans-serif;}
.d-link:hover{color:var(--sand);padding-left:5px;transition:0.2s;}

/* MOBILE RESPONSIVE */
@media(max-width:1080px){
  .info-grid{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:768px){
  :root{--sidebar:0px;}
  .sidebar{display:none;}
  .topbar{left:0;padding:0 16px;}
  .content{padding:16px;}
  .info-grid{grid-template-columns:1fr;}
  .cb-grid{grid-template-columns:1fr;}
  .order-item{flex-direction:column;align-items:center;text-align:center;}
  .item-price{text-align:left;margin-top:8px;width:100%;display:flex;justify-content:space-between;}
  .item-meta{justify-content:center;}
  .message{max-width:90%;}
  
  /* Hamburger Visibility */
  .ham-btn{display:flex;}
  .buyer-chip{display:none;}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="logo-text">Art Bazaar</div>
    <div class="logo-tag">BUYER DASHBOARD</div>
  </div>
  <div class="sidebar-section">Account</div>
  <a href="account.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
    Overview
  </a>
  <a href="orders.php" class="nav-item active">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
    My Orders
  </a>
  <div class="sidebar-bottom">
    <a href="../../logout.php" class="signout-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Sign Out
    </a>
  </div>
</aside>

<!-- TOPBAR -->
<header class="topbar">
  <div class="topbar-left"><h1>Order Details</h1></div>
  <div class="topbar-right" style="display:flex;align-items:center;gap:12px;">
    <div class="buyer-chip">
      <div class="avatar"><?= strtoupper(substr($buyerName, 0, 1)) ?></div>
      <span class="name"><?= htmlspecialchars($buyerName) ?></span>
    </div>
    <button class="ham-btn" id="hamBtn">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>

<!-- MAIN -->
<main class="main">
<div class="content">

  <a href="orders.php" class="back-link"><span>←</span> Back to My Orders</a>

  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'cancelled'): ?>
    <div class="success-msg">✓ Order cancelled successfully.</div>
  <?php endif; ?>
  
  <?php if ($chatSuccess): ?>
    <div class="success-msg">✓ Message sent successfully.</div>
  <?php endif; ?>
  
  <?php if ($chatError): ?>
    <div class="success-msg" style="background:var(--sand);color:var(--ink);border-color:var(--border);"><?= htmlspecialchars($chatError) ?></div>
  <?php endif; ?>

  <!-- ORDER HEADER -->
  <div class="order-header">
    <div>
      <h1>Order #<?= htmlspecialchars($order['order_number']) ?>
        <?php if ($isCommission): ?>
          <span class="type-badge commission">Commission</span>
        <?php else: ?>
          <span class="type-badge artwork">Artwork</span>
        <?php endif; ?>
        <span class="status-badge <?= getStatusBadgeClass($order['order_status']) ?>"><?= getStatusLabel($order['order_status']) ?></span>
      </h1>
      <p style="color:var(--muted);margin-top:4px;opacity:0.7;">Placed on <?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?></p>
    </div>
    <?php if ($order['order_status'] === 'pending'): ?>
      <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this order?')">
        <button type="submit" name="cancel_order" class="cancel-btn">Cancel Order</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- ORDER INFO GRID -->
  <div class="info-grid">
    <div class="info-card">
      <div class="info-label">Total Amount</div>
      <div class="info-value">PKR <?= number_format($order['total']) ?></div>
    </div>
    <div class="info-card">
      <div class="info-label">Payment Method</div>
      <div class="info-value"><?= getPaymentMethodLabel($order['payment_method']) ?></div>
    </div>
    <div class="info-card">
      <div class="info-label">Payment Status</div>
      <div class="info-value"><?= getPaymentStatusLabel($order['payment_status']) ?></div>
    </div>
    <div class="info-card">
      <div class="info-label">Shipping Address</div>
      <div class="info-value"><?= htmlspecialchars($order['shipping_address']) ?>, <?= htmlspecialchars($order['shipping_city']) ?></div>
    </div>
  </div>

  <?php if ($isCommission): ?>
  <!-- ============================================ -->
  <!-- COMMISSION BRIEF (reads from orders table)   -->
  <!-- ============================================ -->
  <div class="commission-brief">
    <div class="cb-header">
      <svg width="18" height="18" fill="none" stroke="var(--ink)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      Commission Brief
    </div>
    <div class="cb-body">
      <?php if (!empty($order['commission_reference_image'])): 
        $refImgUrl = getImageUrl($order['commission_reference_image'], 'commission');
      ?>
      <img src="<?= htmlspecialchars($refImgUrl) ?>" alt="Reference Image" class="cb-ref-img">
      <?php endif; ?>

      <?php if (!empty($order['commission_description'])): ?>
      <div class="cb-desc"><?= nl2br(htmlspecialchars($order['commission_description'])) ?></div>
      <?php endif; ?>

      <div class="cb-grid">
        <div class="cb-field">
          <div class="cb-field-label">Category</div>
          <div class="cb-field-value"><?= htmlspecialchars($order['commission_category_name'] ?? 'Custom Orders') ?></div>
        </div>
        <div class="cb-field">
          <div class="cb-field-label">Assigned Artist</div>
          <div class="cb-field-value"><?= htmlspecialchars($order['commission_artist_name'] ?? 'To be assigned') ?></div>
        </div>
        <div class="cb-field">
          <div class="cb-field-label">Budget Range</div>
          <div class="cb-field-value">
            <?php 
              $bMin = $order['budget_min'] ?? null;
              $bMax = $order['budget_max'] ?? null;
              if ($bMin && $bMax) {
                echo 'PKR ' . number_format($bMin) . ' – PKR ' . number_format($bMax);
              } elseif ($bMin) {
                echo 'PKR ' . number_format($bMin) . '+';
              } elseif ($bMax) {
                echo 'Up to PKR ' . number_format($bMax);
              } else {
                echo 'To be discussed';
              }
            ?>
          </div>
        </div>
        <div class="cb-field">
          <div class="cb-field-label">Deadline</div>
          <div class="cb-field-value"><?= !empty($order['commission_deadline']) ? date('F j, Y', strtotime($order['commission_deadline'])) : 'Flexible' ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($isCommission && $order['order_status'] === 'delivered' && ($order['commission_category_slug'] ?? '') === 'digital-art' && !empty($order['commission_digital_file_path'])): ?>
  <div class="items-card" style="padding:20px;text-align:center;">
    <p style="font-size:13px;margin-bottom:12px;color:var(--ink);">Your commissioned digital artwork is ready.</p>
    <a href="download-artwork.php?order_id=<?= $orderId ?>&commission=1"
       style="display:inline-block;padding:10px 20px;background:var(--ink);color:var(--bg);border-radius:8px;font-size:13px;font-weight:500;text-decoration:none;">
      ⬇ Download Final Artwork
    </a>
  </div>
  <?php endif; ?>

  <!-- ORDER ITEMS (shown for artwork orders) -->
  <?php if (!$isCommission && !empty($items)): ?>
  <div class="items-card">
    <div class="card-header">Order Items (<?= count($items) ?>)</div>
    <?php foreach ($items as $item): 
      $imgUrl = getImageUrl($item['cover_image'] ?? '', 'artwork');
    ?>
    <div class="order-item">
      <?php if ($item['item_type'] === 'artwork' && $imgUrl): ?>
        <img class="item-img" src="<?= htmlspecialchars($imgUrl) ?>" alt="">
      <?php else: ?>
        <div class="item-img" style="display:flex;align-items:center;justify-content:center;">
          <svg width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        </div>
      <?php endif; ?>
      <div class="item-details">
        <div class="item-title"><?= htmlspecialchars($item['artwork_title'] ?? 'Artwork') ?></div>
        <div class="item-artist">by <?= htmlspecialchars($item['artist_name'] ?? 'Art Bazaar') ?></div>
        <div class="item-meta">
          <span>Ready-made Artwork</span>
          <?php 
            $displayStatus = $order['order_status'];
          ?>
          <span>Status: <?= ucfirst($displayStatus) ?></span>
        </div>
      </div>
      <div class="item-price">
        <div class="price">PKR <?= number_format($item['price']) ?></div>
        <div class="qty">Qty: <?= $item['quantity'] ?></div>
        <?php if ($order['order_status'] === 'delivered' && ($item['category_slug'] ?? '') === 'digital-art'): ?>
          <a href="download-artwork.php?order_id=<?= $orderId ?>&artwork_id=<?= (int)$item['item_id'] ?>"
             style="display:inline-block;margin-top:8px;padding:8px 16px;background:var(--ink);color:var(--bg);border-radius:8px;font-size:12px;font-weight:500;text-decoration:none;">
            ⬇ Download Artwork
          </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- STATUS TIMELINE -->
  <?php if (!empty($history)): ?>
  <div class="timeline-card">
    <div class="timeline-title">Order Timeline</div>
    <div class="timeline">
      <?php foreach (array_reverse($history) as $event): ?>
      <div class="timeline-item">
        <div class="timeline-dot completed"></div>
        <div class="timeline-content">
          <div class="timeline-status">
            <?php
              $statusMap = [
                'pending' => 'Order placed',
                'confirmed' => 'Order confirmed',
                'processing' => 'Order being processed',
                'shipped' => 'Order shipped',
                'delivered' => 'Order delivered',
                'cancelled' => 'Order cancelled'
              ];
              echo $statusMap[$event['status_to']] ?? ucfirst($event['status_to']);
            ?>
          </div>
          <div class="timeline-date"><?= date('F j, Y \a\t g:i A', strtotime($event['created_at'])) ?></div>
          <?php if ($event['notes']): ?>
            <div class="timeline-note"><?= htmlspecialchars($event['notes']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- COMMISSION CHAT (ONLY for commission orders) -->
  <?php if ($isCommission): ?>
  <div class="chat-card">
    <div class="chat-header">🎨 Commission Discussion</div>
    <div class="chat-messages" id="chatMessages">
      <?php if (empty($messages)): ?>
        <div style="text-align:center;padding:20px;color:var(--muted);opacity:0.7;">No messages yet. Discuss your custom artwork requirements with the artist here.</div>
      <?php else: ?>
        <?php foreach ($messages as $msg): 
          $roleClass = $msg['sender_role'] === 'buyer' ? 'buyer' : ($msg['sender_role'] === 'artist' ? 'artist' : 'admin');
        ?>
          <div class="message <?= $roleClass ?>">
            <?php if (($msg['message_type'] ?? 'text') === 'image' && !empty($msg['attachment_path'])): ?>
              <img src="../../<?= htmlspecialchars($msg['attachment_path']) ?>" alt="Attachment" style="max-width:220px;border-radius:8px;display:block;margin-bottom:<?= $msg['message'] ? '6px' : '0' ?>;">
              <?php if (empty($order['commission_final_approved'])): ?>
                <form method="POST" style="margin-top:6px;">
                  <input type="hidden" name="action" value="approve_final">
                  <input type="hidden" name="approved_message_id" value="<?= (int)$msg['id'] ?>">
                  <button type="submit" style="font-size:11px;padding:5px 10px;border-radius:6px;background:#2E7D32;color:#fff;border:none;cursor:pointer;" onclick="return confirm('Approve this version as final? The artist will then prepare your final deliverable.')">✓ Approve This Version</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($msg['message'])): ?><div class="message-bubble"><?= nl2br(htmlspecialchars($msg['message'])) ?></div><?php endif; ?>
            <div class="message-meta">
              <span><?= htmlspecialchars($msg['sender_name']) ?></span>
              <span>•</span>
              <span><?= date('M j, g:i A', strtotime($msg['created_at'])) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div id="chatAttachPreview" style="display:none;align-items:center;gap:10px;margin-top:10px;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:8px 12px;">
      <img id="chatAttachPreviewImg" src="" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid var(--border);">
      <span style="font-size:11px;color:var(--muted);flex:1;">Image attached</span>
      <button type="button" id="chatAttachRemoveBtn" style="background:none;border:none;color:var(--ink);font-size:16px;cursor:pointer;line-height:1;">×</button>
    </div>
    <form method="POST" enctype="multipart/form-data" class="chat-input-area" onsubmit="return validateMessage(this)">
      <input type="hidden" name="action" value="send_message">
      <label style="cursor:pointer;display:flex;align-items:center;padding:0 4px;" title="Attach image">
        📎<input type="file" name="attachment" id="chatAttachInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
      </label>
      <input type="text" name="message" placeholder="Discuss commission details (No phone/email/social handles)" autocomplete="off">
      <button type="submit">Send</button>
    </form>
    <div class="chat-warning">
      ⚠️ Contact information (phone, email, Instagram, bank details) is automatically blocked for security.
    </div>
  </div>
  <?php endif; ?>

</div>
</main>

<!-- HAMBURGER DRAWER HTML -->
<div id="nav-overlay"></div>
<div id="nav-drawer">
  <div class="d-header">Menu</div>
  <a href="account.php" class="d-link">Overview</a>
  <a href="orders.php" class="d-link">My Orders</a>
  <div style="margin-top:auto;border-top:1px solid rgba(246,237,222,0.1);padding-top:16px;">
    <a href="../../logout.php" class="d-link" style="color:#ff9999;">Sign Out</a>
  </div>
</div>

<script>
// Auto-scroll chat to bottom
const chatContainer = document.getElementById('chatMessages');
if (chatContainer) {
  chatContainer.scrollTop = chatContainer.scrollHeight;
}

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

// Drawer Logic
const hamBtn = document.getElementById('hamBtn');
const drawer = document.getElementById('nav-drawer');
const overlay = document.getElementById('nav-overlay');

if(hamBtn && drawer && overlay){
    hamBtn.addEventListener('click', () => {
        drawer.classList.add('open');
        overlay.classList.add('open');
    });
    overlay.addEventListener('click', () => {
        drawer.classList.remove('open');
        overlay.classList.remove('open');
    });
}

function validateMessage(form) {
  const input = form.querySelector('input[name="message"]');
  const message = input.value.trim();
  
  const patterns = [
    /[\w.+-]+@[\w-]+\.[a-z]{2,}/i,
    /(\+92[\s-]?[0-9]{3}[\s-]?[0-9]{7}|(?<!\d)0[0-9]{2,3}[\s-]?[0-9]{6,8})/,
    /\b(instagram|insta|whatsapp|facebook|twitter|tiktok|snapchat|ig|fb|wa)\b(\s*[:\-@]\s*|\s+(?:is|id|number|no|me|on|at)\s+)\w+/i,
    /@[a-zA-Z0-9._]{2,30}/,
    /(iban|account\s*no|bank|easypaisa|jazzcash|sadapay|nayapay)/i,
  ];
  
  for (let pattern of patterns) {
    if (pattern.test(message)) {
      alert('Your message was blocked. Contact information cannot be shared here.');
      input.value = '';
      return false;
    }
  }
  
  if (!message) {
    alert('Please enter a message.');
    return false;
  }
  
  return true;
}
</script>

</body>
</html>