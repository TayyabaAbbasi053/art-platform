<?php
session_start();
require_once __DIR__ . '/config/db.php';
$isLoggedIn = isset($_SESSION['user_id']);

// ── Filters ──────────────────────────────────────────────
 $search     = trim($_GET['q'] ?? '');
 $catFilter  = (int)($_GET['category'] ?? 0);
 $cityFilter = trim($_GET['city'] ?? '');
 $medFilter  = trim($_GET['medium'] ?? '');
 $minPrice   = !empty($_GET['min_price']) ? (int)$_GET['min_price'] : null;
 $maxPrice   = !empty($_GET['max_price']) ? (int)$_GET['max_price'] : null;
 $avail      = trim($_GET['availability'] ?? '');
 $sort       = $_GET['sort'] ?? 'newest';
 $featured   = isset($_GET['featured']) ? 1 : 0;
 $page       = max(1, (int)($_GET['page'] ?? 1));
 $perPage    = 16;
 $offset     = ($page - 1) * $perPage;

// ── Build query ──────────────────────────────────────────
 $where = [
    "a.status IN ('active', 'sold')",
    "u.status = 'active'",
    "ap.profile_complete = 1"
];
 $params = [];
 $types = '';

if ($search) {
    $where[] = "(a.title LIKE ? OR u.name LIKE ? OR a.description LIKE ? OR a.tags LIKE ? OR c.name LIKE ?)";
    $s = "%$search%";
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $types .= 'sssss';
}
if ($catFilter) {
    $where[] = "a.category_id = ?";
    $params[] = $catFilter;
    $types .= 'i';
}
if ($cityFilter) {
    $where[] = "a.city LIKE ?";
    $params[] = "%$cityFilter%";
    $types .= 's';
}
if ($medFilter) {
    $where[] = "a.medium LIKE ?";
    $params[] = "%$medFilter%";
    $types .= 's';
}
if ($minPrice) {
    $where[] = "a.price >= ?";
    $params[] = $minPrice;
    $types .= 'i';
}
if ($maxPrice) {
    $where[] = "a.price <= ?";
    $params[] = $maxPrice;
    $types .= 'i';
}
if ($avail === 'available') {
    $where[] = "a.status = 'active'";
}
if ($avail === 'sold') {
    $where[] = "a.status = 'sold'";
    $where = array_filter($where, fn($w) => $w !== "a.status = 'active'");
}
if ($featured) {
    $where[] = "a.is_featured = 1";
}

 $orderMap = [
    'newest' => 'a.created_at DESC',
    'oldest' => 'a.created_at ASC',
    'price_asc' => 'a.price ASC',
    'price_desc' => 'a.price DESC'
];
 $orderBy = $orderMap[$sort] ?? 'a.created_at DESC';
 $whereSQL = implode(' AND ', $where);

// Count total
 $countSQL = "SELECT COUNT(*) FROM artworks a JOIN users u ON a.artist_id = u.id JOIN categories c ON a.category_id = c.id LEFT JOIN artist_profiles ap ON ap.user_id = u.id WHERE $whereSQL";
if ($params) {
    $cs = $conn->prepare($countSQL);
    $cs->bind_param($types, ...$params);
    $cs->execute();
    $totalRows = $cs->get_result()->fetch_row()[0];
    $cs->close();
} else {
    $totalRows = $conn->query($countSQL)->fetch_row()[0];
}
 $totalPages = max(1, ceil($totalRows / $perPage));

// Fetch artworks
 $sql = "SELECT a.id, a.title, a.price, a.city, a.status, a.medium, a.is_featured, a.reserved_by,
               u.name AS artist_name, u.id AS artist_id,
               c.name AS category_name,
               (SELECT image_path FROM artwork_images WHERE artwork_id = a.id ORDER BY is_cover DESC, sort_order ASC LIMIT 1) AS cover_image
        FROM artworks a
        JOIN users u ON a.artist_id = u.id
        JOIN categories c ON a.category_id = c.id
        LEFT JOIN artist_profiles ap ON ap.user_id = u.id
        WHERE $whereSQL
        ORDER BY $orderBy
        LIMIT ? OFFSET ?";

 $allParams = $params;
 $allParams[] = $perPage;
 $allParams[] = $offset;
 $allTypes = $types . 'ii';

 $stmt2 = $conn->prepare($sql);
if ($allParams) {
    $stmt2->bind_param($allTypes, ...$allParams);
}
 $stmt2->execute();
 $artworks = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
 $stmt2->close();

// Sidebar data
 $categories = $conn->query("SELECT id, name FROM categories ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
 $cities = $conn->query("SELECT DISTINCT city FROM artworks WHERE status = 'active' AND city IS NOT NULL AND city != '' ORDER BY city ASC")->fetch_all(MYSQLI_ASSOC);
 $mediums = $conn->query("SELECT DISTINCT medium FROM artworks WHERE status = 'active' AND medium IS NOT NULL AND medium != '' ORDER BY medium ASC")->fetch_all(MYSQLI_ASSOC);

function getImgUrl($p) {
    if (!$p) return null;
    $p = ltrim($p, './');
    if (strpos($p, 'uploads/') !== false) return $p;
    return 'uploads/artworks/' . $p;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Browse Artworks — Art Bazaar</title>
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
img{display:block;max-width:100%;}

/* ─── NAV ─── */
.nav{background:var(--ink);border-bottom:1px solid var(--ink);position:sticky;top:0;z-index:200;}
.nw{max-width:var(--w);margin:0 auto;padding:0 28px;height:58px;display:flex;align-items:center;gap:16px;}
.nlogo{flex-shrink:0;display:flex;flex-direction:column;line-height:1;margin-right:4px;}
.nlogo b{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--bg);}
.nlogo small{font-size:7.5px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);margin-top:1px;}
.nlinks{display:flex;align-items:center;gap:1px;flex:1;}
.nlinks a{font-size:12.5px;color:var(--bg);padding:6px 10px;border-radius:6px;transition:background .12s;}
.nlinks a:hover{background:var(--sand); color: var(--ink);}
.nlinks a.active{background:var(--sand); color: var(--ink);font-weight:500;}
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

/* ─── PAGE HERO ─── */
.page-hero {
  border-top: 3px solid #0C3F30;
  border-bottom: 3px solid #0C3F30;
  background: #0C3F30;
  padding: 36px 28px;
}
.page-hero-inner {
  max-width: var(--w);
  margin: 0 auto;
}
.ph-tag {
  font-size: 10px;
  letter-spacing: 2.5px;
  text-transform: uppercase;
  color: #DDCDAE;
  opacity: 0.8;
  margin-bottom: 10px;
}
.ph-title {
  font-family: 'Playfair Display', serif;
  font-size: clamp(26px, 3vw, 42px);
  font-weight: 400;
  color: #F6EDDE;
  line-height: 1.15;
  margin-bottom: 8px;
}
.ph-title em {
  font-style: italic;
  color: #DDCDAE;
}
.ph-desc {
  font-size: 13px;
  color: #F6EDDE;
  opacity: 0.6;
  max-width: 480px;
  line-height: 1.65;
}
@media(max-width:768px){
  .page-hero { padding: 24px 16px; }
  .ph-title { font-size: 26px; }
  .ph-desc { font-size: 12px; }
}

/* ─── LAYOUT & CONTENT ─── */
.content{max-width:var(--w);margin:0 auto;padding:28px 28px;}

/* TOP BAR */
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.topbar-left{display:flex;align-items:center;gap:12px;}
.result-count{font-size:12.5px;color:var(--muted);}
.result-count strong{color:var(--ink);}
.active-filters{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.af-chip{display:inline-flex;align-items:center;gap:5px;background:var(--sand);border:1px solid var(--border);border-radius:20px;padding:3px 10px;font-size:11px;color:var(--body);}
.af-chip a{color:var(--muted);font-size:12px;line-height:1;margin-left:1px;text-decoration:none;}
.af-chip a:hover{color:var(--ink);}
.sort-row{display:flex;align-items:center;gap:8px;}
.sort-row label{font-size:11.5px;color:var(--muted);}
.sort-sel{border:1px solid var(--border);border-radius:6px;padding:6px 10px;font-size:12.5px;font-family:'DM Sans',sans-serif;color:var(--ink);background:var(--card);outline:none;cursor:pointer;}

/* CATEGORY PILLS */
.cat-pills{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:22px;}
.cat-pill{font-size:12px;padding:6px 14px;border-radius:20px;border:1px solid var(--border);background:var(--card);color:var(--body);cursor:pointer;transition:all .12s;white-space:nowrap;display:inline-block;text-decoration:none;}
.cat-pill:hover{border-color:var(--sand);color:var(--ink);}
.cat-pill.active{background:var(--ink);color:var(--bg);border-color:var(--ink);}

/* ARTWORKS GRID */
.aw-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;}
.aw-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;transition:transform .15s,box-shadow .15s;}
.aw-card:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(12,63,48,.09);}
.aw-img{aspect-ratio:1;overflow:hidden;position:relative;background:var(--sand);cursor:pointer;}
.aw-img img{width:100%;height:100%;object-fit:cover;transition:transform .3s;}
.aw-card:hover .aw-img img{transform:scale(1.04);}
.aw-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
.aw-ph svg{opacity:.15;color:var(--muted);}
.aw-badges{position:absolute;top:8px;left:8px;display:flex;gap:4px;}
.aw-badge{font-size:8.5px;letter-spacing:.8px;text-transform:uppercase;padding:3px 7px;border-radius:4px;font-weight:600;}
.aw-badge.sold{background:rgba(12,63,48,.78);color:var(--bg);}
.aw-badge.feat{background:var(--sand);color:var(--ink);}
.aw-body{padding:11px 12px 0;}
.aw-title{font-size:13px;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px;cursor:pointer;}
.aw-by{font-size:11.5px;color:var(--muted);margin-bottom:8px;}
.aw-by a{color:var(--ink);transition:opacity .12s;text-decoration:none;}
.aw-by a:hover{opacity:.75;text-decoration:underline;}
.aw-foot{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.aw-price{font-size:13.5px;font-weight:600;color:var(--ink);}
.aw-price small{font-size:9.5px;font-weight:400;color:var(--muted);margin-right:1px;}
.aw-cat{font-size:10px;color:var(--muted);background:var(--sand);padding:2px 7px;border-radius:20px;}
.aw-city{font-size:10.5px;color:var(--light);margin-bottom:10px;}
.aw-city svg{display:inline;vertical-align:middle;margin-right:2px;}
.aw-add-cart{display:block;width:calc(100% - 24px);margin:0 12px 12px;background:var(--sand);color:var(--ink);border:none;border-radius:6px;padding:8px;font-size:12px;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .12s;text-align:center;}
.aw-add-cart:hover{background:#c4b69e;}
.aw-add-cart:disabled{opacity:.5;cursor:default;}
.aw-view-btn{display:block;width:calc(100% - 24px);margin:0 12px 12px;background:var(--ink);color:var(--bg);border:1px solid var(--ink);border-radius:6px;padding:7px;font-size:12px;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all .12s;text-align:center;text-decoration:none;}
.aw-view-btn:hover{background:var(--body);border-color:var(--body);}

/* EMPTY STATE */
.empty{text-align:center;padding:64px 20px;}
.empty svg{opacity:.2;color:var(--muted);margin:0 auto 16px;}
.empty h3{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;color:var(--ink);margin-bottom:6px;}
.empty p{font-size:13px;color:var(--muted);}
.empty a{color:var(--ink);text-decoration:underline;}

/* PAGINATION */
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:36px;}
.pag-btn{width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:7px;border:1px solid var(--border);background:var(--card);color:var(--body);font-size:13px;cursor:pointer;transition:all .12s;text-decoration:none;}
.pag-btn:hover{border-color:var(--muted);}
.pag-btn.active{background:var(--ink);color:var(--bg);border-color:var(--ink);}
.pag-btn.disabled{opacity:.35;cursor:default;pointer-events:none;}

/* FOOTER */
.footer{background:var(--ink);color:var(--bg);margin-top:56px;}
.fw{max-width:var(--w);margin:0 auto;padding:40px 28px 26px;}
.fg-foot{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:32px;}
.fb b{font-family:'Playfair Display',serif;font-size:17px;color:var(--bg);display:block;margin-bottom:7px;}
.fb p{font-size:12.5px;line-height:1.65;max-width:230px;}
.fc h4{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:11px;}
.fc a{display:block;font-size:12.5px;color:rgba(246,237,222,.42);margin-bottom:8px;transition:color .12s;text-decoration:none;}
.fc a:hover{color:var(--bg);}
.fbot{border-top:1px solid rgba(246,237,222,.07);padding-top:18px;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;}

/* ─── RESPONSIVE ─── */

/* Tablet and below - 2 columns */
@media(max-width:1080px){
  .aw-grid{grid-template-columns:repeat(2,1fr);}
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
  .content{padding:16px;}
  
  /* Filters */
  .cat-pills{overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch;}
  .cat-pills::-webkit-scrollbar{display:none;}
  .topbar{flex-direction:column;align-items:stretch;gap:12px;}
  .topbar-left{justify-content:space-between;}
  .sort-row{width:100%;}
  .sort-sel{width:100%;}
  
  /* Grid */
  .aw-grid{grid-template-columns:repeat(2,1fr);}
  
  /* Footer */
  .fg-foot{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 16px;}
  .fc{display:none;}
  .fb{margin-bottom:12px;}
  .fb b{font-size:16px;}
  .fb p{font-size:10px;}
  .fbot{flex-direction:column;gap:12px;text-align:center;font-size:10px;padding-top:14px;}
}
/* ─── MOBILE BACK BUTTON ─── */
.mobile-back { display:none; }
@media(max-width:768px){
  .mobile-back {
    display:flex;
    align-items:center;
    gap:6px;
    padding:10px 16px;
    background:var(--bg);
    border-bottom:1px solid var(--sand);
  }
  .mobile-back a {
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-size:13px;
    color:var(--ink);
    font-family:'DM Sans',sans-serif;
    font-weight:500;
    text-decoration:none;
  }
  .mobile-back a svg { flex-shrink:0; }
}
</style>
</head>
<body>

<!-- NAV -->
<nav class="nav">
  <div class="nw">
    <a href="index.php" class="nlogo"><img src="logo.png" alt="Art Bazaar" style="height:36px;width:auto;display:block;"></a>
    <div class="nlinks">
      <a href="artworks.php" class="active">Explore Art</a>
      <a href="artists.php">Artists</a>
      <a href="blog.php">Blog</a>
      <a href="commission.php">Custom Artwork</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php">Contact</a>
    </div>
    <div class="nsearch">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input type="text" placeholder="Search artworks, artists..." value="<?= htmlspecialchars($search) ?>" onkeydown="if(event.key==='Enter'){window.location='artworks.php?q='+encodeURIComponent(this.value);}">
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
<!-- MOBILE BACK BUTTON -->
<div class="mobile-back">
  <a href="javascript:history.back()">
    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
    Back to Blog
  </a>
</div>

<!-- PAGE HERO -->
<div class="page-hero" style="padding:0;position:relative;min-height:280px;display:flex;align-items:center;">
  <img src="arthero.jpeg" alt="Artworks" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;display:block;">
  <div style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(12,63,48,0.65);"></div>
  <div class="page-hero-inner" style="position:relative;z-index:1;padding:52px 28px;">
    <div class="ph-tag">Original Art · Pakistan</div>
    <h1 class="ph-title">Browse <em>Original Artworks</em></h1>
    <p class="ph-desc">Handpicked pieces from verified Pakistani artists — paintings, calligraphy, digital art and more.</p>
  </div>
</div>

<!-- CONTENT -->
<div class="content">

  <!-- Category pills -->
  <div class="cat-pills">
    <a href="artworks.php" class="cat-pill <?= !$catFilter ? 'active' : '' ?>">All</a>
    <?php foreach ($categories as $cat): ?>
    <a href="artworks.php?category=<?= $cat['id'] ?><?= $search ? "&q=" . urlencode($search) : '' ?>" class="cat-pill <?= $catFilter == $cat['id'] ? 'active' : '' ?>"><?= htmlspecialchars($cat['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Top bar -->
  <div class="topbar">
    <div class="topbar-left">
      <p class="result-count">Showing <strong><?= $totalRows ?></strong> artwork<?= $totalRows != 1 ? 's' : '' ?><?= $search ? " for \"" . htmlspecialchars($search) . "\"" : '' ?></p>
      <div class="active-filters">
        <?php if ($catFilter): $cn = array_column($categories, 'name', 'id')[$catFilter] ?? ''; ?>
        <span class="af-chip"><?= htmlspecialchars($cn) ?><a href="<?= strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(array_diff_key($_GET, ['category' => ''])) ?>">×</a></span>
        <?php endif; ?>
        <?php if ($cityFilter): ?>
        <span class="af-chip"><?= htmlspecialchars($cityFilter) ?><a href="<?= strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(array_diff_key($_GET, ['city' => ''])) ?>">×</a></span>
        <?php endif; ?>
        <?php if ($medFilter): ?>
        <span class="af-chip"><?= htmlspecialchars($medFilter) ?><a href="<?= strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(array_diff_key($_GET, ['medium' => ''])) ?>">×</a></span>
        <?php endif; ?>
      </div>
    </div>
    <form class="sort-row" method="GET" action="artworks.php">
      <?php foreach ($_GET as $k => $v): if ($k !== 'sort'): ?>
      <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
      <?php endif; endforeach; ?>
      <label>Sort by</label>
      <select name="sort" class="sort-sel" onchange="this.form.submit()">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
      </select>
    </form>
  </div>

  <!-- Grid -->
  <?php if (empty($artworks)): ?>
  <div class="empty">
    <svg width="56" height="56" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
    <h3>No artworks found</h3>
    <p>Try adjusting your filters or <a href="artworks.php">browse all artworks</a>.</p>
  </div>
  <?php else: ?>
  <div class="aw-grid">
    <?php foreach ($artworks as $art): 
      $img = getImgUrl($art['cover_image']); 
    ?>
    <div class="aw-card">
      <div class="aw-img" onclick="location.href='artwork-detail.php?id=<?= $art['id'] ?>'">
        <?php if ($img): ?>
        <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($art['title']) ?>" loading="lazy">
        <?php else: ?>
        <div class="aw-ph">
          <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        </div>
        <?php endif; ?>
        <div class="aw-badges">
          <?php if ($art['status'] === 'sold'): ?>
          <span class="aw-badge sold">Sold</span>
          <?php endif; ?>
          <?php if ($art['is_featured']): ?>
          <span class="aw-badge feat">Featured</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="aw-body">
        <div class="aw-title" onclick="location.href='artwork-detail.php?id=<?= $art['id'] ?>'"><?= htmlspecialchars($art['title']) ?></div>
        <div class="aw-by">by <a href="artist-profile.php?id=<?= $art['artist_id'] ?>"><?= htmlspecialchars($art['artist_name']) ?></a></div>
        <?php if ($art['city']): ?>
        <div class="aw-city">
          <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <?= htmlspecialchars($art['city']) ?>
        </div>
        <?php endif; ?>
        <div class="aw-foot">
          <div class="aw-price"><small>Rs. </small><?= number_format($art['price']) ?></div>
          <span class="aw-cat"><?= htmlspecialchars($art['category_name']) ?></span>
        </div>
      </div>
      <?php if ($art['status'] === 'sold'): ?>
  <button class="aw-add-cart" disabled style="opacity:0.5;cursor:not-allowed;">🚫 Sold Out</button>
<?php elseif ($isLoggedIn): ?>
  <a href="checkout.php?artwork_id=<?= $art['id'] ?>" class="aw-add-cart" style="text-decoration:none;">🛒 Buy Now</a>
<?php else: ?>
  <a href="artwork-detail.php?id=<?= $art['id'] ?>" class="aw-view-btn">View Details</a>
<?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php
    $queryParams = $_GET;
    unset($queryParams['page']);
    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($queryParams);
    ?>
    <a href="<?= $baseUrl ?>&page=<?= max(1, $page - 1) ?>" class="pag-btn <?= $page <= 1 ? 'disabled' : '' ?>">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
    </a>
    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
    <a href="<?= $baseUrl ?>&page=<?= $i ?>" class="pag-btn <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <a href="<?= $baseUrl ?>&page=<?= min($totalPages, $page + 1) ?>" class="pag-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
    </a>
  </div>
  <?php endif; ?>
  <?php endif; ?>
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
    <a href="commission.php">Custom Artwork</a>
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