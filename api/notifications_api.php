<?php
session_start();
require_once __DIR__ . "/../config/db.php";

header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_notifications':
        $sql = "SELECT un.*, n.title, n.message, n.type, n.created_at as notification_time, n.target_url, n.target_type, n.related_document_id
                FROM user_notifications un
                JOIN notifications n ON un.notification_id = n.notification_id
                WHERE un.user_id = ?
                ORDER BY n.created_at DESC
                LIMIT 100";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            // Generate default routing URL if not set
            if (empty($row['target_url'])) {
                $row['target_url'] = generateDefaultRoute($user_id, $row, $conn);
            }
            $notifications[] = $row;
        }
        echo json_encode(["success" => true, "notifications" => $notifications]);
        break;

    case 'get_unread_count':
        $sql = "SELECT COUNT(*) as unread_count FROM user_notifications WHERE user_id = ? AND is_read = FALSE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        echo json_encode(["success" => true, "unread_count" => (int)$result['unread_count']]);
        break;

    case 'mark_as_read':
        $user_notification_id = $_POST['user_notification_id'] ?? null;
        if (!$user_notification_id) {
            echo json_encode(["success" => false, "message" => "Missing notification ID"]);
            exit;
        }

        $sql = "UPDATE user_notifications SET is_read = TRUE WHERE user_notification_id = ? AND user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $user_notification_id, $user_id);
        $stmt->execute();
        echo json_encode(["success" => true]);
        break;

    case 'mark_all_as_read':
        $sql = "UPDATE user_notifications SET is_read = TRUE WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        echo json_encode(["success" => true]);
        break;

    default:
        echo json_encode(["success" => false, "message" => "Invalid action"]);
        break;
}

/**
 * Helper function to generate default routing URL based on user role and notification type
 */
function generateDefaultRoute($user_id, $notification_row, $conn) {
    // Get user role
    $sql_user = "SELECT role FROM users WHERE user_id = ?";
    $stmt_user = $conn->prepare($sql_user);
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user_result = $stmt_user->get_result()->fetch_assoc();
    $user_role = $user_result['role'] ?? 'Unknown';

    // Route based on role and notification type
    if ($user_role === 'QMO Admin') {
        // QMO Admin notifications route to monitoring or review submissions
        if ($notification_row['related_document_id']) {
            $doc_id = (int)$notification_row['related_document_id'];
            $status_check = $conn->query("SELECT status FROM documents WHERE document_id = $doc_id");
            if ($status_check && $status_check->num_rows > 0) {
                $status_row = $status_check->fetch_assoc();
                if ($status_row['status'] === 'Awaiting Review' || $status_row['status'] === 'Pending') {
                    return "dashboard/qmo/review_submissions.php?id=" . $doc_id;
                }
            }
            return "dashboard/qmo/review_submissions.php"; // Generic review queue
        }
        return "dashboard/qmo/monitoring.php";
    } elseif ($user_role === 'Dean' || $user_role === 'Office') {
        // Dean/Office notifications route to my submissions
        return "dashboard/dean/my_submissions.php";
    }
    
    // Default fallback
    return "dashboard/qmo/index.php";
}
?>
