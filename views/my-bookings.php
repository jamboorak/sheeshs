<?php
require_once __DIR__ . '/../config/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/guest_info_schema.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // No session - redirect to Google sign-in
    header("Location: google-auth.php?action=login");
    exit();
}

$userId = $_SESSION['user_id'];

require_once __DIR__ . '/../includes/header.php';

// Fetch user's reservations with current user + lead guest details
$reservations = [];
$reservationSql = "SELECT r.*, 
    " . guestDisplayNameSql('gi', 'u') . " as user_name,
    COALESCE(NULLIF(gi.email, ''), u.email, '') as user_email,
    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(gi.mobile_country_code, ''), COALESCE(gi.mobile_number, ''))), ''), u.phone, '') as user_phone
FROM reservations r 
LEFT JOIN users u ON r.user_id = u.id
LEFT JOIN guest_info gi ON r.guest_info_id = gi.id
WHERE r.user_id = ? 
ORDER BY r.created_at DESC";

$db = new Database();
$conn = $db->getConnection();
ensureGuestInfoSchema($conn);
$stmt = $conn->prepare($reservationSql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$reservations = $result->fetch_all(MYSQLI_ASSOC);

// Fetch reservation items for each reservation, including room/cottage image URLs
foreach ($reservations as &$reservation) {
    $itemsSql = "SELECT ri.*, COALESCE(r.image_url, c.image_url) AS image_url
        FROM reservation_items ri
        LEFT JOIN rooms r ON ri.item_type = 'room' AND ri.item_id = r.id
        LEFT JOIN cottages c ON ri.item_type = 'cottage' AND ri.item_id = c.id
        WHERE ri.reservation_id = ?";
    $itemStmt = $conn->prepare($itemsSql);
    $itemStmt->bind_param("i", $reservation['id']);
    $itemStmt->execute();
    $itemsResult = $itemStmt->get_result();
    $reservation['items'] = $itemsResult->fetch_all(MYSQLI_ASSOC);
}

// Separate reservations into current and past based on tour end time
$currentReservations = [];
$pastReservations = [];
$now = new DateTime();

foreach ($reservations as $reservation) {
    $checkInDate = new DateTime($reservation['check_in']);
    
    // Calculate actual end datetime based on tour type
    if ($reservation['tour_type'] === 'day') {
        // Day tour: 8 AM to 5 PM same day
        $endDateTime = clone $checkInDate;
        $endDateTime->setTime(17, 0, 0); // 5:00 PM
    } else {
        // Night tour: 8 PM to 5 AM next day
        $endDateTime = clone $checkInDate;
        $endDateTime->modify('+1 day');
        $endDateTime->setTime(5, 0, 0); // 5:00 AM
    }
    
    // Current reservations: end datetime is in the future
    // Past reservations: end datetime has passed (regardless of status)
    if ($endDateTime > $now) {
        $arrivalDateTime = clone $checkInDate;
        $arrivalDateTime->setTime($reservation['tour_type'] === 'night' ? 20 : 8, 0, 0);
        $cancellationDeadline = clone $arrivalDateTime;
        $cancellationDeadline->modify('-24 hours');
        $reservation['can_cancel'] = in_array($reservation['status'], ['pending', 'approved'], true)
            && $now < $cancellationDeadline;
        $currentReservations[] = $reservation;
    } else {
        $pastReservations[] = $reservation;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Reservation details - Villa Soledad</title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .booking-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            max-width: 1100px;
            margin: 2rem auto;
            gap: 1.5rem;
            padding: 0 1rem;
        }

        .tab-sidebar {
            width: 100%;
            max-width: 740px;
            background: transparent;
            border-radius: 20px;
            box-shadow: none;
            padding: 0;
            height: auto;
            position: static;
            top: auto;
            margin: 0 auto 0.75rem;
            text-align: center;
        }

        .tab-title {
            font-size: 1rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 0.75rem;
            padding-bottom: 0;
            border-bottom: none;
        }

        .tab-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            overflow-x: hidden;
        }

        .tab-btn {
            flex: 0 1 auto;
            min-width: 120px;
            padding: 0.6rem 1rem;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 999px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            font-size: 0.95rem;
            font-weight: 600;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            box-shadow: 0 1px 6px rgba(15, 23, 42, 0.06);
        }

        .tab-btn:hover {
            border-color: #c7d2fe;
            color: #3730a3;
            transform: translateY(-0.5px);
        }

        .tab-btn.active {
            background: #ff7a3d;
            color: white;
            border-color: #ff7a3d;
            box-shadow: 0 8px 18px rgba(255, 122, 61, 0.2);
        }

        .tab-btn i {
            margin-right: 0.35rem;
            width: auto;
        }

        .tab-content {
            flex: 1;
        }

        .reservation-details {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        .reservation-header {
            background: #ffffff;
            color: #1f2937;
            padding: 2rem 2rem 1.5rem;
            text-align: left;
            border-bottom: 3px solid #f97316;
        }

        .reservation-header h1 {
            margin: 0 0 1rem;
            color: #1e3a8a;
            font-size: clamp(1.75rem, 4vw, 2.5rem);
            font-weight: 700;
            letter-spacing: 0;
        }

        .reservation-status {
            display: flex;
            width: 100%;
            justify-content: center;
            margin-bottom: 1rem;
            text-align: center;
        }

        .reservation-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 0.75rem 1.5rem;
            margin-top: 1.25rem;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-height: 32px;
            color: #374151;
            font-size: 0.95rem;
        }

        .info-item i {
            width: 20px;
            text-align: center;
            color: #f97316;
        }

        .reservation-content {
            padding: 2rem;
        }

        .section-title {
            font-size: 1.5rem;
            color: #1e3a8a;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f97316;
        }

        .chosen-items {
            display: grid;
            grid-template-columns: repeat(2, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .item-panel {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .item-panel .section-title {
            margin: 0;
            font-size: 1.25rem;
            color: #333;
            border-bottom: none;
            padding-bottom: 0;
        }

        .item-card {
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            background: #ffffff;
        }

        .item-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .item-image {
            width: 100%;
            height: 180px;
            max-height: 180px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
        }

        .item-details {
            padding: 1.5rem;
            background: #ffffff;
        }

        .item-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.5rem;
        }

        .item-meta {
            color: #334155;
            margin-bottom: 0.35rem;
            font-weight: 500;
        }

        .item-price {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1d4ed8;
            margin-bottom: 1rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .status-pending {
            background: #f59e0b;
            color: white;
        }

        .status-approved {
            background: #10b981;
            color: white;
        }

        .status-cancelled {
            background: #ef4444;
            color: white;
        }

        .guests-section {
            background: #eef2ff;
            border-radius: 18px;
            padding: 1.1rem 1.25rem;
            margin-bottom: 1.75rem;
        }

        .guests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.9rem;
            margin-bottom: 0;
        }

        .guest-item {
            text-align: center;
            padding: 0.9rem 0.9rem;
            background: white;
            border-radius: 16px;
            border: 1px solid #dbeafe;
            min-height: 108px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: 0 10px 24px rgba(99, 102, 241, 0.08);
        }

        .guest-number {
            font-size: 1.9rem;
            font-weight: 700;
            color: #4f46e5;
            margin-bottom: 0.35rem;
        }

        .guest-label {
            color: #475569;
            font-size: 0.85rem;
            letter-spacing: 0.01em;
        }

        .total-section {
            background: #1e3a8a;
            color: white;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            text-align: center;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: 0 16px 40px rgba(102, 126, 234, 0.14);
        }

        .total-label {
            font-size: 1rem;
            margin-bottom: 0.4rem;
            opacity: 0.95;
            letter-spacing: 0.05em;
        }

        .total-amount {
            font-size: 2.2rem;
            font-weight: 700;
            margin: 0;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #666;
        }

        .empty-state i {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 1rem;
        }

        .empty-state h2 {
            color: #333;
            margin-bottom: 1rem;
        }

        .btn-primary {
            background: #4169E1;
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
            transition: background-color 0.3s ease;
        }

        .btn-primary:hover {
            background: #5a6fd8;
        }

        @media (max-width: 768px) {
            .booking-container {
                flex-direction: column;
                align-items: center;
            }

            .tab-sidebar {
                width: 100%;
                max-width: 100%;
                position: relative;
                top: 0;
            }

            .reservation-details {
                margin: 1rem 0;
            }

            .reservation-header h1 {
                font-size: 2rem;
            }

            .chosen-items {
                grid-template-columns: 1fr;
            }

            .guests-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="booking-container">
        <div class="tab-sidebar">
            <h3 class="tab-title">Reservation Type</h3>
            <div class="tab-buttons">
                <button class="tab-btn active" onclick="showTab('current')">
                    <i class="fas fa-calendar-check"></i>
                    Current
                    <span style="background: #ffffff; color: #334155; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">
                        <?php echo count($currentReservations); ?>
                    </span>
                </button>
                <button class="tab-btn" onclick="showTab('past')">
                    <i class="fas fa-history"></i>
                    Past
                    <span style="background: #f3f4f6; color: #475569; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">
                        <?php echo count($pastReservations); ?>
                    </span>
                </button>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="tab-content">
            <!-- Current Reservations Tab -->
            <div id="current-tab" class="tab-panel active">
                <?php if (empty($currentReservations)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-xmark"></i>
                        <h2>No Current Reservations</h2>
                        <p>You don't have any upcoming or active reservations.</p>
                        <a href="<?php echo SITE_URL; ?>booking.php" class="btn-primary" onclick="return (typeof handleBookNowClick === 'function') ? handleBookNowClick(event) : true;">Book Now</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($currentReservations as $reservation): ?>
                        <div class="reservation-details">
                            <div class="reservation-header">
                                <h1>Your Current Reservation</h1>
                                <div class="reservation-status">
                                    <span class="status-badge status-<?php echo strtolower($reservation['status']); ?>">
                                        <?php echo ucfirst($reservation['status']); ?>
                                    </span>
                                </div>
                                <div class="reservation-info">
                                    <div class="info-item">
                                        <i class="fas fa-user"></i>
                                        <span><?php echo htmlspecialchars($reservation['user_name'] ?? 'Guest'); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-phone"></i>
                                        <span><?php echo htmlspecialchars($reservation['user_phone'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-calendar-check"></i>
                                        <span>Check-in: <?php echo date('F d, Y', strtotime($reservation['check_in'])); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-calendar-times"></i>
                                        <span>Check-out: <?php 
                                            // For Day tour, check-out is same day; for Night tour, check-out is next day
                                            if ($reservation['tour_type'] === 'day') {
                                                echo date('F d, Y', strtotime($reservation['check_in']));
                                            } else {
                                                echo date('F d, Y', strtotime($reservation['check_in'] . ' +1 day'));
                                            }
                                        ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-clock"></i>
                                        <span><?php echo ucfirst($reservation['tour_type']); ?> Tour</span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-sign-in-alt"></i>
                                        <span><?php 
                                            // Show proper check-in time based on tour type
                                            if ($reservation['tour_type'] === 'day') {
                                                echo '8:00 AM';
                                            } else {
                                                echo '8:00 PM';
                                            }
                                        ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-sign-out-alt"></i>
                                        <span><?php 
                                            // Show proper check-out time based on tour type
                                            if ($reservation['tour_type'] === 'day') {
                                                echo '5:00 PM';
                                            } else {
                                                echo '5:00 AM';
                                            }
                                        ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="reservation-content">
                                <div class="chosen-items">
                                    <div class="item-panel">
                                        <h2 class="section-title">Choosen Room</h2>
                                        <?php 
                                        $roomItems = array_filter($reservation['items'], function($item) {
                                            return $item['item_type'] === 'room';
                                        });
                                        
                                        if (!empty($roomItems)):
                                            foreach ($roomItems as $item): ?>
                                                <div class="item-card">
                                                    <div class="item-image">
                                                        <?php if (!empty($item['image_url'])): ?>
    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>">
<?php else: ?>
    <i class="fas fa-bed"></i>
<?php endif; ?>
                                                    </div>
                                                    <div class="item-details">
                                                        <h3 class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                                                        <p class="item-meta">Good for <?php echo $item['capacity']; ?> guests</p>
                                                        <p class="item-price">₱<?php echo number_format($item['price'], 2); ?></p>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p>No rooms selected.</p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="item-panel">
                                        <h2 class="section-title">Choosen Cottage</h2>
                                        <?php 
                                        $cottageItems = array_filter($reservation['items'], function($item) {
                                            return $item['item_type'] === 'cottage';
                                        });
                                        
                                        if (!empty($cottageItems)):
                                            foreach ($cottageItems as $item): ?>
                                                <div class="item-card">
                                                    <div class="item-image">
                                                        <?php if (!empty($item['image_url'])): ?>
    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>">
<?php else: ?>
    <i class="fas fa-home"></i>
<?php endif; ?>
                                                    </div>
                                                    <div class="item-details">
                                                        <h3 class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                                                        <p class="item-meta">Good for <?php echo $item['capacity']; ?> guests</p>
                                                        <p class="item-price">₱<?php echo number_format($item['price'], 2); ?></p>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p>No cottages selected.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <h2 class="section-title">Total Guests</h2>
                                <div class="guests-section">
                                    <div class="guests-grid">
                                        <div class="guest-item">
                                            <div class="guest-number"><?php echo $reservation['adults']; ?></div>
                                            <div class="guest-label">Adults</div>
                                        </div>
                                        <div class="guest-item">
                                            <div class="guest-number"><?php echo $reservation['children']; ?></div>
                                            <div class="guest-label">Kids</div>
                                        </div>

                                        <div class="guest-item">
                                            <div class="guest-number"><?php echo $reservation['adults'] + $reservation['children'] + $reservation['seniors']; ?></div>
                                            <div class="guest-label">Total Guests</div>
                                        </div>
                                    </div>
                                </div>

                                <h2 class="section-title">Total Entrance Fee</h2>
                                <div class="total-section">
                                    <div class="total-label">Total Amount</div>
                                    <div class="total-amount">₱<?php echo number_format($reservation['total_amount'], 2); ?></div>
                                </div>

                                <?php if (!empty($reservation['can_cancel'])): ?>
                                <div style="margin-top: 1.5rem; text-align: center;">
                                    <button type="button" onclick="openCancellationModal(<?php echo $reservation['id']; ?>)" style="padding: 1rem 2rem; background: #ef4444; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer;">
                                        <i class="fas fa-times-circle"></i> Cancel Reservation
                                    </button>
                                </div>
                                <?php elseif (in_array($reservation['status'], ['pending', 'approved'], true)): ?>
                                <div style="margin-top: 1.5rem; text-align: center; padding: 1rem; background: #fef3c7; border-radius: 8px;">
                                    <span style="color: #92400e; font-weight: 600;"><i class="fas fa-clock"></i> The cancellation period has ended (less than 24 hours before arrival)</span>
                                </div>
                                <?php elseif ($reservation['status'] === 'cancelled'): ?>
                                <div style="margin-top: 1.5rem; text-align: center; padding: 1rem; background: #fee2e2; border-radius: 8px;">
                                    <span style="color: #dc2626; font-weight: 600;"><i class="fas fa-ban"></i> This reservation has been cancelled</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Past Reservations Tab -->
            <div id="past-tab" class="tab-panel">
                <?php if (empty($pastReservations)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h2>No Past Reservations</h2>
                        <p>You don't have any past reservations yet.</p>
                        <a href="<?php echo SITE_URL; ?>booking.php" class="btn-primary" onclick="return (typeof handleBookNowClick === 'function') ? handleBookNowClick(event) : true;">Book Now</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($pastReservations as $reservation): ?>
                        <div class="reservation-details">
                            <div class="reservation-header">
                                <h1>Your Past Reservation</h1>
                                <div class="reservation-status">
                                    <span class="status-badge status-<?php echo strtolower($reservation['status']); ?>">
                                        <?php echo ucfirst($reservation['status']); ?>
                                    </span>
                                </div>
                                <div class="reservation-info">
                                    <div class="info-item">
                                        <i class="fas fa-user"></i>
                                        <span><?php echo htmlspecialchars($reservation['user_name'] ?? 'Guest'); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-phone"></i>
                                        <span><?php echo htmlspecialchars($reservation['user_phone'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-calendar-check"></i>
                                        <span>Check-in: <?php echo date('F d, Y', strtotime($reservation['check_in'])); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-calendar-times"></i>
                                        <span>Check-out: <?php 
                                            // For Day tour, check-out is same day; for Night tour, check-out is next day
                                            if ($reservation['tour_type'] === 'day') {
                                                echo date('F d, Y', strtotime($reservation['check_in']));
                                            } else {
                                                echo date('F d, Y', strtotime($reservation['check_in'] . ' +1 day'));
                                            }
                                        ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-clock"></i>
                                        <span><?php echo ucfirst($reservation['tour_type']); ?> Tour</span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-sign-in-alt"></i>
                                        <span><?php 
                                            // Show proper check-in time based on tour type
                                            if ($reservation['tour_type'] === 'day') {
                                                echo '8:00 AM';
                                            } else {
                                                echo '8:00 PM';
                                            }
                                        ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-sign-out-alt"></i>
                                        <span><?php 
                                            // Show proper check-out time based on tour type
                                            if ($reservation['tour_type'] === 'day') {
                                                echo '5:00 PM';
                                            } else {
                                                echo '5:00 AM';
                                            }
                                        ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="reservation-content">
                                <div class="chosen-items">
                                    <div class="item-panel">
                                        <h2 class="section-title">Choosen Room</h2>
                                        <?php 
                                        $roomItems = array_filter($reservation['items'], function($item) {
                                            return $item['item_type'] === 'room';
                                        });
                                        
                                        if (!empty($roomItems)):
                                            foreach ($roomItems as $item): ?>
                                                <div class="item-card">
                                                    <div class="item-image">
                                                        <?php if (!empty($item['image_url'])): ?>
    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>">
<?php else: ?>
    <i class="fas fa-bed"></i>
<?php endif; ?>
                                                    </div>
                                                    <div class="item-details">
                                                        <h3 class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                                                        <p class="item-meta">Good for <?php echo $item['capacity']; ?> guests</p>
                                                        <p class="item-price">₱<?php echo number_format($item['price'], 2); ?></p>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p>No rooms selected.</p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="item-panel">
                                        <h2 class="section-title">Choosen Cottage</h2>
                                        <?php 
                                        $cottageItems = array_filter($reservation['items'], function($item) {
                                            return $item['item_type'] === 'cottage';
                                        });
                                        
                                        if (!empty($cottageItems)):
                                            foreach ($cottageItems as $item): ?>
                                                <div class="item-card">
                                                    <div class="item-image">
                                                        <?php if (!empty($item['image_url'])): ?>
    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>">
<?php else: ?>
    <i class="fas fa-home"></i>
<?php endif; ?>
                                                    </div>
                                                    <div class="item-details">
                                                        <h3 class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                                                        <p class="item-meta">Good for <?php echo $item['capacity']; ?> guests</p>
                                                        <p class="item-price">₱<?php echo number_format($item['price'], 2); ?></p>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p>No cottages selected.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <h2 class="section-title">Total Guests</h2>
                                <div class="guests-section">
                                    <div class="guests-grid">
                                        <div class="guest-item">
                                            <div class="guest-number"><?php echo $reservation['adults']; ?></div>
                                            <div class="guest-label">Adults</div>
                                        </div>
                                        <div class="guest-item">
                                            <div class="guest-number"><?php echo $reservation['children']; ?></div>
                                            <div class="guest-label">Kids</div>
                                        </div>

                                        <div class="guest-item">
                                            <div class="guest-number"><?php echo $reservation['adults'] + $reservation['children'] + $reservation['seniors']; ?></div>
                                            <div class="guest-label">Total Guests</div>
                                        </div>
                                    </div>
                                </div>

                                <h2 class="section-title">Total Entrance Fee</h2>
                                <div class="total-section">
                                    <div class="total-label">Total Amount</div>
                                    <div class="total-amount">₱<?php echo number_format($reservation['total_amount'], 2); ?></div>
                                </div>

                                <?php if ($reservation['status'] === 'cancelled'): ?>
                                <div style="margin-top: 1.5rem; text-align: center; padding: 1rem; background: #fee2e2; border-radius: 8px;">
                                    <span style="color: #dc2626; font-weight: 600;"><i class="fas fa-ban"></i> This reservation was cancelled</span>
                                </div>
                                <?php else: ?>
                                <div style="margin-top: 1.5rem; text-align: center; padding: 1rem; background: #d1fae5; border-radius: 8px;">
                                    <span style="color: #059669; font-weight: 600;"><i class="fas fa-check-circle"></i> This reservation has been completed</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="cancellationModal" role="dialog" aria-modal="true" aria-labelledby="cancellationModalTitle" style="display:none; position:fixed; inset:0; z-index:10000; background:rgba(15,23,42,0.55); align-items:center; justify-content:center; padding:1rem;">
        <div style="width:min(100%, 500px); background:#fff; border-radius:12px; padding:2rem; box-shadow:0 20px 60px rgba(0,0,0,0.25);">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1rem;">
                <h2 id="cancellationModalTitle" style="margin:0; color:#102a43; font-size:1.4rem;">Cancel Reservation</h2>
                <button type="button" onclick="closeCancellationModal()" aria-label="Close" style="border:0; background:transparent; color:#64748b; font-size:1.6rem; cursor:pointer; line-height:1;">&times;</button>
            </div>
            <p style="margin:0 0 1rem; color:#475569;">Reservations can be cancelled at least 24 hours before arrival. Please provide a reason.</p>
            <label for="cancellationPreset" style="display:block; margin-bottom:0.4rem; color:#334155; font-weight:600;">Common reason</label>
            <select id="cancellationPreset" style="width:100%; padding:0.75rem; margin-bottom:1rem; border:1px solid #cbd5e1; border-radius:8px; font:inherit;">
                <option value="">Select a reason or write your own</option>
                <option value="My plans have changed.">My plans have changed</option>
                <option value="I need to reschedule my stay.">I need to reschedule my stay</option>
                <option value="I found another accommodation.">I found another accommodation</option>
                <option value="There is an issue with my travel schedule.">There is an issue with my travel schedule</option>
            </select>
            <label for="cancellationReason" style="display:block; margin-bottom:0.4rem; color:#334155; font-weight:600;">Cancellation reason <span style="color:#dc2626;">*</span></label>
            <textarea id="cancellationReason" rows="4" minlength="5" maxlength="500" placeholder="Tell us why you are cancelling..." style="width:100%; padding:0.75rem; border:1px solid #cbd5e1; border-radius:8px; resize:vertical; font:inherit;"></textarea>
            <p id="cancellationError" style="display:none; margin:0.5rem 0 0; color:#dc2626; font-size:0.9rem;"></p>
            <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.25rem;">
                <button type="button" onclick="closeCancellationModal()" style="padding:0.75rem 1rem; background:#e2e8f0; color:#334155; border:0; border-radius:8px; font-weight:600; cursor:pointer;">Keep Reservation</button>
                <button type="button" id="submitCancellationButton" onclick="submitCancellation()" style="padding:0.75rem 1rem; background:#dc2626; color:#fff; border:0; border-radius:8px; font-weight:600; cursor:pointer;">Confirm Cancellation</button>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tab panels
            const panels = document.querySelectorAll('.tab-panel');
            panels.forEach(panel => panel.classList.remove('active'));
            
            // Remove active class from all tab buttons
            const buttons = document.querySelectorAll('.tab-btn');
            buttons.forEach(button => button.classList.remove('active'));
            
            // Show selected tab panel
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }

        function viewItemDetails(itemId, itemType) {
            // Open a modal or navigate to details page
            alert('View details for ' + itemType + ' ID: ' + itemId);
        }

        function removeItem(itemId) {
            if (confirm('Are you sure you want to remove this item?')) {
                // Implement remove functionality
                alert('Item removed: ' + itemId);
                // You could refresh the page or make an API call here
            }
        }

        function editGuests(reservationId) {
            // Open a modal to edit guest counts
            alert('Edit guests for reservation: ' + reservationId);
            // You could implement a modal here to allow editing
        }

        let cancellationReservationId = null;

        function openCancellationModal(reservationId) {
            cancellationReservationId = reservationId;
            document.getElementById('cancellationReason').value = '';
            document.getElementById('cancellationPreset').value = '';
            document.getElementById('cancellationError').style.display = 'none';
            document.getElementById('cancellationModal').style.display = 'flex';
            document.getElementById('cancellationReason').focus();
        }

        function closeCancellationModal() {
            cancellationReservationId = null;
            document.getElementById('cancellationModal').style.display = 'none';
        }

        document.getElementById('cancellationPreset').addEventListener('change', function() {
            if (this.value) {
                document.getElementById('cancellationReason').value = this.value;
            }
        });

        function submitCancellation() {
            const reasonInput = document.getElementById('cancellationReason');
            const error = document.getElementById('cancellationError');
            const submitButton = document.getElementById('submitCancellationButton');
            const reason = reasonInput.value.trim();

            if (reason.length < 5) {
                error.textContent = 'Please provide a cancellation reason of at least 5 characters.';
                error.style.display = 'block';
                reasonInput.focus();
                return;
            }

            submitButton.disabled = true;
            submitButton.textContent = 'Cancelling...';

            fetch('<?php echo SITE_URL; ?>api/cancel_reservation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    reservation_id: cancellationReservationId,
                    cancellation_reason: reason
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeCancellationModal();
                    location.reload();
                } else {
                    error.textContent = data.message || 'Unable to cancel this reservation.';
                    error.style.display = 'block';
                    submitButton.disabled = false;
                    submitButton.textContent = 'Confirm Cancellation';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                const errorMessage = document.getElementById('cancellationError');
                errorMessage.textContent = 'An error occurred while cancelling your reservation. Please try again.';
                errorMessage.style.display = 'block';
                submitButton.disabled = false;
                submitButton.textContent = 'Confirm Cancellation';
            });
        }
    </script>
</body>
</html>





