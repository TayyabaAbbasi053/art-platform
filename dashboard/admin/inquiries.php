<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

$adminName = $_SESSION['name'] ?? 'Admin';
$toast = '';

// Helper function to get correct image path
function getArtworkImageUrl($imagePath) {
    if (empty($imagePath)) {
        return null;
    }
    // Remove any leading slashes or dots
    $imagePath = ltrim($imagePath, './');
    // If it's already a full path starting with uploads/
    if (strpos($imagePath, 'uploads/') === 0) {
        return '../../' . $imagePath;
    }
    // If it already has uploads/ in it somewhere
    if (strpos($imagePath, 'uploads/') !== false) {
        return '../../' . $imagePath;
    }
    // Default fallback - assume it's just a filename in uploads/artworks/
    return '../../uploads/artworks/' . $imagePath;
}

// ── Handle actions ──────────────────────────────────────

// Update status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $id = (int) ($_POST['id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    if ($id && in_array($newStatus, ['new', 'contacted', 'confirmed', 'completed', 'cancelled'])) {
        $conn->query("UPDATE buyer_inquiries SET status = '$newStatus' WHERE id = $id");
        $toast = 'Inquiry status updated to ' . ucfirst($newStatus) . '.';
    }
}

// Save admin notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notes') {
    $id = (int) ($_POST['id'] ?? 0);
    $notes = trim($_POST['admin_notes'] ?? '');
    if ($id) {
        $stmt = $conn->prepare("UPDATE buyer_inquiries SET admin_notes = ? WHERE id = ?");
        $stmt->bind_param('si', $notes, $id);
        $stmt->execute();
        $toast = 'Notes saved.';
    }
}

// Delete inquiry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("DELETE FROM buyer_inquiries WHERE id = $id");
        $toast = 'Inquiry deleted.';
    }
}

// ── Build query ─────────────────────────────────────────
$where = ["1=1"];
$params = [];
$types = '';

$statusFilter = $_GET['status'] ?? '';
if (in_array($statusFilter, ['new', 'contacted', 'confirmed', 'completed', 'cancelled'])) {
    $where[] = "bi.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$search = trim($_GET['q'] ?? '');
if ($search) {
    $where[] = "(bi.buyer_name LIKE ? OR bi.buyer_email LIKE ? OR bi.buyer_phone LIKE ? OR a.title LIKE ? OR u.name LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
    $types .= 'sssss';
}

$artworkFilter = (int) ($_GET['artwork'] ?? 0);
if ($artworkFilter > 0) {
    $where[] = "bi.artwork_id = ?";
    $params[] = $artworkFilter;
    $types .= 'i';
}

$whereSQL = implode(' AND ', $where);

$sortMap = [
    'newest' => 'bi.created_at DESC',
    'oldest' => 'bi.created_at ASC',
    'name' => 'bi.buyer_name ASC',
];
$sortBy = $sortMap[$_GET['sort'] ?? ''] ?? 'bi.created_at DESC';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Count
$countSQL = "SELECT COUNT(*) FROM buyer_inquiries bi JOIN artworks a ON a.id = bi.artwork_id JOIN users u ON u.id = a.artist_id WHERE $whereSQL";
if ($params) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalResults = (int) $stmt->get_result()->fetch_row()[0];
} else {
    $totalResults = (int) $conn->query($countSQL)->fetch_row()[0];
}
$totalPages = max(1, ceil($totalResults / $perPage));

// Fetch
$dataSQL = "
    SELECT bi.*, a.title AS artwork_title, a.price AS artwork_price,
           a.status AS artwork_status,
           (SELECT image_path FROM artwork_images WHERE artwork_id = a.id AND is_cover = 1 LIMIT 1) AS artwork_image,
           u.name AS artist_name, u.id AS artist_id, c.name AS category_name
    FROM buyer_inquiries bi
    JOIN artworks a ON a.id = bi.artwork_id
    JOIN users u ON u.id = a.artist_id
    JOIN categories c ON c.id = a.category_id
    WHERE $whereSQL
    ORDER BY $sortBy
    LIMIT $perPage OFFSET $offset
";
$inquiries = [];
if ($params) {
    $stmt = $conn->prepare($dataSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($dataSQL);
}
while ($row = $res->fetch_assoc())
    $inquiries[] = $row;

// Status counts
$statusCounts = [];
foreach (['new', 'contacted', 'confirmed', 'completed', 'cancelled'] as $s) {
    $r = $conn->query("SELECT COUNT(*) FROM buyer_inquiries WHERE status='$s'");
    $statusCounts[$s] = (int) $r->fetch_row()[0];
}
$statusCounts['all'] = array_sum($statusCounts);

// Unique artworks for filter dropdown
$artworkOptions = [];
$aoRes = $conn->query("
    SELECT DISTINCT a.id, a.title FROM buyer_inquiries bi
    JOIN artworks a ON a.id = bi.artwork_id
    ORDER BY a.title ASC LIMIT 50
");
while ($row = $aoRes->fetch_assoc())
    $artworkOptions[] = $row;

function buildQS($overrides = [])
{
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null)
            unset($q[$k]);
        else
            $q[$k] = $v;
    }
    unset($q['page']);
    return http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Inquiries — Art Bazaar Admin</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap"
        rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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
            --blue: #0984e3;
            --purple: #5e35b1;
            --terracotta: #C96B4B;
            --sidebar: 240px;
            --top: 60px;
        }

        html,
        body {
            height: 100%;
            background: var(--grey1);
            color: var(--black);
            font-family: 'DM Sans', sans-serif;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar);
            height: 100vh;
            background: #EFE3D2;
            border-right: 1px solid var(--grey2);
            display: flex;
            flex-direction: column;
            z-index: 100;
            overflow-y: auto;
        }

        .sidebar-brand {
            padding: 22px 24px 18px;
            border-bottom: 1px solid var(--grey2);
        }

        .sidebar-brand .logo-tag {
            font-size: 10px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--grey4);
        }

        .sidebar-brand .logo-name {
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            color: var(--black);
            margin-top: 2px;
        }

        .sidebar-brand .logo-badge {
            display: inline-block;
            margin-top: 6px;
            background: var(--terracotta);
            color: var(--white);
            font-size: 8px;
            letter-spacing: 2px;
            text-transform: uppercase;
            padding: 2px 7px;
            border-radius: 20px;
        }

        .sidebar-section {
            padding: 18px 16px 6px;
            font-size: 9px;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: var(--grey4);
            font-weight: 500;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            font-size: 12.5px;
            color: var(--grey5);
            text-decoration: none;
            font-weight: 400;
            border-left: 2px solid transparent;
            transition: all .15s;
        }

        .nav-item:hover {
            color: var(--black);
            background: rgba(255,255,255,0.3);
            border-left-color: var(--grey3);
        }

        .nav-item.active {
            color: var(--black);
            background: rgba(255,255,255,0.4);
            border-left-color: var(--terracotta);
            font-weight: 500;
        }

        .nav-item .icon {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            opacity: .55;
        }

        .nav-item.active .icon,
        .nav-item:hover .icon {
            opacity: 1;
        }

        .badge {
            margin-left: auto;
            background: var(--terracotta);
            color: #fff;
            font-size: 9px;
            font-weight: 600;
            padding: 1px 6px;
            border-radius: 20px;
            min-width: 18px;
            text-align: center;
        }

        .sidebar-bottom {
            margin-top: auto;
            padding: 16px;
            border-top: 1px solid var(--grey2);
        }

        .signout-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            font-size: 12px;
            color: var(--grey5);
            text-decoration: none;
            border-radius: 8px;
            transition: all .15s;
            width: 100%;
            background: none;
            border: none;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
        }

        .signout-btn:hover {
            background: #FFF0EC;
            color: var(--terracotta);
        }

        .topbar {
            position: fixed;
            top: 0;
            left: var(--sidebar);
            right: 0;
            height: var(--top);
            background: var(--white);
            border-bottom: 1px solid var(--grey2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            z-index: 99;
        }

        .topbar-left h1 {
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            font-weight: 400;
            color: var(--black);
        }

        .topbar-left .sub {
            font-size: 11px;
            color: var(--grey4);
            margin-top: 1px;
        }

        .admin-chip {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--grey1);
            border: 1px solid var(--grey2);
            padding: 5px 12px 5px 5px;
            border-radius: 30px;
        }

        .admin-chip .avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--terracotta);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            color: #fff;
            font-weight: 600;
        }

        .admin-chip .name {
            font-size: 12px;
            color: var(--black);
            font-weight: 500;
        }

        .admin-chip .arrow {
            font-size: 12px;
            color: var(--grey4);
            margin-left: 4px;
        }

        .main {
            margin-left: var(--sidebar);
            padding-top: var(--top);
            min-height: 100vh;
        }

        .content {
            padding: 28px 32px;
        }

        .toast {
            background: #FCEEE2;
            color: var(--black);
            border: 1px solid var(--grey2);
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 12.5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .toast.hidden {
            display: none;
        }

        .toast-close {
            background: none;
            border: none;
            color: var(--grey4);
            cursor: pointer;
            font-size: 16px;
        }

        .toast-close:hover {
            color: var(--black);
        }

        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 11.5px;
            color: var(--grey5);
            text-decoration: none;
            border-radius: 10px;
            border: 1px solid transparent;
            transition: all .15s;
            font-weight: 400;
            background: none;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
        }

        .tab:hover {
            background: var(--white);
            border-color: var(--grey2);
            color: var(--black);
        }

        .tab.active {
            background: var(--white);
            border-color: var(--black);
            color: var(--black);
            font-weight: 500;
        }

        .tab .count {
            font-size: 10px;
            font-weight: 600;
            background: var(--grey2);
            padding: 1px 7px;
            border-radius: 20px;
            color: var(--grey5);
        }

        .tab.active .count {
            background: var(--black);
            color: #fff;
        }

        .tab .count.hot {
            background: var(--terracotta);
            color: #fff;
            font-weight: 700;
        }

        .tab.active .count.hot {
            background: var(--terracotta);
        }

        .filters {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filters input[type="text"],
        .filters select {
            padding: 8px 14px;
            border: 1px solid var(--grey2);
            border-radius: 9px;
            font-size: 12px;
            font-family: 'DM Sans', sans-serif;
            color: var(--black);
            background: var(--white);
            outline: none;
            transition: border-color .15s;
        }

        .filters input:focus,
        .filters select:focus {
            border-color: var(--black);
        }

        .filters input {
            width: 240px;
        }

        .filters select {
            min-width: 160px;
            cursor: pointer;
        }

        .clear-link {
            font-size: 11px;
            color: var(--grey4);
            text-decoration: none;
            cursor: pointer;
            background: none;
            border: none;
            font-family: 'DM Sans', sans-serif;
        }

        .clear-link:hover {
            color: var(--terracotta);
        }

        .results-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            font-size: 11px;
            color: var(--grey4);
        }

        .card {
            background: var(--white);
            border: 1px solid var(--grey2);
            border-radius: 14px;
            overflow: hidden;
        }

        .card table {
            border-radius: 0 0 14px 14px;
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            font-size: 9px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--grey4);
            font-weight: 500;
            padding: 11px 16px;
            text-align: left;
            border-bottom: 1px solid var(--grey2);
            background: var(--grey1);
            white-space: nowrap;
        }

        td {
            font-size: 12.5px;
            color: var(--grey5);
            padding: 12px 16px;
            border-bottom: 1px solid var(--grey2);
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: var(--grey1);
        }

        .td-buyer {
            color: var(--black);
            font-weight: 500;
        }

        .td-buyer-sub {
            font-size: 11px;
            color: var(--grey4);
        }

        .td-artwork {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .td-artwork-img {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
            background: var(--grey2);
            border: 1px solid var(--grey2);
            flex-shrink: 0;
        }

        .td-artwork-img-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: var(--grey2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            color: var(--grey4);
            flex-shrink: 0;
        }

        .td-artwork-title {
            color: var(--black);
            font-weight: 500;
            font-size: 12px;
            max-width: 130px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .td-artwork-title a {
            color: var(--black);
            text-decoration: none;
        }

        .td-artwork-title a:hover {
            text-decoration: underline;
        }

        .td-artwork-sub {
            font-size: 10px;
            color: var(--grey4);
        }

        .td-price {
            font-weight: 600;
            color: var(--black);
            white-space: nowrap;
            font-size: 12px;
        }

        .td-date {
            font-size: 11px;
            color: var(--grey4);
            white-space: nowrap;
        }

        .td-notes {
            max-width: 120px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 11px;
            color: var(--grey4);
            font-style: italic;
        }

        .pill {
            display: inline-block;
            font-size: 9px;
            letter-spacing: .5px;
            text-transform: uppercase;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 20px;
            white-space: nowrap;
        }

        .pill.new {
            background: #FFF0EC;
            color: var(--terracotta);
            font-weight: 700;
        }

        .pill.contacted {
            background: #E8F4FF;
            color: #1565c0;
        }

        .pill.confirmed {
            background: #E8F5EE;
            color: #6BA58D;
        }

        .pill.completed {
            background: #EEE8FF;
            color: #6B5CE6;
        }

        .pill.cancelled {
            background: #F4F4F4;
            color: #888;
        }

        .td-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: center;
        }

        .status-select {
            padding: 5px 8px;
            font-size: 10px;
            border: 1px solid var(--grey2);
            border-radius: 7px;
            background: var(--white);
            color: var(--black);
            font-family: 'DM Sans', sans-serif;
            cursor: pointer;
            outline: none;
        }

        .status-select:focus {
            border-color: var(--black);
        }

        .act-btn {
            padding: 5px 10px;
            font-size: 10.5px;
            font-weight: 500;
            border-radius: 7px;
            border: 1px solid var(--grey2);
            background: var(--white);
            color: var(--grey5);
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: all .12s;
            white-space: nowrap;
        }

        .act-btn:hover {
            border-color: var(--black);
            color: var(--black);
        }

        .act-btn.red:hover {
            border-color: var(--terracotta);
            color: var(--terracotta);
            background: #FFF0EC;
        }

        .act-btn.blue:hover {
            border-color: var(--blue);
            color: var(--blue);
            background: #EEF2F8;
        }

        .empty {
            text-align: center;
            padding: 48px 24px;
            color: var(--grey4);
            font-size: 13px;
        }

        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin-top: 20px;
        }

        .page-btn {
            padding: 7px 13px;
            font-size: 11.5px;
            border: 1px solid var(--grey2);
            border-radius: 8px;
            background: var(--white);
            color: var(--grey5);
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            text-decoration: none;
            transition: all .12s;
        }

        .page-btn:hover {
            border-color: var(--black);
            color: var(--black);
        }

        .page-btn.active {
            background: var(--black);
            color: #fff;
            border-color: var(--black);
        }

        .page-btn.disabled {
            opacity: .35;
            pointer-events: none;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .4);
            z-index: 200;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s;
        }

        .modal-overlay.open {
            opacity: 1;
            pointer-events: auto;
        }

        .modal {
            background: var(--white);
            border-radius: 16px;
            width: 520px;
            max-width: 92vw;
            box-shadow: 0 24px 60px rgba(0, 0, 0, .15);
            transform: translateY(12px);
            transition: transform .2s;
        }

        .modal-overlay.open .modal {
            transform: translateY(0);
        }

        .modal-head {
            padding: 24px 28px 0;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
        }

        .modal-head h3 {
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            font-weight: 400;
            color: var(--black);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 18px;
            color: var(--grey4);
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }

        .modal-close:hover {
            color: var(--black);
        }

        .modal-body {
            padding: 20px 28px;
        }

        .modal-foot {
            padding: 0 28px 24px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: all .15s;
            text-decoration: none;
        }

        .btn-ghost {
            background: transparent;
            color: var(--grey5);
            border: 1px solid var(--grey2);
        }

        .btn-ghost:hover {
            border-color: var(--black);
            color: var(--black);
        }

        .btn-primary {
            background: var(--black);
            color: #fff;
        }

        .btn-primary:hover {
            background: #333;
        }

        .btn-danger {
            background: var(--terracotta);
            color: #fff;
        }

        .btn-danger:hover {
            background: #B85C3D;
        }

        /* ── Detail modal fields ─────────────────────────────── */
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .detail-item {}

        .detail-item .dl {
            font-size: 9px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--grey4);
            font-weight: 500;
            margin-bottom: 4px;
        }

        .detail-item .dv {
            font-size: 13px;
            color: var(--black);
            font-weight: 500;
        }

        .detail-item .dv a {
            color: var(--blue);
            text-decoration: none;
        }

        .detail-item .dv a:hover {
            text-decoration: underline;
        }

        .detail-item .dv.muted {
            color: var(--grey4);
            font-weight: 400;
        }

        .detail-full {
            grid-column: 1 / -1;
        }

        .detail-full .msg-text {
            font-size: 13px;
            color: var(--grey5);
            line-height: 1.6;
            background: var(--grey1);
            padding: 14px;
            border-radius: 10px;
            margin-top: 4px;
        }

        .notes-area {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--grey2);
            border-radius: 9px;
            font-size: 12.5px;
            font-family: 'DM Sans', sans-serif;
            color: var(--black);
            outline: none;
            resize: vertical;
            min-height: 60px;
            transition: border-color .15s;
        }

        .notes-area:focus {
            border-color: var(--black);
        }

        .dash-footer {
            padding: 20px 32px;
            border-top: 1px solid var(--grey2);
            font-size: 11px;
            color: var(--grey4);
            margin-top: 12px;
        }

        @media (max-width: 900px) {
            :root {
                --sidebar: 0px;
            }

            .sidebar {
                display: none;
            }

            .topbar {
                left: 0;
            }

            .content {
                padding: 16px;
            }

            td,
            th {
                padding: 8px 10px;
            }

            .td-actions {
                flex-direction: column;
            }

            .hide-mobile {
                display: none !important;
            }

            .filters input {
                width: 100%;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 600px) {
            .tabs {
                gap: 2px;
            }

            .tab {
                padding: 6px 10px;
                font-size: 10.5px;
            }

            .filters {
                flex-direction: column;
                align-items: stretch;
            }
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
        <a href="index.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="1.8">
                <rect x="3" y="3" width="7" height="7" rx="1" />
                <rect x="14" y="3" width="7" height="7" rx="1" />
                <rect x="3" y="14" width="7" height="7" rx="1" />
                <rect x="14" y="14" width="7" height="7" rx="1" />
            </svg> Overview</a>
        <div class="sidebar-section">Content</div>
        <a href="artworks.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="1.8">
                <rect x="3" y="3" width="18" height="18" rx="2" />
                <path d="M3 9l4-4 4 4 4-4 4 4" />
                <circle cx="8.5" cy="14.5" r="1.5" />
            </svg> Artworks</a>
        <a href="artists.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="1.8">
                <circle cx="12" cy="8" r="4" />
                <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" />
            </svg> Artists</a>
        <a href="categories.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="1.8">
                <path d="M4 6h16M4 12h10M4 18h7" />
            </svg> Categories</a>
        <div class="sidebar-section">Requests</div>
        <a href="inquiries.php" class="nav-item active"><svg class="icon" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="1.8">
                <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
            </svg> Buyer Inquiries<?php if ($statusCounts['new'] > 0): ?><span
                    class="badge"><?= $statusCounts['new'] ?></span><?php endif; ?></a>
        <a href="commissions.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="1.8">
                <path d="M12 20h9" />
                <path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z" />
            </svg> Commissions</a>
        <a href="messages.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="1.8">
                <path d="M4 4h16v13H4z" />
                <path d="M4 4l8 9 8-9" />
            </svg> Messages</a>
        <div class="sidebar-bottom">
            <a href="../../logout.php" class="signout-btn"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.8">
                    <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4" />
                    <polyline points="16 17 21 12 16 7" />
                    <line x1="21" y1="12" x2="9" y2="12" />
                </svg> Sign out</a>
        </div>
    </aside>

    <!-- ══════════════ TOPBAR ══════════════ -->
    <header class="topbar">
        <div class="topbar-left">
            <h1>Buyer Inquiries</h1>
            <div class="sub">Manage purchase requests from buyers</div>
        </div>
        <div class="topbar-right">
            <div class="admin-chip">
                <div class="avatar"><?= strtoupper(substr($adminName, 0, 1)) ?></div>
                <span class="name"><?= htmlspecialchars($adminName) ?></span>
                <span class="arrow">∨</span>
            </div>
        </div>
    </header>

    <!-- ══════════════ MAIN ══════════════ -->
    <main class="main">
        <div class="content">

            <?php if ($toast): ?>
                <div class="toast"><span><?= htmlspecialchars($toast) ?></span><button class="toast-close"
                        onclick="this.parentElement.classList.add('hidden')">&times;</button></div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <a href="?<?= buildQS(['status' => null]) ?>" class="tab <?= !$statusFilter ? 'active' : '' ?>">All
                    <span class="count"><?= $statusCounts['all'] ?></span></a>
                <?php foreach (['new', 'contacted', 'confirmed', 'completed', 'cancelled'] as $s): ?>
                    <a href="?<?= buildQS(['status' => $s]) ?>"
                        class="tab <?= $statusFilter === $s ? 'active' : '' ?>"><?= ucfirst($s) ?> <span
                            class="count <?= ($s === 'new' && $statusCounts[$s] > 0) ? 'hot' : '' ?>"><?= $statusCounts[$s] ?></span></a>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <div class="filters">
                <input type="text" placeholder="Search buyer, email, phone, artwork, artist..."
                    value="<?= htmlspecialchars($search) ?>" id="searchInput">
                <select id="artworkSelect">
                    <option value="">All Artworks</option>
                    <?php foreach ($artworkOptions as $ao): ?>
                        <option value="<?= $ao['id'] ?>" <?= $artworkFilter === $ao['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ao['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="sortSelect">
                    <option value="newest" <?= ($_GET['sort'] ?? '') === 'newest' || !isset($_GET['sort']) ? 'selected' : '' ?>>Newest first</option>
                    <option value="oldest" <?= ($_GET['sort'] ?? '') === 'oldest' ? 'selected' : '' ?>>Oldest first
                    </option>
                    <option value="name" <?= ($_GET['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Buyer name A–Z</option>
                </select>
                <?php if ($statusFilter || $search || $artworkFilter): ?>
                    <button class="clear-link" onclick="window.location.href='inquiries.php'">Clear all</button>
                <?php endif; ?>
            </div>

            <!-- Results info -->
            <div class="results-info">
                <div>Showing <?= count($inquiries) ?> of <?= $totalResults ?> inquiries</div>
                <div>Page <?= $page ?> of <?= $totalPages ?></div>
            </div>

            <!-- Table -->
            <div class="card">
                <?php if (empty($inquiries)): ?>
                    <div class="empty">No inquiries found matching your filters.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Buyer</th>
                                <th>Artwork</th>
                                <th class="hide-mobile">Price</th>
                                <th class="hide-mobile">Notes</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inquiries as $inq): 
                                $imageUrl = getArtworkImageUrl($inq['artwork_image'] ?? '');
                            ?>
                                <tr>
                                    <td>
                                        <div class="td-buyer"><?= htmlspecialchars($inq['buyer_name']) ?></div>
                                        <div class="td-buyer-sub">
                                            <?php
                                            if ($inq['buyer_email']) {
                                                echo htmlspecialchars($inq['buyer_email']);
                                            }

                                            if ($inq['buyer_email'] && $inq['buyer_phone']) {
                                                echo ' · ';
                                            }

                                            if ($inq['buyer_phone']) {
                                                echo htmlspecialchars($inq['buyer_phone']);
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="td-artwork">
                                            <?php if ($imageUrl): ?>
                                                <img class="td-artwork-img"
                                                    src="<?= htmlspecialchars($imageUrl) ?>"
                                                    alt="">
                                            <?php else: ?>
                                                <div class="td-artwork-img-placeholder">No img</div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="td-artwork-title"><a
                                                        href="artwork-edit.php?id=<?= $inq['artwork_id'] ?>"
                                                        title="<?= htmlspecialchars($inq['artwork_title']) ?>"><?= htmlspecialchars($inq['artwork_title']) ?></a>
                                                </div>
                                                <div class="td-artwork-sub">by <a
                                                        href="artist-view.php?id=<?= $inq['artist_id'] ?>"
                                                        style="color:var(--grey4);text-decoration:none;"><?= htmlspecialchars($inq['artist_name']) ?></a>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="td-price hide-mobile">PKR <?= number_format($inq['artwork_price']) ?></td>
                                    <td class="td-notes hide-mobile" title="<?= htmlspecialchars($inq['admin_notes'] ?? '') ?>">
                                        <?= $inq['admin_notes'] ? htmlspecialchars($inq['admin_notes']) : '—' ?></td>
                                    <td class="td-date"><?= date('d M Y', strtotime($inq['created_at'])) ?></td>
                                    <td><span class="pill <?= $inq['status'] ?>"><?= ucfirst($inq['status']) ?></span></td>
                                    <td>
                                        <div class="td-actions">
                                            <form method="POST" style="display:inline"
                                                onsubmit="return confirm('Change status?')">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?= $inq['id'] ?>">
                                                <select name="new_status" class="status-select" onchange="this.form.submit()">
                                                    <?php foreach (['new', 'contacted', 'confirmed', 'completed', 'cancelled'] as $s): ?>
                                                        <option value="<?= $s ?>" <?= $inq['status'] === $s ? 'selected' : '' ?>>
                                                            <?= ucfirst($s) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                            <button type="button" class="act-btn blue"
                                                onclick="openDetail(<?= $inq['id'] ?>)">View</button>
                                            <form method="POST" style="display:inline"
                                                onsubmit="return confirm('Delete this inquiry?')"><input type="hidden"
                                                    name="action" value="delete"><input type="hidden" name="id"
                                                    value="<?= $inq['id'] ?>"><button type="submit"
                                                    class="act-btn red">Delete</button></form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= buildQS(['page' => $page - 1]) ?>" class="page-btn">← Prev</a>
                    <?php else: ?>
                        <span class="page-btn disabled">← Prev</span>
                    <?php endif; ?>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    if ($start > 1) {
                        echo '<a href="?' . buildQS(['page' => 1]) . '" class="page-btn">1</a>';
                        if ($start > 2)
                            echo '<span class="page-btn disabled">...</span>';
                    }
                    for ($i = $start; $i <= $end; $i++)
                        echo '<a href="?' . buildQS(['page' => $i]) . '" class="page-btn ' . ($i === $page ? 'active' : '') . '">' . $i . '</a>';
                    if ($end < $totalPages) {
                        if ($end < $totalPages - 1)
                            echo '<span class="page-btn disabled">...</span>';
                        echo '<a href="?' . buildQS(['page' => $totalPages]) . '" class="page-btn">' . $totalPages . '</a>';
                    }
                    ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= buildQS(['page' => $page + 1]) ?>" class="page-btn">Next →</a>
                    <?php else: ?>
                        <span class="page-btn disabled">Next →</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
        <div class="dash-footer">Art Bazaar Admin Panel &mdash; <?= date('Y') ?></div>
    </main>

    <!-- ══════════════ DETAIL MODAL ══════════════ -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal">
            <div class="modal-head">
                <h3>Inquiry Details</h3>
                <button class="modal-close" onclick="closeDetail()">&times;</button>
            </div>
            <div class="modal-body" id="detailContent">
                <!-- Filled by JS -->
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="closeDetail()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Store inquiry data for the detail modal
        const inquiryData = <?= json_encode($inquiries, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function openDetail(id) {
            const inq = inquiryData.find(i => i.id == id);
            if (!inq) return;

            const content = document.getElementById('detailContent');
            content.innerHTML = `
        <div class="detail-grid">
            <div class="detail-item"><div class="dl">Buyer Name</div><div class="dv">${esc(inq.buyer_name)}</div></div>
            <div class="detail-item"><div class="dl">Date</div><div class="dv">${esc(inq.created_at)}</div></div>
            <div class="detail-item"><div class="dl">Email</div><div class="dv ${!inq.buyer_email ? 'muted' : ''}">${inq.buyer_email ? esc(inq.buyer_email) : 'Not provided'}</div></div>
            <div class="detail-item"><div class="dl">Phone / WhatsApp</div><div class="dv ${!inq.buyer_phone ? 'muted' : ''}">${inq.buyer_phone ? esc(inq.buyer_phone) : 'Not provided'}</div></div>
            <div class="detail-item"><div class="dl">Artwork</div><div class="dv"><a href="artwork-edit.php?id=${inq.artwork_id}">${esc(inq.artwork_title)}</a></div></div>
            <div class="detail-item"><div class="dl">Price</div><div class="dv">PKR ${Number(inq.artwork_price).toLocaleString()}</div></div>
            <div class="detail-item"><div class="dl">Artist</div><div class="dv"><a href="artist-view.php?id=${inq.artist_id}">${esc(inq.artist_name)}</a></div></div>
            <div class="detail-item"><div class="dl">Category</div><div class="dv">${esc(inq.category_name)}</div></div>
            <div class="detail-item"><div class="dl">Status</div><div class="dv"><span class="pill ${inq.status}">${ucf(inq.status)}</span></div></div>
        </div>
        <div class="detail-full" style="margin-top:18px;">
            <div class="dl">Buyer Message</div>
            <div class="msg-text">${inq.message ? esc(inq.message) : '<span style="color:var(--grey4)">No message.</span>'}</div>
        </div>
        <div class="detail-full" style="margin-top:18px;">
            <form method="POST">
                <input type="hidden" name="action" value="save_notes">
                <input type="hidden" name="id" value="${inq.id}">
                <div class="dl" style="margin-bottom:6px;">Admin Notes</div>
                <textarea class="notes-area" name="admin_notes" placeholder="Add internal notes about this inquiry...">${esc(inq.admin_notes || '')}</textarea>
                <div style="margin-top:10px;text-align:right;">
                    <button type="submit" class="btn btn-primary btn-sm">Save Notes</button>
                </div>
            </form>
        </div>
    `;
            document.getElementById('detailModal').classList.add('open');
        }

        function closeDetail() { document.getElementById('detailModal').classList.remove('open'); }
        document.getElementById('detailModal').addEventListener('click', function (e) { if (e.target === this) closeDetail(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDetail(); });

        function esc(str) { if (!str) return ''; const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
        function ucf(str) { return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''; }

        // Filters
        let searchTimer;
        document.getElementById('searchInput').addEventListener('keyup', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilters, 400);
        });
        document.getElementById('artworkSelect').addEventListener('change', applyFilters);
        document.getElementById('sortSelect').addEventListener('change', applyFilters);

        function applyFilters() {
            let params = new URLSearchParams(window.location.search);
            let q = document.getElementById('searchInput').value.trim();
            let art = document.getElementById('artworkSelect').value;
            let sort = document.getElementById('sortSelect').value;
            if (q) params.set('q', q); else params.delete('q');
            if (art) params.set('artwork', art); else params.delete('artwork');
            if (sort) params.set('sort', sort); else params.delete('sort');
            params.delete('page');
            window.location.href = 'inquiries.php?' + params.toString();
        }
    </script>
</body>

</html>