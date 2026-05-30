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

// ── Handle Actions (Delete) ───────────────────────────
if (isset($_GET['delete'])) {
    $artId = (int) $_GET['delete'];
    // Check ownership
    $check = $conn->query("SELECT id FROM artworks WHERE id = $artId AND artist_id = $artistId");
    if ($check->num_rows > 0) {
        // Soft delete: set to hidden
        $conn->query("UPDATE artworks SET status = 'hidden' WHERE id = $artId");
        $successMsg = 'Artwork hidden successfully.';
    }
    header("Location: my-artworks.php?msg=hidden");
    exit;
}

// ── Filtering ─────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$allowedFilters = ['all', 'pending', 'approved', 'sold', 'rejected'];
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

// ── Stats for Tabs ───────────────────────────────────
$counts = [
    'all'      => (int) $conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status != 'hidden'")->fetch_row()[0],
    'pending'  => (int) $conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'pending'")->fetch_row()[0],
    'approved' => (int) $conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'approved'")->fetch_row()[0],
    'sold'     => (int) $conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'sold'")->fetch_row()[0],
    'rejected' => (int) $conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'rejected'")->fetch_row()[0],
];

// ── Messages ─────────────────────────────────────────
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'uploaded') $successMsg = 'Artwork submitted successfully! Waiting for admin approval.';
    if ($_GET['msg'] === 'hidden')   $successMsg = 'Artwork has been hidden.';
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

/* ── Sidebar & Topbar (Consistent) ─────────────────── */
.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: #EFE3D2; border-right: 1px solid var(--grey2); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--grey2); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--grey4); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--black); font-weight: 400; margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--terracotta); color: var(--white); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--grey5); text-decoration: none; border-left: 2px solid transparent; transition: all .15s; }
.nav-item:hover { color: var(--black); background: rgba(255,255,255,0.3); border-left-color: var(--grey3); }
.nav-item.active { color: var(--black); background: rgba(255,255,255,0.4); border-left-color: var(--terracotta); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .55; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.badge { margin-left: auto; background: var(--terracotta); color: #fff; font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
.badge.amber { background: var(--amber); }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--grey2); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--grey5); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: #FFF0EC; color: var(--terracotta); }

.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--white); border-bottom: 1px solid var(--grey2); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--black); }
.artist-chip { display: flex; align-items: center; gap: 8px; background: var(--grey1); border: 1px solid var(--grey2); padding: 5px 12px 5px 5px; border-radius: 30px; }
.artist-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--terracotta); display: flex; align-items: center; justify-content: center; font-size: 11px; color: #fff; font-weight: 600; overflow: hidden; }
.artist-chip .avatar img { width: 100%; height: 100%; object-fit: cover; }
.artist-chip .name { font-size: 12px; font-weight: 500; color: var(--black); }
.artist-chip .arrow { font-size: 12px; color: var(--grey4); margin-left: 4px; }

.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; margin-bottom: 20px; }

/* ── Messages ───────────────────────────────────────── */
.msg { padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
.msg.success { background: #E8F5EE; color: #6BA58D; border: 1px solid #C8E0D5; }

/* ── Header Actions ─────────────────────────────────── */
.header-actions { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.btn {
    padding: 10px 20px; border-radius: 10px; font-size: 12.5px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer; border: none;
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all .15s;
}
.btn-primary { background: var(--black); color: #fff; }
.btn-primary:hover { background: #333; }

/* ── Tabs ───────────────────────────────────────────── */
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--grey2); margin-bottom: 24px; overflow-x: auto; }
.tab-btn {
    padding: 10px 16px; background: none; border: none; font-size: 12.5px; color: var(--grey5);
    cursor: pointer; font-family: 'DM Sans', sans-serif; border-bottom: 2px solid transparent; transition: all .2s;
}
.tab-btn:hover { color: var(--black); }
.tab-btn.active { color: var(--black); border-bottom-color: var(--terracotta); font-weight: 500; }

/* ── Table ──────────────────────────────────────────── */
.card { background: var(--white); border: 1px solid var(--grey2); border-radius: 16px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 10px; letter-spacing: 1.2px; text-transform: uppercase; color: var(--grey4); font-weight: 500; padding: 14px 24px; text-align: left; border-bottom: 1px solid var(--grey2); background: var(--grey1); }
td { padding: 16px 24px; border-bottom: 1px solid var(--grey2); vertical-align: middle; font-size: 13px; color: var(--black); }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--grey1); }

.thumb { width: 50px; height: 50px; border-radius: 8px; object-fit: cover; background: #EFE3D2; border: 1px solid var(--grey3); }
.art-title { font-weight: 500; font-size: 13px; color: var(--black); }
.art-cat { font-size: 11px; color: var(--grey4); display: block; margin-top: 2px; }
.price { font-weight: 600; font-size: 13px; color: var(--black); }

/* ── Badges ─────────────────────────────────────────── */
.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
.pill.pending  { background: #FFF4E6; color: #E48A4A; }
.pill.approved { background: #E8F5EE; color: #6BA58D; }
.pill.rejected { background: #FDEAEA; color: #D46A6A; }
.pill.sold     { background: #EEE8FF; color: #6B5CE6; }

/* ── Rejection Reason ───────────────────────────────── */
.reject-reason { 
    display: block; font-size: 11px; color: #D46A6A; margin-top: 4px; 
    background: #FDEAEA; padding: 4px 8px; border-radius: 4px; 
    border: 1px solid #F5C6C6;
}

/* ── Actions Column ─────────────────────────────────── */
.actions { display: flex; gap: 8px; }
.icon-btn { width: 30px; height: 30px; border-radius: 6px; border: 1px solid var(--grey3); background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--grey5); transition: all .2s; }
.icon-btn:hover { border-color: var(--black); color: var(--black); }
.icon-btn.danger:hover { border-color: var(--terracotta); color: var(--terracotta); background: #FFF0EC; }

/* ── Empty State ─────────────────────────────────────── */
.empty-state { padding: 60px 20px; text-align: center; }
.empty-state h3 { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--black); margin-bottom: 8px; }
.empty-state p { font-size: 13px; color: var(--grey4); }

@media (max-width: 900px) { :root { --sidebar: 0px; } .sidebar { display: none; } .topbar { left: 0; } .content { padding: 20px; } .btn { display: none; } }
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
    <div class="topbar-left"><h1>My Artworks</h1></div>
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
            <span class="arrow">∨</span>
        </div>
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <div class="section-title">Portfolio Management</div>

    <?php if ($successMsg): ?>
        <div class="msg success">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= htmlspecialchars($successMsg) ?>
        </div>
    <?php endif; ?>

    <div class="header-actions">
        <div class="tabs">
            <button class="tab-btn <?= $filterStatus === 'all' ? 'active' : '' ?>" onclick="location.href='?status=all'">All (<?= $counts['all'] ?>)</button>
            <button class="tab-btn <?= $filterStatus === 'pending' ? 'active' : '' ?>" onclick="location.href='?status=pending'">Pending (<?= $counts['pending'] ?>)</button>
            <button class="tab-btn <?= $filterStatus === 'approved' ? 'active' : '' ?>" onclick="location.href='?status=approved'">Approved (<?= $counts['approved'] ?>)</button>
            <button class="tab-btn <?= $filterStatus === 'sold' ? 'active' : '' ?>" onclick="location.href='?status=sold'">Sold (<?= $counts['sold'] ?>)</button>
            <button class="tab-btn <?= $filterStatus === 'rejected' ? 'active' : '' ?>" onclick="location.href='?status=rejected'">Rejected (<?= $counts['rejected'] ?>)</button>
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
                                    <div class="thumb" style="background:#EFE3D2;border-radius:6px;"></div>
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
                                <?php if ($art['status'] === 'rejected' && !empty($art['rejection_reason'])): ?>
                                    <span class="reject-reason" title="<?= htmlspecialchars($art['rejection_reason']) ?>">
                                        <?= htmlspecialchars(substr($art['rejection_reason'], 0, 40)) ?>...
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <a href="edit-artwork.php?id=<?= $art['id'] ?>" class="icon-btn" title="Edit">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </a>
                                    <a href="?delete=<?= $art['id'] ?>" class="icon-btn danger" title="Hide" onclick="return confirm('Are you sure you want to hide this artwork?')">
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

</body>
</html>