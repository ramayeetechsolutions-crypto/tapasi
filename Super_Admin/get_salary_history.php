<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

$employee_id = (int)($_GET['employee_id'] ?? 0);

if ($employee_id <= 0) {
    echo '<div class="alert alert-danger">Invalid employee ID</div>';
    exit;
}

// Get current employee details
$stmt = $mysqli->prepare("SELECT employee_name, salary, tds_per FROM employee_master WHERE employee_id = ?");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$employee) {
    echo '<div class="alert alert-danger">Employee not found</div>';
    exit;
}

// Get salary history
$stmt = $mysqli->prepare("
    SELECT h.*, 
           (h.new_salary - h.old_salary) as salary_diff,
           (h.new_salary * h.tds_per / 100) as tds_amount,
           (h.new_salary - (h.new_salary * h.tds_per / 100)) as net_salary
    FROM employee_salary_history h 
    WHERE h.employee_id = ? 
    ORDER BY h.changed_at DESC
");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$history = $stmt->get_result();
?>

<div class="row mb-3">
    <div class="col-md-12">
        <div class="alert alert-info">
            <strong>Current Salary:</strong> ₹<?= number_format($employee['salary'], 2) ?> | 
            <strong>TDS:</strong> <?= $employee['tds_per'] ?>% | 
            <strong>Net:</strong> ₹<?= number_format($employee['salary'] - ($employee['salary'] * $employee['tds_per'] / 100), 2) ?>
        </div>
    </div>
</div>

<?php if ($history->num_rows > 0): ?>
<div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead class="table-light">
            <tr>
                <th>Date</th>
                <th>Old Salary</th>
                <th>New Salary</th>
                <th>Change</th>
                <th>TDS %</th>
                <th>TDS Amount</th>
                <th>Net Salary</th>
                <th>Changed By</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $history->fetch_assoc()): 
                $change_class = $row['salary_diff'] > 0 ? 'text-success' : ($row['salary_diff'] < 0 ? 'text-danger' : '');
                $change_icon = $row['salary_diff'] > 0 ? '↑' : ($row['salary_diff'] < 0 ? '↓' : '');
            ?>
            <tr>
                <td><?= date('d-m-Y H:i', strtotime($row['changed_at'])) ?></td>
                <td>₹<?= number_format($row['old_salary'], 2) ?></td>
                <td>₹<?= number_format($row['new_salary'], 2) ?></td>
                <td class="<?= $change_class ?>">
                    <?= $change_icon ?> ₹<?= number_format(abs($row['salary_diff']), 2) ?>
                </td>
                <td><?= $row['tds_per'] ?>%</td>
                <td>₹<?= number_format($row['tds_amount'], 2) ?></td>
                <td>₹<?= number_format($row['net_salary'], 2) ?></td>
                <td><?= htmlspecialchars($row['changed_by']) ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="alert alert-warning">
    No salary history found for this employee.
</div>
<?php endif; ?>