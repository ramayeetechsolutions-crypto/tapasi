<?php
session_start();
require 'db.php';

$id = $_GET['id'] ?? 0;

// Fetch record
$stmt = $mysqli->prepare("SELECT * FROM ce WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$record = $result->fetch_assoc();
$stmt->close();

if (!$record) {
    echo "Record not found";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Record Details - <?php echo $record['job_id']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <h3>Record Details</h3>
    <div class="card">
        <div class="card-body">
            <table class="table table-bordered">
                <tr><th>Job ID</th><td><?php echo $record['job_id']; ?></td></tr>
                <tr><th>Date</th><td><?php echo $record['date']; ?></td></tr>
                <tr><th>Client</th><td><?php echo $record['client']; ?></td></tr>
                <tr><th>Status</th><td><?php echo $record['status']; ?></td></tr>
                <tr><th>Equations</th><td><?php echo $record['eq']; ?></td></tr>
                <tr><th>Images</th><td><?php echo $record['images']; ?></td></tr>
                <tr><th>Tables</th><td><?php echo $record['tables_count']; ?></td></tr>
                <tr><th>Word Count</th><td><?php echo $record['word_count']; ?></td></tr>
                <tr><th>Last Updated By</th><td><?php echo $record['done_by']; ?></td></tr>
                <tr><th>Last Update Date</th><td><?php echo $record['ce_date']; ?></td></tr>
            </table>
            <a href="ce_table.php" class="btn btn-secondary">Back</a>
        </div>
    </div>
</div>
</body>
</html>