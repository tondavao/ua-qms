<?php
session_start();
include "config/db.php";

$message = "";
$show_form = false;

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE password_reset_token=? AND token_expiry > NOW() AND status='Active'");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $show_form = true;
        $user = $result->fetch_assoc();

        if (isset($_POST['submit'])) {
            $password = $_POST['password'];
            $confirm_password = $_POST['confirm_password'];

            if ($password !== $confirm_password) {
                $message = "Passwords do not match!";
            } elseif (strlen($password) < 6) {
                $message = "Password must be at least 6 characters!";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET password=?, password_reset_token=NULL, token_expiry=NULL WHERE user_id=?");
                $update->bind_param("si", $hashed_password, $user['user_id']);
                $update->execute();

                $message = "Password updated successfully! <a href='index.php'>Login</a>";
                $show_form = false;
            }
        }
    } else {
        $message = "Invalid or expired token!";
    }
} else {
    $message = "No token provided!";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password | UA QMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
    body {
        margin: 0;
        font-family: 'Inter', sans-serif;
        background-color: #f5f5f5;
     }

    .container {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
    }

    .card {
        background: #fff;
        padding: 40px;
        border-radius: 12px;
        width: 400px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
    }

    .card h2 {
        text-align: center;
        margin-bottom: 10px;
    }

    .card p.subtitle {
        text-align: center;
        margin-bottom: 30px;
        color: #555;
        font-size: 14px;
    }

    .card form input {
        width: 100%;
        padding: 12px 15px;
        margin: 10px 0;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        box-sizing: border-box;
    }

    .card form button {
        width: 100%;
        padding: 12px;
        margin-top: 15px;
        background-color: #8B0000;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        cursor: pointer;
        font-weight: 500;
    }

    .card form button:hover {
        background-color: #a00000;
    }

    .back-link {
        display: block;
        margin-bottom: 20px;
        text-decoration: none;
        color: #555;
        font-size: 14px;
    }

    .message {
        text-align: center;
        color: #dc2626;
        font-size: 14px;
        margin-bottom: 15px;
        line-height: 1.5;
    }
    
    .message.success {
        color: green;
    }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <a href="index.php" class="back-link">&larr; Back to Login</a>
        <h2>Reset Password</h2>
        <p class="subtitle">Enter your new password below</p>

        <?php if($message): ?>
            <div class="message <?php echo (strpos($message, 'successfully') !== false) ? 'success' : ''; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if($show_form): ?>
        <form method="POST">
            <input type="password" name="password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <button type="submit" name="submit">Reset Password</button>
        </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
