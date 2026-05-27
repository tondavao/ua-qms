<?php
require_once __DIR__ . '/../config/db.php';

// Exact list from user: Code => [Name, CategoryName]
$user_offices = [
    'ADM' => ['Administration and Finance Office', 'Non-Academic'],
    'ALU' => ['Alumni Unit', 'Non-Academic'],
    'ARU' => ['Admission/Registrarship Unit', 'Non-Academic'],
    'AU' => ['Accounting Unit', 'Non-Academic'],
    'BOU' => ['Business Operations Unit', 'Non-Academic'],
    'BU' => ['Budget Unit', 'Non-Academic'],
    'CBM' => ['College of Business and Management', 'Academic'],
    'CCS' => ['College of Computing and Information Sciences', 'Academic'],
    'CD' => ['Campus Director/Administration', 'Non-Academic'],
    'CEU' => ['Continuing Education Unit', 'Non-Academic'],
    'CJP' => ['Career and Job Placement Unit', 'Non-Academic'],
    'COF' => ['College of Fisheries', 'Academic'],
    'CSS' => ['Civil Security Services Unit', 'Non-Academic'],
    'CTE' => ['College of Teacher Education', 'Academic'],
    'CU' => ['Cash Unit', 'Non-Academic'],
    'CUL' => ['Cultural Unit', 'Non-Academic'],
    'DAA' => ['Academic Affairs Office', 'Non-Academic'],
    'EAU' => ['External Affairs Unit', 'Non-Academic'],
    'EXU' => ['Extension Unit', 'Non-Academic'],
    'GAD' => ['Gender and Development Unit', 'Non-Academic'],
    'GU' => ['Guidance Unit', 'Non-Academic'],
    'HRU' => ['Human Resource Unit', 'Non-Academic'],
    'HU' => ['Health Unit', 'Non-Academic'],
    'IAFF' => ['International Affairs Unit', 'Non-Academic'],
    'ICTU' => ['Information and Communications Technology Unit', 'Non-Academic'],
    'IPU' => ['Innovation Promotion Unit', 'Non-Academic'],
    'IU' => ['Information Unit', 'Non-Academic'],
    'LHS' => ['Laboratory High School', 'Academic'],
    'NST' => ['National Service Training Program Unit', 'Non-Academic'],
    'PLAN' => ['Planning Unit', 'Non-Academic'],
    'PMU' => ['Project Management Unit', 'Non-Academic'],
    'PU' => ['Procurement Unit', 'Non-Academic'],
    'QMO' => ['Quality Management Office', 'Non-Academic'],
    'QUA' => ['Quality Assurance Unit', 'Non-Academic'],
    'REC' => ['Records Unit', 'Non-Academic'],
    'RESU' => ['Research Unit', 'Non-Academic'],
    'SASD' => ['Student Affairs Services Division', 'Non-Academic'],
    'SDIS' => ['Students Discipline Unit', 'Non-Academic'],
    'SPM' => ['Supply and Property Management Unit', 'Non-Academic'],
    'SU' => ['Sports Unit', 'Non-Academic'],
    'UL' => ['Library', 'Non-Academic'],
    'DREI' => ['Research Extension and Innovation', 'Non-Academic']
];

// Fetch categories mapping
$cat_res = $conn->query("SELECT category_id, category_name FROM unit_categories");
$categories = [];
while ($row = $cat_res->fetch_assoc()) {
    $categories[$row['category_name']] = $row['category_id'];
}

echo "Syncing offices...\n";

foreach ($user_offices as $code => $info) {
    $name = $info[0];
    $cat_name = $info[1];
    $cat_id = $categories[$cat_name] ?? null;

    // Check if code exists
    $stmt = $conn->prepare("SELECT office_id FROM offices WHERE office_code = ?");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        // Update existing office
        $office = $res->fetch_assoc();
        $id = $office['office_id'];
        $update = $conn->prepare("UPDATE offices SET office_name = ?, category_id = ?, status = 'Active' WHERE office_id = ?");
        $update->bind_param("sii", $name, $cat_id, $id);
        $update->execute();
        echo "Updated office: $code - $name\n";
    } else {
        // Insert new office
        $insert = $conn->prepare("INSERT INTO offices (office_name, office_code, category_id, status) VALUES (?, ?, ?, 'Active')");
        $insert->bind_param("ssi", $name, $code, $cat_id);
        $insert->execute();
        echo "Inserted office: $code - $name\n";
    }
}

// Inactivate or delete offices not in the list
$codes_in_list = "'" . implode("','", array_keys($user_offices)) . "'";
$res_others = $conn->query("SELECT office_id, office_code, office_name FROM offices WHERE office_code NOT IN ($codes_in_list)");
while ($row = $res_others->fetch_assoc()) {
    $id = $row['office_id'];
    $code = $row['office_code'];
    $name = $row['office_name'];
    
    // Try to delete
    try {
        $conn->query("DELETE FROM offices WHERE office_id = $id");
        echo "Deleted obsolete office: $code - $name\n";
    } catch (Exception $e) {
        // If fails (due to FKeys), mark as Inactive
        $conn->query("UPDATE offices SET status = 'Inactive' WHERE office_id = $id");
        echo "Deactivated (FKey exists) obsolete office: $code - $name\n";
    }
}

echo "Office sync completed.\n";
?>
