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
<title>Artist Terms — Art Bazaar Pakistan</title>
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
  --terr:#0C3F30;
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
.nend{display:flex;align-items:center;gap:8px;flex-shrink:0;position:relative;margin-left:auto;}
.btn-ghost{font-size:12.5px;color:var(--bg);padding:7px 14px;border-radius:6px;border:1px solid var(--bg);background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.btn-ghost:hover{border-color:var(--sand);background:var(--sand); color: var(--ink);}
.btn-dark{font-size:12.5px;color:var(--ink);padding:7px 16px;border-radius:6px;border:none;background:var(--sand);cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:500;transition:background .12s;}
.btn-dark:hover{background:#c4b69e;}

/* ─── LAYOUT ─── */
.wrap{max-width:var(--w);margin:0 auto;padding:0 28px;}
.divhr{border:none;border-top:1px solid var(--border);}

/* ─── TERMS CONTENT SPECIFIC ─── */
.page-hd{padding:44px 0 24px;text-align:center;}
.page-hd h1{font-family:'Playfair Display',serif;font-size:clamp(28px,3.4vw,42px);font-weight:400;color:var(--ink);margin-bottom:6px;}
.page-hd p{font-size:12.5px;color:var(--ink);opacity:.7;}

.terms-container{max-width:860px;margin:0 auto;padding:0 28px 60px;}

.terms-section{margin-bottom:28px;}
.terms-section-title{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--ink);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--sand);}
.terms-text{font-size:13.5px;line-height:1.7;color:var(--ink);margin-bottom:12px;}

.terms-list{list-style:none;padding:0;margin:0 0 12px 0;}
.terms-list li{position:relative;padding-left:20px;margin-bottom:6px;font-size:13.5px;line-height:1.6;color:var(--ink);}
.terms-list li::before{content:'';position:absolute;left:0;top:9px;width:6px;height:6px;border-radius:50%;background:var(--sand);}

.terms-numbered{padding-left:24px;margin:0 0 12px 0;}
.terms-numbered li{margin-bottom:8px;font-size:13.5px;line-height:1.6;color:var(--ink);}
.terms-numbered li::marker{color:var(--ink);font-weight:600;}

.terms-highlight-box{background:rgba(12,63,48,0.04);border:1px solid var(--sand);border-radius:8px;padding:16px 20px;margin-bottom:16px;}
.terms-highlight-box p{font-size:13px;line-height:1.6;margin:0;}

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

/* ─── MOBILE HAMBURGER & DRAWER ─── */
#nav-drawer { display:none; }
#nav-overlay { display:none; }
.ham-btn { display:none; }

@media(max-width:1080px){
  .fg-foot{grid-template-columns:1fr 1fr;}
}
@media(max-width:768px){
  .nlinks,.nsearch{display:none;}
  .wrap{padding:0 16px;}
  .terms-container{padding:0 16px 40px;}
  .fg-foot{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 16px;}
  .fc{display:none;}
  .fb{margin-bottom:12px;}
  .fb b{font-size:16px;}
  .fb p{font-size:10px;max-width:280px;}
  .fbot{flex-direction:column;gap:12px;text-align:center;font-size:10px;padding-top:14px;}
  .nend .btn-ghost, .nend .btn-dark, .nend span { display:none; }
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
      <a href="commission.php">Custom Artwork</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php">Contact</a>
    </div>
    <div class="nend">
      
      <?php if ($isLoggedIn): ?>
        
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

<!-- Overlay & Drawer -->
<div id="nav-overlay" onclick="closeDrawer()"></div>
<div id="nav-drawer">
  <div class="drawer-top">
    <div class="drawer-logo"><b>Art Bazaar</b><small>Pakistan</small></div>
    <button class="drawer-close" onclick="closeDrawer()">✕</button>
  </div>
  <div class="drawer-links">
    <a href="artworks.php">Explore Art</a><a href="artists.php">Artists</a><a href="blog.php">Blog</a><a href="commission.php">Custom Artwork</a><a href="sell.php">Sell Your Art</a><a href="about.php">About Us</a><a href="contact.php">Contact</a>
  </div>
  <div class="drawer-actions">
    
    <?php if ($isLoggedIn): ?>
      <a href="dashboard/buyer/account.php" class="drawer-btn-ghost">My Account</a><a href="logout.php" class="drawer-btn-dark">Logout</a>
    <?php else: ?>
      <a href="login.php" class="drawer-btn-ghost">Login</a><a href="register.php" class="drawer-btn-dark">Join as Artist</a>
    <?php endif; ?>
  </div>
</div>

<div class="wrap">
  <div class="page-hd">
    <h1>Artist Terms</h1>
    <p>Art Bazaar Pakistan — Last updated: [Add Date]</p>
  </div>
  <hr class="divhr">
</div>

<div class="terms-container">

  <div class="terms-highlight-box">
    <p>These Artist Terms explain how artists can use Art Bazaar Pakistan to create profiles, upload artworks, receive buyer requests, and manage custom artwork orders. By creating an artist account, uploading artwork, or accepting requests through Art Bazaar Pakistan, you agree to these Artist Terms, along with our Terms & Conditions and Privacy Policy.</p>
  </div>

  <!-- 1 -->
  <div class="terms-section">
    <h2 class="terms-section-title">1. Artist Account</h2>
    <p class="terms-text">Artists may create an account to showcase and sell their artwork through Art Bazaar Pakistan. Artists must provide accurate information, including:</p>
    <ul class="terms-list">
      <li>Artist name or full name</li>
      <li>Email</li>
      <li>Phone/WhatsApp number for admin use</li>
      <li>City</li>
      <li>Art style or medium</li>
      <li>Bio/about section</li>
      <li>Profile picture</li>
      <li>Artwork details</li>
    </ul>
    <p class="terms-text">Artist contact details are for admin/platform use and should not be displayed publicly unless Art Bazaar Pakistan allows it.</p>
  </div>

  <!-- 2 -->
  <div class="terms-section">
    <h2 class="terms-section-title">2. Artist Profile Approval</h2>
    <p class="terms-text">Artist profiles may not appear publicly immediately after signup. Before appearing publicly, the artist profile must be:</p>
    <ul class="terms-list">
      <li>Complete</li>
      <li>Reviewed by Art Bazaar Pakistan</li>
      <li>Approved by admin</li>
    </ul>
    <p class="terms-text">A complete artist profile should include:</p>
    <ul class="terms-list">
      <li>Bio</li>
      <li>City</li>
      <li>Art style/medium</li>
      <li>Profile picture</li>
      <li>Contact details for admin use</li>
      <li>Sample artwork or uploaded artwork</li>
    </ul>
    <p class="terms-text">Incomplete or pending profiles should not appear on the public Artists page.</p>
  </div>

  <!-- 3 -->
  <div class="terms-section">
    <h2 class="terms-section-title">3. Profile Information</h2>
    <p class="terms-text">Artists are responsible for keeping their profile information accurate and updated. Artists must not add public contact/payment details in their profile, bio, artwork descriptions, or images.</p>
    <p class="terms-text">Artists should not publicly share:</p>
    <ul class="terms-list">
      <li>Phone number</li>
      <li>WhatsApp number</li>
      <li>Email address</li>
      <li>Instagram handle for direct orders</li>
      <li>Bank details</li>
      <li>JazzCash/Easypaisa details</li>
      <li>Home address</li>
      <li>"DM me directly"</li>
      <li>"Pay me directly"</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan may edit, hide, reject, or remove profile content that breaks these rules.</p>
  </div>

  <!-- 4 -->
  <div class="terms-section">
    <h2 class="terms-section-title">4. Artwork Upload Rules</h2>
    <p class="terms-text">Artists may upload artwork to be reviewed by Art Bazaar Pakistan. Uploaded artwork must be:</p>
    <ul class="terms-list">
      <li>Original</li>
      <li>Created by the artist</li>
      <li>Properly photographed or scanned</li>
      <li>Accurately described</li>
      <li>Listed with correct price, category, size, and details</li>
    </ul>
    <p class="terms-text">Artists must not upload:</p>
    <ul class="terms-list">
      <li>Stolen artwork</li>
      <li>Copied artwork</li>
      <li>Artwork taken from Pinterest, Instagram, Google, or another artist</li>
      <li>Copyrighted characters or designs unless they have rights</li>
      <li>Misleading artwork images</li>
      <li>Offensive, illegal, or inappropriate content</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan may reject or remove artwork that appears unoriginal, misleading, low quality, or against platform rules.</p>
  </div>

  <!-- 5 -->
  <div class="terms-section">
    <h2 class="terms-section-title">5. Artwork Review</h2>
    <p class="terms-text">Uploaded artworks may go through admin review before appearing publicly. Artwork status may include:</p>
    <ul class="terms-list">
      <li>Draft</li>
      <li>Pending Review</li>
      <li>Approved</li>
      <li>Rejected</li>
      <li>Hidden</li>
      <li>Sold</li>
    </ul>
    <p class="terms-text">Pending, rejected, hidden, or unapproved artworks should not appear publicly. If an artwork is rejected, Art Bazaar Pakistan may provide a reason where possible.</p>
  </div>

  <!-- 6 -->
  <div class="terms-section">
    <h2 class="terms-section-title">6. Artwork Details</h2>
    <p class="terms-text">Artists must provide clear and accurate artwork details, including:</p>
    <ul class="terms-list">
      <li>Title</li>
      <li>Category</li>
      <li>Description</li>
      <li>Price</li>
      <li>Size</li>
      <li>Medium/material</li>
      <li>Framed or unframed status</li>
      <li>City/location</li>
      <li>Availability</li>
      <li>Whether similar custom work is accepted</li>
    </ul>
    <p class="terms-text">Artwork price should only refer to the artwork itself. Shipping fee and platform maintenance fee may be handled separately by Art Bazaar Pakistan.</p>
  </div>

  <!-- 7 -->
  <div class="terms-section">
    <h2 class="terms-section-title">7. Originality Confirmation</h2>
    <p class="terms-text">Before uploading artwork, artists may be required to confirm:</p>
    <div class="terms-highlight-box">
      <p>“I confirm this is my original artwork and I have the right to upload/sell it.”</p>
    </div>
    <p class="terms-text">If an artist uploads stolen, copied, or unauthorized artwork, Art Bazaar Pakistan may:</p>
    <ul class="terms-list">
      <li>Reject the artwork</li>
      <li>Remove the artwork</li>
      <li>Suspend the artist profile</li>
      <li>Cancel related orders</li>
      <li>Take further action if needed</li>
    </ul>
  </div>

  <!-- 8 -->
  <div class="terms-section">
    <h2 class="terms-section-title">8. Ready-Made Artwork Orders</h2>
    <p class="terms-text">When a buyer requests or orders ready-made artwork, Art Bazaar Pakistan may confirm:</p>
    <ul class="terms-list">
      <li>Artwork availability</li>
      <li>Buyer details</li>
      <li>Shipping details</li>
      <li>Final total</li>
      <li>Payment status</li>
      <li>Order status</li>
    </ul>
    <p class="terms-text">Artists should not ship or hand over artwork until Art Bazaar Pakistan confirms the order process. Artists must keep listed artworks available and update availability if the artwork is sold elsewhere or no longer available.</p>
  </div>

  <!-- 9 -->
  <div class="terms-section">
    <h2 class="terms-section-title">9. Custom Artwork Requests</h2>
    <p class="terms-text">Artists may receive custom artwork requests through Art Bazaar Pakistan. Custom requests may include:</p>
    <ul class="terms-list">
      <li>Buyer description</li>
      <li>Artwork type</li>
      <li>Reference image</li>
      <li>Estimated budget</li>
      <li>Preferred deadline</li>
      <li>Size</li>
      <li>Style</li>
      <li>Delivery city</li>
    </ul>
    <p class="terms-text">Artists should review the request carefully before accepting or quoting. Custom artwork should not begin until the final quote, timeline, and payment process are confirmed through Art Bazaar Pakistan.</p>
  </div>

  <!-- 10 -->
  <div class="terms-section">
    <h2 class="terms-section-title">10. Pricing and Quotes</h2>
    <p class="terms-text">For custom artwork, artists may suggest a price based on:</p>
    <ul class="terms-list">
      <li>Size</li>
      <li>Detail level</li>
      <li>Medium/material</li>
      <li>Time required</li>
      <li>Deadline</li>
      <li>Framing</li>
      <li>Complexity</li>
      <li>Revisions</li>
    </ul>
    <p class="terms-text">The buyer’s budget is only an estimate. The final quote should be confirmed through Art Bazaar Pakistan. The final total may include:</p>
    <ul class="terms-list">
      <li>Artist artwork price</li>
      <li>Shipping fee</li>
      <li>Maintenance/platform fee</li>
      <li>Packaging or handling charges, if applicable</li>
    </ul>
  </div>

  <!-- 11 -->
  <div class="terms-section">
    <h2 class="terms-section-title">11. Maintenance / Platform Fee</h2>
    <p class="terms-text">Art Bazaar Pakistan may charge a maintenance/platform fee for managing the website, requests, orders, communication, review, and platform operations. This should not be described as "commission" in public wording. Artists should not mislead buyers about platform fees or ask buyers to avoid the fee by dealing outside the platform.</p>
  </div>

  <!-- 12 -->
  <div class="terms-section">
    <h2 class="terms-section-title">12. Payment Rules</h2>
    <div class="terms-highlight-box">
      <p>Official payment instructions should only come from Art Bazaar Pakistan. Artists must not ask buyers for: Direct bank transfer, JazzCash/Easypaisa payment, Card/payment details, Direct WhatsApp payment, Any off-platform payment arrangement. Art Bazaar Pakistan is not responsible for payment disputes caused by artists accepting direct/off-platform payments. Repeated attempts to bypass the platform may lead to account suspension.</p>
    </div>
  </div>

  <!-- 13 -->
  <div class="terms-section">
    <h2 class="terms-section-title">13. Communication Rules</h2>
    <p class="terms-text">Artists must communicate professionally and respectfully with buyers and platform staff. Artists must not:</p>
    <ul class="terms-list">
      <li>Harass or pressure buyers</li>
      <li>Share private contact details in chat</li>
      <li>Share payment details in chat</li>
      <li>Ask buyers to move the order outside the platform</li>
      <li>Use abusive or inappropriate language</li>
      <li>Misrepresent their work, price, or timeline</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan may review communication where needed for safety, support, or dispute handling.</p>
  </div>

  <!-- 14 -->
  <div class="terms-section">
    <h2 class="terms-section-title">14. Deadlines and Delivery</h2>
    <p class="terms-text">Artists should only accept deadlines they can realistically meet. If an artist cannot meet a deadline, they should inform Art Bazaar Pakistan as soon as possible. Delays may affect buyer trust and order status. Artists should package artwork carefully, especially if the artwork is framed, fragile, large, or high-value.</p>
  </div>

  <!-- 15 -->
  <div class="terms-section">
    <h2 class="terms-section-title">15. Revisions for Custom Artwork</h2>
    <p class="terms-text">For custom artwork, revision rules should be discussed before confirmation. Artists should clearly mention:</p>
    <ul class="terms-list">
      <li>Whether revisions are included</li>
      <li>How many small changes are allowed</li>
      <li>Whether major changes cost extra</li>
      <li>Whether changes are possible after work starts</li>
    </ul>
    <p class="terms-text">Major changes after approval may increase price or timeline.</p>
  </div>

  <!-- 16 -->
  <div class="terms-section">
    <h2 class="terms-section-title">16. Cancellations</h2>
    <p class="terms-text">Artists should not cancel confirmed orders without a valid reason. If cancellation is necessary, the artist must inform Art Bazaar Pakistan as soon as possible. Reasons may include:</p>
    <ul class="terms-list">
      <li>Health emergency</li>
      <li>Material unavailability</li>
      <li>Unrealistic request</li>
      <li>Buyer changed requirements too much</li>
      <li>Artist cannot complete the work</li>
    </ul>
    <p class="terms-text">Repeated cancellations may affect artist approval or visibility on the platform.</p>
  </div>

  <!-- 17 -->
  <div class="terms-section">
    <h2 class="terms-section-title">17. Quality and Accuracy</h2>
    <p class="terms-text">Artists are responsible for delivering work that matches the agreed details as closely as possible. Artists should not:</p>
    <ul class="terms-list">
      <li>Upload misleading artwork photos</li>
      <li>Overpromise quality or timeline</li>
      <li>Sell artwork that is unavailable</li>
      <li>Send a different artwork than the one ordered</li>
      <li>Use poor packaging that risks damage</li>
    </ul>
  </div>

  <!-- 18 -->
  <div class="terms-section">
    <h2 class="terms-section-title">18. Public Visibility</h2>
    <p class="terms-text">Art Bazaar Pakistan may control whether an artist appears publicly. An artist may be hidden, suspended, or removed if:</p>
    <ul class="terms-list">
      <li>Profile is incomplete</li>
      <li>Artwork is unoriginal</li>
      <li>Buyer complaints are serious</li>
      <li>Artist repeatedly misses deadlines</li>
      <li>Artist tries to bypass the platform</li>
      <li>Artist shares direct payment/contact details publicly</li>
      <li>Artist breaks platform rules</li>
    </ul>
  </div>

  <!-- 19 -->
  <div class="terms-section">
    <h2 class="terms-section-title">19. Reports and Disputes</h2>
    <p class="terms-text">If a buyer reports an issue with an artist or artwork, Art Bazaar Pakistan may review the case. The artist may be asked to provide:</p>
    <ul class="terms-list">
      <li>Artwork proof</li>
      <li>Progress photos</li>
      <li>Chat details</li>
      <li>Delivery proof</li>
      <li>Explanation of delay or issue</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan may take action based on the situation.</p>
  </div>

  <!-- 20 -->
  <div class="terms-section">
    <h2 class="terms-section-title">20. Agreement</h2>
    <p class="terms-text">By using Art Bazaar Pakistan as an artist, you agree that:</p>
    <ul class="terms-list">
      <li>Your profile may require admin approval</li>
      <li>Your artworks may require admin approval</li>
      <li>You must upload only original artwork</li>
      <li>You must not share payment/contact details publicly</li>
      <li>You must not bypass the platform</li>
      <li>Custom artwork should begin only after confirmation</li>
      <li>Art Bazaar Pakistan may remove content or suspend accounts that break rules</li>
    </ul>
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
    <div class="fbot">
      <span>© <?= date('Y') ?> Art Bazaar. Supporting Pakistani artists.</span>
      <span>Made with care in Pakistan 🇵🇰</span>
    </div>
  </div>
</footer>

<script>
const hamBtn = document.querySelector('.ham-btn');
const navDrawer = document.getElementById('nav-drawer');
const navOverlay = document.getElementById('nav-overlay');

if(hamBtn) {
  hamBtn.addEventListener('click', () => {
    navDrawer.classList.add('open');
    navOverlay.classList.add('open');
  });
}

function closeDrawer() {
  navDrawer.classList.remove('open');
  navOverlay.classList.remove('open');
}
</script>

</body>
</html>