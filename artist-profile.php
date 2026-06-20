<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Check if logged in
 $isLoggedIn = isset($_SESSION['user_id']);

 $artistId = (int)($_GET['id'] ?? 0);
if (!$artistId) {
    header('Location: artists.php');
    exit;
}

// Fetch artist details
 $stmt = $conn->prepare("
    SELECT u.id, u.name, u.email, u.profile_picture, u.created_at,
           ap.bio, ap.city, ap.instagram_url, ap.art_style, ap.accepts_commissions, ap.is_featured
    FROM users u
    LEFT JOIN artist_profiles ap ON u.id = ap.user_id
    WHERE u.id = ? AND u.role = 'artist' AND u.status = 'active'
");
 $stmt->bind_param('i', $artistId);
 $stmt->execute();
 $artist = $stmt->get_result()->fetch_assoc();

if (!$artist) {
    header('Location: artists.php');
    exit;
}

// Fetch artist's artworks (approved only)
 $artworks = $conn->prepare("
    SELECT a.id, a.title, a.price, a.status, a.description, a.medium, a.size, a.created_at, c.name AS category_name,
           (SELECT image_path FROM artwork_images WHERE artwork_id = a.id ORDER BY is_cover DESC LIMIT 1) AS cover_image
    FROM artworks a
    JOIN categories c ON a.category_id = c.id
    WHERE a.artist_id = ? AND a.status IN ('approved', 'sold')
    ORDER BY a.created_at DESC
");
 $artworks->bind_param('i', $artistId);
 $artworks->execute();
 $artistArtworks = $artworks->get_result()->fetch_all(MYSQLI_ASSOC);

 $artworkCount = count($artistArtworks);
 $soldCount = 0;
 $availableCount = 0;
foreach ($artistArtworks as $aw) {
    if ($aw['status'] === 'sold') $soldCount++;
    else $availableCount++;
}

// Pre-fill user details for commission form
 $prefillName = '';
 $prefillEmail = '';
 $prefillPhone = '';
if ($isLoggedIn) {
    $uid = (int)$_SESSION['user_id'];
    $userRes = $conn->query("SELECT name, email, phone FROM users WHERE id = $uid");
    if ($userRow = $userRes->fetch_assoc()) {
        $prefillName = htmlspecialchars($userRow['name'] ?? '');
        $prefillEmail = htmlspecialchars($userRow['email'] ?? '');
        $prefillPhone = htmlspecialchars($userRow['phone'] ?? '');
    }
}

// ── Handle commission request submission ──────────────────
 $commissionError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commission_request') {
    $buyerName  = trim($_POST['buyer_name'] ?? ''); 
    $buyerEmail = trim($_POST['buyer_email'] ?? '');
    $buyerPhone = trim($_POST['buyer_phone'] ?? '');
    
    $requestedArtistId = !empty($_POST['requested_artist_id']) ? (int)$_POST['requested_artist_id'] : null;
    $artworkType = trim($_POST['artwork_type'] ?? '');
    $budgetMin   = !empty($_POST['budget_min']) ? (float)$_POST['budget_min'] : null;
    $budgetMax   = !empty($_POST['budget_max']) ? (float)$_POST['budget_max'] : null;
    $deadline    = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
    $description = trim($_POST['description'] ?? ''); 
    $referenceImage = null;

    // ── NEW FIELDS MAPPING ─────────────────────────────────────
    $commissionSize        = trim($_POST['commission_size'] ?? '');
    $commissionFramedRaw   = trim($_POST['commission_framed'] ?? '');
    $commissionQuantity    = !empty($_POST['commission_quantity']) ? (int)$_POST['commission_quantity'] : 1;
    $commissionDeliveryCity = trim($_POST['commission_delivery_city'] ?? '');

    // Map form framing values to valid DB ENUM values
    $framedMap = [
        'unframed'         => 'unframed',
        'framed_basic'     => 'framed',
        'framed_premium'   => 'framed',
        'stretched_canvas' => 'unframed',
    ];
    $commissionFramed = $framedMap[$commissionFramedRaw] ?? 'not_specified';
    
    // Handle reference image upload (Increased size to 10MB)
    if (isset($_FILES['reference_image']) && $_FILES['reference_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['reference_image']; 
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $maxSize = 10 * 1024 * 1024; // 10MB
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if ($file['size'] <= $maxSize && in_array($ext, $allowedExt)) {
            $dir = __DIR__ . '/uploads/commissions/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fn = 'ref_'.time().'_'.rand(1000,9999).'.'.$ext;
            if (move_uploaded_file($file['tmp_name'], $dir.$fn)) $referenceImage = $fn;
        }
    }
    
    if (!$buyerName || !$buyerEmail || !$description) { 
        $commissionError = "Name, email, and description are required."; 
    } else {
        // Map artwork_type to a category slug → commission_category_id
        $commissionCategoryId = null;
        if (!empty($artworkType)) {
            $slugMap = [
                'painting'     => 'painting',
                'portrait'     => 'portrait',
                'digital_art'  => 'digital-art',
                'calligraphy'  => 'calligraphy',
                'abstract'     => 'custom-orders',
                'landscape'    => 'custom-orders',
                'other'        => 'custom-orders'
            ];
            $slug = $slugMap[strtolower($artworkType)] ?? 'custom-orders';
            $catSlug = $conn->real_escape_string($slug);
            $catRes = $conn->query("SELECT id FROM categories WHERE slug = '$catSlug' LIMIT 1");
            if ($catRow = $catRes->fetch_assoc()) {
                $commissionCategoryId = (int)$catRow['id'];
            }
        }

        // Determine buyer_id: logged-in user or NULL for guest
        $buyerId = ($isLoggedIn) ? (int)$_SESSION['user_id'] : null;

        // Generate unique order number
        $orderNumber = 'COM-' . time() . '-' . rand(1000, 9999);

        // Price placeholder = budget_min or 0 (will be confirmed/agreed later)
        $subtotal = $budgetMin ?? 0;
        $total    = $subtotal;

        // Insert into orders with order_type='commission'
        // NOTE: Added commission_size, commission_framed, commission_quantity, commission_delivery_city
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
            $newOrderId = $conn->insert_id;

            // Insert bridge row in commission_requests (order_id + artist_id)
            if ($requestedArtistId) {
                $cr = $conn->prepare("INSERT INTO commission_requests (order_id, artist_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                $cr->bind_param("ii", $newOrderId, $requestedArtistId);
            } else {
                $cr = $conn->prepare("INSERT INTO commission_requests (order_id, artist_id, created_at, updated_at) VALUES (?, NULL, NOW(), NOW())");
                $cr->bind_param("i", $newOrderId);
            }
            $cr->execute();

            // Log initial status in order_status_history
            $changedByRole = $isLoggedIn ? 'buyer' : 'system';
            $changedById   = $isLoggedIn ? (int)$_SESSION['user_id'] : 'NULL';
            $conn->query("
                INSERT INTO order_status_history (order_id, status_from, status_to, notes, changed_by_role, changed_by_id, created_at)
                VALUES ($newOrderId, NULL, 'pending', 'Commission request submitted', '$changedByRole', $changedById, NOW())
            ");

            // Redirect to buyer account page with confirmation flag
            if ($isLoggedIn) {
    header('Location: dashboard/buyer/account.php?commission_submitted=1');
} else {
    header('Location: artist-profile.php?id=' . $artistId . '&submitted=1');
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

function getArtworkImageUrl($p) {
    if (!$p) return null;
    $p = ltrim($p, './');
    if (strpos($p, 'uploads/') !== false) return $p;
    return 'uploads/artworks/' . $p;
}

 $profilePic = getProfileImageUrl($artist['profile_picture']);
 $joinDate = date('F Y', strtotime($artist['created_at']));
 $instagram = $artist['instagram_url'] ?: null;

// Fetch available artists for commission modal dropdown
 $availableArtists = $conn->query("SELECT u.id, u.name, ap.city, ap.art_style FROM users u JOIN artist_profiles ap ON u.id=ap.user_id WHERE u.role='artist' AND u.status='active' AND ap.accepts_commissions=1 ORDER BY u.name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($artist['name']) ?> — Art Bazaar</title>
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
#nav-drawer { display:none; }
#nav-overlay { display:none; }
.ham-btn { display:none; }

/* BREADCRUMB */
.breadcrumb{max-width:var(--w);margin:0 auto;padding:20px 28px 0;font-size:12px;color:var(--muted);}
.breadcrumb a{color:var(--body);}
.breadcrumb a:hover{color:var(--ink);}

/* PROFILE HEADER */
.profile-header{max-width:var(--w);margin:0 auto;padding:28px;display:flex;gap:32px;flex-wrap:wrap;}
.profile-avatar{flex-shrink:0;}
.profile-avatar img{width:140px;height:140px;border-radius:50%;object-fit:cover;border:3px solid var(--card);box-shadow:0 4px 12px rgba(0,0,0,.08);}
.avatar-placeholder{width:140px;height:140px;border-radius:50%;background:var(--ink);display:flex;align-items:center;justify-content:center;font-size:56px;color:var(--bg);font-weight:500;}
.profile-info{flex:1;}
.profile-name{font-family:'Playfair Display',serif;font-size:clamp(28px,3vw,38px);font-weight:500;color:var(--ink);margin-bottom:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.feat-star{background:var(--sand);color:var(--ink);font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;}
.profile-meta{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;}
.meta-item{display:flex;align-items:center;gap:5px;font-size:13px;color:var(--muted);}
.meta-item svg{color:var(--light);}
.profile-bio{color:var(--body);line-height:1.7;margin-bottom:20px;max-width:600px;}
.profile-stats{display:flex;gap:24px;margin-bottom:20px;}
.stat{text-align:center;}
.stat-num{font-family:'Playfair Display',serif;font-size:28px;font-weight:500;color:var(--ink);}
.stat-label{font-size:11px;color:var(--muted);letter-spacing:.5px;}
.social-link{display:inline-flex;align-items:center;gap:6px;background:var(--sand);padding:6px 12px;border-radius:20px;font-size:12px;color:var(--body);margin-top:8px;}
.social-link:hover{background:var(--border);color:var(--ink);}

/* COMMISSION BUTTON */
.comm-btn{background:var(--ink);color:var(--bg);border:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .15s;margin-top:8px;display:inline-flex;align-items:center;gap:8px;}
.comm-btn:hover{background:var(--body);}
.comm-btn.disabled{background:var(--muted);cursor:default;}
.comm-btn svg{width:18px;height:18px;}

/* SECTION HEADER */
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.section-title{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;color:var(--ink);}
.section-link{font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);padding-bottom:2px;}

/* ARTWORK GRID */
.artwork-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px;}
.artwork-card{background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;cursor:pointer;transition:transform .15s,box-shadow .15s;}
.artwork-card:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(12,63,48,.09);}
.artwork-img{aspect-ratio:1;overflow:hidden;background:var(--sand);position:relative;}
.artwork-img img{width:100%;height:100%;object-fit:cover;transition:transform .3s;}
.artwork-card:hover .artwork-img img{transform:scale(1.03);}
.sold-badge{position:absolute;top:8px;right:8px;background:rgba(12,63,48,.8);color:var(--bg);font-size:9px;padding:3px 8px;border-radius:4px;letter-spacing:.5px;}
.artwork-info{padding:12px;}
.artwork-title{font-size:14px;font-weight:500;color:var(--ink);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.artwork-price{font-size:13px;font-weight:600;color:var(--ink);margin-top:6px;}
.artwork-price small{font-size:9px;font-weight:400;color:var(--muted);}
.artwork-cat{font-size:10px;color:var(--muted);margin-top:4px;}

/* EMPTY */
.empty-artworks{text-align:center;padding:48px;color:var(--muted);background:var(--sand);border-radius:12px;}
.empty-artworks svg{margin-bottom:12px;opacity:.3;}

/* ─── MODALS ─── */
.mbg{display:none;position:fixed;inset:0;background:rgba(12,63,48,.58);backdrop-filter:blur(3px);z-index:500;align-items:center;justify-content:center;padding:16px;}
.mbg.open{display:flex;}
.modal{background:var(--card);border-radius:14px;width:100%;max-width:500px;max-height:92vh;overflow-y:auto;}
.mhd{padding:22px 24px 0;display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px;}
.mhd h3{font-family:'Playfair Display',serif;font-size:21px;font-weight:400;color:var(--ink);}
.mcls{background:var(--sand);border:none;border-radius:6px;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--ink);flex-shrink:0;}
.mcls:hover{background:var(--border);}
.mbd{padding:14px 24px 24px;}
.fg{margin-bottom:12px;}
.fg label{display:block;font-size:10.5px;letter-spacing:.7px;text-transform:uppercase;color:var(--body);font-weight:500;margin-bottom:5px;}
.fg label span{color:var(--ink);}
.fi,.fs,.ft{width:100%;padding:9px 12px;border:1.5px solid var(--sand);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--ink);background:var(--bg);outline:none;transition:border-color .12s;}
.fi:focus,.fs:focus,.ft:focus{border-color:var(--ink);}
.ft{min-height:86px;resize:vertical;line-height:1.55;}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.fr3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.msub{width:100%;background:var(--ink);color:var(--bg);border:none;padding:11px;border-radius:8px;font-size:13px;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;margin-top:2px;transition:background .12s;}
.msub:hover{background:var(--body);}
.mmsg{padding:9px 12px;border-radius:7px;font-size:12px;margin-bottom:12px;}
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
.fbot{border-top:1px solid rgba(246,237,222,.07);padding-top:18px;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;}

/* ─── RESPONSIVE ─── */

/* Tablet (max-width: 1080px) */
@media(max-width:1080px){
  .fg-foot{grid-template-columns:1fr 1fr;}
}

/* Mobile (max-width: 768px) */
@media(max-width:768px){
  /* Nav */
  .nlinks,.nsearch{display:none;}
  .nend .btn-ghost, .nend .btn-dark, .nend span { display:none; }
  
  /* Hamburger */
  .ham-btn { display:flex; flex-direction:column; justify-content:center; gap:5px; background:transparent; border:none; cursor:pointer; padding:6px; margin-left:auto;}
  .ham-btn span { display:block; width:22px; height:2px; background:var(--bg); border-radius:2px; }

  #nav-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:298; }
  #nav-overlay.open { display:block; }

  #nav-drawer { display:flex; flex-direction:column; position:fixed; top:0; right:0; width:75vw; max-width:300px; height:100vh; background:var(--ink); z-index:299; transform:translateX(100%); transition:transform 0.3s ease; padding:0; overflow-y:auto; }
  #nav-drawer.open { transform:translateX(0); }
  
  .drawer-top { display:flex; align-items:center; justify-content:space-between; padding:18px 20px; border-bottom:1px solid rgba(246,237,222,0.1); }
  .drawer-logo b { font-family:'Playfair Display',serif; font-size:16px; color:var(--bg); display:block; }
  .drawer-logo small { font-size:7px; letter-spacing:2px; text-transform:uppercase; color:var(--sand); }
  .drawer-close { background:transparent; border:none; color:var(--bg); font-size:18px; cursor:pointer; padding:4px; }
  
  .drawer-links { display:flex; flex-direction:column; padding:12px 0; }
  .drawer-links a { color:var(--bg); font-size:14px; padding:13px 20px; border-bottom:1px solid rgba(246,237,222,0.07); transition:background 0.12s; }
  .drawer-links a:hover { background:rgba(246,237,222,0.06); }
  
  .drawer-actions { margin-top:auto; padding:20px; display:flex; flex-direction:column; gap:10px; border-top:1px solid rgba(246,237,222,0.1); }
  .drawer-btn-ghost { font-size:13px; color:var(--bg); padding:9px 14px; border-radius:6px; border:1px solid rgba(246,237,222,0.4); text-align:center; transition:all 0.12s; }
  .drawer-btn-ghost:hover { border-color:var(--sand); background:rgba(246,237,222,0.08); }
  .drawer-btn-dark { font-size:13px; color:var(--ink); padding:9px 14px; border-radius:6px; background:var(--sand); text-align:center; font-weight:500; transition:background 0.12s; }
  .drawer-btn-dark:hover { background:#c4b69e; }

  /* Layout */
  .profile-header{flex-direction:column;align-items:center;text-align:center;}
  .profile-meta{justify-content:center;}
  .profile-stats{justify-content:center;}
  .artwork-grid{grid-template-columns:repeat(2,1fr);}
  
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
      <a href="artists.php" class="active">Artists</a>
      <a href="commission.php">Commission Art</a>
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

<!-- BREADCRUMB -->
<div class="breadcrumb">
  <a href="index.php">Home</a> <span>/</span>
  <a href="artists.php">Artists</a> <span>/</span>
  <span><?= htmlspecialchars($artist['name']) ?></span>
</div>

<!-- PROFILE HEADER -->
<div class="profile-header">
  <div class="profile-avatar">
    <?php if ($profilePic): ?>
      <img src="<?= htmlspecialchars($profilePic) ?>" alt="<?= htmlspecialchars($artist['name']) ?>">
    <?php else: ?>
      <div class="avatar-placeholder"><?= strtoupper(substr($artist['name'], 0, 1)) ?></div>
    <?php endif; ?>
  </div>
  <div class="profile-info">
    <div class="profile-name">
      <?= htmlspecialchars($artist['name']) ?>
      <?php if ($artist['is_featured']): ?><span class="feat-star">★ Featured Artist</span><?php endif; ?>
    </div>
    <div class="profile-meta">
      <?php if ($artist['city']): ?>
        <span class="meta-item"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg><?= htmlspecialchars($artist['city']) ?></span>
      <?php endif; ?>
      <span class="meta-item"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Joined <?= $joinDate ?></span>
      <?php if ($artist['art_style']): ?>
        <span class="meta-item"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2h9a1 1 0 011 1v7"/><path d="M12 2l9 9"/><rect x="3" y="9" width="9" height="12" rx="1"/><rect x="12" y="3" width="9" height="9" rx="1"/></svg><?= htmlspecialchars($artist['art_style']) ?></span>
      <?php endif; ?>
    </div>
    <?php if ($artist['bio']): ?>
      <div class="profile-bio"><?= nl2br(htmlspecialchars($artist['bio'])) ?></div>
    <?php endif; ?>
    <div class="profile-stats">
      <div class="stat"><div class="stat-num"><?= $artworkCount ?></div><div class="stat-label">Artworks</div></div>
      <div class="stat"><div class="stat-num"><?= $availableCount ?></div><div class="stat-label">Available</div></div>
      <div class="stat"><div class="stat-num"><?= $soldCount ?></div><div class="stat-label">Sold</div></div>
    </div>
      <?php if ($artist['accepts_commissions']): ?>
        <button class="comm-btn" onclick="openCM(<?= $artist['id'] ?>, '<?= addslashes($artist['name']) ?>')">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
          Request Commission
        </button>
      <?php else: ?>
        <button class="comm-btn disabled" disabled>Commissions Currently Closed</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ARTWORKS SECTION -->
<div style="max-width:var(--w);margin:0 auto;padding:0 28px 28px;">
  <div class="section-header">
    <h2 class="section-title">Artworks by <?= htmlspecialchars($artist['name']) ?></h2>
    <?php if ($artworkCount > 6): ?>
      <a href="artworks.php?artist=<?= $artist['id'] ?>" class="section-link">View all →</a>
    <?php endif; ?>
  </div>

  <?php if (empty($artistArtworks)): ?>
    <div class="empty-artworks">
      <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      <p>No artworks uploaded yet.</p>
    </div>
  <?php else: ?>
    <div class="artwork-grid">
      <?php foreach ($artistArtworks as $aw): 
        $img = getArtworkImageUrl($aw['cover_image']);
      ?>
        <div class="artwork-card" onclick="location.href='artwork-detail.php?id=<?= $aw['id'] ?>'">
          <div class="artwork-img">
            <?php if ($img): ?>
              <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($aw['title']) ?>" loading="lazy">
            <?php else: ?>
              <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--muted);">No image</div>
            <?php endif; ?>
            <?php if ($aw['status'] === 'sold'): ?>
              <span class="sold-badge">SOLD</span>
            <?php endif; ?>
          </div>
          <div class="artwork-info">
            <div class="artwork-title"><?= htmlspecialchars($aw['title']) ?></div>
            <div class="artwork-cat"><?= htmlspecialchars($aw['category_name']) ?></div>
            <div class="artwork-price"><small>PKR</small> <?= number_format($aw['price']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- COMMISSION MODAL -->
<div class="mbg" id="cm">
  <div class="modal">
    <div class="mhd">
        <h3>Request Custom Artwork</h3>
        <button class="mcls" onclick="document.getElementById('cm').classList.remove('open')">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
    </div>
    <div class="mbd">
      <p style="font-size:11px;color:var(--ink);background:var(--sand);border:1px solid var(--border);border-radius:8px;padding:10px 14px;margin-bottom:12px;line-height:1.6;">Submit your custom artwork request. The artist/platform will review the details, confirm pricing, timeline, and shipping before payment. <strong>Official payment instructions will only be shared by Art Bazaar Pakistan.</strong></p>
      <?php if ($commissionError): ?><div class="mmsg er"><?= htmlspecialchars($commissionError) ?></div><?php endif; ?>
<?php if (isset($_GET['submitted'])): ?><div class="mmsg" style="background:var(--sand);border:1px solid var(--border);color:var(--ink);">✓ Commission request submitted! We'll be in touch via email soon.</div><?php endif; ?>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="commission_request">
        
        <!-- User Info -->
        <div class="frow">
          <div class="fg"><label>Your Name <span>*</span></label><input type="text" name="buyer_name" class="fi" placeholder="Full name" value="<?= $prefillName ?>" required></div>
          <div class="fg"><label>Email <span>*</span></label><input type="email" name="buyer_email" class="fi" placeholder="you@example.com" value="<?= $prefillEmail ?>" required></div>
        </div>
        <div class="fg">
            <label>Phone / WhatsApp</label>
            <input type="tel" name="buyer_phone" class="fi" placeholder="+92 300 0000000" value="<?= $prefillPhone ?>">
            <p style="font-size:10px;color:var(--muted);margin-top:4px;">Used for delivery updates only.</p>
        </div>
        
        <!-- Artist Selection -->
        <div class="fg">
            <label>Preferred Artist <span style="font-size:10px;color:var(--muted);font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
            <select name="requested_artist_id" class="fs" id="cm-artist">
                <option value="">— Any artist (we'll find the best match) —</option>
                <?php foreach ($availableArtists as $a): ?>
                <option value="<?= $a['id'] ?>">
                    <?= htmlspecialchars($a['name']) ?>
                    <?php if ($a['city']): ?> (<?= htmlspecialchars($a['city']) ?>)<?php endif; ?>
                    <?php if ($a['art_style']): ?> — <?= htmlspecialchars($a['art_style']) ?><?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Artwork Details -->
        <div class="frow">
            <div class="fg">
                <label>Artwork Type</label>
                <select name="artwork_type" class="fs">
                    <option value="">Select type...</option>
                    <option value="painting">Painting</option>
                    <option value="portrait">Portrait</option>
                    <option value="digital_art">Digital Art</option>
                    <option value="calligraphy">Calligraphy</option>
                    <option value="abstract">Abstract</option>
                    <option value="landscape">Landscape</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="fg">
                <label>Preferred Deadline</label>
                <input type="date" name="deadline" class="fi">
            </div>
        </div>
        
        <!-- Budget -->
        <div class="frow">
            <div class="fg"><label>Budget Min (PKR)</label><input type="number" name="budget_min" class="fi" placeholder="5000"></div>
            <div class="fg"><label>Budget Max (PKR)</label><input type="number" name="budget_max" class="fi" placeholder="15000"></div>
        </div>
        <p style="font-size:10px;color:var(--muted);margin-top:-8px;margin-bottom:12px;">Realistic budget helps us match you faster.</p>

        <!-- NEW FIELDS BLOCK -->
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
        
        <!-- Description -->
        <div class="fg">
            <label>Description <span>*</span></label>
            <textarea name="description" class="ft" placeholder="Describe what you want in detail..." required></textarea>
        </div>
        
        <!-- Reference Image -->
        <div class="fg">
            <label>Reference Image <span style="color:var(--ink);font-size:9px;text-transform:none;letter-spacing:0">(optional, max 10MB)</span></label>
            <input type="file" name="reference_image" class="fi" accept="image/jpeg,image/png,image/webp,image/gif" style="padding:7px 12px;">
        </div>
        
        <button type="submit" class="msub">Submit Commission Request</button>
      </form>
    </div>
  </div>
</div>

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
    <a href="commission.php">Commission Art</a>
    <a href="sell.php">Sell Your Art</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact</a>
  </div>
  <div class="drawer-actions"> 
    <?php if ($isLoggedIn): ?>
      <a href="dashboard/buyer/account.php" class="btn-ghost">My Account</a>
      <a href="logout.php" class="drawer-btn-dark">Logout</a>
    <?php else: ?>
      <a href="login.php" class="drawer-btn-ghost">Login</a>
      <a href="register.php" class="drawer-btn-dark">Join as Artist</a>
    <?php endif; ?>
  </div>
</div>

<!-- FOOTER -->
<footer class="footer">
  <div class="fw">
    <div class="fg-foot">
      <div class="fb"><b>Art Bazaar</b><p>Pakistan's premier marketplace for original art. Connecting talented Pakistani artists with art lovers across the country.</p></div>
      <div class="fc"><h4>Explore</h4><a href="artworks.php">All Artworks</a><a href="artists.php">All Artists</a><a href="artworks.php?featured=1">Featured</a></div>
      <div class="fc"><h4>For Artists</h4><a href="sell.php">How to Sell</a><a href="register.php">Join as Artist</a><a href="login.php">Artist Login</a></div>
      <div class="fc"><h4>Company</h4><a href="about.php">About Us</a><a href="contact.php">Contact</a><a href="commission.php">Commissions</a><a href="terms.php">Terms & Conditions</a><a href="privacy-policy.php">Privacy & Policies</a></div>
    </div>
    <div class="fbot"><span>© <?= date('Y') ?> Art Bazaar. Supporting Pakistani artists.</span><span>Made with care in Pakistan 🇵🇰</span></div>
  </div>
</footer>

<script>
// Hamburger drawer
const hamBtn = document.querySelector('.ham-btn');
const navDrawer = document.getElementById('nav-drawer');
const navOverlay = document.getElementById('nav-overlay');
function openDrawer(){ navDrawer.classList.add('open'); navOverlay.classList.add('open'); document.body.style.overflow='hidden'; }
function closeDrawer(){ navDrawer.classList.remove('open'); navOverlay.classList.remove('open'); document.body.style.overflow=''; }
if(hamBtn) hamBtn.addEventListener('click', openDrawer);
if(navOverlay) navOverlay.addEventListener('click', closeDrawer);
document.querySelector('.drawer-close')?.addEventListener('click', closeDrawer);

function openCM(id, name) {
  const s = document.getElementById('cm-artist');
  if (s && id) s.value = id;
  document.getElementById('cm').classList.add('open');
}

// Close modal on backdrop click
document.querySelectorAll('.mbg').forEach(b => b.addEventListener('click', e => {
  if (e.target === b) b.classList.remove('open');
}));

<?php if ($commissionError || isset($_GET['submitted'])): ?>document.getElementById('cm').classList.add('open');<?php endif; ?>
</script>
</body>
</html>