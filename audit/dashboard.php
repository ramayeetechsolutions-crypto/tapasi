<?php
// =========================================================================
// FILE: audit/dashboard.php
// PURPOSE: Audit Executive Dashboard & Quality Operations Suite
// 100% Preserved Functionality • Senior Developer Modern UI
// =========================================================================

require_once __DIR__ . '/db.php';
$pageTitle = "Dashboard";
$currentPage = "dashboard.php";
require_once __DIR__ . '/header.php';
?>

<style>
/* =========================================================================
   SENIOR DEVELOPER EXECUTIVE SUITE STYLING
   ========================================================================= */
.main-content {
    padding-top: 85px;
    padding-bottom: 50px;
    background-color: #f8fafc;
    min-height: 100vh;
}

/* Executive Hero Banner */
.exec-hero-banner {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    border-radius: 16px;
    padding: 24px 28px;
    color: #ffffff;
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.2);
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.exec-hero-banner::after {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 320px;
    height: 320px;
    background: radial-gradient(circle, rgba(52, 152, 219, 0.15) 0%, transparent 70%);
    pointer-events: none;
}
.live-pulse-dot {
    width: 8px;
    height: 8px;
    background-color: #10b981;
    border-radius: 50%;
    display: inline-block;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: pulseLive 2s infinite;
}
@keyframes pulseLive {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

/* Section Header Badges */
.section-icon-bubble {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    font-size: 14px;
}
.section-title-wrap {
    display: flex;
    align-items: center;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e2e8f0;
}
.section-title {
    font-size: 0.88rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #334155;
    margin: 0;
}

/* Senior Executive Metric Cards */
.metric-card-senior {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
    overflow: hidden;
    height: 100%;
}
.metric-card-senior:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
    border-color: #cbd5e1;
}
.metric-icon-squircle {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.metric-val {
    font-size: 1.65rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.2;
    font-feature-settings: "tnum";
    font-variant-numeric: tabular-nums;
}
.metric-lbl {
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #64748b;
    margin-bottom: 4px;
}
.metric-sub {
    font-size: 0.76rem;
    color: #94a3b8;
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Card Themes */
.card-theme-primary .metric-icon-squircle { background: rgba(59, 130, 246, 0.12); color: #2563eb; }
.card-theme-success .metric-icon-squircle { background: rgba(16, 185, 129, 0.12); color: #059669; }
.card-theme-warning .metric-icon-squircle { background: rgba(245, 158, 11, 0.12); color: #d97706; }
.card-theme-danger .metric-icon-squircle  { background: rgba(239, 68, 68, 0.12); color: #dc2626; }
.card-theme-purple .metric-icon-squircle  { background: rgba(139, 92, 246, 0.12); color: #7c3aed; }
.card-theme-info .metric-icon-squircle    { background: rgba(14, 165, 233, 0.12); color: #0284c7; }
.card-theme-dark .metric-icon-squircle    { background: rgba(30, 41, 59, 0.08); color: #1e293b; }
.card-theme-emerald .metric-icon-squircle { background: rgba(5, 150, 105, 0.12); color: #047857; }
</style>

<div class="main-content">
    <div class="container-fluid px-lg-4 px-md-3 px-2">

        <!-- EXECUTIVE HERO BANNER -->
        <div class="exec-hero-banner">
            <div class="row align-items-center">
                <div class="col-lg-8 col-md-7 col-12 mb-3 mb-md-0">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge bg-dark bg-opacity-50 text-white border border-light border-opacity-25 px-2.5 py-1 rounded-pill" style="font-size: 0.72rem;">
                            <span class="live-pulse-dot me-1.5"></span> Live Audit Operations
                        </span>
                        <span class="badge bg-primary bg-opacity-25 text-white border border-primary border-opacity-25 px-2.5 py-1 rounded-pill" style="font-size: 0.72rem;">
                            <i class="fas fa-shield-alt me-1"></i> Quality & Compliance
                        </span>
                    </div>
                    <h3 class="fw-bold mb-1" style="letter-spacing: -0.3px;">
                        Quality Audit & Operational Overview
                    </h3>
                    <p class="text-white-50 mb-0 small">
                        Real-time inspection analytics, job compliance velocity, and revenue reconciliation.
                    </p>
                </div>
                <div class="col-lg-4 col-md-5 col-12 text-md-end text-start">
                    <div class="d-inline-flex flex-wrap align-items-center gap-2">
                        <button class="btn btn-sm btn-outline-light border-opacity-25 bg-white bg-opacity-10 d-flex align-items-center gap-2">
                            <i class="far fa-calendar-alt"></i> <span>Today: <?= date('d M, Y') ?></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION 1: PRODUCTION & LIFECYCLE AUDIT -->
        <div class="section-title-wrap">
            <span class="section-icon-bubble bg-primary bg-opacity-10 text-primary">
                <i class="fas fa-tasks"></i>
            </span>
            <h5 class="section-title">Production & Lifecycle Audit</h5>
        </div>

        <div class="row g-3 mb-4">
            <!-- Total Jobs -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="metric-card-senior card-theme-primary">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="metric-lbl">Total Jobs</div>
                            <div class="metric-val">120</div>
                            <div class="metric-sub text-primary">
                                <i class="fas fa-layer-group"></i> Across all pipelines
                            </div>
                        </div>
                        <div class="metric-icon-squircle">
                            <i class="fas fa-briefcase"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Completed Jobs -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="metric-card-senior card-theme-success">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="metric-lbl">Completed Jobs</div>
                            <div class="metric-val">98</div>
                            <div class="metric-sub text-success">
                                <i class="fas fa-check-circle"></i> 81.7% completion rate
                            </div>
                        </div>
                        <div class="metric-icon-squircle">
                            <i class="fas fa-check-double"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Jobs -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="metric-card-senior card-theme-warning">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="metric-lbl">Pending Audit</div>
                            <div class="metric-val">15</div>
                            <div class="metric-sub text-warning">
                                <i class="fas fa-clock"></i> In queue for inspection
                            </div>
                        </div>
                        <div class="metric-icon-squircle">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rejected Jobs -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="metric-card-senior card-theme-danger">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="metric-lbl">Rejected / Revision</div>
                            <div class="metric-val">7</div>
                            <div class="metric-sub text-danger">
                                <i class="fas fa-exclamation-triangle"></i> Returned for rework
                            </div>
                        </div>
                        <div class="metric-icon-squircle">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION 2: PERFORMANCE & BUSINESS METRICS -->
        <div class="section-title-wrap">
            <span class="section-icon-bubble bg-info bg-opacity-10 text-info">
                <i class="fas fa-chart-line"></i>
            </span>
            <h5 class="section-title">Performance & Business Metrics</h5>
        </div>

        <div class="row g-3 mb-4">
            <!-- Team Productivity -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="metric-card-senior card-theme-purple">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="metric-lbl">Team Productivity</div>
                            <div class="metric-val">87%</div>
                            <div class="metric-sub text-purple" style="color: #7c3aed;">
                                <i class="fas fa-arrow-trend-up"></i> +2.4% vs last week
                            </div>
                        </div>
                        <div class="metric-icon-squircle">
                            <i class="fas fa-bolt"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customer Emails / Communications -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="metric-card-senior card-theme-info">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="metric-lbl">Client Queries</div>
                            <div class="metric-val">34</div>
                            <div class="metric-sub text-info">
                                <i class="fas fa-envelope-open-text"></i> Handled inquiries
                            </div>
                        </div>
                        <div class="metric-icon-squircle">
                            <i class="fas fa-comments"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="metric-card-senior card-theme-emerald">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="metric-lbl">Total Revenue</div>
                            <div class="metric-val">₹1,20,000</div>
                            <div class="metric-sub text-success">
                                <i class="fas fa-coins"></i> Verified billing
                            </div>
                        </div>
                        <div class="metric-icon-squircle">
                            <i class="fas fa-receipt"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Active Customers -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="metric-card-senior card-theme-dark">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="metric-lbl">Active Customers</div>
                            <div class="metric-val">12</div>
                            <div class="metric-sub text-muted">
                                <i class="fas fa-building"></i> Retained accounts
                            </div>
                        </div>
                        <div class="metric-icon-squircle">
                            <i class="fas fa-users-cog"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
