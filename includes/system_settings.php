<?php
/**
 * Shared helpers for reading and storing the active academic period.
 */

function ua_qms_upsert_system_setting($conn, $key, $value) {
    if (!is_object($conn)) {
        return false;
    }

    $stmt = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) " .
        "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );

    if (!$stmt) {
        return false;
    }

    $value = (string) ($value ?? '');
    $stmt->bind_param('ss', $key, $value);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function ua_qms_get_active_period($conn) {
    $settings = [];
    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('active_academic_year', 'active_semester')");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    $active_year = trim($settings['active_academic_year'] ?? '');
    if ($active_year === '' || !preg_match('/^\d{4}(-\d{4})?$/', $active_year)) {
        $year_res = $conn->query("SELECT year_range FROM academic_years WHERE is_active = 1 ORDER BY ay_id DESC LIMIT 1");
        if ($year_res && $year_res->num_rows > 0) {
            $active_year = trim($year_res->fetch_assoc()['year_range'] ?? '');
        }

        if ($active_year === '') {
            $active_year = date('Y') . '-' . (date('Y') + 1);
        }
    }

    $active_semester = trim($settings['active_semester'] ?? '');
    if ($active_semester === '') {
        $sem_res = $conn->query("SELECT semester_name FROM semesters WHERE is_active = 1 ORDER BY sem_id DESC LIMIT 1");
        if ($sem_res && $sem_res->num_rows > 0) {
            $active_semester = trim($sem_res->fetch_assoc()['semester_name'] ?? '');
        }

        if ($active_semester === '') {
            $active_semester = '1st Quarter';
        }
    }

    ua_qms_upsert_system_setting($conn, 'active_academic_year', $active_year);
    ua_qms_upsert_system_setting($conn, 'active_semester', $active_semester);

    return [
        'active_year' => $active_year,
        'active_semester' => $active_semester,
    ];
}
?>
