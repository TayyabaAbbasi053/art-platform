<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// Auth guard — artist only
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

 $artistId   = (int) $_SESSION['user_id'];
 $artistName = $_SESSION['name'] ?? 'Artist';

// ── Stats queries ──────────────────────────────────────────
 $stats = [];

 $statQueries = [
    'total_artworks'    => "SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId",
    'approved_artworks' => "SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'approved'",
    'pending_artworks'  => "SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'pending'",
    'rejected_artworks' => "SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'rejected'",
    'sold_artworks'     => "SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'sold'",
];

foreach ($statQueries as $key => $sql) {
    $r = $conn->query($sql);
    $stats[$key] = $r ? (int) $r->fetch_row()[0] : 0;
}

// Commission stats (now bridge table + orders)
$stats['total_commissions'] = (int)$conn->query("
   SELECT COUNT(*) FROM commission_requests cr 
   JOIN orders o ON cr.order_id = o.id
   WHERE cr.artist_id = $artistId AND o.order_type = 'commission' AND o.order_status NOT IN ('pending')
")->fetch_row()[0];

 $stats['new_commissions'] = (int)$conn->query("
    SELECT COUNT(*) FROM commission_requests cr 
    JOIN orders o ON cr.order_id = o.id 
    WHERE cr.artist_id = $artistId AND o.order_type = 'commission' AND o.order_status = 'assigned'
")->fetch_row()[0];
// Order stats (from orders table)
 $stats['total_orders'] = (int)$conn->query("
    SELECT COUNT(DISTINCT o.id) FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = $artistId AND o.order_type = 'artwork'
")->fetch_row()[0];

 $stats['new_orders'] = (int)$conn->query("
    SELECT COUNT(DISTINCT o.id) FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = $artistId AND o.order_type = 'artwork' AND o.order_status = 'pending'
")->fetch_row()[0];

// ── Profile completeness check ─────────────────────────────
 $profileRow = $conn->query("
    SELECT bio, city, instagram_url, profile_picture
    FROM artist_profiles ap
    JOIN users u ON u.id = ap.user_id
    WHERE ap.user_id = $artistId
")->fetch_assoc();

 $profileComplete = $profileRow
    && !empty($profileRow['bio'])
    && !empty($profileRow['city'])
    && !empty($profileRow['profile_picture']);

// ── Recent artworks (last 6) ───────────────────────────────
 $recentArtworks = [];
 $ra = $conn->query("
    SELECT a.id, a.title, a.price, a.status, a.created_at, c.name AS category
    FROM artworks a
    JOIN categories c ON c.id = a.category_id
    WHERE a.artist_id = $artistId
    ORDER BY a.created_at DESC
    LIMIT 6
");
while ($row = $ra->fetch_assoc()) $recentArtworks[] = $row;

// ── Recent Orders ───────────────────────────────
 $recentOrders = [];
 $ro = $conn->query("
    SELECT o.id, o.order_number, o.order_status, o.created_at,
           o.guest_name, o.guest_email, 
           u.name AS buyer_name_real, u.email AS buyer_email_real,
           a.title AS artwork_title
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    LEFT JOIN users u ON o.buyer_id = u.id
    WHERE a.artist_id = $artistId AND o.order_type = 'artwork'
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 5
");
while ($row = $ro->fetch_assoc()) {
    $row['display_name'] = $row['buyer_name_real'] ?: $row['guest_name'];
    $recentOrders[] = $row;
}

// ── Payout status: artwork orders + commission orders, combined ──
$payoutOrders = [];

$poArt = $conn->query("
    SELECT DISTINCT o.id, o.order_number, o.order_status, o.artist_paid, o.artist_paid_at, o.created_at, 'artwork' AS order_type
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = $artistId AND o.order_type = 'artwork'
    ORDER BY o.created_at DESC
");
while ($row = $poArt->fetch_assoc()) $payoutOrders[] = $row;

$poComm = $conn->query("
    SELECT o.id, o.order_number, o.order_status, o.artist_paid, o.artist_paid_at, o.created_at, 'commission' AS order_type
    FROM orders o
    JOIN commission_requests cr ON cr.order_id = o.id
    WHERE cr.artist_id = $artistId AND o.order_type = 'commission'
    ORDER BY o.created_at DESC
");
while ($row = $poComm->fetch_assoc()) $payoutOrders[] = $row;

usort($payoutOrders, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

function payoutStatusLabel($order) {
    if ($order['order_status'] === 'cancelled') {
        return ['label' => 'Cancelled', 'class' => 'cancelled'];
    }
    if ($order['artist_paid']) {
        return ['label' => 'Paid', 'class' => 'paid'];
    }
    return ['label' => 'Not paid yet', 'class' => 'unpaid'];
}

 $today = date('l, d F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Artist Dashboard — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ── Reset & Base ─────────────────────────────────────── */
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --bg:#F6EDDE; --card:#F6EDDE; --sand:#DDCDAE; --border:#0C3F30;
    --ink:#0C3F30; --body:#0C3F30; --muted:#0C3F30; --light:#0C3F30;
    --sidebar: 240px;
    --top:     60px;
}
html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }

/* ── Sidebar ─────────────────────────────────────────── */
.sidebar {
    position: fixed; top: 0; left: 0;
    width: var(--sidebar); height: 100vh;
    background: var(--ink);
    border-right: 1px solid rgba(246,237,222,.1);
    display: flex; flex-direction: column;
    z-index: 100;
    overflow-y: auto;
}
.sidebar-brand {
    padding: 22px 24px 18px;
    border-bottom: 1px solid rgba(246,237,222,.1);
}
.sidebar-brand .logo-tag {
    font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--bg); font-weight: 400;
}
.sidebar-brand .logo-name {
    font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); font-weight: 400; margin-top: 2px;
}
.sidebar-brand .logo-badge {
    display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink);
    font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px;
}
.sidebar-section {
    padding: 18px 16px 6px;
    font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500;
}
.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 20px;
    font-size: 12.5px; color: var(--bg); text-decoration: none; font-weight: 400;
    border-left: 2px solid transparent;
    transition: all .15s; position: relative;
}
.nav-item:hover { color: var(--bg); background: rgba(255,255,255,0.05); border-left-color: rgba(255,255,255,0.2); }
.nav-item.active { color: var(--ink); background: var(--sand); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .7; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.badge {
    margin-left: auto; background: var(--sand); color: var(--ink);
    font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center;
}
.badge.amber { background: #fff; color: var(--ink); }
.sidebar-bottom {
    margin-top: auto; padding: 16px; border-top: 1px solid rgba(246,237,222,.1);
}
.signout-btn {
    display: flex; align-items: center; gap: 8px; padding: 9px 12px;
    font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px;
    transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif;
}
.signout-btn:hover { background: rgba(255,255,255,0.1); color: var(--bg); }

/* ── Topbar ──────────────────────────────────────────── */
.topbar {
    position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top);
    background: var(--ink); border-bottom: 1px solid var(--ink);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 32px; z-index: 99;
}
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.topbar-left .date { font-size: 11px; color: var(--bg); margin-top: 1px; }
.topbar-right { display: flex; align-items: center; gap: 20px; }
.artist-chip {
    display: flex; align-items: center; gap: 8px;
    background: var(--sand); border: 1px solid var(--border);
    padding: 5px 12px 5px 5px; border-radius: 30px;
}
.artist-chip .avatar {
    width: 26px; height: 26px; border-radius: 50%; background: var(--sand);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; color: var(--ink); font-weight: 600;
}
.artist-chip .name { font-size: 12px; color: var(--ink); font-weight: 500; }
.artist-chip .arrow { font-size: 12px; color: var(--muted); margin-left: 4px; }

/* ── Main ────────────────────────────────────────────── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; }

/* ── Section headers ─────────────────────────────────── */
.section-header { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 18px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; }
.section-link { font-size: 11px; color: var(--body); text-decoration: none; border-bottom: 1px solid var(--sand); }
.section-link:hover { color: var(--ink); }

/* ── Alert strip ─────────────────────────────────────── */
.alert-strip {
    background: var(--sand); color: var(--ink);
    border: 1px solid var(--border);
    border-radius: 12px; padding: 14px 20px;
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 28px; gap: 12px;
}
.alert-strip .alert-text { font-size: 12.5px; line-height: 1.5; color: var(--body); }
.alert-strip .alert-text strong { font-weight: 600; color: var(--ink); }
.alert-strip .alert-actions { display: flex; gap: 8px; flex-shrink: 0; }
.alert-btn {
    padding: 7px 14px; border-radius: 8px; font-size: 11px; font-weight: 500;
    text-decoration: none; font-family: 'DM Sans', sans-serif; cursor: pointer; border: none;
    transition: opacity .15s; white-space: nowrap;
}
.alert-btn.primary { background: var(--ink); color: var(--bg); }
.alert-btn.ghost { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.alert-btn.ghost:hover { border-color: var(--ink); color: var(--ink); }
.alert-btn:hover { opacity: .85; }

/* ── PROFILE BANNER (NEW ADDED STYLE) ─────────────────────────────── */
.profile-banner {
    background: #FDF8F2; 
    border: 1px solid #EADDCB;
    border-radius: 14px;
    padding: 20px 24px;
    display: flex; 
    align-items: center; 
    justify-content: space-between;
    margin-bottom: 32px; 
    gap: 16px;
    box-shadow: 0 4px 12px rgba(12,63,48,0.05);
}
.banner-left { display: flex; align-items: center; gap: 16px; }
.banner-icon { 
    width: 44px; height: 44px; 
    background: var(--sand); 
    border-radius: 50%; 
    display: flex; align-items: center; justify-content: center;
    color: var(--ink);
    flex-shrink: 0;
}
.banner-content h3 { 
    font-family: 'Playfair Display', serif; 
    font-size: 18px; color: var(--ink); 
    margin-bottom: 4px; 
}
.banner-content p { font-size: 13px; color: var(--muted); line-height: 1.4; }
.banner-right-btn {
    background: var(--ink); color: var(--bg); 
    padding: 10px 20px; border-radius: 8px; 
    font-size: 12px; font-weight: 500; 
    text-decoration: none; border: none;
    cursor: pointer;
    transition: background 0.2s; display: flex; align-items: center; gap: 8px;
    white-space: nowrap;
}
.banner-right-btn:hover { background: #082e23; }
.banner-close {
    background: none; border: none; font-size: 18px; color: var(--muted); cursor: pointer; opacity: 0.6; margin-left: 10px;
}
.banner-close:hover { opacity: 1; color: var(--ink); }

/* ── Stat cards ──────────────────────────────────────── */
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
.stat-card {
    background: var(--card); border: 1px solid var(--border); border-radius: 14px;
    padding: 22px 24px; position: relative; overflow: hidden; transition: box-shadow .2s;
}
.stat-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.06); }
.stat-card .label { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 10px; }
.stat-card .value { font-family: 'Playfair Display', serif; font-size: 36px; font-weight: 400; color: var(--ink); line-height: 1; }
.stat-card .sub { font-size: 11px; color: var(--muted); margin-top: 6px; }
.stat-card .sub span { font-weight: 600; }
.stat-card .corner-icon {
    position: absolute; right: 18px; top: 18px; width: 32px; height: 32px;
    border-radius: 8px; display: flex; align-items: center; justify-content: center;
    background: var(--sand);
}
.stat-card .corner-icon svg { stroke: var(--ink); }
/* GLOW OVERRIDE */
.stat-card .corner-icon::before { display: none; }

/* ── Quick actions ───────────────────────────────────── */
.quick-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 28px; }
.quick-card {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: 18px 16px; text-decoration: none; display: flex; flex-direction: column; align-items: flex-start;
    gap: 10px; transition: all .2s;
}
.quick-card:hover { border-color: var(--muted); box-shadow: 0 4px 16px rgba(0,0,0,.07); }
.quick-card .q-icon {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    background: var(--sand);
}
.quick-card .q-label { font-size: 12px; font-weight: 500; color: var(--ink); line-height: 1.3; }
.quick-card .q-desc { font-size: 10.5px; color: var(--muted); }
.pending-badge {
    margin-left: 4px; background: var(--sand); color: var(--ink);
    font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 10px;
}

/* ── Two-col layout ──────────────────────────────────── */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }

/* ── Table card ──────────────────────────────────────── */
.card {
    background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden;
}
.card-head {
    padding: 18px 22px 14px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.card-head .card-title { font-size: 13px; font-weight: 500; color: var(--ink); }
table { width: 100%; border-collapse: collapse; }
th { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; padding: 10px 22px; text-align: left; border-bottom: 1px solid var(--border); background: var(--sand); }
td { font-size: 12px; color: var(--body); padding: 12px 22px; border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--sand); color: var(--ink); }
.td-title { color: var(--ink); font-weight: 500; font-size: 12.5px; max-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.td-price { font-weight: 500; color: var(--ink); white-space: nowrap; }

/* ── Status badges ───────────────────────────────────── */
.pill {
    display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase;
    font-weight: 600; padding: 3px 9px; border-radius: 20px;
}
.pill.pending     { background: var(--sand); color: var(--ink); }
.pill.approved    { background: var(--sand); color: var(--ink); }
.pill.rejected    { background: var(--sand); color: var(--ink); }
.pill.sold        { background: var(--sand); color: var(--ink); }
.pill.hidden      { background: #F4F4F4; color: #888; }
.pill.new         { background: var(--sand); color: var(--ink); font-weight: 700; }
.pill.contacted   { background: var(--sand); color: var(--ink); }
.pill.assigned    { background: var(--sand); color: var(--ink); }
.pill.in_progress { background: var(--sand); color: var(--ink); }
.pill.completed   { background: var(--ink); color: var(--bg); }
.pill.cancelled   { background: #F4F4F4; color: #888; }
/* Order specific badges */
.pill.processing { background: var(--sand); color: var(--ink); }
.pill.shipped    { background: var(--sand); color: var(--ink); }
.pill.delivered  { background: var(--ink); color: var(--bg); }
.pill.confirmed  { background: var(--sand); color: var(--ink); }
/* Payout status pills */
.pill.paid       { background: var(--ink); color: var(--bg); }
.pill.unpaid     { background: var(--sand); color: var(--ink); }
.pill.payout-cancelled { background: #F4F4F4; color: #888; }

/* Payout list section */
.payout-list { margin-bottom: 28px; }
.payout-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 13px 22px; border-bottom: 1px solid var(--border);
    font-size: 12.5px;
}
.payout-row:last-child { border-bottom: none; }
.payout-row:hover { background: var(--bg); }
.payout-order-num { font-family: monospace; font-size: 11.5px; color: var(--ink); font-weight: 500; }
.payout-type { font-size: 10px; color: var(--muted); margin-left: 8px; text-transform: uppercase; letter-spacing: .5px; }

/* ── Empty state ─────────────────────────────────────── */
.empty { text-align: center; padding: 32px; color: var(--muted); font-size: 12px; }

/* ── Footer ──────────────────────────────────────────── */
.dash-footer { background: var(--ink); padding: 20px 32px; font-size: 11px; color: var(--bg); margin-top: 12px; text-align: center;}

/* ── Drawer (Hamburger) ──────────────────────────────── */
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

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 1080px) {
    .stats-grid { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 768px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar  { left: 0; padding: 0 16px; }
    .content { padding: 16px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .quick-grid { grid-template-columns: 1fr; }
    .two-col { grid-template-columns: 1fr; }
    
    .card { overflow-x: auto; }
    table { min-width: 500px; }
    
    .ham-btn { display: flex; }
    .topbar-right { align-items: center; }
    
    /* Hide non-essential topbar items */
    .topbar-left .date { display: none; }

    /* Banner responsive */
    .profile-banner { flex-direction: column; align-items: stretch; text-align: left; padding: 16px; }
    .banner-left { align-items: flex-start; }
    .banner-right-btn { width: 100%; justify-content: center; }
    .artist-chip { display: none; }
}
</style>
</head>
<body>

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="logo-tag">Art Bazaar</div>
        <div class="logo-name">Dashboard</div>
        <span class="logo-badge">Artist</span>
    </div>

    <div class="sidebar-section">Overview</div>
    <a href="index.php" class="nav-item active">
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
        <?php if ($stats['pending_artworks'] > 0): ?>
            <span class="badge amber"><?= $stats['pending_artworks'] ?></span>
        <?php endif; ?>
    </a>
    <a href="commissions.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Commission Requests
        <?php if ($stats['new_commissions'] > 0): ?>
            <span class="badge"><?= $stats['new_commissions'] ?></span>
        <?php endif; ?>
    </a>
    
    <!-- ADDED ORDERS LINK TO SIDEBAR -->
    <a href="orders.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        Orders
        <?php if ($stats['new_orders'] > 0): ?>
            <span class="badge"><?= $stats['new_orders'] ?></span>
        <?php endif; ?>
    </a>

    <div class="sidebar-section">Account</div>
    <a href="profile.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        My Profile
        <?php if (!$profileComplete): ?>
            <span class="badge amber">!</span>
        <?php endif; ?>
    </a>

    <div class="sidebar-bottom">
        <a href="../../logout.php" class="signout-btn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign out
        </a>
    </div>
</aside>

<!-- ══════════════ TOPBAR ══════════════ -->
<header class="topbar">
    <div class="topbar-left">
        <h1>Welcome, <?= htmlspecialchars(explode(' ', $artistName)[0]) ?></h1>        <div class="date"><?= $today ?></div>
    </div>
    <div class="topbar-right">
        <button class="ham-btn" onclick="openDrawer()"><span></span><span></span><span></span></button>
        <div class="artist-chip">
            <div class="avatar"><?= strtoupper(substr($artistName, 0, 1)) ?></div>
            <span class="name"><?= htmlspecialchars($artistName) ?></span>
            <span class="arrow">∨</span>
        </div>
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <!-- ══════════════ PROFILE COMPLETION BANNER ══════════════ -->
    <?php if (!$profileComplete): ?>
    <div class="profile-banner">
        <div class="banner-left">
            <div class="banner-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            </div>
            <div class="banner-content">
                <h3>Complete Your Profile</h3>
                <p>Please complete your profile details to start selling and build trust with buyers.</p>
            </div>
        </div>
        <div style="display:flex; align-items:center;">
            <a href="profile.php" class="banner-right-btn">
                Complete Profile
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </a>
            <button onclick="this.parentElement.parentElement.style.display='none'" class="banner-close">
                &times;
            </button>
        </div>
    </div>
    <?php endif; ?>
    <!-- ══════════════ END PROFILE BANNER ══════════════ -->

    <!-- Rejected artworks alert -->
    <?php if ($stats['rejected_artworks'] > 0): ?>
    <div class="alert-strip" style="background: var(--sand); border-color: var(--border);">
        <div class="alert-text" style="color: var(--ink);">
            You have <strong><?= $stats['rejected_artworks'] ?> artwork<?= $stats['rejected_artworks'] > 1 ? 's' : '' ?></strong> that were rejected. Review them and resubmit if needed.
        </div>
        <div class="alert-actions">
            <a href="my-artworks.php?status=rejected" class="alert-btn primary" style="background: var(--ink);">View Rejected</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Stat Cards ─────────────────────────────── -->
    <div class="section-header">
        <span class="section-title">Overview</span>
    </div>
    <div class="stats-grid">
        <div class="stat-card artworks">
            <div class="corner-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
            </div>
            <div class="label">Total Artworks</div>
            <div class="value"><?= $stats['total_artworks'] ?></div>
            <div class="sub">
                <span><?= $stats['approved_artworks'] ?></span> approved
                &middot; <span><?= $stats['sold_artworks'] ?></span> sold
            </div>
        </div>
        <div class="stat-card pending">
            <div class="corner-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="label">Pending Review</div>
            <div class="value"><?= $stats['pending_artworks'] ?></div>
            <div class="sub">Waiting for admin approval</div>
        </div>
        <div class="stat-card sold">
            <div class="corner-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            </div>
            <div class="label">Total Orders</div>
            <div class="value"><?= $stats['total_orders'] ?></div>
            <div class="sub">
                <span><?= $stats['new_orders'] ?></span> new/pending
            </div>
        </div>
        <div class="stat-card commissions">
            <div class="corner-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </div>
            <div class="label">Commission Requests</div>
            <div class="value"><?= $stats['total_commissions'] ?></div>
            <div class="sub">
                <span><?= $stats['new_commissions'] ?></span> new
            </div>
        </div>
    </div>

    <!-- ── Quick Actions ──────────────────────────── -->
    <div class="section-header">
        <span class="section-title">Quick Actions</span>
    </div>
    <div class="quick-grid">
        <a href="upload-artwork.php" class="quick-card upload-artwork">
            <div class="q-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="1.8"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <div>
                <div class="q-label">Upload New Artwork</div>
                <div class="q-desc">Add a new piece to your portfolio</div>
            </div>
        </a>
        <a href="my-artworks.php" class="quick-card my-artworks">
            <div class="q-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
            </div>
            <div>
                <div class="q-label">My Artworks <?php if ($stats['pending_artworks'] > 0): ?><span class="pending-badge"><?= $stats['pending_artworks'] ?> pending</span><?php endif; ?></div>
                <div class="q-desc">View and manage your submissions</div>
            </div>
        </a>
        <a href="commissions.php" class="quick-card commissions">
            <div class="q-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </div>
            <div>
                <div class="q-label">Commission Requests <?php if ($stats['new_commissions'] > 0): ?><span class="pending-badge"><?= $stats['new_commissions'] ?> new</span><?php endif; ?></div>
                <div class="q-desc">Custom artwork requests from buyers</div>
            </div>
        </a>
    </div>

    <!-- ── Two column tables ──────────────────────── -->
    <div class="two-col">

        <!-- Recent Artworks -->
        <div class="card">
            <div class="card-head">
                <span class="card-title">Recent Artworks</span>
                <a href="my-artworks.php" class="section-link">View all &rarr;</a>
            </div>
            <?php if (empty($recentArtworks)): ?>
                <div class="empty">No artworks uploaded yet.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentArtworks as $aw): ?>
                    <tr>
                        <td class="td-title" title="<?= htmlspecialchars($aw['title']) ?>"><?= htmlspecialchars($aw['title']) ?></td>
                        <td><?= htmlspecialchars($aw['category']) ?></td>
                        <td class="td-price">PKR <?= number_format($aw['price']) ?></td>
                        <td><span class="pill <?= $aw['status'] ?>"><?= ucfirst($aw['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Recent Orders -->
        <div class="card">
            <div class="card-head">
                <span class="card-title">Recent Orders</span>
                <a href="orders.php" class="section-link">View all &rarr;</a>
            </div>
            <?php if (empty($recentOrders)): ?>
                <div class="empty">No orders yet.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Buyer</th>
                        <th>Artwork</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentOrders as $order): 
                    $statusClass = $order['order_status'];
                    $label = ucfirst($order['order_status']);
                ?>
                    <tr>
                        <td class="td-title" title="<?= htmlspecialchars($order['display_name']) ?>"><?= htmlspecialchars($order['display_name']) ?></td>
                        <td class="td-title" title="<?= htmlspecialchars($order['artwork_title']) ?>"><?= htmlspecialchars($order['artwork_title']) ?></td>
                        <td style="white-space:nowrap;font-size:11px"><?= date('d M', strtotime($order['created_at'])) ?></td>
                        <td><span class="pill <?= $statusClass ?>"><?= $label ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div><!-- /two-col -->

    <!-- ── Payout Status ──────────────────────────── -->
    <div class="section-header">
        <span class="section-title">Payout Status</span>
    </div>
    <div class="card payout-list">
        <?php if (empty($payoutOrders)): ?>
            <div class="empty">No orders or commissions yet.</div>
        <?php else: ?>
            <?php foreach ($payoutOrders as $po):
                $status = payoutStatusLabel($po);
            ?>
            <div class="payout-row">
                <div>
                    <span class="payout-order-num">#<?= htmlspecialchars($po['order_number']) ?></span>
                    <span class="payout-type"><?= ucfirst($po['order_type']) ?></span>
                </div>
                <span class="pill <?= $status['class'] ?>"><?= htmlspecialchars($status['label']) ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div><!-- /content -->

<div class="dash-footer">
    Art Bazaar &mdash; Artist Dashboard &mdash; <?= date('Y') ?>
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
        <a href="../../index.php">Home</a>
        <a href="index.php">Dashboard</a>
        <a href="my-artworks.php">My Artworks</a>
        <a href="commissions.php">Commissions</a>
        <a href="orders.php">Orders</a>
        <a href="profile.php">Profile</a>
    </div>
    <div class="drawer-actions">
        <a href="../../cart.php">Cart</a>
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