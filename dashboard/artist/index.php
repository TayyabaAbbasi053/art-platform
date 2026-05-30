<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// Auth guard — artist only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'artist') {
    header('Location: ../../login.php');
    exit;
}

$artistId   = (int) $_SESSION['user_id'];
$artistName = $_SESSION['name'] ?? 'Artist';

// ── Stats queries ──────────────────────────────────────────
$stats = [];

$statQueries = [
    'total_artworks'    => "SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId",
    'approved_artworks' => "SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'approved'",
    'pending_artworks'  => "SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'pending'",
    'rejected_artworks' => "SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'rejected'",
    'sold_artworks'     => "SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'sold'",
    'total_commissions' => "SELECT COUNT(*) FROM commission_requests WHERE artist_id = $artistId",
    'new_commissions'   => "SELECT COUNT(*) FROM commission_requests WHERE artist_id = $artistId AND status = 'new'",
];

foreach ($statQueries as $key => $sql) {
    $r = $conn->query($sql);
    $stats[$key] = $r ? (int) $r->fetch_row()[0] : 0;
}

// ── Profile completeness check ─────────────────────────────
$profileRow = $conn->query("
    SELECT bio, city, instagram_url, profile_picture
    FROM artist_profiles ap
    JOIN users u ON u.id = ap.user_id
    WHERE ap.user_id = $artistId
")->fetch_assoc();

$profileComplete = $profileRow
    && !empty($profileRow['bio'])
    && !empty($profileRow['city'])
    && !empty($profileRow['profile_picture']);

// ── Recent artworks (last 6) ───────────────────────────────
$recentArtworks = [];
$ra = $conn->query("
    SELECT a.id, a.title, a.price, a.status, a.created_at, c.name AS category
    FROM artworks a
    JOIN categories c ON c.id = a.category_id
    WHERE a.artist_id = $artistId
    ORDER BY a.created_at DESC
    LIMIT 6
");
while ($row = $ra->fetch_assoc()) $recentArtworks[] = $row;

// ── Recent commission requests ─────────────────────────────
$recentCommissions = [];
$rc = $conn->query("
    SELECT id, buyer_name, budget_min, budget_max, deadline, status, created_at
    FROM commission_requests
    WHERE artist_id = $artistId
    ORDER BY created_at DESC
    LIMIT 5
");
while ($row = $rc->fetch_assoc()) $recentCommissions[] = $row;

$today = date('l, d F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Artist Dashboard — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ── Reset & Base ─────────────────────────────────────── */
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --black:   #0a0a0a;
    --grey1:   #f7f7f7;
    --grey2:   #efefef;
    --grey3:   #d8d8d8;
    --grey4:   #999;
    --grey5:   #555;
    --white:   #ffffff;
    --accent:  #0a0a0a;
    --red:     #d63031;
    --green:   #00b894;
    --amber:   #e17055;
    --blue:    #0984e3;
    --sidebar: 220px;
    --top:     60px;
}
html, body { height: 100%; background: var(--grey1); color: var(--black); font-family: 'DM Sans', sans-serif; }

/* ── Sidebar ─────────────────────────────────────────── */
.sidebar {
    position: fixed; top: 0; left: 0;
    width: var(--sidebar); height: 100vh;
    background: var(--white);
    border-right: 1px solid var(--grey2);
    display: flex; flex-direction: column;
    z-index: 100;
    overflow-y: auto;
}
.sidebar-brand {
    padding: 22px 24px 18px;
    border-bottom: 1px solid var(--grey2);
}
.sidebar-brand .logo-tag {
    font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--grey4); font-weight: 400;
}
.sidebar-brand .logo-name {
    font-family: 'Playfair Display', serif; font-size: 20px; color: var(--black); font-weight: 400; margin-top: 2px;
}
.sidebar-brand .logo-badge {
    display: inline-block; margin-top: 6px; background: var(--black); color: var(--white);
    font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px;
}
.sidebar-section {
    padding: 18px 16px 6px;
    font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500;
}
.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 20px;
    font-size: 12.5px; color: var(--grey5); text-decoration: none; font-weight: 400;
    border-left: 2px solid transparent;
    transition: all .15s; position: relative;
}
.nav-item:hover { color: var(--black); background: var(--grey1); border-left-color: var(--grey3); }
.nav-item.active { color: var(--black); background: var(--grey1); border-left-color: var(--black); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .55; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.badge {
    margin-left: auto; background: var(--red); color: #fff;
    font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center;
}
.badge.amber { background: var(--amber); }
.sidebar-bottom {
    margin-top: auto; padding: 16px; border-top: 1px solid var(--grey2);
}
.signout-btn {
    display: flex; align-items: center; gap: 8px; padding: 9px 12px;
    font-size: 12px; color: var(--grey5); text-decoration: none; border-radius: 8px;
    transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif;
}
.signout-btn:hover { background: #fff0f0; color: var(--red); }

/* ── Topbar ──────────────────────────────────────────── */
.topbar {
    position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top);
    background: var(--white); border-bottom: 1px solid var(--grey2);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 32px; z-index: 99;
}
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--black); }
.topbar-left .date { font-size: 11px; color: var(--grey4); margin-top: 1px; }
.topbar-right { display: flex; align-items: center; gap: 20px; }
.artist-chip {
    display: flex; align-items: center; gap: 8px;
    background: var(--grey1); border: 1px solid var(--grey2);
    padding: 5px 12px 5px 5px; border-radius: 30px;
}
.artist-chip .avatar {
    width: 26px; height: 26px; border-radius: 50%; background: var(--black);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; color: #fff; font-weight: 600;
}
.artist-chip .name { font-size: 12px; color: var(--black); font-weight: 500; }

/* ── Main ────────────────────────────────────────────── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; }

/* ── Section headers ─────────────────────────────────── */
.section-header { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 18px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; }
.section-link { font-size: 11px; color: var(--grey5); text-decoration: none; border-bottom: 1px solid var(--grey3); }
.section-link:hover { color: var(--black); }

/* ── Alert strip ─────────────────────────────────────── */
.alert-strip {
    background: var(--black); color: #fff;
    border-radius: 12px; padding: 14px 20px;
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 28px; gap: 12px;
}
.alert-strip .alert-text { font-size: 12.5px; line-height: 1.5; }
.alert-strip .alert-text strong { font-weight: 600; }
.alert-strip .alert-actions { display: flex; gap: 8px; flex-shrink: 0; }
.alert-btn {
    padding: 7px 14px; border-radius: 8px; font-size: 11px; font-weight: 500;
    text-decoration: none; font-family: 'DM Sans', sans-serif; cursor: pointer; border: none;
    transition: opacity .15s; white-space: nowrap;
}
.alert-btn.primary { background: #fff; color: var(--black); }
.alert-btn.ghost { background: rgba(255,255,255,.12); color: #fff; }
.alert-btn:hover { opacity: .85; }

/* ── Stat cards ──────────────────────────────────────── */
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
.stat-card {
    background: var(--white); border: 1px solid var(--grey2); border-radius: 14px;
    padding: 22px 24px; position: relative; overflow: hidden; transition: box-shadow .2s;
}
.stat-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.06); }
.stat-card .label { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; margin-bottom: 10px; }
.stat-card .value { font-family: 'Playfair Display', serif; font-size: 36px; font-weight: 400; color: var(--black); line-height: 1; }
.stat-card .sub { font-size: 11px; color: var(--grey4); margin-top: 6px; }
.stat-card .sub span { font-weight: 600; }
.stat-card .corner-icon {
    position: absolute; right: 18px; top: 18px; width: 32px; height: 32px;
    border-radius: 8px; display: flex; align-items: center; justify-content: center;
}
.stat-card.artworks .corner-icon  { background: #f0f4ff; }
.stat-card.sold .corner-icon      { background: #f0e8ff; }
.stat-card.pending .corner-icon   { background: #fff8e6; }
.stat-card.commissions .corner-icon { background: #f0fff6; }

/* ── Quick actions ───────────────────────────────────── */
.quick-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 28px; }
.quick-card {
    background: var(--white); border: 1px solid var(--grey2); border-radius: 12px;
    padding: 18px 16px; text-decoration: none; display: flex; flex-direction: column; align-items: flex-start;
    gap: 10px; transition: all .2s;
}
.quick-card:hover { border-color: var(--black); box-shadow: 0 4px 16px rgba(0,0,0,.07); }
.quick-card .q-icon {
    width: 36px; height: 36px; border-radius: 10px; background: var(--grey1);
    display: flex; align-items: center; justify-content: center; border: 1px solid var(--grey2);
}
.quick-card .q-label { font-size: 12px; font-weight: 500; color: var(--black); line-height: 1.3; }
.quick-card .q-desc { font-size: 10.5px; color: var(--grey4); }
.pending-badge {
    margin-left: 4px; background: var(--amber); color: #fff;
    font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 10px;
}

/* ── Two-col layout ──────────────────────────────────── */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }

/* ── Table card ──────────────────────────────────────── */
.card {
    background: var(--white); border: 1px solid var(--grey2); border-radius: 14px; overflow: hidden;
}
.card-head {
    padding: 18px 22px 14px; border-bottom: 1px solid var(--grey2);
    display: flex; align-items: center; justify-content: space-between;
}
.card-head .card-title { font-size: 13px; font-weight: 500; color: var(--black); }
table { width: 100%; border-collapse: collapse; }
th { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; padding: 10px 22px; text-align: left; border-bottom: 1px solid var(--grey2); background: var(--grey1); }
td { font-size: 12px; color: var(--grey5); padding: 12px 22px; border-bottom: 1px solid var(--grey2); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--grey1); color: var(--black); }
.td-title { color: var(--black); font-weight: 500; font-size: 12.5px; max-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.td-price { font-weight: 500; color: var(--black); white-space: nowrap; }

/* ── Status badges ───────────────────────────────────── */
.pill {
    display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase;
    font-weight: 600; padding: 3px 9px; border-radius: 20px;
}
.pill.pending     { background: #fff8e6; color: #b07800; }
.pill.approved    { background: #e6fff3; color: #00875a; }
.pill.rejected    { background: #fff0f0; color: #c0392b; }
.pill.sold        { background: #eef2ff; color: #3730a3; }
.pill.hidden      { background: #f4f4f4; color: #888; }
.pill.new         { background: #fff0e8; color: #c0392b; font-weight: 700; }
.pill.contacted   { background: #e8f4ff; color: #1565c0; }
.pill.assigned    { background: #f0e8ff; color: #5e35b1; }
.pill.in_progress { background: #fff8e6; color: #b07800; }
.pill.completed   { background: #e6fff3; color: #00875a; }
.pill.cancelled   { background: #f4f4f4; color: #888; }

/* ── Empty state ─────────────────────────────────────── */
.empty { text-align: center; padding: 32px; color: var(--grey4); font-size: 12px; }

/* ── Footer ──────────────────────────────────────────── */
.dash-footer { padding: 20px 32px; border-top: 1px solid var(--grey2); font-size: 11px; color: var(--grey4); margin-top: 12px; }

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 1200px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .quick-grid  { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 900px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar  { left: 0; }
    .two-col { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .content { padding: 18px; }
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
    <a href="index.php" class="nav-item active">
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
        <?php if ($stats['pending_artworks'] > 0): ?>
            <span class="badge amber"><?= $stats['pending_artworks'] ?></span>
        <?php endif; ?>
    </a>
    <a href="commissions.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Commission Requests
        <?php if ($stats['new_commissions'] > 0): ?>
            <span class="badge"><?= $stats['new_commissions'] ?></span>
        <?php endif; ?>
    </a>

    <div class="sidebar-section">Account</div>
    <a href="profile.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        My Profile
        <?php if (!$profileComplete): ?>
            <span class="badge amber">!</span>
        <?php endif; ?>
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
        <h1>Good <?= (date('H') < 12) ? 'morning' : ((date('H') < 18) ? 'afternoon' : 'evening') ?>, <?= htmlspecialchars(explode(' ', $artistName)[0]) ?></h1>
        <div class="date"><?= $today ?></div>
    </div>
    <div class="topbar-right">
        <div class="artist-chip">
            <div class="avatar"><?= strtoupper(substr($artistName, 0, 1)) ?></div>
            <span class="name"><?= htmlspecialchars($artistName) ?></span>
        </div>
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <!-- Profile incomplete alert -->
    <?php if (!$profileComplete): ?>
    <div class="alert-strip">
        <div class="alert-text">
            Your profile is incomplete. Add a bio, city, and profile picture so buyers can find and trust you.
        </div>
        <div class="alert-actions">
            <a href="profile.php" class="alert-btn primary">Complete Profile</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Rejected artworks alert -->
    <?php if ($stats['rejected_artworks'] > 0): ?>
    <div class="alert-strip" style="background: var(--red)">
        <div class="alert-text">
            You have <strong><?= $stats['rejected_artworks'] ?> artwork<?= $stats['rejected_artworks'] > 1 ? 's' : '' ?></strong> that were rejected. Review them and resubmit if needed.
        </div>
        <div class="alert-actions">
            <a href="my-artworks.php?status=rejected" class="alert-btn primary">View Rejected</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Stat Cards ─────────────────────────────── -->
    <div class="section-header">
        <span class="section-title">Overview</span>
    </div>
    <div class="stats-grid">
        <div class="stat-card artworks">
            <div class="corner-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b7dd8" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
            </div>
            <div class="label">Total Artworks</div>
            <div class="value"><?= $stats['total_artworks'] ?></div>
            <div class="sub">
                <span style="color:var(--green)"><?= $stats['approved_artworks'] ?></span> approved
                &middot; <span style="color:#5e35b1"><?= $stats['sold_artworks'] ?></span> sold
            </div>
        </div>
        <div class="stat-card pending">
            <div class="corner-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#b07800" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="label">Pending Review</div>
            <div class="value"><?= $stats['pending_artworks'] ?></div>
            <div class="sub">Waiting for admin approval</div>
        </div>
        <div class="stat-card sold">
            <div class="corner-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#5e35b1" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            </div>
            <div class="label">Sold Artworks</div>
            <div class="value"><?= $stats['sold_artworks'] ?></div>
            <div class="sub">Confirmed by admin</div>
        </div>
        <div class="stat-card commissions">
            <div class="corner-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#00b894" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </div>
            <div class="label">Commission Requests</div>
            <div class="value"><?= $stats['total_commissions'] ?></div>
            <div class="sub">
                <span style="color:var(--red)"><?= $stats['new_commissions'] ?></span> new
            </div>
        </div>
    </div>

    <!-- ── Quick Actions ──────────────────────────── -->
    <div class="section-header">
        <span class="section-title">Quick Actions</span>
    </div>
    <div class="quick-grid">
        <a href="upload-artwork.php" class="quick-card">
            <div class="q-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="1.8"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <div>
                <div class="q-label">Upload New Artwork</div>
                <div class="q-desc">Add a new piece to your portfolio</div>
            </div>
        </a>
        <a href="my-artworks.php" class="quick-card">
            <div class="q-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
            </div>
            <div>
                <div class="q-label">My Artworks <?php if ($stats['pending_artworks'] > 0): ?><span class="pending-badge"><?= $stats['pending_artworks'] ?> pending</span><?php endif; ?></div>
                <div class="q-desc">View and manage your submissions</div>
            </div>
        </a>
        <a href="commissions.php" class="quick-card">
            <div class="q-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </div>
            <div>
                <div class="q-label">Commission Requests <?php if ($stats['new_commissions'] > 0): ?><span class="pending-badge"><?= $stats['new_commissions'] ?> new</span><?php endif; ?></div>
                <div class="q-desc">Custom artwork requests from buyers</div>
            </div>
        </a>
    </div>

    <!-- ── Two column tables ──────────────────────── -->
    <div class="two-col">

        <!-- Recent Artworks -->
        <div class="card">
            <div class="card-head">
                <span class="card-title">Recent Artworks</span>
                <a href="my-artworks.php" class="section-link">View all &rarr;</a>
            </div>
            <?php if (empty($recentArtworks)): ?>
                <div class="empty">No artworks uploaded yet.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentArtworks as $aw): ?>
                    <tr>
                        <td class="td-title" title="<?= htmlspecialchars($aw['title']) ?>"><?= htmlspecialchars($aw['title']) ?></td>
                        <td><?= htmlspecialchars($aw['category']) ?></td>
                        <td class="td-price">PKR <?= number_format($aw['price']) ?></td>
                        <td><span class="pill <?= $aw['status'] ?>"><?= ucfirst($aw['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Recent Commission Requests -->
        <div class="card">
            <div class="card-head">
                <span class="card-title">Commission Requests</span>
                <a href="commissions.php" class="section-link">View all &rarr;</a>
            </div>
            <?php if (empty($recentCommissions)): ?>
                <div class="empty">No commission requests yet.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Buyer</th>
                        <th>Budget</th>
                        <th>Deadline</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentCommissions as $cr): ?>
                    <tr>
                        <td class="td-title" title="<?= htmlspecialchars($cr['buyer_name']) ?>"><?= htmlspecialchars($cr['buyer_name']) ?></td>
                        <td class="td-price" style="font-size:11px">
                            <?php if ($cr['budget_min'] || $cr['budget_max']): ?>
                                PKR <?= number_format($cr['budget_min']) ?>&ndash;<?= number_format($cr['budget_max']) ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;font-size:11px">
                            <?= $cr['deadline'] ? date('d M Y', strtotime($cr['deadline'])) : '&mdash;' ?>
                        </td>
                        <td><span class="pill <?= $cr['status'] ?>"><?= ucfirst(str_replace('_', ' ', $cr['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div><!-- /two-col -->

</div><!-- /content -->

<div class="dash-footer">
    Art Bazaar &mdash; Artist Dashboard &mdash; <?= date('Y') ?>
</div>
</main>

</body>
</html>