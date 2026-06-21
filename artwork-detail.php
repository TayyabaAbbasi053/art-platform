<?php
session_start();
require_once __DIR__ . '/config/db.php';

 $artworkId = (int)($_GET['id'] ?? 0);
if (!$artworkId) {
    header('Location: artworks.php');
    exit;
}

 $isLoggedIn = isset($_SESSION['user_id']);

// Fetch artwork with all details
 $stmt = $conn->prepare("
    SELECT a.*, 
           u.name AS artist_name, u.id AS artist_id, u.profile_picture,
           c.name AS category_name, c.id AS category_id,
           ap.city AS artist_city, ap.art_style, ap.accepts_commissions, ap.bio AS artist_bio, ap.instagram_url
    FROM artworks a
    JOIN users u ON a.artist_id = u.id
    JOIN categories c ON a.category_id = c.id
    LEFT JOIN artist_profiles ap ON ap.user_id = u.id
    WHERE a.id = ? AND a.status IN ('active', 'sold')
");
 $stmt->bind_param('i', $artworkId);
 $stmt->execute();
 $artwork = $stmt->get_result()->fetch_assoc();

if (!$artwork) {
    header('Location: artworks.php');
    exit;
}

// Fetch all images for this artwork
 $images = $conn->prepare("
    SELECT image_path, is_cover FROM artwork_images 
    WHERE artwork_id = ? ORDER BY is_cover DESC, sort_order ASC
");
 $images->bind_param('i', $artworkId);
 $images->execute();
 $artworkImages = $images->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch similar artworks (same category, different artwork, limit 4)
 $similar = $conn->prepare("
    SELECT a.id, a.title, a.price, a.artist_id, u.name AS artist_name,
           (SELECT image_path FROM artwork_images WHERE artwork_id = a.id ORDER BY is_cover DESC LIMIT 1) AS cover_image
    FROM artworks a
    JOIN users u ON a.artist_id = u.id
    WHERE a.category_id = ? AND a.id != ? AND a.status = 'active'
    LIMIT 4
");
 $similar->bind_param('ii', $artwork['category_id'], $artworkId);
 $similar->execute();
 $similarArtworks = $similar->get_result()->fetch_all(MYSQLI_ASSOC);

function getImageUrl($path) {
    if (!$path) return null;
    $path = ltrim($path, './');
    if (strpos($path, 'uploads/') !== false) return $path;
    return 'uploads/artworks/' . $path;
}

function getProfileImageUrl($path) {
    if (!$path) return null;
    $path = ltrim($path, './');
    if (strpos($path, 'uploads/') !== false) return $path;
    return 'uploads/profiles/' . $path;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($artwork['title']) ?> — Art Bazaar</title>
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
html{scroll-behavior:smooth;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.55;}
a{text-decoration:none;color:inherit;}
img{max-width:100%;display:block;}

/* ─── NAV ─── */
.nav{background:var(--ink);border-bottom:1px solid var(--ink);position:sticky;top:0;z-index:200;}
.nw{max-width:var(--w);margin:0 auto;padding:0 28px;height:58px;display:flex;align-items:center;gap:16px;}
.nlogo{flex-shrink:0;display:flex;flex-direction:column;line-height:1;margin-right:4px;}
.nlogo b{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--bg);}
.nlogo small{font-size:7.5px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);margin-top:1px;}
.nlinks{display:flex;align-items:center;gap:1px;flex:1;}
.nlinks a{font-size:12.5px;color:var(--bg);padding:6px 10px;border-radius:6px;transition:background .12s;}
.nlinks a:hover{background:var(--sand); color: var(--ink);}
.nsearch{display:flex;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--sand);border-radius:6px;padding:6px 12px;width:210px;flex-shrink:0;transition:border-color .15s;}
.nsearch:focus-within{border-color:var(--ink);}
.nsearch input{border:none;background:transparent;font-size:12.5px;font-family:'DM Sans',sans-serif;color:var(--ink);outline:none;width:100%;}
.nsearch input::placeholder{color:var(--ink); opacity: 0.6;}
.nsearch svg{color:var(--ink); opacity: 0.6; flex-shrink:0;}
.nend{display:flex;align-items:center;gap:8px;flex-shrink:0;position:relative;margin-left:auto;}
.btn-ghost{font-size:12.5px;color:var(--bg);padding:7px 14px;border-radius:6px;border:1px solid var(--bg);background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.btn-ghost:hover{border-color:var(--sand);background:var(--sand); color: var(--ink);}
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
.breadcrumb span{color:var(--light);}

/* MAIN LAYOUT */
.main{max-width:var(--w);margin:0 auto;padding:28px;display:grid;grid-template-columns:1fr 0.9fr;gap:40px;}

/* GALLERY */
.gallery{position:relative;}
.main-img{background:var(--sand);border-radius:14px;overflow:hidden;aspect-ratio:1;margin-bottom:12px;}
.main-img img{width:100%;height:100%;object-fit:cover;}
.thumb-grid{display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;}
.thumb{width:80px;height:80px;border-radius:8px;overflow:hidden;cursor:pointer;border:2px solid transparent;background:var(--sand);}
.thumb.active{border-color:var(--ink);}
.thumb img{width:100%;height:100%;object-fit:cover;}
.thumb-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--light);font-size:10px;}

/* INFO */
.artist-row{display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border);}
.artist-avatar{width:48px;height:48px;border-radius:50%;object-fit:cover;background:var(--sand);}
.artist-avatar-placeholder{width:48px;height:48px;border-radius:50%;background:var(--ink);display:flex;align-items:center;justify-content:center;color:var(--bg);font-size:18px;font-weight:500;}
.artist-details h3{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;margin-bottom:2px;}
.artist-details p{font-size:12px;color:var(--muted);}
.artist-details a{color:var(--ink);}
.artist-details a:hover{text-decoration:underline;}
h1{font-family:'Playfair Display',serif;font-size:clamp(24px,2.5vw,32px);font-weight:500;margin-bottom:12px;}
.price{font-size:26px;font-weight:600;color:var(--ink);margin-bottom:12px;}
.price small{font-size:13px;font-weight:400;color:var(--muted);}
.meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:16px 0;padding:16px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);}
.meta-item .label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:4px;}
.meta-item .value{font-size:13px;font-weight:500;color:var(--ink);}
.description{margin:20px 0;line-height:1.7;color:var(--body);}
.description p{white-space:pre-wrap;}
.tags{display:flex;gap:8px;margin:16px 0;flex-wrap:wrap;}
.tag{font-size:11px;background:var(--sand);padding:4px 12px;border-radius:20px;color:var(--body);}
.comm-note{background:var(--sand);border-radius:10px;padding:12px 16px;margin:16px 0;font-size:12px;color:var(--ink);display:flex;align-items:center;gap:8px;}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all .15s;border:none;width:100%;}
.btn-primary{background:var(--ink);color:var(--bg);}
.btn-primary:hover{background:var(--body);}
.btn-secondary{background:transparent;border:1.5px solid var(--border);color:var(--ink);}
.btn-secondary:hover{border-color:var(--ink);color:var(--ink);}
.btn-outline{background:transparent;border:1.5px solid var(--border);color:var(--ink);}
.btn-outline:hover{border-color:var(--ink);color:var(--ink);}
.btn-terr{background:var(--sand);color:var(--ink);}
.btn-terr:hover{background:#c4b69e;}
.btn-group{display:flex;gap:12px;margin:20px 0;flex-direction:column;}
.btn-group-2{display:flex;gap:12px;}
.btn-group-2 .btn{flex:1;}

/* SIMILAR ARTWORKS */
.similar-section{margin-top:40px;padding:32px 28px;background:var(--sand);border-radius:20px;}
.similar-title{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;margin-bottom:20px;}
.similar-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;}
.similar-card{background:var(--card);border-radius:10px;overflow:hidden;cursor:pointer;transition:transform .15s;}
.similar-card:hover{transform:translateY(-2px);}
.similar-img{aspect-ratio:1;overflow:hidden;background:var(--sand);}
.similar-img img{width:100%;height:100%;object-fit:cover;}
.similar-info{padding:10px 12px;}
.similar-title-txt{font-size:13px;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.similar-price{font-size:12px;font-weight:600;color:var(--ink);margin-top:4px;}

/* FOOTER */
.footer{background:var(--ink);color:var(--bg);margin-top:56px;}
.fw{max-width:var(--w);margin:0 auto;padding:40px 28px 26px;}
.fg-foot{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:32px;}
.fb b{font-family:'Playfair Display',serif;font-size:17px;color:var(--bg);display:block;margin-bottom:7px;}
.fb p{font-size:12.5px;max-width:230px;}
.fc h4{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:11px;}
.fc a{display:block;font-size:12.5px;color:rgba(246,237,222,.42);margin-bottom:8px;}
.fc a:hover{color:var(--bg);}
.fbot{border-top:1px solid rgba(246,237,222,.07);padding-top:18px;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;}

/* ─── RESPONSIVE ─── */

/* Tablet (max-width: 900px) */
@media(max-width:900px){
  .main{grid-template-columns:1fr;}
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
  .breadcrumb{padding:12px 16px 0;}
  .main{padding:16px;}
  .main-img{aspect-ratio:4/3;}
  .thumb-grid{justify-content:center;}
  .meta-grid{grid-template-columns:1fr;}
  .similar-section{padding:20px 16px;margin-top:24px;}
  .similar-grid{grid-template-columns:repeat(2,1fr);}
  .btn-group-2{font-size:12px;}
  
  /* Footer */
  .fg-foot{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 16px;}
  .fc{display:none;}
  .fb{margin-bottom:12px;}
  .fb b{font-size:16px;}
  .fb p{font-size:10px;}
  .fbot{flex-direction:column;gap:8px;text-align:center;font-size:10px;padding-top:14px;}
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
      <a href="commission.php">Commission Art</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php">Contact</a>
    </div>
    <div class="nsearch">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input type="text" placeholder="Search artworks, artists...">
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

<!-- BREADCRUMB -->
<div class="breadcrumb">
  <a href="index.php">Home</a> <span>/</span>
  <a href="artworks.php">Artworks</a> <span>/</span>
  <span><?= htmlspecialchars($artwork['title']) ?></span>
</div>

<!-- MAIN CONTENT -->
<div class="main">
  <!-- LEFT: GALLERY -->
  <div class="gallery">
    <div class="main-img" id="mainImageContainer">
      <?php 
      $coverImage = null;
      foreach ($artworkImages as $img) {
          if ($img['is_cover']) { $coverImage = $img; break; }
      }
      if (!$coverImage && !empty($artworkImages)) $coverImage = $artworkImages[0];
      $mainImgUrl = $coverImage ? getImageUrl($coverImage['image_path']) : null;
      ?>
      <?php if ($mainImgUrl): ?>
        <img src="<?= htmlspecialchars($mainImgUrl) ?>" alt="<?= htmlspecialchars($artwork['title']) ?>" id="mainImage">
      <?php else: ?>
        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--muted);">No image</div>
      <?php endif; ?>
    </div>
    <?php if (count($artworkImages) > 1): ?>
    <div class="thumb-grid" id="thumbGrid">
      <?php foreach ($artworkImages as $idx => $img): 
        $thumbUrl = getImageUrl($img['image_path']);
      ?>
        <div class="thumb <?= $idx === 0 ? 'active' : '' ?>" data-img="<?= htmlspecialchars($thumbUrl) ?>">
          <?php if ($thumbUrl): ?>
            <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="Thumbnail">
          <?php else: ?>
            <div class="thumb-placeholder">No img</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- RIGHT: DETAILS -->
  <div class="details">
    <div class="artist-row">
      <?php 
      $artistAvatar = getProfileImageUrl($artwork['profile_picture']);
      if ($artistAvatar): ?>
        <img class="artist-avatar" src="<?= htmlspecialchars($artistAvatar) ?>" alt="<?= htmlspecialchars($artwork['artist_name']) ?>">
      <?php else: ?>
        <div class="artist-avatar-placeholder"><?= htmlspecialchars(substr($artwork['artist_name'], 0, 1)) ?></div>
      <?php endif; ?>
      <div class="artist-details">
        <h3><?= htmlspecialchars($artwork['artist_name']) ?></h3>
        <p><?= htmlspecialchars($artwork['artist_city'] ?? 'Pakistan') ?> • <?= htmlspecialchars($artwork['art_style'] ?? 'Artist') ?></p>
        <a href="artist-profile.php?id=<?= $artwork['artist_id'] ?>">View full profile →</a>
      </div>
    </div>

    <h1><?= htmlspecialchars($artwork['title']) ?></h1>
    <div class="price"><small>PKR</small> <?= number_format($artwork['price']) ?></div>

    <div class="meta-grid">
      <div class="meta-item"><div class="label">Category</div><div class="value"><?= htmlspecialchars($artwork['category_name']) ?></div></div>
      <div class="meta-item"><div class="label">Medium</div><div class="value"><?= htmlspecialchars($artwork['medium'] ?? 'Not specified') ?></div></div>
      <div class="meta-item"><div class="label">Size</div><div class="value"><?= htmlspecialchars($artwork['size'] ?? 'Not specified') ?></div></div>
      <div class="meta-item"><div class="label">Location</div><div class="value"><?= htmlspecialchars($artwork['city'] ?? 'Not specified') ?></div></div>
      <div class="meta-item"><div class="label">Framing</div><div class="value"><?= $artwork['is_framed'] ? 'Framed' : 'Unframed' ?></div></div>
    </div>

    <?php if ($artwork['description']): ?>
    <div class="description">
      <p><?= nl2br(htmlspecialchars($artwork['description'])) ?></p>
    </div>
    <?php endif; ?>

    <div class="tags">
      <?php if ($artwork['delivery_available']): ?><span class="tag">📦 Delivery Available</span><?php endif; ?>
      <?php if ($artwork['similar_work_available']): ?><span class="tag">🎨 Similar Work Available</span><?php endif; ?>
      <?php if ($artwork['status'] === 'sold'): ?><span class="tag" style="background:#FDEAEA;color:var(--ink);">Sold</span><?php endif; ?>
    </div>

    <?php if ($artwork['similar_work_available']): ?>
    <div class="comm-note">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      This artist can create similar custom work. Request a commission!
    </div>
    <?php endif; ?>

    <div class="btn-group">
  <?php if ($artwork['status'] === 'sold'): ?>
    <button class="btn btn-secondary" disabled style="opacity:0.6;cursor:not-allowed;">🚫 Sold Out</button>
  <?php elseif ($isLoggedIn): ?>
    <a href="checkout.php?artwork_id=<?= $artworkId ?>" class="btn btn-terr" style="text-align:center;">🛒 Buy Now</a>
  <?php else: ?>
    <a href="login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-terr" style="text-align:center;">🛒 Login to Buy</a>
  <?php endif; ?>
  <a href="commission.php?artist=<?= $artwork['artist_id'] ?>" class="btn btn-outline" style="text-align:center;">
    🎨 Request Similar Custom Work
  </a>
</div>
  </div>
</div>

<!-- SIMILAR ARTWORKS -->
<?php if (!empty($similarArtworks)): ?>
<div class="similar-section">
  <div class="similar-title">You might also like</div>
  <div class="similar-grid">
    <?php foreach ($similarArtworks as $sim): 
      $simImg = getImageUrl($sim['cover_image']);
    ?>
    <div class="similar-card" onclick="location.href='artwork-detail.php?id=<?= (int)$sim['id'] ?>'">
      <div class="similar-img">
        <?php if ($simImg): ?>
          <img src="<?= htmlspecialchars($simImg) ?>" alt="<?= htmlspecialchars($sim['title']) ?>">
        <?php else: ?>
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:10px;">No image</div>
        <?php endif; ?>
      </div>
      <div class="similar-info">
        <div class="similar-title-txt"><?= htmlspecialchars($sim['title']) ?></div>
        <div class="similar-price">PKR <?= number_format($sim['price']) ?></div>
        <div style="font-size:10px;color:var(--muted);margin-top:4px;">by <?= htmlspecialchars($sim['artist_name']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

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
    <a href="commission.php">Commission Art</a>
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

// Thumbnail switcher
const thumbs = document.querySelectorAll('.thumb');
const mainImg = document.getElementById('mainImage');
if (thumbs.length > 0 && mainImg) {
  thumbs.forEach(thumb => {
    thumb.addEventListener('click', () => {
      thumbs.forEach(t => t.classList.remove('active'));
      thumb.classList.add('active');
      const imgSrc = thumb.getAttribute('data-img');
      if (imgSrc) mainImg.src = imgSrc;
    });
  });
}
 
</script>
</body>
</html>