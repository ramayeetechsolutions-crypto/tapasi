<br><br><br>
<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$sql = "
SELECT 
    j.job_id,
    j.job_date,
    j.client_id,
    j.journal_issue,
    j.article,
    j.process_id,
    j.assigned_ce,
    j.assigned_le,
    j.due_date,
    c.status AS ce_status,
    c.done_by AS ce_done_by,
    c.ce_date,
    l.status AS le_status,
    l.done_by AS le_done_by,
    l.le_date
FROM jobs j
JOIN ce c ON j.job_id = c.job_id
JOIN le l ON j.job_id = l.job_id
WHERE c.status = 'Completed'
  AND l.status = 'Completed'
ORDER BY j.job_date DESC
";

$result = $mysqli->query($sql);

if (!$result) {
    die("Query failed: " . $mysqli->error);
}

?>
<?php require 'header.php';?>

<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<style>
    #jobsTable_wrapper {
        padding: 10px;
    }
</style>

<h2>Completed Jobs (CE & LE)</h2>

<?php if ($result->num_rows > 0): ?>
    <table id="jobsTable" class="display" style="width:100%">
        <thead>
            <tr>
                <th>Job ID</th>
                <th>Job Date</th>
                <th>Client ID</th>
                <th>Issue</th>
                <th>Article</th>
                <th>Process ID</th>
                <th>Assigned CE</th>
                <th>CE Status</th>
                <th>CE Done By</th>
                <th>CE Date</th>
                <th>Assigned LE</th>
                <th>LE Status</th>
                <th>LE Done By</th>
                <th>LE Date</th>
                <th>Due Date</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['job_id']) ?></td>
                <td><?= htmlspecialchars($row['job_date']) ?></td>
                <td><?= htmlspecialchars($row['client_id']) ?></td>
                <td><?= htmlspecialchars($row['journal_issue']) ?></td>
                <td><?= htmlspecialchars($row['article']) ?></td>
                <td><?= htmlspecialchars($row['process_id']) ?></td>
                <td><?= htmlspecialchars($row['assigned_ce'] ?? 'N/A') ?></td>
                <td><span class="badge bg-success"><?= htmlspecialchars($row['ce_status']) ?></span></td>
                <td><?= htmlspecialchars($row['ce_done_by'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($row['ce_date']) ?></td>
                <td><?= htmlspecialchars($row['assigned_le'] ?? 'N/A') ?></td>
                <td><span class="badge bg-success"><?= htmlspecialchars($row['le_status']) ?></span></td>
                <td><?= htmlspecialchars($row['le_done_by'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($row['le_date']) ?></td>
                <td><?= htmlspecialchars($row['due_date']) ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="alert alert-info">
        <h4>No Data Found</h4>
        <p>No jobs found where both CE and LE stages are completed.</p>
        <p>This could mean:</p>
        <ul>
            <li>No jobs have both CE and LE completed yet</li>
            <li>There might be no data in the database</li>
            <li>The query conditions are not being met</li>
        </ul>
    </div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<script>
$(document).ready(function() {
    <?php if ($result->num_rows > 0): ?>
    $('#jobsTable').DataTable({
        pageLength: 10,
        order: [[1, 'desc']],
        responsive: true,
        dom: '<"top"lf>rt<"bottom"ip><"clear">'
    });
    <?php endif; ?>
});
</script>