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

    // Validation
    if ($title === '' || $price <= 0 || $categoryId === 0 || empty($_FILES['images']['name'][0])) {
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
    --black: #0a0a0a;
    --grey1: #f7f7f7;
    --grey2: #efefef;
    --grey3: #d8d8d8;
    --grey4: #999;
    --grey5: #555;
    --white: #ffffff;
    --red: #d63031;
    --green: #00b894;
    --sidebar: 220px;
    --top: 60px;
}
html, body { height: 100%; background: var(--grey1); color: var(--black); font-family: 'DM Sans', sans-serif; }

/* ── Sidebar (Copy from previous pages) ─────────────── */
.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: var(--white); border-right: 1px solid var(--grey2); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--grey2); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--grey4); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--black); font-weight: 400; margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--black); color: var(--white); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--grey5); text-decoration: none; border-left: 2px solid transparent; transition: all .15s; }
.nav-item:hover { color: var(--black); background: var(--grey1); border-left-color: var(--grey3); }
.nav-item.active { color: var(--black); background: var(--grey1); border-left-color: var(--black); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .55; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--grey2); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--grey5); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: #fff0f0; color: var(--red); }

/* ── Topbar (Copy from previous pages) ──────────────── */
.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--white); border-bottom: 1px solid var(--grey2); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; }
.artist-chip { display: flex; align-items: center; gap: 8px; background: var(--grey1); border: 1px solid var(--grey2); padding: 5px 12px 5px 5px; border-radius: 30px; }
.artist-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--black); display: flex; align-items: center; justify-content: center; font-size: 11px; color: #fff; font-weight: 600; overflow: hidden; }
.artist-chip .avatar img { width: 100%; height: 100%; object-fit: cover; }
.artist-chip .name { font-size: 12px; font-weight: 500; }

/* ── Main Layout ────────────────────────────────────── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; max-width: 860px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; margin-bottom: 20px; }

/* ── Messages ────────────────────────────────────────── */
.msg { padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
.msg.error { background: #fff0f0; color: #c0392b; border: 1px solid #f5c6c6; }

/* ── Card ────────────────────────────────────────────── */
.card { background: var(--white); border: 1px solid var(--grey2); border-radius: 16px; overflow: hidden; padding: 32px; }

/* ── Form Grid ───────────────────────────────────────── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
.form-grid.full { grid-template-columns: 1fr; }
.field-group { margin-bottom: 20px; }
.field-group label { display: block; font-size: 11px; letter-spacing: .8px; text-transform: uppercase; color: var(--grey5); font-weight: 500; margin-bottom: 8px; }
.field-group label span { color: var(--red); }
.field-input, .field-select, .field-textarea {
    width: 100%; padding: 12px 16px; font-size: 13px; font-family: 'DM Sans', sans-serif;
    border: 1px solid var(--grey3); border-radius: 10px; background: var(--white);
    color: var(--black); outline: none; transition: border-color .15s;
}
.field-input:focus, .field-select:focus, .field-textarea:focus { border-color: var(--black); }
.field-textarea { min-height: 120px; resize: vertical; line-height: 1.6; }

/* ── Toggle Row ─────────────────────────────────────── */
.toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-top: 1px solid var(--grey2); }
.toggle-label { font-size: 13px; font-weight: 500; }
.toggle-desc { font-size: 11px; color: var(--grey4); display: block; margin-top: 2px; }
.toggle-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; inset: 0; cursor: pointer; background: var(--grey3); border-radius: 24px; transition: background .2s; }
.toggle-slider::before { content: ''; position: absolute; left: 3px; top: 3px; width: 18px; height: 18px; background: #fff; border-radius: 50%; transition: transform .2s; }
.toggle-switch input:checked + .toggle-slider { background: var(--black); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

/* ── File Upload Area ───────────────────────────────── */
.upload-area {
    border: 2px dashed var(--grey3); border-radius: 12px;
    padding: 40px 20px; text-align: center; background: var(--grey1);
    transition: border-color .2s; cursor: pointer; margin-bottom: 24px;
}
.upload-area:hover { border-color: var(--black); background: #f0f0f0; }
.upload-icon { color: var(--grey4); margin-bottom: 12px; }
.upload-title { font-size: 14px; font-weight: 500; color: var(--black); margin-bottom: 4px; }
.upload-hint { font-size: 11px; color: var(--grey4); }
.preview-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 12px; margin-top: 20px; }
.preview-item { position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden; border: 1px solid var(--grey3); }
.preview-item img { width: 100%; height: 100%; object-fit: cover; }
.preview-badge {
    position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,.7); color: #fff;
    font-size: 9px; padding: 2px 6px; border-radius: 4px;
}

/* ── Actions ─────────────────────────────────────────── */
.form-actions { display: flex; align-items: center; justify-content: flex-end; gap: 16px; margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--grey2); }
.btn {
    padding: 12px 28px; border-radius: 10px; font-size: 13px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer; border: none;
    text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all .15s;
}
.btn-primary { background: var(--black); color: #fff; }
.btn-primary:hover { background: #222; }
.btn-ghost { background: transparent; color: var(--grey5); border: 1px solid var(--grey3); }
.btn-ghost:hover { border-color: var(--black); color: var(--black); }

@media (max-width: 900px) { :root { --sidebar: 0px; } .sidebar { display: none; } .topbar { left: 0; } .form-grid { grid-template-columns: 1fr; } .content { padding: 20px; } }
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
        <div class="artist-chip">
            <div class="avatar">
                <?php if ($avatarUrl ?? false): ?>
                    <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="">
                <?php else: ?>
                    <?= strtoupper(substr($artistName, 0, 1)) ?>
                <?php endif; ?>
            </div>
            <span class="name"><?= htmlspecialchars($artistName) ?></span>
        </div>
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <div class="section-title">Submit New Artwork</div>

    <?php if ($errorMsg): ?>
        <div class="msg error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="uploadForm">

        <div class="card">
            
            <!-- ── Images ─────────────────────────────── -->
            <div class="upload-area" id="dropZone">
                <svg class="upload-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <div class="upload-title">Click to upload images</div>
                <div class="upload-hint">JPG, PNG, WebP (Max 3MB per image)<br>First image will be the cover photo</div>
                <input type="file" name="images[]" id="fileInput" accept="image/jpeg,image/png,image/webp" multiple hidden required>
            </div>

            <div class="preview-grid" id="previewGrid"></div>

            <!-- ── Details ────────────────────────────── -->
            <div class="form-grid full">
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

<script>
// ── Image Preview Logic ─────────────────────────────
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const previewGrid = document.getElementById('previewGrid');

dropZone.addEventListener('click', () => fileInput.click());

fileInput.addEventListener('change', handleFiles);

function handleFiles() {
    previewGrid.innerHTML = '';
    const files = Array.from(fileInput.files);
    
    files.forEach((file, index) => {
        if (!file.type.startsWith('image/')) return;
        
        const reader = new FileReader();
        reader.onload = (e) => {
            const div = document.createElement('div');
            div.className = 'preview-item';
            div.innerHTML = `
                <img src="${e.target.result}" alt="Preview ${index + 1}">
                ${index === 0 ? '<span class="preview-badge">Cover</span>' : ''}
            `;
            previewGrid.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
}
</script>

</body>
</html>