<?php
/*$servername = "localhost";
$username   = "rtssoftware_tapasi_user";     
$password   = "tapasi_pass";         
$dbname     = "rtssoftware_tapasi_erp";*/

$servername = "localhost";
$username   = "root";     
$password   = "Tapasi@2026";         
$dbname     = "tapasi_erp"; 

$mysqli = new mysqli($servername, $username, $password, $dbname);

if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}
?>
