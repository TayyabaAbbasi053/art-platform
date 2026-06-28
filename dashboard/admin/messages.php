<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

 $adminName = $_SESSION['name'] ?? 'Admin';
 $toast = '';

// ── Handle actions ──────────────────────────────────────

// Mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE contact_messages SET is_read = 1 WHERE id = $id");
        $toast = 'Marked as read.';
    }
}

// Mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    $conn->query("UPDATE contact_messages SET is_read = 1 WHERE is_read = 0");
    $toast = 'All messages marked as read.';
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("DELETE FROM contact_messages WHERE id = $id");
        $toast = 'Message deleted.';
    }
}

// ── Build query ─────────────────────────────────────────
 $where   = ["1=1"];
 $params  = [];
 $types   = '';

 $readFilter = $_GET['read'] ?? '';
if ($readFilter === 'unread') {
    $where[] = "cm.is_read = 0";
} elseif ($readFilter === 'read') {
    $where[] = "cm.is_read = 1";
}

 $search = trim($_GET['q'] ?? '');
if ($search) {
    $where[] = "(cm.name LIKE ? OR cm.email LIKE ? OR cm.phone LIKE ? OR cm.message LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}

 $whereSQL = implode(' AND ', $where);

 $sortMap = [
    'newest' => 'cm.created_at DESC',
    'oldest' => 'cm.created_at ASC',
    'name'   => 'cm.name ASC',
];
 $sortBy = $sortMap[$_GET['sort'] ?? ''] ?? 'cm.created_at DESC';

 $page    = max(1, (int)($_GET['page'] ?? 1));
 $perPage = 20;
 $offset  = ($page - 1) * $perPage;

// Count
 $countSQL = "SELECT COUNT(*) FROM contact_messages cm WHERE $whereSQL";
if ($params) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalResults = (int)$stmt->get_result()->fetch_row()[0];
} else {
    $totalResults = (int)$conn->query($countSQL)->fetch_row()[0];
}
 $totalPages = max(1, ceil($totalResults / $perPage));

// Fetch
 $dataSQL = "SELECT cm.* FROM contact_messages cm WHERE $whereSQL ORDER BY $sortBy LIMIT $perPage OFFSET $offset";
 $messages = [];
if ($params) {
    $stmt = $conn->prepare($dataSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($dataSQL);
}
while ($row = $res->fetch_assoc()) $messages[] = $row;

// Counts
 $unreadCount = (int)$conn->query("SELECT COUNT(*) FROM contact_messages WHERE is_read = 0")->fetch_row()[0];
 $readCount   = (int)$conn->query("SELECT COUNT(*) FROM contact_messages WHERE is_read = 1")->fetch_row()[0];
 $totalCount  = $unreadCount + $readCount;

function buildQS($overrides = []) {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    unset($q['page']);
    return http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages — Art Bazaar Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    /* Design System Variables */
    --bg: #F6EDDE;
    --card: #F6EDDE;
    --sand: #DDCDAE;
    --border: #0C3F30;
    --ink: #0C3F30;
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }

.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: var(--ink); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--border); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--sand); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--bg); text-decoration: none; font-weight: 400; border-left: 2px solid transparent; transition: all .15s; }
.nav-item:hover { color: var(--ink); background: var(--sand); border-left-color: var(--sand); }
.nav-item.active { color: var(--ink); background: var(--sand); border-left-color: var(--ink); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .8; stroke: var(--bg); }
.nav-item.active .icon, .nav-item:hover .icon { stroke: var(--ink); opacity: 1; }
.badge { margin-left: auto; background: var(--ink); color: var(--bg); font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
.badge.amber { background: var(--ink); color: var(--bg); }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: var(--sand); color: var(--ink); }

.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--ink); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.topbar-left .sub { font-size: 11px; color: var(--sand); margin-top: 1px; opacity: 0.8; }
.admin-chip { display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); padding: 5px 12px 5px 5px; border-radius: 30px; }
.admin-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--bg); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--ink); font-weight: 600; }
.admin-chip .name { font-size: 12px; color: var(--ink); font-weight: 500; }
.admin-chip .arrow { font-size: 12px; color: var(--ink); margin-left: 4px; opacity: 0.6; }

.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 28px 32px; }

.toast { background: var(--sand); color: var(--ink); border: 1px solid var(--border); padding: 12px 20px; border-radius: 10px; font-size: 12.5px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }
.toast.hidden { display: none; }
.toast-close { background: none; border: none; color: var(--ink); cursor: pointer; font-size: 16px; opacity: 0.6; }
.toast-close:hover { opacity: 1; }

/* ── Tabs ──────────────────────────────────────────── */
.tabs { display: flex; gap: 6px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
.tab { display: flex; align-items: center; gap: 6px; padding: 9px 18px; font-size: 12px; color: var(--ink); text-decoration: none; border-radius: 999px; border: 2px solid var(--border); transition: all .15s; font-weight: 500; background: var(--bg); cursor: pointer; font-family: 'DM Sans', sans-serif; }
.tab:hover { background: var(--sand); }
.tab.active { background: var(--ink); color: var(--bg); border-color: var(--ink); font-weight: 600; }
.tab .count { font-size: 10px; font-weight: 700; background: rgba(12,63,48,0.1); padding: 2px 8px; border-radius: 999px; color: var(--ink); }
.tab.active .count { background: rgba(246,237,222,0.25); color: var(--bg); }
.tab .count.hot { background: var(--sand); color: var(--ink); }
.tab-sep { width: 1px; height: 20px; background: var(--border); margin: 0 4px; flex-shrink: 0; opacity: 0.3; }
.mark-all-btn { padding: 9px 18px; font-size: 12px; font-weight: 600; border-radius: 999px; border: 2px solid var(--border); background: var(--bg); color: var(--ink); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .15s; margin-left: auto; white-space: nowrap; }
.mark-all-btn:hover { background: var(--sand); }
.mark-all-btn:disabled { opacity: .4; pointer-events: none; }

/* ── Filters ──────────────────────────────────────── */
.filters { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
.filters input[type="text"], .filters select { padding: 10px 20px; border: 2px solid var(--border); border-radius: 999px; font-size: 13px; font-family: 'DM Sans', sans-serif; color: var(--ink); background: var(--bg); outline: none; transition: border-color .15s, box-shadow .15s; font-weight: 500; }
.filters input:focus, .filters select:focus { border-color: var(--ink); box-shadow: 0 0 0 3px rgba(12,63,48,0.12); }
.filters input { width: 280px; }
.filters select { min-width: 180px; cursor: pointer; }
.clear-link { font-size: 11px; color: var(--ink); text-decoration: none; cursor: pointer; background: none; border: none; font-family: 'DM Sans', sans-serif; opacity: 0.6; }
.clear-link:hover { color: var(--ink); opacity: 1; }

.results-info { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; font-size: 11px; color: var(--ink); opacity: 0.7; }

/* ── Table ─────────────────────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.card table { border-radius: 0 0 14px 14px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; padding: 11px 18px; text-align: left; border-bottom: 1px solid var(--border); background: var(--sand); opacity: 0.8; white-space: nowrap; }
td { font-size: 12.5px; color: var(--ink); padding: 14px 18px; border-bottom: 1px solid var(--border); vertical-align: top; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--sand); }
tr.unread td { background: var(--card); }
tr.unread:hover td { background: var(--sand); }

.td-sender { display: flex; align-items: flex-start; gap: 12px; }
.sender-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--sand); flex-shrink: 0; margin-top: 6px; }
.unread .sender-dot { background: var(--ink); }
.sender-info .sender-name { color: var(--ink); font-weight: 500; font-size: 13px; }
.sender-info .sender-contact { font-size: 11px; color: var(--ink); opacity: 0.7; margin-top: 2px; }
.sender-info .sender-contact a { color: var(--ink); text-decoration: none; }
.sender-info .sender-contact a:hover { text-decoration: underline; }
.td-message { max-width: 400px; line-height: 1.6; color: var(--ink); font-size: 12.5px; cursor: pointer; }
.td-message:hover { color: var(--ink); }
.td-date { font-size: 11px; color: var(--ink); white-space: nowrap; vertical-align: middle; opacity: 0.7; }

.read-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--sand); flex-shrink: 0; }
.read-dot.unread { background: var(--ink); }

.td-actions { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; vertical-align: middle; }
.act-btn { padding: 5px 10px; font-size: 10.5px; font-weight: 500; border-radius: 7px; border: 1px solid var(--border); background: var(--card); color: var(--ink); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .12s; white-space: nowrap; }
.act-btn:hover { border-color: var(--ink); color: var(--ink); background: var(--sand); }
.act-btn.red:hover { border-color: var(--ink); color: var(--ink); background: var(--sand); }

.empty { text-align: center; padding: 48px 24px; color: var(--ink); font-size: 13px; opacity: 0.7; }

/* ── Pagination ────────────────────────────────────── */
.pagination { display: flex; align-items: center; justify-content: center; gap: 4px; margin-top: 20px; }
.page-btn { padding: 7px 13px; font-size: 11.5px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); color: var(--ink); cursor: pointer; font-family: 'DM Sans', sans-serif; text-decoration: none; transition: all .12s; }
.page-btn:hover { border-color: var(--ink); color: var(--ink); }
.page-btn.active { background: var(--ink); color: var(--bg); border-color: var(--ink); }
.page-btn.disabled { opacity: .35; pointer-events: none; }

/* ── Detail modal ─────────────────────────────────── */
.modal-overlay { position: fixed; inset: 0; background: rgba(12,63,48,.4); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .2s; }
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal { background: var(--card); border-radius: 16px; width: 560px; max-width: 92vw; box-shadow: 0 24px 60px rgba(12,63,48,.2); transform: translateY(12px); transition: transform .2s; border: 1px solid var(--border); }
.modal-overlay.open .modal { transform: translateY(0); }
.modal-head { padding: 24px 28px 0; display: flex; align-items: flex-start; justify-content: space-between; }
.modal-head h3 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--ink); }
.modal-close { background: none; border: none; font-size: 18px; color: var(--ink); cursor: pointer; padding: 0; line-height: 1; opacity: 0.7; }
.modal-close:hover { opacity: 1; }
.modal-body { padding: 20px 28px 28px; }
.modal-foot { padding: 0 28px 24px; display: flex; gap: 10px; justify-content: flex-end; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; font-size: 12px; font-weight: 500; border-radius: 10px; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .15s; text-decoration: none; }
.btn-ghost { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--ink); color: var(--ink); background: var(--sand); }
.btn-primary { background: var(--ink); color: var(--bg); }
.btn-primary:hover { background: #164a3b; }
.btn-danger { background: var(--ink); color: var(--bg); }
.btn-danger:hover { background: #164a3b; }
.btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 7px; }

.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.detail-item .dl { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 4px; opacity: 0.7; }
.detail-item .dv { font-size: 13px; color: var(--ink); font-weight: 500; }
.detail-item .dv a { color: var(--ink); text-decoration: none; }
.detail-item .dv a:hover { text-decoration: underline; }
.detail-item .dv.muted { color: var(--ink); opacity: 0.5; font-weight: 400; }
.detail-full { grid-column: 1 / -1; margin-top: 16px; }
.detail-full .dl { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 6px; opacity: 0.7; }
.msg-box { font-size: 13.5px; color: var(--ink); line-height: 1.7; background: var(--sand); padding: 18px; border-radius: 10px; white-space: pre-wrap; word-break: break-word; border: 1px solid var(--border); }

.dash-footer { padding: 20px 32px; border-top: 1px solid var(--border); font-size: 11px; color: var(--bg); margin-top: 12px; background: var(--ink); }

/* ── Hamburger Drawer ─────────────────────────────────── */
#nav-drawer { display:none; position: fixed; top: 0; right: 0; width: 260px; height: 100vh; background: var(--ink); z-index: 200; transform: translateX(100%); transition: transform 0.3s ease; padding: 24px; display: flex; flex-direction: column; border-left: 1px solid var(--border); }
#nav-drawer.open { transform: translateX(0); display: flex; }
#nav-overlay { display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(12,63,48,0.4); z-index: 150; backdrop-filter: blur(2px); }
#nav-overlay.open { display: block; }
.ham-btn { display: none; flex-direction: column; gap: 5px; background: none; border: none; cursor: pointer; padding: 5px; width: 30px; }
.ham-btn span { width: 100%; height: 2px; background: var(--bg); border-radius: 2px; transition: 0.2s; }
.d-header { font-family: 'Playfair Display', serif; font-size: 18px; color: var(--bg); margin-bottom: 24px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
.d-link { color: var(--bg); text-decoration: none; font-size: 14px; padding: 12px 0; display: block; border-bottom: 1px solid rgba(246,237,222,0.1); font-family: 'DM Sans', sans-serif; }
.d-link:hover { color: var(--sand); padding-left: 5px; transition: 0.2s; }

/* Mobile Responsive */
@media (max-width: 1080px) {
    /* Tablet adjustments */
}

@media (max-width: 768px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; padding: 0 16px; }
    .content { padding: 16px; }
    td, th { padding: 10px 14px; }
    .td-message { max-width: 240px; }
    .td-actions { flex-direction: column; width: 100%; }
    .td-actions form, .td-actions button { width: 100%; }
    .detail-grid { grid-template-columns: 1fr; }
    .tabs { gap: 2px; overflow-x: auto; flex-wrap: nowrap; }
    .tab { padding: 6px 10px; font-size: 10.5px; white-space: nowrap; }
    .tab-sep { display: none; }
    .filters input { width: 100%; }
    .dash-footer { padding: 20px 16px; text-align: center; }
    
    .ham-btn { display: flex; }
    .admin-chip { display: none; }
}
</style>
</head>
<body>

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="logo-tag">Art Bazaar</div>
        <div class="logo-name">Dashboard</div>
        <span class="logo-badge">Admin</span>
    </div>
    <div class="sidebar-section">Overview</div>
    <a href="index.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> Overview</a>
    <div class="sidebar-section">Content</div>
    <a href="artworks.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg> Artworks</a>
    <a href="artists.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Artists</a>
    <a href="blogs.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/></svg>
    Blog Posts
</a>
    <a href="categories.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h7"/></svg> Categories</a>
    <div class="sidebar-section">Requests</div>
    <a href="inquiries.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Buyer Inquiries</a>
    <a href="commissions.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Commissions</a>
    <a href="messages.php" class="nav-item active"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v13H4z"/><path d="M4 4l8 9 8-9"/></svg> Messages<?php if ($unreadCount > 0): ?><span class="badge amber"><?= $unreadCount ?></span><?php endif; ?></a>
    <div class="sidebar-bottom">
        <a href="../../logout.php" class="signout-btn"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sign out</a>
    </div>
</aside>

<!-- ══════════════ TOPBAR ══════════════ -->
<header class="topbar">
    <div class="topbar-left">
        <h1>Messages</h1>
        <div class="sub">Contact form submissions from visitors</div>
    </div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:12px;">
        <button class="ham-btn" id="hamBtn">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <?php if ($toast): ?>
    <div class="toast"><span><?= htmlspecialchars($toast) ?></span><button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?<?= buildQS(['read' => null]) ?>" class="tab <?= !$readFilter ? 'active' : '' ?>">All <span class="count"><?= $totalCount ?></span></a>
        <a href="?<?= buildQS(['read' => 'unread']) ?>" class="tab <?= $readFilter === 'unread' ? 'active' : '' ?>">Unread <span class="count <?= ($unreadCount > 0) ? 'hot' : '' ?>"><?= $unreadCount ?></span></a>
        <a href="?<?= buildQS(['read' => 'read']) ?>" class="tab <?= $readFilter === 'read' ? 'active' : '' ?>">Read <span class="count"><?= $readCount ?></span></a>
        <span class="tab-sep"></span>
        <?php if ($unreadCount > 0): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="mark-all-btn">Mark all as read</button>
            </form>
        <?php else: ?>
            <button class="mark-all-btn" disabled>All caught up ✓</button>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="filters">
        <input type="text" placeholder="Search name, email, phone, message..." value="<?= htmlspecialchars($search) ?>" id="searchInput">
        <select id="sortSelect">
            <option value="newest" <?= ($_GET['sort'] ?? '') === 'newest' || !isset($_GET['sort']) ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest" <?= ($_GET['sort'] ?? '') === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
            <option value="name" <?= ($_GET['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Name A–Z</option>
        </select>
        <?php if ($readFilter || $search): ?>
            <button class="clear-link" onclick="window.location.href='messages.php'">Clear all</button>
        <?php endif; ?>
    </div>

    <!-- Results info -->
    <div class="results-info">
        <div>Showing <?= count($messages) ?> of <?= $totalResults ?> messages</div>
        <div>Page <?= $page ?> of <?= $totalPages ?></div>
    </div>

    <!-- Table -->
    <div class="card">
        <?php if (empty($messages)): ?>
            <div class="empty">No messages found. They will appear here when visitors use the contact form.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th></th>
                    <th>Sender</th>
                    <th>Message</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($messages as $msg): ?>
                <tr class="<?= $msg['is_read'] ? '' : 'unread' ?>">
                    <td><span class="read-dot <?= $msg['is_read'] ? '' : 'unread' ?>"></span></td>
                    <td>
                        <div class="td-sender">
                            <div class="sender-info">
                                <div class="sender-name"><?= htmlspecialchars($msg['name']) ?></div>
                                <div class="sender-contact">
                                    <?php if ($msg['email']): ?><a href="mailto:<?= htmlspecialchars($msg['email']) ?>"><?= htmlspecialchars($msg['email']) ?></a><?php endif; ?>
                                    <?php if ($msg['email'] && $msg['phone']): ?> · <?php endif; ?>
                                    <?php if ($msg['phone']): ?><?= htmlspecialchars($msg['phone']) ?><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="td-message" onclick="openDetail(<?= $msg['id'] ?>)" title="Click to view full message"><?= htmlspecialchars(trim($msg['message'])) ?>...</div>
                    </td>
                    <td class="td-date"><?= date('d M Y', strtotime($msg['created_at'])) ?></td>
                    <td>
                        <div class="td-actions">
                            <?php if (!$msg['is_read']): ?>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?= $msg['id'] ?>"><button type="submit" class="act-btn" title="Mark as read">Read</button></form>
                            <?php endif; ?>
                            <button type="button" class="act-btn" onclick="openDetail(<?= $msg['id'] ?>)" title="View full message">View</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this message?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $msg['id'] ?>"><button type="submit" class="act-btn red" title="Delete">Delete</button></form>
                        </div>
                    </td>
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
            <a href="?<?= buildQS(['page' => $page - 1]) ?>" class="page-btn">← Prev</a>
        <?php else: ?>
            <span class="page-btn disabled">← Prev</span>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
        if ($start > 1) { echo '<a href="?'.buildQS(['page'=>1]).'" class="page-btn">1</a>'; if ($start > 2) echo '<span class="page-btn disabled">...</span>'; }
        for ($i = $start; $i <= $end; $i++) echo '<a href="?'.buildQS(['page'=>$i]).'" class="page-btn '.($i === $page ? 'active' : '').'">'.$i.'</a>';
        if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<span class="page-btn disabled">...</span>'; echo '<a href="?'.buildQS(['page'=>$totalPages]).'" class="page-btn">'.$totalPages.'</a>'; }
        ?>
        <?php if ($page < $totalPages): ?>
            <a href="?<?= buildQS(['page' => $page + 1]) ?>" class="page-btn">Next →</a>
        <?php else: ?>
            <span class="page-btn disabled">Next →</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>
<div class="dash-footer">Art Bazaar Admin Panel &mdash; <?= date('Y') ?></div>
</main>

<!-- HAMBURGER DRAWER HTML -->
<div id="nav-overlay"></div>
<div id="nav-drawer">
  <div class="d-header">Menu</div>
  <a href="index.php" class="d-link">Overview</a>
  <a href="artworks.php" class="d-link">Artworks</a>
  <a href="artists.php" class="d-link">Artists</a>
  <a href="blogs.php" class="d-link">Blog Posts</a>
  <a href="categories.php" class="d-link">Categories</a>
  <a href="inquiries.php" class="d-link">Buyer Inquiries</a>
  <a href="commissions.php" class="d-link">Commissions</a>
  <a href="messages.php" class="d-link">Messages</a>
  <div style="margin-top:auto;border-top:1px solid rgba(246,237,222,0.1);padding-top:16px;">
    <a href="../../logout.php" class="d-link" style="color:#ff9999;">Sign Out</a>
  </div>
</div>

<!-- ══════════════ DETAIL MODAL ══════════════ -->
<div class="modal-overlay" id="detailModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Message Details</h3>
            <button class="modal-close" onclick="closeDetail()">&times;</button>
        </div>
        <div class="modal-body" id="detailContent"></div>
    </div>
</div>

<script>
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

const messageData = <?= json_encode($messages, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function openDetail(id) {
    const msg = messageData.find(i => i.id == id);
    if (!msg) return;

    const content = document.getElementById('detailContent');
    content.innerHTML = `
        <div class="detail-grid">
            <div class="detail-item"><div class="dl">Name</div><div class="dv">${esc(msg.name)}</div></div>
            <div class="detail-item"><div class="dl">Date / Time</div><div class="dv">${esc(msg.created_at)}</div></div>
            <div class="detail-item"><div class="dl">Email</div><div class="dv ${!msg.email ? 'muted' : ''}">${msg.email ? '<a href="mailto:' + esc(msg.email) + '">' + esc(msg.email) + '</a>' : 'Not provided'}</div></div>
            <div class="detail-item"><div class="dl">Phone</div><div class="dv ${!msg.phone ? 'muted' : ''}">${msg.phone ? esc(msg.phone) : 'Not provided'}</div></div>
        </div>
        <div class="detail-full">
            <div class="dl">Message</div>
            <div class="msg-box">${esc(msg.message)}</div>
        </div>
        <div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end;">
            ${!msg.is_read ? '<form method="POST"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="' + msg.id + '"><button type="submit" class="btn btn-primary btn-sm">Mark as Read</button></form>' : '<span style="font-size:12px;color:var(--ink);padding:9px 0;">✓ Already read</span>'}
            <form method="POST" onsubmit="return confirm('Delete this message?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${msg.id}"><button type="submit" class="btn btn-danger btn-sm">Delete</button></form>
        </div>
    `;
    document.getElementById('detailModal').classList.add('open');
}

function closeDetail() { document.getElementById('detailModal').classList.remove('open'); }
document.getElementById('detailModal').addEventListener('click', function(e) { if (e.target === this) closeDetail(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeDetail(); });

function esc(str) { if (!str) return ''; const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }

let searchTimer;
document.getElementById('searchInput').addEventListener('keyup', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 400);
});
document.getElementById('sortSelect').addEventListener('change', applyFilters);

function applyFilters() {
    let params = new URLSearchParams(window.location.search);
    let q = document.getElementById('searchInput').value.trim();
    let sort = document.getElementById('sortSelect').value;
    if (q) params.set('q', q); else params.delete('q');
    if (sort) params.set('sort', sort); else params.delete('sort');
    params.delete('page');
    window.location.href = 'messages.php?' + params.toString();
}
</script>
</body>
</html>