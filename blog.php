<?php
session_start();
require_once __DIR__ . '/config/db.php';



function getImgUrl($p) {
    if (!$p) return null;
    $p = ltrim($p, './');
    if (strpos($p, 'uploads/') !== false) return $p;
    return 'uploads/artworks/' . $p;
}

 $isLoggedIn = isset($_SESSION['user_id']) && $_SESSION['role'] === 'buyer';

// ── Build Query ─────────────────────────────────────────
 $where = ["bp.status = 'published'"];
 $params = [];
 $types = '';

 $whereSQL = implode(' AND ', $where);

// Pagination
 $page = max(1, (int)($_GET['page'] ?? 1));
 $perPage = 9;
 $offset = ($page - 1) * $perPage;

// Count total
 $countSQL = "SELECT COUNT(*) FROM blog_posts bp WHERE $whereSQL";
if ($params) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalResults = (int)$stmt->get_result()->fetch_row()[0];
} else {
    $totalResults = (int)$conn->query($countSQL)->fetch_row()[0];
}
 $totalPages = max(1, ceil($totalResults / $perPage));

// Fetch posts
 $dataSQL = "
    SELECT bp.*, u.name AS author_name
    FROM blog_posts bp
    JOIN users u ON u.id = bp.author_id
    WHERE $whereSQL
    ORDER BY bp.published_at DESC
    LIMIT $perPage OFFSET $offset
";
 $posts = [];
if ($params) {
    $stmt = $conn->prepare($dataSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($dataSQL);
}
while ($row = $res->fetch_assoc()) $posts[] = $row;

// Build query string helper
function buildQS($overrides = []) {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
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
<title>Blog — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
  --bg:#F6EDDE;--card:#F6EDDE;--sand:#DDCDAE;--border:#0C3F30;
  --ink:#0C3F30;--body:#0C3F30;--muted:#0C3F30;--light:#0C3F30;
  --terr:#0C3F30;--w:1280px;--r:10px;
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
.nlinks a:hover{background:var(--sand);color:var(--ink);}
.nlinks a.dd::after{content:' ▾';font-size:9px;opacity:.4;}
.nsearch{display:flex;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--sand);border-radius:6px;padding:6px 12px;width:210px;flex-shrink:0;transition:border-color .15s;}
.nsearch:focus-within{border-color:var(--ink);}
.nsearch input{border:none;background:transparent;font-size:12.5px;font-family:'DM Sans',sans-serif;color:var(--ink);outline:none;width:100%;}
.nsearch input::placeholder{color:var(--ink);opacity:.6;}
.nsearch svg{color:var(--ink);opacity:.6;flex-shrink:0;}
.nend{display:flex;align-items:center;gap:8px;flex-shrink:0;position:relative;margin-left:auto;}
.btn-ghost{font-size:12.5px;color:var(--bg);padding:7px 14px;border-radius:6px;border:1px solid var(--bg);background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.btn-ghost:hover{border-color:var(--sand);background:var(--sand);color:var(--ink);}
.btn-dark{font-size:12.5px;color:var(--ink);padding:7px 16px;border-radius:6px;border:none;background:var(--sand);cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:500;transition:background .12s;}
.btn-dark:hover{background:#c4b69e;}

/* ─── HERO ─── */
.blog-hero{background:var(--ink);padding:52px 28px 48px;text-align:center;}
.blog-hero h1{font-family:'Playfair Display',serif;font-size:clamp(28px,3.2vw,42px);font-weight:400;color:var(--bg);margin-bottom:8px;}
.blog-hero p{font-size:14px;color:rgba(246,237,222,.55);max-width:480px;margin:0 auto;line-height:1.6;}

/* ─── LAYOUT ─── */
.wrap{max-width:var(--w);margin:0 auto;padding:0 28px;}
.sec{padding:36px 0 12px;}
.sec-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;}
.sec-title{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;color:var(--ink);}
.results-info{font-size:11.5px;color:var(--ink);opacity:.6;margin-bottom:8px;}

/* ─── POST CARD ─── */
.blog-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;}
.post-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;transition:transform .15s,box-shadow .15s;cursor:pointer;display:flex;flex-direction:column;}
.post-card:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(12,63,48,.09);}
.pc-img{aspect-ratio:4/3;overflow:hidden;position:relative;background:var(--sand);}
.pc-img img{width:100%;height:100%;object-fit:contain;transition:transform .3s;}
.post-card:hover .pc-img img{transform:scale(1.04);}
.pc-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
.pc-ph svg{opacity:.16;color:var(--ink);}
.pc-body{padding:16px 18px 18px;flex:1;display:flex;flex-direction:column;}
.pc-title{font-family:'Playfair Display',serif;font-size:17px;font-weight:400;color:var(--ink);line-height:1.3;margin-bottom:8px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.pc-excerpt{font-size:12.5px;color:var(--ink);opacity:.65;line-height:1.55;margin-bottom:14px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;flex:1;}
.pc-meta{display:flex;align-items:center;justify-content:space-between;font-size:11px;color:var(--ink);opacity:.5;}
.pc-meta span{display:flex;align-items:center;gap:4px;}
.pc-meta svg{width:12px;height:12px;}

/* ─── EMPTY ─── */
.empty-state{text-align:center;padding:64px 24px;color:var(--ink);font-size:14px;}
.empty-state svg{opacity:.15;margin-bottom:16px;}

/* ─── PAGINATION ─── */
.pagination{display:flex;align-items:center;justify-content:center;gap:4px;margin:32px 0 12px;}
.page-btn{padding:7px 13px;font-size:11.5px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--ink);cursor:pointer;font-family:'DM Sans',sans-serif;text-decoration:none;transition:all .12s;}
.page-btn:hover{border-color:var(--ink);color:var(--ink);}
.page-btn.active{background:var(--ink);color:var(--bg);border-color:var(--ink);}
.page-btn.disabled{opacity:.35;pointer-events:none;}

/* ─── FOOTER ─── */
.footer{background:var(--ink);color:var(--bg);margin-top:56px;}
.fw{max-width:var(--w);margin:0 auto;padding:40px 28px 26px;}
.fg-foot{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:32px;}
.fb b{font-family:'Playfair Display',serif;font-size:17px;color:var(--bg);display:block;margin-bottom:7px;}
.fb p{font-size:12.5px;line-height:1.65;max-width:230px;}
.fc h4{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:11px;}
.fc a{display:block;font-size:12.5px;color:rgba(246,237,222,.42);margin-bottom:8px;transition:color .12s;}
.fc a:hover{color:var(--bg);}
.fbot{border-top:1px solid rgba(246,237,222,.07);padding-top:18px;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;}

/* ─── DRAWER ─── */
#nav-drawer{display:none;}
#nav-overlay{display:none;}

/* ─── RESPONSIVE ─── */
@media(max-width:1080px){
  .blog-grid{grid-template-columns:repeat(2,1fr);}
  .fg-foot{grid-template-columns:1fr 1fr;}
}
@media(max-width:768px){
  .nlinks,.nsearch{display:none;}
  .wrap{padding:0 16px;}
  .blog-hero{padding:36px 16px 40px;}
  .blog-grid{grid-template-columns:repeat(2,1fr);}
  .fg-foot{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 16px;}
  .fc{display:none;}
  .fb{margin-bottom:12px;}
  .fb b{font-size:16px;}
  .fb p{font-size:10px;}
  .fbot{flex-direction:column;gap:12px;text-align:center;font-size:10px;padding-top:14px;}
  .nend .btn-ghost,.nend .btn-dark,.nend span{display:none;}
  .ham-btn{display:flex;flex-direction:column;justify-content:center;gap:5px;background:transparent;border:none;cursor:pointer;padding:6px;margin-left:auto;}
  .ham-btn span{display:block;width:22px;height:2px;background:var(--bg);border-radius:2px;}
  #nav-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:298;}
  #nav-overlay.open{display:block;}
  #nav-drawer{display:flex;flex-direction:column;position:fixed;top:0;right:0;width:75vw;max-width:300px;height:100vh;background:var(--ink);z-index:299;transform:translateX(100%);transition:transform .3s ease;padding:0;overflow-y:auto;}
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
  section[style*="grid-template-columns:1fr 1fr"]{grid-template-columns:1fr!important;}
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
      <a href="artworks.php" class="dd">Explore Art</a>
      <a href="artists.php">Artists</a>
      <a href="blog.php">Blog</a>
      <a href="commission.php">Custom Artwork</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
    </div>
    <div class="nsearch">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input type="text" placeholder="Search artworks, artists..." onkeydown="if(event.key==='Enter'){window.location='artworks.php?q='+encodeURIComponent(this.value);}">
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
      <button class="ham-btn" aria-label="Open menu"><span></span><span></span><span></span></button>
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

<!-- HERO -->
<section style="position:relative;min-height:280px;display:flex;align-items:center;">
  <img src="bloghero.jpeg" alt="Blog" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;display:block;">
  <div style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(12,63,48,0.65);"></div>
  <div style="position:relative;z-index:1;padding:52px 48px;">
    <div style="font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:12px;">OUR BLOG</div>
    <h1 style="font-family:'Playfair Display',serif;font-size:clamp(28px,3.2vw,42px);font-weight:400;color:var(--bg);margin-bottom:12px;">From Our Blog</h1>
    <p style="font-size:14px;color:rgba(246,237,222,.55);max-width:420px;line-height:1.6;">Stories, insights, and inspiration from Pakistani artists and the local art community.</p>
  </div>
</section>

<!-- POSTS -->
<div class="wrap">
  <div class="sec">
    <div class="results-info">Showing <?= count($posts) ?> of <?= $totalResults ?> posts</div>
    
    <?php if (empty($posts)): ?>
    <div class="empty-state">
      <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/></svg>
      <p>No posts found yet. Check back soon!</p>
    </div>
    <?php else: ?>
    <div class="blog-grid">
      <?php foreach ($posts as $p): ?>
      <a href="blog-post.php?slug=<?= htmlspecialchars($p['slug']) ?>" class="post-card">
        <div class="pc-img">
          <?php if ($p['featured_image']): ?>
            <img src="<?= htmlspecialchars($p['featured_image']) ?>" alt="" loading="lazy">
          <?php else: ?>
          <div class="pc-ph">
            <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/><path d="M17 16l2 2"/></svg>
          </div>
          <?php endif; ?>
        </div>
        <div class="pc-body">
          <div class="pc-title"><?= htmlspecialchars($p['title']) ?></div>
          <div class="pc-excerpt"><?= htmlspecialchars(mb_substr(strip_tags($p['content'] ?? ''), 0, 120)) ?>...</div>
          <div class="pc-meta">
            <span>
              <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <?= htmlspecialchars($p['author_name']) ?>
            </span>
            <span>
              <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <?= date('M j, Y', strtotime($p['published_at'] ?? $p['created_at'])) ?>
            </span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- PAGINATION -->
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
    if ($start > 1) { echo '<a href="?'.buildQS(['page'=>1]).'" class="page-btn">1</a>'; if ($start > 2) echo '<span class="page-btn disabled">...</span>'; }
    for ($i = $start; $i <= $end; $i++) {
        echo '<a href="?'.buildQS(['page'=>$i]).'" class="page-btn '.($i === $page ? 'active' : '').'">'.$i.'</a>';
    }
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

<!-- FOOTER -->
<footer class="footer">
  <div class="fw">
    <div class="fg-foot">
      <div class="fb"><b>Art Bazaar</b><p>Pakistan's premier marketplace for original art. Connecting talented Pakistani artists with art lovers across the country.</p></div>
      <div class="fc"><h4>Explore</h4><a href="artworks.php">All Artworks</a><a href="artists.php">All Artists</a><a href="blog.php">Blog</a><a href="artworks.php?featured=1">Featured</a></div>
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
const hamBtn=document.querySelector('.ham-btn');
const navDrawer=document.getElementById('nav-drawer');
const navOverlay=document.getElementById('nav-overlay');
function openDrawer(){navDrawer.classList.add('open');navOverlay.classList.add('open');document.body.style.overflow='hidden';}
function closeDrawer(){navDrawer.classList.remove('open');navOverlay.classList.remove('open');document.body.style.overflow='';}
if(hamBtn)hamBtn.addEventListener('click',openDrawer);
if(navOverlay)navOverlay.addEventListener('click',closeDrawer);
document.querySelector('.drawer-close')?.addEventListener('click',closeDrawer);
</script>
</body>
</html>