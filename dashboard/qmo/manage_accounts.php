<?php
session_start();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../includes/unit-seed.php";
require_once __DIR__ . "/../../includes/schema_helpers.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin') {
    header("Location: ../../index.php");
    exit();
}

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

ua_qms_seed_default_units($conn);
ua_qms_ensure_mfa_tables($conn);

if (isset($_GET['action']) || isset($_POST['action'])) {
    header("Content-Type: application/json");
    $action = $_POST["action"] ?? $_GET["action"] ?? null;

    function response($success, $message, $data = null) {
        echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
        exit;
    }

    function tempPassword($length = 8) {
        return substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ23456789"), 0, $length);
    }

    if ($action === "fetch") {
        $result = $conn->query("
            SELECT
                u.user_id AS id,
                u.full_name AS name,
                u.email,
                u.role,
                u.office_id,
                o.office_name AS unit,
                u.status,
                u.profile_photo,
                COALESCE(m.mfa_enabled, 0) AS mfa_enabled,
                COALESCE(m.preferred_method, 'totp') AS mfa_method
            FROM users u
            LEFT JOIN offices o ON u.office_id = o.office_id
            LEFT JOIN user_mfa m ON u.user_id = m.user_id
            ORDER BY u.full_name ASC
        ");
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        response(true, "Users loaded", $users);
    }

    if ($action === "offices") {
        $result = $conn->query("SELECT office_id, office_name FROM offices WHERE status = 'Active' ORDER BY office_name ASC");
        $offices = [];
        while ($row = $result->fetch_assoc()) {
            $offices[] = $row;
        }
        response(true, "Units loaded", $offices);
    }

    if ($action === "add") {
        $name = trim($_POST["name"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $role = $_POST["role"] ?? "Office";
        $office_id = (int)($_POST["office_id"] ?? 0);
        $password = $_POST["password"] ?? "";
        $confirm = $_POST["confirm_password"] ?? "";

        if ($name === "" || $email === "" || ($role !== 'QMO Admin' && !$office_id)) {
            response(false, "Name, email, and unit are required.");
        }

        if (strlen($password) < 6) {
            response(false, "Password must be at least 6 characters.");
        }

        if ($password !== $confirm) {
            response(false, "Passwords do not match.");
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $office_id_val = ($office_id > 0) ? $office_id : null;
        $stmt = $conn->prepare("INSERT INTO users (full_name, email, role, office_id, password, status) VALUES (?, ?, ?, ?, ?, 'Active')");
        $stmt->bind_param("sssis", $name, $email, $role, $office_id_val, $hash);
        if ($stmt->execute()) {
            response(true, "Account created successfully.");
        }
        response(false, $conn->error);
    }

    if ($action === "edit") {
        $id = (int)($_POST["id"] ?? 0);
        $name = trim($_POST["name"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $role = $_POST["role"] ?? "Office";
        $office_id = (int)($_POST["office_id"] ?? 0);
        $password = $_POST["password"] ?? "";
        $confirm = $_POST["confirm_password"] ?? "";

        if (!$id || $name === "" || $email === "" || ($role !== 'QMO Admin' && !$office_id)) {
            response(false, "User, name, email, and unit are required.");
        }

        $office_id_val = ($office_id > 0) ? $office_id : null;

        if ($password !== "") {
            if (strlen($password) < 6) {
                response(false, "Password must be at least 6 characters.");
            }
            if ($password !== $confirm) {
                response(false, "Passwords do not match.");
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, office_id = ?, password = ? WHERE user_id = ?");
            $stmt->bind_param("sssisi", $name, $email, $role, $office_id_val, $hash, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, office_id = ? WHERE user_id = ?");
            $stmt->bind_param("sssii", $name, $email, $role, $office_id_val, $id);
        }

        if ($stmt->execute()) {
            response(true, "Account updated successfully.");
        }
        response(false, $conn->error);
    }

    if ($action === "toggleStatus") {
        $id = (int)($_POST["id"] ?? 0);
        if (!$id) response(false, "Missing user ID.");
        $conn->query("UPDATE users SET status = IF(status='Active','Suspended','Active') WHERE user_id = $id");
        response(true, "Status updated");
    }

    if ($action === "delete") {
        $id = (int)($_POST["id"] ?? 0);
        if (!$id) response(false, "Missing user ID.");
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            response(true, "User deleted");
        }
        response(false, $conn->error);
    }

    response(false, "Invalid action");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/jpeg" href="../../assets/img/ua-qms-logo.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accounts | UA QMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id']); ?>;
        window.CURRENT_USER_ROLE = <?php echo json_encode($_SESSION['role']); ?>;
    </script>
    <script src="../../assets/js/messaging.js" defer></script>
    <script src="../../assets/js/notifications.js" defer></script>
    <style>
        :root { --ua-maroon: #800000; }
        .bg-ua-maroon { background-color: var(--ua-maroon); }
        .text-ua-maroon { color: var(--ua-maroon); }

    </style>
</head>
<body class="bg-gray-50 font-sans" data-user-role="<?php echo htmlspecialchars($_SESSION['role']); ?>">
<script>localStorage.setItem('userRole', '<?php echo htmlspecialchars($_SESSION['role']); ?>');</script>
<div class="flex h-screen overflow-hidden">
    <?php require_once __DIR__ . "/../../includes/sidebar_qmo.php"; ?>

    <main class="flex-1 flex flex-col overflow-hidden">
        <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0">
            <div>
                <h1 class="text-lg font-bold text-gray-900 leading-tight">Manage Accounts</h1>
            </div>
            <div class="flex items-center gap-6">
                <div id="clock" class="text-sm text-gray-500 font-medium"></div>
                <button id="inbox-btn" class="relative p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition">
                    <i class="fas fa-envelope text-lg"></i>
                    <span id="message-badge" class="absolute top-1 right-1 w-4 h-4 bg-ua-maroon text-white text-[10px] flex items-center justify-center rounded-full hidden">0</span>
                </button>
                <button id="notification-bell" class="relative p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition">
                    <i class="fas fa-bell text-lg"></i>
                    <span id="notification-badge" class="absolute top-1 right-1 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center hidden">0</span>
                </button>
                <div class="relative">
                    <button id="profile-btn" class="w-10 h-10 rounded-full bg-ua-maroon flex items-center justify-center text-white font-bold cursor-pointer hover:opacity-80 transition overflow-hidden">
                            <?php if (!empty($_SESSION['profile_photo'])): ?>
                                <img src="<?php echo htmlspecialchars($_SESSION['profile_photo']); ?>" class="w-full h-full object-cover rounded-full" alt="Profile" />
                            <?php else: ?>
                                <?php echo h(isset($_SESSION['full_name']) ? substr($_SESSION['full_name'], 0, 1) : 'U'); ?>
                            <?php endif; ?>
                        </button>
                    <div id="profile-dropdown" class="profile-dropdown">
                        <div class="profile-dropdown-header">
                            <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></p>
                            <span class="text-xs text-gray-500"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Role'); ?></span>
                        </div>
                        <a href="../../logout.php" class="profile-dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i> Sign Out
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-4 md:p-8">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-4 border-b border-gray-200 flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
                    <div class="relative w-full sm:w-80">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input id="searchInput" oninput="renderTable()" type="text" placeholder="Search users, roles, or units..." class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-ua-maroon">
                    </div>
                    <button onclick="openAccountModal()" class="bg-ua-maroon text-white px-4 py-2 rounded-lg hover:bg-red-900 transition flex items-center justify-center gap-2 shadow-sm text-sm font-semibold">
                        <i class="fas fa-user-plus"></i> Add Account
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Role</th>
                                <th class="px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Unit / Department</th>
                                <th class="px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody" class="divide-y divide-gray-100">
                            <tr><td colspan="5" class="px-5 py-8 text-center text-gray-500">Loading accounts...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Account Modal -->
            <div id="accountModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center z-[9999] backdrop-blur-sm w-screen h-screen">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden">
                    <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center">
                        <h3 id="accountModalTitle" class="font-bold flex items-center gap-2"><i class="fas fa-user-circle"></i> Add Account</h3>
                        <button onclick="closeModal('accountModal')" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
                    </div>
                    <form id="accountForm" class="p-5 space-y-4">
                        <input type="hidden" name="action" id="formAction" value="add">
                        <input type="hidden" name="id" id="userId">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                            <input type="text" name="name" id="userName" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                            <input type="email" name="email" id="userEmail" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none">
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                                <select name="role" id="userRole" required onchange="syncOfficeRequirement()" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none">
                                    <option value="Office">Unit User</option>
                                    <option value="Dean">Dean</option>
                                    <option value="QMO Admin">QMO Admin</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Unit / Department</label>
                                <select name="office_id" id="userOffice" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none"></select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                                <div class="relative">
                                    <input type="password" name="password" id="userPassword" placeholder="Min 6 characters" class="w-full pl-4 pr-10 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none text-sm">
                                    <button type="button" onclick="togglePasswordVisibility('userPassword', 'eyeIcon1')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none">
                                        <i class="fas fa-eye text-sm" id="eyeIcon1"></i>
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                                <div class="relative">
                                    <input type="password" name="confirm_password" id="userConfirmPassword" placeholder="Confirm password" class="w-full pl-4 pr-10 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none text-sm">
                                    <button type="button" onclick="togglePasswordVisibility('userConfirmPassword', 'eyeIcon2')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none">
                                        <i class="fas fa-eye text-sm" id="eyeIcon2"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div id="passwordNotice" class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-xs text-gray-600">
                            Please specify a password for the new account.
                        </div>
                        <button type="submit" class="w-full bg-ua-maroon text-white py-3 rounded-lg font-bold hover:bg-red-900 transition">
                            Save Account
                        </button>
                    </form>
                </div>
            </div>
        </main>
    </div>



<script>
(function() {
    let users = [];
    let offices = [];

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function(char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
        });
    }

    async function fetchOffices() {
        const res = await fetch('manage_accounts.php?action=offices');
        const data = await res.json();
        offices = data.data || [];
        const options = '<option value="">N/A (QMO Admin Only)</option>' + offices.map(office => `<option value="${office.office_id}">${escapeHtml(office.office_name)}</option>`).join('');
        document.getElementById('userOffice').innerHTML = options;
    }

    async function fetchUsers() {
        const res = await fetch('manage_accounts.php?action=fetch');
        const data = await res.json();
        if (data.success) {
            users = data.data || [];
            renderTable();
        }
    }

    function renderTable() {
        const query = document.getElementById('searchInput')?.value.toLowerCase() || '';
        const filtered = users.filter(user => {
            return [user.name, user.email, user.role, user.unit, user.status]
                .join(' ')
                .toLowerCase()
                .includes(query);
        });

        const tbody = document.getElementById('userTableBody');
        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="px-5 py-8 text-center text-gray-500">No accounts found.</td></tr>';
            return;
        }

        tbody.innerHTML = filtered.map(user => `
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-5 py-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center text-gray-500 font-bold overflow-hidden">
                            ${user.profile_photo ? `<img src="${escapeHtml(user.profile_photo)}" class="w-full h-full object-cover" />` : escapeHtml(user.name || 'U').charAt(0)}
                        </div>
                        <div>
                            <p class="font-medium text-gray-900">${escapeHtml(user.name)}</p>
                            <p class="text-xs text-gray-500">${escapeHtml(user.email)}</p>
                        </div>
                    </div>
                </td>
                <td class="px-5 py-4 text-sm font-medium text-gray-700">${escapeHtml(user.role)}</td>
                <td class="px-5 py-4 text-sm text-gray-700">${escapeHtml(user.unit || 'Unassigned')}</td>
                <td class="px-5 py-4">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold ${user.status === 'Active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">${escapeHtml(user.status)}</span>
                        ${Number(user.mfa_enabled) === 1 ? `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200" title="MFA Enabled (${user.mfa_method === 'email' ? 'Email OTP' : 'Authenticator App'})"><i class="fas fa-shield-alt text-[10px]"></i> MFA</span>` : ''}
                    </div>
                </td>
                <td class="px-5 py-4 text-right">
                    <div class="inline-flex flex-wrap justify-end items-center gap-2">
                        <button onclick="openAccountModal(${Number(user.id)})" class="inline-flex items-center gap-1 bg-red-50 text-[#800000] border border-red-200 hover:bg-[#800000] hover:text-white px-2.5 py-1.5 rounded-lg text-xs font-bold transition shadow-sm">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        ${Number(user.mfa_enabled) === 1 ? `
                        <button onclick="resetUserMfa(${Number(user.id)})" class="inline-flex items-center gap-1 bg-amber-50 text-amber-800 border border-amber-200 hover:bg-amber-600 hover:text-white px-2.5 py-1.5 rounded-lg text-xs font-bold transition shadow-sm" title="Disable MFA and clear trusted devices for recovery">
                            <i class="fas fa-shield-alt"></i> Reset MFA
                        </button>` : ''}
                        <button onclick="toggleStatus(${Number(user.id)})" class="inline-flex items-center gap-1 bg-red-50 text-[#800000] border border-red-200 hover:bg-[#800000] hover:text-white px-2.5 py-1.5 rounded-lg text-xs font-bold transition shadow-sm">
                            <i class="fas ${user.status === 'Active' ? 'fa-ban' : 'fa-check-circle'}"></i> ${user.status === 'Active' ? 'Suspend' : 'Activate'}
                        </button>
                        <button onclick="deleteUser(${Number(user.id)})" class="inline-flex items-center gap-1 bg-red-50 text-[#800000] border border-red-200 hover:bg-[#800000] hover:text-white px-2.5 py-1.5 rounded-lg text-xs font-bold transition shadow-sm">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    function syncOfficeRequirement() {
        const roleEl = document.getElementById('userRole');
        const officeEl = document.getElementById('userOffice');
        if (!roleEl || !officeEl) return;
        const isAdmin = roleEl.value === 'QMO Admin';
        officeEl.required = !isAdmin;
    }

    function openAccountModal(userId = null) {
        const user = userId ? users.find(item => Number(item.id) === Number(userId)) : null;
        document.getElementById('accountModalTitle').innerHTML = user ? '<i class="fas fa-user-circle"></i> Edit Account' : '<i class="fas fa-user-circle"></i> Add Account';
        document.getElementById('formAction').value = user ? 'edit' : 'add';
        document.getElementById('userId').value = user?.id || '';
        document.getElementById('userName').value = user?.name || '';
        document.getElementById('userEmail').value = user?.email || '';
        document.getElementById('userRole').value = user?.role || 'Office';
        document.getElementById('userOffice').value = user?.office_id || '';
        syncOfficeRequirement();
        
        const passInput = document.getElementById('userPassword');
        const confirmInput = document.getElementById('userConfirmPassword');
        
        if (user) {
            passInput.value = '';
            confirmInput.value = '';
            passInput.required = false;
            confirmInput.required = false;
            document.getElementById('passwordNotice').textContent = "Leave blank to keep the current password. Enter a new password to change it.";
        } else {
            passInput.value = 'password';
            confirmInput.value = 'password';
            passInput.required = true;
            confirmInput.required = true;
            document.getElementById('passwordNotice').textContent = "Default password is set to 'password'. Modify if needed (minimum 6 characters).";
        }
        
        passInput.type = 'password';
        confirmInput.type = 'password';
        
        const eye1 = document.getElementById('eyeIcon1');
        const eye2 = document.getElementById('eyeIcon2');
        if (eye1) {
            eye1.className = 'fas fa-eye text-sm';
        }
        if (eye2) {
            eye2.className = 'fas fa-eye text-sm';
        }
        
        openModal('accountModal');
    }

    function togglePasswordVisibility(inputId, eyeIconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(eyeIconId);
        if (input && icon) {
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    }

    document.getElementById('accountForm').onsubmit = async function(event) {
        event.preventDefault();
        const pass = document.getElementById('userPassword').value;
        const conf = document.getElementById('userConfirmPassword').value;
        const action = document.getElementById('formAction').value;
        
        if (action === 'add' || pass !== '') {
            if (pass.length < 6) {
                alert("Password must be at least 6 characters!");
                return;
            }
            if (pass !== conf) {
                alert("Passwords do not match!");
                return;
            }
        }
        
        const formData = new FormData(event.target);
        const res = await fetch('manage_accounts.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (!data.success) {
            alert(data.message);
            return;
        }
        closeModal('accountModal');
        await fetchUsers();
    };

    async function toggleStatus(id) {
        const formData = new FormData();
        formData.append('action', 'toggleStatus');
        formData.append('id', id);
        const res = await fetch('manage_accounts.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) fetchUsers();
    }

    async function deleteUser(id) {
        showThemeConfirm('Permanently delete this account?', async () => {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            try {
                const res = await fetch('manage_accounts.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    fetchUsers();
                } else {
                    showThemeAlert('Failed to delete account: ' + data.message);
                }
            } catch (err) {
                showThemeAlert('An error occurred while deleting the account.');
            }
        });
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            document.body.appendChild(modal);
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
        document.body.classList.add('overflow-hidden');
        document.querySelectorAll('.overflow-auto').forEach(el => {
            el.dataset.prevOverflow = el.style.overflow || '';
            el.style.setProperty('overflow', 'hidden', 'important');
        });
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        const main = document.querySelector('main');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            if (main) {
                main.appendChild(modal);
            }
        }
        document.body.classList.remove('overflow-hidden');
        document.querySelectorAll('.overflow-auto').forEach(el => {
            el.style.overflow = el.dataset.prevOverflow || '';
        });
    }

    async function resetUserMfa(id) {
        const user = users.find(item => Number(item.id) === Number(id));
        const displayName = user ? user.name : 'this user';
        showThemeConfirm(`Reset MFA and clear trusted devices for ${displayName}? They will be able to sign in normally and set up MFA again.`, async () => {
            try {
                const res = await fetch('../../api/mfa_api.php?action=admin_reset_user_mfa', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: id })
                });
                const data = await res.json();
                if (data.success) {
                    if (typeof showThemeAlert === 'function') showThemeAlert(data.message || 'MFA reset successfully.');
                    fetchUsers();
                } else {
                    if (typeof showThemeAlert === 'function') showThemeAlert('Failed to reset MFA: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                if (typeof showThemeAlert === 'function') showThemeAlert('An error occurred while resetting MFA.');
            }
        });
    }

    // Expose functions to window scope for inline HTML handlers
    window.openAccountModal = openAccountModal;
    window.toggleStatus = toggleStatus;
    window.deleteUser = deleteUser;
    window.resetUserMfa = resetUserMfa;
    window.openModal = openModal;
    window.closeModal = closeModal;
    window.togglePasswordVisibility = togglePasswordVisibility;
    window.syncOfficeRequirement = syncOfficeRequirement;
    window.renderTable = renderTable;

    document.getElementById('searchInput')?.addEventListener('input', renderTable);

    fetchOffices().then(fetchUsers);
})();
</script>
</body>
</html>
