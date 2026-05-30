<?php
session_start();
require_once __DIR__ . '/config/db.php';

 $artworkId = (int)($_GET['id'] ?? 0);

// ── Fetch artwork ─────────────────────────────────────
 $stmt = $conn->prepare("
    SELECT a.*, c.name AS category_name,
           u.name AS artist_name, u.id AS artist_user_id, u.profile_picture,
           ap.bio AS artist_bio, ap.city AS artist_city,
           ap.instagram_url, ap.art_style, ap.accepts_commissions
    FROM artworks a
    JOIN categories c  ON c.id  = a.category_id
    JOIN users u       ON u.id  = a.artist_id
    LEFT JOIN artist_profiles ap ON ap.user_id = u.id
    WHERE a.id = ? AND a.status IN ('approved','sold','pending')
");
 $stmt->bind_param('i', $artworkId);
 $stmt->execute();
 $artwork = $stmt->get_result()->fetch_assoc();

if (!$artwork) {
    // Optional: Redirect to home if not found
    header('Location: index.php');
    exit;
}

// ── Fetch artwork images ──────────────────────────────────
 $imgs = $conn->prepare("
    SELECT image_path, is_cover FROM artwork_images
    WHERE artwork_id = ? ORDER BY is_cover DESC, sort_order ASC
");
 $imgs->bind_param('i', $artworkId);
 $imgs->execute();
 $images = $imgs->get_result()->fetch_all(MYSQLI_ASSOC);
 $coverImage = $images[0]['image_path'] ?? null;

// ── Fetch categories (for commission form dropdown) ───────
 $cats = $conn->query("SELECT id, name FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// ── Fetch similar artworks (same category, not this one) ─
// FIXED: Added table aliases (a.) to avoid ambiguous column 'status'
 $similarRes = $conn->prepare("
    SELECT a.id, a.title, a.price, a.city,
           (SELECT image_path FROM artwork_images WHERE artwork_id = a.id AND is_cover = 1 LIMIT 1) AS cover
    FROM artworks a
    WHERE a.category_id = ? AND a.status IN ('approved','sold','pending') AND a.id != ?
    ORDER BY a.created_at DESC LIMIT 4
");
 $similarRes->bind_param('ii', $artwork['category_id'], $artworkId);
 $similarRes->execute();
 $similar = $similarRes->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Handle: Contact to Buy (inquiry) ─────────────────────
 $inquirySuccess = false;
 $inquiryError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'inquiry') {
    $name    = trim($_POST['buyer_name']  ?? '');
    $email   = trim($_POST['buyer_email'] ?? '');
    $phone   = trim($_POST['buyer_phone'] ?? '');
    $message = trim($_POST['message']     ?? '');

    if (!$name || (!$email && !$phone)) {
        $inquiryError = 'Please fill in your name and at least one contact method.';
    } else {
        $s = $conn->prepare("
            INSERT INTO buyer_inquiries (artwork_id, buyer_name, buyer_email, buyer_phone, message)
            VALUES (?, ?, ?, ?, ?)
        ");
        $s->bind_param('issss', $artworkId, $name, $email, $phone, $message);
        $s->execute();
        $inquirySuccess = true;
    }
}

// ── Handle: Commission / Request Similar Work ─────────────
 $commissionSuccess = false;
 $commissionError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'commission') {
    $name       = trim($_POST['buyer_name']   ?? '');
    $email      = trim($_POST['buyer_email']  ?? '');
    $phone      = trim($_POST['buyer_phone']  ?? '');
    $catId      = (int)($_POST['category_id'] ?? 0) ?: null;
    $budgetMin  = (float)($_POST['budget_min'] ?? 0) ?: null;
    $budgetMax  = (float)($_POST['budget_max'] ?? 0) ?: null;
    $deadline   = $_POST['deadline'] ?? null ?: null;
    $desc       = trim($_POST['description']  ?? '');
    $assignedId = (int)$artwork['artist_user_id']; // pre-assigned to this artist

    // Reference image upload
    $refImage = null;
    if (!empty($_FILES['reference_image']['tmp_name'])) {
        $allowedTypes = ['image/jpeg','image/png','image/webp','image/gif'];
        $fileType = mime_content_type($_FILES['reference_image']['tmp_name']);
        if (in_array($fileType, $allowedTypes) && $_FILES['reference_image']['size'] <= 5*1024*1024) {
            $ext = pathinfo($_FILES['reference_image']['name'], PATHINFO_EXTENSION);
            $filename = 'ref_' . uniqid() . '.' . $ext;
            $uploadPath = __DIR__ . '/uploads/commissions/' . $filename;
            if (!is_dir(dirname($uploadPath))) mkdir(dirname($uploadPath), 0755, true);
            if (move_uploaded_file($_FILES['reference_image']['tmp_name'], $uploadPath)) {
                $refImage = 'uploads/commissions/' . $filename;
            }
        }
    }

    if (!$name || (!$email && !$phone)) {
        $commissionError = 'Please fill in your name and at least one contact method.';
    } else {
        $s = $conn->prepare("
            INSERT INTO commission_requests
                (artist_id, buyer_name, buyer_email, buyer_phone, category_id,
                 budget_min, budget_max, deadline, description, reference_image)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $s->bind_param('isssisssss',
            $assignedId, $name, $email, $phone, $catId,
            $budgetMin, $budgetMax, $deadline, $desc, $refImage
        );
        $s->execute();
        $commissionSuccess = true;
    }
}

// ── Determine which modal to open after redirect ──────────
 $openModal = '';
if ($inquirySuccess)   $openModal = '#modal-inquiry-success';
if ($commissionSuccess) $openModal = '#modal-commission-success';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($artwork['title']) ?> — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ── Reset & Base ─────────────────────────────────────── */
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --black:  #0a0a0a;
    --grey1:  #f7f7f5;
    --grey2:  #efefec;
    --grey3:  #d8d8d3;
    --grey4:  #999;
    --grey5:  #555;
    --white:  #ffffff;
    --ink:    #1a1a1a;
    --accent: #b5894a;    /* warm gold — art gallery feel */
    --red:    #d63031;
    --green:  #00a878;
    --sold:   #e17055;
}
html { scroll-behavior: smooth; }
body { background: var(--grey1); color: var(--ink); font-family: 'DM Sans', sans-serif; font-size: 15px; line-height: 1.6; }

/* ── Navbar ───────────────────────────────────────────── */
.navbar {
    position: sticky; top: 0; z-index: 200;
    background: var(--white); border-bottom: 1px solid var(--grey2);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 32px; height: 62px;
}
.navbar-brand { font-family: 'Playfair Display', serif; font-size: 1.3rem; color: var(--black); text-decoration: none; }
.navbar-links { display: flex; gap: 28px; list-style: none; }
.navbar-links a { text-decoration: none; color: var(--grey5); font-size: .875rem; font-weight: 500; transition: color .2s; }
.navbar-links a:hover { color: var(--black); }
.navbar-cta { display: flex; gap: 10px; }
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 20px; border-radius: 6px; font-family: inherit; font-size: .875rem; font-weight: 500; cursor: pointer; border: none; transition: all .2s; text-decoration: none; }
.btn-outline { background: transparent; border: 1px solid var(--grey3); color: var(--ink); }
.btn-outline:hover { border-color: var(--black); color: var(--black); }
.btn-dark { background: var(--black); color: var(--white); }
.btn-dark:hover { background: #222; }
.btn-accent { background: var(--accent); color: var(--white); }
.btn-accent:hover { background: #a07840; }
.btn-green { background: var(--green); color: var(--white); }
.btn-green:hover { background: #008f66; }
.btn-lg { padding: 13px 28px; font-size: 1rem; }
.btn-full { width: 100%; justify-content: center; }
.btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Breadcrumb ───────────────────────────────────────── */
.breadcrumb { padding: 16px 32px; font-size: .8rem; color: var(--grey4); }
.breadcrumb a { color: var(--grey4); text-decoration: none; }
.breadcrumb a:hover { color: var(--black); }
.breadcrumb span { margin: 0 6px; }

/* ── Main layout ──────────────────────────────────────── */
.page-wrap { max-width: 1180px; margin: 0 auto; padding: 0 32px 80px; }

.artwork-grid {
    display: grid;
    grid-template-columns: 1fr 420px;
    gap: 52px;
    align-items: start;
    padding-top: 20px;
}

/* ── Image gallery ────────────────────────────────────── */
.gallery { position: sticky; top: 80px; }
.gallery-main {
    width: 100%; aspect-ratio: 4/3;
    border-radius: 10px; overflow: hidden;
    background: var(--grey2);
    margin-bottom: 12px;
}
.gallery-main img {
    width: 100%; height: 100%;
    object-fit: cover; display: block;
    transition: transform .4s;
}
.gallery-main img:hover { transform: scale(1.02); }
.gallery-thumbs { display: flex; gap: 8px; flex-wrap: wrap; }
.thumb {
    width: 74px; height: 74px; border-radius: 6px; overflow: hidden;
    cursor: pointer; border: 2px solid transparent;
    transition: border-color .2s;
    background: var(--grey2);
}
.thumb.active { border-color: var(--accent); }
.thumb img { width: 100%; height: 100%; object-fit: cover; }

/* ── Detail panel ─────────────────────────────────────── */
.detail-panel { display: flex; flex-direction: column; gap: 22px; }

.status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 20px; font-size: .78rem; font-weight: 600;
}
.badge-available { background: #e8f8f2; color: var(--green); }
.badge-sold      { background: #fff0ec; color: var(--sold); }

.artwork-category { font-size: .8rem; color: var(--accent); font-weight: 600; letter-spacing: .05em; text-transform: uppercase; }
.artwork-title { font-family: 'Playfair Display', serif; font-size: 2rem; font-weight: 600; line-height: 1.2; color: var(--black); margin-top: 4px; }
.artwork-price { font-size: 1.75rem; font-weight: 700; color: var(--black); }
.artwork-price small { font-size: .85rem; font-weight: 400; color: var(--grey4); margin-left: 4px; }

.meta-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 12px; padding: 18px; background: var(--white);
    border: 1px solid var(--grey2); border-radius: 8px;
}
.meta-item label { display: block; font-size: .72rem; color: var(--grey4); font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
.meta-item span { font-size: .92rem; color: var(--ink); font-weight: 500; }

.delivery-tag {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .8rem; color: var(--green); font-weight: 500;
}
.delivery-tag svg { flex-shrink: 0; }

.description-block h3 { font-size: .85rem; font-weight: 600; color: var(--grey4); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px; }
.description-block p { color: var(--grey5); line-height: 1.7; }

/* ── CTA buttons ──────────────────────────────────────── */
.cta-stack { display: flex; flex-direction: column; gap: 10px; }

/* ── Artist card ──────────────────────────────────────── */
.artist-card {
    display: flex; align-items: center; gap: 14px;
    padding: 16px; background: var(--white);
    border: 1px solid var(--grey2); border-radius: 8px;
    text-decoration: none; color: inherit;
    transition: border-color .2s;
}
.artist-card:hover { border-color: var(--grey3); }
.artist-avatar {
    width: 52px; height: 52px; border-radius: 50%;
    object-fit: cover; flex-shrink: 0;
    background: var(--grey2);
}
.artist-card-info h4 { font-size: .95rem; font-weight: 600; color: var(--black); }
.artist-card-info p  { font-size: .8rem; color: var(--grey4); }
.artist-card-arrow { margin-left: auto; color: var(--grey3); font-size: 1.2rem; }

/* ── Section heading ──────────────────────────────────── */
.section-heading {
    font-family: 'Playfair Display', serif;
    font-size: 1.4rem; font-weight: 600;
    margin-bottom: 24px;
}
.section-divider { margin: 56px 0 32px; border: none; border-top: 1px solid var(--grey2); }

/* ── Similar artworks grid ────────────────────────────── */
.similar-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
.artwork-card { text-decoration: none; color: inherit; display: block; transition: transform .2s; }
.artwork-card:hover { transform: translateY(-3px); }
.artwork-card-img {
    width: 100%; aspect-ratio: 1;
    border-radius: 8px; overflow: hidden;
    background: var(--grey2); margin-bottom: 10px;
}
.artwork-card-img img { width: 100%; height: 100%; object-fit: cover; }
.artwork-card h4 { font-size: .9rem; font-weight: 500; color: var(--black); margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.artwork-card p { font-size: .82rem; color: var(--grey5); }
.artwork-card .price { font-weight: 700; color: var(--accent); }

/* ── Modal backdrop ───────────────────────────────────── */
.modal-backdrop {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.55); z-index: 500;
    align-items: center; justify-content: center; padding: 20px;
}
.modal-backdrop.open { display: flex; }
.modal {
    background: var(--white); border-radius: 12px;
    width: 100%; max-width: 520px; max-height: 90vh;
    overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.25);
    animation: slideUp .25s ease;
}
@keyframes slideUp { from { opacity:0; transform: translateY(20px); } to { opacity:1; transform: translateY(0); } }
.modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 22px 24px 18px; border-bottom: 1px solid var(--grey2);
    position: sticky; top: 0; background: var(--white); z-index: 1;
}
.modal-header h2 { font-family: 'Playfair Display', serif; font-size: 1.25rem; }
.modal-close { background: none; border: none; font-size: 1.4rem; cursor: pointer; color: var(--grey4); line-height: 1; padding: 4px; }
.modal-close:hover { color: var(--black); }
.modal-body { padding: 24px; }

/* ── Form styles ──────────────────────────────────────── */
.form-group { margin-bottom: 16px; }
.form-label { display: block; font-size: .82rem; font-weight: 600; color: var(--grey5); margin-bottom: 6px; }
.form-label .req { color: var(--red); margin-left: 2px; }
.form-input, .form-select, .form-textarea {
    width: 100%; padding: 10px 14px;
    border: 1px solid var(--grey3); border-radius: 7px;
    font-family: inherit; font-size: .9rem; color: var(--ink);
    background: var(--white); transition: border-color .2s;
    outline: none;
}
.form-input:focus, .form-select:focus, .form-textarea:focus { border-color: var(--accent); }
.form-textarea { resize: vertical; min-height: 90px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-hint { font-size: .76rem; color: var(--grey4); margin-top: 4px; }
.form-error { font-size: .82rem; color: var(--red); margin-top: 4px; }
.form-separator { border: none; border-top: 1px solid var(--grey2); margin: 18px 0; }
.artwork-ref {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; background: var(--grey1);
    border-radius: 7px; margin-bottom: 16px; font-size: .85rem; color: var(--grey5);
}
.artwork-ref img { width: 36px; height: 36px; object-fit: cover; border-radius: 4px; }
.artwork-ref strong { display: block; color: var(--ink); font-size: .88rem; }

/* ── Success screen ───────────────────────────────────── */
.success-screen { text-align: center; padding: 36px 24px; }
.success-icon { font-size: 3rem; margin-bottom: 12px; }
.success-screen h2 { font-family: 'Playfair Display', serif; font-size: 1.4rem; margin-bottom: 10px; }
.success-screen p { color: var(--grey5); max-width: 320px; margin: 0 auto 24px; }

/* ── Alert ────────────────────────────────────────────── */
.alert { padding: 12px 16px; border-radius: 7px; margin-bottom: 16px; font-size: .875rem; }
.alert-error { background: #fff0f0; color: var(--red); border: 1px solid #ffd5d5; }

/* ── Responsive ───────────────────────────────────────── */
@media (max-width: 960px) {
    .artwork-grid { grid-template-columns: 1fr; gap: 32px; }
    .gallery { position: static; }
    .similar-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
    .page-wrap { padding: 0 16px 60px; }
    .navbar { padding: 0 16px; }
    .navbar-links, .navbar-cta { display: none; }
    .breadcrumb { padding: 12px 16px; }
    .artwork-title { font-size: 1.5rem; }
    .meta-grid { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .similar-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>

<!-- ── Navbar ──────────────────────────────────────────────── -->
<nav class="navbar">
    <a href="index.php" class="navbar-brand">Art Bazaar</a>
    <ul class="navbar-links">
        <li><a href="/artworks.php">Artworks</a></li>
        <li><a href="/artists.php">Artists</a></li>
        <li><a href="/commissions.php">Commissions</a></li>
        <li><a href="/about.php">About</a></li>
    </ul>
    <div class="navbar-cta">
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="/dashboard/" class="btn btn-outline">Dashboard</a>
        <?php else: ?>
            <a href="/login.php" class="btn btn-outline">Log In</a>
            <a href="/register.php" class="btn btn-dark">Join as Artist</a>
        <?php endif; ?>
    </div>
</nav>

<!-- ── Breadcrumb ───────────────────────────────────────────── -->
<div class="breadcrumb">
    <a href="/">Home</a><span>›</span>
    <a href="/artworks.php">Artworks</a><span>›</span>
    <a href="/artworks.php?category=<?= urlencode($artwork['category_id']) ?>"><?= htmlspecialchars($artwork['category_name']) ?></a><span>›</span>
    <?= htmlspecialchars($artwork['title']) ?>
</div>

<!-- ── Main content ─────────────────────────────────────────── -->
<div class="page-wrap">
    <div class="artwork-grid">

        <!-- LEFT: Image Gallery -->
        <div class="gallery">
            <div class="gallery-main">
                <?php if ($coverImage): ?>
                    <img id="mainImage" src="/<?= htmlspecialchars($coverImage) ?>" alt="<?= htmlspecialchars($artwork['title']) ?>">
                <?php else: ?>
                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--grey3);font-size:3rem;">🖼️</div>
                <?php endif; ?>
            </div>
            <?php if (count($images) > 1): ?>
            <div class="gallery-thumbs">
                <?php foreach ($images as $i => $img): ?>
                <div class="thumb <?= $i === 0 ? 'active' : '' ?>"
                     onclick="switchImage(this, '/<?= htmlspecialchars($img['image_path']) ?>')">
                    <img src="/<?= htmlspecialchars($img['image_path']) ?>" alt="">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Detail Panel -->
        <div class="detail-panel">

            <!-- Status + Category -->
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <?php if ($artwork['status'] === 'sold'): ?>
                    <span class="status-badge badge-sold">✕ Sold</span>
                <?php else: ?>
                    <span class="status-badge badge-available">✓ Available</span>
                <?php endif; ?>
                <span class="artwork-category"><?= htmlspecialchars($artwork['category_name']) ?></span>
            </div>

            <!-- Title -->
            <h1 class="artwork-title"><?= htmlspecialchars($artwork['title']) ?></h1>

            <!-- Price -->
            <div class="artwork-price">
                PKR <?= number_format($artwork['price']) ?>
                <small>/ artwork</small>
            </div>

            <!-- Meta grid -->
            <div class="meta-grid">
                <?php if ($artwork['medium']): ?>
                <div class="meta-item">
                    <label>Medium</label>
                    <span><?= htmlspecialchars($artwork['medium']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($artwork['size']): ?>
                <div class="meta-item">
                    <label>Size</label>
                    <span><?= htmlspecialchars($artwork['size']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($artwork['city']): ?>
                <div class="meta-item">
                    <label>Location</label>
                    <span><?= htmlspecialchars($artwork['city']) ?></span>
                </div>
                <?php endif; ?>
                <div class="meta-item">
                    <label>Delivery</label>
                    <?php if ($artwork['delivery_available']): ?>
                        <span class="delivery-tag">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            Available
                        </span>
                    <?php else: ?>
                        <span style="color:var(--grey4)">Pickup only</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Description -->
            <?php if ($artwork['description']): ?>
            <div class="description-block">
                <h3>About this piece</h3>
                <p><?= nl2br(htmlspecialchars($artwork['description'])) ?></p>
            </div>
            <?php endif; ?>

            <!-- CTA Buttons -->
            <div class="cta-stack">
                <?php if ($artwork['status'] !== 'sold'): ?>
                    <button class="btn btn-green btn-lg btn-full" onclick="openModal('modal-inquiry')">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Contact to Buy
                    </button>
                <?php else: ?>
                    <button class="btn btn-lg btn-full" disabled style="background:var(--grey2);color:var(--grey4);cursor:not-allowed;">
                        This artwork has been sold
                    </button>
                <?php endif; ?>

                <?php if ($artwork['similar_work_available'] && $artwork['accepts_commissions']): ?>
                    <button class="btn btn-accent btn-lg btn-full" onclick="openModal('modal-commission')">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        Request Similar Work
                    </button>
                <?php endif; ?>
            </div>

            <!-- Artist card -->
            <a href="/artist-profile.php?id=<?= $artwork['artist_user_id'] ?>" class="artist-card">
                <?php if ($artwork['profile_picture']): ?>
                    <img class="artist-avatar" src="/uploads/profiles/<?= htmlspecialchars($artwork['profile_picture']) ?>" alt="">
                <?php else: ?>
                    <div class="artist-avatar" style="background:var(--grey2);display:flex;align-items:center;justify-content:center;font-size:1.4rem;">🎨</div>
                <?php endif; ?>
                <div class="artist-card-info">
                    <h4><?= htmlspecialchars($artwork['artist_name']) ?></h4>
                    <p><?= $artwork['artist_city'] ? htmlspecialchars($artwork['artist_city']) : 'Artist' ?>
                       <?php if ($artwork['art_style']): ?> · <?= htmlspecialchars($artwork['art_style']) ?><?php endif; ?>
                    </p>
                </div>
                <span class="artist-card-arrow">›</span>
            </a>

        </div><!-- /detail-panel -->
    </div><!-- /artwork-grid -->

    <!-- Similar Artworks -->
    <?php if (!empty($similar)): ?>
    <hr class="section-divider">
    <h2 class="section-heading">More <?= htmlspecialchars($artwork['category_name']) ?></h2>
    <div class="similar-grid">
        <?php foreach ($similar as $s): ?>
        <a href="/artwork-detail.php?id=<?= $s['id'] ?>" class="artwork-card">
            <div class="artwork-card-img">
                <?php if ($s['cover']): ?>
                    <img src="/<?= htmlspecialchars($s['cover']) ?>" alt="">
                <?php else: ?>
                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--grey3);font-size:2rem;">🖼️</div>
                <?php endif; ?>
            </div>
            <h4><?= htmlspecialchars($s['title']) ?></h4>
            <p class="price">PKR <?= number_format($s['price']) ?></p>
            <?php if ($s['city']): ?><p style="font-size:.78rem;color:var(--grey4);"><?= htmlspecialchars($s['city']) ?></p><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- /page-wrap -->


<!-- ═══════════════════════════════════════════════════════ -->
<!--  MODAL: Contact to Buy (Inquiry)                        -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-inquiry" <?= $inquirySuccess ? 'style="display:none"' : '' ?>>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-inquiry-title">
        <?php if ($inquiryError): ?>
        <!-- Re-open with error -->
        <?php endif; ?>
        <div class="modal-header">
            <h2 id="modal-inquiry-title">Contact to Buy</h2>
            <button class="modal-close" onclick="closeModal('modal-inquiry')" aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <!-- Artwork reference strip -->
            <div class="artwork-ref">
                <?php if ($coverImage): ?>
                    <img src="/<?= htmlspecialchars($coverImage) ?>" alt="">
                <?php endif; ?>
                <div>
                    <strong><?= htmlspecialchars($artwork['title']) ?></strong>
                    PKR <?= number_format($artwork['price']) ?>
                </div>
            </div>

            <?php if ($inquiryError): ?>
                <div class="alert alert-error"><?= htmlspecialchars($inquiryError) ?></div>
            <?php endif; ?>

            <form method="POST" action="#modal-inquiry">
                <input type="hidden" name="form_type" value="inquiry">

                <div class="form-group">
                    <label class="form-label" for="inq-name">Your name <span class="req">*</span></label>
                    <input class="form-input" type="text" id="inq-name" name="buyer_name"
                           placeholder="e.g. Sara Ahmed" required
                           value="<?= htmlspecialchars($_POST['buyer_name'] ?? '') ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="inq-phone">WhatsApp / Phone</label>
                        <input class="form-input" type="tel" id="inq-phone" name="buyer_phone"
                               placeholder="03XX XXXXXXX"
                               value="<?= htmlspecialchars($_POST['buyer_phone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="inq-email">Email</label>
                        <input class="form-input" type="email" id="inq-email" name="buyer_email"
                               placeholder="you@email.com"
                               value="<?= htmlspecialchars($_POST['buyer_email'] ?? '') ?>">
                    </div>
                </div>
                <p class="form-hint" style="margin-top:-10px;margin-bottom:14px;">Please fill in at least one contact method.</p>

                <div class="form-group">
                    <label class="form-label" for="inq-message">Message <span style="color:var(--grey4);font-weight:400;">(optional)</span></label>
                    <textarea class="form-textarea" id="inq-message" name="message"
                              placeholder="Any questions, preferred delivery, etc."><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn btn-green btn-lg btn-full">
                    Send Inquiry
                </button>
                <p class="form-hint" style="text-align:center;margin-top:10px;">
                    We'll connect you with the artist or admin within 24 hours.
                </p>
            </form>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════ -->
<!--  MODAL: Inquiry Success                                 -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-inquiry-success" <?= $inquirySuccess ? 'class="modal-backdrop open"' : '' ?>>
    <div class="modal" role="dialog" aria-modal="true">
        <div class="success-screen">
            <div class="success-icon">✅</div>
            <h2>Inquiry Sent!</h2>
            <p>We've received your inquiry for <strong><?= htmlspecialchars($artwork['title']) ?></strong>. Our team will reach out to you soon.</p>
            <button class="btn btn-dark btn-lg" onclick="closeModal('modal-inquiry-success')">Done</button>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════ -->
<!--  MODAL: Request Similar Work (Commission)               -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-commission" <?= $commissionError ? 'class="modal-backdrop open"' : '' ?>>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-commission-title">
        <div class="modal-header">
            <h2 id="modal-commission-title">Request Similar Work</h2>
            <button class="modal-close" onclick="closeModal('modal-commission')" aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <!-- Artist reference -->
            <div class="artwork-ref">
                <?php if ($artwork['profile_picture']): ?>
                    <img src="/uploads/profiles/<?= htmlspecialchars($artwork['profile_picture']) ?>" alt="" style="border-radius:50%;">
                <?php else: ?>
                    <span style="font-size:1.4rem;">🎨</span>
                <?php endif; ?>
                <div>
                    <strong>Commission from <?= htmlspecialchars($artwork['artist_name']) ?></strong>
                    Inspired by "<?= htmlspecialchars($artwork['title']) ?>"
                </div>
            </div>

            <?php if ($commissionError): ?>
                <div class="alert alert-error"><?= htmlspecialchars($commissionError) ?></div>
            <?php endif; ?>

            <form method="POST" action="#modal-commission" enctype="multipart/form-data">
                <input type="hidden" name="form_type" value="commission">

                <!-- Contact info -->
                <div class="form-group">
                    <label class="form-label" for="com-name">Your name <span class="req">*</span></label>
                    <input class="form-input" type="text" id="com-name" name="buyer_name"
                           placeholder="e.g. Ali Khan" required
                           value="<?= htmlspecialchars($_POST['buyer_name'] ?? '') ?>">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="com-phone">WhatsApp / Phone</label>
                        <input class="form-input" type="tel" id="com-phone" name="buyer_phone"
                               placeholder="03XX XXXXXXX"
                               value="<?= htmlspecialchars($_POST['buyer_phone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="com-email">Email</label>
                        <input class="form-input" type="email" id="com-email" name="buyer_email"
                               placeholder="you@email.com"
                               value="<?= htmlspecialchars($_POST['buyer_email'] ?? '') ?>">
                    </div>
                </div>

                <hr class="form-separator">

                <!-- Art details -->
                <div class="form-group">
                    <label class="form-label" for="com-category">Artwork type / category</label>
                    <select class="form-select" id="com-category" name="category_id">
                        <option value="">— Select category —</option>
                        <?php foreach ($cats as $cat): ?>
                            <option value="<?= $cat['id'] ?>"
                                <?= (isset($_POST['category_id']) && $_POST['category_id'] == $cat['id']) ? 'selected' : '' ?>
                                <?= ($cat['id'] == $artwork['category_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="com-bmin">Budget min (PKR)</label>
                        <input class="form-input" type="number" id="com-bmin" name="budget_min"
                               placeholder="e.g. 5000" min="0"
                               value="<?= htmlspecialchars($_POST['budget_min'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="com-bmax">Budget max (PKR)</label>
                        <input class="form-input" type="number" id="com-bmax" name="budget_max"
                               placeholder="e.g. 15000" min="0"
                               value="<?= htmlspecialchars($_POST['budget_max'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="com-deadline">Deadline <span style="color:var(--grey4);font-weight:400;">(optional)</span></label>
                    <input class="form-input" type="date" id="com-deadline" name="deadline"
                           min="<?= date('Y-m-d') ?>"
                           value="<?= htmlspecialchars($_POST['deadline'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="com-desc">Describe what you want <span class="req">*</span></label>
                    <textarea class="form-textarea" id="com-desc" name="description" required
                              placeholder="Size, subject, colours, mood, any specific details..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="com-ref">Reference image <span style="color:var(--grey4);font-weight:400;">(optional, max 5MB)</span></label>
                    <input class="form-input" type="file" id="com-ref" name="reference_image"
                           accept="image/jpeg,image/png,image/webp,image/gif"
                           style="padding: 8px 12px; cursor:pointer;">
                    <p class="form-hint">JPEG, PNG, or WebP accepted.</p>
                </div>

                <button type="submit" class="btn btn-accent btn-lg btn-full">
                    Submit Commission Request
                </button>
                <p class="form-hint" style="text-align:center;margin-top:10px;">
                    Admin will contact the artist and confirm timeline + pricing with you.
                </p>
            </form>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════ -->
<!--  MODAL: Commission Success                              -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-commission-success" <?= $commissionSuccess ? 'class="modal-backdrop open"' : '' ?>>
    <div class="modal" role="dialog" aria-modal="true">
        <div class="success-screen">
            <div class="success-icon">🎨</div>
            <h2>Request Received!</h2>
            <p>Your commission request has been sent. The admin will contact you after coordinating with <strong><?= htmlspecialchars($artwork['artist_name']) ?></strong>.</p>
            <button class="btn btn-dark btn-lg" onclick="closeModal('modal-commission-success')">Done</button>
        </div>
    </div>
</div>


<script>
// ── Gallery image switch ───────────────────────────────────
function switchImage(thumb, src) {
    document.getElementById('mainImage').src = src;
    document.querySelectorAll('.thumb').forEach(t => t.classList.remove('active'));
    thumb.classList.add('active');
}

// ── Modal open/close ───────────────────────────────────────
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}

// Close on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('open');
            document.body.style.overflow = '';
        }
    });
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open').forEach(m => {
            m.classList.remove('open');
            document.body.style.overflow = '';
        });
    }
});

// ── Re-open correct modal if there was a form error ────────
<?php if ($inquiryError): ?>
    openModal('modal-inquiry');
<?php endif; ?>
<?php if ($commissionError): ?>
    openModal('modal-commission');
<?php endif; ?>
</script>
</body>
</html>