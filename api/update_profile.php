<?php
session_start();
include "../config/db.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized access."]);
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle GET request to fetch profile info
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->prepare("SELECT full_name, email, profile_photo FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        echo json_encode(["success" => true, "data" => $user]);
    } else {
        echo json_encode(["success" => false, "message" => "User not found."]);
    }
    exit;
}

// Handle POST request to update profile/password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $full_name = trim($input['full_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $current_password = $input['current_password'] ?? '';
    $new_password = $input['new_password'] ?? '';
    $confirm_password = $input['confirm_password'] ?? '';

    if (empty($full_name) || empty($email) || empty($current_password)) {
        echo json_encode(["success" => false, "message" => "Full name, email, and current password are required."]);
        exit;
    }

    // Verify current password first
    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows !== 1) {
        echo json_encode(["success" => false, "message" => "User not found."]);
        exit;
    }

    $user = $result->fetch_assoc();
    if (!password_verify($current_password, $user['password'])) {
        echo json_encode(["success" => false, "message" => "Incorrect current password."]);
        exit;
    }

    // Email duplication check (excluding current user)
    $email_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
    $email_stmt->bind_param("si", $email, $user_id);
    $email_stmt->execute();
    $email_result = $email_stmt->get_result();
    if ($email_result->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "Email address is already in use by another account."]);
        exit;
    }

    // If new password is provided, validate and hash it
    $update_password = false;
    $hashed_password = '';
    if (!empty($new_password)) {
        if ($new_password !== $confirm_password) {
            echo json_encode(["success" => false, "message" => "New passwords do not match."]);
            exit;
        }
        if (strlen($new_password) < 6) {
            echo json_encode(["success" => false, "message" => "New password must be at least 6 characters long."]);
            exit;
        }
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update_password = true;
    }

    // Handle profile photo upload if provided
    $profile_photo_url = null;
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['profile_photo']['tmp_name'];
        $file_name = $_FILES['profile_photo']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($file_ext, $allowed_exts, true)) {
            $dest_dir = '../assets/img/profiles';
            if (!is_dir($dest_dir)) {
                mkdir($dest_dir, 0777, true);
            }
            $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_ext;
            $dest_path = $dest_dir . '/' . $new_filename;
            if (move_uploaded_file($file_tmp, $dest_path)) {
                $profile_photo_url = '../../assets/img/profiles/' . $new_filename;
                $_SESSION['profile_photo'] = $profile_photo_url;
            }
        }
    }

    // Perform update
    if ($profile_photo_url !== null) {
        if ($update_password) {
            $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, password = ?, profile_photo = ? WHERE user_id = ?");
            $update_stmt->bind_param("ssssi", $full_name, $email, $hashed_password, $profile_photo_url, $user_id);
        } else {
            $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, profile_photo = ? WHERE user_id = ?");
            $update_stmt->bind_param("sssi", $full_name, $email, $profile_photo_url, $user_id);
        }
    } else {
        if ($update_password) {
            $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, password = ? WHERE user_id = ?");
            $update_stmt->bind_param("sssi", $full_name, $email, $hashed_password, $user_id);
        } else {
            $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?");
            $update_stmt->bind_param("ssi", $full_name, $email, $user_id);
        }
    }

    if ($update_stmt->execute()) {
        // Update session info
        $_SESSION['full_name'] = $full_name;
        $res_data = ["success" => true, "message" => "Profile updated successfully."];
        if ($profile_photo_url !== null) {
            $res_data["profile_photo"] = $profile_photo_url;
        }
        echo json_encode($res_data);
    } else {
        echo json_encode(["success" => false, "message" => "Database update failed."]);
    }
    exit;
}
?>
