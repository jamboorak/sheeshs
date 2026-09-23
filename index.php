<?php
$pageClass = 'home-page';
require_once 'includes/header.php';
require_once __DIR__ . '/config/RoomConfig.php';

function resolveImageUrl($imageUrl, $default = '') {
    if (empty($imageUrl)) {
        return $default;
    }
    $imageUrl = trim($imageUrl);
    if (preg_match('/^https?:\/\//i', $imageUrl)) {
        return $imageUrl;
    }
    if (strpos($imageUrl, '/') === 0) {
        return rtrim(SITE_URL, '/') . $imageUrl;
    }
    return SITE_URL . $imageUrl;
}

$db = new Database();
$conn = $db->getConnection();
$conn->query("CREATE TABLE IF NOT EXISTS cottages (id INT(11) AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, description TEXT, capacity INT(11) NOT NULL, price_per_night DECIMAL(10,2) NOT NULL, image_url VARCHAR(255), available BOOLEAN DEFAULT TRUE)");
$conn->query("CREATE TABLE IF NOT EXISTS pools (id INT(11) AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, description TEXT, capacity INT(11) NOT NULL, features TEXT, status VARCHAR(20) NOT NULL DEFAULT 'active', image_url VARCHAR(255), available BOOLEAN DEFAULT TRUE)");
syncRoomBrochureData($conn);
$publicRoomsSql = "SELECT * FROM rooms WHERE available = 1 AND archived = 0 ORDER BY id";
$publicRoomsResult = $conn->query($publicRoomsSql);
$publicRooms = $publicRoomsResult ? $publicRoomsResult->fetch_all(MYSQLI_ASSOC) : [];
$publicCottagesSql = "SELECT * FROM cottages WHERE available = 1 AND archived = 0 ORDER BY id";
$publicCottagesResult = $conn->query($publicCottagesSql);
$publicCottages = $publicCottagesResult ? $publicCottagesResult->fetch_all(MYSQLI_ASSOC) : [];
$publicPoolsSql = "SELECT name, status FROM pools WHERE status IN ('active', 'maintenance') AND archived = 0 ORDER BY id";
$publicPoolsResult = $conn->query($publicPoolsSql);
$publicPools = $publicPoolsResult ? $publicPoolsResult->fetch_all(MYSQLI_ASSOC) : [];
$poolStatusByName = [];
foreach ($publicPools as $publicPool) {
    $poolStatusByName[strtolower(trim($publicPool['name'] ?? ''))] = $publicPool['status'] ?? 'active';
}
?>

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

    <!-- Welcome + Contact Section -->
    <section id="welcome" class="section welcome-contact" style="background: linear-gradient(rgba(15, 23, 42, 0.72), rgba(15, 23, 42, 0.78)), url('images/villasoledadbg.png') center/cover no-repeat; padding: 5rem 0;">
        <div class="home-reference-hero">
            <div class="home-reference-hero-copy">
                <span class="home-reference-eyebrow">YOUR PERFECT GETAWAY AWAITS</span>
                <h1>Villa Soledad Resort</h1>
                <p>Experience comfort, nature, and relaxation at our beautiful resort. Book your stay today and create unforgettable memories.</p>
            </div>
            <div class="home-reference-map-card" id="homeReferenceMapCard">
                <div class="home-reference-map-heading" id="homeReferenceMapDragHandle"><i class="fas fa-map-marker-alt"></i><strong>Location</strong><button type="button" aria-label="Close location preview">&times;</button></div>
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3870.6601965650625!2d121.3124313108307!3d14.038145390542423!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33bd4301035b4ef3%3A0xd22111becbb8a4!2sVilla%20Soledad%20Garden%20Resort!5e0!3m2!1sen!2sph!4v1777265586353!5m2!1sen!2sph" title="Villa Soledad Garden Resort location map" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                <div class="home-reference-map-footer"><button type="button" class="home-reference-address" id="homeReferenceAddress"><i class="fas fa-map-marker-alt"></i> San Pablo, Laguna</button><a href="https://maps.google.com/?q=Villa+Soledad+Garden+Resort" target="_blank" rel="noopener noreferrer">Open in Google Maps <i class="fas fa-external-link-alt"></i></a></div>
            </div>
        </div>

        <div class="home-address-modal" id="homeAddressModal" hidden>
            <div class="home-address-dialog home-navigation-dialog" role="dialog" aria-modal="true" aria-labelledby="homeAddressTitle">
                <button type="button" class="home-address-close" id="homeAddressClose" aria-label="Close address">&times;</button>
                <div class="home-navigation-heading"><i class="fas fa-map-marker-alt"></i><strong>Navigation Map</strong></div>
                <div class="home-navigation-body">
                    <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3870.6601965650625!2d121.3124313108307!3d14.038145390542423!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33bd4301035b4ef3%3A0xd22111becbb8a4!2sVilla%20Soledad%20Garden%20Resort!5e0!3m2!1sen!2sph!4v1777265586353!5m2!1sen!2sph" title="Navigation map to Villa Soledad Garden Resort" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    <div class="home-navigation-details">
                        <div class="home-navigation-route"><i class="fas fa-car"></i><strong>Villa Soledad Garden Resort</strong><span>San Pablo, Laguna</span></div>
                        <div class="home-navigation-step"><i class="fas fa-location-dot"></i><div><strong>Your Location</strong><small>Start</small></div></div>
                        <div class="home-navigation-step"><i class="fas fa-arrow-up"></i><div><strong>Head northeast on AH26</strong><small>Follow the main road toward San Pablo City</small></div></div>
                        <div class="home-navigation-step"><i class="fas fa-turn-up"></i><div><strong>Turn toward Villa Soledad Road</strong><small>Continue to the resort entrance</small></div></div>
                        <div class="home-navigation-step"><i class="fas fa-location-dot"></i><div><strong>Arrive at Villa Soledad Resort</strong><small>201 Soledad-Santa Maria-Santisimo Road</small></div></div>
                        <a href="https://maps.google.com/?q=Villa+Soledad+Garden+Resort" target="_blank" rel="noopener noreferrer" class="home-address-link"><i class="fas fa-location-arrow"></i> Open in Google Maps</a>
                    </div>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const mapCard = document.getElementById('homeReferenceMapCard');
                const dragHandle = document.getElementById('homeReferenceMapDragHandle');
                const addressButton = document.getElementById('homeReferenceAddress');
                const addressModal = document.getElementById('homeAddressModal');
                const addressClose = document.getElementById('homeAddressClose');

                if (mapCard && dragHandle) {
                    let dragging = false;
                    let startX = 0;
                    let startY = 0;
                    let startLeft = 0;
                    let startTop = 0;

                    dragHandle.addEventListener('pointerdown', function(event) {
                        if (event.target.closest('button')) return;
                        const hero = mapCard.closest('.home-reference-hero');
                        const heroRect = hero.getBoundingClientRect();
                        const cardRect = mapCard.getBoundingClientRect();
                        mapCard.style.left = `${cardRect.left - heroRect.left}px`;
                        mapCard.style.right = 'auto';
                        mapCard.style.bottom = 'auto';
                        mapCard.style.top = `${cardRect.top - heroRect.top}px`;
                        startX = event.clientX;
                        startY = event.clientY;
                        startLeft = cardRect.left - heroRect.left;
                        startTop = cardRect.top - heroRect.top;
                        dragging = true;
                        dragHandle.setPointerCapture(event.pointerId);
                        mapCard.classList.add('is-dragging');
                    });

                    dragHandle.addEventListener('pointermove', function(event) {
                        if (!dragging) return;
                        const hero = mapCard.closest('.home-reference-hero');
                        const maxLeft = hero.clientWidth - mapCard.offsetWidth;
                        const maxTop = hero.clientHeight - mapCard.offsetHeight;
                        const nextLeft = Math.max(0, Math.min(maxLeft, startLeft + event.clientX - startX));
                        const nextTop = Math.max(0, Math.min(maxTop, startTop + event.clientY - startY));
                        mapCard.style.left = `${nextLeft}px`;
                        mapCard.style.top = `${nextTop}px`;
                    });

                    dragHandle.addEventListener('pointerup', function() {
                        dragging = false;
                        mapCard.classList.remove('is-dragging');
                    });
                }

                function toggleAddressModal(show) {
                    if (!addressModal) return;
                    addressModal.hidden = !show;
                    document.body.classList.toggle('home-address-open', show);
                }

                addressButton?.addEventListener('click', () => toggleAddressModal(true));
                addressClose?.addEventListener('click', () => toggleAddressModal(false));
                addressModal?.addEventListener('click', function(event) {
                    if (event.target === addressModal) toggleAddressModal(false);
                });
            });
        </script>

        <div class="home-reference-features">
            <div class="home-reference-feature"><i class="fas fa-bed"></i><h3>Comfortable Rooms</h3><p>Spacious and well-equipped for your relaxation.</p></div>
            <div class="home-reference-feature"><i class="fas fa-water"></i><h3>Resort Amenities</h3><p>Pool, restaurant, and more for your enjoyment.</p></div>
            <div class="home-reference-feature"><i class="fas fa-shield-alt"></i><h3>Safe &amp; Secure</h3><p>Your safety is our priority.</p></div>
            <div class="home-reference-feature"><i class="fas fa-leaf"></i><h3>Nature Escape</h3><p>Enjoy the beauty of Laguna.</p></div>
        </div>

        <div class="home-reference-resorts home-reference-facade">
            <div class="home-reference-section-heading"><h2>Facade</h2><p>Take a closer look at Villa Soledad Garden Resort.</p></div>
            <div class="home-facade-slideshow" id="homeFacadeSlideshow" aria-label="Villa Soledad facade slideshow">
                <div class="home-facade-track">
                    <?php for ($slideNumber = 1; $slideNumber <= 9; $slideNumber++): ?>
                        <div class="home-facade-slide">
                            <img src="images/slideimg<?php echo $slideNumber; ?>.jpg" alt="Villa Soledad Garden Resort facade <?php echo $slideNumber; ?>">
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="home-facade-dots" role="tablist" aria-label="Facade slideshow controls">
                    <?php for ($slideNumber = 1; $slideNumber <= 7; $slideNumber++): ?>
                        <button type="button" class="home-facade-dot<?php echo $slideNumber === 1 ? ' is-active' : ''; ?>" data-slide="<?php echo $slideNumber - 1; ?>" aria-label="Show facade image <?php echo $slideNumber; ?>"></button>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const slideshow = document.getElementById('homeFacadeSlideshow');
            if (!slideshow) return;

            const slides = Array.from(slideshow.querySelectorAll('.home-facade-slide'));
            const dots = Array.from(slideshow.querySelectorAll('.home-facade-dot'));
            let activeSlide = 0;
            let slideshowTimer;

            function showFacadeSlide(index) {
                const lastPosition = Math.max(0, slides.length - 3);
                activeSlide = (index + lastPosition + 1) % (lastPosition + 1);
                slideshow.querySelector('.home-facade-track').style.transform = `translateX(-${activeSlide * 11.111}%)`;
                dots.forEach((dot, dotIndex) => {
                    dot.classList.toggle('is-active', dotIndex === activeSlide);
                });
            }

            function restartFacadeTimer() {
                window.clearInterval(slideshowTimer);
                slideshowTimer = window.setInterval(() => showFacadeSlide(activeSlide + 1), 4000);
            }

            dots.forEach((dot) => {
                dot.addEventListener('click', function() {
                    showFacadeSlide(Number(this.dataset.slide));
                    restartFacadeTimer();
                });
            });

            slideshow.addEventListener('mouseenter', () => window.clearInterval(slideshowTimer));
            slideshow.addEventListener('mouseleave', restartFacadeTimer);
            restartFacadeTimer();
        });
    </script>

    <!-- Rooms Section -->
    <section id="rooms" class="facilities home-section-with-bg" style="background: linear-gradient(rgba(15, 23, 42, 0.72), rgba(15, 23, 42, 0.78)), url('images/villasoledadbg.png') center/cover no-repeat; padding: 3rem 0 2rem;">
        <div class="container">
            <h2 class="home-section-heading" style="color: #ffffff !important;">Rooms we offer</h2>
            <div class="room-slider-wrapper" style="perspective: 1400px;">
                <div class="room-slider" id="roomSlider" style="display: flex; align-items: center; justify-content: center; gap: 3rem; position: relative;">
                    <button onclick="rotateRooms(-1)" style="background: none; border: none; font-size: 2rem; color: var(--primary-blue); cursor: pointer; padding: 0; width: 50px; height: 50px; margin-right: 2.5rem; border-radius: 50%; background: var(--bg-light); transition: all 0.3s ease;" onmouseover="this.style.background='var(--accent-orange)'; this.style.color='white';" onmouseout="this.style.background='var(--bg-light)'; this.style.color='var(--primary-blue)';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <div class="slider-stage" style="width: 100%; max-width: 760px; height: 580px; position: relative; transform-style: preserve-3d; transition: transform 0.8s ease;">
                        <?php if (!empty($publicRooms)): ?>
                            <?php foreach ($publicRooms as $index => $room): ?>
                            <?php
                                $roomInclusionsHtml = renderRoomInclusionsHtml($room['name'] ?? '', true);
                                $roomDescription = $room['description'] ?? '';
                            ?>
                            <div class="room-card room-card-<?php echo $index; ?>" style="position: absolute; top: 0; left: 50%; width: 380px; height: 560px; transform-style: preserve-3d; transform-origin: center center; transition: transform 0.8s ease, opacity 0.8s ease;">
                                <div style="width: 100%; height: 100%; border-radius: 28px; overflow: hidden; box-shadow: 0 28px 60px rgba(15, 23, 42, 0.14); background: #fff; display: flex; flex-direction: column;">
                                    <img src="<?php echo htmlspecialchars(resolveImageUrl($room['image_url'] ?? '', SITE_URL . 'images/standard.jpg')); ?>" alt="<?php echo htmlspecialchars($room['name'] ?? 'Room'); ?>" style="width: 100%; height: 170px; object-fit: cover; display: block; flex-shrink: 0;">
                                    <div style="padding: 1.15rem 1.35rem 1.25rem; display: flex; flex-direction: column; flex: 1;">
                                        <h3 style="color: var(--primary-blue); font-size: 1.4rem; margin-bottom: 0.45rem; margin-top: 0;"><?php echo htmlspecialchars($room['name'] ?? 'Room'); ?></h3>
                                        <div style="margin-bottom: 0.5rem;">
                                            <?php if ($roomInclusionsHtml !== ''): ?>
                                                <?php echo $roomInclusionsHtml; ?>
                                            <?php else: ?>
                                                <p style="color: #475569; line-height: 1.6; margin: 0;"><?php echo htmlspecialchars($roomDescription); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div style="display: flex; align-items: center; justify-content: flex-start; gap: 1rem; margin-top: 0.35rem;">
                                            <span style="color: var(--accent-orange); font-weight: 700; font-size: 1.05rem;">₱<?php echo number_format((float)($room['price_per_night'] ?? 0), 2); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 2rem; text-align: center; color: var(--text-light);">No rooms available yet.</div>
                        <?php endif; ?>
                    </div>
                    <button onclick="rotateRooms(1)" style="background: none; border: none; font-size: 2rem; color: var(--primary-blue); cursor: pointer; padding: 0; width: 50px; height: 50px; margin-left: 2.5rem; border-radius: 50%; background: var(--bg-light); transition: all 0.3s ease;" onmouseover="this.style.background='var(--accent-orange)'; this.style.color='white';" onmouseout="this.style.background='var(--bg-light)'; this.style.color='var(--primary-blue)';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <div id="descriptionModalRoom" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 2000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 2rem; border-radius: 10px; max-width: 440px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 id="modalTitleRoom" style="color: var(--primary-blue); margin: 0;"></h2>
                <button onclick="closeModalRoom()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-light);">&times;</button>
            </div>
            <div id="modalPromoRoom" style="display:none; margin-bottom:0.75rem;"></div>
            <div id="modalDescriptionRoom" style="color: var(--text-dark); margin-bottom: 1rem; line-height: 1.6;"></div>
            <div id="modalPriceRoom" style="font-size: 1.5rem; color: var(--accent-orange); font-weight: 700; margin-bottom: 1.5rem;"></div>
            <button onclick="closeModalRoom()" style="width: 100%; padding: 0.8rem; background: var(--primary-blue); color: white; border: none; border-radius: 5px; font-weight: 600; cursor: pointer;">Close</button>
        </div>
    </div>

    <!-- Cottages Section -->
    <section id="cottages" class="home-section-with-bg" style="background: linear-gradient(rgba(15, 23, 42, 0.72), rgba(15, 23, 42, 0.78)), url('images/villasoledadbg.png') center/cover no-repeat; padding: 3rem 0 2rem;">
        <div class="container">
            <h2 class="home-section-heading" style="color: #ffffff !important;">Cottages we offer</h2>
            <div class="cottage-slider-wrapper" style="perspective: 1400px;">
                <div class="cottage-slider" id="cottageSlider" style="display: flex; align-items: center; justify-content: center; gap: 3rem; position: relative;">
                    <button onclick="rotateCottages(-1)" style="background: none; border: none; font-size: 2rem; color: var(--primary-blue); cursor: pointer; padding: 0; width: 50px; height: 50px; margin-right: 2.5rem; border-radius: 50%; background: var(--bg-light); transition: all 0.3s ease;" onmouseover="this.style.background='var(--accent-orange)'; this.style.color='white';" onmouseout="this.style.background='var(--bg-light)'; this.style.color='var(--primary-blue)';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <div class="slider-stage" style="width: 100%; max-width: 760px; height: 420px; position: relative; transform-style: preserve-3d; transition: transform 0.8s ease;">
                        <?php if (!empty($publicCottages)): ?>
                            <?php foreach ($publicCottages as $index => $cottage): ?>
                            <div class="cottage-card cottage-card-<?php echo $index; ?>" style="position: absolute; top: 0; left: 50%; width: 360px; height: 420px; transform-style: preserve-3d; transform-origin: center center; transition: transform 0.8s ease, opacity 0.8s ease;">
                                <div style="width: 100%; height: 100%; border-radius: 28px; overflow: hidden; box-shadow: 0 28px 60px rgba(15, 23, 42, 0.14); background: #fff;">
                                    <img src="<?php echo htmlspecialchars(!empty($cottage['image_url']) ? $cottage['image_url'] : 'images/cottage a.png'); ?>" alt="<?php echo htmlspecialchars($cottage['name'] ?? 'Cottage'); ?>" style="width: 100%; height: 220px; object-fit: cover; display: block;">
                                    <div style="padding: 1.5rem;">
                                        <h3 style="color: var(--primary-blue); font-size: 1.6rem; margin-bottom: 0.75rem;"><?php echo htmlspecialchars($cottage['name'] ?? 'Cottage'); ?></h3>
                                        <p style="color: #475569; line-height: 1.6; margin-bottom: 1rem;">Good for <?php echo (int)($cottage['capacity'] ?? 0); ?> pax • ₱<?php echo number_format((float)($cottage['price_per_night'] ?? 0), 2); ?></p>
                                        <div style="display: flex; align-items: center; justify-content: flex-start; gap: 1rem;">
                                            <span style="color: var(--accent-orange); font-weight: 700;">₱<?php echo number_format((float)($cottage['price_per_night'] ?? 0), 2); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 2rem; text-align: center; color: var(--text-light);">No cottages available yet.</div>
                        <?php endif; ?>
                    </div>
                    <button onclick="rotateCottages(1)" style="background: none; border: none; font-size: 2rem; color: var(--primary-blue); cursor: pointer; padding: 0; width: 50px; height: 50px; margin-left: 2.5rem; border-radius: 50%; background: var(--bg-light); transition: all 0.3s ease;" onmouseover="this.style.background='var(--accent-orange)'; this.style.color='white';" onmouseout="this.style.background='var(--bg-light)'; this.style.color='var(--primary-blue)';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <div id="descriptionModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 2000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 2rem; border-radius: 10px; max-width: 400px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 id="modalTitle" style="color: var(--primary-blue); margin: 0;"></h2>
                <button onclick="closeModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-light);">&times;</button>
            </div>
            <p id="modalDescription" style="color: var(--text-dark); margin-bottom: 1rem; line-height: 1.6;"></p>
            <div id="modalPrice" style="font-size: 1.5rem; color: var(--accent-orange); font-weight: 700; margin-bottom: 1.5rem;"></div>
            <button onclick="closeModal()" style="width: 100%; padding: 0.8rem; background: var(--primary-blue); color: white; border: none; border-radius: 5px; font-weight: 600; cursor: pointer;">Close</button>
        </div>
    </div>

    <!-- Pools Section -->
    <section id="pools" class="home-section-with-bg" style="background: linear-gradient(rgba(15, 23, 42, 0.72), rgba(15, 23, 42, 0.78)), url('images/villasoledadbg.png') center/cover no-repeat; padding: 3rem 0 2rem; display: none;">
        <div class="container">
            <h2 class="home-section-heading" style="color: #ffffff !important;">Pools we offer</h2>
            <div class="pool-slider-wrapper" style="perspective: 1400px;">
                <div class="pool-slider" id="poolSlider" style="display: flex; align-items: center; justify-content: center; gap: 3rem; position: relative;">
                    <button onclick="rotatePools(-1)" style="background: none; border: none; font-size: 2rem; color: var(--primary-blue); cursor: pointer; padding: 0; width: 50px; height: 50px; margin-right: 2.5rem; border-radius: 50%; background: var(--bg-light); transition: all 0.3s ease;" onmouseover="this.style.background='var(--accent-orange)'; this.style.color='white';" onmouseout="this.style.background='var(--bg-light)'; this.style.color='var(--primary-blue)';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <div class="slider-stage" style="width: 100%; max-width: 760px; height: 420px; position: relative; transform-style: preserve-3d; transition: transform 0.8s ease;">
                        <div class="pool-card pool-card-0" style="position: absolute; top: 0; left: 50%; width: 360px; height: 420px; transform-style: preserve-3d; transform-origin: center center; transition: transform 0.8s ease, opacity 0.8s ease;">
                            <div style="width: 100%; height: 100%; border-radius: 28px; overflow: hidden; box-shadow: 0 28px 60px rgba(15, 23, 42, 0.14); background: #fff; position: relative;">
                                <img src="images/guest%20pool.png" alt="Guest Pool" style="width: 100%; height: 220px; object-fit: cover; display: block;" />
                                <?php if (($poolStatusByName['main pool'] ?? 'active') === 'maintenance'): ?>
                                    <div class="pool-maintenance-badge">Under Maintenance</div>
                                <?php endif; ?>
                                <div style="padding: 1.5rem;">
                                    <h3 style="color: var(--primary-blue); font-size: 1.6rem; margin-bottom: 0.75rem;">Main Pool</h3>
                                    <p style="color: #475569; line-height: 1.8; margin-bottom: 1rem;">A spacious guest pool ideal for relaxing swims and social time with family.</p>
                                    <div style="display: flex; align-items: center; justify-content: flex-start; gap: 1rem;">
                                        <span style="color: var(--accent-orange); font-weight: 700;">FREE for guests</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="pool-card pool-card-1" style="position: absolute; top: 0; left: 50%; width: 360px; height: 420px; transform-style: preserve-3d; transform-origin: center center; transition: transform 0.8s ease, opacity 0.8s ease;">
                            <div style="width: 100%; height: 100%; border-radius: 28px; overflow: hidden; box-shadow: 0 28px 60px rgba(15, 23, 42, 0.14); background: #fff; position: relative;">
                                <img src="images/kids%20pool.png" alt="Kids Pool" style="width: 100%; height: 220px; object-fit: cover; display: block;" />
                                <?php if (($poolStatusByName['kiddie pool'] ?? 'active') === 'maintenance'): ?>
                                    <div class="pool-maintenance-badge">Under Maintenance</div>
                                <?php endif; ?>
                                <div style="padding: 1.5rem;">
                                    <h3 style="color: var(--primary-blue); font-size: 1.6rem; margin-bottom: 0.75rem;">Kiddie Pool</h3>
                                    <p style="color: #475569; line-height: 1.8; margin-bottom: 1rem;">A safe, shallow pool designed for children and family fun.</p>
                                    <div style="display: flex; align-items: center; justify-content: flex-start; gap: 1rem;">
                                        <span style="color: var(--accent-orange); font-weight: 700;">FREE for guests</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="pool-card pool-card-2" style="position: absolute; top: 0; left: 50%; width: 360px; height: 420px; transform-style: preserve-3d; transform-origin: center center; transition: transform 0.8s ease, opacity 0.8s ease;">
                            <div style="width: 100%; height: 100%; border-radius: 28px; overflow: hidden; box-shadow: 0 28px 60px rgba(15, 23, 42, 0.14); background: #fff; position: relative;">
                                <img src="images/private%20pool.png" alt="Private Pool" style="width: 100%; height: 220px; object-fit: cover; display: block;" />
                                <?php if (($poolStatusByName['private pool'] ?? 'active') === 'maintenance'): ?>
                                    <div class="pool-maintenance-badge">Under Maintenance</div>
                                <?php endif; ?>
                                <div style="padding: 1.5rem;">
                                    <h3 style="color: var(--primary-blue); font-size: 1.6rem; margin-bottom: 0.75rem;">Private Pool</h3>
                                    <p style="color: #475569; line-height: 1.8; margin-bottom: 1rem;">An exclusive private pool for guests seeking privacy and tranquility.</p>
                                    <div style="display: flex; align-items: center; justify-content: flex-start; gap: 1rem;">
                                        <span style="color: var(--accent-orange); font-weight: 700;">Available for hotel guests</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button onclick="rotatePools(1)" style="background: none; border: none; font-size: 2rem; color: var(--primary-blue); cursor: pointer; padding: 0; width: 50px; height: 50px; margin-left: 2.5rem; border-radius: 50%; background: var(--bg-light); transition: all 0.3s ease;" onmouseover="this.style.background='var(--accent-orange)'; this.style.color='white';" onmouseout="this.style.background='var(--bg-light)'; this.style.color='var(--primary-blue)';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <div id="descriptionModalPool" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 2000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 2rem; border-radius: 10px; max-width: 400px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 id="modalTitlePool" style="color: var(--primary-blue); margin: 0;"></h2>
                <button onclick="closeModalPool()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-light);">&times;</button>
            </div>
            <p id="modalDescriptionPool" style="color: var(--text-dark); margin-bottom: 1rem; line-height: 1.6;"></p>
            <div id="modalPricePool" style="font-size: 1.5rem; color: var(--accent-orange); font-weight: 700; margin-bottom: 1.5rem;"></div>
            <button onclick="closeModalPool()" style="width: 100%; padding: 0.8rem; background: var(--primary-blue); color: white; border: none; border-radius: 5px; font-weight: 600; cursor: pointer;">Close</button>
        </div>
    </div>

    <script>
        let roomIndex = 0;
        const roomCards = document.querySelectorAll('.room-card');

        function updateRoomRotation() {
            const total = roomCards.length;
            const prevIndex = (roomIndex - 1 + total) % total;
            const nextIndex = (roomIndex + 1) % total;

            roomCards.forEach((card, index) => {
                let transform = 'translateX(-50%) rotateY(0deg) translateZ(120px) scale(1)';
                let opacity = 1;
                let zIndex = 3;

                if (index === prevIndex) {
                    transform = 'translateX(calc(-50% - 320px)) rotateY(30deg) translateZ(-20px) scale(0.78)';
                    opacity = 0.7;
                    zIndex = 2;
                } else if (index === nextIndex) {
                    transform = 'translateX(calc(-50% + 320px)) rotateY(-30deg) translateZ(-20px) scale(0.78)';
                    opacity = 0.7;
                    zIndex = 2;
                } else if (index !== roomIndex) {
                    transform = 'translateX(-50%) rotateY(0deg) translateZ(-140px) scale(0.72)';
                    opacity = 0.45;
                    zIndex = 1;
                }

                card.style.transform = transform;
                card.style.opacity = opacity;
                card.style.zIndex = zIndex;
            });
        }

        function rotateRooms(direction) {
            roomIndex = (roomIndex + direction + roomCards.length) % roomCards.length;
            updateRoomRotation();
        }

        function openModalRoom(button) {
            document.getElementById('modalTitleRoom').textContent = button.dataset.name || 'Room';
            document.getElementById('modalPriceRoom').textContent = button.dataset.price || '';
            document.getElementById('modalDescriptionRoom').textContent = button.dataset.description || 'No description available.';

            const promoEl = document.getElementById('modalPromoRoom');
            if (promoEl) {
                promoEl.style.display = 'none';
                promoEl.textContent = '';
            }

            document.getElementById('descriptionModalRoom').style.display = 'flex';
        }

        function closeModalRoom() {
            document.getElementById('descriptionModalRoom').style.display = 'none';
        }

        let cottageIndex = 0;
        const cottageCards = document.querySelectorAll('.cottage-card');

        function updateCottageRotation() {
            const total = cottageCards.length;
            const prevIndex = (cottageIndex - 1 + total) % total;
            const nextIndex = (cottageIndex + 1) % total;

            cottageCards.forEach((card, index) => {
                let transform = 'translateX(-50%) rotateY(0deg) translateZ(120px) scale(1)';
                let opacity = 1;
                let zIndex = 3;

                if (index === prevIndex) {
                    transform = 'translateX(calc(-50% - 320px)) rotateY(30deg) translateZ(-20px) scale(0.78)';
                    opacity = 0.7;
                    zIndex = 2;
                } else if (index === nextIndex) {
                    transform = 'translateX(calc(-50% + 320px)) rotateY(-30deg) translateZ(-20px) scale(0.78)';
                    opacity = 0.7;
                    zIndex = 2;
                } else if (index !== cottageIndex) {
                    transform = 'translateX(-50%) rotateY(0deg) translateZ(-140px) scale(0.72)';
                    opacity = 0.45;
                    zIndex = 1;
                }

                card.style.transform = transform;
                card.style.opacity = opacity;
                card.style.zIndex = zIndex;
            });
        }

        function rotateCottages(direction) {
            cottageIndex = (cottageIndex + direction + cottageCards.length) % cottageCards.length;
            updateCottageRotation();
        }

        function openModal(title, description, price) {
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalDescription').textContent = description;
            document.getElementById('modalPrice').textContent = price;
            document.getElementById('descriptionModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('descriptionModal').style.display = 'none';
        }

        let poolIndex = 0;
        const poolCards = document.querySelectorAll('.pool-card');

        function updatePoolRotation() {
            const total = poolCards.length;
            const prevIndex = (poolIndex - 1 + total) % total;
            const nextIndex = (poolIndex + 1) % total;

            poolCards.forEach((card, index) => {
                let transform = 'translateX(-50%) rotateY(0deg) translateZ(120px) scale(1)';
                let opacity = 1;
                let zIndex = 3;

                if (index === prevIndex) {
                    transform = 'translateX(calc(-50% - 320px)) rotateY(30deg) translateZ(-20px) scale(0.78)';
                    opacity = 0.7;
                    zIndex = 2;
                } else if (index === nextIndex) {
                    transform = 'translateX(calc(-50% + 320px)) rotateY(-30deg) translateZ(-20px) scale(0.78)';
                    opacity = 0.7;
                    zIndex = 2;
                } else if (index !== poolIndex) {
                    transform = 'translateX(-50%) rotateY(0deg) translateZ(-140px) scale(0.72)';
                    opacity = 0.45;
                    zIndex = 1;
                }

                card.style.transform = transform;
                card.style.opacity = opacity;
                card.style.zIndex = zIndex;
            });
        }

        function rotatePools(direction) {
            poolIndex = (poolIndex + direction + poolCards.length) % poolCards.length;
            updatePoolRotation();
        }

        function openModalPool(title, description, price) {
            document.getElementById('modalTitlePool').textContent = title;
            document.getElementById('modalDescriptionPool').textContent = description;
            document.getElementById('modalPricePool').textContent = price;
            document.getElementById('descriptionModalPool').style.display = 'flex';
        }

        function closeModalPool() {
            document.getElementById('descriptionModalPool').style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (roomCards.length) updateRoomRotation();
            if (cottageCards.length) updateCottageRotation();
            if (poolCards.length) updatePoolRotation();
            updatePageSections();
        });

        window.addEventListener('click', function(event) {
            const roomModal = document.getElementById('descriptionModalRoom');
            const cottageModal = document.getElementById('descriptionModal');
            const poolModal = document.getElementById('descriptionModalPool');
            if (event.target === roomModal) closeModalRoom();
            if (event.target === cottageModal) closeModal();
            if (event.target === poolModal) closeModalPool();
        });

        function showSection(sectionId) {
            window.location.hash = sectionId;
        }

        function updatePageSections() {
            const homeSections = ['home', 'welcome', 'contact', 'reviews'];
            const sectionIds = [...homeSections, 'rooms', 'cottages', 'pools'];
            const target = window.location.hash.replace('#', '') || 'home';

            sectionIds.forEach(id => {
                const section = document.getElementById(id);
                if (!section) return;
                const isHomeTarget = homeSections.includes(target) && homeSections.includes(id);
                const isSpecialTarget = target === id;
                section.style.display = (isHomeTarget || isSpecialTarget) ? 'block' : 'none';
            });
        }

        window.addEventListener('hashchange', updatePageSections);
    </script>

    <!-- Reviews Section -->
    <section id="reviews" class="section reviews">
        <div class="container">
            <div style="text-align: center; margin-bottom: 2rem;">
                <h2>Reviews</h2>
                <p>Share your experience or read what other guests loved about Villa Soledad.</p>
            </div>

            <!-- Review Form - First -->
            <div class="review-form" style="background: white; padding: 2rem; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 2rem;">
                <?php if (isset($user) && $user->isLoggedIn()): ?>
                    <form action="<?php echo SITE_URL; ?>controllers/ReviewController.php?action=create" method="POST" id="reviewForm">
                        <h3 style="color: var(--primary-blue); margin-bottom: 1rem;">Write a Review</h3>
                        <input type="hidden" name="return_to" value="index">
                        <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 0.75rem;">Select a rating: <span style="color: red;">*</span></p>
                        <div class="star-rating" style="margin-bottom: 1rem;">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star" data-rating="<?php echo $i; ?>" style="font-size: 1.5rem; cursor: pointer; color: #d1d5db;"></i>
                            <?php endfor; ?>
                        </div>
                        <textarea name="review_text" id="reviewText" placeholder="Tell us about your stay at Villa Soledad" required style="width: 100%; min-height: 100px; padding: 1rem; border: 1px solid #e5e7eb; border-radius: 8px; font-family: inherit; resize: vertical;"></textarea>
                        <div class="review-note" style="margin:0.75rem 0 1rem; color:#475569; font-size:0.95rem;">Maximum 30 words. <span id="wordCount">0</span>/30 words used.</div>
                        <input type="hidden" name="rating" id="ratingInput" value="0">
                        <button type="submit" class="btn-primary">Submit Review</button>
                    </form>
                <?php else: ?>
                    <div class="review-form-box" style="text-align: center; padding: 2rem;">
                        <p><strong><a href="#" onclick="event.preventDefault(); openLoginModal()">Login</a></strong> to share your experience with the resort.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Submitted Reviews - Below the form -->
            <div style="text-align: center; margin-bottom: 1rem;">
                <h3 style="color: var(--primary-blue);">Guest Reviews</h3>
            </div>
            <div class="reviews-list" id="reviewsList">
                <?php if (!empty($recentReviews)): ?>
                    <?php foreach ($recentReviews as $review): ?>
                        <div class="review-item" style="background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 1rem; border-left: 4px solid var(--accent-orange);">
                            <div class="review-header">
                                <span class="review-author" style="font-weight: 600; color: var(--primary-blue);"><?php echo htmlspecialchars($review['fullname'] ?? 'Guest'); ?></span>
                                <span class="review-rating" style="color: var(--accent-orange); margin-left: 0.5rem;"><?php echo $review['stars_html'] ?? ''; ?></span>
                            </div>
                            <p style="margin: 0.75rem 0; color: #374151; line-height: 1.6;"><?php echo htmlspecialchars($review['review_text']); ?></p>
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                                <div class="review-date" style="font-size: 0.85rem; color: #6b7280;"><?php echo htmlspecialchars($review['created_date'] ?? ''); ?></div>
                                <?php if (isset($_SESSION['user_id']) && $review['user_id'] == $_SESSION['user_id']): ?>
                                    <button type="button" class="home-review-edit" data-review-id="<?php echo (int)$review['id']; ?>" data-rating="<?php echo (int)$review['rating']; ?>" data-review-text="<?php echo htmlspecialchars($review['review_text'], ENT_QUOTES, 'UTF-8'); ?>" style="border: 0; background: transparent; color: var(--primary-blue); cursor: pointer; font-weight: 600;">Edit</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="review-item" style="text-align: center; padding: 2rem; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                        <p>No reviews have been submitted yet. Be the first to share your stay!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <script>
        (function() {
            const reviewText = document.getElementById('reviewText');
            const wordCount = document.getElementById('wordCount');
            const reviewForm = document.getElementById('reviewForm');
            const reviewHeading = reviewForm?.querySelector('h3');
            const submitButton = reviewForm?.querySelector('button[type="submit"]');

            function countWords(value) {
                return value.trim().split(/\s+/).filter(word => word.length > 0).length;
            }

            if (reviewText && wordCount) {
                reviewText.addEventListener('input', function() {
                    const words = countWords(this.value);
                    wordCount.textContent = words;
                    if (words > 30) {
                        this.setCustomValidity('Please limit your review to 30 words.');
                    } else {
                        this.setCustomValidity('');
                    }
                });
            }

            if (reviewForm && reviewText) {
                reviewForm.addEventListener('submit', function(event) {
                    const words = countWords(reviewText.value);
                    const rating = parseInt(document.getElementById('ratingInput').value) || 0;
                    
                    if (words > 30) {
                        event.preventDefault();
                        alert('Please limit your review to 30 words.');
                        return;
                    }
                    
                    if (rating === 0) {
                        event.preventDefault();
                        alert('Please select a star rating before submitting your review.');
                        return;
                    }
                });
            }

            document.querySelectorAll('.home-review-edit').forEach(button => {
                button.addEventListener('click', function() {
                    reviewForm.action = '<?php echo SITE_URL; ?>controllers/ReviewController.php?action=update&id=' + encodeURIComponent(this.dataset.reviewId);
                    reviewHeading.textContent = 'Edit Your Review';
                    submitButton.textContent = 'Save Review';
                    reviewText.value = this.dataset.reviewText;
                    ratingInput.value = this.dataset.rating;
                    stars.forEach(s => s.style.color = Number(s.dataset.rating) <= Number(this.dataset.rating) ? '#ff7a3d' : '#d1d5db');
                    reviewText.dispatchEvent(new Event('input'));
                    reviewForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    reviewText.focus();
                });
            });

            // Handle star rating clicks
            const stars = document.querySelectorAll('.star-rating i');
            const ratingInput = document.getElementById('ratingInput');

            if (stars.length > 0) {
                stars.forEach(star => {
                    star.addEventListener('click', function() {
                        const rating = this.getAttribute('data-rating');
                        ratingInput.value = rating;

                        // Update visual state
                        stars.forEach(s => {
                            s.style.color = '#d1d5db';
                        });
                        for (let i = 0; i < rating; i++) {
                            stars[i].style.color = '#ff7a3d';
                        }
                    });

                    star.addEventListener('mouseenter', function() {
                        const rating = this.getAttribute('data-rating');
                        stars.forEach(s => {
                            s.style.color = '#d1d5db';
                        });
                        for (let i = 0; i < rating; i++) {
                            stars[i].style.color = '#ff7a3d';
                        }
                    });
                });

                // Reset on mouse leave
                const starRatingDiv = document.querySelector('.star-rating');
                if (starRatingDiv) {
                    starRatingDiv.addEventListener('mouseleave', function() {
                        const rating = ratingInput.value || 0;
                        stars.forEach(s => {
                            s.style.color = '#d1d5db';
                        });
                        for (let i = 0; i < rating; i++) {
                            stars[i].style.color = '#ff7a3d';
                        }
                    });
                }
            }
        })();

        // Review success toast notification (similar to booking toast)
        function createOrGetReviewToast() {
            let toast = document.getElementById('reviewToast');
            let toastMessage = document.getElementById('reviewToastMessage');

            if (!toast || !toastMessage) {
                toast = document.createElement('div');
                toast.id = 'reviewToast';
                toast.style.cssText = 'display:none;position:fixed;top:88px;right:20px;background:#ffffff;color:#000000;padding:1rem 1.25rem;border:2px solid #16a34a;border-radius:0.75rem;box-shadow:0 10px 25px rgba(0,0,0,0.2);z-index:10001;max-width:340px;animation:slideIn 0.3s ease;';
                toastMessage = document.createElement('div');
                toastMessage.id = 'reviewToastMessage';
                toastMessage.style.cssText = 'font-size:0.95rem;line-height:1.35;';
                const icon = document.createElement('i');
                icon.className = 'fas fa-check-circle';
                icon.style.cssText = 'font-size:1.1rem;color:#16a34a;margin-right:0.75rem;';
                const content = document.createElement('div');
                content.style.cssText = 'display:flex;align-items:center;gap:0.75rem;';
                content.appendChild(icon);
                content.appendChild(toastMessage);
                toast.appendChild(content);
                document.body.appendChild(toast);
            }

            return { toast, toastMessage };
        }

        function showReviewToast(message) {
            const { toast, toastMessage } = createOrGetReviewToast();
            if (!toast || !toastMessage) {
                console.error('Review toast could not be created.');
                return;
            }

            toastMessage.textContent = message;
            toast.style.display = 'flex';
            toast.style.opacity = '1';
            toast.style.visibility = 'visible';

            if (window.reviewToastTimeout) {
                clearTimeout(window.reviewToastTimeout);
            }

            window.reviewToastTimeout = setTimeout(() => {
                hideReviewToast();
            }, 4500);
        }

        function hideReviewToast() {
            const toast = document.getElementById('reviewToast');
            if (toast) {
                toast.style.display = 'none';
            }
        }

        // Check for review status on page load and show toast
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const reviewStatus = urlParams.get('review_status');
            
            if (reviewStatus === 'created') {
                showReviewToast('Your Review is Successfully submitted');
                // Clean up URL without reloading
                const newUrl = window.location.pathname + window.location.hash;
                window.history.replaceState({}, '', newUrl);
            } else if (reviewStatus === 'updated') {
                showReviewToast('Your Edited Review is Successfully submitted');
                // Clean up URL without reloading
                const newUrl = window.location.pathname + window.location.hash;
                window.history.replaceState({}, '', newUrl);
            }
        });

        // Add slide-in animation for review toast
        const reviewToastStyle = document.createElement('style');
        reviewToastStyle.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(reviewToastStyle);
    </script>

<?php
require_once 'includes/footer.php';
?>
