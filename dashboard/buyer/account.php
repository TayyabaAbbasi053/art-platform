<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// ── Auth guard ───────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'buyer') {
    header('Location: ../../login.php');
    exit;
}

 $buyerId = (int) $_SESSION['user_id'];
 $buyerName = $_SESSION['name'] ?? 'Buyer';
 $buyerEmail = $_SESSION['email'] ?? '';

// ── Handle commission price accept/reject ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['commission_action'])) {
    $actionOrderId = (int)($_POST['order_id'] ?? 0);
    $actionType = $_POST['commission_action'] ?? '';

    if ($actionOrderId > 0 && in_array($actionType, ['accept_price', 'reject_price'])) {
        // Verify this order belongs to this buyer and is in price_proposed state
        $checkStmt = $conn->prepare("SELECT id, order_status, price_status FROM orders WHERE id = ? AND buyer_id = ? AND order_type = 'commission' AND order_status = 'price_proposed'");
        $checkStmt->bind_param('ii', $actionOrderId, $buyerId);
        $checkStmt->execute();
        $checkOrder = $checkStmt->get_result()->fetch_assoc();

        if ($checkOrder) {
            if ($actionType === 'accept_price') {
                // Move to confirmed — buyer will be redirected to checkout
                $upd = $conn->prepare("UPDATE orders SET order_status = 'confirmed', price_status = 'accepted', updated_at = NOW() WHERE id = ?");
                $upd->bind_param('i', $actionOrderId);
                $upd->execute();

                // Log status change
                $hist = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'price_proposed', 'confirmed', 'buyer', ?, 'Buyer accepted proposed price')");
                $hist->bind_param('ii', $actionOrderId, $buyerId);
                $hist->execute();

                // Redirect to checkout for payment
                header("Location: ../../checkout.php?order_id=" . $actionOrderId . "&type=commission");
                exit;

            } elseif ($actionType === 'reject_price') {
                // Reset back to pending, clear proposed price
                $upd = $conn->prepare("UPDATE orders SET order_status = 'pending', price_status = 'none', proposed_price = NULL, updated_at = NOW() WHERE id = ?");
                $upd->bind_param('i', $actionOrderId);
                $upd->execute();

                // Log status change
                $hist = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'price_proposed', 'pending', 'buyer', ?, 'Buyer rejected proposed price — renegotiation requested')");
                $hist->bind_param('ii', $actionOrderId, $buyerId);
                $hist->execute();

                // Stay on page, show rejection confirmation
                $priceRejected = true;
            }
        }
    }
}

// ── Contact info filter ──────────────────────────────────
function containsContactInfo(string $text): bool {
    $patterns = [
        '/\b[\w.+-]+@[\w-]+\.[a-z]{2,}\b/i',
        '/(\+92|0)?[-\s]?[0-9]{3}[-\s]?[0-9]{7,8}/',
        '/\b(instagram|insta|ig|whatsapp|wa|facebook|fb|twitter|tiktok|snapchat)\s*[:\-@]?\s*\w+/i',
        '/@[a-zA-Z0-9._]{2,30}/',
        '/\b(iban|account\s*no|bank|easypaisa|jazzcash|sadapay|nayapay)\b/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $text)) return true;
    }
    return false;
}

// ── Handle commission chat message POST ──────────────────
 $chatError = '';
 $chatSuccess = false;
 $chatOrderId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_commission_message') {
    $chatOrderId = (int)($_POST['order_id'] ?? 0);
    $chatMessage = trim($_POST['message'] ?? '');

    // Verify this commission belongs to this buyer
    $verifyStmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND buyer_id = ? AND order_type = 'commission'");
    $verifyStmt->bind_param('ii', $chatOrderId, $buyerId);
    $verifyStmt->execute();

    if ($verifyStmt->get_result()->num_rows === 0) {
        $chatError = 'Invalid order.';
    } elseif (containsContactInfo($chatMessage)) {
        $chatError = 'Message blocked: Contact information cannot be shared.';
    } elseif ($chatMessage) {
        $msgStmt = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message, is_read_by_admin, is_read_by_artist, is_read_by_buyer) VALUES (?, 'buyer', ?, ?, ?, 0, 0, 1)");
        $msgStmt->bind_param('iiss', $chatOrderId, $buyerId, $buyerName, $chatMessage);
        $msgStmt->execute();
        $chatSuccess = true;
    }
}

// ── Fetch buyer details ──────────────────────────────────
 $userStmt = $conn->prepare("SELECT name, email, phone, profile_picture, created_at FROM users WHERE id = ?");
 $userStmt->bind_param('i', $buyerId);
 $userStmt->execute();
 $buyer = $userStmt->get_result()->fetch_assoc();

// ── Fetch order stats ────────────────────────────────────
 $orderStats = [
    'total' => 0,
    'pending' => 0,
    'price_proposed' => 0,
    'confirmed' => 0,
    'processing' => 0,
    'shipped' => 0,
    'delivered' => 0,
    'cancelled' => 0,
    'refunded' => 0
];

 $statsQuery = $conn->prepare("
    SELECT order_status, COUNT(*) as count 
    FROM orders 
    WHERE buyer_id = ? 
    GROUP BY order_status
");
 $statsQuery->bind_param('i', $buyerId);
 $statsQuery->execute();
 $statsResult = $statsQuery->get_result();
while ($row = $statsResult->fetch_assoc()) {
    $orderStats['total'] += $row['count'];
    if (isset($orderStats[$row['order_status']])) {
        $orderStats[$row['order_status']] = $row['count'];
    }
}

// ── Fetch recent orders (last 5, all types) ──────────────
 $recentOrders = [];
 $recentQuery = $conn->prepare("
    SELECT id, order_number, order_type, order_status, total, created_at
    FROM orders 
    WHERE buyer_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
 $recentQuery->bind_param('i', $buyerId);
 $recentQuery->execute();
 $recentOrders = $recentQuery->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch commission orders ──────────────────────────────
 $commissionOrders = [];
 $commQuery = $conn->prepare("
    SELECT o.id, o.order_number, o.order_status, o.order_type, 
           o.total, o.subtotal, o.proposed_price, o.price_status,
           o.commission_description, o.commission_deadline, o.commission_reference_image,
           o.budget_min, o.budget_max, o.created_at, o.updated_at,
           c.name AS category_name,
           ua.name AS artist_name
    FROM orders o
    LEFT JOIN categories c ON o.commission_category_id = c.id
    LEFT JOIN commission_requests cr ON cr.order_id = o.id
    LEFT JOIN users ua ON cr.artist_id = ua.id
    WHERE o.buyer_id = ? AND o.order_type = 'commission'
    ORDER BY o.created_at DESC
");
 $commQuery->bind_param('i', $buyerId);
 $commQuery->execute();
 $commissionOrders = $commQuery->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch messages for each commission ───────────────────
 $commissionMessages = [];
foreach ($commissionOrders as $comm) {
    $msgStmt = $conn->prepare("SELECT * FROM order_messages WHERE order_id = ? ORDER BY created_at ASC");
    $msgStmt->bind_param('i', $comm['id']);
    $msgStmt->execute();
    $commissionMessages[$comm['id']] = $msgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Fetch cart items count ───────────────────────────────
 $cartCount = 0;
 $cartQuery = $conn->prepare("SELECT COUNT(*) as count FROM shopping_cart WHERE buyer_id = ?");
 $cartQuery->bind_param('i', $buyerId);
 $cartQuery->execute();
 $cartCount = $cartQuery->get_result()->fetch_assoc()['count'];

// ── Handle profile update ─────────────────────────────────
 $updateSuccess = false;
 $updateError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    if (!$name) {
        $updateError = 'Name is required.';
    } else {
        $stmt = $conn->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?");
        $stmt->bind_param('ssi', $name, $phone, $buyerId);
        $stmt->execute();
        
        $_SESSION['name'] = $name;
        $buyer['name'] = $name;
        $buyer['phone'] = $phone;
        $updateSuccess = true;
    }
}

function getStatusBadgeClass($status) {
    $classes = [
        'pending' => 'pending',
        'price_proposed' => 'price_proposed',
        'confirmed' => 'confirmed',
        'processing' => 'processing',
        'shipped' => 'shipped',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled',
        'refunded' => 'refunded'
    ];
    return $classes[$status] ?? 'pending';
}

function getStatusLabel($status) {
    $labels = [
        'pending' => 'Pending',
        'price_proposed' => 'Price Proposed',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded'
    ];
    return $labels[$status] ?? ucfirst($status);
}

function getProfileImageUrl($path) {
    if (!$path) return null;
    $path = ltrim($path, './');
    if (strpos($path, 'uploads/') !== false) return '../../' . $path;
    return '../../uploads/profiles/' . $path;
}

 $avatarUrl = getProfileImageUrl($buyer['profile_picture'] ?? null);
 $joinDate = date('F Y', strtotime($buyer['created_at']));
 $commissionSubmitted = isset($_GET['commission_submitted']) && $_GET['commission_submitted'] == 1;
 $priceRejected = $priceRejected ?? false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Account — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
  --bg:#F6EDDE; --card:#F6EDDE; --sand:#DDCDAE; --border:#0C3F30;
  --ink:#0C3F30; --body:#0C3F30; --muted:#0C3F30; --light:#0C3F30;
  --sidebar:260px; --top:60px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.55;}
a{text-decoration:none;color:inherit;}
img{max-width:100%;display:block;}

/* SIDEBAR */
.sidebar{position:fixed;top:0;left:0;width:var(--sidebar);height:100vh;background:#EFE3D2;border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;overflow-y:auto;}
.sidebar-brand{padding:24px 24px 20px;border-bottom:1px solid var(--border);}
.sidebar-brand .logo-text{font-family:'Playfair Display',serif;font-size:20px;font-weight:500;color:var(--ink);}
.sidebar-brand .logo-tag{font-size:9px;letter-spacing:2px;color:var(--muted);margin-top:2px;}
.sidebar-section{padding:20px 20px 8px;font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:var(--muted);font-weight:500;}
.nav-item{display:flex;align-items:center;gap:12px;padding:10px 20px;font-size:13px;color:var(--body);border-left:2px solid transparent;transition:all .15s;}
.nav-item:hover{color:var(--ink);background:rgba(255,255,255,0.3);border-left-color:var(--border);}
.nav-item.active{color:var(--ink);background:rgba(255,255,255,0.4);border-left-color:var(--sand);font-weight:500;}
.nav-item .icon{width:18px;height:18px;opacity:.6;}
.badge{margin-left:auto;background:var(--sand);color:#fff;font-size:9px;padding:2px 7px;border-radius:20px;}
.sidebar-bottom{margin-top:auto;padding:20px;border-top:1px solid var(--border);}
.signout-btn{display:flex;align-items:center;gap:10px;padding:10px;font-size:13px;color:var(--body);border-radius:8px;transition:all .15s;}
.signout-btn:hover{background:var(--bg);color:var(--ink);}

/* TOPBAR */
.topbar{position:fixed;top:0;left:var(--sidebar);right:0;height:var(--top);background:var(--card);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 32px;z-index:99;}
.topbar-left h1{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;}
.buyer-chip{display:flex;align-items:center;gap:10px;background:var(--sand);border:1px solid var(--border);padding:5px 12px 5px 8px;border-radius:30px;}
.buyer-chip .avatar{width:32px;height:32px;border-radius:50%;background:var(--sand);display:flex;align-items:center;justify-content:center;font-size:14px;color:#fff;font-weight:600;overflow:hidden;}
.buyer-chip .avatar img{width:100%;height:100%;object-fit:cover;}
.buyer-chip .name{font-size:13px;font-weight:500;}

/* MAIN */
.main{margin-left:var(--sidebar);padding-top:var(--top);min-height:100vh;}
.content{padding:28px 32px;}

/* WELCOME SECTION */
.welcome-card{background:var(--ink);border-radius:16px;padding:28px 32px;margin-bottom:28px;color:#fff;}
.welcome-card h2{font-family:'Playfair Display',serif;font-size:28px;font-weight:400;margin-bottom:8px;}
.welcome-card p{color:rgba(255,255,255,.6);font-size:13px;}

/* STATS GRID */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center;}
.stat-number{font-family:'Playfair Display',serif;font-size:28px;font-weight:500;color:var(--ink);}
.stat-label{font-size:11px;color:var(--muted);margin-top:4px;}

/* TWO COLUMN LAYOUT */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px;}

/* PROFILE CARD */
.profile-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:24px;}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);font-weight:600;font-size:15px;display:flex;justify-content:space-between;align-items:center;}
.card-body{padding:20px;}
.profile-row{display:flex;align-items:center;gap:16px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border);}
.profile-avatar{width:70px;height:70px;border-radius:50%;object-fit:cover;background:var(--sand);}
.avatar-placeholder{width:70px;height:70px;border-radius:50%;background:var(--sand);display:flex;align-items:center;justify-content:center;font-size:28px;color:#fff;font-weight:500;}
.profile-info h4{font-size:18px;font-weight:500;margin-bottom:4px;}
.profile-info p{font-size:12px;color:var(--muted);}
.info-row{display:flex;margin-bottom:12px;}
.info-label{width:100px;font-size:12px;color:var(--muted);}
.info-value{font-size:13px;color:var(--ink);font-weight:500;}
.edit-form .form-group{margin-bottom:14px;}
.edit-form label{display:block;font-size:11px;letter-spacing:.7px;text-transform:uppercase;color:var(--muted);margin-bottom:5px;}
.edit-form input{width:100%;padding:10px 14px;border:1.5px solid var(--sand);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;outline:none;background:var(--bg);color:var(--ink);}
.edit-form input:focus{border-color:var(--ink);}
.form-actions{display:flex;gap:10px;margin-top:16px;}
.btn-primary{background:var(--sand);color:var(--ink);border:none;padding:10px 20px;border-radius:8px;font-size:12px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .12s;}
.btn-primary:hover{background:#c4b69e;}
.btn-secondary{background:transparent;border:1px solid var(--border);padding:10px 20px;border-radius:8px;font-size:12px;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;color:var(--ink);}
.btn-secondary:hover{border-color:var(--muted);background:var(--sand);}
.btn-link{color:var(--ink);font-size:12px;text-decoration:none;background:none;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;}
.btn-link:hover{text-decoration:underline;}

/* RECENT ORDERS TABLE */
.orders-table{width:100%;border-collapse:collapse;}
.orders-table th{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-weight:500;padding:10px 12px;text-align:left;border-bottom:1px solid var(--border);}
.orders-table td{padding:12px 12px;border-bottom:1px solid var(--border);font-size:13px;}
.status-badge{display:inline-block;font-size:9px;letter-spacing:.5px;text-transform:uppercase;font-weight:600;padding:3px 8px;border-radius:20px;}
.status-badge.pending{background:var(--sand);color:var(--ink);}
.status-badge.price_proposed{background:var(--sand);color:var(--ink);}
.status-badge.confirmed{background:var(--sand);color:var(--ink);}
.status-badge.processing{background:var(--sand);color:var(--ink);}
.status-badge.shipped{background:var(--sand);color:var(--ink);}
.status-badge.delivered{background:var(--ink);color:var(--bg);}
.status-badge.cancelled{background:var(--sand);color:var(--ink);}
.status-badge.refunded{background:var(--sand);color:var(--ink);}
.view-link{color:var(--ink);font-size:12px;}
.view-link:hover{text-decoration:underline;}

/* QUICK ACTIONS */
.quick-actions{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-top:12px;}
.quick-action{background:var(--sand);border-radius:12px;padding:16px;text-align:center;transition:all .15s;}
.quick-action:hover{background:var(--border);transform:translateY(-2px);}
.quick-action svg{color:var(--ink);margin-bottom:8px;}
.quick-action h4{font-size:13px;font-weight:500;margin-bottom:4px;}
.quick-action p{font-size:11px;color:var(--muted);}

/* SUCCESS / ERROR MESSAGES */
.success-msg{background:var(--sand);color:var(--ink);padding:12px 16px;border-radius:10px;margin-bottom:20px;border:1px solid var(--border);}
.error-msg{background:var(--sand);color:var(--ink);padding:12px 16px;border-radius:10px;margin-bottom:20px;border:1px solid var(--border);}

/* NOTIFICATION BANNER */
.notif-banner{background:var(--ink);color:#fff;border-radius:12px;padding:18px 24px;margin-bottom:28px;display:flex;align-items:center;gap:14px;animation:slideIn .35s ease;}
.notif-banner .notif-icon{width:40px;height:40px;border-radius:50%;background:var(--sand);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.notif-banner .notif-text h4{font-size:14px;font-weight:500;margin-bottom:2px;}
.notif-banner .notif-text p{font-size:12px;color:rgba(255,255,255,.6);}
.notif-banner .notif-close{margin-left:auto;background:rgba(255,255,255,.1);border:none;color:rgba(255,255,255,.5);width:28px;height:28px;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .12s;}
.notif-banner .notif-close:hover{background:rgba(255,255,255,.2);color:#fff;}
@keyframes slideIn{from{opacity:0;transform:translateY(-12px);}to{opacity:1;transform:translateY(0);}}

/* COMMISSION CARDS */
.commission-list{display:flex;flex-direction:column;gap:16px;}
.comm-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;transition:border-color .15s;}
.comm-card:hover{border-color:var(--muted);}
.comm-card-price-proposed{border-color:var(--ink);background:linear-gradient(135deg,var(--card) 0%,#FFFDF0 100%);}
.comm-card-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;gap:12px;flex-wrap:wrap;}
.comm-card-title{font-size:15px;font-weight:600;color:var(--ink);margin-bottom:4px;}
.comm-card-meta{font-size:11px;color:var(--muted);}
.comm-card-desc{font-size:12.5px;color:var(--body);line-height:1.6;margin-bottom:14px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}
.comm-card-details{display:flex;gap:20px;flex-wrap:wrap;margin-bottom:14px;font-size:12px;}
.comm-card-detail{display:flex;flex-direction:column;gap:2px;}
.comm-card-detail .detail-label{font-size:9.5px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);}
.comm-card-detail .detail-value{font-weight:500;color:var(--ink);}
.comm-card-actions{display:flex;gap:10px;align-items:center;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);}
.btn-accept{background:var(--sand);color:var(--ink);border:none;padding:10px 20px;border-radius:8px;font-size:12px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .12s;display:inline-flex;align-items:center;gap:6px;}
.btn-accept:hover{background:#c4b69e;}
.btn-reject{background:transparent;border:1px solid var(--border);color:var(--ink);padding:10px 20px;border-radius:8px;font-size:12px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.btn-reject:hover{background:var(--sand);}
.price-proposed-box{background:var(--sand);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:14px;}
.price-proposed-box .proposed-label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--ink);font-weight:600;}
.price-proposed-box .proposed-amount{font-family:'Playfair Display',serif;font-size:24px;font-weight:500;color:var(--ink);}
.price-proposed-box .proposed-note{font-size:11px;color:var(--muted);margin-top:2px;}
.comm-ref-img{max-height:80px;border-radius:6px;border:1px solid var(--border);margin-top:8px;}
.empty-commissions{text-align:center;padding:40px;color:var(--muted);background:var(--sand);border-radius:12px;}
.empty-commissions p{margin-bottom:12px;}
.empty-commissions a{color:var(--ink);font-weight:500;}

/* COMMISSION CHAT */
.comm-chat-section{margin-top:16px;padding-top:16px;border-top:1px solid var(--border);}
.comm-chat-title{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-weight:500;margin-bottom:10px;}
.comm-chat-messages{background:var(--bg);border:1px solid var(--border);border-radius:10px;height:220px;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:10px;}
.comm-message{display:flex;flex-direction:column;max-width:85%;}
.comm-message.buyer{align-self:flex-end;}
.comm-message.artist,.comm-message.admin{align-self:flex-start;}
.comm-message-bubble{padding:8px 12px;border-radius:14px;font-size:12.5px;line-height:1.5;word-break:break-word;}
.comm-message.buyer .comm-message-bubble{background:var(--ink);color:#fff;border-bottom-right-radius:3px;}
.comm-message.artist .comm-message-bubble,.comm-message.admin .comm-message-bubble{background:var(--sand);color:var(--ink);border-bottom-left-radius:3px;}
.comm-message-meta{font-size:10px;color:var(--muted);margin-top:3px;padding:0 4px;}
.comm-message.buyer .comm-message-meta{text-align:right;}
.comm-chat-input{display:flex;gap:8px;margin-top:10px;}
.comm-chat-input input{flex:1;padding:10px 14px;border:1.5px solid var(--border);border-radius:20px;font-size:12.5px;font-family:'DM Sans',sans-serif;outline:none;background:var(--bg);color:var(--ink);}
.comm-chat-input input:focus{border-color:var(--ink);}
.comm-chat-input button{background:var(--ink);color:#fff;border:none;border-radius:20px;padding:0 18px;font-size:12px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;}
.comm-chat-input button:hover{opacity:.85;}
.comm-chat-warning{font-size:10px;color:var(--muted);margin-top:6px;text-align:center;opacity:.7;}
.comm-chat-toggle{background:none;border:1px solid var(--border);border-radius:8px;padding:6px 14px;font-size:11px;font-family:'DM Sans',sans-serif;color:var(--ink);cursor:pointer;margin-top:10px;transition:all .15s;}
.comm-chat-toggle:hover{background:var(--sand);}
.comm-chat-error{font-size:11px;color:var(--ink);margin-top:8px;padding:8px 12px;background:var(--sand);border-radius:6px;}

/* EMPTY STATE */
.empty{text-align:center;padding:32px;color:var(--muted);}

/* MOBILE RESPONSIVE */
@media(max-width:768px){
    :root{--sidebar:0px;}
    .sidebar{display:none;}
    .topbar{left:0;padding:0 16px;}
    .content{padding:16px;}
    .stats-grid{grid-template-columns:1fr 1fr;}
    .btn-primary,.btn-secondary,.btn-reject,.btn-accept{width:100%;justify-content:center;}
    .comm-card-details{flex-direction:column;gap:10px;}
    .comm-card-actions{flex-direction:column;align-items:stretch;}
    .comm-card-actions span{margin-left:0!important;text-align:center;}
    .form-actions{flex-direction:column;}
    .quick-actions{grid-template-columns:1fr;}
    .orders-table th,.orders-table td{padding:8px 4px;font-size:11px;}
    .view-link{font-size:10px;}
    .comm-chat-input{flex-direction:column;}
    .comm-chat-input button{padding:10px 18px;border-radius:20px;}
}

@media(max-width:1080px){
    .stats-grid{grid-template-columns:repeat(2,1fr);}
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
  <a href="account.php" class="nav-item active">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
    Account Overview
  </a>
  <a href="orders.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
    My Orders
    <?php if ($orderStats['pending'] > 0): ?><span class="badge"><?= $orderStats['pending'] ?></span><?php endif; ?>
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
  <div class="topbar-left"><h1>My Account</h1></div>
  <div class="topbar-right">
    <div class="buyer-chip">
      <div class="avatar">
        <?php if ($avatarUrl): ?>
          <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="">
        <?php else: ?>
          <?= strtoupper(substr($buyerName, 0, 1)) ?>
        <?php endif; ?>
      </div>
      <span class="name"><?= htmlspecialchars($buyerName) ?></span>
    </div>
  </div>
</header>

<!-- MAIN -->
<main class="main">
<div class="content">

  <!-- COMMISSION SUBMITTED BANNER -->
  <?php if ($commissionSubmitted): ?>
  <div class="notif-banner" id="commissionBanner">
    <div class="notif-icon">
      <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <div class="notif-text">
      <h4>Your commission request has been submitted!</h4>
      <p>We'll review your request and get back to you soon. You can track its status below.</p>
    </div>
    <button class="notif-close" onclick="document.getElementById('commissionBanner').style.display='none'">
      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <?php endif; ?>

  <!-- PRICE REJECTED BANNER -->
  <?php if ($priceRejected): ?>
  <div class="notif-banner" style="background:var(--body);" id="rejectBanner">
    <div class="notif-icon" style="background:var(--sand);">
      <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </div>
    <div class="notif-text">
      <h4>Price proposal rejected</h4>
      <p>Your commission has been sent back for renegotiation. We'll propose a revised price soon.</p>
    </div>
    <button class="notif-close" onclick="document.getElementById('rejectBanner').style.display='none'">
      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <?php endif; ?>

  <!-- CHAT SUCCESS BANNER -->
  <?php if ($chatSuccess): ?>
  <div class="notif-banner" id="chatBanner">
    <div class="notif-icon">
      <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <div class="notif-text">
      <h4>Message sent!</h4>
      <p>Your message has been sent to the artist.</p>
    </div>
    <button class="notif-close" onclick="document.getElementById('chatBanner').style.display='none'">
      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <?php endif; ?>

  <!-- WELCOME CARD -->
  <div class="welcome-card">
    <h2>Welcome back, <?= htmlspecialchars(explode(' ', $buyerName)[0]) ?> 👋</h2>
    <p>Track your orders, manage commissions, and discover new art.</p>
  </div>

  <!-- STATS GRID -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-number"><?= $orderStats['total'] ?></div>
      <div class="stat-label">Total Orders</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= $orderStats['pending'] + $orderStats['price_proposed'] + $orderStats['processing'] + $orderStats['shipped'] ?></div>
      <div class="stat-label">Active Orders</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= $orderStats['delivered'] ?></div>
      <div class="stat-label">Delivered</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= count($commissionOrders) ?></div>
      <div class="stat-label">Commissions</div>
    </div>
  </div>

  <!-- MY COMMISSIONS SECTION -->
  <div class="profile-card">
    <div class="card-header">
      <span>🎨 My Commissions</span>
      <?php if (!empty($commissionOrders)): ?>
        <span style="font-size:11px;color:var(--muted);font-weight:400;"><?= count($commissionOrders) ?> request<?= count($commissionOrders) !== 1 ? 's' : '' ?></span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if (empty($commissionOrders)): ?>
        <div class="empty-commissions">
          <svg width="40" height="40" fill="none" stroke="var(--light)" stroke-width="1.2" viewBox="0 0 24 24" style="margin:0 auto 12px;"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
          <p>You haven't submitted any commission requests yet.</p>
          <a href="../../commission.php">Request a custom artwork →</a>
        </div>
      <?php else: ?>
        <div class="commission-list">
          <?php foreach ($commissionOrders as $comm): 
            $isPriceProposed = $comm['order_status'] === 'price_proposed';
            $proposedPrice = $comm['proposed_price'] ?? null;
            $msgs = $commissionMessages[$comm['id']] ?? [];
            $unreadCount = count(array_filter($msgs, fn($m) => $m['sender_role'] !== 'buyer'));
          ?>
          <div class="comm-card <?= $isPriceProposed ? 'comm-card-price-proposed' : '' ?>" id="comm-<?= $comm['id'] ?>">
            <div class="comm-card-top">
              <div>
                <div class="comm-card-title">
                  <?= htmlspecialchars($comm['category_name'] ?? 'Custom Commission') ?>
                </div>
                <div class="comm-card-meta">
                  #<?= htmlspecialchars($comm['order_number']) ?> · 
                  <?= date('d M Y', strtotime($comm['created_at'])) ?> · 
                  Artist: <?= htmlspecialchars($comm['artist_name'] ?? 'To be assigned') ?>
                </div>
              </div>
              <span class="status-badge <?= getStatusBadgeClass($comm['order_status']) ?>"><?= getStatusLabel($comm['order_status']) ?></span>
            </div>

            <?php if (!empty($comm['commission_description'])): ?>
            <div class="comm-card-desc"><?= nl2br(htmlspecialchars(substr($comm['commission_description'], 0, 200))) ?><?= strlen($comm['commission_description']) > 200 ? '...' : '' ?></div>
            <?php endif; ?>

            <?php if (!empty($comm['commission_reference_image'])): 
              $refImgUrl = '../../uploads/commissions/' . ltrim($comm['commission_reference_image'], '/');
            ?>
            <img src="<?= htmlspecialchars($refImgUrl) ?>" alt="Reference" class="comm-ref-img">
            <?php endif; ?>

            <div class="comm-card-details">
              <?php if ($comm['budget_min'] || $comm['budget_max']): ?>
              <div class="comm-card-detail">
                <span class="detail-label">Your Budget</span>
                <span class="detail-value">
                  <?php 
                    if ($comm['budget_min'] && $comm['budget_max']) {
                      echo 'PKR ' . number_format($comm['budget_min']) . ' – ' . number_format($comm['budget_max']);
                    } elseif ($comm['budget_min']) {
                      echo 'PKR ' . number_format($comm['budget_min']) . '+';
                    } else {
                      echo 'Up to PKR ' . number_format($comm['budget_max']);
                    }
                  ?>
                </span>
              </div>
              <?php endif; ?>
              <div class="comm-card-detail">
                <span class="detail-label">Deadline</span>
                <span class="detail-value"><?= $comm['commission_deadline'] ? date('M j, Y', strtotime($comm['commission_deadline'])) : 'Flexible' ?></span>
              </div>
              <div class="comm-card-detail">
                <span class="detail-label">Order Total</span>
                <span class="detail-value">PKR <?= number_format((float)$comm['total']) ?></span>
              </div>
            </div>

            <?php if ($isPriceProposed && $proposedPrice): ?>
            <!-- PRICE PROPOSAL BOX -->
            <div class="price-proposed-box">
              <div style="flex:1;">
                <div class="proposed-label">💰 Artist's Proposed Price</div>
                <div class="proposed-amount">PKR <?= number_format((float)$proposedPrice) ?></div>
                <div class="proposed-note">Review this price and accept to proceed with payment.</div>
              </div>
            </div>
            <div class="comm-card-actions">
              <form method="POST" style="display:inline;" onsubmit="return confirm('Accept this price and proceed to payment?');">
                <input type="hidden" name="order_id" value="<?= $comm['id'] ?>">
                <input type="hidden" name="commission_action" value="accept_price">
                <button type="submit" class="btn-accept">
                  <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                  Accept & Pay
                </button>
              </form>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Reject this price? The commission will go back for renegotiation.');">
                <input type="hidden" name="order_id" value="<?= $comm['id'] ?>">
                <input type="hidden" name="commission_action" value="reject_price">
                <button type="submit" class="btn-reject">Reject Price</button>
              </form>
              <span style="font-size:11px;color:var(--muted);margin-left:8px;">You can negotiate or request changes</span>
            </div>
            <?php elseif ($isPriceProposed && !$proposedPrice): ?>
            <!-- Price proposed but no price set yet (edge case) -->
            <div class="comm-card-actions">
              <span style="font-size:12px;color:var(--ink);">⏳ A price proposal is being prepared for you.</span>
            </div>
            <?php endif; ?>

            <!-- COMMISSION CHAT -->
            <div class="comm-chat-section">
              <button class="comm-chat-toggle" onclick="toggleCommChat(<?= $comm['id'] ?>)">
                💬 Discussion <?= $unreadCount > 0 ? '(' . $unreadCount . ' message' . ($unreadCount !== 1 ? 's' : '') . ')' : '' ?>
              </button>
              <div id="comm-chat-<?= $comm['id'] ?>" style="display:none; margin-top:12px;">
                <div class="comm-chat-title">Commission Discussion</div>
                <div class="comm-chat-messages" id="comm-msgs-<?= $comm['id'] ?>">
                  <?php if (empty($msgs)): ?>
                    <div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;opacity:0.7;">No messages yet.</div>
                  <?php else: ?>
                    <?php foreach ($msgs as $msg): 
                      $rc = $msg['sender_role'] === 'buyer' ? 'buyer' : ($msg['sender_role'] === 'artist' ? 'artist' : 'admin');
                    ?>
                    <div class="comm-message <?= $rc ?>">
                      <div class="comm-message-bubble"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                      <div class="comm-message-meta"><?= htmlspecialchars($msg['sender_name']) ?> · <?= date('M j, g:i A', strtotime($msg['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <?php if ($chatError && $chatOrderId === $comm['id']): ?>
                  <div class="comm-chat-error"><?= htmlspecialchars($chatError) ?></div>
                <?php endif; ?>
                <form method="POST" class="comm-chat-input" onsubmit="return validateCommMsg(this)">
                  <input type="hidden" name="action" value="send_commission_message">
                  <input type="hidden" name="order_id" value="<?= $comm['id'] ?>">
                  <input type="text" name="message" placeholder="Type a message... (no phone/email/socials)" autocomplete="off" required>
                  <button type="submit">Send</button>
                </form>
                <div class="comm-chat-warning">⚠️ Contact info is automatically blocked.</div>
              </div>
            </div>

          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- TWO COLUMN LAYOUT -->
  <div class="two-col">
    
    <!-- LEFT: PROFILE INFO -->
    <div class="profile-card" style="margin-bottom:0;">
      <div class="card-header">
        Profile Information
        <button class="btn-link" id="editProfileBtn" onclick="toggleEdit()">Edit</button>
      </div>
      <div class="card-body">
        <!-- View Mode -->
        <div id="viewProfile">
          <div class="profile-row">
            <?php if ($avatarUrl): ?>
              <img class="profile-avatar" src="<?= htmlspecialchars($avatarUrl) ?>" alt="">
            <?php else: ?>
              <div class="avatar-placeholder"><?= strtoupper(substr($buyerName, 0, 1)) ?></div>
            <?php endif; ?>
            <div class="profile-info">
              <h4><?= htmlspecialchars($buyer['name']) ?></h4>
              <p>Member since <?= $joinDate ?></p>
            </div>
          </div>
          <div class="info-row">
            <div class="info-label">Email</div>
            <div class="info-value"><?= htmlspecialchars($buyerEmail) ?></div>
          </div>
          <div class="info-row">
            <div class="info-label">Phone</div>
            <div class="info-value"><?= htmlspecialchars($buyer['phone'] ?? 'Not provided') ?></div>
          </div>
        </div>
        
        <!-- Edit Mode -->
        <div id="editProfile" style="display:none;">
          <?php if ($updateSuccess): ?>
            <div class="success-msg" style="margin-bottom:16px;">✓ Profile updated successfully!</div>
          <?php endif; ?>
          <?php if ($updateError): ?>
            <div class="error-msg" style="margin-bottom:16px;"><?= htmlspecialchars($updateError) ?></div>
          <?php endif; ?>
          <form method="POST" class="edit-form">
            <div class="form-group">
              <label>Full Name</label>
              <input type="text" name="name" value="<?= htmlspecialchars($buyer['name']) ?>" required>
            </div>
            <div class="form-group">
              <label>Email</label>
              <input type="email" value="<?= htmlspecialchars($buyerEmail) ?>" disabled style="background:var(--sand);">
              <small style="font-size:10px;color:var(--muted);">Email cannot be changed. Contact support for assistance.</small>
            </div>
            <div class="form-group">
              <label>Phone Number</label>
              <input type="tel" name="phone" value="<?= htmlspecialchars($buyer['phone'] ?? '') ?>" placeholder="+92 300 0000000">
            </div>
            <div class="form-actions">
              <button type="submit" name="update_profile" class="btn-primary">Save Changes</button>
              <button type="button" class="btn-secondary" onclick="toggleEdit()">Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    
    <!-- RIGHT: QUICK ACTIONS -->
    <div class="profile-card" style="margin-bottom:0;">
      <div class="card-header">Quick Actions</div>
      <div class="card-body">
        <div class="quick-actions">
          <a href="../../artworks.php" class="quick-action">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
            <h4>Browse Art</h4>
            <p>Discover new artworks</p>
          </a>
          <a href="../../commission.php" class="quick-action">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            <h4>Commission Art</h4>
            <p>Request custom artwork</p>
          </a>
          <a href="orders.php" class="quick-action">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            <h4>Track Orders</h4>
            <p>View order status</p>
          </a>
          <a href="../../cart.php" class="quick-action">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
            <h4>My Cart</h4>
            <p><?= $cartCount ?> item<?= $cartCount !== 1 ? 's' : '' ?></p>
          </a>
        </div>
      </div>
    </div>
    
  </div>

  <!-- RECENT ORDERS -->
  <div class="profile-card">
    <div class="card-header">
      Recent Orders
      <a href="orders.php" class="btn-link">View all →</a>
    </div>
    <div class="card-body" style="padding:0;">
      <?php if (empty($recentOrders)): ?>
        <div class="empty">No orders yet. <a href="../../artworks.php" style="color:var(--ink);">Start shopping →</a></div>
      <?php else: ?>
        <table class="orders-table">
          <thead>
            <tr><th>Order #</th><th>Type</th><th>Date</th><th>Total</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentOrders as $order): ?>
            <tr>
              <td><?= htmlspecialchars($order['order_number']) ?></td>
              <td style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);"><?= $order['order_type'] ?? 'artwork' ?></td>
              <td style="font-size:12px;"><?= date('d M Y', strtotime($order['created_at'])) ?></td>
              <td>PKR <?= number_format($order['total']) ?></td>
              <td><span class="status-badge <?= getStatusBadgeClass($order['order_status']) ?>"><?= getStatusLabel($order['order_status']) ?></span></td>
              <td><a href="order-detail.php?id=<?= $order['id'] ?>" class="view-link">View →</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

</div>
</main>

<script>
function toggleEdit() {
  const viewMode = document.getElementById('viewProfile');
  const editMode = document.getElementById('editProfile');
  const editBtn = document.getElementById('editProfileBtn');
  
  if (viewMode.style.display === 'none') {
    viewMode.style.display = 'block';
    editMode.style.display = 'none';
    editBtn.textContent = 'Edit';
  } else {
    viewMode.style.display = 'none';
    editMode.style.display = 'block';
    editBtn.textContent = 'Cancel';
  }
}

function toggleCommChat(id) {
  const el = document.getElementById('comm-chat-' + id);
  if (!el) return;
  const isOpen = el.style.display !== 'none';
  el.style.display = isOpen ? 'none' : 'block';
  if (!isOpen) {
    const msgs = document.getElementById('comm-msgs-' + id);
    if (msgs) msgs.scrollTop = msgs.scrollHeight;
  }
}

function validateCommMsg(form) {
  const input = form.querySelector('input[name="message"]');
  const msg = input.value.trim();
  const patterns = [
    /[\w.+-]+@[\w-]+\.[a-z]{2,}/i,
    /(\+92|0)?[-\s]?[0-9]{3}[-\s]?[0-9]{7,8}/,
    /(instagram|insta|ig|whatsapp|wa|facebook|fb|twitter|tiktok|snapchat)\s*[:\-@]?\s*\w+/i,
    /@[a-zA-Z0-9._]{2,30}/,
    /(iban|account\s*no|bank|easypaisa|jazzcash|sadapay|nayapay)/i,
  ];
  for (let p of patterns) {
    if (p.test(msg)) {
      alert('Contact information cannot be shared here.');
      input.value = '';
      return false;
    }
  }
  return true;
}

// Auto-dismiss banners after 8 seconds
document.querySelectorAll('.notif-banner').forEach(banner => {
  setTimeout(() => {
    banner.style.transition = 'opacity .4s, transform .4s';
    banner.style.opacity = '0';
    banner.style.transform = 'translateY(-12px)';
    setTimeout(() => banner.style.display = 'none', 400);
  }, 8000);
});

// Clean URL of query params without reload
if (window.location.search.includes('commission_submitted=1')) {
  const url = new URL(window.location);
  url.searchParams.delete('commission_submitted');
  window.history.replaceState({}, '', url.pathname);
}

// Auto-open chat and scroll to commission card after message send or error
<?php if ($chatSuccess && $chatOrderId > 0): ?>
  document.addEventListener('DOMContentLoaded', function() {
    toggleCommChat(<?= $chatOrderId ?>);
    var el = document.getElementById('comm-<?= $chatOrderId ?>');
    if (el) el.scrollIntoView({behavior: 'smooth', block: 'start'});
  });
<?php elseif ($chatError && $chatOrderId > 0): ?>
  document.addEventListener('DOMContentLoaded', function() {
    toggleCommChat(<?= $chatOrderId ?>);
    var el = document.getElementById('comm-<?= $chatOrderId ?>');
    if (el) el.scrollIntoView({behavior: 'smooth', block: 'start'});
  });
<?php endif; ?>
</script>

</body>
</html>