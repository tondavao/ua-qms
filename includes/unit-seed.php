<?php

function ua_qms_default_units() {
    return [
        "Administration and Finance Office",
        "Alumni Unit",
        "Admission/Registrarship Unit",
        "Accounting Unit",
        "Business Operations Unit",
        "Budget Unit",
        "College of Business and Management",
        "College of Computing and Information Sciences",
        "Campus Director/Administration",
        "Continuing Education Unit",
        "Career and Job Placement Unit",
        "College of Fisheries",
        "Civil Security Services Unit",
        "College of Teacher Education",
        "Cash Unit",
        "Cultural Unit",
        "Academic Affairs Office",
        "External Affairs Unit",
        "Extension Unit",
        "Gender and Development Unit",
        "Guidance Unit",
        "Human Resource Unit",
        "Health Unit",
        "International Affairs Unit",
        "Information and Communications Technology Unit",
        "Innovation Promotion Unit",
        "Information Unit",
        "Laboratory High School",
        "National Service Training Program Unit",
        "Planning Unit",
        "Project Management Unit",
        "Procurement Unit",
        "Quality Management Office",
        "Quality Assurance Unit",
        "Records Unit",
        "Research Unit",
        "Student Affairs Services Division",
        "Students Discipline Unit",
        "Supply and Property Management Unit",
        "Sports Unit",
        "Library",
        "Research Extension and Innovation"
    ];
}

function ua_qms_unit_code($unit_name) {
    $codes = [
        "Administration and Finance Office" => "ADM",
        "Alumni Unit" => "ALU",
        "Admission/Registrarship Unit" => "ARU",
        "Accounting Unit" => "AU",
        "Business Operations Unit" => "BOU",
        "Budget Unit" => "BU",
        "College of Business and Management" => "CBM",
        "College of Computing and Information Sciences" => "CCS",
        "Campus Director/Administration" => "CD",
        "Continuing Education Unit" => "CEU",
        "Career and Job Placement Unit" => "CJP",
        "College of Fisheries" => "COF",
        "Civil Security Services Unit" => "CSS",
        "College of Teacher Education" => "CTE",
        "Cash Unit" => "CU",
        "Cultural Unit" => "CUL",
        "Academic Affairs Office" => "DAA",
        "External Affairs Unit" => "EAU",
        "Extension Unit" => "EXU",
        "Gender and Development Unit" => "GAD",
        "Guidance Unit" => "GU",
        "Human Resource Unit" => "HRU",
        "Health Unit" => "HU",
        "International Affairs Unit" => "IAFF",
        "Information and Communications Technology Unit" => "ICTU",
        "Innovation Promotion Unit" => "IPU",
        "Information Unit" => "IU",
        "Laboratory High School" => "LHS",
        "National Service Training Program Unit" => "NST",
        "Planning Unit" => "PLAN",
        "Project Management Unit" => "PMU",
        "Procurement Unit" => "PU",
        "Quality Management Office" => "QMO",
        "Quality Assurance Unit" => "QUA",
        "Records Unit" => "REC",
        "Research Unit" => "RESU",
        "Student Affairs Services Division" => "SASD",
        "Students Discipline Unit" => "SDIS",
        "Supply and Property Management Unit" => "SPM",
        "Sports Unit" => "SU",
        "Library" => "UL",
        "Research Extension and Innovation" => "DREI"
    ];

    if (isset($codes[$unit_name])) {
        return $codes[$unit_name];
    }

    $words = preg_split('/[^A-Za-z0-9]+/', $unit_name, -1, PREG_SPLIT_NO_EMPTY);
    $code = '';
    foreach ($words as $word) {
        if (in_array(strtolower($word), ['and', 'of', 'the'], true)) {
            continue;
        }
        $code .= strtoupper(substr($word, 0, 1));
    }
    return substr($code ?: strtoupper(substr($unit_name, 0, 3)), 0, 12);
}

function ua_qms_legacy_unit_names($unit_name) {
    $legacy = [
        "College of Computing and Information Sciences" => [
            "College of Computer Studies",
            "College of Computing"
        ]
    ];
    return $legacy[$unit_name] ?? [];
}

function ua_qms_seed_default_units($conn) {
    // Only seed if the offices table is completely empty to prevent overwriting user modifications
    $count_res = $conn->query("SELECT COUNT(*) FROM offices");
    if ($count_res) {
        $count_row = $count_res->fetch_row();
        if ($count_row && $count_row[0] > 0) {
            return;
        }
    }

    $stmt_check = $conn->prepare("SELECT office_id, office_code FROM offices WHERE office_name = ? LIMIT 1");
    $stmt_legacy = $conn->prepare("SELECT office_id, office_name, office_code FROM offices WHERE office_name = ? LIMIT 1");
    $stmt_update_code = $conn->prepare("UPDATE offices SET office_code = ? WHERE office_id = ?");
    $stmt_update_name_and_code = $conn->prepare("UPDATE offices SET office_name = ?, office_code = ? WHERE office_id = ?");
    $stmt_insert = $conn->prepare("INSERT INTO offices (office_name, office_code, status) VALUES (?, ?, 'Active')");

    foreach (ua_qms_default_units() as $unit) {
        $code = ua_qms_unit_code($unit);

        $stmt_check->bind_param("s", $unit);
        $stmt_check->execute();
        $result = $stmt_check->get_result();

        if ($row = $result->fetch_assoc()) {
            if ($row['office_code'] !== $code) {
                $stmt_update_code->bind_param("si", $code, $row['office_id']);
                $stmt_update_code->execute();
            }
            continue;
        }

        foreach (ua_qms_legacy_unit_names($unit) as $legacy_name) {
            $stmt_legacy->bind_param("s", $legacy_name);
            $stmt_legacy->execute();
            $legacy_result = $stmt_legacy->get_result();

            if ($legacy_row = $legacy_result->fetch_assoc()) {
                $stmt_update_name_and_code->bind_param("ssi", $unit, $code, $legacy_row['office_id']);
                $stmt_update_name_and_code->execute();
                continue 2;
            }
        }

        $stmt_insert->bind_param("ss", $unit, $code);
        $stmt_insert->execute();
    }
}

?>
