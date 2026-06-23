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
<title>Sell Your Art — Art Bazaar</title>
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

/* WHY SECTION */
.why-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:24px;margin-bottom:56px;}
.why-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px 20px;text-align:center;transition:transform .15s;}
.why-card:hover{transform:translateY(-4px);}
.why-icon{width:56px;height:56px;background:var(--sand);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;color:var(--ink);}
.why-card h4{font-size:16px;font-weight:600;margin-bottom:10px;}
.why-card p{font-size:12.5px;color:var(--muted);line-height:1.6;}

/* HOW IT WORKS */
.how-title{font-family:'Playfair Display',serif;font-size:28px;font-weight:400;text-align:center;margin-bottom:40px;}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:32px;margin-bottom:56px;}
.step{text-align:center;}
.step-num{width:56px;height:56px;background:var(--ink);color:var(--bg);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Playfair Display',serif;font-size:24px;font-weight:400;margin:0 auto 16px;}
.step h4{font-size:16px;font-weight:600;margin-bottom:8px;}
.step p{font-size:12.5px;color:var(--muted);line-height:1.6;}

/* FAQ SECTION */
.faq-section{background:var(--sand);border-radius:20px;padding:48px;margin-bottom:48px;}
.faq-title{font-family:'Playfair Display',serif;font-size:24px;font-weight:400;text-align:center;margin-bottom:32px;}
.faq-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:28px;}
.faq-item h4{font-size:15px;font-weight:600;margin-bottom:8px;}
.faq-item p{font-size:12.5px;color:var(--body);line-height:1.6;}

/* CTA */
.cta-section{background:var(--ink);border-radius:20px;padding:48px 40px;text-align:center;}
.cta-section h3{font-family:'Playfair Display',serif;font-size:28px;font-weight:400;color:var(--bg);margin-bottom:12px;}
.cta-section p{color:rgba(246,237,222,.6);margin-bottom:24px;}
.cta-buttons{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}
.cta-btn{padding:12px 32px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;font-family:'DM Sans',sans-serif;}
.cta-btn.primary{background:var(--sand);color:var(--ink);border:none;}
.cta-btn.primary:hover{background:#c4b69e;}
.cta-btn.secondary{background:transparent;border:1px solid rgba(246,237,222,.2);color:var(--bg);}
.cta-btn.secondary:hover{border-color:var(--sand);color:var(--bg);}

/* TESTIMONIAL */
.testimonial{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:32px;margin-bottom:48px;text-align:center;}
.testimonial p{font-family:'Playfair Display',serif;font-size:18px;font-style:italic;color:var(--body);max-width:700px;margin:0 auto 20px;line-height:1.5;}
.testimonial-author{font-weight:600;color:var(--ink);}
.testimonial-role{font-size:11px;color:var(--muted);}

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
  .why-grid{grid-template-columns:repeat(2,1fr);}
  .steps{grid-template-columns:1fr;gap:24px;}
  .faq-grid{grid-template-columns:1fr;}
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
  .why-grid{grid-template-columns:1fr;}
  .faq-section{padding:28px;}
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
      <a href="sell.php" class="active">Sell Your Art</a>
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

<!-- HERO -->
<section class="hero" style="padding:0;position:relative;">
  <img src="sellhero.jpeg" alt="Sell Your Art" style="width:100%;max-height:480px;object-fit:cover;display:block;">
  <div style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(12,63,48,0.55);display:flex;align-items:center;">
    <div class="hero-inner" style="padding:0 28px;">
      <div class="hero-tag">FOR ARTISTS</div>
      <h1>Share your art with the world.<br><em>Start selling</em> on Art Bazaar.</h1>
      <p class="hero-desc">Join a growing community of Pakistani artists who are reaching collectors across the country — with zero upfront cost and fair commission rates.</p>
    </div>
  </div>
</section>

<!-- MAIN CONTENT -->
<div class="main">

  <!-- WHY SELL HERE -->
  <div class="why-grid">
    <div class="why-card">
      <div class="why-icon"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg></div>
      <h4>Reach more buyers</h4>
      <p>Thousands of art lovers visit Art Bazaar every month looking for original Pakistani art. Your work gets seen.</p>
    </div>
    <div class="why-card">
      <div class="why-icon"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></div>
      <h4>Commission requests</h4>
      <p>Buyers can request custom work directly from you. Turn your skills into personalized creations.</p>
    </div>
    <div class="why-card">
      <div class="why-icon"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
      <h4>Full support</h4>
      <p>Our team helps with inquiries, shipping coordination, and any questions along the way.</p>
    </div>
  </div>

  <!-- HOW IT WORKS STEPS -->
  <h2 class="how-title">How to start selling</h2>
  <div class="steps">
    <div class="step">
      <div class="step-num">1</div>
      <h4>Create an artist account</h4>
      <p>Sign up as an artist — it's free. Fill out your profile with your bio, city, art style, and profile picture.</p>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <h4>Upload your artwork</h4>
      <p>Add high-quality images, set your price, choose a category, and describe your creative process.</p>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <h4>Start selling</h4>
      <p>Once approved, your artwork appears in our marketplace. Buyers can contact you directly through the platform.</p>
    </div>
  </div>

  <!-- FAQ -->
  <div class="faq-section">
    <h3 class="faq-title">Frequently asked questions</h3>
    <div class="faq-grid">
      <div class="faq-item">
        <h4>How much does it cost to join?</h4>
        <p>Joining Art Bazaar is completely free. There are no upfront fees or subscription costs. We only take a small commission when you make a sale.</p>
      </div>
      <div class="faq-item">
        <h4>How do I get paid?</h4>
        <p>When a buyer purchases your artwork, our team coordinates the payment and shipping. You'll receive your payment via bank transfer after delivery confirmation.</p>
      </div>
      <div class="faq-item">
        <h4>What kind of art can I sell?</h4>
        <p>Not just paintings — all types of original artwork are welcome, including sketches, digital art, calligraphy, photography, and mixed media. We don't accept prints or reproductions of other artists' work.</p>
      </div>
      <div class="faq-item">
        <h4>How long does approval take?</h4>
        <p>Artwork submissions are reviewed within 2-3 business days. Once approved, your artwork appears immediately in the marketplace.</p>
      </div>
      <div class="faq-item">
        <h4>Can I accept custom commissions?</h4>
        <p>Yes! Enable commissions in your profile settings. Buyers can request custom work, and you'll be notified when a request comes in.</p>
      </div>
    </div>
  </div>

  <!-- CTA -->
  <div class="cta-section">
    <h3>Ready to share your art?</h3>
    <p>Join Art Bazaar today and start reaching collectors who love Pakistani art.</p>
    <div class="cta-buttons">
      <a href="register.php" class="cta-btn primary">Create Artist Account</a>
      <a href="artworks.php" class="cta-btn secondary">Browse the Marketplace</a>
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
    <div class="drawer-logo"><b>Art Bazaar</b><small>Marketplace</small></div>
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