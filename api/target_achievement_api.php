<?php
/**
 * Target Achievement API
 * Feature 3: Target achievement display (e.g., 1/8) per office
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

// Get target achievement for an office (e.g., 1/8 format)
if ($action === 'get_office_achievement') {
    $office_id = $_GET['office_id'] ?? null;
    $quarter = $_GET['quarter'] ?? null;
    $year = $_GET['year'] ?? date('Y');

    if (!$office_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing office_id']);
        exit();
    }

    // Get all required document types
    $sql_targets = "SELECT dt.doc_type_id, dt.doc_code, dt.description, dt.required, dt.frequency,
                           ta.target_count, ta.achieved_count, ta.achievement_date
                    FROM document_types dt
                    LEFT JOIN target_achievements ta ON dt.doc_type_id = ta.doc_type_id 
                                                      AND ta.office_id = ? 
                                                      AND ta.quarter = ? 
                                                      AND ta.year = ?
                    WHERE dt.required = 'Yes'
                    ORDER BY dt.doc_type_id";
    
    $stmt = $conn->prepare($sql_targets);
    $stmt->bind_param("iss", $office_id, $quarter, $year);
    $stmt->execute();
    $result = $stmt->get_result();

    $targets = [];
    $total_target = 0;
    $total_achieved = 0;

    while ($row = $result->fetch_assoc()) {
        $target_count = $row['target_count'] ?? 1;
        $achieved_count = $row['achieved_count'] ?? 0;
        
        $total_target += $target_count;
        $total_achieved += $achieved_count;

        $targets[] = [
            'doc_type_id' => $row['doc_type_id'],
            'doc_code' => $row['doc_code'],
            'description' => $row['description'],
            'target' => $target_count,
            'achieved' => $achieved_count,
            'achievement_date' => $row['achievement_date'],
            'status' => $achieved_count >= $target_count ? 'completed' : 'pending'
        ];
    }

    // Format as "achieved/target" (e.g., "1/8")
    $achievement_display = "$total_achieved/$total_target";
    $completion_percentage = $total_target > 0 ? round(($total_achieved / $total_target) * 100) : 0;

    echo json_encode([
        'success' => true,
        'office_id' => $office_id,
        'quarter' => $quarter,
        'year' => $year,
        'achievement_display' => $achievement_display,
        'total_target' => $total_target,
        'total_achieved' => $total_achieved,
        'completion_percentage' => $completion_percentage,
        'targets' => $targets
    ]);
    exit();
}

// Get achievements for all offices (dashboard view)
if ($action === 'get_all_office_achievements') {
    $quarter = $_GET['quarter'] ?? null;
    $year = $_GET['year'] ?? date('Y');

    $sql = "SELECT o.office_id, o.office_name, o.office_code,
                   COUNT(DISTINCT ta.doc_type_id) as total_docs,
                   SUM(ta.achieved_count) as total_achieved,
                   SUM(ta.target_count) as total_target
            FROM offices o
            LEFT JOIN target_achievements ta ON o.office_id = ta.office_id 
                                              AND ta.quarter = ? 
                                              AND ta.year = ?
            WHERE o.status = 'Active'
            GROUP BY o.office_id, o.office_name, o.office_code
            ORDER BY (SUM(ta.achieved_count) / SUM(ta.target_count)) DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $quarter, $year);
    $stmt->execute();
    $result = $stmt->get_result();

    $achievements = [];
    while ($row = $result->fetch_assoc()) {
        $total_target = $row['total_target'] ?? 0;
        $total_achieved = $row['total_achieved'] ?? 0;
        $completion = $total_target > 0 ? round(($total_achieved / $total_target) * 100) : 0;

        $achievements[] = [
            'office_id' => $row['office_id'],
            'office_name' => $row['office_name'],
            'office_code' => $row['office_code'],
            'achievement_display' => "$total_achieved/$total_target",
            'total_achieved' => $total_achieved,
            'total_target' => $total_target,
            'completion_percentage' => $completion
        ];
    }

    echo json_encode([
        'success' => true,
        'quarter' => $quarter,
        'year' => $year,
        'achievements' => $achievements
    ]);
    exit();
}

// Update target achievement
if ($action === 'update_achievement') {
    if ($role !== 'QMO Admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only QMO Admin can update achievements']);
        exit();
    }

    $office_id = $_POST['office_id'] ?? null;
    $doc_type_id = $_POST['doc_type_id'] ?? null;
    $quarter = $_POST['quarter'] ?? null;
    $year = $_POST['year'] ?? date('Y');
    $achieved_count = $_POST['achieved_count'] ?? 0;

    if (!$office_id || !$doc_type_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }

    // Check if record exists
    $sql_check = "SELECT achievement_id FROM target_achievements 
                  WHERE office_id = ? AND doc_type_id = ? AND quarter = ? AND year = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("iiss", $office_id, $doc_type_id, $quarter, $year);
    $stmt_check->execute();
    $check_result = $stmt_check->get_result();

    if ($check_result->num_rows > 0) {
        // Update existing
        $sql_update = "UPDATE target_achievements SET achieved_count = ?, achievement_date = NOW() 
                       WHERE office_id = ? AND doc_type_id = ? AND quarter = ? AND year = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("iisss", $achieved_count, $office_id, $doc_type_id, $quarter, $year);
        $success = $stmt_update->execute();
    } else {
        // Insert new
        $target_count = 1;
        $sql_insert = "INSERT INTO target_achievements (office_id, doc_type_id, quarter, year, target_count, achieved_count, achievement_date) 
                       VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param("iissii", $office_id, $doc_type_id, $quarter, $year, $target_count, $achieved_count);
        $success = $stmt_insert->execute();
    }

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Achievement updated successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update achievement']);
    }
    exit();
}

// Feature 7: Get officer compliance count
if ($action === 'get_officer_compliance') {
    if ($role !== 'QMO Admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only QMO Admin can view officer compliance']);
        exit();
    }

    $office_id = $_GET['office_id'] ?? null;
    $quarter = $_GET['quarter'] ?? null;
    $year = $_GET['year'] ?? date('Y');

    if (!$office_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing office_id']);
        exit();
    }

    // Get all officers in the office
    $sql_officers = "SELECT u.user_id, u.full_name, u.email, u.role
                     FROM users u
                     WHERE u.office_id = ? AND u.role IN ('Dean', 'Office')
                     ORDER BY u.full_name";
    $stmt_officers = $conn->prepare($sql_officers);
    $stmt_officers->bind_param("i", $office_id);
    $stmt_officers->execute();
    $officers_result = $stmt_officers->get_result();

    $officers = [];
    $total_officers = 0;
    $complied_officers = 0;

    while ($officer = $officers_result->fetch_assoc()) {
        $total_officers++;
        $user_id_check = $officer['user_id'];

        // Check if officer has complied (submitted required documents)
        $sql_compliance = "SELECT COUNT(DISTINCT oc.doc_type_id) as complied_docs
                          FROM officer_compliance oc
                          WHERE oc.user_id = ? AND oc.office_id = ? AND oc.quarter = ? AND oc.year = ? AND oc.is_complied = 1";
        $stmt_compliance = $conn->prepare($sql_compliance);
        $stmt_compliance->bind_param("iiss", $user_id_check, $office_id, $quarter, $year);
        $stmt_compliance->execute();
        $compliance_result = $stmt_compliance->get_result();
        $compliance_row = $compliance_result->fetch_assoc();

        $is_complied = $compliance_row['complied_docs'] > 0;
        if ($is_complied) {
            $complied_officers++;
        }

        $officers[] = [
            'user_id' => $officer['user_id'],
            'full_name' => $officer['full_name'],
            'email' => $officer['email'],
            'role' => $officer['role'],
            'is_complied' => $is_complied,
            'complied_docs' => $compliance_row['complied_docs']
        ];
    }

    $compliance_percentage = $total_officers > 0 ? round(($complied_officers / $total_officers) * 100) : 0;

    echo json_encode([
        'success' => true,
        'office_id' => $office_id,
        'quarter' => $quarter,
        'year' => $year,
        'total_officers' => $total_officers,
        'complied_officers' => $complied_officers,
        'non_complied_officers' => $total_officers - $complied_officers,
        'compliance_percentage' => $compliance_percentage,
        'officers' => $officers
    ]);
    exit();
}

// Record officer compliance
if ($action === 'record_officer_compliance') {
    $user_id_target = $_POST['user_id'] ?? null;
    $office_id = $_POST['office_id'] ?? null;
    $doc_type_id = $_POST['doc_type_id'] ?? null;
    $quarter = $_POST['quarter'] ?? null;
    $year = $_POST['year'] ?? date('Y');

    if (!$user_id_target || !$office_id || !$doc_type_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }

    // Check if record exists
    $sql_check = "SELECT compliance_record_id FROM officer_compliance 
                  WHERE user_id = ? AND office_id = ? AND doc_type_id = ? AND quarter = ? AND year = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("iisss", $user_id_target, $office_id, $doc_type_id, $quarter, $year);
    $stmt_check->execute();
    $check_result = $stmt_check->get_result();

    if ($check_result->num_rows > 0) {
        // Update existing
        $sql_update = "UPDATE officer_compliance SET is_complied = 1, compliance_date = NOW() 
                       WHERE user_id = ? AND office_id = ? AND doc_type_id = ? AND quarter = ? AND year = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("iisss", $user_id_target, $office_id, $doc_type_id, $quarter, $year);
        $success = $stmt_update->execute();
    } else {
        // Insert new
        $sql_insert = "INSERT INTO officer_compliance (user_id, office_id, doc_type_id, quarter, year, is_complied, compliance_date) 
                       VALUES (?, ?, ?, ?, ?, 1, NOW())";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param("iisss", $user_id_target, $office_id, $doc_type_id, $quarter, $year);
        $success = $stmt_insert->execute();
    }

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Officer compliance recorded successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to record compliance']);
    }
    exit();
}

// Default response
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
