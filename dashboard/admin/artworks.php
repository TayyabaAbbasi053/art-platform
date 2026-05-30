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

// Approve
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE artworks SET status = 'approved', rejection_reason = NULL WHERE id = $id");
        $toast = 'Artwork approved.';
    }
}

// Reject (Now with Reason)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    $id = (int)($_POST['id'] ?? 0);
    $reason = trim($_POST['rejection_reason'] ?? '');
    
    if ($id && !empty($reason)) {
        $stmt = $conn->prepare("UPDATE artworks SET status = 'rejected', rejection_reason = ? WHERE id = ?");
        $stmt->bind_param('si', $reason, $id);
        $stmt->execute();
        $toast = 'Artwork rejected. Artist will be notified.';
    } elseif (empty($reason)) {
        $toast = 'Please provide a rejection reason.';
    }
}

// Toggle featured
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'feature') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE artworks SET is_featured = IF(is_featured=1, 0, 1) WHERE id = $id");
        $toast = 'Featured status updated.';
    }
}

// Hide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hide') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE artworks SET status = 'hidden' WHERE id = $id");
        $toast = 'Artwork hidden.';
    }
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        // Delete images first
        $imgs = $conn->query("SELECT image_path FROM artwork_images WHERE artwork_id = $id");
        $uploadDir = __DIR__ . '/../../uploads/artworks/';
        while ($img = $imgs->fetch_assoc()) {
            $filePath = __DIR__ . '/../../' . $img['image_path'];
            if (file_exists($filePath)) unlink($filePath);
        }
        $conn->query("DELETE FROM artwork_images WHERE artwork_id = $id");
        $conn->query("DELETE FROM buyer_inquiries WHERE artwork_id = $id");
        $conn->query("DELETE FROM artworks WHERE id = $id");
        $toast = 'Artwork deleted permanently.';
    }
}

// ── Build query with filters ───────────────────────────
$where   = ["1=1"];
$params  = [];
$types   = '';

// Status filter
$statusFilter = $_GET['status'] ?? '';
if (in_array($statusFilter, ['pending','approved','rejected','sold','hidden'])) {
    $where[] = "a.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

// Category filter
$catFilter = (int)($_GET['category'] ?? 0);
if ($catFilter > 0) {
    $where[] = "a.category_id = ?";
    $params[] = $catFilter;
    $types .= 'i';
}

// Featured filter
if (isset($_GET['featured']) && $_GET['featured'] === '1') {
    $where[] = "a.is_featured = 1";
}

// Search
$search = trim($_GET['q'] ?? '');
if ($search) {
    $where[] = "(a.title LIKE ? OR u.name LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

$whereSQL = implode(' AND ', $where);

// Sort
$sortMap = [
    'newest'  => 'a.created_at DESC',
    'oldest'  => 'a.created_at ASC',
    'price_high' => 'a.price DESC',
    'price_low'  => 'a.price ASC',
    'title'   => 'a.title ASC',
];
$sortBy = $sortMap[$_GET['sort'] ?? ''] ?? 'a.created_at DESC';

// Pagination
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 15;
$offset   = ($page - 1) * $perPage;

// Count total
$countSQL = "SELECT COUNT(*) FROM artworks a JOIN users u ON u.id = a.artist_id WHERE $whereSQL";
if ($params) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalResults = (int)$stmt->get_result()->fetch_row()[0];
} else {
    $totalResults = (int)$conn->query($countSQL)->fetch_row()[0];
}
$totalPages = max(1, ceil($totalResults / $perPage));

// Fetch artworks
$dataSQL = "
    SELECT a.*, c.name AS category_name, u.name AS artist_name,
           (SELECT image_path FROM artwork_images WHERE artwork_id = a.id AND is_cover = 1 LIMIT 1) AS cover_image
    FROM artworks a
    JOIN users u ON u.id = a.artist_id
    JOIN categories c ON c.id = a.category_id
    WHERE $whereSQL
    ORDER BY $sortBy
    LIMIT $perPage OFFSET $offset
";
$artworks = [];
if ($params) {
    $stmt = $conn->prepare($dataSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($dataSQL);
}
while ($row = $res->fetch_assoc()) $artworks[] = $row;

// Fetch categories for filter dropdown
$categories = [];
$catRes = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $catRes->fetch_assoc()) $categories[] = $row;

// Status counts for tabs
$statusCounts = [];
foreach (['pending','approved','rejected','sold','hidden'] as $s) {
    $r = $conn->query("SELECT COUNT(*) FROM artworks WHERE status='$s'");
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
<title>Artworks — Art Bazaar Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --black: #1E1B18;
    --grey1: #F7F1E8;
    --grey2: #E6DDD0;
    --grey3: #D6CDBF;
    --grey4: #8A7D72;
    --grey5: #3D332A;
    --white: #FFFDF8;
    --red: #C96B4B;
    --green: #6BA58D;
    --amber: #E48A4A;
    --blue: #0984e3;
    --purple: #5e35b1;
    --terracotta: #C96B4B;
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--grey1); color: var(--black); font-family: 'DM Sans', sans-serif; }

/* ── Sidebar ─────────────────────────────────────────── */
.sidebar {
    position: fixed; top: 0; left: 0;
    width: var(--sidebar); height: 100vh;
    background: #EFE3D2;
    border-right: 1px solid var(--grey2);
    display: flex; flex-direction: column;
    z-index: 100;
    overflow-y: auto;
}
.sidebar-brand {
    padding: 22px 24px 18px;
    border-bottom: 1px solid var(--grey2);
}
.sidebar-brand .logo-text {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 500;
    color: var(--black);
}
.sidebar-brand .logo-tag {
    font-size: 8px;
    letter-spacing: 2px;
    color: var(--grey4);
    margin-top: 2px;
}
.sidebar-brand .logo-badge {
    display: inline-block;
    margin-left: 6px;
    background: var(--terracotta);
    color: var(--white);
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
    color: var(--grey4);
    font-weight: 500;
}
.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    font-size: 12.5px;
    color: var(--grey5);
    text-decoration: none;
    font-weight: 400;
    border-left: 2px solid transparent;
    transition: all .15s;
    position: relative;
}
.nav-item:hover {
    color: var(--black);
    background: rgba(255,255,255,0.3);
    border-left-color: var(--grey3);
}
.nav-item.active {
    color: var(--black);
    background: rgba(255,255,255,0.4);
    border-left-color: var(--terracotta);
    font-weight: 500;
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
    background: var(--terracotta);
    color: #fff;
    font-size: 9px;
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 20px;
    min-width: 18px;
    text-align: center;
}
.badge.amber {
    background: var(--amber);
}
.sidebar-bottom {
    margin-top: auto;
    padding: 16px;
    border-top: 1px solid var(--grey2);
}
.signout-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 12px;
    font-size: 12px;
    color: var(--grey5);
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
    background: rgba(255,255,255,0.3);
    color: var(--terracotta);
}

/* ── Topbar ──────────────────────────────────────────── */
.topbar {
    position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top);
    background: var(--white);
    border-bottom: 1px solid var(--grey2);
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
    color: var(--black);
}
.topbar-left .sub {
    font-size: 11px;
    color: var(--grey4);
    margin-top: 1px;
}
.admin-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--grey1);
    border: 1px solid var(--grey2);
    padding: 5px 12px 5px 5px;
    border-radius: 30px;
}
.admin-chip .avatar {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--terracotta);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    color: #fff;
    font-weight: 600;
}
.admin-chip .name {
    font-size: 12px;
    color: var(--black);
    font-weight: 500;
}
.admin-chip .arrow {
    font-size: 12px;
    color: var(--grey4);
    margin-left: 4px;
}

/* ── Main ────────────────────────────────────────────── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 28px 32px; }

/* ── Toast ───────────────────────────────────────────── */
.toast {
    background: #FCEEE2;
    color: var(--black);
    border: 1px solid var(--grey2);
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 12.5px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.toast.error { background: #FDEAEA; color: #D46A6A; border-color: #F5C6C6; }
.toast.hidden { display: none; }
.toast-close { background: none; border: none; color: var(--grey4); cursor: pointer; font-size: 16px; }
.toast-close:hover { color: var(--black); }

/* ── Status tabs ─────────────────────────────────────── */
.tabs { display: flex; gap: 4px; margin-bottom: 20px; flex-wrap: wrap; }
.tab {
    display: flex; align-items: center; gap: 6px; padding: 8px 16px;
    font-size: 11.5px; color: var(--grey5); text-decoration: none; border-radius: 10px;
    border: 1px solid transparent; transition: all .15s; font-weight: 400;
    background: none; cursor: pointer; font-family: 'DM Sans', sans-serif;
}
.tab:hover { background: var(--white); border-color: var(--grey2); color: var(--black); }
.tab.active { background: var(--white); border-color: var(--black); color: var(--black); font-weight: 500; }
.tab .count { font-size: 10px; font-weight: 600; background: var(--grey2); padding: 1px 7px; border-radius: 20px; color: var(--grey5); }
.tab.active .count { background: var(--black); color: #fff; }
.tab .count.hot { background: var(--amber); color: #fff; }
.tab.active .count.hot { background: var(--amber); }

/* ── Filters bar ─────────────────────────────────────── */
.filters { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
.filters select, .filters input[type="text"] {
    padding: 8px 14px; border: 1px solid var(--grey2); border-radius: 9px;
    font-size: 12px; font-family: 'DM Sans', sans-serif; color: var(--black);
    background: var(--white); outline: none; transition: border-color .15s;
}
.filters select:focus, .filters input:focus { border-color: var(--black); }
.filters select { min-width: 150px; cursor: pointer; }
.filters input[type="text"] { width: 220px; }
.filter-sep { width: 1px; height: 24px; background: var(--grey2); }
.clear-link { font-size: 11px; color: var(--grey4); text-decoration: none; cursor: pointer; background: none; border: none; font-family: 'DM Sans', sans-serif; }
.clear-link:hover { color: var(--terracotta); }

/* ── Results info ────────────────────────────────────── */
.results-info { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.results-info .left { font-size: 11px; color: var(--grey4); }
.results-info .right { font-size: 11px; color: var(--grey4); }

/* ── Table ───────────────────────────────────────────── */
.card { background: var(--white); border: 1px solid var(--grey2); border-radius: 14px; overflow: hidden; }
.card table { border-radius: 0 0 14px 14px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; padding: 11px 16px; text-align: left; border-bottom: 1px solid var(--grey2); background: var(--grey1); white-space: nowrap; }
td { font-size: 12.5px; color: var(--grey5); padding: 12px 16px; border-bottom: 1px solid var(--grey2); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--grey1); }

/* ── Table cells ─────────────────────────────────────── */
.td-img { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; background: var(--grey2); border: 1px solid var(--grey2); }
.td-title { color: var(--black); font-weight: 500; max-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.td-artist { color: var(--grey5); }
.td-price { font-weight: 600; color: var(--black); white-space: nowrap; font-size: 12px; }
.td-city { font-size: 11px; color: var(--grey4); }

/* ── Pills ───────────────────────────────────────────── */
.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.pill.pending   { background: #FFF4E6; color: #E48A4A; }
.pill.approved  { background: #E8F5EE; color: #6BA58D; }
.pill.rejected  { background: #FDEAEA; color: #D46A6A; }
.pill.sold      { background: #EEE8FF; color: #6B5CE6; }
.pill.hidden    { background: #F4F4F4; color: #888; }
.featured-star { color: #E48A4A; font-size: 13px; margin-left: 4px; }

/* ── Action buttons in table ─────────────────────────── */
.td-actions { display: flex; gap: 4px; flex-wrap: wrap; }
.act-btn {
    padding: 5px 10px; font-size: 10.5px; font-weight: 500; border-radius: 7px;
    border: 1px solid var(--grey2); background: var(--white); color: var(--grey5);
    cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .12s; white-space: nowrap;
}
.act-btn:hover { border-color: var(--black); color: var(--black); }
.act-btn.green:hover { border-color: var(--green); color: var(--green); background: #EEF5F0; }
.act-btn.red:hover { border-color: var(--terracotta); color: var(--terracotta); background: #FFF0EC; }
.act-btn.blue:hover { border-color: var(--blue); color: var(--blue); background: #EEF2F8; }
.act-btn.purple:hover { border-color: var(--purple); color: var(--purple); background: #EEE8FF; }
.act-btn.amber:hover { border-color: var(--amber); color: var(--amber); background: #FFF4E6; }

/* ── Empty state ─────────────────────────────────────── */
.empty { text-align: center; padding: 48px 24px; color: var(--grey4); font-size: 13px; }

/* ── Pagination ──────────────────────────────────────── */
.pagination { display: flex; align-items: center; justify-content: center; gap: 4px; margin-top: 20px; }
.page-btn {
    padding: 7px 13px; font-size: 11.5px; border: 1px solid var(--grey2); border-radius: 8px;
    background: var(--white); color: var(--grey5); cursor: pointer; font-family: 'DM Sans', sans-serif;
    text-decoration: none; transition: all .12s;
}
.page-btn:hover { border-color: var(--black); color: var(--black); }
.page-btn.active { background: var(--black); color: #fff; border-color: var(--black); }
.page-btn.disabled { opacity: .35; pointer-events: none; }

/* ── Modals (Delete & Reject) ────────────────────────── */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .2s; }
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal { background: var(--white); border-radius: 16px; width: 420px; max-width: 92vw; box-shadow: 0 24px 60px rgba(0,0,0,.15); transform: translateY(12px); transition: transform .2s; }
.modal-overlay.open .modal { transform: translateY(0); }
.modal-head { padding: 24px 28px 0; }
.modal-head h3 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--black); }
.modal-body { padding: 20px 28px; }
.confirm-text { font-size: 13px; color: var(--grey5); line-height: 1.6; }
.confirm-text strong { color: var(--black); }
.modal-foot { padding: 0 28px 24px; display: flex; gap: 10px; justify-content: flex-end; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; font-size: 12px; font-weight: 500; border-radius: 10px; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .15s; text-decoration: none; }
.btn-ghost { background: transparent; color: var(--grey5); border: 1px solid var(--grey2); }
.btn-ghost:hover { border-color: var(--black); color: var(--black); }
.btn-danger { background: var(--terracotta); color: #fff; }
.btn-danger:hover { background: #B85C3D; }

/* Modal Input */
.modal-input { width: 100%; padding: 10px; border: 1px solid var(--grey2); border-radius: 8px; font-family: 'DM Sans', sans-serif; font-size: 13px; resize: vertical; }
.modal-input:focus { outline: none; border-color: var(--black); }

/* ── Footer ──────────────────────────────────────────── */
.dash-footer { padding: 20px 32px; border-top: 1px solid var(--grey2); font-size: 11px; color: var(--grey4); margin-top: 12px; }

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 900px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; }
    .content { padding: 16px; }
    td, th { padding: 8px 10px; }
    .td-actions { flex-direction: column; }
    .filters input[type="text"] { width: 100%; }
    .hide-mobile { display: none !important; }
}
@media (max-width: 600px) {
    .tabs { gap: 2px; }
    .tab { padding: 6px 10px; font-size: 10.5px; }
    .filters { flex-direction: column; align-items: stretch; }
    .filter-sep { display: none; }
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
    <a href="artworks.php" class="nav-item active">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
        Artworks
        <?php if ($statusCounts['pending'] > 0): ?><span class="badge"><?= $statusCounts['pending'] ?></span><?php endif; ?>
    </a>
    <a href="artists.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        Artists
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
        <h1>Artworks</h1>
        <div class="sub">Manage all submitted artworks</div>
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

    <!-- Toast -->
    <?php if ($toast): ?>
    <div class="toast <?= strpos($toast, 'provide') !== false ? 'error' : '' ?>">
        <span><?= htmlspecialchars($toast) ?></span>
        <button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Status tabs -->
    <div class="tabs">
        <a href="?<?= buildQS(['status' => null]) ?>" class="tab <?= !$statusFilter ? 'active' : '' ?>">
            All <span class="count"><?= $statusCounts['all'] ?></span>
        </a>
        <?php foreach (['pending','approved','rejected','sold','hidden'] as $s): ?>
        <a href="?<?= buildQS(['status' => $s]) ?>" class="tab <?= $statusFilter === $s ? 'active' : '' ?>">
            <?= ucfirst($s) ?>
            <span class="count <?= ($s === 'pending' && $statusCounts[$s] > 0) ? 'hot' : '' ?>"><?= $statusCounts[$s] ?></span>
        </a>
        <?php endforeach; ?>
        <a href="?<?= buildQS(['featured' => '1', 'status' => null]) ?>" class="tab <?= (isset($_GET['featured']) && $_GET['featured'] === '1' && !$statusFilter) ? 'active' : '' ?>">
            ★ Featured
        </a>
    </div>

    <!-- Filters -->
    <div class="filters">
        <input type="text" name="q" placeholder="Search title or artist..." value="<?= htmlspecialchars($search) ?>" id="searchInput">
        <select id="catSelect">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $catFilter === $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="sortSelect">
            <option value="newest" <?= ($_GET['sort'] ?? '') === 'newest' || !isset($_GET['sort']) ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest" <?= ($_GET['sort'] ?? '') === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
            <option value="price_high" <?= ($_GET['sort'] ?? '') === 'price_high' ? 'selected' : '' ?>>Price: high to low</option>
            <option value="price_low" <?= ($_GET['sort'] ?? '') === 'price_low' ? 'selected' : '' ?>>Price: low to high</option>
            <option value="title" <?= ($_GET['sort'] ?? '') === 'title' ? 'selected' : '' ?>>Title: A–Z</option>
        </select>
        <?php if ($statusFilter || $catFilter || $search || isset($_GET['featured'])): ?>
            <button class="clear-link" onclick="window.location.href='artworks.php'">Clear all</button>
        <?php endif; ?>
    </div>

    <!-- Results info -->
    <div class="results-info">
        <div class="left">Showing <?= count($artworks) ?> of <?= $totalResults ?> artworks</div>
        <div class="right">Page <?= $page ?> of <?= $totalPages ?></div>
    </div>

    <!-- Table -->
    <div class="card">
        <?php if (empty($artworks)): ?>
            <div class="empty">No artworks found matching your filters.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th></th>
                    <th>Title</th>
                    <th>Artist</th>
                    <th class="hide-mobile">Category</th>
                    <th>Price</th>
                    <th class="hide-mobile">City</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($artworks as $aw): ?>
                <tr>
                    <td>
                        <?php if ($aw['cover_image']): ?>
                            <img class="td-img" src="../../<?= htmlspecialchars($aw['cover_image']) ?>" alt="">
                        <?php else: ?>
                            <div class="td-img" style="display:flex;align-items:center;justify-content:center;color:var(--grey4);font-size:10px;">No img</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="td-title" title="<?= htmlspecialchars($aw['title']) ?>"><?= htmlspecialchars($aw['title']) ?></div>
                        <?php if ($aw['is_featured']): ?><span class="featured-star">★</span><?php endif; ?>
                    </td>
                    <td><a href="artist-view.php?id=<?= $aw['artist_id'] ?>" style="color:var(--grey5);text-decoration:none;" class="td-artist"><?= htmlspecialchars($aw['artist_name']) ?></a></td>
                    <td class="hide-mobile" style="font-size:12px"><?= htmlspecialchars($aw['category_name']) ?></td>
                    <td class="td-price">PKR <?= number_format($aw['price']) ?></td>
                    <td class="td-city hide-mobile"><?= htmlspecialchars($aw['city'] ?? '—') ?></td>
                    <td><span class="pill <?= $aw['status'] ?>"><?= ucfirst($aw['status']) ?></span></td>
                    <td>
                        <div class="td-actions">
                            <?php if ($aw['status'] === 'pending'): ?>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $aw['id'] ?>"><button type="submit" class="act-btn green" title="Approve">Approve</button></form>
                                <button type="button" class="act-btn red" onclick="openRejectModal(<?= $aw['id'] ?>)" title="Reject">Reject</button>
                            <?php elseif ($aw['status'] === 'approved'): ?>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="feature"><input type="hidden" name="id" value="<?= $aw['id'] ?>"><button type="submit" class="act-btn amber" title="Toggle featured"><?= $aw['is_featured'] ? 'Unfeature' : 'Feature' ?></button></form>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="hide"><input type="hidden" name="id" value="<?= $aw['id'] ?>"><button type="submit" class="act-btn" title="Hide">Hide</button></form>
                            <?php elseif ($aw['status'] === 'rejected' || $aw['status'] === 'hidden'): ?>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $aw['id'] ?>"><button type="submit" class="act-btn green" title="Re-approve">Approve</button></form>
                            <?php endif; ?>
                            <a href="artwork-edit.php?id=<?= $aw['id'] ?>" class="act-btn blue" title="Edit details">Edit</a>
                            <button type="button" class="act-btn red" onclick="openDelete(<?= $aw['id'] ?>, '<?= htmlspecialchars(addslashes($aw['title'])) ?>')" title="Delete permanently">Delete</button>
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
        <div class="modal-head"><h3>Delete Artwork</h3></div>
        <div class="modal-body">
            <p class="confirm-text">Are you sure you want to permanently delete <strong id="deleteName"></strong>? This will also remove all images and buyer inquiries for this artwork. This cannot be undone.</p>
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

<!-- ══════════════ REJECT MODAL ══════════════ -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal">
        <div class="modal-head"><h3>Reject Artwork</h3></div>
        <div class="modal-body">
            <p class="confirm-text" style="margin-bottom:12px;">Please provide a reason for rejection. The artist will see this message.</p>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" id="rejectId">
                <textarea name="rejection_reason" class="modal-input" rows="4" required placeholder="e.g. Low quality images, Wrong category, Copyright issue..."></textarea>
                <div class="modal-foot" style="padding-top:16px;">
                    <button type="button" class="btn btn-ghost" onclick="closeReject()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Artwork</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Filter triggers — apply on change with slight delay for search
let searchTimer;
document.getElementById('searchInput').addEventListener('keyup', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 400);
});
document.getElementById('catSelect').addEventListener('change', applyFilters);
document.getElementById('sortSelect').addEventListener('change', applyFilters);

function applyFilters() {
    let params = new URLSearchParams(window.location.search);
    let q = document.getElementById('searchInput').value.trim();
    let cat = document.getElementById('catSelect').value;
    let sort = document.getElementById('sortSelect').value;

    if (q) params.set('q', q); else params.delete('q');
    if (cat) params.set('category', cat); else params.delete('category');
    if (sort) params.set('sort', sort); else params.delete('sort');
    params.delete('page');
    window.location.href = 'artworks.php?' + params.toString();
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

// Reject modal
function openRejectModal(id) {
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectModal').classList.add('open');
}
function closeReject() {
    document.getElementById('rejectModal').classList.remove('open');
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('open');
        }
    });
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDelete();
        closeReject();
    }
});
</script>
</body>
</html>