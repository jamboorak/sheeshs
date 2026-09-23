<?php
require_once '../config/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/database.php';
require_once '../includes/guest_info_schema.php';
require_once '../includes/ActivityLogger.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$adminId = $_SESSION['admin_id'];
$adminName = $_SESSION['admin_name'] ?? 'Admin';

// Get database connection
$db = new Database();
$conn = $db->getConnection();
ensureGuestInfoSchema($conn);

$conn->query("CREATE TABLE IF NOT EXISTS property_gallery_images (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    property_type ENUM('room', 'cottage') NOT NULL,
    property_id INT(11) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT(11) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_property_gallery (property_type, property_id)
)");

function savePropertyGalleryImages($conn, $files, $propertyType, $propertyId) {
    if ($propertyId <= 0 || !isset($files['name']) || !is_array($files['name'])) {
        return;
    }

    $uploadDir = __DIR__ . '/uploads/gallery/' . $propertyType . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $nextOrder = 0;
    $orderStmt = $conn->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM property_gallery_images WHERE property_type = ? AND property_id = ?');
    if ($orderStmt) {
        $orderStmt->bind_param('si', $propertyType, $propertyId);
        $orderStmt->execute();
        $nextOrder = (int)($orderStmt->get_result()->fetch_assoc()['next_order'] ?? 0);
        $orderStmt->close();
    }

    $insertStmt = $conn->prepare('INSERT INTO property_gallery_images (property_type, property_id, image_path, sort_order) VALUES (?, ?, ?, ?)');
    if (!$insertStmt) {
        return;
    }

    foreach ($files['name'] as $index => $originalName) {
        $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        $tmpName = $files['tmp_name'][$index] ?? '';
        if ($error !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
            continue;
        }
        if (@getimagesize($tmpName) === false || ($files['size'][$index] ?? 0) > 8 * 1024 * 1024) {
            continue;
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            continue;
        }

        $fileName = $propertyType . '_' . $propertyId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        if (!move_uploaded_file($tmpName, $uploadDir . $fileName)) {
            continue;
        }

        $imagePath = SITE_URL . 'admin/uploads/gallery/' . $propertyType . '/' . $fileName;
        $insertStmt->bind_param('sisi', $propertyType, $propertyId, $imagePath, $nextOrder);
        $insertStmt->execute();
        $nextOrder++;
    }
    $insertStmt->close();
}

// Add reversible archive state to managed catalog tables.
foreach (['rooms', 'cottages', 'pools', 'foods'] as $archiveTable) {
    $tableExists = $conn->query("SHOW TABLES LIKE '{$archiveTable}'");
    if (!$tableExists || $tableExists->num_rows === 0) {
        continue;
    }
    $archiveColumn = $conn->query("SHOW COLUMNS FROM `{$archiveTable}` LIKE 'archived'");
    if ($archiveColumn && $archiveColumn->num_rows === 0) {
        $conn->query("ALTER TABLE `{$archiveTable}` ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0");
    }
}
ensureActivityLogSchema($conn);
$guestNameExpr = guestDisplayNameSql('gi', 'u');

$activityAction = trim($_GET['activity_action'] ?? '');
$activityUserId = (int)($_GET['activity_user_id'] ?? 0);
$activityFrom = trim($_GET['activity_from'] ?? '');
$activityTo = trim($_GET['activity_to'] ?? '');
$activityUsersResult = $conn->query("SELECT id, fullname, email FROM users WHERE role = 'user' ORDER BY fullname");
$activityUsers = $activityUsersResult ? $activityUsersResult->fetch_all(MYSQLI_ASSOC) : [];
$activityWhere = ['1 = 1'];
$activityParams = [];
$activityTypes = '';
if ($activityAction !== '') {
    $activityWhere[] = 'a.action = ?';
    $activityParams[] = $activityAction;
    $activityTypes .= 's';
}
if ($activityUserId > 0) {
    $activityWhere[] = 'a.user_id = ?';
    $activityParams[] = $activityUserId;
    $activityTypes .= 'i';
}
if ($activityFrom !== '') {
    $activityWhere[] = 'a.created_at >= ?';
    $activityParams[] = $activityFrom . ' 00:00:00';
    $activityTypes .= 's';
}
if ($activityTo !== '') {
    $activityWhere[] = 'a.created_at <= ?';
    $activityParams[] = $activityTo . ' 23:59:59';
    $activityTypes .= 's';
}
$hasActivityFilter = $activityAction !== '' || $activityUserId > 0 || $activityFrom !== '' || $activityTo !== '';
$activitySql = "SELECT a.*, COALESCE(u.fullname, u.email, 'Unknown user') AS user_name, u.email
                FROM user_activity_log a
                LEFT JOIN users u ON u.id = a.user_id
                WHERE " . implode(' AND ', $activityWhere) . "
                ORDER BY a.created_at DESC";
if (!$hasActivityFilter) {
    $activitySql .= " LIMIT 10";
}
$activityStmt = $conn->prepare($activitySql);
$activityLog = [];
if ($activityStmt) {
    if ($activityParams) {
        $activityStmt->bind_param($activityTypes, ...$activityParams);
    }
    $activityStmt->execute();
    $activityLog = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $activityStmt->close();
}

/**
 * Day tour: checkout = check-in day
 * Night tour: checkout = next day
 */
function normalizeTourCheckoutDate($checkIn, $checkOut, $tourType = 'day') {
    $checkIn = trim((string)$checkIn);
    if ($checkIn === '') {
        return $checkOut;
    }
    $tourType = strtolower(trim((string)$tourType)) === 'night' ? 'night' : 'day';
    if ($tourType === 'day') {
        return $checkIn;
    }
    return date('Y-m-d', strtotime($checkIn . ' +1 day'));
}

// Fetch statistics
$statsQueries = [
    'total_bookings' => "SELECT COUNT(*) as count FROM reservations",
    'total_revenue' => "SELECT COALESCE(SUM(total_amount),0) as total FROM reservations WHERE COALESCE(NULLIF(status, ''), 'pending') IN ('approved','completed')",
    'total_users' => "SELECT COUNT(*) as count FROM users WHERE role = 'user'",
    'total_reviews' => "SELECT COUNT(*) as count FROM reviews",
    'monthly_bookings' => "SELECT MONTH(check_in) as month, COUNT(*) as count FROM reservations WHERE YEAR(check_in) = YEAR(NOW()) GROUP BY MONTH(check_in) ORDER BY month"
];

$stats = [];
foreach ($statsQueries as $key => $query) {
    $result = $conn->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats[$key] = $row;
    }
}

// Fetch pending reservations for the manage reservations section
$recentReservationsSql = "SELECT r.id, r.check_in, r.check_out, r.adults, r.children, r.seniors, r.total_amount, COALESCE(NULLIF(r.status, ''), 'pending') AS status, COALESCE(r.tour_type, 'day') AS tour_type, {$guestNameExpr} as name, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(gi.mobile_country_code, ''), COALESCE(gi.mobile_number, ''))), ''), u.phone, '') AS guest_phone, GROUP_CONCAT(CONCAT(ri.item_name, ' ', ri.item_type) SEPARATOR ', ') as items FROM reservations r LEFT JOIN users u ON r.user_id = u.id LEFT JOIN guest_info gi ON r.guest_info_id = gi.id LEFT JOIN reservation_items ri ON r.id = ri.reservation_id WHERE COALESCE(NULLIF(r.status, ''), 'pending') = 'pending' GROUP BY r.id ORDER BY r.created_at DESC LIMIT 10";
$recentReservationsResult = $conn->query($recentReservationsSql);
$recentReservations = $recentReservationsResult ? $recentReservationsResult->fetch_all(MYSQLI_ASSOC) : [];
$notificationSql = "SELECT r.id, r.check_in, r.check_out, COALESCE(NULLIF(r.status, ''), 'pending') AS status,
                           {$guestNameExpr} AS name,
                           GROUP_CONCAT(CONCAT(ri.item_name, ' (', ri.item_type, ')') SEPARATOR ', ') AS items
                    FROM reservations r
                    LEFT JOIN users u ON r.user_id = u.id
                    LEFT JOIN guest_info gi ON r.guest_info_id = gi.id
                    LEFT JOIN reservation_items ri ON r.id = ri.reservation_id
                    WHERE COALESCE(NULLIF(r.status, ''), 'pending') = 'pending'
                    GROUP BY r.id
                    ORDER BY r.created_at DESC
                    LIMIT 5";
$notificationResult = $conn->query($notificationSql);
$reservationNotifications = $notificationResult ? $notificationResult->fetch_all(MYSQLI_ASSOC) : [];
$notificationCount = count($reservationNotifications);

// Fetch processed reservations for the booking records section
$bookingRecordsSql = "SELECT r.id, r.user_id, r.check_in, r.check_out, r.adults, r.children, r.seniors, r.total_amount, COALESCE(NULLIF(r.status, ''), 'pending') AS status, r.tour_type, {$guestNameExpr} AS guest_name, COALESCE(NULLIF(gi.email, ''), u.email, '') AS guest_email, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(gi.mobile_country_code, ''), COALESCE(gi.mobile_number, ''))), ''), u.phone, '') AS guest_phone, GROUP_CONCAT(CONCAT(ri.item_name, ' (', ri.item_type, ')') SEPARATOR ', ') AS items FROM reservations r LEFT JOIN users u ON r.user_id = u.id LEFT JOIN guest_info gi ON r.guest_info_id = gi.id LEFT JOIN reservation_items ri ON r.id = ri.reservation_id WHERE COALESCE(NULLIF(r.status, ''), 'pending') IN ('approved', 'cancelled', 'completed') GROUP BY r.id ORDER BY r.created_at DESC";
$bookingRecordsResult = $conn->query($bookingRecordsSql);
$bookingRecords = [];

if ($bookingRecordsResult) {
    while ($row = $bookingRecordsResult->fetch_assoc()) {
        $guestName = trim($row['guest_name'] ?? '') !== '' ? $row['guest_name'] : 'Guest';
        $guestEmail = trim($row['guest_email'] ?? '');
        $guestPhone = trim($row['guest_phone'] ?? '');
        $items = trim($row['items'] ?? '');
        $guestCount = (int)($row['adults'] ?? 0) + (int)($row['children'] ?? 0) + (int)($row['seniors'] ?? 0);
        $tourType = $row['tour_type'] ?? 'day';
        $checkIn = $row['check_in'];
        $checkOut = normalizeTourCheckoutDate($checkIn, $row['check_out'] ?? '', $tourType);

        $bookingRecords[] = [
            'id' => (int) $row['id'],
            'guestName' => $guestName,
            'contact' => $guestEmail !== '' ? $guestEmail : $guestPhone,
            'items' => $items !== '' ? $items : 'No items',
            'dates' => date('M d, Y', strtotime($checkIn)) . ' - ' . date('M d, Y', strtotime($checkOut)),
            'guests' => $guestCount > 0 ? $guestCount : 1,
            'amount' => number_format((float) $row['total_amount'], 2, '.', ''),
            'status' => $row['status'] ?? 'pending',
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'tourType' => $tourType
        ];
    }
}

$activeSection = isset($_GET['section']) && in_array($_GET['section'], ['dashboard', 'reservations', 'booking-records', 'rooms', 'cottages', 'pools', 'foods', 'facilities', 'pricing', 'scheduling', 'reports', 'reviews', 'concerns', 'system-data', 'monitoring', 'maintenance'], true) ? $_GET['section'] : 'dashboard';
$editingRoom = null;
$editingCottage = null;
$editingPool = null;
$errorMessage = '';
$cottageErrorMessage = '';
$poolErrorMessage = '';

$conn->query("CREATE TABLE IF NOT EXISTS pools (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    capacity INT(11) NOT NULL,
    features TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    image_url VARCHAR(255),
    available BOOLEAN DEFAULT TRUE
)");

// Create foods table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS foods (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) DEFAULT 0.00,
    image_url VARCHAR(255),
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    available BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
)");

foreach (['pools', 'foods'] as $archiveTable) {
    $archiveColumn = $conn->query("SHOW COLUMNS FROM `{$archiveTable}` LIKE 'archived'");
    if ($archiveColumn && $archiveColumn->num_rows === 0) {
        $conn->query("ALTER TABLE `{$archiveTable}` ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0");
    }
}

$foodCountResult = $conn->query("SELECT COUNT(*) AS count FROM foods");
$foodCount = $foodCountResult ? (int) $foodCountResult->fetch_assoc()['count'] : 0;
if ($foodCount === 0) {
    $seedFoods = [
        ['Lumpia Shanghai', 'Starters', 'Filipino-style spring rolls filled with ground pork.', 180.00, 'images/lumpiang shanghai.avif'],
        ['Crispy Pata', 'Starters', 'Crispy fried pork knuckle with special dipping sauce.', 350.00, 'images/crispy pata.jpg'],
        ['Cheese Sticks', 'Starters', 'Breaded cheese sticks served hot and crispy.', 140.00, 'images/cheese sticks.jpg'],
        ['Chicharon Bulaklak', 'Starters', 'Crunchy pork rinds with a savory, salty bite.', 180.00, 'images/chicharon bulaklak.jpg'],
        ['Kinilaw', 'Starters', 'Fresh seafood cured in citrus and spices.', 220.00, 'images/kinilaw.jpg'],
        ['Sizzling Sisig', 'Starters', 'A sizzling pork dish with onions, calamansi, and chili.', 240.00, 'images/sizzling sisig.jpg'],
        ['Creamy Beef', 'Main Course', 'Beef cooked in a rich, creamy sauce.', 410.00, 'images/creamy beef.jpg'],
        ['Beef Salpicao', 'Main Course', 'Garlicky beef strips sauteed with mushrooms and peppers.', 430.00, 'images/Beef Salpicao.jpg'],
        ['Bicol Express', 'Main Course', 'Coconut milk-based dish with pork and chilies.', 390.00, 'images/Bicol Express.jpg'],
        ['Chicken Sisig', 'Main Course', 'Sizzling chicken sisig with egg and calamansi.', 320.00, 'images/Chicken Sisig.jpg'],
        ['Chicken Tinola', 'Main Course', 'Chicken soup with ginger, green papaya, and malunggay.', 300.00, 'images/Chicken Tinola.jpg'],
        ['Mix Pansit Guisado', 'Main Course', 'Stir-fried noodles with assorted vegetables and meat.', 260.00, 'images/Mix Pansit Guisado.jpg'],
        ['Pork Binagoongan', 'Main Course', 'Pork simmered in shrimp paste and rich sauce.', 360.00, 'images/Pork Binagoongan.webp'],
        ['Pork Sisig', 'Main Course', 'A savory pork dish with onions and chili.', 330.00, 'images/Pork Sisig.webp'],
        ['Sweet & Sour Fish', 'Main Course', 'Crispy fish fillet in a tangy sweet-and-sour sauce.', 360.00, 'images/Sweet & Sour Fish.jpg'],
        ['Beef Sinigang', 'Soups', 'Sour tamarind-based soup with beef and vegetables.', 420.00, 'images/Beef Sinigang.webp'],
        ['Pork Sinigang', 'Soups', 'Classic tamarind soup with pork and vegetables.', 400.00, 'images/Pork Sinigang.jpg'],
        ['Nilagang Baka', 'Soups', 'Beef soup with corn, cabbage, and potatoes.', 380.00, 'images/Nilagang Baka.jpg'],
        ['Chicken Tinola', 'Soups', 'Light chicken soup with ginger and green vegetables.', 300.00, 'images/Chicken Tinola.jpg'],
        ['Beef Tapsilog', 'All Day Breakfast', 'Beef tapa with garlic rice and fried egg.', 190.00, 'images/Beef Tapsilog.jpg'],
        ['Hotsilog', 'All Day Breakfast', 'Hotdog, garlic rice, and fried egg.', 160.00, 'images/Hotsilog.jpeg'],
        ['Longsilog', 'All Day Breakfast', 'Longganisa with garlic rice and fried egg.', 170.00, 'images/Longsilog.jpg'],
        ['Tocilog', 'All Day Breakfast', 'Tocino served with garlic rice and fried egg.', 170.00, 'images/Tocilog.jpg'],
        ['Bangsilog', 'All Day Breakfast', 'Bangus silog with garlic rice and fried egg.', 180.00, 'images/Bangsilog.jpg'],
        ['Spamsilog', 'All Day Breakfast', 'Spam, garlic rice, and fried egg.', 160.00, 'images/Spamsilog.jpg'],
        ['Corned Beef Silog', 'All Day Breakfast', 'Corned beef served with garlic rice and fried egg.', 175.00, 'images/Corned Beef Silog.webp'],
        ['Pork Tapsilog', 'All Day Breakfast', 'Pork tapa with garlic rice and fried egg.', 180.00, 'images/Pork Tapsilog.jpg'],
        ['Latte Macchiato', 'Hot Beverages', 'Espresso with steamed milk, creamy and smooth.', 120.00, 'images/Latte Macchiato.avif'],
        ['Americano', 'Hot Beverages', 'Strong black coffee with hot water.', 100.00, 'images/Americano.jpeg'],
        ['Cappuccino', 'Hot Beverages', 'Espresso with steamed milk and foam.', 115.00, 'images/Cappuccino.webp'],
        ['Espresso', 'Hot Beverages', 'Short and bold espresso shot.', 90.00, 'images/Espresso.webp'],
        ['Earl Grey Tea', 'Hot Beverages', 'Black tea with bergamot fragrance.', 95.00, 'images/Earl Grey Tea.jpg'],
        ['English Breakfast Tea', 'Hot Beverages', 'Classic black tea blend for a warm cup.', 95.00, 'images/English Breakfast Tea.jpeg'],
        ['Green Tea & Lemon', 'Hot Beverages', 'Green tea with a bright citrus finish.', 95.00, 'images/Green Tea & Lemon.jpg'],
        ['Hot Chocolate', 'Hot Beverages', 'Rich hot chocolate served warm.', 110.00, 'images/Hot Chocolate.webp'],
        ['Bottled Water', 'Non-Alcoholic', 'Purified drinking water.', 30.00, 'images/Bottled Water.jpg'],
        ['Minute Maid', 'Non-Alcoholic', 'Packaged fruit juice drink.', 20.00, 'images/Minute Maid.webp'],
        ['Zesto Big', 'Non-Alcoholic', 'Juice drink in a larger serving.', 15.00, 'images/Zesto Big.jpg'],
        ['Coke Mismo', 'Non-Alcoholic', 'Small bottle of Coca-Cola.', 25.00, 'images/Coke Mismo.webp'],
        ['Sprite', 'Non-Alcoholic', 'Lemon-lime soda.', 25.00, 'images/Sprite.webp'],
        ['Royal', 'Non-Alcoholic', 'Orange-flavored soda.', 25.00, 'images/Royal.webp'],
        ['C2', 'Non-Alcoholic', 'Bottled iced tea.', 20.00, 'images/C2.webp'],
        ['Plain Rice', 'Sides', 'Steamed white rice.', 35.00, 'images/Plain Rice.jpg'],
        ['Garlic Rice', 'Sides', 'Fried rice infused with garlic.', 40.00, 'images/Garlic Rice.jpeg'],
        ['Fried Rice', 'Sides', 'Classic stir-fried rice with seasoning.', 50.00, 'images/Fried Rice.jpg'],
        ['French Fries', 'Sides', 'Deep-fried potato strips, crispy and lightly salted.', 80.00, 'images/French Fries.webp'],
        ['Buttered Vegetables', 'Vegetables', 'Mixed vegetables sautéed in butter.', 250.00, 'images/Buttered Vegetables.jpg'],
        ['Steamed Vegetables', 'Vegetables', 'Lightly steamed mixed vegetables.', 200.00, 'images/Steamed Vegetables.jpg'],
        ['Tortang Talong', 'Vegetables', 'Eggplant omelette, savory and soft.', 180.00, 'images/Tortang Talong.jpg'],
        ['Pinakbet', 'Vegetables', 'Mixed vegetables cooked with shrimp paste, traditional Filipino style.', 280.00, 'images/Pinakbet.jpg'],
        ['Chopsuey', 'Vegetables', 'Stir-fried mixed vegetables with meat and sauce.', 280.00, 'images/Chopsuey.jpg'],
        ['Grilled Liempo', 'Rice Meals', 'Grilled pork belly, smoky and flavorful.', 225.00, 'images/Grilled Liempo.jpg'],
        ['Chicken BBQ', 'Rice Meals', 'Grilled marinated chicken with a sweet-savory glaze.', 225.00, 'images/Chicken BBQ.webp'],
        ['Chicken Tenders', 'Rice Meals', 'Breaded and fried chicken strips, crispy outside and juicy inside.', 220.00, 'images/Chicken Tenders.jpg'],
        ['Baked Fish', 'Rice Meals', 'Oven-baked fish, tender and lightly seasoned.', 225.00, 'images/Baked Fish.jpg'],
        ['Fried Tilapia', 'Rice Meals', 'Deep-fried whole tilapia, crispy skin and soft meat.', 200.00, 'images/Fried Tilapia.webp'],
        ['OMG! Chicken', 'Rice Meals', 'House-style fried chicken, flavorful and crispy.', 225.00, 'images/OMG! Chicken.jpg'],
        ['Coffee Jelly', 'Dessert', 'Sweet gelatin dessert flavored with coffee.', 65.00, 'images/Coffee Jelly.jpg'],
        ['Buko Pandan', 'Dessert', 'Coconut and pandan-flavored dessert with cream and jelly.', 65.00, 'images/Buko Pandan.jpg'],
        ['Mango Sago', 'Dessert', 'Mango dessert with sago pearls and creamy milk base.', 65.00, 'images/Mango Sago.jpg'],
        ['Fruit Salad', 'Dessert', 'Mixed fruits in cream.', 95.00, 'images/Fruit Salad.webp'],
        ['Cuba Libre', 'Cocktails', 'Rum, Coke, fresh calamansi. A twist on the classic—smooth, citrusy, and refreshing.', 149.00, 'images/Cuba Libre.jpg'],
        ['Beer Citruz Fizz', 'Cocktails', 'Red Horse, Sprite, calamansi. A light, fizzy mix with the strength of beer and a citrus punch.', 159.00, 'images/Beer Citruz Fizz.png'],
        ['Golden Hour', 'Cocktails', 'Tequila & pineapple. Tropical, sweet, and smooth—like sunset in a glass.', 149.00, 'images/Golden Hour.jpg'],
        ['Island Mix', 'Cocktails', 'Vodka, tequila, gin, rum, calamansi, Coke. A Long Island-style drink—strong, bold, and balanced.', 159.00, 'images/Island Mix.jpg'],
        ['Gin Citrus Cooler', 'Cocktails', 'Gin, calamansi, soda. Light, crisp, and smooth with a Filipino twist.', 149.00, 'images/Gin Citrus Cooler.jpg'],
        ['Screw Driver', 'Cocktails', 'Orange juice, water, gin, lime juice, and triple sec.', 399.00, 'images/Screw Driver.jpg'],
        ['Red Alert', 'Cocktails', 'Strawberry, pineapple, water, brandy.', 399.00, 'images/Red Alert.jpg'],
        ['Blue Lagoon', 'Cocktails', 'Pineapple, water, gin, Sprite, blue curaçao.', 499.00, 'images/Blue Lagoon.jpg'],
        ['Weng Weng', 'Cocktails', 'Orange, pineapple, water, gin, brandy, tequila gold, grenadine.', 599.00, 'images/Weng Weng.jpg']
    ];

    $stmt = $conn->prepare('INSERT INTO foods (name, category, description, price, image_url, status, available) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if ($stmt) {
        $status = 'active';
        $available = 1;
        foreach ($seedFoods as $food) {
            $stmt->bind_param('sssdssi', $food[0], $food[1], $food[2], $food[3], $food[4], $status, $available);
            $stmt->execute();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['room_action'])) {
    $roomAction = $_POST['room_action'];

    if ($roomAction === 'save_room') {
        $roomId = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
        $roomName = trim($_POST['room_name'] ?? '');
        $description = trim($_POST['room_description'] ?? '');
        $capacity = max(1, (int)($_POST['room_capacity'] ?? 1));
        $pricePerNight = (float)($_POST['room_price'] ?? 0);
        $daySlots = max(1, (int)($_POST['room_day_slots'] ?? 1));
        $nightSlots = max(1, (int)($_POST['room_night_slots'] ?? 1));
        $available = isset($_POST['room_available']) ? 1 : 0;
        
        // Handle file upload
        $imageUrl = '';
        if (isset($_FILES['room_image']) && $_FILES['room_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $fileName = 'room_' . time() . '_' . basename($_FILES['room_image']['name']);
            $uploadPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['room_image']['tmp_name'], $uploadPath)) {
                $imageUrl = SITE_URL . 'admin/uploads/' . $fileName;
            }
        } elseif ($roomId > 0) {
            // Keep existing image if no new file uploaded during edit
            $result = $conn->query("SELECT image_url FROM rooms WHERE id = $roomId");
            if ($result) {
                $row = $result->fetch_assoc();
                $imageUrl = $row['image_url'] ?? '';
            }
        }

        if ($roomName === '') {
            $errorMessage = 'Room name is required.';
        } else {
            $stmt = $roomId > 0
                ? $conn->prepare('UPDATE rooms SET name = ?, description = ?, capacity = ?, price_per_night = ?, image_url = ?, available = ?, day_slots = ?, night_slots = ? WHERE id = ?')
                : $conn->prepare('INSERT INTO rooms (name, description, capacity, price_per_night, image_url, available, day_slots, night_slots) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

            if ($stmt) {
                if ($roomId > 0) {
                    $stmt->bind_param('ssidsiiii', $roomName, $description, $capacity, $pricePerNight, $imageUrl, $available, $daySlots, $nightSlots, $roomId);
                } else {
                    $stmt->bind_param('ssidsiii', $roomName, $description, $capacity, $pricePerNight, $imageUrl, $available, $daySlots, $nightSlots);
                }

                if ($stmt->execute()) {
                    $savedRoomId = $roomId > 0 ? $roomId : $conn->insert_id;
                    savePropertyGalleryImages($conn, $_FILES['room_images'] ?? [], 'room', $savedRoomId);
                    $notice = $roomId > 0 ? 'updated' : 'added';
                    header('Location: dashboard.php?section=rooms&notice=' . $notice . '&item=room');
                    exit();
                } else {
                    $errorMessage = 'Unable to save room.';
                }
            } else {
                $errorMessage = 'Unable to prepare room save query.';
            }
        }
    } elseif ($roomAction === 'delete_archived_room') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        if ($roomId > 0) {
            $galleryStmt = $conn->prepare('DELETE FROM property_gallery_images WHERE property_type = ? AND property_id = ?');
            if ($galleryStmt) { $galleryType = 'room'; $galleryStmt->bind_param('si', $galleryType, $roomId); $galleryStmt->execute(); }
            $stmt = $conn->prepare('DELETE FROM rooms WHERE id = ? AND archived = 1');
            if ($stmt) { $stmt->bind_param('i', $roomId); $stmt->execute(); }
        }
        header('Location: dashboard.php?section=rooms&notice=deleted&item=room');
        exit();
    } elseif ($roomAction === 'delete_room' || $roomAction === 'archive_room') {
        $roomId = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
        if ($roomId > 0) {
            $stmt = $conn->prepare('UPDATE rooms SET archived = 1, available = 0 WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $roomId);
                $stmt->execute();
            }
        }

        header('Location: dashboard.php?section=rooms&notice=archived&item=room');
        exit();
    } elseif ($roomAction === 'restore_room') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $stmt = $conn->prepare('UPDATE rooms SET archived = 0, available = 1 WHERE id = ?');
        if ($stmt) { $stmt->bind_param('i', $roomId); $stmt->execute(); }
        header('Location: dashboard.php?section=rooms&notice=restored&item=room');
        exit();
    }
    } elseif (isset($_POST['cottage_action'])) {
        $cottageAction = $_POST['cottage_action'];
        if ($cottageAction === 'save_cottage') {
            $cottageId = isset($_POST['cottage_id']) ? (int)$_POST['cottage_id'] : 0;
            $cottageName = trim($_POST['cottage_name'] ?? '');
            $description = trim($_POST['cottage_description'] ?? '');
            $capacity = max(1, (int)($_POST['cottage_capacity'] ?? 1));
            $pricePerNight = (float)($_POST['cottage_price'] ?? 0);
            $daySlots = max(1, (int)($_POST['cottage_day_slots'] ?? 1));
            $nightSlots = max(1, (int)($_POST['cottage_night_slots'] ?? 1));
            $available = isset($_POST['cottage_available']) ? 1 : 0;
            
            // Handle file upload
            $imageUrl = '';
            if (isset($_FILES['cottage_image']) && $_FILES['cottage_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $fileName = 'cottage_' . time() . '_' . basename($_FILES['cottage_image']['name']);
                $uploadPath = $uploadDir . $fileName;
                if (move_uploaded_file($_FILES['cottage_image']['tmp_name'], $uploadPath)) {
                    $imageUrl = SITE_URL . 'admin/uploads/' . $fileName;
                }
            } elseif ($cottageId > 0) {
                // Keep existing image if no new file uploaded during edit
                $result = $conn->query("SELECT image_url FROM cottages WHERE id = $cottageId");
                if ($result) {
                    $row = $result->fetch_assoc();
                    $imageUrl = $row['image_url'] ?? '';
                }
            }

            if ($cottageName === '') {
                $cottageErrorMessage = 'Cottage name is required.';
            } else {
                $stmt = $cottageId > 0
                    ? $conn->prepare('UPDATE cottages SET name = ?, description = ?, capacity = ?, price_per_night = ?, image_url = ?, available = ?, day_slots = ?, night_slots = ? WHERE id = ?')
                    : $conn->prepare('INSERT INTO cottages (name, description, capacity, price_per_night, image_url, available, day_slots, night_slots) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

                if ($stmt) {
                    if ($cottageId > 0) {
                        $stmt->bind_param('ssidsiiii', $cottageName, $description, $capacity, $pricePerNight, $imageUrl, $available, $daySlots, $nightSlots, $cottageId);
                    } else {
                        $stmt->bind_param('ssidsiii', $cottageName, $description, $capacity, $pricePerNight, $imageUrl, $available, $daySlots, $nightSlots);
                    }

                    if ($stmt->execute()) {
                        $savedCottageId = $cottageId > 0 ? $cottageId : $conn->insert_id;
                        savePropertyGalleryImages($conn, $_FILES['cottage_images'] ?? [], 'cottage', $savedCottageId);
                        $notice = $cottageId > 0 ? 'updated' : 'added';
                        header('Location: dashboard.php?section=cottages&notice=' . $notice . '&item=cottage');
                        exit();
                    } else {
                        $cottageErrorMessage = 'Unable to save cottage.';
                    }
                } else {
                    $cottageErrorMessage = 'Unable to prepare cottage save query.';
                }
            }
        } elseif ($cottageAction === 'delete_archived_cottage') {
            $cottageId = (int)($_POST['cottage_id'] ?? 0);
            if ($cottageId > 0) {
                $galleryStmt = $conn->prepare('DELETE FROM property_gallery_images WHERE property_type = ? AND property_id = ?');
                if ($galleryStmt) { $galleryType = 'cottage'; $galleryStmt->bind_param('si', $galleryType, $cottageId); $galleryStmt->execute(); }
                $stmt = $conn->prepare('DELETE FROM cottages WHERE id = ? AND archived = 1');
                if ($stmt) { $stmt->bind_param('i', $cottageId); $stmt->execute(); }
            }
            header('Location: dashboard.php?section=cottages&notice=deleted&item=cottage');
            exit();
        } elseif ($cottageAction === 'delete_cottage' || $cottageAction === 'archive_cottage') {
            $cottageId = isset($_POST['cottage_id']) ? (int)$_POST['cottage_id'] : 0;
            if ($cottageId > 0) {
                $stmt = $conn->prepare('UPDATE cottages SET archived = 1, available = 0 WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $cottageId);
                    $stmt->execute();
                }
            }
            header('Location: dashboard.php?section=cottages&notice=archived&item=cottage');
            exit();
        } elseif ($cottageAction === 'restore_cottage') {
            $cottageId = (int)($_POST['cottage_id'] ?? 0);
            $stmt = $conn->prepare('UPDATE cottages SET archived = 0, available = 1 WHERE id = ?');
            if ($stmt) { $stmt->bind_param('i', $cottageId); $stmt->execute(); }
            header('Location: dashboard.php?section=cottages&notice=restored&item=cottage');
            exit();
        }
    } elseif (isset($_POST['pool_action'])) {
        $poolAction = $_POST['pool_action'];
        if ($poolAction === 'save_pool') {
            $poolId = isset($_POST['pool_id']) ? (int)$_POST['pool_id'] : 0;
            $poolName = trim($_POST['pool_name'] ?? '');
            $description = trim($_POST['pool_description'] ?? '');
            $capacity = max(1, (int)($_POST['pool_capacity'] ?? 1));
            $features = trim($_POST['pool_features'] ?? '');
            $status = trim($_POST['pool_status'] ?? 'active');
            $available = isset($_POST['pool_available']) ? 1 : 0;
            
            // Handle file upload
            $imageUrl = '';
            if (isset($_FILES['pool_image']) && $_FILES['pool_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $fileName = 'pool_' . time() . '_' . basename($_FILES['pool_image']['name']);
                $uploadPath = $uploadDir . $fileName;
                if (move_uploaded_file($_FILES['pool_image']['tmp_name'], $uploadPath)) {
                    $imageUrl = SITE_URL . 'admin/uploads/' . $fileName;
                }
            } elseif ($poolId > 0) {
                // Keep existing image if no new file uploaded during edit
                $result = $conn->query("SELECT image_url FROM pools WHERE id = $poolId");
                if ($result) {
                    $row = $result->fetch_assoc();
                    $imageUrl = $row['image_url'] ?? '';
                }
            }

            if ($poolName === '') {
                $poolErrorMessage = 'Pool name is required.';
            } else {
                $stmt = $poolId > 0
                    ? $conn->prepare('UPDATE pools SET name = ?, description = ?, capacity = ?, features = ?, status = ?, image_url = ?, available = ? WHERE id = ?')
                    : $conn->prepare('INSERT INTO pools (name, description, capacity, features, status, image_url, available) VALUES (?, ?, ?, ?, ?, ?, ?)');

                if ($stmt) {
                    if ($poolId > 0) {
                        $stmt->bind_param('ssisssii', $poolName, $description, $capacity, $features, $status, $imageUrl, $available, $poolId);
                    } else {
                        $stmt->bind_param('ssisssi', $poolName, $description, $capacity, $features, $status, $imageUrl, $available);
                    }

                    if ($stmt->execute()) {
                        $notice = $poolId > 0 ? 'updated' : 'added';
                        header('Location: dashboard.php?section=pools&notice=' . $notice . '&item=pool');
                        exit();
                    } else {
                        $poolErrorMessage = 'Unable to save pool.';
                    }
                } else {
                    $poolErrorMessage = 'Unable to prepare pool save query.';
                }
            }
        } elseif ($poolAction === 'delete_archived_pool') {
            $poolId = (int)($_POST['pool_id'] ?? 0);
            if ($poolId > 0) {
                $stmt = $conn->prepare('DELETE FROM pools WHERE id = ? AND archived = 1');
                if ($stmt) { $stmt->bind_param('i', $poolId); $stmt->execute(); }
            }
            header('Location: dashboard.php?section=pools&notice=deleted&item=pool');
            exit();
        } elseif ($poolAction === 'delete_pool' || $poolAction === 'archive_pool') {
            $poolId = isset($_POST['pool_id']) ? (int)$_POST['pool_id'] : 0;
            if ($poolId > 0) {
                $stmt = $conn->prepare('UPDATE pools SET archived = 1, available = 0 WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $poolId);
                    $stmt->execute();
                }
            }
            header('Location: dashboard.php?section=pools&notice=archived&item=pool');
            exit();
        } elseif ($poolAction === 'restore_pool') {
            $poolId = (int)($_POST['pool_id'] ?? 0);
            $stmt = $conn->prepare('UPDATE pools SET archived = 0, available = 1 WHERE id = ?');
            if ($stmt) { $stmt->bind_param('i', $poolId); $stmt->execute(); }
            header('Location: dashboard.php?section=pools&notice=restored&item=pool');
            exit();
        }
    }
    elseif (isset($_POST['food_action'])) {
        $foodAction = $_POST['food_action'];
        if ($foodAction === 'save_food') {
            $foodId = isset($_POST['food_id']) ? (int)$_POST['food_id'] : 0;
            $foodName = trim($_POST['food_name'] ?? '');
            $category = trim($_POST['food_category'] ?? 'Starters');
            $description = trim($_POST['food_description'] ?? '');
            $price = (float)($_POST['food_price'] ?? 0);
            $status = trim($_POST['food_status'] ?? 'active');
            $available = isset($_POST['food_available']) ? 1 : 0;

            // Handle file upload
            $imageUrl = '';
            if (isset($_FILES['food_image']) && $_FILES['food_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $fileName = 'food_' . time() . '_' . basename($_FILES['food_image']['name']);
                $uploadPath = $uploadDir . $fileName;
                if (move_uploaded_file($_FILES['food_image']['tmp_name'], $uploadPath)) {
                    $imageUrl = SITE_URL . 'admin/uploads/' . $fileName;
                }
            } elseif ($foodId > 0) {
                $result = $conn->query("SELECT image_url FROM foods WHERE id = $foodId");
                if ($result) {
                    $row = $result->fetch_assoc();
                    $imageUrl = $row['image_url'] ?? '';
                }
            }

            if ($foodName === '') {
                $errorMessage = 'Food name is required.';
            } else {
                $stmt = $foodId > 0
                    ? $conn->prepare('UPDATE foods SET name = ?, category = ?, description = ?, price = ?, image_url = ?, status = ?, available = ? WHERE id = ?')
                    : $conn->prepare('INSERT INTO foods (name, category, description, price, image_url, status, available) VALUES (?, ?, ?, ?, ?, ?, ?)');

                if ($stmt) {
                    if ($foodId > 0) {
                        $stmt->bind_param('sssdsiii', $foodName, $category, $description, $price, $imageUrl, $status, $available, $foodId);
                    } else {
                        $stmt->bind_param('sssdsii', $foodName, $category, $description, $price, $imageUrl, $status, $available);
                    }

                    if ($stmt->execute()) {
                        $notice = $foodId > 0 ? 'updated' : 'added';
                        header('Location: dashboard.php?section=foods&notice=' . $notice . '&item=food');
                        exit();
                    } else {
                        $errorMessage = 'Unable to save food.';
                    }
                } else {
                    $errorMessage = 'Unable to prepare food save query.';
                }
            }
        } elseif ($foodAction === 'delete_food' || $foodAction === 'archive_food') {
            $foodId = isset($_POST['food_id']) ? (int)$_POST['food_id'] : 0;
            if ($foodId > 0) {
                $stmt = $conn->prepare('UPDATE foods SET archived = 1, available = 0 WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $foodId);
                    $stmt->execute();
                }
            }
            header('Location: dashboard.php?section=foods&notice=archived&item=food');
            exit();
        } elseif ($foodAction === 'restore_food') {
            $foodId = (int)($_POST['food_id'] ?? 0);
            $stmt = $conn->prepare('UPDATE foods SET archived = 0, available = 1 WHERE id = ?');
            if ($stmt) { $stmt->bind_param('i', $foodId); $stmt->execute(); }
            header('Location: dashboard.php?section=foods&notice=restored&item=food');
            exit();
        }
    }
}

if (isset($_GET['edit_room']) && (int)$_GET['edit_room'] > 0) {
    $editRoomId = (int)$_GET['edit_room'];
    $editRoomStmt = $conn->prepare('SELECT * FROM rooms WHERE id = ?');
    if ($editRoomStmt) {
        $editRoomStmt->bind_param('i', $editRoomId);
        $editRoomStmt->execute();
        $editRoomResult = $editRoomStmt->get_result();
        $editingRoom = $editRoomResult->fetch_assoc();
    }
}

if (isset($_GET['edit_cottage']) && (int)$_GET['edit_cottage'] > 0) {
    $editCottageId = (int)$_GET['edit_cottage'];
    $editCottageStmt = $conn->prepare('SELECT * FROM cottages WHERE id = ?');
    if ($editCottageStmt) {
        $editCottageStmt->bind_param('i', $editCottageId);
        $editCottageStmt->execute();
        $editCottageResult = $editCottageStmt->get_result();
        $editingCottage = $editCottageResult->fetch_assoc();
    }
}

if (isset($_GET['edit_pool']) && (int)$_GET['edit_pool'] > 0) {
    $editPoolId = (int)$_GET['edit_pool'];
    $editPoolStmt = $conn->prepare('SELECT * FROM pools WHERE id = ?');
    if ($editPoolStmt) {
        $editPoolStmt->bind_param('i', $editPoolId);
        $editPoolStmt->execute();
        $editPoolResult = $editPoolStmt->get_result();
        $editingPool = $editPoolResult->fetch_assoc();
    }
}

// Fetch all rooms
$roomsSql = "SELECT * FROM rooms WHERE archived = 0 ORDER BY id";
$roomsResult = $conn->query($roomsSql);
$rooms = $roomsResult->fetch_all(MYSQLI_ASSOC);

$cottagesSql = "SELECT * FROM cottages WHERE archived = 0 ORDER BY id";
$cottagesResult = $conn->query($cottagesSql);
$cottages = $cottagesResult ? $cottagesResult->fetch_all(MYSQLI_ASSOC) : [];

$poolsSql = "SELECT * FROM pools WHERE archived = 0 ORDER BY id";
$poolsResult = $conn->query($poolsSql);
$pools = $poolsResult ? $poolsResult->fetch_all(MYSQLI_ASSOC) : [];

// Fetch all foods
$foodsSql = "SELECT * FROM foods WHERE archived = 0 ORDER BY id";
$foodsResult = $conn->query($foodsSql);
$foods = $foodsResult ? $foodsResult->fetch_all(MYSQLI_ASSOC) : [];

$archivedRooms = ($result = $conn->query("SELECT id, name FROM rooms WHERE archived = 1 ORDER BY name")) ? $result->fetch_all(MYSQLI_ASSOC) : [];
$archivedCottages = ($result = $conn->query("SELECT id, name FROM cottages WHERE archived = 1 ORDER BY name")) ? $result->fetch_all(MYSQLI_ASSOC) : [];
$archivedPools = ($result = $conn->query("SELECT id, name FROM pools WHERE archived = 1 ORDER BY name")) ? $result->fetch_all(MYSQLI_ASSOC) : [];
$archivedFoods = ($result = $conn->query("SELECT id, name FROM foods WHERE archived = 1 ORDER BY name")) ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Fetch recent reviews
$reviewsSql = "SELECT r.*, u.fullname as name FROM reviews r LEFT JOIN users u ON r.user_id = u.id ORDER BY r.created_at DESC LIMIT 10";
$reviewsResult = $conn->query($reviewsSql);
$reviews = $reviewsResult->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Villa Soledad</title>
    <link rel="stylesheet" href="../css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        // Fallback CDN if jsDelivr is blocked
        if (typeof Chart === 'undefined') {
            document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"><\/script>');
        }
    </script>
    <style>
        body.admin-page {
            background: linear-gradient(180deg, #edf5fb 0%, #f8fbff 100%);
            color: #173b5d;
        }

        .admin-page .header {
            background: linear-gradient(135deg, rgba(8, 36, 58, 0.96) 0%, rgba(30, 92, 143, 0.96) 100%);
            box-shadow: 0 2px 12px rgba(20, 59, 92, 0.12);
            padding: 0.9rem 0;
        }

        .admin-page .container {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 0;
        }

        .admin-container {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 1.25rem;
            min-height: calc(100vh - 140px);
            width: 100%;
            margin: 1.5rem auto 2rem;
            max-width: 1400px;
            padding: 0 1rem;
        }

        .admin-sidebar {
            background: rgba(255, 255, 255, 0.9);
            padding: 1.25rem;
            border-radius: 18px;
            position: sticky;
            top: 92px;
            height: calc(100vh - 110px);
            align-self: start;
            overflow-y: auto;
            box-shadow: 0 14px 28px rgba(18, 58, 91, 0.08);
            border: 1px solid rgba(150, 178, 201, 0.22);
        }

        .header .container {
            padding: 0 2rem;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu li {
            margin-bottom: 0.5rem;
        }

        .sidebar-menu a {
            display: block;
            padding: 0.8rem 1rem;
            color: var(--text-dark);
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background-color: var(--accent-orange);
            color: var(--white);
        }

        .admin-content {
            background: rgba(255, 255, 255, 0.92);
            border-radius: 18px;
            box-shadow: 0 14px 28px rgba(18, 58, 91, 0.08);
            padding: 2rem;
            border: 1px solid rgba(150, 178, 201, 0.2);
        }

        .admin-section {
            display: none;
        }

        .admin-section.active {
            display: block;
        }

        .section-title {
            font-size: 1.8rem;
            color: #163d60;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #ff7a3d;
            padding-bottom: 0.5rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #0e3b5c 0%, #2d7fc2 100%);
            color: var(--white);
            padding: 1.5rem;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 18px 30px rgba(16, 57, 94, 0.12);
        }

        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            opacity: 0.7;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .table-container {
            overflow-x: auto;
            margin-bottom: 2rem;
        }

        .items-booked-cell {
            white-space: nowrap;
            min-width: 180px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background-color: var(--primary-blue);
            color: var(--white);
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
        }

        td {
            padding: 0.8rem 1rem;
            border-bottom: 1px solid var(--border-gray);
        }

        tbody tr:hover {
            background-color: var(--bg-light);
        }

        .btn-small {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 5px;
        }

        .food-tab {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-gray);
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .food-tab:hover {
            background: var(--bg-light);
        }

        .food-tab.btn-primary {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .food-tab.btn-primary:hover {
            background: #1e3a8a;
        }

        .admin-food-category-select {
            width: min(100%, 240px);
            min-height: 42px;
            padding: 0.55rem 2.5rem 0.55rem 0.9rem;
            border: 1px solid rgba(26, 58, 91, 0.25);
            border-radius: 8px;
            background: linear-gradient(180deg, #ffffff 0%, #f7fafd 100%);
            color: var(--text-dark);
            font-size: 0.95rem;
            font-weight: 600;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: linear-gradient(45deg, transparent 50%, #1b486f 50%), linear-gradient(135deg, #1b486f 50%, transparent 50%);
            background-position: calc(100% - 22px) calc(50% - 3px), calc(100% - 15px) calc(50% - 3px);
            background-size: 7px 7px, 7px 7px;
            background-repeat: no-repeat;
            cursor: pointer;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.02);
        }

        .btn-primary {
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-edit {
            background-color: var(--primary-blue);
            color: var(--white);
        }

        .btn-edit:hover {
            background-color: var(--light-blue);
        }

        .btn-delete {
            background-color: #ef4444;
            color: var(--white);
            margin-left: 0.25rem;
        }

        .btn-delete:hover {
            background-color: #dc2626;
        }

        .chart-container {
            background: var(--bg-light);
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        .chart-container h3 {
            color: var(--primary-blue);
            margin-bottom: 1rem;
        }

        .review-item {
            background: var(--bg-light);
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid var(--accent-orange);
        }

        .review-item-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .review-item-author {
            font-weight: 600;
            color: var(--primary-blue);
        }

        .review-item-rating {
            color: var(--accent-orange);
        }

        .room-card {
            background: var(--bg-light);
            padding: 1.5rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }

        .rooms-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .cottages-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .room-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .room-card-header h3 {
            color: var(--primary-blue);
            margin: 0;
        }

        .price-badge {
            background-color: var(--accent-orange);
            color: var(--white);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .cottage-card {
            background: var(--bg-light);
            padding: 1.5rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }

        .cottage-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .cottage-card-header h3 {
            color: var(--primary-blue);
            margin: 0;
        }

        .pool-card {
            background: var(--bg-light);
            padding: 1.5rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }

        .pool-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .pool-card-header h3 {
            color: var(--primary-blue);
            margin: 0;
        }

        .review-item-date {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        /* Status Badge Styles */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-available {
            background: #d1fae5;
            color: #065f46;
        }

        .status-unavailable {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-active {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-maintenance {
            background: #fef3c7;
            color: #92400e;
        }

        /* Button Styles */
        .btn-primary {
            background: var(--primary-blue);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: var(--light-blue);
        }

        .btn-approve {
            background: #10b981;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-approve:hover {
            background: #059669;
        }

        .btn-loading {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, 0.35);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: reservationBtnSpin 0.8s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }

        @keyframes reservationBtnSpin {
            to { transform: rotate(360deg); }
        }

        .reservation-action-btn:disabled {
            opacity: 0.75;
            cursor: wait;
        }

        .btn-reject {
            background: #ef4444;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-reject:hover {
            background: #dc2626;
        }

        /* Facility Card Styles */
        .facility-card h3 {
            margin-bottom: 0.5rem;
        }

        .facility-card p {
            margin: 0.25rem 0;
            color: var(--text-dark);
        }

        /* Filter Controls */
        .filter-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Concern Item Styles */
        .concern-item h4 {
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }

        .concern-item p {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        /* Data Stats */
        .data-stat h4 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        /* Monitor Card */
        .monitor-card h4 {
            color: var(--primary-blue);
            margin-bottom: 1rem;
        }

        .monitor-card p {
            margin: 0.5rem 0;
            color: var(--text-dark);
        }

        @media (max-width: 768px) {
            .admin-container {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-controls input,
            .filter-controls select {
                margin-left: 0 !important;
                margin-top: 0.5rem;
            }
        }

        /* Notification bell */
        .notif-bell-wrap {
            position: relative;
        }

        .notif-bell-btn {
            background: transparent;
            border: none;
            color: var(--white);
            font-size: 1.25rem;
            cursor: pointer;
            padding: 0.4rem 0.55rem;
            position: relative;
            line-height: 1;
            border-radius: 8px;
            transition: color 0.2s ease, background 0.2s ease;
        }

        .notif-bell-btn:hover {
            color: var(--accent-orange);
            background: rgba(255, 255, 255, 0.08);
        }

        .notif-badge {
            position: absolute;
            top: 0;
            right: 0;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            background: #ef4444;
            color: #fff;
            font-size: 0.7rem;
            font-weight: 700;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            box-shadow: 0 0 0 2px rgba(15, 76, 129, 0.9);
            pointer-events: none;
        }

        .notif-badge.dot-only {
            min-width: 10px;
            width: 10px;
            height: 10px;
            padding: 0;
            top: 4px;
            right: 4px;
        }

        .notif-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 0.75rem);
            right: 0;
            width: 360px;
            max-width: min(360px, calc(100vw - 2rem));
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
            z-index: 1000;
            overflow: hidden;
            color: var(--text-dark);
        }

        .notif-dropdown.open {
            display: block;
        }

        .notif-dropdown-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.9rem 1rem;
            border-bottom: 1px solid #e5e7eb;
            background: #f8fafc;
        }

        .notif-dropdown-header h4 {
            margin: 0;
            font-size: 0.95rem;
            color: var(--primary-blue);
        }

        .notif-dropdown-list {
            max-height: 320px;
            overflow-y: auto;
        }

        .notif-item {
            display: block;
            width: 100%;
            text-align: left;
            padding: 0.85rem 1rem;
            border: none;
            border-bottom: 1px solid #f1f5f9;
            background: #fff;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .notif-item:hover {
            background: #f8fafc;
        }

        .notif-item-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            color: #0f172a;
            margin-bottom: 0.25rem;
        }

        .notif-item-meta {
            font-size: 0.8rem;
            color: #64748b;
            line-height: 1.35;
        }

        .notif-tag {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            padding: 0.15rem 0.45rem;
            border-radius: 999px;
            white-space: nowrap;
        }

        .notif-tag.pending {
            background: #fef3c7;
            color: #b45309;
        }

        .notif-tag.coming {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .notif-tag.today {
            background: #fee2e2;
            color: #b91c1c;
        }

        .notif-empty {
            padding: 1.5rem 1rem;
            text-align: center;
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .notif-dropdown-footer {
            padding: 0.75rem 1rem;
            border-top: 1px solid #e5e7eb;
            background: #f8fafc;
        }

        .notif-dropdown-footer button {
            width: 100%;
            border: none;
            background: transparent;
            color: var(--primary-blue);
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            padding: 0.35rem;
        }

        .notif-dropdown-footer button:hover {
            color: var(--accent-orange);
        }

        .nav-links .notif-bell-wrap a::after,
        .nav-links .notif-bell-btn::after {
            display: none;
        }
    </style>
</head>
<body class="admin-page">
    <!-- Header & Navigation -->
    <header class="header">
        <div class="container">
            <div class="nav">
                <a href="../index.php" class="logo" style="display: flex; align-items: center; gap: 1rem;">
                    <img src="../images/logo.jpg" alt="Villa Soledad Garden Resort Logo" style="height: 60px; width: 60px; object-fit: cover; border-radius: 50%; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                    <span style="font-size: 1.5rem; font-weight: 700; color: white;">Villa Soledad Garden Resort Admin</span>
                </a>
                <nav class="nav-links">
                    <div class="notif-bell-wrap">
                        <button type="button" class="notif-bell-btn" id="notifBellBtn" aria-label="Reservation notifications" aria-expanded="false" aria-haspopup="true">
                            <i class="fas fa-bell"></i>
                            <?php if ($notificationCount > 0): ?>
                                <span class="notif-badge" id="notifBadge"><?php echo $notificationCount > 99 ? '99+' : (int)$notificationCount; ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="notif-dropdown" id="notifDropdown" role="menu">
                            <div class="notif-dropdown-header">
                                <h4>Reservations</h4>
                                <span id="notifAlertCount" style="font-size: 0.8rem; color: #64748b;"><?php echo (int)$notificationCount; ?> alert<?php echo $notificationCount === 1 ? '' : 's'; ?></span>
                            </div>
                            <div class="notif-dropdown-list" id="notifDropdownList">
                                <?php if ($notificationCount === 0): ?>
                                    <div class="notif-empty">No pending reservations</div>
                                <?php else: ?>
                                    <?php foreach ($reservationNotifications as $notif): ?>
                                        <?php
                                            $checkInTs = strtotime($notif['check_in']);
                                            $guestName = trim($notif['name'] ?? '') !== '' ? $notif['name'] : 'Guest';
                                            $itemsLabel = trim($notif['items'] ?? '') !== '' ? $notif['items'] : 'Reservation';
                                        ?>
                                        <button type="button" class="notif-item" data-section="reservations" role="menuitem">
                                            <div class="notif-item-title">
                                                <span><?php echo htmlspecialchars($guestName); ?></span>
                                                <span class="notif-tag pending">Pending</span>
                                            </div>
                                            <div class="notif-item-meta">
                                                #<?php echo (int)$notif['id']; ?> · <?php echo htmlspecialchars($itemsLabel); ?><br>
                                                Check-in: <?php echo date('M d, Y', $checkInTs); ?>
                                            </div>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="notif-dropdown-footer">
                                <button type="button" id="notifViewAllBtn">View manage reservations</button>
                            </div>
                        </div>
                    </div>
                    <a href="logout.php" style="color: var(--white); text-decoration: none;">Logout</a>
                </nav>
            </div>
        </div>
    </header>

    <!-- Admin Container -->
    <div class="container">
        <div class="admin-container">
            <!-- Sidebar -->
            <aside class="admin-sidebar">
                <ul class="sidebar-menu">
                    <li><a href="dashboard.php?section=dashboard" class="menu-link active" data-section="dashboard">Dashboard</a></li>
                    <li><a href="dashboard.php?section=reservations" class="menu-link" data-section="reservations">Manage Reservations</a></li>
                    <li><a href="dashboard.php?section=booking-records" class="menu-link" data-section="booking-records">Booking Records</a></li>
                    <li><a href="dashboard.php?section=rooms" class="menu-link" data-section="rooms">Manage Rooms</a></li>
                    <li><a href="dashboard.php?section=cottages" class="menu-link" data-section="cottages">Manage Cottages</a></li>
                    <li><a href="dashboard.php?section=pools" class="menu-link" data-section="pools">Manage Pools</a></li>
                    <li><a href="dashboard.php?section=foods" class="menu-link" data-section="foods">Manage Food</a></li>
                    <li><a href="dashboard.php?section=reports" class="menu-link" data-section="reports">Reports</a></li>
                    <li><a href="dashboard.php?section=reviews" class="menu-link" data-section="reviews">Reviews</a></li>
                </ul>
            </aside>

            <!-- Main Content -->
            <main class="admin-content">
                <!-- Dashboard Section -->
                <div id="dashboard" class="admin-section active">
                    <h2 class="section-title"><i class="fas fa-th-large"></i> Dashboard Overview</h2>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <i class="fas fa-book"></i>
                            <div class="stat-value" id="totalBookingsValue"><?php echo $stats['total_bookings']['count'] ?? 0; ?></div>
                            <div class="stat-label">Total Bookings</div>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-peso-sign"></i>
                            <div class="stat-value">₱<?php echo number_format($stats['total_revenue']['total'] ?? 0, 0); ?></div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-users"></i>
                            <div class="stat-value"><?php echo $stats['total_users']['count'] ?? 0; ?></div>
                            <div class="stat-label">Total Users</div>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-star"></i>
                            <div class="stat-value"><?php echo $stats['total_reviews']['count'] ?? 0; ?></div>
                            <div class="stat-label">Total Reviews</div>
                        </div>
                    </div>

                    <div class="table-container">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1rem;">
                            <h3 style="color: var(--primary-blue); margin:0;">Activity Log History</h3>
                        </div>
                        <form method="get" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:0.75rem; margin-bottom:1.25rem; align-items:end;">
                            <input type="hidden" name="section" value="dashboard">
                            <label style="font-size:0.8rem; color:var(--text-light);">Action
                                <select name="activity_action" style="width:100%; padding:0.55rem; border:1px solid var(--border-gray); border-radius:6px; margin-top:0.25rem;">
                                    <option value="">All actions</option>
                                    <?php foreach (['login' => 'Login', 'logout' => 'Logout', 'reservation_created' => 'Reservation created', 'reservation_cancelled' => 'Reservation cancelled'] as $actionValue => $actionLabel): ?>
                                        <option value="<?php echo $actionValue; ?>" <?php echo $activityAction === $actionValue ? 'selected' : ''; ?>><?php echo $actionLabel; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label style="font-size:0.8rem; color:var(--text-light);">User
                                <select name="activity_user_id" style="width:100%; padding:0.55rem; border:1px solid var(--border-gray); border-radius:6px; margin-top:0.25rem;">
                                    <option value="0">All users</option>
                                    <?php foreach ($activityUsers as $activityUser): ?>
                                        <option value="<?php echo (int)$activityUser['id']; ?>" <?php echo $activityUserId === (int)$activityUser['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($activityUser['fullname'] ?: $activityUser['email']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label style="font-size:0.8rem; color:var(--text-light);">From
                                <input type="date" name="activity_from" value="<?php echo htmlspecialchars($activityFrom); ?>" style="width:100%; padding:0.5rem; border:1px solid var(--border-gray); border-radius:6px; margin-top:0.25rem;">
                            </label>
                            <label style="font-size:0.8rem; color:var(--text-light);">To
                                <input type="date" name="activity_to" value="<?php echo htmlspecialchars($activityTo); ?>" style="width:100%; padding:0.5rem; border:1px solid var(--border-gray); border-radius:6px; margin-top:0.25rem;">
                            </label>
                            <button type="submit" class="btn-primary" style="padding:0.6rem 1rem;">Filter</button>
                            <a href="dashboard.php?section=dashboard" class="btn-small" style="text-align:center; text-decoration:none; padding:0.6rem 1rem;">Clear</a>
                        </form>
                        <div style="overflow-x:auto;">
                            <table>
                                <thead><tr><th>Date &amp; Time</th><th>User</th><th>Action</th><th>Activity</th></tr></thead>
                                <tbody>
                                    <?php if (empty($activityLog)): ?>
                                        <tr><td colspan="4" style="text-align:center; color:var(--text-light);">No activity found for the selected filters.</td></tr>
                                    <?php else: foreach ($activityLog as $activity): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($activity['user_name']); ?></td>
                                            <td><span style="background:#e0e7ff; color:#3730a3; border-radius:999px; padding:0.25rem 0.55rem; font-size:0.78rem; font-weight:700;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $activity['action']))); ?></span></td>
                                            <td><?php echo htmlspecialchars($activity['description']); ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Manage Rooms Section -->
                <div id="rooms" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-door-open"></i> Manage Rooms</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Add a new room or update an existing one. Changes will appear on the public user-side room listing.</p>

                    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom: 1.5rem; flex-wrap:wrap;">
                        <button class="btn-primary" onclick="openAddRoomModal()"><i class="fas fa-plus"></i> Add New Room</button>
                        
                        <?php
                        require_once '../config/RoomConfig.php';
                        $selectedDate = $_GET['date'] ?? null;
                        
                        if ($selectedDate): ?>
                        <div style="background: #e0f2fe; padding: 0.5rem 1rem; border-radius: 6px; border-left: 4px solid var(--primary-blue);">
                            <strong>Selected Date:</strong> <?php echo date('F d, Y', strtotime($selectedDate)); ?>
                            <a href="dashboard.php?section=rooms" style="background: none; border: none; color: var(--primary-blue); cursor: pointer; margin-left: 0.5rem; text-decoration: underline; font-size: 0.85rem;">Clear</a>
                        </div>
                        <?php endif; ?>
                        
                        <button class="btn-primary" type="button" onclick="openArchiveModal('rooms')" style="background: var(--primary-blue);"><i class="fas fa-box-archive"></i> View Archived</button>
                        <button class="btn-primary" onclick="openDateModal()" style="background: var(--primary-blue);"><i class="fas fa-calendar"></i> Check Availability</button>
                        <?php if (!empty($editingRoom)): ?>
                            <a href="dashboard.php?section=rooms" class="btn-small btn-delete" style="text-decoration:none;">Cancel Edit</a>
                        <?php endif; ?>
                    </div>

                    <?php
                    // Calculate remaining slots for each room on selected date
                    $roomAvailability = [];
                    if ($selectedDate) {
                        foreach ($rooms as $room) {
                            $roomName = $room['name'];
                            $limit = $room['daily_slots'] ?? 1;
                            
                            // Count existing reservations for this room on selected date
                            $countSql = "SELECT COUNT(*) as count 
                                        FROM reservation_items ri 
                                        JOIN reservations r ON ri.reservation_id = r.id 
                                        WHERE ri.item_name = ? AND ri.item_type = 'room' 
                                        AND r.check_in = ? AND r.status != 'cancelled'";
                            $countStmt = $conn->prepare($countSql);
                            $countStmt->bind_param("ss", $roomName, $selectedDate);
                            $countStmt->execute();
                            $countResult = $countStmt->get_result();
                            $bookedCount = $countResult->fetch_assoc()['count'] ?? 0;
                            
                            $remainingSlots = max(0, $limit - $bookedCount);
                            $roomAvailability[$room['id']] = [
                                'limit' => $limit,
                                'booked' => $bookedCount,
                                'remaining' => $remainingSlots
                            ];
                        }
                    } else {
                        // Set default availability when no date is selected
                        foreach ($rooms as $room) {
                            $limit = $room['daily_slots'] ?? 1;
                            $roomAvailability[$room['id']] = [
                                'limit' => $limit,
                                'booked' => 0,
                                'remaining' => $limit
                            ];
                        }
                    }
                    ?>

                    <?php if (!empty($errorMessage)): ?>
                        <div style="background:#fee2e2; color:#991b1b; padding:0.75rem 1rem; border-radius:6px; margin-bottom:1rem;"><?php echo htmlspecialchars($errorMessage); ?></div>
                    <?php endif; ?>

                    <div class="rooms-container">
                    <?php foreach ($rooms as $room): ?>
                    <?php $availability = $roomAvailability[$room['id']]; ?>
                    <div class="room-card" style="background: #f3f4f6; border-radius: 12px; padding: 1.5rem; text-align: center;">
                        <h3 style="color: var(--primary-blue); margin: 0 0 1rem 0; font-size: 1.2rem;"><?php echo htmlspecialchars($room['name'] ?? ''); ?></h3>
                        
                        <div style="height: 150px; background: #e5e7eb; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; overflow: hidden;">
                            <?php 
                            $imageUrl = $room['image_url'] ?? '';
                            if (!empty($imageUrl)): 
                                if (preg_match('/^https?:\/\//i', $imageUrl)) {
                                    $displayImage = $imageUrl;
                                } elseif (strpos($imageUrl, '/') === 0) {
                                    $displayImage = rtrim(SITE_URL, '/') . $imageUrl;
                                } else {
                                    $displayImage = SITE_URL . $imageUrl;
                                }
                            ?>
                                <img src="<?php echo htmlspecialchars($displayImage); ?>" alt="<?php echo htmlspecialchars($room['name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <span style="color: #9ca3af; font-size: 0.9rem;">Actual Image</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($selectedDate): ?>
                        <div style="background: white; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                            <div style="color: #64748b; font-size: 0.9rem; margin-bottom: 0.5rem;">Remaining slots</div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: <?php echo $availability['remaining'] > 0 ? '#10b981' : '#ef4444'; ?>;">
                                <?php echo $availability['remaining']; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div style="display: flex; gap: 0.5rem; justify-content: center;">
                            <button class="btn-small btn-edit" onclick="openEditRoomModal(<?php echo (int)$room['id']; ?>, '<?php echo str_replace("'", "\\'", htmlspecialchars($room['name'] ?? '', ENT_QUOTES)); ?>', <?php echo (int)($room['capacity'] ?? 0); ?>, <?php echo (float)($room['price_per_night'] ?? 0); ?>, <?php echo (int)($room['day_slots'] ?? $room['daily_slots'] ?? 1); ?>, <?php echo (int)($room['night_slots'] ?? $room['daily_slots'] ?? 1); ?>, '<?php echo str_replace("'", "\\'", htmlspecialchars($room['image_url'] ?? '', ENT_QUOTES)); ?>', '<?php echo str_replace("'", "\\'", htmlspecialchars($room['description'] ?? '', ENT_QUOTES)); ?>', <?php echo !empty($room['available']) ? 'true' : 'false'; ?>)">Edit</button>
                            <form method="post" action="dashboard.php?section=rooms" style="display:inline-block;" data-delete-form="room" onsubmit="event.preventDefault(); openDeleteConfirmModal(this);">
                                <input type="hidden" name="room_action" value="delete_room">
                                <input type="hidden" name="room_id" value="<?php echo (int)$room['id']; ?>">
                                <button type="submit" class="btn-small btn-delete">Delete</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>

                    <!-- Add Room Modal -->
                    <div id="addRoomModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
                        <div style="background:white; padding:2rem; border-radius:8px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                                <h2 style="color:var(--primary-blue); margin:0;">Add New Room</h2>
                                <button onclick="closeAddRoomModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
                            </div>
                            <form method="post" action="dashboard.php?section=rooms" enctype="multipart/form-data">
                                <input type="hidden" name="room_action" value="save_room">
                                <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem;">
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Room Name</label>
                                        <input type="text" name="room_name" value="" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Capacity</label>
                                        <input type="number" name="room_capacity" min="1" value="2" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Price per Night</label>
                                        <input type="number" step="0.01" name="room_price" min="0" value="0" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Day Tour Slots</label>
                                        <input type="number" name="room_day_slots" min="1" value="1" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                        <small style="color: var(--text-light); font-size: 0.8rem;">Maximum day-tour bookings</small>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Night Tour Slots</label>
                                        <input type="number" name="room_night_slots" min="1" value="1" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                        <small style="color: var(--text-light); font-size: 0.8rem;">Maximum night-tour bookings</small>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Upload Image</label>
                                        <input type="file" name="room_image" accept="image/*" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Description</label>
                                        <textarea name="room_description" rows="4" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;"></textarea>
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:flex; align-items:center; gap:0.5rem; font-weight:600;">
                                            <input type="checkbox" name="room_available" value="1" checked>
                                            Available for booking
                                        </label>
                                    </div>
                                </div>
                                <div style="margin-top:1.5rem; display:flex; gap:1rem;">
                                    <button type="submit" class="btn-primary">Save Room</button>
                                    <button type="button" class="btn-small btn-delete" onclick="closeAddRoomModal()">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Edit Room Modal -->
                    <div id="editRoomModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
                        <div style="background:white; padding:2rem; border-radius:8px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                                <h2 style="color:var(--primary-blue); margin:0;">Edit Room</h2>
                                <button onclick="closeEditRoomModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
                            </div>
                            <form method="post" action="dashboard.php?section=rooms" enctype="multipart/form-data">
                                <input type="hidden" name="room_action" value="save_room">
                                <input type="hidden" name="room_id" id="editRoomId" value="">
                                <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem;">
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Room Name</label>
                                        <input type="text" name="room_name" id="editRoomName" value="" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Capacity</label>
                                        <input type="number" name="room_capacity" id="editRoomCapacity" min="1" value="2" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Price per Night</label>
                                        <input type="number" step="0.01" name="room_price" id="editRoomPrice" min="0" value="0" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Day Tour Slots</label>
                                        <input type="number" name="room_day_slots" id="editRoomDaySlots" min="1" value="1" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                        <small style="color: var(--text-light); font-size: 0.8rem;">Maximum day-tour bookings</small>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Night Tour Slots</label>
                                        <input type="number" name="room_night_slots" id="editRoomNightSlots" min="1" value="1" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                        <small style="color: var(--text-light); font-size: 0.8rem;">Maximum night-tour bookings</small>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Upload New Image (optional)</label>
                                        <input type="file" name="room_image" id="editRoomImage" accept="image/*" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Add Images to Room Album</label>
                                        <input type="file" name="room_images[]" id="editRoomGallery" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                        <small style="color: var(--text-light); font-size: 0.8rem;">Select multiple images. Maximum 8 MB per image.</small>
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Description</label>
                                        <textarea name="room_description" id="editRoomDescription" rows="4" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;"></textarea>
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:flex; align-items:center; gap:0.5rem; font-weight:600;">
                                            <input type="checkbox" name="room_available" id="editRoomAvailable" value="1">
                                            Available for booking
                                        </label>
                                    </div>
                                </div>
                                <div style="margin-top:1.5rem; display:flex; gap:1rem;">
                                    <button type="submit" class="btn-primary">Save Changes</button>
                                    <button type="button" class="btn-small btn-delete" onclick="closeEditRoomModal()">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- View Bookings Section -->
                <div id="bookings" class="admin-section" style="display: none;">
                    <h2 class="section-title"><i class="fas fa-calendar-check"></i> All Bookings</h2>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Guests</th>
                                    <th>Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentReservations as $reservation): ?>
                                <tr>
                                    <td>#<?php echo $reservation['id']; ?></td>
                                    <td><?php echo htmlspecialchars($reservation['name']); ?></td>
                                    <td><?php echo htmlspecialchars($reservation['items']); ?></td>
                                    <td><?php echo date('M d', strtotime($reservation['check_in'])); ?></td>
                                    <td><?php echo date('M d', strtotime($reservation['check_out'])); ?></td>
                                    <td><?php echo ($reservation['adults'] + $reservation['children'] + $reservation['seniors']); ?></td>
                                    <td>₱<?php echo number_format($reservation['total_amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Reviews Section -->
                <div id="reviews" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-comments"></i> Guest Reviews</h2>
                    
                    <?php foreach ($reviews as $review): ?>
                    <div class="review-item">
                        <div class="review-item-header">
                            <span class="review-item-author"><?php echo htmlspecialchars($review['name']); ?></span>
                            <span class="review-item-rating">
                                <?php for ($i = 0; $i < $review['rating']; $i++): ?>
                                    ★
                                <?php endfor; ?>
                            </span>
                        </div>
                        <?php
                            $adminReviewText = trim((string)($review['review_text'] ?? $review['comment'] ?? $review['text'] ?? ''));
                            if ($adminReviewText === '') {
                                $adminReviewText = '(No comment provided)';
                            }
                        ?>
                        <p style="margin: 0.5rem 0; color: var(--text-dark);"><?php echo htmlspecialchars($adminReviewText); ?></p>
                        <small style="color: var(--text-light);"><?php echo date('M d, Y H:i', strtotime($review['created_at'])); ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Statistics Section -->
                <div id="statistics" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-bar-chart"></i> Statistics</h2>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; margin-top: 1rem;">
                        
                        <!-- Peak Season Analysis -->
                        <div class="chart-container" style="margin: 0;">
                            <h3 style="font-size: 1rem; margin-bottom: 0.5rem;">Peak Season</h3>
                            
                            <?php
                            $peakSeasonSql = "SELECT MONTH(check_in) as month, COUNT(*) as bookings, 
                                             SUM(adults + children + seniors) as total_guests 
                                             FROM reservations 
                                             WHERE YEAR(check_in) = YEAR(NOW()) 
                                             AND COALESCE(NULLIF(status, ''), 'pending') IN ('approved','completed')
                                             GROUP BY MONTH(check_in) 
                                             ORDER BY total_guests DESC 
                                             LIMIT 3";
                            $peakSeasonResult = $conn->query($peakSeasonSql);
                            $peakSeasons = $peakSeasonResult ? $peakSeasonResult->fetch_all(MYSQLI_ASSOC) : [];
                            $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                            ?>
                            
                            <?php if (!empty($peakSeasons)): ?>
                            <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                                <?php foreach ($peakSeasons as $index => $season): ?>
                                <div style="flex: 1; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.75rem; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 0.75rem; opacity: 0.9;">
                                        <?php echo $index === 0 ? '🏆' : ($index === 1 ? '🥈' : '🥉'); ?>
                                    </div>
                                    <div style="font-size: 1rem; font-weight: 700;">
                                        <?php echo $months[$season['month'] - 1]; ?>
                                    </div>
                                    <div style="font-size: 0.75rem; opacity: 0.9;">
                                        <?php echo $season['total_guests']; ?> guests
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p style="color: var(--text-light); font-size: 0.85rem;">No data available</p>
                            <?php endif; ?>
                        </div>

                        <!-- Revenue Summary -->
                        <div class="chart-container" style="margin: 0;">
                            <h3 style="font-size: 1rem; margin-bottom: 0.5rem;">Revenue</h3>
                            <p style="color: var(--text-light); font-size: 0.85rem;">Total: <strong style="color: var(--accent-orange); font-size: 1.25rem;">₱<?php echo number_format($stats['total_revenue']['total'] ?? 0, 2); ?></strong></p>
                        </div>
                    </div>

                    <!-- Charts Section -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1rem; margin-top: 1rem;">
                        
                        <!-- Monthly Guest Count Line Graph -->
                        <div class="chart-container" style="margin: 0;">
                            <h3 style="font-size: 1rem; margin-bottom: 0.5rem;">Monthly Guests</h3>
                            
                            <?php
                            $monthlyGuestsSql = "SELECT MONTH(check_in) as month, SUM(adults + children + seniors) as total_guests 
                                               FROM reservations 
                                               WHERE YEAR(check_in) = YEAR(NOW()) 
                                               AND COALESCE(NULLIF(status, ''), 'pending') IN ('approved','completed')
                                               GROUP BY MONTH(check_in) 
                                               ORDER BY month";
                            $monthlyGuestsResult = $conn->query($monthlyGuestsSql);
                            $monthlyGuestsData = $monthlyGuestsResult ? $monthlyGuestsResult->fetch_all(MYSQLI_ASSOC) : [];
                            
                            // Create array with all months (0 for months with no data)
                            $guestsByMonth = array_fill(0, 12, 0);
                            foreach ($monthlyGuestsData as $data) {
                                $guestsByMonth[$data['month'] - 1] = $data['total_guests'];
                            }
                            ?>
                            
                            <div id="monthlyGuestsChart" style="width:100%; height:180px;"></div>
                        </div>

                        <!-- Monthly Bookings Chart -->
                        <div class="chart-container" style="margin: 0;">
                            <h3 style="font-size: 1rem; margin-bottom: 0.5rem;">Monthly Bookings</h3>
                            <div id="monthlyBookingsChart" style="width:100%; height:180px;"></div>
                        </div>
                    </div>

                    <!-- Compact Tables Section -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1rem; margin-top: 1rem;">
                        
                        <!-- Monthly Guest Statistics Table -->
                        <div class="chart-container" style="margin: 0;">
                            <h3 style="font-size: 1rem; margin-bottom: 0.5rem;">Guests by Month</h3>
                            <table style="font-size: 0.85rem;">
                                <thead>
                                    <tr>
                                        <th style="padding: 0.4rem;">Month</th>
                                        <th style="padding: 0.4rem;">Guests</th>
                                        <th style="padding: 0.4rem;">Avg/Booking</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $totalGuests = 0;
                                    $totalBookings = 0;
                                    foreach ($months as $index => $monthName):
                                        $guestCount = $guestsByMonth[$index];
                                        $monthBookingSql = "SELECT COUNT(*) as count FROM reservations 
                                                          WHERE MONTH(check_in) = " . ($index + 1) . " 
                                                          AND YEAR(check_in) = YEAR(NOW()) 
                                                          AND COALESCE(NULLIF(status, ''), 'pending') IN ('approved','completed')";
                                        $monthBookingResult = $conn->query($monthBookingSql);
                                        $monthBookingData = $monthBookingResult ? $monthBookingResult->fetch_assoc() : [];
                                        $bookingCount = $monthBookingData['count'] ?? 0;
                                        $avgPerBooking = $bookingCount > 0 ? round($guestCount / $bookingCount, 1) : 0;
                                        $totalGuests += $guestCount;
                                        $totalBookings += $bookingCount;
                                    ?>
                                    <tr>
                                        <td style="padding: 0.4rem;"><?php echo $monthName; ?></td>
                                        <td style="padding: 0.4rem;"><?php echo $guestCount; ?></td>
                                        <td style="padding: 0.4rem;"><?php echo $avgPerBooking; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr style="background: #f3f4f6; font-weight: 700;">
                                        <td style="padding: 0.4rem;">Total</td>
                                        <td style="padding: 0.4rem;"><?php echo $totalGuests; ?></td>
                                        <td style="padding: 0.4rem;"><?php echo $totalBookings > 0 ? round($totalGuests / $totalBookings, 1) : 0; ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Monthly Bookings Table -->
                        <div class="chart-container" style="margin: 0;">
                            <h3 style="font-size: 1rem; margin-bottom: 0.5rem;">Bookings by Month</h3>
                            <table style="font-size: 0.85rem;">
                                <thead>
                                    <tr>
                                        <th style="padding: 0.4rem;">Month</th>
                                        <th style="padding: 0.4rem;">Bookings</th>
                                        <th style="padding: 0.4rem;">%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $monthlyData = $conn->query("SELECT MONTH(check_in) as month, COUNT(*) as count FROM reservations WHERE YEAR(check_in) = YEAR(NOW()) AND COALESCE(NULLIF(status, ''), 'pending') IN ('approved','completed') GROUP BY MONTH(check_in) ORDER BY month")->fetch_all(MYSQLI_ASSOC);
                                    $totalMonthly = array_sum(array_column($monthlyData, 'count'));
                                    
                                    foreach ($monthlyData as $data):
                                        $percentage = $totalMonthly > 0 ? round(($data['count'] / $totalMonthly) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td style="padding: 0.4rem;"><?php echo $months[$data['month'] - 1]; ?></td>
                                        <td style="padding: 0.4rem;"><?php echo $data['count']; ?></td>
                                        <td style="padding: 0.4rem;"><?php echo $percentage; ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr style="background: #f3f4f6; font-weight: 700;">
                                        <td style="padding: 0.4rem;">Total</td>
                                        <td style="padding: 0.4rem;"><?php echo $totalMonthly; ?></td>
                                        <td style="padding: 0.4rem;">100%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <script>
                    (function(){
                        async function fetchMonthly(){
                            try {
                                const resp = await fetch('../api/get_monthly_bookings.php');
                                if (!resp.ok) return null;
                                const json = await resp.json();
                                return json.months || null;
                            } catch (e) { console.error(e); return null; }
                        }

                        function renderLineChart(containerId, values, color = '#ff7a3d'){
                            const container = document.getElementById(containerId);
                            if (!container) return;
                            const w = container.clientWidth || 400;
                            const h = container.clientHeight || 180;
                            const pad = 25;
                            const max = Math.max(...values, 1);
                            const stepX = (w - pad*2) / (values.length - 1);

                            let path = '';
                            values.forEach((v,i) => {
                                const x = pad + i * stepX;
                                const y = h - pad - (v / max) * (h - pad*2);
                                path += (i === 0 ? 'M ' : ' L ') + x + ' ' + y;
                            });

                            let svg = '<svg viewBox="0 0 '+w+' '+h+'" preserveAspectRatio="none" style="width:100%; height:100%;">';
                            // axes
                            svg += '<line x1="'+pad+'" y1="'+pad+'" x2="'+pad+'" y2="'+(h-pad)+'" stroke="#e6e7eb" stroke-width="1"/>';
                            svg += '<line x1="'+pad+'" y1="'+(h-pad)+'" x2="'+(w-pad)+'" y2="'+(h-pad)+'" stroke="#e6e7eb" stroke-width="1"/>';
                            // grid
                            for (let i=0;i<=4;i++){
                                const gy = pad + i * ((h - pad*2)/4);
                                svg += '<line x1="'+pad+'" y1="'+gy+'" x2="'+(w-pad)+'" y2="'+gy+'" stroke="#f3f4f6" stroke-width="1"/>';
                            }
                            // line
                            svg += '<path d="'+path+'" fill="none" stroke="'+color+'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />';
                            // points and labels
                            const monthsShort = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
                            values.forEach((v,i)=>{
                                const x = pad + i * stepX;
                                const y = h - pad - (v / max) * (h - pad*2);
                                svg += '<circle cx="'+x+'" cy="'+y+'" r="3" fill="'+color+'" />';
                                svg += '<text x="'+x+'" y="'+(h - pad + 12)+'" font-size="9" text-anchor="middle" fill="#374151">'+monthsShort[i]+'</text>';
                            });
                            svg += '</svg>';
                            container.innerHTML = svg;
                        }

                        document.addEventListener('DOMContentLoaded', async function(){
                            // Render monthly guests chart
                            const monthlyGuestsData = <?php echo json_encode($guestsByMonth); ?>;
                            renderLineChart('monthlyGuestsChart', monthlyGuestsData, '#667eea');

                            // Render monthly bookings chart
                            const months = await fetchMonthly();
                            if (!months) return;
                            const values = [];
                            for (let i=1;i<=12;i++) values.push(months[i] || 0);
                            renderLineChart('monthlyBookingsChart', values);
                        });
                    })();
                    </script>
                </div>

                <!-- Manage Reservations Section -->
                <div id="reservations" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-calendar-check"></i> Manage Reservations</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">View, approve, update, or cancel customer reservations for rooms and resort facilities.</p>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reservation ID</th>
                                    <th>Guest Name</th>
                                    <th>Contact Number</th>
                                    <th>Items Booked</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Total Amount</th>
                                    <th>Guests</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentReservations as $reservation): ?>
                                <tr>
                                    <td>#<?php echo $reservation['id']; ?></td>
                                    <td><?php echo htmlspecialchars($reservation['name']); ?></td>
                                    <td><?php echo htmlspecialchars(trim((string)($reservation['guest_phone'] ?? '')) !== '' ? $reservation['guest_phone'] : 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($reservation['items']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($reservation['check_in'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($reservation['check_out'])); ?></td>
                                    <td>₱<?php echo number_format($reservation['total_amount'], 2); ?></td>
                                    <td>
                                        <strong><?php echo ($reservation['adults'] ?? 0) + ($reservation['children'] ?? 0); ?></strong>
                                        <small style="color: var(--text-light); display: block;">
                                            <?php echo ($reservation['adults'] ?? 0); ?> adults, <?php echo ($reservation['children'] ?? 0); ?> children
                                        </small>
                                    </td>
                                    <?php $reservationStatus = empty($reservation['status']) ? 'pending' : $reservation['status']; ?>
                                    <td><span class="status-badge status-<?php echo strtolower(htmlspecialchars($reservationStatus)); ?>"><?php echo ucfirst(htmlspecialchars($reservationStatus)); ?></span></td>
                                    <td>
                                        <?php if ($reservationStatus === 'pending'): ?>
                                        <div style="display:flex; align-items:center; justify-content:center; gap:0.5rem; flex-wrap:nowrap; white-space:nowrap;">
                                            <button class="btn-small btn-approve" onclick="openApproveReservationModal(<?php echo (int)$reservation['id']; ?>)">Approve</button>
                                            <button class="btn-small btn-reject" onclick="openCancelReservationModal(<?php echo (int)$reservation['id']; ?>)">Cancel</button>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Booking Records Section -->
                <div id="booking-records" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-history"></i> Booking Records</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Access a centralized database of all transactions, including past, current, and upcoming reservations.</p>
                    
                    <div class="filter-controls" style="margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                        <select id="recordFilter" style="padding: 0.7rem; border: 1px solid var(--border-gray); border-radius: 6px; background: white; font-size: 0.95rem;">
                            <option value="all">All Records</option>
                            <option value="upcoming">Upcoming</option>
                            <option value="past">Past</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <input type="text" id="searchRecords" placeholder="Search by guest name or reservation ID..." style="padding: 0.7rem; border: 1px solid var(--border-gray); border-radius: 6px; font-size: 0.95rem; min-width: 280px;">
                    </div>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Guest</th>
                                    <th>Contact</th>
                                    <th>Items</th>
                                    <th>Dates</th>
                                    <th>Tour Hours</th>
                                    <th>Guests</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="recordsTableBody">
                                <!-- Records will be populated here -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Manage Cottages Section -->
                <div id="cottages" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-home"></i> Manage Cottages</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Add, edit, or remove available cottages and adjust availability schedules. Changes will appear on the public cottage list.</p>

                    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom: 1.5rem; flex-wrap:wrap;">
                        <button class="btn-primary" onclick="openAddCottageModal()"><i class="fas fa-plus"></i> Add New Cottage</button>
                        
                        <?php
                        $selectedDateCottages = $_GET['date'] ?? null;
                        
                        if ($selectedDateCottages): ?>
                        <div style="background: #e0f2fe; padding: 0.5rem 1rem; border-radius: 6px; border-left: 4px solid var(--primary-blue);">
                            <strong>Selected Date:</strong> <?php echo date('F d, Y', strtotime($selectedDateCottages)); ?>
                            <a href="dashboard.php?section=cottages" style="background: none; border: none; color: var(--primary-blue); cursor: pointer; margin-left: 0.5rem; text-decoration: underline; font-size: 0.85rem;">Clear</a>
                        </div>
                        <?php endif; ?>
                        
                        <button class="btn-primary" type="button" onclick="openArchiveModal('cottages')" style="background: var(--primary-blue);"><i class="fas fa-box-archive"></i> View Archived</button>
                        <button class="btn-primary" onclick="openDateModal()" style="background: var(--primary-blue);"><i class="fas fa-calendar"></i> Check Availability</button>
                        <?php if (!empty($editingCottage)): ?>
                            <a href="dashboard.php?section=cottages" class="btn-small btn-delete" style="text-decoration:none;">Cancel Edit</a>
                        <?php endif; ?>
                    </div>

                    <?php
                    // Calculate remaining slots for each cottage on selected date
                    $cottageAvailability = [];
                    if ($selectedDateCottages) {
                        foreach ($cottages as $cottage) {
                            $cottageName = $cottage['name'];
                            $limit = $cottage['daily_slots'] ?? 1;
                            
                            // Count existing reservations for this cottage on selected date
                            $countSql = "SELECT COUNT(*) as count 
                                        FROM reservation_items ri 
                                        JOIN reservations r ON ri.reservation_id = r.id 
                                        WHERE ri.item_name = ? AND ri.item_type = 'cottage' 
                                        AND r.check_in = ? AND r.status != 'cancelled'";
                            $countStmt = $conn->prepare($countSql);
                            $countStmt->bind_param("ss", $cottageName, $selectedDateCottages);
                            $countStmt->execute();
                            $countResult = $countStmt->get_result();
                            $bookedCount = $countResult->fetch_assoc()['count'] ?? 0;
                            
                            $remainingSlots = max(0, $limit - $bookedCount);
                            $cottageAvailability[$cottage['id']] = [
                                'limit' => $limit,
                                'booked' => $bookedCount,
                                'remaining' => $remainingSlots
                            ];
                        }
                    } else {
                        // Set default availability when no date is selected
                        foreach ($cottages as $cottage) {
                            $limit = $cottage['daily_slots'] ?? 1;
                            $cottageAvailability[$cottage['id']] = [
                                'limit' => $limit,
                                'booked' => 0,
                                'remaining' => $limit
                            ];
                        }
                    }
                    ?>

                    <?php if (!empty($cottageErrorMessage)): ?>
                        <div style="background:#fee2e2; color:#991b1b; padding:0.75rem 1rem; border-radius:6px; margin-bottom:1rem;"><?php echo htmlspecialchars($cottageErrorMessage); ?></div>
                    <?php endif; ?>

                    <div class="cottages-container">
                    <?php foreach ($cottages as $cottage): ?>
                    <?php $availability = $cottageAvailability[$cottage['id']]; ?>
                    <div class="cottage-card" style="background: #f3f4f6; border-radius: 12px; padding: 1.5rem; text-align: center;">
                        <h3 style="color: var(--primary-blue); margin: 0 0 1rem 0; font-size: 1.2rem;"><?php echo htmlspecialchars($cottage['name'] ?? ''); ?></h3>
                        
                        <div style="height: 150px; background: #e5e7eb; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; overflow: hidden;">
                            <?php 
                            $imageUrl = $cottage['image_url'] ?? '';
                            if (!empty($imageUrl)): 
                                if (preg_match('/^https?:\/\//i', $imageUrl)) {
                                    $displayImage = $imageUrl;
                                } elseif (strpos($imageUrl, '/') === 0) {
                                    $displayImage = rtrim(SITE_URL, '/') . $imageUrl;
                                } else {
                                    $displayImage = SITE_URL . $imageUrl;
                                }
                            ?>
                                <img src="<?php echo htmlspecialchars($displayImage); ?>" alt="<?php echo htmlspecialchars($cottage['name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <span style="color: #9ca3af; font-size: 0.9rem;">Actual Image</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($selectedDateCottages): ?>
                        <div style="background: white; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                            <div style="color: #64748b; font-size: 0.9rem; margin-bottom: 0.5rem;">Remaining slots</div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: <?php echo $availability['remaining'] > 0 ? '#10b981' : '#ef4444'; ?>;">
                                <?php echo $availability['remaining']; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div style="display: flex; gap: 0.5rem; justify-content: center;">
                            <button class="btn-small btn-edit" onclick="openEditCottageModal(<?php echo (int)$cottage['id']; ?>, '<?php echo str_replace("'", "\\'", htmlspecialchars($cottage['name'] ?? '', ENT_QUOTES)); ?>', <?php echo (int)($cottage['capacity'] ?? 0); ?>, <?php echo (float)($cottage['price_per_night'] ?? 0); ?>, <?php echo (int)($cottage['day_slots'] ?? $cottage['daily_slots'] ?? 1); ?>, <?php echo (int)($cottage['night_slots'] ?? $cottage['daily_slots'] ?? 1); ?>, '<?php echo str_replace("'", "\\'", htmlspecialchars($cottage['image_url'] ?? '', ENT_QUOTES)); ?>', '<?php echo str_replace("'", "\\'", htmlspecialchars($cottage['description'] ?? '', ENT_QUOTES)); ?>', <?php echo !empty($cottage['available']) ? 'true' : 'false'; ?>)">Edit</button>
                            <form method="post" action="dashboard.php?section=cottages" style="display:inline-block;" data-delete-form="cottage" onsubmit="event.preventDefault(); openDeleteConfirmModal(this);">
                                <input type="hidden" name="cottage_action" value="delete_cottage">
                                <input type="hidden" name="cottage_id" value="<?php echo (int)$cottage['id']; ?>">
                                        <button type="submit" class="btn-small btn-delete">Delete</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>

                    <!-- Add Cottage Modal -->
                    <div id="addCottageModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
                        <div style="background:white; padding:2rem; border-radius:8px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                                <h2 style="color:var(--primary-blue); margin:0;">Add New Cottage</h2>
                                <button onclick="closeAddCottageModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
                            </div>
                            <form method="post" action="dashboard.php?section=cottages" enctype="multipart/form-data">
                                <input type="hidden" name="cottage_action" value="save_cottage">
                                <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem;">
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Cottage Name</label>
                                        <input type="text" name="cottage_name" value="" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Capacity</label>
                                        <input type="number" name="cottage_capacity" min="1" value="2" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Price per Night</label>
                                        <input type="number" step="0.01" name="cottage_price" min="0" value="0" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Day Tour Slots</label>
                                        <input type="number" name="cottage_day_slots" min="1" value="1" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                        <small style="color: var(--text-light); font-size: 0.8rem;">Maximum day-tour bookings</small>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Night Tour Slots</label>
                                        <input type="number" name="cottage_night_slots" min="1" value="1" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                        <small style="color: var(--text-light); font-size: 0.8rem;">Maximum night-tour bookings</small>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Upload Image</label>
                                        <input type="file" name="cottage_image" accept="image/*" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Description</label>
                                        <textarea name="cottage_description" rows="4" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;"></textarea>
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:flex; align-items:center; gap:0.5rem; font-weight:600;">
                                            <input type="checkbox" name="cottage_available" value="1" checked>
                                            Available for booking
                                        </label>
                                    </div>
                                </div>
                                <div style="margin-top:1.5rem; display:flex; gap:1rem;">
                                    <button type="submit" class="btn-primary">Save Cottage</button>
                                    <button type="button" class="btn-small btn-delete" onclick="closeAddCottageModal()">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Edit Cottage Modal -->
                    <div id="editCottageModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
                        <div style="background:white; padding:2rem; border-radius:8px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                                <h2 style="color:var(--primary-blue); margin:0;">Edit Cottage</h2>
                                <button onclick="closeEditCottageModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
                            </div>
                            <form method="post" action="dashboard.php?section=cottages" enctype="multipart/form-data">
                                <input type="hidden" name="cottage_action" value="save_cottage">
                                <input type="hidden" name="cottage_id" id="editCottageId" value="">
                                <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem;">
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Cottage Name</label>
                                        <input type="text" name="cottage_name" id="editCottageName" value="" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Capacity</label>
                                        <input type="number" name="cottage_capacity" id="editCottageCapacity" min="1" value="2" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Price per Night</label>
                                        <input type="number" step="0.01" name="cottage_price" id="editCottagePrice" min="0" value="0" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Day Tour Slots</label>
                                        <input type="number" name="cottage_day_slots" id="editCottageDaySlots" min="1" value="1" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                        <small style="color: var(--text-light); font-size: 0.8rem;">Maximum day-tour bookings</small>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Night Tour Slots</label>
                                        <input type="number" name="cottage_night_slots" id="editCottageNightSlots" min="1" value="1" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                        <small style="color: var(--text-light); font-size: 0.8rem;">Maximum night-tour bookings</small>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Upload New Image (optional)</label>
                                        <input type="file" name="cottage_image" id="editCottageImage" accept="image/*" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Add Images to Cottage Album</label>
                                        <input type="file" name="cottage_images[]" id="editCottageGallery" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                        <small style="color: var(--text-light); font-size: 0.8rem;">Select multiple images. Maximum 8 MB per image.</small>
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Description</label>
                                        <textarea name="cottage_description" id="editCottageDescription" rows="4" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;"></textarea>
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:flex; align-items:center; gap:0.5rem; font-weight:600;">
                                            <input type="checkbox" name="cottage_available" id="editCottageAvailable" value="1">
                                            Available for booking
                                        </label>
                                    </div>
                                </div>
                                <div style="margin-top:1.5rem; display:flex; gap:1rem;">
                                    <button type="submit" class="btn-primary">Save Changes</button>
                                    <button type="button" class="btn-small btn-delete" onclick="closeEditCottageModal()">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Manage Pools Section -->
                <div id="pools" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-swimming-pool"></i> Manage Pools</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Manage swimming pool facilities and their availability. Changes will appear on the public pool list.</p>

                    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom: 1.5rem; flex-wrap:wrap;">
                        <button class="btn-primary" onclick="openAddPoolModal()"><i class="fas fa-plus"></i> Add New Pool</button>
                        <button class="btn-primary" type="button" onclick="openArchiveModal('pools')" style="background: var(--primary-blue);"><i class="fas fa-box-archive"></i> View Archived</button>
                        <?php if (!empty($editingPool)): ?>
                            <a href="dashboard.php?section=pools" class="btn-small btn-delete" style="text-decoration:none;">Cancel Edit</a>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($poolErrorMessage)): ?>
                        <div style="background:#fee2e2; color:#991b1b; padding:0.75rem 1rem; border-radius:6px; margin-bottom:1rem;"><?php echo htmlspecialchars($poolErrorMessage); ?></div>
                    <?php endif; ?>

                    <!-- Add Pool Modal -->
                    <div id="addPoolModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
                        <div style="background:white; padding:2rem; border-radius:8px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                                <h2 style="color:var(--primary-blue); margin:0;">Add New Pool</h2>
                                <button onclick="closeAddPoolModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
                            </div>
                            <form method="post" action="dashboard.php?section=pools" enctype="multipart/form-data">
                                <input type="hidden" name="pool_action" value="save_pool">
                                <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem;">
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Pool Name</label>
                                        <input type="text" name="pool_name" value="" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Capacity</label>
                                        <input type="number" name="pool_capacity" min="1" value="10" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Status</label>
                                        <select name="pool_status" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                            <option value="active" selected>Active</option>
                                            <option value="maintenance">Maintenance</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Upload Image</label>
                                        <input type="file" name="pool_image" accept="image/*" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Description</label>
                                        <textarea name="pool_description" rows="4" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;"></textarea>
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Features</label>
                                        <input type="text" name="pool_features" value="" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:flex; align-items:center; gap:0.5rem; font-weight:600;">
                                            <input type="checkbox" name="pool_available" value="1" checked>
                                            Available for guests
                                        </label>
                                    </div>
                                </div>
                                <div style="margin-top:1.5rem; display:flex; gap:1rem;">
                                    <button type="submit" class="btn-primary">Save Pool</button>
                                    <button type="button" class="btn-small btn-delete" onclick="closeAddPoolModal()">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Edit Pool Modal -->
                    <div id="editPoolModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
                        <div style="background:white; padding:2rem; border-radius:8px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                                <h2 style="color:var(--primary-blue); margin:0;">Edit Pool</h2>
                                <button onclick="closeEditPoolModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
                            </div>
                            <form method="post" action="dashboard.php?section=pools" enctype="multipart/form-data">
                                <input type="hidden" name="pool_action" value="save_pool">
                                <input type="hidden" name="pool_id" id="editPoolId" value="">
                                <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem;">
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Pool Name</label>
                                        <input type="text" name="pool_name" id="editPoolName" value="" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Capacity</label>
                                        <input type="number" name="pool_capacity" id="editPoolCapacity" min="1" value="20" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Status</label>
                                        <select name="pool_status" id="editPoolStatus" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                            <option value="active">Active</option>
                                            <option value="maintenance">Maintenance</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Upload New Image (optional)</label>
                                        <input type="file" name="pool_image" id="editPoolImage" accept="image/*" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Description</label>
                                        <textarea name="pool_description" id="editPoolDescription" rows="4" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;"></textarea>
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Features</label>
                                        <input type="text" name="pool_features" id="editPoolFeatures" value="" placeholder="e.g., Deep end, Shallow end, Slides" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:flex; align-items:center; gap:0.5rem; font-weight:600;">
                                            <input type="checkbox" name="pool_available" id="editPoolAvailable" value="1">
                                            Available for use
                                        </label>
                                    </div>
                                </div>
                                <div style="margin-top:1.5rem; display:flex; gap:1rem;">
                                    <button type="submit" class="btn-primary">Save Changes</button>
                                    <button type="button" class="btn-small btn-delete" onclick="closeEditPoolModal()">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="pools-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem;">
                    <?php foreach ($pools as $pool): ?>
                    <div class="pool-card" style="background: #f3f4f6; border-radius: 12px; padding: 1.5rem; text-align: center;">
                        <h3 style="color: var(--primary-blue); margin: 0 0 1rem 0; font-size: 1.2rem;"><?php echo htmlspecialchars($pool['name'] ?? ''); ?></h3>

                        <div style="height: 150px; background: #e5e7eb; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; overflow: hidden;">
                            <?php
                            $imageUrl = $pool['image_url'] ?? '';
                            if (!empty($imageUrl)):
                                if (preg_match('/^https?:\/\//i', $imageUrl)) {
                                    $displayImage = $imageUrl;
                                } elseif (strpos($imageUrl, '/') === 0) {
                                    $displayImage = rtrim(SITE_URL, '/') . $imageUrl;
                                } else {
                                    $displayImage = SITE_URL . $imageUrl;
                                }
                            ?>
                                <img src="<?php echo htmlspecialchars($displayImage); ?>" alt="<?php echo htmlspecialchars($pool['name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <span style="color: #9ca3af; font-size: 0.9rem;">Pool Image</span>
                            <?php endif; ?>
                        </div>

                        <div style="margin-bottom: 1rem;">
                            <span class="status-badge status-<?php echo htmlspecialchars($pool['status'] ?? 'active'); ?>" style="padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                <?php echo ucfirst(htmlspecialchars($pool['status'] ?? 'active')); ?>
                            </span>
                            <span style="margin-left: 0.5rem; color: #64748b; font-size: 0.85rem;">
                                <?php echo !empty($pool['available']) ? 'Available' : 'Unavailable'; ?>
                            </span>
                        </div>

                        <div style="display: flex; gap: 0.5rem; justify-content: center;">
                            <button class="btn-small btn-edit" onclick="openEditPoolModal(<?php echo (int)$pool['id']; ?>, '<?php echo str_replace("'", "\\'", htmlspecialchars($pool['name'] ?? '', ENT_QUOTES)); ?>', <?php echo (int)($pool['capacity'] ?? 0); ?>, '<?php echo str_replace("'", "\\'", htmlspecialchars($pool['status'] ?? 'active', ENT_QUOTES)); ?>', '<?php echo str_replace("'", "\\'", htmlspecialchars($pool['image_url'] ?? '', ENT_QUOTES)); ?>', '<?php echo str_replace("'", "\\'", htmlspecialchars($pool['description'] ?? '', ENT_QUOTES)); ?>', '<?php echo str_replace("'", "\\'", htmlspecialchars($pool['features'] ?? '', ENT_QUOTES)); ?>', <?php echo !empty($pool['available']) ? 'true' : 'false'; ?>)">Edit</button>
                            <form method="post" action="dashboard.php?section=pools" style="display:inline-block;" data-delete-form="pool" onsubmit="event.preventDefault(); openDeleteConfirmModal(this);">
                                <input type="hidden" name="pool_action" value="delete_pool">
                                <input type="hidden" name="pool_id" value="<?php echo (int)$pool['id']; ?>">
                                        <button type="submit" class="btn-small btn-delete">Delete</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>

                <!-- Manage Foods Section -->
                <div id="foods" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-utensils"></i> Manage Food</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Add, edit, or remove food items and categorize them for the public menu.</p>

                    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom: 1rem; flex-wrap:wrap;">
                        <button class="btn-primary" onclick="openAddFoodModal()"><i class="fas fa-plus"></i> Add New Food</button>
                            <button class="btn-primary" type="button" onclick="openArchiveModal('foods')" style="background: var(--primary-blue);"><i class="fas fa-box-archive"></i> View Archived</button>
                        <?php if (!empty($errorMessage)): ?>
                            <div style="color:#991b1b;"><?php echo htmlspecialchars($errorMessage); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Category Dropdown -->
                    <div style="margin-bottom: 1.5rem; display: flex; justify-content: flex-start;">
                        <select id="adminFoodCategorySelect" class="admin-food-category-select" aria-label="Filter food by category">
                            <option value="all" selected>All</option>
                            <option value="Starters">Starters</option>
                            <option value="Main Course">Main Course</option>
                            <option value="Soups">Soups</option>
                            <option value="All Day Breakfast">All Day Breakfast</option>
                            <option value="Hot Beverages">Hot Beverages</option>
                            <option value="Non-Alcoholic">Non-Alcoholic</option>
                            <option value="Sides">Sides</option>
                            <option value="Vegetables">Vegetables</option>
                            <option value="Rice Meals">Rice Meals</option>
                            <option value="Dessert">Dessert</option>
                            <option value="Cocktails">Cocktails</option>
                        </select>
                    </div>

                    <!-- Add Food Modal -->
                    <div id="addFoodModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
                        <div style="background:white; padding:2rem; border-radius:8px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                                <h2 style="color:var(--primary-blue); margin:0;">Add New Food</h2>
                                <button onclick="closeAddFoodModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
                            </div>
                            <form method="post" action="dashboard.php?section=foods" enctype="multipart/form-data">
                                <input type="hidden" name="food_action" value="save_food">
                                <input type="hidden" name="food_id" id="editFoodId" value="">
                                <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem;">
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Food Name</label>
                                        <input type="text" name="food_name" value="" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Category</label>
                                        <select name="food_category" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                            <?php
                                            $categories = ['Starters','Main Course','Soups','All Day Breakfast','Hot Beverages','Non-Alcoholic','Sides','Vegetables','Rice Meals','Dessert','Cocktails'];
                                            foreach ($categories as $cat) {
                                                echo '<option value="' . htmlspecialchars($cat) . '">' . htmlspecialchars($cat) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Price</label>
                                        <input type="number" step="0.01" name="food_price" min="0" value="0" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Status</label>
                                        <select name="food_status" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                            <option value="active" selected>Active</option>
                                            <option value="inactive">Inactive</option>
                                        </select>
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Description</label>
                                        <textarea name="food_description" rows="3" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;"></textarea>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Upload Image</label>
                                        <input type="file" name="food_image" accept="image/*" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                                    </div>
                                    <div style="display:flex; align-items:center; gap:0.5rem;">
                                        <label style="display:flex; align-items:center; gap:0.5rem; font-weight:600;">
                                            <input type="checkbox" name="food_available" value="1" checked>
                                            Available
                                        </label>
                                    </div>
                                </div>
                                <div style="margin-top:1.5rem; display:flex; gap:1rem;">
                                    <button type="submit" class="btn-primary">Save Food</button>
                                    <button type="button" class="btn-small btn-delete" onclick="closeAddFoodModal()">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Foods List -->
                    <div class="foods-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem;">
                        <?php if (!empty($foods)): ?>
                            <?php foreach ($foods as $food): ?>
                            <div class="food-card food-table-row" data-search="<?php echo htmlspecialchars(strtolower(($food['name'] ?? '') . ' ' . ($food['category'] ?? '') . ' ' . ($food['description'] ?? ''))); ?>" data-category="<?php echo htmlspecialchars($food['category'] ?? ''); ?>" style="background: #f3f4f6; border-radius: 12px; padding: 1.5rem; text-align: center;">
                                <h3 style="color: var(--primary-blue); margin: 0 0 0.5rem 0; font-size: 1.1rem;"><?php echo htmlspecialchars($food['name'] ?? ''); ?></h3>
                                <p style="color: #64748b; font-size: 0.85rem; margin: 0 0 1rem 0;"><?php echo htmlspecialchars($food['category'] ?? ''); ?></p>

                                <div style="height: 150px; background: #e5e7eb; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; overflow: hidden;">
                                    <?php
                                    $imageUrl = $food['image_url'] ?? '';
                                    if (!empty($imageUrl)):
                                        if (preg_match('/^https?:\/\//i', $imageUrl)) {
                                            $displayImage = $imageUrl;
                                        } elseif (strpos($imageUrl, '/') === 0) {
                                            $displayImage = rtrim(SITE_URL, '/') . $imageUrl;
                                        } else {
                                            $displayImage = SITE_URL . $imageUrl;
                                        }
                                    ?>
                                        <img src="<?php echo htmlspecialchars($displayImage); ?>" alt="<?php echo htmlspecialchars($food['name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <span style="color: #9ca3af; font-size: 0.9rem;">Food Image</span>
                                    <?php endif; ?>
                                </div>

                                <div style="background: white; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;">
                                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--primary-blue);">
                                        ₱<?php echo number_format((float)$food['price'], 2); ?>
                                    </div>
                                </div>

                                <div style="display: flex; gap: 0.5rem; justify-content: center;">
                                    <button class="btn-small btn-edit" onclick="openEditFoodModal(<?php echo (int)$food['id']; ?>, '<?php echo str_replace("'", "\\'", htmlspecialchars($food['name'], ENT_QUOTES)); ?>', '<?php echo str_replace("'", "\\'", htmlspecialchars($food['category'], ENT_QUOTES)); ?>', <?php echo (float)$food['price']; ?>, '<?php echo str_replace("'", "\\'", htmlspecialchars($food['status'] ?? 'active', ENT_QUOTES)); ?>', '<?php echo str_replace("'", "\\'", htmlspecialchars($food['image_url'] ?? '', ENT_QUOTES)); ?>', <?php echo !empty($food['available']) ? 'true' : 'false'; ?>, '<?php echo str_replace("'", "\\'", htmlspecialchars($food['description'] ?? '', ENT_QUOTES)); ?>')">Edit</button>
                                    <form method="post" action="dashboard.php?section=foods" style="display:inline-block;" data-delete-form="food" onsubmit="event.preventDefault(); openDeleteConfirmModal(this);">
                                        <input type="hidden" name="food_action" value="delete_food">
                                        <input type="hidden" name="food_id" value="<?php echo (int)$food['id']; ?>">
                                        <button type="submit" class="btn-small btn-delete">Archive</button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="grid-column: 1 / -1; text-align: center; color: var(--text-light); padding: 1.5rem;">No food items yet.</div>
                        <?php endif; ?>
                        <div id="foodNoResultsRow" style="display:none; grid-column: 1 / -1; text-align:center; color:var(--text-light); padding:1.5rem;">No food items matched your search.</div>
                    </div>
                </div>

                <script>
                let currentCategory = 'all';
                let foodRows = [];
                let foodEmptyRow = null;

                window.filterFoodByCategory = function(category) {
                    currentCategory = category;

                    const categorySelect = document.getElementById('adminFoodCategorySelect');
                    if (categorySelect && categorySelect.value !== category) {
                        categorySelect.value = category;
                    }

                    // Apply filter
                    filterFoodRows();
                };

                function filterFoodRows() {
                    let visibleCount = 0;

                    foodRows.forEach(function (row) {
                        const category = row.getAttribute('data-category') || '';
                        const matchesCategory = currentCategory === 'all' || category === currentCategory;

                        row.style.display = matchesCategory ? '' : 'none';
                        if (matchesCategory) visibleCount++;
                    });

                    if (foodEmptyRow) {
                        foodEmptyRow.style.display = visibleCount === 0 ? '' : 'none';
                    }
                }

                document.addEventListener('DOMContentLoaded', function () {
                    foodRows = Array.from(document.querySelectorAll('.food-table-row'));
                    foodEmptyRow = document.getElementById('foodNoResultsRow');

                    const categorySelect = document.getElementById('adminFoodCategorySelect');
                    if (categorySelect) {
                        categorySelect.addEventListener('change', function () {
                            filterFoodByCategory(this.value);
                        });
                    }

                    filterFoodRows();
                });
                </script>

                <!-- All Facilities Section -->
                <div id="facilities" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-building"></i> All Facilities</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Overview of all resort facilities and their current status.</p>
                    
                    <div class="facilities-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                        <!-- Rooms Summary -->
                        <div class="facility-card" style="background: var(--bg-light); padding: 1.5rem; border-radius: 10px; border-left: 4px solid var(--primary-blue);">
                            <h3 style="color: var(--primary-blue); margin-bottom: 1rem;"><i class="fas fa-door-open"></i> Rooms</h3>
                            <p>Total: <?php echo $conn->query("SELECT COUNT(*) as count FROM rooms")->fetch_assoc()['count']; ?></p>
                            <p>Available: <?php echo $conn->query("SELECT COUNT(*) as count FROM rooms WHERE available = 1")->fetch_assoc()['count']; ?></p>
                            <p>Occupied: <?php echo $conn->query("SELECT COUNT(*) as count FROM rooms WHERE available = 0")->fetch_assoc()['count']; ?></p>
                        </div>
                        
                        <!-- Cottages Summary -->
                        <div class="facility-card" style="background: var(--bg-light); padding: 1.5rem; border-radius: 10px; border-left: 4px solid var(--accent-orange);">
                            <h3 style="color: var(--accent-orange); margin-bottom: 1rem;"><i class="fas fa-home"></i> Cottages</h3>
                            <p>Total: <?php echo $conn->query("SELECT COUNT(*) as count FROM cottages")->fetch_assoc()['count']; ?></p>
                            <p>Available: <?php echo $conn->query("SELECT COUNT(*) as count FROM cottages WHERE available = 1")->fetch_assoc()['count']; ?></p>
                            <p>Occupied: <?php echo $conn->query("SELECT COUNT(*) as count FROM cottages WHERE available = 0")->fetch_assoc()['count']; ?></p>
                        </div>
                        
                        <!-- Pools Summary -->
                        <div class="facility-card" style="background: var(--bg-light); padding: 1.5rem; border-radius: 10px; border-left: 4px solid #3b82f6;">
                            <h3 style="color: #3b82f6; margin-bottom: 1rem;"><i class="fas fa-swimming-pool"></i> Pools</h3>
                            <p>Total: <?php echo $conn->query("SELECT COUNT(*) as count FROM pools")->fetch_assoc()['count']; ?></p>
                            <p>Active: <?php echo $conn->query("SELECT COUNT(*) as count FROM pools WHERE status = 'active'")->fetch_assoc()['count']; ?></p>
                            <p>Maintenance: <?php echo $conn->query("SELECT COUNT(*) as count FROM pools WHERE status = 'maintenance'")->fetch_assoc()['count']; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Pricing & Packages Section -->
                <div id="pricing" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-tag"></i> Pricing & Packages</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Update pricing for rooms, facilities, and food menu items. Modify rates based on peak seasons or promotional offers.</p>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <button class="btn-edit" onclick="addNewPackage()"><i class="fas fa-plus"></i> Create Package</button>
                        <button class="btn-edit" style="margin-left: 1rem;" onclick="updateSeasonalPricing()"><i class="fas fa-calendar"></i> Seasonal Pricing</button>
                    </div>
                    
                    <div class="pricing-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                        <!-- Current pricing will be displayed here -->
                    </div>
                </div>

                <!-- Facility Scheduling Section -->
                <div id="scheduling" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-clock"></i> Facility Scheduling</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Control time slots and booking schedules for resort amenities to ensure proper allocation and prevent overlaps.</p>
                    
                    <div class="schedule-controls" style="margin-bottom: 1.5rem;">
                        <select id="facilitySelect" style="padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 5px;">
                            <option value="">Select Facility</option>
                        </select>
                        <input type="date" id="scheduleDate" style="padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 5px; margin-left: 1rem;">
                        <button class="btn-edit" style="margin-left: 1rem;" onclick="loadSchedule()">Load Schedule</button>
                    </div>
                    
                    <div class="schedule-view" style="background: var(--bg-light); padding: 1.5rem; border-radius: 10px;">
                        <h4>Time Slots</h4>
                        <div id="timeSlots">
                            <!-- Time slots will be populated here -->
                        </div>
                    </div>
                </div>

                <!-- Reports Section -->
                <div id="reports" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-file-alt"></i> Reports</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Generate summaries of reservations, occupancy rates, and usage of facilities to support decision-making.</p>
                    
                    <div class="report-controls" style="margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                        <select id="reportType" style="padding: 0.7rem; border: 1px solid var(--border-gray); border-radius: 6px; background: white; font-size: 0.95rem;">
                            <option value="revenue">Revenue Report</option>
                            <option value="customer">Customer Analytics</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                        <input type="date" id="reportStartDate" style="padding: 0.7rem; border: 1px solid var(--border-gray); border-radius: 6px; font-size: 0.95rem;">
                        <input type="date" id="reportEndDate" style="padding: 0.7rem; border: 1px solid var(--border-gray); border-radius: 6px; font-size: 0.95rem;">
                        <button class="btn-primary" onclick="generateReport()"><i class="fas fa-sync-alt"></i> Generate Report</button>
                    </div>
                    
                    <div class="report-content" style="background: var(--bg-light); padding: 1.5rem; border-radius: 10px;">
                        <div id="reportResults">
                            <p style="color: var(--text-light);">Select report parameters and click "Generate Report" to view results.</p>
                        </div>
                    </div>
                </div>

                <!-- Maintenance Section -->
                <div id="maintenance" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-tools"></i> Maintenance Management</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Manage maintenance and repair fees for resort facilities.</p>
                    
                    <div class="report-controls" style="margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                        <input type="date" id="maintenanceStartDate" style="padding: 0.7rem; border: 1px solid var(--border-gray); border-radius: 6px; font-size: 0.95rem;">
                        <input type="date" id="maintenanceEndDate" style="padding: 0.7rem; border: 1px solid var(--border-gray); border-radius: 6px; font-size: 0.95rem;">
                        <button class="btn-primary" onclick="generateMaintenanceReportFromSection()"><i class="fas fa-sync-alt"></i> Generate Report</button>
                        <button class="btn-primary" onclick="openAddFeeModal()"><i class="fas fa-plus"></i> Add Maintenance Fee</button>
                    </div>
                    
                    <div class="report-content" style="background: var(--bg-light); padding: 1.5rem; border-radius: 10px;">
                        <div id="maintenanceResults">
                            <p style="color: var(--text-light);">Select date range and click "Generate Report" to view maintenance records.</p>
                        </div>
                    </div>
                </div>

                <!-- Customers Section -->
                <div id="customers" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-users"></i> Customers</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">View and manage customer information and booking history.</p>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Total Bookings</th>
                                    <th>Total Spent</th>
                                    <th>Last Booking</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $customersSql = "SELECT u.*, COUNT(r.id) as booking_count, COALESCE(SUM(r.total_amount), 0) as total_spent, MAX(r.created_at) as last_booking FROM users u LEFT JOIN reservations r ON u.id = r.user_id WHERE u.role = 'user' GROUP BY u.id ORDER BY u.fullname";
                                $customersResult = $conn->query($customersSql);
                                while ($customer = $customersResult->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?php echo $customer['id']; ?></td>
                                    <td><?php echo htmlspecialchars($customer['fullname']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['phone'] ?? 'N/A'); ?></td>
                                    <td><?php echo $customer['booking_count']; ?></td>
                                    <td>₱<?php echo number_format($customer['total_spent'], 2); ?></td>
                                    <td><?php echo $customer['last_booking'] ? date('M d, Y', strtotime($customer['last_booking'])) : 'Never'; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Customer Concerns Section -->
                <div id="concerns" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-exclamation-triangle"></i> Customer Concerns</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Review and respond to booking issues, cancellations, or special requests made by customers.</p>
                    
                    <div class="concerns-filters" style="margin-bottom: 1.5rem;">
                        <button class="btn-edit" onclick="filterConcerns('all')">All Concerns</button>
                        <button class="btn-edit" style="margin-left: 0.5rem;" onclick="filterConcerns('pending')">Pending</button>
                        <button class="btn-edit" style="margin-left: 0.5rem;" onclick="filterConcerns('resolved')">Resolved</button>
                    </div>
                    
                    <div class="concerns-list">
                        <!-- Customer concerns will be displayed here -->
                        <div class="concern-item" style="background: var(--bg-light); padding: 1rem; border-radius: 5px; margin-bottom: 1rem; border-left: 4px solid var(--accent-orange);">
                            <h4>Sample Concern - Double Booking Issue</h4>
                            <p>Customer reported overlapping reservations for the same dates.</p>
                            <small>Submitted: May 1, 2026 | Status: <span class="status-badge status-pending">Pending</span></small>
                            <div style="margin-top: 0.5rem;">
                                <button class="btn-small btn-edit" onclick="respondToConcern(1)">Respond</button>
                                <button class="btn-small btn-approve" onclick="resolveConcern(1)">Mark Resolved</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Data Section -->
                <div id="system-data" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-database"></i> System Data</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Ensure all system information is accurate, updated, and consistent.</p>
                    
                    <div class="data-actions" style="margin-bottom: 1.5rem;">
                        <button class="btn-edit" onclick="backupDatabase()"><i class="fas fa-download"></i> Backup Database</button>
                        <button class="btn-edit" style="margin-left: 1rem;" onclick="validateData()"><i class="fas fa-check-circle"></i> Validate Data</button>
                        <button class="btn-edit" style="margin-left: 1rem;" onclick="cleanOldData()"><i class="fas fa-broom"></i> Clean Old Data</button>
                    </div>
                    
                    <div class="data-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div class="data-stat" style="background: var(--bg-light); padding: 1rem; border-radius: 5px; text-align: center;">
                            <h4>Users</h4>
                            <p style="font-size: 1.5rem; color: var(--primary-blue);"><?php echo $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count']; ?></p>
                        </div>
                        <div class="data-stat" style="background: var(--bg-light); padding: 1rem; border-radius: 5px; text-align: center;">
                            <h4>Reservations</h4>
                            <p style="font-size: 1.5rem; color: var(--accent-orange);"><?php echo $conn->query("SELECT COUNT(*) as count FROM reservations")->fetch_assoc()['count']; ?></p>
                        </div>
                        <div class="data-stat" style="background: var(--bg-light); padding: 1rem; border-radius: 5px; text-align: center;">
                            <h4>Reviews</h4>
                            <p style="font-size: 1.5rem; color: #10b981;"><?php echo $conn->query("SELECT COUNT(*) as count FROM reviews")->fetch_assoc()['count']; ?></p>
                        </div>
                        <div class="data-stat" style="background: var(--bg-light); padding: 1rem; border-radius: 5px; text-align: center;">
                            <h4>Database Size</h4>
                            <p style="font-size: 1.5rem; color: #8b5cf6;">~<?php echo round(filesize('../config/database.php') / 1024, 2); ?>KB</p>
                        </div>
                    </div>
                </div>

                <!-- System Monitoring Section -->
                <div id="monitoring" class="admin-section">
                    <h2 class="section-title"><i class="fas fa-heartbeat"></i> System Monitoring</h2>
                    <p style="color: var(--text-light); margin-bottom: 1.5rem;">Oversee the overall system performance, ensuring that transactions are processed correctly and data integrity is maintained.</p>
                    
                    <div class="monitoring-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                        <div class="monitor-card" style="background: var(--bg-light); padding: 1.5rem; border-radius: 10px;">
                            <h4>System Status</h4>
                            <div style="margin: 1rem 0;">
                                <p>Database: <span class="status-badge status-available">Connected</span></p>
                                <p>Server Load: <span class="status-badge status-available">Normal</span></p>
                                <p>Storage: <span class="status-badge status-available">85% Free</span></p>
                            </div>
                        </div>
                        
                        <div class="monitor-card" style="background: var(--bg-light); padding: 1.5rem; border-radius: 10px;">
                            <h4>Recent Activity</h4>
                            <div style="margin: 1rem 0;">
                                <p>New Reservations: <strong>12</strong> (today)</p>
                                <p>User Registrations: <strong>3</strong> (today)</p>
                                <p>System Errors: <strong>0</strong> (24h)</p>
                            </div>
                        </div>
                        
                        <div class="monitor-card" style="background: var(--bg-light); padding: 1.5rem; border-radius: 10px;">
                            <h4>Performance Metrics</h4>
                            <div style="margin: 1rem 0;">
                                <p>Avg Response Time: <strong>0.3s</strong></p>
                                <p>Uptime: <strong>99.9%</strong></p>
                                <p>API Calls: <strong>1,247</strong> (today)</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- Footer -->
    <footer class="footer" style="margin-top: 4rem;">
        <div class="container">
            <div class="footer-bottom">
                <p>&copy; 2024 Villa Soledad Resort Admin Panel. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
        function activateSection(section) {
            const targetSection = document.getElementById(section);
            const activeSection = targetSection ? section : 'dashboard';

            document.querySelectorAll('.admin-section').forEach(s => {
                s.classList.remove('active');
            });

            document.querySelectorAll('.menu-link').forEach(l => {
                l.classList.remove('active');
            });

            document.getElementById(activeSection).classList.add('active');

            document.querySelectorAll('.menu-link').forEach(link => {
                if (link.dataset.section === activeSection) {
                    link.classList.add('active');
                }
            });

            return activeSection;
        }

        const params = new URLSearchParams(window.location.search);
        const initialSection = params.get('section') || 'dashboard';

        // Admin navigation
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.menu-link').forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    const section = link.dataset.section;
                    const activeSection = activateSection(section);

                    const url = new URL(window.location.href);
                    url.searchParams.set('section', activeSection);
                    window.history.replaceState({}, '', url.toString());
                });
            });

            activateSection(initialSection);
        });

        let pendingDeleteForm = null;

        function openDeleteConfirmModal(form) {
            pendingDeleteForm = form;
            const modal = document.getElementById('deleteConfirmModal');
            if (!modal) return;
            modal.style.display = 'flex';
        }

        function closeDeleteConfirmModal() {
            const modal = document.getElementById('deleteConfirmModal');
            if (modal) modal.style.display = 'none';
            pendingDeleteForm = null;
        }

        function confirmDeleteAction() {
            if (pendingDeleteForm) {
                pendingDeleteForm.submit();
                return;
            }
            closeDeleteConfirmModal();
        }

        // Management notice popup (add=blue, edit=green, delete=red)
        function showManagementNotice(type, item) {
            const modal = document.getElementById('managementNoticeModal');
            const card = document.getElementById('managementNoticeCard');
            const iconEl = document.getElementById('managementNoticeIcon');
            const titleEl = document.getElementById('managementNoticeTitle');
            const messageEl = document.getElementById('managementNoticeMessage');
            const okBtn = document.getElementById('managementNoticeOkBtn');
            if (!modal || !card || !iconEl || !titleEl || !messageEl || !okBtn) return;

            const itemLabels = {
                room: 'Room',
                cottage: 'Cottage',
                pool: 'Pool',
                food: 'Food item'
            };
            const label = itemLabels[item] || 'Item';

            const configs = {
                added: {
                    color: '#2563eb',
                    icon: 'fas fa-plus-circle',
                    title: label + ' Added',
                    message: 'The ' + label.toLowerCase() + ' was added successfully.'
                },
                updated: {
                    color: '#16a34a',
                    icon: 'fas fa-check-circle',
                    title: label + ' Updated',
                    message: 'The ' + label.toLowerCase() + ' was updated successfully.'
                },
                deleted: {
                    color: '#dc2626',
                    icon: 'fas fa-trash-alt',
                    title: label + ' Deleted',
                    message: 'The ' + label.toLowerCase() + ' was deleted successfully.'
                }
            };

            const config = configs[type] || configs.updated;
            card.style.borderTopColor = config.color;
            iconEl.className = config.icon;
            iconEl.style.color = config.color;
            titleEl.textContent = config.title;
            titleEl.style.color = config.color;
            messageEl.textContent = config.message;
            okBtn.style.background = config.color;
            modal.style.display = 'flex';
        }

        function closeManagementNotice() {
            const modal = document.getElementById('managementNoticeModal');
            if (modal) modal.style.display = 'none';
        }

        function initManagementNoticeFromUrl() {
            const url = new URL(window.location.href);
            const notice = url.searchParams.get('notice');
            const item = url.searchParams.get('item');
            if (!notice || !['added', 'updated', 'deleted'].includes(notice)) return;
            if (!item || !['room', 'cottage', 'pool', 'food'].includes(item)) return;

            showManagementNotice(notice, item);
            url.searchParams.delete('notice');
            url.searchParams.delete('item');
            window.history.replaceState({}, '', url.toString());
        }

        // Modal is rendered after this script — wait for full DOM before showing notices
        document.addEventListener('DOMContentLoaded', function() {
            const managementModal = document.getElementById('managementNoticeModal');
            if (managementModal) {
                managementModal.addEventListener('click', function(e) {
                    if (e.target === managementModal) closeManagementNotice();
                });
            }
            initManagementNoticeFromUrl();
        });

        // Notification bell (real-time pending count)
        (function initNotificationBell() {
            const bellBtn = document.getElementById('notifBellBtn');
            const dropdown = document.getElementById('notifDropdown');
            const listEl = document.getElementById('notifDropdownList');
            const alertCountEl = document.getElementById('notifAlertCount');
            if (!bellBtn || !dropdown || !listEl) return;

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function closeNotifDropdown() {
                dropdown.classList.remove('open');
                bellBtn.setAttribute('aria-expanded', 'false');
            }

            function openSectionFromNotif(section) {
                activateSection(section);
                const url = new URL(window.location.href);
                url.searchParams.set('section', section);
                window.history.replaceState({}, '', url.toString());
                closeNotifDropdown();
            }

            function bindNotifItemClicks() {
                listEl.querySelectorAll('.notif-item').forEach((item) => {
                    item.addEventListener('click', () => {
                        openSectionFromNotif(item.dataset.section || 'reservations');
                    });
                });
            }

            function updateNotifBadge(count) {
                let badge = document.getElementById('notifBadge');
                if (count <= 0) {
                    if (badge) badge.remove();
                    if (alertCountEl) alertCountEl.textContent = '0 alerts';
                    return;
                }

                const label = count > 99 ? '99+' : String(count);
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'notif-badge';
                    badge.id = 'notifBadge';
                    bellBtn.appendChild(badge);
                }
                badge.textContent = label;
                if (alertCountEl) {
                    alertCountEl.textContent = count + ' alert' + (count === 1 ? '' : 's');
                }
            }

            function renderNotifList(items) {
                if (!items || items.length === 0) {
                    listEl.innerHTML = '<div class="notif-empty">No pending reservations</div>';
                    return;
                }

                listEl.innerHTML = items.map((item) => {
                    const name = escapeHtml(item.name || 'Guest');
                    const itemsLabel = escapeHtml(item.items || 'Reservation');
                    const checkIn = escapeHtml(item.check_in_label || item.check_in || '');
                    const id = Number(item.id) || 0;
                    return (
                        '<button type="button" class="notif-item" data-section="reservations" role="menuitem">' +
                            '<div class="notif-item-title">' +
                                '<span>' + name + '</span>' +
                                '<span class="notif-tag pending">Pending</span>' +
                            '</div>' +
                            '<div class="notif-item-meta">' +
                                '#' + id + ' · ' + itemsLabel + '<br>' +
                                'Check-in: ' + checkIn +
                            '</div>' +
                        '</button>'
                    );
                }).join('');
                bindNotifItemClicks();
            }

            function refreshPendingNotifications() {
                return fetch('../api/get_pending_notifications.php', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                })
                .then(async (response) => {
                    const data = await response.json();
                    if (!response.ok || !data?.success) {
                        throw new Error(data?.message || 'Failed to load notifications');
                    }
                    return data;
                })
                .then((data) => {
                    updateNotifBadge(Number(data.count) || 0);
                    renderNotifList(Array.isArray(data.items) ? data.items : []);
                    return data;
                })
                .catch(() => null);
            }

            window.refreshPendingNotifications = refreshPendingNotifications;

            bellBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = dropdown.classList.toggle('open');
                bellBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (isOpen) refreshPendingNotifications();
            });

            dropdown.addEventListener('click', (e) => e.stopPropagation());
            bindNotifItemClicks();

            const viewAllBtn = document.getElementById('notifViewAllBtn');
            if (viewAllBtn) {
                viewAllBtn.addEventListener('click', () => openSectionFromNotif('reservations'));
            }

            document.addEventListener('click', closeNotifDropdown);
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeNotifDropdown();
                    closeManagementNotice();
                }
            });

            // Keep pending count live while admin stays on the page
            setInterval(refreshPendingNotifications, 8000);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) refreshPendingNotifications();
            });
        })();

        // Reservation Management Functions
        function viewReservation(reservationId) {
            console.log('Viewing reservation:', reservationId);
            alert('View reservation details for ID: ' + reservationId);
        }

        let pendingReservationAction = { id: null, status: null };

        function openApproveReservationModal(reservationId) {
            pendingReservationAction = { id: reservationId, status: 'approved' };
            const modal = document.getElementById('approveReservationModal');
            const idLabel = document.getElementById('approveReservationIdLabel');
            if (idLabel) idLabel.textContent = '#' + reservationId;
            if (modal) {
                modal.style.display = 'flex';
            }
        }

        function closeApproveReservationModal() {
            const modal = document.getElementById('approveReservationModal');
            if (modal) modal.style.display = 'none';
            pendingReservationAction = { id: null, status: null };
        }

        function confirmApproveReservation() {
            if (!pendingReservationAction.id) return;
            updateReservationStatus(pendingReservationAction.id, 'approved');
        }

        function openCancelReservationModal(reservationId) {
            pendingReservationAction = { id: reservationId, status: 'cancelled' };
            const modal = document.getElementById('cancelReservationModal');
            const idLabel = document.getElementById('cancelReservationIdLabel');
            const reasonInput = document.getElementById('cancellationReasonInput');
            const errorEl = document.getElementById('cancellationReasonError');
            if (idLabel) idLabel.textContent = '#' + reservationId;
            if (reasonInput) reasonInput.value = '';
            if (errorEl) {
                errorEl.style.display = 'none';
                errorEl.textContent = '';
            }
            if (modal) {
                modal.style.display = 'flex';
                setTimeout(() => reasonInput && reasonInput.focus(), 50);
            }
        }

        function closeCancelReservationModal() {
            const modal = document.getElementById('cancelReservationModal');
            if (modal) modal.style.display = 'none';
            pendingReservationAction = { id: null, status: null };
        }

        function confirmCancelReservation() {
            const reasonInput = document.getElementById('cancellationReasonInput');
            const errorEl = document.getElementById('cancellationReasonError');
            const reason = (reasonInput?.value || '').trim();

            if (reason.length < 5) {
                if (errorEl) {
                    errorEl.textContent = 'Please enter a cancellation reason (at least 5 characters).';
                    errorEl.style.display = 'block';
                }
                reasonInput?.focus();
                return;
            }

            if (!pendingReservationAction.id) return;
            updateReservationStatus(pendingReservationAction.id, 'cancelled', reason);
        }

        function setReservationActionLoading(isLoading, status = null) {
            const approveBtn = document.getElementById('confirmApproveBtn');
            const cancelBtn = document.getElementById('confirmCancelBtn');

            if (approveBtn) {
                if (!approveBtn.dataset.defaultText) {
                    approveBtn.dataset.defaultText = approveBtn.textContent.trim();
                }
                if (isLoading && status === 'approved') {
                    approveBtn.disabled = true;
                    approveBtn.classList.add('reservation-action-btn');
                    approveBtn.innerHTML = '<span class="btn-loading"></span>Approving...';
                } else if (!isLoading) {
                    approveBtn.disabled = false;
                    approveBtn.classList.remove('reservation-action-btn');
                    approveBtn.textContent = approveBtn.dataset.defaultText;
                }
            }

            if (cancelBtn) {
                if (!cancelBtn.dataset.defaultText) {
                    cancelBtn.dataset.defaultText = cancelBtn.textContent.trim();
                }
                if (isLoading && status === 'cancelled') {
                    cancelBtn.disabled = true;
                    cancelBtn.classList.add('reservation-action-btn');
                    cancelBtn.innerHTML = '<span class="btn-loading"></span>Cancelling...';
                } else if (!isLoading) {
                    cancelBtn.disabled = false;
                    cancelBtn.classList.remove('reservation-action-btn');
                    cancelBtn.textContent = cancelBtn.dataset.defaultText;
                }
            }
        }

        function updateReservationStatus(reservationId, status, cancellationReason = '') {
            setReservationActionLoading(true, status);

            fetch('../api/update_reservation_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    reservation_id: reservationId,
                    status: status,
                    cancellation_reason: cancellationReason
                })
            })
            .then(async response => {
                const text = await response.text();
                let data = null;

                if (text) {
                    try {
                        data = JSON.parse(text);
                    } catch (error) {
                        throw new Error(text.slice(0, 200));
                    }
                }

                if (!response.ok) {
                    throw new Error(data?.message || 'Request failed');
                }

                if (!data?.success) {
                    throw new Error(data?.message || 'Unknown error');
                }

                return data;
            })
            .then((data) => {
                closeApproveReservationModal();
                closeCancelReservationModal();
                if (typeof window.refreshPendingNotifications === 'function') {
                    window.refreshPendingNotifications();
                }
                showReservationNotice(
                    status === 'approved' ? 'Reservation Approved' : 'Reservation Cancelled',
                    data.message || (status === 'approved'
                        ? 'The reservation has been approved successfully.'
                        : 'The reservation has been cancelled successfully.'),
                    status === 'approved' ? 'success' : 'danger'
                );
                const noticeModal = document.getElementById('reservationNoticeModal');
                if (noticeModal) noticeModal.dataset.reloadOnClose = 'true';
            })
            .catch(error => {
                setReservationActionLoading(false);
                showReservationNotice('Update Failed', 'Error updating reservation: ' + error.message, 'danger');
            });
        }

        function showReservationNotice(title, message, type = 'success') {
            const modal = document.getElementById('reservationNoticeModal');
            const titleEl = document.getElementById('reservationNoticeTitle');
            const messageEl = document.getElementById('reservationNoticeMessage');
            const iconEl = document.getElementById('reservationNoticeIcon');
            if (!modal) {
                alert(message);
                return;
            }
            if (titleEl) titleEl.textContent = title;
            if (messageEl) messageEl.textContent = message;
            if (iconEl) {
                iconEl.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
                iconEl.style.color = type === 'success' ? '#16a34a' : '#dc2626';
            }
            modal.style.display = 'flex';
        }

        function closeReservationNoticeModal() {
            const modal = document.getElementById('reservationNoticeModal');
            if (!modal) return;
            const reloadOnClose = modal.dataset.reloadOnClose === 'true';
            delete modal.dataset.reloadOnClose;
            modal.style.display = 'none';
            if (reloadOnClose) location.reload();
        }

        // Facility Management Functions
        function addNewCottage() {
            const name = prompt('Enter cottage name:');
            if (name) {
                const description = prompt('Enter cottage description:');
                const capacity = prompt('Enter capacity:');
                const price = prompt('Enter price per night:');
                
                if (description && capacity && price) {
                    // Implementation for adding new cottage
                    console.log('Adding cottage:', { name, description, capacity, price });
                    alert('New cottage added successfully!');
                    location.reload();
                }
            }
        }

        function editCottage(cottageId) {
            console.log('Editing cottage:', cottageId);
            alert('Edit cottage functionality for ID: ' + cottageId);
        }

        function deleteCottage(cottageId) {
            if (confirm('Are you sure you want to delete this cottage?')) {
                console.log('Deleting cottage:', cottageId);
                alert('Cottage deleted successfully!');
                location.reload();
            }
        }

        function addNewPool() {
            const name = prompt('Enter pool name:');
            if (name) {
                const description = prompt('Enter pool description:');
                const capacity = prompt('Enter capacity:');
                const features = prompt('Enter features:');
                
                if (description && capacity && features) {
                    console.log('Adding pool:', { name, description, capacity, features });
                    alert('New pool added successfully!');
                    location.reload();
                }
            }
        }

        function editPool(poolId) {
            console.log('Editing pool:', poolId);
            alert('Edit pool functionality for ID: ' + poolId);
        }

        function deletePool(poolId) {
            if (confirm('Are you sure you want to delete this pool?')) {
                console.log('Deleting pool:', poolId);
                alert('Pool deleted successfully!');
                location.reload();
            }
        }

        // Pricing & Package Functions
        function addNewPackage() {
            const name = prompt('Enter package name:');
            if (name) {
                const description = prompt('Enter package description:');
                const price = prompt('Enter package price:');
                
                if (description && price) {
                    console.log('Adding package:', { name, description, price });
                    alert('New package added successfully!');
                }
            }
        }

        function updateSeasonalPricing() {
            const season = prompt('Enter season (peak/regular/off-peak):');
            if (season) {
                const percentage = prompt('Enter price adjustment percentage:');
                
                if (percentage) {
                    console.log('Updating seasonal pricing:', { season, percentage });
                    alert('Seasonal pricing updated successfully!');
                }
            }
        }

        // Scheduling Functions
        function loadSchedule() {
            const facility = document.getElementById('facilitySelect').value;
            const date = document.getElementById('scheduleDate').value;
            
            if (facility && date) {
                console.log('Loading schedule for:', { facility, date });
                // Implementation for loading schedule
                document.getElementById('timeSlots').innerHTML = '<p>Schedule loaded for ' + facility + ' on ' + date + '</p>';
            }
        }

        // Reports Functions
        function generateReport() {
            const reportType = document.getElementById('reportType').value;
            const startDate = document.getElementById('reportStartDate').value;
            const endDate = document.getElementById('reportEndDate').value;
            
            if (!reportType || !startDate || !endDate) {
                alert('Please select report type and date range');
                return;
            }

            if (reportType === 'revenue') {
                generateRevenueReport(startDate, endDate);
            } else if (reportType === 'customer') {
                generateCustomerReport(startDate, endDate);
            } else if (reportType === 'maintenance') {
                generateMaintenanceReport(startDate, endDate);
            }
        }

        async function generateRevenueReport(startDate, endDate) {
            const reportResults = document.getElementById('reportResults');
            reportResults.innerHTML = '<p style="color: var(--text-light);"><i class="fas fa-spinner fa-spin"></i> Generating report...</p>';

            try {
                const response = await fetch(`../api/get_monthly_revenue_report.php?start_date=${startDate}&end_date=${endDate}`);
                const data = await response.json();

                if (!data.success) {
                    reportResults.innerHTML = `<p style="color: #ef4444;">Error: ${data.message}</p>`;
                    return;
                }

                const monthlyData = data.data;
                const summary = data.summary;

                let tableRows = '';
                monthlyData.forEach(row => {
                    tableRows += `
                        <tr>
                            <td>${row.month_name}</td>
                            <td>${row.total_bookings}</td>
                            <td>₱${row.gross_revenue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                            <td>₱${row.maintenance_fees.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                            <td>₱${row.net_revenue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                        </tr>
                    `;
                });

                // Add summary row
                tableRows += `
                    <tr style="background: var(--primary-blue); color: white; font-weight: bold;">
                        <td>TOTAL</td>
                        <td>${summary.total_bookings}</td>
                        <td>₱${summary.gross_revenue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                        <td>₱${summary.maintenance_fees.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                        <td>₱${summary.net_revenue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    </tr>
                `;

                const reportContent = '<div id="revenueReportContent">' +
                    '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">' +
                        '<div>' +
                            '<h3 style="color: var(--primary-blue); margin: 0;">Monthly Revenue Report</h3>' +
                            '<p style="color: var(--text-light); margin: 0.5rem 0 0 0;">' +
                                'Period: ' + new Date(startDate).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) + ' - ' +
                                new Date(endDate).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) +
                            '</p>' +
                        '</div>' +
                        '<button class="btn-small btn-edit" id="printReportBtn">' +
                            '<i class="fas fa-print"></i> Print Report' +
                        '</button>' +
                    '</div>' +
                    '<div class="table-container">' +
                        '<table>' +
                            '<thead>' +
                                '<tr>' +
                                    '<th>Month</th>' +
                                    '<th>Total Bookings</th>' +
                                    '<th>Gross Revenue</th>' +
                                    '<th>Maintenance Fees</th>' +
                                    '<th>Net Revenue</th>' +
                                '</tr>' +
                            '</thead>' +
                            '<tbody>' +
                                tableRows +
                            '</tbody>' +
                        '</table>' +
                    '</div>' +
                    '<div style="margin-top: 1.5rem; padding: 1rem; background: var(--bg-light); border-radius: 8px;">' +
                        '<h4 style="color: var(--primary-blue); margin: 0 0 0.5rem 0;">Summary</h4>' +
                        '<p style="margin: 0.25rem 0;"><strong>Gross Revenue:</strong> ₱' + summary.gross_revenue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</p>' +
                        '<p style="margin: 0.25rem 0;"><strong>Maintenance Fees:</strong> ₱' + summary.maintenance_fees.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</p>' +
                        '<p style="margin: 0.25rem 0;"><strong>Net Revenue:</strong> ₱' + summary.net_revenue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</p>' +
                        '<p style="margin: 0.25rem 0;"><strong>Total Bookings:</strong> ' + summary.total_bookings + '</p>' +
                    '</div>' +
                '</div>';

                reportResults.innerHTML = reportContent;

                // Add event listener to print button
                document.getElementById('printReportBtn').addEventListener('click', printRevenueReport);

            } catch (error) {
                reportResults.innerHTML = '<p style="color: #ef4444;">Error generating report: ' + error.message + '</p>';
            }
        }

        const customerAnalyticsCharts = {};

        function generateMaintenanceReportFromSection() {
            const startDate = document.getElementById('maintenanceStartDate').value;
            const endDate = document.getElementById('maintenanceEndDate').value;
            
            if (!startDate || !endDate) {
                alert('Please select date range');
                return;
            }
            
            generateMaintenanceReport(startDate, endDate, 'maintenanceResults');
        }

        async function generateMaintenanceReport(startDate, endDate, targetElementId = 'reportResults') {
            const reportResults = document.getElementById(targetElementId);
            reportResults.innerHTML = '<p style="color: var(--text-light);"><i class="fas fa-spinner fa-spin"></i> Generating maintenance report...</p>';

            try {
                const response = await fetch(`../api/get_maintenance_report.php?start_date=${startDate}&end_date=${endDate}`);
                const data = await response.json();

                if (!data.success) {
                    reportResults.innerHTML = `<p style="color: #ef4444;">Error: ${data.message}</p>`;
                    return;
                }

                const maintenanceData = data.data || [];
                const summary = data.summary || { total_fees: 0, total_count: 0 };

                let tableRows = '';
                maintenanceData.forEach(fee => {
                    tableRows += `
                        <tr>
                            <td>${fee.date_incurred}</td>
                            <td style="text-transform: capitalize;">${fee.fee_type}</td>
                            <td style="text-transform: capitalize;">${fee.facility_type}</td>
                            <td>${fee.facility_name}</td>
                            <td>₱${parseFloat(fee.amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                            <td>${fee.description || '-'}</td>
                        </tr>
                    `;
                });

                const reportContent = `
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <div>
                            <h3 style="color: var(--primary-blue); margin: 0;">Maintenance Report</h3>
                            <p style="color: var(--text-light); margin: 0.5rem 0 0 0;">
                                Period: ${new Date(startDate + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} - 
                                ${new Date(endDate + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                            </p>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn-small btn-edit" onclick="openAddFeeModal()">
                                <i class="fas fa-plus"></i> Add Maintenance Fee
                            </button>
                            <button class="btn-small btn-edit" id="printMaintenanceReportBtn">
                                <i class="fas fa-print"></i> Print Report
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom: 1.5rem; padding: 1rem; background: var(--bg-light); border-radius: 8px;">
                        <h4 style="color: var(--primary-blue); margin: 0 0 0.5rem 0;">Summary</h4>
                        <p style="margin: 0.25rem 0;"><strong>Total Maintenance Fees:</strong> ₱${summary.total_fees.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>
                        <p style="margin: 0.25rem 0;"><strong>Total Transactions:</strong> ${summary.total_count}</p>
                    </div>

                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Facility Type</th>
                                    <th>Facility Name</th>
                                    <th>Amount</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${tableRows || '<tr><td colspan="6" style="text-align: center; color: var(--text-light);">No maintenance records found for this period.</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                `;

                reportResults.innerHTML = reportContent;

                // Add event listener to print button
                document.getElementById('printMaintenanceReportBtn').addEventListener('click', printMaintenanceReport);

            } catch (error) {
                reportResults.innerHTML = '<p style="color: #ef4444;">Error generating maintenance report: ' + error.message + '</p>';
            }
        }

        function printMaintenanceReport() {
            const report = document.getElementById('reportResults')?.cloneNode(true);
            if (!report) return;
            report.querySelectorAll('button').forEach(button => button.remove());
            printUniversalReport('Maintenance Report - Villa Soledad Garden Resort', report.innerHTML);
        }

        function destroyCustomerAnalyticsCharts() {
            Object.keys(customerAnalyticsCharts).forEach(key => {
                try {
                    customerAnalyticsCharts[key].destroy();
                } catch (e) { /* ignore */ }
                delete customerAnalyticsCharts[key];
            });
        }

        function ensureChartJsLoaded() {
            return new Promise((resolve, reject) => {
                if (typeof Chart !== 'undefined') {
                    resolve();
                    return;
                }
                const script = document.createElement('script');
                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js';
                script.onload = () => (typeof Chart !== 'undefined' ? resolve() : reject(new Error('Chart.js failed to load')));
                script.onerror = () => reject(new Error('Chart.js CDN could not be loaded. Check your internet connection.'));
                document.head.appendChild(script);
            });
        }

        function createAnalyticsChart(canvasId, config) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) {
                console.error('Canvas not found:', canvasId);
                return null;
            }
            const wrapper = canvas.parentElement;
            if (wrapper) {
                wrapper.style.position = 'relative';
                if (!wrapper.style.minHeight) {
                    wrapper.style.minHeight = '220px';
                }
            }
            canvas.style.width = '100%';
            canvas.style.height = '100%';
            canvas.style.display = 'block';

            if (customerAnalyticsCharts[canvasId]) {
                try { customerAnalyticsCharts[canvasId].destroy(); } catch (e) { /* ignore */ }
            }

            const chart = new Chart(canvas, config);
            customerAnalyticsCharts[canvasId] = chart;
            return chart;
        }

        function normalizeWeekendWeekday(rows) {
            const map = { Weekday: 0, Weekend: 0 };
            (rows || []).forEach(row => {
                if (row.day_type === 'Weekend') map.Weekend = Number(row.bookings) || 0;
                else map.Weekday = Number(row.bookings) || 0;
            });
            return {
                labels: ['Weekday', 'Weekend'],
                data: [map.Weekday, map.Weekend]
            };
        }

        function normalizeDayNight(rows) {
            const map = { Day: 0, Night: 0 };
            (rows || []).forEach(row => {
                const key = String(row.tour_type || '').toLowerCase();
                if (key === 'night') map.Night = Number(row.bookings) || 0;
                else if (key === 'day') map.Day = Number(row.bookings) || 0;
                else {
                    // Keep unknown types as Day bucket label override later if needed
                    map.Day += Number(row.bookings) || 0;
                }
            });
            return {
                labels: ['Day Tours', 'Night Tours'],
                data: [map.Day, map.Night]
            };
        }

        function buildTrendSeries(analytics, startDate, endDate) {
            const monthly = analytics.monthly_bookings || [];
            const byMonth = {};
            monthly.forEach(m => {
                byMonth[m.month] = Number(m.bookings) || 0;
            });

            const monthsShort = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const start = new Date(startDate + 'T00:00:00');
            const end = new Date(endDate + 'T00:00:00');
            const labels = [];
            const data = [];

            // Same calendar year: show full Jan–Dec (matches Statistics chart)
            if (!isNaN(start) && !isNaN(end) && start.getFullYear() === end.getFullYear()) {
                const year = start.getFullYear();
                for (let month = 1; month <= 12; month++) {
                    const key = year + '-' + String(month).padStart(2, '0');
                    labels.push(monthsShort[month - 1]);
                    data.push(byMonth[key] || 0);
                }
            } else if (!isNaN(start) && !isNaN(end) && start <= end) {
                const cursor = new Date(start.getFullYear(), start.getMonth(), 1);
                const last = new Date(end.getFullYear(), end.getMonth(), 1);
                while (cursor <= last) {
                    const key = cursor.getFullYear() + '-' + String(cursor.getMonth() + 1).padStart(2, '0');
                    labels.push(monthsShort[cursor.getMonth()]);
                    data.push(byMonth[key] || 0);
                    cursor.setMonth(cursor.getMonth() + 1);
                }
            } else {
                monthly.forEach(m => {
                    labels.push(m.month_name);
                    data.push(Number(m.bookings) || 0);
                });
            }

            return {
                title: 'Monthly Booking Trend',
                labels,
                data
            };
        }

        function renderMonthlyBookingTrend(containerId, labels, values) {
            const container = document.getElementById(containerId);
            if (!container) return;

            const lineColor = '#1E88E5';
            const markerColor = '#1565C0';
            const gridColor = '#E0E0E0';
            const labelColor = '#424242';

            const w = Math.max(container.clientWidth || 700, 560);
            const h = 320;
            const padLeft = 52;
            const padRight = 24;
            const padTop = 28;
            const padBottom = 56; // month + booking count
            const dataMax = Math.max(...values, 1);
            // Nice Y-axis max (steps of 10 like the mockup)
            const yMax = Math.max(10, Math.ceil(dataMax / 10) * 10);
            const ySteps = Math.min(8, Math.max(4, Math.round(yMax / 10)));
            const usableW = w - padLeft - padRight;
            const usableH = h - padTop - padBottom;
            const stepX = values.length > 1 ? usableW / (values.length - 1) : usableW / 2;

            const valueToY = (v) => padTop + usableH - (v / yMax) * usableH;

            let path = '';
            values.forEach((v, i) => {
                const x = padLeft + i * stepX;
                const y = valueToY(v);
                path += (i === 0 ? 'M ' : ' L ') + x + ' ' + y;
            });

            let svg = '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="xMidYMid meet" style="width:100%; height:100%; display:block; background:#ffffff;">';

            // Y-axis title
            svg += '<text x="14" y="' + (padTop + usableH / 2) + '" font-size="12" font-weight="600" fill="' + labelColor + '" text-anchor="middle" transform="rotate(-90 14 ' + (padTop + usableH / 2) + ')">Bookings</text>';

            // Horizontal gridlines + Y labels
            for (let g = 0; g <= ySteps; g++) {
                const value = Math.round((yMax / ySteps) * (ySteps - g));
                const gy = padTop + (usableH * g / ySteps);
                svg += '<line x1="' + padLeft + '" y1="' + gy + '" x2="' + (w - padRight) + '" y2="' + gy + '" stroke="' + gridColor + '" stroke-width="1"/>';
                svg += '<text x="' + (padLeft - 8) + '" y="' + (gy + 4) + '" font-size="11" text-anchor="end" fill="' + labelColor + '">' + value + '</text>';
            }

            // Axes
            svg += '<line x1="' + padLeft + '" y1="' + padTop + '" x2="' + padLeft + '" y2="' + (padTop + usableH) + '" stroke="' + labelColor + '" stroke-width="1.25"/>';
            svg += '<line x1="' + padLeft + '" y1="' + (padTop + usableH) + '" x2="' + (w - padRight) + '" y2="' + (padTop + usableH) + '" stroke="' + labelColor + '" stroke-width="1.25"/>';

            // Trend line
            svg += '<path d="' + path + '" fill="none" stroke="' + lineColor + '" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round"/>';

            // Markers + month labels + booking counts under months
            values.forEach((v, i) => {
                const x = padLeft + i * stepX;
                const y = valueToY(v);
                svg += '<circle cx="' + x + '" cy="' + y + '" r="5" fill="' + markerColor + '" stroke="#ffffff" stroke-width="1.5"/>';
                svg += '<text x="' + x + '" y="' + (h - padBottom + 18) + '" font-size="11" font-weight="600" text-anchor="middle" fill="' + labelColor + '">' + labels[i] + '</text>';
                svg += '<text x="' + x + '" y="' + (h - padBottom + 34) + '" font-size="11" font-weight="700" text-anchor="middle" fill="' + labelColor + '">' + v + '</text>';
            });

            svg += '</svg>';
            container.innerHTML = svg;
        }

        async function generateCustomerReport(startDate, endDate) {
            const reportResults = document.getElementById('reportResults');
            destroyCustomerAnalyticsCharts();
            reportResults.innerHTML = '<p style="color: var(--text-light);"><i class="fas fa-spinner fa-spin"></i> Generating customer analytics...</p>';

            try {
                await ensureChartJsLoaded();

                const response = await fetch(`../api/get_customer_analytics.php?start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`);
                const data = await response.json();

                if (!data.success) {
                    reportResults.innerHTML = `<p style="color: #ef4444;">Error: ${data.message}</p>`;
                    return;
                }

                const analytics = data.data || {};
                const loyalty = analytics.guest_loyalty || {
                    new_guests: 0,
                    returning_guests: 0,
                    new_guests_percentage: 0,
                    returning_guests_percentage: 0,
                    total_guests: 0
                };
                const trend = buildTrendSeries(analytics, startDate, endDate);
                const chartBox = 'margin-bottom: 2rem; padding: 1.5rem; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px;';
                const chartBoxHalf = 'padding: 1.5rem; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px;';

                const reportContent = '<div id="customerAnalyticsContent">' +
                    '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">' +
                        '<div>' +
                            '<h3 style="color: var(--primary-blue); margin: 0;">Customer Analytics Report</h3>' +
                            '<p style="color: var(--text-light); margin: 0.5rem 0 0 0;">' +
                                'Period: ' + new Date(startDate + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) + ' - ' +
                                new Date(endDate + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) +
                            '</p>' +
                        '</div>' +
                        '<button class="btn-small btn-edit" id="printCustomerReportBtn">' +
                            '<i class="fas fa-print"></i> Print Report' +
                        '</button>' +
                    '</div>' +

                    '<div style="' + chartBox + '">' +
                        '<h4 id="bookingTrendTitle" style="color: #424242; margin: 0 0 1rem 0;">Monthly Booking Trend</h4>' +
                        '<div id="customerMonthlyBookingsChart" style="width:100%; height:320px; background:#ffffff;"></div>' +
                        '<p id="monthlyBookingsEmpty" style="display:none; text-align:center; color:#6b7280; margin:0.5rem 0 0 0;">No booking data for this period.</p>' +
                    '</div>' +

                    '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">' +
                        '<div style="' + chartBoxHalf + '">' +
                            '<h4 style="color: #111827; margin: 0 0 1rem 0;">Weekend vs Weekday</h4>' +
                            '<div style="position: relative; height: 250px; width: 100%;"><canvas id="weekendWeekdayChart"></canvas></div>' +
                        '</div>' +
                        '<div style="' + chartBoxHalf + '">' +
                            '<h4 style="color: #111827; margin: 0 0 1rem 0;">Day vs Night Tours</h4>' +
                            '<div style="position: relative; height: 250px; width: 100%;"><canvas id="dayNightChart"></canvas></div>' +
                        '</div>' +
                    '</div>' +

                    '<div style="' + chartBox + '">' +
                        '<h4 style="color: #111827; margin: 0 0 1rem 0;">Repeat vs New Guests</h4>' +
                        '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; align-items: center;">' +
                            '<div style="position: relative; height: 250px; width: 100%;"><canvas id="guestLoyaltyChart"></canvas></div>' +
                            '<div>' +
                                '<div style="padding: 1rem; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; margin-bottom: 1rem;">' +
                                    '<div style="font-size: 1.5rem; font-weight: bold; color: #111827;">' + loyalty.new_guests_percentage + '%</div>' +
                                    '<div style="color: #6b7280; font-size: 0.9rem;">New Guests (' + loyalty.new_guests + ')</div>' +
                                '</div>' +
                                '<div style="padding: 1rem; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px;">' +
                                    '<div style="font-size: 1.5rem; font-weight: bold; color: #111827;">' + loyalty.returning_guests_percentage + '%</div>' +
                                    '<div style="color: #6b7280; font-size: 0.9rem;">Returning Guests (' + loyalty.returning_guests + ')</div>' +
                                '</div>' +
                                '<p style="margin-top: 1rem; margin-bottom: 0; color: #6b7280; font-size: 0.9rem;">' +
                                    '<strong>Total Guests:</strong> ' + loyalty.total_guests +
                                '</p>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +

                    '<div style="padding: 1.5rem; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px;">' +
                        '<h4 style="color: var(--primary-blue); margin: 0 0 1rem 0;">Peak Booking Months</h4>' +
                        (analytics.peak_months && analytics.peak_months.length > 0 ?
                            '<ul style="margin: 0; padding-left: 1.5rem;">' +
                                analytics.peak_months.map((pm, i) =>
                                    '<li style="margin-bottom: 0.25rem;"><strong>' + (i + 1) + '. ' + pm.month_name + ':</strong> ' + pm.bookings + ' bookings</li>'
                                ).join('') +
                            '</ul>' :
                            '<p style="margin: 0; color: #6b7280;">No booking data available for this period.</p>'
                        ) +
                    '</div>' +

                    '<div style="padding: 1.5rem; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; margin-top: 1.5rem;">' +
                        '<h4 style="color: var(--primary-blue); margin: 0 0 1rem 0;">Occupancy Rates & Guest Count</h4>' +
                        (analytics.occupancy_rates && analytics.occupancy_rates.length > 0 ?
                            '<div style="overflow-x: auto;">' +
                                '<table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">' +
                                    '<thead>' +
                                        '<tr style="background: #f9fafb;">' +
                                            '<th style="padding: 0.75rem; text-align: left; border-bottom: 2px solid #e5e7eb;">Date</th>' +
                                            '<th style="padding: 0.75rem; text-align: right; border-bottom: 2px solid #e5e7eb;">Bookings</th>' +
                                            '<th style="padding: 0.75rem; text-align: right; border-bottom: 2px solid #e5e7eb;">Total Guests</th>' +
                                        '</tr>' +
                                    '</thead>' +
                                    '<tbody>' +
                                        analytics.occupancy_rates.map(or =>
                                            '<tr>' +
                                                '<td style="padding: 0.75rem; border-bottom: 1px solid #e5e7eb;">' + or.date + '</td>' +
                                                '<td style="padding: 0.75rem; text-align: right; border-bottom: 1px solid #e5e7eb;">' + or.bookings + '</td>' +
                                                '<td style="padding: 0.75rem; text-align: right; border-bottom: 1px solid #e5e7eb;">' + or.total_guests + '</td>' +
                                            '</tr>'
                                        ).join('') +
                                    '</tbody>' +
                                '</table>' +
                            '</div>' :
                            '<p style="margin: 0; color: #6b7280;">No occupancy data available for this period.</p>'
                        ) +
                    '</div>' +

                    '<div style="padding: 1.5rem; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; margin-top: 1.5rem;">' +
                        '<h4 style="color: var(--primary-blue); margin: 0 0 1rem 0;">Facility Usage Statistics</h4>' +
                        (analytics.facility_usage && analytics.facility_usage.length > 0 ?
                            '<div style="overflow-x: auto;">' +
                                '<table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">' +
                                    '<thead>' +
                                        '<tr style="background: #f9fafb;">' +
                                            '<th style="padding: 0.75rem; text-align: left; border-bottom: 2px solid #e5e7eb;">Facility</th>' +
                                            '<th style="padding: 0.75rem; text-align: left; border-bottom: 2px solid #e5e7eb;">Type</th>' +
                                            '<th style="padding: 0.75rem; text-align: right; border-bottom: 2px solid #e5e7eb;">Usage Count</th>' +
                                            '<th style="padding: 0.75rem; text-align: right; border-bottom: 2px solid #e5e7eb;">Total Users</th>' +
                                        '</tr>' +
                                    '</thead>' +
                                    '<tbody>' +
                                        analytics.facility_usage.map(fu =>
                                            '<tr>' +
                                                '<td style="padding: 0.75rem; border-bottom: 1px solid #e5e7eb; font-weight: 600;">' + fu.item_name + '</td>' +
                                                '<td style="padding: 0.75rem; border-bottom: 1px solid #e5e7eb; text-transform: capitalize;">' + fu.item_type + '</td>' +
                                                '<td style="padding: 0.75rem; text-align: right; border-bottom: 1px solid #e5e7eb;">' + fu.usage_count + '</td>' +
                                                '<td style="padding: 0.75rem; text-align: right; border-bottom: 1px solid #e5e7eb;">' + fu.total_users + '</td>' +
                                            '</tr>'
                                        ).join('') +
                                    '</tbody>' +
                                '</table>' +
                            '</div>' :
                            '<p style="margin: 0; color: #6b7280;">No facility usage data available for this period.</p>'
                        ) +
                    '</div>' +
                '</div>';

                reportResults.innerHTML = reportContent;

                // Wait for layout so Chart.js gets non-zero canvas size
                await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));

                const commonOptions = {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 400 }
                };

                try {
                    const hasBookings = trend.data.some(v => Number(v) > 0);
                    if (!trend.labels.length || !hasBookings) {
                        const emptyEl = document.getElementById('monthlyBookingsEmpty');
                        if (emptyEl) emptyEl.style.display = 'block';
                    }
                    // Same style as Statistics > Monthly Bookings, with count under each month
                    renderMonthlyBookingTrend('customerMonthlyBookingsChart', trend.labels, trend.data);
                } catch (err) {
                    console.error('Trend chart error:', err);
                }

                try {
                    const ww = normalizeWeekendWeekday(analytics.weekend_weekday);
                    createAnalyticsChart('weekendWeekdayChart', {
                        type: 'doughnut',
                        data: {
                            labels: ww.labels,
                            datasets: [{
                                data: ww.data,
                                backgroundColor: ['#3b82f6', '#f59e0b'],
                                borderWidth: 1,
                                borderColor: '#ffffff'
                            }]
                        },
                        options: {
                            ...commonOptions,
                            plugins: {
                                legend: { position: 'bottom' },
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => {
                                            const total = ww.data.reduce((a, b) => a + b, 0) || 1;
                                            const value = ctx.raw || 0;
                                            return `${ctx.label}: ${value} (${Math.round(value / total * 100)}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                } catch (err) {
                    console.error('Weekend/weekday chart error:', err);
                }

                try {
                    const dn = normalizeDayNight(analytics.day_night_tours);
                    createAnalyticsChart('dayNightChart', {
                        type: 'pie',
                        data: {
                            labels: dn.labels,
                            datasets: [{
                                data: dn.data,
                                backgroundColor: ['#ec4899', '#8b5cf6'],
                                borderWidth: 1,
                                borderColor: '#ffffff'
                            }]
                        },
                        options: {
                            ...commonOptions,
                            plugins: { legend: { position: 'bottom' } }
                        }
                    });
                } catch (err) {
                    console.error('Day/night chart error:', err);
                }

                try {
                    createAnalyticsChart('guestLoyaltyChart', {
                        type: 'doughnut',
                        data: {
                            labels: ['New Guests', 'Returning Guests'],
                            datasets: [{
                                data: [Number(loyalty.new_guests) || 0, Number(loyalty.returning_guests) || 0],
                                backgroundColor: ['#22c55e', '#16a34a'],
                                borderWidth: 1,
                                borderColor: '#ffffff'
                            }]
                        },
                        options: {
                            ...commonOptions,
                            plugins: { legend: { position: 'bottom' } }
                        }
                    });
                } catch (err) {
                    console.error('Guest loyalty chart error:', err);
                }

                const printCustomerBtn = document.getElementById('printCustomerReportBtn');
                if (printCustomerBtn) {
                    printCustomerBtn.addEventListener('click', printCustomerAnalyticsReport);
                }

            } catch (error) {
                reportResults.innerHTML = '<p style="color: #ef4444;">Error generating customer analytics: ' + error.message + '</p>';
            }
        }

        function printCustomerAnalyticsReport() {
            const source = document.getElementById('customerAnalyticsContent');
            if (!source) {
                alert('Generate the customer analytics report first.');
                return;
            }

            const clone = source.cloneNode(true);

            // Hide print button in printed copy
            const printBtn = clone.querySelector('#printCustomerReportBtn');
            if (printBtn) {
                printBtn.remove();
            }

            // Convert Chart.js canvases to images so they appear in print
            const originalCanvases = source.querySelectorAll('canvas');
            const cloneCanvases = clone.querySelectorAll('canvas');
            originalCanvases.forEach((canvas, index) => {
                if (!cloneCanvases[index]) return;
                try {
                    const img = document.createElement('img');
                    img.src = canvas.toDataURL('image/png');
                    img.alt = 'Chart';
                    img.style.maxWidth = '100%';
                    img.style.height = 'auto';
                    img.style.display = 'block';
                    cloneCanvases[index].parentNode.replaceChild(img, cloneCanvases[index]);
                } catch (e) {
                    console.error('Could not export chart for print:', e);
                }
            });

            printUniversalReport('Customer Analytics Report - Villa Soledad Garden Resort', clone.innerHTML);
        }

        function printRevenueReport() {
            const report = document.getElementById('revenueReportContent')?.cloneNode(true);
            if (!report) return;
            report.querySelectorAll('button').forEach(button => button.remove());
            printUniversalReport('Monthly Revenue Report - Villa Soledad Garden Resort', report.innerHTML);
        }

        function printUniversalReport(title, content) {
            const printWindow = window.open('', '_blank');
            if (!printWindow) {
                alert('Please allow pop-ups to print this report.');
                return;
            }
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${title}</title>
                    <style>
                        :root { color-scheme: light; }
                        * { box-sizing: border-box; }
                        body { margin: 0; padding: 28px; font-family: Arial, sans-serif; color: #1f2937; background: #ffffff; }
                        h3, h4 { color: #1e3a8a !important; }
                        p { line-height: 1.5; }
                        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                        th, td { border: 1px solid #dbe3ea; padding: 10px; text-align: left; }
                        th { background: #1e3a8a !important; color: #ffffff !important; }
                        tr:nth-child(even) { background: #f8fafc; }
                        button { display: none !important; }
                        img { max-width: 100%; height: auto; }
                        @media print {
                            body { padding: 0; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
                        }
                    </style>
                </head>
                <body>${content}<script>window.onload = function() { window.print(); window.close(); };<\/script></body>
                </html>
            `);
            printWindow.document.close();
        }

        // Customer Management Functions
        function viewCustomer(customerId) {
            console.log('Viewing customer:', customerId);
            alert('View customer details for ID: ' + customerId);
        }

        function emailCustomer(customerId) {
            const subject = prompt('Enter email subject:');
            if (subject) {
                const message = prompt('Enter email message:');
                if (message) {
                    console.log('Sending email to customer:', { customerId, subject, message });
                    alert('Email sent successfully!');
                }
            }
        }

        // Customer Concerns Functions
        function filterConcerns(filter) {
            console.log('Filtering concerns:', filter);
            alert('Filtering concerns by: ' + filter);
        }

        function respondToConcern(concernId) {
            const response = prompt('Enter your response:');
            if (response) {
                console.log('Responding to concern:', { concernId, response });
                alert('Response sent successfully!');
            }
        }

        function resolveConcern(concernId) {
            if (confirm('Mark this concern as resolved?')) {
                console.log('Resolving concern:', concernId);
                alert('Concern marked as resolved!');
                location.reload();
            }
        }

        // System Data Functions
        function backupDatabase() {
            if (confirm('Create database backup? This may take a few moments.')) {
                console.log('Creating database backup...');
                alert('Database backup created successfully!');
            }
        }

        function validateData() {
            console.log('Validating system data...');
            alert('Data validation completed! No issues found.');
        }

        function cleanOldData() {
            if (confirm('Clean old data? This will remove records older than 1 year.')) {
                console.log('Cleaning old data...');
                alert('Old data cleaned successfully!');
            }
        }

        // Booking Records Functions
        const bookingRecords = <?php echo json_encode($bookingRecords, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function capitalize(value) {
            const text = String(value ?? '');
            return text ? text.charAt(0).toUpperCase() + text.slice(1) : '';
        }

        function loadBookingRecords() {
            const filter = document.getElementById('recordFilter').value;
            const search = document.getElementById('searchRecords').value.toLowerCase().trim();
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const filteredRecords = bookingRecords.filter((record) => {
                const matchesSearch = String(record.guestName).toLowerCase().includes(search) || String(record.id).includes(search);
                if (!matchesSearch) {
                    return false;
                }

                const checkIn = new Date(record.checkIn + 'T00:00:00');
                const checkOut = new Date(record.checkOut + 'T00:00:00');

                if (filter === 'upcoming') {
                    return record.status !== 'cancelled' && checkIn >= today;
                }

                if (filter === 'past') {
                    return record.status !== 'cancelled' && checkOut < today;
                }

                if (filter === 'cancelled') {
                    return record.status === 'cancelled';
                }

                return true;
            });

            const recordsTableBody = document.getElementById('recordsTableBody');
            if (!filteredRecords.length) {
                recordsTableBody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:1.5rem; color:var(--text-light);">No booking records found.</td></tr>';
                return;
            }

            recordsTableBody.innerHTML = filteredRecords.map((record) => {
                const tourType = record.tourType || 'day';
                const tourHours = tourType === 'day' 
                    ? '8:00 AM - 5:00 PM' 
                    : '8:00 PM - 5:00 AM (next day)';
                
                return `
                <tr>
                    <td>#${record.id}</td>
                    <td>${escapeHtml(record.guestName)}</td>
                    <td>${escapeHtml(record.contact || 'N/A')}</td>
                    <td>${escapeHtml(record.items)}</td>
                    <td>${escapeHtml(record.dates)}</td>
                    <td>${escapeHtml(tourHours)}</td>
                    <td>${record.guests}</td>
                    <td>₱${Number(record.amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td><span class="status-badge status-${escapeHtml(record.status)}">${escapeHtml(capitalize(record.status))}</span></td>
                </tr>
            `}).join('');
        }

        // Modal functions for Add Room, Cottage, and Pool
        function openAddRoomModal() {
            const modal = document.getElementById('addRoomModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeAddRoomModal() {
            const modal = document.getElementById('addRoomModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openArchiveModal(type) {
            const modal = document.getElementById('archiveModal');
            if (!modal) return;
            document.querySelectorAll('[id^="archive-"]').forEach(panel => panel.style.display = 'none');
            const panel = document.getElementById('archive-' + type);
            if (panel) panel.style.display = 'block';
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeArchiveModal() {
            const modal = document.getElementById('archiveModal');
            if (modal) modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openAddCottageModal() {
            const modal = document.getElementById('addCottageModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeAddCottageModal() {
            const modal = document.getElementById('addCottageModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openAddPoolModal() {
            const modal = document.getElementById('addPoolModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeAddPoolModal() {
            const modal = document.getElementById('addPoolModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Modal functions for Edit Room, Cottage, and Pool
        function openEditRoomModal(id, name, capacity, price, daySlots, nightSlots, imageUrl, description, available) {
            document.getElementById('editRoomId').value = id;
            document.getElementById('editRoomName').value = name;
            document.getElementById('editRoomCapacity').value = capacity;
            document.getElementById('editRoomPrice').value = price;
            document.getElementById('editRoomDaySlots').value = daySlots;
            document.getElementById('editRoomNightSlots').value = nightSlots;
            document.getElementById('editRoomImage').value = '';
            document.getElementById('editRoomDescription').value = description;
            document.getElementById('editRoomAvailable').checked = available;
            
            const modal = document.getElementById('editRoomModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeEditRoomModal() {
            const modal = document.getElementById('editRoomModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openEditCottageModal(id, name, capacity, price, daySlots, nightSlots, imageUrl, description, available) {
            document.getElementById('editCottageId').value = id;
            document.getElementById('editCottageName').value = name;
            document.getElementById('editCottageCapacity').value = capacity;
            document.getElementById('editCottagePrice').value = price;
            document.getElementById('editCottageDaySlots').value = daySlots;
            document.getElementById('editCottageNightSlots').value = nightSlots;
            document.getElementById('editCottageImage').value = '';
            document.getElementById('editCottageDescription').value = description;
            document.getElementById('editCottageAvailable').checked = available;
            
            const modal = document.getElementById('editCottageModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeEditCottageModal() {
            const modal = document.getElementById('editCottageModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openEditPoolModal(id, name, capacity, status, imageUrl, description, features, available) {
            document.getElementById('editPoolId').value = id;
            document.getElementById('editPoolName').value = name;
            document.getElementById('editPoolCapacity').value = capacity;
            document.getElementById('editPoolStatus').value = status;
            document.getElementById('editPoolImage').value = '';
            document.getElementById('editPoolDescription').value = description;
            document.getElementById('editPoolFeatures').value = features;
            document.getElementById('editPoolAvailable').checked = available;
            
            const modal = document.getElementById('editPoolModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeEditPoolModal() {
            const modal = document.getElementById('editPoolModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openAddFoodModal() {
            const modal = document.getElementById('addFoodModal');
            // clear fields
            document.querySelector('input[name="food_name"]').value = '';
            document.querySelector('select[name="food_category"]').value = 'Starters';
            document.querySelector('input[name="food_price"]').value = '0';
            document.querySelector('select[name="food_status"]').value = 'active';
            document.querySelector('textarea[name="food_description"]').value = '';
            document.getElementById('editFoodId').value = '';
            const fileInput = document.querySelector('input[name="food_image"]'); if (fileInput) fileInput.value = '';
            document.body.style.overflow = 'hidden';
            modal.style.display = 'flex';
        }

        function closeAddFoodModal() {
            const modal = document.getElementById('addFoodModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openEditFoodModal(id, name, category, price, status, imageUrl, available, description) {
            document.getElementById('editFoodId').value = id;
            document.querySelector('input[name="food_name"]').value = name;
            document.querySelector('select[name="food_category"]').value = category;
            document.querySelector('input[name="food_price"]').value = price;
            document.querySelector('select[name="food_status"]').value = status;
            document.querySelector('textarea[name="food_description"]').value = description;
            document.querySelector('input[name="food_available"]').checked = !!available;
            const fileInput = document.querySelector('input[name="food_image"]'); if (fileInput) fileInput.value = '';
            const modal = document.getElementById('addFoodModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeEditFoodModal() {
            closeAddFoodModal();
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const roomModal = document.getElementById('addRoomModal');
            const cottageModal = document.getElementById('addCottageModal');
            const poolModal = document.getElementById('addPoolModal');
            const editRoomModal = document.getElementById('editRoomModal');
            const editCottageModal = document.getElementById('editCottageModal');
            const editPoolModal = document.getElementById('editPoolModal');
            const addFoodModal = document.getElementById('addFoodModal');
            
            if (event.target === roomModal) closeAddRoomModal();
            if (event.target === cottageModal) closeAddCottageModal();
            if (event.target === poolModal) closeAddPoolModal();
            if (event.target === addFoodModal) closeAddFoodModal();
            if (event.target === editRoomModal) closeEditRoomModal();
            if (event.target === editCottageModal) closeEditCottageModal();
            if (event.target === editPoolModal) closeEditPoolModal();
        });

        // Initialize event listeners
        document.addEventListener('DOMContentLoaded', function() {
            const totalBookingsValue = document.getElementById('totalBookingsValue');

            async function refreshBookingCount() {
                if (!totalBookingsValue) return;

                try {
                    const response = await fetch('../api/get_dashboard_stats.php');
                    if (!response.ok) return;

                    const data = await response.json();
                    if (typeof data.total_bookings === 'number') {
                        totalBookingsValue.textContent = data.total_bookings;
                    }
                } catch (error) {
                    console.error('Unable to refresh booking count', error);
                }
            }

            // Load booking records when filters change
            document.getElementById('recordFilter').addEventListener('change', loadBookingRecords);
            document.getElementById('searchRecords').addEventListener('input', loadBookingRecords);
            
            // Initialize booking records
            loadBookingRecords();
            refreshBookingCount();
            setInterval(refreshBookingCount, 15000);
        });

        // Date picker modal functions
        window.openDateModal = function() {
            document.getElementById('dateModal').style.display = 'flex';
            // Set minimum date to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('dateInput').min = today;
            document.getElementById('dateInput').value = today;
        }

        window.closeDateModal = function() {
            document.getElementById('dateModal').style.display = 'none';
        }

        window.applyDate = function() {
            const selectedDate = document.getElementById('dateInput').value;
            if (selectedDate) {
                const currentSection = document.querySelector('.admin-section.active')?.id || 'rooms';
                window.location.href = `dashboard.php?section=${currentSection}&date=${selectedDate}`;
            }
        }

        window.clearDate = function() {
            const currentSection = document.querySelector('.admin-section.active')?.id || 'rooms';
            window.location.href = `dashboard.php?section=${currentSection}`;
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('dateModal');
            if (event.target === modal) {
                window.closeDateModal();
            }
        });

        // Fee Modal Functions
        window.openAddFeeModal = function() {
            document.getElementById('addFeeModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        window.closeAddFeeModal = function() {
            document.getElementById('addFeeModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        window.saveFee = async function(event) {
            event.preventDefault();

            const form = document.getElementById('feeForm');
            const formData = new FormData(form);

            try {
                const response = await fetch('../api/save_maintenance_fee.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showReservationNotice('Maintenance Fee Saved', result.message, 'success');
                    closeAddFeeModal();
                    form.reset();

                    // Auto-refresh report if one is currently displayed
                    const reportType = document.getElementById('reportType').value;
                    const startDate = document.getElementById('reportStartDate').value;
                    const endDate = document.getElementById('reportEndDate').value;

                    if (reportType && startDate && endDate) {
                        generateReport();
                    }
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error saving fee: ' + error.message);
            }
        }
    </script>

    <!-- Archived Catalog Modal -->
    <div id="archiveModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2200; align-items:center; justify-content:center; padding:1rem;">
        <div style="background:#fff; padding:2rem; border-radius:8px; width:90%; max-width:620px; max-height:85vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                <h2 style="color:var(--primary-blue); margin:0;"><i class="fas fa-box-archive"></i> Archived Items</h2>
                <button type="button" onclick="closeArchiveModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
            </div>
            <?php foreach (['rooms' => ['label' => 'Rooms', 'items' => $archivedRooms, 'action' => 'room'], 'cottages' => ['label' => 'Cottages', 'items' => $archivedCottages, 'action' => 'cottage'], 'pools' => ['label' => 'Pools', 'items' => $archivedPools, 'action' => 'pool'], 'foods' => ['label' => 'Food', 'items' => $archivedFoods, 'action' => 'food']] as $archiveType => $archiveGroup): ?>
                <section id="archive-<?php echo $archiveType; ?>" style="display:none; margin-bottom:1.25rem;">
                    <h3 style="color:var(--text-dark); margin:0 0 0.5rem;"><?php echo $archiveGroup['label']; ?></h3>
                    <?php if (empty($archiveGroup['items'])): ?>
                        <p style="color:var(--text-light); margin:0;">No archived <?php echo strtolower($archiveGroup['label']); ?>.</p>
                    <?php else: foreach ($archiveGroup['items'] as $archivedItem): ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:0.65rem 0; border-bottom:1px solid var(--border-gray);">
                            <span><?php echo htmlspecialchars($archivedItem['name']); ?></span>
                            <form method="post" action="dashboard.php?section=<?php echo $archiveType; ?>">
                                <input type="hidden" name="<?php echo $archiveGroup['action']; ?>_action" value="restore_<?php echo $archiveGroup['action']; ?>">
                                <input type="hidden" name="<?php echo $archiveGroup['action']; ?>_id" value="<?php echo (int)$archivedItem['id']; ?>">
                                <button type="submit" class="btn-small btn-approve"><i class="fas fa-rotate-left"></i> Restore</button>
                            </form>
                            <?php if (in_array($archiveGroup['action'], ['room', 'cottage', 'pool'], true)): ?>
                                <form method="post" action="dashboard.php?section=<?php echo $archiveType; ?>" onsubmit="return confirm('Permanently delete this archived item? This cannot be undone.');">
                                    <input type="hidden" name="<?php echo $archiveGroup['action']; ?>_action" value="delete_archived_<?php echo $archiveGroup['action']; ?>">
                                    <input type="hidden" name="<?php echo $archiveGroup['action']; ?>_id" value="<?php echo (int)$archivedItem['id']; ?>">
                                    <button type="submit" class="btn-small btn-delete"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Approve Reservation Modal -->
    <div id="approveReservationModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2100; align-items:center; justify-content:center;">
        <div style="background:white; padding:2rem; border-radius:10px; width:90%; max-width:460px; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
            <div style="text-align:center; margin-bottom:1.25rem;">
                <i class="fas fa-check-circle" style="font-size:2.5rem; color:#16a34a;"></i>
                <h2 style="color:var(--primary-blue); margin:0.75rem 0 0.35rem 0;">Approve Reservation?</h2>
                <p style="color:var(--text-light); margin:0;">You are about to approve reservation <strong id="approveReservationIdLabel">#</strong>.</p>
            </div>
            <p style="color:#374151; margin:0 0 1.5rem 0; text-align:center;">The guest will be notified that their booking is confirmed.</p>
            <div style="display:flex; gap:0.75rem; justify-content:flex-end;">
                <button type="button" class="btn-small btn-delete" onclick="closeApproveReservationModal()">Close</button>
                <button type="button" id="confirmApproveBtn" class="btn-small btn-approve" onclick="confirmApproveReservation()">Confirm Approve</button>
            </div>
        </div>
    </div>

    <!-- Cancel Reservation Modal -->
    <div id="cancelReservationModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2100; align-items:center; justify-content:center;">
        <div style="background:white; padding:2rem; border-radius:10px; width:90%; max-width:520px; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                <h2 style="color:#dc2626; margin:0;">Cancel Reservation</h2>
                <button onclick="closeCancelReservationModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
            </div>
            <p style="color:var(--text-light); margin:0 0 1rem 0;">Please provide a reason for cancelling reservation <strong id="cancelReservationIdLabel">#</strong>.</p>
            <label for="cancellationReasonInput" style="display:block; margin-bottom:0.4rem; font-weight:600;">Cancellation Reason <span style="color:#dc2626;">*</span></label>
            <textarea id="cancellationReasonInput" rows="4" placeholder="Enter the reason for cancellation..." style="width:100%; padding:0.75rem; border:1px solid var(--border-gray); border-radius:6px; resize:vertical; font-family:inherit;"></textarea>
            <p id="cancellationReasonError" style="display:none; color:#dc2626; margin:0.5rem 0 0 0; font-size:0.9rem;"></p>
            <div style="display:flex; gap:0.75rem; justify-content:flex-end; margin-top:1.25rem;">
                <button type="button" class="btn-small" onclick="closeCancelReservationModal()" style="background:#e5e7eb; color:#111827;">Close</button>
                <button type="button" id="confirmCancelBtn" class="btn-small btn-reject" onclick="confirmCancelReservation()">Confirm Cancel</button>
            </div>
        </div>
    </div>

    <!-- Reservation Notice Modal -->
    <div id="reservationNoticeModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2200; align-items:center; justify-content:center;">
        <div style="background:white; padding:2rem; border-radius:10px; width:90%; max-width:420px; box-shadow:0 10px 40px rgba(0,0,0,0.3); text-align:center;">
            <i id="reservationNoticeIcon" class="fas fa-check-circle" style="font-size:2.5rem; color:#16a34a;"></i>
            <h2 id="reservationNoticeTitle" style="color:var(--primary-blue); margin:0.75rem 0 0.5rem 0;">Notice</h2>
            <p id="reservationNoticeMessage" style="color:#374151; margin:0 0 1.25rem 0;"></p>
            <button type="button" class="btn-small btn-approve" onclick="closeReservationNoticeModal()">OK</button>
        </div>
    </div>

    <!-- Custom Delete Confirmation Modal -->
    <div id="deleteConfirmModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.45); z-index:2400; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:1.5rem 1.5rem 1.25rem; border-radius:12px; width:90%; max-width:420px; box-shadow:0 12px 35px rgba(0,0,0,0.15); border-top:5px solid #ef4444; border:1px solid rgba(239,68,68,0.15);">
            <p id="deleteConfirmMessage" style="margin:0 0 1.5rem 0; font-size:1.05rem; line-height:1.5; color:#1f2937; font-weight:600; text-align:left;">Are you Sure you want to Delete?</p>
            <div style="display:flex; justify-content:flex-end; gap:0.75rem;">
                <button type="button" onclick="closeDeleteConfirmModal()" style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:0.7rem 1.5rem; border-radius:999px; font-weight:700; cursor:pointer; min-width:110px; transition:all 0.2s ease;">Cancel</button>
                <button type="button" onclick="confirmDeleteAction()" style="background:#ef4444; color:#fff; border:none; padding:0.7rem 1.5rem; border-radius:999px; font-weight:700; cursor:pointer; min-width:110px; box-shadow:0 6px 12px rgba(239,68,68,0.2); transition:all 0.2s ease;">OK</button>
            </div>
        </div>
    </div>

    <!-- Management Notice Modal (rooms / cottages / pools / foods) -->
    <div id="managementNoticeModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2300; align-items:center; justify-content:center;">
        <div id="managementNoticeCard" style="background:white; padding:2rem; border-radius:10px; width:90%; max-width:420px; box-shadow:0 10px 40px rgba(0,0,0,0.3); text-align:center; border-top:6px solid #2563eb;">
            <i id="managementNoticeIcon" class="fas fa-info-circle" style="font-size:2.5rem; color:#2563eb;"></i>
            <h2 id="managementNoticeTitle" style="margin:0.75rem 0 0.5rem 0; color:#2563eb;">Notice</h2>
            <p id="managementNoticeMessage" style="color:#374151; margin:0 0 1.25rem 0;"></p>
            <button type="button" id="managementNoticeOkBtn" class="btn-small" onclick="closeManagementNotice()" style="background:#2563eb; color:#fff; border:none; padding:0.55rem 1.4rem; border-radius:6px; font-weight:600; cursor:pointer;">OK</button>
        </div>
    </div>

    <!-- Add Fee Modal -->
    <div id="addFeeModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
        <div style="background:white; padding:2rem; border-radius:8px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                <h2 style="color:var(--primary-blue); margin:0;">Add Maintenance/Repair Fee</h2>
                <button onclick="closeAddFeeModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
            </div>
            <form id="feeForm" onsubmit="saveFee(event)">
                <div style="margin-bottom:1rem;">
                    <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Fee Type</label>
                    <select name="fee_type" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                        <option value="maintenance">Maintenance</option>
                        <option value="repair">Repair</option>
                        <option value="upgrade">Upgrade</option>
                    </select>
                </div>

                <div style="margin-bottom:1rem;">
                    <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Facility Type</label>
                    <select name="facility_type" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                        <option value="pool">Pool</option>
                        <option value="cottage">Cottage</option>
                        <option value="room">Room</option>
                        <option value="general">General</option>
                    </select>
                </div>

                <div style="margin-bottom:1rem;">
                    <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Facility Name</label>
                    <input type="text" name="facility_name" required placeholder="e.g., Main Pool, Cottage A, Room 101" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                </div>

                <div style="margin-bottom:1rem;">
                    <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Amount (₱)</label>
                    <input type="number" name="amount" required min="0" step="0.01" placeholder="0.00" style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                </div>

                <div style="margin-bottom:1rem;">
                    <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Description</label>
                    <textarea name="description" rows="3" placeholder="Describe the maintenance or repair work..." style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px; resize:vertical;"></textarea>
                </div>

                <div style="margin-bottom:1rem;">
                    <label style="display:block; margin-bottom:0.35rem; font-weight:600;">Date Incurred</label>
                    <input type="date" name="date_incurred" required style="width:100%; padding:0.7rem; border:1px solid var(--border-gray); border-radius:6px;">
                </div>

                <div style="margin-top:1.5rem; display:flex; gap:1rem;">
                    <button type="submit" class="btn-primary">Save Fee</button>
                    <button type="button" class="btn-small btn-delete" onclick="closeAddFeeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Date Picker Modal -->
    <div id="dateModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 2rem; border-radius: 12px; max-width: 400px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 style="color: var(--primary-blue); margin: 0;">Select Date</h2>
                <button onclick="closeDateModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-light);">&times;</button>
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-dark); font-weight: 600;">Choose a date to check availability:</label>
                <input type="date" id="dateInput" style="width: 100%; padding: 0.75rem; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 1rem;">
            </div>
            <div style="display: flex; gap: 1rem;">
                <button onclick="closeDateModal()" style="flex: 1; padding: 0.75rem; background: #e5e7eb; color: var(--text-dark); border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">Cancel</button>
                <button onclick="applyDate()" style="flex: 1; padding: 0.75rem; background: var(--primary-blue); color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">Check Availability</button>
            </div>
        </div>
    </div>
</body>
</html>