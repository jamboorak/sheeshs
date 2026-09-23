<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/database.php';

// Ensure the foods table exists and seed it with the resort menu if empty.
$db = new Database();
$conn = $db->getConnection();

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

$seedCheck = $conn->query("SELECT COUNT(*) AS count FROM foods");
$foodCount = $seedCheck ? (int) $seedCheck->fetch_assoc()['count'] : 0;

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

$foodsResult = $conn->query("SELECT * FROM foods WHERE status = 'active' AND archived = 0 ORDER BY name");
$foods = $foodsResult ? $foodsResult->fetch_all(MYSQLI_ASSOC) : [];

$categories = ['Starters','Main Course','Soups','All Day Breakfast','Hot Beverages','Non-Alcoholic','Sides','Vegetables','Rice Meals','Dessert','Cocktails'];
$foodsByCategory = [];
foreach ($categories as $c) { $foodsByCategory[$c] = []; }
foreach ($foods as $f) {
    $cat = trim($f['category'] ?? '') !== '' ? $f['category'] : 'Starters';
    if (!isset($foodsByCategory[$cat])) $foodsByCategory[$cat] = [];
    $foodsByCategory[$cat][] = $f;
}
?>

<style>
    
    .food-categories-nav {
        display: flex;
        justify-content: flex-end;
        margin: 0 auto 2.5rem;
        background: transparent;
        padding: 0 1rem;
        max-width: 1200px;
    }

    .food-category-select {
        width: min(100%, 240px);
        padding: 0.6rem 2.5rem 0.6rem 0.8rem;
        border: 1px solid #cbddea;
        border-radius: 8px;
        background: #ffffff;
        color: #234663;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        appearance: auto;
        box-shadow: 0 6px 18px rgba(24, 67, 98, 0.08);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .food-category-select:focus {
        outline: none;
        border-color: #2d8bd0;
        box-shadow: 0 0 0 3px rgba(45, 139, 208, 0.14);
    }

    .food-category-link {
        display: none;
    }

    .food-category-link:hover {
        color: var(--primary-blue);
        border-bottom-color: var(--primary-blue);
        background: rgba(255, 122, 61, 0.05);
    }

    .food-category-link.active {
        color: var(--accent-orange);
        border-bottom-color: var(--accent-orange);
        background: rgba(255, 122, 61, 0.1);
        font-weight: 600;
    }

    .food-category-select option {
        color: #234663;
        font-size: 1rem;
    }

    .food-items-section {
        background: white;
        border-radius: 12px;
        padding: 2rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        margin-bottom: 2rem;
        display: none;
        max-width: 1200px;
        margin-left: auto;
        margin-right: auto;
        margin-bottom: 4rem;
        padding: 0 2rem 4rem;
    }

    .food-items-section.active {
        display: block;
    }

    .food-items-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 2rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #e5e7eb;
    }

    .food-items-header i {
        font-size: 2rem;
        color: var(--primary-blue);
    }

    .food-items-header h2 {
        color: var(--primary-blue);
        font-size: 2rem;
        font-weight: 700;
    }

    .food-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 2rem;
    }

    .food-item {
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 0;
        transition: all 0.3s ease;
        display: flex;
        align-items: stretch;
        overflow: hidden;
    }

    .food-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .food-item-content {
        flex: 1;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .food-item-image {
        width: 150px;
        height: 150px;
        background: #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #64748b;
        font-size: 0.9rem;
        text-align: center;
        padding: 1rem;
        flex-shrink: 0;
    }

    .food-item-name {
        font-size: 1.2rem;
        font-weight: 600;
        color: var(--dark-gray);
        margin-bottom: 0.5rem;
    }

    .food-item-description {
        color: #64748b;
        margin-bottom: 1rem;
        line-height: 1.6;
    }

    .food-item-price {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--accent-orange);
        margin-top: auto;
    }

    .back-to-categories {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: var(--primary-blue);
        color: white;
        padding: 0.8rem 1.5rem;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
        margin-bottom: 2rem;
    }

    .back-to-categories:hover {
        background: var(--accent-orange);
        transform: translateY(-2px);
    }



    .food-item.hidden {
        display: none;
    }

    @media (max-width: 768px) {
        .food-menu-hero h1 {
            font-size: 2rem;
        }

        .food-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
    }
</style>

<!-- Categories Navigation -->
<div class="food-categories-nav" id="categoriesNav">
    <select class="food-category-select" id="foodCategorySelect" aria-label="Choose a food category">
        <?php foreach ($categories as $i => $cat):
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($cat));
        ?>
            <option value="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($cat); ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Food Items Sections -->
<div id="foodItemsContainer">
<?php foreach ($categories as $i => $cat):
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($cat));
    $items = $foodsByCategory[$cat] ?? [];
?>
    <div class="food-items-section<?php echo $i===0 ? ' active' : ''; ?>" id="<?php echo $slug; ?>">
        <div class="food-items-header">
            <i class="fas fa-utensils"></i>
            <h2><?php echo htmlspecialchars($cat); ?></h2>
        </div>
        <div class="food-grid">
            <?php if (!empty($items)): ?>
                <?php foreach ($items as $f): ?>
                    <div class="food-item">
                        <div class="food-item-content">
                            <div class="food-item-name"><?php echo htmlspecialchars($f['name']); ?></div>
                            <div class="food-item-description"><?php echo htmlspecialchars($f['description'] ?? ''); ?></div>
                            <div class="food-item-price">₱<?php echo number_format((float)($f['price'] ?? 0), 2); ?></div>
                        </div>
                        <div class="food-item-image">
                            <?php $img = !empty($f['image_url']) ? $f['image_url'] : 'images/placeholder-food.png'; ?>
                            <img src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($f['name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding:2rem; color:var(--text-light);">No items in this category yet.</div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<script>
let activeCategoryId = '';

function showCategory(categoryId, el) {
    activeCategoryId = categoryId;

    const allSections = document.querySelectorAll('.food-items-section');
    allSections.forEach(section => section.classList.remove('active'));

    const selectedSection = document.getElementById(categoryId);
    if (selectedSection) selectedSection.classList.add('active');

    const categorySelect = document.getElementById('foodCategorySelect');
    if (categorySelect && categorySelect.value !== categoryId) {
        categorySelect.value = categoryId;
    }
}

// Show first category by default
document.addEventListener('DOMContentLoaded', function() {
    const firstSection = document.querySelector('.food-items-section');
    const categorySelect = document.getElementById('foodCategorySelect');
    if (firstSection) {
        activeCategoryId = firstSection.id;
        firstSection.classList.add('active');
    }
    if (categorySelect) {
        categorySelect.addEventListener('change', function() {
            showCategory(this.value);
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
