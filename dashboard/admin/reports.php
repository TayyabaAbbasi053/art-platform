<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

$adminName = $_SESSION['name'] ?? 'Admin';

// ── Handle Mark as Paid (single order) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
    $orderId   = (int)($_POST['order_id'] ?? 0);
    $paidNotes = trim($_POST['paid_notes'] ?? '');
    if ($orderId) {
        $stmt = $conn->prepare("UPDATE orders SET artist_paid = 1, artist_paid_at = NOW(), artist_paid_notes = ? WHERE id = ?");
        $stmt->bind_param('si', $paidNotes, $orderId);
        $stmt->execute();
    }
    $qs = http_build_query(array_filter([
        'date_from' => $_POST['date_from'] ?? '',
        'date_to'   => $_POST['date_to']   ?? '',
        'preset'    => $_POST['preset']    ?? '',
        'paid_msg'  => '1',
    ]));
    header("Location: reports.php?$qs");
    exit;
}

// ── Date range ─────────────────────────────────────────────────
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';
$hasReport = false;
$reportData = [];
$summaryTotals = ['sales'=>0,'commissions'=>0,'revenue'=>0,'total_orders'=>0,'unpaid_amount'=>0];

$presets = [
    'this_month' => [date('Y-m-01'), date('Y-m-d')],
    'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    'last_7'     => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    'last_30'    => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    'this_year'  => [date('Y-01-01'), date('Y-m-d')],
];

if (isset($_GET['preset']) && isset($presets[$_GET['preset']])) {
    [$dateFrom, $dateTo] = $presets[$_GET['preset']];
}

if ($dateFrom && $dateTo) {
    $hasReport = true;
    $dfSafe = $conn->real_escape_string($dateFrom);
    $dtSafe = $conn->real_escape_string($dateTo);

    // Collect artist IDs active in period
    $artistIdsInPeriod = [];
    $r1 = $conn->query("SELECT DISTINCT aw.artist_id FROM orders o JOIN order_items oi ON o.id=oi.order_id AND oi.item_type='artwork' JOIN artworks aw ON oi.item_id=aw.id WHERE o.order_type='artwork' AND o.created_at BETWEEN '{$dfSafe} 00:00:00' AND '{$dtSafe} 23:59:59'");
    if ($r1) while ($row = $r1->fetch_assoc()) if ($row['artist_id']) $artistIdsInPeriod[] = $row['artist_id'];

    $r2 = $conn->query("SELECT DISTINCT cr.artist_id FROM orders o JOIN commission_requests cr ON cr.order_id=o.id WHERE o.order_type='commission' AND o.created_at BETWEEN '{$dfSafe} 00:00:00' AND '{$dtSafe} 23:59:59'");
    if ($r2) while ($row = $r2->fetch_assoc()) if ($row['artist_id']) $artistIdsInPeriod[] = $row['artist_id'];

    $artistIdsInPeriod = array_unique(array_filter($artistIdsInPeriod));

    // Fetch artist profiles
    $artistProfiles = [];
    if (!empty($artistIdsInPeriod)) {
        $idsStr = implode(',', $artistIdsInPeriod);
        $rp = $conn->query("
            SELECT u.id, u.name, u.email, u.phone, u.status AS account_status, u.created_at,
                   ap.city, ap.art_style, ap.contact_email, ap.contact_phone,
                   (SELECT COUNT(*) FROM artworks WHERE artist_id=u.id) AS total_artworks,
                   (SELECT COUNT(*) FROM artworks WHERE artist_id=u.id AND status='sold') AS sold_artworks
            FROM users u LEFT JOIN artist_profiles ap ON ap.user_id=u.id
            WHERE u.id IN ($idsStr)");
        if ($rp) while ($row = $rp->fetch_assoc()) $artistProfiles[$row['id']] = $row;
    }

    // Artwork orders
    $salesData = $conn->query("
        SELECT aw.artist_id, o.id AS order_id, o.order_number, o.created_at,
               o.total AS order_total, o.subtotal, o.shipping_fee,
               o.order_status, o.payment_status, o.payment_method,
               o.artist_paid, o.artist_paid_at, o.artist_paid_notes,
               aw.title AS artwork_title, aw.price AS artwork_price,
               COALESCE(u.name, o.guest_name) AS buyer_name
        FROM orders o
        JOIN order_items oi ON oi.order_id=o.id AND oi.item_type='artwork'
        JOIN artworks aw ON oi.item_id=aw.id
        LEFT JOIN users u ON o.buyer_id=u.id
        WHERE o.order_type='artwork'
          AND o.created_at BETWEEN '{$dfSafe} 00:00:00' AND '{$dtSafe} 23:59:59'
        ORDER BY o.created_at DESC");

    // Commission orders
    $commData = $conn->query("
        SELECT cr.artist_id, o.id AS order_id, o.order_number, o.created_at,
               o.total AS order_total, o.subtotal, o.shipping_fee,
               o.order_status, o.payment_status, o.payment_method,
               o.artist_paid, o.artist_paid_at, o.artist_paid_notes,
               cat.name AS category_name,
               COALESCE(u.name, o.guest_name) AS buyer_name
        FROM orders o
        JOIN commission_requests cr ON cr.order_id=o.id
        LEFT JOIN categories cat ON o.commission_category_id=cat.id
        LEFT JOIN users u ON o.buyer_id=u.id
        WHERE o.order_type='commission'
          AND o.created_at BETWEEN '{$dfSafe} 00:00:00' AND '{$dtSafe} 23:59:59'
        ORDER BY o.created_at DESC");

    // Structure data
    $structured = [];
    foreach ($artistProfiles as $aid => $prof) {
        $structured[$aid] = ['profile'=>$prof,'orders'=>[],'sales_rev'=>0,'comm_rev'=>0,'sales_count'=>0,'comm_count'=>0,'unpaid_amount'=>0];
    }

    $processRow = function($row, $type) use (&$structured, &$summaryTotals) {
        $aid = $row['artist_id'];
        if (!isset($structured[$aid])) {
            $structured[$aid] = ['profile'=>['name'=>'Unknown Artist','city'=>'','created_at'=>date('Y-m-d'),'total_artworks'=>0,'sold_artworks'=>0,'email'=>'','phone'=>'','contact_email'=>'','contact_phone'=>''],'orders'=>[],'sales_rev'=>0,'comm_rev'=>0,'sales_count'=>0,'comm_count'=>0,'unpaid_amount'=>0];
        }
        $artPrice    = (float)($type === 'artwork' ? ($row['artwork_price'] ?? $row['subtotal']) : (float)$row['subtotal']);
        $shippingFee = (float)($row['shipping_fee'] ?? 0);

        $row['type']               = $type;
        $row['display_title']      = $type === 'artwork' ? ($row['artwork_title'] ?? '—') : ($row['category_name'] ?? 'Custom Request');
        $row['artwork_price_calc'] = $artPrice;

        $structured[$aid]['orders'][] = $row;
        $rev = (float)$row['order_total'];

        if ($type === 'artwork') { $structured[$aid]['sales_rev'] += $rev; $structured[$aid]['sales_count']++; $summaryTotals['sales']++; }
        else                     { $structured[$aid]['comm_rev']  += $rev; $structured[$aid]['comm_count']++;  $summaryTotals['commissions']++; }

        $summaryTotals['revenue']      += $rev;
        $summaryTotals['total_orders'] ++;
        if (!$row['artist_paid']) {
    $structured[$aid]['unpaid_amount']  += $artPrice;
    $summaryTotals['unpaid_amount']     += $artPrice;
}
    };

    if ($salesData) while ($row = $salesData->fetch_assoc()) $processRow($row, 'artwork');
    if ($commData)  while ($row = $commData->fetch_assoc())  $processRow($row, 'commission');

    $structured = array_filter($structured, fn($a) => count($a['orders']) > 0);
    uasort($structured, fn($a,$b) => ($b['sales_rev']+$b['comm_rev']) <=> ($a['sales_rev']+$a['comm_rev']));
    $reportData = $structured;
}

function getStatusPill($status) {
    $s = strtolower($status); $cls = 'pill';
    if (in_array($s, ['pending','price_proposed']))      $cls .= ' pending';
    elseif (in_array($s, ['confirmed','delivered','active'])) $cls .= ' active';
    elseif (in_array($s, ['processing','shipped']))      $cls .= ' pending';
    elseif ($s === 'cancelled')                          $cls .= ' blocked';
    return "<span class='$cls'>".ucfirst(str_replace('_',' ',$status))."</span>";
}
function paidBadge($paid, $paidAt) {
    if ($paid) { $d = $paidAt ? ' '.date('d M Y', strtotime($paidAt)) : ''; return "<span class='pill paid-pill'>&#10003; Paid$d</span>"; }
    return "<span class='pill unpaid-pill'>Unpaid</span>";
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
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{--bg:#F6EDDE;--card:#F6EDDE;--sand:#DDCDAE;--border:#0C3F30;--ink:#0C3F30;--sidebar:240px;--top:60px;}
html,body{height:100%;background:var(--bg);color:var(--ink);font-family:'DM Sans',sans-serif;}

/* Sidebar */
.sidebar{position:fixed;top:0;left:0;width:var(--sidebar);height:100vh;background:var(--ink);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;overflow-y:auto;}
.sidebar-brand{padding:22px 24px 18px;border-bottom:1px solid var(--border);}
.sidebar-brand .logo-tag{font-size:10px;letter-spacing:3px;text-transform:uppercase;color:var(--sand);}
.sidebar-brand .logo-name{font-family:'Playfair Display',serif;font-size:20px;color:var(--bg);margin-top:2px;}
.sidebar-brand .logo-badge{display:inline-block;margin-top:6px;background:var(--sand);color:var(--ink);font-size:8px;letter-spacing:2px;text-transform:uppercase;padding:2px 7px;border-radius:20px;}
.sidebar-section{padding:18px 16px 6px;font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);font-weight:500;}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 20px;font-size:12.5px;color:var(--bg);text-decoration:none;border-left:2px solid transparent;transition:all .15s;}
.nav-item:hover{color:var(--ink);background:var(--sand);border-left-color:var(--sand);}
.nav-item.active{color:var(--ink);background:var(--sand);border-left-color:var(--ink);font-weight:500;}
.nav-item .icon{width:16px;height:16px;flex-shrink:0;opacity:.8;stroke:var(--bg);}
.nav-item.active .icon,.nav-item:hover .icon{stroke:var(--ink);opacity:1;}
.sidebar-bottom{margin-top:auto;padding:16px;border-top:1px solid var(--border);}
.signout-btn{display:flex;align-items:center;gap:8px;padding:9px 12px;font-size:12px;color:var(--bg);text-decoration:none;border-radius:8px;transition:all .15s;width:100%;background:none;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;}
.signout-btn:hover{background:var(--sand);color:var(--ink);}

/* Topbar */
.topbar{position:fixed;top:0;left:var(--sidebar);right:0;height:var(--top);background:var(--ink);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 32px;z-index:99;}
.topbar-left h1{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;color:var(--bg);}
.topbar-left .sub{font-size:11px;color:var(--sand);margin-top:1px;}
.admin-chip{display:flex;align-items:center;gap:8px;background:var(--sand);border:1px solid var(--border);padding:5px 12px 5px 5px;border-radius:30px;}
.admin-chip .avatar{width:26px;height:26px;border-radius:50%;background:var(--bg);display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--ink);font-weight:600;}
.admin-chip .name{font-size:12px;color:var(--ink);font-weight:500;}

/* Layout */
.main{margin-left:var(--sidebar);padding-top:var(--top);min-height:100vh;}
.content{padding:28px 32px;}

/* Filter */
.filter-card{background:var(--card);border-radius:14px;padding:24px 28px;margin-bottom:28px;border:1px solid var(--border);}
.filter-card h3{font-family:'Playfair Display',serif;font-size:18px;font-weight:400;margin-bottom:6px;}
.filter-card p{font-size:12px;opacity:.8;margin-bottom:20px;}
.filter-row{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;}
.filter-group{display:flex;flex-direction:column;gap:6px;}
.filter-group label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;font-weight:500;opacity:.7;}
.filter-group input[type="date"]{background:var(--bg);border:1px solid var(--border);color:var(--ink);padding:8px 12px;border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;outline:none;min-width:160px;}
.btn{padding:9px 18px;border-radius:9px;border:none;font-size:12px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s;}
.btn-primary{background:var(--ink);color:var(--bg);}
.btn-primary:hover{opacity:.9;}
.btn-sm{padding:5px 12px;font-size:11px;border-radius:6px;}
.btn-pay{background:var(--ink);color:var(--bg);border:none;cursor:pointer;font-family:'DM Sans',sans-serif;}
.btn-pay:hover{opacity:.85;}
.presets{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid var(--border);}
.presets span{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;align-self:center;margin-right:4px;font-weight:500;opacity:.7;}
.preset-btn{background:transparent;border:1px solid var(--border);color:var(--ink);padding:5px 14px;border-radius:20px;font-size:11px;font-family:'DM Sans',sans-serif;cursor:pointer;text-decoration:none;transition:all .15s;white-space:nowrap;}
.preset-btn:hover,.preset-btn.active{background:var(--ink);color:var(--bg);border-color:var(--ink);}

/* Alert */
.alert{padding:11px 16px;border-radius:8px;font-size:12.5px;margin-bottom:20px;}
.alert-success{background:var(--sand);border:1px solid var(--border);color:var(--ink);}

/* Summary */
.summary-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px;}
.summary-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px 22px;}
.summary-card .s-label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;font-weight:500;margin-bottom:8px;opacity:.7;}
.summary-card .s-value{font-family:'Playfair Display',serif;font-size:28px;line-height:1;}
.summary-card .s-sub{font-size:11px;margin-top:4px;opacity:.7;}
.summary-card.highlight{background:var(--ink);}
.summary-card.highlight .s-label,.summary-card.highlight .s-value,.summary-card.highlight .s-sub{color:var(--bg);opacity:1;}

/* Period label */
.period-info{margin-bottom:20px;font-size:12px;}
.period-info strong{font-weight:600;}

/* Artist blocks */
.artist-section{background:var(--card);border:1px solid var(--border);border-radius:14px;margin-bottom:32px;overflow:hidden;}
.artist-header{padding:20px 24px;background:rgba(12,63,48,0.03);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:flex-start;gap:20px;}
.artist-info h3{font-family:'Playfair Display',serif;font-size:21px;font-weight:400;margin-bottom:5px;}
.artist-meta{display:flex;gap:10px;font-size:12px;opacity:.8;margin-bottom:5px;flex-wrap:wrap;}
.artist-contact{display:flex;gap:14px;font-size:11px;opacity:.7;margin-top:4px;flex-wrap:wrap;}
.artist-stats{display:flex;gap:20px;text-align:right;flex-shrink:0;}
.stat-item{display:flex;flex-direction:column;align-items:flex-end;}
.stat-label{font-size:9px;letter-spacing:1px;text-transform:uppercase;font-weight:500;opacity:.7;}
.stat-val{font-size:18px;font-weight:600;}
.unpaid-stat{color:#c0392b !important;}

/* Sub-header */
.orders-subhd{padding:10px 24px;font-size:10px;letter-spacing:2px;text-transform:uppercase;font-weight:600;opacity:.6;background:rgba(12,63,48,0.02);border-bottom:1px solid var(--border);}

/* Tables */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th{text-align:left;padding:10px 12px;background:var(--card);font-weight:500;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;border-bottom:1px solid var(--border);opacity:.7;}
td{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr.is-paid{opacity:.55;}
.amount{font-weight:600;}

/* Total footer row */
.total-row{background:var(--sand);padding:12px 24px;font-size:12px;font-weight:600;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;}
.unpaid-warn{color:#c0392b;font-weight:700;}

/* Pills */
.pill{display:inline-block;font-size:9px;letter-spacing:.5px;text-transform:uppercase;font-weight:600;padding:3px 9px;border-radius:20px;}
.pill.active{background:var(--ink);color:var(--bg);}
.pill.pending{background:var(--sand);color:var(--ink);}
.pill.blocked{background:var(--sand);color:var(--ink);}
.paid-pill{background:var(--ink);color:var(--bg);}
.unpaid-pill{background:var(--sand);color:var(--ink);}

/* Modal */
.mbg{display:none;position:fixed;inset:0;background:rgba(12,63,48,.55);backdrop-filter:blur(3px);z-index:500;align-items:center;justify-content:center;padding:16px;}
.mbg.open{display:flex;}
.modal{background:var(--card);border-radius:14px;width:100%;max-width:440px;max-height:92vh;overflow-y:auto;}
.mhd{padding:22px 24px 0;display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px;}
.mhd h3{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;}
.mcls{background:var(--sand);border:none;border-radius:6px;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--ink);flex-shrink:0;font-size:14px;}
.mcls:hover{background:var(--border);color:var(--bg);}
.mbd{padding:14px 24px 24px;}
.mbd p{font-size:12px;opacity:.75;margin-bottom:14px;}
.order-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;background:rgba(12,63,48,0.04);border:1px solid var(--sand);border-radius:8px;padding:14px 16px;margin-bottom:16px;font-size:12.5px;}
.od-label{opacity:.65;font-size:11px;}
.od-val{font-weight:600;}
.fg{margin-bottom:12px;}
.fg label{display:block;font-size:10px;letter-spacing:.7px;text-transform:uppercase;font-weight:500;margin-bottom:5px;}
.fi,.ft{width:100%;padding:9px 12px;border:1.5px solid var(--sand);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--ink);background:var(--bg);outline:none;transition:border-color .12s;}
.fi:focus,.ft:focus{border-color:var(--ink);}
.ft{min-height:70px;resize:vertical;line-height:1.5;}
.msub{width:100%;background:var(--ink);color:var(--bg);border:none;padding:11px;border-radius:8px;font-size:13px;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;margin-top:2px;}
.msub:hover{opacity:.9;}

/* Grand footer */
.grand-footer{background:var(--ink);color:var(--bg);padding:24px 32px;border-radius:14px;margin-top:40px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:20px;text-align:center;}
.gf-item strong{display:block;font-family:'Playfair Display',serif;font-size:24px;font-weight:400;}
.gf-item span{font-size:10px;opacity:.7;letter-spacing:1px;text-transform:uppercase;}
.gf-big{grid-column:1/-1;border-top:1px solid rgba(255,255,255,.2);padding-top:20px;margin-top:20px;}
.gf-big strong{font-size:32px;}

/* Empty */
.prompt-state{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:60px 32px;text-align:center;}
.prompt-state .p-title{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;margin-bottom:8px;}
.prompt-state .p-sub{font-size:12px;opacity:.7;}

@media(max-width:768px){
    :root{--sidebar:0px;}
    .sidebar{display:none;}
    .topbar{left:0;padding:0 16px;}
    .content{padding:16px;}
    .filter-row{flex-direction:column;align-items:stretch;}
    .artist-header{flex-direction:column;gap:14px;}
    .artist-stats{width:100%;justify-content:space-between;text-align:left;align-items:flex-start;}
    .summary-strip{grid-template-columns:1fr 1fr;}
    .grand-footer{grid-template-columns:1fr 1fr;}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-brand"><div class="logo-tag">Art Bazaar</div><div class="logo-name">Dashboard</div><span class="logo-badge">Admin</span></div>
    <div class="sidebar-section">Overview</div>
    <a href="index.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> Overview</a>
    <div class="sidebar-section">Content</div>
    <a href="artworks.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg> Artworks</a>
    <a href="artists.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Artists</a>
    <a href="blogs.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/></svg> Blog Posts</a>
    <a href="categories.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h7"/></svg> Categories</a>
    <div class="sidebar-section">Requests</div>
    <a href="inquiries.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Orders & Inquiries</a>
    <a href="commissions.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Commissions</a>
    <a href="messages.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v13H4z"/><path d="M4 4l8 9 8-9"/></svg> Messages</a>
    <a href="reports.php" class="nav-item active"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> Reports</a>
    <div class="sidebar-bottom"><a href="../../logout.php" class="signout-btn"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sign out</a></div>
</aside>

<!-- TOPBAR -->
<header class="topbar">
    <div class="topbar-left"><h1>Reports</h1><div class="sub">Artist Performance &amp; Payment Tracking</div></div>
    <div class="topbar-right"><div class="admin-chip"><div class="avatar"><?= strtoupper(substr($adminName,0,1)) ?></div><span class="name"><?= htmlspecialchars($adminName) ?></span></div></div>
</header>

<main class="main">
<div class="content">

<?php if (isset($_GET['paid_msg']) && $_GET['paid_msg'] === '1'): ?>
<div class="alert alert-success">&#10003; Order marked as paid to artist successfully.</div>
<?php endif; ?>

<!-- Filter Card -->
<div class="filter-card">
    <h3>Set Report Period</h3>
    <p>Select a date range to see all orders, revenue, and which artists still need to be paid.</p>
    <form method="GET">
        <div class="filter-row">
            <div class="filter-group"><label>From</label><input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" required></div>
            <div class="filter-group"><label>To</label><input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" required></div>
            <div class="filter-group"><button type="submit" class="btn btn-primary">Generate Report</button></div>
        </div>
        <div class="presets">
            <span>Quick</span>
            <?php
            $activePreset = $_GET['preset'] ?? '';
            foreach (['last_7'=>'Last 7 days','last_30'=>'Last 30 days','this_month'=>'This month','last_month'=>'Last month','this_year'=>'This year'] as $key=>$label): ?>
            <a href="?preset=<?= $key ?>" class="preset-btn <?= $activePreset===$key?'active':'' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </form>
</div>

<?php if ($hasReport): ?>

<!-- Summary Strip -->
<div class="summary-strip">
    <div class="summary-card">
        <div class="s-label">Artwork Sales</div>
        <div class="s-value"><?= $summaryTotals['sales'] ?></div>
        <div class="s-sub"><?= count($reportData) ?> artist<?= count($reportData)!==1?'s':'' ?></div>
    </div>
    <div class="summary-card">
        <div class="s-label">Commissions</div>
        <div class="s-value"><?= $summaryTotals['commissions'] ?></div>
        <div class="s-sub">Custom requests</div>
    </div>
    <div class="summary-card">
        <div class="s-label">Total Revenue</div>
        <div class="s-value" style="font-size:22px">PKR <?= number_format($summaryTotals['revenue']) ?></div>
        <div class="s-sub">Sales + commissions</div>
    </div>
    <div class="summary-card highlight">
        <div class="s-label">Unpaid to Artists</div>
        <div class="s-value" style="font-size:22px">PKR <?= number_format($summaryTotals['unpaid_amount']) ?></div>
        <div class="s-sub">Pending payouts</div>
    </div>
</div>

<div class="period-info">
    Showing <strong><?= date('d M Y', strtotime($dateFrom)) ?></strong> to <strong><?= date('d M Y', strtotime($dateTo)) ?></strong> &mdash; <?= count($reportData) ?> artist<?= count($reportData)!==1?'s':'' ?> active
</div>

<?php if (empty($reportData)): ?>
<div class="prompt-state"><div class="p-title">No activity in this period</div><div class="p-sub">No sales or commissions were recorded.</div></div>
<?php else: ?>

<?php foreach ($reportData as $aid => $data):
    $p      = $data['profile'];
    $tRev   = $data['sales_rev'] + $data['comm_rev'];
    $unpaid = $data['unpaid_amount'];
    $artOrders  = array_filter($data['orders'], fn($o) => $o['type']==='artwork');
    $commOrders = array_filter($data['orders'], fn($o) => $o['type']==='commission');
?>

<div class="artist-section">

    <!-- Artist Header -->
    <div class="artist-header">
        <div class="artist-info">
            <h3><?= htmlspecialchars($p['name']) ?></h3>
            <div class="artist-meta">
                <span>Joined <?= date('M Y', strtotime($p['created_at'])) ?></span>
                <span>&bull;</span><span><?= htmlspecialchars($p['city'] ?: 'N/A') ?></span>
                <span>&bull;</span><span><?= $p['total_artworks'] ?> works listed</span>
                <span>&bull;</span><span><?= $p['sold_artworks'] ?> sold</span>
            </div>
            <div class="artist-contact">
                <span>&#128231; <?= htmlspecialchars($p['contact_email'] ?: $p['email']) ?></span>
                <span>&#128241; <?= htmlspecialchars($p['contact_phone'] ?: ($p['phone'] ?: 'N/A')) ?></span>
            </div>
        </div>
        <div class="artist-stats">
            <div class="stat-item"><span class="stat-label">Sales</span><span class="stat-val"><?= $data['sales_count'] ?></span></div>
            <div class="stat-item"><span class="stat-label">Comm.</span><span class="stat-val"><?= $data['comm_count'] ?></span></div>
            <div class="stat-item"><span class="stat-label">Revenue</span><span class="stat-val">PKR <?= number_format($tRev) ?></span></div>
            <?php if ($unpaid > 0): ?>
            <div class="stat-item"><span class="stat-label">Unpaid</span><span class="stat-val unpaid-stat">PKR <?= number_format($unpaid) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $renderTable = function($orders, $label) use ($p, $conn) { ?>
    <div class="orders-subhd"><?= $label ?> (<?= count($orders) ?>)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Order #</th>
                    <th>Item</th>
                    <th>Buyer</th>
                    <th>Artwork Price</th>
                    <th>Total Order Price</th>
                    <th>Order Status</th>
                    <th>Payment</th>
                    <th>Paid to Artist</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
            <tr class="<?= $o['artist_paid'] ? 'is-paid' : '' ?>">
                <td style="white-space:nowrap"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                <td style="font-family:monospace;font-size:11px"><?= htmlspecialchars($o['order_number']) ?></td>
                <td><div style="font-weight:500;max-width:130px"><?= htmlspecialchars($o['display_title']) ?></div></td>
                <td><?= htmlspecialchars($o['buyer_name']) ?></td>
                <td class="amount">PKR <?= number_format($o['artwork_price_calc']) ?></td>
<td class="amount">PKR <?= number_format($o['order_total']) ?></td>
                <td><?= getStatusPill($o['order_status']) ?></td>
                <td><?= getStatusPill($o['payment_status']) ?></td>
                <td><?= paidBadge($o['artist_paid'], $o['artist_paid_at']) ?></td>
                <td>
                    <?php if (!$o['artist_paid']): ?>
                    <button class="btn btn-sm btn-pay" onclick="openPayModal(
                        <?= $o['order_id'] ?>,
                        '<?= addslashes(htmlspecialchars($o['order_number'])) ?>',
                        '<?= addslashes(htmlspecialchars($o['display_title'])) ?>',
                        '<?= addslashes(htmlspecialchars($p['name'])) ?>',
                        <?= $o['artwork_price_calc'] ?>,
                        <?= $o['order_total'] ?>
                    )">Mark Paid</button>
                    <?php else: ?>
                    <span style="font-size:11px;opacity:.45">Done<?= $o['artist_paid_notes'] ? ' &bull; '.htmlspecialchars(substr($o['artist_paid_notes'],0,30)) : '' ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php }; ?>

    <?php if (!empty($artOrders))  $renderTable($artOrders,  'Artwork Sales'); ?>
    <?php if (!empty($commOrders)) $renderTable($commOrders, 'Commission Orders'); ?>

    <!-- Artist totals row -->
    <div class="total-row">
        <span>Total revenue for <strong><?= htmlspecialchars($p['name']) ?></strong>: PKR <?= number_format($tRev) ?></span>
        <?php if ($unpaid > 0): ?>
        <span class="unpaid-warn">&#9888; Still owed to artist: PKR <?= number_format($unpaid) ?></span>
        <?php else: ?>
        <span style="opacity:.65">&#10003; All orders paid out</span>
        <?php endif; ?>
    </div>

</div><!-- .artist-section -->
<?php endforeach; ?>

<!-- Grand Footer -->
<div class="grand-footer">
    <div class="gf-item"><strong><?= count($reportData) ?></strong><span>Artists Active</span></div>
    <div class="gf-item"><strong><?= $summaryTotals['total_orders'] ?></strong><span>Total Orders</span></div>
    <div class="gf-item"><strong><?= $summaryTotals['sales'] ?></strong><span>Artwork Sales</span></div>
    <div class="gf-item"><strong><?= $summaryTotals['commissions'] ?></strong><span>Commissions</span></div>
    <div class="gf-big"><strong>PKR <?= number_format($summaryTotals['revenue']) ?></strong><span>Overall Grand Total Revenue</span></div>
</div>

<?php endif; // empty reportData ?>
<?php else: // !hasReport ?>
<div class="prompt-state">
    <div class="p-title">Set a date range to generate a report</div>
    <div class="p-sub">Use the date picker above or pick a preset like "This month" or "Last 30 days".</div>
</div>
<?php endif; ?>

</div>
</main>

<!-- MARK PAID MODAL -->
<div class="mbg" id="pay-modal">
    <div class="modal">
        <div class="mhd">
            <h3>Mark as Paid to Artist</h3>
            <button class="mcls" onclick="document.getElementById('pay-modal').classList.remove('open')">&#10005;</button>
        </div>
        <div class="mbd">
            <p>Confirm you have transferred this artist's payout. This cannot be undone.</p>

            <div class="order-detail-grid">
                <span class="od-label">Order #</span><span class="od-val" id="md-order-num">—</span>
                <span class="od-label">Artist</span><span class="od-val" id="md-artist">—</span>
                <span class="od-label">Item</span><span class="od-val" id="md-item">—</span>
                <span class="od-label">Artwork Price</span><span class="od-val" id="md-art-price">—</span>
<span class="od-label">Total Order Price</span><span class="od-val" id="md-shipping">—</span>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="order_id" id="modal-order-id" value="">
                <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="hidden" name="date_to"   value="<?= htmlspecialchars($dateTo) ?>">
                <input type="hidden" name="preset"    value="<?= htmlspecialchars($_GET['preset'] ?? '') ?>">
                <div class="fg">
                    <label>Notes (optional)</label>
                    <textarea name="paid_notes" class="ft" placeholder="e.g. Sent via JazzCash, ref #12345 on 13 June 2026..."></textarea>
                </div>
                <button type="submit" class="msub">&#10003; Confirm Payment to Artist</button>
            </form>
        </div>
    </div>
</div>

<script>
function openPayModal(orderId, orderNum, itemTitle, artistName, artPrice, orderTotal) {
    document.getElementById('modal-order-id').value     = orderId;
    document.getElementById('md-order-num').textContent = orderNum;
    document.getElementById('md-artist').textContent    = artistName;
    document.getElementById('md-item').textContent      = itemTitle;
    document.getElementById('md-art-price').textContent = 'PKR ' + Number(artPrice).toLocaleString();
    document.getElementById('md-shipping').textContent  = 'PKR ' + Number(orderTotal).toLocaleString(); // shippingFee here is actually order_total now
    document.getElementById('pay-modal').classList.add('open');
}
document.getElementById('pay-modal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
</script>

</body>
</html>