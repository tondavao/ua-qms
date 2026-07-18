<?php
session_start();
// Incoming Request Logging
$incomingLog = date('Y-m-d H:i:s') . " - Incoming: METHOD: " . $_SERVER['REQUEST_METHOD'] . " | GET: " . json_encode($_GET) . " | POST: " . json_encode($_POST) . " | SESSION: " . json_encode($_SESSION ?? []) . "\n";
file_put_contents(__DIR__ . '/../debug_announcements.log', $incomingLog, FILE_APPEND);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/workflow-tracking.php";

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
                LEFT JOIN users u ON a.posted_by = u.user_id 
                ORDER BY a.created_at DESC";
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

        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $image_path = null;

        if (empty($title) || empty($content)) {
            echo json_encode(["success" => false, "message" => "Title and content cannot be empty"]);
            exit;
        }

        // Image upload handling
        if (isset($_FILES['announcement_image']) && $_FILES['announcement_image']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['announcement_image']['tmp_name'];
            $fileName = $_FILES['announcement_image']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($fileExtension, $allowedExtensions)) {
                $uploadDir = __DIR__ . '/../uploads/announcements/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
                $dest_path = $uploadDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $dest_path)) {
                    $image_path = 'uploads/announcements/' . $newFileName;
                }
            }
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO announcements (title, content, image_path, posted_by) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Prepare statement failed: " . $conn->error);
            }
            $stmt->bind_param("sssi", $title, $content, $image_path, $user_id);
            if (!$stmt->execute()) {
                throw new Exception("Execute insert failed: " . $stmt->error);
            }
            $stmt->close();

            // Insert system-wide notification for all active units (fault-tolerant)
            try {
                $sql_users = "SELECT user_id FROM users WHERE role IN ('Dean', 'Office', 'RMO', 'HMO') AND status = 'Active'";
                $res_users = $conn->query($sql_users);

                if ($res_users && $res_users->num_rows > 0) {
                    // Create a single notification record
                    $notif_title = "New Announcement: " . $title;
                    $notif_msg = substr($content, 0, 100) . (strlen($content) > 100 ? '...' : '');
                    $type = 'Announcement';
                    
                    $sql_notif = "INSERT INTO notifications (title, message, type) VALUES (?, ?, ?)";
                    $stmt_notif = $conn->prepare($sql_notif);
                    if ($stmt_notif) {
                        $stmt_notif->bind_param("sss", $notif_title, $notif_msg, $type);
                        if ($stmt_notif->execute()) {
                            $notification_id = $conn->insert_id;
                            $stmt_notif->close();
                            
                            // Link to users
                            $sql_user_notif = "INSERT INTO user_notifications (user_id, notification_id) VALUES (?, ?)";
                            $stmt_un = $conn->prepare($sql_user_notif);
                            if ($stmt_un) {
                                while($u = $res_users->fetch_assoc()) {
                                    $uid = $u['user_id'];
                                    $stmt_un->bind_param("ii", $uid, $notification_id);
                                    if (!$stmt_un->execute()) {
                                        throw new Exception("Link user notification failed: " . $stmt_un->error);
                                    }
                                }
                                $stmt_un->close();
                            }
                        } else {
                            $stmt_notif->close();
                        }
                    }
                }
            } catch (Exception $e_notif) {
                // Log notification failure but do not roll back the main announcement transaction
                error_log("Failed to create system notifications for announcement: " . $e_notif->getMessage());
            }
            
            if (!$conn->commit()) {
                throw new Exception("Commit transaction failed: " . $conn->error);
            }
            $logMsg = date('Y-m-d H:i:s') . " - Successfully posted announcement: Title: $title, Author: $user_id\n";
            file_put_contents(__DIR__ . '/../debug_announcements.log', $logMsg, FILE_APPEND);
            echo json_encode(["success" => true, "message" => "Announcement posted successfully!"]);
        } catch (Exception $e) {
            $conn->rollback();
            $logMsg = date('Y-m-d H:i:s') . " - Error posting announcement: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            file_put_contents(__DIR__ . '/../debug_announcements.log', $logMsg, FILE_APPEND);
            echo json_encode(["success" => false, "message" => "Error posting announcement: " . $e->getMessage()]);
        }
        break;

    case 'edit_announcement':
        if ($role !== 'QMO Admin') {
            echo json_encode(["success" => false, "message" => "Unauthorized: Only QMO Admin can edit announcements"]);
            exit;
        }

        $id = $_POST['announcement_id'] ?? '';
        $title = $_POST['title'] ?? '';
        $content = $_POST['content'] ?? '';
        $remove_image = $_POST['remove_image'] ?? '0';

        if (empty($id) || empty($title) || empty($content)) {
            echo json_encode(["success" => false, "message" => "Invalid data"]);
            exit;
        }

        // Get current image path
        $stmt_select = $conn->prepare("SELECT image_path FROM announcements WHERE id = ?");
        $stmt_select->bind_param("i", $id);
        $stmt_select->execute();
        $res = $stmt_select->get_result();
        $ann = $res->fetch_assoc();
        $stmt_select->close();

        if (!$ann) {
            echo json_encode(["success" => false, "message" => "Announcement not found"]);
            exit;
        }

        $image_path = $ann['image_path'];

        if ($remove_image === '1' && $image_path) {
            $full_path = __DIR__ . '/../' . $image_path;
            if (file_exists($full_path)) {
                unlink($full_path);
            }
            $image_path = null;
        }

        // Image upload handling (new image)
        if (isset($_FILES['announcement_image']) && $_FILES['announcement_image']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['announcement_image']['tmp_name'];
            $fileName = $_FILES['announcement_image']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($fileExtension, $allowedExtensions)) {
                $uploadDir = __DIR__ . '/../uploads/announcements/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                // Delete old image if new one is uploaded
                if ($ann['image_path']) {
                    $old_full_path = __DIR__ . '/../' . $ann['image_path'];
                    if (file_exists($old_full_path)) {
                        unlink($old_full_path);
                    }
                }
                
                $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
                $dest_path = $uploadDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $dest_path)) {
                    $image_path = 'uploads/announcements/' . $newFileName;
                }
            }
        }

        $stmt = $conn->prepare("UPDATE announcements SET title = ?, content = ?, image_path = ? WHERE id = ?");
        $stmt->bind_param("sssi", $title, $content, $image_path, $id);
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Announcement updated successfully!"]);
        } else {
            echo json_encode(["success" => false, "message" => "Error updating announcement: " . $stmt->error]);
        }
        $stmt->close();
        break;

    case 'delete_announcement':
        if ($role !== 'QMO Admin') {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            exit;
        }

        // Can receive JSON or POST params
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? $_POST['id'] ?? '';

        if (empty($id)) {
            echo json_encode(["success" => false, "message" => "Invalid ID"]);
            exit;
        }

        // Get current image path
        $stmt_select = $conn->prepare("SELECT image_path FROM announcements WHERE id = ?");
        $stmt_select->bind_param("i", $id);
        $stmt_select->execute();
        $res = $stmt_select->get_result();
        $ann = $res->fetch_assoc();
        $stmt_select->close();

        if ($ann && $ann['image_path']) {
            $full_path = __DIR__ . '/../' . $ann['image_path'];
            if (file_exists($full_path)) {
                unlink($full_path);
            }
        }

        $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Announcement deleted successfully!"]);
        } else {
            echo json_encode(["success" => false, "message" => "Error deleting announcement: " . $stmt->error]);
        }
        $stmt->close();
        break;

    default:
        echo json_encode(["success" => false, "message" => "Invalid action"]);
        break;
}
?>
