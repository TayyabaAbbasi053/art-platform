<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// ── Auth guard ───────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'artist') {
    header('Location: ../../login.php');
    exit;
}
$__stmtStatus = $conn->prepare("SELECT status, status_reason FROM users WHERE id = ?");
$__stmtStatus->bind_param('i', $_SESSION['user_id']);
$__stmtStatus->execute();
$__userStatus = $__stmtStatus->get_result()->fetch_assoc();
$__stmtStatus->close();
if ($__userStatus['status'] === 'blocked') {
    session_destroy();
    header('Location: ../../login.php?blocked=1&reason=' . urlencode($__userStatus['status_reason'] ?? ''));
    exit;
}
if ($__userStatus['status'] === 'pending') {
    session_destroy();
    $__pendingEmail = $conn->query("SELECT email FROM users WHERE id={$_SESSION['user_id']}")->fetch_assoc()['email'] ?? '';
header('Location: ../../login.php?pending=1&email=' . urlencode($__pendingEmail));
    exit;
}

$artistId = (int) $_SESSION['user_id'];

// ── Profile completeness check ───────────────────────
$__profile = $conn->query("
    SELECT ap.bio, ap.city, ap.address, ap.art_style,
           u.profile_picture,
           (ap.has_bank_account OR ap.has_easypaisa OR ap.has_jazzcash OR ap.has_nayapay OR ap.has_sadapay) AS has_payment
    FROM artist_profiles ap
    JOIN users u ON u.id = ap.user_id
    WHERE ap.user_id = $artistId
")->fetch_assoc();

$__missingFields = [];
if (empty($__profile['bio']))             $__missingFields[] = 'Bio';
if (empty($__profile['city']))            $__missingFields[] = 'City';
if (empty($__profile['address']))         $__missingFields[] = 'Address';
if (empty($__profile['art_style']))       $__missingFields[] = 'Art Style';
if (empty($__profile['profile_picture'])) $__missingFields[] = 'Profile Picture';
if (!$__profile['has_payment'])           $__missingFields[] = 'Payment Method';

if (!empty($__missingFields)) {
    $_SESSION['profile_incomplete_msg'] = 'Please complete your profile before uploading artwork. Missing: ' . implode(', ', $__missingFields) . '.';
    header('Location: profile.php');
    exit;
}

 $artistId   = (int) $_SESSION['user_id'];
 $artistName = $_SESSION['name'] ?? 'Artist';
 $errorMsg   = '';
 $successMsg = '';
 // ── CSRF Token ───────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Upload Token (per page-load, prevents duplicate/multi-tab submission) ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['upload_token'] = bin2hex(random_bytes(16));
}

// ── Fetch categories ─────────────────────────────────
 $categories = $conn->query("SELECT id, name, slug FROM categories ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// ── HEIC support health check ────────────────────────
// Cached in session for 1 hour so we're not shelling out on every page load
$heicSupported = false;
if (isset($_SESSION['heic_support_checked']) && (time() - $_SESSION['heic_support_checked']) < 3600) {
    $heicSupported = $_SESSION['heic_support_ok'] ?? false;
} else {
    $heicSupported = function_exists('exec')
        && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))
        && trim((string) @shell_exec('which convert')) !== ''
        && trim((string) @shell_exec('which timeout')) !== ''
        && stripos((string) @shell_exec('convert -list format 2>/dev/null | grep -i heic'), 'HEIC') !== false;
    $_SESSION['heic_support_checked'] = time();
    $_SESSION['heic_support_ok'] = $heicSupported;
}

// ── Fetch New Orders Count for Sidebar Badge ────────────
 $newOrdersCount = 0;
 $countStmt = $conn->prepare("
    SELECT COUNT(DISTINCT o.id) 
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = ? AND o.order_type = 'artwork' AND o.order_status = 'pending'
");
 $countStmt->bind_param('i', $artistId);
 $countStmt->execute();
 $newOrdersCount = $countStmt->get_result()->fetch_row()[0];

// ── Handle form submission ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errorMsg = 'Invalid session token. Please refresh the page and try again.';
        goto skip_processing;
    }

    // Prevent double/multi-tab submission — token is single-use
    if (
        !isset($_POST['upload_token']) ||
        !isset($_SESSION['upload_token']) ||
        !hash_equals($_SESSION['upload_token'], $_POST['upload_token'])
    ) {
        header("Location: my-artworks.php?msg=uploaded");
        exit;
    }
    // Invalidate immediately so a second tab/request with the same token can't slip through
    unset($_SESSION['upload_token']);

    $title                  = trim($_POST['title'] ?? '');
    $categoryId             = (int) ($_POST['category'] ?? 0);
    $medium                 = trim($_POST['medium'] ?? '');
    $size                   = trim($_POST['size'] ?? '');
    $price                  = (float) ($_POST['price'] ?? 0);
    $city                   = trim($_POST['city'] ?? '');
    $description            = trim($_POST['description'] ?? '');
    $tags                   = trim($_POST['tags'] ?? '');
    $delivery_available = 1;
$similar_work_available = isset($_POST['similar_work_available']) ? 1 : 0;
$is_framed              = isset($_POST['is_framed']) ? 1 : 0;
    $weight_kg              = (float)($_POST['weight_kg'] ?? 1.00);

    // Determine if the selected category is "Digital Art"
    $catSlugRes = $conn->prepare("SELECT slug FROM categories WHERE id = ?");
    $catSlugRes->bind_param('i', $categoryId);
    $catSlugRes->execute();
    $catSlugRow = $catSlugRes->get_result()->fetch_assoc();
    $isDigitalCategory = ($catSlugRow && $catSlugRow['slug'] === 'digital-art');

    // Validation: Check if the 'images' input actually has files
    $hasFiles = isset($_FILES['images']) && isset($_FILES['images']['name'][0]) && $_FILES['images']['name'][0] !== '';
    $imageCount = $hasFiles ? count($_FILES['images']['name']) : 0;

    // Digital Art listings must include the deliverable file
    $hasDigitalFile = isset($_FILES['digital_file']) && $_FILES['digital_file']['error'] === UPLOAD_ERR_OK;
    $digitalFileExt = $hasDigitalFile ? strtolower(pathinfo($_FILES['digital_file']['name'], PATHINFO_EXTENSION)) : '';
    $allowedDigitalExt = ['zip', 'psd', 'ai', 'png', 'jpg', 'jpeg', 'pdf'];

    if ($title === '' || $price <= 0 || $categoryId === 0 || !$hasFiles) {
        $errorMsg = 'Please fill in all required fields and upload at least one image.';
    } elseif ($imageCount > 5) {
        $errorMsg = 'You can only upload up to 5 images.';
    } elseif ($isDigitalCategory && !$hasDigitalFile) {
        $errorMsg = 'Please upload the digital artwork file buyers will receive after purchase.';
    } elseif ($isDigitalCategory && !in_array($digitalFileExt, $allowedDigitalExt)) {
        $errorMsg = 'Invalid digital file type. Allowed: ZIP, PSD, AI, PNG, JPG, PDF.';
    } elseif ($isDigitalCategory && $_FILES['digital_file']['size'] > 50 * 1024 * 1024) {
        $errorMsg = 'Digital file must be under 50MB.';
    } else {
        
        // 1. Insert Artwork
        $conn->begin_transaction();

$stmt = $conn->prepare("
    INSERT INTO artworks 
    (artist_id, category_id, title, description, tags, medium, size, is_framed, weight_kg, price, city, delivery_available, similar_work_available, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
");

if (!$stmt) {
    error_log('Failed to prepare artwork insert statement: ' . $conn->error);
    $conn->rollback();
    $errorMsg = 'Could not save artwork. Please try again.';
} else {
    $stmt->bind_param('iisssssiddsii', $artistId, $categoryId, $title, $description, $tags, $medium, $size, $is_framed, $weight_kg, $price, $city, $delivery_available, $similar_work_available);

        if ($stmt->execute()) {
            $artworkId = $conn->insert_id;

            // 1b. Handle Digital File (only for Digital Art category)
            if ($isDigitalCategory && $hasDigitalFile) {
                $digitalDir = __DIR__ . '/../../uploads/digital_files/';
                if (!is_dir($digitalDir)) {
                    mkdir($digitalDir, 0755, true);
                }
                $digitalName = 'digital_' . $artworkId . '_' . bin2hex(random_bytes(8)) . '.' . $digitalFileExt;
                $digitalDest = $digitalDir . $digitalName;

                if (move_uploaded_file($_FILES['digital_file']['tmp_name'], $digitalDest)) {
                    chmod($digitalDest, 0644);
                    $dbDigitalPath = 'uploads/digital_files/' . $digitalName;
                    $stmtDigital = $conn->prepare("UPDATE artworks SET digital_file_path = ? WHERE id = ?");
                    $stmtDigital->bind_param('si', $dbDigitalPath, $artworkId);
                    $stmtDigital->execute();
                } else {
                    $conn->rollback();
                    $errorMsg = 'Failed to save digital artwork file. Please try again.';
                    goto skip_image_loop;
                }
            }

            // 2. Handle Images
            $uploadDir = __DIR__ . '/../../uploads/artworks/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $files = $_FILES['images'];
            $uploadedCount = 0;
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

            // Open finfo handle once, outside the loop
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            // Prepare the image-insert statement once, outside the loop
            $stmtImg = $conn->prepare("INSERT INTO artwork_images (artwork_id, image_path, is_cover, sort_order) VALUES (?, ?, ?, ?)");
            if (!$stmtImg) {
                error_log('Failed to prepare image insert statement: ' . $conn->error);
                $conn->rollback();
                $errorMsg = 'Could not process images. Please try again.';
                goto skip_image_loop;
            }
            $savedFilePaths = []; // track physical files so we can clean up on failure

            // Loop through the files array provided by the DataTransfer object in JS
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $fileName = $files['name'][$i];
                    $fileTmp  = $files['tmp_name'][$i];
                    $fileSize = $files['size'][$i];
                    
                    // 10MB max per image
                    if ($fileSize > 10 * 1024 * 1024) {
                        continue; // Skip oversized files
                    }

                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt)) {
                        continue; // Skip invalid types
                    }
                    if (in_array($ext, ['heic', 'heif']) && !$heicSupported) {
                        error_log("HEIC upload skipped — server lacks ImageMagick/timeout support for file: {$fileName}");
                        continue; // Skip HEIC if the server can't convert it
                    }

                    // MIME type check — don't trust the extension alone.
                    // HEIC/HEIF is special-cased: many hosts report it as application/octet-stream
                    // even for genuinely valid files, so if the server has confirmed HEIC support
                    // and the extension matches, we trust the extension instead of the MIME sniff.
                    $mime  = finfo_file($finfo, $fileTmp);
                    $allowedMime = [
                        'image/jpeg', 'image/png', 'image/webp',
                        'image/heic', 'image/heif'
                    ];
                    $isTrustedHeic = $heicSupported && in_array($ext, ['heic', 'heif']);
                    if (!$isTrustedHeic && !in_array($mime, $allowedMime)) {
                        continue; // Skip files whose real content doesn't match an allowed image type
                    }

                    // Dimension check — protect against decompression-bomb style images
                    // getimagesize() doesn't reliably read HEIC/HEIF, so only check formats it supports
                    if (!in_array($ext, ['heic', 'heif'])) {
                        $dimensions = @getimagesize($fileTmp);
                        if ($dimensions === false) {
                            continue; // Not a readable image despite passing MIME check — skip it
                        }
                        [$imgWidth, $imgHeight] = $dimensions;
                        if ($imgWidth > 8000 || $imgHeight > 8000) {
                            continue; // Skip excessively large images
                        }
                    }

                    // Generate unique filename + handle HEIC conversion
$uniqueId = bin2hex(random_bytes(8));
if (in_array($ext, ['heic', 'heif'])) {
    $newName = 'art_' . $artworkId . '_' . $uniqueId . '.jpg';
    $destPath = $uploadDir . $newName;
    $cmd = "timeout 15 convert " . escapeshellarg($fileTmp) . " " . escapeshellarg($destPath) . " 2>&1";
    exec($cmd, $cmdOutput, $cmdStatus);
    $dbPath = 'uploads/artworks/' . $newName;
    $converted = ($cmdStatus === 0) && file_exists($destPath) && filesize($destPath) > 0;
    if (!$converted) {
        error_log("HEIC conversion failed for {$fileName}: " . implode("\n", $cmdOutput));
    } else {
        chmod($destPath, 0644);
    }
} else {
    $newName = 'art_' . $artworkId . '_' . $uniqueId . '.' . $ext;
    $destPath = $uploadDir . $newName;
    $converted = move_uploaded_file($fileTmp, $destPath);
    $dbPath = 'uploads/artworks/' . $newName;
    if ($converted) {
        chmod($destPath, 0644);
    }
}

if ($converted) {
    $dbPath = 'uploads/artworks/' . $newName;

                        // First image is cover
                        $isCover = ($uploadedCount === 0) ? 1 : 0;

                        $stmtImg->bind_param('isii', $artworkId, $dbPath, $isCover, $uploadedCount);
                        if ($stmtImg->execute()) {
                            $savedFilePaths[] = $destPath;
                            $uploadedCount++;
                        } else {
                            error_log('Image insert failed: ' . $stmtImg->error);
                            @unlink($destPath); // this specific insert failed — remove its file immediately
                        }
                    }
                }
            }

            $stmtImg->close();

            if ($uploadedCount > 0) {
                $conn->commit();
                header("Location: my-artworks.php?msg=uploaded");
                exit;
            } else {
                $conn->rollback();
                // Clean up any files saved before the rollback decision
                foreach ($savedFilePaths as $orphanPath) {
                    @unlink($orphanPath);
                }
                $errorMsg = 'Artwork saved but images failed to upload. Please try again.';
            }
            skip_image_loop:

        } else {
            $conn->rollback();
            error_log('Artwork insert failed: ' . $stmt->error);
            $errorMsg = 'Could not save artwork. Please try again.';
        }
    }
    skip_processing:
}
} // closes "if ($_SERVER['REQUEST_METHOD'] === 'POST')"
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload Artwork — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --bg:#F6EDDE; --card:#F6EDDE; --sand:#DDCDAE; --border:#0C3F30;
    --ink:#0C3F30; --body:#0C3F30; --muted:#0C3F30; --light:#0C3F30;
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }

/* ── Sidebar ─────────────────────────────────────────── */
.sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: var(--ink); border-right: 1px solid rgba(246,237,222,.1); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid rgba(246,237,222,.1); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--bg); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); font-weight: 400; margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--bg); text-decoration: none; border-left: 2px solid transparent; transition: all .15s; position: relative; }
.nav-item:hover { color: var(--bg); background: rgba(255,255,255,0.05); border-left-color: rgba(255,255,255,0.2); }
.nav-item.active { color: var(--ink); background: var(--sand); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .7; }
.nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
.badge { margin-left: auto; background: var(--sand); color: var(--ink); font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid rgba(246,237,222,.1); }
.signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.signout-btn:hover { background: rgba(255,255,255,0.1); color: var(--bg); }

/* ── Topbar ──────────────────────────────────────────── */
.topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--ink); border-bottom: 1px solid var(--ink); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.artist-chip { display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); padding: 5px 12px 5px 5px; border-radius: 30px; }
.artist-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--sand); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--ink); font-weight: 600; overflow: hidden; }
.artist-chip .avatar img { width: 100%; height: 100%; object-fit: cover; }
.artist-chip .name { font-size: 12px; font-weight: 500; color: var(--ink); }
.artist-chip .arrow { font-size: 12px; color: var(--muted); margin-left: 4px; }

/* ── Main Layout ────────────────────────────────────── */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; max-width: 860px; }
.section-title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; margin-bottom: 20px; }

/* ── Messages ────────────────────────────────────────── */
.msg { padding: 12px 18px; border-radius: 10px; font-size: 12.5px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); color: var(--ink); }

/* ── Card ────────────────────────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; padding: 32px; }

/* ── Form Grid ───────────────────────────────────────── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
.form-grid.full { grid-template-columns: 1fr; }
.field-group { margin-bottom: 20px; }
.field-group label { display: block; font-size: 10.5px; letter-spacing: .7px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 8px; }
.field-group label span { color: var(--ink); }
.field-input, .field-select, .field-textarea {
    width: 100%; padding: 12px 16px; font-size: 13px; font-family: 'DM Sans', sans-serif;
    border: 1.5px solid var(--sand); border-radius: 10px; background: var(--bg);
    color: var(--ink); outline: none; transition: border-color .15s;
}
.field-input:focus, .field-select:focus, .field-textarea:focus { border-color: var(--ink); }
.field-textarea { min-height: 120px; resize: vertical; line-height: 1.6; }

/* ── Toggle Row ─────────────────────────────────────── */
.toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-top: 1px solid var(--border); }
.toggle-label { font-size: 13px; font-weight: 500; color: var(--ink); }
.toggle-desc { font-size: 11px; color: var(--muted); display: block; margin-top: 2px; }
.toggle-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; inset: 0; cursor: pointer; background: var(--sand); border-radius: 24px; transition: background .2s; }
.toggle-slider::before { content: ''; position: absolute; left: 3px; top: 3px; width: 18px; height: 18px; background: #fff; border-radius: 50%; transition: transform .2s; }
.toggle-switch input:checked + .toggle-slider { background: var(--ink); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

/* ── File Upload Area ───────────────────────────────── */
.upload-area {
    border: 2px dashed var(--border); border-radius: 12px;
    padding: 40px 20px; text-align: center; background: var(--sand);
    transition: border-color .2s; cursor: pointer; margin-bottom: 24px; color: var(--ink);
}
.upload-area:hover { border-color: var(--ink); }
.upload-icon { color: var(--ink); margin-bottom: 12px; }
.upload-title { font-size: 14px; font-weight: 500; color: var(--ink); margin-bottom: 4px; }
.upload-hint { font-size: 11px; color: var(--muted); }

/* ── Preview Grid with Counter & Remove ─────────────────── */
.preview-header {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 12px;
}
.preview-counter {
    font-size: 11px;
    color: var(--muted);
}
.preview-counter span {
    font-weight: 600;
    color: var(--ink);
}
.preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 12px;
}
.preview-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid var(--border);
    background: #f0f0f0;
}
.preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.preview-badge {
    position: absolute;
    top: 4px;
    left: 4px;
    background: var(--ink);
    color: var(--bg);
    font-size: 9px;
    padding: 2px 6px;
    border-radius: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.remove-preview {
    position: absolute;
    top: 4px;
    right: 4px;
    background: var(--ink);
    color: var(--bg);
    border: none;
    border-radius: 50%;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    transition: all .2s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.remove-preview:hover {
    background: var(--sand);
    color: var(--ink);
    transform: scale(1.1);
}

/* ── Actions ─────────────────────────────────────────── */
.form-actions { display: flex; align-items: center; justify-content: flex-end; gap: 16px; margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border); }
.btn {
    padding: 12px 28px; border-radius: 10px; font-size: 13px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer; border: none;
    text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all .15s;
}
.btn-primary { background: var(--sand); color: var(--ink); width: 100%; justify-content: center; }
.btn-primary:hover { background: #c4b69e; }
.btn-ghost { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--ink); color: var(--ink); }

/* ── Drawer (Hamburger) ──────────────────────────────── */
#nav-drawer{display:none; position:fixed; top:0; right:0; bottom:0; width:260px; background:var(--ink); z-index:200; padding:20px; transform:translateX(100%); transition:transform .3s ease; flex-direction:column; border-left:1px solid rgba(246,237,222,.1);}
#nav-drawer.open{transform:translateX(0); display:flex;}
#nav-overlay{display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:199;}
#nav-overlay.open{display:block;}
.ham-btn{display:none; flex-direction:column; gap:4px; background:none; border:none; cursor:pointer; padding:4px;}
.ham-btn span{width:22px; height:2px; background:var(--bg); border-radius:2px; transition:.2s;}
.drawer-top{display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; border-bottom:1px solid rgba(246,237,222,.1); padding-bottom:15px;}
.drawer-logo{font-family:'Playfair Display',serif; font-size:18px; color:var(--bg); font-weight:400;}
.drawer-close{background:none; border:none; color:var(--bg); font-size:24px; cursor:pointer;}
.drawer-links a{display:block; color:var(--bg); text-decoration:none; padding:12px 0; border-bottom:1px solid rgba(246,237,222,.05); font-size:14px;}
.drawer-links a:hover{color:var(--sand);}
.drawer-actions{margin-top:auto; padding-top:20px; border-top:1px solid rgba(246,237,222,.1);}
.drawer-actions a{display:block; padding:10px 0; color:var(--bg); text-decoration:none; font-size:13px;}

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 1080px) {
    /* Footer grid adjustment if needed, but currently no footer grid here */
}

@media (max-width: 768px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; padding: 0 16px; }
    .content { padding: 16px; }
    .form-grid { grid-template-columns: 1fr; }
    .upload-area { width: 100%; }
    .preview-grid { grid-template-columns: repeat(2, 1fr); }
    .btn-primary { width: 100%; }
    .ham-btn { display: flex; }
}
.btn-ghost:hover { border-color: var(--ink); color: var(--ink); }

/* ── Upload Overlay ──────────────────────────────────── */
.upload-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(12, 63, 48, 0.6);
    backdrop-filter: blur(6px);
    z-index: 9999;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 20px;
}
.upload-overlay.active { display: flex; }
.upload-overlay-text {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    color: var(--bg);
    font-weight: 400;
}
.upload-overlay-sub {
    font-size: 12px;
    color: rgba(246,237,222,0.7);
    margin-top: -12px;
}
.dots-loader { display: flex; gap: 12px; }
.dots-loader span {
    width: 14px; height: 14px; border-radius: 50%;
    background: #DDCDAE;
    animation: dotBounce 1.2s infinite ease-in-out;
}
.dots-loader span:nth-child(1) { animation-delay: 0s; }
.dots-loader span:nth-child(2) { animation-delay: 0.2s; }
@keyframes dotBounce {
    0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
    40% { transform: scale(1.2); opacity: 1; }
}
</style>
</head>
<body>

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="logo-tag">Art Bazaar</div>
        <div class="logo-name">Dashboard</div>
        <span class="logo-badge">Artist</span>
    </div>
    <div class="sidebar-section">Overview</div>
    <a href="index.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Overview
    </a>
    <div class="sidebar-section">My Work</div>
    <a href="upload-artwork.php" class="nav-item active">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Upload Artwork
    </a>
    <a href="my-artworks.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
        My Artworks
    </a>
    <a href="commissions.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Commission Requests
    </a>
    
    <!-- ADDED ORDERS LINK -->
    <a href="orders.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        Orders
        <?php if ($newOrdersCount > 0): ?>
            <span class="badge"><?= $newOrdersCount ?></span>
        <?php endif; ?>
    </a>

    <div class="sidebar-section">Account</div>
    <a href="profile.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        My Profile
    </a>
    <div class="sidebar-bottom">
        <a href="../../logout.php" class="signout-btn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign out
        </a>
    </div>
</aside>

<!-- ══════════════ TOPBAR ══════════════ -->
<header class="topbar">
    <div class="topbar-left"><h1>Upload Artwork</h1></div>
    <div class="topbar-right">
        <button class="ham-btn" onclick="openDrawer()"><span></span><span></span><span></span></button>
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <div class="section-title">Submit New Artwork</div>

    <?php if ($errorMsg): ?>
        <div class="msg"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="uploadForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="upload_token" value="<?= htmlspecialchars($_SESSION['upload_token']) ?>">

        <div class="card">
            
            <!-- ── Images ─────────────────────────────── -->
            <div class="upload-area" id="dropZone">
                <svg class="upload-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <div class="upload-title">Click to upload images</div>
                <div class="upload-hint">
                    Up to 5 images · Max 10MB each · First image will be the cover.
                    <?php if (!$heicSupported): ?>
                        <br><strong>Note:</strong> iPhone HEIC photos aren't supported right now — please upload JPG or PNG.
                    <?php endif; ?>
                </div>
<div style="margin-top:14px;text-align:left;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px 14px;">
    <div style="font-size:10px;letter-spacing:1px;text-transform:uppercase;font-weight:600;color:var(--ink);margin-bottom:8px;">Image Guidelines</div>
    <ul style="list-style:none;display:flex;flex-direction:column;gap:5px;">
        <li style="font-size:11px;color:var(--ink);">✓ Clear, well-lit photo of the actual artwork</li>
        <li style="font-size:11px;color:var(--ink);">✗ No watermarks covering the artwork</li>
        <li style="font-size:11px;color:var(--ink);">✗ Do not upload stolen or unoriginal work</li>
        <li style="font-size:11px;color:var(--ink);">✗ No copyrighted characters (Disney, Marvel, etc.) unless you hold the rights</li>
        <li style="font-size:11px;color:var(--ink);">✗ No AI-generated artwork</li>
    </ul>
    <div style="font-size:10px;color:var(--ink);opacity:0.6;margin-top:8px;border-top:1px solid var(--border);padding-top:8px;">Violations may result in artwork rejection or account suspension.</div>
</div>
                <input type="file" name="images[]" id="fileInput" accept="image/jpeg,image/png,image/webp<?= $heicSupported ? ',image/heic,image/heif' : '' ?>" multiple hidden>
            </div>

            <div class="preview-header">
                <div></div>
                <div class="preview-counter" id="previewCounter">0 / 5 images selected</div>
            </div>
            <div class="preview-grid" id="previewGrid"></div>

            <!-- ── Details ────────────────────────────── -->
            <div class="form-grid full" style="margin-top: 24px;">
                <div class="field-group">
                    <label>Artwork Title <span>*</span></label>
                    <input type="text" name="title" class="field-input" placeholder="e.g. The Blue Horizon" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>Category <span>*</span></label>
                    <select name="category" class="field-select" id="categorySelect" required>
                        <option value="">Select category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" data-slug="<?= htmlspecialchars($cat['slug']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label>Medium <span>*</span></label>
                    <input type="text" name="medium" class="field-input" placeholder="e.g. Oil on Canvas" required>
                </div>
            </div>

            <div class="form-grid full" id="digitalFileGroup" style="display:none;">
                <div class="field-group">
                    <label>Digital Artwork File <span>*</span></label>
                    <input type="file" name="digital_file" id="digitalFileInput" class="field-input" accept=".zip,.psd,.ai,.png,.jpg,.jpeg,.pdf">
                    <p style="font-size:11px;color:var(--muted);margin-top:6px;">This is the actual file the buyer will download after payment is confirmed. Not shown publicly. Max 50MB. Allowed: ZIP, PSD, AI, PNG, JPG, PDF.</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>Size <span>*</span></label>
                    <input type="text" name="size" class="field-input" placeholder="e.g. 24 x 36 inches" required>
                </div>
                <div class="field-group">
                    <label>Price (PKR) <span>*</span></label>
                    <input type="number" name="price" class="field-input" placeholder="e.g. 25000" min="1" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>Weight (kg) <span>*</span></label>
                    <input type="number" name="weight_kg" class="field-input" placeholder="e.g. 1.5" min="0.1" step="0.1" value="1" required>
                    <p style="font-size:11px;color:var(--muted);margin-top:6px;">Used to calculate shipping fee. Add +100 PKR per kg above 1 kg.</p>
                </div>
                <div class="field-group">
                    <!-- Spacer -->
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>City <span>*</span></label>
                    <input type="text" name="city" class="field-input" placeholder="e.g. Lahore" required>
                </div>
                <div class="field-group">
                    <!-- Spacer for balance -->
                </div>
            </div>

            <div class="form-grid full">
                <div class="field-group">
                    <label>Description</label>
                    <textarea name="description" class="field-textarea" placeholder="Tell the story behind this artwork, techniques used, inspiration..."></textarea>
                </div>
            </div>

            <div class="form-grid full">
                <div class="field-group">
                    <label>Tags <small style="text-transform:none;letter-spacing:0;font-weight:400;opacity:.7;">(optional)</small></label>
                    <input type="text" name="tags" class="field-input" placeholder="e.g. floral, abstract, blue, Lahore, miniature, portrait, gift">
                    <p style="font-size:11px;color:var(--muted);margin-top:6px;">Separate keywords with commas. These help buyers find your artwork through search.</p>
                </div>
            </div>

            <!-- ── Toggles ───────────────────────────── -->
            <div class="toggle-row">
                <div>
                    <div class="toggle-label">Framed</div>
                    <span class="toggle-desc">Is this artwork framed and ready to hang?</span>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="is_framed" value="1">
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="toggle-row">
                <div>
                    <div class="toggle-label">Similar Work Available</div>
                    <span class="toggle-desc">Can you create similar custom commissions?</span>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="similar_work_available" value="1">
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <!-- ── Actions ───────────────────────────── -->
            <div class="form-actions">
                <a href="my-artworks.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    Publish Artwork
                </button>
            </div>

        </div>
    </form>

</div>
</main>

<!-- NAV DRAWER (Mobile) -->
<div id="nav-overlay" onclick="closeDrawer()"></div>
<div id="nav-drawer">
    <div class="drawer-top">
        <div class="drawer-logo">Art Bazaar</div>
        <button class="drawer-close" onclick="closeDrawer()">&times;</button>
    </div>
    <div class="drawer-links">
        <a href="../../index.php">Home</a>
        <a href="index.php">Dashboard</a>
        <a href="upload-artwork.php">Upload Artwork</a>
        <a href="my-artworks.php">My Artworks</a>
        <a href="commissions.php">Commissions</a>
        <a href="orders.php">Orders</a>
        <a href="profile.php">Profile</a>
    </div>
    <div class="drawer-actions">
        <a href="../../cart.php">Cart</a>
        <a href="../../logout.php">Logout</a>
    </div>
</div>

<script>
function openDrawer() {
    document.getElementById('nav-drawer').classList.add('open');
    document.getElementById('nav-overlay').classList.add('open');
}
function closeDrawer() {
    document.getElementById('nav-drawer').classList.remove('open');
    document.getElementById('nav-overlay').classList.remove('open');
}

// ── Image Preview Logic with Remove Functionality & Counter ─────────────────
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const previewGrid = document.getElementById('previewGrid');
const previewCounter = document.getElementById('previewCounter');
const uploadForm = document.getElementById('uploadForm');

let currentFiles = [];
let compressedFiles = [];
let compressionPromise = null;
let isSubmitting = false;

dropZone.addEventListener('click', () => fileInput.click());

// ── Toggle Digital File field based on category ──────────────────────────
const categorySelect = document.getElementById('categorySelect');
const digitalFileGroup = document.getElementById('digitalFileGroup');
const digitalFileInput = document.getElementById('digitalFileInput');

function updateDigitalFileVisibility() {
    const selected = categorySelect.options[categorySelect.selectedIndex];
    const isDigital = selected && selected.dataset.slug === 'digital-art';
    digitalFileGroup.style.display = isDigital ? 'block' : 'none';
    digitalFileInput.required = isDigital;
}
categorySelect.addEventListener('change', updateDigitalFileVisibility);
updateDigitalFileVisibility();

fileInput.addEventListener('change', function(e) {
    const newFiles = Array.from(e.target.files);
    
    if (currentFiles.length + newFiles.length > 5) {
        alert('You can only upload up to 5 images. Please remove some before adding more.');
        fileInput.value = '';
        return;
    }
    
    newFiles.forEach(file => {
        if (file.type.startsWith('image/') || file.name.match(/\.(heic|heif)$/i)) {
            currentFiles.push(file);
        }
    });
    
    renderPreviews();
    compressionPromise = compressAllInBackground(); // ← compress immediately in background, track the promise
});

function compressImage(file, maxWidth = 1400, quality = 0.70) {
    return new Promise((resolve) => {
        if (file.name.match(/\.(heic|heif)$/i)) { resolve(file); return; }
        if (file.size < 2 * 1024 * 1024) { resolve(file); return; } // skip compression for small files
        const reader = new FileReader();
        reader.onload = (e) => {
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                let width = img.width, height = img.height;
                if (width > maxWidth) { height = Math.round((height * maxWidth) / width); width = maxWidth; }
                canvas.width = width; canvas.height = height;
                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    console.error('Canvas context unavailable:', file.name);
                    resolve(file);
                    return;
                }
                ctx.drawImage(img, 0, 0, width, height);

                // Preserve transparency for PNGs — don't force-convert to JPG (which has no alpha channel)
                const isPng = file.type === 'image/png' || /\.png$/i.test(file.name);
                const outputType = isPng ? 'image/png' : 'image/jpeg';
                const outputExt = isPng ? '.png' : '.jpg';
                const outputQuality = isPng ? undefined : quality; // PNG toBlob ignores quality anyway

                canvas.toBlob((blob) => {
                    if (!blob) {
                        console.error('Compression failed:', file.name);
                        resolve(file); // Upload original file instead
                        return;
                    }
                    resolve(new File([blob], file.name.replace(/\.(png|webp)$/i, outputExt), { type: outputType, lastModified: Date.now() }));
                }, outputType, outputQuality);
            };
            img.onerror = () => {
                console.error('Image decode failed:', file.name);
                resolve(file);
            };
            img.src = e.target.result;
        };
        reader.onerror = () => {
            console.error('File read failed:', file.name);
            resolve(file);
        };
        reader.readAsDataURL(file);
    });
}

async function compressAllInBackground() {
    compressedFiles = await Promise.all(currentFiles.map(f => compressImage(f)));
}

let previewUrls = []; // track object URLs so we can revoke them and avoid memory leaks

function renderPreviews() {
    // Revoke old object URLs before re-rendering
    previewUrls.forEach(url => URL.revokeObjectURL(url));
    previewUrls = [];

    previewGrid.innerHTML = '';
    currentFiles.forEach((file, index) => {
        const objectUrl = URL.createObjectURL(file);
        previewUrls.push(objectUrl);

        const div = document.createElement('div');
        div.className = 'preview-item';
        div.setAttribute('data-index', index);
        div.innerHTML = `
            <img src="${objectUrl}" alt="Preview ${index + 1}">
            ${index === 0 ? '<span class="preview-badge">Cover</span>' : ''}
            <button type="button" class="remove-preview" data-index="${index}" title="Remove image">×</button>
        `;
        previewGrid.appendChild(div);
        div.querySelector('.remove-preview').addEventListener('click', function(e) {
            e.stopPropagation();
            removeFileAtIndex(parseInt(this.getAttribute('data-index')));
        });
    });
    previewCounter.innerHTML = `${currentFiles.length} / 5 images selected`;
}

function removeFileAtIndex(index) {
    currentFiles.splice(index, 1);
    renderPreviews();
    compressionPromise = compressAllInBackground();
}

// ── Form Submission ──────────────────────────────────────────────────────
uploadForm.addEventListener('submit', async function(e) {
    e.preventDefault();

    if (currentFiles.length === 0) { alert('Please upload at least one image.'); return; }
    if (isSubmitting) return;
    isSubmitting = true;

    const submitBtn = uploadForm.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Preparing images…';
    document.getElementById('uploadOverlay').classList.add('active');
    document.getElementById('overlayProgressText').textContent = 'Preparing images…';

    const formData = new FormData(uploadForm);
    formData.delete('images[]');

    // Wait for any in-flight background compression instead of redoing it
    if (compressionPromise) {
        await compressionPromise;
    }
    const filesToUpload = compressedFiles.length === currentFiles.length
        ? compressedFiles
        : await Promise.all(currentFiles.map(f => compressImage(f)));

    filesToUpload.forEach(file => formData.append('images[]', file));

    submitBtn.textContent = 'Uploading…';
    document.getElementById('overlayProgressText').textContent = 'Please wait';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.href, true);

    xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            document.getElementById('overlayProgressText').textContent = `${pct}% complete`;
        }
    });

    xhr.onload = function() {
        document.getElementById('uploadOverlay').classList.remove('active');
        console.log('Status:', xhr.status);
        console.log('Response:', xhr.responseText);

        if (xhr.responseURL && xhr.responseURL !== window.location.href) {
            window.location.href = xhr.responseURL;
        } else {
            const doc = new DOMParser().parseFromString(xhr.responseText, 'text/html');
            const errorDiv = doc.querySelector('.msg');
            const existingMsg = document.querySelector('.msg');
            if (existingMsg) existingMsg.remove();

            if (errorDiv) {
                uploadForm.parentNode.insertBefore(errorDiv, uploadForm);
            } else {
                alert('Upload failed. Please try again or contact support.');
                console.log('No .msg element found. Full response logged above.');
            }

            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            isSubmitting = false;
        }
    };

    xhr.onerror = function() {
        document.getElementById('uploadOverlay').classList.remove('active');
        console.error('Upload failed.');
        alert('An error occurred during upload. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        isSubmitting = false;
    };

    xhr.send(formData);
});

uploadForm.addEventListener('reset', function() {
    previewUrls.forEach(url => URL.revokeObjectURL(url));
    previewUrls = [];
    currentFiles = [];
    compressedFiles = [];
    renderPreviews();
});
</script>

<div class="upload-overlay" id="uploadOverlay">
    <div class="dots-loader">
        <span></span>
        <span></span>
    </div>
    <div class="upload-overlay-text">Uploading your artwork…</div>
    <div class="upload-overlay-sub" id="overlayProgressText">Please wait</div>
</div>

</body>
</html>