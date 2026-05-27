<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin') {
    header("Location: ../../index.php");
    exit();
}

header("Location: review_submissions.php");
exit();
?>
