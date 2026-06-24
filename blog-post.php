<?php
session_start();
require_once __DIR__ . '/config/db.php';



function getProfileUrl($p) {
    if (!$p) return null;
    $p = ltrim($p, './');
    if (strpos($p, 'uploads/') !== false) return $p;
    return 'uploads/profiles/' . $p;
}

 $isLoggedIn = isset($_SESSION['user_id']) && $_SESSION['role'] === 'buyer';

// ── Fetch Post ─────────────────────────────────────────
 $slug = trim($_GET['slug'] ?? '');
 $post = null;

if ($slug) {
    $stmt = $conn->prepare("
        SELECT bp.*, 
               u.name AS author_name, u.profile_picture, u.role AS author_role,
               ap.bio AS author_bio, ap.art_style AS author_style, ap.city AS author_city
        FROM blog_posts bp
        JOIN users u ON u.id = bp.author_id
        LEFT JOIN artist_profiles ap ON ap.user_id = u.id
        WHERE bp.slug = ? AND bp.status = 'published'
    ");
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $post = $stmt->get_result()->fetch_assoc();
}

if (!$post) {
    header('Location: blog.php');
    exit;
}

// Increment views
 $conn->query("UPDATE blog_posts SET views = views + 1 WHERE id = " . (int)$post['id']);

// Parse tags
 $tags = [];
if (!empty($post['tags'])) {
    $rawTags = explode(',', $post['tags']);
    foreach ($rawTags as $t) {
        $t = trim($t);
        if ($t) $tags[] = $t;
    }
}

// Related posts
 $relatedPosts = [];
 $postId = (int)$post['id'];
 $relRes = $conn->query("
    SELECT bp.id, bp.title, bp.slug, bp.featured_image, bp.published_at, bp.created_at,
           u.name AS author_name
    FROM blog_posts bp
    JOIN users u ON u.id = bp.author_id
    WHERE bp.id != $postId AND bp.status = 'published'
    ORDER BY bp.published_at DESC
    LIMIT 3
");
while ($row = $relRes->fetch_assoc()) $relatedPosts[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($post['title']) ?> — Art Bazaar Blog</title>
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

/* ─── BREADCRUMB ─── */
.breadcrumb{max-width:780px;margin:0 auto;padding:20px 28px 0;font-size:12px;color:var(--ink);opacity:.55;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.breadcrumb a{color:var(--ink);opacity:.7;transition:opacity .12s;}
.breadcrumb a:hover{opacity:1;}
.breadcrumb .sep{opacity:.4;}
.breadcrumb .current{opacity:.85;font-weight:500;}

/* ─── ARTICLE LAYOUT ─── */
.article-wrap{max-width:780px;margin:0 auto;padding:24px 28px 40px;}

/* Featured Image */
.featured-img{width:100%;max-height:480px;object-fit:contain;border-radius:14px;margin-bottom:24px;background:var(--sand);}
.featured-img-ph{width:100%;height:320px;border-radius:14px;background:var(--sand);display:flex;align-items:center;justify-content:center;margin-bottom:24px;}
.featured-img-ph svg{opacity:.15;color:var(--ink);}

/* Meta bar */
.meta-bar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;}
.cat-badge{display:inline-block;font-size:9px;letter-spacing:.5px;text-transform:uppercase;font-weight:600;padding:4px 10px;border-radius:20px;background:var(--ink);color:var(--bg);}
.meta-date{font-size:12px;color:var(--ink);opacity:.5;}
.meta-views{font-size:11px;color:var(--ink);opacity:.4;margin-left:auto;display:flex;align-items:center;gap:4px;}
.meta-views svg{width:13px;height:13px;}

/* Title */
.post-title{font-family:'Playfair Display',serif;font-size:clamp(26px,3vw,36px);font-weight:400;line-height:1.2;color:var(--ink);margin-bottom:20px;}

/* Author row */
.author-row{display:flex;align-items:center;gap:12px;margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid var(--sand);}
.author-av{width:42px;height:42px;border-radius:50%;overflow:hidden;background:var(--sand);border:2px solid var(--border);flex-shrink:0;}
.author-av img{width:100%;height:100%;object-fit:cover;}
.author-av-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-family:'Playfair Display',serif;font-size:17px;color:var(--ink);font-weight:500;}
.author-info{display:flex;flex-direction:column;gap:1px;}
.author-name{font-size:13.5px;font-weight:600;color:var(--ink);}
.author-sub{font-size:11.5px;color:var(--ink);opacity:.5;}

/* Post Content */
.post-content{font-size:15px;line-height:1.75;color:var(--ink);margin-bottom:32px;}
.post-content p{margin-bottom:16px;}
.post-content h2{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;margin:28px 0 12px;color:var(--ink);}
.post-content h3{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;margin:24px 0 10px;color:var(--ink);}
.post-content ul,.post-content ol{margin:0 0 16px 22px;}
.post-content li{margin-bottom:6px;}
.post-content blockquote{border-left:3px solid var(--sand);padding:10px 20px;margin:20px 0;font-style:italic;opacity:.85;}
.post-content img{border-radius:8px;margin:16px auto;object-fit:contain;max-height:480px;}
.post-content a{color:var(--ink);text-decoration:underline;text-underline-offset:2px;}

/* Tags */
.tags-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:28px;}
.tags-label{font-size:10.5px;letter-spacing:1px;text-transform:uppercase;color:var(--ink);opacity:.5;font-weight:600;}
.tag-chip{display:inline-block;font-size:11px;font-weight:500;padding:5px 12px;border-radius:20px;border:1px solid var(--border);background:transparent;color:var(--ink);transition:all .12s;cursor:pointer;}
.tag-chip:hover{background:var(--sand);border-color:var(--sand);}

/* Divider */
.divhr{border:none;border-top:1px solid var(--border);margin:8px 0 32px;}

/* ─── RELATED POSTS ─── */
.related-section{max-width:var(--w);margin:0 auto;padding:0 28px 48px;}
.related-hd{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;color:var(--ink);margin-bottom:18px;}
.related-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;}

/* Post Card (same as blog.php) */
.post-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;transition:transform .15s,box-shadow .15s;cursor:pointer;display:flex;flex-direction:column;}
.post-card:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(12,63,48,.09);}
.pc-img{aspect-ratio:4/3;overflow:hidden;position:relative;background:var(--sand);}
.pc-img img{width:100%;height:100%;object-fit:contain;transition:transform .3s;}
.post-card:hover .pc-img img{transform:scale(1.04);}
.pc-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
.pc-ph svg{opacity:.16;color:var(--ink);}
.pc-cat{position:absolute;top:10px;left:10px;font-size:9px;letter-spacing:.5px;text-transform:uppercase;font-weight:600;padding:3px 9px;border-radius:20px;background:var(--ink);color:var(--bg);}
.pc-body{padding:16px 18px 18px;flex:1;display:flex;flex-direction:column;}
.pc-title{font-family:'Playfair Display',serif;font-size:17px;font-weight:400;color:var(--ink);line-height:1.3;margin-bottom:8px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.pc-excerpt{font-size:12.5px;color:var(--ink);opacity:.65;line-height:1.55;margin-bottom:14px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;flex:1;}
.pc-meta{display:flex;align-items:center;justify-content:space-between;font-size:11px;color:var(--ink);opacity:.5;}
.pc-meta span{display:flex;align-items:center;gap:4px;}
.pc-meta svg{width:12px;height:12px;}

/* ─── FOOTER ─── */
.footer{background:var(--ink);color:var(--bg);margin-top:0;}
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
  .related-grid{grid-template-columns:repeat(2,1fr);}
  .fg-foot{grid-template-columns:1fr 1fr;}
}
@media(max-width:768px){
  .nlinks,.nsearch{display:none;}
  .article-wrap{padding:20px 16px 32px;}
  .breadcrumb{padding:16px 16px 0;}
  .related-section{padding:0 16px 36px;}
  .related-grid{grid-template-columns:repeat(2,1fr);}
  .featured-img{max-height:280px;}
  .featured-img-ph{height:200px;}
  .post-title{font-size:24px;}
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
<!-- BREADCRUMB -->
<div class="breadcrumb">
  <a href="index.php">Home</a>
  <span class="sep">→</span>
  <a href="blog.php">Blog</a>
  <span class="sep">→</span>
  <span class="current"><?= htmlspecialchars(mb_strimwidth($post['title'], 0, 50, '...')) ?></span>
</div>

<!-- ARTICLE -->
<article class="article-wrap">

  <?php if ($post['featured_image']): ?>
  <img class="featured-img" src="<?= htmlspecialchars($post['featured_image']) ?>" alt="<?= htmlspecialchars($post['title']) ?>">
  <?php else: ?>
  <div class="featured-img-ph">
    <svg width="56" height="56" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/><path d="M17 16l2 2"/></svg>
  </div>
  <?php endif; ?>

  <!-- Meta bar -->
  <div class="meta-bar">
    <span class="meta-date"><?= date('F j, Y', strtotime($post['published_at'] ?? $post['created_at'])) ?></span>
    <span class="meta-views">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
      <?= number_format($post['views'] + 1) ?> views
    </span>
  </div>

  <!-- Title -->
  <h1 class="post-title"><?= htmlspecialchars($post['title']) ?></h1>

  <!-- Content -->
  <div class="post-content">
    <?php 
      // Check if content has HTML tags — if not, apply nl2br
      $content = $post['content'];
      if ($content !== strip_tags($content)) {
        // Content has HTML, output as-is
        echo $content;
      } else {
        // Plain text, convert newlines
        echo nl2br(htmlspecialchars($content));
      }
    ?>
  </div>

  <hr class="divhr">

</article>

<!-- RELATED POSTS -->
<?php if (!empty($relatedPosts)): ?>
<section class="related-section">
  <h2 class="related-hd">More from the Blog</h2>
  <div class="related-grid">
    <?php foreach ($relatedPosts as $rp): ?>
    <a href="blog-post.php?slug=<?= htmlspecialchars($rp['slug']) ?>" class="post-card">
      <div class="pc-img">
        <?php if ($rp['featured_image']): ?>
        <img src="<?= htmlspecialchars($rp['featured_image']) ?>" alt="" loading="lazy">
        <?php else: ?>
        <div class="pc-ph">
          <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/><path d="M17 16l2 2"/></svg>
        </div>
        <?php endif; ?>
      </div>
      <div class="pc-body">
        <div class="pc-title"><?= htmlspecialchars($rp['title']) ?></div>
        <div class="pc-meta">
          <span>
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <?= date('M j, Y', strtotime($rp['published_at'] ?? $rp['created_at'])) ?>
          </span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

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