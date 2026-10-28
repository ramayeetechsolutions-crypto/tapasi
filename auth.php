<?php
session_start();

if (!isset($_SESSION['uid']) || !isset($_SESSION['role_name'])) {
    session_destroy();
    header("Location: ../login.php?error=not_logged_in");
    exit;
}

// Normalize folder name
$currentFolder = basename(dirname(__FILE__));

// Folder → Role mapping
$folderRoleMap = [
    "Super_Admin" => "Super_Admin",
    "Admin"       => "Admin",
    "Executive"   => "Executive",
    "Team _Lead"  => "Team Lead"
];

// Check if folder is valid
if (!isset($folderRoleMap[$currentFolder])) {
    session_destroy();
    header("Location: ../login.php?error=invalid_folder");
    exit;
}

// If role doesn’t match → logout instead of looping
if ($_SESSION['role_name'] !== $folderRoleMap[$currentFolder]) {
    session_destroy();
    header("Location: ../login.php?error=unauthorized");
    exit;
}
