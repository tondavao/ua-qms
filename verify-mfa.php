<?php
session_start();
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/includes/mfa_helper.php";

if (isset($_GET['cancel'])) {
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_email_status']);
    header("Location: index.php");
    exit();
}

$pending = $_SESSION['mfa_pending'] ?? null;
if (!$pending || empty($pending['user_id']) || (int)($pending['expires_at'] ?? 0) < time()) {
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_email_status']);
    header("Location: index.php");
    exit();
}

$uid = (int)$pending['user_id'];
$userMfa = ua_qms_get_user_mfa($conn, $uid);
$hasTotp = $userMfa && (int)$userMfa['totp_verified'] === 1 && !empty($userMfa['totp_secret']);
$backupList = ($userMfa && !empty($userMfa['backup_codes_json'])) ? (json_decode($userMfa['backup_codes_json'], true) ?: []) : [];
$hasBackup = count($backupList) > 0;

$defaultMethod = ($hasTotp && (($userMfa['preferred_method'] ?? 'totp') === 'totp')) ? 'totp' : 'email';
$activeMethod = $_POST['method'] ?? $_GET['method'] ?? $defaultMethod;
if (!in_array($activeMethod, ['totp', 'email', 'backup'], true)) {
    $activeMethod = $defaultMethod;
}
if ($activeMethod === 'totp' && !$hasTotp) {
    $activeMethod = 'email';
}
if ($activeMethod === 'backup' && !$hasBackup) {
    $activeMethod = $hasTotp ? 'totp' : 'email';
}

$error = "";
$info = "";
$devCode = null;
$maskedEmail = ua_qms_mask_email($pending['email']);
$deviceName = ua_qms_parse_device_name();

// Auto-send email OTP once if email method is active and none sent yet in this pending session
if ($activeMethod === 'email' && $_SERVER['REQUEST_METHOD'] === 'GET' && empty($_SESSION['mfa_email_status']['sent_at'])) {
    $sendRes = ua_qms_send_email_otp($conn, $uid, $pending['email'], $pending['full_name']);
    $_SESSION['mfa_email_status'] = [
        'sent_at'  => time(),
        'channel'  => $sendRes['channel'] ?? 'none',
        'sent'     => !empty($sendRes['sent']),
        'dev_code' => $sendRes['dev_code'] ?? null,
    ];
}

// Handle POST: Send/Resend Email OTP OR Verify Code
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_email_otp'])) {
        $activeMethod = 'email';
        $sendRes = ua_qms_send_email_otp($conn, $uid, $pending['email'], $pending['full_name']);
        $_SESSION['mfa_email_status'] = [
            'sent_at'  => time(),
            'channel'  => $sendRes['channel'] ?? 'none',
            'sent'     => !empty($sendRes['sent']),
            'dev_code' => $sendRes['dev_code'] ?? null,
        ];
        if (!empty($sendRes['sent'])) {
            $info = "A 6-digit verification code has been sent to {$maskedEmail}.";
        } elseif (!empty($sendRes['dev_code'])) {
            $info = "Local test mode (SMTP not configured): use the verification code shown below.";
        } else {
            $error = "Unable to deliver email OTP. Please configure SMTP in System Configuration or use your Authenticator App.";
        }
    } elseif (isset($_POST['verify_mfa'])) {
        $codeInput = trim($_POST['mfa_code'] ?? '');
        $trustDevice = isset($_POST['trust_device']);
        $verified = false;

        if ($activeMethod === 'totp') {
            if ($hasTotp && ua_qms_verify_totp($userMfa['totp_secret'], $codeInput)) {
                $verified = true;
            } else {
                $error = "Invalid 6-digit authenticator code. Please try again.";
            }
        } elseif ($activeMethod === 'email') {
            $chk = ua_qms_verify_email_otp($conn, $uid, $codeInput);
            if (!empty($chk['valid'])) {
                $verified = true;
            } else {
                $error = $chk['error'] ?? "Invalid verification code.";
            }
        } elseif ($activeMethod === 'backup') {
            if (ua_qms_consume_backup_code($conn, $uid, $codeInput)) {
                $verified = true;
            } else {
                $error = "Invalid or already used backup recovery code.";
            }
        }

        if ($verified) {
            if ($trustDevice) {
                ua_qms_trust_current_device($conn, $uid, UA_QMS_TRUST_DAYS);
            }

            // Log verified MFA login
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $logDetails = "MFA verified via {$activeMethod} on {$deviceName}" . ($trustDevice ? " (device trusted)" : "");
            $stmtLog = $conn->prepare("INSERT INTO system_logs (user_id, action, details, ip_address) VALUES (?, 'MFA Login Verified', ?, ?)");
            if ($stmtLog) {
                $stmtLog->bind_param("iss", $uid, $logDetails, $ip);
                $stmtLog->execute();
                $stmtLog->close();
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = $pending['user_id'];
            $_SESSION['full_name'] = $pending['full_name'];
            $_SESSION['role'] = $pending['role'];
            $_SESSION['office_id'] = $pending['office_id'];
            $_SESSION['profile_photo'] = $pending['profile_photo'];
            unset($_SESSION['mfa_pending'], $_SESSION['mfa_email_status']);

            if ($pending['role'] === 'QMO Admin') {
                header("Location: dashboard/qmo/index.php");
            } elseif ($pending['role'] === 'Office' || $pending['role'] === 'Dean') {
                header("Location: dashboard/dean/index.php");
            } else {
                header("Location: dashboard/qmo/index.php");
            }
            exit();
        }
    }
}

$emailStatus = $_SESSION['mfa_email_status'] ?? null;
if ($activeMethod === 'email' && $emailStatus) {
    $devCode = $emailStatus['dev_code'] ?? null;
    if (!$info && !$error) {
        if (!empty($emailStatus['sent'])) {
            $info = "A 6-digit verification code was sent to {$maskedEmail}.";
        } elseif ($devCode) {
            $info = "Local test mode (SMTP not configured): use the code below to verify.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/jpeg" href="assets/img/ua-qms-logo.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UA QMS | Multi-Factor Verification</title>
    <link rel="stylesheet" href="assets/css/login.css?v=1.3.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .mfa-device-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            color: #7f1d1d;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 14px;
        }
        .mfa-tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 14px;
        }
        .mfa-tab-link {
            flex: 1;
            text-align: center;
            padding: 8px 6px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            color: #4b5563;
            background: #f9fafb;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .mfa-tab-link:hover {
            border-color: #800000;
            color: #800000;
        }
        .mfa-tab-link.active {
            background: #800000;
            color: #ffffff;
            border-color: #800000;
        }
        .mfa-info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e3a8a;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 12px;
            margin-bottom: 12px;
            line-height: 1.45;
        }
        .mfa-dev-box {
            background: #fffbeb;
            border: 1px dashed #d97706;
            color: #92400e;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 12px;
            margin-bottom: 12px;
            text-align: center;
        }
        .mfa-dev-code {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 5px;
            color: #800000;
            margin-top: 4px;
        }
        .mfa-code-input {
            letter-spacing: 6px;
            font-size: 18px !important;
            font-weight: 700;
            text-align: center;
        }
        .mfa-secondary-btn {
            width: 100%;
            margin-top: 8px;
            padding: 10px;
            background: #f9fafb;
            color: #800000;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .mfa-secondary-btn:hover {
            background: #fef2f2;
        }
        .mfa-back-link {
            display: block;
            text-align: center;
            margin-top: 14px;
            font-size: 12px;
            color: #6b7280;
            text-decoration: none;
            font-weight: 600;
        }
        .mfa-back-link:hover {
            color: #800000;
        }
    </style>
</head>
<body>
<div class="login-page">
    <main class="login-shell">
        <section class="brand-panel" aria-label="UA QMS information">
            <div class="seal-row">
                <div class="logos-container">
                    <img src="assets/img/ua-qms-logo.jpg" alt="University of Antique seal" class="uni-logo">
                    <img src="assets/img/ISO.png" alt="ISO Logo" class="iso-logo">
                </div>
                <div class="institution-copy">
                    <p class="eyebrow">UNIVERSITY OF ANTIQUE</p>
                    <p class="campus-line">TARIO-LIM MEMORIAL CAMPUS</p>
                    <p class="subeyebrow">QUALITY MANAGEMENT OFFICE</p>
                </div>
            </div>

            <div class="brand-copy">
                <h1>
                    <span class="title-line">NEW DEVICE <span class="text-maroon">S</span><span class="text-gold">E</span>CURITY CHECK</span><br>
                    <span class="title-line">MULTI-FACTOR VERIFICATION</span>
                </h1>
                <p class="description">
                    To protect your UA QMS account, logins from a new or unrecognized device require a one-time verification code.
                </p>
            </div>
        </section>

        <section class="login-card" aria-label="MFA Verification Form">
            <h2>VERIFY SIGN-IN</h2>
            <p class="login-note">Signing in as <strong><?php echo htmlspecialchars($pending['full_name']); ?></strong></p>

            <div class="divider"></div>

            <div class="mfa-device-pill">
                <i class="fas fa-shield-alt"></i>
                <span>New device detected: <?php echo htmlspecialchars($deviceName); ?></span>
            </div>

            <!-- Method Switcher -->
            <div class="mfa-tabs">
                <?php if ($hasTotp): ?>
                    <a href="verify-mfa.php?method=totp" class="mfa-tab-link <?php echo $activeMethod === 'totp' ? 'active' : ''; ?>">
                        <i class="fas fa-mobile-alt"></i> Authenticator
                    </a>
                <?php endif; ?>
                <a href="verify-mfa.php?method=email" class="mfa-tab-link <?php echo $activeMethod === 'email' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i> Email OTP
                </a>
                <?php if ($hasBackup): ?>
                    <a href="verify-mfa.php?method=backup" class="mfa-tab-link <?php echo $activeMethod === 'backup' ? 'active' : ''; ?>">
                        <i class="fas fa-key"></i> Backup Code
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
                <p class="error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>

            <?php if ($info): ?>
                <div class="mfa-info"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($info); ?></div>
            <?php endif; ?>

            <?php if ($activeMethod === 'email' && $devCode): ?>
                <div class="mfa-dev-box">
                    <div><strong>Local Testing OTP Code</strong> (SMTP not configured on localhost)</div>
                    <div class="mfa-dev-code"><?php echo htmlspecialchars($devCode); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="method" value="<?php echo htmlspecialchars($activeMethod); ?>">

                <label for="mfa_code">
                    <?php if ($activeMethod === 'totp'): ?>
                        6-Digit Authenticator App Code
                    <?php elseif ($activeMethod === 'email'): ?>
                        6-Digit Email Verification Code (<?php echo htmlspecialchars($maskedEmail); ?>)
                    <?php else: ?>
                        One-Time Backup Recovery Code
                    <?php endif; ?>
                </label>

                <div class="input-wrap">
                    <span class="input-icon"><i class="fas fa-key"></i></span>
                    <input
                        id="mfa_code"
                        type="text"
                        name="mfa_code"
                        class="mfa-code-input"
                        autocomplete="one-time-code"
                        inputmode="<?php echo $activeMethod === 'backup' ? 'text' : 'numeric'; ?>"
                        maxlength="<?php echo $activeMethod === 'backup' ? '12' : '6'; ?>"
                        placeholder="<?php echo $activeMethod === 'backup' ? 'XXXX-XXXX' : '000000'; ?>"
                        required
                        autofocus
                    >
                </div>

                <div class="form-row" style="margin-top: 10px;">
                    <label class="remember">
                        <input type="checkbox" name="trust_device" value="1" checked>
                        <span>Trust this device for 30 days</span>
                    </label>
                </div>

                <button type="submit" name="verify_mfa">Verify &amp; Continue</button>
            </form>

            <?php if ($activeMethod === 'email'): ?>
                <form method="POST">
                    <input type="hidden" name="method" value="email">
                    <button type="submit" name="send_email_otp" class="mfa-secondary-btn">
                        <i class="fas fa-paper-plane"></i> Resend Email Code
                    </button>
                </form>
            <?php endif; ?>

            <a href="verify-mfa.php?cancel=1" class="mfa-back-link">
                <i class="fas fa-arrow-left"></i> Cancel and return to Login
            </a>
        </section>
    </main>

    <footer class="login-footer">
        <span>(C) 2026 UNIVERSITY OF ANTIQUE</span>
        <span>AUTOMATED QUALITY MONITORING AND DOCUMENT SUBMISSION SYSTEM</span>
    </footer>
</div>
</body>
</html>
