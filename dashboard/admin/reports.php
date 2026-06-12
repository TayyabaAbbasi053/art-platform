<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

 $adminName = $_SESSION['name'] ?? 'Admin';

// ── Date range from form ────────────────────────────────
 $dateFrom = $_GET['date_from'] ?? '';
 $dateTo   = $_GET['date_to']   ?? '';
 $hasReport = false;
 $reportData = []; 
 $summaryTotals = ['sales' => 0, 'commissions' => 0, 'revenue' => 0, 'total_orders' => 0];

// Preset shortcuts
 $presets = [
    'this_month'  => [date('Y-m-01'), date('Y-m-d')],
    'last_month'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    'last_7'      => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    'last_30'     => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    'this_year'   => [date('Y-01-01'), date('Y-m-d')],
];

if (isset($_GET['preset']) && isset($presets[$_GET['preset']])) {
    [$dateFrom, $dateTo] = $presets[$_GET['preset']];
}

if ($dateFrom && $dateTo) {
    $hasReport = true;
    $dfSafe = $conn->real_escape_string($dateFrom);
    $dtSafe = $conn->real_escape_string($dateTo);

    // ── 1. Fetch Artist Profile Stats ───────
    $artistIdsInPeriod = [];

    // Sales IDs
    $sqlSalesIds = "SELECT DISTINCT aw.artist_id 
                    FROM orders o 
                    JOIN order_items oi ON o.id = oi.order_id AND oi.item_type = 'artwork'
                    JOIN artworks aw ON oi.item_id = aw.id
                    WHERE o.order_type = 'artwork' AND o.created_at BETWEEN '{$dfSafe} 00:00:00' AND '{$dtSafe} 23:59:59'";
    $resSalesIds = $conn->query($sqlSalesIds);
    if ($resSalesIds) {
        while($row = $resSalesIds->fetch_assoc()) {
            if (isset($row['artist_id'])) $artistIdsInPeriod[] = $row['artist_id'];
        }
    }

    // Commission IDs
    $sqlCommIds = "SELECT DISTINCT cr.artist_id 
                    FROM orders o 
                    JOIN commission_requests cr ON cr.order_id = o.id 
                    WHERE o.order_type = 'commission' AND o.created_at BETWEEN '{$dfSafe} 00:00:00' AND '{$dtSafe} 23:59:59'";
    $resCommIds = $conn->query($sqlCommIds);
    if ($resCommIds) {
        while($row = $resCommIds->fetch_assoc()) {
            if (isset($row['artist_id'])) $artistIdsInPeriod[] = $row['artist_id'];
        }
    }

    $artistIdsInPeriod = array_unique($artistIdsInPeriod);
    
    // Fetch Profiles
    $artistProfiles = [];
    if (!empty($artistIdsInPeriod)) {
        $idsStr = implode(',', $artistIdsInPeriod);
        $profSql = "
            SELECT 
                u.id, u.name, u.email, u.phone, u.status AS account_status, u.created_at,
                ap.city, ap.art_style, ap.accepts_commissions, ap.is_featured, ap.bio,
                (SELECT COUNT(*) FROM artworks WHERE artist_id = u.id) as total_artworks,
                (SELECT COUNT(*) FROM artworks WHERE artist_id = u.id AND status='sold') as sold_artworks,
                (SELECT COUNT(*) FROM artworks WHERE artist_id = u.id AND status='approved') as approved_artworks
            FROM users u
            LEFT JOIN artist_profiles ap ON ap.user_id = u.id
            WHERE u.id IN ($idsStr)
        ";
        $resProf = $conn->query($profSql);
        if ($resProf) {
            while ($row = $resProf->fetch_assoc()) {
                $artistProfiles[$row['id']] = $row;
            }
        }
    }

    // ── 2. Fetch Detailed Sales ───────
    $salesQuery = "
        SELECT 
            aw.artist_id,
            o.id AS order_id,
            o.order_number,
            o.created_at,
            o.total AS order_total,
            o.order_status,
            o.payment_status,
            o.payment_method,
            aw.title AS artwork_title,
            COALESCE(u.name, o.guest_name) AS buyer_name
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id AND oi.item_type = 'artwork'
        JOIN artworks aw ON oi.item_id = aw.id
        LEFT JOIN users u ON o.buyer_id = u.id
        WHERE o.order_type = 'artwork'
          AND o.created_at BETWEEN '{$dfSafe} 00:00:00' AND '{$dtSafe} 23:59:59'
        ORDER BY o.created_at DESC
    ";

    // ── 3. Fetch Detailed Commissions ──────────────────
    $commissionsQuery = "
        SELECT 
            cr.artist_id,
            o.id AS order_id,
            o.order_number,
            o.created_at,
            o.total AS order_total,
            o.order_status,
            o.payment_status,
            o.payment_method,
            cat.name AS category_name,
            COALESCE(u.name, o.guest_name) AS buyer_name
        FROM orders o
        JOIN commission_requests cr ON cr.order_id = o.id
        LEFT JOIN categories cat ON o.commission_category_id = cat.id
        LEFT JOIN users u ON o.buyer_id = u.id
        WHERE o.order_type = 'commission'
          AND o.created_at BETWEEN '{$dfSafe} 00:00:00' AND '{$dtSafe} 23:59:59'
        ORDER BY o.created_at DESC
    ";

    $salesData = $conn->query($salesQuery);
    $commData = $conn->query($commissionsQuery);

    // ── 4. Structure Data ───────────────────────────────
    $structured = [];
    foreach ($artistProfiles as $aid => $prof) {
        $structured[$aid] = [
            'profile' => $prof,
            'orders' => [], // Unified list
            'sales_rev' => 0, 'comm_rev' => 0, 'sales_count' => 0, 'comm_count' => 0
        ];
    }

    if ($salesData) {
        while ($row = $salesData->fetch_assoc()) {
            $aid = $row['artist_id'];
            if (!isset($structured[$aid])) {
                $structured[$aid] = [
                    'profile' => ['name' => 'Unknown Artist', 'city'=>'', 'created_at'=>date('Y-m-d'), 'total_artworks'=>0, 'sold_artworks'=>0, 'is_featured'=>0, 'email'=>'', 'phone'=>''],
                    'orders' => [], 'sales_rev' => 0, 'comm_rev' => 0, 'sales_count' => 0, 'comm_count' => 0
                ];
            }
            $row['type'] = 'artwork';
            $row['display_title'] = $row['artwork_title'];
            $structured[$aid]['orders'][] = $row;
            $structured[$aid]['sales_rev'] += (float)$row['order_total'];
            $structured[$aid]['sales_count']++;
            $summaryTotals['sales']++;
            $summaryTotals['revenue'] += (float)$row['order_total'];
            $summaryTotals['total_orders']++;
        }
    }

    if ($commData) {
        while ($row = $commData->fetch_assoc()) {
            $aid = $row['artist_id'];
            if (!isset($structured[$aid])) {
                $structured[$aid] = [
                    'profile' => ['name' => 'Unknown Artist', 'city'=>'', 'created_at'=>date('Y-m-d'), 'total_artworks'=>0, 'sold_artworks'=>0, 'is_featured'=>0, 'email'=>'', 'phone'=>''],
                    'orders' => [], 'sales_rev' => 0, 'comm_rev' => 0, 'sales_count' => 0, 'comm_count' => 0
                ];
            }
            $row['type'] = 'commission';
            $row['display_title'] = $row['category_name'] ?? 'Custom Request';
            $structured[$aid]['orders'][] = $row;
            $structured[$aid]['comm_rev'] += (float)$row['order_total'];
            $structured[$aid]['comm_count']++;
            $summaryTotals['commissions']++;
            $summaryTotals['revenue'] += (float)$row['order_total'];
            $summaryTotals['total_orders']++;
        }
    }

    $structured = array_filter($structured, function($a) { return count($a['orders']) > 0; });
    
    // Sort by Total Revenue
    uasort($structured, function($a, $b) {
        return ($b['sales_rev'] + $b['comm_rev']) <=> ($a['sales_rev'] + $a['comm_rev']);
    });

    $reportData = $structured;
}

 $today = date('l, d F Y');

function getStatusPill($status) {
    $s = strtolower($status);
    // Using specific classes from your Profile Page
    $cls = 'pill'; 
    if ($s == 'pending') $cls .= ' pending';
    elseif ($s == 'confirmed' || $s == 'active') $cls .= ' active'; // using active as green
    elseif ($s == 'processing' || $s == 'shipped') $cls .= ' pending'; // styling pending/processing similarly
    elseif ($s == 'delivered') $cls .= ' active';
    elseif ($s == 'cancelled') $cls .= ' blocked';
    
    return "<span class='$cls'>".ucfirst(str_replace('_',' ',$status))."</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — Art Bazaar Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    /* Copied from Artist Profile Page */
    --bg: #F6EDDE;
    --card: #F6EDDE;
    --sand: #DDCDAE;
    --border: #0C3F30;
    --ink: #0C3F30;
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }

/* ── Sidebar (Exact Copy from Profile Page) ── */
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

/* ── Topbar (Exact Copy) ── */
.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--ink); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.topbar-left .sub { font-size: 11px; color: var(--sand); margin-top: 1px; }
.admin-chip { display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); padding: 5px 12px 5px 5px; border-radius: 30px; }
.admin-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--bg); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--ink); font-weight: 600; }
.admin-chip .name { font-size: 12px; color: var(--ink); font-weight: 500; }
.admin-chip .arrow { font-size: 12px; color: var(--ink); margin-left: 4px; opacity: 0.6; }

/* ── Main Layout ── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 28px 32px; }

/* ── Report Specific Styles ── */
.filter-card { background: var(--card); border-radius: 14px; padding: 24px 28px; margin-bottom: 28px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
.filter-card h3 { font-family: 'Playfair Display', serif; font-size: 18px; font-weight: 400; color: var(--ink); margin-bottom: 6px; }
.filter-card p { font-size: 12px; color: var(--ink); margin-top:0; opacity:0.8; margin-bottom: 20px; }
.filter-row { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-group label { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; opacity:0.7; }
.filter-group input[type="date"] { background: var(--bg); border: 1px solid var(--border); color: var(--ink); padding: 8px 12px; border-radius: 8px; font-size: 13px; font-family: 'DM Sans', sans-serif; outline: none; min-width: 160px; transition: border .15s; }
.filter-group input:focus { border-color: var(--ink); }
.btn { padding: 9px 18px; border-radius: 9px; border: none; font-size: 12px; font-weight: 500; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .15s; }
.btn-primary { background: var(--ink); color: var(--bg); }
.btn-primary:hover { opacity: 0.9; }

.presets { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); }
.presets span { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); align-self: center; margin-right: 4px; font-weight: 500; opacity:0.7; }
.preset-btn { background: transparent; border: 1px solid var(--border); color: var(--ink); padding: 5px 14px; border-radius: 20px; font-size: 11px; font-family: 'DM Sans', sans-serif; cursor: pointer; text-decoration: none; transition: all .15s; white-space: nowrap; }
.preset-btn:hover, .preset-btn.active { background: var(--ink); color: var(--bg); border-color: var(--ink); }

/* ── Summary Strip ── */
.summary-strip { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 28px; }
.summary-card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 20px 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
.summary-card .s-label { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 8px; opacity:0.7; }
.summary-card .s-value { font-family: 'Playfair Display', serif; font-size: 32px; color: var(--ink); line-height: 1; }
.summary-card .s-sub { font-size: 12px; color: var(--ink); margin-top: 4px; opacity:0.7; }

/* ── Period Label ── */
.period-info { margin-bottom: 20px; font-size: 12px; color: var(--ink); }
.period-info strong { font-weight: 600; }

/* ── Artist Blocks ── */
.artist-section { background: var(--card); border: 1px solid var(--border); border-radius: 14px; margin-bottom: 32px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
.artist-header { padding: 20px 24px; background: rgba(12,63,48,0.03); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; }
.artist-info { flex: 1; }
.artist-info h3 { font-family: 'Playfair Display', serif; font-size: 22px; font-weight: 400; color: var(--ink); margin-bottom: 6px; }
.artist-meta { display: flex; gap: 12px; font-size: 12px; color: var(--ink); opacity: 0.8; margin-bottom: 6px; }
.artist-contact { display: flex; gap: 12px; font-size: 11px; color: var(--ink); opacity: 0.7; margin-top: 4px; }
.artist-contact span { display: flex; align-items: center; gap: 4px; }
.artist-stats { display: flex; gap: 20px; text-align: right; flex-shrink: 0; }
.stat-item { display: flex; flex-direction: column; align-items: flex-end; }
.stat-label { font-size: 9px; letter-spacing: 1px; text-transform: uppercase; color: var(--ink); font-weight: 500; opacity: 0.7; }
.stat-val { font-size: 18px; font-weight: 600; color: var(--ink); }

/* ── Tables ── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
th { text-align: left; padding: 11px 14px; background: var(--card); color: var(--ink); font-weight: 500; font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; border-bottom: 1px solid var(--border); opacity: 0.7; }
td { padding: 12px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
.type-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; margin-right: 8px; text-transform: uppercase; }
.type-art { background: #EEF2F8; color: #3B7DD8; }
.type-comm { background: #FFF0EC; color: #C96B4B; }
.amount { font-weight: 600; color: var(--ink); }
.total-row { background: var(--sand); text-align: right; padding: 12px 24px; font-size: 12px; font-weight: 600; color: var(--ink); border-top: 1px solid var(--border); }

/* ── Pills (Match Profile Page) ── */
.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.pill.active { background: var(--ink); color: var(--bg); }
.pill.pending { background: var(--sand); color: var(--ink); }
.pill.blocked { background: var(--sand); color: var(--ink); }

/* ── Grand Footer ── */
.grand-footer { background: var(--ink); color: var(--bg); padding: 24px 32px; border-radius: 14px; margin-top: 40px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; text-align: center; }
.gf-item strong { display: block; font-family: 'Playfair Display', serif; font-size: 24px; font-weight: 400; }
.gf-item span { font-size: 10px; opacity: 0.7; letter-spacing: 1px; text-transform: uppercase; }
.gf-big { grid-column: 1 / -1; border-top: 1px solid rgba(255,255,255,0.2); padding-top: 20px; margin-top: 20px; }
.gf-big strong { font-size: 32px; }

/* ── Empty State ── */
.prompt-state { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 60px 32px; text-align: center; }
.prompt-state .p-title { font-family: 'Playfair Display', serif; font-size: 22px; font-weight: 400; color: var(--ink); margin-bottom: 8px; }
.prompt-state .p-sub { font-size: 12px; color: var(--ink); opacity: 0.7; }

/* Mobile */
@media (max-width: 768px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; padding: 0 16px; }
    .content { padding: 16px; }
    .filter-row { flex-direction: column; align-items: stretch; }
    .filter-group input { width: 100%; }
    .artist-header { flex-direction: column; align-items: flex-start; gap: 15px; }
    .artist-stats { width: 100%; justify-content: space-between; text-align: left; align-items: flex-start; }
    .grand-footer { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
    <div class="sidebar-brand"><div class="logo-tag">Art Bazaar</div><div class="logo-name">Dashboard</div><span class="logo-badge">Admin</span></div>
    <div class="sidebar-section">Overview</div>
    <a href="index.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> Overview</a>
    <div class="sidebar-section">Content</div>
    <a href="artworks.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg> Artworks</a>
    <a href="artists.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Artists</a>
    <a href="blogs.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/></svg> Blog Posts</a>
    <a href="categories.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h7"/></svg> Categories</a>
    <div class="sidebar-section">Requests</div>
    <a href="inquiries.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Orders & Inquiries</a>
    <a href="commissions.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Commissions</a>
    <a href="messages.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v13H4z"/><path d="M4 4l8 9 8-9"/></svg> Messages</a>
    <!-- Reports link is active here -->
    <a href="reports.php" class="nav-item active"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002 2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> Reports</a>
    <div class="sidebar-bottom"><a href="../../logout.php" class="signout-btn"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sign out</a></div>
</aside>

<!-- ══ TOPBAR ══ -->
<header class="topbar">
    <div class="topbar-left"><h1>Reports</h1><div class="sub">Artist Performance & Analytics</div></div>
    <div class="topbar-right"><div class="admin-chip"><div class="avatar"><?= strtoupper(substr($adminName, 0, 1)) ?></div><span class="name"><?= htmlspecialchars($adminName) ?></span><span class="arrow">∨</span></div></div>
</header>

<!-- ══ MAIN ══ -->
<main class="main">
<div class="content">

    <!-- Filter Card -->
    <div class="filter-card">
        <h3>Set Report Period</h3>
        <p>Choose a custom date range or pick a preset below.</p>
        <form method="GET">
            <div class="filter-row">
                <div class="filter-group"><label>From</label><input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" required></div>
                <div class="filter-group"><label>To</label><input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" required></div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">Generate Report</button>
                </div>
            </div>
            <div class="presets">
                <span>Quick</span>
                <?php
                $activePreset = $_GET['preset'] ?? '';
                $presetLabels = ['last_7'=>'Last 7 days','last_30'=>'Last 30 days','this_month'=>'This month','last_month'=>'Last month','this_year'=>'This year'];
                foreach ($presetLabels as $key => $label): ?>
                <a href="?preset=<?= $key ?>" class="preset-btn <?= $activePreset === $key ? 'active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        </form>
    </div>

    <?php if ($hasReport): ?>
        
        <!-- Summary Strip -->
        <div class="summary-strip">
            <div class="summary-card">
                <div class="s-label">Total Artwork Sales</div>
                <div class="s-value"><?= $summaryTotals['sales'] ?></div>
                <div class="s-sub">Across <?= count($reportData) ?> artist<?= count($reportData) !== 1 ? 's' : '' ?></div>
            </div>
            <div class="summary-card">
                <div class="s-label">Total Commissions</div>
                <div class="s-value"><?= $summaryTotals['commissions'] ?></div>
                <div class="s-sub">Custom artwork requests</div>
            </div>
            <div class="summary-card">
                <div class="s-label">Total Revenue</div>
                <div class="s-value" style="font-size:24px">PKR <?= number_format($summaryTotals['revenue']) ?></div>
                <div class="s-sub">Combined sales + commissions</div>
            </div>
        </div>

        <!-- Period Label -->
        <div class="period-info">
            Showing results from <strong><?= date('d M Y', strtotime($dateFrom)) ?></strong> to <strong><?= date('d M Y', strtotime($dateTo)) ?></strong> &mdash; <?= count($reportData) ?> artist<?= count($reportData) !== 1 ? 's' : '' ?> active
        </div>

        <?php if (empty($reportData)): ?>
            <div class="prompt-state">
                <div class="p-title">No activity in this period</div>
                <div class="p-sub">No sales or commissions were recorded.</div>
            </div>
        <?php else: ?>

            <?php foreach ($reportData as $aid => $data): 
                $p = $data['profile'];
                $tRev = $data['sales_rev'] + $data['comm_rev'];
            ?>
            <div class="artist-section">
                <!-- Artist Header -->
                <div class="artist-header">
                    <div class="artist-info">
                        <h3><?= htmlspecialchars($p['name']) ?></h3>
                        <div class="artist-meta">
                            <span>Joined: <?= date('M Y', strtotime($p['created_at'])) ?></span>
                            <span>&bull;</span>
                            <span><?= htmlspecialchars($p['city'] ?: 'N/A') ?></span>
                            <span>&bull;</span>
                            <span><?= $p['total_artworks'] ?> Total Works</span>
                            <span>&bull;</span>
                            <span><?= $p['sold_artworks'] ?> Sold</span>
                        </div>
                        <!-- Added Artist Contact Info -->
                        <div class="artist-contact">
                            <span>📧 <?= htmlspecialchars($p['email']) ?></span>
                            <span>📱 <?= htmlspecialchars($p['phone'] ?: 'N/A') ?></span>
                        </div>
                    </div>
                    <div class="artist-stats">
                        <div class="stat-item">
                            <span class="stat-label">Sales</span>
                            <span class="stat-val"><?= $data['sales_count'] ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Comm.</span>
                            <span class="stat-val"><?= $data['comm_count'] ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Revenue</span>
                            <span class="stat-val">PKR <?= number_format($tRev) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Unified Table -->
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th width="80">Date</th>
                                <th width="100">Order #</th>
                                <th>Type</th>
                                <th>Details</th>
                                <th>Customer</th>
                                <th width="100">Amount</th>
                                <th width="100">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['orders'] as $o): ?>
                            <tr>
                                <td><?= date('M d', strtotime($o['created_at'])) ?></td>
                                <td style="font-family:monospace;font-size:11px"><?= $o['order_number'] ?></td>
                                <td>
                                    <?php if($o['type'] == 'artwork'): ?>
                                        <span class="type-badge type-art">Artwork</span>
                                    <?php else: ?>
                                        <span class="type-badge type-comm">Comm</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight:500"><?= htmlspecialchars($o['display_title']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($o['buyer_name']) ?></td>
                                <td class="amount">PKR <?= number_format($o['order_total']) ?></td>
                                <td><?= getStatusPill($o['order_status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Artist Subtotal -->
                <div class="total-row">
                    Total Earnings for <?= htmlspecialchars($p['name']) ?>: PKR <?= number_format($tRev) ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Grand Footer -->
            <div class="grand-footer">
                <div class="gf-item">
                    <strong><?= count($reportData) ?></strong>
                    <span>Artists Active</span>
                </div>
                <div class="gf-item">
                    <strong><?= $summaryTotals['total_orders'] ?></strong>
                    <span>Total Orders</span>
                </div>
                <div class="gf-item">
                    <strong><?= $summaryTotals['sales'] ?></strong>
                    <span>Artwork Orders</span>
                </div>
                <div class="gf-item">
                    <strong><?= $summaryTotals['commissions'] ?></strong>
                    <span>Commission Orders</span>
                </div>
                <div class="gf-big">
                    <strong>PKR <?= number_format($summaryTotals['revenue']) ?></strong>
                    <span>Overall Grand Total</span>
                </div>
            </div>

        <?php endif; ?>

    <?php else: ?>
        <div class="prompt-state">
            <div class="p-title">Set a date range to generate a report</div>
            <div class="p-sub">Use the date picker above or pick a preset period like "This month" or "Last 30 days".</div>
        </div>
    <?php endif; ?>

</div>
</main>

</body>
</html>