<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/firebase.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Review.php';
require_once __DIR__ . '/../controllers/ReviewController.php';
require_once __DIR__ . '/guest_info_schema.php';

$user = new User();
$reviewController = new ReviewController();
$currentUser = $user->getCurrentUser();
$recentReviews = $reviewController->getRecentReviews(3);

// Prefill helpers for lead guest form
$guestPrefill = $_SESSION['guest_info'] ?? [];
$guestPrefillFirst = strtoupper($guestPrefill['first_name'] ?? '');
$guestPrefillLast = strtoupper($guestPrefill['last_name'] ?? '');
$guestPrefillEmail = $guestPrefill['email'] ?? ($currentUser['email'] ?? '');
if (!empty($guestPrefill['mobile_number'])) {
    $storedCode = trim((string)($guestPrefill['mobile_country_code'] ?? ''));
    $storedNumber = trim((string)($guestPrefill['mobile_number'] ?? ''));
    $guestPrefillMobile = $storedCode !== '' ? $storedCode . $storedNumber : $storedNumber;
} else {
    $guestPrefillMobile = trim((string)($currentUser['phone'] ?? ''));
}
    $guestPhoneVerified = false;

if (($guestPrefillFirst === '' || $guestPrefillLast === '') && !empty($currentUser['fullname'])) {
    $nameParts = preg_split('/\s+/', trim($currentUser['fullname']), 2);
    if ($guestPrefillFirst === '') {
        $guestPrefillFirst = $nameParts[0] ?? '';
    }
    if ($guestPrefillLast === '') {
        $guestPrefillLast = $nameParts[1] ?? '';
    }
}

// Fetch recent reservations for the user dropdown
$recentReservations = [];
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $conn = $db->getConnection();
    ensureGuestInfoSchema($conn);

    $tablesToEnsure = [
        "CREATE TABLE IF NOT EXISTS reservations (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            check_in DATE NOT NULL,
            check_out DATE NOT NULL,
            adults INT(11) NOT NULL,
            children INT(11) NOT NULL,
            seniors INT(11) NOT NULL,
            total_amount DECIMAL(10,2) NOT NULL,
            tour_type ENUM('day', 'night') DEFAULT 'day',
            status VARCHAR(20) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )",
        "CREATE TABLE IF NOT EXISTS reservation_items (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            reservation_id INT(11) NOT NULL,
            item_type VARCHAR(20) NOT NULL,
            item_id INT(11) NOT NULL,
            item_name VARCHAR(100) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            capacity INT(11) NOT NULL,
            nights INT(11) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (reservation_id) REFERENCES reservations(id)
        )"
    ];

    foreach ($tablesToEnsure as $tableSql) {
        @$conn->query($tableSql);
    }

    $resSql = "SELECT r.id, r.status, r.total_amount, r.created_at, 
               GROUP_CONCAT(ri.item_name SEPARATOR ', ') as item_names
               FROM reservations r 
               LEFT JOIN reservation_items ri ON r.id = ri.reservation_id
               WHERE r.user_id = ? 
               GROUP BY r.id 
               ORDER BY r.created_at DESC 
               LIMIT 3";

    if ($stmt = $conn->prepare($resSql)) {
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $resResult = $stmt->get_result();
        $recentReservations = $resResult->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>css/style.css?v=20260922-footer1">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        #loginPopup.login-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.7);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        #loginPopup.login-modal.show {
            display: flex;
        }

        .login-modal-content {
            width: min(520px, 100%);
            max-width: 520px;
            background: #ffffff;
            border-radius: 30px;
            padding: 2.5rem;
            box-shadow: 0 30px 80px rgba(15, 23, 42, 0.18);
            border: 1px solid rgba(148, 163, 184, 0.16);
            position: relative;
            transform: translateY(-10px);
            transition: transform 0.25s ease, opacity 0.25s ease;
        }

        .login-modal.show .login-modal-content {
            transform: translateY(0);
            opacity: 1;
        }

        .login-modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: transparent;
            border: none;
            color: #475569;
            font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .login-modal-close:hover {
            color: #0f172a;
        }

        .login-popup-header h2 {
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
            color: #1f3a8a;
        }

        .login-popup-header p {
            color: #64748b;
            line-height: 1.8;
            margin-bottom: 1.8rem;
        }

        .login-popup-buttons {
            display: grid;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .social-btn {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            border-radius: 16px;
            font-weight: 700;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .social-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12);
        }

        .social-btn-google {
            background: #ffffff;
            color: #1f1f1f;
            border: 1px solid #747775;
            font-weight: 500;
        }

        .social-btn-google:hover {
            background: #f8f9fa;
            box-shadow: 0 1px 3px rgba(60, 64, 67, 0.3), 0 1px 2px rgba(60, 64, 67, 0.15);
        }

        .social-btn-google .google-logo {
            flex-shrink: 0;
        }

        .social-btn-facebook {
            background: #111827;
            color: #ffffff;
            border: 1px solid transparent;
        }

        .login-popup-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 1rem 0 1.2rem;
            color: #94a3b8;
            font-size: 0.9rem;
            letter-spacing: 0.1em;
        }

        .login-popup-divider::before,
        .login-popup-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
            margin: 0 1rem;
        }

        .login-popup-disclaimer {
            color: #64748b;
            font-size: 0.88rem;
            line-height: 1.6;
        }

        .login-popup-disclaimer a {
            color: #2563eb;
            text-decoration: underline;
        }

        /* User Avatar Styles */
        .user-avatar-container {
            display: flex;
            align-items: center;
            margin-left: 1rem;
            position: relative;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            cursor: pointer;
            transition: all 0.3s ease;
            object-fit: cover;
        }

        .user-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
            border-color: #FF7A3D;
        }

        /* User Dropdown Menu */
        .user-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 0.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            border: 1px solid rgba(0, 0, 0, 0.08);
            min-width: 340px;
            max-width: 420px;
            z-index: 1000;
            display: none;
            overflow: hidden;
        }

        .user-dropdown.show {
            display: block;
        }

        .user-dropdown-header {
            padding: 1rem;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
        }

        .user-dropdown-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-dropdown-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 2px solid #FF7A3D;
            object-fit: cover;
        }

        .user-dropdown-details h4 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: #1f2937;
        }

        .user-dropdown-details p {
            margin: 0;
            font-size: 0.875rem;
            color: #6b7280;
        }

        .user-dropdown-menu {
            padding: 0.75rem;
        }

        .user-dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: #1e3a8a !important;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.2s ease;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .user-dropdown-item:hover {
            background: #f3f4f6;
            color: #1f2937;
        }

        .user-dropdown-item i {
            width: 16px;
            color: #000000;
        }

        .reservation-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 1rem;
            margin: 0.5rem 0;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }

        .reservation-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .reservation-card-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }

        .reservation-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: white;
        }

        .reservation-status.pending { background: #f59e0b; }
        .reservation-status.approved { background: #10b981; }
        .reservation-status.cancelled { background: #ef4444; }

        .reservation-card-meta {
            display: grid;
            gap: 0.4rem;
            margin-bottom: 0.75rem;
        }

        .reservation-card-meta span {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.82rem;
            color: #475569;
        }

        .reservation-card-section {
            display: grid;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .reservation-card-section strong {
            display: block;
            font-size: 0.78rem;
            color: #0f172a;
            margin-bottom: 0.2rem;
        }

        .reservation-card-details {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .reservation-card-detail {
            flex: 1 1 45%;
            background: #f8fafc;
            border-radius: 12px;
            padding: 0.65rem 0.75rem;
            font-size: 0.82rem;
            color: #334155;
        }

        .reservation-card-action {
            display: block;
            width: 100%;
            margin-top: 0.5rem;
            padding: 0.75rem 0.9rem;
            text-align: center;
            border-radius: 12px;
            border: none;
            background: #3b82f6;
            color: white;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.2s ease;
        }

        .reservation-card-action:hover {
            background: #2563eb;
        }

        .user-dropdown-divider {
            height: 1px;
            background: #e5e7eb;
            margin: 0.5rem 0;
        }

            </style>
        <?php echo isset($pageHead) ? $pageHead : ''; ?>
</head>
<body class="public-page <?php echo htmlspecialchars($pageClass ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="nav">
                <div class="logo" style="display: flex; align-items: center; gap: 1rem;">
                    <img src="<?php echo SITE_URL; ?>images/logo.jpg" alt="Villa Soledad Garden Resort Logo" style="height: 60px; width: 60px; object-fit: cover; border-radius: 50%; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                    <span style="font-size: 1.5rem; font-weight: 700; color: white;">Villa Soledad Garden Resort</span>
                                    </div>
                <button class="mobile-menu-toggle" onclick="toggleMobileMenu()" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; display: none;">
                    <i class="fas fa-bars"></i>
                </button>
                <nav class="nav-links" id="navLinks">
                    <a href="<?php echo SITE_URL; ?>index.php#home">Home</a>
                    <a href="<?php echo SITE_URL; ?>index.php#cottages">Cottages</a>
                    <a href="<?php echo SITE_URL; ?>index.php#pools">Pools</a>
                    <a href="<?php echo SITE_URL; ?>index.php#rooms">Rooms</a>
                    <?php if ($user->isLoggedIn()): ?>
                        <a href="<?php echo SITE_URL; ?>booking.php" id="bookNowNavLink" onclick="return handleBookNowClick(event);">Book Now</a>
                    <?php endif; ?>
                    <a href="<?php echo SITE_URL; ?>food-menu.php">Food Menu</a>
                    
                    <?php if ($user->isLoggedIn()): ?>
                        <div class="user-avatar-container">
                            <img src="<?php echo !empty($_SESSION['user_avatar']) ? $_SESSION['user_avatar'] : 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['user_name'] ?? 'User') . '&background=FF7A3D&color=fff&size=32'; ?>" 
                                 alt="User Avatar" 
                                 class="user-avatar"
                                 title="<?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>"
                                 onclick="toggleUserDropdown()">
                            
                            <div class="user-dropdown" id="userDropdown">
                                <div class="user-dropdown-header">
                                    <div class="user-dropdown-info">
                                        <img src="<?php echo !empty($_SESSION['user_avatar']) ? $_SESSION['user_avatar'] : 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['user_name'] ?? 'User') . '&background=FF7A3D&color=fff&size=48'; ?>" 
                                             alt="User Avatar" 
                                             class="user-dropdown-avatar">
                                        <div class="user-dropdown-details">
                                            <h4><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></h4>
                                            <p><?php echo htmlspecialchars($currentUser['email'] ?? ''); ?></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="user-dropdown-menu">
                                    <a href="<?php echo SITE_URL; ?>profile.php" class="user-dropdown-item" onclick="event.stopPropagation()">
                                        <i class="fas fa-user"></i>
                                        My Profile
                                    </a>
                                    <a href="<?php echo SITE_URL; ?>my-bookings.php" class="user-dropdown-item" onclick="event.stopPropagation()">
                                        <i class="fas fa-calendar-check"></i>
                                        My Bookings
                                    </a>
                                    <div class="user-dropdown-divider"></div>
                                    <a href="<?php echo SITE_URL; ?>logout.php" class="user-dropdown-item">
                                        <i class="fas fa-sign-out-alt"></i>
                                        Log out
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <button type="button" onclick="openLoginModal()" class="btn-login" style="background: #FF7A3D; color: white; border: none; padding: 0.9rem 2rem; border-radius: 50px; font-weight: 700; font-size: 1rem; cursor: pointer; display: flex; align-items: center; gap: 0.6rem; transition: all 0.3s ease;" onmouseover="this.style.background='#FF6B1F';" onmouseout="this.style.background='#FF7A3D';"><i class="fas fa-user-circle"></i> Login</button>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </header>

    <!-- Login Popup -->
    <div id="loginPopup" class="login-modal" aria-hidden="true" hidden>
        <div class="login-modal-content">
            <button type="button" class="login-modal-close" onclick="closeLoginModal()" aria-label="Close">&times;</button>
            <div class="login-popup-header">
                <h2>Log in</h2>
                <p>Choose a sign-in option to continue to the resort booking system.</p>
            </div>
            <div class="login-popup-buttons">
                <button type="button" class="social-btn social-btn-google" onclick="window.location.href='<?php echo SITE_URL; ?>google-auth.php'">
                    <svg class="google-logo" width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                    Sign in with Google
                </button>
            </div>
            <div class="login-popup-divider"><span>OR</span></div>
            <p class="login-popup-disclaimer">By using our service, you agree with our Terms of service, CCPA Notice and Privacy Notice that details what personal data we collect and use to provide you with the best learning experience.</p>
        </div>
    </div>

    <?php if ($user->isLoggedIn()): ?>
    <!-- Lead Guest Info Popup -->
    <div id="guestInfoPopup" class="guest-info-modal" aria-hidden="true" hidden>
        <div class="guest-info-modal-content" role="dialog" aria-labelledby="guestInfoTitle">
            <button type="button" class="guest-info-modal-close" onclick="closeGuestInfoModal()" aria-label="Close">&times;</button>
            <h2 id="guestInfoTitle">Who's the lead guest?</h2>
            <p class="guest-info-required">*Required field</p>
            <form id="guestInfoForm" onsubmit="return submitGuestInfoForm(event);">
                <div class="guest-info-grid">
                    <div class="guest-field">
                        <label for="guestFirstName">First name <span>*</span></label>
                        <input type="text" id="guestFirstName" name="first_name" required value="<?php echo htmlspecialchars($guestPrefillFirst); ?>" autocomplete="given-name" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()" onblur="this.value = this.value.toUpperCase()">
                    </div>
                    <div class="guest-field">
                        <label for="guestLastName">Last name <span>*</span></label>
                        <input type="text" id="guestLastName" name="last_name" required value="<?php echo htmlspecialchars($guestPrefillLast); ?>" autocomplete="family-name" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()" onblur="this.value = this.value.toUpperCase()">
                    </div>
                    <div class="guest-field">
                        <label for="guestEmail">Email <span>*</span></label>
                        <input type="email" id="guestEmail" name="email" required value="<?php echo htmlspecialchars($guestPrefillEmail); ?>" autocomplete="email" readonly>
                    </div>
                    <div class="guest-field">
                        <label for="guestMobileNumber">Mobile number <span>*</span></label>
                        <div class="guest-mobile-row">
                            <input type="tel" id="guestMobileNumber" name="mobile_number" required value="<?php echo htmlspecialchars($guestPrefillMobile); ?>" placeholder="Mobile number" autocomplete="tel">
                            <?php if (FIREBASE_GUEST_PHONE_AUTH_ENABLED): ?>
                                <button type="button" class="guest-phone-verify-btn" id="guestPhoneVerifyBtn" onclick="startGuestPhoneVerification()">Verify</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <p id="guestInfoError" class="guest-info-error" style="display:none;"></p>
                <p class="guest-info-note">Please make sure your contact information is correct. We'll use it to send your booking confirmation and any reminders to assist your booking completion.</p>
                <button type="submit" id="guestInfoContinueBtn" class="guest-info-submit">Continue to booking</button>
            </form>
        </div>
    </div>
    <div id="guestPhoneVerifyPopup" class="guest-phone-verify-modal" hidden aria-hidden="true">
        <div class="guest-phone-verify-content" role="dialog" aria-modal="true" aria-labelledby="guestPhoneVerifyTitle">
            <button type="button" class="guest-phone-verify-close" onclick="closeGuestPhoneVerification()" aria-label="Close">&times;</button>
            <h2 id="guestPhoneVerifyTitle">Verify mobile number</h2>
            <p id="guestPhoneVerifyMessage">Enter the 6-digit code sent by SMS.</p>
            <div id="guestPhoneRecaptcha"></div>
            <label for="guestPhoneVerificationCode">SMS verification code</label>
            <input type="text" id="guestPhoneVerificationCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="Enter code">
            <p id="guestPhoneVerifyError" class="guest-info-error" style="display:none;"></p>
            <button type="button" class="guest-info-submit" id="guestPhoneConfirmBtn" onclick="confirmGuestPhoneVerification()">Confirm verification</button>
        </div>
    </div>
    <?php endif; ?>

    <style>
        .guest-info-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 3000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .guest-info-modal.show {
            display: flex;
        }
        .guest-info-modal-content {
            background: #fff;
            width: 100%;
            max-width: 640px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 1.75rem;
            position: relative;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
            max-height: 90vh;
            overflow-y: auto;
        }
        .guest-info-modal-close {
            position: absolute;
            top: 0.85rem;
            right: 0.95rem;
            border: none;
            background: transparent;
            font-size: 1.6rem;
            color: #64748b;
            cursor: pointer;
            line-height: 1;
        }
        .guest-info-modal-content h2 {
            margin: 0 0 0.35rem 0;
            color: #0f172a;
            font-size: 1.45rem;
        }
        .guest-info-required {
            color: #dc2626;
            font-size: 0.85rem;
            margin: 0 0 1.25rem 0;
        }
        .guest-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem 1.1rem;
        }
        .guest-field {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .guest-field label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #334155;
        }
        .guest-field label span {
            color: #dc2626;
        }
        .guest-field input,
        .guest-field select {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 0.8rem 0.9rem;
            font-size: 0.95rem;
            color: #0f172a;
            background: #fff;
        }
        .guest-field input:focus,
        .guest-field select:focus {
            outline: none;
            border-color: #ff7a3d;
            box-shadow: 0 0 0 3px rgba(255, 122, 61, 0.15);
        }
        .guest-field input[readonly] {
            background: #f8fafc;
            cursor: not-allowed;
            color: #475569;
            border-color: #e2e8f0;
        }
        .guest-field input[readonly]:focus {
            border-color: #e2e8f0;
            box-shadow: none;
        }
        .guest-field input[readonly]::placeholder {
            color: #94a3b8;
        }
        .guest-mobile-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .guest-mobile-row input {
            min-width: 0;
            flex: 1;
        }
        .guest-phone-verify-btn,
        .guest-phone-verified {
            flex: 0 0 auto;
            white-space: nowrap;
        }
        .guest-phone-verify-btn {
            border: 1px solid #ff7a3d;
            border-radius: 8px;
            background: #fff7ed;
            color: #ea580c;
            padding: 0.8rem 0.75rem;
            font-weight: 700;
            cursor: pointer;
        }
        .guest-phone-verify-btn:hover {
            background: #ffedd5;
        }
        .guest-phone-verified {
            color: #047857;
            font-size: 0.82rem;
            font-weight: 700;
        }
        .guest-phone-verify-modal {
            position: fixed;
            inset: 0;
            z-index: 3100;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(15, 23, 42, 0.62);
        }
        .guest-phone-verify-modal[hidden] {
            display: none;
        }
        .guest-phone-verify-content {
            position: relative;
            width: min(100%, 400px);
            padding: 1.5rem;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.3);
        }
        .guest-phone-verify-content h2 {
            margin: 0 0 0.5rem;
            color: #0f172a;
        }
        .guest-phone-verify-content p {
            color: #64748b;
            line-height: 1.45;
        }
        .guest-phone-verify-content label {
            display: block;
            margin: 1rem 0 0.35rem;
            color: #334155;
            font-weight: 600;
        }
        .guest-phone-verify-content input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 0.8rem;
            font-size: 1rem;
        }
        .guest-phone-verify-close {
            position: absolute;
            top: 0.65rem;
            right: 0.75rem;
            border: 0;
            background: transparent;
            color: #64748b;
            font-size: 1.5rem;
            cursor: pointer;
        }
        .guest-info-note {
            margin: 1.1rem 0 1.25rem 0;
            color: #64748b;
            font-size: 0.86rem;
            line-height: 1.45;
        }
        .guest-info-error {
            color: #b91c1c;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 0.65rem 0.8rem;
            font-size: 0.88rem;
            margin: 0.85rem 0 0;
        }
        .guest-info-submit {
            width: 100%;
            border: none;
            border-radius: 999px;
            background: #ff7a3d;
            color: #fff;
            font-weight: 700;
            font-size: 1rem;
            padding: 0.9rem 1.2rem;
            cursor: pointer;
        }
        .guest-info-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        @media (max-width: 640px) {
            .guest-info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <?php if (FIREBASE_GUEST_PHONE_AUTH_ENABLED): ?>
        <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
        <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-auth-compat.js"></script>
        <script>
            firebase.initializeApp(<?php echo json_encode([
                'apiKey' => FIREBASE_API_KEY,
                'authDomain' => FIREBASE_AUTH_DOMAIN,
                'projectId' => FIREBASE_PROJECT_ID,
                'storageBucket' => FIREBASE_STORAGE_BUCKET,
                'messagingSenderId' => FIREBASE_MESSAGING_SENDER_ID,
                'appId' => FIREBASE_APP_ID,
                'measurementId' => FIREBASE_MEASUREMENT_ID
            ], JSON_UNESCAPED_SLASHES); ?>);
        </script>
    <?php endif; ?>
    <script>
        let guestPhoneConfirmationResult = null;
        let guestPhoneRecaptchaVerifier = null;

        function normalizeGuestPhoneNumber(value) {
            const digits = String(value || '').replace(/\D/g, '');
            if (digits.startsWith('63')) return '+' + digits;
            if (digits.startsWith('0')) return '+63' + digits.substring(1);
            return '+' + digits;
        }

        function openGuestPhoneVerification() {
            const modal = document.getElementById('guestPhoneVerifyPopup');
            if (!modal) return;
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            document.getElementById('guestPhoneVerificationCode')?.focus();
        }

        function closeGuestPhoneVerification() {
            const modal = document.getElementById('guestPhoneVerifyPopup');
            if (!modal) return;
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
        }

        function setGuestPhoneVerificationError(text) {
            const error = document.getElementById('guestPhoneVerifyError');
            if (error) {
                error.textContent = text;
                error.style.display = text ? 'block' : 'none';
            }
        }

        async function startGuestPhoneVerification() {
            const input = document.getElementById('guestMobileNumber');
            const button = document.getElementById('guestPhoneVerifyBtn');
            const phoneNumber = normalizeGuestPhoneNumber(input?.value);
            if (!/^\+63\d{10}$/.test(phoneNumber)) {
                const error = document.getElementById('guestInfoError');
                if (error) {
                    error.textContent = 'Enter a valid Philippine mobile number before verifying.';
                    error.style.display = 'block';
                }
                return;
            }

            if (button) button.disabled = true;
            setGuestPhoneVerificationError('Sending SMS code...');
            openGuestPhoneVerification();
            try {
                if (!guestPhoneRecaptchaVerifier) {
                    guestPhoneRecaptchaVerifier = new firebase.auth.RecaptchaVerifier('guestPhoneRecaptcha', { size: 'normal' });
                    await guestPhoneRecaptchaVerifier.render();
                }
                guestPhoneConfirmationResult = await firebase.auth().signInWithPhoneNumber(phoneNumber, guestPhoneRecaptchaVerifier);
                setGuestPhoneVerificationError('SMS code sent. Enter it below.');
            } catch (error) {
                setGuestPhoneVerificationError(error.message || 'Unable to send the SMS code.');
                if (guestPhoneRecaptchaVerifier) {
                    guestPhoneRecaptchaVerifier.clear();
                    guestPhoneRecaptchaVerifier = null;
                }
            } finally {
                if (button) button.disabled = false;
            }
        }

        async function confirmGuestPhoneVerification() {
            const code = document.getElementById('guestPhoneVerificationCode')?.value.trim();
            const confirmButton = document.getElementById('guestPhoneConfirmBtn');
            if (!guestPhoneConfirmationResult || !/^\d{6}$/.test(code)) {
                setGuestPhoneVerificationError('Enter the 6-digit SMS verification code.');
                return;
            }

            if (confirmButton) confirmButton.disabled = true;
            setGuestPhoneVerificationError('Verifying mobile number...');
            try {
                const result = await guestPhoneConfirmationResult.confirm(code);
                const idToken = await result.user.getIdToken();
                const response = await fetch('<?php echo SITE_URL; ?>api/firebase_verify_guest_phone.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ id_token: idToken })
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.message || 'Unable to verify mobile number.');

                const input = document.getElementById('guestMobileNumber');
                input.value = data.phone_number || input.value;
                input.readOnly = true;
                input.insertAdjacentHTML('afterend', '<span class="guest-phone-verified" id="guestPhoneVerified"><i class="fas fa-check-circle"></i> Verified</span>');
                document.getElementById('guestPhoneVerifyBtn')?.remove();
                closeGuestPhoneVerification();
                setGuestPhoneVerificationError('');
            } catch (error) {
                setGuestPhoneVerificationError(error.message || 'Unable to verify mobile number.');
                if (confirmButton) confirmButton.disabled = false;
            }
        }

        function openLoginModal() {
            const modal = document.getElementById('loginPopup');
            if (modal) {
                modal.hidden = false;
                modal.classList.add('show');
                modal.setAttribute('aria-hidden', 'false');
            }
        }

        function closeLoginModal() {
            const modal = document.getElementById('loginPopup');
            if (modal) {
                modal.classList.remove('show');
                modal.setAttribute('aria-hidden', 'true');
                modal.hidden = true;
            }
        }

        function openGuestInfoModal() {
            const modal = document.getElementById('guestInfoPopup');
            if (!modal) return false;
            modal.hidden = false;
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            const firstInput = document.getElementById('guestFirstName');
            if (firstInput) setTimeout(() => firstInput.focus(), 50);
            return false;
        }

        function closeGuestInfoModal() {
            const modal = document.getElementById('guestInfoPopup');
            if (!modal) return;
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            modal.hidden = true;
        }

        function handleBookNowClick(event) {
            event.preventDefault();
            openGuestInfoModal();
            return false;
        }

        function submitGuestInfoForm(event) {
            event.preventDefault();
            const errorEl = document.getElementById('guestInfoError');
            const submitBtn = document.getElementById('guestInfoContinueBtn');
            
            // Get input values and force uppercase
            const firstName = (document.getElementById('guestFirstName')?.value || '').trim().toUpperCase();
            const lastName = (document.getElementById('guestLastName')?.value || '').trim().toUpperCase();
            
            const payload = {
                first_name: firstName,
                last_name: lastName,
                email: (document.getElementById('guestEmail')?.value || '').trim(),
                mobile_number: (document.getElementById('guestMobileNumber')?.value || '').trim()
            };

            if (errorEl) {
                errorEl.style.display = 'none';
                errorEl.textContent = '';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving...';
            }

            fetch('<?php echo SITE_URL; ?>api/save_guest_info.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
            .then(async (response) => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to save guest information.');
                }
                return data;
            })
            .then((data) => {
                window.hasGuestInfo = true;
                const onBookingPage = /booking\.php/i.test(window.location.pathname + window.location.href);
                if (onBookingPage) {
                    closeGuestInfoModal();
                    return;
                }
                window.location.href = data.redirect || '<?php echo SITE_URL; ?>booking.php';
            })
            .catch((error) => {
                if (errorEl) {
                    errorEl.textContent = error.message;
                    errorEl.style.display = 'block';
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Continue to booking';
                }
            });

            return false;
        }

        document.addEventListener('click', function(event) {
            const modal = document.getElementById('loginPopup');
            if (modal && modal.classList.contains('show') && event.target === modal) {
                closeLoginModal();
            }
            const guestModal = document.getElementById('guestInfoPopup');
            if (guestModal && guestModal.classList.contains('show') && event.target === guestModal) {
                closeGuestInfoModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeLoginModal();
                closeGuestInfoModal();
                closeUserDropdown();
            }
        });

        // User dropdown functionality
        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            if (dropdown) {
                dropdown.classList.toggle('show');
            }
        }

        function closeUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            if (dropdown) {
                dropdown.classList.remove('show');
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const avatarContainer = document.querySelector('.user-avatar-container');
            
            if (dropdown && avatarContainer && !avatarContainer.contains(event.target)) {
                closeUserDropdown();
            }
        });

            </script>

    <!-- Flash Messages -->
    <?php
        $reviewStatus = $_GET['review_status'] ?? '';
        $reviewNotification = null;
        if ($reviewStatus === 'created') {
            $reviewNotification = 'Your Review is Successfully submitted';
        } elseif ($reviewStatus === 'updated') {
            $reviewNotification = 'Your Edited Review is Successfully submitted';
        }
    ?>
    <?php if ($reviewNotification): ?>
        <div class="review-success-popup" role="status" aria-live="polite">
            <span class="review-success-icon" aria-hidden="true"><i class="fas fa-check"></i></span>
            <span class="review-success-message"><?php echo htmlspecialchars($reviewNotification, ENT_QUOTES, 'UTF-8'); ?></span>
            <button type="button" class="review-success-close" aria-label="Close notification">&times;</button>
        </div>
        <script>
            document.querySelector('.review-success-close')?.addEventListener('click', function() {
                this.closest('.review-success-popup')?.remove();
            });
            window.setTimeout(function() {
                document.querySelector('.review-success-popup')?.remove();
            }, 5000);
        </script>
    <?php endif; ?>

    <?php if (isset($_SESSION['error']) || isset($_SESSION['login_error'])): ?>
        <div class="alert alert-error">
            <?php 
                echo isset($_SESSION['error']) ? $_SESSION['error'] : $_SESSION['login_error']; 
                unset($_SESSION['error'], $_SESSION['login_error']);
            ?>
        </div>
    <?php endif; ?>

    <main>
        <?php if (isset($showHero) && $showHero): ?>
            <!-- Hero Section -->
            <section id="home" class="hero">
                <div class="hero-content">
                    <h1>Pool Resort</h1>
                    <p>great for families, barkada hangouts and reunions.</p>
                    <button class="btn-primary" onclick="scrollToBooking()">Start Booking</button>
                </div>
            </section>
        <?php endif; ?>
