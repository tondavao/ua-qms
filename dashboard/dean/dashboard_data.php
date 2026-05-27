<?php
session_start();
require_once "../../config/db.php";

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'Dean' && $_SESSION['role'] != 'Office')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$dean_id = $_SESSION['user_id'];
$office_id = $_SESSION['office_id'] ?? 0;

// Load active academic year and active semester from system configurations
$settings = [];
$settings_result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('active_academic_year', 'active_semester')");
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
$active_year = $settings['active_academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
$active_semester = $settings['active_semester'] ?? '1st Semester';

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

$total_docs = $stats['approved'] + $stats['pending'] + $stats['revision'];
if($total_docs > 0) {
    $stats['completion_rate'] = round(($stats['approved'] / $total_docs) * 100);
}

// Fetch Upcoming Deadlines
$deadlines = [];
$sql_deadlines = "SELECT ts.deadline, dt.doc_code, dt.description 
                  FROM target_submissions ts 
                  JOIN document_types dt ON ts.doc_type_id = dt.doc_type_id 
                  WHERE ts.year = ? AND ts.deadline >= CURDATE() 
                  ORDER BY ts.deadline ASC LIMIT 3";
$stmt_deadlines = $conn->prepare($sql_deadlines);
$stmt_deadlines->bind_param("i", $target_year);
$stmt_deadlines->execute();
$res_deadlines = $stmt_deadlines->get_result();
while($row = $res_deadlines->fetch_assoc()) {
    $deadlines[] = $row;
}
$stmt_deadlines->close();

// Fetch Revision Inbox
$revisions = [];
$sql_revisions = "SELECT d.document_id, d.file_name, dt.doc_code, dr.remarks, dr.reviewed_at 
                  FROM documents d
                  JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
                  LEFT JOIN document_reviews dr ON d.document_id = dr.document_id
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
