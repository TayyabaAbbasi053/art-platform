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

// Approve artist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE users SET status = 'active' WHERE id = $id AND role = 'artist'");
        $toast = 'Artist approved and activated.';
    }
}

// Block artist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'block') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE users SET status = 'blocked' WHERE id = $id AND role = 'artist'");
        $toast = 'Artist blocked.';
    }
}

// Unblock artist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unblock') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE users SET status = 'active' WHERE id = $id AND role = 'artist'");
        $toast = 'Artist unblocked.';
    }
}

// Toggle featured
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'feature') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE artist_profiles SET is_featured = IF(is_featured=1, 0, 1) WHERE user_id = $id");
        $toast = 'Featured status updated.';
    }
}

// Delete artist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        // Delete artist's artwork images from disk
        $imgs = $conn->query("SELECT ai.image_path FROM artwork_images ai JOIN artworks a ON a.id = ai.artwork_id WHERE a.artist_id = $id");
        $uploadDir = __DIR__ . '/../../uploads/artworks/';
        while ($img = $imgs->fetch_assoc()) {
            $f = $uploadDir . $img['image_path'];
            if (file_exists($f)) unlink($f);
        }
        // Delete artist profile picture
        $pp = $conn->query("SELECT profile_picture FROM users WHERE id = $id")->fetch_assoc();
        if ($pp && $pp['profile_picture']) {
            $pf = __DIR__ . '/../../uploads/profiles/' . $pp['profile_picture'];
            if (file_exists($pf)) unlink($pf);
        }
        
        // NOTE: We do NOT delete orders here to preserve purchase history for buyers.
        // We only delete the artist's account and their content.
        
        $conn->query("DELETE FROM artwork_images WHERE artwork_id IN (SELECT id FROM artworks WHERE artist_id = $id)");
        $conn->query("DELETE FROM order_items WHERE item_type='artwork' AND item_id IN (SELECT id FROM artworks WHERE artist_id = $id)");
        $conn->query("DELETE FROM artworks WHERE artist_id = $id");
        $conn->query("DELETE FROM artist_profiles WHERE user_id = $id");
        $conn->query("DELETE FROM users WHERE id = $id AND role = 'artist'");
        $toast = 'Artist and their data deleted. Order history preserved.';
    }
}

// ── Build query ─────────────────────────────────────────
 $where   = ["u.role = 'artist'"];
 $params  = [];
 $types   = '';

 $statusFilter = $_GET['status'] ?? '';
if (in_array($statusFilter, ['active','pending','blocked'])) {
    $where[] = "u.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

if (isset($_GET['featured']) && $_GET['featured'] === '1') {
    $where[] = "ap.is_featured = 1";
}

 $search = trim($_GET['q'] ?? '');
if ($search) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR ap.city LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

 $whereSQL = implode(' AND ', $where);

 $sortMap = [
    'newest'  => 'u.created_at DESC',
    'oldest'  => 'u.created_at ASC',
    'name'    => 'u.name ASC',
    'artworks'=> 'art_count DESC',
];
 $sortBy = $sortMap[$_GET['sort'] ?? ''] ?? 'u.created_at DESC';

 $page    = max(1, (int)($_GET['page'] ?? 1));
 $perPage = 15;
 $offset  = ($page - 1) * $perPage;

// Count
 $countSQL = "SELECT COUNT(*) FROM users u LEFT JOIN artist_profiles ap ON ap.user_id = u.id WHERE $whereSQL";
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
 $dataSQL = "
    SELECT u.id, u.name, u.email, u.phone, u.status, u.profile_picture, u.created_at,
           ap.city, ap.art_style, ap.accepts_commissions, ap.is_featured,
           (SELECT COUNT(*) FROM artworks WHERE artist_id = u.id) AS art_count,
           (SELECT COUNT(*) FROM artworks WHERE artist_id = u.id AND status = 'approved') AS approved_count,
           (SELECT COUNT(*) FROM artworks WHERE artist_id = u.id AND status = 'sold') AS sold_count
    FROM users u
    LEFT JOIN artist_profiles ap ON ap.user_id = u.id
    WHERE $whereSQL
    ORDER BY $sortBy
    LIMIT $perPage OFFSET $offset
";
 $artists = [];
if ($params) {
    $stmt = $conn->prepare($dataSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($dataSQL);
}
while ($row = $res->fetch_assoc()) $artists[] = $row;

// Status counts
 $statusCounts = [];
foreach (['active','pending','blocked'] as $s) {
    $r = $conn->query("SELECT COUNT(*) FROM users WHERE role='artist' AND status='$s'");
    $statusCounts[$s] = (int)$r->fetch_row()[0];
}
 $statusCounts['all'] = array_sum($statusCounts);

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
<title>Artists — Art Bazaar Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --bg: #F6EDDE; --card: #F6EDDE; --sand: #DDCDAE; --border: #0C3F30;
    --ink: #0C3F30; --body: #0C3F30; --muted: #0C3F30; --light: #0C3F30;
    --r: 16px;
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }

/* ── Sidebar ─────────────────────────────────────────── */
.sidebar {
    position: fixed; top: 0; left: 0;
    width: var(--sidebar); height: 100vh;
    background: var(--ink);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    z-index: 100;
    overflow-y: auto;
}
.sidebar-brand {
    padding: 22px 24px 18px;
    border-bottom: 1px solid var(--border);
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
    color: var(--ink);
    background: rgba(255,255,255,0.3);
    border-left-color: var(--sand);
}
.nav-item.active {
    color: var(--ink);
    background: var(--sand);
    font-weight: 500;
    border-left-color: var(--sand);
}
.nav-item .icon {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    opacity: .55;
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
    background: var(--sand);
    color: var(--ink);
}
.sidebar-bottom {
    margin-top: auto;
    padding: 16px;
    border-top: 1px solid var(--border);
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
    color: var(--sand);
}

/* ── Topbar ──────────────────────────────────────────── */
.topbar {
    position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top);
    background: var(--ink);
    border-bottom: 1px solid var(--border);
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
    background: var(--sand);
    border: 1px solid var(--border);
    padding: 5px 12px 5px 5px;
    border-radius: 30px;
}
.admin-chip .avatar {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--ink);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    color: var(--bg);
    font-weight: 600;
}
.admin-chip .name {
    font-size: 12px;
    color: var(--ink);
    font-weight: 500;
}
.admin-chip .arrow {
    font-size: 12px;
    color: var(--ink);
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
.toast.error { background: var(--sand); color: var(--ink); border-color: var(--border); }
.toast.hidden { display: none; }
.toast-close { background: none; border: none; color: var(--ink); cursor: pointer; font-size: 16px; }
.toast-close:hover { color: var(--ink); }

/* ── Tabs ────────────────────────────────────────────── */
.tabs { display: flex; gap: 4px; margin-bottom: 20px; flex-wrap: wrap; }
.tab {
    display: flex; align-items: center; gap: 6px; padding: 8px 16px;
    font-size: 11.5px; color: var(--ink); text-decoration: none; border-radius: 10px;
    border: 1px solid transparent; transition: all .15s; font-weight: 400;
    background: none; cursor: pointer; font-family: 'DM Sans', sans-serif;
}
.tab:hover { background: var(--sand); color: var(--ink); }
.tab.active { background: var(--ink); color: var(--bg); font-weight: 500; }
.tab .count { font-size: 10px; font-weight: 600; background: var(--sand); padding: 1px 7px; border-radius: 20px; color: var(--ink); }
.tab.active .count { background: var(--bg); color: var(--ink); }
.tab .count.hot { background: var(--sand); color: var(--ink); }
.tab.active .count.hot { background: var(--bg); }

/* ── Filters ─────────────────────────────────────────── */
.filters { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
.filters input[type="text"], .filters select {
    padding: 8px 14px; border: 1.5px solid var(--sand); border-radius: 9px;
    font-size: 12px; font-family: 'DM Sans', sans-serif; color: var(--ink);
    background: var(--bg); outline: none; transition: border-color .15s;
}
.filters input:focus, .filters select:focus { border-color: var(--ink); }
.filters input { width: 240px; }
.filters select { min-width: 150px; cursor: pointer; }
.clear-link { font-size: 11px; color: var(--ink); text-decoration: none; cursor: pointer; background: none; border: none; font-family: 'DM Sans', sans-serif; }
.clear-link:hover { color: var(--ink); }

/* ── Results info ────────────────────────────────────── */
.results-info { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; font-size: 11px; color: var(--muted); }

/* ── Card & Table ────────────────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--r); overflow: hidden; }
.card table { border-radius: 0 0 var(--r) var(--r); overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; padding: 11px 16px; text-align: left; border-bottom: 1px solid var(--border); background: var(--sand); white-space: nowrap; }
td { font-size: 12.5px; color: var(--body); padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--bg); box-shadow: 0 4px 12px rgba(12,63,48,.06); }

/* ── Table cells ─────────────────────────────────────── */
.td-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; background: var(--sand); border: 1px solid var(--border); }
.td-avatar-placeholder { width: 36px; height: 36px; border-radius: 50%; background: var(--ink); display: flex; align-items: center; justify-content: center; font-size: 13px; color: var(--bg); font-weight: 600; }
.td-name { color: var(--ink); font-weight: 500; }
.td-name a { color: var(--ink); text-decoration: none; }
.td-name a:hover { text-decoration: underline; }
.td-email { font-size: 11px; color: var(--muted); }
.td-stats { display: flex; gap: 10px; font-size: 11px; flex-wrap: wrap; }
.td-stats span { white-space: nowrap; }
.td-stats .num { font-weight: 600; color: var(--ink); }
.td-city { font-size: 11px; color: var(--muted); }
.featured-star { color: var(--ink); font-size: 13px; margin-left: 4px; }

/* ── Pills ───────────────────────────────────────────── */
.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.pill.active { background: var(--ink); color: var(--bg); }
.pill.pending { background: var(--sand); color: var(--ink); }
.pill.blocked { background: var(--sand); color: var(--ink); }

/* ── Action buttons ──────────────────────────────────── */
.td-actions { display: flex; gap: 4px; flex-wrap: wrap; }
.act-btn {
    padding: 5px 10px; font-size: 10.5px; font-weight: 500; border-radius: 7px;
    border: 1px solid var(--border); background: var(--white); color: var(--ink);
    cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .12s; white-space: nowrap;
}
.act-btn:hover { border-color: var(--ink); color: var(--ink); }
.act-btn.green:hover { border-color: var(--ink); color: var(--ink); background: #e0e0e0; } /* Fallback hover, acting as approve */
.act-btn.approve { background: var(--ink); color: var(--bg); border: 1px solid var(--ink); }
.act-btn.approve:hover { background: #1a4d3e; }

.act-btn.red { border: 1px solid var(--border); background: transparent; color: var(--ink); }
.act-btn.red:hover { background: var(--sand); color: var(--ink); }
.act-btn.amber { background: var(--sand); color: var(--ink); border: 1px solid var(--border); }
.act-btn.amber:hover { background: #c4b69e; }

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
.modal-overlay { position: fixed; inset: 0; background: rgba(12,63,48,.4); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .2s; }
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal { background: var(--card); border-radius: 16px; width: 420px; max-width: 92vw; box-shadow: 0 24px 60px rgba(0,0,0,.15); border: 1px solid var(--border); }
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
.btn-danger:hover { background: #1a4d3e; }

/* ── Footer ──────────────────────────────────────────── */
.dash-footer { padding: 20px 32px; border-top: 1px solid var(--border); font-size: 11px; color: var(--bg); margin-top: 12px; background: var(--ink); }

/* ── Drawer & Mobile Responsive ───────────────────────── */
#nav-drawer{display:none;}
#nav-overlay{display:none;}
.ham-btn{display:none;}

@media(max-width:1080px){
    .content { padding: 24px; }
}

@media(max-width:768px){
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; }
    .content { padding: 16px; }
    
    .ham-btn{display:inline-block;width:30px;height:24px;position:relative;background:none;border:none;cursor:pointer;z-index:2000;}
    .ham-btn span{position:absolute;display:block;width:100%;height:2px;background:var(--bg);border-radius:2px;transition:all .3s;opacity:1;left:0;}
    .ham-btn span:nth-child(1){top:2px;}
    .ham-btn span:nth-child(2){top:10px;}
    .ham-btn span:nth-child(3){top:18px;}
    
    .open #nav-drawer{display:block;position:fixed;top:0;right:0;width:80%;height:100%;background:var(--ink);z-index:1001;padding:40px 20px;box-shadow:-5px 0 15px rgba(0,0,0,0.1);transition:right 0.3s ease;}
    .open #nav-overlay{display:block;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;}
    
    #nav-drawer a { display: block; padding: 15px 0; color: var(--bg); font-size: 16px; border-bottom: 1px solid rgba(255,255,255,0.1); }
    
    .filters input { width: 100%; }
    .filters { flex-direction: column; align-items: stretch; }
    .tabs { overflow-x: auto; flex-wrap: nowrap; white-space: nowrap; }
    
    /* Table to Cards */
    table, thead, tbody, th, td, tr { display: block; }
    thead { display: none; }
    tr { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    td { padding: 8px 0; border: none; display: flex; justify-content: space-between; align-items: center; }
    td:before { content: attr(data-label); font-weight: 600; font-size: 11px; text-transform: uppercase; color: var(--muted); flex: 1; }
    .td-actions { flex-direction: column; width: 100%; margin-top: 10px; }
    .td-actions button, .td-actions form { width: 100%; }
    .td-actions .act-btn { width: 100%; justify-content: center; }
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
    <a href="artists.php" class="nav-item active">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        Artists
        <?php if ($statusCounts['pending'] > 0): ?><span class="badge amber"><?= $statusCounts['pending'] ?></span><?php endif; ?>
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
        <h1>Artists</h1>
        <div class="sub">Manage artist accounts</div>
    </div>
    <div class="topbar-right">
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

    <?php if ($toast): ?>
    <div class="toast <?= strpos($toast, 'provide') !== false ? 'error' : '' ?>">
        <span><?= htmlspecialchars($toast) ?></span>
        <button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?<?= buildQS(['status' => null, 'featured' => null]) ?>" class="tab <?= !$statusFilter && !isset($_GET['featured']) ? 'active' : '' ?>">All <span class="count"><?= $statusCounts['all'] ?></span></a>
        <?php foreach (['active','pending','blocked'] as $s): ?>
        <a href="?<?= buildQS(['status' => $s, 'featured' => null]) ?>" class="tab <?= $statusFilter === $s ? 'active' : '' ?>"><?= ucfirst($s) ?> <span class="count <?= ($s === 'pending' && $statusCounts[$s] > 0) ? 'hot' : '' ?>"><?= $statusCounts[$s] ?></span></a>
        <?php endforeach; ?>
        <a href="?<?= buildQS(['featured' => '1', 'status' => null]) ?>" class="tab <?= (isset($_GET['featured']) && $_GET['featured'] === '1' && !$statusFilter) ? 'active' : '' ?>">★ Featured</a>
    </div>

    <!-- Filters -->
    <div class="filters">
        <input type="text" placeholder="Search name, email, city..." value="<?= htmlspecialchars($search) ?>" id="searchInput">
        <select id="sortSelect">
            <option value="newest" <?= ($_GET['sort'] ?? '') === 'newest' || !isset($_GET['sort']) ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest" <?= ($_GET['sort'] ?? '') === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
            <option value="name" <?= ($_GET['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Name A–Z</option>
            <option value="artworks" <?= ($_GET['sort'] ?? '') === 'artworks' ? 'selected' : '' ?>>Most artworks</option>
        </select>
        <?php if ($statusFilter || $search || isset($_GET['featured'])): ?>
            <button class="clear-link" onclick="window.location.href='artists.php'">Clear all</button>
        <?php endif; ?>
    </div>

    <!-- Results info -->
    <div class="results-info">
        <div>Showing <?= count($artists) ?> of <?= $totalResults ?> artists</div>
        <div>Page <?= $page ?> of <?= $totalPages ?></div>
    </div>

    <!-- Table -->
    <div class="card">
        <?php if (empty($artists)): ?>
            <div class="empty">No artists found.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th></th>
                    <th>Artist</th>
                    <th class="hide-mobile">City</th>
                    <th>Artworks</th>
                    <th class="hide-mobile">Joined</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($artists as $a): ?>
                <tr>
                    <td data-label="">
                        <?php if ($a['profile_picture']): ?>
                            <img class="td-avatar" src="../../<?= htmlspecialchars($a['profile_picture']) ?>" alt="">
                        <?php else: ?>
                            <div class="td-avatar-placeholder"><?= strtoupper(substr($a['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Artist">
                        <div class="td-name">
                            <a href="artist-view.php?id=<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></a>
                            <?php if ($a['is_featured']): ?><span class="featured-star">★</span><?php endif; ?>
                        </div>
                        <div class="td-email"><?= htmlspecialchars($a['email']) ?></div>
                    </td>
                    <td class="td-city hide-mobile" data-label="City"><?= htmlspecialchars($a['city'] ?? '—') ?></td>
                    <td data-label="Artworks">
                        <div class="td-stats">
                            <span><span class="num"><?= $a['approved_count'] ?></span> approved</span>
                            <span><span class="num"><?= $a['sold_count'] ?></span> sold</span>
                            <span><span class="num"><?= $a['art_count'] - $a['approved_count'] - $a['sold_count'] ?></span> other</span>
                        </div>
                    </td>
                    <td class="hide-mobile" style="font-size:11px;color:var(--muted);white-space:nowrap" data-label="Joined"><?= date('d M Y', strtotime($a['created_at'])) ?></td>
                    <td data-label="Status"><span class="pill <?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span></td>
                    <td data-label="Actions">
                        <div class="td-actions">
                            <?php if ($a['status'] === 'pending'): ?>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button type="submit" class="act-btn approve">Approve</button></form>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="block"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button type="submit" class="act-btn red">Block</button></form>
                            <?php elseif ($a['status'] === 'active'): ?>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="feature"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button type="submit" class="act-btn amber"><?= $a['is_featured'] ? 'Unfeature' : 'Feature' ?></button></form>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="block"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button type="submit" class="act-btn red">Block</button></form>
                            <?php elseif ($a['status'] === 'blocked'): ?>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="unblock"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button type="submit" class="act-btn approve">Unblock</button></form>
                            <?php endif; ?>
                            <button type="button" class="act-btn red" onclick="openDelete(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['name'])) ?>', <?= $a['art_count'] ?>)">Delete</button>
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

<!-- ══════════════ DELETE MODAL ══════════════ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-head"><h3>Delete Artist</h3></div>
        <div class="modal-body">
            <p class="confirm-text">Are you sure you want to permanently delete <strong id="deleteName"></strong>? This artist has <strong id="deleteCount">0</strong> artwork(s). All their artworks, images, and profile data will be permanently removed. Order history will be preserved. This cannot be undone.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="closeDelete()">Cancel</button>
                <button type="submit" class="btn btn-danger">Yes, Delete Permanently</button>
            </div>
        </form>
    </div>
</div>

<!-- MOBILE DRAWER & OVERLAY -->
<div id="nav-drawer">
    <div style="margin-bottom: 30px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 20px;">
        <h2 style="color:var(--bg); font-family:'Playfair Display',serif;">Menu</h2>
    </div>
    <a href="index.php">Overview</a>
    <a href="artworks.php">Artworks</a>
    <a href="artists.php">Artists</a>
    <a href="categories.php">Categories</a>
    <a href="inquiries.php">Buyer Inquiries</a>
    <a href="commissions.php">Commissions</a>
    <a href="messages.php">Messages</a>
    <div style="margin-top: 40px;">
        <a href="../../logout.php" style="display:inline-block; padding: 10px 20px; background:var(--sand); color:var(--ink); border-radius:30px; font-weight:600;">Sign Out</a>
    </div>
</div>
<div id="nav-overlay"></div>

<script>
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
    window.location.href = 'artists.php?' + params.toString();
}

function openDelete(id, name, count) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteCount').textContent = count;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDelete() { document.getElementById('deleteModal').classList.remove('open'); }
document.getElementById('deleteModal').addEventListener('click', function(e) { if (e.target === this) closeDelete(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeDelete(); });

// ── Drawer Logic ───────────────────────────────────────
const drawer = document.querySelector('body');
const overlay = document.getElementById('nav-overlay');

// Inject Hamburger if not present (Mobile only)
if(window.innerWidth <= 768 && !document.querySelector('.ham-btn')){
    const topbarRight = document.querySelector('.topbar-right');
    if(topbarRight){
        const btn = document.createElement('button');
        btn.className = 'ham-btn';
        btn.innerHTML = '<span></span><span></span><span></span>';
        topbarRight.insertBefore(btn, topbarRight.firstChild);
        
        btn.addEventListener('click', () => {
            drawer.classList.toggle('open');
        });
    }
}

overlay.addEventListener('click', () => {
    drawer.classList.remove('open');
});
</script>
</body>
</html>