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
 $errorMsg   = '';
 $successMsg = '';

// ── Fetch categories ─────────────────────────────────
 $categories = $conn->query("SELECT id, name FROM categories ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// ── Fetch New Orders Count for Sidebar Badge ────────────
 $newOrdersCount = 0;
 $countStmt = $conn->prepare("
    SELECT COUNT(DISTINCT o.id) 
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = ? AND o.order_type = 'artwork' AND o.order_status = 'pending'
");
 $countStmt->bind_param('i', $artistId);
 $countStmt->execute();
 $newOrdersCount = $countStmt->get_result()->fetch_row()[0];

// ── Handle form submission ───────────────────────────
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

    // Validation: Check if the 'images' input actually has files
    $hasFiles = isset($_FILES['images']) && isset($_FILES['images']['name'][0]) && $_FILES['images']['name'][0] !== '';

    if ($title === '' || $price <= 0 || $categoryId === 0 || !$hasFiles) {
        $errorMsg = 'Please fill in all required fields and upload at least one image.';
    } else {
        
        // 1. Insert Artwork
        $stmt = $conn->prepare("
            INSERT INTO artworks 
            (artist_id, category_id, title, description, medium, size, price, city, delivery_available, similar_work_available, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param('iisssddsii', $artistId, $categoryId, $title, $description, $medium, $size, $price, $city, $delivery_available, $similar_work_available);
        
        if ($stmt->execute()) {
            $artworkId = $conn->insert_id;
            
            // 2. Handle Images
            $uploadDir = __DIR__ . '/../../uploads/artworks/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $files = $_FILES['images'];
            $uploadedCount = 0;
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];

            // Loop through the files array provided by the DataTransfer object in JS
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $fileName = $files['name'][$i];
                    $fileTmp  = $files['tmp_name'][$i];
                    $fileSize = $files['size'][$i];
                    
                    // 3MB max per image
                    if ($fileSize > 3 * 1024 * 1024) {
                        continue; // Skip oversized files
                    }

                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt)) {
                        continue; // Skip invalid types
                    }

                    // Generate unique filename
                    $newName = 'art_' . $artworkId . '_' . time() . '_' . $i . '.' . $ext;
                    $destPath = $uploadDir . $newName;

                    if (move_uploaded_file($fileTmp, $destPath)) {
                        $dbPath = 'uploads/artworks/' . $newName;
                        // First image is cover
                        $isCover = ($uploadedCount === 0) ? 1 : 0;

                        $stmtImg = $conn->prepare("INSERT INTO artwork_images (artwork_id, image_path, is_cover, sort_order) VALUES (?, ?, ?, ?)");
                        $stmtImg->bind_param('isii', $artworkId, $dbPath, $isCover, $uploadedCount);
                        $stmtImg->execute();
                        
                        $uploadedCount++;
                    }
                }
            }

            if ($uploadedCount > 0) {
                header("Location: my-artworks.php?msg=uploaded");
                exit;
            } else {
                $conn->query("DELETE FROM artworks WHERE id = $artworkId"); // Cleanup if no images saved
                $errorMsg = 'Artwork saved but images failed to upload. Please try again.';
            }

        } else {
            $errorMsg = 'Database error: Could not save artwork.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload Artwork — Art Bazaar</title>
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
.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: var(--ink); border-right: 1px solid rgba(246,237,222,.1); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid rgba(246,237,222,.1); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--bg); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); font-weight: 400; margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--bg); text-decoration: none; border-left: 2px solid transparent; transition: all .15s; position: relative; }
.nav-item:hover { color: var(--bg); background: rgba(255,255,255,0.05); border-left-color: rgba(255,255,255,0.2); }
.nav-item.active { color: var(--ink); background: var(--sand); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .7; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.badge { margin-left: auto; background: var(--sand); color: var(--ink); font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid rgba(246,237,222,.1); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: rgba(255,255,255,0.1); color: var(--bg); }

/* ── Topbar ──────────────────────────────────────────── */
.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--card); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--ink); }
.artist-chip { display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); padding: 5px 12px 5px 5px; border-radius: 30px; }
.artist-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--sand); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--ink); font-weight: 600; overflow: hidden; }
.artist-chip .avatar img { width: 100%; height: 100%; object-fit: cover; }
.artist-chip .name { font-size: 12px; font-weight: 500; color: var(--ink); }
.artist-chip .arrow { font-size: 12px; color: var(--muted); margin-left: 4px; }

/* ── Main Layout ────────────────────────────────────── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; max-width: 860px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; margin-bottom: 20px; }

/* ── Messages ────────────────────────────────────────── */
.msg { padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); color: var(--ink); }

/* ── Card ────────────────────────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; padding: 32px; }

/* ── Form Grid ───────────────────────────────────────── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
.form-grid.full { grid-template-columns: 1fr; }
.field-group { margin-bottom: 20px; }
.field-group label { display: block; font-size: 10.5px; letter-spacing: .7px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 8px; }
.field-group label span { color: var(--ink); }
.field-input, .field-select, .field-textarea {
    width: 100%; padding: 12px 16px; font-size: 13px; font-family: 'DM Sans', sans-serif;
    border: 1.5px solid var(--sand); border-radius: 10px; background: var(--bg);
    color: var(--ink); outline: none; transition: border-color .15s;
}
.field-input:focus, .field-select:focus, .field-textarea:focus { border-color: var(--ink); }
.field-textarea { min-height: 120px; resize: vertical; line-height: 1.6; }

/* ── Toggle Row ─────────────────────────────────────── */
.toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-top: 1px solid var(--border); }
.toggle-label { font-size: 13px; font-weight: 500; color: var(--ink); }
.toggle-desc { font-size: 11px; color: var(--muted); display: block; margin-top: 2px; }
.toggle-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; inset: 0; cursor: pointer; background: var(--sand); border-radius: 24px; transition: background .2s; }
.toggle-slider::before { content: ''; position: absolute; left: 3px; top: 3px; width: 18px; height: 18px; background: #fff; border-radius: 50%; transition: transform .2s; }
.toggle-switch input:checked + .toggle-slider { background: var(--ink); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

/* ── File Upload Area ───────────────────────────────── */
.upload-area {
    border: 2px dashed var(--border); border-radius: 12px;
    padding: 40px 20px; text-align: center; background: var(--sand);
    transition: border-color .2s; cursor: pointer; margin-bottom: 24px; color: var(--ink);
}
.upload-area:hover { border-color: var(--ink); }
.upload-icon { color: var(--ink); margin-bottom: 12px; }
.upload-title { font-size: 14px; font-weight: 500; color: var(--ink); margin-bottom: 4px; }
.upload-hint { font-size: 11px; color: var(--muted); }

/* ── Preview Grid with Counter & Remove ─────────────────── */
.preview-header {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 12px;
}
.preview-counter {
    font-size: 11px;
    color: var(--muted);
}
.preview-counter span {
    font-weight: 600;
    color: var(--ink);
}
.preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 12px;
}
.preview-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid var(--border);
    background: #f0f0f0;
}
.preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.preview-badge {
    position: absolute;
    top: 4px;
    left: 4px;
    background: var(--ink);
    color: var(--bg);
    font-size: 9px;
    padding: 2px 6px;
    border-radius: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.remove-preview {
    position: absolute;
    top: 4px;
    right: 4px;
    background: var(--ink);
    color: var(--bg);
    border: none;
    border-radius: 50%;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    transition: all .2s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.remove-preview:hover {
    background: var(--sand);
    color: var(--ink);
    transform: scale(1.1);
}

/* ── Actions ─────────────────────────────────────────── */
.form-actions { display: flex; align-items: center; justify-content: flex-end; gap: 16px; margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border); }
.btn {
    padding: 12px 28px; border-radius: 10px; font-size: 13px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer; border: none;
    text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all .15s;
}
.btn-primary { background: var(--sand); color: var(--ink); width: 100%; justify-content: center; }
.btn-primary:hover { background: #c4b69e; }
.btn-ghost { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--ink); color: var(--ink); }

/* ── Drawer (Hamburger) ──────────────────────────────── */
#nav-drawer{display:none; position:fixed; top:0; right:0; bottom:0; width:260px; background:var(--ink); z-index:200; padding:20px; transform:translateX(100%); transition:transform .3s ease; flex-direction:column; border-left:1px solid rgba(246,237,222,.1);}
#nav-drawer.open{transform:translateX(0); display:flex;}
#nav-overlay{display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:199;}
#nav-overlay.open{display:block;}
.ham-btn{display:none; flex-direction:column; gap:4px; background:none; border:none; cursor:pointer; padding:4px;}
.ham-btn span{width:22px; height:2px; background:var(--ink); border-radius:2px; transition:.2s;}
.drawer-top{display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; border-bottom:1px solid rgba(246,237,222,.1); padding-bottom:15px;}
.drawer-logo{font-family:'Playfair Display',serif; font-size:18px; color:var(--bg); font-weight:400;}
.drawer-close{background:none; border:none; color:var(--bg); font-size:24px; cursor:pointer;}
.drawer-links a{display:block; color:var(--bg); text-decoration:none; padding:12px 0; border-bottom:1px solid rgba(246,237,222,.05); font-size:14px;}
.drawer-links a:hover{color:var(--sand);}
.drawer-actions{margin-top:auto; padding-top:20px; border-top:1px solid rgba(246,237,222,.1);}
.drawer-actions a{display:block; padding:10px 0; color:var(--bg); text-decoration:none; font-size:13px;}

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 1080px) {
    /* Footer grid adjustment if needed, but currently no footer grid here */
}

@media (max-width: 768px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; padding: 0 16px; }
    .content { padding: 16px; }
    .form-grid { grid-template-columns: 1fr; }
    .upload-area { width: 100%; }
    .preview-grid { grid-template-columns: repeat(2, 1fr); }
    .btn-primary { width: 100%; }
    .ham-btn { display: flex; }
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
    <a href="upload-artwork.php" class="nav-item active">
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
    
    <!-- ADDED ORDERS LINK -->
    <a href="orders.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        Orders
        <?php if ($newOrdersCount > 0): ?>
            <span class="badge"><?= $newOrdersCount ?></span>
        <?php endif; ?>
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
    <div class="topbar-left"><h1>Upload Artwork</h1></div>
    <div class="topbar-right">
        <button class="ham-btn" onclick="openDrawer()"><span></span><span></span><span></span></button>
        <div class="artist-chip">
            <div class="avatar">
                <?php 
                $avatar = $conn->query("SELECT profile_picture FROM users WHERE id = $artistId")->fetch_assoc()['profile_picture'] ?? '';
                if ($avatar): ?>
                    <img src="<?= '../../' . ltrim($avatar, './') ?>" alt="">
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

    <div class="section-title">Submit New Artwork</div>

    <?php if ($errorMsg): ?>
        <div class="msg"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="uploadForm">

        <div class="card">
            
            <!-- ── Images ─────────────────────────────── -->
            <div class="upload-area" id="dropZone">
                <svg class="upload-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <div class="upload-title">Click to upload images</div>
                <div class="upload-hint">Up to 5 images · Max 3MB each · First image will be the cover.</div>
                <input type="file" name="images[]" id="fileInput" accept="image/jpeg,image/png,image/webp" multiple hidden>
            </div>

            <div class="preview-header">
                <div></div>
                <div class="preview-counter" id="previewCounter">0 / 5 images selected</div>
            </div>
            <div class="preview-grid" id="previewGrid"></div>

            <!-- ── Details ────────────────────────────── -->
            <div class="form-grid full" style="margin-top: 24px;">
                <div class="field-group">
                    <label>Artwork Title <span>*</span></label>
                    <input type="text" name="title" class="field-input" placeholder="e.g. The Blue Horizon" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>Category <span>*</span></label>
                    <select name="category" class="field-select" required>
                        <option value="">Select category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label>Medium <span>*</span></label>
                    <input type="text" name="medium" class="field-input" placeholder="e.g. Oil on Canvas" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>Size <span>*</span></label>
                    <input type="text" name="size" class="field-input" placeholder="e.g. 24 x 36 inches" required>
                </div>
                <div class="field-group">
                    <label>Price (PKR) <span>*</span></label>
                    <input type="number" name="price" class="field-input" placeholder="e.g. 25000" min="1" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>City <span>*</span></label>
                    <input type="text" name="city" class="field-input" placeholder="e.g. Lahore" required>
                </div>
                <div class="field-group">
                    <!-- Spacer for balance -->
                </div>
            </div>

            <div class="form-grid full">
                <div class="field-group">
                    <label>Description</label>
                    <textarea name="description" class="field-textarea" placeholder="Tell the story behind this artwork, techniques used, inspiration..."></textarea>
                </div>
            </div>

            <!-- ── Toggles ───────────────────────────── -->
            <div class="toggle-row">
                <div>
                    <div class="toggle-label">Delivery Available</div>
                    <span class="toggle-desc">Can you ship this artwork to other cities?</span>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="delivery_available" value="1">
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="toggle-row">
                <div>
                    <div class="toggle-label">Similar Work Available</div>
                    <span class="toggle-desc">Can you create similar custom commissions?</span>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="similar_work_available" value="1">
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <!-- ── Actions ───────────────────────────── -->
            <div class="form-actions">
                <a href="my-artworks.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    Submit for Review
                </button>
            </div>

        </div>
    </form>

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
        <a href="upload-artwork.php">Upload Artwork</a>
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

// ── Image Preview Logic with Remove Functionality & Counter ─────────────────
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const previewGrid = document.getElementById('previewGrid');
const previewCounter = document.getElementById('previewCounter');

let currentFiles = [];

dropZone.addEventListener('click', () => fileInput.click());

fileInput.addEventListener('change', function(e) {
    const newFiles = Array.from(e.target.files);
    
    // Check max file limit (5)
    if (currentFiles.length + newFiles.length > 5) {
        alert('You can only upload up to 5 images. Please remove some before adding more.');
        fileInput.value = ''; // Reset input to allow re-selection if needed, though currentFiles holds state
        return;
    }
    
    // Add new files to currentFiles array
    newFiles.forEach(file => {
        if (file.type.startsWith('image/')) {
            currentFiles.push(file);
        }
    });
    
    renderPreviews();
    updateFileInput();
});

function renderPreviews() {
    previewGrid.innerHTML = '';
    
    currentFiles.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = (e) => {
            const div = document.createElement('div');
            div.className = 'preview-item';
            div.setAttribute('data-index', index);
            div.innerHTML = `
                <img src="${e.target.result}" alt="Preview ${index + 1}">
                ${index === 0 ? '<span class="preview-badge">Cover</span>' : ''}
                <button type="button" class="remove-preview" data-index="${index}" title="Remove image">×</button>
            `;
            previewGrid.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
    
    // Update counter text
    previewCounter.innerHTML = `${currentFiles.length} / 5 images selected`;
    previewCounter.querySelector('span')?.classList.add('highlight'); // Optional visual cue
    
    // Attach remove event listeners after DOM update
    document.querySelectorAll('.remove-preview').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation(); // Prevent triggering dropZone click
            const idx = parseInt(this.getAttribute('data-index'));
            removeFileAtIndex(idx);
        });
    });
}

function removeFileAtIndex(index) {
    currentFiles.splice(index, 1);
    renderPreviews();
    updateFileInput();
}

function updateFileInput() {
    // Create a new FileList-like object using DataTransfer so the form submits correctly
    const dataTransfer = new DataTransfer();
    currentFiles.forEach(file => {
        dataTransfer.items.add(file);
    });
    fileInput.files = dataTransfer.files;
    
    // Toggle required attribute to pass browser validation
    if (currentFiles.length > 0) {
        fileInput.removeAttribute('required');
    } else {
        fileInput.setAttribute('required', 'required');
    }
}

// Optional: Clear all on form reset (if you add a reset button)
document.getElementById('uploadForm').addEventListener('reset', function() {
    currentFiles = [];
    renderPreviews();
    updateFileInput();
});
</script>

</body>
</html>