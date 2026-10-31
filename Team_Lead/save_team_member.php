<?php
// save_team_member.php
require 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $emp_id = $_POST['employee_id'];
    $tl_name = $_POST['reporting_manager'];
    $process_id = $_POST['process_id'];

    // Update reporting_manager and primary process in employee_master
    $sql = "UPDATE employee_master SET reporting_manager = ?, process = ? WHERE employee_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ssi", $tl_name, $process_id, $emp_id);

    if ($stmt->execute()) {
        header("Location: team_view.php?msg=Member Assigned Successfully");
    } else {
        echo "Error updating record: " . $mysqli->error;
    }
    $stmt->close();
    $mysqli->close();
}
?>