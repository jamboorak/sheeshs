<?php
session_start();
require_once __DIR__ . '/../config/config.php';

$isLoggedIn = isset($_SESSION['user_id']);
$userName = $isLoggedIn ? $_SESSION['user_name'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - Villa Soledad</title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>css/style.css?v=20260919-system1">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .about-hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--light-blue) 100%);
            color: var(--white);
            padding: 4rem 0;
            text-align: center;
        }

        .about-hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .about-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            margin: 4rem 0;
            align-items: center;
        }

        .about-text h2 {
            color: var(--primary-blue);
            margin-bottom: 1rem;
            font-size: 2rem;
        }

        .about-text p {
            color: var(--text-light);
            line-height: 1.8;
            margin-bottom: 1rem;
        }

        .about-image {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--light-blue) 100%);
            height: 400px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 5rem;
        }

        .values-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
            margin: 4rem 0;
        }

        .value-card {
            background: var(--bg-light);
            padding: 2rem;
            border-radius: 10px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .value-card:hover {
            transform: translateY(-5px);
        }

        .value-card i {
            font-size: 3rem;
            color: var(--accent-orange);
            margin-bottom: 1rem;
        }

        .value-card h3 {
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }

        .team-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
            margin: 4rem 0;
        }

        .team-member {
            text-align: center;
        }

        .team-member-avatar {
            width: 150px;
            height: 150px;
            background: linear-gradient(135deg, var(--primary-blue), var(--light-blue));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: var(--white);
            margin: 0 auto 1rem;
        }

        .team-member h3 {
            color: var(--primary-blue);
            margin-bottom: 0.25rem;
        }

        .team-member p {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .about-content {
                grid-template-columns: 1fr;
            }

            .values-grid {
                grid-template-columns: 1fr;
            }

            .team-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .about-text h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body class="public-page">
    <!-- Header & Navigation -->
    <header class="header">
        <div class="container">
            <div class="nav">
                <a href="<?php echo SITE_URL; ?>index.php" class="logo">
                    <img src="<?php echo SITE_URL; ?>images/logo.jpg" alt="Villa Soledad Garden Resort Logo" style="height: 44px; width: 44px; object-fit: cover; border-radius: 50%;">
                    <span>Villa Soledad Garden Resort</span>
                </a>
                <nav class="nav-links">
                    <a href="<?php echo SITE_URL; ?>index.php">Home</a>
                    <a href="<?php echo SITE_URL; ?>index.php#rooms">Rooms</a>
                    <a href="<?php echo SITE_URL; ?>index.php#cottages">Cottages</a>
                    <a href="<?php echo SITE_URL; ?>index.php#pools">Pools</a>
                    <?php if ($isLoggedIn): ?>
                        <a href="<?php echo SITE_URL; ?>profile.php" class="btn-profile">
                            <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($userName); ?>
                        </a>
                    <?php else: ?>
                        <a href="<?php echo SITE_URL; ?>google-auth.php?action=login" class="btn-profile">
                            <i class="fas fa-user-circle"></i> Login/Sign Up
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </header>

    <!-- About Hero -->
    <section class="about-hero">
        <div class="container">
            <h1>About Villa Soledad</h1>
            <p>Creating Unforgettable Memories Since 2015</p>
        </div>
    </section>

    <!-- Main Content -->
    <div class="container">
        <!-- Our Story -->
        <section class="about-content">
            <div class="about-text">
                <h2>Our Story</h2>
                <p>Villa Soledad Resort was founded in 2015 with a simple mission: to provide an exceptional retreat experience for families, friends, and corporate groups. What started as a small, family-run establishment has grown into one of the region's most beloved resorts.</p>
                <p>We believe that the perfect resort experience combines luxury, comfort, and genuine hospitality. Our dedicated team works tirelessly to ensure every guest leaves with cherished memories and a desire to return.</p>
            </div>
            <div class="about-image">
                <i class="fas fa-hotel"></i>
            </div>
        </section>

        <!-- Our Values -->
        <section>
            <h2 style="text-align: center; color: var(--primary-blue); margin-bottom: 3rem;">Our Values</h2>
            <div class="values-grid">
                <div class="value-card">
                    <i class="fas fa-heart"></i>
                    <h3>Guest Care</h3>
                    <p>Your satisfaction and comfort are our top priorities. We go the extra mile to make your stay special.</p>
                </div>
                <div class="value-card">
                    <i class="fas fa-leaf"></i>
                    <h3>Sustainability</h3>
                    <p>We are committed to environmental responsibility and sustainable practices in all our operations.</p>
                </div>
                <div class="value-card">
                    <i class="fas fa-star"></i>
                    <h3>Quality</h3>
                    <p>Excellence in service, facilities, and amenities is our guarantee to every guest who chooses us.</p>
                </div>
            </div>
        </section>

        <!-- Our Team -->
        <section>
            <h2 style="text-align: center; color: var(--primary-blue); margin-bottom: 3rem;">Meet Our Team</h2>
            <div class="team-grid">
                <div class="team-member">
                    <div class="team-member-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <h3>Maria Santos</h3>
                    <p>General Manager</p>
                </div>
                <div class="team-member">
                    <div class="team-member-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <h3>Juan Reyes</h3>
                    <p>Operations Manager</p>
                </div>
                <div class="team-member">
                    <div class="team-member-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <h3>Ana Garcia</h3>
                    <p>Guest Services</p>
                </div>
                <div class="team-member">
                    <div class="team-member-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <h3>Carlos Lopez</h3>
                    <p>Facilities Manager</p>
                </div>
            </div>
        </section>

        <!-- Why Choose Us -->
        <section style="margin: 4rem 0;">
            <h2 style="text-align: center; color: var(--primary-blue); margin-bottom: 2rem;">Why Choose Villa Soledad?</h2>
            <div" style="background: var(--bg-light); padding: 2rem; border-radius: 10px; line-height: 1.8;">
                <ul style="list-style: none;">
                    <li style="margin-bottom: 1rem;"><i class="fas fa-check" style="color: var(--success); margin-right: 0.5rem;"></i> <strong>Prime Location</strong> - Conveniently accessible yet peaceful and serene</li>
                    <li style="margin-bottom: 1rem;"><i class="fas fa-check" style="color: var(--success); margin-right: 0.5rem;"></i> <strong>World-Class Facilities</strong> - Olympic pool, jacuzzi, and well-maintained rooms</li>
                    <li style="margin-bottom: 1rem;"><i class="fas fa-check" style="color: var(--success); margin-right: 0.5rem;"></i> <strong>Expert Staff</strong> - Trained professionals ready to serve 24/7</li>
                    <li style="margin-bottom: 1rem;"><i class="fas fa-check" style="color: var(--success); margin-right: 0.5rem;"></i> <strong>Affordable Pricing</strong> - Premium experience at competitive rates</li>
                    <li style="margin-bottom: 1rem;"><i class="fas fa-check" style="color: var(--success); margin-right: 0.5rem;"></i> <strong>Family-Friendly</strong> - Perfect for all ages and group sizes</li>
                    <li><i class="fas fa-check" style="color: var(--success); margin-right: 0.5rem;"></i> <strong>Flexible Booking</strong> - Easy cancellation and rescheduling options</li>
                </ul>
            </div>
        </section>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Quick Links</h3>
                    <ul>
                        <li><a href="about.php">About Us</a></li>
                        <li><a href="contact.php">Contact</a></li>
                        <li><a href="#">Booking Policy</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>Contact Info</h3>
                    <p><?php echo RESORT_PHONE ?? '+63 (0) 123 456 789'; ?></p>
                    <p><?php echo RESORT_EMAIL ?? 'info@villasoledad.com'; ?></p>
                </div>
                <div class="footer-section">
                    <h3>Follow Us</h3>
                    <div class="social-icons">
                        <a href="#"><i class="fab fa-facebook"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2024 Villa Soledad Resort. All rights reserved.</p>
            </div>
        </div>
    </footer>
</body>
</html>