<?php
/**
 * Analytics API
 * Feature 6: Content determined by system with analytics
 * Feature 7: Determine how many officers have complied
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

// Track document analytics when document is submitted/reviewed
if ($action === 'track_document_analytics') {
    $document_id = $_POST['document_id'] ?? null;
    $event_type = $_POST['event_type'] ?? 'submission'; // submission, review, approval

    if (!$document_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing document_id']);
        exit();
    }

    // Get document details
    $sql_doc = "SELECT d.*, dt.doc_type_id FROM documents d 
                JOIN document_types dt ON d.doc_type_id = dt.doc_type_id 
                WHERE d.document_id = ?";
    $stmt_doc = $conn->prepare($sql_doc);
    $stmt_doc->bind_param("i", $document_id);
    $stmt_doc->execute();
    $doc = $stmt_doc->get_result()->fetch_assoc();

    if (!$doc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit();
    }

    // Check if analytics record exists
    $sql_check = "SELECT analytics_id FROM document_analytics WHERE document_id = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("i", $document_id);
    $stmt_check->execute();
    $check_result = $stmt_check->get_result();

    $analytics_data = [];
    $now = date('Y-m-d H:i:s');

    if ($check_result->num_rows > 0) {
        // Update existing analytics
        if ($event_type === 'review') {
            $sql_update = "UPDATE document_analytics SET review_date = ? WHERE document_id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("si", $now, $document_id);
            $stmt_update->execute();
        } elseif ($event_type === 'approval') {
            $sql_update = "UPDATE document_analytics SET approval_date = ? WHERE document_id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("si", $now, $document_id);
            $stmt_update->execute();
        }
    } else {
        // Create new analytics record
        $submission_date = $doc['submitted_at'];
        $status_history = json_encode([
            ['status' => $doc['status'], 'timestamp' => $now]
        ]);

        $sql_insert = "INSERT INTO document_analytics 
                      (document_id, office_id, doc_type_id, submission_date, status_history) 
                      VALUES (?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param("iisss", $document_id, $doc['office_id'], $doc['doc_type_id'], $submission_date, $status_history);
        $stmt_insert->execute();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Analytics tracked successfully',
        'document_id' => $document_id,
        'event_type' => $event_type
    ]);
    exit();
}

// Get document analytics dashboard
if ($action === 'get_analytics_dashboard') {
    if ($role !== 'QMO Admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only QMO Admin can view analytics']);
        exit();
    }

    $quarter = $_GET['quarter'] ?? null;
    $year = $_GET['year'] ?? date('Y');

    // Get submission statistics
    $sql_submissions = "SELECT COUNT(*) as total_submissions,
                              SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
                              SUM(CASE WHEN status = 'Awaiting Review' THEN 1 ELSE 0 END) as pending,
                              SUM(CASE WHEN status = 'Revision' THEN 1 ELSE 0 END) as revision,
                              SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
                       FROM documents
                       WHERE YEAR(submitted_at) = ?";
    $stmt_submissions = $conn->prepare($sql_submissions);
    $stmt_submissions->bind_param("s", $year);
    $stmt_submissions->execute();
    $submissions_stats = $stmt_submissions->get_result()->fetch_assoc();

    // Get average review time
    $sql_review_time = "SELECT AVG(DATEDIFF(review_date, submission_date)) as avg_review_days,
                               MIN(DATEDIFF(review_date, submission_date)) as min_review_days,
                               MAX(DATEDIFF(review_date, submission_date)) as max_review_days
                        FROM document_analytics
                        WHERE review_date IS NOT NULL AND YEAR(submission_date) = ?";
    $stmt_review_time = $conn->prepare($sql_review_time);
    $stmt_review_time->bind_param("s", $year);
    $stmt_review_time->execute();
    $review_time = $stmt_review_time->get_result()->fetch_assoc();

    // Get document type distribution
    $sql_doc_types = "SELECT dt.doc_code, dt.description, COUNT(d.document_id) as count,
                             SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved_count
                      FROM document_types dt
                      LEFT JOIN documents d ON dt.doc_type_id = d.doc_type_id AND YEAR(d.submitted_at) = ?
                      GROUP BY dt.doc_type_id, dt.doc_code, dt.description
                      ORDER BY count DESC";
    $stmt_doc_types = $conn->prepare($sql_doc_types);
    $stmt_doc_types->bind_param("s", $year);
    $stmt_doc_types->execute();
    $doc_types_result = $stmt_doc_types->get_result();

    $doc_types = [];
    while ($row = $doc_types_result->fetch_assoc()) {
        $doc_types[] = $row;
    }

    // Get office compliance ranking
    $sql_offices = "SELECT o.office_id, o.office_name, o.office_code,
                           COUNT(d.document_id) as total_docs,
                           SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved_docs
                    FROM offices o
                    LEFT JOIN documents d ON o.office_id = d.office_id AND YEAR(d.submitted_at) = ?
                    WHERE o.status = 'Active'
                    GROUP BY o.office_id, o.office_name, o.office_code
                    ORDER BY approved_docs DESC";
    $stmt_offices = $conn->prepare($sql_offices);
    $stmt_offices->bind_param("s", $year);
    $stmt_offices->execute();
    $offices_result = $stmt_offices->get_result();

    $offices = [];
    while ($row = $offices_result->fetch_assoc()) {
        $compliance_rate = $row['total_docs'] > 0 ? round(($row['approved_docs'] / $row['total_docs']) * 100) : 0;
        $row['compliance_rate'] = $compliance_rate;
        $offices[] = $row;
    }

    echo json_encode([
        'success' => true,
        'year' => $year,
        'quarter' => $quarter,
        'submissions' => $submissions_stats,
        'review_time' => $review_time,
        'document_types' => $doc_types,
        'offices' => $offices
    ]);
    exit();
}

// Get content recommendations based on analytics
if ($action === 'get_content_recommendations') {
    if ($role !== 'QMO Admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only QMO Admin can view recommendations']);
        exit();
    }

    // Analyze submission patterns to recommend content
    $sql_analysis = "SELECT dt.doc_code, dt.description,
                            COUNT(d.document_id) as submission_count,
                            SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approval_count,
                            SUM(CASE WHEN d.status = 'Revision' THEN 1 ELSE 0 END) as revision_count,
                            AVG(DATEDIFF(NOW(), d.submitted_at)) as avg_days_pending
                     FROM document_types dt
                     LEFT JOIN documents d ON dt.doc_type_id = d.doc_type_id
                     GROUP BY dt.doc_type_id, dt.doc_code, dt.description
                     ORDER BY revision_count DESC";
    
    $stmt_analysis = $conn->prepare($sql_analysis);
    $stmt_analysis->execute();
    $result = $stmt_analysis->get_result();

    $recommendations = [];
    while ($row = $result->fetch_assoc()) {
        $approval_rate = $row['submission_count'] > 0 ? round(($row['approval_count'] / $row['submission_count']) * 100) : 0;
        
        $recommendation = '';
        $priority = 'low';

        if ($approval_rate < 70) {
            $recommendation = 'High revision rate detected. Consider providing clearer guidelines or templates.';
            $priority = 'high';
        } elseif ($approval_rate < 85) {
            $recommendation = 'Moderate revision rate. Review feedback patterns to improve document quality.';
            $priority = 'medium';
        } else {
            $recommendation = 'Good approval rate. Continue current guidelines.';
            $priority = 'low';
        }

        $recommendations[] = [
            'doc_code' => $row['doc_code'],
            'description' => $row['description'],
            'submission_count' => $row['submission_count'],
            'approval_rate' => $approval_rate,
            'revision_count' => $row['revision_count'],
            'avg_days_pending' => round($row['avg_days_pending']),
            'recommendation' => $recommendation,
            'priority' => $priority
        ];
    }

    echo json_encode([
        'success' => true,
        'recommendations' => $recommendations
    ]);
    exit();
}

// Get officer compliance summary
if ($action === 'get_compliance_summary') {
    if ($role !== 'QMO Admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only QMO Admin can view compliance summary']);
        exit();
    }

    $quarter = $_GET['quarter'] ?? null;
    $year = $_GET['year'] ?? date('Y');

    // Get total officers per office
    $sql_officers = "SELECT o.office_id, o.office_name, o.office_code,
                            COUNT(DISTINCT u.user_id) as total_officers,
                            SUM(CASE WHEN oc.is_complied = 1 THEN 1 ELSE 0 END) as complied_officers
                     FROM offices o
                     LEFT JOIN users u ON o.office_id = u.office_id AND u.role IN ('Dean', 'Office')
                     LEFT JOIN officer_compliance oc ON u.user_id = oc.user_id 
                                                      AND oc.office_id = o.office_id
                                                      AND oc.quarter = ?
                                                      AND oc.year = ?
                     WHERE o.status = 'Active'
                     GROUP BY o.office_id, o.office_name, o.office_code
                     ORDER BY complied_officers DESC";
    
    $stmt_officers = $conn->prepare($sql_officers);
    $stmt_officers->bind_param("ss", $quarter, $year);
    $stmt_officers->execute();
    $result = $stmt_officers->get_result();

    $compliance_summary = [];
    $total_all_officers = 0;
    $total_complied = 0;

    while ($row = $result->fetch_assoc()) {
        $total_officers = $row['total_officers'] ?? 0;
        $complied = $row['complied_officers'] ?? 0;
        $compliance_rate = $total_officers > 0 ? round(($complied / $total_officers) * 100) : 0;

        $total_all_officers += $total_officers;
        $total_complied += $complied;

        $compliance_summary[] = [
            'office_id' => $row['office_id'],
            'office_name' => $row['office_name'],
            'office_code' => $row['office_code'],
            'total_officers' => $total_officers,
            'complied_officers' => $complied,
            'non_complied_officers' => $total_officers - $complied,
            'compliance_rate' => $compliance_rate
        ];
    }

    $overall_compliance = $total_all_officers > 0 ? round(($total_complied / $total_all_officers) * 100) : 0;

    echo json_encode([
        'success' => true,
        'quarter' => $quarter,
        'year' => $year,
        'total_officers' => $total_all_officers,
        'total_complied' => $total_complied,
        'overall_compliance_rate' => $overall_compliance,
        'compliance_summary' => $compliance_summary
    ]);
    exit();
}

// Default response
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
