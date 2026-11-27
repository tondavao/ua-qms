<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mfa_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

ua_qms_ensure_mfa_tables($conn);

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

function mfaLog($conn, $user_id, $action_label, $details) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isss", $user_id, $action_label, $details, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

function verifyUserPassword($conn, $user_id, $plain_password) {
    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    if (!$stmt) return false;
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && password_verify((string)$plain_password, $row['password']);
}

switch ($action) {
    case 'get_status':
        $mfa = ua_qms_get_user_mfa($conn, $user_id);
        $policy = ua_qms_get_mfa_policy($conn);
        $currentHash = ua_qms_get_current_device_token_hash();

        $devices = [];
        $stmt_d = $conn->prepare("
            SELECT device_id, device_name, ip_address, last_used_at, expires_at, created_at, token_hash
            FROM user_trusted_devices
            WHERE user_id = ? AND expires_at > NOW()
            ORDER BY last_used_at DESC
        ");
        if ($stmt_d) {
            $stmt_d->bind_param("i", $user_id);
            $stmt_d->execute();
            $res_d = $stmt_d->get_result();
            while ($d = $res_d->fetch_assoc()) {
                $devices[] = [
                    'device_id'   => (int)$d['device_id'],
                    'device_name' => $d['device_name'],
                    'ip_address'  => $d['ip_address'],
                    'last_used_at'=> $d['last_used_at'],
                    'expires_at'  => $d['expires_at'],
                    'is_current'  => ($currentHash !== null && hash_equals($d['token_hash'], $currentHash)),
                ];
            }
            $stmt_d->close();
        }

        $backupCodes = ($mfa && !empty($mfa['backup_codes_json'])) ? (json_decode($mfa['backup_codes_json'], true) ?: []) : [];

        echo json_encode([
            'success' => true,
            'data' => [
                'mfa_enabled'            => $mfa ? ((int)$mfa['mfa_enabled'] === 1) : false,
                'totp_verified'          => $mfa ? ((int)$mfa['totp_verified'] === 1) : false,
                'preferred_method'       => $mfa['preferred_method'] ?? 'totp',
                'global_required'        => $policy['mfa_require_all'],
                'backup_codes_remaining' => count($backupCodes),
                'trusted_devices'        => $devices,
                'current_device_name'    => ua_qms_parse_device_name(),
            ]
        ]);
        break;

    case 'begin_totp_setup':
        $stmt_u = $conn->prepare("SELECT email FROM users WHERE user_id = ?");
        $stmt_u->bind_param("i", $user_id);
        $stmt_u->execute();
        $uRow = $stmt_u->get_result()->fetch_assoc();
        $stmt_u->close();

        $email = $uRow['email'] ?? 'user@antiquespride.edu.ph';
        $secret = ua_qms_generate_totp_secret(20);

        $stmt = $conn->prepare("
            INSERT INTO user_mfa (user_id, totp_secret, totp_verified)
            VALUES (?, ?, 0)
            ON DUPLICATE KEY UPDATE totp_secret = VALUES(totp_secret), totp_verified = 0
        ");
        $stmt->bind_param("is", $user_id, $secret);
        $stmt->execute();
        $stmt->close();

        echo json_encode([
            'success'     => true,
            'secret'      => $secret,
            'otpauth_uri' => ua_qms_build_otpauth_uri($email, $secret, 'UA QMS'),
            'email'       => $email,
        ]);
        break;

    case 'confirm_totp_setup':
        $code = trim((string)($input['code'] ?? ''));
        $mfa = ua_qms_get_user_mfa($conn, $user_id);
        if (!$mfa || empty($mfa['totp_secret'])) {
            echo json_encode(['success' => false, 'error' => 'Please start Authenticator setup first.']);
            break;
        }
        if (!ua_qms_verify_totp($mfa['totp_secret'], $code)) {
            echo json_encode(['success' => false, 'error' => 'Invalid 6-digit code. Check your authenticator app clock and try again.']);
            break;
        }

        $codes = ua_qms_generate_backup_codes(8);
        $backupJson = json_encode($codes['hashed']);

        $stmt = $conn->prepare("
            UPDATE user_mfa
            SET mfa_enabled = 1, totp_verified = 1, preferred_method = 'totp', backup_codes_json = ?
            WHERE user_id = ?
        ");
        $stmt->bind_param("si", $backupJson, $user_id);
        $stmt->execute();
        $stmt->close();

        // Automatically trust this setup device so user isn't locked out
        ua_qms_trust_current_device($conn, $user_id, UA_QMS_TRUST_DAYS);
        mfaLog($conn, $user_id, "Enabled MFA (Authenticator App)", "Configured TOTP authenticator and trusted current device.");

        echo json_encode([
            'success'      => true,
            'message'      => 'Authenticator App MFA enabled! This device is now trusted for 30 days.',
            'backup_codes' => $codes['plain'],
        ]);
        break;

    case 'enable_email_mfa':
        $mfa = ua_qms_get_user_mfa($conn, $user_id);
        $plainCodes = null;
        $existingBackup = ($mfa && !empty($mfa['backup_codes_json'])) ? $mfa['backup_codes_json'] : null;

        if (!$existingBackup) {
            $gen = ua_qms_generate_backup_codes(8);
            $existingBackup = json_encode($gen['hashed']);
            $plainCodes = $gen['plain'];
        }

        $stmt = $conn->prepare("
            INSERT INTO user_mfa (user_id, mfa_enabled, preferred_method, backup_codes_json)
            VALUES (?, 1, 'email', ?)
            ON DUPLICATE KEY UPDATE mfa_enabled = 1, preferred_method = 'email', backup_codes_json = COALESCE(backup_codes_json, VALUES(backup_codes_json))
        ");
        $stmt->bind_param("is", $user_id, $existingBackup);
        $stmt->execute();
        $stmt->close();

        ua_qms_trust_current_device($conn, $user_id, UA_QMS_TRUST_DAYS);
        mfaLog($conn, $user_id, "Enabled MFA (Email OTP)", "Enabled new-device Email OTP verification.");

        echo json_encode([
            'success'      => true,
            'message'      => 'Email OTP MFA enabled for new devices! This device is now trusted for 30 days.',
            'backup_codes' => $plainCodes,
        ]);
        break;

    case 'set_preferred_method':
        $method = ($input['method'] ?? '') === 'email' ? 'email' : 'totp';
        $mfa = ua_qms_get_user_mfa($conn, $user_id);
        if ($method === 'totp' && (!$mfa || (int)$mfa['totp_verified'] !== 1)) {
            echo json_encode(['success' => false, 'error' => 'Please set up an Authenticator App first.']);
            break;
        }
        $stmt = $conn->prepare("UPDATE user_mfa SET preferred_method = ? WHERE user_id = ?");
        $stmt->bind_param("si", $method, $user_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Preferred MFA method updated.']);
        break;

    case 'disable_mfa':
        $policy = ua_qms_get_mfa_policy($conn);
        if ($policy['mfa_require_all']) {
            echo json_encode(['success' => false, 'error' => 'MFA is currently required for all accounts by QMO Administrator policy.']);
            break;
        }
        $password = (string)($input['password'] ?? '');
        if (!verifyUserPassword($conn, $user_id, $password)) {
            echo json_encode(['success' => false, 'error' => 'Incorrect current password.']);
            break;
        }
        $stmt = $conn->prepare("UPDATE user_mfa SET mfa_enabled = 0, totp_verified = 0, totp_secret = NULL, backup_codes_json = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        mfaLog($conn, $user_id, "Disabled MFA", "User disabled MFA on their account.");
        echo json_encode(['success' => true, 'message' => 'Multi-Factor Authentication has been disabled.']);
        break;

    case 'regenerate_backup_codes':
        $password = (string)($input['password'] ?? '');
        if (!verifyUserPassword($conn, $user_id, $password)) {
            echo json_encode(['success' => false, 'error' => 'Incorrect current password.']);
            break;
        }
        $gen = ua_qms_generate_backup_codes(8);
        $json = json_encode($gen['hashed']);
        $stmt = $conn->prepare("UPDATE user_mfa SET backup_codes_json = ? WHERE user_id = ?");
        $stmt->bind_param("si", $json, $user_id);
        $stmt->execute();
        $stmt->close();
        mfaLog($conn, $user_id, "Regenerated MFA Backup Codes", "User generated new backup recovery codes.");
        echo json_encode(['success' => true, 'backup_codes' => $gen['plain'], 'message' => 'New backup recovery codes generated.']);
        break;

    case 'revoke_device':
        $device_id = (int)($input['device_id'] ?? 0);
        if (!$device_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid device ID.']);
            break;
        }
        $stmt = $conn->prepare("DELETE FROM user_trusted_devices WHERE device_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $device_id, $user_id);
        $stmt->execute();
        $stmt->close();
        mfaLog($conn, $user_id, "Revoked Trusted Device", "Revoked trusted device ID {$device_id}");
        echo json_encode(['success' => true, 'message' => 'Device removed. Next sign-in on that device will require MFA.']);
        break;

    case 'revoke_all_devices':
        $stmt = $conn->prepare("DELETE FROM user_trusted_devices WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        mfaLog($conn, $user_id, "Revoked All Trusted Devices", "Cleared all trusted devices for account.");
        echo json_encode(['success' => true, 'message' => 'All trusted devices cleared. Next sign-in on any device will require MFA.']);
        break;

    // --- QMO Admin Policy & Recovery Actions ---
    case 'get_admin_policy':
        if ($role !== 'QMO Admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            break;
        }
        $p = ua_qms_get_mfa_policy($conn);
        // Never expose SMTP password back to browser in plaintext
        $p['smtp_pass_set'] = $p['smtp_pass'] !== '';
        $p['smtp_pass'] = '';
        echo json_encode(['success' => true, 'data' => $p]);
        break;

    case 'update_admin_policy':
        if ($role !== 'QMO Admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            break;
        }
        $requireAll = !empty($input['mfa_require_all']) ? '1' : '0';
        ua_qms_upsert_system_setting($conn, 'mfa_require_all', $requireAll);

        if (isset($input['smtp_host'])) {
            ua_qms_upsert_system_setting($conn, 'smtp_host', trim((string)$input['smtp_host']));
            ua_qms_upsert_system_setting($conn, 'smtp_port', (string)((int)($input['smtp_port'] ?? 587)));
            ua_qms_upsert_system_setting($conn, 'smtp_user', trim((string)($input['smtp_user'] ?? '')));
            ua_qms_upsert_system_setting($conn, 'smtp_encryption', trim((string)($input['smtp_encryption'] ?? 'tls')));
            ua_qms_upsert_system_setting($conn, 'smtp_from_email', trim((string)($input['smtp_from_email'] ?? 'no-reply@antiquespride.edu.ph')));
            if (isset($input['smtp_pass']) && (string)$input['smtp_pass'] !== '') {
                ua_qms_upsert_system_setting($conn, 'smtp_pass', (string)$input['smtp_pass']);
            }
        }

        // If enabling global requirement, trust admin's current device automatically
        if ($requireAll === '1') {
            ua_qms_trust_current_device($conn, $user_id, UA_QMS_TRUST_DAYS);
        }

        mfaLog($conn, $user_id, "Updated MFA Policy", "Require MFA on new devices for all users: " . ($requireAll === '1' ? 'Enabled' : 'Disabled'));
        echo json_encode(['success' => true, 'message' => 'MFA security policy updated successfully.']);
        break;

    case 'test_smtp_email':
        if ($role !== 'QMO Admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            break;
        }
        $targetEmail = trim((string)($input['email'] ?? ''));
        if (!$targetEmail) {
            $resU = $conn->query("SELECT email, full_name FROM users WHERE user_id = $user_id");
            $u = $resU ? $resU->fetch_assoc() : null;
            $targetEmail = $u['email'] ?? '';
        }
        $res = ua_qms_send_email_otp($conn, $user_id, $targetEmail, $_SESSION['full_name'] ?? 'Admin');
        if (!empty($res['sent'])) {
            echo json_encode(['success' => true, 'message' => "Test OTP email delivered to {$targetEmail} via {$res['channel']}."]);
        } elseif (!empty($res['dev_code'])) {
            echo json_encode(['success' => true, 'message' => "SMTP not configured; local test preview code generated: {$res['dev_code']}"]);
        } else {
            echo json_encode(['success' => false, 'error' => "Could not deliver test email to {$targetEmail}. Check SMTP host, port, and credentials."]);
        }
        break;

    case 'admin_reset_user_mfa':
        if ($role !== 'QMO Admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            break;
        }
        $target_uid = (int)($input['user_id'] ?? 0);
        if (!$target_uid) {
            echo json_encode(['success' => false, 'error' => 'Missing user ID.']);
            break;
        }
        $stmt1 = $conn->prepare("DELETE FROM user_mfa WHERE user_id = ?");
        $stmt1->bind_param("i", $target_uid);
        $stmt1->execute();
        $stmt1->close();

        $stmt2 = $conn->prepare("DELETE FROM user_trusted_devices WHERE user_id = ?");
        $stmt2->bind_param("i", $target_uid);
        $stmt2->execute();
        $stmt2->close();

        mfaLog($conn, $user_id, "Admin Reset User MFA", "Reset MFA and trusted devices for user ID {$target_uid}");
        echo json_encode(['success' => true, 'message' => 'User MFA and trusted devices have been reset.']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
