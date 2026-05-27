<?php
session_start();
require_once __DIR__ . "/../config/db.php";

header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function update_last_message_at($conn, $conversation_id) {
    $stmt = $conn->prepare("UPDATE conversations SET last_message_at = CURRENT_TIMESTAMP WHERE conversation_id = ?");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $stmt->close();
}

switch ($action) {
    case 'get_conversations':
        $sql = "SELECT c.conversation_id, c.last_message_at,
                       (SELECT m.message_text FROM messages m WHERE m.conversation_id = c.conversation_id ORDER BY m.created_at DESC LIMIT 1) as last_message_text,
                       (SELECT 
                            CASE 
                                WHEN u.role = 'QMO Admin' THEN 'QMO Office'
                                WHEN o.office_name IS NOT NULL THEN o.office_name
                                ELSE u.role 
                            END
                        FROM messages m 
                        JOIN users u ON m.sender_id = u.user_id 
                        LEFT JOIN offices o ON u.office_id = o.office_id
                        WHERE m.conversation_id = c.conversation_id 
                        ORDER BY m.created_at DESC LIMIT 1) as last_message_sender_name,
                       (SELECT COUNT(m2.message_id) FROM messages m2 JOIN conversation_participants cp2 ON m2.conversation_id = cp2.conversation_id WHERE m2.conversation_id = c.conversation_id AND m2.message_id > COALESCE(cp2.last_read_message_id, 0) AND cp2.user_id = ?) as unread_count
                FROM conversations c
                JOIN conversation_participants cp ON c.conversation_id = cp.conversation_id
                WHERE cp.user_id = ?
                ORDER BY c.last_message_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $conversations = [];
        while ($row = $result->fetch_assoc()) {
            $conversations[] = $row;
        }
        echo json_encode(["success" => true, "conversations" => $conversations]);
        break;

    case 'get_messages':
        $conversation_id = $_GET['conversation_id'] ?? null;
        if (!$conversation_id) {
            echo json_encode(["success" => false, "message" => "Missing conversation ID"]);
            exit;
        }

        // Check if user is part of this conversation
        $stmt = $conn->prepare("SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $conversation_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(["success" => false, "message" => "Unauthorized to view this conversation"]);
            exit;
        }

        $sql = "SELECT m.*, 
                       CASE 
                           WHEN u.role = 'QMO Admin' THEN 'QMO Office'
                           WHEN o.office_name IS NOT NULL THEN o.office_name
                           ELSE u.role 
                       END as sender_name,
                       u.role as sender_role
                FROM messages m
                JOIN users u ON m.sender_id = u.user_id
                LEFT JOIN offices o ON u.office_id = o.office_id
                WHERE m.conversation_id = ?
                ORDER BY m.created_at ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $conversation_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];
        $last_message_id = 0;
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
            $last_message_id = $row['message_id'];
        }

        // Mark messages as read for the current user in this conversation
        if ($last_message_id > 0) {
            $stmt = $conn->prepare("UPDATE conversation_participants SET last_read_message_id = ? WHERE conversation_id = ? AND user_id = ?");
            $stmt->bind_param("iii", $last_message_id, $conversation_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }

        echo json_encode(["success" => true, "messages" => $messages]);
        break;

    case 'send_message':
        $receiver_id = $_POST['receiver_id'] ?? null;
        $conversation_id = $_POST['conversation_id'] ?? null; // Optional: if replying to existing conversation
        $message_text = $_POST['message_text'] ?? '';
        
        $attachment_path = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/messages/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $file_name = time() . '_' . basename($_FILES['attachment']['name']);
            $target_file = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_file)) {
                $attachment_path = 'uploads/messages/' . $file_name;
            }
        }

        if (!$receiver_id && !$conversation_id) {
            echo json_encode(["success" => false, "message" => "Missing recipient or conversation ID"]);
            exit;
        }
        if (!$message_text && !$attachment_path) {
            echo json_encode(["success" => false, "message" => "Message text or attachment is required"]);
            exit;
        }

        $conn->begin_transaction();
        try {
            if (!$conversation_id) {
                // Check if a conversation already exists between these two users
                $stmt = $conn->prepare("SELECT cp1.conversation_id FROM conversation_participants cp1 JOIN conversation_participants cp2 ON cp1.conversation_id = cp2.conversation_id WHERE cp1.user_id = ? AND cp2.user_id = ?");
                $stmt->bind_param("ii", $user_id, $receiver_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $conversation_id = $row['conversation_id'];
                } else {
                    // Create new conversation
                    $stmt = $conn->prepare("INSERT INTO conversations () VALUES ()");
                    $stmt->execute();
                    $conversation_id = $conn->insert_id;
                    $stmt->close();

                    // Add participants
                    $stmt = $conn->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)");
                    $stmt->bind_param("iiii", $conversation_id, $user_id, $conversation_id, $receiver_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            // Insert message
            $stmt = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, message_text, attachment_path, status) VALUES (?, ?, ?, ?, 'sent')");
            $stmt->bind_param("iiss", $conversation_id, $user_id, $message_text, $attachment_path);
            $stmt->execute();
            $message_id = $conn->insert_id;
            $stmt->close();

            // Update last_message_at for the conversation
            update_last_message_at($conn, $conversation_id);

            $conn->commit();
            echo json_encode(["success" => true, "message" => "Message sent successfully", "conversation_id" => $conversation_id, "message_id" => $message_id]);
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            echo json_encode(["success" => false, "message" => "Failed to send message: " . $e->getMessage()]);
        }
        break;

    case 'mark_as_read':
        $conversation_id = $_POST['conversation_id'] ?? null;
        $last_message_id = $_POST['last_message_id'] ?? null;

        if (!$conversation_id || !$last_message_id) {
            echo json_encode(["success" => false, "message" => "Missing conversation ID or last message ID"]);
            exit;
        }

        $stmt = $conn->prepare("UPDATE conversation_participants SET last_read_message_id = ? WHERE conversation_id = ? AND user_id = ?");
        $stmt->bind_param("iii", $last_message_id, $conversation_id, $user_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(["success" => true]);
        break;

    case 'get_recipients':
        $sql = "SELECT u.user_id, 
                       CASE 
                           WHEN u.role = 'QMO Admin' THEN 'QMO Office'
                           WHEN o.office_name IS NOT NULL THEN o.office_name
                           ELSE u.role 
                       END as full_name, 
                       u.role 
                FROM users u 
                LEFT JOIN offices o ON u.office_id = o.office_id
                WHERE u.user_id != ? AND u.status = 'Active'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $recipients = [];
        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row;
        }
        echo json_encode(["success" => true, "recipients" => $recipients]);
        break;

    case 'get_unread_count':
        $sql = "SELECT COUNT(m.message_id) as unread_count
                FROM messages m
                JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id
                WHERE cp.user_id = ? AND m.message_id > COALESCE(cp.last_read_message_id, 0)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        echo json_encode(["success" => true, "unread_count" => (int)$result['unread_count']]);
        break;

    case 'send_bulk_message':
        if ($role !== 'QMO Admin') {
            echo json_encode(["success" => false, "message" => "Unauthorized: Only QMO Admin can send bulk messages"]);
            exit;
        }

        $receiver_ids = $_POST['receiver_ids'] ?? []; // Array of user IDs
        $message_text = $_POST['message_text'] ?? '';

        if (empty($receiver_ids)) {
            echo json_encode(["success" => false, "message" => "No recipients selected"]);
            exit;
        }
        if (!$message_text) {
            echo json_encode(["success" => false, "message" => "Message text cannot be empty"]);
            exit;
        }

        $conn->begin_transaction();
        try {
            foreach ($receiver_ids as $rid) {
                // For each recipient, find or create a conversation
                $conv_id = null;
                $stmt = $conn->prepare("SELECT cp1.conversation_id FROM conversation_participants cp1 JOIN conversation_participants cp2 ON cp1.conversation_id = cp2.conversation_id WHERE cp1.user_id = ? AND cp2.user_id = ?");
                $stmt->bind_param("ii", $user_id, $rid);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $conv_id = $row['conversation_id'];
                } else {
                    // Create new conversation
                    $stmt = $conn->prepare("INSERT INTO conversations () VALUES ()");
                    $stmt->execute();
                    $conv_id = $conn->insert_id;
                    $stmt->close();

                    // Add participants
                    $stmt = $conn->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)");
                    $stmt->bind_param("iiii", $conv_id, $user_id, $conv_id, $rid);
                    $stmt->execute();
                    $stmt->close();
                }

                // Insert message
                $stmt = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, message_text, status) VALUES (?, ?, ?, 'sent')");
                $stmt->bind_param("iis", $conv_id, $user_id, $message_text);
                $stmt->execute();
                $stmt->close();

                // Update last_message_at for the conversation
                update_last_message_at($conn, $conv_id);
            }

            $conn->commit();
            echo json_encode(["success" => true, "message" => "Bulk message sent successfully to " . count($receiver_ids) . " recipients"]);
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            echo json_encode(["success" => false, "message" => "Failed to send bulk message: " . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(["success" => false, "message" => "Invalid action"]);
        break;
}
?>
