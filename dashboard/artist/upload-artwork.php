<?php
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
$pakistaniCities = [
    'Abbottabad','Ahmedpur East','Arif Wala','Attock','Badin','Bahawalnagar','Bahawalpur',
    'Barikot','Bhakkar','Bhalwal','Bholari','Burewala','Chaman','Charsadda','Chichawatni',
    'Chiniot','Chishtian','Dadu','Daska','Dera Ghazi Khan','Dera Ismail Khan','Dera Murad Jamali',
    'Dipalpur','Faisalabad','Farooqabad','Ferozwala','Ghotki','Gojra','Gujar Khan','Gujranwala',
    'Gujranwala Cantonment','Gujrat','Hafizabad','Haroonabad','Hasilpur','Haveli Lakha','Hub',
    'Hyderabad','Islamabad','Jacobabad','Jalalpur Jattan','Jampur','Jaranwala','Jatoi','Jauharabad',
    'Jhang','Jhelum','Kabal','Kamalia','Kamber Ali Khan','Kamoke','Karachi','Kasur','Khairpur',
    'Khanewal','Khanpur','Kharian','Khushab','Khuzdar','Kohat','Kot Abdul Malik','Kot Addu',
    'Kot Radha Kishan','Kotri','Lahore','Lala Musa','Larkana','Layyah','Lodhran','Ludhewala Waraich',
    'Mailsi','Mandi Bahauddin','Mansehra','Mardan','Mian Channu','Mianwali','Mingora','Mirpur',
    'Mirpur Khas','Moro','Multan','Muridke','Muzaffarabad','Muzaffargarh','Narowal','Nawabshah',
    'Nowshera','Okara','Pakpattan','Panjgur','Pasrur','Pattoki','Phool Nagar','Pishin','Quetta',
    'Rahim Yar Khan','Rajanpur','Rawalpindi','Renala Khurd','Sadiqabad','Sahiwal','Sambrial',
    'Samundri','Sangla Hill','Sargodha','Shabqadar','Shahdadkot','Shahdadpur','Shakargarh',
    'Shikarpur','Shujabad','Sialkot','Sukkur','Swabi','Taxila','Tando Adam','Tando Allahyar',
    'Tando Muhammad Khan','Taunsa','Turbat','Umerkot','Vehari','Wah Cantonment','Wazirabad'
];

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

// ── Fetch Pending Artworks Count for Sidebar Badge ──────
 $pendingCount = (int) ($conn->query("SELECT COUNT(*) FROM artworks WHERE artist_id = $artistId AND status = 'pending'")->fetch_row()[0] ?? 0);

// ── Fetch New Commissions Count for Sidebar Badge ───────
 $newCommCount = (int) ($conn->query("
    SELECT COUNT(*) 
    FROM commission_requests cr 
    JOIN orders o ON cr.order_id = o.id 
    WHERE cr.artist_id = $artistId AND o.order_type = 'commission' AND o.order_status = 'assigned'
")->fetch_row()[0] ?? 0);

// ── Fetch New Orders Count for Sidebar Badge ────────────
 $newOrdersCount = 0;
 $countStmt = $conn->prepare("
   SELECT COUNT(DISTINCT o.id) 
   FROM orders o
   JOIN order_items oi ON o.id = oi.order_id
   JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
   WHERE a.artist_id = ? AND o.order_type = 'artwork'
     AND o.order_status NOT IN ('pending', 'payment_review')
     AND o.seen_by_artist = 0
");
 $countStmt->bind_param('i', $artistId);
 $countStmt->execute();
 $newOrdersCount = $countStmt->get_result()->fetch_row()[0];
 $unreadCommissionMsgs = (int)$conn->query("
    SELECT COUNT(*) FROM order_messages om
    JOIN commission_requests cr ON cr.order_id = om.order_id
    WHERE cr.artist_id = $artistId
      AND om.sender_role != 'artist'
      AND om.is_read_by_artist = 0
")->fetch_row()[0];

 $unreadOrderMsgs = (int)$conn->query("
    SELECT COUNT(DISTINCT om.id) FROM order_messages om
    JOIN orders o ON o.id = om.order_id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
    WHERE a.artist_id = $artistId
      AND o.order_type = 'artwork'
      AND om.sender_role != 'artist'
      AND om.is_read_by_artist = 0
")->fetch_row()[0];

 $pendingQCount = (int)$conn->query("
    SELECT COUNT(*) FROM artwork_questions aq
    JOIN artworks a ON aq.artwork_id = a.id
    WHERE a.artist_id = $artistId AND aq.answer IS NULL
")->fetch_row()[0];

// ── Server-side image resize safety net ──────────────
// Re-saves an on-disk image in place if it's larger than our target, using
// GD. This is a backstop for whatever the browser's compression missed —
// normal path is that the browser already sent a small file, so this is a
// no-op most of the time and only does real work for oversized/JS-disabled
// uploads.
function resizeImageIfNeeded(string $path, int $maxDimension = 2000, int $jpegQuality = 85): void {
    $info = @getimagesize($path);
    if ($info === false) return;
    [$width, $height, $type] = $info;

    // Already small enough — leave it alone.
    if ($width <= $maxDimension && $height <= $maxDimension) return;

    $source = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
    if (!$source) return;

    $scale = $maxDimension / max($width, $height);
    $newWidth = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));

    $resized = imagecreatetruecolor($newWidth, $newHeight);

    // Write back out in the SAME format it came in as, so the file
    // extension on disk still matches its actual content.
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
    }
    imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    match ($type) {
        IMAGETYPE_JPEG => imagejpeg($resized, $path, $jpegQuality),
        IMAGETYPE_PNG  => imagepng($resized, $path, 6),
        IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($resized, $path, $jpegQuality) : imagejpeg($resized, $path, $jpegQuality),
        default => null,
    };

    imagedestroy($source);
    imagedestroy($resized);
}

// ── Handle form submission ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $__dbg = __DIR__ . '/upload_debug.log';
    file_put_contents($__dbg, date('c') . " START user={$_SESSION['user_id']} files=" . json_encode(array_map(fn($n,$s)=>"$n ({$s}b)", $_FILES['images']['name'] ?? [], $_FILES['images']['size'] ?? [])) . "\n", FILE_APPEND);

    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errorMsg = 'Invalid session token. Please refresh the page and try again.';
        goto skip_processing;
    }

    // Prevent double/multi-tab submission — token is single-use.
    // A mismatch can mean two different things: (a) a second tab/request
    // resubmitting a form whose upload already succeeded moments ago — safe
    // to silently no-op, or (b) an expired/missing/never-issued token, where
    // nothing was ever saved. Only (a) should redirect as "uploaded"; (b)
    // must surface a real error, or the user is told artwork was uploaded
    // when it wasn't.
    $tokenMismatch = !isset($_POST['upload_token'])
        || !isset($_SESSION['upload_token'])
        || !hash_equals($_SESSION['upload_token'], $_POST['upload_token']);

    if ($tokenMismatch) {
        $recentSuccess = isset($_SESSION['last_upload_success_at'])
            && (time() - $_SESSION['last_upload_success_at']) < 60;

        if ($recentSuccess) {
            // Genuine duplicate resubmission of an already-successful upload.
            header("Location: my-artworks.php?msg=uploaded");
            exit;
        }

        $errorMsg = 'This upload session has expired. Please refresh the page and try again.';
        goto skip_processing;
    }
    // Invalidate immediately so a second tab/request with the same token can't slip through
    unset($_SESSION['upload_token']);

    $title                  = trim($_POST['title'] ?? '');
    $categoryId             = (int) ($_POST['category'] ?? 0);
    $medium                 = trim($_POST['medium'] ?? '');
    $size                   = trim($_POST['size'] ?? '');
    $licenseType            = trim($_POST['license_type'] ?? '');
    $isShowcaseOnly         = isset($_POST['is_showcase_only']) ? 1 : 0;
    $price                  = $isShowcaseOnly ? null : (float) ($_POST['price'] ?? 0);
    $city                   = trim($_POST['city'] ?? '');
    $description            = trim($_POST['description'] ?? '');
    $tags                   = trim($_POST['tags'] ?? '');
    $delivery_available = 1;
 $similar_work_available = isset($_POST['similar_work_available']) ? 1 : 0;
 $is_framed              = isset($_POST['is_framed']) ? 1 : 0;
    $weight_kg              = (float)($_POST['weight_kg'] ?? 1.00);

    // Determine delivery type for this listing.
    // Every artwork asks the artist directly, regardless of category —
    // no more inferring/forcing it from the category slug.
    $postedDelivery = $_POST['delivery_type'] ?? '';
    if ($postedDelivery !== 'physical' && $postedDelivery !== 'digital') {
        $errorMsg = 'Please choose how buyers will receive this artwork (physical or digital).';
        goto skip_processing;
    }
    $deliveryType = $postedDelivery;
    $isDigitalItem = ($deliveryType === 'digital');

    // Digital art has no physical shipment — always force weight to 1kg
    // server-side too, regardless of what the (hidden) form field posted.
    if ($isDigitalItem) {
        $weight_kg = 1.00;
    }

    // Validation: Check if the 'images' input actually has files
    $hasFiles = isset($_FILES['images']) && isset($_FILES['images']['name'][0]) && $_FILES['images']['name'][0] !== '';
    $imageCount = $hasFiles ? count($_FILES['images']['name']) : 0;

    // Digital Art listings must include the deliverable file
    $hasDigitalFile = isset($_FILES['digital_file']) && $_FILES['digital_file']['error'] === UPLOAD_ERR_OK;
    $digitalFileExt = $hasDigitalFile ? strtolower(pathinfo($_FILES['digital_file']['name'], PATHINFO_EXTENSION)) : '';
    $allowedDigitalExt = ['zip', 'psd', 'ai', 'png', 'jpg', 'jpeg', 'pdf', 'gif', 'mp4', 'mov', 'webm', 'mp3', 'wav', 'm4a'];

    // A "size" like "24 x 36" is ambiguous without a unit (inches? cm?),
    // and for digital items px vs MB mean completely different things —
    // so require a recognizable unit token, mirroring the client-side check.
    $physicalSizeUnitRegex = '/\b(in|inch|inches|"|cm|centimeters?|mm|millimeters?|ft|feet|\'|m|meters?)\b/i';
    $digitalSizeUnitRegex  = '/\b(px|pixels?|kb|mb|gb)\b/i';
    $sizeUnitRegex = $isDigitalItem ? $digitalSizeUnitRegex : $physicalSizeUnitRegex;
    $sizeMissingUnit = $size !== '' && !preg_match($sizeUnitRegex, $size);

    if ($title === '' || (!$isShowcaseOnly && $price <= 0) || $categoryId === 0 || !$hasFiles) {
        $errorMsg = $isDigitalItem
            ? 'Please fill in all required fields and add at least one public preview image (step 1).'
            : 'Please fill in all required fields and upload at least one image.';
    } elseif ($size === '') {
        $errorMsg = 'Please enter a size.';
    } elseif ($sizeMissingUnit) {
        $errorMsg = $isDigitalItem
            ? 'Please include a unit with the size, e.g. "1920 x 1080 px" or "15 MB".'
            : 'Please include a unit with the size, e.g. "24 x 36 inches" or "60 x 90 cm".';
    } elseif ($imageCount > 5) {
        $errorMsg = 'You can only upload up to 5 images.';
    } elseif ($isDigitalItem && !$hasDigitalFile) {
        $errorMsg = 'Please upload the final file buyers will receive after purchase (step 2).';
    } elseif ($isDigitalItem && !in_array($digitalFileExt, $allowedDigitalExt)) {
        $errorMsg = 'Invalid digital file type. Allowed: ZIP, PSD, AI, PNG, JPG, PDF, GIF, MP4, MOV, WebM, MP3, WAV, M4A.';
    } elseif ($isDigitalItem && $_FILES['digital_file']['size'] > 300 * 1024 * 1024) {
        $errorMsg = 'Digital file must be under 300MB.';
    } elseif ($isDigitalItem && $licenseType === '') {
        $errorMsg = 'Please select a license type for this digital artwork.';
    } else {
        
        // 1. Insert Artwork
        file_put_contents($__dbg, date('c') . " VALIDATION_PASSED — starting DB insert\n", FILE_APPEND);
        $conn->begin_transaction();

 $initialStatus = $isShowcaseOnly ? 'sold' : 'active';
 $stmt = $conn->prepare("
    INSERT INTO artworks 
    (artist_id, category_id, title, description, tags, medium, size, is_framed, weight_kg, price, city, delivery_available, similar_work_available, status, is_showcase_only, delivery_type)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    error_log('Failed to prepare artwork insert statement: ' . $conn->error);
    $conn->rollback();
    $errorMsg = 'Could not save artwork. Please try again.';
} else {
    $stmt->bind_param('iisssssiddsiisis', $artistId, $categoryId, $title, $description, $tags, $medium, $size, $is_framed, $weight_kg, $price, $city, $delivery_available, $similar_work_available, $initialStatus, $isShowcaseOnly, $deliveryType);

        if ($stmt->execute()) {
            $artworkId = $conn->insert_id;

            // 1b. Handle Digital File (only for Digital Art category)
            if ($isDigitalItem && $hasDigitalFile) {
                $digitalDir = __DIR__ . '/../../uploads/digital_files/';
                if (!is_dir($digitalDir)) {
                    mkdir($digitalDir, 0755, true);
                }
                $digitalName = 'digital_' . $artworkId . '_' . bin2hex(random_bytes(8)) . '.' . $digitalFileExt;
                $digitalDest = $digitalDir . $digitalName;

                if (move_uploaded_file($_FILES['digital_file']['tmp_name'], $digitalDest)) {
                    chmod($digitalDest, 0644);
                    $dbDigitalPath = 'uploads/digital_files/' . $digitalName;

                    // Auto-derive format & size from the actual uploaded file —
                    // never trust manual entry for these.
                    $digitalFileSizeBytes = $_FILES['digital_file']['size'];

                    // Resolution only applies to image-type digital files;
                    // stays NULL for zip/psd/ai/video/audio, which is fine —
                    // the detail page simply won't show that row for those.
                    $digitalResolution = null;
                    $resolutionCheckableExt = ['jpg', 'jpeg', 'png', 'gif'];
                    if (in_array($digitalFileExt, $resolutionCheckableExt)) {
                        $dim = @getimagesize($digitalDest);
                        if ($dim !== false) {
                            $digitalResolution = $dim[0] . ' x ' . $dim[1] . ' px';
                        }
                    }

                    $stmtDigital = $conn->prepare("UPDATE artworks SET digital_file_path = ?, digital_file_format = ?, digital_file_size_bytes = ?, digital_resolution = ?, license_type = ? WHERE id = ?");
                    $stmtDigital->bind_param('ssissi', $dbDigitalPath, $digitalFileExt, $digitalFileSizeBytes, $digitalResolution, $licenseType, $artworkId);
                    $stmtDigital->execute();
                } else {
                    $conn->rollback();
                    $errorMsg = 'Failed to save digital artwork file. Please try again.';
                    goto skip_image_loop;
                }
            }

            // 2. Handle Images
            file_put_contents($__dbg, date('c') . " ARTWORK_INSERTED id={$artworkId} — starting image loop\n", FILE_APPEND);
            $uploadDir = __DIR__ . '/../../uploads/artworks/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $files = $_FILES['images'];
            $uploadedCount = 0;
            $imageExt = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'gif'];
            $videoExt = ['mp4', 'mov', 'webm'];
            $audioExt = ['mp3', 'wav', 'm4a'];
            $allowedExt = $isDigitalItem ? array_merge($imageExt, $videoExt, $audioExt) : $imageExt;

            // Open finfo handle once, outside the loop
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            // Prepare the image-insert statement once, outside the loop
            $stmtImg = $conn->prepare("INSERT INTO artwork_images (artwork_id, image_path, media_type, is_cover, sort_order) VALUES (?, ?, ?, ?, ?)");
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

                    file_put_contents(
                        $__dbg,
                        date('c') . " FILE[$i] name={$fileName} size={$fileSize}\n",
                        FILE_APPEND
                    );

                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt)) {
                        file_put_contents(
                            $__dbg,
                            date('c') . " SKIP[$i] {$fileName} - Invalid extension: {$ext}\n",
                            FILE_APPEND
                        );
                        continue; // Skip invalid types
                    }
                    if (in_array($ext, ['heic', 'heif']) && !$heicSupported) {
                        error_log("HEIC upload skipped — server lacks ImageMagick/timeout support for file: {$fileName}");
                        file_put_contents(
                            $__dbg,
                            date('c') . " SKIP[$i] {$fileName} - HEIC not supported on this server\n",
                            FILE_APPEND
                        );
                        continue; // Skip HEIC if the server can't convert it
                    }

                    // Bigger allowance for video/audio previews than for static images
                    $isMediaFile = in_array($ext, $videoExt) || in_array($ext, $audioExt);
                    $maxFileSize = $isMediaFile ? 60 * 1024 * 1024 : 10 * 1024 * 1024;
                    if ($fileSize > $maxFileSize) {
                        file_put_contents(
                            $__dbg,
                            date('c') . " SKIP[$i] {$fileName} - Too large ({$fileSize} bytes, max {$maxFileSize})\n",
                            FILE_APPEND
                        );
                        continue; // Skip oversized files
                    }

                    // MIME type check — don't trust the extension alone.
                    // HEIC/HEIF is special-cased: many hosts report it as application/octet-stream
                    // even for genuinely valid files, so if the server has confirmed HEIC support
                    // and the extension matches, we trust the extension instead of the MIME sniff.
                    $mime  = finfo_file($finfo, $fileTmp);
                    file_put_contents(
                        $__dbg,
                        date('c') . " MIME[$i] {$fileName} = {$mime}\n",
                        FILE_APPEND
                    );
                    $allowedMime = [
                        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                        'image/heic', 'image/heif',
                        'video/mp4', 'video/quicktime', 'video/webm',
                        'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/x-m4a'
                    ];
                    $isTrustedHeic = $heicSupported && in_array($ext, ['heic', 'heif']);
                    if (!$isTrustedHeic && !in_array($mime, $allowedMime)) {
                        file_put_contents(
                            $__dbg,
                            date('c') . " SKIP[$i] {$fileName} - Invalid MIME: {$mime}\n",
                            FILE_APPEND
                        );
                        continue; // Skip files whose real content doesn't match an allowed type
                    }

                    // Dimension check — protect against decompression-bomb style images
                    // getimagesize() doesn't reliably read HEIC/HEIF/video/audio, so only check formats it supports
                    $dimensionCheckable = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                    if (in_array($ext, $dimensionCheckable)) {
                        $dimensions = @getimagesize($fileTmp);
                        if ($dimensions === false) {
                            file_put_contents(
                                $__dbg,
                                date('c') . " SKIP[$i] {$fileName} - getimagesize() failed (unreadable image)\n",
                                FILE_APPEND
                            );
                            continue; // Not a readable image despite passing MIME check — skip it
                        }
                        [$imgWidth, $imgHeight] = $dimensions;
                        if ($imgWidth > 8000 || $imgHeight > 8000) {
                            file_put_contents(
                                $__dbg,
                                date('c') . " SKIP[$i] {$fileName} - Dimensions too large ({$imgWidth}x{$imgHeight})\n",
                                FILE_APPEND
                            );
                            continue; // Skip excessively large images
                        }
                    }

                    // Generate unique filename + handle HEIC conversion
 $uniqueId = bin2hex(random_bytes(8));
if (in_array($ext, ['heic', 'heif'])) {
    $newName = 'art_' . $artworkId . '_' . $uniqueId . '.jpg';
    $destPath = $uploadDir . $newName;
    file_put_contents($__dbg, date('c') . " CONVERTING_HEIC file={$fileName} size={$fileSize}b\n", FILE_APPEND);
    $cmd = "timeout 15 convert " . escapeshellarg($fileTmp) . " " . escapeshellarg($destPath) . " 2>&1";
    exec($cmd, $cmdOutput, $cmdStatus);
    file_put_contents($__dbg, date('c') . " HEIC_DONE status={$cmdStatus}\n", FILE_APPEND);
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

    file_put_contents(
        $__dbg,
        date('c') . " MOVE[$i] {$fileName} = " . ($converted ? "SUCCESS" : "FAILED") . "\n",
        FILE_APPEND
    );

    $dbPath = 'uploads/artworks/' . $newName;
    if ($converted) {
        chmod($destPath, 0644);
    }
}

// Server-side safety net: cap dimensions/filesize even if the browser
// didn't compress (JS disabled, non-browser client, compression failed,
// etc). Only for static raster images we can safely re-encode with GD —
// GIF is skipped to preserve animation, video/audio pass through untouched.
$mediaTypeForResize = in_array($ext, $videoExt) ? 'video' : (in_array($ext, $audioExt) ? 'audio' : 'image');
if ($converted && $mediaTypeForResize === 'image' && $ext !== 'gif' && function_exists('gd_info')) {
    resizeImageIfNeeded($destPath);
}

if ($converted) {
    $dbPath = 'uploads/artworks/' . $newName;

                        // First image is cover
                        $isCover = ($uploadedCount === 0) ? 1 : 0;
                        $mediaType = in_array($ext, $videoExt) ? 'video' : (in_array($ext, $audioExt) ? 'audio' : 'image');

                        $stmtImg->bind_param('issii', $artworkId, $dbPath, $mediaType, $isCover, $uploadedCount);
                        if ($stmtImg->execute()) {
                            file_put_contents(
                                $__dbg,
                                date('c') . " DB[$i] INSERT OK {$dbPath}\n",
                                FILE_APPEND
                            );
                            $savedFilePaths[] = $destPath;
                            $uploadedCount++;
                        } else {
                            file_put_contents(
                                $__dbg,
                                date('c') . " DB[$i] INSERT FAILED: " . $stmtImg->error . "\n",
                                FILE_APPEND
                            );
                            error_log('Image insert failed: ' . $stmtImg->error);
                            @unlink($destPath); // this specific insert failed — remove its file immediately
                        }
                    }
                }
            }

            $stmtImg->close();

            if ($uploadedCount > 0) {
                $conn->commit();
                $_SESSION['last_upload_success_at'] = time();
                file_put_contents($__dbg, date('c') . " SUCCESS uploaded={$uploadedCount} — redirecting\n", FILE_APPEND);
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
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
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
.field-input.field-invalid { border-color: #c0392b; }
.field-error-text { font-size: 11px; color: #c0392b; margin-top: 6px; display: none; }
.field-error-text.show { display: block; }
.field-textarea { min-height: 120px; resize: vertical; line-height: 1.6; }
.city-search-wrap{position:relative;}
.city-dropdown{display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg);border:1.5px solid var(--border);border-radius:8px;max-height:180px;overflow-y:auto;z-index:50;box-shadow:0 8px 20px rgba(0,0,0,0.1);margin-top:4px;}
.city-dropdown.open{display:block;}
.city-option{padding:8px 14px;font-size:13px;color:var(--ink);cursor:pointer;}
.city-option:hover,.city-option.active{background:var(--sand);}
.city-no-results{padding:8px 14px;font-size:11.5px;color:var(--muted);font-style:italic;}

/* ── Upload Overlay ─────────────────────────────────── */
.upload-overlay {
    display: none; position: fixed; inset: 0; background: rgba(12,63,48,0.88);
    z-index: 1000; align-items: center; justify-content: center; flex-direction: column;
}
.upload-overlay.active { display: flex; }
.upload-overlay .spinner {
    width: 44px; height: 44px; border: 3px solid rgba(246,237,222,.25);
    border-top-color: var(--bg); border-radius: 50%; animation: spin .8s linear infinite; margin-bottom: 18px;
}
@keyframes spin { to { transform: rotate(360deg); } }
.upload-overlay .overlay-title { color: var(--bg); font-family: 'Playfair Display', serif; font-size: 18px; margin-bottom: 6px; }
.upload-overlay .overlay-sub { color: var(--bg); font-size: 12.5px; opacity: .8; display: flex; align-items: center; gap: 3px; }
.overlay-sub .dot { animation: dotPulse 1.4s infinite; opacity: .3; }
.overlay-sub .dot:nth-child(2) { animation-delay: .2s; }
.overlay-sub .dot:nth-child(3) { animation-delay: .4s; }
@keyframes dotPulse { 0%,80%,100% { opacity: .3; } 40% { opacity: 1; } }

/* ── Compression status on preview items ──────────────── */
.preview-item.compressing::after {
    content: ''; position: absolute; inset: 0; background: rgba(12,63,48,0.45);
    border-radius: inherit;
}
.preview-item { position: relative; }

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

/* ── Delivery type cards ─────────────────────────────── */
.delivery-options { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 4px; }
.field-group .delivery-option { position: relative; display: block; cursor: pointer; margin: 0; font-size: 13px; text-transform: none; letter-spacing: 0; }
.delivery-option input { position: absolute; opacity: 0; pointer-events: none; }
.delivery-card { display: block; padding: 14px 16px; border: 1.5px solid var(--sand); border-radius: 12px; background: var(--bg); transition: border-color .15s, background .15s; }
.delivery-option:hover .delivery-card { border-color: var(--ink); }
.delivery-option input:checked + .delivery-card { border-color: var(--ink); background: var(--sand); }
.delivery-option input:focus-visible + .delivery-card { outline: 2px solid var(--ink); outline-offset: 2px; }
.delivery-title { display: block; font-size: 13px; font-weight: 600; color: var(--ink); text-transform: none; letter-spacing: 0; }
.delivery-desc { display: block; font-size: 11.5px; font-weight: 400; color: var(--ink); opacity: .8; margin-top: 3px; line-height: 1.5; text-transform: none; letter-spacing: 0; }

/* ── Two-step explainer (digital only) ───────────────── */
.flow-explainer { display: none; grid-template-columns: 1fr auto 1fr; gap: 12px; align-items: stretch; margin-bottom: 28px; }
.flow-card { display: flex; gap: 12px; padding: 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--sand); color: var(--ink); }
.flow-card.private { background: var(--ink); color: var(--bg); }
.flow-icon { flex-shrink: 0; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--bg); color: var(--ink); }
.flow-card.private .flow-icon { background: var(--bg); color: var(--ink); }
.flow-step { font-size: 11px; font-weight: 500; opacity: .75; }
.flow-title { font-family: 'Playfair Display', serif; font-size: 16px; margin: 1px 0 4px; }
.flow-card p { font-size: 12px; line-height: 1.55; opacity: .9; }
.flow-arrow { display: flex; align-items: center; color: var(--ink); }

/* ── Upload sections ─────────────────────────────────── */
.upload-section { margin-bottom: 28px; }
.section-head { margin-bottom: 16px; }
.step-pill { display: none; align-items: center; gap: 6px; font-size: 11px; font-weight: 500; padding: 4px 11px; border-radius: 20px; background: var(--sand); color: var(--ink); border: 1px solid var(--border); margin-bottom: 10px; }
.step-pill.private { background: var(--ink); color: var(--bg); }
.section-head h3 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--ink); margin-bottom: 4px; }
.section-head p { font-size: 12.5px; line-height: 1.6; color: var(--ink); opacity: .85; max-width: 640px; }

/* ── Protect-your-preview panel ──────────────────────── */
.protect-panel { display: none; border: 1px solid var(--border); border-radius: 12px; padding: 18px; margin-bottom: 20px; background: var(--bg); }
.protect-title { font-size: 14px; font-weight: 600; color: var(--ink); }
.protect-lead { font-size: 12px; color: var(--ink); opacity: .85; margin-top: 3px; line-height: 1.55; }
.protect-body { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 14px; }
.protect-controls .toggle-row { border-top: 1px solid var(--sand); }
.protect-sub-controls { display: none; flex-direction: column; gap: 12px; padding: 4px 0 14px; }
.protect-sub-controls .field-group { margin-bottom: 0; }
.protect-panel input[type="range"] { width: 100%; accent-color: var(--ink); }
.range-labels { display: flex; justify-content: space-between; font-size: 10.5px; color: var(--ink); opacity: .7; margin-top: 2px; }
.protect-preview-label { font-size: 11px; color: var(--ink); opacity: .8; margin-bottom: 8px; }
.protect-preview-box { position: relative; aspect-ratio: 4 / 3; border: 1px dashed var(--border); border-radius: 10px; background: var(--sand); display: flex; align-items: center; justify-content: center; overflow: hidden; }
.protect-preview-box img { width: 100%; height: 100%; object-fit: contain; display: none; background: #fff; }
.protect-preview-empty { font-size: 11.5px; padding: 16px; text-align: center; color: var(--ink); opacity: .75; line-height: 1.5; }
.protect-note { font-size: 11.5px; color: var(--ink); opacity: .8; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--sand); line-height: 1.55; }

/* ── Preview tags ────────────────────────────────────── */
.preview-tag { position: absolute; bottom: 4px; left: 4px; background: rgba(12,63,48,.88); color: var(--bg); font-size: 9.5px; padding: 2px 6px; border-radius: 4px; }
.preview-tag.warn { background: #c0392b; }

/* ── Final-file (step 2) extras ──────────────────────── */
.file-summary { display: none; align-items: flex-start; gap: 8px; margin-top: 10px; padding: 10px 14px; border-radius: 10px; background: var(--sand); border: 1px solid var(--border); font-size: 12px; line-height: 1.5; color: var(--ink); word-break: break-word; }
.use-as-preview { display: none; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 12px; padding: 12px 14px; border: 1px dashed var(--border); border-radius: 10px; font-size: 12px; line-height: 1.5; color: var(--ink); }
.use-as-preview span { flex: 1 1 260px; }
.btn-sm { padding: 8px 14px; font-size: 12px; }
.hint-text { font-size: 11px; color: var(--muted); margin-top: 6px; line-height: 1.5; }

@media (max-width: 768px) {
    .delivery-options { grid-template-columns: 1fr; }
    .flow-explainer { grid-template-columns: 1fr; }
    .flow-arrow { justify-content: center; transform: rotate(90deg); }
    .protect-body { grid-template-columns: 1fr; }
}

/* ══════════════ Mobile & touch refinements ══════════════ */
html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
:root { --top: calc(60px + env(safe-area-inset-top, 0px)); }
.topbar { padding-top: env(safe-area-inset-top, 0px); }
img, video { max-width: 100%; }
.protect-body > *, .flow-card > div, .field-group { min-width: 0; }

/* Shared component styles that used inline styles before */
.field-group .checkbox-row { display: flex; align-items: flex-start; gap: 10px; margin-top: 10px; font-size: 12.5px; font-weight: 400; line-height: 1.5; text-transform: none; letter-spacing: 0; cursor: pointer; }
.checkbox-row input { flex-shrink: 0; width: 18px; height: 18px; margin-top: 1px; accent-color: var(--ink); }

/* Keyboard focus that was missing */
.btn:focus-visible, .ham-btn:focus-visible, .drawer-close:focus-visible, .remove-preview:focus-visible, .drawer-links a:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
.toggle-switch input:focus-visible + .toggle-slider { outline: 2px solid var(--ink); outline-offset: 2px; }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition-duration: .01ms !important; } }

/* Drawer: animates properly, scrolls if it's taller than the screen, respects the notch */
#nav-drawer { display: flex; visibility: hidden; width: min(86vw, 300px); overflow-y: auto; overscroll-behavior: contain; padding: calc(20px + env(safe-area-inset-top, 0px)) 20px calc(20px + env(safe-area-inset-bottom, 0px)); transition: transform .3s ease, visibility 0s linear .3s; }
#nav-drawer.open { visibility: visible; transition: transform .3s ease, visibility 0s; }
.drawer-close { width: 44px; height: 44px; margin-right: -10px; display: flex; align-items: center; justify-content: center; }
.drawer-links a { display: flex; align-items: center; min-height: 48px; padding: 10px 0; }

/* Tablet: sidebar is still visible, so just trim the padding */
@media (max-width: 1080px) and (min-width: 769px) {
    .content { padding: 24px; }
    .card { padding: 26px; }
}

/* Phones and small tablets */
@media (max-width: 768px) {
    .topbar { padding-left: max(16px, env(safe-area-inset-left, 0px)); padding-right: max(12px, env(safe-area-inset-right, 0px)); }
    .topbar-left h1 { font-size: 18px; }
    .ham-btn { width: 44px; height: 44px; align-items: center; justify-content: center; gap: 5px; }

    /* Give the form the width it needs: the old 16px page padding + 32px card padding left ~235px of a 375px screen */
    .content { padding: 16px max(12px, env(safe-area-inset-right, 0px)) calc(32px + env(safe-area-inset-bottom, 0px)) max(12px, env(safe-area-inset-left, 0px)); }
    .card { padding: 18px 16px; border-radius: 14px; overflow: visible; }
    .section-title { margin-bottom: 14px; }

    /* Two-column rows collapse to one; drop the double spacing and the empty spacer columns */
    .form-grid { gap: 0; margin-bottom: 0; }
    #deliveryTypeGroup { margin-bottom: 22px; }
    .field-spacer { display: none; }
    .field-group { margin-bottom: 18px; }

    /* 16px+ stops iOS Safari zooming the page when a field is focused; 46px is a comfortable tap height */
    .field-input, .field-select, .field-textarea { font-size: 16px; min-height: 46px; padding: 11px 14px; }
    .field-textarea { min-height: 120px; }
    .field-group label { font-size: 11px; }
    .field-group .checkbox-row { font-size: 13px; }
    .checkbox-row input { width: 22px; height: 22px; }
    .hint-text, #sizeHint, .field-error-text { font-size: 12px; }

    .city-dropdown { max-height: 240px; }
    .city-option { padding: 13px 14px; font-size: 15px; }

    /* Toggles: larger switch, more room between the label and the control */
    .toggle-row { gap: 16px; padding: 14px 0; }
    .toggle-label { font-size: 14px; }
    .toggle-desc { font-size: 12px; line-height: 1.45; }
    .toggle-switch { width: 52px; height: 30px; }
    .toggle-slider { border-radius: 30px; }
    .toggle-slider::before { width: 24px; height: 24px; }
    .toggle-switch input:checked + .toggle-slider::before { transform: translateX(22px); }
    .protect-panel input[type="range"] { height: 32px; }
    /* Invisible extra hit area so the switch is easy to hit with a thumb */
    .toggle-switch::after { content: ''; position: absolute; inset: -9px -6px; }
    .field-group .checkbox-row { padding: 8px 0; margin-top: 4px; }

    /* Delivery cards, step explainer */
    .delivery-card { padding: 14px; }
    .delivery-title { font-size: 14px; }
    .flow-card { padding: 14px; }
    .flow-arrow { height: 18px; align-items: center; }
    .section-head h3 { font-size: 19px; }

    /* Drop zone */
    .upload-area { padding: 22px 14px; margin-bottom: 16px; }
    .upload-title { font-size: 15px; line-height: 1.4; }
    .upload-hint { font-size: 12px; line-height: 1.5; }

    /* Protect panel: the preview stays pinned at the top while the artist drags the sliders below it */
    .protect-panel { padding: 14px; }
    .protect-body { display: flex; flex-direction: column; gap: 6px; }
    .protect-preview { order: -1; position: sticky; top: calc(var(--top) + 6px); z-index: 5; background: var(--bg); padding-bottom: 8px; box-shadow: 0 8px 8px -8px rgba(12,63,48,.35); }
    .protect-preview-box { aspect-ratio: auto; height: 32vh; min-height: 140px; max-height: 260px; }

    /* Previews */
    .preview-grid { grid-template-columns: repeat(3, 1fr); gap: 10px; }
    .preview-counter { font-size: 12px; }

    /* Actions: full-width, stacked, Publish first (it comes second in the markup) */
    .form-actions { flex-direction: column-reverse; align-items: stretch; gap: 10px; margin-top: 22px; padding-top: 20px; }
    .form-actions .btn { width: 100%; }
    .btn { min-height: 48px; justify-content: center; font-size: 14px; }
    .btn-sm { min-height: 40px; }
    .use-as-preview .btn { width: 100%; }
}

@media (max-width: 480px) {
    .preview-grid { grid-template-columns: repeat(2, 1fr); }
    .card { padding: 16px 14px; }
    .flow-title { font-size: 15px; }
    .upload-overlay .overlay-title { font-size: 16px; text-align: center; padding: 0 20px; }
}

/* Touch screens (any width): fingers are bigger than cursors */
@media (pointer: coarse) {
    .remove-preview { width: 32px; height: 32px; font-size: 22px; top: 5px; right: 5px; }
    .preview-badge, .preview-tag { font-size: 10px; }
    .nav-item, .signout-btn { min-height: 44px; }
}

/* Short landscape phones: don't let the pinned preview eat the screen */
@media (max-width: 900px) and (max-height: 500px) {
    .protect-preview { position: static; box-shadow: none; }
    .protect-preview-box { height: 60vh; }
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
        <?php if ($pendingCount > 0): ?><span class="badge amber"><?= $pendingCount ?></span><?php endif; ?>
        <?php if ($pendingQCount > 0): ?><span class="badge" style="background:#c0392b;color:#fff;display:flex;align-items:center;gap:4px;"><span style="background:#fff;width:6px;height:6px;border-radius:50%;display:inline-block;"></span><?= $pendingQCount ?></span><?php endif; ?>
    </a>
    <a href="commissions.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Commission Requests
        <?php if ($unreadCommissionMsgs > 0): ?>
            <span class="badge" style="background:#c0392b;color:#fff;display:flex;align-items:center;gap:4px;"><span style="background:#fff;width:6px;height:6px;border-radius:50%;display:inline-block;"></span><?= $unreadCommissionMsgs ?></span>
        <?php elseif ($newCommCount > 0): ?>
            <span class="badge"><?= $newCommCount ?></span>
        <?php endif; ?>
    </a>
    
    <!-- ADDED ORDERS LINK -->
    <a href="orders.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        Orders
        <?php if ($unreadOrderMsgs > 0): ?>
            <span class="badge" style="background:#c0392b;color:#fff;display:flex;align-items:center;gap:4px;"><span style="background:#fff;width:6px;height:6px;border-radius:50%;display:inline-block;"></span><?= $unreadOrderMsgs ?></span>
        <?php elseif ($newOrdersCount > 0): ?>
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
        <button type="button" class="ham-btn" onclick="openDrawer()" aria-label="Open menu"><span></span><span></span><span></span></button>
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
            
            <!-- ── Delivery type (asked first, because it changes everything below) ── -->
            <div class="form-grid full" id="deliveryTypeGroup">
                <div class="field-group" style="margin-bottom:0;">
                    <label>How will buyers receive this? <span>*</span></label>
                    <div class="delivery-options">
                        <label class="delivery-option">
                            <input type="radio" name="delivery_type" value="physical" checked>
                            <span class="delivery-card">
                                <span class="delivery-title">Physical artwork</span>
                                <span class="delivery-desc">A painting, print or craft piece that you ship to the buyer.</span>
                            </span>
                        </label>
                        <label class="delivery-option">
                            <input type="radio" name="delivery_type" value="digital">
                            <span class="delivery-card">
                                <span class="delivery-title">Digital artwork</span>
                                <span class="delivery-desc">A file the buyer downloads after they pay. You'll add a public preview and the final file.</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- ── How digital uploads work (digital only) ── -->
            <div class="flow-explainer" id="flowExplainer">
                <div class="flow-card">
                    <div class="flow-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </div>
                    <div>
                        <div class="flow-step">Step 1 of 2</div>
                        <div class="flow-title">Public preview</div>
                        <p>Shown on your listing to everyone. Add a watermark or a light blur so it can't simply be saved.</p>
                    </div>
                </div>
                <div class="flow-arrow" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </div>
                <div class="flow-card private">
                    <div class="flow-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
                    </div>
                    <div>
                        <div class="flow-step">Step 2 of 2</div>
                        <div class="flow-title">Final file</div>
                        <p>Private. Only the buyer receives it, after payment is confirmed. Upload your best-quality file, with no watermark.</p>
                    </div>
                </div>
            </div>

            <!-- ── Step 1: Images / public preview ─────────────────────────────── -->
            <div class="upload-section" id="previewSection">
                <div class="section-head">
                    <span class="step-pill" id="step1Pill">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        Step 1 of 2 · Visible to everyone
                    </span>
                    <h3 id="previewSectionTitle">Artwork images</h3>
                    <p id="previewSectionDesc">Add clear photos of your artwork. The first one becomes the cover.</p>
                </div>

                <div class="upload-area" id="dropZone">
                    <svg class="upload-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <div class="upload-title" id="uploadTitle">Click to upload images</div>
                    <div class="upload-hint" id="uploadHint">
                        Up to 5 images · Max 10MB each · First image will be the cover.
                        <?php if (!$heicSupported): ?>
                            <br><strong>Note:</strong> iPhone HEIC photos aren't supported right now — please upload JPG or PNG.
                        <?php endif; ?>
                    </div>

                    <div id="physicalGuidelines" style="margin-top:14px;text-align:left;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px 14px;">
                        <div style="font-size:10px;letter-spacing:1px;text-transform:uppercase;font-weight:600;color:var(--ink);margin-bottom:8px;">Image Guidelines</div>
                        <ul style="list-style:none;display:flex;flex-direction:column;gap:5px;">
                            <li style="font-size:11px;color:var(--ink);">✓ Clear, well-lit photo of the actual artwork</li>
                            <li style="font-size:11px;color:var(--ink);">✗ No watermarks covering the artwork</li>
                            <li style="font-size:11px;color:var(--ink);">✗ Do not upload stolen or unoriginal work</li>
                            <li style="font-size:11px;color:var(--ink);">✗ No AI-generated artwork</li>
                        </ul>
                        <div style="font-size:10px;color:var(--ink);opacity:0.6;margin-top:8px;border-top:1px solid var(--border);padding-top:8px;">Violations may result in artwork rejection or account suspension.</div>
                    </div>

                    <div id="digitalGuidelines" style="display:none;margin-top:14px;text-align:left;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px 14px;">
                        <div style="font-size:10px;letter-spacing:1px;text-transform:uppercase;font-weight:600;color:var(--ink);margin-bottom:8px;">Preview Guidelines</div>
                        <ul style="list-style:none;display:flex;flex-direction:column;gap:5px;">
                            <li style="font-size:11px;color:var(--ink);">✓ A clear preview that shows buyers what they're getting</li>
                            <li style="font-size:11px;color:var(--ink);">✓ Watermarks and a light blur are welcome on previews</li>
                            <li style="font-size:11px;color:var(--ink);">✗ Do not upload stolen or unoriginal work</li>
                            <li style="font-size:11px;color:var(--ink);">✗ No AI-generated artwork</li>
                        </ul>
                        <div style="font-size:10px;color:var(--ink);opacity:0.6;margin-top:8px;border-top:1px solid var(--border);padding-top:8px;">Violations may result in artwork rejection or account suspension.</div>
                    </div>
                    <input type="file" name="images[]" id="fileInput" accept="image/jpeg,image/png,image/webp<?= $heicSupported ? ',image/heic,image/heif' : '' ?>" multiple hidden>
                </div>

                <!-- Protection tools (digital only) -->
                <div class="protect-panel" id="protectPanel">
                    <div class="protect-title">Protect your preview</div>
                    <p class="protect-lead">Optional. These effects are applied to the public preview only. The final file you upload in step 2 is delivered untouched.</p>
                    <div class="protect-body">
                        <div class="protect-controls">
                            <div class="toggle-row">
                                <div>
                                    <div class="toggle-label">Watermark</div>
                                    <span class="toggle-desc">Write your name across the preview</span>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="wmEnabled">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <div class="protect-sub-controls" id="wmControls">
                                <div class="field-group">
                                    <label for="wmText">Watermark text</label>
                                    <input type="text" id="wmText" class="field-input" maxlength="40" autocomplete="off">
                                </div>
                                <div class="field-group">
                                    <label for="wmStyle">Placement</label>
                                    <select id="wmStyle" class="field-select">
                                        <option value="tiled">Repeated (hardest to crop out)</option>
                                        <option value="center">Large, in the center</option>
                                        <option value="corner">Small, in a corner</option>
                                    </select>
                                </div>
                                <div class="field-group">
                                    <label for="wmOpacity">Visibility <span id="wmOpacityVal" style="text-transform:none;letter-spacing:0;font-weight:400;">35%</span></label>
                                    <input type="range" id="wmOpacity" min="10" max="80" value="35">
                                    <div class="range-labels"><span>Subtle</span><span>Bold</span></div>
                                </div>
                            </div>

                            <div class="toggle-row">
                                <div>
                                    <div class="toggle-label">Blur</div>
                                    <span class="toggle-desc">Soften the preview so it isn't print quality</span>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="blurEnabled">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <div class="protect-sub-controls" id="blurControls">
                                <div class="field-group">
                                    <label for="blurLevel">Blur amount</label>
                                    <input type="range" id="blurLevel" min="1" max="10" value="3">
                                    <div class="range-labels"><span>Slight</span><span>Strong</span></div>
                                </div>
                            </div>
                        </div>

                        <div class="protect-preview">
                            <div class="protect-preview-label">What buyers will see</div>
                            <div class="protect-preview-box">
                                <img id="protectPreviewImg" alt="Preview of your protected image">
                                <div class="protect-preview-empty" id="protectPreviewEmpty">Add a preview image above to see the effect here.</div>
                            </div>
                        </div>
                    </div>
                    <p class="protect-note">Watermark and blur work on JPG, PNG and WebP images. GIFs, videos and audio are uploaded as they are, so for those, upload a short, lower-quality clip rather than the full piece.</p>
                </div>

                <div class="preview-header">
                    <div></div>
                    <div class="preview-counter" id="previewCounter">0 / 5 files selected</div>
                </div>
                <div class="preview-grid" id="previewGrid"></div>
                <p class="field-error-text" id="previewError"></p>
            </div>

            <!-- ── Step 2: Final file (digital only) ────────────────────────────── -->
            <div class="upload-section" id="digitalFileGroup" style="display:none;">
                <div class="section-head">
                    <span class="step-pill private" id="step2Pill" style="display:inline-flex;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
                        Step 2 of 2 · Private, buyer only
                    </span>
                    <h3>Final file for the buyer</h3>
                    <p>Upload your best-quality original, with no watermark and no blur. The buyer receives exactly this file once payment is confirmed. It is never shown publicly.</p>
                </div>

                <div class="field-group">
                    <label for="digitalFileInput">Digital artwork file <span>*</span></label>
                    <input type="file" name="digital_file" id="digitalFileInput" class="field-input" accept=".zip,.psd,.ai,.png,.jpg,.jpeg,.pdf,.gif,.mp4,.mov,.webm,.mp3,.wav,.m4a">
                    <div class="file-summary" id="digitalFileSummary"></div>
                    <div class="use-as-preview" id="useAsPreviewBox">
                        <span>This file is an image, so you can use it for the public preview too. We'll resize it and apply the watermark or blur you chose above.</span>
                        <button type="button" class="btn btn-ghost btn-sm" id="useAsPreviewBtn">Use it as a preview</button>
                    </div>
                    <p class="hint-text">Max 300MB. Allowed: ZIP, PSD, AI, PNG, JPG, PDF, GIF, MP4, MOV, WebM, MP3, WAV, M4A.</p>
                </div>

                <div class="field-group">
                    <label for="licenseInput">Licensing <span>*</span></label>
                    <select name="license_type" id="licenseInput" class="field-input">
                        <option value="">Select a license...</option>
                        <option value="Personal Use">Personal Use — buyer can use it for themselves, not resell or use commercially</option>
                        <option value="Commercial Use">Commercial Use — buyer can use it for business/commercial purposes</option>
                        <option value="Extended/Exclusive">Extended / Exclusive — buyer gets full or exclusive rights</option>
                    </select>
                    <p class="hint-text">Tell buyers what they're allowed to do with the file after purchase. Shown on the listing.</p>
                </div>
            </div>

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
                    <input type="text" name="medium" id="mediumInput" class="field-input" placeholder="e.g. Oil on Canvas" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>Size <span>*</span></label>
                    <input type="text" name="size" id="sizeInput" class="field-input" placeholder="e.g. 24 x 36 inches" required>
                    <p style="font-size:11px;color:var(--muted);margin-top:6px;" id="sizeHint">Include the unit (e.g. inches, cm, ft).</p>
                    <p class="field-error-text" id="sizeError">Please include a unit with the size (e.g. "24 x 36 inches", "1920 x 1080 px", "15 MB").</p>
                </div>
                <div class="field-group">
                    <label>Price (PKR) <span id="priceRequiredMark">*</span></label>
                    <input type="number" name="price" id="priceInput" class="field-input" placeholder="e.g. 25000" min="1" required>
                    <label class="checkbox-row">
                        <input type="checkbox" name="is_showcase_only" id="showcaseCheckbox" value="1">
                        This piece was already sold elsewhere — show as portfolio only (no price, not purchasable)
                    </label>
                </div>
            </div>

            <div class="form-grid" id="weightGroupRow">
                <div class="field-group" id="weightGroup">
                    <label>Weight (kg) <span>*</span></label>
                    <input type="number" name="weight_kg" id="weightInput" class="field-input" placeholder="e.g. 1.5" min="0.1" step="0.1" value="1" required>
                    <p style="font-size:11px;color:var(--muted);margin-top:6px;">Used to calculate shipping fee. Add +100 PKR per kg above 1 kg.</p>
                </div>
                <div class="field-group field-spacer">
                    <!-- Spacer -->
                </div>
            </div>

            <div class="form-grid">
                <div class="field-group">
                    <label>City <span>*</span></label>
                    <div class="city-search-wrap">
                        <input type="text" class="field-input city-search-input" id="citySearchInput" placeholder="Search or type your city..." autocomplete="off">
                        <input type="hidden" name="city" id="cityHidden" required>
                        <div class="city-dropdown" id="cityDropdown"></div>
                    </div>
                </div>
                <div class="field-group field-spacer">
                    <!-- Spacer for balance -->
                </div>
            </div>

            <div class="form-grid full">
                <div class="field-group">
                    <label>Description</label>
                    <textarea name="description" id="descriptionInput" class="field-textarea" placeholder="Tell the story behind this artwork, techniques used, inspiration..."></textarea>
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
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    Publish Artwork
                </button>
            </div>

        </div>
    </form>

</div>
</main>

<!-- ══════════════ UPLOAD OVERLAY ══════════════ -->
<div class="upload-overlay" id="uploadOverlay">
    <div class="spinner"></div>
    <div class="overlay-title" id="overlayTitle">Uploading artwork</div>
    <div class="overlay-sub">Please wait<span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></div>
</div>

<!-- NAV DRAWER (Mobile) -->
<div id="nav-overlay" onclick="closeDrawer()"></div>
<div id="nav-drawer">
    <div class="drawer-top">
        <div class="drawer-logo">Art Bazaar</div>
        <button type="button" class="drawer-close" onclick="closeDrawer()" aria-label="Close menu">&times;</button>
    </div>
    <div class="drawer-links">
        <a href="../../index.php">Home</a>
        <a href="index.php">Dashboard</a>
        <a href="upload-artwork.php">Upload Artwork</a>
        <a href="my-artworks.php">My Artworks
            <?php if ($pendingCount > 0): ?><span style="background:var(--sand);color:var(--ink);font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $pendingCount ?></span><?php endif; ?>
            <?php if ($pendingQCount > 0): ?><span style="background:#c0392b;color:#fff;font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:4px;"><?= $pendingQCount ?></span><?php endif; ?>
        </a>
        <a href="commissions.php">Commissions
            <?php if ($unreadCommissionMsgs > 0): ?><span style="background:#c0392b;color:#fff;font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $unreadCommissionMsgs ?></span><?php endif; ?>
            <?php if ($unreadCommissionMsgs == 0 && $newCommCount > 0): ?><span style="background:var(--sand);color:var(--ink);font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $newCommCount ?></span><?php endif; ?>
        </a>
        <a href="orders.php">Orders
            <?php if ($unreadOrderMsgs > 0): ?><span style="background:#c0392b;color:#fff;font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $unreadOrderMsgs ?></span><?php endif; ?>
            <?php if ($unreadOrderMsgs == 0 && $newOrdersCount > 0): ?><span style="background:var(--sand);color:var(--ink);font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px;"><?= $newOrdersCount ?></span><?php endif; ?>
        </a>
        <a href="profile.php">Profile</a>
    </div>
    <div class="drawer-actions">
        <a href="../../cart.php">Cart</a>
        <a href="../../logout.php">Logout</a>
    </div>
</div>

<script>
const PK_CITIES = <?= json_encode($pakistaniCities) ?>;

function initCitySearch(searchId, hiddenId, dropdownId) {
    const searchInput = document.getElementById(searchId);
    const hiddenInput = document.getElementById(hiddenId);
    const dropdown = document.getElementById(dropdownId);
    if (!searchInput || !hiddenInput || !dropdown) return;
    let activeIndex = -1;

    function renderOptions(filter) {
        const f = filter.trim().toLowerCase();
        const matches = f ? PK_CITIES.filter(c => c.toLowerCase().includes(f)) : PK_CITIES;
        dropdown.innerHTML = '';
        if (matches.length === 0) {
            dropdown.innerHTML = '<div class="city-no-results">No match — your typed city will be used as entered</div>';
        } else {
            matches.slice(0, 50).forEach((city) => {
                const opt = document.createElement('div');
                opt.className = 'city-option';
                opt.textContent = city;
                opt.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    selectCity(city);
                });
                dropdown.appendChild(opt);
            });
        }
        activeIndex = -1;
    }

    function selectCity(city) {
        searchInput.value = city;
        hiddenInput.value = city;
        hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
        dropdown.classList.remove('open');
    }

    searchInput.addEventListener('input', () => {
        hiddenInput.value = searchInput.value;
        hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
        renderOptions(searchInput.value);
        dropdown.classList.add('open');
    });
    searchInput.addEventListener('focus', () => {
        renderOptions(searchInput.value);
        dropdown.classList.add('open');
    });
    searchInput.addEventListener('blur', () => {
        setTimeout(() => dropdown.classList.remove('open'), 100);
    });
    searchInput.addEventListener('keydown', (e) => {
        const options = dropdown.querySelectorAll('.city-option');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, options.length - 1);
            options.forEach((o, i) => o.classList.toggle('active', i === activeIndex));
            if (options[activeIndex]) options[activeIndex].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            options.forEach((o, i) => o.classList.toggle('active', i === activeIndex));
            if (options[activeIndex]) options[activeIndex].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (options[activeIndex]) selectCity(options[activeIndex].textContent);
        } else if (e.key === 'Escape') {
            dropdown.classList.remove('open');
        }
    });
}

initCitySearch('citySearchInput', 'cityHidden', 'cityDropdown');

function openDrawer() {
    document.getElementById('nav-drawer').classList.add('open');
    document.getElementById('nav-overlay').classList.add('open');
    document.body.style.overflow = 'hidden'; // stop the page scrolling behind the menu
}
function closeDrawer() {
    document.getElementById('nav-drawer').classList.remove('open');
    document.getElementById('nav-overlay').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });

// ── Elements ─────────────────────────────────────────────────────────────
const ARTIST_NAME = <?= json_encode($artistName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const previewGrid = document.getElementById('previewGrid');
const previewCounter = document.getElementById('previewCounter');
const previewError = document.getElementById('previewError');
const previewSection = document.getElementById('previewSection');
const previewSectionTitle = document.getElementById('previewSectionTitle');
const previewSectionDesc = document.getElementById('previewSectionDesc');
const step1Pill = document.getElementById('step1Pill');
const flowExplainer = document.getElementById('flowExplainer');
const physicalGuidelines = document.getElementById('physicalGuidelines');
const digitalGuidelines = document.getElementById('digitalGuidelines');
const uploadForm = document.getElementById('uploadForm');

const categorySelect = document.getElementById('categorySelect');
const digitalFileGroup = document.getElementById('digitalFileGroup');
const digitalFileInput = document.getElementById('digitalFileInput');
const digitalFileSummary = document.getElementById('digitalFileSummary');
const useAsPreviewBox = document.getElementById('useAsPreviewBox');
const useAsPreviewBtn = document.getElementById('useAsPreviewBtn');
const licenseInput = document.getElementById('licenseInput');
const deliveryTypeRadios = document.querySelectorAll('input[name="delivery_type"]');
const weightGroup = document.getElementById('weightGroup');
const weightInput = document.getElementById('weightInput');
const sizeInput = document.getElementById('sizeInput');
const sizeHint = document.getElementById('sizeHint');
const sizeError = document.getElementById('sizeError');
const mediumInput = document.getElementById('mediumInput');
const descriptionInput = document.getElementById('descriptionInput');

const protectPanel = document.getElementById('protectPanel');
const wmEnabled = document.getElementById('wmEnabled');
const wmControls = document.getElementById('wmControls');
const wmText = document.getElementById('wmText');
const wmStyle = document.getElementById('wmStyle');
const wmOpacity = document.getElementById('wmOpacity');
const wmOpacityVal = document.getElementById('wmOpacityVal');
const blurEnabled = document.getElementById('blurEnabled');
const blurControls = document.getElementById('blurControls');
const blurLevel = document.getElementById('blurLevel');
const protectPreviewImg = document.getElementById('protectPreviewImg');
const protectPreviewEmpty = document.getElementById('protectPreviewEmpty');

// Limits (mirror the server-side checks so artists hear about problems
// before they submit, instead of after a failed upload).
const MAX_FILES = 5;
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
const MAX_MEDIA_BYTES = 60 * 1024 * 1024;
const MAX_DIGITAL_BYTES = 300 * 1024 * 1024;

function formatBytes(bytes) {
    if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

// ── State ────────────────────────────────────────────────────────────────
// Every preview is an "item": `orig` is the file the artist picked, `out` is
// the file that will actually be uploaded. They're the same unless the image
// was compressed or protected (watermark/blur). Keeping the original around
// means changing a slider re-renders from the untouched source every time,
// instead of stacking effects on top of an already-processed copy.
let items = [];
let isSubmitting = false;
const pendingJobs = new Set();
let previewUrls = [];
let protectPreviewUrl = null;
let lastDeliveryWasDigital = null;

// Keeps the real <input type="file"> in sync with our items array,
// since previews are rendered from items but the browser only
// submits whatever is sitting in fileInput.files.
function syncFileInput() {
    const dt = new DataTransfer();
    items.forEach(item => dt.items.add(item.out));
    fileInput.files = dt.files;
}

dropZone.addEventListener('click', () => fileInput.click());

// ── Size unit validation ──────────────────────────────
// The "Size" field is free text, so nothing stops an artist from typing
// just "24 x 36" with no unit. That's ambiguous (inches? cm?) for physical
// pieces, and meaningless for digital ones (px vs MB are very different).
// Require a recognizable unit token before letting the form submit.
const PHYSICAL_SIZE_UNITS = /\b(in|inch|inches|"|cm|centimeters?|mm|millimeters?|ft|feet|'|m|meters?)\b/i;
const DIGITAL_SIZE_UNITS  = /\b(px|pixels?|kb|mb|gb)\b/i;

function validateSizeField() {
    const value = sizeInput.value.trim();
    const isDigital = isDigitalSelected();
    const unitPattern = isDigital ? DIGITAL_SIZE_UNITS : PHYSICAL_SIZE_UNITS;

    // Empty is handled by the native "required" attribute — don't also
    // flag it here, or the user sees two errors at once.
    const missingUnit = value !== '' && !unitPattern.test(value);

    sizeInput.classList.toggle('field-invalid', missingUnit);
    sizeError.classList.toggle('show', missingUnit);
    return !missingUnit;
}

sizeInput.addEventListener('blur', validateSizeField);
sizeInput.addEventListener('input', function () {
    // Once an error is showing, clear it live as soon as it's fixed
    // rather than making the user click away again.
    if (sizeInput.classList.contains('field-invalid')) validateSizeField();
});

// Every artwork asks the artist directly, regardless of category — the
// radio choice is the single source of truth for physical vs. digital.
function isDigitalSelected() {
    const checked = document.querySelector('input[name="delivery_type"]:checked');
    return checked ? checked.value === 'digital' : false;
}

// ── Category list depends on delivery type ────────────────────────────
// "Digital Art" only makes sense when buyers receive a digital download,
// and the physical craft categories (Calligraphy, Pottery, Wood Work, etc.)
// only make sense when something is actually being shipped. So the two
// sets are mutually exclusive in the dropdown — flip the toggle and the
// list of choices flips with it.
function isDigitalCategoryOption(option) {
    const slug = (option.dataset.slug || '').toLowerCase();
    const name = option.textContent.trim().toLowerCase();
    return slug.includes('digital') || name.includes('digital art') || name.includes('digital');
}

// These categories work for either delivery type (e.g. a photograph or an
// illustration can be sold as a physical print OR a digital download), so
// they stay visible no matter which way the toggle is set.
const DELIVERY_NEUTRAL_CATEGORIES = [
    'illustration',
    'mixed media',
    'other forms of art',
    'photography',
    'stickers'
];

function isDeliveryNeutralOption(option) {
    const slug = (option.dataset.slug || '').toLowerCase().replace(/-/g, ' ');
    const name = option.textContent.trim().toLowerCase();
    return DELIVERY_NEUTRAL_CATEGORIES.some(entry => slug.includes(entry) || name.includes(entry));
}

function filterCategoriesByDeliveryType() {
    const isDigital = isDigitalSelected();
    let selectedStillValid = false;

    Array.from(categorySelect.options).forEach(option => {
        if (!option.value) return; // leave the "Select category" placeholder alone

        const showThisOption = isDeliveryNeutralOption(option)
            || (isDigital ? isDigitalCategoryOption(option) : !isDigitalCategoryOption(option));
        option.hidden = !showThisOption;
        option.disabled = !showThisOption;

        if (showThisOption && option.selected) selectedStillValid = true;
    });

    // If the previously chosen category just got hidden (toggle flipped),
    // reset back to the placeholder so an artist can't submit a category
    // that no longer matches their delivery type.
    if (!selectedStillValid) {
        categorySelect.value = '';
    }
}

// ── Client-side image processing ─────────────────────────────────────────
// Every still image is downscaled + re-encoded in the browser before it hits
// the network (a 10MB phone photo becomes ~1-2MB). For digital listings the
// same pass can also blur the image and/or stamp a watermark on it, so the
// public preview is never the deliverable.
const COMPRESS_MAX_DIMENSION = 1600;
const COMPRESS_QUALITY = 0.82;
const PROTECTED_QUALITY = 0.86;
const COMPRESS_SKIP_UNDER_BYTES = 400 * 1024; // don't bother re-encoding already-small files

function fileKind(file) {
    // HEIC/HEIF can't be decoded by canvas in most browsers (the server converts it),
    // and GIF would lose its animation — neither can be blurred or watermarked here.
    if (/\.(heic|heif)$/i.test(file.name)) return 'heic';
    if (file.type === 'image/gif') return 'gif';
    if (file.type.startsWith('video/')) return 'video';
    if (file.type.startsWith('audio/')) return 'audio';
    if (file.type === 'image/jpeg' || file.type === 'image/png' || file.type === 'image/webp') return 'raster';
    return 'other';
}

function shouldCompress(file) {
    if (fileKind(file) !== 'raster') return false;
    return file.size > COMPRESS_SKIP_UNDER_BYTES;
}

async function compressImage(file) {
    try {
        const bitmap = await createImageBitmap(file);
        let { width, height } = bitmap;

        if (width > COMPRESS_MAX_DIMENSION || height > COMPRESS_MAX_DIMENSION) {
            const scale = COMPRESS_MAX_DIMENSION / Math.max(width, height);
            width = Math.round(width * scale);
            height = Math.round(height * scale);
        }

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        // Flatten transparency onto white so PNGs re-encoded as JPEG don't turn black
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, width, height);
        ctx.drawImage(bitmap, 0, 0, width, height);
        bitmap.close?.();

        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', COMPRESS_QUALITY));
        if (!blob || blob.size >= file.size) {
            // Compression didn't actually help (rare, e.g. already-compressed small image) — keep original
            return file;
        }

        const newName = file.name.replace(/\.[^.]+$/, '') + '.jpg';
        return new File([blob], newName, { type: 'image/jpeg', lastModified: Date.now() });
    } catch (err) {
        console.warn('Image compression failed, using original file:', file.name, err);
        return file; // fall back to the original file — server-side checks still apply
    }
}

// Reads the protection controls. Returns null when nothing is switched on
// (or the listing isn't digital), which means "just compress as usual".
function getProtection() {
    if (!isDigitalSelected()) return null;
    const text = wmText.value.trim();
    const wm = wmEnabled.checked && text !== '';
    const blur = blurEnabled.checked;
    if (!wm && !blur) return null;
    return {
        wm,
        wmText: text,
        wmStyle: wmStyle.value,
        wmOpacity: Number(wmOpacity.value) / 100,
        blur,
        blurLevel: Number(blurLevel.value)
    };
}

let canvasFilterSupported;
function supportsCanvasFilter() {
    if (canvasFilterSupported === undefined) {
        const c = document.createElement('canvas').getContext('2d');
        canvasFilterSupported = !!c && typeof c.filter === 'string';
    }
    return canvasFilterSupported;
}

// Blur strength is relative to the image size, so "slight" looks the same
// on a 900px image as on a 4000px one.
function drawBlurred(ctx, bitmap, w, h, level) {
    const blurPx = level * 0.8 * (Math.max(w, h) / COMPRESS_MAX_DIMENSION);
    if (supportsCanvasFilter()) {
        // Draw slightly oversized so the blur doesn't fade to transparent at the edges.
        const pad = Math.ceil(blurPx * 2);
        ctx.filter = 'blur(' + blurPx + 'px)';
        ctx.drawImage(bitmap, -pad, -pad, w + pad * 2, h + pad * 2);
        ctx.filter = 'none';
    } else {
        // Safari has no canvas filter: shrink then stretch back up for a similar soft look.
        const factor = 1 / (1 + blurPx * 1.5);
        const tw = Math.max(1, Math.round(w * factor));
        const th = Math.max(1, Math.round(h * factor));
        const tmp = document.createElement('canvas');
        tmp.width = tw;
        tmp.height = th;
        const tctx = tmp.getContext('2d');
        tctx.imageSmoothingQuality = 'high';
        tctx.drawImage(bitmap, 0, 0, tw, th);
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(tmp, 0, 0, w, h);
    }
}

function drawWatermark(ctx, w, h, opts) {
    const text = opts.wmText;
    const longSide = Math.max(w, h);

    const stamp = (x, y, size) => {
        ctx.font = '600 ' + size + 'px "DM Sans", Arial, sans-serif';
        ctx.lineWidth = Math.max(1, size / 14);
        ctx.lineJoin = 'round';
        ctx.strokeStyle = 'rgba(0,0,0,0.55)'; // dark edge keeps the white text readable on light art
        ctx.fillStyle = '#ffffff';
        ctx.strokeText(text, x, y);
        ctx.fillText(text, x, y);
    };

    ctx.save();
    ctx.globalAlpha = opts.wmOpacity;
    ctx.textBaseline = 'middle';

    if (opts.wmStyle === 'tiled') {
        const size = Math.max(14, Math.round(longSide / 30));
        ctx.font = '600 ' + size + 'px "DM Sans", Arial, sans-serif';
        const stepX = ctx.measureText(text).width + size * 3;
        const stepY = size * 4;
        const diag = Math.sqrt(w * w + h * h);
        ctx.textAlign = 'center';
        ctx.translate(w / 2, h / 2);
        ctx.rotate(-Math.PI / 6);
        let row = 0;
        for (let y = -diag / 2; y <= diag / 2; y += stepY, row++) {
            const offset = (row % 2) ? stepX / 2 : 0;
            for (let x = -diag / 2 - offset; x <= diag / 2; x += stepX) {
                stamp(x, y, size);
            }
        }
    } else if (opts.wmStyle === 'center') {
        let size = Math.round(longSide / 11);
        ctx.font = '600 ' + size + 'px "DM Sans", Arial, sans-serif';
        const textWidth = ctx.measureText(text).width;
        const maxWidth = w * 0.85;
        if (textWidth > maxWidth) size = Math.max(12, Math.floor(size * maxWidth / textWidth));
        ctx.textAlign = 'center';
        ctx.translate(w / 2, h / 2);
        ctx.rotate(-Math.PI / 9);
        stamp(0, 0, size);
    } else {
        const size = Math.max(14, Math.round(longSide / 32));
        ctx.textAlign = 'right';
        stamp(w - size, h - size, size);
    }
    ctx.restore();
}

async function getBitmap(item) {
    if (!item.bitmap) item.bitmap = await createImageBitmap(item.orig);
    return item.bitmap;
}

async function renderProtectedPreview(item, protection) {
    const bitmap = await getBitmap(item);
    let w = bitmap.width;
    let h = bitmap.height;
    if (Math.max(w, h) > COMPRESS_MAX_DIMENSION) {
        const scale = COMPRESS_MAX_DIMENSION / Math.max(w, h);
        w = Math.round(w * scale);
        h = Math.round(h * scale);
    }

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, w, h);

    if (protection.blur) {
        drawBlurred(ctx, bitmap, w, h, protection.blurLevel);
    } else {
        ctx.drawImage(bitmap, 0, 0, w, h);
    }
    if (protection.wm) drawWatermark(ctx, w, h, protection);

    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', PROTECTED_QUALITY));
    if (!blob) throw new Error('Could not encode the protected preview');
    const newName = item.orig.name.replace(/\.[^.]+$/, '') + '.jpg';
    return new File([blob], newName, { type: 'image/jpeg', lastModified: Date.now() });
}

async function buildOutput(item) {
    const protection = getProtection();
    if (protection && item.kind === 'raster') {
        return { file: await renderProtectedPreview(item, protection), protectedApplied: true };
    }
    if (shouldCompress(item.orig)) {
        return { file: await compressImage(item.orig), protectedApplied: false };
    }
    return { file: item.orig, protectedApplied: false };
}

function needsProcessing(item) {
    return (getProtection() && item.kind === 'raster') || shouldCompress(item.orig);
}

function processItem(item) {
    const version = ++item.version;

    if (!needsProcessing(item)) {
        // Nothing to do — make sure any earlier processed copy is dropped.
        item.out = item.orig;
        item.protectedApplied = false;
        item.failed = false;
        item.processing = false;
        return Promise.resolve();
    }

    item.processing = true;
    const job = (async () => {
        let result;
        try {
            result = await buildOutput(item);
        } catch (err) {
            console.warn('Preview processing failed, using original file:', item.orig.name, err);
            result = { file: item.orig, protectedApplied: false, failed: true };
        }
        // Superseded by a newer run, or the artist removed this file meanwhile.
        if (item.version !== version || !items.includes(item)) return;
        item.out = result.file;
        item.protectedApplied = result.protectedApplied;
        item.failed = !!result.failed;
        item.processing = false;
        syncFileInput();
        renderPreviews();
    })();

    pendingJobs.add(job);
    job.then(() => pendingJobs.delete(job));
    return job;
}

let reprocessTimer = null;
let reprocessPending = false;

function reprocessAll() {
    clearTimeout(reprocessTimer);
    reprocessPending = false;
    items.forEach(processItem);
    syncFileInput();
    renderPreviews();
}

// Sliders fire many events per second — wait until the artist pauses.
function scheduleReprocess() {
    clearTimeout(reprocessTimer);
    reprocessPending = true;
    reprocessTimer = setTimeout(reprocessAll, 250);
}

// ── Adding / removing previews ───────────────────────────────────────────
function addFiles(newFiles, extra) {
    if (items.length + newFiles.length > MAX_FILES) {
        alert('You can only upload up to ' + MAX_FILES + ' files. Please remove some before adding more.');
        return false;
    }

    const isDigital = isDigitalSelected();
    const accepted = newFiles.filter(file => {
        const isImage = file.type.startsWith('image/') || file.name.match(/\.(heic|heif)$/i);
        const isMedia = isDigital && (file.type.startsWith('video/') || file.type.startsWith('audio/'));
        return isImage || isMedia;
    });
    if (accepted.length === 0) return false;

    const added = accepted.map(file => Object.assign({
        orig: file,
        out: file,
        kind: fileKind(file),
        version: 0,
        processing: false,
        protectedApplied: false,
        failed: false,
        bitmap: null
    }, extra || {}));

    items.push(...added);
    syncFileInput();
    renderPreviews();
    added.forEach(processItem);
    renderPreviews();
    return true;
}

fileInput.addEventListener('change', function (e) {
    const newFiles = Array.from(e.target.files);
    fileInput.value = '';
    if (newFiles.length === 0) { syncFileInput(); return; }
    if (!addFiles(newFiles)) syncFileInput();
});

function removeItem(item) {
    const index = items.indexOf(item);
    if (index === -1) return;
    items.splice(index, 1);
    item.version++;
    item.bitmap?.close?.();
    syncFileInput();
    renderPreviews();
}

function renderPreviews() {
    previewUrls.forEach(url => URL.revokeObjectURL(url));
    previewUrls = [];
    previewGrid.innerHTML = '';

    const protection = getProtection();

    items.forEach((item, index) => {
        const file = item.out;
        const objectUrl = URL.createObjectURL(file);
        previewUrls.push(objectUrl);

        const div = document.createElement('div');
        div.className = 'preview-item' + (item.processing ? ' compressing' : '');
        div.setAttribute('data-index', index);

        let media;
        if (file.type.startsWith('video/')) {
            media = document.createElement('video');
            media.src = objectUrl;
            media.muted = true;
            media.playsInline = true;
            media.style.cssText = 'width:100%;height:100%;object-fit:cover;';
        } else if (file.type.startsWith('audio/')) {
            media = document.createElement('div');
            media.style.cssText = 'display:flex;align-items:center;justify-content:center;height:100%;font-size:11px;text-align:center;padding:8px;word-break:break-word;';
            media.textContent = '🎵 ' + file.name;
        } else {
            media = document.createElement('img');
            media.src = objectUrl;
            media.alt = 'Preview ' + (index + 1);
        }
        div.appendChild(media);

        if (index === 0) {
            const badge = document.createElement('span');
            badge.className = 'preview-badge';
            badge.textContent = 'Cover';
            div.appendChild(badge);
        }

        // Tell the artist which previews actually got the effects.
        if (protection && !item.processing) {
            const tag = document.createElement('span');
            tag.className = 'preview-tag' + (item.protectedApplied ? '' : ' warn');
            tag.textContent = item.protectedApplied ? 'Protected' : 'Not protected';
            div.appendChild(tag);
        }

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'remove-preview';
        removeBtn.title = 'Remove file';
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            removeItem(item);
        });
        div.appendChild(removeBtn);

        previewGrid.appendChild(div);
    });

    previewCounter.textContent = items.length + ' / ' + MAX_FILES + ' files selected';
    if (items.length > 0) previewError.classList.remove('show');
    updateProtectPreview();
    updateUseAsPreviewBox();
}

// Larger "what buyers will see" view, so the artist can judge the effect
// while moving the sliders (the thumbnails are too small to show a watermark).
function updateProtectPreview() {
    if (protectPreviewUrl) {
        URL.revokeObjectURL(protectPreviewUrl);
        protectPreviewUrl = null;
    }
    const shown = items.find(item => item.kind === 'raster' || item.kind === 'gif');
    if (shown) {
        protectPreviewUrl = URL.createObjectURL(shown.out);
        protectPreviewImg.src = protectPreviewUrl;
        protectPreviewImg.style.display = 'block';
        protectPreviewEmpty.style.display = 'none';
    } else {
        protectPreviewImg.removeAttribute('src');
        protectPreviewImg.style.display = 'none';
        protectPreviewEmpty.style.display = 'block';
    }
}

// ── Step 2: final file ───────────────────────────────────────────────────
function updateUseAsPreviewBox() {
    const file = digitalFileInput.files[0];
    const ext = file ? file.name.split('.').pop().toLowerCase() : '';
    const canOffer = isDigitalSelected()
        && file
        && ['png', 'jpg', 'jpeg'].includes(ext)
        && items.length < MAX_FILES
        && !items.some(item => item.fromFinal === file);
    useAsPreviewBox.style.display = canOffer ? 'flex' : 'none';
}

digitalFileInput.addEventListener('change', function () {
    const file = digitalFileInput.files[0];
    if (!file) {
        digitalFileSummary.style.display = 'none';
        updateUseAsPreviewBox();
        return;
    }
    if (file.size > MAX_DIGITAL_BYTES) {
        alert('This file is ' + formatBytes(file.size) + '. The final file must be under 300MB.');
        digitalFileInput.value = '';
        digitalFileSummary.style.display = 'none';
        updateUseAsPreviewBox();
        return;
    }
    digitalFileSummary.textContent = '✓ ' + file.name + ' (' + formatBytes(file.size) + ') — this exact file will be sent to the buyer.';
    digitalFileSummary.style.display = 'flex';
    updateUseAsPreviewBox();
});

useAsPreviewBtn.addEventListener('click', function () {
    const file = digitalFileInput.files[0];
    if (!file) return;
    // The preview is rendered as a separate, resized copy — the final file itself is never modified.
    addFiles([file], { fromFinal: file });
});

// ── Delivery-type driven UI ──────────────────────────────────────────────
function updateDigitalFileVisibility() {
    const isDigital = isDigitalSelected();
    filterCategoriesByDeliveryType();

    // Step 2 (final file + license) only exists for digital listings.
    digitalFileGroup.style.display = isDigital ? 'block' : 'none';
    digitalFileInput.required = isDigital;
    if (licenseInput) licenseInput.required = isDigital;

    // Make the two-step relationship explicit for digital listings.
    flowExplainer.style.display = isDigital ? 'grid' : 'none';
    step1Pill.style.display = isDigital ? 'inline-flex' : 'none';
    protectPanel.style.display = isDigital ? 'block' : 'none';
    physicalGuidelines.style.display = isDigital ? 'none' : 'block';
    digitalGuidelines.style.display = isDigital ? 'block' : 'none';

    if (isDigital) {
        previewSectionTitle.textContent = 'Public preview';
        previewSectionDesc.textContent = 'This is what buyers see on your listing before they buy. It is not the file they receive, so protect it however you like.';
    } else {
        previewSectionTitle.textContent = 'Artwork images';
        previewSectionDesc.textContent = 'Add clear photos of your artwork. The first one becomes the cover.';
    }

    // ── Weight: digital art has no physical shipment, so hide the field
    // and lock the value at 1 (kg) instead of asking the artist for it. ──
    if (isDigital) {
        weightGroup.style.display = 'none';
        weightInput.value = '1';
        weightInput.required = false;
    } else {
        weightGroup.style.display = 'block';
        weightInput.required = true;
    }

    // ── Size: guide the artist toward the right unit depending on
    // whether this is a physical piece or a digital file, since "size"
    // means something different for an image vs. an audio/video file. ──
    if (isDigital) {
        sizeInput.placeholder = 'e.g. 1920 x 1080 px  or  15 MB';
        sizeHint.textContent = 'Images: enter dimensions in pixels (e.g. "1920 x 1080 px"). Audio/video files: enter the file size in MB (e.g. "15 MB").';
    } else {
        sizeInput.placeholder = 'e.g. 24 x 36 inches';
        sizeHint.textContent = 'Include the unit (e.g. inches, cm, ft).';
    }
    // Re-check validity, since what counts as a valid unit changes with delivery type.
    validateSizeField();

    // ── Medium & Description: the hint text these fields show should talk
    // about digital tools/formats when the listing is a digital download,
    // instead of physical materials and a narrative "story" prompt that
    // doesn't fit a digital file. ──
    if (isDigital) {
        mediumInput.placeholder = 'e.g. Digital Painting, Vector Illustration, 3D Render';
        descriptionInput.placeholder = 'Describe the digital artwork — software/tools used, file contents, and what buyers get after purchase...';
    } else {
        mediumInput.placeholder = 'e.g. Oil on Canvas';
        descriptionInput.placeholder = 'Tell the story behind this artwork, techniques used, inspiration...';
    }

    const baseImageAccept = 'image/jpeg,image/png,image/webp,image/gif<?= $heicSupported ? ',image/heic,image/heif' : '' ?>';
    const digitalPreviewAccept = baseImageAccept + ',video/mp4,video/quicktime,video/webm,audio/mpeg,audio/wav,audio/mp4';
    fileInput.accept = isDigital ? digitalPreviewAccept : baseImageAccept;

    const titleEl = document.getElementById('uploadTitle');
    const hintEl  = document.getElementById('uploadHint');
    if (isDigital) {
        titleEl.textContent = 'Click to upload a preview image, GIF, or a short video/audio clip';
        hintEl.innerHTML = 'Up to 5 files · Images/GIFs max 10MB, video/audio max 60MB each · First file will be the cover.';
    } else {
        titleEl.textContent = 'Click to upload images';
        hintEl.innerHTML = 'Up to 5 images · Max 10MB each · First image will be the cover.<?php if (!$heicSupported): ?><br><strong>Note:</strong> iPhone HEIC photos aren\'t supported right now — please upload JPG or PNG.<?php endif; ?>';
    }

    // Protection only exists for digital listings, so switching the delivery
    // type re-renders any files already added (from their untouched originals).
    if (lastDeliveryWasDigital !== isDigital) {
        lastDeliveryWasDigital = isDigital;
        if (items.length > 0) reprocessAll();
    }
    updateUseAsPreviewBox();
}

// ── Protection controls ──────────────────────────────────────────────────
function onProtectionChange() {
    wmControls.style.display = wmEnabled.checked ? 'flex' : 'none';
    blurControls.style.display = blurEnabled.checked ? 'flex' : 'none';
    wmOpacityVal.textContent = wmOpacity.value + '%';
    scheduleReprocess();
}
[wmEnabled, wmText, wmStyle, wmOpacity, blurEnabled, blurLevel].forEach(el => el.addEventListener('input', onProtectionChange));
// Enter inside the watermark text box should not submit the whole listing.
wmText.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
wmText.value = '© ' + ARTIST_NAME;

categorySelect.addEventListener('change', updateDigitalFileVisibility);
deliveryTypeRadios.forEach(r => r.addEventListener('change', updateDigitalFileVisibility));
updateDigitalFileVisibility();
renderPreviews();

// ── Submit flow ──────────────────────────────────────────────────────────
// Block double-submits, make sure there's at least one preview, wait for any
// in-flight image processing to finish so we never upload an unprocessed
// original by accident, then show a loading overlay so the page never looks
// frozen while it uploads.
const uploadOverlay = document.getElementById('uploadOverlay');
const overlayTitle = document.getElementById('overlayTitle');
const submitBtn = document.getElementById('submitBtn');

function showPreviewError(message) {
    previewError.textContent = message;
    previewError.classList.add('show');
    previewSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

uploadForm.addEventListener('submit', function (e) {
    if (isSubmitting) {
        e.preventDefault();
        return;
    }

    // Block submission until the size field has a recognizable unit —
    // scroll/focus it so the artist sees exactly what needs fixing.
    if (!validateSizeField()) {
        e.preventDefault();
        sizeInput.focus();
        sizeInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    // If a slider was just moved, apply that change before uploading anything.
    if (reprocessPending) reprocessAll();

    // Catch a missing preview here, where the form is still filled in.
    // Left to the server, the artist would come back to an empty form.
    if (items.length === 0) {
        e.preventDefault();
        showPreviewError(isDigitalSelected()
            ? 'Add at least one public preview image in step 1. This is what buyers see before they buy.'
            : 'Add at least one image of your artwork.');
        return;
    }

    // The server silently skips oversized files, so say so up front instead.
    const tooBig = items.find(item => {
        const isMedia = item.kind === 'video' || item.kind === 'audio';
        return item.out.size > (isMedia ? MAX_MEDIA_BYTES : MAX_IMAGE_BYTES);
    });
    if (tooBig) {
        e.preventDefault();
        showPreviewError('"' + tooBig.out.name + '" is ' + formatBytes(tooBig.out.size) + ', which is too large for a preview (max 10MB for images, 60MB for video/audio). Remove it or choose a smaller file.');
        return;
    }

    // If images are still processing, hold the native submit, wait, then
    // submit programmatically (bypasses this listener, so no double-fire).
    if (pendingJobs.size > 0) {
        e.preventDefault();
        isSubmitting = true;
        submitBtn.disabled = true;
        overlayTitle.textContent = 'Preparing images';
        uploadOverlay.classList.add('active');

        Promise.all(Array.from(pendingJobs)).then(() => {
            syncFileInput();
            overlayTitle.textContent = 'Uploading artwork';
            uploadForm.submit();
        });
        return;
    }

    syncFileInput();
    isSubmitting = true;
    submitBtn.disabled = true;
    uploadOverlay.classList.add('active');
});
</script>
</body>
</html>