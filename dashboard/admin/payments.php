<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

$adminId   = (int)$_SESSION['user_id'];
$adminName = $_SESSION['name'] ?? 'Admin';
$toast     = '';

// ── Make sure a row exists for every active artist for the requested period ──
// This is what gives the "automatic monthly reset": as soon as anyone opens
// this page after the 1st, new unpaid rows get created for the new month.
// Old months are NEVER touched or deleted, so full history is kept.
function ensurePeriodRows($conn, $period) {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO artist_payments (artist_id, period, amount, status)
        SELECT id, ?, 50.00, 'unpaid'
        FROM users
        WHERE role = 'artist' AND status = 'active'
    ");
    $stmt->bind_param('s', $period);
    $stmt->execute();
}

$currentPeriod = date('Y-m'); // e.g. "2026-09"
ensurePeriodRows($conn, $currentPeriod);

// ── Handle actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $period = $_POST['period'] ?? $currentPeriod;

    if ($action === 'mark_paid' || $action === 'mark_unpaid') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            if ($action === 'mark_paid') {
                $stmt = $conn->prepare("UPDATE artist_payments SET status='paid', paid_by=?, paid_at=NOW() WHERE artist_id=? AND period=?");
                $stmt->bind_param('iis', $adminId, $id, $period);
            } else {
                $stmt = $conn->prepare("UPDATE artist_payments SET status='unpaid', paid_by=NULL, paid_at=NULL WHERE artist_id=? AND period=?");
                $stmt->bind_param('is', $id, $period);
            }
            $stmt->execute();
            $toast = $action === 'mark_paid' ? 'Marked as paid.' : 'Marked as unpaid.';
        }
    }

    if ($action === 'mark_paid_bulk') {
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types  = 'is' . str_repeat('i', count($ids));
            $params = array_merge([$adminId, $period], $ids);
            $stmt = $conn->prepare("UPDATE artist_payments SET status='paid', paid_by=?, paid_at=NOW() WHERE period=? AND artist_id IN ($placeholders)");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $toast = count($ids) . ' artist(s) marked as paid.';
        }
    }
}

// ── Filters ─────────────────────────────────────────────
$period       = $_GET['period'] ?? $currentPeriod;
$statusFilter = $_GET['pstatus'] ?? '';
$search       = trim($_GET['q'] ?? '');

$where  = ['ap.period = ?'];
$params = [$period];
$types  = 's';

if (in_array($statusFilter, ['paid', 'unpaid'])) {
    $where[] = 'ap.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
if ($search) {
    $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}
$whereSQL = implode(' AND ', $where);

// ── Export unpaid (or currently filtered) list as CSV ────
if (isset($_GET['export'])) {
    $exportSQL = "
        SELECT u.name, u.email, u.phone
        FROM artist_payments ap
        JOIN users u ON u.id = ap.artist_id
        WHERE $whereSQL
        ORDER BY u.name ASC
    ";
    $stmt = $conn->prepare($exportSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $filename = 'unpaid_artists_' . $period . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Phone']);
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [$row['name'], $row['email'], $row['phone']]);
    }
    fclose($out);
    exit;
}

// ── Fetch rows for display ───────────────────────────────
$dataSQL = "
    SELECT u.id, u.name, u.email, u.phone, ap.status, ap.amount, ap.paid_at,
           pb.name AS paid_by_name
    FROM artist_payments ap
    JOIN users u ON u.id = ap.artist_id
    LEFT JOIN users pb ON pb.id = ap.paid_by
    WHERE $whereSQL
    ORDER BY ap.status ASC, u.name ASC
";
$stmt = $conn->prepare($dataSQL);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;

// ── Summary counts for this period ───────────────────────
$summary = $conn->prepare("SELECT status, COUNT(*) c FROM artist_payments WHERE period=? GROUP BY status");
$summary->bind_param('s', $period);
$summary->execute();
$sres = $summary->get_result();
$counts = ['paid' => 0, 'unpaid' => 0];
while ($row = $sres->fetch_assoc()) $counts[$row['status']] = (int)$row['c'];
$totalArtists = $counts['paid'] + $counts['unpaid'];
$collected = $counts['paid'] * 50;

// ── Periods available (for the dropdown / history) ───────
$periods = [];
$pres = $conn->query("SELECT DISTINCT period FROM artist_payments ORDER BY period DESC");
while ($p = $pres->fetch_assoc()) $periods[] = $p['period'];
if (!in_array($currentPeriod, $periods)) array_unshift($periods, $currentPeriod);

function periodLabel($p) {
    return date('F Y', strtotime($p . '-01'));
}

function buildQS2($overrides = []) {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payments — Art Bazaar Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --bg: #F6EDDE; --card: #F6EDDE; --sand: #DDCDAE; --border: #0C3F30;
    --ink: #0C3F30; --body: #0C3F30; --muted: #0C3F30; --light: #0C3F30;
    --r: 16px; --sidebar: 240px; --top: 60px;
}
html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }

/* ── Sidebar ─────────────────────────────────────────── */
.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: var(--ink); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--border); }
.sidebar-brand .logo-text { font-family: 'Playfair Display', serif; font-size: 18px; font-weight: 500; color: var(--bg); }
.sidebar-brand .logo-tag { font-size: 8px; letter-spacing: 2px; color: var(--sand); margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-left: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--bg); text-decoration: none; font-weight: 400; border-left: 2px solid transparent; transition: all .15s; position: relative; }
.nav-item:hover { color: var(--ink); background: rgba(255,255,255,0.3); border-left-color: var(--sand); }
.nav-item.active { color: var(--ink); background: var(--sand); font-weight: 500; border-left-color: var(--sand); }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .55; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: rgba(255,255,255,0.1); color: var(--sand); }

/* ── Topbar ──────────────────────────────────────────── */
.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--ink); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.topbar-left .sub { font-size: 11px; color: var(--sand); margin-top: 1px; }

/* ── Main ────────────────────────────────────────────── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 28px 32px; }

/* ── Toast ───────────────────────────────────────────── */
.toast { background: var(--sand); color: var(--ink); border: 1px solid var(--border); padding: 12px 20px; border-radius: 10px; font-size: 12.5px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }
.toast.hidden { display: none; }
.toast-close { background: none; border: none; color: var(--ink); cursor: pointer; font-size: 16px; }

/* ── Summary cards ───────────────────────────────────── */
.summary-row { display: flex; gap: 14px; margin-bottom: 22px; flex-wrap: wrap; }
.sum-card { flex: 1; min-width: 160px; background: var(--card); border: 1px solid var(--border); border-radius: var(--r); padding: 16px 20px; }
.sum-card .label { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); font-weight: 600; }
.sum-card .value { font-family: 'Playfair Display', serif; font-size: 26px; margin-top: 6px; color: var(--ink); }

/* ── Filters ─────────────────────────────────────────── */
.filters { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
.filters input[type="text"], .filters select { padding: 10px 20px; border: 2px solid var(--border); border-radius: 999px; font-size: 13px; font-family: 'DM Sans', sans-serif; color: var(--ink); background: var(--bg); outline: none; font-weight: 500; }
.filters input { width: 240px; }
.filters select { min-width: 150px; cursor: pointer; }
.clear-link { font-size: 11px; color: var(--ink); text-decoration: none; cursor: pointer; background: none; border: none; font-family: 'DM Sans', sans-serif; }

/* ── Card & Table ────────────────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--r); overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; padding: 11px 16px; text-align: left; border-bottom: 1px solid var(--border); background: var(--sand); white-space: nowrap; }
td { font-size: 12.5px; color: var(--body); padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--bg); }
.td-name { color: var(--ink); font-weight: 500; }
.td-email { font-size: 11px; color: var(--muted); }
.td-meta { font-size: 11px; color: var(--muted); }

/* ── Pills ───────────────────────────────────────────── */
.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.pill.paid { background: var(--ink); color: var(--bg); }
.pill.unpaid { background: var(--sand); color: var(--ink); }

/* ── Buttons ─────────────────────────────────────────── */
.act-btn { padding: 5px 10px; font-size: 10.5px; font-weight: 500; border-radius: 7px; border: 1px solid var(--border); background: var(--card); color: var(--ink); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .12s; white-space: nowrap; }
.act-btn:hover { border-color: var(--ink); }
.act-btn.approve { background: var(--ink); color: var(--bg); border: 1px solid var(--ink); }
.act-btn.approve:hover { background: #1a4d3e; }
.act-btn.red { background: transparent; color: var(--ink); }
.act-btn.red:hover { background: var(--sand); }
.act-btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Bulk bar ────────────────────────────────────────── */
.bulk-bar { display: none; align-items: center; gap: 14px; background: var(--ink); color: var(--bg); padding: 12px 20px; border-radius: 12px; margin-bottom: 16px; font-size: 12.5px; }
.bulk-bar.show { display: flex; }
.bulk-bar button { margin-left: auto; }

.empty { text-align: center; padding: 48px 24px; color: var(--muted); font-size: 13px; }
.dash-footer { padding: 20px 32px; border-top: 1px solid var(--border); font-size: 11px; color: var(--bg); margin-top: 12px; background: var(--ink); }

@media(max-width:1080px){ .content { padding: 24px; } }
@media(max-width:768px){
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; }
    .content { padding: 16px; }
    .filters { flex-direction: column; align-items: stretch; }
    .filters input { width: 100%; }
    table, thead, tbody, th, td, tr { display: block; }
    thead { display: none; }
    tr { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    td { padding: 8px 0; border: none; display: flex; justify-content: space-between; align-items: center; }
    td:before { content: attr(data-label); font-weight: 600; font-size: 11px; text-transform: uppercase; color: var(--muted); flex: 1; }
}
</style>
</head>
<body>

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="logo-text">Art Bazaar</div>
        <div class="logo-tag">DASHBOARD <span class="logo-badge">Admin</span></div>
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
    <a href="artists.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        Artists
    </a>
    <a href="payments.php" class="nav-item active">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
        Payments
    </a>
    <a href="blogs.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/></svg>
        Blog Posts
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
        <h1>Payments</h1>
        <div class="sub">Monthly Rs 50 artist fee — <?= htmlspecialchars(periodLabel($period)) ?></div>
    </div>
    <div class="topbar-right"></div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <?php if ($toast): ?>
    <div class="toast">
        <span><?= htmlspecialchars($toast) ?></span>
        <button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="summary-row">
        <div class="sum-card"><div class="label">Total Artists</div><div class="value"><?= $totalArtists ?></div></div>
        <div class="sum-card"><div class="label">Paid</div><div class="value"><?= $counts['paid'] ?></div></div>
        <div class="sum-card"><div class="label">Unpaid</div><div class="value"><?= $counts['unpaid'] ?></div></div>
        <div class="sum-card"><div class="label">Collected</div><div class="value">Rs <?= number_format($collected) ?></div></div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <select id="periodSelect">
            <?php foreach ($periods as $p): ?>
                <option value="<?= $p ?>" <?= $p === $period ? 'selected' : '' ?>><?= htmlspecialchars(periodLabel($p)) ?><?= $p === $currentPeriod ? ' (current)' : '' ?></option>
            <?php endforeach; ?>
        </select>
        <select id="statusSelect">
            <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All statuses</option>
            <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid only</option>
            <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid only</option>
        </select>
        <input type="text" id="searchInput" placeholder="Search name or email..." value="<?= htmlspecialchars($search) ?>">
        <?php if ($statusFilter || $search): ?>
            <button class="clear-link" onclick="window.location.href='payments.php?period=<?= $period ?>'">Clear filters</button>
        <?php endif; ?>
        <span style="margin-left:auto;"></span>
        <button type="button" class="act-btn approve" onclick="exportUnpaid()">⬇ Export Unpaid (CSV)</button>
    </div>

    <!-- Bulk action bar -->
    <div class="bulk-bar" id="bulkBar">
        <span id="bulkCount">0 selected</span>
        <button type="button" class="act-btn approve" onclick="markSelectedPaid()">Mark Selected as Paid</button>
    </div>

    <!-- Table -->
    <div class="card">
        <?php if (empty($rows)): ?>
            <div class="empty">No artists found for this filter.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll"></th>
                    <th>Artist</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Marked By</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="">
                        <?php if ($r['status'] === 'unpaid'): ?>
                            <input type="checkbox" class="row-check" value="<?= $r['id'] ?>" onchange="updateBulkBar()">
                        <?php endif; ?>
                    </td>
                    <td data-label="Artist">
                        <div class="td-name"><?= htmlspecialchars($r['name']) ?></div>
                        <div class="td-email"><?= htmlspecialchars($r['email']) ?></div>
                    </td>
                    <td data-label="Amount">Rs <?= number_format($r['amount']) ?></td>
                    <td data-label="Status"><span class="pill <?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td data-label="Marked By" class="td-meta">
                        <?php if ($r['status'] === 'paid'): ?>
                            <?= htmlspecialchars($r['paid_by_name'] ?? '—') ?><br>
                            <?= $r['paid_at'] ? date('d M, h:i A', strtotime($r['paid_at'])) : '' ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td data-label="Action">
                        <?php if ($r['status'] === 'unpaid'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="mark_paid">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="period" value="<?= $period ?>">
                                <button type="submit" class="act-btn approve">Mark as Paid</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="mark_unpaid">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="period" value="<?= $period ?>">
                                <button type="submit" class="act-btn red">Undo</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
<div class="dash-footer">Art Bazaar Admin Panel &mdash; <?= date('Y') ?></div>
</main>

<!-- Hidden bulk form -->
<form method="POST" id="bulkForm" style="display:none">
    <input type="hidden" name="action" value="mark_paid_bulk">
    <input type="hidden" name="period" value="<?= $period ?>">
    <input type="hidden" name="ids" id="bulkIds">
</form>

<script>
document.getElementById('periodSelect').addEventListener('change', applyFilters);
document.getElementById('statusSelect').addEventListener('change', applyFilters);
let searchTimer;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 400);
});

function applyFilters() {
    const params = new URLSearchParams();
    params.set('period', document.getElementById('periodSelect').value);
    const status = document.getElementById('statusSelect').value;
    if (status) params.set('pstatus', status);
    const q = document.getElementById('searchInput').value.trim();
    if (q) params.set('q', q);
    window.location.href = 'payments.php?' + params.toString();
}

function exportUnpaid() {
    const params = new URLSearchParams(window.location.search);
    if (!params.get('period')) params.set('period', '<?= $period ?>');
    params.set('pstatus', 'unpaid');
    params.set('export', '1');
    window.location.href = 'payments.php?' + params.toString();
}

document.getElementById('selectAll').addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
    updateBulkBar();
});

function updateBulkBar() {
    const checked = document.querySelectorAll('.row-check:checked');
    const bar = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = checked.length + ' selected';
    bar.classList.toggle('show', checked.length > 0);
}

function markSelectedPaid() {
    const checked = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
    if (!checked.length) return;
    document.getElementById('bulkIds').value = checked.join(',');
    document.getElementById('bulkForm').submit();
}
</script>
</body>
</html>
