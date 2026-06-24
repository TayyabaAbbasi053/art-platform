<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// ── Auth guard — artist only ────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'artist') {
    header('Location: ../../login.php');
    exit;
}
$__userStatus = $conn->query("SELECT status, status_reason FROM users WHERE id = {$_SESSION['user_id']}")->fetch_assoc();
if ($__userStatus['status'] === 'blocked') {
    session_destroy();
    header('Location: ../../login.php?blocked=1&reason=' . urlencode($__userStatus['status_reason'] ?? ''));
    exit;
}
if ($__userStatus['status'] === 'pending') {
    session_destroy();
    $__pendingEmail = $conn->query("SELECT email FROM users WHERE id={$_SESSION['user_id']}")->fetch_assoc()['email'] ?? '';
header('Location: ../../login.php?pending=1&email=' . urlencode($__pendingEmail));
    exit;
}

$artistId = (int) $_SESSION['user_id'];
$artistName = $_SESSION['name'] ?? 'Artist';
$pakistaniCities = [
    'Abbottabad','Ahmedpur East','Arif Wala','Attock','Badin','Bahawalnagar','Bahawalpur',
    'Barikot','Bhakkar','Bhalwal','Bholari','Burewala','Chaman','Charsadda','Chichawatni',
    'Chiniot','Chishtian','Dadu','Daska','Dera Ghazi Khan','Dera Ismail Khan','Dera Murad Jamali',
    'Dipalpur','Faisalabad','Farooqabad','Ferozwala','Ghotki','Gojra','Gujar Khan','Gujranwala',
    'Gujranwala Cantonment','Gujrat','Hafizabad','Haroonabad','Hasilpur','Haveli Lakha','Hub',
    'Hyderabad','Islamabad','Jacobabad','Jalalpur Jattan','Jampur','Jaranwala','Jatoi','Jauharabad',
    'Jhang','Jhelum','Kabal','Kamalia','Kamber Ali Khan','Kamoke','Karachi','Kasur','Khairpur',
    'Khanewal','Khanpur','Kharian','Khushab','Khuzdar','Kohat','Kot Abdul Malik','Kot Addu',
    'Kot Radha Kishan','Kotri','Lahore','Lala Musa','Larkana','Layyah','Lodhran','Ludhewala Waraich',
    'Mailsi','Mandi Bahauddin','Mansehra','Mardan','Mian Channu','Mianwali','Mingora','Mirpur',
    'Mirpur Khas','Moro','Multan','Muridke','Muzaffarabad','Muzaffargarh','Narowal','Nawabshah',
    'Nowshera','Okara','Pakpattan','Panjgur','Pasrur','Pattoki','Phool Nagar','Pishin','Quetta',
    'Rahim Yar Khan','Rajanpur','Rawalpindi','Renala Khurd','Sadiqabad','Sahiwal','Sambrial',
    'Samundri','Sangla Hill','Sargodha','Shabqadar','Shahdadkot','Shahdadpur','Shakargarh',
    'Shikarpur','Shujabad','Sialkot','Sukkur','Swabi','Taxila','Tando Adam','Tando Allahyar',
    'Tando Muhammad Khan','Taunsa','Turbat','Umerkot','Vehari','Wah Cantonment','Wazirabad'
];
$successMsg = '';
$errorMsg   = '';

if (isset($_SESSION['profile_incomplete_msg'])) {
    $errorMsg = $_SESSION['profile_incomplete_msg'];
    unset($_SESSION['profile_incomplete_msg']);
}

// ── Fetch current data ──────────────────────────────────
$user = $conn->query("SELECT name, email, phone, profile_picture FROM users WHERE id = $artistId")->fetch_assoc();

// Ensure artist_profiles row exists
$conn->query("INSERT IGNORE INTO artist_profiles (user_id) VALUES ($artistId)");

$profile = $conn->query("
    SELECT bio, city, address, instagram_url, contact_email, contact_phone, art_style, accepts_commissions,
           has_bank_account, bank_name, bank_account_title, bank_account_number,
           has_easypaisa, easypaisa_name, easypaisa_number,
           has_jazzcash, jazzcash_name, jazzcash_number,
           has_nayapay, nayapay_name, nayapay_number,
           has_sadapay, sadapay_name, sadapay_number,
           profile_complete
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
    $address            = trim($_POST['address'] ?? '');
    $hasBankAccount     = isset($_POST['has_bank_account']) ? 1 : 0;
    $hasEasypaisa       = isset($_POST['has_easypaisa']) ? 1 : 0;
    $hasJazzcash        = isset($_POST['has_jazzcash']) ? 1 : 0;
    $hasNayapay         = isset($_POST['has_nayapay']) ? 1 : 0;
    $hasSadapay         = isset($_POST['has_sadapay']) ? 1 : 0;

    $bankName   = $hasBankAccount ? trim($_POST['bank_name'] ?? '') : null;
    $bankTitle  = $hasBankAccount ? trim($_POST['bank_account_title'] ?? '') : null;
    $bankNumber = $hasBankAccount ? trim($_POST['bank_account_number'] ?? '') : null;
    $epName     = $hasEasypaisa ? trim($_POST['easypaisa_name'] ?? '') : null;
    $epNum      = $hasEasypaisa ? trim($_POST['easypaisa_number'] ?? '') : null;
    $jcName     = $hasJazzcash ? trim($_POST['jazzcash_name'] ?? '') : null;
    $jcNum      = $hasJazzcash ? trim($_POST['jazzcash_number'] ?? '') : null;
    $npName     = $hasNayapay ? trim($_POST['nayapay_name'] ?? '') : null;
    $npNum      = $hasNayapay ? trim($_POST['nayapay_number'] ?? '') : null;
    $spName     = $hasSadapay ? trim($_POST['sadapay_name'] ?? '') : null;
    $spNum      = $hasSadapay ? trim($_POST['sadapay_number'] ?? '') : null;

    // Determine if profile is now complete
    $profileComplete = (!empty($city) && !empty($address) && ($hasBankAccount || $hasEasypaisa || $hasJazzcash || $hasNayapay || $hasSadapay)) ? 1 : 0;
    $profileCompletedAt = ($profileComplete && !($profile['profile_complete'] ?? 0)) ? date('Y-m-d H:i:s') : null;

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

            // Update artist_profiles table with all fields
            // COUNT: 26 fields being updated: bio, city, address, instagram_url, contact_email, 
            // contact_phone, art_style, accepts_commissions, has_bank_account, bank_name, 
            // bank_account_title, bank_account_number, has_easypaisa, easypaisa_name, easypaisa_number,
            // has_jazzcash, jazzcash_name, jazzcash_number, has_nayapay, nayapay_name, nayapay_number,
            // has_sadapay, sadapay_name, sadapay_number, profile_complete, profile_completed_at
            // + 1 for WHERE user_id = 27 total parameters
            $stmt = $conn->prepare("
                UPDATE artist_profiles
                SET bio = ?, city = ?, address = ?, instagram_url = ?, contact_email = ?,
                    contact_phone = ?, art_style = ?, accepts_commissions = ?,
                    has_bank_account = ?, bank_name = ?, bank_account_title = ?, bank_account_number = ?,
                    has_easypaisa = ?, easypaisa_name = ?, easypaisa_number = ?,
                    has_jazzcash = ?, jazzcash_name = ?, jazzcash_number = ?,
                    has_nayapay = ?, nayapay_name = ?, nayapay_number = ?,
                    has_sadapay = ?, sadapay_name = ?, sadapay_number = ?,
                    profile_complete = ?, profile_completed_at = ?
                WHERE user_id = ?
            ");
            
            // 27 parameters total: 26 SET fields + 1 WHERE
            // Types: s=string, i=integer
            // s(1) bio, s(2) city, s(3) address, s(4) instagram_url, s(5) contact_email,
            // s(6) contact_phone, s(7) art_style, i(8) accepts_commissions,
            // i(9) has_bank_account, s(10) bank_name, s(11) bank_account_title, s(12) bank_account_number,
            // i(13) has_easypaisa, s(14) easypaisa_name, s(15) easypaisa_number,
            // i(16) has_jazzcash, s(17) jazzcash_name, s(18) jazzcash_number,
            // i(19) has_nayapay, s(20) nayapay_name, s(21) nayapay_number,
            // i(22) has_sadapay, s(23) sadapay_name, s(24) sadapay_number,
            // i(25) profile_complete, s(26) profile_completed_at,
            // i(27) user_id
            $typeString = 'sssssssiisssississississisi';
            
            $stmt->bind_param(
                $typeString,
                $bio, $city, $address, $instagram_url, $contact_email,
                $contact_phone, $art_style, $accepts_commissions,
                $hasBankAccount, $bankName, $bankTitle, $bankNumber,
                $hasEasypaisa, $epName, $epNum,
                $hasJazzcash, $jcName, $jcNum,
                $hasNayapay, $npName, $npNum,
                $hasSadapay, $spName, $spNum,
                $profileComplete, $profileCompletedAt,
                $artistId
            );
            $stmt->execute();

            // Refresh session and data
            $_SESSION['name'] = $name;
            $artistName = $name;
            $user = $conn->query("SELECT name, email, phone, profile_picture FROM users WHERE id = $artistId")->fetch_assoc();
            $profile = $conn->query("
                SELECT bio, city, address, instagram_url, contact_email, contact_phone, art_style, accepts_commissions,
                       has_bank_account, bank_name, bank_account_title, bank_account_number,
                       has_easypaisa, easypaisa_name, easypaisa_number,
                       has_jazzcash, jazzcash_name, jazzcash_number,
                       has_nayapay, nayapay_name, nayapay_number,
                       has_sadapay, sadapay_name, sadapay_number,
                       profile_complete
                FROM artist_profiles WHERE user_id = $artistId
            ")->fetch_assoc();

            $successMsg = 'Profile updated successfully.';
        }
    }
}

// ── Avatar URL for display ──────────────────────────────
$avatarUrl = $user['profile_picture'] ? '../../' . $user['profile_picture'] : null;

// ── Fetch counts for Sidebar Badges ─────────────────────
$pendingCount = (int) ($conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'pending'")->fetch_row()[0] ?? 0);

$newCommCount = (int) ($conn->query("
    SELECT COUNT(*) 
    FROM commission_requests cr 
    JOIN orders o ON cr.order_id = o.id 
    WHERE cr.artist_id = $artistId AND o.order_type = 'commission' AND o.order_status = 'pending'
")->fetch_row()[0] ?? 0);

$newOrdersCount = 0;
$countStmt = $conn->prepare("
    SELECT COUNT(DISTINCT o.id) 
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = ? AND o.order_type = 'artwork' AND o.order_status = 'pending'
");
$countStmt->bind_param('i', $artistId);
$countStmt->execute();
$newOrdersCount = $countStmt->get_result()->fetch_row()[0];
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
    --bg: #F6EDDE; --card: #F6EDDE; --sand: #DDCDAE; --border: #0C3F30;
    --ink: #0C3F30; --body: #0C3F30; --muted: #8a9e97; --light: #0C3F30;
    --r: 16px;
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }

/* ── Sidebar ─────────────────────────────────────────── */
.sidebar {
    position: fixed; top: 0; left: 0;
    width: var(--sidebar); height: 100vh;
    background: var(--ink);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column; z-index: 100; overflow-y: auto;
}
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--border); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--bg); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); font-weight: 400; margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 20px; font-size: 12.5px; color: var(--bg);
    text-decoration: none; font-weight: 400;
    border-left: 2px solid transparent; transition: all .15s;
}
.nav-item:hover { color: var(--ink); background: rgba(255,255,255,0.3); border-left-color: var(--sand); }
.nav-item.active { color: var(--ink); background: var(--sand); font-weight: 500; border-left-color: var(--sand); }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .55; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.badge { margin-left: auto; background: var(--sand); color: var(--ink); font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
.badge.amber { background: var(--sand); color: var(--ink); }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
.signout-btn {
    display: flex; align-items: center; gap: 8px; padding: 9px 12px;
    font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px;
    transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif;
}
.signout-btn:hover { background: rgba(255,255,255,0.1); color: var(--sand); }

/* ── Topbar ──────────────────────────────────────────── */
.topbar {
    position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top);
    background: var(--ink); border-bottom: 1px solid var(--ink);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 32px; z-index: 99;
}
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.topbar-right { display: flex; align-items: center; gap: 20px; }
.artist-chip {
    display: flex; align-items: center; gap: 8px;
    background: var(--sand); border: 1px solid var(--border);
    padding: 5px 12px 5px 5px; border-radius: 30px;
}
.artist-chip .avatar {
    width: 26px; height: 26px; border-radius: 50%; background: var(--ink);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; color: var(--bg); font-weight: 600; overflow: hidden;
}
.artist-chip .avatar img { width: 100%; height: 100%; object-fit: cover; }
.artist-chip .name { font-size: 12px; font-weight: 500; color: var(--ink); }
.artist-chip .arrow { font-size: 12px; color: var(--muted); margin-left: 4px; }

/* ── Main ────────────────────────────────────────────── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; max-width: 780px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 20px; }

/* ── Messages ────────────────────────────────────────── */
.msg { padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
.msg.success { background: var(--sand); color: var(--ink); border: 1px solid var(--border); }
.msg.error { background: var(--sand); color: var(--ink); border: 1px solid var(--border); }

/* ── Profile Card ────────────────────────────────────── */
.profile-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--r); overflow: visible; margin-bottom: 24px; }
.profile-card-header {
    padding: 24px 28px 20px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.profile-card-header h2 { font-family: 'Playfair Display', serif; font-size: 18px; font-weight: 400; color: var(--ink); }
.profile-card-header .hint { font-size: 11px; color: var(--muted); }
.profile-card-body { padding: 28px; }

/* ── Avatar upload ───────────────────────────────────── */
.avatar-upload { display: flex; align-items: center; gap: 24px; padding-bottom: 28px; }
.avatar-preview {
    width: 100px; height: 100px; border-radius: 50%; flex-shrink: 0;
    background: var(--sand); border: 2px dashed var(--border);
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; position: relative; cursor: pointer; transition: border-color .2s;
}
.avatar-preview:hover { border-color: var(--ink); }
.avatar-preview img { width: 100%; height: 100%; object-fit: cover; }
.avatar-preview .placeholder-icon { color: var(--ink); }
.avatar-preview .overlay {
    position: absolute; inset: 0; background: rgba(12,63,48,.45);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .2s; border-radius: 50%;
}
.avatar-preview:hover .overlay { opacity: 1; }
.avatar-info { flex: 1; }
.avatar-info .avatar-label { font-size: 13px; font-weight: 500; margin-bottom: 4px; color: var(--ink); }
.avatar-info .avatar-hint { font-size: 11px; color: var(--muted); line-height: 1.5; }
.avatar-info .avatar-hint span { color: var(--ink); font-weight: 500; }
.avatar-remove {
    margin-top: 8px; font-size: 11px; color: var(--ink); background: transparent;
    border: 1px solid var(--border); cursor: pointer; font-family: 'DM Sans', sans-serif;
    text-decoration: none; display: none; padding: 4px 10px; border-radius: 6px;
}
.avatar-remove.visible { display: inline-block; }

/* ── Form fields ─────────────────────────────────────── */
.field-group { margin-bottom: 22px; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.field-label {
    display: block; font-size: 10.5px; letter-spacing: .8px; text-transform: uppercase;
    color: var(--ink); font-weight: 500; margin-bottom: 7px;
}
.field-label .optional { font-weight: 400; text-transform: none; letter-spacing: 0; color: var(--muted); }
.field-label .private-tag {
    font-weight: 400; text-transform: none; letter-spacing: 0;
    background: var(--sand); border: 1px solid var(--border);
    padding: 1px 7px; border-radius: 10px; font-size: 9px; margin-left: 6px; color: var(--ink);
}
.field-input {
    width: 100%; padding: 10px 14px; font-size: 13px; font-family: 'DM Sans', sans-serif;
    border: 1.5px solid var(--sand); border-radius: 10px; background: var(--bg);
    color: var(--ink); transition: border-color .15s; outline: none;
}
.field-input:focus { border-color: var(--ink); }
.field-input::placeholder { color: var(--muted); }
.city-search-wrap{position:relative;}
.city-dropdown{display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg);border:1.5px solid var(--border);border-radius:8px;max-height:180px;overflow-y:auto;z-index:50;box-shadow:0 8px 20px rgba(0,0,0,0.1);margin-top:4px;}
.city-dropdown.open{display:block;}
.city-option{padding:8px 14px;font-size:13px;color:var(--ink);cursor:pointer;}
.city-option:hover,.city-option.active{background:var(--sand);}
.city-no-results{padding:8px 14px;font-size:11.5px;color:var(--muted);font-style:italic;}
textarea.field-input { resize: vertical; min-height: 110px; line-height: 1.6; }

/* ── BIO RESTRICTION NOTE STYLE ────────────────────── */
.bio-warning-box {
    background: #FCEEE9;
    border: 1px solid #EEC5B8;
    border-radius: 10px;
    padding: 10px 14px;
    margin-top: 6px;
    font-size: 11px;
    color: #7D2A14;
    line-height: 1.5;
    display: flex;
    gap: 8px;
}
.bio-warning-box svg {
    flex-shrink: 0;
    margin-top: 2px;
}

/* ── Toggle switch ───────────────────────────────────── */
.toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 22px 28px;
}
.toggle-title { font-size: 13px; font-weight: 500; margin-bottom: 3px; color: var(--ink); }
.toggle-desc { font-size: 11px; color: var(--muted); }
.toggle-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; accent-color: var(--ink); }
.toggle-slider {
    position: absolute; inset: 0; cursor: pointer;
    background: var(--sand); border: 1.5px solid var(--border); border-radius: 24px; transition: background .2s;
}
.toggle-slider::before {
    content: ''; position: absolute; left: 3px; top: 3px;
    width: 18px; height: 18px; background: #fff; border-radius: 50%; transition: transform .2s;
}
.toggle-switch input:checked + .toggle-slider { background: var(--ink); border-color: var(--ink); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

/* ── Payment Toggles ─────────────────────────────────── */
.pay-toggle{display:flex;align-items:center;gap:5px;font-size:10px;color:#666;cursor:pointer;padding:5px 12px;border:1.5px solid var(--sand);border-radius:20px;transition:all .2s;user-select:none;}
.pay-toggle input{display:none;}
.pay-toggle.active{border-color:var(--ink);background:var(--sand);color:var(--ink);font-weight:500;}
.pay-fields{display:none;background:var(--bg);border:1px solid var(--sand);border-radius:10px;padding:14px 16px;margin-bottom:10px;}
.pay-fields.show{display:block;}

/* ── Buttons ─────────────────────────────────────────── */
.form-actions { padding-top: 24px; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.btn {
    padding: 10px 24px; border-radius: 10px; font-size: 12.5px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer; border: none;
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all .15s;
}
.btn-primary { background: var(--sand); color: var(--ink); }
.btn-primary:hover { background: #c4b69e; }
.btn-ghost { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--ink); color: var(--ink); }

/* ── Info box ────────────────────────────────────────── */
.info-box { background: var(--sand); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; margin-top: 4px; }
.info-box p { font-size: 11px; color: var(--ink); line-height: 1.5; }
.info-box strong { color: var(--ink); }

/* ── Footer ──────────────────────────────────────────── */
.dash-footer { padding: 20px 32px; border-top: 1px solid var(--border); font-size: 11px; color: var(--bg); margin-top: 12px; background: var(--ink); }

/* ── Responsive & Drawer ─────────────────────────────── */
@media(max-width:1080px){
    .content { padding: 24px; }
}

@media(max-width:768px){
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; }
    .content { padding: 16px; }
    .field-row { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column; gap: 12px; }
    .form-actions .btn { width: 100%; justify-content: center; }
    .avatar-upload { flex-direction: column; text-align: center; }
    
    .ham-btn{display:inline-block;width:30px;height:24px;position:relative;background:none;border:none;cursor:pointer;z-index:2000;}
    .ham-btn span{position:absolute;display:block;width:100%;height:2px;background:var(--bg);border-radius:2px;transition:all .3s;opacity:1;left:0;}
    .ham-btn span:nth-child(1){top:2px;}
    .ham-btn span:nth-child(2){top:10px;}
    .ham-btn span:nth-child(3){top:18px;}
    .open #nav-drawer{display:block;position:fixed;top:0;right:0;width:80%;height:100%;background:var(--ink);z-index:1001;padding:40px 20px;box-shadow:-5px 0 15px rgba(0,0,0,0.1);transition:right 0.3s ease;}
    .open #nav-overlay{display:block;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;}
    
    #nav-drawer a { display: block; padding: 15px 0; color: var(--bg); font-size: 16px; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .dash-footer { text-align: center; padding: 20px 16px; }
}

#nav-drawer{display:none;}
#nav-overlay{display:none;}
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
    <a href="orders.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        Orders
        <?php if ($newOrdersCount > 0): ?>
            <span class="badge"><?= $newOrdersCount ?></span>
        <?php endif; ?>
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
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <?php if (!($profile['profile_complete'] ?? 1)): ?>
    <div style="background:#fef3cd;border:1px solid #e6c200;border-radius:12px;padding:14px 20px;margin-bottom:24px;display:flex;align-items:flex-start;gap:12px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#856404" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><path d="M12 9v4m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <div>
            <strong style="font-size:12px;color:#856404;">Complete your profile</strong>
            <p style="font-size:11px;color:#856404;margin-top:2px;">Please add your address and at least one payment method below so you can receive payouts.</p>
            <p style="font-size:11px;color:#856404;margin-top:6px;font-weight:600;">⚠ You will not be able to upload artworks until your profile is 100% complete — including your bio, city, address, art style, profile picture, and at least one payment method.</p>
        </div>
    </div>
<?php endif; ?>
    
    <div class="section-title">Edit Your Profile</div>

    <?php if ($successMsg): ?>
        <div class="msg success">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= htmlspecialchars($successMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
    <div class="msg error" style="flex-direction:column;align-items:flex-start;gap:6px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <?= htmlspecialchars($errorMsg) ?>
        </div>
        <div style="font-size:12px;font-weight:600;padding-left:24px;">⚠ To upload artworks on Art Bazaar, your profile must be 100% complete. Please fill in all the missing fields below.</div>
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
                    <label class="field-label">Bio </label>
                    <textarea name="bio" class="field-input" id="bioInput" placeholder="Tell buyers about yourself, your artistic journey, inspiration, and techniques..."><?= htmlspecialchars($profile['bio'] ?? '') ?></textarea>
                    
                    <div class="bio-warning-box">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 9v4m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <div>
                            <strong>Please do not add:</strong> Phone numbers, WhatsApp, Email, "DM me", bank accounts, Easypaisa, JazzCash, or direct payment links. These are not allowed in the bio.
                        </div>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label class="field-label">City *</label>
                        <div class="city-search-wrap">
                            <input type="text" class="field-input city-search-input" id="citySearchInput" placeholder="Search or type your city..." autocomplete="off" value="<?= htmlspecialchars($profile['city'] ?? '') ?>">
                            <input type="hidden" name="city" id="cityHidden" value="<?= htmlspecialchars($profile['city'] ?? '') ?>">
                            <div class="city-dropdown" id="cityDropdown"></div>
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Address *</label>
                        <input type="text" name="address" class="field-input" value="<?= htmlspecialchars($profile['address'] ?? '') ?>" placeholder="Street / Area">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label class="field-label">Art Style</label>
                        <input type="text" name="art_style" class="field-input" value="<?= htmlspecialchars($profile['art_style'] ?? '') ?>" placeholder="e.g. Contemporary, Abstract, Realism">
                    </div>
                    <div class="field-group">
                        <!-- empty for layout -->
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Social & Contact ───────────────────── -->
        <div class="profile-card">
            <div class="profile-card-header">
                <h2>Social &amp; Contact</h2>
            </div>
            <div class="profile-card-body">
                <div class="field-group">
                    <label class="field-label">Instagram URL <span class="optional">(optional)</span></label>
                    <input type="url" name="instagram_url" class="field-input" value="<?= htmlspecialchars($profile['instagram_url'] ?? '') ?>" placeholder="https://instagram.com/yourhandle">
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label class="field-label">Contact Email <span class="private-tag">Private</span> </label>
                        <input type="email" name="contact_email" class="field-input" value="<?= htmlspecialchars($profile['contact_email'] ?? '') ?>" placeholder="your@email.com">
                    </div>
                    <div class="field-group">
                        <label class="field-label">Contact Phone <span class="private-tag">Private</span> </label>
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

        <!-- ── Payment Methods ───────────────────── -->
        <div class="profile-card">
            <div class="profile-card-header">
                <h2>Payment Methods</h2>
                <span class="hint">For receiving payouts — never shown publicly</span>
            </div>
            <div class="profile-card-body">
    <p style="font-size:11px;color:var(--muted);margin-bottom:12px;line-height:1.5;">
        Tap a method below to add its details. Click <strong>Remove this payment method</strong> inside an open method to delete it. Don't forget to hit <strong>Save Changes</strong> at the bottom to confirm any changes.
    </p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
                   <label class="pay-toggle <?= ($profile['has_bank_account'] ?? 0) ? 'active' : '' ?>">
    <input type="checkbox" name="has_bank_account" data-key="bank" <?= ($profile['has_bank_account'] ?? 0) ? 'checked' : '' ?>> 🏦 Bank Account
</label>
<label class="pay-toggle <?= ($profile['has_easypaisa'] ?? 0) ? 'active' : '' ?>">
    <input type="checkbox" name="has_easypaisa" data-key="easypaisa" <?= ($profile['has_easypaisa'] ?? 0) ? 'checked' : '' ?>> Easypaisa
</label>
<label class="pay-toggle <?= ($profile['has_jazzcash'] ?? 0) ? 'active' : '' ?>">
    <input type="checkbox" name="has_jazzcash" data-key="jazzcash" <?= ($profile['has_jazzcash'] ?? 0) ? 'checked' : '' ?>> JazzCash
</label>
<label class="pay-toggle <?= ($profile['has_nayapay'] ?? 0) ? 'active' : '' ?>">
    <input type="checkbox" name="has_nayapay" data-key="nayapay" <?= ($profile['has_nayapay'] ?? 0) ? 'checked' : '' ?>> NayaPay
</label>
<label class="pay-toggle <?= ($profile['has_sadapay'] ?? 0) ? 'active' : '' ?>">
    <input type="checkbox" name="has_sadapay" data-key="sadapay" <?= ($profile['has_sadapay'] ?? 0) ? 'checked' : '' ?>> SadaPay
</label>

                </div>

                <div id="pay-bank" class="pay-fields <?= ($profile['has_bank_account'] ?? 0) ? 'show' : '' ?>">
                    <div class="field-group"><label class="field-label">Bank Name</label><input type="text" name="bank_name" class="field-input" value="<?= htmlspecialchars($profile['bank_name'] ?? '') ?>" placeholder="e.g. HBL, Meezan"></div>
                    <div class="field-row">
                        <div class="field-group"><label class="field-label">Account Title</label><input type="text" name="bank_account_title" class="field-input" value="<?= htmlspecialchars($profile['bank_account_title'] ?? '') ?>" placeholder="Name on account"></div>
                        <div class="field-group"><label class="field-label">Account Number / IBAN</label><input type="text" name="bank_account_number" class="field-input" value="<?= htmlspecialchars($profile['bank_account_number'] ?? '') ?>" placeholder="Account / IBAN"></div>
                    </div>
                </div>

                <div id="pay-easypaisa" class="pay-fields <?= ($profile['has_easypaisa'] ?? 0) ? 'show' : '' ?>">
                    <div class="field-row">
                        <div class="field-group"><label class="field-label">Account Name</label><input type="text" name="easypaisa_name" class="field-input" value="<?= htmlspecialchars($profile['easypaisa_name'] ?? '') ?>" placeholder="Registered name"></div>
                        <div class="field-group"><label class="field-label">Mobile Number</label><input type="tel" name="easypaisa_number" class="field-input" value="<?= htmlspecialchars($profile['easypaisa_number'] ?? '') ?>" placeholder="03XX XXXXXXX"></div>
                    </div>
                </div>

                <div id="pay-jazzcash" class="pay-fields <?= ($profile['has_jazzcash'] ?? 0) ? 'show' : '' ?>">
                    <div class="field-row">
                        <div class="field-group"><label class="field-label">Account Name</label><input type="text" name="jazzcash_name" class="field-input" value="<?= htmlspecialchars($profile['jazzcash_name'] ?? '') ?>" placeholder="Registered name"></div>
                        <div class="field-group"><label class="field-label">Mobile Number</label><input type="tel" name="jazzcash_number" class="field-input" value="<?= htmlspecialchars($profile['jazzcash_number'] ?? '') ?>" placeholder="03XX XXXXXXX"></div>
                    </div>
                </div>

                <div id="pay-nayapay" class="pay-fields <?= ($profile['has_nayapay'] ?? 0) ? 'show' : '' ?>">
                    <div class="field-row">
                        <div class="field-group"><label class="field-label">Account Name</label><input type="text" name="nayapay_name" class="field-input" value="<?= htmlspecialchars($profile['nayapay_name'] ?? '') ?>" placeholder="Registered name"></div>
                        <div class="field-group"><label class="field-label">Mobile Number</label><input type="tel" name="nayapay_number" class="field-input" value="<?= htmlspecialchars($profile['nayapay_number'] ?? '') ?>" placeholder="03XX XXXXXXX"></div>
                    </div>
                </div>

                <div id="pay-sadapay" class="pay-fields <?= ($profile['has_sadapay'] ?? 0) ? 'show' : '' ?>">
                    <div class="field-row">
                        <div class="field-group"><label class="field-label">Account Name</label><input type="text" name="sadapay_name" class="field-input" value="<?= htmlspecialchars($profile['sadapay_name'] ?? '') ?>" placeholder="Registered name"></div>
                        <div class="field-group"><label class="field-label">Mobile Number</label><input type="tel" name="sadapay_number" class="field-input" value="<?= htmlspecialchars($profile['sadapay_number'] ?? '') ?>" placeholder="03XX XXXXXXX"></div>
                    </div>
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
            <button type="submit" class="btn btn-primary" id="saveBtn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Changes
            </button>
        </div>

    </form>

</div>
<div class="dash-footer">Art Bazaar &mdash; Artist Dashboard &mdash; <?= date('Y') ?></div>
</main>

<!-- MOBILE DRAWER & OVERLAY -->
<div id="nav-drawer">
    <div style="margin-bottom: 30px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 20px;">
        <h2 style="color:var(--bg); font-family:'Playfair Display',serif;">Menu</h2>
    </div>
    <a href="index.php">Overview</a>
    <a href="upload-artwork.php">Upload Artwork</a>
    <a href="my-artworks.php">My Artworks</a>
    <a href="commissions.php">Commission Requests</a>
    <a href="orders.php">Orders</a>
    <a href="profile.php">My Profile</a>
    <div style="margin-top: 40px;">
        <a href="../../logout.php" style="display:inline-block; padding: 10px 20px; background:var(--sand); color:var(--ink); border-radius:30px; font-weight:600;">Sign Out</a>
    </div>
</div>
<div id="nav-overlay"></div>

<script>
    const PK_CITIES = <?= json_encode($pakistaniCities) ?>;

function initCitySearch(searchId, hiddenId, dropdownId) {
    const searchInput = document.getElementById(searchId);
    const hiddenInput = document.getElementById(hiddenId);
    const dropdown = document.getElementById(dropdownId);
    if (!searchInput || !hiddenInput || !dropdown) return;
    let activeIndex = -1;

    function renderOptions(filter) {
        const f = filter.trim().toLowerCase();
        const matches = f ? PK_CITIES.filter(c => c.toLowerCase().includes(f)) : PK_CITIES;
        dropdown.innerHTML = '';
        if (matches.length === 0) {
            dropdown.innerHTML = '<div class="city-no-results">No match — your typed city will be used as entered</div>';
        } else {
            matches.slice(0, 50).forEach((city) => {
                const opt = document.createElement('div');
                opt.className = 'city-option';
                opt.textContent = city;
                opt.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    selectCity(city);
                });
                dropdown.appendChild(opt);
            });
        }
        activeIndex = -1;
    }

    function selectCity(city) {
        searchInput.value = city;
        hiddenInput.value = city;
        hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
        dropdown.classList.remove('open');
    }

    searchInput.addEventListener('input', () => {
        hiddenInput.value = searchInput.value;
        hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
        renderOptions(searchInput.value);
        dropdown.classList.add('open');
    });
    searchInput.addEventListener('focus', () => {
        renderOptions(searchInput.value);
        dropdown.classList.add('open');
    });
    searchInput.addEventListener('blur', () => {
        setTimeout(() => dropdown.classList.remove('open'), 100);
    });
    searchInput.addEventListener('keydown', (e) => {
        const options = dropdown.querySelectorAll('.city-option');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, options.length - 1);
            options.forEach((o, i) => o.classList.toggle('active', i === activeIndex));
            if (options[activeIndex]) options[activeIndex].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            options.forEach((o, i) => o.classList.toggle('active', i === activeIndex));
            if (options[activeIndex]) options[activeIndex].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (options[activeIndex]) selectCity(options[activeIndex].textContent);
        } else if (e.key === 'Escape') {
            dropdown.classList.remove('open');
        }
    });
}

initCitySearch('citySearchInput', 'cityHidden', 'cityDropdown');
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

// ── Payment toggle ─────────────────────────────────────
// NEW — add this in its place
document.querySelectorAll('.pay-toggle input[type=checkbox]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        const key = this.dataset.key;
        this.closest('.pay-toggle').classList.toggle('active', this.checked);
        const field = document.getElementById('pay-' + key);
        if (field) field.classList.toggle('show', this.checked);
    });
});

// ── Drawer Logic ───────────────────────────────────────
const drawer = document.querySelector('body');
const overlay = document.getElementById('nav-overlay');

if(window.innerWidth <= 768 && !document.querySelector('.ham-btn')){
    const topbarRight = document.querySelector('.topbar-right');
    if(topbarRight){
        const btn = document.createElement('button');
        btn.className = 'ham-btn';
        btn.innerHTML = '<span></span><span></span><span></span>';
        topbarRight.insertBefore(btn, topbarRight.firstChild);
        
        btn.addEventListener('click', () => {
            drawer.classList.toggle('open');
        });
    }
}

overlay.addEventListener('click', () => {
    drawer.classList.remove('open');
});

// ── Bio validation ─────────────────────────────────────
document.getElementById('profileForm').addEventListener('submit', function(e) {
    const bio = document.getElementById('bioInput').value.toLowerCase();
    const forbidden = [
        /\d{4,}/,
        /easypaisa|jazzcash|bank|account/i,
        /wa\.me|whatsapp/i,
        /dm\s*me|direct\s*message/i,
        /@gmail\.com|@yahoo\.com/i
    ];

    for (let pattern of forbidden) {
        if (pattern.test(bio)) {
            alert('Please remove phone numbers, payment details (Easypaisa, JazzCash), or "DM me" instructions from your bio. These are not allowed on public profiles.');
            e.preventDefault();
            return;
        }
    }
});
</script>

</body>
</html>