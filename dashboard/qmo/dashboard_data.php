<?php
session_start();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../includes/system_settings.php";

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn->set_charset("utf8mb4");

function dashboard_quarter_bounds($year, $quarter) {
    switch ($quarter) {
        case '1st Quarter': return ["$year-01-01", "$year-03-31", 1, 3];
        case '2nd Quarter': return ["$year-04-01", "$year-06-30", 4, 6];
        case '3rd Quarter': return ["$year-07-01", "$year-09-30", 7, 9];
        case '4th Quarter': return ["$year-10-01", "$year-12-31", 10, 12];
        default: return ["$year-01-01", "$year-12-31", 1, 12];
    }
}

function normalize_year($value) {
    if (preg_match('/\d{4}/', (string)$value, $matches)) {
        return $matches[0];
    }
    return date('Y');
}

function fetch_rows($stmt) {
    $rows = [];
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

$period = ua_qms_get_active_period($conn);
$selected_year = $_GET['year'] ?? $period['active_year'];
$quarters = ['1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter'];
$selected_quarter = in_array(($_GET['quarter'] ?? ''), $quarters, true) ? $_GET['quarter'] : $period['active_semester'];
$selected_ay_range = $selected_year;

$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'revision' => 0, 'rejected' => 0];
$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'Awaiting Review' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'Revision' THEN 1 ELSE 0 END) AS revision,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected
    FROM documents
    WHERE academic_year = ? AND semester = ?
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$stmt->execute();
$stats = array_merge($stats, $stmt->get_result()->fetch_assoc() ?: []);
$stmt->close();

foreach ($stats as $key => $value) {
    $stats[$key] = (int)$value;
}

$avg_review_days = 0;
$stmt = $conn->prepare("
    SELECT ROUND(AVG(DATEDIFF(dr.reviewed_at, d.submitted_at)), 1) AS avg_review_days
    FROM document_reviews dr
    JOIN documents d ON dr.document_id = d.document_id
    WHERE dr.reviewed_at IS NOT NULL
        AND d.submitted_at IS NOT NULL
        AND d.academic_year = ?
        AND d.semester = ?
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$stmt->execute();
$avg_review_days = (float)($stmt->get_result()->fetch_assoc()['avg_review_days'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("
    SELECT d.document_id, d.file_name, d.submitted_at, o.office_name, o.office_code, dt.doc_code
    FROM documents d
    JOIN offices o ON d.office_id = o.office_id
    JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
    WHERE d.academic_year = ?
        AND d.semester = ?
        AND d.status = 'Awaiting Review'
    ORDER BY d.submitted_at ASC
    LIMIT 8
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$review_queue = fetch_rows($stmt);

$stmt = $conn->prepare("
    SELECT ts.deadline, ts.quarter, dt.doc_code, dt.description
    FROM target_submissions ts
    JOIN document_types dt ON ts.doc_type_id = dt.doc_type_id
    WHERE ts.year = ?
    ORDER BY ts.deadline ASC
    LIMIT 6
");
    $stmt->bind_param("s", $selected_year);
$deadlines = fetch_rows($stmt);

$stmt = $conn->prepare("
    SELECT
        dt.doc_code,
        dt.description,
        COUNT(DISTINCT o.office_id) AS required_offices,
        COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN o.office_id END) AS approved_offices,
        SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) AS submissions,
        SUM(CASE WHEN d.status = 'Awaiting Review' THEN 1 ELSE 0 END) AS pending_docs,
        SUM(CASE WHEN d.status = 'Revision' THEN 1 ELSE 0 END) AS revision_docs,
        SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_docs,
        ROUND(
            (
                COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN o.office_id END)
                / NULLIF(COUNT(DISTINCT o.office_id), 0)
            ) * 100
        ) AS compliance_rate
    FROM document_types dt
    CROSS JOIN offices o
    LEFT JOIN documents d
        ON d.doc_type_id = dt.doc_type_id
        AND d.office_id = o.office_id
        AND d.academic_year = ?
        AND d.semester = ?
    WHERE dt.required = 'Yes' AND o.status = 'Active' AND o.is_archived = FALSE
    GROUP BY dt.doc_type_id, dt.doc_code, dt.description
    ORDER BY compliance_rate ASC, (pending_docs + revision_docs + rejected_docs) DESC, dt.doc_code ASC
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$doc_type_rows = fetch_rows($stmt);

$stmt = $conn->prepare("
    SELECT
        o.office_name,
        o.office_code,
        COUNT(DISTINCT dt.doc_type_id) AS required_docs,
        COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN dt.doc_type_id END) AS approved_docs,
        SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) AS submissions,
        SUM(CASE WHEN d.status = 'Awaiting Review' THEN 1 ELSE 0 END) AS pending_docs,
        SUM(CASE WHEN d.status = 'Revision' THEN 1 ELSE 0 END) AS revision_docs,
        SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_docs,
        ROUND(
            (
                COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN dt.doc_type_id END)
                / NULLIF(COUNT(DISTINCT dt.doc_type_id), 0)
            ) * 100
        ) AS compliance_rate
    FROM offices o
    CROSS JOIN document_types dt
    LEFT JOIN documents d
        ON d.office_id = o.office_id
        AND d.doc_type_id = dt.doc_type_id
        AND d.academic_year = ?
        AND d.semester = ?
    WHERE o.status = 'Active' AND o.is_archived = FALSE AND dt.required = 'Yes'
    GROUP BY o.office_id, o.office_name, o.office_code
    ORDER BY compliance_rate ASC, (pending_docs + revision_docs + rejected_docs) DESC, o.office_name ASC
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$office_rows = fetch_rows($stmt);

$total_required_slots = 0;
$total_approved_slots = 0;
foreach ($office_rows as &$office) {
    foreach (['required_docs', 'approved_docs', 'submissions', 'pending_docs', 'revision_docs', 'rejected_docs', 'compliance_rate'] as $field) {
        $office[$field] = (int)($office[$field] ?? 0);
    }
    $total_required_slots += $office['required_docs'];
    $total_approved_slots += $office['approved_docs'];
}
unset($office);

foreach ($doc_type_rows as &$doc) {
    foreach (['required_offices', 'approved_offices', 'submissions', 'pending_docs', 'revision_docs', 'rejected_docs', 'compliance_rate'] as $field) {
        $doc[$field] = (int)($doc[$field] ?? 0);
    }
}
unset($doc);

$overall_compliance = $total_required_slots > 0 ? round(($total_approved_slots / $total_required_slots) * 100) : 0;
$approval_rate = $stats['total'] > 0 ? round(($stats['approved'] / $stats['total']) * 100) : 0;
$open_issues = $stats['pending'] + $stats['revision'] + $stats['rejected'];

echo json_encode([
    'success' => true,
    'year' => $selected_year,
    'quarter' => $selected_quarter,
    'stats' => $stats,
    'total' => $stats['total'],
    'pending' => $stats['pending'],
    'approved' => $stats['approved'],
    'revision' => $stats['revision'],
    'rejected' => $stats['rejected'],
    'has_submissions' => $stats['total'] > 0,
    'overall_compliance' => $overall_compliance,
    'approval_rate' => $approval_rate,
    'open_issues' => $open_issues,
    'avg_review_days' => $avg_review_days,
    'review_queue' => $review_queue,
    'deadlines' => $deadlines,
    'doc_type_rows' => $doc_type_rows,
    'office_rows' => $office_rows,
    'office_attention' => array_slice($office_rows, 0, 8),
]);
?>
