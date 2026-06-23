<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// Auth guard
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

 $adminName = $_SESSION['name'] ?? 'Admin';
 $toast = '';

// ── Handle actions ──────────────────────────────────────

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        // Delete featured image file
        $imgRow = $conn->query("SELECT featured_image FROM blog_posts WHERE id = $id");
        if ($imgRow && $img = $imgRow->fetch_assoc()) {
            if ($img['featured_image']) {
                $filePath = __DIR__ . '/../../' . $img['featured_image'];
                if (file_exists($filePath)) unlink($filePath);
            }
        }
        $conn->query("DELETE FROM blog_posts WHERE id = $id");
        $toast = 'Blog post deleted permanently.';
    }
}

// Toggle published/draft
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE blog_posts SET status = IF(status='published','draft','published'), published_at = IF(status='draft', NOW(), published_at) WHERE id = $id");
        $toast = 'Post status updated.';
    }
}

// Toggle featured
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'feature') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE blog_posts SET is_featured = IF(is_featured=1, 0, 1) WHERE id = $id");
        $toast = 'Featured status updated.';
    }
}

// ── Build query with filters ───────────────────────────
 $where   = ["1=1"];
 $params  = [];
 $types   = '';

// Status filter
 $statusFilter = $_GET['status'] ?? '';
if (in_array($statusFilter, ['draft','published'])) {
    $where[] = "bp.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

// Featured filter
if (isset($_GET['featured']) && $_GET['featured'] === '1') {
    $where[] = "bp.is_featured = 1";
}

// Search
 $search = trim($_GET['q'] ?? '');
if ($search) {
    $where[] = "(bp.title LIKE ? OR u.name LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

 $whereSQL = implode(' AND ', $where);

// Sort
 $sortMap = [
    'newest'      => 'bp.created_at DESC',
    'oldest'      => 'bp.created_at ASC',
    'views_high'  => 'bp.views DESC',
    'views_low'   => 'bp.views ASC',
    'title'       => 'bp.title ASC',
];
 $sortBy = $sortMap[$_GET['sort'] ?? ''] ?? 'bp.created_at DESC';

// Pagination
 $page     = max(1, (int)($_GET['page'] ?? 1));
 $perPage  = 15;
 $offset   = ($page - 1) * $perPage;

// Count total
 $countSQL = "SELECT COUNT(*) FROM blog_posts bp JOIN users u ON u.id = bp.author_id WHERE $whereSQL";
if ($params) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalResults = (int)$stmt->get_result()->fetch_row()[0];
} else {
    $totalResults = (int)$conn->query($countSQL)->fetch_row()[0];
}
 $totalPages = max(1, ceil($totalResults / $perPage));

// Fetch blog posts
 $dataSQL = "
    SELECT bp.*, u.name AS author_name
    FROM blog_posts bp
    JOIN users u ON u.id = bp.author_id
    WHERE $whereSQL
    ORDER BY $sortBy
    LIMIT $perPage OFFSET $offset
";
 $posts = [];
if ($params) {
    $stmt = $conn->prepare($dataSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($dataSQL);
}
while ($row = $res->fetch_assoc()) $posts[] = $row;

// Status counts for tabs
 $statusCounts = [];
foreach (['draft','published'] as $s) {
    $r = $conn->query("SELECT COUNT(*) FROM blog_posts WHERE status='$s'");
    $statusCounts[$s] = (int)$r->fetch_row()[0];
}
 $statusCounts['all'] = array_sum($statusCounts);

// Build query string helper
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
<title>Blog Posts — Art Bazaar Admin</title>
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
    position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top);
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
.topbar-left .sub {
    font-size: 11px;
    color: var(--sand);
    margin-top: 1px;
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
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 28px 32px; }

/* ── Toast ───────────────────────────────────────────── */
.toast {
    background: var(--sand);
    color: var(--ink);
    border: 1px solid var(--border);
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 12.5px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.toast.hidden { display: none; }
.toast-close { background: none; border: none; color: var(--ink); cursor: pointer; font-size: 16px; }
.toast-close:hover { color: var(--ink); }

/* ── Status tabs ─────────────────────────────────────── */
.tabs { display: flex; gap: 6px; margin-bottom: 20px; flex-wrap: wrap; }
.tab {
    display: flex; align-items: center; gap: 6px; padding: 9px 18px;
    font-size: 12px; color: var(--ink); text-decoration: none; border-radius: 999px;
    border: 2px solid var(--border); transition: all .15s; font-weight: 500;
    background: var(--bg); cursor: pointer; font-family: 'DM Sans', sans-serif;
}
.tab:hover { background: var(--sand); }
.tab.active { background: var(--ink); color: var(--bg); border-color: var(--ink); font-weight: 600; }
.tab .count { font-size: 10px; font-weight: 700; background: rgba(12,63,48,0.1); padding: 2px 8px; border-radius: 999px; color: var(--ink); }
.tab.active .count { background: rgba(246,237,222,0.25); color: var(--bg); }

/* ── Filters bar ─────────────────────────────────────── */
.filters { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
.filters select, .filters input[type="text"] {
    padding: 10px 20px; border: 2px solid var(--border); border-radius: 999px;
    font-size: 13px; font-family: 'DM Sans', sans-serif; color: var(--ink);
    background: var(--bg); outline: none; transition: border-color .15s, box-shadow .15s; font-weight: 500;
}
.filters select:focus, .filters input:focus { border-color: var(--ink); box-shadow: 0 0 0 3px rgba(12,63,48,0.12); }
.filters select { min-width: 180px; cursor: pointer; }
.filters input[type="text"] { width: 280px; }
.filter-sep { width: 1px; height: 24px; background: var(--border); }
.clear-link { font-size: 11px; color: var(--muted); text-decoration: none; cursor: pointer; background: none; border: none; font-family: 'DM Sans', sans-serif; }
.clear-link:hover { color: var(--ink); }

/* ── Create button ───────────────────────────────────── */
.create-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 20px; font-size: 12px; font-weight: 500;
    border-radius: 10px; border: 1.5px solid var(--border);
    background: var(--ink); color: var(--bg); text-decoration: none;
    font-family: 'DM Sans', sans-serif; transition: all .15s;
    cursor: pointer;
}
.create-btn:hover { background: #1a503f; border-color: #1a503f; }

/* ── Results info ────────────────────────────────────── */
.results-info { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.results-info .left { font-size: 11px; color: var(--muted); }
.results-info .right { font-size: 11px; color: var(--muted); }

/* ── Table ───────────────────────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.card table { border-radius: 0 0 14px 14px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; padding: 11px 16px; text-align: left; border-bottom: 1px solid var(--border); background: var(--sand); white-space: nowrap; }
td { font-size: 12.5px; color: var(--body); padding: 12px 16px; border-bottom: 1px solid var(--sand); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--sand); box-shadow: 0 4px 12px rgba(12,63,48,.06); }

/* ── Table cells ─────────────────────────────────────── */
.td-img { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; background: var(--sand); border: 1px solid var(--border); }
.td-title { color: var(--ink); font-weight: 500; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.td-author { color: var(--body); }
.td-views { font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }
.td-date { font-size: 11px; color: var(--muted); white-space: nowrap; }
.featured-star { color: var(--ink); font-size: 13px; margin-left: 4px; }

/* ── Pills ───────────────────────────────────────────── */
.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.pill.published { background: var(--ink); color: var(--bg); }
.pill.draft     { background: var(--sand); color: var(--ink); }

/* ── Action buttons in table ─────────────────────────── */
.td-actions { display: flex; gap: 4px; flex-wrap: wrap; }
.act-btn {
    padding: 5px 10px; font-size: 10.5px; font-weight: 500; border-radius: 7px;
    border: 1px solid var(--border); background: var(--card); color: var(--ink);
    cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .12s; white-space: nowrap;
    text-decoration: none; display: inline-block;
}
.act-btn:hover { border-color: var(--ink); color: var(--ink); }
.act-btn.green:hover { background: var(--sand); }
.act-btn.amber:hover { background: var(--sand); }
.act-btn.blue:hover { background: var(--sand); }
.act-btn.red { color: var(--ink); }
.act-btn.red:hover { background: var(--sand); border-color: var(--border); }

/* ── Empty state ─────────────────────────────────────── */
.empty { text-align: center; padding: 48px 24px; color: var(--muted); font-size: 13px; }

/* ── Pagination ──────────────────────────────────────── */
.pagination { display: flex; align-items: center; justify-content: center; gap: 4px; margin-top: 20px; }
.page-btn {
    padding: 7px 13px; font-size: 11.5px; border: 1px solid var(--border); border-radius: 8px;
    background: var(--card); color: var(--ink); cursor: pointer; font-family: 'DM Sans', sans-serif;
    text-decoration: none; transition: all .12s;
}
.page-btn:hover { border-color: var(--ink); color: var(--ink); }
.page-btn.active { background: var(--ink); color: var(--bg); border-color: var(--ink); }
.page-btn.disabled { opacity: .35; pointer-events: none; }

/* ── Modal ───────────────────────────────────────────── */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .2s; }
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal { background: var(--card); border-radius: 16px; width: 420px; max-width: 92vw; box-shadow: 0 24px 60px rgba(0,0,0,.15); transform: translateY(12px); transition: transform .2s; border: 1px solid var(--border); }
.modal-overlay.open .modal { transform: translateY(0); }
.modal-head { padding: 24px 28px 0; }
.modal-head h3 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--ink); }
.modal-body { padding: 20px 28px; }
.confirm-text { font-size: 13px; color: var(--body); line-height: 1.6; }
.confirm-text strong { color: var(--ink); }
.modal-foot { padding: 0 28px 24px; display: flex; gap: 10px; justify-content: flex-end; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; font-size: 12px; font-weight: 500; border-radius: 10px; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .15s; text-decoration: none; }
.btn-ghost { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--ink); color: var(--ink); }
.btn-danger { background: var(--ink); color: var(--bg); }
.btn-danger:hover { background: #1a503f; }

/* ── Footer ──────────────────────────────────────────── */
.dash-footer { background: var(--ink); padding: 20px 32px; font-size: 11px; color: var(--bg); margin-top: 12px; text-align: center; }

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
@media (max-width: 768px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; padding: 0 16px; }
    .content { padding: 16px; }
    td, th { padding: 8px 10px; }
    .td-actions { flex-direction: column; }
    .filters input[type="text"] { width: 100%; }
    .hide-mobile { display: none !important; }
    .card { overflow-x: auto; }
    table { min-width: 600px; }
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
    <a href="index.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Overview
    </a>
    <div class="sidebar-section">Content</div>
    <a href="artworks.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
        Artworks
    </a>
    <a href="artists.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        Artists
    </a>
    <a href="blogs.php" class="nav-item active">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/><path d="M17 16l2 2"/></svg>
        Blog Posts
    </a>
    <a href="categories.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h7"/></svg>
        Categories
    </a>
    <div class="sidebar-section">Requests</div>
    <a href="inquiries.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        Buyer Inquiries
    </a>
    <a href="commissions.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Commissions
    </a>
    <a href="messages.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v13H4z"/><path d="M4 4l8 9 8-9"/></svg>
        Messages
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
        <h1>Blog Posts</h1>
        <div class="sub">Manage all blog content</div>
    </div>
    <div class="topbar-right">
        <button class="ham-btn" onclick="openDrawer()"><span></span><span></span><span></span></button>
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

    <!-- Toast -->
    <?php if ($toast): ?>
    <div class="toast">
        <span><?= htmlspecialchars($toast) ?></span>
        <button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Status tabs + Create button -->
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
        <div class="tabs" style="margin-bottom:0;">
            <a href="?<?= buildQS(['status' => null]) ?>" class="tab <?= !$statusFilter ? 'active' : '' ?>">
                All <span class="count"><?= $statusCounts['all'] ?></span>
            </a>
            <?php foreach (['draft','published'] as $s): ?>
            <a href="?<?= buildQS(['status' => $s]) ?>" class="tab <?= $statusFilter === $s ? 'active' : '' ?>">
                <?= ucfirst($s) ?>
                <span class="count"><?= $statusCounts[$s] ?></span>
            </a>
            <?php endforeach; ?>
            <a href="?<?= buildQS(['featured' => '1', 'status' => null]) ?>" class="tab <?= (isset($_GET['featured']) && $_GET['featured'] === '1' && !$statusFilter) ? 'active' : '' ?>">
                ★ Featured
            </a>
        </div>
        <a href="blog-create.php" class="create-btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create New Post
        </a>
    </div>

    <!-- Filters -->
    <div class="filters">
        <input type="text" name="q" placeholder="Search title or author..." value="<?= htmlspecialchars($search) ?>" id="searchInput">
        <select id="sortSelect">
            <option value="newest" <?= ($_GET['sort'] ?? '') === 'newest' || !isset($_GET['sort']) ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest" <?= ($_GET['sort'] ?? '') === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
            <option value="views_high" <?= ($_GET['sort'] ?? '') === 'views_high' ? 'selected' : '' ?>>Most views</option>
            <option value="views_low" <?= ($_GET['sort'] ?? '') === 'views_low' ? 'selected' : '' ?>>Fewest views</option>
            <option value="title" <?= ($_GET['sort'] ?? '') === 'title' ? 'selected' : '' ?>>Title: A–Z</option>
        </select>
        <?php if ($statusFilter || $search || isset($_GET['featured'])): ?>
            <button class="clear-link" onclick="window.location.href='blogs.php'">Clear all</button>
        <?php endif; ?>
    </div>

    <!-- Results info -->
    <div class="results-info">
        <div class="left">Showing <?= count($posts) ?> of <?= $totalResults ?> posts</div>
        <div class="right">Page <?= $page ?> of <?= $totalPages ?></div>
    </div>

    <!-- Table -->
    <div class="card">
        <?php if (empty($posts)): ?>
            <div class="empty">No blog posts found matching your filters.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th></th>
                    <th>Title</th>
                    <th class="hide-mobile">Author</th>
                    <th>Status</th>
                    <th class="hide-mobile">Views</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($posts as $p): ?>
                <tr>
                    <td>
                        <?php if ($p['featured_image']): ?>
                            <img class="td-img" src="../../<?= htmlspecialchars($p['featured_image']) ?>" alt="">
                        <?php else: ?>
                            <div class="td-img" style="display:flex;align-items:center;justify-content:center;color:var(--ink);font-size:10px;">No img</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="td-title" title="<?= htmlspecialchars($p['title']) ?>"><?= htmlspecialchars($p['title']) ?></div>
                        <?php if ($p['is_featured']): ?><span class="featured-star">★</span><?php endif; ?>
                    </td>
                    <td class="hide-mobile td-author"><?= htmlspecialchars($p['author_name']) ?></td>
                    <td><span class="pill <?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span></td>
                    <td class="td-views hide-mobile"><?= number_format($p['views']) ?></td>
                    <td class="td-date"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                    <td>
                        <div class="td-actions">
                            <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button type="submit" class="act-btn green" title="<?= $p['status'] === 'published' ? 'Unpublish' : 'Publish' ?>"><?= $p['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button></form>
                            <form method="POST" style="display:inline"><input type="hidden" name="action" value="feature"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button type="submit" class="act-btn amber" title="Toggle featured"><?= $p['is_featured'] ? 'Unfeature' : 'Feature' ?></button></form>
                            <a href="blog-edit.php?id=<?= $p['id'] ?>" class="act-btn blue" title="Edit post">Edit</a>
                            <button type="button" class="act-btn red" onclick="openDelete(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['title'])) ?>')" title="Delete permanently">Delete</button>
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
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        if ($start > 1) { echo '<a href="?'.buildQS(['page'=>1]).'" class="page-btn">1</a>'; if ($start > 2) echo '<span class="page-btn disabled">...</span>'; }
        for ($i = $start; $i <= $end; $i++) {
            echo '<a href="?'.buildQS(['page'=>$i]).'" class="page-btn '.($i === $page ? 'active' : '').'">'.$i.'</a>';
        }
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

<!-- ══════════════ DELETE MODAL ══════════════ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-head"><h3>Delete Blog Post</h3></div>
        <div class="modal-body">
            <p class="confirm-text">Are you sure you want to permanently delete <strong id="deleteName"></strong>? This will also remove the featured image. This cannot be undone.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="closeDelete()">Cancel</button>
                <button type="submit" class="btn btn-danger">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

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
        <a href="blogs.php">Blog Posts</a>
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

// Filter triggers
let searchTimer;
document.getElementById('searchInput').addEventListener('keyup', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 400);
});
document.getElementById('sortSelect').addEventListener('change', applyFilters);

function applyFilters() {
    let params = new URLSearchParams(window.location.search);
    let q = document.getElementById('searchInput').value.trim();
    let sort = document.getElementById('sortSelect').value;

    if (q) params.set('q', q); else params.delete('q');
    if (sort) params.set('sort', sort); else params.delete('sort');
    params.delete('page');
    window.location.href = 'blogs.php?' + params.toString();
}

// Delete modal
function openDelete(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDelete() {
    document.getElementById('deleteModal').classList.remove('open');
}

// Close modal on overlay click
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDelete();
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDelete();
});
</script>
</body>
</html>