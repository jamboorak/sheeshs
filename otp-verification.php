<?php
require_once 'config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user data exists from Google login
if (!isset($_SESSION['google_user_data'])) {
    header('Location: index.php');
    exit;
}

$userEmail = $_SESSION['google_user_data']['email'];
$otpResendCooldown = (int)(defined('OTP_RESEND_COOLDOWN_SECONDS') ? OTP_RESEND_COOLDOWN_SECONDS : 120);
$otpExpirySeconds = (int)(defined('OTP_EXPIRY_SECONDS') ? OTP_EXPIRY_SECONDS : 180);
$otpResendTimerLabel = gmdate('i:s', $otpResendCooldown);

// Automatically send OTP when page loads
require_once 'config/database.php';

// Send OTP silently
$otpSent = false;
try {
    $database = new Database();
    
    // Generate 6-digit OTP
    $otpCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Set expiry time (3 minutes from now)
    $expiresAt = date('Y-m-d H:i:s', time() + $otpExpirySeconds);
    
    // Store user data as JSON
    $userDataJson = json_encode($_SESSION['google_user_data']);
    
    // Delete any existing unused OTP for this email
    $deleteSql = "DELETE FROM otp_codes WHERE email = ? AND is_used = 0";
    $database->delete($deleteSql, [$userEmail]);
    
    // Insert new OTP
    $insertSql = "INSERT INTO otp_codes (email, otp_code, user_data, expires_at, is_used) VALUES (?, ?, ?, ?, 0)";
    $database->insert($insertSql, [$userEmail, $otpCode, $userDataJson, $expiresAt]);
    
    // Send OTP via email using function
    $otpSent = sendOTPEmail($userEmail, $otpCode, $_SESSION['google_user_data']['name'] ?? 'User');
    
} catch (Exception $e) {
    // Log error but don't prevent page load
    error_log("OTP send error: " . $e->getMessage());
}

/**
 * Send OTP email using Gmail SMTP
 */
function sendOTPEmail($toEmail, $otpCode, $userName) {
    try {
        // Use PHPMailer for sending emails
        require_once __DIR__ . '/vendor/autoload.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $userName);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your OTP Verification Code - ' . SITE_NAME;
        
        $emailBody = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>OTP Verification</title>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #234663; background: #eaf2f9;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background: #234663; padding: 30px; border-radius: 10px 10px 0 0; text-align: center;'>
                    <p style='color: #b9d9ed; margin: 0 0 8px; font-size: 13px; letter-spacing: 1px;'>VILLA SOLEDAD GARDEN RESORT</p>
                    <h1 style='color: white; margin: 0; font-size: 28px;'>OTP Verification</h1>
                </div>
                <div style='background: #ffffff; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #dbe7f0; border-top: none;'>
                    <p style='font-size: 16px; margin-bottom: 20px;'>Dear <strong>{$userName}</strong>,</p>
                    <p style='font-size: 16px; margin-bottom: 20px;'>Thank you for choosing <strong>" . SITE_NAME . "</strong>. Your One-Time Password (OTP) for account verification is:</p>
                    <div style='background: #eaf2f9; border: 2px solid #2d8bd0; border-radius: 10px; padding: 20px; text-align: center; margin: 20px 0;'>
                        <span style='font-size: 36px; font-weight: bold; color: #234663; letter-spacing: 5px;'>{$otpCode}</span>
                    </div>
                    <p style='font-size: 14px; color: #60788c; margin-bottom: 10px;'>This OTP will expire in <strong style='color: #234663;'>3 minutes</strong>.</p>
                    <p style='font-size: 14px; color: #60788c; margin-bottom: 20px;'>If you did not request this verification, please ignore this email.</p>
                    <hr style='border: none; border-top: 1px solid #dbe7f0; margin: 20px 0;'>
                    <p style='font-size: 12px; color: #71879a; text-align: center;'>This is an automated email. Please do not reply.</p>
                    <p style='font-size: 12px; color: #71879a; text-align: center; margin-top: 10px;'>&copy; " . date('Y') . " " . SITE_NAME . ". All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->Body = $emailBody;
        $mail->AltBody = "Your OTP verification code is: {$otpCode}\n\nThis code will expire in 3 minutes.\n\nIf you did not request this verification, please ignore this email.";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Verification - <?php echo SITE_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            background: linear-gradient(rgba(15, 23, 42, 0.8), rgba(15, 23, 42, 0.9)), url('images/villasoledadbg.png') center/cover no-repeat;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .container {
            width: min(100%, 520px);
            height: auto;
            display: flex;
            border-radius: 20px;
            overflow: hidden;
            background: white;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .left {
            width: 50%;
            position: relative;
            background: url('images/villasoledadbg.png') no-repeat center/cover;
            color: white;
            padding: 20px;
        }

        .overlay {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
        }

        .left-content {
            position: relative;
            z-index: 1;
            text-align: center;
            top: 50%;
            transform: translateY(-50%);
        }

        .left h1 {
            font-size: 26px;
            margin-top: 20px;
        }

        .left h2 {
            font-size: 36px;
            margin: 10px 0;
            font-weight: bold;
        }

        .left p {
            font-size: 14px;
        }

        .right {
            width: 100%;
            padding: 40px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            border-left: 2px solid rgba(249, 115, 22, 0.2);
        }

        .right h2 {
            text-align: center;
            margin-bottom: 30px;
            color: #1e3a8a;
            font-size: 28px;
        }

        .email-text {
            text-align: center;
            color: #475569;
            font-size: 14px;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .email-text strong {
            color: #1e3a8a;
            font-weight: bold;
        }

        .otp-inputs {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 25px;
        }

        .otp-input {
            width: 50px;
            height: 55px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 24px;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s ease;
            outline: none;
        }

        .otp-input:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }

        .otp-input.has-error {
            border-color: #ef4444;
            animation: shake 0.5s;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-5px); }
            40% { transform: translateX(5px); }
            60% { transform: translateX(-5px); }
            80% { transform: translateX(5px); }
        }

        .verify-btn {
            margin-top: 20px;
            padding: 12px;
            border: none;
            border-radius: 25px;
            background: #f97316;
            color: white;
            font-weight: bold;
            cursor: pointer;
            font-size: 15px;
            transition: all 0.3s ease;
            width: 100%;
        }

        .verify-btn:hover {
            background: #ea580c;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(249, 115, 22, 0.4);
        }

        .verify-btn:active {
            transform: translateY(0);
            background: #c2410c;
        }

        .verify-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            background: #f97316;
        }

        .resend-text {
            text-align: center;
            color: #777;
            font-size: 12px;
            margin-top: 15px;
        }

        .resend-link {
            color: #f97316;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
        }

        .resend-link:hover {
            text-decoration: underline;
        }

        .resend-link.disabled {
            color: #ccc;
            cursor: not-allowed;
            pointer-events: none;
        }

        .resend-loading {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            color: #f97316;
            font-weight: 600;
            cursor: wait;
            pointer-events: none;
            text-decoration: none;
        }

        .resend-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(249, 115, 22, 0.2);
            border-radius: 50%;
            border-top-color: #f97316;
            animation: spin 0.8s linear infinite;
            flex-shrink: 0;
        }

        .error-message {
            color: #ef4444;
            font-size: 12px;
            margin-top: 15px;
            text-align: center;
            display: none;
        }

        .success-message {
            color: #10b981;
            font-size: 12px;
            margin-top: 15px;
            text-align: center;
            display: none;
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-right: 10px;
            vertical-align: middle;
        }

        .loading-resend {
            display: none;
        }

        .loading-orange {
            border: 3px solid rgba(249, 115, 22, 0.2);
            border-top-color: #f97316;
        }

        .loading-resend {
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .container {
                width: 95%;
                height: auto;
                flex-direction: column;
                margin: 20px;
            }

            .left {
                width: 100%;
                height: 200px;
            }

            .right {
                width: 100%;
                padding: 30px 20px;
            }

            .otp-input {
                width: 40px;
                height: 45px;
                font-size: 20px;
            }

            h2 {
                font-size: 24px;
            }
        }

        @media (max-width: 480px) {
            .container {
                width: 100%;
                height: 100vh;
                margin: 0;
                border-radius: 0;
            }

            .left {
                height: 150px;
            }

            .left h1 {
                font-size: 20px;
            }

            .left h2 {
                font-size: 28px;
            }

            .left p {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="right">
            <h2>Verify OTP</h2>
            <p class="email-text">
                We've sent a 6-digit OTP to<br>
                <strong><?php echo htmlspecialchars($userEmail); ?></strong>
            </p>

            <div class="otp-inputs">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
            </div>

            <button class="verify-btn" id="verifyBtn">
                <span id="btnText">Verify OTP</span>
            </button>

            <p class="resend-text">
                Didn't receive the code? 
                <span class="resend-link disabled" id="resendLink">Resend OTP in <span id="timer"><?php echo htmlspecialchars($otpResendTimerLabel); ?></span></span>
            </p>

            <div class="error-message" id="errorMessage"></div>
            <div class="success-message" id="successMessage"></div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const otpInputs = document.querySelectorAll('.otp-input');
            const verifyBtn = document.getElementById('verifyBtn');
            const btnText = document.getElementById('btnText');
            const resendLink = document.getElementById('resendLink');
            let timerDisplay = document.getElementById('timer');
            const errorMessage = document.getElementById('errorMessage');
            const successMessage = document.getElementById('successMessage');

            let timeLeft = <?php echo (int)$otpResendCooldown; ?>;
            let timerInterval;

            // Auto-focus first input
            otpInputs[0].focus();

            // Handle OTP input navigation
            otpInputs.forEach((input, index) => {
                input.addEventListener('input', function(e) {
                    // Only allow numbers
                    this.value = this.value.replace(/[^0-9]/g, '');

                    if (this.value.length === 1 && index < otpInputs.length - 1) {
                        otpInputs[index + 1].focus();
                    }
                });

                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && this.value.length === 0 && index > 0) {
                        otpInputs[index - 1].focus();
                    }
                });

                input.addEventListener('paste', function(e) {
                    e.preventDefault();
                    const pastedData = e.clipboardData.getData('text').replace(/[^0-9]/g, '');
                    
                    if (pastedData.length === 6) {
                        pastedData.split('').forEach((char, i) => {
                            if (otpInputs[i]) {
                                otpInputs[i].value = char;
                            }
                        });
                        otpInputs[5].focus();
                    }
                });
            });

            // Timer countdown
            function startTimer() {
                if (timerInterval) {
                    clearInterval(timerInterval);
                }

                timerInterval = setInterval(() => {
                    timeLeft--;
                    
                    const minutes = Math.floor(timeLeft / 60);
                    const seconds = timeLeft % 60;
                    
                    timerDisplay.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                    
                            if (timeLeft <= 0) {
                        clearInterval(timerInterval);
                        if (!resendLink.classList.contains('resend-loading')) {
                            resendLink.className = 'resend-link';
                            resendLink.textContent = 'Resend OTP';
                        }
                    }
                }, 1000);
            }

            startTimer();

            function setResendLoading(isLoading) {
                if (isLoading) {
                    resendLink.classList.remove('disabled');
                    resendLink.className = 'resend-loading';
                    resendLink.innerHTML = '<span class="resend-spinner" aria-hidden="true"></span><span>Resending...</span>';
                    return;
                }

                resendLink.className = 'resend-link disabled';
                resendLink.textContent = 'Resend OTP in ';
                const newTimer = document.createElement('span');
                newTimer.id = 'timer';
                newTimer.textContent = '<?php echo htmlspecialchars($otpResendTimerLabel); ?>';
                resendLink.appendChild(newTimer);
                timerDisplay = newTimer;
            }

            // Resend OTP
            resendLink.addEventListener('click', function() {
                if (this.classList.contains('disabled') || this.classList.contains('resend-loading')) return;

                setResendLoading(true);
                errorMessage.style.display = 'none';
                successMessage.style.display = 'none';

                fetch('api/send_otp.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'resend'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reset timer
                        timeLeft = <?php echo (int)$otpResendCooldown; ?>;
                        setResendLoading(false);
                        startTimer();
                        
                        // Clear inputs
                        otpInputs.forEach(input => input.value = '');
                        otpInputs[0].focus();
                        
                        showSuccess('New OTP sent successfully!');
                    } else {
                        resendLink.className = 'resend-link';
                        resendLink.textContent = 'Resend OTP';
                        showError(data.message || 'Failed to resend OTP');
                    }
                })
                .catch(error => {
                    resendLink.className = 'resend-link';
                    resendLink.textContent = 'Resend OTP';
                    showError('An error occurred. Please try again.');
                });
            });

            // Verify OTP
            verifyBtn.addEventListener('click', function() {
                const otp = Array.from(otpInputs).map(input => input.value).join('');
                
                if (otp.length !== 6) {
                    showError('Please enter all 6 digits');
                    otpInputs.forEach(input => input.classList.add('has-error'));
                    setTimeout(() => {
                        otpInputs.forEach(input => input.classList.remove('has-error'));
                    }, 500);
                    return;
                }

                // Show loading state
                verifyBtn.disabled = true;
                btnText.innerHTML = '<span class="loading"></span>Verifying...';

                fetch('api/verify_otp.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        otp: otp
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess('OTP verified successfully!');
                        setTimeout(() => {
                            window.location.href = data.redirect_url || 'index.php';
                        }, 1000);
                    } else {
                        showError(data.message || 'Invalid OTP');
                        otpInputs.forEach(input => {
                            input.classList.add('has-error');
                            input.value = '';
                        });
                        otpInputs[0].focus();
                        setTimeout(() => {
                            otpInputs.forEach(input => input.classList.remove('has-error'));
                        }, 500);
                    }
                })
                .catch(error => {
                    showError('An error occurred. Please try again.');
                })
                .finally(() => {
                    verifyBtn.disabled = false;
                    btnText.textContent = 'Verify OTP';
                });
            });

            function showError(message) {
                errorMessage.textContent = message;
                errorMessage.style.display = 'block';
                successMessage.style.display = 'none';
                
                setTimeout(() => {
                    errorMessage.style.display = 'none';
                }, 5000);
            }

            function showSuccess(message) {
                successMessage.textContent = message;
                successMessage.style.display = 'block';
                errorMessage.style.display = 'none';
                
                setTimeout(() => {
                    successMessage.style.display = 'none';
                }, 5000);
            }
        });
    </script>
</body>
</html>
