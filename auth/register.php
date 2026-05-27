<?php
session_start();
include "../config/db.php";

$message = "";
$full_name = $email = $role = $office_selection = "";

// Define the list of units/offices for Unit/Office Head selection
$unit_list = [
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

if (isset($_POST['submit'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $office_selection = !empty($_POST['office_selection']) ? $_POST['office_selection'] : NULL;
    $office_id = NULL;

    if ($role === 'QMO Admin') {
        $message = "QMO Admin accounts must be created by an existing QMO Administrator.";
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters!";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $message = "Email already exists!";
        } else {
            // Handle office assignment
            if ($role === 'Office') {
                if (!$office_selection) {
                    $message = "Please select a unit/office for Unit/Office Head role!";
                } else {
                    // Check if office exists in database, if not create it
                    $stmt_office = $conn->prepare("SELECT office_id FROM offices WHERE office_name = ?");
                    $stmt_office->bind_param("s", $office_selection);
                    $stmt_office->execute();
                    $res_office = $stmt_office->get_result();
                    
                    if ($res_office->num_rows > 0) {
                        $row_office = $res_office->fetch_assoc();
                        $office_id = $row_office['office_id'];
                    } else {
                        // Create new office entry
                        $stmt_ins_office = $conn->prepare("INSERT INTO offices (office_name, status) VALUES (?, 'Active')");
                        $stmt_ins_office->bind_param("s", $office_selection);
                        $stmt_ins_office->execute();
                        $office_id = $conn->insert_id;
                    }
                }
            }

            if (!$message) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $insert = $conn->prepare("INSERT INTO users (full_name, email, password, role, office_id, status) VALUES (?, ?, ?, ?, ?, 'Active')");
                $insert->bind_param("ssssi", $full_name, $email, $hashed_password, $role, $office_id);
                if ($insert->execute()) {
                    $message = "Account created successfully! <a href='../index.php'>Login</a>";
                    $full_name = $email = $role = $office_selection = "";
                } else {
                    $message = "Error creating account: " . $conn->error;
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Register | UA QMS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&display=swap" rel="stylesheet">
<style>
body {
    margin: 0;
    font-family: 'Inter', sans-serif;
    background-color: #f5f5f5;
}

.container {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
}

.card {
    background: #fff;
    padding: 40px;
    border-radius: 12px;
    width: 400px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.1);
}

.card h2 {
    text-align: center;
    margin-bottom: 10px;
}

.card p.subtitle {
    text-align: center;
    margin-bottom: 30px;
    color: #555;
    font-size: 14px;
}

.card form input,
.card form select {
    width: 100%;
    padding: 12px 15px;
    margin: 10px 0;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    box-sizing: border-box;
}

.card form button {
    width: 100%;
    padding: 12px;
    margin-top: 15px;
    background-color: #8B0000;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    cursor: pointer;
    font-weight: 500;
}

.card form button:hover {
    background-color: #a00000;
}

.back-link {
    display: block;
    margin-bottom: 20px;
    text-decoration: none;
    color: #555;
    font-size: 14px;
}

.message {
    text-align: center;
    color: green;
    font-size: 14px;
    margin-bottom: 15px;
}

.error-message {
    text-align: center;
    color: #dc2626;
    font-size: 14px;
    margin-bottom: 15px;
}

.form-group {
    margin-bottom: 5px;
}

.form-group label {
    display: block;
    font-size: 12px;
    color: #666;
    margin-bottom: 5px;
    font-weight: 500;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 12px 15px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    box-sizing: border-box;
}

.form-group.hidden {
    display: none;
}
</style>
</head>
<body>
<div class="container">
    <div class="card">
        <a href="../index.php" class="back-link">&larr; Back to Login</a>
        <h2>Create an Account</h2>
        <p class="subtitle">Join the UA QMS</p>

        <?php 
        if($message) { 
            if (strpos($message, 'successfully') !== false) {
                echo "<div class='message'>$message</div>"; 
            } else {
                echo "<div class='error-message'>$message</div>"; 
            }
        } 
        ?>

        <form method="POST">
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" placeholder="Full Name" required value="<?=htmlspecialchars($full_name)?>">
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="Email Address" required value="<?=htmlspecialchars($email)?>">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Password" required>
            </div>

            <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role" required onchange="updateOfficeField()">
                    <option value="">Select Role</option>
                    <option value="Office" <?= $role=="Office"?"selected":"" ?>>Unit / Office Head</option>
                    <option value="QMO Admin" <?= $role=="QMO Admin"?"selected":"" ?>>QMO Admin</option>
                </select>
            </div>

            <!-- Unit selection field for Unit/Office Head users -->
            <div class="form-group" id="officeField">
                <label for="office_selection">Unit / Office Assignment</label>
                <select id="office_selection" name="office_selection">
                    <option value="">-- Select Unit / Office --</option>
                    <?php foreach($unit_list as $unit): ?>
                        <option value="<?php echo htmlspecialchars($unit); ?>" <?= $office_selection==$unit?"selected":"" ?>>
                            <?php echo htmlspecialchars($unit); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" name="submit">Register Account</button>
        </form>
    </div>
</div>

<script>
function updateOfficeField() {
    const role = document.getElementById('role').value;
    const officeField = document.getElementById('officeField');
    
    // Show office field only for Office role
    if (role === 'Office') {
        officeField.style.display = 'block';
    } else {
        officeField.style.display = 'none';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateOfficeField();
});
</script>
</body>
</html>
