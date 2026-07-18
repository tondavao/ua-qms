<?php
/**
 * Repository API
 * Handles all document repository operations including search, filtering, and retrieval
 * Provides centralized access to approved documents from all offices
 */

session_start();
include "../config/db.php";

header('Content-Type: application/json');

// Verify user is authenticated and has QMO Admin role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'QMO Admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$action = $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];

// Read JSON input for POST requests
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$_POST = array_merge($_POST, $input);

// Get all repository documents with advanced filtering
if ($action === 'get_repository_documents') {
    $search_query = $_GET['search'] ?? '';
    $doc_type_filter = $_GET['doc_type'] ?? '';
    $office_filter = $_GET['office'] ?? '';
    $status_filter = $_GET['status'] ?? 'All';
    $ay_filter = $_GET['academic_year'] ?? '';
    $sem_filter = $_GET['semester'] ?? '';
    $sort_by = $_GET['sort_by'] ?? 'submitted_at';
    $sort_order = $_GET['sort_order'] ?? 'DESC';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, intval($_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    // Validate sort parameters to prevent SQL injection
    $allowed_sorts = ['submitted_at', 'file_name', 'office_name', 'doc_code'];
    $sort_by = in_array($sort_by, $allowed_sorts) ? $sort_by : 'submitted_at';
    $sort_order = in_array(strtoupper($sort_order), ['ASC', 'DESC']) ? strtoupper($sort_order) : 'DESC';

    // Build the base query
    $sql = "SELECT d.document_id, d.file_name, d.file_path, d.preview_path, d.status, d.submitted_at,
                   d.academic_year, d.semester,
                   o.office_id, o.office_name, o.office_code,
                   dt.doc_type_id, dt.doc_code, dt.description,
                   u.full_name as submitted_by
            FROM documents d
            JOIN offices o ON d.office_id = o.office_id
            JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
            LEFT JOIN users u ON o.office_id = u.office_id AND u.role IN ('Dean', 'Office')
            WHERE d.status = 'Approved'";

    $params = [];
    $types = '';

    // Apply search filter
    if (!empty($search_query)) {
        $sql .= " AND (d.file_name LIKE ? OR dt.description LIKE ? OR o.office_name LIKE ?)";
        $search_term = '%' . $search_query . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= 'sss';
    }

    // Apply document type filter
    if (!empty($doc_type_filter)) {
        $sql .= " AND dt.doc_code = ?";
        $params[] = $doc_type_filter;
        $types .= 's';
    }

    // Apply office filter
    if (!empty($office_filter)) {
        $sql .= " AND o.office_id = ?";
        $params[] = intval($office_filter);
        $types .= 'i';
    }

    // Apply academic year filter
    if (!empty($ay_filter)) {
        $sql .= " AND d.academic_year = ?";
        $params[] = $ay_filter;
        $types .= 's';
    }

    // Apply semester filter
    if (!empty($sem_filter)) {
        $sql .= " AND d.semester = ?";
        $params[] = $sem_filter;
        $types .= 's';
    }

    // Get total count for pagination
    $count_sql = "SELECT COUNT(DISTINCT d.document_id) as total FROM documents d
                  JOIN offices o ON d.office_id = o.office_id
                  JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
                  WHERE d.status = 'Approved'";
    
    // Apply same filters to count query
    if (!empty($search_query)) {
        $count_sql .= " AND (d.file_name LIKE ? OR dt.description LIKE ? OR o.office_name LIKE ?)";
    }
    if (!empty($doc_type_filter)) {
        $count_sql .= " AND dt.doc_code = ?";
    }
    if (!empty($office_filter)) {
        $count_sql .= " AND o.office_id = ?";
    }
    if (!empty($ay_filter)) {
        $count_sql .= " AND d.academic_year = ?";
    }
    if (!empty($sem_filter)) {
        $count_sql .= " AND d.semester = ?";
    }

    $stmt_count = $conn->prepare($count_sql);
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $count_result = $stmt_count->get_result()->fetch_assoc();
    $total_count = $count_result['total'] ?? 0;

    // Add sorting and pagination
    $sql .= " ORDER BY d." . $sort_by . " " . $sort_order;
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    // Execute main query
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = [
            'document_id' => $row['document_id'],
            'file_name' => $row['file_name'],
            'file_path' => $row['file_path'],
            'preview_path' => $row['preview_path'],
            'status' => $row['status'],
            'submitted_at' => $row['submitted_at'],
            'office_id' => $row['office_id'],
            'office_name' => $row['office_name'],
            'office_code' => $row['office_code'],
            'doc_type_id' => $row['doc_type_id'],
            'doc_code' => $row['doc_code'],
            'description' => $row['description'],
            'submitted_by' => $row['submitted_by'],
            'academic_year' => $row['academic_year'],
            'semester' => $row['semester']
        ];
    }

    echo json_encode([
        'success' => true,
        'documents' => $documents,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_count' => $total_count,
            'total_pages' => ceil($total_count / $limit),
            'offset' => $offset
        ]
    ]);
    exit();
}

// Get repository statistics
if ($action === 'get_repository_stats') {
    // Total documents in repository
    $sql_total = "SELECT COUNT(*) as total_documents FROM documents WHERE status = 'Approved'";
    $stmt_total = $conn->prepare($sql_total);
    $stmt_total->execute();
    $total_docs = $stmt_total->get_result()->fetch_assoc()['total_documents'];

    // Documents by type
    $sql_by_type = "SELECT dt.doc_code, dt.description, COUNT(d.document_id) as count
                    FROM document_types dt
                    LEFT JOIN documents d ON dt.doc_type_id = d.doc_type_id AND d.status = 'Approved'
                    GROUP BY dt.doc_type_id, dt.doc_code, dt.description
                    ORDER BY count DESC";
    $stmt_by_type = $conn->prepare($sql_by_type);
    $stmt_by_type->execute();
    $by_type_result = $stmt_by_type->get_result();

    $by_type = [];
    while ($row = $by_type_result->fetch_assoc()) {
        $by_type[] = $row;
    }

    // Documents by office
    $sql_by_office = "SELECT o.office_id, o.office_name, o.office_code, COUNT(d.document_id) as count
                      FROM offices o
                      LEFT JOIN documents d ON o.office_id = d.office_id AND d.status = 'Approved'
                      WHERE o.status = 'Active'
                      GROUP BY o.office_id, o.office_name, o.office_code
                      ORDER BY count DESC";
    $stmt_by_office = $conn->prepare($sql_by_office);
    $stmt_by_office->execute();
    $by_office_result = $stmt_by_office->get_result();

    $by_office = [];
    while ($row = $by_office_result->fetch_assoc()) {
        $by_office[] = $row;
    }

    // Recent uploads (last 7 days)
    $sql_recent = "SELECT COUNT(*) as recent_uploads
                   FROM documents
                   WHERE status = 'Approved' AND submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $stmt_recent = $conn->prepare($sql_recent);
    $stmt_recent->execute();
    $recent_uploads = $stmt_recent->get_result()->fetch_assoc()['recent_uploads'];

    echo json_encode([
        'success' => true,
        'total_documents' => $total_docs,
        'recent_uploads_7days' => $recent_uploads,
        'by_type' => $by_type,
        'by_office' => $by_office
    ]);
    exit();
}

// Get document categories (for filter dropdowns)
if ($action === 'get_categories') {
    $category_type = $_GET['type'] ?? 'all'; // 'doc_types' or 'offices'

    if ($category_type === 'doc_types' || $category_type === 'all') {
        $sql_types = "SELECT doc_type_id, doc_code, description FROM document_types ORDER BY doc_code ASC";
        $stmt_types = $conn->prepare($sql_types);
        $stmt_types->execute();
        $types_result = $stmt_types->get_result();

        $doc_types = [];
        while ($row = $types_result->fetch_assoc()) {
            $doc_types[] = $row;
        }
    }

    if ($category_type === 'offices' || $category_type === 'all') {
        $sql_offices = "SELECT office_id, office_name, office_code FROM offices WHERE status = 'Active' AND is_archived = FALSE ORDER BY office_name ASC";
        $stmt_offices = $conn->prepare($sql_offices);
        $stmt_offices->execute();
        $offices_result = $stmt_offices->get_result();

        $offices = [];
        while ($row = $offices_result->fetch_assoc()) {
            $offices[] = $row;
        }
    }

    if ($category_type === 'all') {
        // Fetch academic years
        $sql_ay = "SELECT year_range, is_active FROM academic_years ORDER BY year_range DESC";
        $stmt_ay = $conn->prepare($sql_ay);
        $stmt_ay->execute();
        $ay_res = $stmt_ay->get_result();
        $academic_years = [];
        while ($row = $ay_res->fetch_assoc()) {
            $academic_years[] = $row;
        }

        // Fetch semesters
        $sql_sem = "SELECT semester_name, is_active FROM semesters ORDER BY semester_name ASC";
        $stmt_sem = $conn->prepare($sql_sem);
        $stmt_sem->execute();
        $sem_res = $stmt_sem->get_result();
        $semesters = [];
        while ($row = $sem_res->fetch_assoc()) {
            $semesters[] = $row;
        }
    }

    $response = ['success' => true];
    if ($category_type === 'doc_types' || $category_type === 'all') {
        $response['doc_types'] = $doc_types ?? [];
    }
    if ($category_type === 'offices' || $category_type === 'all') {
        $response['offices'] = $offices ?? [];
    }
    if ($category_type === 'all') {
        $response['academic_years'] = $academic_years ?? [];
        $response['semesters'] = $semesters ?? [];
    }

    echo json_encode($response);
    exit();
}

// Get single document details
if ($action === 'get_document_details') {
    $document_id = $_GET['document_id'] ?? null;

    if (!$document_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing document_id']);
        exit();
    }

    $sql = "SELECT d.document_id, d.file_name, d.file_path, d.status, d.submitted_at,
                   o.office_id, o.office_name, o.office_code,
                   dt.doc_type_id, dt.doc_code, dt.description,
                   u.full_name as submitted_by,
                   COUNT(dr.review_id) as review_count
            FROM documents d
            JOIN offices o ON d.office_id = o.office_id
            JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
            LEFT JOIN users u ON o.office_id = u.office_id AND u.role IN ('Dean', 'Office')
            LEFT JOIN document_reviews dr ON d.document_id = dr.document_id
            WHERE d.document_id = ?
            GROUP BY d.document_id";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $document_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit();
    }

    $document = $result->fetch_assoc();

    echo json_encode([
        'success' => true,
        'document' => $document
    ]);
    exit();
}

// Log repository access for audit trail
if ($action === 'log_access') {
    $document_id = $_POST['document_id'] ?? null;
    $access_type = $_POST['access_type'] ?? 'view'; // view, download, print

    if (!$document_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing document_id']);
        exit();
    }

    // Log to system_logs table
    $action_desc = "Repository Access: " . ucfirst($access_type) . " Document ID " . $document_id;
    $sql_log = "INSERT INTO system_logs (user_id, action, details) VALUES (?, ?, ?)";
    $stmt_log = $conn->prepare($sql_log);
    $stmt_log->bind_param("iss", $user_id, $action_desc, $access_type);
    $stmt_log->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Access logged successfully'
    ]);
    exit();
}

// Delete Document from Repository
if ($action === 'delete_document') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }

    $document_id = $_POST['document_id'] ?? null;
    if (!$document_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing document_id']);
        exit();
    }

    // Get file info
    $sql_doc = "SELECT file_path, preview_path FROM documents WHERE document_id = ?";
    $stmt_doc = $conn->prepare($sql_doc);
    $stmt_doc->bind_param("i", $document_id);
    $stmt_doc->execute();
    $res_doc = $stmt_doc->get_result();

    if ($res_doc->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit();
    }

    $doc = $res_doc->fetch_assoc();

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
        // Log the deletion to system_logs
        $action_desc = "Document Deleted: Document ID " . $document_id . " from Repository";
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $sql_log = "INSERT INTO system_logs (user_id, action, details, ip_address) VALUES (?, ?, 'Approved document deleted by QMO Admin', ?)";
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
