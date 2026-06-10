<?php
// ── DEBUGGING: Shows errors on screen. Remove these 3 lines in production. ──
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../../config/db.php';

// Auth guard
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

 $adminName = $_SESSION['name'] ?? 'Admin';
 $toast = '';
 $id = (int)($_GET['id'] ?? 0);

// ── DEBUGGING: Stop here if no ID is provided ──
if (!$id) {
    die("Error: No Artwork ID provided. Please access this page from the artworks list.");
}

// Fetch artwork with artist and category
 $stmt = $conn->prepare("
    SELECT a.*, c.name AS category_name, u.name AS artist_name
    FROM artworks a
    JOIN users u ON u.id = a.artist_id
    JOIN categories c ON c.id = a.category_id
    WHERE a.id = ?
");
 $stmt->bind_param('i', $id);
 $stmt->execute();
 $artwork = $stmt->get_result()->fetch_assoc();

// ── DEBUGGING: Stop if artwork doesn't exist ──
if (!$artwork) {
    die("Error: Artwork with ID $id does not exist in the database.");
}

// Fetch all images (display only)
 $images = [];
 $imgRes = $conn->prepare("SELECT * FROM artwork_images WHERE artwork_id = ? ORDER BY is_cover DESC, sort_order ASC");
 $imgRes->bind_param('i', $id);
 $imgRes->execute();
 $imgResult = $imgRes->get_result();
while ($row = $imgResult->fetch_assoc()) $images[] = $row;

// Fetch categories for dropdown
 $categories = [];
 $catRes = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $catRes->fetch_assoc()) $categories[] = $row;

// Fetch inquiries (Orders) for this artwork
 $inquiries = [];
// UPDATED: Now queries 'orders' + 'order_items' instead of 'buyer_inquiries'
 $inqRes = $conn->prepare("
    SELECT o.order_number, o.order_status, o.created_at, o.buyer_notes AS message,
           u.name AS buyer_name, u.email AS buyer_email, u.phone AS buyer_phone
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN users u ON o.buyer_id = u.id
    WHERE oi.item_type = 'artwork' AND oi.item_id = ?
    ORDER BY o.created_at DESC
");
 $inqRes->bind_param('i', $id);
 $inqRes->execute();
 $inqResult = $inqRes->get_result();
while ($row = $inqResult->fetch_assoc()) $inquiries[] = $row;

// ── Handle POST actions ──────────────────────────────────

// Update artwork details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $title       = trim($_POST['title'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $medium      = trim($_POST['medium'] ?? '');
    $size        = trim($_POST['size'] ?? '');
    $price       = floatval($_POST['price'] ?? 0);
    $city        = trim($_POST['city'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $delivery    = isset($_POST['delivery_available']) ? 1 : 0;
    $similar     = isset($_POST['similar_work_available']) ? 1 : 0;

    if (!$title || !$category_id || $price <= 0) {
        $toast = 'Title, category, and price are required.';
    } else {
        $upd = $conn->prepare("UPDATE artworks SET title=?, category_id=?, medium=?, size=?, price=?, city=?, description=?, delivery_available=?, similar_work_available=? WHERE id=?");
        $upd->bind_param('sisddsssii', $title, $category_id, $medium, $size, $price, $city, $description, $delivery, $similar, $id);
        
        if ($upd->execute()) {
            // Re-fetch artwork data to update the view
            $stmt = $conn->prepare("SELECT a.*, c.name AS category_name, u.name AS artist_name FROM artworks a JOIN users u ON u.id = a.artist_id JOIN categories c ON c.id = a.category_id WHERE a.id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $artwork = $stmt->get_result()->fetch_assoc();
            $toast = 'Artwork updated successfully.';
        } else {
            $toast = 'Error updating database: ' . $conn->error;
        }
    }
}

// Approve
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    $conn->query("UPDATE artworks SET status = 'approved', rejection_reason = NULL WHERE id = $id");
    $artwork['status'] = 'approved';
    $toast = 'Artwork approved.';
}

// Reject (Now with Reason)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    $reason = trim($_POST['rejection_reason'] ?? '');
    
    if (!empty($reason)) {
        $stmt = $conn->prepare("UPDATE artworks SET status = 'rejected', rejection_reason = ? WHERE id = ?");
        $stmt->bind_param('si', $reason, $id);
        $stmt->execute();
        $artwork['status'] = 'rejected';
        $artwork['rejection_reason'] = $reason;
        $toast = 'Artwork rejected. Artist will be notified.';
    } else {
        $toast = 'please provide a rejection reason.';
    }
}

// Helper function to determine correct image path
function getImagePath($dbPath) {
    if (strpos($dbPath, '/') !== false) {
        return "../../" . $dbPath;
    }
    return "../../uploads/artworks/" . $dbPath;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Artwork — Art Bazaar Admin</title>
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
.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: var(--ink); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--border); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--bg); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--bg); text-decoration: none; font-weight: 400; border-left: 2px solid transparent; transition: all .15s; }
.nav-item:hover { color: var(--ink); background: rgba(255,255,255,0.3); border-left-color: var(--sand); }
.nav-item.active { color: var(--ink); background: var(--sand); font-weight: 500; border-left-color: var(--sand); }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .55; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: rgba(255,255,255,0.1); color: var(--sand); }

/* ── Topbar ──────────────────────────────────────────── */
.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--ink); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left { display: flex; align-items: center; gap: 14px; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.back-link { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--bg); text-decoration: none; transition: color .15s; opacity: 0.7; }
.back-link:hover { color: var(--bg); opacity: 1; }
.admin-chip { display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); padding: 5px 12px 5px 5px; border-radius: 30px; }
.admin-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--ink); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--bg); font-weight: 600; }
.admin-chip .name { font-size: 12px; color: var(--ink); font-weight: 500; }
.admin-chip .arrow { font-size: 12px; color: var(--ink); margin-left: 4px; }

/* ── Main ────────────────────────────────────────────── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 28px 32px; max-width: 1100px; }

/* ── Toast ───────────────────────────────────────────── */
.toast { background: var(--sand); color: var(--ink); border: 1px solid var(--border); padding: 12px 20px; border-radius: 10px; font-size: 12.5px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }
.toast.error { background: var(--sand); color: var(--ink); border-color: var(--border); }
.toast.hidden { display: none; }
.toast-close { background: none; border: none; color: var(--ink); cursor: pointer; font-size: 16px; }
.toast-close:hover { color: var(--ink); }

/* ── Page header ─────────────────────────────────────── */
.page-head { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; gap: 16px; flex-wrap: wrap; }
.page-head .info h2 { font-family: 'Playfair Display', serif; font-size: 24px; font-weight: 400; color: var(--ink); }
.page-head .info .meta { font-size: 12px; color: var(--muted); margin-top: 4px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

/* ── Buttons ─────────────────────────────────────────── */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; font-size: 12px; font-weight: 500; border-radius: 10px; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .15s; text-decoration: none; white-space: nowrap; }
.btn-primary { background: var(--sand); color: var(--ink); }
.btn-primary:hover { background: #c4b69e; }
.btn-ghost { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--ink); color: var(--ink); }
.btn-green { background: var(--ink); color: var(--bg); }
.btn-green:hover { background: #1a4d3e; }
.btn-red { background: transparent; border: 1px solid var(--border); color: var(--ink); }
.btn-red:hover { background: var(--sand); color: var(--ink); }
.btn-amber { background: transparent; border: 1px solid var(--border); color: var(--ink); }
.btn-amber:hover { background: var(--sand); }
.btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 7px; }

/* ── Pills ───────────────────────────────────────────── */
.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.pill.pending { background: var(--sand); color: var(--ink); }
.pill.approved { background: var(--ink); color: var(--bg); }
.pill.rejected { background: var(--sand); color: var(--ink); }
.pill.sold { background: var(--ink); color: var(--bg); }
.pill.hidden { background: var(--bg); border: 1px solid var(--border); color: var(--ink); }
.featured-tag { display: inline-flex; align-items: center; gap: 3px; font-size: 10px; color: var(--ink); font-weight: 600; }

/* ── Two col layout ──────────────────────────────────── */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 28px; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--r); overflow: hidden; }
.card-head { padding: 16px 22px; border-bottom: 1px solid var(--border); font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: var(--ink); font-weight: 500; }
.card-body { padding: 22px; }

/* ── Images grid (read-only) ─────────────────────────── */
.img-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; }
.img-item { position: relative; border-radius: 6px; overflow: hidden; border: 1px solid var(--border); aspect-ratio: 1; background: var(--sand); }
.img-item.cover { border-color: var(--ink); }
.img-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.img-cover-badge { position: absolute; top: 6px; left: 6px; background: var(--ink); color: var(--bg); font-size: 8px; letter-spacing: 1px; text-transform: uppercase; padding: 2px 7px; border-radius: 6px; font-weight: 600; }
.img-empty { text-align: center; padding: 30px; color: var(--muted); font-size: 12px; }
.img-readonly-note { margin-top: 12px; font-size: 11px; color: var(--muted); text-align: center; padding: 8px; background: var(--sand); border-radius: 8px; border: 1px solid var(--border); }

/* ── Form fields ─────────────────────────────────────── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; }
.form-group.full { grid-column: 1 / -1; }
.form-group label { font-size: 10.5px; letter-spacing: 0.7px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 6px; }
.form-group input, .form-group select, .form-group textarea {
    padding: 9px 14px; border: 1.5px solid var(--sand); border-radius: 9px;
    font-size: 13px; font-family: 'DM Sans', sans-serif; color: var(--ink);
    background: var(--bg); outline: none; transition: border-color .15s;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--ink); }
.form-group textarea { resize: vertical; min-height: 80px; }

/* ── Checkbox ────────────────────────────────────────── */
.check-row { display: flex; align-items: center; gap: 8px; padding: 10px 0; }
.check-row input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--ink); cursor: pointer; }
.check-row label { font-size: 12.5px; color: var(--ink); cursor: pointer; }

/* ── Inquiries table ─────────────────────────────────── */
.card table { width: 100%; border-collapse: collapse; }
.card th { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; padding: 10px 18px; text-align: left; border-bottom: 1px solid var(--border); background: var(--sand); }
.card td { font-size: 12px; color: var(--body); padding: 10px 18px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.card tr:last-child td { border-bottom: none; }
.card tr:hover td { background: var(--bg); }
.inq-pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 2px 8px; border-radius: 20px; background: var(--sand); color: var(--ink); }
.empty-sm { text-align: center; padding: 24px; color: var(--muted); font-size: 12px; }

/* ── Rejection reason display ────────────────────────── */
.rejection-box { margin-top: 12px; padding: 12px; background: var(--sand); border-radius: 8px; border-left: 3px solid var(--ink); }
.rejection-box .label { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); font-weight: 600; margin-bottom: 6px; }
.rejection-box .reason { font-size: 13px; color: var(--ink); line-height: 1.5; }

/* ── Delete modal ────────────────────────────────────── */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .2s; }
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal { background: var(--card); border-radius: 16px; width: 420px; max-width: 92vw; box-shadow: 0 24px 60px rgba(0,0,0,.15); border: 1px solid var(--border); }
.modal-head { padding: 24px 28px 0; }
.modal-head h3 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--ink); }
.modal-body { padding: 20px 28px; }
.confirm-text { font-size: 13px; color: var(--body); line-height: 1.6; }
.confirm-text strong { color: var(--ink); }
.modal-foot { padding: 0 28px 24px; display: flex; gap: 10px; justify-content: flex-end; }
.modal-input { width: 100%; padding: 10px; border: 1.5px solid var(--sand); border-radius: 8px; font-family: 'DM Sans', sans-serif; font-size: 13px; resize: vertical; background: var(--bg); color: var(--ink); }
.modal-input:focus { outline: none; border-color: var(--ink); }

.dash-footer { padding: 20px 32px; border-top: 1px solid var(--border); font-size: 11px; color: var(--bg); margin-top: 12px; background: var(--ink); }

/* Drawer & Responsive Styles */
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
    .two-col { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
    
    .ham-btn{display:inline-block;width:30px;height:24px;position:relative;background:none;border:none;cursor:pointer;z-index:2000;}
    .ham-btn span{position:absolute;display:block;width:100%;height:2px;background:var(--bg);border-radius:2px;transition:all .3s;opacity:1;left:0;}
    .ham-btn span:nth-child(1){top:2px;}
    .ham-btn span:nth-child(2){top:10px;}
    .ham-btn span:nth-child(3){top:18px;}
    
    .open #nav-drawer{display:block;position:fixed;top:0;right:0;width:80%;height:100%;background:var(--ink);z-index:1001;padding:40px 20px;box-shadow:-5px 0 15px rgba(0,0,0,0.1);transition:right 0.3s ease;}
    .open #nav-overlay{display:block;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;}
    
    #nav-drawer a { display: block; padding: 15px 0; color: var(--bg); font-size: 16px; border-bottom: 1px solid rgba(255,255,255,0.1); }
    
    .img-grid { grid-template-columns: 1fr 1fr; }
    .page-head .actions { display: flex; flex-direction: column; width: 100%; gap: 10px; }
    .page-head .actions .btn { width: 100%; justify-content: center; }
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
    <a href="artworks.php" class="nav-item active"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg> Artworks</a>
    <a href="artists.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Artists</a>
    <a href="blogs.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/></svg>
    Blog Posts
</a>
    <a href="categories.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h7"/></svg> Categories</a>
    <div class="sidebar-section">Requests</div>
    <a href="inquiries.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Buyer Inquiries</a>
    <a href="commissions.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Commissions</a>
    <a href="messages.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v13H4z"/><path d="M4 4l8 9 8-9"/></svg> Messages</a>
    <div class="sidebar-bottom">
        <a href="../../logout.php" class="signout-btn"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sign out</a>
    </div>
</aside>

<!-- ══════════════ TOPBAR ══════════════ -->
<header class="topbar">
    <div class="topbar-left">
        <a href="artworks.php" class="back-link">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            Artworks
        </a>
        <h1>Edit Artwork</h1>
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
    <div class="toast <?= strpos(strtolower($toast), 'error') !== false || strpos(strtolower($toast), 'provide') !== false ? 'error' : '' ?>">
        <span><?= htmlspecialchars($toast) ?></span>
        <button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="page-head">
        <div class="info">
            <h2><?= htmlspecialchars($artwork['title']) ?></h2>
            <div class="meta">
                <span>by <?= htmlspecialchars($artwork['artist_name']) ?></span>
                <span>·</span>
                <span class="pill <?= $artwork['status'] ?>"><?= ucfirst($artwork['status']) ?></span>
                <?php if ($artwork['is_featured']): ?><span class="featured-tag">★ Featured</span><?php endif; ?>
                <span>·</span>
                <span>ID #<?= $artwork['id'] ?></span>
                <span>·</span>
                <span>Added <?= date('d M Y', strtotime($artwork['created_at'])) ?></span>
            </div>
        </div>
        <div class="actions">
            <?php if ($artwork['status'] !== 'approved' && $artwork['status'] !== 'pending'): ?>
                <form method="POST" style="display:inline"><input type="hidden" name="action" value="approve"><button type="submit" class="btn btn-green btn-sm">Approve</button></form>
            <?php endif; ?>
            <?php if ($artwork['status'] !== 'rejected' && $artwork['status'] !== 'pending'): ?>
                <button type="button" class="btn btn-amber btn-sm" onclick="openRejectModal()">Reject</button>
            <?php endif; ?>
            <button type="button" class="btn btn-red btn-sm" onclick="openDelete()">Delete Artwork</button>
        </div>
    </div>

    <!-- Show rejection reason if artwork is rejected -->
    <?php if ($artwork['status'] === 'rejected' && !empty($artwork['rejection_reason'])): ?>
    <div class="rejection-box" style="margin-bottom: 24px;">
        <div class="label">Rejection Reason</div>
        <div class="reason"><?= nl2br(htmlspecialchars($artwork['rejection_reason'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Two column: Images + Details -->
    <div class="two-col">

        <!-- Images card (read-only) -->
        <div class="card">
            <div class="card-head">Images (<?= count($images) ?>)</div>
            <div class="card-body">
                <?php if (!empty($images)): ?>
                <div class="img-grid">
                    <?php foreach ($images as $img): ?>
                    <div class="img-item <?= $img['is_cover'] ? 'cover' : '' ?>">
                        <img src="<?= htmlspecialchars(getImagePath($img['image_path'])) ?>" alt="<?= htmlspecialchars($artwork['title']) ?>">
                        <?php if ($img['is_cover']): ?><span class="img-cover-badge">Cover</span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="img-readonly-note">Images are managed by the artist.</div>
                <?php else: ?>
                <div class="img-empty">No images uploaded.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Details form -->
        <div class="card">
            <div class="card-head">Artwork Details</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Title</label>
                            <input type="text" name="title" value="<?= htmlspecialchars($artwork['title']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category_id" required>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $artwork['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Price (PKR)</label>
                            <input type="number" name="price" value="<?= $artwork['price'] ?>" min="0" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label>Medium</label>
                            <input type="text" name="medium" value="<?= htmlspecialchars($artwork['medium'] ?? '') ?>" placeholder="e.g. Oil on canvas">
                        </div>
                        <div class="form-group">
                            <label>Size</label>
                            <input type="text" name="size" value="<?= htmlspecialchars($artwork['size'] ?? '') ?>" placeholder="e.g. 24x36 inches">
                        </div>
                        <div class="form-group">
                            <label>City / Location</label>
                            <input type="text" name="city" value="<?= htmlspecialchars($artwork['city'] ?? '') ?>">
                        </div>
                        <div class="form-group full">
                            <label>Description</label>
                            <textarea name="description" rows="3"><?= htmlspecialchars($artwork['description'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group">
                            <div class="check-row">
                                <input type="checkbox" name="delivery_available" id="delivery" <?= $artwork['delivery_available'] ? 'checked' : '' ?>>
                                <label for="delivery">Delivery available</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="check-row">
                                <input type="checkbox" name="similar_work_available" id="similar" <?= $artwork['similar_work_available'] ? 'checked' : '' ?>>
                                <label for="similar">Similar work available</label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top:18px;width:100%;justify-content:center;">Save Changes</button>
                </form>
            </div>
        </div>

    </div><!-- /two-col -->

    <!-- Inquiries for this artwork -->
    <div class="card" style="margin-bottom:28px;">
        <div class="card-head">Buyer Inquiries for this Artwork (<?= count($inquiries) ?>)</div>
        <?php if (empty($inquiries)): ?>
            <div class="empty-sm">No inquiries yet.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Buyer</th>
                    <th>Contact</th>
                    <th>Message</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($inquiries as $inq): ?>
                <tr>
                    <td><?= htmlspecialchars($inq['order_number']) ?></td>
                    <td style="font-weight:500;color:var(--ink)"><?= htmlspecialchars($inq['buyer_name']) ?></td>
                    <td>
                        <?php if ($inq['buyer_email']): ?><div style="font-size:11px"><?= htmlspecialchars($inq['buyer_email']) ?></div><?php endif; ?>
                        <?php if ($inq['buyer_phone']): ?><div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($inq['buyer_phone']) ?></div><?php endif; ?>
                    </td>
                    <td style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($inq['message'] ?? '') ?>"><?= htmlspecialchars($inq['message'] ?? '—') ?></td>
                    <td><span class="inq-pill"><?= ucfirst($inq['order_status']) ?></span></td>
                    <td style="white-space:nowrap;font-size:11px;color:var(--muted)"><?= date('d M Y', strtotime($inq['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
<div class="dash-footer">Art Bazaar Admin Panel &mdash; <?= date('Y') ?></div>
</main>

<!-- ══════════════ DELETE MODAL ══════════════ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-head"><h3>Delete Artwork</h3></div>
        <div class="modal-body">
            <p class="confirm-text">Are you sure you want to permanently delete <strong>"<?= htmlspecialchars($artwork['title']) ?>"</strong>? This will remove all images, buyer orders, and cannot be undone.</p>
        </div>
        <form method="POST" action="artworks.php">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="closeDelete()">Cancel</button>
                <button type="submit" class="btn btn-red">Yes, Delete Permanently</button>
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
                <textarea name="rejection_reason" class="modal-input" rows="4" required placeholder="e.g. Low quality images, Wrong category, Copyright issue, Inappropriate content..."></textarea>
                <div class="modal-foot" style="padding-top:16px;">
                    <button type="button" class="btn btn-ghost" onclick="closeReject()">Cancel</button>
                    <button type="submit" class="btn btn-red">Reject Artwork</button>
                </div>
            </form>
        </div>
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
function openDelete() { document.getElementById('deleteModal').classList.add('open'); }
function closeDelete() { document.getElementById('deleteModal').classList.remove('open'); }

function openRejectModal() { document.getElementById('rejectModal').classList.add('open'); }
function closeReject() { document.getElementById('rejectModal').classList.remove('open'); }

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