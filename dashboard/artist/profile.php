<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// ── Auth guard — artist only ────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'artist') {
    header('Location: ../../login.php');
    exit;
}

$artistId   = (int) $_SESSION['user_id'];
$artistName = $_SESSION['name'] ?? 'Artist';
$successMsg = '';
$errorMsg   = '';

// ── Fetch current data ──────────────────────────────────
$user = $conn->query("SELECT name, email, phone, profile_picture FROM users WHERE id = $artistId")->fetch_assoc();

// Ensure artist_profiles row exists
$conn->query("INSERT IGNORE INTO artist_profiles (user_id) VALUES ($artistId)");

$profile = $conn->query("
    SELECT bio, city, instagram_url, contact_email, contact_phone, art_style, accepts_commissions
    FROM artist_profiles WHERE user_id = $artistId
")->fetch_assoc();

// ── Handle photo removal ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_photo'])) {
    if ($user['profile_picture'] && file_exists(__DIR__ . '/../../' . $user['profile_picture'])) {
        unlink(__DIR__ . '/../../' . $user['profile_picture']);
    }
    $conn->query("UPDATE users SET profile_picture = NULL WHERE id = $artistId");
    $user['profile_picture'] = null;
    $successMsg = 'Photo removed successfully.';
}

// ── Handle main form submission ─────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name               = trim($_POST['name'] ?? '');
    $bio                = trim($_POST['bio'] ?? '');
    $city               = trim($_POST['city'] ?? '');
    $instagram_url      = trim($_POST['instagram_url'] ?? '');
    $contact_email      = trim($_POST['contact_email'] ?? '');
    $contact_phone      = trim($_POST['contact_phone'] ?? '');
    $art_style          = trim($_POST['art_style'] ?? '');
    $accepts_commissions = isset($_POST['accepts_commissions']) ? 1 : 0;

    // Validation
    if ($name === '') {
        $errorMsg = 'Display name is required.';
    } else {

        // ── Profile picture upload ───────────────
        $newPicture = $user['profile_picture'];

        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file       = $_FILES['profile_picture'];
            $maxSize    = 2 * 1024 * 1024;
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];

            if ($file['size'] > $maxSize) {
                $errorMsg = 'Image must be under 2MB.';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt)) {
                    $errorMsg = 'Allowed formats: JPG, PNG, WebP.';
                } else {
                    $uploadDir = __DIR__ . '/../../uploads/profiles/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $filename = 'artist_' . $artistId . '_' . time() . '.' . $ext;
                    $destPath = $uploadDir . $filename;

                    if (move_uploaded_file($file['tmp_name'], $destPath)) {
                        if ($user['profile_picture'] && file_exists(__DIR__ . '/../../' . $user['profile_picture'])) {
                            unlink(__DIR__ . '/../../' . $user['profile_picture']);
                        }
                        $newPicture = 'uploads/profiles/' . $filename;
                    } else {
                        $errorMsg = 'Failed to upload image. Check folder permissions.';
                    }
                }
            }
        }

        if (empty($errorMsg)) {
            // Update users table
            $stmt = $conn->prepare("UPDATE users SET name = ?, profile_picture = ? WHERE id = ?");
            $stmt->bind_param('ssi', $name, $newPicture, $artistId);
            $stmt->execute();

            // Update artist_profiles table
            $stmt = $conn->prepare("
                UPDATE artist_profiles
                SET bio = ?, city = ?, instagram_url = ?, contact_email = ?,
                    contact_phone = ?, art_style = ?, accepts_commissions = ?
                WHERE user_id = ?
            ");
            $stmt->bind_param('ssssssii', $bio, $city, $instagram_url, $contact_email, $contact_phone, $art_style, $accepts_commissions, $artistId);
            $stmt->execute();

            // Refresh session and data
            $_SESSION['name'] = $name;
            $artistName = $name;
            $user    = $conn->query("SELECT name, email, phone, profile_picture FROM users WHERE id = $artistId")->fetch_assoc();
            $profile = $conn->query("
                SELECT bio, city, instagram_url, contact_email, contact_phone, art_style, accepts_commissions
                FROM artist_profiles WHERE user_id = $artistId
            ")->fetch_assoc();

            $successMsg = 'Profile updated successfully.';
        }
    }
}

// ── Avatar URL for display ──────────────────────────────
$avatarUrl = $user['profile_picture'] ? '../../' . $user['profile_picture'] : null;

// ── Fetch categories for reference (not used here but keeps sidebar badge queries consistent) ──
$pendingCount = (int) ($conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'pending'")->fetch_row()[0] ?? 0);
$newCommCount = (int) ($conn->query("SELECT COUNT(*) FROM commission_requests WHERE artist_id = $artistId AND status = 'new'")->fetch_row()[0] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — Art Bazaar</title>
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
    display: flex; flex-direction: column; z-index: 100; overflow-y: auto;
}
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--grey2); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--grey4); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--black); font-weight: 400; margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--terracotta); color: var(--white); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; }
.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 20px; font-size: 12.5px; color: var(--grey5);
    text-decoration: none; font-weight: 400;
    border-left: 2px solid transparent; transition: all .15s;
}
.nav-item:hover { color: var(--black); background: rgba(255,255,255,0.3); border-left-color: var(--grey3); }
.nav-item.active { color: var(--black); background: rgba(255,255,255,0.4); border-left-color: var(--terracotta); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .55; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.badge { margin-left: auto; background: var(--terracotta); color: #fff; font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
.badge.amber { background: var(--amber); }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--grey2); }
.signout-btn {
    display: flex; align-items: center; gap: 8px; padding: 9px 12px;
    font-size: 12px; color: var(--grey5); text-decoration: none; border-radius: 8px;
    transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif;
}
.signout-btn:hover { background: #FFF0EC; color: var(--terracotta); }

/* ── Topbar ──────────────────────────────────────────── */
.topbar {
    position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top);
    background: var(--white); border-bottom: 1px solid var(--grey2);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 32px; z-index: 99;
}
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--black); }
.topbar-right { display: flex; align-items: center; gap: 20px; }
.artist-chip {
    display: flex; align-items: center; gap: 8px;
    background: var(--grey1); border: 1px solid var(--grey2);
    padding: 5px 12px 5px 5px; border-radius: 30px;
}
.artist-chip .avatar {
    width: 26px; height: 26px; border-radius: 50%; background: var(--terracotta);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; color: #fff; font-weight: 600; overflow: hidden;
}
.artist-chip .avatar img { width: 100%; height: 100%; object-fit: cover; }
.artist-chip .name { font-size: 12px; font-weight: 500; color: var(--black); }
.artist-chip .arrow { font-size: 12px; color: var(--grey4); margin-left: 4px; }

/* ── Main ────────────────────────────────────────────── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; max-width: 780px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; margin-bottom: 20px; }

/* ── Messages ────────────────────────────────────────── */
.msg { padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
.msg.success { background: #E8F5EE; color: #6BA58D; border: 1px solid #C8E0D5; }
.msg.error { background: #FDEAEA; color: #D46A6A; border: 1px solid #F5C6C6; }

/* ── Profile Card ────────────────────────────────────── */
.profile-card { background: var(--white); border: 1px solid var(--grey2); border-radius: 16px; overflow: hidden; margin-bottom: 24px; }
.profile-card-header {
    padding: 24px 28px 20px; border-bottom: 1px solid var(--grey2);
    display: flex; align-items: center; justify-content: space-between;
}
.profile-card-header h2 { font-family: 'Playfair Display', serif; font-size: 18px; font-weight: 400; color: var(--black); }
.profile-card-header .hint { font-size: 11px; color: var(--grey4); }
.profile-card-body { padding: 28px; }

/* ── Avatar upload ───────────────────────────────────── */
.avatar-upload { display: flex; align-items: center; gap: 24px; padding-bottom: 28px; }
.avatar-preview {
    width: 100px; height: 100px; border-radius: 50%; flex-shrink: 0;
    background: var(--grey1); border: 2px solid var(--grey2);
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; position: relative; cursor: pointer; transition: border-color .2s;
}
.avatar-preview:hover { border-color: var(--black); }
.avatar-preview img { width: 100%; height: 100%; object-fit: cover; }
.avatar-preview .placeholder-icon { color: var(--grey3); }
.avatar-preview .overlay {
    position: absolute; inset: 0; background: rgba(0,0,0,.45);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .2s; border-radius: 50%;
}
.avatar-preview:hover .overlay { opacity: 1; }
.avatar-info { flex: 1; }
.avatar-info .avatar-label { font-size: 13px; font-weight: 500; margin-bottom: 4px; color: var(--black); }
.avatar-info .avatar-hint { font-size: 11px; color: var(--grey4); line-height: 1.5; }
.avatar-info .avatar-hint span { color: var(--terracotta); font-weight: 500; }
.avatar-remove {
    margin-top: 8px; font-size: 11px; color: var(--terracotta); background: none;
    border: none; cursor: pointer; font-family: 'DM Sans', sans-serif;
    text-decoration: underline; display: none;
}
.avatar-remove.visible { display: inline-block; }

/* ── Form fields ─────────────────────────────────────── */
.field-group { margin-bottom: 22px; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.field-label {
    display: block; font-size: 11px; letter-spacing: .8px; text-transform: uppercase;
    color: var(--grey5); font-weight: 500; margin-bottom: 7px;
}
.field-label .optional { font-weight: 400; text-transform: none; letter-spacing: 0; color: var(--grey4); }
.field-label .private-tag {
    font-weight: 400; text-transform: none; letter-spacing: 0;
    background: var(--grey1); border: 1px solid var(--grey2);
    padding: 1px 7px; border-radius: 10px; font-size: 9px; margin-left: 6px; color: var(--grey4);
}
.field-input {
    width: 100%; padding: 10px 14px; font-size: 13px; font-family: 'DM Sans', sans-serif;
    border: 1px solid var(--grey3); border-radius: 10px; background: var(--white);
    color: var(--black); transition: border-color .15s; outline: none;
}
.field-input:focus { border-color: var(--black); }
.field-input::placeholder { color: var(--grey4); }
textarea.field-input { resize: vertical; min-height: 110px; line-height: 1.6; }

/* ── Toggle switch ───────────────────────────────────── */
.toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 22px 28px;
}
.toggle-title { font-size: 13px; font-weight: 500; margin-bottom: 3px; color: var(--black); }
.toggle-desc { font-size: 11px; color: var(--grey4); }
.toggle-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; inset: 0; cursor: pointer;
    background: var(--grey3); border-radius: 24px; transition: background .2s;
}
.toggle-slider::before {
    content: ''; position: absolute; left: 3px; top: 3px;
    width: 18px; height: 18px; background: #fff; border-radius: 50%; transition: transform .2s;
}
.toggle-switch input:checked + .toggle-slider { background: var(--black); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

/* ── Buttons ─────────────────────────────────────────── */
.form-actions { padding-top: 24px; border-top: 1px solid var(--grey2); display: flex; align-items: center; justify-content: space-between; }
.btn {
    padding: 10px 24px; border-radius: 10px; font-size: 12.5px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer; border: none;
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all .15s;
}
.btn-primary { background: var(--black); color: #fff; }
.btn-primary:hover { background: #333; }
.btn-ghost { background: transparent; color: var(--grey5); border: 1px solid var(--grey3); }
.btn-ghost:hover { border-color: var(--black); color: var(--black); }

/* ── Info box ────────────────────────────────────────── */
.info-box { background: var(--grey1); border: 1px solid var(--grey2); border-radius: 10px; padding: 12px 16px; margin-top: 4px; }
.info-box p { font-size: 11px; color: var(--grey4); line-height: 1.5; }
.info-box strong { color: var(--grey5); }

/* ── Footer ──────────────────────────────────────────── */
.dash-footer { padding: 20px 32px; border-top: 1px solid var(--grey2); font-size: 11px; color: var(--grey4); margin-top: 12px; }

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 900px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; }
    .field-row { grid-template-columns: 1fr; }
    .content { padding: 20px; }
}
@media (max-width: 600px) {
    .avatar-upload { flex-direction: column; text-align: center; }
    .form-actions { flex-direction: column; gap: 10px; }
    .form-actions .btn { width: 100%; justify-content: center; }
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
        <?php if ($pendingCount > 0): ?><span class="badge amber"><?= $pendingCount ?></span><?php endif; ?>
    </a>
    <a href="commissions.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Commission Requests
        <?php if ($newCommCount > 0): ?><span class="badge"><?= $newCommCount ?></span><?php endif; ?>
    </a>
    <div class="sidebar-section">Account</div>
    <a href="profile.php" class="nav-item active">
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
    <div class="topbar-left"><h1>My Profile</h1></div>
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

    <div class="section-title">Edit Your Profile</div>

    <?php if ($successMsg): ?>
        <div class="msg success">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= htmlspecialchars($successMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="msg error">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="profileForm">

        <!-- ── Profile Picture ────────────────────── -->
        <div class="profile-card">
            <div class="profile-card-header">
                <h2>Profile Picture</h2>
                <span class="hint">Recommended: 400 &times; 400px</span>
            </div>
            <div class="profile-card-body">
                <div class="avatar-upload">
                    <div class="avatar-preview" onclick="document.getElementById('fileInput').click()">
                        <?php if ($avatarUrl): ?>
                            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Profile" id="avatarImg">
                        <?php else: ?>
                            <svg class="placeholder-icon" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                        <?php endif; ?>
                        <div class="overlay">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        </div>
                    </div>
                    <input type="file" name="profile_picture" id="fileInput" accept="image/jpeg,image/png,image/webp" hidden>
                    <div class="avatar-info">
                        <div class="avatar-label">Upload a photo</div>
                        <div class="avatar-hint">JPG, PNG, or WebP. Max <span>2MB</span>.<br>This appears on your public artist profile.</div>
                        <button type="button" class="avatar-remove <?= $user['profile_picture'] ? 'visible' : '' ?>" id="removeBtn">Remove current photo</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Basic Information ──────────────────── -->
        <div class="profile-card">
            <div class="profile-card-header">
                <h2>Basic Information</h2>
                <span class="hint">Visible to everyone</span>
            </div>
            <div class="profile-card-body">
                <div class="field-group">
                    <label class="field-label">Display Name *</label>
                    <input type="text" name="name" class="field-input" value="<?= htmlspecialchars($user['name']) ?>" placeholder="Your name as it appears on your profile" required>
                </div>
                <div class="field-group">
                    <label class="field-label">Bio <span class="optional">(optional)</span></label>
                    <textarea name="bio" class="field-input" placeholder="Tell buyers about yourself, your artistic journey, inspiration, and techniques..."><?= htmlspecialchars($profile['bio'] ?? '') ?></textarea>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label class="field-label">City *</label>
                        <input type="text" name="city" class="field-input" value="<?= htmlspecialchars($profile['city'] ?? '') ?>" placeholder="e.g. Lahore, Karachi, Islamabad">
                    </div>
                    <div class="field-group">
                        <label class="field-label">Art Style <span class="optional">(optional)</span></label>
                        <input type="text" name="art_style" class="field-input" value="<?= htmlspecialchars($profile['art_style'] ?? '') ?>" placeholder="e.g. Contemporary, Abstract, Realism">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Social & Contact ───────────────────── -->
        <div class="profile-card">
            <div class="profile-card-header">
                <h2>Social &amp; Contact</h2>
                <span class="hint">Links are public, contact details are private</span>
            </div>
            <div class="profile-card-body">
                <div class="field-group">
                    <label class="field-label">Instagram URL <span class="optional">(optional)</span></label>
                    <input type="url" name="instagram_url" class="field-input" value="<?= htmlspecialchars($profile['instagram_url'] ?? '') ?>" placeholder="https://instagram.com/yourhandle">
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label class="field-label">Contact Email <span class="private-tag">Private</span> <span class="optional">(optional)</span></label>
                        <input type="email" name="contact_email" class="field-input" value="<?= htmlspecialchars($profile['contact_email'] ?? '') ?>" placeholder="your@email.com">
                    </div>
                    <div class="field-group">
                        <label class="field-label">Contact Phone <span class="private-tag">Private</span> <span class="optional">(optional)</span></label>
                        <input type="text" name="contact_phone" class="field-input" value="<?= htmlspecialchars($profile['contact_phone'] ?? '') ?>" placeholder="03XX-XXXXXXX">
                    </div>
                </div>
                <div class="info-box">
                    <p>
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:-2px;margin-right:4px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        Private fields are <strong>only visible to admin</strong> — never shown on your public profile. Buyers contact you through our inquiry system.
                    </p>
                </div>
            </div>
        </div>

        <!-- ── Commission Toggle ──────────────────── -->
        <div class="profile-card">
            <div class="toggle-row">
                <div class="toggle-info">
                    <div class="toggle-title">Accept Commission Requests</div>
                    <div class="toggle-desc">When enabled, buyers can send you custom artwork requests through your profile.</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="accepts_commissions" value="1" <?= ($profile['accepts_commissions'] ?? 1) ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>

        <!-- ── Actions ────────────────────────────── -->
        <div class="form-actions">
            <a href="index.php" class="btn btn-ghost">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Dashboard
            </a>
            <button type="submit" class="btn btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Changes
            </button>
        </div>

    </form>

</div>
<div class="dash-footer">Art Bazaar &mdash; Artist Dashboard &mdash; <?= date('Y') ?></div>
</main>

<script>
// ── Live image preview ─────────────────────────────────
document.getElementById('fileInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
        alert('Image must be under 2MB.');
        this.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(ev) {
        const img = document.getElementById('avatarImg');
        if (img) {
            img.src = ev.target.result;
        } else {
            const preview = document.querySelector('.avatar-preview');
            const placeholder = preview.querySelector('.placeholder-icon');
            if (placeholder) placeholder.remove();
            const newImg = document.createElement('img');
            newImg.id = 'avatarImg';
            newImg.src = ev.target.result;
            newImg.alt = 'Preview';
            preview.insertBefore(newImg, preview.firstChild);
        }
        document.getElementById('removeBtn').classList.add('visible');
    };
    reader.readAsDataURL(file);
});

// ── Remove photo ───────────────────────────────────────
document.getElementById('removeBtn').addEventListener('click', function(e) {
    e.preventDefault();

    // Submit a hidden removal form
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'remove_photo';
    input.value = '1';

    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
});
</script>

</body>
</html>