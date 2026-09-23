<?php
session_start();
require_once __DIR__ . '/../config/config.php';

$isLoggedIn = isset($_SESSION['user_id']);
$userName = $isLoggedIn ? $_SESSION['user_name'] : '';
$message = '';

// Process contact form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = htmlspecialchars($_POST['name'] ?? '');
    $email = htmlspecialchars($_POST['email'] ?? '');
    $phone = htmlspecialchars($_POST['phone'] ?? '');
    $subject = htmlspecialchars($_POST['subject'] ?? '');
    $message_text = htmlspecialchars($_POST['message'] ?? '');

    if (!empty($name) && !empty($email) && !empty($subject) && !empty($message_text)) {
        // TODO: Save to database or send email
        $message = '<div style="background-color: #d1fae5; color: #065f46; padding: 1rem; border-radius: 5px; margin-bottom: 1rem;">Thank you for contacting us! We will respond to your inquiry shortly.</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - Villa Soledad</title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>css/style.css?v=20260919-system1">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .contact-hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--light-blue) 100%);
            color: var(--white);
            padding: 4rem 0;
            text-align: center;
        }

        .contact-hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .contact-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            margin: 4rem 0;
        }

        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .contact-info-item {
            background: var(--bg-light);
            padding: 1.5rem;
            border-radius: 10px;
            border-left: 4px solid var(--accent-orange);
        }

        .contact-info-item i {
            font-size: 1.8rem;
            color: var(--accent-orange);
            margin-bottom: 0.5rem;
        }

        .contact-info-item h3 {
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }

        .contact-info-item p {
            color: var(--text-light);
        }

        .contact-form {
            background: var(--white);
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--primary-blue);
            font-weight: 600;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid var(--border-gray);
            border-radius: 5px;
            font-family: inherit;
            font-size: 1rem;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-orange);
            box-shadow: 0 0 5px rgba(249, 115, 22, 0.2);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 150px;
        }

        .btn-submit {
            background-color: var(--accent-orange);
            color: var(--white);
            padding: 1rem 2rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn-submit:hover {
            background-color: var(--light-orange);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(249, 115, 22, 0.3);
        }

        .map-container {
            margin: 4rem 0;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            height: 400px;
            text-align: center;
        }

        .map-container iframe {
            margin: 0 auto;
            display: block;
            max-width: 800px;
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            justify-content: center;
        }

        .social-links a {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-blue);
            color: var(--white);
            border-radius: 50%;
            font-size: 1.3rem;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .social-links a:hover {
            background-color: var(--accent-orange);
            transform: translateY(-5px);
        }

        @media (max-width: 768px) {
            .contact-container {
                grid-template-columns: 1fr;
            }

            .contact-hero h1 {
                font-size: 2rem;
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

    <!-- Contact Hero -->
    <section class="contact-hero">
        <div class="container">
            <h1>Contact Us</h1>
            <p>We'd love to hear from you. Get in touch with us today!</p>
        </div>
    </section>

    <!-- Main Content -->
    <div class="container">
        <!-- Contact Container -->
        <div class="contact-container">
            <!-- Contact Information -->
            <div class="contact-info">
                <div class="contact-info-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <h3>Location</h3>
                    <p><?php echo RESORT_ADDRESS ?? 'Villa Soledad, City'; ?></p>
                </div>

                <div class="contact-info-item">
                    <i class="fas fa-phone"></i>
                    <h3>Phone</h3>
                    <p><a href="tel:<?php echo RESORT_PHONE ?? '+63 (0) 123 456 789'; ?>" style="color: var(--accent-orange); text-decoration: none; font-weight: 600;"><?php echo RESORT_PHONE ?? '+63 (0) 123 456 789'; ?></a></p>
                </div>

                <div class="contact-info-item">
                    <i class="fas fa-envelope"></i>
                    <h3>Email</h3>
                    <p><a href="mailto:<?php echo RESORT_EMAIL ?? 'info@villasoledad.com'; ?>" style="color: var(--accent-orange); text-decoration: none; font-weight: 600;"><?php echo RESORT_EMAIL ?? 'info@villasoledad.com'; ?></a></p>
                </div>

                <div class="contact-info-item">
                    <i class="fas fa-clock"></i>
                    <h3>Hours of Operation</h3>
                    <p><strong>Monday - Sunday:</strong> <?php echo RESORT_HOURS ?? 'Open 24/7'; ?></p>
                    <p style="margin-top: 0.5rem; font-size: 0.9rem; color: var(--text-light);">Public holidays may have special hours</p>
                </div>

                <div class="contact-info-item">
                    <h3>Connect With Us</h3>
                    <div class="social-links">
                        <a href="#" title="Facebook"><i class="fab fa-facebook"></i></a>
                        <a href="#" title="Instagram"><i class="fab fa-instagram"></i></a>
                        <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
                        <a href="#" title="YouTube"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
            </div>

            <!-- Contact Form -->
            <div class="contact-form">
                <h2 style="color: var(--primary-blue); margin-bottom: 1.5rem;">Send us a Message</h2>
                
                <?php echo $message; ?>

                <form method="POST">
                    <div class="form-group">
                        <label for="name">Full Name *</label>
                        <input type="text" id="name" name="name" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" required>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone">
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject *</label>
                        <input type="text" id="subject" name="subject" required>
                    </div>

                    <div class="form-group">
                        <label for="message">Message *</label>
                        <textarea id="message" name="message" required></textarea>
                    </div>

                    <button type="submit" class="btn-submit">Send Message</button>
                </form>

                <p style="text-align: center; color: var(--text-light); margin-top: 1rem; font-size: 0.9rem;">
                    We typically respond within 24 hours on business days.
                </p>
            </div>
        </div>

        <!-- Map -->
        <section>
            <h2 style="text-align: center; color: var(--primary-blue); margin-bottom: 2rem;">Find Us on the Map</h2>
            <div class="map-container">
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3870.660196565071!2d121.31243131083069!3d14.03814539054243!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33bd4301035b4ef3%3A0xd22111becbb8a4!2sVilla%20Soledad%20Garden%20Resort!5e0!3m2!1sen!2sph!4v1774250669779!5m2!1sen!2sph" width="100%" height="100%" style="border:none;" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
        </section>

        <!-- FAQ Section -->
        <section style="margin: 4rem 0;">
            <h2 style="text-align: center; color: var(--primary-blue); margin-bottom: 2rem;">Frequently Asked Questions</h2>
            <div style="max-width: 600px; margin: 0 auto;">
                <div style="background: var(--bg-light); padding: 1.5rem; border-radius: 5px; margin-bottom: 1rem;">
                    <h3 style="color: var(--primary-blue); margin-bottom: 0.5rem;">What are your check-in and check-out times?</h3>
                    <p style="color: var(--text-light);">Check-in is at 2:00 PM and check-out is at 12:00 PM. Early check-in and late check-out may be available upon request.</p>
                </div>

                <div style="background: var(--bg-light); padding: 1.5rem; border-radius: 5px; margin-bottom: 1rem;">
                    <h3 style="color: var(--primary-blue); margin-bottom: 0.5rem;">Do you have cancellation policies?</h3>
                    <p style="color: var(--text-light);">Cancellations made 14 days before arrival are eligible for a full refund. For cancellations within 14 days, a 50% refund is provided.</p>
                </div>

                <div style="background: var(--bg-light); padding: 1.5rem; border-radius: 5px; margin-bottom: 1rem;">
                    <h3 style="color: var(--primary-blue); margin-bottom: 0.5rem;">Is there parking available?</h3>
                    <p style="color: var(--text-light);">Yes, free parking is available for all our guests in the resort parking area.</p>
                </div>

                <div style="background: var(--bg-light); padding: 1.5rem; border-radius: 5px;">
                    <h3 style="color: var(--primary-blue); margin-bottom: 0.5rem;">Do you offer group discounts?</h3>
                    <p style="color: var(--text-light);">Yes! For groups of 20 or more, please contact us directly to discuss special rates and arrangements.</p>
                </div>
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