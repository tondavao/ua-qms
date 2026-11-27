<?php
/**
 * UA QMS Multi-Factor Authentication (MFA) Helper
 * - Pure PHP RFC 6238 TOTP (Google Authenticator / Microsoft Authenticator / Authy)
 * - Email OTP fallback (SMTP / Mailpit / PHP mail() with local dev preview fallback)
 * - New/Different Device detection via HttpOnly Trusted Device Cookie (SHA-256 hashed in DB)
 * - Single-use hashed Backup Recovery Codes
 */

require_once __DIR__ . '/schema_helpers.php';
require_once __DIR__ . '/system_settings.php';

define('UA_QMS_TRUST_COOKIE', 'ua_qms_trusted_device');
define('UA_QMS_TRUST_DAYS', 30);

// ---------------------------------------------------------------------------
// Base32 & TOTP (RFC 4648 / RFC 6238)
// ---------------------------------------------------------------------------

function ua_qms_base32_encode($binary) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($binary);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
    }
    $encoded = '';
    $bitLen = strlen($bits);
    for ($i = 0; $i < $bitLen; $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $encoded .= $alphabet[bindec($chunk)];
    }
    return $encoded;
}

function ua_qms_base32_decode($base32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', (string)$base32));
    $bits = '';
    $len = strlen($clean);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos($alphabet, $clean[$i]);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $binary = '';
    $bitLen = strlen($bits);
    for ($i = 0; $i + 8 <= $bitLen; $i += 8) {
        $binary .= chr(bindec(substr($bits, $i, 8)));
    }
    return $binary;
}

function ua_qms_generate_totp_secret($bytes = 20) {
    return ua_qms_base32_encode(random_bytes($bytes));
}

function ua_qms_get_totp_code($secret, $timeSlice = null) {
    if ($timeSlice === null) {
        $timeSlice = (int)floor(time() / 30);
    }
    $secretKey = ua_qms_base32_decode($secret);
    $time = pack('N*', 0, $timeSlice);
    $hash = hash_hmac('sha1', $time, $secretKey, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $part = substr($hash, $offset, 4);
    $value = unpack('N', $part)[1] & 0x7FFFFFFF;
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function ua_qms_verify_totp($secret, $code, $discrepancy = 1) {
    $cleanCode = preg_replace('/\D/', '', (string)$code);
    if (strlen($cleanCode) !== 6 || empty($secret)) {
        return false;
    }
    $currentSlice = (int)floor(time() / 30);
    for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
        $calc = ua_qms_get_totp_code($secret, $currentSlice + $i);
        if (hash_equals($calc, $cleanCode)) {
            return true;
        }
    }
    return false;
}

function ua_qms_build_otpauth_uri($email, $secret, $issuer = 'UA QMS') {
    $label = rawurlencode($issuer . ':' . $email);
    return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

// ---------------------------------------------------------------------------
// Backup Recovery Codes
// ---------------------------------------------------------------------------

function ua_qms_generate_backup_codes($count = 8) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < $count; $i++) {
        $part1 = '';
        $part2 = '';
        for ($j = 0; $j < 4; $j++) {
            $part1 .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            $part2 .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = $part1 . '-' . $part2;
        $plain[] = $code;
        $hashed[] = password_hash(str_replace('-', '', $code), PASSWORD_DEFAULT);
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}

function ua_qms_consume_backup_code($conn, $user_id, $input_code) {
    $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$input_code));
    if (strlen($clean) < 6) {
        return false;
    }
    $stmt = $conn->prepare("SELECT backup_codes_json FROM user_mfa WHERE user_id = ?");
    if (!$stmt) return false;
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['backup_codes_json'])) {
        return false;
    }
    $hashes = json_decode($row['backup_codes_json'], true);
    if (!is_array($hashes)) return false;

    foreach ($hashes as $idx => $hash) {
        if (password_verify($clean, $hash)) {
            array_splice($hashes, $idx, 1);
            $newJson = json_encode(array_values($hashes));
            $upd = $conn->prepare("UPDATE user_mfa SET backup_codes_json = ? WHERE user_id = ?");
            if ($upd) {
                $upd->bind_param("si", $newJson, $user_id);
                $upd->execute();
                $upd->close();
            }
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Global Policy & User MFA Record
// ---------------------------------------------------------------------------

function ua_qms_get_mfa_policy($conn) {
    $keys = ['mfa_require_all', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name'];
    $in = "'" . implode("','", $keys) . "'";
    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
    $map = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[$row['setting_key']] = $row['setting_value'];
        }
    }
    return [
        'mfa_require_all' => ($map['mfa_require_all'] ?? '0') === '1',
        'smtp_host'       => trim((string)($map['smtp_host'] ?? (getenv('SMTP_HOST') ?: ''))),
        'smtp_port'       => (int)($map['smtp_port'] ?? (getenv('SMTP_PORT') ?: 587)),
        'smtp_user'       => trim((string)($map['smtp_user'] ?? (getenv('SMTP_USER') ?: ''))),
        'smtp_pass'       => (string)($map['smtp_pass'] ?? (getenv('SMTP_PASS') ?: '')),
        'smtp_encryption' => trim((string)($map['smtp_encryption'] ?? (getenv('SMTP_ENCRYPTION') ?: 'tls'))),
        'smtp_from_email' => trim((string)($map['smtp_from_email'] ?? (getenv('SMTP_FROM_EMAIL') ?: 'no-reply@antiquespride.edu.ph'))),
        'smtp_from_name'  => trim((string)($map['smtp_from_name'] ?? 'UA QMS Security')),
    ];
}

function ua_qms_get_user_mfa($conn, $user_id) {
    ua_qms_ensure_mfa_tables($conn);
    $stmt = $conn->prepare("SELECT * FROM user_mfa WHERE user_id = ?");
    if (!$stmt) return null;
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ---------------------------------------------------------------------------
// Trusted Device Recognition (triggers MFA only on new/different devices)
// ---------------------------------------------------------------------------

function ua_qms_parse_device_name($ua = null) {
    $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser');
    $browser = 'Browser';
    if (stripos($ua, 'Edg/') !== false) $browser = 'Microsoft Edge';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) $browser = 'Opera';
    elseif (stripos($ua, 'Chrome/') !== false) $browser = 'Google Chrome';
    elseif (stripos($ua, 'Firefox/') !== false) $browser = 'Mozilla Firefox';
    elseif (stripos($ua, 'Safari/') !== false) $browser = 'Safari';

    $os = 'Unknown OS';
    if (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'iPhone') !== false) $os = 'iPhone';
    elseif (stripos($ua, 'iPad') !== false) $os = 'iPad';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'Mac OS') !== false || stripos($ua, 'Macintosh') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

    return "$browser on $os";
}

function ua_qms_get_current_device_token_hash() {
    $raw = $_COOKIE[UA_QMS_TRUST_COOKIE] ?? '';
    if (!is_string($raw) || strlen($raw) < 32) {
        return null;
    }
    return hash('sha256', $raw);
}

function ua_qms_is_trusted_device($conn, $user_id) {
    ua_qms_ensure_mfa_tables($conn);
    $tokenHash = ua_qms_get_current_device_token_hash();
    if (!$tokenHash) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT device_id FROM user_trusted_devices
        WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("is", $user_id, $tokenHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $devId = (int)$row['device_id'];
        $upd = $conn->prepare("UPDATE user_trusted_devices SET last_used_at = NOW(), ip_address = ? WHERE device_id = ?");
        if ($upd) {
            $upd->bind_param("si", $ip, $devId);
            $upd->execute();
            $upd->close();
        }
        return true;
    }
    return false;
}

function ua_qms_trust_current_device($conn, $user_id, $days = UA_QMS_TRUST_DAYS) {
    ua_qms_ensure_mfa_tables($conn);

    // If current cookie already matches a valid trusted device for this user, extend it
    $existingHash = ua_qms_get_current_device_token_hash();
    if ($existingHash) {
        $chk = $conn->prepare("SELECT device_id FROM user_trusted_devices WHERE user_id = ? AND token_hash = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("is", $user_id, $existingHash);
            $chk->execute();
            $found = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($found) {
                $daysInt = max(1, (int)$days);
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $devId = (int)$found['device_id'];
                $upd = $conn->prepare("UPDATE user_trusted_devices SET expires_at = DATE_ADD(NOW(), INTERVAL ? DAY), last_used_at = NOW(), ip_address = ? WHERE device_id = ?");
                if ($upd) {
                    $upd->bind_param("isi", $daysInt, $ip, $devId);
                    $upd->execute();
                    $upd->close();
                }
                return true;
            }
        }
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $deviceName = ua_qms_parse_device_name();
    $uaHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $daysInt = max(1, (int)$days);

    $stmt = $conn->prepare("
        INSERT INTO user_trusted_devices (user_id, token_hash, device_name, user_agent_hash, ip_address, expires_at)
        VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))
    ");
    if (!$stmt) return false;
    $stmt->bind_param("issssi", $user_id, $tokenHash, $deviceName, $uaHash, $ip, $daysInt);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && !headers_sent()) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        setcookie(UA_QMS_TRUST_COOKIE, $rawToken, [
            'expires'  => time() + ($days * 86400),
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[UA_QMS_TRUST_COOKIE] = $rawToken;
    }

    return $ok;
}

function ua_qms_login_requires_mfa($conn, $user_id) {
    ua_qms_ensure_mfa_tables($conn);
    $policy = ua_qms_get_mfa_policy($conn);
    $mfa = ua_qms_get_user_mfa($conn, $user_id);

    $userEnabled = $mfa && (int)$mfa['mfa_enabled'] === 1;
    $mustVerify = $userEnabled || $policy['mfa_require_all'];

    if (!$mustVerify) {
        return false;
    }

    // Only prompt for MFA when accessing from an untrusted / different device
    if (ua_qms_is_trusted_device($conn, $user_id)) {
        return false;
    }

    return true;
}

// ---------------------------------------------------------------------------
// Email OTP Generation, Delivery (Pure PHP SMTP / Mailpit / mail()), & Verify
// ---------------------------------------------------------------------------

function ua_qms_mask_email($email) {
    $parts = explode('@', (string)$email);
    if (count($parts) !== 2) return '***';
    $local = $parts[0];
    $domain = $parts[1];
    $visible = substr($local, 0, min(2, strlen($local)));
    return $visible . str_repeat('*', max(3, strlen($local) - strlen($visible))) . '@' . $domain;
}

function ua_qms_is_local_environment() {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    $host = preg_replace('/:\d+$/', '', $host);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.local');
}

function ua_qms_smtp_send_raw($host, $port, $user, $pass, $encryption, $fromEmail, $fromName, $toEmail, $subject, $bodyText) {
    $remote = ($encryption === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $fp = @stream_socket_client($remote, $errno, $errstr, 8);
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 8);

    $read = function() use ($fp) {
        $resp = '';
        while ($line = fgets($fp, 515)) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $resp;
    };
    $cmd = function($c) use ($fp, $read) {
        fwrite($fp, $c . "\r\n");
        return $read();
    };

    $greet = $read();
    if (strpos($greet, '220') !== 0) { fclose($fp); return false; }

    $ehlo = $cmd("EHLO localhost");
    if ($encryption === 'tls' && stripos($ehlo, 'STARTTLS') !== false) {
        $start = $cmd("STARTTLS");
        if (strpos($start, '220') !== 0) { fclose($fp); return false; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        $cmd("EHLO localhost");
    }

    if ($user !== '' && $pass !== '') {
        $auth = $cmd("AUTH LOGIN");
        if (strpos($auth, '334') !== 0) { fclose($fp); return false; }
        $cmd(base64_encode($user));
        $authRes = $cmd(base64_encode($pass));
        if (strpos($authRes, '235') !== 0) { fclose($fp); return false; }
    }

    $mFrom = $cmd("MAIL FROM:<{$fromEmail}>");
    if (strpos($mFrom, '250') !== 0) { fclose($fp); return false; }
    $rTo = $cmd("RCPT TO:<{$toEmail}>");
    if (strpos($rTo, '250') !== 0 && strpos($rTo, '251') !== 0) { fclose($fp); return false; }
    $dataRes = $cmd("DATA");
    if (strpos($dataRes, '354') !== 0) { fclose($fp); return false; }

    $headers = [
        "From: {$fromName} <{$fromEmail}>",
        "To: <{$toEmail}>",
        "Subject: {$subject}",
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Date: " . date('r'),
    ];
    $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $bodyText) . "\r\n.";
    $endRes = $cmd($payload);
    $cmd("QUIT");
    fclose($fp);

    return strpos($endRes, '250') === 0;
}

function ua_qms_send_email_otp($conn, $user_id, $email, $full_name = '') {
    ua_qms_ensure_mfa_tables($conn);

    // Invalidate previous unused OTPs for this user
    $del = $conn->prepare("UPDATE user_mfa_otps SET used = 1 WHERE user_id = ? AND used = 0");
    if ($del) {
        $del->bind_param("i", $user_id);
        $del->execute();
        $del->close();
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);

    $ins = $conn->prepare("INSERT INTO user_mfa_otps (user_id, code_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
    if (!$ins) {
        return ['success' => false, 'error' => 'Unable to generate verification code.'];
    }
    $ins->bind_param("is", $user_id, $codeHash);
    $ins->execute();
    $ins->close();

    $deviceName = ua_qms_parse_device_name();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $subject = "UA QMS Verification Code: {$code}";
    $body = "Hello " . ($full_name ?: 'User') . ",\r\n\r\n"
          . "A sign-in attempt was detected from a new device ({$deviceName}, IP: {$ip}).\r\n\r\n"
          . "Your 6-digit verification code is: {$code}\r\n\r\n"
          . "This code expires in 10 minutes. If you did not attempt to sign in, please change your password immediately.\r\n\r\n"
          . "— University of Antique Quality Management Office";

    $policy = ua_qms_get_mfa_policy($conn);
    $sent = false;
    $channel = 'none';

    // 1. Try configured SMTP server
    if ($policy['smtp_host'] !== '') {
        $sent = ua_qms_smtp_send_raw(
            $policy['smtp_host'],
            $policy['smtp_port'] ?: 587,
            $policy['smtp_user'],
            $policy['smtp_pass'],
            $policy['smtp_encryption'],
            $policy['smtp_from_email'],
            $policy['smtp_from_name'],
            $email,
            $subject,
            $body
        );
        if ($sent) $channel = 'smtp';
    }

    // 2. On local Laragon, try local Mailpit (127.0.0.1:1025) if configured SMTP wasn't used
    if (!$sent && ua_qms_is_local_environment()) {
        $sent = ua_qms_smtp_send_raw(
            '127.0.0.1',
            1025,
            '',
            '',
            'none',
            $policy['smtp_from_email'],
            $policy['smtp_from_name'],
            $email,
            $subject,
            $body
        );
        if ($sent) $channel = 'mailpit';
    }

    // 3. Fallback to PHP mail() on production servers
    if (!$sent && !ua_qms_is_local_environment()) {
        $headers = "From: {$policy['smtp_from_name']} <{$policy['smtp_from_email']}>\r\nContent-Type: text/plain; charset=UTF-8";
        $sent = @mail($email, $subject, $body, $headers);
        if ($sent) $channel = 'mail';
    }

    return [
        'success'  => true,
        'sent'     => $sent,
        'channel'  => $sent ? $channel : (ua_qms_is_local_environment() ? 'local_preview' : 'failed'),
        'masked'   => ua_qms_mask_email($email),
        // Only expose code for local Laragon testing when SMTP is not configured
        'dev_code' => (!$sent && ua_qms_is_local_environment()) ? $code : null,
    ];
}

function ua_qms_verify_email_otp($conn, $user_id, $input_code) {
    ua_qms_ensure_mfa_tables($conn);
    $clean = preg_replace('/\D/', '', (string)$input_code);
    if (strlen($clean) !== 6) {
        return ['valid' => false, 'error' => 'Please enter a valid 6-digit code.'];
    }

    $stmt = $conn->prepare("
        SELECT otp_id, code_hash, attempts, expires_at
        FROM user_mfa_otps
        WHERE user_id = ? AND used = 0 AND expires_at > NOW()
        ORDER BY otp_id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return ['valid' => false, 'error' => 'Verification lookup failed.'];
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['valid' => false, 'error' => 'Verification code expired or not found. Please request a new code.'];
    }

    $otpId = (int)$row['otp_id'];
    if ((int)$row['attempts'] >= 5) {
        $conn->query("UPDATE user_mfa_otps SET used = 1 WHERE otp_id = $otpId");
        return ['valid' => false, 'error' => 'Too many failed attempts. Please request a new code.'];
    }

    if (password_verify($clean, $row['code_hash'])) {
        $conn->query("UPDATE user_mfa_otps SET used = 1 WHERE otp_id = $otpId");
        return ['valid' => true];
    }

    $conn->query("UPDATE user_mfa_otps SET attempts = attempts + 1 WHERE otp_id = $otpId");
    return ['valid' => false, 'error' => 'Incorrect verification code. Please try again.'];
}
?>
