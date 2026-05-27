<?php
// Redirect to the API wrapper for Vercel deployment
header('Location: /api/index.php');
exit();
?>

$message = "";

if (isset($_POST['submit'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE email=? AND status='Active'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['office_id'] = $user['office_id'] ?? null;

            if($user['role'] === 'QMO Admin'){
                header("Location: dashboard/qmo/index.php");
            } elseif($user['role'] === 'Office' || $user['role'] === 'Dean'){
                header("Location: dashboard/dean/index.php");
            } else {
                header("Location: dashboard/qmo/index.php");
            }
            exit();
        } else {
            $message = "Invalid password!";
        }
    } else {
        $message = "Email not found or inactive!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UA QMS | Login</title>
    <link rel="stylesheet" href="assets/css/login.css?v=1.2.1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="login-page">
    <main class="login-shell">
        <section class="brand-panel" aria-label="UA QMS information">
            <div class="seal-row">
                <img src="assets/img/ua-qms-logo.jpg" alt="University of Antique seal">
                <div class="institution-copy">
                    <p class="eyebrow">UNIVERSITY OF ANTIQUE</p>
                    <p class="campus-line">TARIO-LIM MEMORIAL CAMPUS</p>
                    <p class="subeyebrow">QUALITY MANAGEMENT OFFICE</p>
                </div>
            </div>

            <div class="brand-copy">
                <h1>Q<span class="text-maroon">U</span><span class="text-gold">A</span>LITY MANAGEMENT<br>SYSTEM <span class="acronym">(UA QMS)</span></h1>
                <p class="description">
                    A unified compliance monitoring and document management platform for the University of Antique
                    Tario-Lim Memorial Campus Quality Management Office.
                </p>
            </div>
        </section>

        <section class="login-card" aria-label="Login form">
            <h2>LOGIN</h2>
            <p class="login-note">Use your assigned account to access the UA QMS Information System.</p>

            <div class="divider"></div>

            <?php if($message) { echo "<p class='error'>".htmlspecialchars($message)."</p>"; } ?>

            <form method="POST">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <span class="input-icon"><i class="fas fa-envelope"></i></span>
                    <input id="email" type="email" name="email" placeholder="user@antiquespride.edu.ph" required>
                </div>

                <label for="password">Password</label>
                <div class="input-wrap password-wrap">
                    <span class="input-icon"><i class="fas fa-lock"></i></span>
                    <input id="password" type="password" name="password" placeholder="Password" required>
                    <button type="button" id="toggle-password" class="toggle-password-btn" aria-label="Toggle password visibility">
                        <i class="fas fa-eye" id="toggle-icon"></i>
                    </button>
                </div>

                <div class="form-row">
                    <label class="remember">
                        <input type="checkbox" name="remember">
                        <span>Remember Me</span>
                    </label>
                    <a href="auth/forgot-password.php">Forgot Password?</a>
                </div>

                <button type="submit" name="submit">Log In</button>
            </form>
        </section>
    </main>

    <footer class="login-footer">
        <span>(C) 2026 UNIVERSITY OF ANTIQUE</span>
        <span>UA QMS - QUALITY MANAGEMENT SYSTEM</span>
    </footer>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    const toggleButton = document.getElementById('toggle-password');
    const toggleIcon = document.getElementById('toggle-icon');

    if (toggleButton && passwordInput && toggleIcon) {
        toggleButton.addEventListener('click', function() {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            
            // Toggle icon classes
            if (isPassword) {
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        });
    }
    
    // Auto-dismiss alerts after 3 seconds
    const errorEl = document.querySelector('.error');
    if (errorEl) {
        setTimeout(() => {
            errorEl.style.transition = 'opacity 0.5s ease-out';
            errorEl.style.opacity = '0';
            setTimeout(() => errorEl.remove(), 500);
        }, 3000);
    }
});
</script>
</body>
</html>
