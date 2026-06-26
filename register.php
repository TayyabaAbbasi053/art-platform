<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
/* FIX 1: Allow scrolling so form doesn't hide top elements */
html,body{height:100%;overflow:auto;}
body{font-family:'DM Sans',sans-serif;background:#f5f5f5;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:20px 10px;}
/* FIX 2: Use min-height so container can grow */
.wrap{display:flex;width:780px;min-height:540px;border-radius:24px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.12),0 2px 8px rgba(0,0,0,0.06);border:none;background:#fff;margin:20px 0;}
.left{width:38%;background:#0C3F30;padding:36px 28px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden;}
.left::after{content:'';position:absolute;top:-60px;left:-60px;width:220px;height:220px;border:1px solid #1e1e1e;border-radius:50%;}
.left-tag{font-size:11px;letter-spacing:3px;color:#555;text-transform:uppercase;font-weight:400;z-index:1;}
.left-content{z-index:1;}
.left-headline{font-family:'Playfair Display',serif;font-size:28px;color:#fff;line-height:1.25;font-weight:400;}
.left-sub{font-size:11px;color:#fff;line-height:1.7;margin-top:12px;}
.left-features{list-style:none;margin-top:16px;}
.left-features li{font-size:10px;color:#fff;padding:4px 0;border-bottom:1px solid #161616;letter-spacing:0.3px;}
.left-features li:last-child{border:none;}
.left-footer{font-size:10px;color:#fff;z-index:1;}
.right{flex:1;padding:32px 36px;display:flex;flex-direction:column;justify-content:flex-start;}
.right h2{font-family:'Playfair Display',serif;font-size:24px;font-weight:400;color:#0a0a0a;margin-bottom:3px;}
.right p.sub{font-size:11px;color:#999;margin-bottom:18px;}
label{display:block;font-size:10px;letter-spacing:1px;text-transform:uppercase;color:#888;margin-bottom:4px;font-weight:500;}
input[type=text],input[type=email],input[type=password],input[type=tel]{width:100%;border:none;border-bottom:1.5px solid #e0e0e0;padding:6px 0;font-size:13px;font-family:'DM Sans',sans-serif;color:#0a0a0a;outline:none;background:transparent;transition:border-color .2s;}
input:focus{border-bottom-color:#0a0a0a;}
.field{margin-bottom:12px;}
.role-row{display:flex;gap:10px;margin-bottom:16px;}
.role-opt{flex:1;border:1.5px solid #e0e0e0;padding:10px 8px;cursor:pointer;text-align:center;transition:all .2s;position:relative;border-radius:10px;}
.role-opt input{display:none;}
.role-opt span{font-size:11px;color:#888;display:block;margin-top:2px;font-weight:400;}
.role-opt strong{font-size:12px;color:#0a0a0a;display:block;}
.role-opt.selected{border-color:#0a0a0a;background:#fafafa;}
.role-opt.selected strong{color:#0a0a0a;}
.role-opt.selected span{color:#666;}
button[type=submit]{width:100%;background:#0C3F30;color:#fff;border:none;padding:11px;font-size:12px;font-family:'DM Sans',sans-serif;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;margin-top:2px;transition:background .2s;border-radius:10px;}
button[type=submit]:hover{background:#0a5240;}
.bottom-link{text-align:center;margin-top:12px;font-size:11px;color:#999;}
.bottom-link a{color:#0a0a0a;font-weight:500;text-decoration:none;}
.msg{padding:8px 12px;font-size:11px;margin-bottom:14px;border-left:3px solid;border-radius:0 8px 8px 0;line-height:1.4;}
.msg.error{background:#fff5f5;border-color:#e24b4a;color:#8b1a1a;}
.msg.success{background:#f5fff8;border-color:#2a9d5c;color:#1a6e3c;}

/* new styles */
.artist-extra{display:none;margin-bottom:0;}
.artist-extra.show{display:block;}
.section-label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#0C3F30;font-weight:600;margin:10px 0 8px;padding-top:8px;border-top:1px solid #f0f0f0;}
.payment-toggle-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;}
.pay-toggle{display:flex;align-items:center;gap:5px;font-size:10px;color:#666;cursor:pointer;padding:5px 10px;border:1.5px solid #e0e0e0;border-radius:20px;transition:all .2s;user-select:none;}
.pay-toggle input{display:none;}
.pay-toggle.active{border-color:#0C3F30;background:#f0f8f5;color:#0C3F30;font-weight:500;}
.pay-fields{display:none;background:#fafafa;border-radius:8px;padding:10px 12px;margin-bottom:8px;}
.pay-fields.show{display:block;}
.pay-fields .field{margin-bottom:8px;}
.pay-fields .field:last-child{margin-bottom:0;}
.two-col{display:flex;gap:12px;}
.two-col .field{flex:1;}

.city-search-wrap{position:relative;}
.city-dropdown{display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e0e0e0;border-radius:8px;max-height:180px;overflow-y:auto;z-index:50;box-shadow:0 8px 20px rgba(0,0,0,0.08);margin-top:4px;}
.city-dropdown.open{display:block;}
.city-option{padding:8px 12px;font-size:12.5px;color:#0a0a0a;cursor:pointer;}
.city-option:hover,.city-option.active{background:#f0f8f5;}
.city-no-results{padding:8px 12px;font-size:11.5px;color:#999;font-style:italic;}
.city-search-input{width:100%;border:none;border-bottom:1.5px solid #e0e0e0;padding:6px 0;font-size:13px;font-family:'DM Sans',sans-serif;color:#0a0a0a;outline:none;background:transparent;}
.city-search-input:focus{border-bottom-color:#0a0a0a;}

.logo-text{font-family:'Playfair Display',serif;font-size:20px;color:#fff;text-decoration:none;font-weight:500;letter-spacing:2px;display:inline-block;}
.logo-text span{color:#8fbc8f;}

@media(max-width:700px){
  body{padding:0;background:#fff;align-items:flex-start;}
  .wrap{flex-direction:column;width:100%;max-width:400px;min-height:auto;border-radius:0;box-shadow:none;margin:0;}
  .left{width:100%;padding:24px 22px;min-height:auto;}
  .left-headline{font-size:22px;margin-top:10px;}
  .left-sub{display:none;}
  .left-features{display:none;}
  .left-footer{font-size:9px;}
  .right{padding:24px 22px;}
  .right h2{font-size:20px;}
  .field{margin-bottom:14px;}
  .role-row{margin-bottom:16px;}
  input{padding:7px 0;font-size:13px;}
  .two-col{flex-direction:column;gap:0;}
  .payment-toggle-row{gap:6px;}
  .pay-toggle{font-size:9px;padding:4px 8px;}
}
</style>
</head>
<body>
<?php
require_once __DIR__ . '/config/db.php';

 $error = '';
 $success = '';
 $formData = $_POST;
 $editMode = false;
 $lockedEmail = '';

 // Pull existing pending artist data for editing
 if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'], $_GET['email'])) {
     $editEmailParam = trim($_GET['email']);
     $eStmt = $conn->prepare("SELECT u.id, u.name, u.email, u.phone,
         p.bio, p.art_style, p.instagram_url, p.city, p.address,
         p.has_bank_account, p.bank_name, p.bank_account_title, p.bank_account_number,
         p.has_easypaisa, p.easypaisa_name, p.easypaisa_number,
         p.has_jazzcash, p.jazzcash_name, p.jazzcash_number,
         p.has_nayapay, p.nayapay_name, p.nayapay_number,
         p.has_sadapay, p.sadapay_name, p.sadapay_number
         FROM users u LEFT JOIN artist_profiles p ON p.user_id = u.id
         WHERE u.email = ? AND u.role = 'artist' AND u.status = 'pending' LIMIT 1");
     $eStmt->bind_param('s', $editEmailParam);
     $eStmt->execute();
     $existingRow = $eStmt->get_result()->fetch_assoc();
     if ($existingRow) {
         $editMode = true;
         $lockedEmail = $existingRow['email'];
         $formData = [
             'role' => 'artist',
             'name' => $existingRow['name'],
             'email' => $existingRow['email'],
             'phone' => $existingRow['phone'],
             'bio' => $existingRow['bio'],
             'art_style' => $existingRow['art_style'],
             'instagram_url' => $existingRow['instagram_url'],
             'city' => $existingRow['city'],
             'address' => $existingRow['address'],
             'has_bank_account' => $existingRow['has_bank_account'] ? '1' : null,
             'bank_name' => $existingRow['bank_name'],
             'bank_account_title' => $existingRow['bank_account_title'],
             'bank_account_number' => $existingRow['bank_account_number'],
             'has_easypaisa' => $existingRow['has_easypaisa'] ? '1' : null,
             'easypaisa_name' => $existingRow['easypaisa_name'],
             'easypaisa_number' => $existingRow['easypaisa_number'],
             'has_jazzcash' => $existingRow['has_jazzcash'] ? '1' : null,
             'jazzcash_name' => $existingRow['jazzcash_name'],
             'jazzcash_number' => $existingRow['jazzcash_number'],
             'has_nayapay' => $existingRow['has_nayapay'] ? '1' : null,
             'nayapay_name' => $existingRow['nayapay_name'],
             'nayapay_number' => $existingRow['nayapay_number'],
             'has_sadapay' => $existingRow['has_sadapay'] ? '1' : null,
             'sadapay_name' => $existingRow['sadapay_name'],
             'sadapay_number' => $existingRow['sadapay_number'],
         ];
     }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ignore_user_abort(true); // keep running even if the user's connection drops mid-request

    // If editing a pending application, the email is locked server-side —
    // we ignore whatever was posted in the email field and use the original.
    $postedEditEmail = trim($_POST['edit_email'] ?? '');
    if ($postedEditEmail !== '') {
        $editMode = true;
        $lockedEmail = $postedEditEmail;
    }

    $name     = trim($_POST['name'] ?? '');
    $email    = $editMode ? $lockedEmail : trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';
    $role     = $_POST['role'] ?? '';

    // new fields
    $city = trim($_POST['city'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $hasBankAccount = isset($_POST['has_bank_account']) ? 1 : 0;
$hasEasypaisa   = isset($_POST['has_easypaisa'])    ? 1 : 0;
$hasJazzcash    = isset($_POST['has_jazzcash'])      ? 1 : 0;
$hasNayapay     = isset($_POST['has_nayapay'])       ? 1 : 0;
$hasSadapay     = isset($_POST['has_sadapay'])       ? 1 : 0;

    if (!$role || !in_array($role, ['artist', 'buyer'])) {
    $error = 'Please select an account type (Artist or Buyer).';
} elseif (!$name) {
    $error = 'Full name is required.';
} elseif (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please enter a valid email address.';
} elseif (!$phone) {
    $error = 'Phone / WhatsApp number is required.';
} elseif (strlen($password) < 8) {
    $error = 'Password must be at least 8 characters.';
} elseif ($password !== $confirm) {
    $error = 'Passwords do not match.';
} elseif ($role === 'artist' && !trim($_POST['bio'] ?? '')) {
    $error = 'Bio is required.';
} elseif ($role === 'artist' && !trim($_POST['art_style'] ?? '')) {
    $error = 'Art style is required.';
} elseif ($role === 'artist' && !$city) {
    $error = 'City is required.';
} elseif ($role === 'artist' && !$address) {
    $error = 'Address is required.';
} elseif ($role === 'artist' && !$hasBankAccount && !$hasEasypaisa && !$hasJazzcash && !$hasNayapay && !$hasSadapay) {
    $error = 'Please add at least one payment method.';
} elseif ($role === 'artist' && $hasBankAccount && (!trim($_POST['bank_name'] ?? '') || !trim($_POST['bank_account_title'] ?? '') || !trim($_POST['bank_account_number'] ?? ''))) {
    $error = 'Please fill in all bank account details (bank name, account title, and account number).';
} elseif ($role === 'artist' && $hasEasypaisa && (!trim($_POST['easypaisa_name'] ?? '') || !trim($_POST['easypaisa_number'] ?? ''))) {
    $error = 'Please fill in your Easypaisa account name and number.';
} elseif ($role === 'artist' && $hasJazzcash && (!trim($_POST['jazzcash_name'] ?? '') || !trim($_POST['jazzcash_number'] ?? ''))) {
    $error = 'Please fill in your JazzCash account name and number.';
} elseif ($role === 'artist' && $hasNayapay && (!trim($_POST['nayapay_name'] ?? '') || !trim($_POST['nayapay_number'] ?? ''))) {
    $error = 'Please fill in your NayaPay account name and number.';
} elseif ($role === 'artist' && $hasSadapay && (!trim($_POST['sadapay_name'] ?? '') || !trim($_POST['sadapay_number'] ?? ''))) {
    $error = 'Please fill in your SadaPay account name and number.';
} elseif (!isset($_POST['terms'])) {
    $error = 'You must agree to the Terms & Conditions and Privacy Policy.';
} else {
        $check = $conn->prepare("SELECT id, status, role FROM users WHERE email = ? LIMIT 1");
        $check->bind_param('s', $email);
        $check->execute();
        $check->store_result();
        $check->bind_result($existingId, $existingStatus, $existingRole);
        $check->fetch();

        if ($check->num_rows > 0 && !($existingRole === 'artist' && $existingStatus === 'pending')) {
            $error = 'An account with this email already exists.';
        } elseif ($check->num_rows > 0 && $existingRole === 'artist' && $existingStatus === 'pending') {
            // Allow pending artist to resubmit — update their record
            $conn->begin_transaction();
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmtUp = $conn->prepare("UPDATE users SET name=?, phone=?, password_hash=? WHERE id=?");
                $stmtUp->bind_param('sssi', $name, $phone, $hash, $existingId);
                $stmtUp->execute();
                $userId = $existingId;

                $bankName    = $hasBankAccount ? trim($_POST['bank_name'] ?? '')           : null;
                $bankTitle   = $hasBankAccount ? trim($_POST['bank_account_title'] ?? '')  : null;
                $bankNumber  = $hasBankAccount ? trim($_POST['bank_account_number'] ?? '') : null;
                $epName   = $hasEasypaisa ? trim($_POST['easypaisa_name'] ?? '')   : null;
                $epNum    = $hasEasypaisa ? trim($_POST['easypaisa_number'] ?? '')  : null;
                $jcName   = $hasJazzcash  ? trim($_POST['jazzcash_name'] ?? '')    : null;
                $jcNum    = $hasJazzcash  ? trim($_POST['jazzcash_number'] ?? '')   : null;
                $npName   = $hasNayapay   ? trim($_POST['nayapay_name'] ?? '')     : null;
                $npNum    = $hasNayapay   ? trim($_POST['nayapay_number'] ?? '')    : null;
                $spName   = $hasSadapay   ? trim($_POST['sadapay_name'] ?? '')     : null;
                $spNum    = $hasSadapay   ? trim($_POST['sadapay_number'] ?? '')    : null;
                $bio_reg        = trim($_POST['bio'] ?? '');
                $art_style_reg  = trim($_POST['art_style'] ?? '');
                $instagram_reg  = trim($_POST['instagram_url'] ?? '');

                $profile = $conn->prepare("INSERT INTO artist_profiles 
                    (user_id, bio, art_style, instagram_url, city, address,
                     has_bank_account, bank_name, bank_account_title, bank_account_number,
                     has_easypaisa, easypaisa_name, easypaisa_number,
                     has_jazzcash, jazzcash_name, jazzcash_number,
                     has_nayapay, nayapay_name, nayapay_number,
                     has_sadapay, sadapay_name, sadapay_number)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                     bio=VALUES(bio), art_style=VALUES(art_style), instagram_url=VALUES(instagram_url),
                     city=VALUES(city), address=VALUES(address),
                     has_bank_account=VALUES(has_bank_account), bank_name=VALUES(bank_name),
                     bank_account_title=VALUES(bank_account_title), bank_account_number=VALUES(bank_account_number),
                     has_easypaisa=VALUES(has_easypaisa), easypaisa_name=VALUES(easypaisa_name), easypaisa_number=VALUES(easypaisa_number),
                     has_jazzcash=VALUES(has_jazzcash), jazzcash_name=VALUES(jazzcash_name), jazzcash_number=VALUES(jazzcash_number),
                     has_nayapay=VALUES(has_nayapay), nayapay_name=VALUES(nayapay_name), nayapay_number=VALUES(nayapay_number),
                     has_sadapay=VALUES(has_sadapay), sadapay_name=VALUES(sadapay_name), sadapay_number=VALUES(sadapay_number)");

                $profile->bind_param('isssssississississssss',
                    $userId, $bio_reg, $art_style_reg, $instagram_reg, $city, $address,
                    $hasBankAccount, $bankName, $bankTitle, $bankNumber,
                    $hasEasypaisa, $epName, $epNum,
                    $hasJazzcash,  $jcName, $jcNum,
                    $hasNayapay,   $npName, $npNum,
                    $hasSadapay,   $spName, $spNum
                );
                $profile->execute();

                $conn->commit();
                $success = 'Artist account updated! Your account is pending admin approval. You will be able to log in once approved.';
                $formData = [];
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Something went wrong. Please try again.';
            }
        } else {
            $conn->begin_transaction();
            try {
                $hash   = password_hash($password, PASSWORD_BCRYPT);
                $status = ($role === 'artist') ? 'pending' : 'active';
                $stmt   = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('ssssss', $name, $email, $phone, $hash, $role, $status);
                $stmt->execute();
                $userId = $conn->insert_id;

                if ($role === 'artist') {
                    $bankName    = $hasBankAccount ? trim($_POST['bank_name'] ?? '')           : null;
                    $bankTitle   = $hasBankAccount ? trim($_POST['bank_account_title'] ?? '')  : null;
                    $bankNumber  = $hasBankAccount ? trim($_POST['bank_account_number'] ?? '') : null;
                    $epName   = $hasEasypaisa ? trim($_POST['easypaisa_name'] ?? '')   : null;
                    $epNum    = $hasEasypaisa ? trim($_POST['easypaisa_number'] ?? '')  : null;
                    $jcName   = $hasJazzcash  ? trim($_POST['jazzcash_name'] ?? '')    : null;
                    $jcNum    = $hasJazzcash  ? trim($_POST['jazzcash_number'] ?? '')   : null;
                    $npName   = $hasNayapay   ? trim($_POST['nayapay_name'] ?? '')     : null;
                    $npNum    = $hasNayapay   ? trim($_POST['nayapay_number'] ?? '')    : null;
                    $spName   = $hasSadapay   ? trim($_POST['sadapay_name'] ?? '')     : null;
                    $spNum    = $hasSadapay   ? trim($_POST['sadapay_number'] ?? '')    : null;
                    $bio_reg        = trim($_POST['bio'] ?? '');
                    $art_style_reg  = trim($_POST['art_style'] ?? '');
                    $instagram_reg  = trim($_POST['instagram_url'] ?? '');

                    $profile = $conn->prepare("INSERT INTO artist_profiles 
                        (user_id, bio, art_style, instagram_url, city, address,
                         has_bank_account, bank_name, bank_account_title, bank_account_number,
                         has_easypaisa, easypaisa_name, easypaisa_number,
                         has_jazzcash, jazzcash_name, jazzcash_number,
                         has_nayapay, nayapay_name, nayapay_number,
                         has_sadapay, sadapay_name, sadapay_number)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                         bio=VALUES(bio), art_style=VALUES(art_style), instagram_url=VALUES(instagram_url),
                         city=VALUES(city), address=VALUES(address),
                         has_bank_account=VALUES(has_bank_account), bank_name=VALUES(bank_name),
                         bank_account_title=VALUES(bank_account_title), bank_account_number=VALUES(bank_account_number),
                         has_easypaisa=VALUES(has_easypaisa), easypaisa_name=VALUES(easypaisa_name), easypaisa_number=VALUES(easypaisa_number),
                         has_jazzcash=VALUES(has_jazzcash), jazzcash_name=VALUES(jazzcash_name), jazzcash_number=VALUES(jazzcash_number),
                         has_nayapay=VALUES(has_nayapay), nayapay_name=VALUES(nayapay_name), nayapay_number=VALUES(nayapay_number),
                         has_sadapay=VALUES(has_sadapay), sadapay_name=VALUES(sadapay_name), sadapay_number=VALUES(sadapay_number)");

                    $profile->bind_param('isssssississississssss',
                        $userId, $bio_reg, $art_style_reg, $instagram_reg, $city, $address,
                        $hasBankAccount, $bankName, $bankTitle, $bankNumber,
                        $hasEasypaisa, $epName, $epNum,
                        $hasJazzcash,  $jcName, $jcNum,
                        $hasNayapay,   $npName, $npNum,
                        $hasSadapay,   $spName, $spNum
                    );
                    $profile->execute();
                    $conn->commit();
                    $success = 'Artist account created! Your account is pending admin approval. You will be able to log in once approved.';
                } else {
                    $conn->commit();
                    $success = 'Account created successfully! You can now log in.';
                }
                $formData = [];
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}
?>
<div class="wrap">
    <div class="left">
        <a href="index.php" class="logo-text">Art <span>Bazaar</span></a>
        <div class="left-content">
            <p class="left-headline">Join the<br>community.</p>
            <p class="left-sub">Whether you create or collect — there's a place for you here.</p>
            <ul class="left-features">
                <li>→ Artists: showcase and sell your artwork</li>
                <li>→ Buyers: browse and request custom artwork</li>
                <li>→ Secure platform-managed requests</li>
                <li>→ Supporting Pakistani artists</li>
            </ul>
        </div>
        <p class="left-footer">Art Bazaar — Pakistan's art marketplace.</p>
    </div>
    <div class="right">
        <h2>Create Account</h2>
        <p class="sub">Choose your account type to get started.</p>

        <?php if ($error): ?>
            <div class="msg error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="msg success"><?= htmlspecialchars($success) ?></div>
            <p class="bottom-link"><a href="login.php">Go to Login →</a></p>
        <?php else: ?>

        <form method="POST" id="regForm">
            <div class="role-row">
                <label class="role-opt <?= ($formData['role'] ?? '') === 'artist' ? 'selected' : '' ?>" onclick="selectRole('artist',this)">
                    <input type="radio" name="role" value="artist" <?= ($formData['role'] ?? '') === 'artist' ? 'checked' : '' ?>>
                    <strong>Artist</strong>
                    <span>Sell your artwork</span>
                </label>
                <label class="role-opt <?= ($formData['role'] ?? '') === 'buyer' ? 'selected' : '' ?>" onclick="selectRole('buyer',this)">
                    <input type="radio" name="role" value="buyer" <?= ($formData['role'] ?? '') === 'buyer' ? 'checked' : '' ?>>
                    <strong>Buyer</strong>
                    <span>Request custom artwork</span>
                </label>
            </div>

            <div class="field">
                <label>Full Name</label>
                <input type="text" name="name" placeholder="Your full name" value="<?= htmlspecialchars($formData['name'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label>Email Address<?= $editMode ? ' (locked — cannot be changed)' : '' ?></label>
                <input type="email" name="email" placeholder="you@example.com"
                       value="<?= htmlspecialchars($formData['email'] ?? '') ?>"
                       <?= $editMode ? 'readonly style="background:#f0f0f0;color:#888;cursor:not-allowed;"' : '' ?>
                       required>
                <?php if ($editMode): ?>
                    <input type="hidden" name="edit_email" value="<?= htmlspecialchars($lockedEmail) ?>">
                <?php endif; ?>
            </div>
            <div class="field">
                <label>Phone / WhatsApp</label>
                <input type="tel" name="phone" placeholder="+92 300 0000000" value="<?= htmlspecialchars($formData['phone'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Password</label>
                <input type="password" name="password" placeholder="Min. 8 characters" required>
            </div>
            <div class="field">
                <label>Confirm Password</label>
                <input type="password" name="confirm" placeholder="Repeat password" required>
            </div>

            <div id="artist-extra" class="artist-extra">
                <div class="section-label">🎨 About You</div>
                <div class="field">
                    <label>Bio</label>
                    <textarea name="bio" placeholder="Tell buyers about your art, style, and inspiration..." style="width:100%;border:none;border-bottom:1.5px solid #e0e0e0;padding:6px 0;font-size:13px;font-family:'DM Sans',sans-serif;color:#0a0a0a;outline:none;background:transparent;resize:vertical;min-height:70px;"><?= htmlspecialchars($formData['bio'] ?? '') ?></textarea>
                </div>
                <div class="two-col">
                    <div class="field">
                        <label>Art Style</label>
                        <input type="text" name="art_style" placeholder="e.g. Abstract, Realism" value="<?= htmlspecialchars($formData['art_style'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label>Instagram URL <span style="font-size:9px;color:#999;text-transform:none;letter-spacing:0">(optional)</span></label>
                        <input type="url" name="instagram_url" placeholder="https://instagram.com/yourhandle" value="<?= htmlspecialchars($formData['instagram_url'] ?? '') ?>">
                    </div>
                </div>
                <div class="section-label">📍 Location</div>
                <div class="two-col">
                    <div class="field">
                        <label>City</label>
                        <div class="city-search-wrap">
                            <input type="text" class="city-search-input" id="citySearchInput" placeholder="Search or type your city..." autocomplete="off" value="<?= htmlspecialchars($formData['city'] ?? '') ?>">
                            <input type="hidden" name="city" id="cityHidden" value="<?= htmlspecialchars($formData['city'] ?? '') ?>">
                            <div class="city-dropdown" id="cityDropdown"></div>
                        </div>
                    </div>
                    <div class="field">
                        <label>Address</label>
                        <input type="text" name="address" placeholder="Address" value="<?= htmlspecialchars($formData['address'] ?? '') ?>">
                    </div>
                </div>

                <div class="section-label">💳 Payment Methods <span style="font-size:9px;color:#999;text-transform:none;letter-spacing:0;font-weight:400">(at least one required)</span></div>
                <div class="payment-toggle-row">
                    <label class="pay-toggle <?= isset($formData['has_bank_account']) ? 'active' : '' ?>" onclick="togglePay('bank',this)">
                        <input type="checkbox" name="has_bank_account" <?= isset($formData['has_bank_account']) ? 'checked' : '' ?>> 🏦 Bank Account
                    </label>
                    <label class="pay-toggle <?= isset($formData['has_easypaisa']) ? 'active' : '' ?>" onclick="togglePay('easypaisa',this)">
                        <input type="checkbox" name="has_easypaisa" <?= isset($formData['has_easypaisa']) ? 'checked' : '' ?>> Easypaisa
                    </label>
                    <label class="pay-toggle <?= isset($formData['has_jazzcash']) ? 'active' : '' ?>" onclick="togglePay('jazzcash',this)">
                        <input type="checkbox" name="has_jazzcash" <?= isset($formData['has_jazzcash']) ? 'checked' : '' ?>> JazzCash
                    </label>
                    <label class="pay-toggle <?= isset($formData['has_nayapay']) ? 'active' : '' ?>" onclick="togglePay('nayapay',this)">
                        <input type="checkbox" name="has_nayapay" <?= isset($formData['has_nayapay']) ? 'checked' : '' ?>> NayaPay
                    </label>
                    <label class="pay-toggle <?= isset($formData['has_sadapay']) ? 'active' : '' ?>" onclick="togglePay('sadapay',this)">
                        <input type="checkbox" name="has_sadapay" <?= isset($formData['has_sadapay']) ? 'checked' : '' ?>> SadaPay
                    </label>
                </div>

                <div id="pay-bank" class="pay-fields <?= isset($formData['has_bank_account']) ? 'show' : '' ?>">
                    <div class="field"><label>Bank Name</label><input type="text" name="bank_name" placeholder="e.g. HBL, Meezan" value="<?= htmlspecialchars($formData['bank_name'] ?? '') ?>"></div>
                    <div class="two-col">
                        <div class="field"><label>Account Title</label><input type="text" name="bank_account_title" placeholder="Name on account" value="<?= htmlspecialchars($formData['bank_account_title'] ?? '') ?>"></div>
                        <div class="field"><label>Account Number</label><input type="text" name="bank_account_number" placeholder="Account / IBAN" value="<?= htmlspecialchars($formData['bank_account_number'] ?? '') ?>"></div>
                    </div>
                </div>

                <div id="pay-easypaisa" class="pay-fields <?= isset($formData['has_easypaisa']) ? 'show' : '' ?>">
                    <div class="two-col">
                        <div class="field"><label>Account Name</label><input type="text" name="easypaisa_name" placeholder="Registered name" value="<?= htmlspecialchars($formData['easypaisa_name'] ?? '') ?>"></div>
                        <div class="field"><label>Mobile Number</label><input type="tel" name="easypaisa_number" placeholder="03XX XXXXXXX" value="<?= htmlspecialchars($formData['easypaisa_number'] ?? '') ?>"></div>
                    </div>
                </div>

                <div id="pay-jazzcash" class="pay-fields <?= isset($formData['has_jazzcash']) ? 'show' : '' ?>">
                    <div class="two-col">
                        <div class="field"><label>Account Name</label><input type="text" name="jazzcash_name" placeholder="Registered name" value="<?= htmlspecialchars($formData['jazzcash_name'] ?? '') ?>"></div>
                        <div class="field"><label>Mobile Number</label><input type="tel" name="jazzcash_number" placeholder="03XX XXXXXXX" value="<?= htmlspecialchars($formData['jazzcash_number'] ?? '') ?>"></div>
                    </div>
                </div>

                <div id="pay-nayapay" class="pay-fields <?= isset($formData['has_nayapay']) ? 'show' : '' ?>">
                    <div class="two-col">
                        <div class="field"><label>Account Name</label><input type="text" name="nayapay_name" placeholder="Registered name" value="<?= htmlspecialchars($formData['nayapay_name'] ?? '') ?>"></div>
                        <div class="field"><label>Mobile Number</label><input type="tel" name="nayapay_number" placeholder="03XX XXXXXXX" value="<?= htmlspecialchars($formData['nayapay_number'] ?? '') ?>"></div>
                    </div>
                </div>

                <div id="pay-sadapay" class="pay-fields <?= isset($formData['has_sadapay']) ? 'show' : '' ?>">
                    <div class="two-col">
                        <div class="field"><label>Account Name</label><input type="text" name="sadapay_name" placeholder="Registered name" value="<?= htmlspecialchars($formData['sadapay_name'] ?? '') ?>"></div>
                        <div class="field"><label>Mobile Number</label><input type="tel" name="sadapay_number" placeholder="03XX XXXXXXX" value="<?= htmlspecialchars($formData['sadapay_number'] ?? '') ?>"></div>
                    </div>
                </div>
            </div>

            <div style="display:flex;align-items:flex-start;gap:8px;margin:10px 0 8px;">
              <input type="checkbox" name="terms" id="terms" required style="margin-top:2px;width:14px;height:14px;accent-color:#0C3F30;">
              <label for="terms" style="font-size:10px;color:#888;text-transform:none;letter-spacing:0;font-weight:400;line-height:1.5;">I agree to the <a href="terms.php" style="color:#0C3F30;">Terms & Conditions</a> and <a href="privacy.php" style="color:#0C3F30;">Privacy Policy</a></label>
            </div>
            <button type="submit">Create Account</button>
        </form>

        <p class="bottom-link">Already have an account? <a href="login.php">Sign in</a></p>
        <?php endif; ?>
    </div>
</div>
<script>
function selectRole(role, el) {
    document.querySelectorAll('.role-opt').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    el.querySelector('input').checked = true;
    const extra = document.getElementById('artist-extra');
    if (role === 'artist') {
        extra.classList.add('show');
    } else {
        extra.classList.remove('show');
    }
}

function togglePay(key, el) {
    el.classList.toggle('active');
    const cb = el.querySelector('input[type=checkbox]');
    cb.checked = !cb.checked;
    document.getElementById('pay-' + key).classList.toggle('show', cb.checked);
}

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

// Restore artist section on page reload with errors
(function(){
    const role = document.querySelector('input[name=role]:checked');
    if (role && role.value === 'artist') {
        document.getElementById('artist-extra').classList.add('show');
    }
})();
</script>
</body>
</html>