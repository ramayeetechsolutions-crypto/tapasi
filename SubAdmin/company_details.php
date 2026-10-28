 
 
<?php
require '../db.php'; // db.php is one level above Admin
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDateTime($dt) {
    if (empty($dt) || $dt === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y H:i:s', strtotime($dt));
}

$mode = $_GET['mode'] ?? 'view';

$company = null;
if ($mode === 'view' || $mode === 'edit') {
    $stmt = $mysqli->prepare("SELECT * FROM company_master ORDER BY id LIMIT 1");
    $stmt->execute();
    $company = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$company) {
        die("No company record found in the database.");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'edit') {
    $id = (int)($company['id'] ?? 0);
    $name = trim($_POST['company_name'] ?? '');
    $pan = trim($_POST['pan_number'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_ac_name = trim($_POST['bank_account_name'] ?? '');
    $bank_ac_no = trim($_POST['bank_account_number'] ?? '');
    $bank_ifsc = trim($_POST['bank_ifsc_code'] ?? '');
    $bank_swift = trim($_POST['bank_swift_code'] ?? '');
    $address = trim($_POST['company_address'] ?? '');
    $raddress = trim($_POST['reg_address'] ?? '');
    $sac=trim($_POST['sac'] ?? '');;

    if ($id > 0) {
      $stmt = $mysqli->prepare("UPDATE company_master 
    SET company_name=?, pan_number=?, bank_name=?, bank_account_name=?, 
        bank_account_number=?, bank_ifsc_code=?, bank_swift_code=?, company_address=?,reg_address=?,SAC=? 
    WHERE id=?");
$stmt->bind_param("ssssssssssi", $name, $pan, $bank_name, $bank_ac_name, $bank_ac_no, $bank_ifsc, $bank_swift, $address,$raddress,$sac, $id);

        $stmt->execute();
        $stmt->close();

        header("Location: company_details.php?mode=view&msg=Company details updated successfully&type=success");
        exit;
    }
}


?>
<?php require 'header.php';?>
<br>
<br><br>
<div class="container mt-3">

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= h($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5>Company Master</h5>
    <?php if ($mode === 'view'): ?>
        <!--a href="company_details.php?mode=edit" class="btn btn-sm btn-primary">Edit</a-->
    <?php else: ?>
        <a href="company_details.php" class="btn btn-sm btn-secondary">Back</a>
    <?php endif; ?>
</div>

<?php if ($mode === 'view'): ?>
    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Company Name</dt>
                <dd class="col-sm-9"><?= h($company['company_name']) ?></dd>

                <dt class="col-sm-3">PAN Number</dt>
                <dd class="col-sm-9"><?= h($company['pan_number']) ?></dd>

                <dt class="col-sm-3">Bank Name</dt>
                <dd class="col-sm-9"><?= h($company['bank_name']) ?></dd>

                <dt class="col-sm-3">Bank Account Name</dt>
                <dd class="col-sm-9"><?= h($company['bank_account_name']) ?></dd>

                <dt class="col-sm-3">Bank Account Number</dt>
                <dd class="col-sm-9"><?= h($company['bank_account_number']) ?></dd>

                <dt class="col-sm-3">Bank IFSC Code</dt>
                <dd class="col-sm-9"><?= h($company['bank_ifsc_code']) ?></dd>

                <dt class="col-sm-3">Bank SWIFT Code</dt>
                <dd class="col-sm-9"><?= h($company['bank_swift_code']) ?></dd>
                
                <dt class="col-sm-3">ESI </dt>
                <dd class="col-sm-9"><?= h($company['esi_details']) ?></dd>
                
                <dt class="col-sm-3">EPF</dt>
                <dd class="col-sm-9"><?= h($company['epf_details']) ?></dd>
                
                <dt class="col-sm-3">EPF Link</dt>
                <dd class="col-sm-9">
                    <?php if (!empty($company['link'])): ?>
                        <a href="<?= h($company['link']) ?>" target="_blank">Move to link</a>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </dd>
                <dt class="col-sm-3">Company Address</dt>
                <dd class="col-sm-9" style="white-space: pre-wrap;"><?= h($company['company_address']) ?></dd>
                <dt class="col-sm-3">Registred Address</dt>
                <dd class="col-sm-9" style="white-space: pre-wrap;"><?= h($company['reg_address']) ?></dd>
 <dt class="col-sm-3">SAC</dt>
                <dd class="col-sm-9" style="white-space: pre-wrap;"><?= h($company['sac']) ?></dd>

                <dt class="col-sm-3">Created At</dt>
                <dd class="col-sm-9"><?= formatDateTime($company['created_at']) ?></dd>
            </dl>
        </div>
    </div>

<?php else: // edit ?>

    <div class="card mx-auto" style="max-width:900px;">
        <div class="card-body">
            <form method="post" autocomplete="off">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control form-control-sm" required value="<?= h($company['company_name']) ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">PAN Number</label>
                        <input type="text" name="pan_number" class="form-control form-control-sm" required value="<?= h($company['pan_number']) ?>">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Bank Name</label>
                        <input type="text" name="bank_name" class="form-control form-control-sm" required value="<?= h($company['bank_name']) ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Bank Account Name</label>
                        <input type="text" name="bank_account_name" class="form-control form-control-sm" required value="<?= h($company['bank_account_name']) ?>">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Bank Account Number</label>
                        <input type="text" name="bank_account_number" class="form-control form-control-sm" required value="<?= h($company['bank_account_number']) ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Bank IFSC Code</label>
                        <input type="text" name="bank_ifsc_code" class="form-control form-control-sm" required value="<?= h($company['bank_ifsc_code']) ?>">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Bank SWIFT Code</label>
                        <input type="text" name="bank_swift_code" class="form-control form-control-sm" required value="<?= h($company['bank_swift_code']) ?>">
                    </div>
                     <div class="col-md-6 mb-3">
                        <label class="form-label">SAC</label>
                        <input type="text" name="sac" class="form-control form-control-sm" required value="<?= h($company['sac']) ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Company Address</label>
                        <textarea name="company_address" rows="2" class="form-control form-control-sm" required><?= h($company['company_address']) ?></textarea>
                    </div>
                
   <div class="col-md-6 mb-3">
                        <label class="form-label">Registered Address</label>
                        <textarea name="reg_address" rows="2" class="form-control form-control-sm" required><?= h($company['reg_address']) ?></textarea>
                    </div>
                

                </div>   

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-sm text-white" style="background-color:#8B4513;">Update</button>
                    <a href="company_details.php" class="btn btn-sm btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
