<?php
session_start();
require_once __DIR__ . '/config/db.php';



 $isLoggedIn = isset($_SESSION['user_id']);

 $preSelectedArtistId = isset($_GET['artist']) ? (int)$_GET['artist'] : null;
 $preSelectedArtistName = null;
 $preSelectedArtistStyle = null;

if ($preSelectedArtistId) {
    $stmt = $conn->prepare("SELECT u.name, ap.art_style FROM users u LEFT JOIN artist_profiles ap ON u.id = ap.user_id WHERE u.id = ? AND u.role = 'artist' AND u.status = 'active'");
    $stmt->bind_param('i', $preSelectedArtistId);
    $stmt->execute();
    $artist = $stmt->get_result()->fetch_assoc();
    if ($artist) {
        $preSelectedArtistName = $artist['name'];
        $preSelectedArtistStyle = $artist['art_style'];
    }
}

// Pre-fill form for logged-in users
 $prefillName = '';
 $prefillEmail = '';
 $prefillPhone = '';
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $userRes = $conn->query("SELECT name, email, phone FROM users WHERE id = $uid");
    if ($userRow = $userRes->fetch_assoc()) {
        $prefillName = htmlspecialchars($userRow['name'] ?? '');
        $prefillEmail = htmlspecialchars($userRow['email'] ?? '');
        $prefillPhone = htmlspecialchars($userRow['phone'] ?? '');
    }
}

// ============================================================
// HANDLE FORM SUBMISSION
// Saves to orders (order_type='commission') + commission_requests bridge
// Then redirects to buyer account page with confirmation flag
// ============================================================
 $commissionError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commission_request') {
    $buyerName  = trim($_POST['buyer_name'] ?? '');
    $buyerEmail = trim($_POST['buyer_email'] ?? '');
    $buyerPhone = trim($_POST['buyer_phone'] ?? '');
    $requestedArtistId = !empty($_POST['requested_artist_id']) ? (int)$_POST['requested_artist_id'] : null;
    $artworkType = trim($_POST['artwork_type'] ?? '');
    $budgetMin  = !empty($_POST['budget_min']) ? (float)$_POST['budget_min'] : null;
    $budgetMax  = !empty($_POST['budget_max']) ? (float)$_POST['budget_max'] : null;
    $deadline   = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
    $description = trim($_POST['description'] ?? '');
    $referenceImage = null;

    // ── NEW FIELDS ───────────────────────────────────────
    $commissionSize        = trim($_POST['commission_size'] ?? '');
    $commissionFramed      = trim($_POST['commission_framed'] ?? '');
    $commissionQuantity    = !empty($_POST['commission_quantity']) ? (int)$_POST['commission_quantity'] : 1;
    $commissionDeliveryCity = trim($_POST['commission_delivery_city'] ?? '');

    // Handle reference image upload (Increased size to 10MB)
    if (isset($_FILES['reference_image']) && $_FILES['reference_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['reference_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $maxSize = 10 * 1024 * 1024; // CHANGED: 2MB -> 10MB
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if ($file['size'] <= $maxSize && in_array($ext, $allowedExt)) {
            $dir = __DIR__ . '/uploads/commissions/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = 'ref_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                $referenceImage = $filename;
            }
        }
    }

    // Validate required fields
    if (!$buyerName || !$buyerEmail || !$description) {
        $commissionError = "Name, email, and description are required.";
    } else {
        // artwork_type now submits the category id directly
        $commissionCategoryId = !empty($_POST['artwork_type']) ? (int)$_POST['artwork_type'] : null;

        // Determine buyer_id: logged-in user or NULL for guest
        $buyerId = (isset($_SESSION['user_id'])) ? (int)$_SESSION['user_id'] : null;

        // Generate unique order number
        $orderNumber = 'COM-' . time() . '-' . rand(1000, 9999);

        // Price placeholder = budget_min or 0 (will be confirmed/agreed later)
        $subtotal = $budgetMin ?? 0;
        $total    = $subtotal;

        // Insert into orders with order_type='commission'
        // NOTE: Added commission_size, commission_framed, commission_quantity, commission_delivery_city
        // Assuming columns exist in 'orders' table or mapping to appropriate text fields.
        // If these columns don't exist in your DB yet, the query will fail.
        $stmt = $conn->prepare("
            INSERT INTO orders (
                buyer_id, guest_name, guest_email, guest_phone,
                order_number, order_type, order_status,
                subtotal, shipping_fee, discount, total,
                payment_method, payment_status,
                shipping_address, shipping_city, shipping_phone,
                commission_description, commission_reference_image,
                commission_deadline, commission_category_id,
                budget_min, budget_max,
                commission_size, commission_framed, commission_quantity, commission_delivery_city,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, 'commission', 'pending', ?, 0, 0, ?, 'cod', 'pending', '', '', '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        // Remap form values to valid DB ENUM values
$framedMap = [
    'unframed'         => 'unframed',
    'framed_basic'     => 'framed',
    'framed_premium'   => 'framed',
    'stretched_canvas' => 'unframed',
];
$commissionFramed = $framedMap[$commissionFramed] ?? 'not_specified';

$stmt->bind_param(
    "issssddsssiddssis",
    $buyerId, $buyerName, $buyerEmail, $buyerPhone,
    $orderNumber,
    $subtotal, $total,
    $description, $referenceImage,
    $deadline, $commissionCategoryId,
    $budgetMin, $budgetMax,
    $commissionSize, $commissionFramed, $commissionQuantity, $commissionDeliveryCity
);

        $commissionSuccess = $stmt->execute();

        if ($commissionSuccess) {
            $orderId = $conn->insert_id;

            // Insert bridge row in commission_requests (order_id + artist_id)
            if ($requestedArtistId) {
                $cr = $conn->prepare("INSERT INTO commission_requests (order_id, artist_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                $cr->bind_param("ii", $orderId, $requestedArtistId);
            } else {
                $cr = $conn->prepare("INSERT INTO commission_requests (order_id, artist_id, created_at, updated_at) VALUES (?, NULL, NOW(), NOW())");
                $cr->bind_param("i", $orderId);
            }
            $cr->execute();

            // Log initial status in order_status_history
            $changedByRole = isset($_SESSION['user_id']) ? 'buyer' : 'system';
            $changedById   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 'NULL';
            $conn->query("
                INSERT INTO order_status_history (order_id, status_from, status_to, notes, changed_by_role, changed_by_id, created_at)
                VALUES ($orderId, NULL, 'pending', 'Commission request submitted', '$changedByRole', $changedById, NOW())
            ");

            // Redirect to buyer account page with confirmation flag
            if (isset($_SESSION['user_id'])) {
    header("Location: dashboard/buyer/account.php?commission_submitted=1");
} else {
    header("Location: commission.php?submitted=1");
}
exit;
        } else {
            $commissionError = "Failed to submit. Please try again.";
        }
    }
}

function getProfileImageUrl($p) {
    if (!$p) return null;
    $p = ltrim($p, './');
    if (strpos($p, 'uploads/') !== false) return $p;
    return 'uploads/profiles/' . $p;
}

// Fetch all categories for the artwork type dropdown
 $artCategories = $conn->query("SELECT id, name, slug FROM categories ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Fetch available artists for the dropdown
 $availableArtists = $conn->query("
    SELECT u.id, u.name, u.profile_picture, ap.city, ap.art_style, ap.accepts_commissions 
    FROM users u 
    JOIN artist_profiles ap ON u.id = ap.user_id 
    WHERE u.role = 'artist' AND u.status = 'active' AND ap.accepts_commissions = 1 
    ORDER BY u.name ASC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Commission Custom Artwork — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
  --bg:#F6EDDE;
  --card:#F6EDDE;
  --sand:#DDCDAE;
  --border:#0C3F30;
  --ink:#0C3F30;
  --body:#0C3F30;
  --muted:#0C3F30;
  --light:#0C3F30;
  --w:1280px; --r:10px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.55;}
a{text-decoration:none;color:inherit;}
img{max-width:100%;display:block;}

/* NAV */
.nav{background:var(--ink);border-bottom:1px solid var(--ink);position:sticky;top:0;z-index:200;}
.nw{max-width:var(--w);margin:0 auto;padding:0 28px;height:58px;display:flex;align-items:center;gap:16px;}
.nlogo{flex-shrink:0;display:flex;flex-direction:column;line-height:1;margin-right:4px;}
.nlogo b{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--bg);}
.nlogo small{font-size:7.5px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);margin-top:1px;}
.nlinks{display:flex;align-items:center;gap:1px;flex:1;}
.nlinks a{font-size:12.5px;color:var(--bg);padding:6px 10px;border-radius:6px;transition:background .12s;}
.nlinks a:hover,.nlinks a.active{background:var(--sand);color:var(--ink);}
.nsearch{display:flex;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--sand);border-radius:6px;padding:6px 12px;width:210px;flex-shrink:0;}
.nsearch input{border:none;background:transparent;font-size:12.5px;font-family:'DM Sans',sans-serif;color:var(--ink);outline:none;width:100%;}
.nsearch input::placeholder{color:var(--ink);opacity:0.6;}
.nsearch svg{color:var(--ink);opacity:0.6;flex-shrink:0;}
.nend{display:flex;align-items:center;gap:8px;flex-shrink:0;position:relative;margin-left:auto;}
.btn-ghost{font-size:12.5px;color:var(--bg);padding:7px 14px;border-radius:6px;border:1px solid var(--bg);background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.btn-ghost:hover{border-color:var(--sand);background:var(--sand);color:var(--ink);}
.btn-dark{font-size:12.5px;color:var(--ink);padding:7px 16px;border-radius:6px;border:none;background:var(--sand);cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:500;transition:background .12s;}
.btn-dark:hover{background:#c4b69e;}

/* ─── MOBILE HAMBURGER & DRAWER GLOBAL STYLES ─── */
#nav-drawer{display:none;}
#nav-overlay{display:none;}
.ham-btn{display:none;}

/* HERO */
.hero{background:var(--ink);padding:52px 28px;}
.hero-inner{max-width:var(--w);margin:0 auto;}
.hero-tag{font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:8px;}
.hero h1{font-family:'Playfair Display',serif;font-size:clamp(32px,3.5vw,44px);font-weight:400;color:var(--bg);line-height:1.15;}
.hero h1 em{font-style:italic;color:var(--sand);}
.hero-desc{font-size:14px;color:rgba(246,237,222,.5);max-width:560px;margin-top:12px;}

/* MAIN LAYOUT */
.main{max-width:var(--w);margin:0 auto;padding:28px;display:grid;grid-template-columns:1fr 0.9fr;gap:48px;}

/* LEFT: HOW IT WORKS */
.process-section{margin-bottom:32px;}
.process-title{font-family:'Playfair Display',serif;font-size:20px;font-weight:500;color:var(--ink);margin-bottom:20px;border-left:3px solid var(--sand);padding-left:14px;}
.process-step{display:flex;gap:14px;margin-bottom:24px;}
.step-num{width:36px;height:36px;background:var(--sand);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:600;color:var(--ink);flex-shrink:0;}
.step-content h4{font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;}
.step-content p{font-size:12.5px;color:var(--muted);line-height:1.6;}
.info-box{background:var(--sand);border-radius:12px;padding:20px;margin-top:28px;}
.info-box h4{font-size:13px;font-weight:600;margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.info-box p{font-size:12px;color:var(--body);line-height:1.65;}
.info-box ul{margin-top:8px;padding-left:20px;}
.info-box li{font-size:12px;color:var(--muted);margin-bottom:4px;}

/* FORM CARD */
.form-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
.form-title{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;margin-bottom:4px;}
.form-sub{font-size:12px;color:var(--muted);margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border);}
.form-pane{padding:28px;}
.fg{margin-bottom:16px;}
.fg label{display:block;font-size:10.5px;letter-spacing:.7px;text-transform:uppercase;color:var(--body);font-weight:500;margin-bottom:6px;}
.fg label span{color:var(--sand);}
.fi,.fs,.ft{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;background:var(--bg);outline:none;transition:border-color .12s;}
.fi:focus,.fs:focus,.ft:focus{border-color:var(--ink);}
.ft{min-height:100px;resize:vertical;line-height:1.55;}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.fr3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}

/* ARTIST PICKER */
.artist-picker{position:relative;}
.ap-trigger{width:100%;display:flex;align-items:center;gap:10px;padding:8px 14px;border:1.5px solid var(--border);border-radius:8px;background:var(--bg);cursor:pointer;font-family:'DM Sans',sans-serif;font-size:13px;color:var(--ink);text-align:left;}
.ap-trigger:hover{border-color:var(--ink);}
.ap-trigger-avatar{width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;background:var(--sand);}
.ap-trigger-avatar-ph{width:32px;height:32px;border-radius:50%;background:var(--ink);color:var(--bg);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;}
.ap-trigger-text{flex:1;display:flex;flex-direction:column;gap:1px;}
.ap-trigger-name{font-weight:500;color:var(--ink);}
.ap-trigger-sub{font-size:11px;color:var(--muted);}
.ap-trigger svg{flex-shrink:0;color:var(--muted);}
.ap-list{display:none;position:absolute;top:calc(100% + 6px);left:0;right:0;background:var(--card);border:1.5px solid var(--border);border-radius:10px;max-height:320px;overflow:hidden;z-index:50;box-shadow:0 10px 28px rgba(12,63,48,.15);display:none;flex-direction:column;}
.ap-list.open{display:flex;}
.ap-search-wrap{display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1.5px solid var(--border);flex-shrink:0;}
.ap-search-wrap svg{color:var(--muted);flex-shrink:0;}
.ap-search{flex:1;border:none;background:transparent;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--ink);outline:none;}
.ap-search::placeholder{color:var(--muted);}
.ap-items{overflow-y:auto;max-height:260px;}
.ap-no-results{padding:16px 14px;text-align:center;font-size:12.5px;color:var(--muted);}
.ap-item{display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--sand);}
.ap-item:last-child{border-bottom:none;}
.ap-item:hover{background:var(--sand);}
.ap-item-avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;background:var(--sand);}
.ap-item-avatar-ph{width:36px;height:36px;border-radius:50%;background:var(--ink);color:var(--bg);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:600;flex-shrink:0;}
.ap-item-text{flex:1;display:flex;flex-direction:column;gap:1px;}
.ap-item-name{font-size:13px;font-weight:500;color:var(--ink);}
.ap-item-sub{font-size:11px;color:var(--muted);}
.ap-item-style{font-size:9.5px;background:var(--sand);padding:2px 7px;border-radius:10px;color:var(--body);white-space:nowrap;}
.msub{width:100%;background:var(--ink);color:#fff;border:none;padding:12px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;margin-top:8px;transition:background .15s;}
.msub:hover{background:var(--body);}
.mmsg{padding:12px 16px;border-radius:8px;font-size:12.5px;margin-bottom:16px;}
.mmsg.er{background:#FCEEE9;color:#7D2A14;border:1px solid #EEC5B8;}

/* FOOTER */
.footer{background:var(--ink);color:var(--bg);margin-top:56px;}
.fw{max-width:var(--w);margin:0 auto;padding:40px 28px 26px;}
.fg-foot{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:32px;}
.fb b{font-family:'Playfair Display',serif;font-size:17px;color:var(--bg);display:block;margin-bottom:7px;}
.fb p{font-size:12.5px;line-height:1.65;max-width:230px;}
.fc h4{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:11px;}
.fc a{display:block;font-size:12.5px;color:rgba(246,237,222,.42);margin-bottom:8px;transition:color .12s;}
.fc a:hover{color:var(--bg);}
.fbot{border-top:1px solid rgba(246,237,222,.07);padding-top:18px;display:flex;justify-content:space-between;font-size:11.5px;}

/* ─── RESPONSIVE ─── */

/* Tablet (max-width: 1080px) */
@media(max-width:1080px){
  .main{grid-template-columns:1fr;}
  .fg-foot{grid-template-columns:1fr 1fr;}
}

/* Mobile (max-width: 768px) */
@media(max-width:768px){
  /* Nav */
  .nlinks,.nsearch{display:none;}
  .nend .btn-ghost,.nend .btn-dark,.nend span{display:none;}
  .ham-btn{display:flex;flex-direction:column;justify-content:center;gap:5px;background:transparent;border:none;cursor:pointer;padding:6px;margin-left:auto;}
  .ham-btn span{display:block;width:22px;height:2px;background:var(--bg);border-radius:2px;}

  #nav-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:298;}
  #nav-overlay.open{display:block;}
  #nav-drawer{display:flex;flex-direction:column;position:fixed;top:0;right:0;width:75vw;max-width:300px;height:100vh;background:var(--ink);z-index:299;transform:translateX(100%);transition:transform 0.3s ease;padding:0;overflow-y:auto;}
  #nav-drawer.open{transform:translateX(0);}
  .drawer-top{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid rgba(246,237,222,0.1);}
  .drawer-logo b{font-family:'Playfair Display',serif;font-size:16px;color:var(--bg);display:block;}
  .drawer-logo small{font-size:7px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);}
  .drawer-close{background:transparent;border:none;color:var(--bg);font-size:18px;cursor:pointer;padding:4px;}
  .drawer-links{display:flex;flex-direction:column;padding:12px 0;}
  .drawer-links a{color:var(--bg);font-size:14px;padding:13px 20px;border-bottom:1px solid rgba(246,237,222,0.07);transition:background 0.12s;}
  .drawer-links a:hover{background:rgba(246,237,222,0.06);}
  .drawer-actions{margin-top:auto;padding:20px;display:flex;flex-direction:column;gap:10px;border-top:1px solid rgba(246,237,222,0.1);}
  .drawer-btn-ghost{font-size:13px;color:var(--bg);padding:9px 14px;border-radius:6px;border:1px solid rgba(246,237,222,0.4);text-align:center;transition:all 0.12s;}
  .drawer-btn-ghost:hover{border-color:var(--sand);background:rgba(246,237,222,0.08);}
  .drawer-btn-dark{font-size:13px;color:var(--ink);padding:9px 14px;border-radius:6px;background:var(--sand);text-align:center;font-weight:500;transition:background 0.12s;}
  .drawer-btn-dark:hover{background:#c4b69e;}

  /* Layout */
  .main{padding:16px;}
  .hero{padding:32px 20px;}
  
  /* Footer */
  .fg-foot{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 16px;}
  .fc{display:none;}
  .fb{margin-bottom:12px;}
  .fb b{font-size:16px;}
  .fb p{font-size:10px;}
  .fbot{flex-direction:column;gap:12px;text-align:center;font-size:10px;padding-top:14px;}
}
</style>
</head>
<body>

<!-- NAV -->
<nav class="nav">
  <div class="nw">
    <a href="index.php" class="nlogo"><img src="logo.png" alt="Art Bazaar" style="height:36px;width:auto;display:block;"></a>
    <div class="nlinks">
      <a href="artworks.php">Explore Art</a>
      <a href="artists.php">Artists</a>
      <a href="blog.php">Blog</a>
      <a href="commission.php" class="active">Custom Artwork</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php">Contact</a>
    </div>
    <div class="nsearch">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input type="text" placeholder="Search...">
    </div>
    <div class="nend">

      <?php if ($isLoggedIn): ?>
        <span style="font-size:12.5px;color:var(--bg);">Hi, <?= htmlspecialchars($_SESSION['name'] ?? 'Buyer') ?></span>
        <a href="dashboard/buyer/account.php" class="btn-ghost">My Account</a>
        <a href="logout.php" class="btn-dark">Logout</a>
      <?php else: ?>
        <a href="login.php" class="btn-ghost">Login</a>
        <a href="register.php" class="btn-dark">Join as Artist</a>
      <?php endif; ?>
      <button class="ham-btn" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</nav>

<!-- HERO (Breadcrumb removed) -->
<section class="hero" style="padding:0;position:relative;">
  <img src="commisionhero.jpeg" alt="Commission Art" style="width:100%;height:auto;object-fit:contain;display:block;">
  <div style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(12,63,48,0.45);display:flex;align-items:flex-end;padding-bottom:40px;">
    <div class="hero-inner" style="padding:0 28px;">
      <div class="hero-tag">CUSTOM ARTWORK</div>
      <h1>Bring your vision to life<br>with a <em>custom commission</em>.</h1>
      <p class="hero-desc">Work directly with Pakistani artists to create something uniquely yours — a portrait, calligraphy piece, abstract painting, or any idea you can imagine.</p>
    </div>
  </div>
</section>

<!-- MAIN CONTENT -->
<div class="main">
  <!-- LEFT: HOW IT WORKS -->
  <div>
    <div class="process-section">
      <h3 class="process-title">How it works?</h3>
      
      <div class="process-step">
        <div class="step-num">1</div>
        <div class="step-content">
          <h4>Tell us what you're looking for</h4>
          <p>Fill out the form with your idea, budget, preferred artist (if any), and deadline. Include reference images if you have them.</p>
        </div>
      </div>
      
      <div class="process-step">
        <div class="step-num">2</div>
        <div class="step-content">
          <h4>We match you with the right artist</h4>
          <p>Our team reviews your request and connects you with an artist whose style and expertise match your vision.</p>
        </div>
      </div>
      
      <div class="process-step">
        <div class="step-num">3</div>
        <div class="step-content">
          <h4>Discuss details & confirm</h4>
          <p>You'll communicate with the artist through our platform to refine the concept, timeline, and final price.</p>
        </div>
      </div>
      
      <div class="process-step">
        <div class="step-num">4</div>
        <div class="step-content">
          <h4>Creation & delivery</h4>
          <p>The artist creates your custom piece, shares progress updates, and delivers the final artwork to your doorstep.</p>
        </div>
      </div>
    </div>
  </div>
  
  <!-- RIGHT: FORM -->
  <div class="form-card">
    <div class="form-pane">
      <!-- Updated Title & Subtitle with Safety Note -->
      <h2 class="form-title">Request a custom artwork</h2>
      <p class="form-sub">Fill out the form and we'll connect you with the perfect artist. Your details are safe with us, and all payments are handled securely through Art Bazaar.</p>
<p style="font-size:11.5px;color:var(--ink);background:var(--sand);border:1px solid var(--border);border-radius:8px;padding:10px 14px;margin-bottom:4px;line-height:1.6;">Submit your custom artwork request. The artist/platform will review the details, confirm pricing, timeline, and shipping before payment. <strong>Official payment instructions will only be shared by Art Bazaar Pakistan.</strong></p>
      
      <?php if ($commissionError): ?>
  <div class="mmsg er"><?= htmlspecialchars($commissionError) ?></div>
<?php endif; ?>
<?php if (isset($_GET['submitted'])): ?>
  <div class="mmsg" style="background:var(--sand);border:1px solid var(--border);color:var(--ink);">
    ✓ Your commission request has been submitted! We'll be in touch via email soon.
  </div>
<?php endif; ?>
      
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="commission_request">
        
        <div class="frow">
          <div class="fg">
            <label>Your Name <span>*</span></label>
            <input type="text" name="buyer_name" class="fi" placeholder="Full name" value="<?= $prefillName ?>" required>
          </div>
          <div class="fg">
            <label>Email <span>*</span></label>
            <input type="email" name="buyer_email" class="fi" placeholder="you@example.com" value="<?= $prefillEmail ?>" required>
          </div>
        </div>
        
        <div class="fg">
          <label>Phone / WhatsApp</label>
          <input type="tel" name="buyer_phone" class="fi" placeholder="+92 300 0000000" value="<?= $prefillPhone ?>">
          <!-- Added Helper Text -->
          <p style="font-size:10px;color:var(--muted);margin-top:4px;">Used for delivery updates only. Not shared with artists.</p>
        </div>
        
        <div class="fg">
          <label>Preferred Artist <span>*</span></label>
          <div class="artist-picker" id="artistPicker">
            <input type="hidden" name="requested_artist_id" id="ap-value" value="<?= htmlspecialchars($preSelectedArtistId ?? '') ?>" required>
            <button type="button" class="ap-trigger" id="apTrigger">
              <?php
                $preSelectedArtist = null;
                if ($preSelectedArtistId) {
                    foreach ($availableArtists as $a) {
                        if ($a['id'] == $preSelectedArtistId) { $preSelectedArtist = $a; break; }
                    }
                }
              ?>
              <?php if ($preSelectedArtist):
                $pAvatar = getProfileImageUrl($preSelectedArtist['profile_picture']);
              ?>
                <?php if ($pAvatar): ?>
                  <img class="ap-trigger-avatar" src="<?= htmlspecialchars($pAvatar) ?>" alt="">
                <?php else: ?>
                  <div class="ap-trigger-avatar-ph"><?= strtoupper(substr($preSelectedArtist['name'],0,1)) ?></div>
                <?php endif; ?>
                <span class="ap-trigger-text">
                  <span class="ap-trigger-name"><?= htmlspecialchars($preSelectedArtist['name']) ?></span>
                  <span class="ap-trigger-sub"><?= htmlspecialchars($preSelectedArtist['city'] ?? '') ?><?= ($preSelectedArtist['city'] && $preSelectedArtist['art_style']) ? ' — ' : '' ?><?= htmlspecialchars($preSelectedArtist['art_style'] ?? '') ?></span>
                </span>
              <?php else: ?>
                <span class="ap-trigger-text">
                  <span class="ap-trigger-name" style="color:var(--muted);font-weight:400;">— Select an artist —</span>
                </span>
              <?php endif; ?>
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="ap-list" id="apList">
              <div class="ap-search-wrap">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                <input type="text" id="apSearch" class="ap-search" placeholder="Search by name, city, or style..." autocomplete="off">
              </div>
              <div class="ap-items" id="apItems">
              <?php foreach ($availableArtists as $a):
                $avatar = getProfileImageUrl($a['profile_picture']);
              ?>
              <div class="ap-item"
                   data-id="<?= $a['id'] ?>"
                   data-name="<?= htmlspecialchars($a['name']) ?>"
                   data-city="<?= htmlspecialchars($a['city'] ?? '') ?>"
                   data-style="<?= htmlspecialchars($a['art_style'] ?? '') ?>"
                   data-avatar="<?= htmlspecialchars($avatar ?? '') ?>">
                <?php if ($avatar): ?>
                  <img class="ap-item-avatar" src="<?= htmlspecialchars($avatar) ?>" alt="">
                <?php else: ?>
                  <div class="ap-item-avatar-ph"><?= strtoupper(substr($a['name'],0,1)) ?></div>
                <?php endif; ?>
                <span class="ap-item-text">
                  <span class="ap-item-name"><?= htmlspecialchars($a['name']) ?></span>
                  <?php if ($a['city']): ?><span class="ap-item-sub">📍 <?= htmlspecialchars($a['city']) ?></span><?php endif; ?>
                </span>
                <?php if ($a['art_style']): ?><span class="ap-item-style"><?= htmlspecialchars($a['art_style']) ?></span><?php endif; ?>
              </div>
              <?php endforeach; ?>
              <div class="ap-no-results" id="apNoResults" style="display:none;">No artists match your search.</div>
              </div>
            </div>
          </div>
        </div>
        
        <div class="fr3">
          <div class="fg">
            <label>Artwork Type</label>
            <select name="artwork_type" class="fs">
              <option value="">Select type...</option>
              <?php foreach ($artCategories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>Budget Min (PKR)</label>
            <input type="number" name="budget_min" class="fi" placeholder="5000">
          </div>
          <div class="fg">
            <label>Budget Max (PKR)</label>
            <input type="number" name="budget_max" class="fi" placeholder="15000">
          </div>
        </div>
        <!-- Added Helper Text -->
        <p style="font-size:10px;color:var(--muted);margin-top:-8px;margin-bottom:16px;">Setting a realistic budget helps us match you with the right artist faster.</p>

        <div class="fg">
          <label>Preferred Deadline</label>
          <input type="date" name="deadline" class="fi">
        </div>
        
        <!-- ADDED NEW FIELDS -->
        <div class="frow">
            <div class="fg">
                <label>Artwork Size</label>
                <input type="text" name="commission_size" class="fi" placeholder="e.g. 18 x 24 inches">
            </div>
            <div class="fg">
                <label>Framed / Unframed</label>
                <select name="commission_framed" class="fs">
                    <option value="unframed">Unframed (Canvas/Paper)</option>
                    <option value="framed_basic">Framed (Basic)</option>
                    <option value="framed_premium">Framed (Premium)</option>
                    <option value="stretched_canvas">Stretched Canvas (No Frame)</option>
                </select>
            </div>
        </div>

        <div class="frow">
            <div class="fg">
                <label>Quantity</label>
                <input type="number" name="commission_quantity" class="fi" value="1" min="1">
            </div>
            <div class="fg">
                <label>Delivery City</label>
                <input type="text" name="commission_delivery_city" class="fi" placeholder="e.g. Lahore">
            </div>
        </div>
        
        <div class="fg">
          <label>Describe Your Request <span>*</span></label>
          <textarea name="description" class="ft" placeholder="Tell us what you want — subject, colors, size, style preferences, any specific details that will help the artist understand your vision..." required></textarea>
        </div>
        
        <div class="fg">
          <label>Reference Image <span style="font-size:10px;color:var(--muted);font-weight:400;">(optional, max 10MB)</span></label>
          <input type="file" name="reference_image" class="fi" accept="image/jpeg,image/png,image/webp,image/gif">
          <p style="font-size:10px;color:var(--muted);margin-top:4px;">Upload a reference image to help the artist understand your idea better.</p>
        </div>
        
        <button type="submit" class="msub">Submit Commission Request</button>
      </form>
    </div>
  </div>
</div>

<!-- FOOTER -->
<footer class="footer">
  <div class="fw">
    <div class="fg-foot">
      <div class="fb"><b>Art Bazaar</b><p>Pakistan's premier marketplace for original art. Connecting talented Pakistani artists with art lovers across the country.</p></div>
      <div class="fc"><h4>Explore</h4><a href="artworks.php">All Artworks</a><a href="artists.php">All Artists</a><a href="artworks.php?featured=1">Featured</a></div>
      <div class="fc"><h4>For Artists</h4><a href="sell.php">How to Sell</a><a href="register.php">Join as Artist</a><a href="login.php">Artist Login</a></div>
      <div class="fc"><h4>Company</h4><a href="about.php">About Us</a><a href="contact.php">Contact</a><a href="commission.php">Custom Artwork</a><a href="terms.php">Terms & Conditions</a><a href="privacy-policy.php">Privacy & Policies</a></div>
    </div>
    <div class="fbot"><span>© <?= date('Y') ?> Art Bazaar. Supporting Pakistani artists.</span><span>Made with care in Pakistan 🇵🇰</span></div>
  </div>
</footer>

<!-- DRAWER & OVERLAY -->
<div id="nav-overlay"></div>
<div id="nav-drawer">
  <div class="drawer-top">
    <div class="drawer-logo"><img src="logo.png" alt="Art Bazaar" style="height:36px;width:auto;display:block;"></div>
    <button class="drawer-close" aria-label="Close menu">✕</button>
  </div>
  <div class="drawer-links">
    <a href="artworks.php">Explore Art</a>
    <a href="artists.php">Artists</a>
    <a href="blog.php">Blog</a>
    <a href="commission.php" class="active">Custom Artwork</a>
    <a href="sell.php">Sell Your Art</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact</a>
  </div>
  <div class="drawer-actions">
    <?php if ($isLoggedIn): ?>
      <a href="dashboard/buyer/account.php" class="drawer-btn-ghost">My Account</a>
      <a href="logout.php" class="drawer-btn-dark">Logout</a>
    <?php else: ?>
      <a href="login.php" class="drawer-btn-ghost">Login</a>
      <a href="register.php" class="drawer-btn-dark">Join as Artist</a>
    <?php endif; ?>
  </div>
</div>

<script>
// Artist picker dropdown
const apTrigger = document.getElementById('apTrigger');
const apList = document.getElementById('apList');
const apValue = document.getElementById('ap-value');

if (apTrigger) {
  const apSearch = document.getElementById('apSearch');
  const apNoResults = document.getElementById('apNoResults');
  const apItemEls = Array.from(apList.querySelectorAll('.ap-item'));

  apTrigger.addEventListener('click', (e) => {
    e.stopPropagation();
    apList.classList.toggle('open');
    if (apList.classList.contains('open')) {
      apSearch.value = '';
      apItemEls.forEach(el => el.style.display = 'flex');
      apNoResults.style.display = 'none';
      setTimeout(() => apSearch.focus(), 0);
    }
  });

  apSearch.addEventListener('click', (e) => e.stopPropagation());
  apSearch.addEventListener('input', () => {
    const q = apSearch.value.trim().toLowerCase();
    let anyVisible = false;
    apItemEls.forEach(item => {
      const haystack = (item.dataset.name + ' ' + item.dataset.city + ' ' + item.dataset.style).toLowerCase();
      const match = haystack.includes(q);
      item.style.display = match ? 'flex' : 'none';
      if (match) anyVisible = true;
    });
    apNoResults.style.display = anyVisible ? 'none' : 'block';
  });

  apItemEls.forEach(item => {
    item.addEventListener('click', () => {
      const id = item.dataset.id;
      const name = item.dataset.name;
      const city = item.dataset.city;
      const style = item.dataset.style;
      const avatar = item.dataset.avatar;

      apValue.value = id;

      let avatarHtml = avatar
        ? `<img class="ap-trigger-avatar" src="${avatar}" alt="">`
        : `<div class="ap-trigger-avatar-ph">${name.charAt(0).toUpperCase()}</div>`;

      let subText = [city, style].filter(Boolean).join(' — ');

      apTrigger.innerHTML = `
        ${avatarHtml}
        <span class="ap-trigger-text">
          <span class="ap-trigger-name">${name}</span>
          <span class="ap-trigger-sub">${subText}</span>
        </span>
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
      `;

      apList.classList.remove('open');
    });
  });

  document.addEventListener('click', (e) => {
    if (!document.getElementById('artistPicker').contains(e.target)) {
      apList.classList.remove('open');
    }
  });
}

// Hamburger drawer
const hamBtn = document.querySelector('.ham-btn');
const navDrawer = document.getElementById('nav-drawer');
const navOverlay = document.getElementById('nav-overlay');
function openDrawer(){ navDrawer.classList.add('open'); navOverlay.classList.add('open'); document.body.style.overflow='hidden'; }
function closeDrawer(){ navDrawer.classList.remove('open'); navOverlay.classList.remove('open'); document.body.style.overflow=''; }
if(hamBtn) hamBtn.addEventListener('click', openDrawer);
if(navOverlay) navOverlay.addEventListener('click', closeDrawer);
document.querySelector('.drawer-close')?.addEventListener('click', closeDrawer);
</script>
</body>
</html>