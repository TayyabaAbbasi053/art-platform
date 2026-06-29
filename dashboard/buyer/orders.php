
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
 $successMsg = $_GET['msg'] ?? '';

// ── Filter and pagination ────────────────────────────────
 $statusFilter = $_GET['status'] ?? '';
 $allowedStatuses = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];
if (!in_array($statusFilter, $allowedStatuses)) {
    $statusFilter = '';
}

 $page = max(1, (int)($_GET['page'] ?? 1));
 $perPage = 10;
 $offset = ($page - 1) * $perPage;

// ── Count total orders ───────────────────────────────────
 $countSql = "SELECT COUNT(*) FROM orders WHERE buyer_id = ?";
 $params = [$buyerId];
 $types = 'i';

if ($statusFilter) {
    $countSql .= " AND order_status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

 $stmt = $conn->prepare($countSql);
 $stmt->bind_param($types, ...$params);
 $stmt->execute();
 $totalOrders = $stmt->get_result()->fetch_row()[0];
 $totalPages = max(1, ceil($totalOrders / $perPage));

// ── Fetch orders (now includes order_type) ───────────────
 $sql = "
    SELECT o.*, o.order_type,
           (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count
    FROM orders o
    WHERE o.buyer_id = ?
";
if ($statusFilter) {
    $sql .= " AND o.order_status = ?";
}
 $sql .= " ORDER BY o.created_at DESC LIMIT ? OFFSET ?";

 $params = [$buyerId];
 $types = 'i';
if ($statusFilter) {
    $params[] = $statusFilter;
    $types .= 's';
}
 $params[] = $perPage;
 $params[] = $offset;
 $types .= 'ii';

 $stmt = $conn->prepare($sql);
 $stmt->bind_param($types, ...$params);
 $stmt->execute();
 $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
 // ── Fetch unread message counts per order ────────────────
$unreadByOrder = [];
$totalUnread = 0;
if (!empty($orders)) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $unreadSql = "
        SELECT order_id, COUNT(*) AS unread_count
        FROM order_messages
        WHERE order_id IN ($placeholders)
          AND sender_role != 'buyer'
          AND is_read_by_buyer = 0
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

// ── Status counts for tabs ───────────────────────────────
 $statusCounts = [
    'all' => $totalOrders,
    'pending' => 0,
    'confirmed' => 0,
    'processing' => 0,
    'shipped' => 0,
    'delivered' => 0,
    'cancelled' => 0
];

 $countStmt = $conn->prepare("SELECT order_status, COUNT(*) FROM orders WHERE buyer_id = ? GROUP BY order_status");
 $countStmt->bind_param('i', $buyerId);
 $countStmt->execute();
 $countResult = $countStmt->get_result();
while ($row = $countResult->fetch_assoc()) {
    if (isset($statusCounts[$row['order_status']])) {
        $statusCounts[$row['order_status']] = $row['COUNT(*)'];
    }
}

function getStatusBadgeClass($status) {
    // Maintained for logic flow, though CSS now handles styling uniformly via generic rules
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Orders — Art Bazaar</title>
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
.sidebar{position:fixed;top:0;left:0;width:var(--sidebar);height:100vh;background:var(--ink);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;overflow-y:auto;}
.sidebar-brand{padding:24px 24px 20px;border-bottom:1px solid var(--border);}
.sidebar-brand .logo-text{font-family:'Playfair Display',serif;font-size:20px;font-weight:500;color:var(--bg);}
.sidebar-brand .logo-tag{font-size:9px;letter-spacing:2px;color:var(--sand);margin-top:2px;}
.sidebar-section{padding:20px 20px 8px;font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);font-weight:500;}
.nav-item{display:flex;align-items:center;gap:12px;padding:10px 20px;font-size:13px;color:var(--bg);border-left:2px solid transparent;transition:all .15s;}
.nav-item:hover{color:var(--ink);background:rgba(246,237,222,0.15);border-left-color:var(--sand);}
.nav-item.active{color:var(--ink);background:var(--sand);font-weight:500;border-left-color:var(--ink);}
.nav-item .icon{width:18px;height:18px;opacity:.8;stroke:var(--bg);}
.nav-item.active .icon, .nav-item:hover .icon{stroke:var(--ink);}
.badge{margin-left:auto;background:var(--sand);color:var(--ink);font-size:9px;padding:2px 7px;border-radius:20px;}
.sidebar-bottom{margin-top:auto;padding:20px;border-top:1px solid var(--border);}
.signout-btn{display:flex;align-items:center;gap:10px;padding:10px;font-size:13px;color:var(--bg);border-radius:8px;transition:all .15s;}
.signout-btn:hover{background:#FFF0EC;color:var(--ink);}

/* TOPBAR */
.topbar{position:fixed;top:0;left:var(--sidebar);right:0;height:var(--top);background:var(--ink);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 32px;z-index:99;}
.topbar-left h1{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;color:var(--bg);}
.buyer-chip{display:flex;align-items:center;gap:10px;background:var(--sand);border:1px solid var(--border);padding:5px 12px 5px 8px;border-radius:30px;}
.buyer-chip .avatar{width:32px;height:32px;border-radius:50%;background:var(--ink);display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--bg);font-weight:600;}
.buyer-chip .name{font-size:13px;font-weight:500;color:var(--ink);}

/* MAIN */
.main{margin-left:var(--sidebar);padding-top:var(--top);min-height:100vh;}
.content{padding:28px 32px;}

/* TABS */
.tabs{display:flex;gap:4px;margin-bottom:24px;flex-wrap:wrap;border-bottom:1px solid var(--border);}
.tab{padding:10px 20px;font-size:13px;color:var(--ink);border-bottom:2px solid transparent;transition:all .15s;}
.tab:hover{color:var(--ink); background: var(--sand);}
.tab.active{color:var(--bg);background:var(--ink);border-bottom-color:var(--ink);font-weight:500;}
.tab .count{font-size:11px;color:var(--bg);margin-left:5px;}

/* ORDERS TABLE */
.orders-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
table{width:100%;border-collapse:collapse;}
th{font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-weight:500;padding:14px 20px;text-align:left;border-bottom:1px solid var(--border);background:var(--sand);}
td{padding:16px 20px;border-bottom:1px solid var(--border);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover { box-shadow: 0 4px 12px rgba(12,63,48,.06); }
.order-number{font-weight:600;color:var(--ink);display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.order-date{font-size:12px;color:var(--muted);margin-top:2px;}
.item-count{font-size:12px;color:var(--muted);}
.price{font-weight:600;color:var(--ink);}

/* TYPE BADGE */
.type-badge{display:inline-block;font-size:9px;letter-spacing:.5px;text-transform:uppercase;font-weight:600;padding:2px 8px;border-radius:20px;}
.type-badge.commission{background:var(--sand);color:var(--ink);border:1px solid var(--border);}
.type-badge.artwork{background:var(--sand);color:var(--ink);border:1px solid var(--border);}
.new-msg-pill{display:inline-flex;align-items:center;gap:6px;font-size:10px;font-weight:600;color:#c0392b;}
.red-dot{width:8px;height:8px;border-radius:50%;background:#c0392b;display:inline-block;animation:pulse-dot 1.5s infinite;}
@keyframes pulse-dot{0%{box-shadow:0 0 0 0 rgba(192,57,43,.5);}70%{box-shadow:0 0 0 5px rgba(192,57,43,0);}100%{box-shadow:0 0 0 0 rgba(192,57,43,0);}}

.status-badge{display:inline-block;font-size:10px;letter-spacing:.5px;text-transform:uppercase;font-weight:600;padding:4px 10px;border-radius:20px; background:var(--sand); color:var(--ink);}
.status-badge.delivered{background:var(--ink);color:var(--bg);}

.view-btn{display:inline-block;padding:6px 14px;border-radius:6px;font-size:11px;font-weight:500;border:1px solid var(--border);background:var(--sand);color:var(--ink);cursor:pointer;transition:all .15s;}
.view-btn:hover{background:#c4b69e;color:var(--ink);}

.empty{text-align:center;padding:60px 20px;}
.empty svg{opacity:.2;margin-bottom:16px;}
.empty h3{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;margin-bottom:8px;}

/* PAGINATION */
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:24px;}
.page-btn{padding:8px 14px;border:1px solid var(--border);border-radius:8px;background:var(--card);font-size:13px;cursor:pointer;transition:all .12s; color:var(--ink);}
.page-btn.active{background:var(--ink);color:var(--bg);border-color:var(--ink);}
.page-btn:hover:not(.active){border-color:var(--ink);color:var(--ink);}
.page-btn.disabled{opacity:.4;cursor:default;}

/* SUCCESS MESSAGE */
.success-msg{background:#E8F5EE;color:#6BA58D;padding:12px 16px;border-radius:10px;margin-bottom:20px;border:1px solid #C8E0D5; background:var(--sand); color:var(--ink); border-color:var(--border);}

/* HAMBURGER DRAWER & OVERLAY (artist-dashboard pattern) */
#nav-drawer{display:none;position:fixed;top:0;right:0;bottom:0;width:260px;background:var(--ink);z-index:200;padding:20px;transform:translateX(100%);transition:transform .3s ease;flex-direction:column;border-left:1px solid rgba(246,237,222,.1);}
#nav-drawer.open{transform:translateX(0);display:flex;}
#nav-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:199;}
#nav-overlay.open{display:block;}
.ham-btn{display:none;flex-direction:column;gap:4px;background:none;border:none;cursor:pointer;padding:4px;}
.ham-btn span{width:22px;height:2px;background:var(--bg);border-radius:2px;transition:.2s;}
.drawer-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;border-bottom:1px solid rgba(246,237,222,.1);padding-bottom:15px;}
.drawer-logo{font-family:'Playfair Display',serif;font-size:18px;color:var(--bg);font-weight:400;}
.drawer-close{background:none;border:none;color:var(--bg);font-size:24px;cursor:pointer;}
.drawer-links a{display:block;color:var(--bg);text-decoration:none;padding:12px 0;border-bottom:1px solid rgba(246,237,222,.05);font-size:14px;}
.drawer-links a:hover{color:var(--sand);}
.drawer-actions{margin-top:auto;padding-top:20px;border-top:1px solid rgba(246,237,222,.1);}
.drawer-actions a{display:block;padding:10px 0;color:var(--bg);text-decoration:none;font-size:13px;}
/* Tablet Adjustments */
@media(max-width:1080px){
    /* Footer adjustments handled in mobile block if necessary, or kept as is */
}

/* RESPONSIVE MOBILE */
@media(max-width:768px){
    :root{--sidebar:0px;}
    .sidebar{display:none;}
    .topbar{left:0;padding:0 16px;}
    .topbar-left h1{font-size:17px;color:var(--bg);}
    .buyer-chip{display:none;}
    .content{padding:16px;}
    
    /* Drawer Active States */
    .open #nav-drawer{display:block;position:fixed;top:0;right:0;width:80%;height:100%;background:var(--ink);z-index:1001;padding:40px 20px;box-shadow:-5px 0 15px rgba(0,0,0,0.1);transition:right 0.3s ease;}
    .open #nav-overlay{display:block;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;}
    
    /* Show Hamburger */
    .ham-btn{display:flex;}
    
    /* Stack Table Rows to Cards */
    table, thead, tbody, th, td, tr{display:block;}
    thead{display:none;}
    tr{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;position:relative;}
    td{padding:8px 0;border:none;display:flex;justify-content:space-between;align-items:center;text-align:right;}
    td:before{content:attr(data-label);font-weight:600;font-size:11px;text-transform:uppercase;color:var(--muted);text-align:left;flex:1;}
    
    /* Hide less critical columns visually via display:none or simplified CSS */
    .hide-mobile{display:none;}
    .item-count{display:none;}
    .hide-desktop{display:block;} 
    
    /* Force button full width */
    .view-btn{width:100%;margin-top:10px;text-align:center;justify-content:center;}
    
    /* Tabs Scroll */
    .tabs{overflow-x:auto;flex-wrap:nowrap;white-space:nowrap;padding-bottom:4px;}
    .tab{flex:0 0 auto;}
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
    <?php if ($totalUnread > 0): ?><span class="badge" style="background:#c0392b;color:#fff;display:inline-flex;align-items:center;gap:5px;"><span class="red-dot" style="background:#fff;"></span>New</span>
    <?php elseif ($statusCounts['pending'] > 0): ?><span class="badge"><?= $statusCounts['pending'] ?></span><?php endif; ?>
  </a>
  <div class="sidebar-section">Browse</div>
  <a href="../../index.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    Home
  </a>
  <a href="../../artworks.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/></svg>
    Artworks
  </a>
  <a href="../../artists.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
    Artists
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
  <div class="topbar-left"><h1>My Orders</h1></div>
  <div class="topbar-right" style="display:flex;align-items:center;gap:12px;">
    <button class="ham-btn" onclick="openDrawer()"><span></span><span></span><span></span></button>
  </div>
</header>

<!-- MAIN -->
<main class="main">
<div class="content">

  <?php if ($successMsg === 'order_placed'): ?>
    <div class="success-msg">✓ Order placed successfully! You will receive a confirmation email shortly.</div>
  <?php endif; ?>

  <!-- Status Tabs -->
  <div class="tabs">
    <a href="?status=" class="tab <?= !$statusFilter ? 'active' : '' ?>">All <span class="count">(<?= $statusCounts['all'] ?>)</span></a>
    <a href="?status=pending" class="tab <?= $statusFilter === 'pending' ? 'active' : '' ?>">Pending <span class="count">(<?= $statusCounts['pending'] ?>)</span></a>
    <a href="?status=confirmed" class="tab <?= $statusFilter === 'confirmed' ? 'active' : '' ?>">Confirmed <span class="count">(<?= $statusCounts['confirmed'] ?>)</span></a>
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
        <p>You haven't placed any orders yet.</p>
        <a href="../../artworks.php" style="color:var(--ink);margin-top:12px;display:inline-block;">Browse Artworks →</a>
      </div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Order #</th>
            <th class="hide-mobile">Date</th>
            <th>Items</th>
            <th>Total</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
          <tr>
            <td data-label="Order #">
              <div class="order-number">
                <?= htmlspecialchars($order['order_number']) ?>
                <?php if ($order['order_type'] === 'commission'): ?>
                  <span class="type-badge commission">Commission</span>
                <?php else: ?>
                  <span class="type-badge artwork">Artwork</span>
                <?php endif; ?>
                <?php if (!empty($unreadByOrder[$order['id']])): ?>
                  <span class="new-msg-pill">
                    <span class="red-dot"></span>
                    New Message<?= $unreadByOrder[$order['id']] > 1 ? 's' : '' ?>
                  </span>
                <?php endif; ?>
              </div>
              <div class="order-date hide-desktop"><?= date('d M Y', strtotime($order['created_at'])) ?></div>
            </td>
            <td class="hide-mobile" data-label="Date"><?= date('d M Y', strtotime($order['created_at'])) ?></td>
            <td class="item-count" data-label="Items"><?= $order['item_count'] ?> item<?= $order['item_count'] != 1 ? 's' : '' ?></td>
            <td class="price" data-label="Total">PKR <?= number_format($order['total']) ?></td>
            <td data-label="Status"><span class="status-badge <?= getStatusBadgeClass($order['order_status']) ?>"><?= getStatusLabel($order['order_status']) ?></span></td>
            <td><a href="order-detail.php?id=<?= $order['id'] ?>" class="view-btn">View Details</a></td>
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

<!-- NAV DRAWER (Mobile) -->
<div id="nav-overlay" onclick="closeDrawer()"></div>
<div id="nav-drawer">
    <div class="drawer-top">
        <div class="drawer-logo">Art Bazaar</div>
        <button class="drawer-close" onclick="closeDrawer()">&times;</button>
    </div>
    <div class="drawer-links">
        <a href="account.php">Account Overview</a>
        <a href="orders.php">My Orders</a> 
        <a href="../../index.php">Home</a>
        <a href="../../artworks.php">Artworks</a>
        <a href="../../artists.php">Artists</a>
    </div>
    <div class="drawer-actions">
        <a href="../../logout.php">Sign Out</a>
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