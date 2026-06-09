<?php
session_start();
require_once __DIR__ . '/config/db.php';

// ── Auth guard ───────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'buyer') {
    $_SESSION['redirect_after_login'] = 'order-confirmation.php';
    header('Location: login.php');
    exit;
}

 $buyerId = (int) $_SESSION['user_id'];
 $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if (!$orderId) {
    header('Location: orders.php');
    exit;
}

// ── Helper Functions ───────────────────────────────────
function getImageUrl($path, $type = 'artwork') {
    if (!$path) return null;
    $path = ltrim($path, './');
    if (strpos($path, 'uploads/') !== false) return $path;
    return $type === 'commission' ? 'uploads/commissions/' . $path : 'uploads/artworks/' . $path;
}

// ── Fetch order details and verify ownership ─────────────
 $stmt = $conn->prepare("
    SELECT o.*, u.name AS buyer_name, u.email AS buyer_email,
           c.name AS commission_category_name,
           ua.name AS commission_artist_name,
           (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count
    FROM orders o
    LEFT JOIN users u ON o.buyer_id = u.id
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

// ── Self-heal missing order_items for commission orders ──
if ($order['order_type'] === 'commission' && (int)$order['item_count'] === 0) {
    $crStmt = $conn->prepare("SELECT id FROM commission_requests WHERE order_id = ?");
    $crStmt->bind_param('i', $orderId);
    $crStmt->execute();
    $crRow = $crStmt->get_result()->fetch_assoc();

    if ($crRow) {
        $crId      = (int)$crRow['id'];
        $itemPrice = (float)($order['total'] > 0 ? $order['total'] : ($order['budget_min'] ?? 0));
        $insStmt   = $conn->prepare("
            INSERT INTO order_items (order_id, item_type, item_id, quantity, price, item_status)
            VALUES (?, 'commission', ?, 1, ?, ?)
        ");
        $itemStatus = $order['order_status'] ?? 'pending';
        $insStmt->bind_param('iids', $orderId, $crId, $itemPrice, $itemStatus);
        $insStmt->execute();
    } else {
        $itemPrice = (float)($order['total'] > 0 ? $order['total'] : 0);
        $insStmt   = $conn->prepare("
            INSERT INTO order_items (order_id, item_type, item_id, quantity, price, item_status)
            VALUES (?, 'commission', 0, 1, ?, ?)
        ");
        $itemStatus = $order['order_status'] ?? 'pending';
        $insStmt->bind_param('ids', $orderId, $itemPrice, $itemStatus);
        $insStmt->execute();
    }
}

// ── Fetch order items for display (only needed for artwork orders) ──
 $items = [];
if ($order['order_type'] === 'artwork') {
    $itemQuery = $conn->prepare("
        SELECT oi.*,
               a.title AS artwork_title,
               (SELECT ai.image_path FROM artwork_images ai WHERE ai.artwork_id = a.id AND ai.is_cover = 1 LIMIT 1) AS cover_image
        FROM order_items oi
        LEFT JOIN artworks a ON oi.item_type = 'artwork' AND oi.item_id = a.id
        WHERE oi.order_id = ?
    ");
    $itemQuery->bind_param('i', $orderId);
    $itemQuery->execute();
    $items = $itemQuery->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Estimated delivery date ──────────────────────────────
 $minDate = date('F j, Y', strtotime($order['created_at'] . ' + 7 days'));
 $maxDate = date('F j, Y', strtotime($order['created_at'] . ' + 14 days'));
// For commissions, use deadline if set
 $commissionDeadline = null;
if ($order['order_type'] === 'commission' && !empty($order['commission_deadline'])) {
    $commissionDeadline = date('F j, Y', strtotime($order['commission_deadline']));
}

 $isCommission = $order['order_type'] === 'commission';

// ── Payment method label ─────────────────────────────────
function getPaymentMethodLabel($method) {
    $labels = [
        'cod'           => 'Cash on Delivery',
        'bank_transfer' => 'Bank Transfer',
        'easypaisa'     => 'Easypaisa',
        'jazzcash'      => 'JazzCash'
    ];
    return $labels[$method] ?? ucfirst($method);
}

function getStatusLabel($status) {
    $labels = [
        'pending'        => 'Pending',
        'price_proposed' => 'Price Proposed',
        'confirmed'      => 'Confirmed',
        'processing'     => 'Processing',
        'shipped'        => 'Shipped',
        'delivered'      => 'Delivered',
        'cancelled'      => 'Cancelled',
        'refunded'       => 'Refunded'
    ];
    return $labels[$status] ?? ucfirst($status);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Order Confirmation — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
  --bg:#F6EDDE;
  --card:#F6EDDE;
  --sand:#DDCDAE;
  --border:#0C3F30;
  --ink:#0C3F30;
  --body:#0C3F30;
  --muted:#0C3F30;
  --light:#0C3F30;
  --w:1280px; --r:10px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.55;}
a{text-decoration:none;color:inherit;}
img{max-width:100%;display:block;}

/* NAV */
.nav{background:var(--ink);border-bottom:1px solid var(--ink);position:sticky;top:0;z-index:200;}
.nw{max-width:var(--w);margin:0 auto;padding:0 28px;height:58px;display:flex;align-items:center;gap:16px;}
.nlogo{flex-shrink:0;display:flex;flex-direction:column;line-height:1;margin-right:4px;}
.nlogo b{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--bg);}
.nlogo small{font-size:7.5px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);margin-top:1px;}
.nlinks{display:flex;align-items:center;gap:1px;flex:1;}
.nlinks a{font-size:12.5px;color:var(--bg);padding:6px 10px;border-radius:6px;transition:background .12s;}
.nlinks a:hover,.nlinks a.active{background:var(--sand);color:var(--ink);}
.nsearch{display:flex;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--sand);border-radius:6px;padding:6px 12px;width:210px;flex-shrink:0;}
.nsearch input{border:none;background:transparent;font-size:12.5px;font-family:'DM Sans',sans-serif;color:var(--ink);outline:none;width:100%;}
.nsearch input::placeholder{color:var(--ink);opacity:0.6;}
.nsearch svg{color:var(--ink);opacity:0.6;flex-shrink:0;}
.nend{display:flex;align-items:center;gap:8px;flex-shrink:0;position:relative;margin-left:auto;}
.cart-icon{position:relative;display:flex;align-items:center;padding:6px 10px;border-radius:6px;transition:background .12s;cursor:pointer;color:var(--bg);}
.cart-icon:hover{background:var(--sand);color:var(--ink);}
.cart-count{position:absolute;top:-5px;right:-5px;background:var(--sand);color:var(--ink);font-size:9px;font-weight:600;padding:2px 5px;border-radius:20px;min-width:16px;text-align:center;}
.btn-ghost{font-size:12.5px;color:var(--bg);padding:7px 14px;border-radius:6px;border:1px solid var(--bg);background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.btn-ghost:hover{border-color:var(--sand);background:var(--sand);color:var(--ink);}
.btn-dark{font-size:12.5px;color:var(--ink);padding:7px 16px;border-radius:6px;border:none;background:var(--sand);cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:500;transition:background .12s;}
.btn-dark:hover{background:#c4b69e;}

/* ─── MOBILE HAMBURGER & DRAWER GLOBAL STYLES ─── */
#nav-drawer{display:none;}
#nav-overlay{display:none;}
.ham-btn{display:none;}

/* MAIN */
.main{max-width:var(--w);margin:0 auto;padding:28px;}

/* SUCCESS HEADER */
.success-header{text-align:center;margin-bottom:32px;}
.success-icon{width:80px;height:80px;background:#E8F5EE;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;}
.success-icon.gold{background:#FFF8E1;}
.success-icon svg{color:var(--ink);}
.success-icon.gold svg{color:var(--ink);}
.success-header h1{font-family:'Playfair Display',serif;font-size:32px;font-weight:400;margin-bottom:8px;}
.success-header p{color:var(--muted);}
.order-number{font-size:18px;font-weight:600;color:var(--ink);margin-top:12px;}

/* PROGRESS STEPS */
.progress-steps{display:flex;align-items:center;justify-content:center;gap:0;margin:28px auto 36px;max-width:600px;}
.progress-step{display:flex;flex-direction:column;align-items:center;gap:8px;flex:1;position:relative;}
.progress-step .step-circle{width:36px;height:36px;border-radius:50%;border:2px solid var(--border);background:var(--card);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;color:var(--muted);position:relative;z-index:2;}
.progress-step.completed .step-circle{background:var(--ink);border-color:var(--ink);color:#fff;}
.progress-step.current .step-circle{background:var(--sand);border-color:var(--sand);color:var(--ink);}
.progress-step .step-label{font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);text-align:center;font-weight:500;}
.progress-step.completed .step-label{color:var(--ink);}
.progress-step.current .step-label{color:var(--ink);font-weight:600;}
.progress-connector{flex:1;height:2px;background:var(--border);margin-top:-20px;}
.progress-connector.completed{background:var(--ink);}

/* INFO GRID */
.info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:28px;}
.info-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center;}
.info-label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.info-value{font-size:15px;font-weight:500;color:var(--ink);}

/* ORDER ITEMS */
.items-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:28px;}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);font-weight:600;font-size:15px;}
.order-item{display:flex;gap:16px;padding:16px 20px;border-bottom:1px solid var(--border);}
.order-item:last-child{border-bottom:none;}
.item-img{width:60px;height:60px;border-radius:8px;object-fit:cover;background:var(--sand);flex-shrink:0;}
.item-details{flex:1;}
.item-title{font-weight:600;margin-bottom:4px;}
.item-artist{font-size:12px;color:var(--muted);margin-bottom:4px;}
.item-meta{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;}
.item-meta span{font-size:11px;background:var(--sand);padding:2px 8px;border-radius:20px;}
.item-price{text-align:right;flex-shrink:0;}
.item-price .price{font-weight:600;font-size:14px;}
.item-price .qty{font-size:11px;color:var(--muted);}

/* SHIPPING INFO */
.shipping-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:28px;}
.shipping-title{font-weight:600;margin-bottom:12px;}
.shipping-address{font-size:13px;color:var(--body);line-height:1.6;margin-bottom:8px;}
.shipping-estimate{font-size:12px;color:var(--muted);}

/* COMMISSION BRIEF */
.commission-brief{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:28px;}
.cb-header{padding:16px 20px;border-bottom:1px solid var(--border);font-weight:600;font-size:15px;display:flex;align-items:center;gap:8px;}
.cb-body{padding:20px;}
.cb-desc{font-size:13px;color:var(--body);line-height:1.7;margin-bottom:16px;padding:14px;background:var(--sand);border-radius:10px;}
.cb-ref-img{max-height:160px;border-radius:8px;margin-bottom:16px;border:1px solid var(--border);}
.cb-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;}
.cb-field{padding:10px 14px;background:var(--sand);border-radius:8px;}
.cb-field-label{font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin-bottom:4px;}
.cb-field-value{font-size:13px;font-weight:500;color:var(--ink);}
.cb-notice{display:flex;align-items:flex-start;gap:10px;padding:14px;background:#E8F5EE;border:1px solid #C8E6D8;border-radius:10px;margin-top:16px;}
.cb-notice svg{flex-shrink:0;margin-top:1px;color:var(--ink);}
.cb-notice p{font-size:12.5px;color:var(--body);line-height:1.55;}
.cb-notice p strong{color:var(--ink);}
.cb-notice.gold{background:#FFFCF0;border-color:#E8D5A0;}
.cb-notice.gold svg{color:var(--ink);}
.cb-notice.gold p{color:var(--body);}
.cb-notice.gold p strong{color:var(--ink);}
.cb-notice.gold p{color:var(--body);}

/* COMMISSION STATUS BANNER */
.commission-status-banner{background:linear-gradient(135deg,#FFFCF0 0%,#FFF8E1 100%);border:1.5px solid #E8D5A0;border-radius:16px;padding:24px;margin-bottom:28px;display:flex;align-items:flex-start;gap:16px;}
.commission-status-icon{width:48px;height:48px;background:#FFF8E1;border:2px solid #E8D5A0;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.commission-status-icon svg{color:var(--ink);}
.commission-status-content h3{font-family:'Playfair Display',serif;font-size:18px;font-weight:400;color:var(--ink);margin-bottom:6px;}
.commission-status-content p{font-size:13px;color:var(--body);line-height:1.6;}
.commission-status-content .status-pill{display:inline-block;font-size:9px;letter-spacing:.5px;text-transform:uppercase;font-weight:600;padding:3px 9px;border-radius:20px;margin-top:8px;}
.status-pill.pending{background:#FFF0EC;color:var(--ink);}
.status-pill.price_proposed{background:#FFF8E1;color:var(--ink);border:1px solid #E8D5A0;}
.status-pill.confirmed{background:#E8F5EE;color:var(--ink);}
.status-pill.processing{background:#EEF2F8;color:#3B7DD8;}

/* ORDER TOTAL CARD */
.total-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:28px;}
.total-row{display:flex;justify-content:space-between;padding:8px 0;font-size:13px;color:var(--body);}
.total-row.grand{border-top:1.5px solid var(--border);margin-top:8px;padding-top:12px;font-size:16px;font-weight:600;color:var(--ink);}

/* ACTION BUTTONS */
.action-buttons{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:20px;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;}
.btn-primary{background:var(--ink);color:var(--bg);border:none;}
.btn-primary:hover{background:var(--body);}
.btn-secondary{background:transparent;border:1px solid var(--border);color:var(--body);}
.btn-secondary:hover{border-color:var(--ink);color:var(--ink);}

/* RECOMMENDATIONS */
.recommendations{background:var(--sand);border-radius:16px;padding:28px;margin-top:20px;}
.recommendations h3{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;margin-bottom:16px;}
.recommend-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;}
.recommend-card{background:var(--card);border-radius:10px;overflow:hidden;cursor:pointer;transition:transform .15s;}
.recommend-card:hover{transform:translateY(-3px);}
.recommend-img{height:120px;background:var(--sand);object-fit:cover;width:100%;}
.recommend-info{padding:10px;}
.recommend-title{font-size:12px;font-weight:500;margin-bottom:2px;}
.recommend-price{font-size:11px;color:var(--ink);font-weight:600;}

/* FOOTER */
.footer{background:var(--ink);color:var(--bg);margin-top:56px;}
.fw{max-width:var(--w);margin:0 auto;padding:40px 28px 26px;}
.fg-foot{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:32px;}
.fb b{font-family:'Playfair Display',serif;font-size:17px;color:var(--bg);display:block;margin-bottom:7px;}
.fb p{font-size:12.5px;line-height:1.65;max-width:230px;}
.fc h4{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:11px;}
.fc a{display:block;font-size:12.5px;color:rgba(246,237,222,.42);margin-bottom:8px;}
.fc a:hover{color:var(--bg);}
.fbot{border-top:1px solid rgba(246,237,222,.07);padding-top:18px;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;}

/* ─── RESPONSIVE ─── */

/* Tablet (max-width: 1080px) */
@media(max-width:1080px){
  .info-grid{grid-template-columns:repeat(2,1fr);}
  .recommend-grid{grid-template-columns:repeat(2,1fr);}
  .cb-grid{grid-template-columns:1fr;}
  .progress-steps{gap:0;}
  .fg-foot{grid-template-columns:1fr 1fr;}
}

/* Mobile (max-width: 768px) */
@media(max-width:768px){
  /* Nav */
  .nlinks,.nsearch{display:none;}
  .nend .btn-ghost,.nend .btn-dark,.nend span{display:none;}
  .ham-btn{display:flex;flex-direction:column;justify-content:center;gap:5px;background:transparent;border:none;cursor:pointer;padding:6px;margin-left:auto;}
  .ham-btn span{display:block;width:22px;height:2px;background:var(--bg);border-radius:2px;}

  #nav-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:298;}
  #nav-overlay.open{display:block;}
  #nav-drawer{display:flex;flex-direction:column;position:fixed;top:0;right:0;width:75vw;max-width:300px;height:100vh;background:var(--ink);z-index:299;transform:translateX(100%);transition:transform 0.3s ease;padding:0;overflow-y:auto;}
  #nav-drawer.open{transform:translateX(0);}
  .drawer-top{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid rgba(246,237,222,0.1);}
  .drawer-logo b{font-family:'Playfair Display',serif;font-size:16px;color:var(--bg);display:block;}
  .drawer-logo small{font-size:7px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);}
  .drawer-close{background:transparent;border:none;color:var(--bg);font-size:18px;cursor:pointer;padding:4px;}
  .drawer-links{display:flex;flex-direction:column;padding:12px 0;}
  .drawer-links a{color:var(--bg);font-size:14px;padding:13px 20px;border-bottom:1px solid rgba(246,237,222,0.07);transition:background 0.12s;}
  .drawer-links a:hover{background:rgba(246,237,222,0.06);}
  .drawer-actions{margin-top:auto;padding:20px;display:flex;flex-direction:column;gap:10px;border-top:1px solid rgba(246,237,222,0.1);}
  .drawer-cart{color:var(--bg);font-size:13.5px;padding:8px 0;}
  .drawer-btn-ghost{font-size:13px;color:var(--bg);padding:9px 14px;border-radius:6px;border:1px solid rgba(246,237,222,0.4);text-align:center;}
  .drawer-btn-ghost:hover{border-color:var(--sand);background:rgba(246,237,222,0.08);}
  .drawer-btn-dark{font-size:13px;color:var(--ink);padding:9px 14px;border-radius:6px;background:var(--sand);text-align:center;font-weight:500;}
  .drawer-btn-dark:hover{background:#c4b69e;}

  /* Layout */
  .order-item{flex-direction:column;}
  .item-price{text-align:left;margin-top:8px;}
  .info-grid{grid-template-columns:1fr;}
  .recommend-grid{grid-template-columns:1fr;}
  .fg-foot{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 16px;}
  .fc{display:none;}
  .fb{margin-bottom:12px;}
  .fb b{font-size:16px;}
  .fb p{font-size:10px;}
  .fbot{flex-direction:column;gap:12px;text-align:center;font-size:10px;padding-top:14px;}
}
</style>
</head>
<body>

<!-- NAV -->
<nav class="nav">
  <div class="nw">
    <a href="index.php" class="nlogo"><b>Art Bazaar</b><small>Marketplace</small></a>
    <div class="nlinks">
      <a href="artworks.php">Explore Art</a>
      <a href="artists.php">Artists</a>
      <a href="commission.php">Commission Art</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php">Contact</a>
    </div>
    <div class="nsearch">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input type="text" placeholder="Search...">
    </div>
    <div class="nend">
      <span style="font-size:12.5px;color:var(--bg);">Welcome, <?= htmlspecialchars($_SESSION['name'] ?? 'Buyer') ?></span>
      <a href="logout.php" class="btn-ghost">Logout</a>

      <button class="ham-btn" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</nav>

<!-- MAIN CONTENT -->
<div class="main">

  <!-- SUCCESS HEADER -->
  <div class="success-header">
    <?php if ($isCommission): ?>
    <div class="success-icon gold">
      <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
    </div>
    <h1>Your commission is in progress!</h1>
    <p>Your artist is working on it. We'll keep you updated every step of the way.</p>
    <?php else: ?>
    <div class="success-icon">
      <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    </div>
    <h1>Thank you for your order!</h1>
    <p>Your order has been placed successfully.</p>
    <?php endif; ?>
    <div class="order-number">Order #<?= htmlspecialchars($order['order_number']) ?></div>
  </div>

  <?php if ($isCommission): ?>
  <!-- COMMISSION PROGRESS STEPS -->
  <div class="progress-steps">
    <div class="progress-step completed">
      <div class="step-circle">✓</div>
      <div class="step-label">Brief<br>Submitted</div>
    </div>
    <div class="progress-connector completed"></div>
    <div class="progress-step current">
      <div class="step-circle">2</div>
      <div class="step-label">Artist<br>Assigned</div>
    </div>
    <div class="progress-connector"></div>
    <div class="progress-step">
      <div class="step-circle">3</div>
      <div class="step-label">Work in<br>Progress</div>
    </div>
    <div class="progress-connector"></div>
    <div class="progress-step">
      <div class="step-circle">4</div>
      <div class="step-label">Delivered</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- INFO GRID -->
  <div class="info-grid">
    <div class="info-card">
      <div class="info-label"><?= $isCommission ? 'Request Date' : 'Order Date' ?></div>
      <div class="info-value"><?= date('F j, Y', strtotime($order['created_at'])) ?></div>
    </div>
    <div class="info-card">
      <div class="info-label">Payment Method</div>
      <div class="info-value"><?= getPaymentMethodLabel($order['payment_method']) ?></div>
    </div>
    <div class="info-card">
      <div class="info-label"><?= $isCommission ? 'Commission Status' : 'Order Status' ?></div>
      <div class="info-value"><?= getStatusLabel($order['order_status']) ?></div>
    </div>
  </div>

  <?php if ($isCommission): ?>
  <!-- ============================================ -->
  <!-- COMMISSION ORDER: Status Banner + Brief       -->
  <!-- ============================================ -->

  <!-- COMMISSION STATUS BANNER -->
  <div class="commission-status-banner">
    <div class="commission-status-icon">
      <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
    </div>
    <div class="commission-status-content">
      <h3>Commission in progress — your artist is working on it</h3>
      <p>
        <?php if ($order['commission_artist_name']): ?>
          <strong><?= htmlspecialchars($order['commission_artist_name']) ?></strong> has been assigned to your project.
          They'll review your brief and may suggest a price. You can chat with them anytime through your order page.
        <?php else: ?>
          Our team is reviewing your brief and will assign the perfect artist for your project. You'll be notified once an artist is matched.
        <?php endif; ?>
      </p>
      <span class="status-pill <?= $order['order_status'] ?>"><?= getStatusLabel($order['order_status']) ?></span>
    </div>
  </div>

  <div class="commission-brief">
    <div class="cb-header">
      <svg width="18" height="18" fill="none" stroke="var(--ink)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      Your Commission Brief
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

      <div class="cb-notice gold">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <p><strong>What happens next?</strong> Your assigned artist will review your brief and may suggest a price. You can accept or reject the price. Once accepted, you'll complete payment and the artist begins creating your custom piece. We'll notify you at every step.</p>
      </div>
    </div>
  </div>

  <?php else: ?>
  <!-- ============================================ -->
  <!-- ARTWORK ORDER: Show Standard Item Rows        -->
  <!-- ============================================ -->
  <div class="items-card">
    <div class="card-header">Order Items (<?= count($items) ?>)</div>
    <?php foreach ($items as $item): 
      $imgUrl = getImageUrl($item['cover_image'] ?? null, 'artwork');
    ?>
    <div class="order-item">
      <?php if ($imgUrl && $item['item_type'] === 'artwork'): ?>
        <img class="item-img" src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars($item['artwork_title'] ?? 'Artwork') ?>">
      <?php else: ?>
      <div class="item-img" style="display:flex;align-items:center;justify-content:center;background:var(--sand);">
        <svg width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24">
          <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/>
        </svg>
      </div>
      <?php endif; ?>
      <div class="item-details">
        <div class="item-title"><?= htmlspecialchars($item['artwork_title'] ?? 'Artwork') ?></div>
        <div class="item-artist">Ready-made Artwork</div>
        <div class="item-meta">
          <span>Qty: <?= $item['quantity'] ?></span>
          <span>Status: <?= ucfirst($item['item_status']) ?></span>
        </div>
      </div>
      <div class="item-price">
        <div class="price">PKR <?= number_format($item['price']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ORDER TOTAL -->
  <div class="total-card">
    <div class="total-row">
      <span>Subtotal</span>
      <span>PKR <?= number_format((float)$order['subtotal']) ?></span>
    </div>
    <div class="total-row">
      <span>Shipping</span>
      <span>PKR <?= number_format((float)$order['shipping_fee']) ?></span>
    </div>
    <?php if ((float)$order['discount'] > 0): ?>
    <div class="total-row">
      <span>Discount</span>
      <span>-PKR <?= number_format((float)$order['discount']) ?></span>
    </div>
    <?php endif; ?>
    <div class="total-row grand">
      <span>Total</span>
      <span>PKR <?= number_format((float)$order['total']) ?></span>
    </div>
  </div>

  <!-- SHIPPING INFO -->
  <?php if (!empty($order['shipping_address']) || !empty($order['shipping_city'])): ?>
  <div class="shipping-card">
    <div class="shipping-title">📦 Shipping Information</div>
    <div class="shipping-address">
      <strong><?= htmlspecialchars($order['buyer_name'] ?? $order['guest_name'] ?? 'Buyer') ?></strong><br>
      <?= htmlspecialchars($order['shipping_address']) ?>
      <?php if (!empty($order['shipping_city'])): ?>
      <br><?= htmlspecialchars($order['shipping_city']) ?>
      <?php endif; ?>
      <?php if (!empty($order['shipping_phone'])): ?>
      <br>📞 <?= htmlspecialchars($order['shipping_phone']) ?>
      <?php endif; ?>
    </div>
    <div class="shipping-estimate">
      <?php if ($isCommission && $commissionDeadline): ?>
        🎨 Target completion: <?= $commissionDeadline ?>
      <?php elseif ($isCommission): ?>
        🎨 Estimated completion: 2–4 weeks
      <?php else: ?>
        🚚 Estimated delivery: <?= $minDate ?> – <?= $maxDate ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ACTION BUTTONS -->
  <div class="action-buttons">
    <a href="dashboard/buyer/order-detail.php?id=<?= $orderId ?>" class="btn btn-primary">
      <?= $isCommission ? 'Track Your Commission →' : 'Track Your Order →' ?>
    </a>
    <?php if ($isCommission): ?>
    <a href="dashboard/buyer/account.php" class="btn btn-secondary">View All Commissions</a>
    <?php else: ?>
    <a href="artworks.php" class="btn btn-secondary">Continue Shopping</a>
    <?php endif; ?>
  </div>

  <!-- RECOMMENDATIONS -->
  <div class="recommendations">
    <h3>You might also like</h3>
    <div class="recommend-grid">
      <div class="recommend-card" onclick="location.href='artworks.php?featured=1'">
        <div class="recommend-img" style="display:flex;align-items:center;justify-content:center;background:var(--sand);">
          <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
        </div>
        <div class="recommend-info">
          <div class="recommend-title">Featured Artworks</div>
          <div class="recommend-price">Curated for you</div>
        </div>
      </div>
      <div class="recommend-card" onclick="location.href='artists.php'">
        <div class="recommend-img" style="display:flex;align-items:center;justify-content:center;background:var(--sand);">
          <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        </div>
        <div class="recommend-info">
          <div class="recommend-title">Discover Artists</div>
          <div class="recommend-price">Meet Pakistani talent</div>
        </div>
      </div>
      <div class="recommend-card" onclick="location.href='commission.php'">
        <div class="recommend-img" style="display:flex;align-items:center;justify-content:center;background:var(--sand);">
          <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        </div>
        <div class="recommend-info">
          <div class="recommend-title">Custom Commissions</div>
          <div class="recommend-price">Get art made for you</div>
        </div>
      </div>
      <div class="recommend-card" onclick="location.href='artworks.php?sort=newest'">
        <div class="recommend-img" style="display:flex;align-items:center;justify-content:center;background:var(--sand);">
          <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        </div>
        <div class="recommend-info">
          <div class="recommend-title">New Arrivals</div>
          <div class="recommend-price">Fresh from artists</div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- FOOTER -->
<footer class="footer">
  <div class="fw">
    <div class="fg-foot">
      <div class="fb"><b>Art Bazaar</b><p>Pakistan's premier marketplace for original art. Connecting talented Pakistani artists with art lovers across the country.</p></div>
      <div class="fc"><h4>Explore</h4><a href="artworks.php">All Artworks</a><a href="artists.php">All Artists</a><a href="artworks.php?featured=1">Featured</a></div>
      <div class="fc"><h4>For Artists</h4><a href="sell.php">How to Sell</a><a href="register.php">Join as Artist</a><a href="login.php">Artist Login</a></div>
      <div class="fc"><h4>Company</h4><a href="about.php">About Us</a><a href="contact.php">Contact</a><a href="commission.php">Commissions</a></div>
    </div>
    <div class="fbot"><span>© <?= date('Y') ?> Art Bazaar. Supporting Pakistani artists.</span><span>Made with care in Pakistan 🇵🇰</span></div>
  </div>
</footer>

<!-- DRAWER & OVERLAY -->
<div id="nav-overlay"></div>
<div id="nav-drawer">
  <div class="drawer-top">
    <div class="drawer-logo"><b>Art Bazaar</b><small>Marketplace</small></div>
    <button class="drawer-close" aria-label="Close menu">✕</button>
  </div>
  <div class="drawer-links">
    <a href="artworks.php">Explore Art</a>
    <a href="artists.php">Artists</a>
    <a href="commission.php">Commission Art</a>
    <a href="sell.php">Sell Your Art</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact</a>
  </div>
  <div class="drawer-actions">
    <a href="cart.php" class="drawer-cart">🛒 Cart</a>
    <a href="dashboard/buyer/account.php" class="drawer-btn-ghost">My Account</a>
    <a href="logout.php" class="drawer-btn-dark">Logout</a>
  </div>
</div>

<script>
// Hamburger drawer
const hamBtn = document.querySelector('.ham-btn');
const navDrawer = document.getElementById('nav-drawer');
const navOverlay = document.getElementById('nav-overlay');
function openDrawer(){ navDrawer.classList.add('open'); navOverlay.classList.add('open'); document.body.style.overflow='hidden'; }
function closeDrawer(){ navDrawer.classList.remove('open'); navOverlay.classList.remove('open'); document.body.style.overflow=''; }
if(hamBtn) hamBtn.addEventListener('click', openDrawer);
if(navOverlay) navOverlay.addEventListener('click', closeDrawer);
document.querySelector('.drawer-close')?.addEventListener('click', closeDrawer);
</script>
</body>
</html>