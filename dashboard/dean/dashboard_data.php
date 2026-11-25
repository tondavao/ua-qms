<?php
session_start();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../includes/system_settings.php";

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'Dean' && $_SESSION['role'] != 'Office')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$dean_id = $_SESSION['user_id'];
$office_id = $_SESSION['office_id'] ?? 0;

// Load active academic year and active semester from system configurations
$period = ua_qms_get_active_period($conn);
$active_year = $period['active_year'];
$active_semester = $period['active_semester'];

// Parse target year
$year_parts = explode('-', $active_year);
$start_year = (int)$year_parts[0];
$end_year = isset($year_parts[1]) ? (int)$year_parts[1] : $start_year + 1;
$target_year = ($active_semester === '1st Semester' || strpos($active_semester, '1st') !== false || strpos($active_semester, '2nd') !== false) ? $start_year : $end_year; // simple fallback

// Stats
$stats = ['completion_rate' => 0, 'approved' => 0, 'pending' => 0, 'revision' => 0];
$sql_counts = "SELECT status, COUNT(*) as count FROM documents WHERE office_id = ? AND academic_year = ? AND semester = ? GROUP BY status";
$stmt = $conn->prepare($sql_counts);
$stmt->bind_param("iss", $office_id, $active_year, $active_semester);
$stmt->execute();
$result = $stmt->get_result();
while($row = $result->fetch_assoc()) {
    if($row['status'] == 'Approved') $stats['approved'] = (int)$row['count'];
    if($row['status'] == 'Awaiting Review') $stats['pending'] = (int)$row['count'];
    if($row['status'] == 'Revision') $stats['revision'] = (int)$row['count'];
}
$stmt->close();

// Calculate completion rate based on QMO compliance logic: (Approved Required Doc Types / Total Required Doc Types)
$res_req = $conn->query("SELECT COUNT(*) as total_req FROM document_types WHERE required = 'Yes'");
$total_required_docs = ($res_req && $row_req = $res_req->fetch_assoc()) ? (int)$row_req['total_req'] : 8;

$stmt_app = $conn->prepare("
    SELECT COUNT(DISTINCT d.doc_type_id) as approved_count 
    FROM documents d
    JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
    WHERE d.office_id = ? 
      AND d.status = 'Approved' 
      AND d.academic_year = ? 
      AND d.semester = ? 
      AND dt.required = 'Yes'
");
$stmt_app->bind_param("iss", $office_id, $active_year, $active_semester);
$stmt_app->execute();
$res_app = $stmt_app->get_result()->fetch_assoc();
$approved_required_count = (int)($res_app['approved_count'] ?? 0);
$stmt_app->close();

$stats['completion_rate'] = $total_required_docs > 0 ? round(($approved_required_count / $total_required_docs) * 100) : 0;

// Fetch Upcoming Deadlines (respecting unit-specific CAR assignments)
require_once __DIR__ . "/../../includes/schema_helpers.php";
ua_qms_ensure_target_office_table($conn);
$deadlines = [];
$sql_deadlines = "SELECT ts.deadline, dt.doc_code, dt.description 
                  FROM target_submissions ts 
                  JOIN document_types dt ON ts.doc_type_id = dt.doc_type_id 
                  WHERE ts.year = ? AND ts.deadline >= CURDATE()
                    AND (
                        NOT EXISTS (SELECT 1 FROM target_submission_offices tso WHERE tso.target_id = ts.target_id)
                        OR EXISTS (SELECT 1 FROM target_submission_offices tso WHERE tso.target_id = ts.target_id AND tso.office_id = ?)
                    )
                  ORDER BY ts.deadline ASC LIMIT 5";
$stmt_deadlines = $conn->prepare($sql_deadlines);
$stmt_deadlines->bind_param("si", $active_year, $office_id);
$stmt_deadlines->execute();
$res_deadlines = $stmt_deadlines->get_result();
while($row = $res_deadlines->fetch_assoc()) {
    $deadlines[] = $row;
}
$stmt_deadlines->close();

// Fetch Revision Inbox
ua_qms_ensure_document_annotations_table($conn);
$revisions = [];
$sql_revisions = "SELECT d.document_id, d.file_name, dt.doc_code, dr.remarks, dr.reviewed_at,
                         (da.annotation_id IS NOT NULL) AS has_edits
                  FROM documents d
                  JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
                  LEFT JOIN document_reviews dr ON d.document_id = dr.document_id
                  LEFT JOIN document_annotations da ON d.document_id = da.document_id
                  WHERE d.office_id = ? AND d.status = 'Revision'
                  AND dr.review_id = (SELECT MAX(review_id) FROM document_reviews WHERE document_id = d.document_id)
                  ORDER BY dr.reviewed_at DESC";
$stmt_rev = $conn->prepare($sql_revisions);
$stmt_rev->bind_param("i", $office_id);
$stmt_rev->execute();
$res_rev = $stmt_rev->get_result();
while($row = $res_rev->fetch_assoc()) {
    $revisions[] = $row;
}
$stmt_rev->close();

echo json_encode([
    'success' => true,
    'stats' => $stats,
    'deadlines' => $deadlines,
    'revisions' => $revisions,
    'active_year' => $active_year,
    'active_semester' => $active_semester
]);
?>
