<?php
// UQ Executive Process Navigation
// All routes point to Executive/ local files — NEVER SubTL
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!-- CE Section -->
<li>
    <a href="#"><i class="fas fa-chart-bar"></i> CE</a>
    <ul class="nav-dropdown">
        <li>
            <a href="mail_subject_master.php">
                <i class="fas fa-envelope"></i> Job Entry
            </a>
        </li>
        <li>
            <a href="jobs.php">
                <i class="fas fa-tasks"></i> Manage Jobs
            </a>
        </li>
        <li>
            <a href="ce_assign_tm.php" class="<?= $currentPage === 'ce_assign_tm.php' ? 'active' : '' ?>">
                <i class="fas fa-user-plus"></i> CE Assign
            </a>
        </li>
        <li>
            <a href="jobmgmt_ce.php" class="<?= $currentPage === 'jobmgmt_ce.php' ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i> CE Report
            </a>
        </li>
        <li>
            <a href="ce_meeting.php" class="<?= $currentPage === 'ce_meeting.php' ? 'active' : '' ?>">
                <i class="fas fa-handshake"></i> CE Meeting
            </a>
        </li>
    </ul>
</li>

<!-- LE Section -->
<li>
    <a href="#"><i class="fas fa-chart-line"></i> LE</a>
    <ul class="nav-dropdown">
        <li>
            <a href="alljobs.php?job=CE" class="<?= ($currentPage === 'alljobs.php') ? 'active' : '' ?>">
                <i class="fas fa-upload"></i> LE Assign-CE
            </a>
        </li>
        <li>
            <a href="le_assign_tm.php" class="<?= $currentPage === 'le_assign_tm.php' ? 'active' : '' ?>">
                <i class="fas fa-user-plus"></i> LE Assign-TS
            </a>
        </li>
        <li>
            <a href="jobmgmt_le.php" class="<?= $currentPage === 'jobmgmt_le.php' ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i> LE Report
            </a>
        </li>
        <li>
            <a href="le_meeting.php" class="<?= $currentPage === 'le_meeting.php' ? 'active' : '' ?>">
                <i class="fas fa-handshake"></i> LE Meeting
            </a>
        </li>
    </ul>
</li>

<!-- TS Section -->
<li>
    <a href="#"><i class="fas fa-chart-pie"></i> TS</a>
    <ul class="nav-dropdown">
        <li>
            <a href="mail_subject_master.php">
                <i class="fas fa-envelope"></i> Job Entry
            </a>
        </li>
        <li>
            <a href="jobs.php">
                <i class="fas fa-tasks"></i> Manage Jobs
            </a>
        </li>
        <li>
            <a href="pag_assign_tm.php" class="<?= $currentPage === 'pag_assign_tm.php' ? 'active' : '' ?>">
                <i class="fas fa-user-plus"></i> TS Assign
            </a>
        </li>
        <li>
            <a href="jobmgmt_pag.php" class="<?= $currentPage === 'jobmgmt_pag.php' ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i> TS Report
            </a>
        </li>
        <li>
            <a href="ts_meeting.php" class="<?= $currentPage === 'ts_meeting.php' ? 'active' : '' ?>">
                <i class="fas fa-handshake"></i> TS Meeting
            </a>
        </li>
    </ul>
</li>

<!-- QC Section -->
<li>
    <a href="#"><i class="fas fa-check-double"></i> QC</a>
    <ul class="nav-dropdown">
        <li>
            <a href="qc_assign_tm.php" class="<?= $currentPage === 'qc_assign_tm.php' ? 'active' : '' ?>">
                <i class="fas fa-user-plus"></i> QC Assign
            </a>
        </li>
        <li>
            <a href="qc_crx_assign_tm.php" class="<?= $currentPage === 'qc_crx_assign_tm.php' ? 'active' : '' ?>">
                <i class="fas fa-user-check"></i> QC_CRX Assign
            </a>
        </li>
        <li>
            <a href="jobmgmt_qc.php" class="<?= $currentPage === 'jobmgmt_qc.php' ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i> QC Report
            </a>
        </li>
        <li>
            <a href="qc_meeting.php" class="<?= $currentPage === 'qc_meeting.php' ? 'active' : '' ?>">
                <i class="fas fa-handshake"></i> QC Meeting
            </a>
        </li>
    </ul>
</li>

<!-- AP Section -->
<li>
    <a href="#"><i class="fas fa-layer-group"></i> AP</a>
    <ul class="nav-dropdown">
        <li>
            <a href="ap_assign.php" class="<?= $currentPage === 'ap_assign.php' ? 'active' : '' ?>">
                <i class="fas fa-user-plus"></i> AP Assign
            </a>
        </li>
        <li>
            <a href="#">
                <i class="fas fa-file-alt"></i> AP Report
            </a>
        </li>
    </ul>
</li>

<!-- WK Section -->
<li>
    <a href="#"><i class="fas fa-book"></i> WK</a>
    <ul class="nav-dropdown">
        <li>
            <a href="wk_ts_assign.php" class="<?= $currentPage === 'wk_ts_assign.php' ? 'active' : '' ?>">
                <i class="fas fa-user-plus"></i> WK TS Assign
            </a>
        </li>
        <li>
            <a href="wk_cs_assign.php" class="<?= $currentPage === 'wk_cs_assign.php' ? 'active' : '' ?>">
                <i class="fas fa-user-plus"></i> WK CS Assign
            </a>
        </li>
        <li>
            <a href="wk_le_assign.php" class="<?= $currentPage === 'wk_le_assign.php' ? 'active' : '' ?>">
                <i class="fas fa-user-plus"></i> WK LE Assign
            </a>
        </li>
        <li>
            <a href="wk_report.php" class="<?= $currentPage === 'wk_report.php' ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i> WK Report
            </a>
        </li>
    </ul>
</li>
