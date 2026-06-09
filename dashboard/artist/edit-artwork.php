<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// ── Auth guard ───────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'artist') {
    header('Location: ../../login.php');
    exit;
}

 $artistId   = (int) $_SESSION['user_id'];
 $artistName = $_SESSION['name'] ?? 'Artist';
 $successMsg = '';
 $errorMsg   = '';

// ── Fetch Artist Avatar (Added this to fix topbar display) ─────
 $artistInfo = $conn->query("SELECT profile_picture FROM users WHERE id = $artistId")->fetch_assoc();
 $avatarUrl = $artistInfo['profile_picture'] ? '../../' . ltrim($artistInfo['profile_picture'], './') : null;

// ── Fetch Artwork & Verify Ownership ─────────────────
 $artworkId = (int) ($_GET['id'] ?? 0);
 $artwork = $conn->query("
    SELECT a.*, c.id as cat_id
    FROM artworks a
    JOIN categories c ON a.category_id = c.id
    WHERE a.id = $artworkId AND a.artist_id = $artistId
")->fetch_assoc();

if (!$artwork) {
    header('Location: my-artworks.php');
    exit;
}

// ── Fetch Categories ─────────────────────────────────
 $categories = $conn->query("SELECT id, name FROM categories ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// ── Fetch Current Images ─────────────────────────────
 $images = $conn->query("
    SELECT id, image_path, is_cover, sort_order
    FROM artwork_images 
    WHERE artwork_id = $artworkId 
    ORDER BY is_cover DESC, sort_order ASC
")->fetch_all(MYSQLI_ASSOC);

// ── Handle Image Actions (Delete/Make Cover) ─────────
if (isset($_GET['delete_img'])) {
    $imgId = (int) $_GET['delete_img'];
    // Verify ownership
    $check = $conn->query("SELECT id FROM artwork_images WHERE id = $imgId AND artwork_id = $artworkId");
    if ($check->num_rows > 0) {
        $row = $check->fetch_assoc();
        $path = __DIR__ . '/../../' . $row['image_path'];
        if (file_exists($path)) unlink($path);
        $conn->query("DELETE FROM artwork_images WHERE id = $imgId");
        
        // If we deleted the cover, make the next one cover
        if ($row['is_cover']) {
            $conn->query("UPDATE artwork_images SET is_cover = 1 WHERE artwork_id = $artworkId ORDER BY sort_order ASC LIMIT 1");
        }
        
        header("Location: edit-artwork.php?id=$artworkId&msg=img_deleted");
        exit;
    }
}

if (isset($_GET['set_cover'])) {
    $imgId = (int) $_GET['set_cover'];
    // Reset all covers for this artwork
    $conn->query("UPDATE artwork_images SET is_cover = 0 WHERE artwork_id = $artworkId");
    // Set new cover
    $conn->query("UPDATE artwork_images SET is_cover = 1 WHERE id = $imgId AND artwork_id = $artworkId");
    header("Location: edit-artwork.php?id=$artworkId");
    exit;
}

// ── Handle Form Submission (Update Details + New Images) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title                  = trim($_POST['title'] ?? '');
    $categoryId             = (int) ($_POST['category'] ?? 0);
    $medium                 = trim($_POST['medium'] ?? '');
    $size                   = trim($_POST['size'] ?? '');
    $price                  = (float) ($_POST['price'] ?? 0);
    $city                   = trim($_POST['city'] ?? '');
    $description            = trim($_POST['description'] ?? '');
    $delivery_available     = isset($_POST['delivery_available']) ? 1 : 0;
    $similar_work_available = isset($_POST['similar_work_available']) ? 1 : 0;

    if ($title === '' || $price <= 0 || $categoryId === 0) {
        $errorMsg = 'Please fill in all required fields.';
    } else {
        
        // 1. Update Artwork Details
        // If it was rejected, maybe we should reset it to pending? Yes, allow re-submission.
        $status = ($artwork['status'] === 'rejected') ? 'pending' : $artwork['status'];

        // FIXED: bind_param type string now correctly has 12 characters (i s s s s d s s i i s i)
        // Variables: $categoryId(i), $title(s), $description(s), $medium(s), $size(s), 
        //            $price(d), $city(s), $delivery_available(i), $similar_work_available(i), 
        //            $status(s), $artworkId(i), $artistId(i)
        $stmt = $conn->prepare("
            UPDATE artworks 
            SET category_id = ?, title = ?, description = ?, medium = ?, size = ?, price = ?, city = ?, delivery_available = ?, similar_work_available = ?, status = ?, rejection_reason = NULL
            WHERE id = ? AND artist_id = ?
        ");
        $stmt->bind_param('issssdsiiisi', $categoryId, $title, $description, $medium, $size, $price, $city, $delivery_available, $similar_work_available, $status, $artworkId, $artistId);
        $stmt->execute();

        // 2. Handle New Image Uploads
        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $uploadDir = __DIR__ . '/../../uploads/artworks/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $files = $_FILES['images'];
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
            $currentMaxSort = (int) $conn->query("SELECT MAX(sort_order) FROM artwork_images WHERE artwork_id = $artworkId")->fetch_row()[0];

            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedExt) && $files['size'][$i] < 3 * 1024 * 1024) {
                        $newName = 'art_' . $artworkId . '_' . time() . '_new_' . $i . '.' . $ext;
                        if (move_uploaded_file($files['tmp_name'][$i], $uploadDir . $newName)) {
                            $dbPath = 'uploads/artworks/' . $newName;
                            // If this is the very first image, and no others exist, make it cover
                            $isCover = (empty($images)) ? 1 : 0;
                            
                            $stmtImg = $conn->prepare("INSERT INTO artwork_images (artwork_id, image_path, is_cover, sort_order) VALUES (?, ?, ?, ?)");
                            $stmtImg->bind_param('isii', $artworkId, $dbPath, $isCover, $currentMaxSort);
                            $stmtImg->execute();
                            $currentMaxSort++;
                        }
                    }
                }
            }
        }

        // Refresh data
        $artwork = $conn->query("SELECT * FROM artworks WHERE id = $artworkId")->fetch_assoc();
        $images = $conn->query("SELECT * FROM artwork_images WHERE artwork_id = $artworkId ORDER BY is_cover DESC, sort_order ASC")->fetch_all(MYSQLI_ASSOC);
        $successMsg = 'Artwork updated successfully.';
    }
}

// ── Message Handling ─────────────────────────────────
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'img_deleted') $successMsg = 'Image removed.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Artwork — Art Bazaar</title>
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

/* ── Sidebar & Topbar ───────────────────────────────── */
.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: var(--ink); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--border); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--bg); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); font-weight: 400; margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--bg); text-decoration: none; border-left: 2px solid transparent; transition: all .15s; }
.nav-item:hover { color: var(--ink); background: rgba(255,255,255,0.3); border-left-color: var(--sand); }
.nav-item.active { color: var(--ink); background: var(--sand); font-weight: 500; border-left-color: var(--sand); }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .55; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: rgba(255,255,255,0.1); color: var(--sand); }

.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--card); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--ink); }
.artist-chip { display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); padding: 5px 12px 5px 5px; border-radius: 30px; }
.artist-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--ink); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--bg); font-weight: 600; overflow: hidden; }
.artist-chip .avatar img { width: 100%; height: 100%; object-fit: cover; }
.artist-chip .name { font-size: 12px; font-weight: 500; color: var(--ink); }
.artist-chip .arrow { font-size: 12px; color: var(--muted); margin-left: 4px; }

.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; max-width: 860px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 20px; }

/* ── Messages ───────────────────────────────────────── */
.msg { padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
.msg.success { background: var(--sand); color: var(--ink); border: 1px solid var(--border); }
.msg.error { background: var(--sand); color: var(--ink); border: 1px solid var(--border); }

/* ── Card ────────────────────────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--r); overflow: hidden; padding: 32px; margin-bottom: 24px; }
.card-head { border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 20px; font-weight: 500; font-size: 14px; color: var(--ink); }

/* ── Form Grid ───────────────────────────────────────── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
.form-grid.full { grid-template-columns: 1fr; }
.field-group { margin-bottom: 20px; }
.field-group label { display: block; font-size: 10.5px; letter-spacing: .8px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 8px; }
.field-group label span { color: var(--ink); }
.field-input, .field-select, .field-textarea {
    width: 100%; padding: 12px 16px; font-size: 13px; font-family: 'DM Sans', sans-serif;
    border: 1.5px solid var(--sand); border-radius: 10px; background: var(--bg);
    color: var(--ink); outline: none; transition: border-color .15s;
}
.field-input:focus, .field-select:focus, .field-textarea:focus { border-color: var(--ink); }
.field-textarea { min-height: 120px; resize: vertical; line-height: 1.6; }

/* ── Image Manager ───────────────────────────────────── */
.image-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 16px; }
.img-card { position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden; border: 2px solid var(--border); }
.img-card.cover { border-color: var(--ink); }
.img-card img { width: 100%; height: 100%; object-fit: cover; }
.img-actions {
    position: absolute; inset: 0; background: var(--sand);
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
    opacity: 0; transition: opacity .2s; border: 1px solid var(--border);
}
.img-card:hover .img-actions { opacity: 1; }
.img-btn {
    background: var(--sand); border: 1px solid var(--border); padding: 8px 12px; border-radius: 6px;
    font-size: 11px; font-weight: 600; cursor: pointer; color: var(--ink);
    display: flex; align-items: center; gap: 4px; text-decoration: none; width: 100%; justify-content: center;
}
.img-btn:hover { background: var(--ink); color: var(--bg); }
.img-btn.danger { color: var(--ink); border: 1px solid var(--border); background: transparent; }
.img-btn.danger:hover { background: var(--sand); }
.cover-tag {
    position: absolute; top: 8px; left: 8px; background: var(--ink); color: var(--bg);
    font-size: 9px; padding: 3px 8px; border-radius: 4px; font-weight: 600;
}

/* ── Toggle Row ─────────────────────────────────────── */
.toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-top: 1px solid var(--border); }
.toggle-label { font-size: 13px; font-weight: 500; color: var(--ink); }
.toggle-desc { font-size: 11px; color: var(--muted); display: block; margin-top: 2px; }
.toggle-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; accent-color: var(--ink); }
.toggle-slider { position: absolute; inset: 0; cursor: pointer; background: var(--sand); border: 1.5px solid var(--border); border-radius: 24px; transition: background .2s; }
.toggle-slider::before { content: ''; position: absolute; left: 3px; top: 3px; width: 18px; height: 18px; background: #fff; border-radius: 50%; transition: transform .2s; }
.toggle-switch input:checked + .toggle-slider { background: var(--ink); border-color: var(--ink); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

/* ── Actions ─────────────────────────────────────────── */
.form-actions { display: flex; align-items: center; justify-content: space-between; margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border); }
.btn {
    padding: 12px 28px; border-radius: 10px; font-size: 13px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer; border: none;
    text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all .15s;
}
.btn-primary { background: var(--sand); color: var(--ink); }
.btn-primary:hover { background: #c4b69e; }
.btn-ghost { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--ink); color: var(--ink); }

/* ── Upload Area (for adding new) ───────────────────── */
.upload-add {
    border: 2px dashed var(--border); border-radius: 12px; padding: 24px;
    text-align: center; background: var(--sand); cursor: pointer; margin-top: 24px;
}
.upload-add:hover { border-color: var(--ink); background: #d0c0a0; }
.upload-add-title { font-size: 13px; font-weight: 500; margin-bottom: 4px; color: var(--ink); }
.upload-add-hint { font-size: 11px; color: var(--muted); }

/* ── Drawer Global Styles ───────────────────────────── */
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
    .form-grid { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column; gap: 12px; }
    .form-actions .btn { width: 100%; justify-content: center; }
    
    .ham-btn{display:inline-block;width:30px;height:24px;position:relative;background:none;border:none;cursor:pointer;z-index:2000;}
    .ham-btn span{position:absolute;display:block;width:100%;height:2px;background:var(--ink);border-radius:2px;transition:all .3s;opacity:1;left:0;}
    .ham-btn span:nth-child(1){top:2px;}
    .ham-btn span:nth-child(2){top:10px;}
    .ham-btn span:nth-child(3){top:18px;}
    .open #nav-drawer{display:block;position:fixed;top:0;right:0;width:80%;height:100%;background:var(--ink);z-index:1001;padding:40px 20px;box-shadow:-5px 0 15px rgba(0,0,0,0.1);transition:right 0.3s ease;}
    .open #nav-overlay{display:block;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;}
    
    /* Mobile Image Grid */
    .image-list { grid-template-columns: 1fr 1fr; }
    
    /* Drawer Links */
    #nav-drawer a { display: block; padding: 15px 0; color: var(--bg); font-size: 16px; border-bottom: 1px solid rgba(255,255,255,0.1); }
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
    <a href="my-artworks.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
        My Artworks
    </a>
    <a href="commissions.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Commission Requests
    </a>
    <!-- ADDED ORDERS LINK HERE -->
    <a href="orders.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        Orders
    </a>
    <!-- END ADDED LINK -->
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
    <div class="topbar-left"><h1>Edit Artwork</h1></div>
    <div class="topbar-right">
        <div class="artist-chip">
            <div class="avatar">
                <?php if ($avatarUrl): ?>
                    <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="">
                <?php else: ?>
                    <?= strtoupper(substr($artistName, 0, 1)) ?>
                <?php endif; ?>
            </div>
            <span class="name"><?= htmlspecialchars($artistName) ?></span>
            <span class="arrow">∨</span>
        </div>
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <div class="section-title">Update Artwork Details</div>

    <?php if ($successMsg): ?>
        <div class="msg success"><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="msg error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="editForm">

        <!-- ── Manage Existing Images ─────────────────── -->
        <div class="card">
            <div class="card-head">Current Images</div>
            <?php if (empty($images)): ?>
                <p style="font-size:12px;color:var(--muted);">No images uploaded.</p>
            <?php else: ?>
                <div class="image-list">
                    <?php foreach ($images as $img): ?>
                        <div class="img-card <?= $img['is_cover'] ? 'cover' : '' ?>">
                            <?php if ($img['is_cover']): ?><span class="cover-tag">Cover</span><?php endif; ?>
                            <img src="../../<?= htmlspecialchars($img['image_path']) ?>" alt="">
                            <div class="img-actions">
                                <?php if (!$img['is_cover']): ?>
                                    <a href="?id=<?= $artworkId ?>&set_cover=<?= $img['id'] ?>" class="img-btn">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        Set Cover
                                    </a>
                                <?php endif; ?>
                                <a href="?id=<?= $artworkId ?>&delete_img=<?= $img['id'] ?>" class="img-btn danger" onclick="return confirm('Delete this image?')">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                    Delete
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Add New Images ───────────────────────────── -->
        <div class="card">
            <div class="upload-add" id="addZone">
                <div class="upload-add-title">+ Add More Images</div>
                <div class="upload-add-hint">Click to browse files (Max 3MB per image)</div>
                <input type="file" name="images[]" id="newFiles" accept="image/jpeg,image/png,image/webp" multiple hidden>
            </div>
        </div>

        <!-- ── Details Form ─────────────────────────────── -->
        <div class="card">
            <div class="card-head">Artwork Information</div>
            
            <div class="form-grid full">
                <div class="field-group">
                    <label>Title <span>*</span></label>
                    <input type="text" name="title" class="field-input" value="<?= htmlspecialchars($artwork['title']) ?>" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>Category <span>*</span></label>
                    <select name="category" class="field-select" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $artwork['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label>Medium <span>*</span></label>
                    <input type="text" name="medium" class="field-input" value="<?= htmlspecialchars($artwork['medium']) ?>" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>Size <span>*</span></label>
                    <input type="text" name="size" class="field-input" value="<?= htmlspecialchars($artwork['size']) ?>" required>
                </div>
                <div class="field-group">
                    <label>Price (PKR) <span>*</span></label>
                    <input type="number" name="price" class="field-input" value="<?= $artwork['price'] ?>" min="1" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>City <span>*</span></label>
                    <input type="text" name="city" class="field-input" value="<?= htmlspecialchars($artwork['city']) ?>" required>
                </div>
                <div class="field-group"></div>
            </div>

            <div class="form-grid full">
                <div class="field-group">
                    <label>Description</label>
                    <textarea name="description" class="field-textarea"><?= htmlspecialchars($artwork['description']) ?></textarea>
                </div>
            </div>

            <!-- ── Toggles ───────────────────────────────── -->
            <div class="toggle-row">
                <div>
                    <div class="toggle-label">Delivery Available</div>
                    <span class="toggle-desc">Can you ship this artwork?</span>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="delivery_available" value="1" <?= $artwork['delivery_available'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="toggle-row">
                <div>
                    <div class="toggle-label">Similar Work Available</div>
                    <span class="toggle-desc">Can you create similar custom commissions?</span>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="similar_work_available" value="1" <?= $artwork['similar_work_available'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <!-- ── Actions ───────────────────────────────── -->
            <div class="form-actions">
                <a href="my-artworks.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </div>

    </form>

</div>
</main>

<!-- MOBILE DRAWER & OVERLAY -->
<div id="nav-drawer">
    <div style="margin-bottom: 30px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 20px;">
        <h2 style="color:var(--bg); font-family:'Playfair Display',serif;">Menu</h2>
    </div>
    <a href="index.php">Overview</a>
    <a href="upload-artwork.php">Upload Artwork</a>
    <a href="my-artworks.php">My Artworks</a>
    <a href="commissions.php">Commission Requests</a>
    <a href="orders.php">Orders</a>
    <a href="profile.php">My Profile</a>
    <div style="margin-top: 40px;">
        <a href="../../logout.php" style="display:inline-block; padding: 10px 20px; background:var(--sand); color:var(--ink); border-radius:30px; font-weight:600;">Sign Out</a>
    </div>
</div>
<div id="nav-overlay"></div>

<script>
// ── Add Images Logic ─────────────────────────────────
const addZone = document.getElementById('addZone');
const newFiles = document.getElementById('newFiles');

addZone.addEventListener('click', () => newFiles.click());

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