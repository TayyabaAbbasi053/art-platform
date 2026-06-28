<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// Auth guard
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

 $adminName = $_SESSION['name'] ?? 'Admin';
 $id = (int)($_GET['id'] ?? 0);

// Fetch existing post
 $post = null;
if ($id) {
    $stmt = $conn->prepare("SELECT * FROM blog_posts WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $post = $res->fetch_assoc();
}

if (!$post) {
    header('Location: blogs.php');
    exit;
}

 $errors = [];
 $formData = [
    'title' => $post['title'],
    'slug' => $post['slug'],
    'status' => $post['status'],
    'is_featured' => $post['is_featured'],
    'content' => $post['content'],
    'tags' => $post['tags'] ?? ''
];
 $currentImage = $post['featured_image'];

// ── Handle POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['title'] = trim($_POST['title'] ?? '');
    $formData['slug'] = trim($_POST['slug'] ?? '');
    $formData['status'] = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    $formData['is_featured'] = isset($_POST['is_featured']) ? 1 : 0;
    $formData['content'] = trim($_POST['content'] ?? '');
    $formData['tags'] = trim($_POST['tags'] ?? '');

    // Validation
    if (empty($formData['title'])) $errors[] = 'Title is required.';
    if (empty($formData['content'])) $errors[] = 'Content is required.';

    // Sanitize slug
    if (empty($formData['slug'])) {
        $formData['slug'] = strtolower($formData['title']);
        $formData['slug'] = preg_replace('/[^a-z0-9\s-]/', '', $formData['slug']);
        $formData['slug'] = preg_replace('/[\s-]+/', '-', $formData['slug']);
        $formData['slug'] = trim($formData['slug'], '-');
    } else {
        $formData['slug'] = strtolower($formData['slug']);
        $formData['slug'] = preg_replace('/[^a-z0-9\s-]/', '', $formData['slug']);
        $formData['slug'] = preg_replace('/[\s-]+/', '-', $formData['slug']);
        $formData['slug'] = trim($formData['slug'], '-');
    }

    // Ensure slug uniqueness (excluding current post)
    if (!empty($formData['slug'])) {
        $baseSlug = $formData['slug'];
        $counter = 2;
        while (true) {
            $check = $conn->prepare("SELECT id FROM blog_posts WHERE slug = ? AND id != ?");
            $check->bind_param('si', $formData['slug'], $id);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $formData['slug'] = $baseSlug . '-' . $counter;
                $counter++;
            } else {
                break;
            }
        }
    }

    // Featured Image Upload
    $featuredImage = $currentImage;
    if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['featured_image'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

        if ($file['size'] > $maxSize) {
            $errors[] = 'Featured image must be under 2MB.';
        } elseif (!in_array($file['type'], $allowedTypes)) {
            $errors[] = 'Only JPG, PNG, and WebP files are allowed for the featured image.';
        } else {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('blog_') . '.' . $ext;
            $uploadDir = __DIR__ . '/../../uploads/blog/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                // Delete old image
                if ($currentImage) {
                    $oldPath = __DIR__ . '/../../' . $currentImage;
                    if (file_exists($oldPath)) unlink($oldPath);
                }
                $featuredImage = 'uploads/blog/' . $filename;
            } else {
                $errors[] = 'Failed to upload image. Please try again.';
            }
        }
    }

    // Update if no errors
    if (empty($errors)) {
        $publishedAt = $post['published_at'];
        if ($formData['status'] === 'published' && $publishedAt === null) {
            $publishedAt = date('Y-m-d H:i:s');
        }

        $stmt = $conn->prepare("UPDATE blog_posts SET title = ?, slug = ?, content = ?, featured_image = ?, status = ?, is_featured = ?, tags = ?, published_at = ? WHERE id = ?");
        $stmt->bind_param('ssssisssi', 
            $formData['title'], 
            $formData['slug'], 
            $formData['content'], 
            $featuredImage, 
            $formData['status'], 
            $formData['is_featured'], 
            $formData['tags'], 
            $publishedAt, 
            $id
        );
        
        if ($stmt->execute()) {
            header('Location: blogs.php?msg=updated');
            exit;
        } else {
            $errors[] = 'Database error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Blog Post — Art Bazaar Admin</title>
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
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid rgba(246,237,222,.1); }
.sidebar-brand .logo-text { font-family: 'Playfair Display', serif; font-size: 18px; font-weight: 500; color: var(--bg); }
.sidebar-brand .logo-tag { font-size: 8px; letter-spacing: 2px; color: var(--sand); margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-left: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--bg); text-decoration: none; font-weight: 400; border-left: 2px solid transparent; transition: all .15s; position: relative; }
.nav-item:hover { color: var(--bg); background: rgba(255,255,255,0.05); border-left-color: rgba(255,255,255,0.2); }
.nav-item.active { color: var(--ink); background: var(--sand); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .7; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid rgba(246,237,222,.1); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: rgba(255,255,255,0.1); color: var(--bg); }

/* ── Topbar ──────────────────────────────────────────── */
.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--ink); border-bottom: 1px solid rgba(246,237,222,.1); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.topbar-left .sub { font-size: 11px; color: var(--sand); margin-top: 1px; }
.admin-chip { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); padding: 5px 12px 5px 5px; border-radius: 30px; }
.admin-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--sand); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--ink); font-weight: 600; }
.admin-chip .name { font-size: 12px; color: var(--bg); font-weight: 500; }
.admin-chip .arrow { font-size: 12px; color: var(--sand); margin-left: 4px; }

/* ── Main ────────────────────────────────────────────── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 28px 32px; max-width: 900px; }

/* ── Back Link ───────────────────────────────────────── */
.back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ink); text-decoration: none; margin-bottom: 20px; font-weight: 500; }
.back-link:hover { text-decoration: underline; }

/* ── Error Box ───────────────────────────────────────── */
.error-box { background: rgba(139,0,0,0.06); border: 1px solid rgba(139,0,0,0.2); color: #8b0000; padding: 14px 20px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; }
.error-box ul { margin-left: 18px; margin-top: 4px; }
.error-box li { margin-bottom: 2px; }

/* ── Form Card ───────────────────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 32px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.full { grid-column: 1 / -1; }
.form-group label { font-size: 10px; font-weight: 600; color: var(--ink); letter-spacing: 1px; text-transform: uppercase; }
.form-input, .form-select, .form-textarea {
    width: 100%; padding: 10px 14px; border: 1.5px solid var(--sand); border-radius: 10px;
    font-family: 'DM Sans', sans-serif; font-size: 13px; color: var(--ink);
    background: var(--bg); outline: none; transition: border-color .15s;
}
.form-textarea { resize: vertical; min-height: 120px; }
.form-textarea.content { min-height: 300px; }
.form-input:focus, .form-select:focus, .form-textarea:focus { border-color: var(--ink); }
.form-hint { font-size: 10px; color: var(--muted); font-style: italic; margin-top: 2px; }

/* Toggle */
.toggle-group { display: flex; align-items: center; gap: 10px; margin-top: 4px; }
.toggle { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.toggle input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--sand); border-radius: 24px; transition: .3s; }
.toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: var(--bg); border-radius: 50%; transition: .3s; }
.toggle input:checked + .toggle-slider { background-color: var(--ink); }
.toggle input:checked + .toggle-slider:before { transform: translateX(20px); }
.toggle-label { font-size: 12px; color: var(--body); }

/* File input */
.file-input-wrapper { position: relative; border: 1.5px dashed var(--sand); border-radius: 10px; padding: 20px; text-align: center; cursor: pointer; transition: border-color .15s; background: var(--bg); }
.file-input-wrapper:hover { border-color: var(--ink); }
.file-input-wrapper input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.file-input-text { font-size: 12px; color: var(--muted); pointer-events: none; }

/* Image Preview */
.image-preview { margin-bottom: 12px; display: inline-block; }
.image-preview img { max-width: 200px; max-height: 150px; border-radius: 8px; border: 1px solid var(--sand); object-fit: cover; }
.image-preview .preview-label { display: block; font-size: 10px; color: var(--muted); margin-top: 6px; }

/* Submit */
.submit-btn { width: 100%; padding: 13px; border: none; border-radius: 10px; background: var(--sand); color: var(--ink); font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 600; cursor: pointer; transition: all .15s; margin-top: 10px; }
.submit-btn:hover { background: var(--ink); color: var(--bg); }

/* ── Footer ──────────────────────────────────────────── */
.dash-footer { background: var(--ink); padding: 20px 32px; font-size: 11px; color: var(--bg); margin-top: 40px; text-align: center; }

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
    .card { padding: 20px; }
    .form-grid { grid-template-columns: 1fr; }
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
    <a href="blogs.php" class="nav-item active">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/></svg>
        Blog Posts
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
        <h1>Edit Post</h1>
        <div class="sub">Modify blog entry</div>
    </div>
    <div class="topbar-right">
        <button class="ham-btn" onclick="openDrawer()"><span></span><span></span><span></span></button>
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <a href="blogs.php" class="back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Blog Posts
    </a>

    <?php if (!empty($errors)): ?>
    <div class="error-box">
        <strong>Please fix the following errors:</strong>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="card">
            <div class="form-grid">
                
                <!-- Title -->
                <div class="form-group full">
                    <label for="title">Title *</label>
                    <input type="text" id="title" name="title" class="form-input" required value="<?= htmlspecialchars($formData['title']) ?>">
                </div>

                <!-- Slug -->
                <div class="form-group full">
                    <label for="slug">Slug</label>
                    <input type="text" id="slug" name="slug" class="form-input" value="<?= htmlspecialchars($formData['slug']) ?>">
                    <div class="form-hint">Only lowercase letters, numbers, and hyphens. Changing this may affect SEO and existing links.</div>
                </div>

                <!-- Status -->
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="draft" <?= $formData['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="published" <?= $formData['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                    </select>
                </div>

                <!-- Is Featured -->
                <div class="form-group">
                    <label>Featured Post</label>
                    <div class="toggle-group">
                        <label class="toggle">
                            <input type="checkbox" name="is_featured" value="1" <?= $formData['is_featured'] ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-label">Mark as featured</span>
                    </div>
                </div>

                <!-- Content -->
                <div class="form-group full">
                    <label for="content">Content *</label>
                    <textarea id="content" name="content" class="form-textarea content" required placeholder="Write the full blog post content here..."><?= htmlspecialchars($formData['content']) ?></textarea>
                </div>

                <!-- Tags -->
                <div class="form-group full">
                    <label for="tags">Tags</label>
                    <input type="text" id="tags" name="tags" class="form-input" value="<?= htmlspecialchars($formData['tags']) ?>" placeholder="e.g. art, painting, tutorial (comma-separated)">
                </div>

                <!-- Featured Image -->
                <div class="form-group full">
                    <label>Featured Image</label>
                    
                    <?php if ($currentImage): ?>
                    <div class="image-preview">
                        <img src="../../<?= htmlspecialchars($currentImage) ?>" alt="Current featured image">
                        <span class="preview-label">Current image — upload a new one to replace</span>
                    </div>
                    <?php endif; ?>

                    <div class="file-input-wrapper">
                        <input type="file" name="featured_image" accept="image/jpeg,image/png,image/webp">
                        <div class="file-input-text"><?= $currentImage ? 'Replace image (JPG, PNG, WebP — Max 2MB)' : 'Click to upload (JPG, PNG, WebP — Max 2MB)' ?></div>
                    </div>
                </div>

            </div>

            <button type="submit" class="submit-btn">Save Changes</button>
        </div>
    </form>

</div>
<div class="dash-footer">Art Bazaar Admin Panel &mdash; <?= date('Y') ?></div>
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

// Auto-sync slug from title if slug matches the expected auto-generated pattern
const titleInput = document.getElementById('title');
const slugInput = document.getElementById('slug');
let originalSlug = slugInput.value;

titleInput.addEventListener('input', function() {
    // Only auto-sync if the user hasn't manually changed the slug
    let expectedSlug = originalSlug;
    if (slugInput.value === expectedSlug || slugInput.dataset.auto === 'true') {
        let slug = this.value.toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/[\s]+/g, '-')
                    .replace(/-+/g, '-');
        slugInput.value = slug;
        slugInput.dataset.auto = 'true';
        originalSlug = slug;
    }
});

slugInput.addEventListener('input', function() {
    // Mark as manually edited
    this.dataset.auto = 'false';
});
</script>
</body>
</html>