<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// ── Auth guard ───────────────────────────────────────
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
    $__pendingEmail = $conn->query("SELECT email FROM users WHERE id={$_SESSION['user_id']}")->fetch_assoc()['email'] ?? '';
header('Location: ../../login.php?pending=1&email=' . urlencode($__pendingEmail));
    exit;
}

$artistId = (int) $_SESSION['user_id'];  // ← whatever comes next in the file

 $artistId   = (int) $_SESSION['user_id'];
 $artistName = $_SESSION['name'] ?? 'Artist';
 $successMsg = '';
 $errorMsg   = '';

// ── Handle Actions (Permanent Delete) ────────────────
if (isset($_GET['delete'])) {
    $artId = (int) $_GET['delete'];
    
    // 1. Verify Ownership
    $check = $conn->query("SELECT id FROM artworks WHERE id = $artId AND artist_id = $artistId");
    if ($check->num_rows > 0) {
        
        // 2. Fetch Images to delete from server
        $imgQuery = $conn->query("SELECT image_path FROM artwork_images WHERE artwork_id = $artId");
        if ($imgQuery) {
            while ($img = $imgQuery->fetch_assoc()) {
                $filePath = __DIR__ . '/../../' . $img['image_path'];
                if (file_exists($filePath)) {
                    unlink($filePath); // Delete physical file
                }
            }
        }

        // 3. Delete Image Records from DB
        $conn->query("DELETE FROM artwork_images WHERE artwork_id = $artId");

        // 4. Delete Artwork Record from DB
        $conn->query("DELETE FROM artworks WHERE id = $artId");

        $successMsg = 'Artwork deleted permanently.';
    }
    
    header("Location: my-artworks.php?msg=deleted");
    exit;
}

// ── Handle Q&A Answer ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer_question'])) {
    $qId    = (int)($_POST['question_id'] ?? 0);
    $answer = trim($_POST['answer'] ?? '');
    if ($qId && $answer) {
        // Make sure the question belongs to one of this artist's artworks
        $conn->query("
            UPDATE artwork_questions aq
            JOIN artworks a ON aq.artwork_id = a.id
            SET aq.answer = '" . $conn->real_escape_string($answer) . "', aq.answered_at = NOW()
            WHERE aq.id = $qId AND a.artist_id = $artistId
        ");
    }
    header("Location: my-artworks.php?status=" . urlencode($_GET['status'] ?? 'all') . "&msg=answered");
    exit;
}

// ── Filtering ─────────────────────────────────────────
 $filterStatus = $_GET['status'] ?? '';
 $allowedFilters = ['all', 'active', 'sold', 'hidden'];
if (!in_array($filterStatus, $allowedFilters)) {
    $filterStatus = 'all';
}

// ── Fetch Artworks ───────────────────────────────────
 $sql = "
    SELECT a.*, c.name as category_name, 
           (SELECT image_path FROM artwork_images WHERE artwork_id = a.id ORDER BY is_cover DESC, sort_order ASC LIMIT 1) as cover_image
    FROM artworks a
    JOIN categories c ON a.category_id = c.id
    WHERE a.artist_id = $artistId
";

if ($filterStatus !== 'all') {
    $sql .= " AND a.status = '" . $conn->real_escape_string($filterStatus) . "'";
}

 $sql .= " ORDER BY a.created_at DESC";

 $artworks = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

 // ── Fetch Pending Questions for this artist's artworks ───
$pendingQs = $conn->query("
    SELECT aq.id, aq.artwork_id, aq.buyer_name, aq.question, aq.created_at,
           a.title AS artwork_title
    FROM artwork_questions aq
    JOIN artworks a ON aq.artwork_id = a.id
    WHERE a.artist_id = $artistId AND aq.answer IS NULL
    ORDER BY aq.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$pendingQCount = count($pendingQs);
$unreadCommissionMsgs = (int)$conn->query("
    SELECT COUNT(*) FROM order_messages om
    JOIN commission_requests cr ON cr.order_id = om.order_id
    WHERE cr.artist_id = $artistId
      AND om.sender_role != 'artist'
      AND om.is_read_by_artist = 0
")->fetch_row()[0];

$unreadOrderMsgs = (int)$conn->query("
    SELECT COUNT(DISTINCT om.id) FROM order_messages om
    JOIN orders o ON o.id = om.order_id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = $artistId
      AND o.order_type = 'artwork'
      AND om.sender_role != 'artist'
      AND om.is_read_by_artist = 0
")->fetch_row()[0];

$unseenOrderCount = (int)$conn->query("
    SELECT COUNT(DISTINCT o.id) FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = $artistId
      AND o.order_type = 'artwork'
      AND o.order_status NOT IN ('pending', 'payment_review')
      AND o.seen_by_artist = 0
")->fetch_row()[0];

$newCommCount = (int)$conn->query("
    SELECT COUNT(*) FROM commission_requests cr
    JOIN orders o ON cr.order_id = o.id
    WHERE cr.artist_id = $artistId AND o.order_type = 'commission' AND o.order_status = 'assigned'
")->fetch_row()[0];

// ── Stats for Tabs ───────────────────────────────────
 $counts = [
    'all'    => (int) $conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId")->fetch_row()[0],
    'active' => (int) $conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'active'")->fetch_row()[0],
    'sold'   => (int) $conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'sold'")->fetch_row()[0],
    'hidden' => (int) $conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'hidden'")->fetch_row()[0],
];

// ── Messages ─────────────────────────────────────────
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'uploaded') $successMsg = 'Artwork uploaded and live on the marketplace!';
    if ($_GET['msg'] === 'deleted') $successMsg = 'Artwork deleted successfully.';
    if ($_GET['msg'] === 'answered') $successMsg = 'Reply posted successfully!';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Artworks — Art Bazaar</title>
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
    --body: #0C3F30;
    --muted: #0C3F30;
    --light: #0C3F30;
    
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }

/* ── Sidebar & Topbar (Consistent) ─────────────────── */
.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: var(--ink); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--border); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--sand); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); font-weight: 400; margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--bg); text-decoration: none; border-left: 2px solid transparent; transition: all .15s; }
.nav-item:hover { color: var(--ink); background: var(--sand); border-left-color: var(--sand); }
.nav-item.active { color: var(--ink); background: var(--sand); border-left-color: var(--ink); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .8; stroke: var(--bg); }
.nav-item.active .icon, .nav-item:hover .icon { stroke: var(--ink); opacity: 1; }
.badge { margin-left: auto; background: var(--sand); color: var(--ink); font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
.badge.amber { background: var(--sand); }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: var(--sand); color: var(--ink); }

.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--ink); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.artist-chip { display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); padding: 5px 12px 5px 5px; border-radius: 30px; }
.artist-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--bg); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--ink); font-weight: 600; overflow: hidden; }
.artist-chip .avatar img { width: 100%; height: 100%; object-fit: cover; }
.artist-chip .name { font-size: 12px; font-weight: 500; color: var(--ink); }
.artist-chip .arrow { font-size: 12px; color: var(--ink); margin-left: 4px; opacity: 0.6; }

.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 20px; opacity: 0.7; }

/* ── Q&A Panel ─────────────────────────────────────── */
.qa-panel { background: var(--card); border: 1px solid var(--border); border-radius: 12px; margin-bottom: 28px; overflow: hidden; }
.qa-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; cursor: pointer; background: var(--sand); }
.qa-panel-header h3 { font-size: 13px; font-weight: 600; letter-spacing: .5px; display: flex; align-items: center; gap: 8px; }
.qa-panel-header .badge-count { background: var(--ink); color: var(--bg); font-size: 10px; padding: 2px 8px; border-radius: 20px; }
.qa-panel-body { display: none; padding: 0; }
.qa-panel-body.open { display: block; }
.qa-item { padding: 16px 20px; border-bottom: 1px solid var(--sand); }
.qa-item:last-child { border-bottom: none; }
.qa-artwork-label { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; opacity: .5; margin-bottom: 6px; }
.qa-question-text { font-size: 13.5px; font-weight: 500; margin-bottom: 4px; }
.qa-meta { font-size: 11px; opacity: .5; margin-bottom: 12px; }
.qa-answer-form textarea { width: 100%; border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; font-size: 13px; font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--ink); outline: none; resize: vertical; min-height: 80px; margin-bottom: 8px; }
.qa-answer-form textarea:focus { border-color: var(--ink); }
.qa-answer-form button { background: var(--ink); color: var(--bg); border: none; border-radius: 8px; padding: 8px 20px; font-size: 12px; font-family: 'DM Sans', sans-serif; cursor: pointer; font-weight: 500; }
.qa-answer-form button:hover { opacity: .85; }
.qa-empty-note { padding: 20px; font-size: 13px; opacity: .5; font-style: italic; }

/* ── Messages ───────────────────────────────────────── */
.msg { padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); color: var(--ink); }

/* ── Header Actions ─────────────────────────────────── */
.header-actions { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.btn {
    padding: 10px 20px; border-radius: 10px; font-size: 12.5px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer; border: none;
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all .15s;
}
.btn-primary { background: var(--ink); color: var(--bg); }
.btn-primary:hover { background: #1a5a48; }

/* ── Tabs ───────────────────────────────────────────── */
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 24px; overflow-x: auto; }
.tab-btn {
    padding: 10px 16px; background: none; border: 1px solid transparent; font-size: 12.5px; color: var(--ink);
    cursor: pointer; font-family: 'DM Sans', sans-serif; border-bottom: 2px solid transparent; transition: all .2s;
    border-radius: 20px;
}
.tab-btn:hover { background: var(--sand); border-color: var(--border); }
.tab-btn.active { background: var(--ink); color: var(--bg); border-color: var(--ink); }

/* ── Table ──────────────────────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 10px; letter-spacing: 1.2px; text-transform: uppercase; color: var(--ink); font-weight: 500; padding: 14px 24px; text-align: left; border-bottom: 1px solid var(--border); background: var(--sand); opacity: 0.8; }
td { padding: 16px 24px; border-bottom: 1px solid var(--border); vertical-align: middle; font-size: 13px; color: var(--ink); }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--sand); }

.thumb { width: 50px; height: 50px; border-radius: 8px; object-fit: cover; background: var(--sand); border: 1px solid var(--border); }
.art-title { font-weight: 500; font-size: 13px; color: var(--ink); }
.art-cat { font-size: 11px; color: var(--ink); display: block; margin-top: 2px; opacity: 0.7; }
.price { font-weight: 600; font-size: 13px; color: var(--ink); }

/* ── Badges ─────────────────────────────────────────── */
.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
.pill.active   { background: var(--ink); color: var(--bg); }
.pill.sold     { background: var(--sand); color: var(--ink); }
.pill.hidden   { background: var(--sand); color: var(--ink); border: 1px solid var(--ink); }

/* ── Rejection Reason ───────────────────────────────── */
.reject-reason { 
    display: block; font-size: 11px; color: var(--ink); margin-top: 4px; 
    background: var(--sand); padding: 4px 8px; border-radius: 4px; 
    border: 1px solid var(--border);
}

/* ── Actions Column ─────────────────────────────────── */
.actions { display: flex; gap: 8px; }
.icon-btn { width: 30px; height: 30px; border-radius: 6px; border: 1px solid var(--border); background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--ink); transition: all .2s; }
.icon-btn:hover { border-color: var(--ink); color: var(--ink); background: var(--sand); }
.icon-btn.danger { border: 1px solid var(--border); background: transparent; color: var(--ink); }
.icon-btn.danger:hover { background: var(--sand); border-color: var(--ink); }

/* ── Empty State ─────────────────────────────────────── */
.empty-state { padding: 60px 20px; text-align: center; }
.empty-state h3 { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--ink); margin-bottom: 8px; }
.empty-state p { font-size: 13px; color: var(--ink); opacity: 0.7; }

/* ── Hamburger Drawer ───────────────────────────────── */
#nav-drawer { display:none; position: fixed; top: 0; right: 0; width: 260px; height: 100vh; background: var(--ink); z-index: 200; transform: translateX(100%); transition: transform 0.3s ease; padding: 24px; display: flex; flex-direction: column; border-left: 1px solid var(--border); }
#nav-drawer.open { transform: translateX(0); display: flex; }
#nav-overlay { display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(12,63,48,0.4); z-index: 150; backdrop-filter: blur(2px); }
#nav-overlay.open { display: block; }
.ham-btn { display: none; flex-direction: column; gap: 5px; background: none; border: none; cursor: pointer; padding: 5px; width: 30px; }
.ham-btn span { width: 100%; height: 2px; background: var(--bg); border-radius: 2px; transition: 0.2s; }
.d-header { font-family: 'Playfair Display', serif; font-size: 18px; color: var(--bg); margin-bottom: 24px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
.d-link { color: var(--bg); text-decoration: none; font-size: 14px; padding: 12px 0; display: block; border-bottom: 1px solid rgba(246,237,222,0.1); font-family: 'DM Sans', sans-serif; }
.d-link:hover { color: var(--sand); padding-left: 5px; transition: 0.2s; }

/* ── Mobile Responsiveness ───────────────────────────── */
@media (max-width: 1080px) {
    /* Tablet adjustments if needed */
}

@media (max-width: 768px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; padding: 0 16px; }
    .content { padding: 16px; }
    .header-actions { flex-direction: column; align-items: stretch; gap: 16px; }
    .btn { width: 100%; justify-content: center; display: none; /* Hide the top 'Upload New' button as it's in drawer or footer ideally, but per rules don't change HTML structure strictly, just CSS. However, displaying flex ensures it shows */ }
    .btn { display: inline-flex; } /* Restore display */
    
    .ham-btn { display: flex; }
    .artist-chip { display: none; }
    
    .tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
    .tab-btn { white-space: nowrap; }
    
    /* Make table responsive if needed, or rely on overflow */
    .card { overflow-x: auto; }
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
    <a href="index.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Overview
    </a>
    <div class="sidebar-section">My Work</div>
    <a href="upload-artwork.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Upload Artwork
    </a>
    <a href="my-artworks.php" class="nav-item active">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
        My Artworks
        <?php if ($pendingQCount > 0): ?><span class="badge" style="background:#c0392b;color:#fff;display:flex;align-items:center;gap:4px;"><span style="background:#fff;width:6px;height:6px;border-radius:50%;display:inline-block;"></span><?= $pendingQCount ?></span><?php endif; ?>
    </a>
    <a href="commissions.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Commission Requests
        <?php if ($unreadCommissionMsgs > 0): ?><span class="badge" style="background:#c0392b;color:#fff;display:flex;align-items:center;gap:4px;"><span style="background:#fff;width:6px;height:6px;border-radius:50%;display:inline-block;"></span><?= $unreadCommissionMsgs ?></span><?php elseif ($newCommCount > 0): ?><span class="badge"><?= $newCommCount ?></span><?php endif; ?>
    </a>
    <a href="orders.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        Orders
        <?php if ($unreadOrderMsgs > 0): ?><span class="badge" style="background:#c0392b;color:#fff;display:flex;align-items:center;gap:4px;"><span style="background:#fff;width:6px;height:6px;border-radius:50%;display:inline-block;"></span><?= $unreadOrderMsgs ?></span><?php elseif ($unseenOrderCount > 0): ?><span class="badge"><?= $unseenOrderCount ?> New</span><?php endif; ?>
    </a>
    <div class="sidebar-section">Account</div>
    <a href="profile.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        My Profile
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
    <div class="topbar-left"><h1>My Artworks</h1></div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:12px;">
        <div class="artist-chip">
            <div class="avatar">
                <?php if ($avatarUrl ?? false): ?>
                    <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="">
                <?php else: ?>
                    <?= strtoupper(substr($artistName, 0, 1)) ?>
                <?php endif; ?>
            </div>
            <span class="name"><?= htmlspecialchars($artistName) ?></span>
            <span class="arrow">∨</span>
        </div>
        <button class="ham-btn" id="hamBtn">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <div class="section-title">Portfolio Management</div>

    <?php if ($successMsg): ?>
        <div class="msg">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= htmlspecialchars($successMsg) ?>
        </div>
    <?php endif; ?>

    <!-- Q&A Questions Panel -->
    <div class="qa-panel">
        <div class="qa-panel-header" onclick="this.nextElementSibling.classList.toggle('open'); this.querySelector('svg').style.transform = this.nextElementSibling.classList.contains('open') ? 'rotate(180deg)' : ''">
            <h3>
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Questions from Buyers
                <?php if ($pendingQCount > 0): ?>
                    <span class="badge-count"><?= $pendingQCount ?> unanswered</span>
                <?php endif; ?>
            </h3>
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="transition:transform .25s;"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="qa-panel-body <?= $pendingQCount > 0 ? 'open' : '' ?>">
            <?php if (empty($pendingQs)): ?>
                <div class="qa-empty-note">No unanswered questions right now.</div>
            <?php else: ?>
                <?php foreach ($pendingQs as $q): ?>
                <div class="qa-item">
                    <div class="qa-artwork-label">On: <?= htmlspecialchars($q['artwork_title']) ?></div>
                    <div class="qa-question-text"><?= htmlspecialchars($q['question']) ?></div>
                    <div class="qa-meta">from <?= htmlspecialchars($q['buyer_name']) ?> · <?= date('M j, Y', strtotime($q['created_at'])) ?></div>
                    <form class="qa-answer-form" method="POST">
                        <input type="hidden" name="answer_question" value="1">
                        <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                        <textarea name="answer" placeholder="Type your reply…" required></textarea>
                        <button type="submit">Post Reply</button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="header-actions">
        <div class="tabs">
            <button class="tab-btn <?= $filterStatus === 'all' ? 'active' : '' ?>" onclick="location.href='?status=all'">All (<?= $counts['all'] ?>)</button>
            <button class="tab-btn <?= $filterStatus === 'active' ? 'active' : '' ?>" onclick="location.href='?status=active'">Live (<?= $counts['active'] ?>)</button>
            <button class="tab-btn <?= $filterStatus === 'sold' ? 'active' : '' ?>" onclick="location.href='?status=sold'">Sold (<?= $counts['sold'] ?>)</button>
            <button class="tab-btn <?= $filterStatus === 'hidden' ? 'active' : '' ?>" onclick="location.href='?status=hidden'">Hidden (<?= $counts['hidden'] ?>)</button>
        </div>
        <a href="upload-artwork.php" class="btn btn-primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Upload New
        </a>
    </div>

    <?php if (empty($artworks)): ?>
        <div class="card empty-state">
            <h3>No artworks found</h3>
            <p>Start by uploading your first piece to the marketplace.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th width="80">Image</th>
                        <th>Artwork</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($artworks as $art): ?>
                        <tr>
                            <td>
                                <?php if ($art['cover_image']): ?>
                                    <img src="../../<?= htmlspecialchars($art['cover_image']) ?>" class="thumb" alt="">
                                <?php else: ?>
                                    <div class="thumb"></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="art-title"><?= htmlspecialchars($art['title']) ?></div>
                                <span class="art-cat"><?= date('M j, Y', strtotime($art['created_at'])) ?></span>
                            </td>
                            <td><?= htmlspecialchars($art['category_name']) ?></td>
                            <td class="price">PKR <?= number_format($art['price']) ?></td>
                            <td>
                                <span class="pill <?= $art['status'] ?>"><?= ucfirst($art['status']) ?></span>
                            </td>
                            <td>
                                <div class="actions">
                                    <a href="?delete=<?= $art['id'] ?>" class="icon-btn danger" title="Delete" onclick="return confirm('Are you sure you want to permanently delete this artwork? This cannot be undone.')">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
  <a href="my-artworks.php" class="d-link">My Artworks
    <?php if ($pendingQCount > 0): ?><span style="background:#c0392b;color:#fff;font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $pendingQCount ?></span><?php endif; ?>
  </a>
  <a href="commissions.php" class="d-link">Commission Requests
    <?php if ($unreadCommissionMsgs > 0): ?><span style="background:#c0392b;color:#fff;font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $unreadCommissionMsgs ?></span><?php elseif ($newCommCount > 0): ?><span style="background:var(--sand);color:var(--ink);font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $newCommCount ?></span><?php endif; ?>
  </a>
  <a href="orders.php" class="d-link">Orders
    <?php if ($unreadOrderMsgs > 0): ?><span style="background:#c0392b;color:#fff;font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $unreadOrderMsgs ?></span><?php elseif ($unseenOrderCount > 0): ?><span style="background:var(--sand);color:var(--ink);font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $unseenOrderCount ?> New</span><?php endif; ?>
  </a>
  <a href="profile.php" class="d-link">My Profile</a>
  <div style="margin-top:auto;border-top:1px solid rgba(246,237,222,0.1);padding-top:16px;">
    <a href="../../logout.php" class="d-link" style="color:#ff9999;">Sign Out</a>
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
</script>

</body>
</html>