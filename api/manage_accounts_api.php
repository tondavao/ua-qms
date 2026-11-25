<?php
// manage_accounts_api.php

header('Content-Type: application/json');

// Allow only POST requests for write operations
$method = $_SERVER['REQUEST_METHOD'];

// Include shared files
require_once '../config/db.php';  // Database connection
require_once '../config/auth.php';        // Authentication functions

// Check if the user is authenticated (adjust function as needed)
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Read the input JSON
$input = json_decode(file_get_contents('php://input'), true);

// Determine the action
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'create':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        createUser($input);
        break;

    case 'read':
        getUsers();
        break;

    case 'update':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        updateUser($input);
        break;

    case 'delete':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        deleteUser($input);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        exit;
}

// -------- Functions --------

function createUser($data) {
    global $conn;

    if (empty($data['username']) || empty($data['password'])) {
        echo json_encode(['error' => 'Username and password required']);
        return;
    }

    $username = $data['username'];
    $password = password_hash($data['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (full_name, password, email, role) VALUES (?, ?, ?, 'Office')");
    $stmt->bind_param('sss', $username, $password, $username);

    if ($stmt->execute()) {
        echo json_encode(['success' => 'User created successfully']);
    } else {
        echo json_encode(['error' => 'Failed to create user']);
    }

    $stmt->close();
}

function getUsers() {
    global $conn;

    $result = $conn->query("SELECT user_id as id, full_name as username, created_at FROM users");

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    echo json_encode($users);
}

function updateUser($data) {
    global $conn;

    if (empty($data['id'])) {
        echo json_encode(['error' => 'User ID required']);
        return;
    }

    $id = $data['id'];
    $username = isset($data['username']) ? $data['username'] : null;
    $password = isset($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null;

    $fields = [];
    $params = [];
    $types = '';

    if ($username) {
        $fields[] = 'full_name = ?';
        $params[] = $username;
        $types .= 's';
    }
    if ($password) {
        $fields[] = 'password = ?';
        $params[] = $password;
        $types .= 's';
    }

    if (empty($fields)) {
        echo json_encode(['error' => 'Nothing to update']);
        return;
    }

    $params[] = $id;
    $types .= 'i';

    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        echo json_encode(['success' => 'User updated successfully']);
    } else {
        echo json_encode(['error' => 'Failed to update user']);
    }

    $stmt->close();
}

function deleteUser($data) {
    global $conn;

    if (empty($data['id'])) {
        echo json_encode(['error' => 'User ID required']);
        return;
    }

    $id = $data['id'];
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo json_encode(['success' => 'User deleted successfully']);
    } else {
        echo json_encode(['error' => 'Failed to delete user']);
    }

    $stmt->close();
}
