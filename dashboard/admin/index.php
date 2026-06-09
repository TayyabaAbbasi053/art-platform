<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// Auth guard
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

 $adminName = $_SESSION['name'] ?? 'Admin';

// ── Stats queries ──────────────────────────────────────────
 $stats = [];

// Stats now query the unified 'orders' table
 $queries = [
    'total_artists'      => "SELECT COUNT(*) FROM users WHERE role='artist'",
    'active_artists'     => "SELECT COUNT(*) FROM users WHERE role='artist' AND status='active'",
    'pending_artists'    => "SELECT COUNT(*) FROM users WHERE role='artist' AND status='pending'",
    'total_artworks'     => "SELECT COUNT(*) FROM artworks",
    'pending_artworks'   => "SELECT COUNT(*) FROM artworks WHERE status='pending'",
    'approved_artworks'  => "SELECT COUNT(*) FROM artworks WHERE status='approved'",
    'sold_artworks'      => "SELECT COUNT(*) FROM artworks WHERE status='sold'",
    'featured_artworks'  => "SELECT COUNT(*) FROM artworks WHERE is_featured=1",
    
    // Updated: Queries orders for artwork purchases (formerly buyer_inquiries)
    'total_inquiries'    => "SELECT COUNT(*) FROM orders WHERE order_type = 'artwork'",
    'new_inquiries'      => "SELECT COUNT(*) FROM orders WHERE order_type = 'artwork' AND order_status = 'pending'",
    
    // Updated: Queries orders for commissions (formerly commission_requests)
    'total_commissions'  => "SELECT COUNT(*) FROM orders WHERE order_type = 'commission'",
    'new_commissions'    => "SELECT COUNT(*) FROM orders WHERE order_type = 'commission' AND order_status = 'pending'",
    
    'total_messages'     => "SELECT COUNT(*) FROM contact_messages",
    'unread_messages'    => "SELECT COUNT(*) FROM contact_messages WHERE is_read=0",
];

foreach ($queries as $key => $sql) {
    $r = $conn->query($sql);
    $stats[$key] = $r ? (int)$r->fetch_row()[0] : 0;
}

// ── Recent artworks (last 6 pending) ──────────────────────
 $recentArtworks = [];
 $ra = $conn->query("
    SELECT a.id, a.title, a.price, a.status, a.created_at,
           u.name AS artist_name, c.name AS category
    FROM artworks a
    JOIN users u ON u.id = a.artist_id
    JOIN categories c ON c.id = a.category_id
    ORDER BY a.created_at DESC LIMIT 6
");
while ($row = $ra->fetch_assoc()) $recentArtworks[] = $row;

// ── Recent inquiries (last 5) ──────────────────────────────
// Updated: Now queries 'orders' joined with 'order_items' and 'artworks'
 $recentInquiries = [];
 $ri = $conn->query("
    SELECT 
        o.id, 
        o.order_status AS status, 
        o.created_at, 
        COALESCE(u.name, o.guest_name) AS buyer_name,
        a.title AS artwork_title
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id AND oi.item_type = 'artwork'
    JOIN artworks a ON oi.item_id = a.id
    LEFT JOIN users u ON o.buyer_id = u.id
    WHERE o.order_type = 'artwork'
    ORDER BY o.created_at DESC LIMIT 5
");
while ($row = $ri->fetch_assoc()) $recentInquiries[] = $row;

 $today = date('l, d F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — Art Bazaar</title>
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
.sidebar-brand .logo-text {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 500;
    color: var(--bg);
}
.sidebar-brand .logo-tag {
    font-size: 8px;
    letter-spacing: 2px;
    color: var(--sand);
    margin-top: 2px;
}
.sidebar-brand .logo-badge {
    display: inline-block;
    margin-left: 6px;
    background: var(--sand);
    color: var(--ink);
    font-size: 8px;
    letter-spacing: 2px;
    text-transform: uppercase;
    padding: 2px 7px;
    border-radius: 20px;
}
.sidebar-section {
    padding: 18px 16px 6px;
    font-size: 9px;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    color: var(--sand);
    font-weight: 500;
}
.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    font-size: 12.5px;
    color: var(--bg);
    text-decoration: none;
    font-weight: 400;
    border-left: 2px solid transparent;
    transition: all .15s;
    position: relative;
}
.nav-item:hover {
    color: var(--bg);
    background: rgba(255,255,255,0.05);
    border-left-color: rgba(255,255,255,0.2);
}
.nav-item.active {
    color: var(--ink);
    background: var(--sand);
    font-weight: 500;
}
.nav-item .icon {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    opacity: .7;
}
.nav-item.active .icon, .nav-item:hover .icon {
    opacity: 1;
}
.badge {
    margin-left: auto;
    background: var(--sand);
    color: var(--ink);
    font-size: 9px;
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 20px;
    min-width: 18px;
    text-align: center;
}
.badge.amber {
    background: #fff;
    color: var(--ink);
}
.sidebar-bottom {
    margin-top: auto;
    padding: 16px;
    border-top: 1px solid rgba(246,237,222,.1);
}
.signout-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 12px;
    font-size: 12px;
    color: var(--bg);
    text-decoration: none;
    border-radius: 8px;
    transition: all .15s;
    width: 100%;
    background: none;
    border: none;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
}
.signout-btn:hover {
    background: rgba(255,255,255,0.1);
    color: var(--bg);
}

/* ── Topbar ──────────────────────────────────────────── */
.topbar {
    position: fixed;
    top: 0;
    left: var(--sidebar);
    right: 0;
    height: var(--top);
    background: var(--ink);
    border-bottom: 1px solid rgba(246,237,222,.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 32px;
    z-index: 99;
}
.topbar-left h1 {
    font-family: 'Playfair Display', serif;
    font-size: 20px;
    font-weight: 400;
    color: var(--bg);
}
.topbar-left .date {
    font-size: 11px;
    color: var(--sand);
    margin-top: 1px;
}
.topbar-right {
    display: flex;
    align-items: center;
    gap: 20px;
}
.topbar-right .notif {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    border: 1px solid rgba(255,255,255,0.1);
}
.topbar-right .notif-dot {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 6px;
    height: 6px;
    background: var(--sand);
    border-radius: 50%;
    border: 1.5px solid var(--ink);
}
.admin-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.2);
    padding: 5px 12px 5px 5px;
    border-radius: 30px;
}
.admin-chip .avatar {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--sand);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    color: var(--ink);
    font-weight: 600;
}
.admin-chip .name {
    font-size: 12px;
    color: var(--bg);
    font-weight: 500;
}
.admin-chip .arrow {
    font-size: 12px;
    color: var(--sand);
    margin-left: 4px;
}

/* ── Main ────────────────────────────────────────────── */
.main {
    margin-left: var(--sidebar);
    padding-top: var(--top);
    min-height: 100vh;
}
.content {
    padding: 32px;
}

/* ── Section headers ─────────────────────────────────── */
.section-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 18px;
}
.section-title {
    font-size: 11px;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    color: var(--muted);
    font-weight: 500;
}
.section-link {
    font-size: 11px;
    color: var(--body);
    text-decoration: none;
    border-bottom: 1px solid var(--border);
}
.section-link:hover {
    color: var(--ink);
}

/* ── Stat cards ──────────────────────────────────────── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 32px;
}
.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 22px 24px;
    position: relative;
    overflow: hidden;
    transition: box-shadow .2s;
}
.stat-card:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,.06);
}
.stat-card .label {
    font-size: 10px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--ink);
    font-weight: 500;
    margin-bottom: 10px;
}
.stat-card .value {
    font-family: 'Playfair Display', serif;
    font-size: 36px;
    font-weight: 400;
    color: var(--ink);
    line-height: 1;
}
.stat-card .sub {
    font-size: 11px;
    color: var(--muted);
    margin-top: 6px;
}
.stat-card .sub span {
    font-weight: 600;
}
.stat-card .corner-icon {
    position: absolute;
    right: 18px;
    top: 18px;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
    background: var(--sand);
}
.stat-card .corner-icon svg {
    stroke: var(--ink);
}
/* Glow override */
.stat-card .corner-icon::before {
    display: none;
}

/* ── Alert strip ─────────────────────────────────────── */
.alert-strip {
    background: var(--sand);
    color: var(--ink);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
    gap: 12px;
}
.alert-strip.hidden {
    display: none;
}
.alert-strip .alert-text {
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--body);
}
.alert-strip .alert-text strong {
    font-weight: 600;
    color: var(--ink);
}
.alert-strip .alert-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}
.alert-btn {
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 500;
    text-decoration: none;
    font-family: 'DM Sans', sans-serif;
    cursor: pointer;
    border: none;
    transition: opacity .15s;
    white-space: nowrap;
}
.alert-btn.primary {
    background: var(--ink);
    color: var(--bg);
}
.alert-btn.ghost {
    background: transparent;
    color: var(--ink);
    border: 1px solid var(--border);
}
.alert-btn.ghost:hover {
    border-color: var(--ink);
    color: var(--ink);
}
.alert-btn:hover {
    opacity: .85;
}

/* ── Two-col layout ──────────────────────────────────── */
.two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 28px;
}

/* ── Table card ──────────────────────────────────────── */
.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
}
.card-head {
    padding: 18px 22px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.card-head .card-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--ink);
}
.card-head .card-count {
    font-size: 11px;
    color: var(--muted);
}
table {
    width: 100%;
    border-collapse: collapse;
}
th {
    font-size: 9px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--muted);
    font-weight: 500;
    padding: 10px 22px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    background: var(--sand);
}
td {
    font-size: 12px;
    color: var(--body);
    padding: 12px 22px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
tr:last-child td {
    border-bottom: none;
}
tr:hover td {
    background: var(--sand);
    color: var(--ink);
}
.td-title {
    color: var(--ink);
    font-weight: 500;
    font-size: 12.5px;
    max-width: 140px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.td-price {
    font-weight: 500;
    color: var(--ink);
    white-space: nowrap;
}

/* ── Status badges ───────────────────────────────────── */
.pill {
    display: inline-block;
    font-size: 9px;
    letter-spacing: .5px;
    text-transform: uppercase;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 20px;
}
.pill.pending {
    background: var(--sand);
    color: var(--ink);
}
.pill.approved {
    background: var(--ink);
    color: var(--bg);
}
.pill.rejected {
    background: var(--sand);
    color: var(--ink);
}
.pill.sold {
    background: var(--ink);
    color: var(--bg);
}
.pill.hidden {
    background: #f4f4f4;
    color: #888;
}
.pill.new {
    background: var(--sand);
    color: var(--ink);
    font-weight: 700;
}
.pill.contacted {
    background: var(--sand);
    color: var(--ink);
}
.pill.confirmed {
    background: var(--ink);
    color: var(--bg);
}
.pill.completed {
    background: var(--ink);
    color: var(--bg);
}
.pill.cancelled {
    background: #F4F4F4;
    color: #888;
}

/* ── Quick actions ───────────────────────────────────── */
.quick-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 28px;
}
.quick-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px 16px;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
    transition: all .2s;
}
.quick-card:hover {
    border-color: var(--border);
    box-shadow: 0 4px 16px rgba(0,0,0,.07);
}
.quick-card .q-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--sand);
}
.quick-card .q-label {
    font-size: 12px;
    font-weight: 500;
    color: var(--ink);
    line-height: 1.3;
}
.quick-card .q-desc {
    font-size: 10.5px;
    color: var(--muted);
}
.pending-badge {
    margin-left: 4px;
    background: var(--ink);
    color: var(--bg);
    font-size: 9px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 10px;
}

/* ── Empty state ─────────────────────────────────────── */
.empty {
    text-align: center;
    padding: 32px;
    color: var(--muted);
    font-size: 12px;
}

/* ── Footer ──────────────────────────────────────────── */
.dash-footer {
    background: var(--ink);
    padding: 20px 32px;
    font-size: 11px;
    color: var(--bg);
    margin-top: 12px;
    text-align: center;
}

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
    .topbar { left: 0; padding: 0 16px; }
    .content { padding: 16px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .quick-grid { grid-template-columns: repeat(2, 1fr); }
    .two-col { grid-template-columns: 1fr; }
    .card { overflow-x: auto; }
    table { min-width: 500px; }
    .ham-btn { display: flex; }
}
</style>
</head>
<body>

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div>
            <div class="logo-text">Art Bazaar</div>
            <div class="logo-tag">DASHBOARD <span class="logo-badge">Admin</span></div>
        </div>
    </div>

    <div class="sidebar-section">Overview</div>
    <a href="index.php" class="nav-item active">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Overview
    </a>

    <div class="sidebar-section">Content</div>
    <a href="artworks.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
        Artworks
        <?php if ($stats['pending_artworks'] > 0): ?>
            <span class="badge"><?= $stats['pending_artworks'] ?></span>
        <?php endif; ?>
    </a>
    <a href="artists.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        Artists
        <?php if ($stats['pending_artists'] > 0): ?>
            <span class="badge amber"><?= $stats['pending_artists'] ?></span>
        <?php endif; ?>
    </a>
    <a href="categories.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h7"/></svg>
        Categories
    </a>

    <div class="sidebar-section">Requests</div>
    <a href="inquiries.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        Orders & Inquiries
        <?php if ($stats['new_inquiries'] > 0): ?>
            <span class="badge"><?= $stats['new_inquiries'] ?></span>
        <?php endif; ?>
    </a>
    <a href="commissions.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Commissions
        <?php if ($stats['new_commissions'] > 0): ?>
            <span class="badge"><?= $stats['new_commissions'] ?></span>
        <?php endif; ?>
    </a>
    <a href="messages.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v13H4z"/><path d="M4 4l8 9 8-9"/></svg>
        Messages
        <?php if ($stats['unread_messages'] > 0): ?>
            <span class="badge amber"><?= $stats['unread_messages'] ?></span>
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
        <h1>Good <?= (date('H') < 12) ? 'morning' : ((date('H') < 18) ? 'afternoon' : 'evening') ?>, <?= htmlspecialchars(explode(' ', $adminName)[0]) ?> </h1>
        <div class="date"><?= $today ?></div>
    </div>
    <div class="topbar-right">
        <button class="ham-btn" onclick="openDrawer()"><span></span><span></span><span></span></button>
        <?php if ($stats['new_inquiries'] + $stats['new_commissions'] + $stats['unread_messages'] > 0): ?>
        <div class="notif" title="You have pending notifications">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            <span class="notif-dot"></span>
        </div>
        <?php endif; ?>
        <div class="admin-chip">
            <div class="avatar"><?= strtoupper(substr($adminName, 0, 1)) ?></div>
            <span class="name"><?= htmlspecialchars($adminName) ?></span>
            <span class="arrow">∨</span>
        </div>
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <!-- Alert strip: show if there are pending artworks or artists -->
    <?php if ($stats['pending_artworks'] > 0 || $stats['pending_artists'] > 0): ?>
    <div class="alert-strip">
        <div class="alert-text">
            <?php if ($stats['pending_artworks'] > 0 && $stats['pending_artists'] > 0): ?>
                You have <strong><?= $stats['pending_artworks'] ?> artwork<?= $stats['pending_artworks'] > 1 ? 's' : '' ?></strong> awaiting approval and <strong><?= $stats['pending_artists'] ?> artist<?= $stats['pending_artists'] > 1 ? 's' : '' ?></strong> pending review.
            <?php elseif ($stats['pending_artworks'] > 0): ?>
                You have <strong><?= $stats['pending_artworks'] ?> artwork<?= $stats['pending_artworks'] > 1 ? 's' : '' ?></strong> waiting for your approval.
            <?php else: ?>
                You have <strong><?= $stats['pending_artists'] ?> new artist<?= $stats['pending_artists'] > 1 ? 's' : '' ?></strong> waiting for account approval.
            <?php endif; ?>
        </div>
        <div class="alert-actions">
            <?php if ($stats['pending_artworks'] > 0): ?>
                <a href="artworks.php?status=pending" class="alert-btn primary">Review Artworks</a>
            <?php endif; ?>
            <?php if ($stats['pending_artists'] > 0): ?>
                <a href="artists.php?status=pending" class="alert-btn ghost">Review Artists</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Stat Cards ─────────────────────────────── -->
    <div class="section-header">
        <span class="section-title">Overview</span>
    </div>
    <div class="stats-grid">
        <div class="stat-card artists">
            <div class="corner-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            </div>
            <div class="label">Artists</div>
            <div class="value"><?= $stats['total_artists'] ?></div>
            <div class="sub"><span><?= $stats['active_artists'] ?></span> active &middot; <span><?= $stats['pending_artists'] ?></span> pending</div>
        </div>
        <div class="stat-card artworks">
            <div class="corner-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
            </div>
            <div class="label">Artworks</div>
            <div class="value"><?= $stats['total_artworks'] ?></div>
            <div class="sub"><span><?= $stats['approved_artworks'] ?></span> approved &middot; <span><?= $stats['pending_artworks'] ?></span> pending &middot; <span style="color:var(--ink)"><?= $stats['sold_artworks'] ?></span> sold</div>
        </div>
        <div class="stat-card inquiries">
            <div class="corner-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </div>
            <div class="label">Buyer Inquiries</div>
            <div class="value"><?= $stats['total_inquiries'] ?></div>
            <div class="sub"><span><?= $stats['new_inquiries'] ?></span> new &amp; pending</div>
        </div>
        <div class="stat-card commissions">
            <div class="corner-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </div>
            <div class="label">Commissions</div>
            <div class="value"><?= $stats['total_commissions'] ?></div>
            <div class="sub"><span><?= $stats['new_commissions'] ?></span> new requests</div>
        </div>
    </div>

    <!-- ── Quick Actions ──────────────────────────── -->
    <div class="section-header">
        <span class="section-title">Quick Actions</span>
    </div>
    <div class="quick-grid">
        <a href="artworks.php?status=pending" class="quick-card review-artworks">
            <div class="q-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="1.8"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            </div>
            <div>
                <div class="q-label">Review Artworks <?php if($stats['pending_artworks']>0): ?><span class="pending-badge"><?= $stats['pending_artworks'] ?></span><?php endif; ?></div>
                <div class="q-desc">Approve or reject submissions</div>
            </div>
        </a>
        <a href="artists.php?status=pending" class="quick-card manage-artists">
            <div class="q-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            </div>
            <div>
                <div class="q-label">Manage Artists <?php if($stats['pending_artists']>0): ?><span class="pending-badge"><?= $stats['pending_artists'] ?></span><?php endif; ?></div>
                <div class="q-desc">Approve or block artist accounts</div>
            </div>
        </a>
        <a href="inquiries.php?status=pending" class="quick-card new-inquiries">
            <div class="q-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </div>
            <div>
                <div class="q-label">New Inquiries <?php if($stats['new_inquiries']>0): ?><span class="pending-badge"><?= $stats['new_inquiries'] ?></span><?php endif; ?></div>
                <div class="q-desc">Buyer purchase requests</div>
            </div>
        </a>
        <a href="commissions.php?status=pending" class="quick-card new-commissions">
            <div class="q-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </div>
            <div>
                <div class="q-label">New Commissions <?php if($stats['new_commissions']>0): ?><span class="pending-badge"><?= $stats['new_commissions'] ?></span><?php endif; ?></div>
                <div class="q-desc">Custom artwork requests</div>
            </div>
        </a>
    </div>

    <!-- ── Two column tables ──────────────────────── -->
    <div class="two-col">

        <!-- Recent Artworks -->
        <div class="card">
            <div class="card-head">
                <span class="card-title">Recent Artworks</span>
                <a href="artworks.php" class="section-link">View all →</a>
            </div>
            <?php if (empty($recentArtworks)): ?>
                <div class="empty">No artworks yet.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Artist</th>
                        <th>Price</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentArtworks as $aw): ?>
                    <tr>
                        <td class="td-title" title="<?= htmlspecialchars($aw['title']) ?>"><?= htmlspecialchars($aw['title']) ?></td>
                        <td><?= htmlspecialchars($aw['artist_name']) ?></td>
                        <td class="td-price">PKR <?= number_format($aw['price']) ?></td>
                        <td><span class="pill <?= $aw['status'] ?>"><?= ucfirst($aw['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Recent Inquiries -->
        <div class="card">
            <div class="card-head">
                <span class="card-title">Recent Inquiries</span>
                <a href="inquiries.php" class="section-link">View all →</a>
            </div>
            <?php if (empty($recentInquiries)): ?>
                <div class="empty">No inquiries yet.</div>
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
                <?php foreach ($recentInquiries as $inq): ?>
                    <tr>
                        <td><?= htmlspecialchars($inq['buyer_name']) ?></td>
                        <td class="td-title" title="<?= htmlspecialchars($inq['artwork_title']) ?>"><?= htmlspecialchars($inq['artwork_title']) ?></td>
                        <td style="white-space:nowrap;font-size:11px"><?= date('d M', strtotime($inq['created_at'])) ?></td>
                        <td><span class="pill <?= $inq['status'] ?>"><?= ucfirst($inq['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div><!-- /two-col -->

    <!-- ── Mini stats row ────────────────────────── -->
    <div class="section-header">
        <span class="section-title">More Stats</span>
    </div>
    <div class="stats-grid" style="margin-bottom:0">
        <div class="stat-card">
            <div class="label">Featured Artworks</div>
            <div class="value" style="font-size:28px"><?= $stats['featured_artworks'] ?></div>
            <div class="sub">Currently on homepage</div>
        </div>
        <div class="stat-card">
            <div class="label">Sold Artworks</div>
            <div class="value" style="font-size:28px"><?= $stats['sold_artworks'] ?></div>
            <div class="sub">Completed sales</div>
        </div>
        <div class="stat-card">
            <div class="label">Contact Messages</div>
            <div class="value" style="font-size:28px"><?= $stats['total_messages'] ?></div>
            <div class="sub"><span><?= $stats['unread_messages'] ?></span> unread</div>
        </div>
        <div class="stat-card">
            <div class="label">Total Buyers Reached</div>
            <div class="value" style="font-size:28px"><?= $stats['total_inquiries'] + $stats['total_commissions'] ?></div>
            <div class="sub">Inquiries + commissions</div>
        </div>
    </div>

</div><!-- /content -->

<div class="dash-footer">
    Art Bazaar Admin Panel &mdash; <?= date('Y') ?>
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
        <a href="index.php">Dashboard</a>
        <a href="artworks.php">Artworks</a>
        <a href="artists.php">Artists</a>
        <a href="categories.php">Categories</a>
        <a href="inquiries.php">Orders & Inquiries</a>
        <a href="commissions.php">Commissions</a>
        <a href="messages.php">Messages</a>
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