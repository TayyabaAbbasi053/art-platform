<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// ── Auth guard ───────────────────────────────────────────
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
    header('Location: ../../login.php?pending=1');
    exit;
}

$artistId = (int) $_SESSION['user_id'];  // ← whatever comes next in the file

 $artistId = (int) $_SESSION['user_id'];
 $artistName = $_SESSION['name'] ?? 'Artist';
 $successMsg = $_GET['msg'] ?? '';
// ── Filter and pagination ────────────────────────────────
 $statusFilter = $_GET['status'] ?? '';
 $allowedStatuses = ['payment_confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];
if (!in_array($statusFilter, $allowedStatuses)) {
    $statusFilter = '';
}




// ── Handle Status Update ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $allowedUpdates = ['cod', 'shipped', 'delivered', 'cancelled'];
    
    if ($orderId && in_array($newStatus, $allowedUpdates)) {
        // Verify this order belongs to the artist's artwork
        $checkStmt = $conn->prepare("
            SELECT o.id FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN artworks a ON oi.item_id = a.id
            WHERE o.id = ? AND a.artist_id = ? AND oi.item_type = 'artwork' AND o.order_type = 'artwork'
        ");
        $checkStmt->bind_param('ii', $orderId, $artistId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        
        
        if ($checkResult->num_rows > 0) {
            $updateStmt = $conn->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
            $updateStmt->bind_param('si', $newStatus, $orderId);
            $updateStmt->execute();

            // If cancelled, release all artworks in this order back to available
            if ($newStatus === 'cancelled') {
                $conn->query("UPDATE artworks SET status = 'approved', reserved_by = NULL WHERE id IN (SELECT item_id FROM order_items WHERE order_id = $orderId AND item_type = 'artwork')");
            }
            
            // Log to history
            $adminId = $artistId;
            $note = "Status updated to " . ucfirst($newStatus) . " by artist";
            
            // FIX: Extract variable before passing to bind_param
            $oldStatus = $_POST['old_status'] ?? '';
            
            $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, ?, ?, 'artist', ?, ?)");
            $stmtH->bind_param('issis', $orderId, $oldStatus, $newStatus, $adminId, $note);
            $stmtH->execute();
            
            header('Location: orders.php?status=' . urlencode($statusFilter) . '&msg=Order+status+updated+successfully');
            exit;
        }
    }
}

// ── Mark order as seen ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_seen') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId) {
        $seenStmt = $conn->prepare("UPDATE orders SET seen_by_artist = 1 WHERE id = ?");
        $seenStmt->bind_param('i', $orderId);
        $seenStmt->execute();
    }
    exit;
}

 $page = max(1, (int)($_GET['page'] ?? 1));
 $perPage = 10;
 $offset = ($page - 1) * $perPage;

function getStatusBadgeClass($status, $paymentMethod = null) {
    $classes = [
        'payment_confirmed' => 'payment_confirmed',
        'processing'        => 'processing',
        'cod'               => 'payment_confirmed', // reuse green — artist accepted COD
        'shipped'           => 'shipped',
        'delivered'         => 'delivered',
        'cancelled'         => 'cancelled',
        'pending'           => 'pending',
    ];
    return $classes[$status] ?? 'pending';
}

function getStatusLabel($status, $paymentMethod = null) {
    $labels = [
        'payment_confirmed' => 'Ready to Start',
        'processing'        => 'Processing',
        'cod'               => 'COD — In Progress',
        'shipped'           => 'Shipped',
        'delivered'         => 'Delivered',
        'cancelled'         => 'Cancelled',
        'pending'           => 'New COD Order',
    ];
    return $labels[$status] ?? ucfirst($status);
}

// ── Count total orders for this artist ───────────────────
 $countSql = "
    SELECT COUNT(DISTINCT o.id) 
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = ? AND o.order_type = 'artwork'
      AND o.order_status NOT IN ('pending', 'payment_review')
";
 $params = [$artistId];
 $types = 'i';

if ($statusFilter) {
    $countSql .= " AND o.order_status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

 $stmt = $conn->prepare($countSql);
 $stmt->bind_param($types, ...$params);
 $stmt->execute();
 $totalOrders = $stmt->get_result()->fetch_row()[0];
 $totalPages = max(1, ceil($totalOrders / $perPage));

// ── Fetch orders for this artist ─────────────────────────
 $sql = "
    SELECT 
        o.id, o.order_number, o.order_status, o.created_at,
        o.shipping_address, o.shipping_city, o.shipping_phone, 
        o.tracking_number, o.payment_method, o.payment_status,
        o.seen_by_artist,
        o.buyer_id, o.guest_name, o.guest_email, o.guest_phone,
        u.name AS buyer_name_real, u.email AS buyer_email_real, u.phone AS buyer_phone_real,
        oi.price AS order_price,
        a.title AS artwork_title, a.price AS artwork_price, a.delivery_available, a.id AS artwork_id,
        (SELECT image_path FROM artwork_images WHERE artwork_id = a.id ORDER BY is_cover DESC, sort_order ASC LIMIT 1) AS artwork_image
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    LEFT JOIN users u ON o.buyer_id = u.id
    WHERE a.artist_id = ? AND o.order_type = 'artwork'
      AND o.order_status NOT IN ('pending', 'payment_review')
";

 $params = [$artistId];
 $types = 'i';

if ($statusFilter) {
    $sql .= " AND o.order_status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
 $sql .= " GROUP BY o.id ORDER BY o.created_at DESC LIMIT ? OFFSET ?";

 $params[] = $perPage;
 $params[] = $offset;
 $types .= 'ii';

 $stmt = $conn->prepare($sql);
 $stmt->bind_param($types, ...$params);
 $stmt->execute();
 $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Status counts for tabs ───────────────────────────────
 $statusCounts = [
    'all' => 0, 'pending' => 0, 'payment_confirmed' => 0,
    'processing' => 0, 'shipped' => 0, 'delivered' => 0, 'cancelled' => 0
];

 $countStmt = $conn->prepare("
    SELECT o.order_status, COUNT(DISTINCT o.id) as count
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = ? AND o.order_type = 'artwork'
      AND o.order_status NOT IN ('pending', 'payment_review')
    GROUP BY o.order_status
");
 $countStmt->bind_param('i', $artistId);
 $countStmt->execute();
 $countResult = $countStmt->get_result();
while ($row = $countResult->fetch_assoc()) {
    if (isset($statusCounts[$row['order_status']])) {
        $statusCounts[$row['order_status']] = $row['count'];
    }
}
 $statusCounts['all'] = array_sum($statusCounts);

// Fetch artist avatar
 $artistInfo = $conn->query("SELECT profile_picture FROM users WHERE id = $artistId")->fetch_assoc();
 $avatarUrl = $artistInfo['profile_picture'] ? '../../' . ltrim($artistInfo['profile_picture'], './') : null;

// Helper function for images
function getArtworkImageUrl($imagePath) {
    if (empty($imagePath)) return null;
    $imagePath = ltrim($imagePath, './');
    if (strpos($imagePath, 'uploads/') === 0) return '../../' . $imagePath;
    if (strpos($imagePath, 'uploads/') !== false) return '../../' . $imagePath;
    return '../../uploads/artworks/' . $imagePath;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Orders — Art Bazaar Artist Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
  /* Design System Variables */
  --bg:#F6EDDE; --card:#F6EDDE; --sand:#DDCDAE; --border:#0C3F30;
  --ink:#0C3F30; --body:#0C3F30; --muted:#0C3F30; --light:#0C3F30;
  --sidebar:260px; --top:60px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.55;}
a{text-decoration:none;color:inherit;}
img{max-width:100%;display:block;}

/* Sidebar & Topbar */
.sidebar{position:fixed;top:0;left:0;width:var(--sidebar);height:100vh;background:var(--ink);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;overflow-y:auto;}
.sidebar-brand{padding:24px 24px 20px;border-bottom:1px solid var(--border);}
.sidebar-brand .logo-text{font-family:'Playfair Display',serif;font-size:20px;font-weight:500;color:var(--bg);}
.sidebar-brand .logo-tag{font-size:9px;letter-spacing:2px;color:var(--sand);margin-top:2px;}
.sidebar-brand .logo-badge{display:inline-block;margin-left:6px;background:var(--sand);color:var(--ink);font-size:8px;padding:2px 7px;border-radius:20px;}
.sidebar-section{padding:20px 20px 8px;font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);font-weight:500;}
.nav-item{display:flex;align-items:center;gap:12px;padding:10px 20px;font-size:13px;color:var(--bg);border-left:2px solid transparent;transition:all .15s;}
.nav-item:hover{color:var(--ink);background:var(--sand);border-left-color:var(--sand);}
.nav-item.active{color:var(--ink);background:var(--sand);border-left-color:var(--ink);font-weight:500;}
.nav-item .icon{width:18px;height:18px;opacity:.8;stroke:var(--bg);}
.nav-item.active .icon, .nav-item:hover .icon { stroke: var(--ink); opacity: 1; }
.badge{margin-left:auto;background:var(--sand);color:var(--ink);font-size:9px;padding:2px 7px;border-radius:20px;}
.sidebar-bottom{margin-top:auto;padding:20px;border-top:1px solid var(--border);}
.signout-btn{display:flex;align-items:center;gap:10px;padding:10px;font-size:13px;color:var(--bg);border-radius:8px;transition:all .15s;}
.signout-btn:hover{background:var(--sand);color:var(--ink);}

.topbar{position:fixed;top:0;left:var(--sidebar);right:0;height:var(--top);background:var(--ink);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 32px;z-index:99;}
.topbar-left h1{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;color:var(--bg);}
.artist-chip{display:flex;align-items:center;gap:10px;background:var(--sand);border:1px solid var(--border);padding:5px 12px 5px 8px;border-radius:30px;}
.artist-chip .avatar{width:32px;height:32px;border-radius:50%;background:var(--bg);display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--ink);font-weight:600;overflow:hidden;}
.artist-chip .avatar img{width:100%;height:100%;object-fit:cover;}
.artist-chip .name{font-size:13px;font-weight:500;color:var(--ink);}

.main{margin-left:var(--sidebar);padding-top:var(--top);min-height:100vh;}
.content{padding:28px 32px;}

/* Tabs */
.tabs{display:flex;gap:4px;margin-bottom:24px;flex-wrap:wrap;border-bottom:1px solid var(--border);overflow-x:auto;}
.tab{padding:10px 20px;font-size:13px;color:var(--ink);border-bottom:2px solid transparent;transition:all .15s;}
.tab:hover{background:var(--sand);}
.tab.active{background:var(--ink);color:var(--bg);border-bottom-color:var(--ink);font-weight:500;}
.tab .count{font-size:11px;color:inherit;margin-left:5px;opacity:0.8;}

/* Messages */
.toast{background:var(--sand);color:var(--ink);border:1px solid var(--border);padding:12px 20px;border-radius:10px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;}
.toast.hidden{display:none;}
.toast-close{background:none;border:none;color:var(--ink);cursor:pointer;font-size:16px;}

/* Orders Card */
.orders-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
table{width:100%;border-collapse:collapse;}
th{font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:var(--ink);font-weight:500;padding:14px 20px;text-align:left;border-bottom:1px solid var(--border);background:var(--sand);opacity:0.8;}
td{padding:16px 20px;border-bottom:1px solid var(--border);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--sand);box-shadow: 0 4px 12px rgba(12,63,48,.06);}

.artwork-preview{display:flex;align-items:center;gap:12px;}
.artwork-preview img{width:44px;height:44px;border-radius:8px;object-fit:cover;border:1px solid var(--border);}
.artwork-preview .placeholder-img{width:44px;height:44px;border-radius:8px;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:8px;color:var(--ink);}
.artwork-info .title{font-weight:600;color:var(--ink);font-size:13px;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.artwork-info .price{font-size:12px;color:var(--ink);opacity:0.7;margin-top:2px;}

.buyer-name{font-weight:500;}
.buyer-contact{font-size:12px;color:var(--ink);opacity:0.7;margin-top:2px;}

/* Status Badges */
.status-badge{display:inline-block;font-size:10px;letter-spacing:.5px;text-transform:uppercase;font-weight:600;padding:4px 10px;border-radius:20px;}
.status-badge.pending{background:var(--sand);color:var(--ink);}
.status-badge.confirmed{background:var(--sand);color:var(--ink);}
.status-badge.processing{background:var(--sand);color:var(--ink);}
.status-badge.shipped{background:var(--sand);color:var(--ink);}
.status-badge.delivered{background:var(--ink);color:var(--bg);}
.status-badge.payment_confirmed{background:#d4edda;color:#155724;border:1px solid #28a745;font-weight:700;}

/* Action Buttons */
.action-btn{padding:6px 14px;border-radius:6px;font-size:11px;font-weight:500;border:1px solid var(--border);background:var(--sand);cursor:pointer;transition:all .15s;display:inline-block;color:var(--ink);}
.action-btn:hover{background:#c4b69e;}

/* Empty State */
.empty{text-align:center;padding:60px 20px;}
.empty svg{opacity:.2;margin-bottom:16px;stroke:var(--ink);}
.empty h3{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;margin-bottom:8px;color:var(--ink);}
.empty p{color:var(--ink);opacity:0.7;font-size:14px;}

/* Pagination */
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:24px;}
.page-btn{padding:8px 14px;border:1px solid var(--border);border-radius:8px;background:var(--card);font-size:13px;cursor:pointer;transition:all .12s;text-decoration:none;color:var(--ink);}
.page-btn.active{background:var(--ink);color:var(--bg);border-color:var(--ink);}
.page-btn:hover:not(.active):not(.disabled){border-color:var(--ink);color:var(--ink);}
.page-btn.disabled{opacity:.4;cursor:default;pointer-events:none;}

/* ── MODAL STYLES ──────────────────────────────────────── */
.modal-overlay{position:fixed;inset:0;background:rgba(12,63,48,.3);z-index:200;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:auto;}
.modal{background:var(--card);border-radius:16px;width:580px;max-width:92vw;max-height:90vh;overflow-y:auto;box-shadow:0 24px 60px rgba(12,63,48,.2);transform:translateY(12px);transition:transform .2s;border:1px solid var(--border);}
.modal-overlay.open .modal{transform:translateY(0);}
.modal-head{padding:24px 28px 0;display:flex;align-items:flex-start;justify-content:space-between;}
.modal-head h3{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;color:var(--ink);}
.modal-close{background:none;border:none;font-size:18px;color:var(--ink);cursor:pointer;padding:0;line-height:1;}
.modal-close:hover{color:var(--muted);}
.modal-body{padding:20px 28px;}
.modal-foot{padding:0 28px 24px;display:flex;gap:10px;justify-content:flex-end;}

.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.detail-item .dl{font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--ink);font-weight:500;margin-bottom:4px;opacity:0.7;}
.detail-item .dv{font-size:13px;color:var(--ink);font-weight:500;}
.detail-item .dv.muted{color:var(--ink);opacity:0.7;font-weight:400;}
.detail-full{grid-column:1/-1;}
.detail-full .msg-text{font-size:13px;color:var(--body);line-height:1.6;background:var(--sand);padding:14px;border-radius:10px;margin-top:4px;}

.artwork-preview-modal{display:flex;gap:16px;background:var(--sand);padding:14px;border-radius:12px;margin-top:16px;}
.artwork-preview-modal img{width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid var(--border);}
.artwork-preview-modal .info{flex:1;}
.artwork-preview-modal .title{font-weight:600;font-size:14px;margin-bottom:4px;}
.artwork-preview-modal .price{font-weight:600;color:var(--ink);margin-bottom:8px;}
.artwork-preview-modal .delivery{font-size:12px;color:var(--ink);background:var(--bg);padding:2px 8px;border-radius:10px;display:inline-block;}

.shipping-box{margin-top:16px;background:var(--sand);padding:16px;border-radius:12px;border:1px solid var(--border);}
.shipping-box h5{font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--ink);margin-bottom:12px;}
.shipping-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.shipping-item .sl{font-size:9px;letter-spacing:1px;text-transform:uppercase;color:var(--ink);margin-bottom:2px;opacity:0.7;}
.shipping-item .sv{font-size:13px;font-weight:500;color:var(--ink);}

.status-update-form{margin-top:20px;background:var(--sand);padding:16px;border-radius:12px;border:1px solid var(--border);}
.status-update-form select{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--ink);background:var(--card);outline:none;margin-top:8px;}
.status-update-form select:focus{border-color:var(--ink);}
.status-update-form select:disabled{background:var(--border);color:var(--ink);opacity:0.5;cursor:not-allowed;}

.payment-warning-box {
    background: var(--sand);
    border: 1px solid var(--border);
    color: var(--ink);
    padding: 12px;
    border-radius: 8px;
    font-size: 12px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.payment-warning-box svg { width: 18px; height: 18px; flex-shrink: 0; }

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;font-size:12px;font-weight:500;border-radius:10px;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s;}
.btn-ghost{background:transparent;color:var(--ink);border:1px solid var(--border);}
.btn-ghost:hover{border-color:var(--ink);color:var(--ink);}
.btn-primary{background:var(--ink);color:var(--bg);}
.btn-primary:hover{background:#164a3b;}
.btn-primary:disabled{background:var(--border);color:var(--ink);opacity:0.5;cursor:not-allowed;}

/* ── Hamburger Drawer ───────────────────────────────────── */
#nav-drawer{display:none;position:fixed;top:0;right:0;width:260px;height:100vh;background:var(--ink);z-index:200;transform:translateX(100%);transition:transform 0.3s ease;padding:24px;display:flex;flex-direction:column;border-left:1px solid var(--border);}
#nav-drawer.open{transform:translateX(0);display:flex;}
#nav-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(12,63,48,0.4);z-index:150;backdrop-filter:blur(2px);}
#nav-overlay.open{display:block;}
.ham-btn{display:none;flex-direction:column;gap:5px;background:none;border:none;cursor:pointer;padding:5px;width:30px;}
.ham-btn span{width:100%;height:2px;background:var(--bg);border-radius:2px;transition:0.2s;}
.d-header{font-family:'Playfair Display',serif;font-size:18px;color:var(--bg);margin-bottom:24px;padding-bottom:12px;border-bottom:1px solid var(--border);}
.d-link{color:var(--bg);text-decoration:none;font-size:14px;padding:12px 0;display:block;border-bottom:1px solid rgba(246,237,222,0.1);font-family:'DM Sans',sans-serif;}
.d-link:hover{color:var(--sand);padding-left:5px;transition:0.2s;}

/* Mobile Responsiveness */
@media(max-width:1080px){
    /* Tablet Adjustments */
}
@media(max-width:768px){
    :root{--sidebar:0px;}
    .sidebar{display:none;}
    .topbar{left:0;padding:0 16px;}
    .content{padding:16px;}
    .tabs{overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch;}
    .tab{white-space:nowrap;}
    
    /* Order rows stacked */
    thead { display: none; }
    table, tbody, tr, td { display: block; width: 100%; }
    tr { margin-bottom: 20px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(12,63,48,.06); }
    td { border-bottom: 1px solid var(--border); padding: 12px 16px; text-align: left; position: relative; padding-left: 40%; }
    td::before { position: absolute; top: 50%; left: 16px; transform: translateY(-50%); width: 35%; padding-right: 10px; white-space: nowrap; font-weight: 600; font-size: 11px; color: var(--ink); opacity: 0.7; content: attr(data-label); }
    td:last-child { border-bottom: none; text-align: center; padding-left: 16px; }
    td:last-child::before { display: none; }
    .hide-mobile { display: none; }
    
    .ham-btn { display: flex; }
    .artist-chip { display: none; }
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="logo-text">Art Bazaar</div>
    <div class="logo-tag">DASHBOARD <span class="logo-badge">Artist</span></div>
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
  </a>
  <a href="commissions.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
    Commission Requests
  </a>
  <a href="orders.php" class="nav-item active">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
    Orders
    <?php
$unseenCount = 0;
foreach ($orders as $o) {
    if (!$o['seen_by_artist']) $unseenCount++;
}
?>
<?php if ($unseenCount > 0): ?><span class="badge"><?= $unseenCount ?> New</span><?php elseif ($statusCounts['payment_confirmed'] > 0): ?><span class="badge"><?= $statusCounts['payment_confirmed'] ?></span><?php endif; ?>
  </a>
  <div class="sidebar-section">Account</div>
  <a href="profile.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
    My Profile
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
  <div class="topbar-left"><h1>Orders & Inquiries</h1></div>
  <div class="topbar-right" style="display:flex;align-items:center;gap:12px;">
    <div class="artist-chip">
      <div class="avatar">
        <?php if ($avatarUrl): ?>
          <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="">
        <?php else: ?>
          <?= strtoupper(substr($artistName, 0, 1)) ?>
        <?php endif; ?>
      </div>
      <span class="name"><?= htmlspecialchars($artistName) ?></span>
    </div>
    <button class="ham-btn" id="hamBtn">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>

<!-- MAIN -->
<main class="main">
<div class="content">

  <?php if ($successMsg): ?>
    <div class="toast" id="successToast">
      <span><?= htmlspecialchars($successMsg) ?></span>
      <button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button>
    </div>
  <?php endif; ?>

  <!-- Status Tabs -->
  <div class="tabs">
    <a href="?status=" class="tab <?= !$statusFilter ? 'active' : '' ?>">All <span class="count">(<?= $statusCounts['all'] ?>)</span></a>
    <a href="?status=payment_confirmed" class="tab <?= $statusFilter === 'payment_confirmed' ? 'active' : '' ?>">Ready to Start <span class="count">(<?= $statusCounts['payment_confirmed'] ?>)</span></a>
    <a href="?status=processing" class="tab <?= $statusFilter === 'processing' ? 'active' : '' ?>">Processing <span class="count">(<?= $statusCounts['processing'] ?>)</span></a>
    <a href="?status=shipped" class="tab <?= $statusFilter === 'shipped' ? 'active' : '' ?>">Shipped <span class="count">(<?= $statusCounts['shipped'] ?>)</span></a>
    <a href="?status=delivered" class="tab <?= $statusFilter === 'delivered' ? 'active' : '' ?>">Delivered <span class="count">(<?= $statusCounts['delivered'] ?>)</span></a>
    <a href="?status=cancelled" class="tab <?= $statusFilter === 'cancelled' ? 'active' : '' ?>">Cancelled <span class="count">(<?= $statusCounts['cancelled'] ?>)</span></a>
  </div>

  <!-- Orders Table -->
  <div class="orders-card">
    <?php if (empty($orders)): ?>
      <div class="empty">
        <svg width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        <h3>No orders found</h3>
        <p>Purchase inquiries from buyers will appear here once confirmed by admin.</p>
      </div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Order / Artwork</th>
            <th>Buyer</th>
            <th class="hide-mobile">Date</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $order): 
            $imageUrl = getArtworkImageUrl($order['artwork_image'] ?? '');
            $displayBuyerName = $order['buyer_name_real'] ?: $order['guest_name'];
            $displayBuyerEmail = $order['buyer_email_real'] ?: $order['guest_email'];
            $displayBuyerPhone = $order['buyer_phone_real'] ?: $order['guest_phone'];
          ?>
          <tr>
            <td data-label="Order / Artwork">
              <div class="artwork-preview">
                <?php if ($imageUrl): ?>
                  <img src="<?= htmlspecialchars($imageUrl) ?>" alt="">
                <?php else: ?>
                  <div class="placeholder-img">N/A</div>
                <?php endif; ?>
                <div class="artwork-info">
                  <div class="title"><?= htmlspecialchars($order['artwork_title']) ?></div>
                  <div class="price">PKR <?= number_format($order['order_price'] ?? $order['artwork_price']) ?></div>
                </div>
              </div>
            </td>
            <td data-label="Buyer">
              <div class="buyer-name"><?= htmlspecialchars($displayBuyerName) ?></div>
            </td>
            <td class="hide-mobile" data-label="Date"><?= date('d M Y', strtotime($order['created_at'])) ?></td>
            <td data-label="Status">
    <span class="status-badge <?= getStatusBadgeClass($order['order_status'], $order['payment_method']) ?>"><?= getStatusLabel($order['order_status'], $order['payment_method']) ?></span>
    <?php if (!$order['seen_by_artist']): ?>
        <span style="display:inline-block;margin-left:6px;background:#fff3cd;color:#856404;border:1px solid #ffc107;font-size:8px;font-weight:700;padding:2px 6px;border-radius:20px;letter-spacing:.5px;text-transform:uppercase;">New</span>
    <?php endif; ?>
</td>
            <td data-label="Actions"><button type="button" class="action-btn" onclick="openDetail(<?= $order['id'] ?>, <?= $order['seen_by_artist'] ? 'true' : 'false' ?>)">View</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-btn">← Prev</a>
    <?php else: ?>
      <span class="page-btn disabled">← Prev</span>
    <?php endif; ?>
    
    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="page-btn <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    
    <?php if ($page < $totalPages): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-btn">Next →</a>
    <?php else: ?>
      <span class="page-btn disabled">Next →</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
</main>

<!-- HAMBURGER DRAWER HTML -->
<div id="nav-overlay"></div>
<div id="nav-drawer">
  <div class="d-header">Menu</div>
  <a href="index.php" class="d-link">Overview</a>
  <a href="upload-artwork.php" class="d-link">Upload Artwork</a>
  <a href="my-artworks.php" class="d-link">My Artworks</a>
  <a href="commissions.php" class="d-link">Commission Requests</a>
  <a href="orders.php" class="d-link">Orders</a>
  <a href="profile.php" class="d-link">My Profile</a>
  <div style="margin-top:auto;border-top:1px solid rgba(246,237,222,0.1);padding-top:16px;">
    <a href="../../logout.php" class="d-link" style="color:#ff9999;">Sign Out</a>
  </div>
</div>

<!-- ══════════════ DETAIL MODAL ══════════════ -->
<div class="modal-overlay" id="detailModal">
  <div class="modal">
    <div class="modal-head">
      <h3>Order Details</h3>
      <button class="modal-close" onclick="closeDetail()">&times;</button>
    </div>
    <div class="modal-body" id="detailContent">
      <!-- Filled by JS -->
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" onclick="closeDetail()">Close</button>
    </div>
  </div>
</div>

<script>
  const orderData = <?= json_encode($orders, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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

  function openDetail(id, alreadySeen) {
    const order = orderData.find(o => o.id == id);
    if (!order) return;

    // Mark as seen via AJAX if not already seen
    if (!alreadySeen) {
        const fd = new FormData();
        fd.append('action', 'mark_seen');
        fd.append('order_id', id);
        fetch('orders.php', { method: 'POST', body: fd });

        // Remove "New" badge from this row immediately without page reload
        const rows = document.querySelectorAll('tr');
        rows.forEach(row => {
            const btn = row.querySelector(`button[onclick*="openDetail(${id},"]`);
            if (btn) {
                const newBadge = row.querySelector('span[style*="ffc107"]');
                if (newBadge) newBadge.remove();
            }
        });
    }

    const imgUrl = order.artwork_image ? `../../${order.artwork_image.replace(/^\.?\/*/, '')}` : '';
    const deliveryText = parseInt(order.delivery_available) === 1 ? 'Delivery Available' : 'Pickup Only';
    const deliveryClass = parseInt(order.delivery_available) === 1 ? 'delivery' : 'delivery pickup';
    
    const displayBuyerName = order.buyer_name_real || order.guest_name || 'Unknown';
    const displayBuyerEmail = order.buyer_email_real || order.guest_email;
    const displayBuyerPhone = order.buyer_phone_real || order.guest_phone;

    // Check payment status — COD orders have nothing to "verify", they're ready immediately
    const isCod = order.payment_method === 'cod';
// COD orders are always actionable for artist — no payment verification needed
const isPaid = order.order_status === 'payment_confirmed'
            || order.order_status === 'cod'
            || order.order_status === 'shipped'
            || order.order_status === 'delivered'
            || order.payment_status === 'paid'
            || (isCod && order.order_status === 'pending');
const isPendingPayment = !isPaid && !isCod;

    let statusFormHtml = '';

    if (isPendingPayment) {
        statusFormHtml = `
            <div class="payment-warning-box">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><strong>Awaiting payment verification</strong> by admin before you can update status.</span>
            </div>
            <div class="dl">Update Order Status</div>
            <select name="new_status" disabled>
                <option value="payment_confirmed" selected>Payment Confirmed</option>
            </select>
            <div style="margin-top:12px;text-align:right;">
                <button type="button" class="btn btn-primary" disabled>Update Status</button>
            </div>
        `;
    } else {
        const readyBannerHtml = isCod
            ? `<div style="background:#d4edda;border:1px solid #28a745;border-radius:10px;padding:14px 16px;margin-bottom:16px;display:flex;gap:10px;align-items:center;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#155724" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <div><strong style="color:#155724;font-size:13px;">Cash on Delivery — Start Working!</strong><div style="font-size:11px;color:#155724;margin-top:2px;">Buyer will pay cash when the order is delivered. Mark as Processing when you begin.</div></div>
               </div>`
            : (order.order_status === 'payment_confirmed' ? `
                <div style="background:#d4edda;border:1px solid #28a745;border-radius:10px;padding:14px 16px;margin-bottom:16px;display:flex;gap:10px;align-items:center;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#155724" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <div><strong style="color:#155724;font-size:13px;">Payment Approved — Start Working!</strong><div style="font-size:11px;color:#155724;margin-top:2px;">Admin verified the payment. Mark as Processing when you begin.</div></div>
                </div>` : '');

        const isCodOrder = order.payment_method === 'cod';
statusFormHtml = `
    ${readyBannerHtml}
    <div class="dl">Update Order Status</div>
        <select name="new_status">
            ${isCodOrder ? `
            <option value="cod" ${order.order_status === 'cod' ? 'selected' : ''}>COD — Accepted & In Progress</option>
            ` : `
            <option value="processing" ${order.order_status === 'processing' ? 'selected' : ''}>Processing</option>
            `}
            <option value="shipped" ${order.order_status === 'shipped' ? 'selected' : ''}>Shipped</option>
            <option value="delivered" ${order.order_status === 'delivered' ? 'selected' : ''}>Delivered</option>
            <option value="cancelled" ${order.order_status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
        </select>
            <div style="margin-top:12px;text-align:right;">
                <button type="submit" class="btn btn-primary">Update Status</button>
            </div>
        `;
    }

    const content = document.getElementById('detailContent');
    content.innerHTML = `
      <div class="artwork-preview-modal">
        ${imgUrl ? `<img src="${esc(imgUrl)}" alt="${esc(order.artwork_title)}">` : `<div style="width:120px;height:120px;background:var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--ink);font-size:12px;">No Image</div>`}
        <div class="info">
          <div class="dl">Artwork</div>
          <div class="title">${esc(order.artwork_title)}</div>
          <div class="price">PKR ${Number(order.order_price || order.artwork_price).toLocaleString()}</div>
          <span class="${deliveryClass}">${deliveryText}</span>
          <div style="margin-top:8px;font-size:11px;color:var(--ink);opacity:0.7;">Order #${esc(order.order_number || order.id)}</div>
        </div>
      </div>

      <div class="detail-grid" style="margin-top: 20px;">
        <div class="detail-item">
          <div class="dl">Buyer Name</div>
          <div class="dv">${esc(displayBuyerName)}</div>
        </div>
        <div class="detail-item">
          <div class="dl">Date</div>
          <div class="dv">${esc(order.created_at)}</div>
        </div>
        <div class="detail-item">
          <div class="dl">Payment Method</div>
          <div class="dv ${!order.payment_method ? 'muted' : ''}">${order.payment_method ? esc(order.payment_method).replace(/_/g, ' ') : 'Not set'}</div>
        </div>
        <div class="detail-item">
          <div class="dl">Payment Status</div>
          <div class="dv ${!order.payment_status ? 'muted' : ''}">${codLabel(order.payment_status)}</div>
        </div>
      </div>

      ${order.shipping_address ? `
      <div class="shipping-box">
        <h5>📦 Shipping Details</h5>
        <div class="shipping-grid">
          <div class="shipping-item">
            <div class="sl">Address</div>
            <div class="sv">${esc(order.shipping_address)}</div>
          </div>
          <div class="shipping-item">
            <div class="sl">City</div>
            <div class="sv">${esc(order.shipping_city || 'N/A')}</div>
          </div>
          <div class="shipping-item">
            <div class="sl">Tracking Number</div>
            <div class="sv">${order.tracking_number ? esc(order.tracking_number) : 'Pending'}</div>
          </div>
        </div>
      </div>
      ` : ''}

      <form method="POST" class="status-update-form">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="order_id" value="${order.id}">
        <input type="hidden" name="old_status" value="${order.order_status}">
        ${statusFormHtml}
      </form>
    `;
    document.getElementById('detailModal').classList.add('open');
  }

  function closeDetail() { document.getElementById('detailModal').classList.remove('open'); }
  document.getElementById('detailModal').addEventListener('click', function (e) { if (e.target === this) closeDetail(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDetail(); });

  function esc(str) { if (!str) return ''; const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
  function codLabel(status) {
    if (status === 'cod_pending') return 'Cash on Delivery';
    if (status === 'cod_collected') return 'Cash Collected';
    return status ? esc(status) : 'Pending';
  }

  // Auto-hide toast
  setTimeout(() => {
    const toast = document.getElementById('successToast');
    if (toast) toast.classList.add('hidden');
  }, 4000);
</script>
</body>
</html>