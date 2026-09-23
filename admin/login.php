<?php
require_once '../config/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in as admin
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Villa Soledad Resort</title>
    <link rel="stylesheet" href="../css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(rgba(9, 35, 58, 0.72), rgba(9, 35, 58, 0.72)), url('../images/villasoledadbg.png') center / cover fixed;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem 1rem;
        }

        .admin-login-wrapper {
            width: 100%;
            max-width: 420px;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 24px 70px rgba(9, 35, 58, 0.24);
            backdrop-filter: blur(8px);
        }

        .admin-login-header {
            background: linear-gradient(135deg, rgba(15, 76, 129, 0.96) 0%, rgba(32, 117, 175, 0.96) 100%);
            color: white;
            padding: 2rem 2rem 1.5rem;
            text-align: center;
        }

        .admin-login-header h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .admin-login-header p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .admin-login-body {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #374151;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group input {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 1px solid #d4e0ea;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.25s ease;
            background: #f8fbff;
            color: #173b5d;
        }

        .form-group input:focus {
            outline: none;
            border-color: rgba(32, 117, 175, 0.8);
            background: white;
            box-shadow: 0 0 0 3px rgba(32, 117, 175, 0.12);
        }

        .password-input-wrapper {
            position: relative;
        }

        .password-input-wrapper input {
            padding-right: 3rem;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 0.75rem;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #6b7280;
            cursor: pointer;
            font-size: 1rem;
            padding: 0.35rem;
        }

        .password-toggle:hover,
        .password-toggle:focus {
            color: var(--accent-orange);
        }

        .form-group input::placeholder {
            color: #9ca3af;
        }

        .login-btn {
            width: 100%;
            padding: 0.9rem 1.5rem;
            background: linear-gradient(135deg, #ff7a3d 0%, #ff9a4d 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
            box-shadow: 0 10px 22px rgba(255, 122, 61, 0.25);
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(255, 122, 61, 0.32);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .back-link {
            text-align: center;
            margin-top: 1rem;
        }

        .back-link a {
            color: #4a6279;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .back-link a:hover {
            color: #ff7a3d;
        }

        .error-message {
            background: #fee2e2;
            color: #dc2626;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            border: 1px solid #fecaca;
        }

        .admin-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <div class="admin-login-wrapper">
        <div class="admin-login-header">
            <i class="fas fa-shield-alt admin-icon"></i>
            <h1>Admin Access</h1>
            <p>Secure login for administrators only</p>
        </div>

        <div class="admin-login-body">
            <?php if (isset($_SESSION['admin_error'])): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($_SESSION['admin_error']); unset($_SESSION['admin_error']); ?>
                </div>
            <?php endif; ?>

            <form action="process_admin_login.php" method="POST">
                <div class="form-group">
                    <label for="login_identifier">Email or Username</label>
                    <input type="text" id="login_identifier" name="login_identifier" placeholder="Enter email or username" autocomplete="username" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password', this)" aria-label="Show password" title="Show password">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="login-btn">
                    <i class="fas fa-sign-in-alt"></i> Login to Dashboard
                </button>
            </form>

            <div class="back-link">
                <a href="../index.php">
                    <i class="fas fa-arrow-left"></i> Back to Website
                </a>
            </div>
        </div>
    </div>

    <script>
        function togglePasswordVisibility(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            const isPassword = input.type === 'password';

            input.type = isPassword ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !isPassword);
            icon.classList.toggle('fa-eye-slash', isPassword);
            button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            button.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
        }
    </script>
</body>
</html>