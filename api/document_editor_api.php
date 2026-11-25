<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/schema_helpers.php';

ua_qms_ensure_document_annotations_table($conn);

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];
$office_id = (int)($_SESSION['office_id'] ?? 0);
$action = $_GET['action'] ?? '';

if (!$office_id && $role !== 'QMO Admin') {
    $stmt_u = $conn->prepare("SELECT office_id FROM users WHERE user_id = ?");
    if ($stmt_u) {
        $stmt_u->bind_param("i", $user_id);
        $stmt_u->execute();
        $u_row = $stmt_u->get_result()->fetch_assoc();
        if ($u_row) {
            $office_id = (int)$u_row['office_id'];
            $_SESSION['office_id'] = $office_id;
        }
        $stmt_u->close();
    }
}

function fetchAuthorizedDocument($conn, $doc_id, $role, $office_id) {
    $sql = "SELECT d.*, dt.doc_code, dt.description AS doc_type_desc, o.office_name, o.office_code,
                   (SELECT dr.remarks FROM document_reviews dr WHERE dr.document_id = d.document_id ORDER BY dr.review_id DESC LIMIT 1) AS latest_remarks
            FROM documents d
            JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
            JOIN offices o ON d.office_id = o.office_id
            WHERE d.document_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param("i", $doc_id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) return null;
    if ($role !== 'QMO Admin' && (int)$doc['office_id'] !== $office_id) {
        return false;
    }
    return $doc;
}

if ($action === 'download_edited') {
    $doc_id = (int)($_GET['document_id'] ?? 0);
    $doc = fetchAuthorizedDocument($conn, $doc_id, $role, $office_id);
    if (!$doc) {
        http_response_code($doc === false ? 403 : 404);
        echo 'Document not found or access denied.';
        exit();
    }

    $stmt_a = $conn->prepare("SELECT da.*, u.full_name AS updated_by_name FROM document_annotations da LEFT JOIN users u ON da.updated_by = u.user_id WHERE da.document_id = ?");
    $stmt_a->bind_param("i", $doc_id);
    $stmt_a->execute();
    $ann = $stmt_a->get_result()->fetch_assoc();
    $stmt_a->close();

    if (!$ann || empty($ann['edited_html'])) {
        http_response_code(404);
        echo 'No edited text version is available for download.';
        exit();
    }

    $base = pathinfo($doc['file_name'], PATHINFO_FILENAME);
    $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $base) . '_Reviewed.doc';

    $comments_html = '';
    if (!empty($ann['annotations_json'])) {
        $parsed = json_decode($ann['annotations_json'], true);
        if (is_array($parsed) && !empty($parsed['comments'])) {
            $comments_html .= '<div style="margin-top:24px;padding:12px 16px;border:1px solid #d1d5db;background:#fffbeb;">';
            $comments_html .= '<h3 style="margin:0 0 8px 0;color:#800000;font-size:13pt;">Reviewer Comments &amp; Revision Notes</h3><ol style="margin:0;padding-left:20px;">';
            foreach ($parsed['comments'] as $c) {
                $quote = htmlspecialchars((string)($c['quote'] ?? ''), ENT_QUOTES, 'UTF-8');
                $note = htmlspecialchars((string)($c['note'] ?? ''), ENT_QUOTES, 'UTF-8');
                $comments_html .= '<li style="margin-bottom:6px;">';
                if ($quote !== '') {
                    $comments_html .= '<span style="background:#fef08a;padding:1px 4px;">&ldquo;' . $quote . '&rdquo;</span> &mdash; ';
                }
                $comments_html .= '<strong>' . $note . '</strong></li>';
            }
            $comments_html .= '</ol></div>';
        }
    }

    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe_name . '"');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
        . 'body{font-family:Calibri,Arial,sans-serif;font-size:11pt;line-height:1.5;color:#111827;margin:24px;}'
        . 'table{border-collapse:collapse;width:100%;margin:10px 0;} td,th{border:1px solid #9ca3af;padding:6px 8px;}'
        . 'mark,.rev-hl-yellow{background:#fef08a;padding:0 2px;}'
        . '.rev-hl-red{background:#fecaca;padding:0 2px;}'
        . '.rev-hl-green{background:#bbf7d0;padding:0 2px;}'
        . '.rev-edit-text{color:#800000;font-weight:bold;text-decoration:underline;}'
        . 'del,s,.rev-strike{color:#b91c1c;text-decoration:line-through;}'
        . '</style></head><body>'
        . '<div style="border-bottom:2px solid #800000;padding-bottom:8px;margin-bottom:16px;font-size:10pt;color:#4b5563;">'
        . '<strong style="color:#800000;">UA QMS Reviewed Document</strong> &middot; '
        . htmlspecialchars($doc['file_name'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($doc['office_name'], ENT_QUOTES, 'UTF-8') . ')<br>'
        . 'Reviewed by: ' . htmlspecialchars($ann['updated_by_name'] ?? 'QMO Reviewer', ENT_QUOTES, 'UTF-8')
        . ' &middot; Updated: ' . htmlspecialchars($ann['updated_at'] ?? '', ENT_QUOTES, 'UTF-8')
        . '</div>'
        . $ann['edited_html']
        . $comments_html
        . '</body></html>';
    exit();
}

header('Content-Type: application/json');

switch ($action) {
    case 'get':
        $doc_id = (int)($_GET['document_id'] ?? 0);
        if (!$doc_id) {
            echo json_encode(['success' => false, 'error' => 'Missing document ID.']);
            exit();
        }
        $doc = fetchAuthorizedDocument($conn, $doc_id, $role, $office_id);
        if ($doc === false) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied.']);
            exit();
        }
        if (!$doc) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Document not found.']);
            exit();
        }

        $stmt_a = $conn->prepare("
            SELECT da.annotation_id, da.document_id, da.edited_html, da.annotations_json, da.editor_mode, da.updated_at, u.full_name AS updated_by_name
            FROM document_annotations da
            LEFT JOIN users u ON da.updated_by = u.user_id
            WHERE da.document_id = ?
        ");
        $stmt_a->bind_param("i", $doc_id);
        $stmt_a->execute();
        $ann = $stmt_a->get_result()->fetch_assoc();
        $stmt_a->close();

        echo json_encode([
            'success' => true,
            'document' => [
                'document_id' => (int)$doc['document_id'],
                'file_name' => $doc['file_name'],
                'file_path' => $doc['file_path'],
                'preview_path' => $doc['preview_path'],
                'status' => $doc['status'],
                'version_number' => (int)($doc['version_number'] ?? 1),
                'office_name' => $doc['office_name'],
                'doc_code' => $doc['doc_code'],
                'doc_type_desc' => $doc['doc_type_desc'],
                'latest_remarks' => $doc['latest_remarks'] ?? '',
            ],
            'annotation' => $ann ?: null
        ]);
        break;

    case 'save':
        if ($role !== 'QMO Admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only QMO Reviewers can save document edits.']);
            exit();
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $doc_id = (int)($input['document_id'] ?? 0);
        $edited_html = isset($input['edited_html']) ? (string)$input['edited_html'] : null;
        $annotations_json = isset($input['annotations_json'])
            ? (is_string($input['annotations_json']) ? $input['annotations_json'] : json_encode($input['annotations_json']))
            : null;
        $editor_mode = (string)($input['editor_mode'] ?? 'rich_text');
        if (!in_array($editor_mode, ['rich_text', 'visual', 'hybrid'], true)) {
            $editor_mode = 'rich_text';
        }

        if (!$doc_id) {
            echo json_encode(['success' => false, 'error' => 'Missing document ID.']);
            exit();
        }

        // Basic script sanitization on saved HTML
        if ($edited_html !== null) {
            $edited_html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $edited_html);
            $edited_html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $edited_html);
        }

        $stmt = $conn->prepare("
            INSERT INTO document_annotations (document_id, edited_html, annotations_json, editor_mode, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                edited_html = VALUES(edited_html),
                annotations_json = VALUES(annotations_json),
                editor_mode = VALUES(editor_mode),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");
        $stmt->bind_param("isssi", $doc_id, $edited_html, $annotations_json, $editor_mode, $user_id);
        if ($stmt->execute()) {
            $stmt->close();
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, ip_address) VALUES (?, 'Annotated Document', ?, ?)");
            if ($log_stmt) {
                $details = "Saved highlights/edits for document ID $doc_id ($editor_mode)";
                $log_stmt->bind_param("iss", $user_id, $details, $ip);
                $log_stmt->execute();
                $log_stmt->close();
            }
            echo json_encode([
                'success' => true,
                'message' => 'Document highlights and edits saved successfully.',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        break;

    case 'clear':
        if ($role !== 'QMO Admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only QMO Reviewers can clear document edits.']);
            exit();
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $doc_id = (int)($input['document_id'] ?? 0);
        if (!$doc_id) {
            echo json_encode(['success' => false, 'error' => 'Missing document ID.']);
            exit();
        }
        $stmt = $conn->prepare("DELETE FROM document_annotations WHERE document_id = ?");
        $stmt->bind_param("i", $doc_id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
