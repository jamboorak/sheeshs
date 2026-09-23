<?php
/**
 * Review Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/Review.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../includes/ActivityLogger.php';

class ReviewController {
    public $review;
    private $user;
    
    public function __construct() {
        $this->review = new Review();
        $this->user = new User();
    }
    
    /**
     * Require authentication
     */
    public function requireAuth() {
        if (!$this->user->isLoggedIn()) {
            $_SESSION['error'] = 'Please login to submit a review';
            header("Location: ../google-auth.php?action=login");
            exit();
        }
    }
    
    /**
     * Create a new review
     */
    public function create() {
        $this->requireAuth();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect(SITE_URL . 'index.php#reviews');
            return;
        }
        
        $userId = $_SESSION['user_id'];
        $rating = intval($_POST['rating'] ?? 0);
        $reviewText = trim($_POST['review_text'] ?? '');
        
        $result = $this->review->create($userId, $rating, $reviewText);
        
        if ($result['success']) {
            $activityDatabase = new Database();
            logUserActivity($activityDatabase->getConnection(), $userId, 'review_created', 'Created a review');
            $this->redirect(SITE_URL . 'index.php?review_status=created#reviews');
        } else {
            $_SESSION['error'] = $result['message'];
            $_SESSION['form_data'] = $_POST;
            $this->redirect(SITE_URL . 'index.php#reviews');
        }
    }
    
    /**
     * Get all reviews for display
     */
    public function getAllReviews($page = 1) {
        return $this->review->getReviewsWithStars($page);
    }
    
    /**
     * Get user's reviews
     */
    public function getUserReviews($page = 1) {
        $this->requireAuth();
        
        $userId = $_SESSION['user_id'];
        return $this->review->getUserReviews($userId, $page);
    }
    
    /**
     * Get review details
     */
    public function getReview($reviewId) {
        $review = $this->review->getReviewById($reviewId);
        
        // Check if user owns this review (for edit/delete)
        if ($review && $this->user->isLoggedIn() && $review['user_id'] != $_SESSION['user_id']) {
            return null;
        }
        
        return $review;
    }
    
    /**
     * Update a review
     */
    public function update($reviewId) {
        $this->requireAuth();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect(SITE_URL . 'reviews.php');
            return;
        }
        
        $userId = $_SESSION['user_id'];
        $rating = intval($_POST['rating'] ?? 0);
        $reviewText = trim($_POST['review_text'] ?? '');
        
        $result = $this->review->update($reviewId, $userId, $rating, $reviewText);
        
        if ($result['success']) {
            $activityDatabase = new Database();
            logUserActivity($activityDatabase->getConnection(), $userId, 'review_updated', 'Updated review #' . (int)$reviewId);
        } else {
            $_SESSION['error'] = $result['message'];
            $_SESSION['form_data'] = $_POST;
        }
        
        $returnTo = ($_POST['return_to'] ?? '') === 'index' ? 'index.php' : 'reviews.php';
        if ($result['success']) {
            $returnTo .= '?review_status=updated#reviews';
        } else {
            $returnTo .= '#reviews';
        }
        $this->redirect(SITE_URL . $returnTo);
    }
    
    /**
     * Delete a review
     */
    public function delete($reviewId) {
        $this->requireAuth();
        
        $userId = $_SESSION['user_id'];
        $result = $this->review->delete($reviewId, $userId);
        
        if ($result['success']) {
            $activityDatabase = new Database();
            logUserActivity($activityDatabase->getConnection(), $userId, 'review_deleted', 'Deleted review #' . (int)$reviewId);
            $_SESSION['success'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }
        
        $this->redirect(SITE_URL . 'reviews.php');
    }
    
    /**
     * Get review statistics
     */
    public function getStatistics() {
        return $this->review->getStatistics();
    }
    
    /**
     * Get recent reviews for homepage
     */
    public function getRecentReviews($limit = 5) {
        $reviews = $this->review->getRecentReviews($limit);
        
        foreach ($reviews as &$review) {
            $review['stars_html'] = $this->generateStars($review['rating']);
            $review['created_date'] = date('F j, Y', strtotime($review['created_at']));
        }
        
        return $reviews;
    }
    
    /**
     * Get all reviews for review section
     */
    public function getAllReviewsForSection($limit = 20) {
        return $this->review->getRecentReviewsEnhanced($limit);
    }
    
    /**
     * Get user's review history
     */
    public function getUserReviewHistory() {
        $this->requireAuth();
        
        $userId = $_SESSION['user_id'];
        return $this->review->getAllUserReviews($userId);
    }
    
    /**
     * Generate star rating HTML
     */
    private function generateStars($rating) {
        $stars = '';
        for ($i = 1; $i <= 5; $i++) {
            if ($i <= $rating) {
                $stars .= '<i class="fas fa-star"></i>';
            } else {
                $stars .= '<i class="far fa-star"></i>';
            }
        }
        return $stars;
    }
    
    /**
     * Calculate time ago string
     */
    private function timeAgo($datetime) {
        $time = strtotime($datetime);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            return floor($diff / 60) . ' minutes ago';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . ' hours ago';
        } elseif ($diff < 2592000) {
            return floor($diff / 86400) . ' days ago';
        } else {
            return date('M j, Y', $time);
        }
    }
    
    /**
     * Redirect to a page
     */
    private function redirect($page) {
        header("Location: $page");
        exit();
    }
}

// Handle route actions
if (isset($_GET['action'])) {
    $controller = new ReviewController();
    
    switch ($_GET['action']) {
        case 'create':
            $controller->create();
            break;
        case 'update':
            $reviewId = $_GET['id'] ?? 0;
            $controller->update($reviewId);
            break;
        case 'delete':
            $reviewId = $_GET['id'] ?? 0;
            $controller->delete($reviewId);
            break;
        default:
            header("Location: index.php");
            exit();
    }
}
