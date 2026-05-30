<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

// ── NEW: JSON endpoint for fetching messages ─────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_messages') {
    header('Content-Type: application/json');
    $commissionId = (int)($_GET['commission_id'] ?? 0);
    
    if ($commissionId > 0) {
        $stmt = $conn->prepare("SELECT * FROM commission_messages WHERE commission_id = ? ORDER BY created_at ASC");
        $stmt->bind_param('i', $commissionId);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['messages' => $messages]);
    } else {
        echo json_encode(['messages' => []]);
    }
    exit;
}

$adminName = $_SESSION['name'] ?? 'Admin';
$toast = '';

// ── Contact info filter function ─────────────────────────
function containsContactInfo(string $text): bool {
    $patterns = [
        '/\b[\w.+-]+@[\w-]+\.[a-z]{2,}\b/i',           // email
        '/(\+92|0)?[-\s]?[0-9]{3}[-\s]?[0-9]{7,8}/',   // Pakistani phone
        '/\b(instagram|insta|ig|whatsapp|wa|facebook|fb|twitter|tiktok|snapchat)\s*[:\-@]?\s*\w+/i',
        '/@[a-zA-Z0-9._]{2,30}/',                        // @username
        '/\b(iban|account\s*no|bank|easypaisa|jazzcash|sadapay|nayapay)\b/i',
        '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/', // card/IBAN numbers
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $text)) return true;
    }
    return false;
}

// ── Handle actions ──────────────────────────────────────

// Update status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $id = (int)($_POST['id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    if ($id && in_array($newStatus, ['new','contacted','assigned','in_progress','completed','cancelled'])) {
        $conn->query("UPDATE commission_requests SET status = '$newStatus' WHERE id = $id");
        $toast = 'Commission status updated to ' . str_replace('_', ' ', ucfirst($newStatus)) . '.';
    }
}

// Assign artist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_artist') {
    $id = (int)($_POST['id'] ?? 0);
    $artistId = (int)($_POST['artist_id'] ?? 0);
    if ($id && $artistId) {
        $conn->query("UPDATE commission_requests SET artist_id = $artistId, status = 'assigned' WHERE id = $id");
        $toast = 'Artist assigned.';
    } elseif ($id && !$artistId) {
        $conn->query("UPDATE commission_requests SET artist_id = NULL WHERE id = $id");
        $toast = 'Artist unassigned.';
    }
}

// Save admin notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notes') {
    $id = (int)($_POST['id'] ?? 0);
    $notes = trim($_POST['admin_notes'] ?? '');
    if ($id) {
        $stmt = $conn->prepare("UPDATE commission_requests SET admin_notes = ? WHERE id = ?");
        $stmt->bind_param('si', $notes, $id);
        $stmt->execute();
        $toast = 'Notes saved.';
    }
}

// Send chat message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $commissionId = (int)($_POST['commission_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    if ($commissionId && $message) {
        if (containsContactInfo($message)) {
            $toast = 'Message blocked: Contact information (phone, email, social handles, bank details) cannot be shared.';
        } else {
            $stmt = $conn->prepare("INSERT INTO commission_messages (commission_id, sender_role, sender_name, message) VALUES (?, 'admin', ?, ?)");
            $stmt->bind_param('iss', $commissionId, $adminName, $message);
            $stmt->execute();
            $toast = 'Message sent.';
        }
    }
}

// Delete chat message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_message') {
    $messageId = (int)($_POST['message_id'] ?? 0);
    if ($messageId) {
        $conn->query("DELETE FROM commission_messages WHERE id = $messageId");
        $toast = 'Message deleted.';
    }
}

// Delete commission request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $row = $conn->query("SELECT reference_image FROM commission_requests WHERE id = $id")->fetch_assoc();
        if ($row && $row['reference_image']) {
            $f = __DIR__ . '/../../uploads/commissions/' . $row['reference_image'];
            if (file_exists($f)) unlink($f);
        }
        $conn->query("DELETE FROM commission_messages WHERE commission_id = $id");
        $conn->query("DELETE FROM commission_requests WHERE id = $id");
        $toast = 'Commission request deleted.';
    }
}

// ── Build query ─────────────────────────────────────────
$where   = ["1=1"];
$params  = [];
$types   = '';

$statusFilter = $_GET['status'] ?? '';
if (in_array($statusFilter, ['new','contacted','assigned','in_progress','completed','cancelled'])) {
    $where[] = "cr.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$search = trim($_GET['q'] ?? '');
if ($search) {
    $where[] = "(cr.buyer_name LIKE ? OR cr.buyer_email LIKE ? OR cr.buyer_phone LIKE ? OR cr.description LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}

$artistFilter = (int)($_GET['artist'] ?? 0);
if ($artistFilter > 0) {
    $where[] = "cr.artist_id = ?";
    $params[] = $artistFilter;
    $types .= 'i';
}

$whereSQL = implode(' AND ', $where);

$sortMap = [
    'newest'  => 'cr.created_at DESC',
    'oldest'  => 'cr.created_at ASC',
    'name'    => 'cr.buyer_name ASC',
    'deadline'=> 'cr.deadline ASC',
];
$sortBy = $sortMap[$_GET['sort'] ?? ''] ?? 'cr.created_at DESC';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// Count
$countSQL = "SELECT COUNT(*) FROM commission_requests cr WHERE $whereSQL";
if ($params) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalResults = (int)$stmt->get_result()->fetch_row()[0];
} else {
    $totalResults = (int)$conn->query($countSQL)->fetch_row()[0];
}
$totalPages = max(1, ceil($totalResults / $perPage));

// Fetch - FIXED: Removed categories join, using artwork_type directly
$dataSQL = "
    SELECT cr.*,
           u.name AS artist_name, u.id AS artist_id
    FROM commission_requests cr
    LEFT JOIN users u ON u.id = cr.artist_id
    WHERE $whereSQL
    ORDER BY $sortBy
    LIMIT $perPage OFFSET $offset
";
$commissions = [];
if ($params) {
    $stmt = $conn->prepare($dataSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($dataSQL);
}
while ($row = $res->fetch_assoc()) $commissions[] = $row;

// Status counts
$statusCounts = [];
foreach (['new','contacted','assigned','in_progress','completed','cancelled'] as $s) {
    $r = $conn->query("SELECT COUNT(*) FROM commission_requests WHERE status='$s'");
    $statusCounts[$s] = (int)$r->fetch_row()[0];
}
$statusCounts['all'] = array_sum($statusCounts);

// Artists for filter/assign dropdown
$artistOptions = [];
$aoRes = $conn->query("SELECT id, name FROM users WHERE role='artist' AND status='active' ORDER BY name ASC");
while ($row = $aoRes->fetch_assoc()) $artistOptions[] = $row;

function buildQS($overrides = []) {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    unset($q['page']);
    return http_build_query($q);
}

function formatBudget($min, $max) {
    if ($min && $max) return 'PKR ' . number_format($min) . ' – ' . number_format($max);
    if ($min) return 'PKR ' . number_format($min) . '+';
    if ($max) return 'Up to PKR ' . number_format($max);
    return '—';
}

function getArtworkTypeLabel($type) {
    $labels = [
        'painting' => 'Painting',
        'portrait' => 'Portrait',
        'digital_art' => 'Digital Art',
        'calligraphy' => 'Calligraphy',
        'abstract' => 'Abstract',
        'landscape' => 'Landscape',
        'other' => 'Other'
    ];
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Commissions — Art Bazaar Admin</title>
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
    --blue: #0984e3;
    --purple: #5e35b1;
    --terracotta: #C96B4B;
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--grey1); color: var(--black); font-family: 'DM Sans', sans-serif; }

.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: #EFE3D2; border-right: 1px solid var(--grey2); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--grey2); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--grey4); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--black); margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--terracotta); color: var(--white); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--grey5); text-decoration: none; font-weight: 400; border-left: 2px solid transparent; transition: all .15s; }
.nav-item:hover { color: var(--black); background: rgba(255,255,255,0.3); border-left-color: var(--grey3); }
.nav-item.active { color: var(--black); background: rgba(255,255,255,0.4); border-left-color: var(--terracotta); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .55; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.badge { margin-left: auto; background: var(--terracotta); color: #fff; font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--grey2); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--grey5); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: #FFF0EC; color: var(--terracotta); }

.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--white); border-bottom: 1px solid var(--grey2); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--black); }
.topbar-left .sub { font-size: 11px; color: var(--grey4); margin-top: 1px; }
.admin-chip { display: flex; align-items: center; gap: 8px; background: var(--grey1); border: 1px solid var(--grey2); padding: 5px 12px 5px 5px; border-radius: 30px; }
.admin-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--terracotta); display: flex; align-items: center; justify-content: center; font-size: 11px; color: #fff; font-weight: 600; }
.admin-chip .name { font-size: 12px; color: var(--black); font-weight: 500; }
.admin-chip .arrow { font-size: 12px; color: var(--grey4); margin-left: 4px; }

.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 28px 32px; }

.toast { background: #FCEEE2; color: var(--black); border: 1px solid var(--grey2); padding: 12px 20px; border-radius: 10px; font-size: 12.5px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }
.toast.hidden { display: none; }
.toast-close { background: none; border: none; color: var(--grey4); cursor: pointer; font-size: 16px; }
.toast-close:hover { color: var(--black); }

.tabs { display: flex; gap: 4px; margin-bottom: 20px; flex-wrap: wrap; }
.tab { display: flex; align-items: center; gap: 6px; padding: 8px 16px; font-size: 11.5px; color: var(--grey5); text-decoration: none; border-radius: 10px; border: 1px solid transparent; transition: all .15s; font-weight: 400; background: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.tab:hover { background: var(--white); border-color: var(--grey2); color: var(--black); }
.tab.active { background: var(--white); border-color: var(--black); color: var(--black); font-weight: 500; }
.tab .count { font-size: 10px; font-weight: 600; background: var(--grey2); padding: 1px 7px; border-radius: 20px; color: var(--grey5); }
.tab.active .count { background: var(--black); color: #fff; }
.tab .count.hot { background: var(--terracotta); color: #fff; font-weight: 700; }
.tab.active .count.hot { background: var(--terracotta); }
.tab-label { white-space: nowrap; }

.filters { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
.filters input[type="text"], .filters select { padding: 8px 14px; border: 1px solid var(--grey2); border-radius: 9px; font-size: 12px; font-family: 'DM Sans', sans-serif; color: var(--black); background: var(--white); outline: none; transition: border-color .15s; }
.filters input:focus, .filters select:focus { border-color: var(--black); }
.filters input { width: 220px; }
.filters select { min-width: 140px; cursor: pointer; }
.clear-link { font-size: 11px; color: var(--grey4); text-decoration: none; cursor: pointer; background: none; border: none; font-family: 'DM Sans', sans-serif; }
.clear-link:hover { color: var(--terracotta); }

.results-info { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; font-size: 11px; color: var(--grey4); }

.card { background: var(--white); border: 1px solid var(--grey2); border-radius: 14px; overflow: hidden; }
.card table { border-radius: 0 0 14px 14px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; padding: 11px 14px; text-align: left; border-bottom: 1px solid var(--grey2); background: var(--grey1); white-space: nowrap; }
td { font-size: 12.5px; color: var(--grey5); padding: 12px 14px; border-bottom: 1px solid var(--grey2); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--grey1); }

.td-buyer { color: var(--black); font-weight: 500; }
.td-buyer-sub { font-size: 11px; color: var(--grey4); }
.td-type { font-size: 11px; color: var(--grey5); }
.td-budget { font-weight: 600; color: var(--black); white-space: nowrap; font-size: 12px; }
.td-deadline { font-size: 11px; color: var(--grey4); white-space: nowrap; }
.td-deadline.overdue { color: var(--terracotta); font-weight: 600; }
.td-artist { font-size: 12px; }
.td-artist a { color: var(--black); text-decoration: none; font-weight: 500; }
.td-artist a:hover { text-decoration: underline; }
.td-artist .unassigned { color: var(--grey4); font-style: italic; font-weight: 400; }
.td-date { font-size: 11px; color: var(--grey4); white-space: nowrap; }
.td-notes { max-width: 110px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 11px; color: var(--grey4); font-style: italic; }

.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.pill.new { background: #FFF0EC; color: var(--terracotta); font-weight: 700; }
.pill.contacted { background: #E8F4FF; color: #1565c0; }
.pill.assigned { background: #FFF4E6; color: #E48A4A; }
.pill.in_progress { background: #EEF2F8; color: #3B7DD8; }
.pill.completed { background: #EEE8FF; color: #6B5CE6; }
.pill.cancelled { background: #F4F4F4; color: #888; }
.ref-icon { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 5px; background: var(--grey1); border: 1px solid var(--grey2); cursor: pointer; transition: all .12s; flex-shrink: 0; }
.ref-icon:hover { border-color: var(--blue); background: #EEF2F8; }
.ref-icon svg { width: 12px; height: 12px; color: var(--grey4); }

.td-actions { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
.status-select { padding: 5px 8px; font-size: 10px; border: 1px solid var(--grey2); border-radius: 7px; background: var(--white); color: var(--black); font-family: 'DM Sans', sans-serif; cursor: pointer; outline: none; }
.status-select:focus { border-color: var(--black); }
.act-btn { padding: 5px 10px; font-size: 10.5px; font-weight: 500; border-radius: 7px; border: 1px solid var(--grey2); background: var(--white); color: var(--grey5); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .12s; white-space: nowrap; }
.act-btn:hover { border-color: var(--black); color: var(--black); }
.act-btn.red:hover { border-color: var(--terracotta); color: var(--terracotta); background: #FFF0EC; }
.act-btn.blue:hover { border-color: var(--blue); color: var(--blue); background: #EEF2F8; }

.empty { text-align: center; padding: 48px 24px; color: var(--grey4); font-size: 13px; }

.pagination { display: flex; align-items: center; justify-content: center; gap: 4px; margin-top: 20px; }
.page-btn { padding: 7px 13px; font-size: 11.5px; border: 1px solid var(--grey2); border-radius: 8px; background: var(--white); color: var(--grey5); cursor: pointer; font-family: 'DM Sans', sans-serif; text-decoration: none; transition: all .12s; }
.page-btn:hover { border-color: var(--black); color: var(--black); }
.page-btn.active { background: var(--black); color: #fff; border-color: var(--black); }
.page-btn.disabled { opacity: .35; pointer-events: none; }

/* ── Modal Styles (enlarged for chat) ── */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .2s; }
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal { background: var(--white); border-radius: 16px; width: 700px; max-width: 92vw; box-shadow: 0 24px 60px rgba(0,0,0,.15); transform: translateY(12px); transition: transform .2s; max-height: 90vh; overflow-y: auto; }
.modal-overlay.open .modal { transform: translateY(0); }
.modal-head { padding: 24px 28px 0; display: flex; align-items: flex-start; justify-content: space-between; position: sticky; top: 0; background: var(--white); z-index: 1; }
.modal-head h3 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--black); }
.modal-close { background: none; border: none; font-size: 18px; color: var(--grey4); cursor: pointer; padding: 0; line-height: 1; }
.modal-close:hover { color: var(--black); }
.modal-body { padding: 20px 28px 28px; }
.modal-foot { padding: 0 28px 24px; display: flex; gap: 10px; justify-content: flex-end; }

/* ── Chat Styles ── */
.chat-section { margin-top: 24px; padding-top: 20px; border-top: 2px solid var(--grey2); }
.chat-title { font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; margin-bottom: 12px; }
.chat-messages { background: var(--grey1); border-radius: 12px; height: 300px; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px; }
.message { display: flex; flex-direction: column; max-width: 80%; }
.message.admin { align-self: flex-end; }
.message.artist { align-self: flex-start; }
.message.buyer { align-self: flex-start; }
.message-bubble { padding: 10px 14px; border-radius: 18px; font-size: 13px; line-height: 1.5; word-break: break-word; }
.message.admin .message-bubble { background: var(--black); color: white; border-bottom-right-radius: 4px; }
.message.artist .message-bubble { background: #EEF2F8; color: var(--black); border-bottom-left-radius: 4px; }
.message.buyer .message-bubble { background: #E8F5EE; color: var(--black); border-bottom-left-radius: 4px; }
.message-meta { font-size: 10px; color: var(--grey4); margin-top: 4px; padding: 0 6px; display: flex; gap: 8px; align-items: center; }
.message.admin .message-meta { justify-content: flex-end; }
.message .delete-msg { background: none; border: none; color: var(--terracotta); font-size: 10px; cursor: pointer; opacity: 0.6; }
.message .delete-msg:hover { opacity: 1; text-decoration: underline; }
.chat-input-area { margin-top: 16px; display: flex; gap: 10px; }
.chat-input-area input { flex: 1; padding: 12px 16px; border: 1.5px solid var(--grey2); border-radius: 30px; font-size: 13px; font-family: 'DM Sans', sans-serif; outline: none; }
.chat-input-area input:focus { border-color: var(--black); }
.chat-input-area button { background: var(--black); color: white; border: none; border-radius: 30px; padding: 0 20px; font-weight: 500; cursor: pointer; transition: background .2s; }
.chat-input-area button:hover { background: #333; }
.chat-warning { font-size: 10px; color: var(--grey4); margin-top: 8px; text-align: center; }

.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; font-size: 12px; font-weight: 500; border-radius: 10px; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .15s; text-decoration: none; }
.btn-ghost { background: transparent; color: var(--grey5); border: 1px solid var(--grey2); }
.btn-ghost:hover { border-color: var(--black); color: var(--black); }
.btn-primary { background: var(--black); color: #fff; }
.btn-primary:hover { background: #333; }
.btn-danger { background: var(--terracotta); color: #fff; }
.btn-danger:hover { background: #B85C3D; }
.btn-sm { padding: 6px 12px; font-size: 11px; }

.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.detail-item .dl { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; margin-bottom: 4px; }
.detail-item .dv { font-size: 13px; color: var(--black); font-weight: 500; }
.detail-item .dv a { color: var(--blue); text-decoration: none; }
.detail-item .dv a:hover { text-decoration: underline; }
.detail-item .dv.muted { color: var(--grey4); font-weight: 400; }
.detail-full { grid-column: 1 / -1; }
.detail-full .msg-text { font-size: 13px; color: var(--grey5); line-height: 1.6; background: var(--grey1); padding: 14px; border-radius: 10px; margin-top: 4px; white-space: pre-wrap; }
.ref-preview { margin-top: 10px; }
.ref-preview img { max-width: 200px; max-height: 200px; border-radius: 10px; border: 1px solid var(--grey2); object-fit: cover; }
.notes-area { width: 100%; padding: 10px 14px; border: 1.5px solid var(--grey2); border-radius: 9px; font-size: 12.5px; font-family: 'DM Sans', sans-serif; color: var(--black); outline: none; resize: vertical; min-height: 60px; transition: border-color .15s; }
.notes-area:focus { border-color: var(--black); }
.assign-row { display: flex; align-items: center; gap: 10px; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--grey2); }
.assign-row label { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; white-space: nowrap; }
.assign-row select { flex: 1; padding: 8px 14px; border: 1.5px solid var(--grey2); border-radius: 9px; font-size: 12px; font-family: 'DM Sans', sans-serif; color: var(--black); outline: none; background: var(--white); }
.assign-row select:focus { border-color: var(--black); }
.assign-row .btn { flex-shrink: 0; }

.dash-footer { padding: 20px 32px; border-top: 1px solid var(--grey2); font-size: 11px; color: var(--grey4); margin-top: 12px; }

@media (max-width: 900px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; }
    .content { padding: 16px; }
    td, th { padding: 8px 10px; }
    .td-actions { flex-direction: column; }
    .hide-mobile { display: none !important; }
    .filters input { width: 100%; }
    .detail-grid { grid-template-columns: 1fr; }
    .message { max-width: 95%; }
}
@media (max-width: 600px) {
    .tabs { gap: 2px; }
    .tab { padding: 6px 10px; font-size: 10.5px; }
    .tab-label { display: none; }
    .filters { flex-direction: column; align-items: stretch; }
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
    <a href="categories.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h7"/></svg> Categories</a>
    <div class="sidebar-section">Requests</div>
    <a href="inquiries.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Buyer Inquiries</a>
    <a href="commissions.php" class="nav-item active"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Commissions<?php if ($statusCounts['new'] > 0): ?><span class="badge"><?= $statusCounts['new'] ?></span><?php endif; ?></a>
    <a href="messages.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v13H4z"/><path d="M4 4l8 9 8-9"/></svg> Messages</a>
    <div class="sidebar-bottom">
        <a href="../../logout.php" class="signout-btn"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sign out</a>
    </div>
</aside>

<!-- ══════════════ TOPBAR ══════════════ -->
<header class="topbar">
    <div class="topbar-left">
        <h1>Commissions</h1>
        <div class="sub">Manage custom artwork requests</div>
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
    <div class="toast"><span><?= htmlspecialchars($toast) ?></span><button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?<?= buildQS(['status' => null]) ?>" class="tab <?= !$statusFilter ? 'active' : '' ?>"><span class="tab-label">All</span> <span class="count"><?= $statusCounts['all'] ?></span></a>
        <?php foreach (['new','contacted','assigned','in_progress','completed','cancelled'] as $s): ?>
        <a href="?<?= buildQS(['status' => $s]) ?>" class="tab <?= $statusFilter === $s ? 'active' : '' ?>"><span class="tab-label"><?= str_replace('_',' ',ucfirst($s)) ?></span> <span class="count <?= ($s === 'new' && $statusCounts[$s] > 0) ? 'hot' : '' ?>"><?= $statusCounts[$s] ?></span></a>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="filters">
        <input type="text" placeholder="Search buyer, email, description..." value="<?= htmlspecialchars($search) ?>" id="searchInput">
        <select id="artistSelect">
            <option value="">All Artists</option>
            <option value="unassigned" <?= $artistFilter === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
            <?php foreach ($artistOptions as $ao): ?>
            <option value="<?= $ao['id'] ?>" <?= $artistFilter === $ao['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ao['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="sortSelect">
            <option value="newest" <?= ($_GET['sort'] ?? '') === 'newest' || !isset($_GET['sort']) ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest" <?= ($_GET['sort'] ?? '') === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
            <option value="name" <?= ($_GET['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Buyer name A–Z</option>
            <option value="deadline" <?= ($_GET['sort'] ?? '') === 'deadline' ? 'selected' : '' ?>>Deadline soonest</option>
        </select>
        <?php if ($statusFilter || $search || $artistFilter): ?>
            <button class="clear-link" onclick="window.location.href='commissions.php'">Clear all</button>
        <?php endif; ?>
    </div>

    <!-- Results info -->
    <div class="results-info">
        <div>Showing <?= count($commissions) ?> of <?= $totalResults ?> commissions</div>
        <div>Page <?= $page ?> of <?= $totalPages ?></div>
    </div>

    <!-- Table -->
    <div class="card">
        <?php if (empty($commissions)): ?>
            <div class="empty">No commission requests found.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Buyer</th>
                    <th>Type</th>
                    <th>Budget</th>
                    <th class="hide-mobile">Deadline</th>
                    <th>Artist</th>
                    <th class="hide-mobile">Notes</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($commissions as $cr): ?>
                <?php
                $isOverdue = $cr['deadline'] && strtotime($cr['deadline']) < time() && !in_array($cr['status'], ['completed','cancelled']);
                $artworkTypeLabel = getArtworkTypeLabel($cr['artwork_type'] ?? '');
                ?>
                <tr>
                    <td>
                        <div class="td-buyer"><?= htmlspecialchars($cr['buyer_name']) ?></div>
                        <div class="td-buyer-sub">
                            <?php
                                $email = $cr['buyer_email'];
                                $phone = $cr['buyer_phone'];

                                if ($email) echo htmlspecialchars($email);

                                if ($email && $phone) echo ' · ';

                                if ($phone) echo htmlspecialchars($phone);
                             ?>
                            <?php if ($cr['reference_image']): ?>
                                <span class="ref-icon" title="Has reference image" onclick="openDetail(<?= $cr['id'] ?>)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="td-type"><?= htmlspecialchars($artworkTypeLabel) ?></td>
                    <td class="td-budget"><?= formatBudget($cr['budget_min'], $cr['budget_max']) ?></td>
                    <td class="td-deadline <?= $isOverdue ? 'overdue' : '' ?> hide-mobile">
                        <?php if ($cr['deadline']): ?>
                            <?= date('d M Y', strtotime($cr['deadline'])) ?>
                            <?php if ($isOverdue): ?> (overdue)<?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--grey4)">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="td-artist">
                        <?php if ($cr['artist_name']): ?>
                            <a href="artist-view.php?id=<?= $cr['artist_id'] ?>"><?= htmlspecialchars($cr['artist_name']) ?></a>
                        <?php else: ?>
                            <span class="unassigned">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td class="td-notes hide-mobile" title="<?= htmlspecialchars($cr['admin_notes'] ?? '') ?>"><?= $cr['admin_notes'] ? htmlspecialchars($cr['admin_notes']) : '—' ?></td>
                    <td class="td-date"><?= date('d M Y', strtotime($cr['created_at'])) ?></td>
                    <td><span class="pill <?= $cr['status'] ?>"><?= str_replace('_',' ',ucfirst($cr['status'])) ?></span></td>
                    <td>
                        <div class="td-actions">
                            <form method="POST" style="display:inline" onsubmit="return confirm('Change status?')">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= $cr['id'] ?>">
                                <select name="new_status" class="status-select" onchange="this.form.submit()">
                                    <?php foreach (['new','contacted','assigned','in_progress','completed','cancelled'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $cr['status'] === $s ? 'selected' : '' ?>><?= str_replace('_',' ',ucfirst($s)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <button type="button" class="act-btn blue" onclick="openDetail(<?= $cr['id'] ?>)">View & Chat</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this commission request?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $cr['id'] ?>"><button type="submit" class="act-btn red">Delete</button></form>
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
        $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
        if ($start > 1) { echo '<a href="?'.buildQS(['page'=>1]).'" class="page-btn">1</a>'; if ($start > 2) echo '<span class="page-btn disabled">...</span>'; }
        for ($i = $start; $i <= $end; $i++) echo '<a href="?'.buildQS(['page'=>$i]).'" class="page-btn '.($i === $page ? 'active' : '').'">'.$i.'</a>';
        if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<span class="page-btn disabled">...</span>'; echo '<a href="?'.buildQS(['page'=>$totalPages]).'" class="page-btn">'.$totalPages.'</a>'; }
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

<!-- ══════════════ DETAIL MODAL (with chat) ══════════════ -->
<div class="modal-overlay" id="detailModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Commission Details &amp; Chat</h3>
            <button class="modal-close" onclick="closeDetail()">&times;</button>
        </div>
        <div class="modal-body" id="detailContent">
            <!-- Dynamic content loaded by JS -->
        </div>
    </div>
</div>

<script>
const commissionData = <?= json_encode($commissions, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const artistList = <?= json_encode($artistOptions, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let currentCommissionId = null;
let messageRefreshInterval = null;

function getArtworkTypeLabel(type) {
    const labels = {
        'painting': 'Painting',
        'portrait': 'Portrait',
        'digital_art': 'Digital Art',
        'calligraphy': 'Calligraphy',
        'abstract': 'Abstract',
        'landscape': 'Landscape',
        'other': 'Other'
    };
    return labels[type] || (type ? type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'Not specified');
}

function openDetail(id) {
    currentCommissionId = id;
    const cr = commissionData.find(i => i.id == id);
    if (!cr) return;

    let artistOptions = '<option value="">— Select artist —</option>\n';
    artistList.forEach(a => {
        artistOptions += `<option value="${a.id}" ${cr.artist_id == a.id ? 'selected' : ''}>${esc(a.name)}</option>\n`;
    });

    const artworkTypeLabel = getArtworkTypeLabel(cr.artwork_type);
    const budgetText = cr.budget_min || cr.budget_max ? 'PKR ' + Number(cr.budget_min || 0).toLocaleString() + (cr.budget_max ? ' – ' + Number(cr.budget_max).toLocaleString() : '+') : 'Not specified';

    const content = document.getElementById('detailContent');
    content.innerHTML = `
        <div class="detail-grid">
            <div class="detail-item"><div class="dl">Buyer Name</div><div class="dv">${esc(cr.buyer_name)}</div></div>
            <div class="detail-item"><div class="dl">Date</div><div class="dv">${esc(cr.created_at)}</div></div>
            <div class="detail-item"><div class="dl">Email</div><div class="dv ${!cr.buyer_email ? 'muted' : ''}">${cr.buyer_email ? esc(cr.buyer_email) : 'Not provided'}</div></div>
            <div class="detail-item"><div class="dl">Phone / WhatsApp</div><div class="dv ${!cr.buyer_phone ? 'muted' : ''}">${cr.buyer_phone ? esc(cr.buyer_phone) : 'Not provided'}</div></div>
            <div class="detail-item"><div class="dl">Art Type</div><div class="dv">${esc(artworkTypeLabel)}</div></div>
            <div class="detail-item"><div class="dl">Budget</div><div class="dv">${budgetText}</div></div>
            <div class="detail-item"><div class="dl">Deadline</div><div class="dv">${cr.deadline ? esc(cr.deadline) : 'Not set'}</div></div>
            <div class="detail-item"><div class="dl">Status</div><div class="dv"><span class="pill ${cr.status}">${ucf(cr.status).replace('_',' ')}</span></div></div>
            <div class="detail-item"><div class="dl">Assigned Artist</div><div class="dv ${!cr.artist_name ? 'muted' : ''}">${cr.artist_name ? '<a href="artist-view.php?id=' + cr.artist_id + '">' + esc(cr.artist_name) + '</a>' : 'Not assigned yet'}</div></div>
        </div>
        <div class="detail-full" style="margin-top:18px;">
            <div class="dl">Description</div>
            <div class="msg-text">${cr.description ? esc(cr.description) : '<span style="color:var(--grey4)">No description.</span>'}</div>
        </div>
        ${cr.reference_image ? `
        <div class="detail-full" style="margin-top:18px;">
            <div class="dl">Reference Image</div>
            <div class="ref-preview"><img src="../../uploads/commissions/${esc(cr.reference_image)}" alt="Reference"></div>
        </div>` : ''}
        <div class="detail-full" style="margin-top:18px;">
            <form method="POST">
                <input type="hidden" name="action" value="save_notes">
                <input type="hidden" name="id" value="${cr.id}">
                <div class="dl" style="margin-bottom:6px;">Admin Notes</div>
                <textarea class="notes-area" name="admin_notes" placeholder="Track progress, conversations, payment status...">${esc(cr.admin_notes || '')}</textarea>
                <div style="margin-top:10px;text-align:right;">
                    <button type="submit" class="btn btn-primary btn-sm">Save Notes</button>
                </div>
            </form>
        </div>
        <div class="assign-row">
            <label>Assign Artist</label>
            <form method="POST" style="display:flex;gap:10px;flex:1;">
                <input type="hidden" name="action" value="assign_artist">
                <input type="hidden" name="id" value="${cr.id}">
                <select name="artist_id">${artistOptions}</select>
                <button type="submit" class="btn btn-primary btn-sm">Assign</button>
            </form>
        </div>
        
        <!-- ── CHAT SECTION ── -->
        <div class="chat-section">
            <div class="chat-title">💬 Conversation Thread</div>
            <div class="chat-messages" id="chatMessages-${cr.id}">
                <div style="text-align:center;padding:20px;color:var(--grey4);">Loading messages...</div>
            </div>
            <div class="chat-input-area">
                <input type="text" id="chatInput-${cr.id}" placeholder="Type your message... (No phone/email/social handles allowed)" autocomplete="off">
                <button onclick="sendMessage(${cr.id})">Send</button>
            </div>
            <div class="chat-warning">
                ⚠️ Contact information (phone, email, Instagram, bank details) is automatically blocked.
            </div>
        </div>
    `;
    
    document.getElementById('detailModal').classList.add('open');
    loadMessages(cr.id);
    
    // Clear existing interval and start new one for auto-refresh
    if (messageRefreshInterval) clearInterval(messageRefreshInterval);
    messageRefreshInterval = setInterval(() => {
        if (currentCommissionId && document.getElementById('detailModal').classList.contains('open')) {
            loadMessages(currentCommissionId);
        } else if (!document.getElementById('detailModal').classList.contains('open')) {
            clearInterval(messageRefreshInterval);
            messageRefreshInterval = null;
        }
    }, 5000);
}

function loadMessages(commissionId) {
    fetch(`commissions.php?action=get_messages&commission_id=${commissionId}`)
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById(`chatMessages-${commissionId}`);
            if (!container) return;
            
            if (!data.messages || data.messages.length === 0) {
                container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--grey4);">No messages yet. Start the conversation!</div>';
                return;
            }
            
            let html = '';
            data.messages.forEach(msg => {
                const isAdmin = msg.sender_role === 'admin';
                const isArtist = msg.sender_role === 'artist';
                const roleClass = isAdmin ? 'admin' : (isArtist ? 'artist' : 'buyer');
                const senderName = msg.sender_name;
                const time = new Date(msg.created_at).toLocaleString();
                html += `
                    <div class="message ${roleClass}" data-msg-id="${msg.id}">
                        <div class="message-bubble">${esc(msg.message)}</div>
                        <div class="message-meta">
                            <span>${esc(senderName)}</span>
                            <span>•</span>
                            <span>${time}</span>
                            <button class="delete-msg" onclick="deleteMessage(${msg.id}, ${commissionId})">Delete</button>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        })
        .catch(err => {
            console.error('Failed to load messages:', err);
        });
}

function sendMessage(commissionId) {
    const input = document.getElementById(`chatInput-${commissionId}`);
    const message = input.value.trim();
    if (!message) return;
    
    fetch('commissions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=send_message&commission_id=${commissionId}&message=${encodeURIComponent(message)}`
    })
    .then(() => {
        input.value = '';
        loadMessages(commissionId);
    })
    .catch(err => console.error('Failed to send message:', err));
}

function deleteMessage(messageId, commissionId) {
    if (!confirm('Delete this message?')) return;
    
    fetch('commissions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete_message&message_id=${messageId}`
    })
    .then(() => {
        loadMessages(commissionId);
    })
    .catch(err => console.error('Failed to delete message:', err));
}

function closeDetail() { 
    document.getElementById('detailModal').classList.remove('open');
    if (messageRefreshInterval) {
        clearInterval(messageRefreshInterval);
        messageRefreshInterval = null;
    }
    currentCommissionId = null;
}

document.getElementById('detailModal').addEventListener('click', function(e) { if (e.target === this) closeDetail(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeDetail(); });

function esc(str) { if (!str) return ''; const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
function ucf(str) { return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''; }

let searchTimer;
document.getElementById('searchInput').addEventListener('keyup', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 400);
});
document.getElementById('artistSelect').addEventListener('change', applyFilters);
document.getElementById('sortSelect').addEventListener('change', applyFilters);

function applyFilters() {
    let params = new URLSearchParams(window.location.search);
    let q = document.getElementById('searchInput').value.trim();
    let art = document.getElementById('artistSelect').value;
    let sort = document.getElementById('sortSelect').value;
    if (q) params.set('q', q); else params.delete('q');
    if (art) params.set('artist', art); else params.delete('artist');
    if (sort) params.set('sort', sort); else params.delete('sort');
    params.delete('page');
    window.location.href = 'commissions.php?' + params.toString();
}
</script>
</body>
</html>