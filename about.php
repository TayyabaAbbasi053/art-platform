<?php
session_start();
require_once __DIR__ . '/config/db.php';



 $isLoggedIn = isset($_SESSION['user_id']) && $_SESSION['role'] === 'buyer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>About Us — Art Bazaar</title>
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

/* MAIN CONTENT */
.main{max-width:var(--w);margin:0 auto;padding:48px 28px;}
.section{margin-bottom:48px;}
.section-title{font-family:'Playfair Display',serif;font-size:28px;font-weight:400;color:var(--ink);margin-bottom:20px;text-align:center;}
.section-sub{text-align:center;color:var(--muted);max-width:680px;margin:0 auto 40px;font-size:15px;}

/* MISSION STATEMENT */
.mission-card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:40px;text-align:center;margin-bottom:48px;}
.mission-icon{margin-bottom:20px;}
.mission-icon svg{stroke:var(--ink);}
.mission-card h3{font-family:'Playfair Display',serif;font-size:24px;font-weight:400;margin-bottom:16px;}
.mission-card p{color:var(--body);line-height:1.7;max-width:700px;margin:0 auto;}

/* VALUES GRID */
.values-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:28px;margin-bottom:48px;}
.value-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px 24px;text-align:center;transition:transform .15s;}
.value-card:hover{transform:translateY(-4px);}
.value-icon{width:56px;height:56px;background:var(--sand);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;color:var(--ink);}
.value-card h4{font-size:16px;font-weight:600;margin-bottom:10px;}
.value-card p{font-size:13px;color:var(--muted);line-height:1.6;}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:48px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px 20px;text-align:center;}
.stat-number{font-family:'Playfair Display',serif;font-size:36px;font-weight:500;color:var(--ink);}
.stat-label{font-size:12px;color:var(--muted);margin-top:6px;letter-spacing:.5px;}

/* TEAM / STORY TWO COLUMN */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-bottom:48px;}
.col-img{background:var(--sand);border-radius:16px;overflow:hidden;min-height:280px;display:flex;align-items:center;justify-content:center;}
.col-img svg{opacity:.2;stroke:var(--ink);}
.col-content h3{font-family:'Playfair Display',serif;font-size:24px;font-weight:400;margin-bottom:16px;}
.col-content p{color:var(--body);line-height:1.7;margin-bottom:16px;}

/* CTA SECTION */
.cta-section{background:var(--sand);border-radius:20px;padding:48px 40px;text-align:center;}
.cta-section h3{font-family:'Playfair Display',serif;font-size:26px;font-weight:400;margin-bottom:12px;}
.cta-section p{color:var(--body);margin-bottom:24px;}
.cta-buttons{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}
.cta-btn{padding:10px 28px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;font-family:'DM Sans',sans-serif;}
.cta-btn.primary{background:var(--ink);color:var(--bg);border:none;}
.cta-btn.primary:hover{background:var(--body);}
.cta-btn.secondary{background:transparent;border:1px solid var(--border);color:var(--body);}
.cta-btn.secondary:hover{border-color:var(--ink);color:var(--ink);}

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
  .values-grid{grid-template-columns:repeat(2,1fr);}
  .stats-grid{grid-template-columns:repeat(2,1fr);}
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
  .drawer-btn-ghost{font-size:13px;color:var(--bg);padding:9px 14px;border-radius:6px;border:1px solid rgba(246,237,222,0.4);text-align:center;}
  .drawer-btn-ghost:hover{border-color:var(--sand);background:rgba(246,237,222,0.08);}
  .drawer-btn-dark{font-size:13px;color:var(--ink);padding:9px 14px;border-radius:6px;background:var(--sand);text-align:center;font-weight:500;}
  .drawer-btn-dark:hover{background:#c4b69e;}

  /* Layout */
  .values-grid{grid-template-columns:1fr;}
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .two-col{grid-template-columns:1fr;}
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
      <a href="commission.php">Commission Art</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php" class="active">About Us</a>
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

<!-- HERO -->
<section class="hero" style="padding:0;position:relative;min-height:280px;display:flex;align-items:center;">
  <img src="abouthero.jpeg" alt="About Us" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;display:block;">
  <div style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(12,63,48,0.65);"></div>
  <div class="hero-inner" style="position:relative;z-index:1;padding:52px 28px;">
    <div class="hero-tag">OUR STORY</div>
    <h1>A platform built for<br><em>Pakistani artists</em> and art lovers.</h1>
    <p class="hero-desc">Art Bazaar was founded to create a space where Pakistani artists can showcase their work and connect with collectors — without middlemen or unfair commissions.</p>
  </div>
</section>

<!-- MAIN CONTENT -->
<div class="main">

  <!-- MISSION STATEMENT -->
  <div class="mission-card">
    <div class="mission-icon">
      <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
    </div>
    <h3>Empowering Pakistani creativity</h3>
    <p>Art Bazaar is more than a marketplace — it's a movement to celebrate and support the incredible artistic talent across Pakistan. We believe every artist deserves a platform to shine, and every art lover deserves access to authentic, original work.</p>
  </div>

  <!-- VALUES -->
  <div class="section">
    <h2 class="section-title">What we believe</h2>
    <div class="values-grid">
      <div class="value-card">
        <div class="value-icon"><svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></div>
        <h4>Fair for artists</h4>
        <p>No hidden fees, no predatory commissions. Artists keep the majority of their earnings.</p>
      </div>
      <div class="value-card">
        <div class="value-icon"><svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
        <h4>Authenticity first</h4>
        <p>Every artwork is verified by our team before it appears on the platform.</p>
      </div>
      <div class="value-card">
        <div class="value-icon"><svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
        <h4>Local roots</h4>
        <p>Proudly Pakistani, built by Pakistanis for the Pakistani art community and beyond.</p>
      </div>
    </div>
  </div>

  <!-- STATS -->
  <?php
// Fetch live stats
$artistCount = 0;
$artworkCount = 0;
$cityCount = 0;

$r = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role='artist'");
if ($r) $artistCount = (int)$r->fetch_assoc()['cnt'];

$r = $conn->query("SELECT COUNT(*) as cnt FROM artworks");
if ($r) $artworkCount = (int)$r->fetch_assoc()['cnt'];

$r = $conn->query("
    SELECT COUNT(DISTINCT ap.city) as cnt 
    FROM artist_profiles ap 
    WHERE ap.city IS NOT NULL AND ap.city != ''
");
if ($r) $cityCount = (int)$r->fetch_assoc()['cnt'];
?>
<!-- STATS -->
<div class="section">
  <div class="stats-grid">
    <div class="stat-card"><div class="stat-number"><?= $artistCount ?>+</div><div class="stat-label">Artists</div></div>
    <div class="stat-card"><div class="stat-number"><?= $artworkCount ?>+</div><div class="stat-label">Artworks</div></div>
    <div class="stat-card"><div class="stat-number"><?= $cityCount ?>+</div><div class="stat-label">Cities</div></div>
    <div class="stat-card"><div class="stat-number">100%</div><div class="stat-label">Pakistani</div></div>
  </div>
</div>

  <!-- STORY TWO COLUMN -->
  <div class="two-col">
    <div class="col-img">
  <img src="about1.jpeg" alt="How Art Bazaar started" style="width:100%;height:100%;object-fit:cover;">
</div>
    <div class="col-content">
      <h3>How Art Bazaar started</h3>
      <p>Art Bazaar was born from a simple observation: talented Pakistani artists had limited ways to reach buyers, and art lovers struggled to find authentic local art. Galleries took high commissions, online marketplaces were impersonal, and shipping was complicated.</p>
      <p>We built Art Bazaar to solve this. A dedicated platform that handles the messy parts — inquiries, commission management, artist verification — so artists can focus on their craft and buyers can focus on finding art they love.</p>
    </div>
  </div>

  <div class="two-col" style="margin-bottom:0;">
    <div class="col-content">
      <h3>What makes us different</h3>
      <p>Unlike international platforms that take up to 50% commission, Art Bazaar takes a minimal fee to keep the lights on — nothing more. We believe that when artists thrive, the entire community thrives.</p>
      <p>We also offer personalized support for every commission request, connecting buyers with the perfect artist for their vision. Whether you're looking for a contemporary painting or a traditional calligraphy piece, we're here to help.</p>
    </div>
    <div class="col-img">
  <img src="about2.jpeg" alt="What makes us different" style="width:100%;height:100%;object-fit:cover;">
</div>
  </div>

  <!-- CTA SECTION -->
  <div class="cta-section">
    <h3>Join our creative community</h3>
    <p>Whether you're an artist looking to share your work or a collector searching for the perfect piece — we'd love to have you.</p>
    <div class="cta-buttons">
      <a href="register.php" class="cta-btn primary">Join as Artist</a>
      <a href="artworks.php" class="cta-btn secondary">Explore Artworks</a>
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