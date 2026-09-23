<?php
require_once __DIR__ . '/../includes/header.php';

// Initialize review controller
$reviewController = new ReviewController();
$allReviews = $reviewController->getAllReviewsForSection(50);

// Set page title
$pageTitle = 'Guest Reviews - Villa Soledad Garden Resort';
?>

<main>
    <!-- Reviews Section -->
    <section class="reviews-section" style="padding: 80px 0; background: #f8fafc;">
        <div class="container">
            <div class="section-header" style="text-align: center; margin-bottom: 3rem;">
                <h1 style="font-size: 2.5rem; font-weight: 700; color: #1f2937; margin-bottom: 1rem;">
                    Guest Reviews
                </h1>
                <p style="font-size: 1.2rem; color: #6b7280; max-width: 600px; margin: 0 auto;">
                    See what our guests are saying about their experience at Villa Soledad Garden Resort
                </p>
            </div>

            <!-- Review Statistics -->
            <div class="review-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 2rem; margin-bottom: 3rem;">
                <div class="stat-card" style="background: white; padding: 2rem; border-radius: 15px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <div style="font-size: 3rem; font-weight: 700; color: #FF7A3D; margin-bottom: 0.5rem;">
                        <?php echo count($allReviews); ?>
                    </div>
                    <div style="color: #6b7280; font-weight: 500;">Total Reviews</div>
                </div>
                <div class="stat-card" style="background: white; padding: 2rem; border-radius: 15px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <div style="font-size: 3rem; font-weight: 700; color: #FF7A3D; margin-bottom: 0.5rem;">
                        <?php 
                        $avgRating = 0;
                        if (!empty($allReviews)) {
                            $totalRating = array_sum(array_column($allReviews, 'rating'));
                            $avgRating = round($totalRating / count($allReviews), 1);
                        }
                        echo $avgRating;
                        ?>
                    </div>
                    <div style="color: #6b7280; font-weight: 500;">Average Rating</div>
                </div>
                <div class="stat-card" style="background: white; padding: 2rem; border-radius: 15px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <div style="font-size: 3rem; font-weight: 700; color: #FF7A3D; margin-bottom: 0.5rem;">
                        4.8
                    </div>
                    <div style="color: #6b7280; font-weight: 500;">Guest Satisfaction</div>
                </div>
            </div>

            <!-- Reviews List -->
            <div class="reviews-list" style="display: grid; gap: 2rem;">
                <?php if (empty($allReviews)): ?>
                    <div class="empty-reviews" style="text-align: center; padding: 4rem 2rem; background: white; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                        <i class="fas fa-star" style="font-size: 4rem; color: #e5e7eb; margin-bottom: 1rem;"></i>
                        <h3 style="color: #6b7280; margin-bottom: 1rem;">No Reviews Yet</h3>
                        <p style="color: #9ca3af;">Be the first to share your experience!</p>
                        <?php if ($user->isLoggedIn()): ?>
                            <button type="button" class="btn-primary" onclick="openReviewModal('create')" style="display: inline-block; margin-top: 1rem; background: #FF7A3D; color: white; padding: 0.75rem 1.5rem; border: 0; border-radius: 8px; text-decoration: none; font-weight: 600; cursor: pointer;">Create Feedback</button>
                        <?php else: ?>
                            <a href="google-auth.php?action=login" class="btn-primary" style="display: inline-block; margin-top: 1rem; background: #FF7A3D; color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: 600;">Login to Review</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($allReviews as $review): ?>
                        <div class="review-card" style="background: white; padding: 2rem; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-left: 4px solid #FF7A3D;">
                            <div class="review-header" style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
                                <img src="<?php echo htmlspecialchars($review['user_avatar']); ?>" alt="<?php echo htmlspecialchars($review['user_display_name']); ?>" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb;">
                                <div class="reviewer-info" style="flex: 1;">
                                    <h4 style="margin: 0; color: #1f2937; font-weight: 600;"><?php echo htmlspecialchars($review['user_display_name']); ?></h4>
                                    <div style="display: flex; align-items: center; gap: 1rem; margin-top: 0.25rem;">
                                        <div class="stars" style="color: #FF7A3D;">
                                            <?php echo $review['stars_html']; ?>
                                        </div>
                                        <span style="color: #6b7280; font-size: 0.875rem;"><?php echo $review['created_date']; ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($review['review_type_label']) && $review['review_type_label'] !== 'General Review'): ?>
                                    <span class="review-type-badge" style="background: #f3f4f6; color: #374151; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 500;">
                                        <?php echo htmlspecialchars($review['review_type_label']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="review-content" style="margin-bottom: 1rem;">
                                <?php
                                    $reviewText = trim((string)($review['review_text'] ?? $review['comment'] ?? $review['text'] ?? ''));
                                    if ($reviewText === '') {
                                        $reviewText = '(No comment provided)';
                                    }
                                ?>
                                <p style="color: #374151; line-height: 1.6; margin: 0;"><?php echo htmlspecialchars($reviewText); ?></p>
                            </div>
                            
                            <div class="review-footer" style="display: flex; justify-content: space-between; align-items: center; padding-top: 1rem; border-top: 1px solid #f3f4f6;">
                                <div class="rating-display" style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="color: #6b7280; font-size: 0.875rem;">Rating:</span>
                                    <div style="background: #FF7A3D; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.875rem; font-weight: 600;">
                                        <?php echo $review['rating']; ?>/5
                                    </div>
                                </div>
                                <?php if ($user->isLoggedIn() && isset($_SESSION['user_id']) && $review['user_id'] == $_SESSION['user_id']): ?>
                                    <div class="review-actions" style="display: flex; gap: 0.5rem;">
                                        <button type="button" class="review-edit-button" data-review-id="<?php echo (int)$review['id']; ?>" data-rating="<?php echo (int)$review['rating']; ?>" data-review-text="<?php echo htmlspecialchars($reviewText, ENT_QUOTES, 'UTF-8'); ?>" style="color: #6b7280; text-decoration: none; font-size: 0.875rem; padding: 0.25rem 0.5rem; border: 0; background: transparent; border-radius: 4px; transition: all 0.2s; cursor: pointer;">Edit</button>
                                        <a href="controllers/ReviewController.php?action=delete&id=<?php echo $review['id']; ?>" style="color: #dc2626; text-decoration: none; font-size: 0.875rem; padding: 0.25rem 0.5rem; border-radius: 4px; transition: all 0.2s;" onclick="return confirm('Are you sure you want to delete this review?');">Delete</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Write Review CTA -->
            <div class="write-review-cta" style="text-align: center; margin-top: 3rem; padding: 3rem; background: white; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <h3 style="color: #1f2937; margin-bottom: 1rem;">Share Your Experience</h3>
                <p style="color: #6b7280; margin-bottom: 2rem;">Your feedback helps us improve and helps other guests make informed decisions.</p>
                <?php if ($user->isLoggedIn()): ?>
                    <button type="button" class="btn-primary" onclick="openReviewModal('create')" style="display: inline-block; background: #FF7A3D; color: white; padding: 1rem 2rem; border: 0; border-radius: 8px; text-decoration: none; font-weight: 600; transition: all 0.3s; cursor: pointer;">Create Feedback</button>
                <?php else: ?>
                    <a href="google-auth.php?action=login" class="btn-primary" style="display: inline-block; background: #FF7A3D; color: white; padding: 1rem 2rem; border-radius: 8px; text-decoration: none; font-weight: 600; transition: all 0.3s;">Login to Write Review</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<?php if ($user->isLoggedIn()): ?>
<div id="reviewModal" class="review-modal" role="dialog" aria-modal="true" aria-labelledby="reviewModalTitle" aria-hidden="true">
    <div class="review-modal-content">
        <button type="button" class="review-modal-close" onclick="closeReviewModal()" aria-label="Close">&times;</button>
        <h2 id="reviewModalTitle">Create Feedback</h2>
        <form action="<?php echo SITE_URL; ?>controllers/ReviewController.php?action=create" method="POST" id="reviewForm">
            <input type="hidden" name="return_to" value="reviews">
            <input type="hidden" name="rating" id="reviewRatingInput" value="0">
            <div class="review-modal-rating">
                <p>Select a rating: <span aria-hidden="true">*</span></p>
                <div class="review-modal-stars" role="radiogroup" aria-label="Rating">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="button" class="review-star" data-rating="<?php echo $i; ?>" role="radio" aria-label="<?php echo $i; ?> stars"><i class="far fa-star"></i></button>
                    <?php endfor; ?>
                </div>
            </div>
            <label for="reviewText">Your feedback</label>
            <textarea name="review_text" id="reviewText" maxlength="1000" required placeholder="Tell us about your stay at Villa Soledad"></textarea>
            <p class="review-word-count">Maximum 30 words. <span id="reviewWordCount">0</span>/30 words used.</p>
            <button type="submit" class="review-submit-button">Submit Feedback</button>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.reviews-section {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.review-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    transition: all 0.3s ease;
}

.review-type-badge {
    border: 1px solid #e5e7eb;
}

.review-actions a:hover {
    background: #f3f4f6;
}

.review-actions button:hover {
    background: #f3f4f6 !important;
}

.review-modal {
    position: fixed;
    inset: 0;
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(15, 23, 42, 0.7);
}

.review-modal.open {
    display: flex;
}

.review-modal-content {
    position: relative;
    width: min(760px, 100%);
    max-height: 90vh;
    overflow-y: auto;
    padding: 2.5rem;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 70px rgba(15, 23, 42, 0.25);
}

.review-modal-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    border: 0;
    background: transparent;
    color: #64748b;
    font-size: 1.8rem;
    cursor: pointer;
}

.review-modal-content h2 {
    margin: 0 0 1.5rem;
    color: #123b75;
}

.review-modal-rating p,
.review-modal-content label {
    display: block;
    margin: 0 0 0.6rem;
    color: #64748b;
}

.review-modal-rating p span {
    color: #dc2626;
}

.review-modal-stars {
    display: flex;
    gap: 0.35rem;
    margin-bottom: 1.5rem;
}

.review-star {
    padding: 0;
    border: 0;
    background: transparent;
    color: #cbd5e1;
    font-size: 2rem;
    cursor: pointer;
}

.review-star.selected,
.review-star.preview {
    color: #ff7a3d;
}

.review-modal-content textarea {
    width: 100%;
    min-height: 150px;
    padding: 1rem;
    border: 1px solid #dbe2ea;
    border-radius: 8px;
    color: #1f2937;
    font: inherit;
    resize: vertical;
}

.review-word-count {
    margin: 0.75rem 0 1.5rem;
    color: #2563a6;
}

.review-submit-button {
    border: 0;
    border-radius: 999px;
    padding: 0.85rem 1.75rem;
    background: #ff7a3d;
    color: #fff;
    font-weight: 700;
    cursor: pointer;
}

@media (max-width: 600px) {
    .review-modal-content {
        padding: 2rem 1.25rem 1.5rem;
    }
}

.btn-primary:hover {
    background: #e66a1f !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 122, 61, 0.3);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    transition: all 0.3s ease;
}

@media (max-width: 768px) {
    .review-stats {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .review-header {
        flex-direction: column;
        text-align: center;
        gap: 0.75rem;
    }
    
    .review-footer {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('reviewModal');
    const form = document.getElementById('reviewForm');
    const ratingInput = document.getElementById('reviewRatingInput');
    const textInput = document.getElementById('reviewText');
    const wordCount = document.getElementById('reviewWordCount');
    const stars = document.querySelectorAll('.review-star');

    document.querySelectorAll('.stars i').forEach(star => {
        star.style.fontSize = '1rem';
        star.style.marginRight = '0.25rem';
    });

    function updateStars(rating) {
        stars.forEach(star => {
            const active = Number(star.dataset.rating) <= rating;
            star.classList.toggle('selected', active);
            star.querySelector('i').className = active ? 'fas fa-star' : 'far fa-star';
        });
    }

    function updateWordCount() {
        if (!textInput || !wordCount) return;
        const words = textInput.value.trim() ? textInput.value.trim().split(/\s+/).length : 0;
        wordCount.textContent = words;
        wordCount.style.color = words > 30 ? '#dc2626' : '#2563a6';
    }

    stars.forEach(star => star.addEventListener('click', function() {
        ratingInput.value = this.dataset.rating;
        updateStars(Number(this.dataset.rating));
    }));
    textInput?.addEventListener('input', updateWordCount);

    form?.addEventListener('submit', function(event) {
        const rating = Number(ratingInput.value);
        const words = textInput.value.trim() ? textInput.value.trim().split(/\s+/).length : 0;
        if (rating < 1 || words < 1 || words > 30) {
            event.preventDefault();
            alert(rating < 1 ? 'Please select a rating.' : 'Please keep your feedback within 30 words.');
        }
    });

    modal?.addEventListener('click', function(event) {
        if (event.target === modal) closeReviewModal();
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') closeReviewModal();
    });

    window.openReviewModal = function(mode, reviewId = '', rating = 0, reviewText = '') {
        if (!modal || !form) return;
        const editing = mode === 'edit';
        form.action = editing
            ? '<?php echo SITE_URL; ?>controllers/ReviewController.php?action=update&id=' + encodeURIComponent(reviewId)
            : '<?php echo SITE_URL; ?>controllers/ReviewController.php?action=create';
        // Ensure return_to field is set
        let returnToInput = form.querySelector('input[name="return_to"]');
        if (!returnToInput) {
            returnToInput = document.createElement('input');
            returnToInput.type = 'hidden';
            returnToInput.name = 'return_to';
            returnToInput.value = 'reviews';
            form.appendChild(returnToInput);
        } else {
            returnToInput.value = 'reviews';
        }
        document.getElementById('reviewModalTitle').textContent = editing ? 'Edit Feedback' : 'Create Feedback';
        document.querySelector('.review-submit-button').textContent = editing ? 'Save Feedback' : 'Submit Feedback';
        ratingInput.value = rating;
        textInput.value = reviewText;
        updateStars(Number(rating));
        updateWordCount();
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        textInput.focus();
    };

    window.closeReviewModal = function() {
        if (!modal) return;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    };

    document.querySelectorAll('.review-edit-button').forEach(button => {
        button.addEventListener('click', function() {
            openReviewModal('edit', this.dataset.reviewId, Number(this.dataset.rating), this.dataset.reviewText);
        });
    });

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
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
