<?php
session_start();
require_once __DIR__ . '/config/db.php';



 $isLoggedIn = isset($_SESSION['user_id']);

 $search = trim($_GET['q'] ?? '');
 $cityFilter = trim($_GET['city'] ?? '');
 $styleFilter = trim($_GET['style'] ?? '');
 $page = max(1, (int)($_GET['page'] ?? 1));
 $perPage = 12;
 $offset = ($page - 1) * $perPage;

// Build query
 $where = [
    "u.role = 'artist'",
    "u.status = 'active'",
    "ap.bio IS NOT NULL AND ap.bio != ''",
    "ap.city IS NOT NULL AND ap.city != ''",
    "ap.art_style IS NOT NULL AND ap.art_style != ''",
    "u.profile_picture IS NOT NULL AND u.profile_picture != ''"
];
 $params = [];
 $types = '';

if ($search) {
    $where[] = "(u.name LIKE ? OR ap.city LIKE ? OR ap.art_style LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($cityFilter) {
    $where[] = "ap.city = ?";
    $params[] = $cityFilter;
    $types .= 's';
}
if ($styleFilter) {
    $where[] = "ap.art_style = ?";
    $params[] = $styleFilter;
    $types .= 's';
}

 $whereSQL = implode(' AND ', $where);

// Count total
 $countSQL = "SELECT COUNT(*) FROM users u LEFT JOIN artist_profiles ap ON u.id = ap.user_id WHERE $whereSQL";
if ($params) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalArtists = $stmt->get_result()->fetch_row()[0];
} else {
    $totalArtists = $conn->query($countSQL)->fetch_row()[0];
}
 $totalPages = max(1, ceil($totalArtists / $perPage));

// Fetch artists
 $sql = "
    SELECT u.id, u.name, u.profile_picture, u.created_at,
           ap.bio, ap.city, ap.art_style, ap.accepts_commissions, ap.is_featured,
           (SELECT COUNT(*) FROM artworks WHERE artist_id = u.id AND status = 'active') AS artwork_count
    FROM users u
    LEFT JOIN artist_profiles ap ON u.id = ap.user_id
    WHERE $whereSQL
    ORDER BY ap.is_featured DESC, u.name ASC
    LIMIT ? OFFSET ?
";
 $allParams = array_merge($params, [$perPage, $offset]);
 $allTypes = $types . 'ii';
 $stmt = $conn->prepare($sql);
if ($allParams) $stmt->bind_param($allTypes, ...$allParams);
 $stmt->execute();
 $artists = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get filter options
 $cities = $conn->query("SELECT DISTINCT ap.city FROM artist_profiles ap JOIN users u ON u.id=ap.user_id WHERE u.role='artist' AND u.status='active' AND ap.city IS NOT NULL AND ap.city != '' ORDER BY ap.city")->fetch_all(MYSQLI_ASSOC);
 $styles = $conn->query("SELECT DISTINCT ap.art_style FROM artist_profiles ap JOIN users u ON u.id=ap.user_id WHERE u.role='artist' AND u.status='active' AND ap.art_style IS NOT NULL AND ap.art_style != '' ORDER BY ap.art_style")->fetch_all(MYSQLI_ASSOC);

function getProfileImageUrl($p) {
    if (!$p) return null;
    $p = ltrim($p, './');
    if (strpos($p, 'uploads/') !== false) return $p;
    return 'uploads/profiles/' . $p;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Artists — Art Bazaar</title>
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
.nlinks a:hover,.nlinks a.active{background:var(--sand); color: var(--ink);}
.nlinks a.active{font-weight:500;}
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

/* HERO */
.hero{background:var(--ink);padding:44px 28px;}
.hero-inner{max-width:var(--w);margin:0 auto;}
.hero-tag{font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:8px;}
.hero h1{font-family:'Playfair Display',serif;font-size:clamp(28px,3vw,38px);font-weight:400;color:var(--bg);line-height:1.15;}
.hero h1 em{font-style:italic;color:var(--sand);}
.hero-desc{font-size:13px;color:rgba(246,237,222,.5);max-width:480px;margin-top:12px;}

/* WRAP */
.wrap{max-width:var(--w);margin:0 auto;padding:28px;}

/* FILTERS */
.filters{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px 24px;margin-bottom:28px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.filter-group{display:flex;flex-direction:column;gap:4px;}
.filter-group label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);}
.filter-input{padding:8px 14px;border:1.5px solid var(--sand);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;background:var(--bg);min-width:160px;outline:none;transition:border-color .12s;color:var(--ink);}
.filter-input:focus{border-color:var(--ink);}
.filter-btn{background:var(--sand);color:var(--ink);border:none;padding:8px 20px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .12s;}
.filter-btn:hover{background:#c4b69e;}
.filter-clear{background:transparent;border:1px solid var(--sand);padding:8px 16px;border-radius:8px;font-size:13px;cursor:pointer;font-family:'DM Sans',sans-serif;color:var(--ink);transition:all .12s;}
.filter-clear:hover{border-color:var(--border);color:var(--ink);}

/* RESULTS */
.results-info{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
.results-count{font-size:12px;color:var(--muted);}
.results-count strong{color:var(--ink);}

/* ARTIST GRID */
.artist-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:24px;margin-bottom:32px;}
.artist-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;transition:transform .15s,box-shadow .15s;}
.artist-card:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(12,63,48,.09);}
.artist-cover{position:relative;height:130px;background:var(--sand);display:flex;align-items:center;justify-content:center;}
.artist-avatar{width:85px;height:85px;border-radius:50%;object-fit:cover;border:3px solid var(--card);position:absolute;bottom:-30px;left:20px;background:var(--sand);}
.artist-avatar-placeholder{width:85px;height:85px;border-radius:50%;background:var(--ink);display:flex;align-items:center;justify-content:center;font-size:34px;color:var(--bg);font-weight:500;border:3px solid var(--card);position:absolute;bottom:-30px;left:20px;}
.feat-badge{position:absolute;top:12px;right:12px;background:var(--sand);color:var(--ink);font-size:10px;font-weight:600;padding:3px 9px;border-radius:20px;}
.artist-info{padding:38px 16px 16px;}
.artist-name{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--ink);margin-bottom:4px;}
.artist-meta{display:flex;gap:10px;margin-bottom:8px;flex-wrap:wrap;}
.artist-city{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:3px;}
.artist-style{font-size:10px;background:var(--sand);padding:2px 8px;border-radius:12px;color:var(--body);}
.artist-stats{display:flex;gap:12px;margin:10px 0;font-size:11px;color:var(--muted);}
.artist-stats span{font-weight:600;color:var(--ink);margin-right:2px;}
.comm-badge{display:inline-block;font-size:9px;background:var(--sand);color:var(--ink);padding:2px 8px;border-radius:12px;margin-bottom:10px;}
.artist-buttons{display:flex;gap:8px;margin-top:12px;}
.art-btn{flex:1;text-align:center;padding:8px;border-radius:7px;font-size:12px;font-weight:500;cursor:pointer;transition:all .12s;font-family:'DM Sans',sans-serif;}
.art-btn.outline{background:transparent;border:1px solid var(--border);color:var(--ink);}
.art-btn.outline:hover{border-color:var(--sand);background:var(--sand);color:var(--ink);}
.art-btn.solid{background:var(--ink);color:var(--bg);border:none;}
.art-btn.solid:hover{background:var(--body);}
.art-btn.solid.disabled{background:var(--sand);color:var(--ink);opacity:0.5;cursor:default;}

/* PAGINATION */
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;}
.pag-btn{width:36px;height:36px;display:flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:8px;background:var(--card);font-size:13px;cursor:pointer;text-decoration:none;color:var(--body);transition:all .12s;}
.pag-btn.active{background:var(--ink);color:var(--bg);border-color:var(--ink);}
.pag-btn.disabled{opacity:.35;cursor:default;pointer-events:none;}
.pag-btn:hover:not(.disabled):not(.active){border-color:var(--muted);}

/* EMPTY */
.empty{text-align:center;padding:64px 20px;}
.empty svg{opacity:.2;margin-bottom:16px;}
.empty h3{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;margin-bottom:6px;}
.empty p{color:var(--muted);}

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
  .artist-grid{grid-template-columns:repeat(2,1fr);}
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
  .hero{padding:28px 16px;}
  .wrap{padding:16px;}
  .filters{flex-direction:column;align-items:stretch;gap:10px;}
  .filter-input{width:100%;}
  .artist-grid{grid-template-columns:1fr;}
  .results-info{flex-direction:column;gap:4px;}
  .artist-buttons{font-size:11px;}
  
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
      <a href="artists.php" class="active">Artists</a>
      <a href="blog.php">Blog</a>
      <a href="commission.php">Commission Art</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php">Contact</a>
    </div>
    <div class="nsearch">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input type="text" placeholder="Search artists..." id="searchInput" value="<?= htmlspecialchars($search) ?>">
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

<!-- HERO -->
<section class="hero" style="padding:0;position:relative;min-height:280px;display:flex;align-items:center;">
  <img src="artisthero.jpeg" alt="Artists" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;display:block;">
  <div style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(12,63,48,0.65);"></div>
  <div class="hero-inner" style="position:relative;z-index:1;padding:52px 28px;">
    <div class="hero-tag">MEET THE ARTISTS</div>
    <h1>Discover talented <em>Pakistani artists</em><br>and their unique creative voices.</h1>
    <p class="hero-desc">Browse through our curated community of artists — from emerging talents to established names.</p>
  </div>
</section>

<div class="wrap">

<!-- FILTERS -->
<form class="filters" method="GET" action="artists.php">
  <div class="filter-group">
    <label>SEARCH</label>
    <input type="text" name="q" class="filter-input" placeholder="Name, city, or style..." value="<?= htmlspecialchars($search) ?>">
  </div>
  <div class="filter-group">
    <label>CITY</label>
    <select name="city" class="filter-input">
      <option value="">All Cities</option>
      <?php foreach ($cities as $c): ?>
      <option value="<?= htmlspecialchars($c['city']) ?>" <?= $cityFilter==$c['city']?'selected':'' ?>><?= htmlspecialchars($c['city']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>ART STYLE</label>
    <select name="style" class="filter-input">
      <option value="">All Styles</option>
      <?php foreach ($styles as $s): ?>
      <option value="<?= htmlspecialchars($s['art_style']) ?>" <?= $styleFilter==$s['art_style']?'selected':'' ?>><?= htmlspecialchars($s['art_style']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="filter-btn">Filter</button>
  <?php if ($search||$cityFilter||$styleFilter): ?>
  <a href="artists.php" class="filter-clear">Clear</a>
  <?php endif; ?>
</form>

<!-- RESULTS INFO -->
<div class="results-info">
  <div class="results-count"><strong><?= $totalArtists ?></strong> artist<?= $totalArtists!=1?'s':'' ?> found</div>
  <div class="results-count">Page <?= $page ?> of <?= $totalPages ?></div>
</div>

<!-- ARTIST GRID -->
<?php if (empty($artists)): ?>
<div class="empty">
  <svg width="56" height="56" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
  <h3>No artists found</h3>
  <p>Try adjusting your search or filters.</p>
</div>
<?php else: ?>
<div class="artist-grid">
  <?php foreach ($artists as $a): 
    $avatar = getProfileImageUrl($a['profile_picture']);
  ?>
  <div class="artist-card">
    <div class="artist-cover">
      <?php if ($a['is_featured']): ?><span class="feat-badge">★ Featured</span><?php endif; ?>
      <?php if ($avatar): ?>
        <img class="artist-avatar" src="<?= htmlspecialchars($avatar) ?>" alt="">
      <?php else: ?>
        <div class="artist-avatar-placeholder"><?= strtoupper(substr($a['name'],0,1)) ?></div>
      <?php endif; ?>
    </div>
    <div class="artist-info">
      <h3 class="artist-name"><?= htmlspecialchars($a['name']) ?></h3>
      <div class="artist-meta">
        <?php if ($a['city']): ?><span class="artist-city">📍 <?= htmlspecialchars($a['city']) ?></span><?php endif; ?>
        <?php if ($a['art_style']): ?><span class="artist-style"><?= htmlspecialchars($a['art_style']) ?></span><?php endif; ?>
      </div>
      <div class="artist-stats">
        <span><span><?= $a['artwork_count'] ?></span> artworks</span>
        <span>Joined <?= date('M Y', strtotime($a['created_at'])) ?></span>
      </div>
      <?php if ($a['accepts_commissions']): ?>
        <div class="comm-badge">✓ Accepts commissions</div>
      <?php endif; ?>
      <div class="artist-buttons">
        <a href="artist-profile.php?id=<?= $a['id'] ?>" class="art-btn outline">View Profile</a>
        <?php if ($a['accepts_commissions']): ?>
          <button class="art-btn solid" onclick="openCommissionModal(<?= $a['id'] ?>, '<?= addslashes($a['name']) ?>')">Commission</button>
        <?php else: ?>
          <button class="art-btn solid disabled" disabled>Commissions Off</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- PAGINATION -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
  <?php
  $queryParams = $_GET;
  unset($queryParams['page']);
  $baseUrl = '?' . http_build_query($queryParams);
  ?>
  <a href="<?= $baseUrl ?>&page=<?= max(1,$page-1) ?>" class="pag-btn <?= $page<=1?'disabled':'' ?>">←</a>
  <?php for($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++): ?>
  <a href="<?= $baseUrl ?>&page=<?= $i ?>" class="pag-btn <?= $i==$page?'active':'' ?>"><?= $i ?></a>
  <?php endfor; ?>
  <a href="<?= $baseUrl ?>&page=<?= min($totalPages,$page+1) ?>" class="pag-btn <?= $page>=$totalPages?'disabled':'' ?>">→</a>
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
      <div class="fc"><h4>Company</h4><a href="about.php">About Us</a><a href="contact.php">Contact</a><a href="commission.php">Commissions</a><a href="terms.php">Terms & Conditions</a><a href="privacy-policy.php">Privacy & Policies</a></div>
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

<script>
function openCommissionModal(artistId, artistName) {
  sessionStorage.setItem('commissionArtistId', artistId);
  sessionStorage.setItem('commissionArtistName', artistName);
  window.location.href = 'commission.php?artist=' + artistId;
}

// Search on Enter
document.getElementById('searchInput').addEventListener('keypress', function(e) {
  if (e.key === 'Enter') {
    let params = new URLSearchParams(window.location.search);
    params.set('q', this.value);
    params.delete('page');
    window.location.href = 'artists.php?' + params.toString();
  }
});

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