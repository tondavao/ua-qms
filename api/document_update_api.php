<?php
/**
 * Document Update API
 * Handles document updates and unit information updates
 * Feature 1: Dean can update unit if document is passed
 */

session_start();
include "../config/db.php";

header('Content-Type: application/json');

// Verify user is authenticated
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$office_id = $_SESSION['office_id'] ?? null;

// Fallback to fetch office_id from DB if not in session
if (!$office_id && $user_id) {
    $sql_user = "SELECT office_id FROM users WHERE user_id = ?";
    $stmt_user = $conn->prepare($sql_user);
    if ($stmt_user) {
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $user_result = $stmt_user->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $office_id = $user_row['office_id'];
            $_SESSION['office_id'] = $office_id;
        }
    }
}

// Feature 1: Update Unit Information when Document is Passed
if ($action === 'update_unit_on_approval') {
    if ($role !== 'Dean') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only Deans can update unit information']);
        exit();
    }

    $document_id = $_POST['document_id'] ?? null;
    $unit_name = $_POST['unit_name'] ?? null;
    $unit_code = $_POST['unit_code'] ?? null;
    $unit_category = $_POST['unit_category'] ?? null;

    if (!$document_id || !$office_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }

    // Verify document belongs to this unit and is approved
    $sql_check = "SELECT d.document_id, d.status FROM documents d 
                  WHERE d.document_id = ? AND d.office_id = ? AND d.status = 'Approved'";
    $stmt = $conn->prepare($sql_check);
    $stmt->bind_param("ii", $document_id, $office_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document not found or not approved']);
        exit();
    }

    // Update unit information
    $sql_update = "UPDATE offices SET office_name = ?, office_code = ?, category_id = ? WHERE office_id = ?";
    $stmt_update = $conn->prepare($sql_update);
    
    // Get category_id from category_name if provided
    $category_id = null;
    if ($unit_category) {
        $sql_cat = "SELECT category_id FROM unit_categories WHERE category_name = ?";
        $stmt_cat = $conn->prepare($sql_cat);
        $stmt_cat->bind_param("s", $unit_category);
        $stmt_cat->execute();
        $cat_result = $stmt_cat->get_result();
        if ($cat_row = $cat_result->fetch_assoc()) {
            $category_id = $cat_row['category_id'];
        }
    }

    $stmt_update->bind_param("ssii", $unit_name, $unit_code, $category_id, $office_id);
    
    if ($stmt_update->execute()) {
        // Log the update
        $sql_log = "INSERT INTO unit_update_history (office_id, updated_by, update_type, document_id, new_data, update_date) 
                    VALUES (?, ?, 'unit_update', ?, JSON_OBJECT('unit_name', ?, 'unit_code', ?, 'category_id', ?), NOW())";
        $stmt_log = $conn->prepare($sql_log);
        $stmt_log->bind_param("iissi", $office_id, $user_id, $document_id, $unit_name, $unit_code, $category_id);
        $stmt_log->execute();

        echo json_encode([
            'success' => true,
            'message' => 'Unit information updated successfully',
            'office_id' => $office_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update unit information']);
    }
    exit();
}



// Feature 5: Record upload process details
if ($action === 'log_upload_process') {
    $document_id = $_POST['document_id'] ?? null;
    $upload_scope = $_POST['upload_scope'] ?? null;
    $upload_limitations = $_POST['upload_limitations'] ?? null;

    if (!$document_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing document_id']);
        exit();
    }

    // Get file info
    $sql_doc = "SELECT file_name, file_path FROM documents WHERE document_id = ?";
    $stmt_doc = $conn->prepare($sql_doc);
    $stmt_doc->bind_param("i", $document_id);
    $stmt_doc->execute();
    $doc_result = $stmt_doc->get_result();
    
    if (!$doc_row = $doc_result->fetch_assoc()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit();
    }

    $file_size = file_exists($doc_row['file_path']) ? filesize($doc_row['file_path']) : 0;
    $file_type = pathinfo($doc_row['file_name'], PATHINFO_EXTENSION);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

    // Update documents table with scope and limitations
    $sql_update = "UPDATE documents SET upload_scope = ?, upload_limitations = ? WHERE document_id = ?";
    $stmt_upd = $conn->prepare($sql_update);
    $stmt_upd->bind_param("ssi", $upload_scope, $upload_limitations, $document_id);
    $stmt_upd->execute();

    // Log the upload process
    $sql_log = "INSERT INTO upload_process_logs (document_id, user_id, file_size, file_type, upload_scope, upload_limitations, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt_log = $conn->prepare($sql_log);
    $stmt_log->bind_param("iiissss", $document_id, $user_id, $file_size, $file_type, $upload_scope, $upload_limitations, $ip_address);

    if ($stmt_log->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Upload process logged successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to log upload process']);
    }
    exit();
}

// Feature 6: Delete Revision Document
if ($action === 'delete_revision_document') {
    $document_id = $_POST['document_id'] ?? null;

    if (!$document_id || !$office_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing document_id or unauthorized office context']);
        exit();
    }

    // Verify document belongs to this office and is in 'Revision' or 'Awaiting Review' status
    $sql_check = "SELECT document_id, file_path, preview_path, status FROM documents WHERE document_id = ? AND office_id = ? AND status IN ('Revision', 'Awaiting Review')";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("ii", $document_id, $office_id);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();

    if ($res_check->num_rows === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document not found or cannot be deleted (must be in Revision or Awaiting Review status)']);
        exit();
    }

    $doc = $res_check->fetch_assoc();
    
    // Attempt to delete physical files
    if (!empty($doc['file_path'])) {
        $file_path = $doc['file_path'];
        if (strpos($file_path, '../../') === 0) {
            $file_path = '../' . substr($file_path, 6);
        } elseif (strpos($file_path, '../') !== 0 && strpos($file_path, '/') !== 0 && strpos($file_path, 'http') !== 0) {
            $file_path = '../' . $file_path;
        }
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
    }
    if (!empty($doc['preview_path'])) {
        $preview_path = $doc['preview_path'];
        if (strpos($preview_path, '../../') === 0) {
            $preview_path = '../' . substr($preview_path, 6);
        } elseif (strpos($preview_path, '../') !== 0 && strpos($preview_path, '/') !== 0 && strpos($preview_path, 'http') !== 0) {
            $preview_path = '../' . $preview_path;
        }
        if (file_exists($preview_path)) {
            @unlink($preview_path);
        }
    }

    // Delete from database
    $sql_delete = "DELETE FROM documents WHERE document_id = ?";
    $stmt_delete = $conn->prepare($sql_delete);
    $stmt_delete->bind_param("i", $document_id);

    if ($stmt_delete->execute()) {
        // Also log this deletion
        $action_desc = "Document Deleted: Document ID " . $document_id . " (" . ($doc['status'] ?? 'Unknown') . " status)";
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $sql_log = "INSERT INTO system_logs (user_id, action, details, ip_address) VALUES (?, ?, 'Document deleted by user', ?)";
        $stmt_log = $conn->prepare($sql_log);
        $stmt_log->bind_param("iss", $user_id, $action_desc, $ip_address);
        $stmt_log->execute();

        echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete document from database']);
    }
    exit();
}

// Default response
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
