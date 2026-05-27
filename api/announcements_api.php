<?php
session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/workflow-tracking.php"; // to potentially use notification function if we abstract it

header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_announcements':
        $sql = "SELECT a.*, u.full_name as author_name 
                FROM announcements a 
                JOIN users u ON a.posted_by = u.user_id 
                ORDER BY a.created_at DESC LIMIT 10";
        $result = $conn->query($sql);
        
        $announcements = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $announcements[] = $row;
            }
        }
        echo json_encode(["success" => true, "announcements" => $announcements]);
        break;

    case 'post_announcement':
        if ($role !== 'QMO Admin') {
            echo json_encode(["success" => false, "message" => "Unauthorized: Only QMO Admin can post announcements"]);
            exit;
        }

        $title = $_POST['title'] ?? '';
        $content = $_POST['content'] ?? '';

        if (empty($title) || empty($content)) {
            echo json_encode(["success" => false, "message" => "Title and content cannot be empty"]);
            exit;
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO announcements (title, content, posted_by) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $title, $content, $user_id);
            $stmt->execute();
            $stmt->close();

            // Insert system-wide notification for all active units
            $sql_users = "SELECT user_id FROM users WHERE role IN ('Dean', 'Office', 'RMO', 'HMO') AND status = 'Active'";
            $res_users = $conn->query($sql_users);

            if ($res_users->num_rows > 0) {
                // Create a single notification record
                $notif_title = "New Announcement: " . $title;
                $notif_msg = substr($content, 0, 100) . (strlen($content) > 100 ? '...' : '');
                $type = 'System';
                
                $sql_notif = "INSERT INTO notifications (title, message, type) VALUES (?, ?, ?)";
                $stmt_notif = $conn->prepare($sql_notif);
                $stmt_notif->bind_param("sss", $notif_title, $notif_msg, $type);
                
                if ($stmt_notif->execute()) {
                    $notification_id = $conn->insert_id;
                    $stmt_notif->close();
                    
                    // Link to users
                    $sql_user_notif = "INSERT INTO user_notifications (user_id, notification_id) VALUES (?, ?)";
                    $stmt_un = $conn->prepare($sql_user_notif);
                    
                    while($u = $res_users->fetch_assoc()) {
                        $uid = $u['user_id'];
                        $stmt_un->bind_param("ii", $uid, $notification_id);
                        $stmt_un->execute();
                    }
                    $stmt_un->close();
                }
            }
            
            $conn->commit();
            echo json_encode(["success" => true, "message" => "Announcement posted successfully!"]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["success" => false, "message" => "Error posting announcement: " . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(["success" => false, "message" => "Invalid action"]);
        break;
}
?>
