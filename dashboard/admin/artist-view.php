<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

 $adminName = $_SESSION['name'] ?? 'Admin';
 $toast = '';
 $id = (int)($_GET['id'] ?? 0);

if (!$id) { header('Location: artists.php'); exit; }

// Fetch artist
 $stmt = $conn->prepare("
    SELECT u.*, ap.bio, ap.city, ap.address, ap.instagram_url, ap.contact_email, ap.contact_phone,
           ap.art_style, ap.accepts_commissions, ap.is_featured, ap.profile_complete,
           ap.has_bank_account, ap.bank_name, ap.bank_account_title, ap.bank_account_number,
           ap.has_easypaisa, ap.easypaisa_name, ap.easypaisa_number,
           ap.has_jazzcash, ap.jazzcash_name, ap.jazzcash_number,
           ap.has_nayapay, ap.nayapay_name, ap.nayapay_number,
           ap.has_sadapay, ap.sadapay_name, ap.sadapay_number,
           u.status_reason
    FROM users u
    LEFT JOIN artist_profiles ap ON ap.user_id = u.id
    WHERE u.id = ? AND u.role = 'artist'
");
 $stmt->bind_param('i', $id);
 $stmt->execute();
 $result = $stmt->get_result();
 $artist = $result->fetch_assoc();

if (!$artist) { header('Location: artists.php'); exit; }

// Change 1: Add completeness calculation after fetching the artist
 $missingFields = [];
if (empty($artist['bio']))             $missingFields[] = 'Bio';
if (empty($artist['city']))            $missingFields[] = 'City';
if (empty($artist['address']))         $missingFields[] = 'Address';
if (empty($artist['art_style']))       $missingFields[] = 'Art Style';
if (empty($artist['profile_picture'])) $missingFields[] = 'Profile Picture';
$hasPayment = $artist['has_bank_account'] || $artist['has_easypaisa'] || $artist['has_jazzcash'] || $artist['has_nayapay'] || $artist['has_sadapay'];
if (!$hasPayment)                      $missingFields[] = 'Payment Method';
$isComplete = empty($missingFields);

// Fetch artworks
 $artworks = [];
 $awRes = $conn->prepare("
    SELECT a.*, c.name AS category_name
    FROM artworks a
    JOIN categories c ON c.id = a.category_id
    WHERE a.artist_id = ?
    ORDER BY a.created_at DESC
");
 $awRes->bind_param('i', $id);
 $awRes->execute();
 $awResult = $awRes->get_result();

while ($row = $awResult->fetch_assoc()) {
    $imgStmt = $conn->prepare("
        SELECT image_path FROM artwork_images 
        WHERE artwork_id = ? AND is_cover = 1 
        LIMIT 1
    ");
    $imgStmt->bind_param('i', $row['id']);
    $imgStmt->execute();
    $imgResult = $imgStmt->get_result();
    $coverImg = $imgResult->fetch_assoc();
    $row['cover_image'] = $coverImg ? $coverImg['image_path'] : null;
    $imgStmt->close();
    
    $artworks[] = $row;
}

 $artworkCounts = ['active' => 0, 'sold' => 0, 'hidden' => 0];
foreach ($artworks as $aw) {
    $status = $aw['status'];
    if (isset($artworkCounts[$status])) {
        $artworkCounts[$status]++;
    }
}

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'block') {
        // Change 2: Update the block action to save the reason
        $reason = $conn->real_escape_string(trim($_POST['status_reason'] ?? ''));
        $conn->query("UPDATE users SET status = 'blocked', status_reason = '$reason' WHERE id = $id");
        $artist['status'] = 'blocked';
        $artist['status_reason'] = $reason;
        $toast = 'Artist blocked.';
    } elseif ($action === 'unblock') {
        // Change 3: Clear reason on unblock
        $conn->query("UPDATE users SET status = 'active', status_reason = NULL WHERE id = $id");
        $artist['status'] = 'active';
        $artist['status_reason'] = null;
        $toast = 'Artist unblocked.';
    } elseif ($action === 'toggle_featured') {
        $conn->query("UPDATE artist_profiles SET is_featured = IF(is_featured=1, 0, 1) WHERE user_id = $id");
        $artist['is_featured'] = $artist['is_featured'] ? 0 : 1;
        $toast = $artist['is_featured'] ? 'Marked as featured.' : 'Removed from featured.';
    } elseif ($action === 'toggle_commissions') {
        $newVal = $artist['accepts_commissions'] ? 0 : 1;
        $conn->query("UPDATE artist_profiles SET accepts_commissions = $newVal WHERE user_id = $id");
        $artist['accepts_commissions'] = $newVal;
        $toast = $newVal ? 'Commissions enabled.' : 'Commissions disabled.';
    } elseif ($action === 'delete_artist') {
        // Delete artist's artwork images from disk
        $imgs = $conn->query("SELECT ai.image_path FROM artwork_images ai JOIN artworks a ON a.id = ai.artwork_id WHERE a.artist_id = $id");
        $uploadDir = __DIR__ . '/../../uploads/artworks/';
        while ($img = $imgs->fetch_assoc()) { 
            $f = $uploadDir . $img['image_path']; 
            if (file_exists($f)) unlink($f); 
        }
        
        // Delete profile picture
        if ($artist['profile_picture']) { 
            $pf = __DIR__ . '/../../' . $artist['profile_picture']; 
            if (file_exists($pf)) unlink($pf); 
        }
        
        // Delete from Orders (New Schema)
        $conn->query("DELETE FROM order_items WHERE item_type='artwork' AND item_id IN (SELECT id FROM artworks WHERE artist_id = $id)");
        
        $conn->query("DELETE FROM artwork_images WHERE artwork_id IN (SELECT id FROM artworks WHERE artist_id = $id)");
        $conn->query("DELETE FROM artworks WHERE artist_id = $id");
        $conn->query("DELETE FROM artist_profiles WHERE user_id = $id");
        $conn->query("DELETE FROM users WHERE id = $id");
        header('Location: artists.php'); 
        exit;
    }
}

function getProfileImageUrl($imagePath) {
    if (empty($imagePath)) {
        return null;
    }
    $imagePath = ltrim($imagePath, './');
    if (strpos($imagePath, 'uploads/') === 0) {
        return '../../' . $imagePath;
    }
    return '../../uploads/profiles/' . $imagePath;
}

function getArtworkImageUrl($imagePath) {
    if (empty($imagePath)) {
        return null;
    }
    $imagePath = ltrim($imagePath, './');
    if (strpos($imagePath, 'uploads/') === 0) {
        return '../../' . $imagePath;
    }
    return '../../uploads/artworks/' . $imagePath;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Artist Profile — Art Bazaar Admin</title>
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
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }

/* Sidebar */
.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: var(--ink); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--border); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--sand); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--bg); text-decoration: none; font-weight: 400; border-left: 2px solid transparent; transition: all .15s; }
.nav-item:hover { color: var(--ink); background: var(--sand); border-left-color: var(--sand); }
.nav-item.active { color: var(--ink); background: var(--sand); border-left-color: var(--ink); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .8; stroke: var(--bg); }
.nav-item.active .icon, .nav-item:hover .icon { stroke: var(--ink); opacity: 1; }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: var(--sand); color: var(--ink); }

/* Topbar */
.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--ink); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left { display: flex; align-items: center; gap: 14px; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.back-link { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--bg); text-decoration: none; opacity: 0.7; }
.back-link:hover { opacity: 1; color: var(--sand); }
.admin-chip { display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); padding: 5px 12px 5px 5px; border-radius: 30px; }
.admin-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--bg); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--ink); font-weight: 600; }
.admin-chip .name { font-size: 12px; color: var(--ink); font-weight: 500; }
.admin-chip .arrow { font-size: 12px; color: var(--ink); margin-left: 4px; opacity: 0.6; }

.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 28px 32px; max-width: 1000px; }

.toast { background: var(--sand); color: var(--ink); border: 1px solid var(--border); padding: 12px 20px; border-radius: 10px; font-size: 12.5px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }
.toast.hidden { display: none; }
.toast-close { background: none; border: none; color: var(--ink); cursor: pointer; font-size: 16px; opacity: 0.6; }

.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; font-size: 12px; font-weight: 500; border-radius: 10px; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .15s; text-decoration: none; white-space: nowrap; }
.btn-primary { background: var(--ink); color: var(--bg); }
.btn-primary:hover { background: #1a5a48; }
.btn-ghost { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--ink); color: var(--ink); background: var(--sand); }
.btn-green { background: var(--ink); color: var(--bg); }
.btn-green:hover { background: #1a5a48; }
.btn-red { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.btn-red:hover { background: var(--sand); }
.btn-amber { background: var(--ink); color: var(--bg); }
.btn-amber:hover { background: #1a5a48; }
.btn-blue { background: var(--ink); color: var(--bg); }
.btn-blue:hover { background: #1a5a48; }
.btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 7px; }

.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.pill.active { background: var(--ink); color: var(--bg); }
.pill.pending { background: var(--sand); color: var(--ink); }
.pill.blocked { background: var(--sand); color: var(--ink); }
.featured-tag { display: inline-flex; align-items: center; gap: 3px; font-size: 10px; color: var(--ink); font-weight: 600; }

/* Profile Header */
.profile-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 28px; gap: 20px; flex-wrap: wrap; }
.profile-info { display: flex; gap: 20px; align-items: flex-start; }
.profile-pic { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; background: var(--sand); border: 2px solid var(--border); flex-shrink: 0; }
.profile-pic-placeholder { width: 72px; height: 72px; border-radius: 50%; background: var(--ink); display: flex; align-items: center; justify-content: center; font-size: 26px; color: var(--bg); font-weight: 600; flex-shrink: 0; border: 2px solid var(--border); }
.profile-details h2 { font-family: 'Playfair Display', serif; font-size: 24px; font-weight: 400; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; color: var(--ink); }
.profile-meta { font-size: 12px; color: var(--ink); margin-top: 6px; display: flex; flex-wrap: wrap; gap: 8px; opacity: 0.7; }
.profile-meta span { display: inline-flex; align-items: center; gap: 4px; }
.profile-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* Stats Row */
.stats-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 28px; }
.mini-stat { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px; text-align: center; }
.mini-stat .num { font-family: 'Playfair Display', serif; font-size: 28px; font-weight: 400; color: var(--ink); }
.mini-stat .lbl { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-top: 4px; opacity: 0.7; }

/* Two Col Info */
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.card-head { padding: 16px 22px; border-bottom: 1px solid var(--border); font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: var(--ink); font-weight: 500; }
.card-body { padding: 22px; }

.info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border); }
.info-row:last-child { border-bottom: none; }
.info-row .label { font-size: 11px; color: var(--ink); text-transform: uppercase; letter-spacing: 1px; opacity: 0.7; }
.info-row .value { font-size: 12.5px; color: var(--ink); font-weight: 500; text-align: right; max-width: 60%; word-break: break-all; }
.info-row .value a { color: var(--ink); text-decoration: none; }
.info-row .value a:hover { text-decoration: underline; }
.info-row .value.muted { color: var(--ink); opacity: 0.5; font-weight: 400; }
.bio-text { font-size: 13px; color: var(--ink); line-height: 1.7; white-space: pre-wrap; }

/* Artworks Grid */
.aw-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px; }
.aw-card { border-radius: 6px; overflow: hidden; border: 1px solid var(--border); background: var(--card); transition: box-shadow .15s; }
.aw-card:hover { box-shadow: 0 4px 16px rgba(12,63,48,0.08); }
.aw-card img { width: 100%; aspect-ratio: 1; object-fit: cover; background: var(--sand); display: block; }
.aw-card-info { padding: 10px 12px; }
.aw-card-info .aw-title { font-size: 12px; font-weight: 500; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.aw-card-info .aw-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 4px; }
.aw-card-info .aw-price { font-size: 11px; font-weight: 600; color: var(--ink); }
.aw-card-info .aw-link { font-size: 10px; color: var(--ink); text-decoration: none; }
.aw-card-info .aw-link:hover { text-decoration: underline; }
.empty-sm { text-align: center; padding: 30px; color: var(--ink); font-size: 12px; opacity: 0.7; }

.dash-footer { padding: 20px 32px; border-top: 1px solid var(--border); font-size: 11px; color: var(--bg); margin-top: 12px; background: var(--ink); }

.modal-overlay { position: fixed; inset: 0; background: rgba(12,63,48,0.4); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .2s; }
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal { background: var(--card); border-radius: 16px; width: 420px; max-width: 92vw; box-shadow: 0 24px 60px rgba(12,63,48,0.2); border: 1px solid var(--border); }
.modal-head { padding: 24px 28px 0; }
.modal-head h3 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--ink); }
.modal-body { padding: 20px 28px; }
.confirm-text { font-size: 13px; color: var(--ink); line-height: 1.6; }
.confirm-text strong { color: var(--ink); }
.modal-foot { padding: 0 28px 24px; display: flex; gap: 10px; justify-content: flex-end; }

/* Hamburger Drawer */
#nav-drawer { display:none; position: fixed; top: 0; right: 0; width: 260px; height: 100vh; background: var(--ink); z-index: 200; transform: translateX(100%); transition: transform 0.3s ease; padding: 24px; display: flex; flex-direction: column; border-left: 1px solid var(--border); }
#nav-drawer.open { transform: translateX(0); display: flex; }
#nav-overlay { display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(12,63,48,0.4); z-index: 150; backdrop-filter: blur(2px); }
#nav-overlay.open { display: block; }
.ham-btn { display: none; flex-direction: column; gap: 5px; background: none; border: none; cursor: pointer; padding: 5px; width: 30px; }
.ham-btn span { width: 100%; height: 2px; background: var(--bg); border-radius: 2px; transition: 0.2s; }
.d-header { font-family: 'Playfair Display', serif; font-size: 18px; color: var(--bg); margin-bottom: 24px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
.d-link { color: var(--bg); text-decoration: none; font-size: 14px; padding: 12px 0; display: block; border-bottom: 1px solid rgba(246,237,222,0.1); font-family: 'DM Sans', sans-serif; }
.d-link:hover { color: var(--sand); padding-left: 5px; transition: 0.2s; }

/* Mobile Responsive */
@media (max-width: 1080px) {
    .stats-row { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 768px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; padding: 0 16px; }
    .content { padding: 16px; }
    .profile-header { flex-direction: column; }
    .info-grid { grid-template-columns: 1fr; }
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .aw-grid { grid-template-columns: repeat(2, 1fr); }
    .profile-actions { display: flex; flex-direction: column; width: 100%; }
    .profile-actions form, .profile-actions button { width: 100%; }
    .card-body { padding: 16px; }
    .dash-footer { padding: 20px 16px; text-align: center; }
    
    .ham-btn { display: flex; }
    .admin-chip { display: none; }
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
    <a href="artworks.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg> Artworks</a>
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
        <a href="<?= htmlspecialchars($_GET['back'] ?? 'artists.php') ?>" class="back-link">troke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Artists</a>
        <h1>Artist Profile</h1>
    </div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:12px;">
        <div class="admin-chip">
            <div class="avatar"><?= strtoupper(substr($adminName, 0, 1)) ?></div>
            <span class="name"><?= htmlspecialchars($adminName) ?></span>
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

    <?php if ($toast): ?>
    <div class="toast"><span><?= htmlspecialchars($toast) ?></span><button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button></div>
    <?php endif; ?>

    <!-- Profile header -->
    <div class="profile-header">
        <div class="profile-info">
            <?php 
            $profilePicUrl = getProfileImageUrl($artist['profile_picture'] ?? '');
            if ($profilePicUrl): ?>
                <img class="profile-pic" src="<?= htmlspecialchars($profilePicUrl) ?>" alt="">
            <?php else: ?>
                <div class="profile-pic-placeholder"><?= strtoupper(substr($artist['name'], 0, 1)) ?></div>
            <?php endif; ?>
            <div class="profile-details">
                <h2>
                    <?= htmlspecialchars($artist['name']) ?>
                    <?php if ($artist['is_featured']): ?><span class="featured-tag">★ Featured</span><?php endif; ?>
                    <!-- Change 4: Add Complete/Incomplete badge -->
                    <span class="pill <?= $artist['status'] ?>"><?= ucfirst($artist['status']) ?></span>
                    <?php if ($isComplete): ?>
                        <span class="pill" style="background:var(--ink);color:var(--bg);">✓ Complete</span>
                    <?php else: ?>
                        <span class="pill" style="background:var(--sand);color:var(--ink);">⚠ Incomplete</span>
                    <?php endif; ?>
                </h2>
                <div class="profile-meta">
                    <span>📍 <?= htmlspecialchars($artist['city'] ?? 'No city set') ?></span>
                    <span>📅 Joined <?= date('d M Y', strtotime($artist['created_at'])) ?></span>
                    <span>ID #<?= $artist['id'] ?></span>
                </div>
            </div>
        </div>
        <div class="profile-actions">
            <?php if ($artist['status'] === 'active'): ?>
                <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_featured"><button type="submit" class="btn btn-amber btn-sm"><?= $artist['is_featured'] ? 'Unfeature' : 'Feature' ?></button></form>
                <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_commissions"><button type="submit" class="btn btn-blue btn-sm"><?= $artist['accepts_commissions'] ? 'Disable Commissions' : 'Enable Commissions' ?></button></form>
                <!-- Change 6: Replace active block button with modal trigger -->
                <button type="button" class="btn btn-red btn-sm" onclick="openBlock()">Block</button>
            <?php elseif ($artist['status'] === 'blocked'): ?>
                <form method="POST" style="display:inline"><input type="hidden" name="action" value="unblock"><button type="submit" class="btn btn-green btn-sm">Unblock</button></form>
            <?php endif; ?>
            <button type="button" class="btn btn-red btn-sm" onclick="openDelete()">Delete Artist</button>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="mini-stat"><div class="num"><?= count($artworks) ?></div><div class="lbl">Total</div></div>
        <div class="mini-stat"><div class="num"><?= $artworkCounts['active'] ?></div><div class="lbl">Active</div></div>
        <div class="mini-stat"><div class="num"><?= $artworkCounts['sold'] ?></div><div class="lbl">Sold</div></div>
        <div class="mini-stat"><div class="num"><?= $artworkCounts['hidden'] ?></div><div class="lbl">Hidden</div></div>
    </div>

    <!-- Two col info -->
    <div class="info-grid">
        <div class="card">
            <div class="card-head">Contact Details (Private)</div>
            <div class="card-body">
                <div class="info-row"><span class="label">Email</span><span class="value"><?= htmlspecialchars($artist['email']) ?></span></div>
                <div class="info-row"><span class="label">Address</span><span class="value <?= !$artist['address'] ? 'muted' : '' ?>"><?= $artist['address'] ? htmlspecialchars($artist['address']) : 'Not set' ?></span></div>
                <div class="info-row"><span class="label">Phone</span><span class="value <?= !$artist['phone'] ? 'muted' : '' ?>"><?= $artist['phone'] ? htmlspecialchars($artist['phone']) : 'Not set' ?></span></div>
                <div class="info-row"><span class="label">Contact Email</span><span class="value <?= !$artist['contact_email'] ? 'muted' : '' ?>"><?= $artist['contact_email'] ? htmlspecialchars($artist['contact_email']) : ($artist['email'] ? htmlspecialchars($artist['email']) : 'Not set') ?></span></div>
<div class="info-row"><span class="label">Contact Phone</span><span class="value <?= !$artist['contact_phone'] ? 'muted' : '' ?>"><?= $artist['contact_phone'] ? htmlspecialchars($artist['contact_phone']) : ($artist['phone'] ? htmlspecialchars($artist['phone']) : 'Not set') ?></span></div>
            </div>
        </div>
        <div class="card">
            <div class="card-head">Profile Info</div>
            <div class="card-body">
                <div class="info-row"><span class="label">Art Style</span><span class="value <?= !$artist['art_style'] ? 'muted' : '' ?>"><?= $artist['art_style'] ? htmlspecialchars($artist['art_style']) : 'Not set' ?></span></div>
                <div class="info-row"><span class="label">Commissions</span><span class="value"><?= $artist['accepts_commissions'] ? '✓ Accepted' : '✗ Disabled' ?></span></div>
                <div class="info-row"><span class="label">Instagram</span><span class="value <?= !$artist['instagram_url'] ? 'muted' : '' ?>"><?= $artist['instagram_url'] ? '<a href="' . htmlspecialchars($artist['instagram_url']) . '" target="_blank">' . htmlspecialchars($artist['instagram_url']) . '</a>' : 'Not set' ?></span></div>
                <div class="info-row"><span class="label">Bio</span></div>
                <div class="bio-text"><?= $artist['bio'] ? nl2br(htmlspecialchars($artist['bio'])) : '<span style="opacity:0.5">No bio written.</span>' ?></div>
                
                <!-- Change 5: Show missing fields and current block reason -->
                <?php if (!$isComplete): ?>
                <div class="info-row">
                    <span class="label">Missing Fields</span>
                    <span class="value" style="color:#b45309;"><?= implode(', ', $missingFields) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($artist['status_reason'])): ?>
                <div class="info-row">
                    <span class="label">Block Reason</span>
                    <span class="value" style="color:#b45309;"><?= htmlspecialchars($artist['status_reason']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payment Methods -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-head">Payment Methods (Private)</div>
    <div class="card-body">
        <?php if ($artist['has_bank_account']): ?>
        <div class="info-row"><span class="label">Bank</span><span class="value"><?= htmlspecialchars($artist['bank_name'] ?? '—') ?> — <?= htmlspecialchars($artist['bank_account_title'] ?? '') ?> — <?= htmlspecialchars($artist['bank_account_number'] ?? '') ?></span></div>
        <?php endif; ?>
        <?php if ($artist['has_easypaisa']): ?>
        <div class="info-row"><span class="label">Easypaisa</span><span class="value"><?= htmlspecialchars($artist['easypaisa_name'] ?? '—') ?> — <?= htmlspecialchars($artist['easypaisa_number'] ?? '') ?></span></div>
        <?php endif; ?>
        <?php if ($artist['has_jazzcash']): ?>
        <div class="info-row"><span class="label">JazzCash</span><span class="value"><?= htmlspecialchars($artist['jazzcash_name'] ?? '—') ?> — <?= htmlspecialchars($artist['jazzcash_number'] ?? '') ?></span></div>
        <?php endif; ?>
        <?php if ($artist['has_nayapay']): ?>
        <div class="info-row"><span class="label">NayaPay</span><span class="value"><?= htmlspecialchars($artist['nayapay_name'] ?? '—') ?> — <?= htmlspecialchars($artist['nayapay_number'] ?? '') ?></span></div>
        <?php endif; ?>
        <?php if ($artist['has_sadapay']): ?>
        <div class="info-row"><span class="label">SadaPay</span><span class="value"><?= htmlspecialchars($artist['sadapay_name'] ?? '—') ?> — <?= htmlspecialchars($artist['sadapay_number'] ?? '') ?></span></div>
        <?php endif; ?>
        <?php if (!$artist['has_bank_account'] && !$artist['has_easypaisa'] && !$artist['has_jazzcash'] && !$artist['has_nayapay'] && !$artist['has_sadapay']): ?>
        <div style="font-size:12px;color:var(--ink);opacity:0.5;">No payment methods added yet.</div>
        <?php endif; ?>
    </div>
</div>
    <!-- Artworks -->
    <div class="card" style="margin-bottom:28px;">
        <div class="card-head">Artworks by <?= htmlspecialchars($artist['name']) ?> (<?= count($artworks) ?>)</div>
        <?php if (empty($artworks)): ?>
            <div class="empty-sm">This artist hasn't uploaded any artworks yet.</div>
        <?php else: ?>
        <div style="padding:18px;">
            <div class="aw-grid">
                <?php foreach ($artworks as $aw): ?>
                <?php 
                $artworkImageUrl = getArtworkImageUrl($aw['cover_image'] ?? '');
                ?>
                <div class="aw-card">
                    <?php if ($artworkImageUrl): ?>
                        <img src="<?= htmlspecialchars($artworkImageUrl) ?>" alt="<?= htmlspecialchars($aw['title']) ?>">
                    <?php else: ?>
                        <div style="width:100%;aspect-ratio:1;background:var(--sand);display:flex;align-items:center;justify-content:center;color:var(--ink);font-size:10px;opacity:0.5;">No image</div>
                    <?php endif; ?>
                    <div class="aw-card-info">
    <div class="aw-title" title="<?= htmlspecialchars($aw['title']) ?>"><?= htmlspecialchars($aw['title']) ?></div>
    <div class="aw-bottom">
        <span class="aw-price">PKR <?= number_format($aw['price']) ?></span>
        <span class="pill <?= $aw['status'] ?>" style="font-size:8px;padding:2px 6px;"><?= ucfirst($aw['status']) ?></span>
    </div>
</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>
<div class="dash-footer">Art Bazaar Admin Panel &mdash; <?= date('Y') ?></div>
</main>

<!-- HAMBURGER DRAWER HTML -->
<div id="nav-overlay"></div>
<div id="nav-drawer">
  <div class="d-header">Menu</div>
  <a href="index.php" class="d-link">Overview</a>
  <a href="artworks.php" class="d-link">Artworks</a>
  <a href="artists.php" class="d-link">Artists</a>
  <a href="categories.php" class="d-link">Categories</a>
  <a href="inquiries.php" class="d-link">Buyer Inquiries</a>
  <a href="commissions.php" class="d-link">Commissions</a>
  <a href="messages.php" class="d-link">Messages</a>
  <div style="margin-top:auto;border-top:1px solid rgba(246,237,222,0.1);padding-top:16px;">
    <a href="../../logout.php" class="d-link" style="color:#ff9999;">Sign Out</a>
  </div>
</div>

<!-- ══════════════ DELETE MODAL ══════════════ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-head"><h3>Delete Artist</h3></div>
        <div class="modal-body">
            <p class="confirm-text">Are you sure you want to permanently delete <strong>"<?= htmlspecialchars($artist['name']) ?>"</strong> and all their <strong><?= count($artworks) ?> artwork(s)</strong>? This will remove all images, orders, and profile data. Order history will be preserved. This cannot be undone.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete_artist">
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="closeDelete()">Cancel</button>
                <button type="submit" class="btn btn-red">Yes, Delete Permanently</button>
            </div>
        </form>
    </div>
</div>

<!-- Change 7: Add Block Modal -->
<div class="modal-overlay" id="blockModal">
    <div class="modal">
        <div class="modal-head"><h3>Block Artist</h3></div>
        <div class="modal-body">
            <p class="confirm-text" style="margin-bottom:14px;">Optionally provide a reason. The artist will see this in their dashboard.</p>
            <textarea name="status_reason" id="blockReason" placeholder="e.g. Profile incomplete — please add a bio and profile picture." style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);resize:vertical;min-height:90px;outline:none;"></textarea>
        </div>
        <form method="POST" id="blockForm">
            <input type="hidden" name="action" value="block">
            <input type="hidden" name="status_reason" id="blockReasonHidden">
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="closeBlock()">Cancel</button>
                <button type="submit" class="btn btn-red">Block Artist</button>
            </div>
        </form>
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

function openDelete() { document.getElementById('deleteModal').classList.add('open'); }
function closeDelete() { document.getElementById('deleteModal').classList.remove('open'); }
document.getElementById('deleteModal').addEventListener('click', function(e) { if (e.target === this) closeDelete(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeDelete(); });

// Change 8: Add JS for block modal
function openBlock() { document.getElementById('blockModal').classList.add('open'); }
function closeBlock() { document.getElementById('blockModal').classList.remove('open'); }
document.getElementById('blockModal').addEventListener('click', function(e) { if (e.target === this) closeBlock(); });
document.getElementById('blockForm').addEventListener('submit', function() {
    document.getElementById('blockReasonHidden').value = document.getElementById('blockReason').value;
});
</script>
</body>
</html>