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
$errorMsg = '';

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

// ── Fetch artist avatar ───────────────────────────────
$artistInfo = $conn->query("SELECT profile_picture FROM users WHERE id = $artistId")->fetch_assoc();
$avatarUrl  = $artistInfo['profile_picture'] ? '../../' . $artistInfo['profile_picture'] : null;

// ── Handle send message POST action ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $commissionId = (int)($_POST['commission_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    // Verify this commission belongs to the artist
    $check = $conn->prepare("SELECT id FROM commission_requests WHERE id = ? AND artist_id = ?");
    $check->bind_param('ii', $commissionId, $artistId);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $errorMsg = 'Invalid commission request.';
    } elseif ($message && containsContactInfo($message)) {
        $errorMsg = 'Message blocked: Contact information (phone, email, social handles, bank details) cannot be shared.';
    } elseif ($message) {
        $stmt = $conn->prepare("INSERT INTO commission_messages (commission_id, sender_role, sender_name, message) VALUES (?, 'artist', ?, ?)");
        $stmt->bind_param('iss', $commissionId, $artistName, $message);
        $stmt->execute();
        $successMsg = 'Message sent.';
    }
}

// ── Fetch commission requests assigned to this artist ──
$sql = "
    SELECT cr.*, c.name as category_name
    FROM commission_requests cr
    LEFT JOIN categories c ON cr.category_id = c.id
    WHERE cr.artist_id = $artistId
    ORDER BY
        CASE cr.status
            WHEN 'new'         THEN 1
            WHEN 'contacted'   THEN 2
            WHEN 'assigned'    THEN 3
            WHEN 'in_progress' THEN 4
            WHEN 'completed'   THEN 5
            WHEN 'cancelled'   THEN 6
        END,
        cr.created_at DESC
";
$requests = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// ── Fetch specific request for modal ─────────────────
$viewRequest = null;
$viewMessages = [];
if (isset($_GET['view'])) {
    $viewId = (int) $_GET['view'];
    foreach ($requests as $r) {
        if ($r['id'] == $viewId) {
            $viewRequest = $r;
            // Fetch messages for this commission
            $msgStmt = $conn->prepare("SELECT * FROM commission_messages WHERE commission_id = ? ORDER BY created_at ASC");
            $msgStmt->bind_param('i', $viewId);
            $msgStmt->execute();
            $viewMessages = $msgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Commission Requests — Art Bazaar</title>
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
    --terracotta: #C96B4B;
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--grey1); color: var(--black); font-family: 'DM Sans', sans-serif; }

.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: #EFE3D2; border-right: 1px solid var(--grey2); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--grey2); }
.sidebar-brand .logo-tag  { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--grey4); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--black); font-weight: 400; margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--terracotta); color: var(--white); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--grey5); text-decoration: none; border-left: 2px solid transparent; transition: all .15s; }
.nav-item:hover { color: var(--black); background: rgba(255,255,255,0.3); border-left-color: var(--grey3); }
.nav-item.active { color: var(--black); background: rgba(255,255,255,0.4); border-left-color: var(--terracotta); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .55; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
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
.content { padding: 32px; max-width: 1200px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; margin-bottom: 20px; }

.success-msg { background: #E8F5EE; color: #6BA58D; padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; border: 1px solid #C8E0D5; }
.error-msg { background: #FDEAEA; color: #D46A6A; padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; border: 1px solid #F5C6C6; }

.info-box { background: #EEF2F8; border: 1px solid #D6E0E8; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; display: flex; gap: 12px; align-items: flex-start; }
.info-box svg { flex-shrink: 0; color: var(--blue); margin-top: 2px; }
.info-box-content h4 { font-size: 13px; font-weight: 500; color: var(--black); margin-bottom: 4px; }
.info-box-content p  { font-size: 12px; color: var(--grey5); line-height: 1.5; }

.card { background: var(--white); border: 1px solid var(--grey2); border-radius: 16px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 10px; letter-spacing: 1.2px; text-transform: uppercase; color: var(--grey4); font-weight: 500; padding: 14px 20px; text-align: left; border-bottom: 1px solid var(--grey2); background: var(--grey1); }
td { padding: 16px 20px; border-bottom: 1px solid var(--grey2); vertical-align: middle; font-size: 13px; color: var(--black); }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--grey1); }

.pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
.pill.new         { background: #FFF0EC; color: var(--terracotta); }
.pill.contacted   { background: #E8F4FF; color: #1565c0; }
.pill.assigned    { background: #FFF4E6; color: #E48A4A; }
.pill.in_progress { background: #EEF2F8; color: #3B7DD8; }
.pill.completed   { background: #E8F5EE; color: #6BA58D; }
.pill.cancelled   { background: #F4F4F4; color: #888; }

.view-btn { padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 500; border: 1px solid var(--grey3); background: #fff; color: var(--black); cursor: pointer; text-decoration: none; transition: all .15s; display: inline-block; }
.view-btn:hover { border-color: var(--black); background: var(--grey1); }

.empty-state { padding: 60px 20px; text-align: center; }
.empty-state h3 { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--black); margin-bottom: 8px; }
.empty-state p  { font-size: 13px; color: var(--grey4); }

/* Modal Styles (with chat) */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all .2s; }
.modal-overlay.active { opacity: 1; visibility: visible; }
.modal { background: var(--white); width: 100%; max-width: 750px; max-height: 90vh; border-radius: 20px; overflow-y: auto; position: relative; transform: translateY(20px); transition: transform .2s; }
.modal-overlay.active .modal { transform: translateY(0); }
.modal-header { padding: 24px 28px 16px; border-bottom: 1px solid var(--grey2); display: flex; justify-content: space-between; align-items: flex-start; }
.modal-header h2 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--black); }
.close-modal { background: none; border: none; cursor: pointer; color: var(--grey4); padding: 4px; font-size: 22px; line-height: 1; }
.close-modal:hover { color: var(--terracotta); }
.modal-body { padding: 28px; }
.detail-grid { display: grid; grid-template-columns: 140px 1fr; gap: 14px; margin-bottom: 20px; }
.detail-label { color: var(--grey5); font-weight: 500; font-size: 12px; }
.detail-value { color: var(--black); font-size: 13px; line-height: 1.5; }
.detail-full { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--grey2); }
.detail-full h5 { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: var(--grey5); margin-bottom: 10px; }
.detail-full p { font-size: 13px; line-height: 1.6; color: var(--grey5); background: var(--grey1); padding: 14px; border-radius: 10px; }
.ref-image { margin-top: 8px; max-width: 100%; max-height: 300px; border-radius: 10px; border: 1px solid var(--grey3); object-fit: contain; }
.admin-note { margin-top: 20px; background: #FFF4E6; padding: 14px; border-radius: 10px; border: 1px solid #E8DDD0; }
.admin-note h5 { font-size: 10px; color: #E48A4A; text-transform: uppercase; margin-bottom: 6px; }
.admin-note p { font-size: 12px; color: #5e4b00; line-height: 1.5; margin: 0; background: none; padding: 0; }

/* Chat Styles */
.chat-section { margin-top: 24px; padding-top: 20px; border-top: 2px solid var(--grey2); }
.chat-title { font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--grey4); font-weight: 500; margin-bottom: 12px; }
.chat-messages { background: var(--grey1); border-radius: 12px; height: 300px; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px; }
.message { display: flex; flex-direction: column; max-width: 80%; }
.message.artist { align-self: flex-end; }
.message.admin { align-self: flex-start; }
.message.buyer { align-self: flex-start; }
.message-bubble { padding: 10px 14px; border-radius: 18px; font-size: 13px; line-height: 1.5; word-break: break-word; }
.message.artist .message-bubble { background: var(--black); color: white; border-bottom-right-radius: 4px; }
.message.admin .message-bubble { background: #EEF2F8; color: var(--black); border-bottom-left-radius: 4px; }
.message.buyer .message-bubble { background: #E8F5EE; color: var(--black); border-bottom-left-radius: 4px; }
.message-meta { font-size: 10px; color: var(--grey4); margin-top: 4px; padding: 0 6px; display: flex; gap: 8px; align-items: center; }
.message.artist .message-meta { justify-content: flex-end; }
.chat-input-area { margin-top: 16px; display: flex; gap: 10px; }
.chat-input-area input { flex: 1; padding: 12px 16px; border: 1.5px solid var(--grey2); border-radius: 30px; font-size: 13px; font-family: 'DM Sans', sans-serif; outline: none; }
.chat-input-area input:focus { border-color: var(--black); }
.chat-input-area button { background: var(--black); color: white; border: none; border-radius: 30px; padding: 0 20px; font-weight: 500; cursor: pointer; transition: background .2s; }
.chat-input-area button:hover { background: #333; }
.chat-warning { font-size: 10px; color: var(--grey4); margin-top: 8px; text-align: center; }

@media (max-width: 900px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; }
    .content { padding: 20px; }
    .detail-grid { grid-template-columns: 1fr; gap: 8px; }
    .message { max-width: 95%; }
}
@media (max-width: 700px) {
    th, td { padding: 10px 12px; }
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
    </a>
    <a href="commissions.php" class="nav-item active">
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
    <div class="topbar-left"><h1>Commission Requests</h1></div>
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

    <div class="section-title">Incoming Requests</div>

    <?php if ($successMsg): ?>
        <div class="success-msg">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= htmlspecialchars($successMsg) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($errorMsg): ?>
        <div class="error-msg">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <div class="info-box">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <div class="info-box-content">
            <h4>How it works</h4>
            <p>Commissions are assigned to you by the admin. You can view all details and chat with the buyer through this page. Please do not share contact information — all communication stays here.</p>
        </div>
    </div>

    <?php if (empty($requests)): ?>
        <div class="card empty-state">
            <h3>No commission requests yet</h3>
            <p>When a buyer requests custom work from you, it will appear here.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Buyer</th>
                        <th>Project Type</th>
                        <th>Budget</th>
                        <th>Deadline</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($req['buyer_name']) ?></strong>
                                <div style="font-size:11px;color:var(--grey4);margin-top:2px;"><?= date('M j, Y', strtotime($req['created_at'])) ?></div>
                            </td>
                            <td><?= htmlspecialchars($req['category_name'] ?: 'Custom Artwork') ?></td>
                            <td style="font-size:12px;">
                                <?php if ($req['budget_min'] || $req['budget_max']): ?>
                                    PKR <?= number_format($req['budget_min'] ?? 0) ?> – <?= number_format($req['budget_max'] ?? 0) ?>
                                <?php else: ?>
                                    <span style="color:var(--grey4)">Open Budget</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req['deadline']): ?>
                                    <?= date('M j, Y', strtotime($req['deadline'])) ?>
                                <?php else: ?>
                                    <span style="color:var(--grey4)">No deadline</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="pill <?= $req['status'] ?>"><?= ucfirst(str_replace('_', ' ', $req['status'])) ?></span></td>
                            <td>
                                <a href="?view=<?= $req['id'] ?>" class="view-btn">View &amp; Chat</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>
</main>

<!-- ══════════════ MODAL (with chat) ══════════════ -->
<?php if ($viewRequest): ?>
<div class="modal-overlay active" id="modalOverlay">
    <div class="modal">
        <div class="modal-header">
            <h2>Commission Request #<?= $viewRequest['id'] ?></h2>
            <button class="close-modal" onclick="window.location.href='commissions.php'">&times;</button>
        </div>
        <div class="modal-body">

            <div class="detail-grid">
                <div class="detail-label">Status</div>
                <div class="detail-value"><span class="pill <?= $viewRequest['status'] ?>"><?= ucfirst(str_replace('_', ' ', $viewRequest['status'])) ?></span></div>

                <div class="detail-label">Buyer Name</div>
                <div class="detail-value"><strong><?= htmlspecialchars($viewRequest['buyer_name']) ?></strong></div>

                <div class="detail-label">Artwork Type</div>
                <div class="detail-value"><?= htmlspecialchars($viewRequest['category_name'] ?: 'Custom / Not specified') ?></div>

                <div class="detail-label">Budget Range</div>
                <div class="detail-value">
                    <?php if ($viewRequest['budget_min'] || $viewRequest['budget_max']): ?>
                        PKR <?= number_format($viewRequest['budget_min'] ?? 0) ?> – <?= number_format($viewRequest['budget_max'] ?? 0) ?>
                    <?php else: ?>
                        <span style="color:var(--grey4)">Not specified (open budget)</span>
                    <?php endif; ?>
                </div>

                <div class="detail-label">Desired Deadline</div>
                <div class="detail-value">
                    <?php if ($viewRequest['deadline']): ?>
                        <?= date('F j, Y', strtotime($viewRequest['deadline'])) ?>
                    <?php else: ?>
                        <span style="color:var(--grey4)">No specific deadline</span>
                    <?php endif; ?>
                </div>

                <div class="detail-label">Submitted On</div>
                <div class="detail-value"><?= date('F j, Y \a\t g:i A', strtotime($viewRequest['created_at'])) ?></div>
            </div>

            <div class="detail-full">
                <h5>Project Description</h5>
                <p><?= nl2br(htmlspecialchars($viewRequest['description'] ?? '')) ?></p>
            </div>

            <?php if ($viewRequest['reference_image']): ?>
                <div class="detail-full">
                    <h5>Reference Image</h5>
                    <img src="../../uploads/commissions/<?= htmlspecialchars($viewRequest['reference_image']) ?>" alt="Reference Image" class="ref-image">
                </div>
            <?php endif; ?>

            <?php if ($viewRequest['admin_notes']): ?>
                <div class="admin-note">
                    <h5>📝 Admin Note</h5>
                    <p><?= nl2br(htmlspecialchars($viewRequest['admin_notes'])) ?></p>
                </div>
            <?php endif; ?>

            <!-- Chat Section -->
            <div class="chat-section">
                <div class="chat-title">💬 Conversation</div>
                <div class="chat-messages" id="chatMessages">
                    <?php if (empty($viewMessages)): ?>
                        <div style="text-align:center;padding:20px;color:var(--grey4);">No messages yet. Start the conversation!</div>
                    <?php else: ?>
                        <?php foreach ($viewMessages as $msg): ?>
                            <?php 
                            $isArtist = $msg['sender_role'] === 'artist';
                            $roleClass = $isArtist ? 'artist' : ($msg['sender_role'] === 'admin' ? 'admin' : 'buyer');
                            ?>
                            <div class="message <?= $roleClass ?>">
                                <div class="message-bubble"><?= htmlspecialchars($msg['message']) ?></div>
                                <div class="message-meta">
                                    <span><?= htmlspecialchars($msg['sender_name']) ?></span>
                                    <span>•</span>
                                    <span><?= date('M j, g:i A', strtotime($msg['created_at'])) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <form method="POST" class="chat-input-area" onsubmit="return validateAndSubmit(this)">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="commission_id" value="<?= $viewRequest['id'] ?>">
                    <input type="text" name="message" placeholder="Type your message... (No phone/email/social handles allowed)" autocomplete="off" required>
                    <button type="submit">Send</button>
                </form>
                <div class="chat-warning">
                    ⚠️ Contact information (phone, email, Instagram, bank details) is automatically blocked.
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function validateAndSubmit(form) {
    const messageInput = form.querySelector('input[name="message"]');
    const message = messageInput.value.trim();
    
    // Client-side check for contact info (same patterns as server)
    const patterns = [
        /[\w.+-]+@[\w-]+\.[a-z]{2,}/i,
        /(\+92|0)?[-\s]?[0-9]{3}[-\s]?[0-9]{7,8}/,
        /(instagram|insta|ig|whatsapp|wa|facebook|fb|twitter|tiktok|snapchat)\s*[:\-@]?\s*\w+/i,
        /@[a-zA-Z0-9._]{2,30}/,
        /(iban|account\s*no|bank|easypaisa|jazzcash|sadapay|nayapay)/i,
    ];
    
    for (let pattern of patterns) {
        if (pattern.test(message)) {
            alert('Your message was blocked. Contact information (phone, email, social handles, bank details) cannot be shared here.');
            messageInput.value = '';
            return false;
        }
    }
    
    if (!message) {
        alert('Please enter a message.');
        return false;
    }
    
    return true;
}

// Auto-scroll chat to bottom
const chatContainer = document.getElementById('chatMessages');
if (chatContainer) {
    chatContainer.scrollTop = chatContainer.scrollHeight;
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('modalOverlay');
        if (modal && modal.classList.contains('active')) {
            window.location.href = 'commissions.php';
        }
    }
});
</script>
<?php endif; ?>

</body>
</html>