<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function redirect_with($params = []) {
    $base = 'payment.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Currency Conversion Functions
class CurrencyConverter {
    private $api_key = "6aab63da7e1eed3b15b34503"; // Replace with your actual key
    private $cacheFile = 'currency_rates_cache.json';
    private $cacheTime = 3600; // 1 hour cache
    
    public function __construct() {
        if (!file_exists($this->cacheFile)) {
            file_put_contents($this->cacheFile, json_encode([]));
        }
    }
    
    public function convertToINR($amount, $fromCurrency) {
        if ($fromCurrency === 'INR') {
            return [
                'success' => true,
                'converted_amount' => $amount,
                'exchange_rate' => 1,
                'original_amount' => $amount,
                'original_currency' => 'INR'
            ];
        }
        
        $rate = $this->getExchangeRate($fromCurrency, 'INR');
        if (!$rate['success']) {
            return $rate;
        }
        
        $converted = $amount * $rate['rate'];
        
        return [
            'success' => true,
            'converted_amount' => $converted,
            'exchange_rate' => $rate['rate'],
            'original_amount' => $amount,
            'original_currency' => $fromCurrency,
            'api_used' => $rate['api_used']
        ];
    }
    
    private function getExchangeRate($fromCurrency, $toCurrency) {
        $cacheKey = $fromCurrency . '_TO_' . $toCurrency;
        
        // Check cache first
        $cachedRate = $this->getCachedRate($cacheKey);
        if ($cachedRate !== false) {
            return [
                'success' => true,
                'rate' => $cachedRate['rate'],
                'date' => $cachedRate['date'],
                'api_used' => 'cache'
            ];
        }
        
        // Try multiple APIs
        $apis = [
            'exchangerate_api' => function() use ($fromCurrency, $toCurrency) {
                return $this->fetchFromExchangeRateAPI($fromCurrency, $toCurrency);
            },
            'exchangerate_host' => function() use ($fromCurrency, $toCurrency) {
                return $this->fetchFromExchangeRateHost($fromCurrency, $toCurrency);
            }
        ];
        
        foreach ($apis as $apiName => $apiFunction) {
            $result = $apiFunction();
            if ($result['success']) {
                $this->updateCache($cacheKey, $result['rate'], $result['date']);
                $result['api_used'] = $apiName;
                return $result;
            }
        }
        
        return [
            'success' => false,
            'error' => 'All currency APIs failed. Using fallback rates.'
        ];
    }
    
    private function fetchFromExchangeRateAPI($fromCurrency, $toCurrency) {
        $url = "https://v6.exchangerate-api.com/v6/{$this->api_key}/latest/{$fromCurrency}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200 || $error) {
            return ['success' => false, 'error' => "HTTP {$httpCode}: {$error}"];
        }
        
        $data = json_decode($response, true);
        
        if ($data['result'] !== 'success' || !isset($data['conversion_rates'][$toCurrency])) {
            return ['success' => false, 'error' => 'Invalid API response'];
        }
        
        return [
            'success' => true,
            'rate' => floatval($data['conversion_rates'][$toCurrency]),
            'date' => date('Y-m-d', $data['time_last_update_unix'])
        ];
    }
    
    private function fetchFromExchangeRateHost($fromCurrency, $toCurrency) {
        $url = "https://api.exchangerate.host/latest?base={$fromCurrency}&symbols={$toCurrency}";
        
        $response = @file_get_contents($url);
        if ($response === false) {
            return ['success' => false, 'error' => 'Network error'];
        }
        
        $data = json_decode($response, true);
        
        if (!$data['success'] || !isset($data['rates'][$toCurrency])) {
            return ['success' => false, 'error' => 'Invalid API response'];
        }
        
        return [
            'success' => true,
            'rate' => floatval($data['rates'][$toCurrency]),
            'date' => $data['date']
        ];
    }
    
    private function getCachedRate($cacheKey) {
        $cacheData = json_decode(file_get_contents($this->cacheFile), true);
        
        if (isset($cacheData[$cacheKey])) {
            $cached = $cacheData[$cacheKey];
            if ((time() - $cached['timestamp']) < $this->cacheTime) {
                return $cached;
            }
        }
        
        return false;
    }
    
    private function updateCache($cacheKey, $rate, $date) {
        $cacheData = json_decode(file_get_contents($this->cacheFile), true);
        
        $cacheData[$cacheKey] = [
            'rate' => $rate,
            'date' => $date,
            'timestamp' => time()
        ];
        
        file_put_contents($this->cacheFile, json_encode($cacheData));
    }
    
    // Fallback manual rates (update these periodically)
    public function getFallbackRates() {
        return [
            'USD_TO_INR' => 83.25,
            'EUR_TO_INR' => 90.50
        ];
    }
}

// Initialize currency converter
$currencyConverter = new CurrencyConverter();

// Handle Auto Reconciliation
if (isset($_POST['action']) && $_POST['action'] === 'reconcile') {
    $result = autoReconcilePayments();
    if ($result['success']) {
        redirect_with(['msg' => $result['message'], 'type' => 'success']);
    } else {
        redirect_with(['msg' => $result['message'], 'type' => 'danger']);
    }
}

// Handle Manual Payment Match
if (isset($_POST['action']) && $_POST['action'] === 'manual_match') {
    $transaction_id = (int)$_POST['transaction_id'];
    $invoice_id = (int)$_POST['invoice_id'];
    
    $result = manualPaymentMatch($transaction_id, $invoice_id);
    if ($result['success']) {
        redirect_with(['msg' => $result['message'], 'type' => 'success']);
    } else {
        redirect_with(['msg' => $result['message'], 'type' => 'danger']);
    }
}

function autoReconcilePayments() {
    global $mysqli, $currencyConverter;
              
    try {
        // Get all clients with templates
        $clients = $mysqli->query("
            SELECT client_id, client_name, company_name, template, tds_percentage, currency
            FROM client_master 
            WHERE status = 'Active' AND template IS NOT NULL AND template != ''
        ");
        
        $matched_count = 0;
        $errors = [];
        
        while ($client = $clients->fetch_assoc()) {
            // Get unpaid invoices for this client
            $invoices = $mysqli->query("
                SELECT invoice_id, invoice_no, grand_total, invoice_date, raised_month 
                FROM invoices 
                WHERE client_id = '{$client['client_id']}' 
                AND status = 'Pending'
            ");
            
            while ($invoice = $invoices->fetch_assoc()) {
                // Search for matching bank transactions
                $template_pattern = '%' . $client['template'] . '%';
                $amount = $invoice['grand_total'];
                
                // Convert amount to client's currency for matching
                $client_currency = $client['currency'] ?? 'INR';
                if ($client_currency !== 'INR') {
                    $conversion = $currencyConverter->convertToINR($amount, $client_currency);
                    if ($conversion['success']) {
                        $amount = $conversion['original_amount']; // Amount in client's currency
                    }
                }
                
                $transactions = $mysqli->query("
                    SELECT id, trans_id, value_date, transaction_remarks, deposit, balance 
                    FROM bank_transactions 
                    WHERE transaction_remarks LIKE '{$template_pattern}'
                    AND deposit = {$amount}
                    AND id NOT IN (SELECT transaction_id FROM payment_reconciliations WHERE transaction_id IS NOT NULL)
                ");
                
                if ($transactions->num_rows > 0) {
                    while ($transaction = $transactions->fetch_assoc()) {
                        $result = recordPayment($client, $invoice, $transaction);
                        if ($result['success']) {
                            $matched_count++;
                        } else {
                            $errors[] = $result['message'];
                        }
                    }
                }
            }
        }
        
        $message = "Auto-reconciliation completed. Matched {$matched_count} payments.";
        if (!empty($errors)) {
            $message .= " Errors: " . implode(', ', array_slice($errors, 0, 5));
        }
        
        return ['success' => true, 'message' => $message, 'matched_count' => $matched_count];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error during reconciliation: ' . $e->getMessage()];
    }
}

function manualPaymentMatch($transaction_id, $invoice_id) {
    global $mysqli, $currencyConverter;
    
    try {
        // Get transaction details
        $transaction_stmt = $mysqli->prepare("
            SELECT * FROM bank_transactions WHERE id = ?
        ");
        $transaction_stmt->bind_param("i", $transaction_id);
        $transaction_stmt->execute();
        $transaction = $transaction_stmt->get_result()->fetch_assoc();
        $transaction_stmt->close();
        
        if (!$transaction) {
            return ['success' => false, 'message' => 'Transaction not found'];
        }
        
        // Get invoice details
        $invoice_stmt = $mysqli->prepare("
            SELECT i.*, cm.client_id, cm.client_name, cm.tds_percentage, cm.currency
            FROM invoices i 
            LEFT JOIN client_master cm ON i.client_id = cm.client_id 
            WHERE i.invoice_id = ?
        ");
        $invoice_stmt->bind_param("i", $invoice_id);
        $invoice_stmt->execute();
        $invoice = $invoice_stmt->get_result()->fetch_assoc();
        $invoice_stmt->close();
        
        if (!$invoice) {
            return ['success' => false, 'message' => 'Invoice not found'];
        }
        
        // Check if invoice is already paid
        if ($invoice['status'] === 'Paid') {
            return ['success' => false, 'message' => 'Invoice is already paid'];
        }
        
        // Check if transaction is already used
        $existing_stmt = $mysqli->prepare("
            SELECT * FROM payment_reconciliations WHERE transaction_id = ?
        ");
        $existing_stmt->bind_param("i", $transaction_id);
        $existing_stmt->execute();
        $existing = $existing_stmt->get_result()->fetch_assoc();
        $existing_stmt->close();
        
        if ($existing) {
            return ['success' => false, 'message' => 'Transaction already used for another payment'];
        }
        
        // Record payment
        $client_data = [
            'client_id' => $invoice['client_id'],
            'client_name' => $invoice['client_name'],
            'tds_percentage' => $invoice['tds_percentage'],
            'currency' => $invoice['currency'] ?? 'INR'
        ];
        
        $result = recordPayment($client_data, $invoice, $transaction);
        return $result;
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error in manual match: ' . $e->getMessage()];
    }
}

function recordPayment($client, $invoice, $transaction) {
    global $mysqli, $currencyConverter;
    
    try {
        $mysqli->begin_transaction();
        
        $client_currency = $client['currency'] ?? 'INR';
        $deposit_amount = $transaction['deposit'];
        
        // Convert transaction amount to INR if client uses foreign currency
        $conversion_data = [];
        $invoice_amount_inr = $invoice['grand_total'];
        
        if ($client_currency !== 'INR') {
            $conversion = $currencyConverter->convertToINR($deposit_amount, $client_currency);
            if ($conversion['success']) {
                $conversion_data = [
                    'original_currency' => $conversion['original_currency'],
                    'original_amount' => $conversion['original_amount'],
                    'exchange_rate' => $conversion['exchange_rate'],
                    'converted_amount' => $conversion['converted_amount']
                ];
                $deposit_amount = $conversion['converted_amount']; // Now in INR
            } else {
                // Use fallback rates if API fails
                $fallback_rates = $currencyConverter->getFallbackRates();
                $rate_key = $client_currency . '_TO_INR';
                if (isset($fallback_rates[$rate_key])) {
                    $exchange_rate = $fallback_rates[$rate_key];
                    $conversion_data = [
                        'original_currency' => $client_currency,
                        'original_amount' => $transaction['deposit'],
                        'exchange_rate' => $exchange_rate,
                        'converted_amount' => $transaction['deposit'] * $exchange_rate,
                        'using_fallback' => true
                    ];
                    $deposit_amount = $conversion_data['converted_amount'];
                }
            }
        }
        
        // Calculate TDS amount if applicable
        $tds_amount = 0;
        if ($client['tds_percentage'] > 0) {
            $tds_amount = ($invoice_amount_inr * $client['tds_percentage']) / 100;
        }
        
        $net_amount = $invoice_amount_inr - $tds_amount;
        
        // Insert into payment_reconciliations table
        $payment_stmt = $mysqli->prepare("
            INSERT INTO payment_reconciliations (
                client_id, client_name, invoice_id, invoice_no, invoice_amount,
                transaction_id, transaction_ref, transaction_date, transaction_remarks,
                deposited_amount, original_currency, original_amount, exchange_rate, converted_amount,
                tds_percentage, tds_amount, net_amount,
                payment_date, reconciled_date, status, matched_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Paid', ?)
        ");
        
        $matched_by = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'System';
        $payment_date = $transaction['value_date'] ?? $transaction['transaction_date'];
        
        $original_currency = $conversion_data['original_currency'] ?? 'INR';
        $original_amount = $conversion_data['original_amount'] ?? $deposit_amount;
        $exchange_rate = $conversion_data['exchange_rate'] ?? 1;
        $converted_amount = $conversion_data['converted_amount'] ?? $deposit_amount;
        
        $payment_stmt->bind_param(
            "ssisdsisssdssdddddss",
            $client['client_id'],
            $client['client_name'],
            $invoice['invoice_id'],
            $invoice['invoice_no'],
            $invoice_amount_inr,
            $transaction['id'],
            $transaction['trans_id'],
            $payment_date,
            $transaction['transaction_remarks'],
            $deposit_amount,
            $original_currency,
            $original_amount,
            $exchange_rate,
            $converted_amount,
            $client['tds_percentage'],
            $tds_amount,
            $net_amount,
            $payment_date,
            $matched_by
        );
        
        $payment_stmt->execute();
        $payment_id = $payment_stmt->insert_id;
        $payment_stmt->close();
        
        // Update invoice status to Paid
        $update_invoice_stmt = $mysqli->prepare("
            UPDATE invoices SET status = 'Paid' WHERE invoice_id = ?
        ");
        $update_invoice_stmt->bind_param("i", $invoice['invoice_id']);
        $update_invoice_stmt->execute();
        $update_invoice_stmt->close();
        
        $mysqli->commit();
        
        return [
            'success' => true, 
            'message' => "Payment recorded for invoice {$invoice['invoice_no']}",
            'payment_id' => $payment_id,
            'conversion_applied' => !empty($conversion_data)
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        return ['success' => false, 'message' => 'Error recording payment: ' . $e->getMessage()];
    }
}

// Get filter parameters
$month = $_GET['month'] ?? date('F');
$year = $_GET['year'] ?? date('Y');
$status = $_GET['status'] ?? 'all';

// Get unmatched transactions
$unmatched_transactions = $mysqli->query("
    SELECT bt.* 
    FROM bank_transactions bt
    LEFT JOIN payment_reconciliations pr ON bt.id = pr.transaction_id
    WHERE pr.id IS NULL 
    AND bt.deposit > 0
    ORDER BY bt.value_date DESC
    LIMIT 100
");

// Get unpaid invoices with client currency info
$unpaid_invoices = $mysqli->query("
    SELECT i.*, cm.client_name, cm.company_name, cm.template, cm.currency
    FROM invoices i
    LEFT JOIN client_master cm ON i.client_id = cm.client_id
    WHERE i.status = 'Pending'
    ORDER BY i.invoice_date DESC
    LIMIT 100
");

// Get recent reconciliations with currency info
$reconciliations = $mysqli->query("
    SELECT pr.*, cm.company_name, cm.currency
    FROM payment_reconciliations pr
    LEFT JOIN client_master cm ON pr.client_id = cm.client_id
    ORDER BY pr.reconciled_date DESC
    LIMIT 50
");

// Get statistics
$stats = $mysqli->query("
    SELECT 
        (SELECT COUNT(*) FROM invoices WHERE status = 'Pending') as unpaid_invoices,
        (SELECT COUNT(*) FROM bank_transactions WHERE deposit > 0 AND id NOT IN (SELECT transaction_id FROM payment_reconciliations WHERE transaction_id IS NOT NULL)) as unmatched_transactions,
        (SELECT COUNT(*) FROM payment_reconciliations WHERE DATE(reconciled_date) = CURDATE()) as today_reconciliations,
        (SELECT SUM(invoice_amount) FROM payment_reconciliations WHERE DATE(reconciled_date) = CURDATE()) as today_collection
")->fetch_assoc();

// Get available months
$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Reconciliation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>

        .card-header {
            background-color: #707981 !important;
            color: white !important;
        }
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 2000px;
            margin: 0 auto;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
            margin-bottom: 20px;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
            border: none;
        }
        .table td {
            vertical-align: middle;
        }
        .amount-cell {
            text-align: right;
            font-weight: 500;
        }
        .status-badge {
            font-size: 0.75em;
        }
        .match-highlight {
            background-color: #fff3cd !important;
            border-left: 4px solid #ffc107;
        }
        .auto-match-btn {
            background: linear-gradient(135deg, #667eea 0%, #FAE6FA 100%);
            color: white;
            border: none;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #FAE6FA 100%);
            color: white;
            border-radius: 10px;
        }
        .currency-badge {
            font-size: 0.7em;
            margin-left: 5px;
        }
        .conversion-info {
            font-size: 0.8em;
            color: #6c757d;
        }
        .foreign-currency-row {
            background-color: #f0f8ff !important;
            border-left: 4px solid #007bff;
        }
    </style>
</head>
<body>
    <?php if (file_exists('header.php')) require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-money-check me-2"></i>Payment Reconciliation</h4>
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="reconcile">
                <button type="submit" class="btn auto-match-btn" onclick="return confirm('Run auto-reconciliation? This will match payments based on client templates.')">
                    <i class="fas fa-robot me-1"></i> Auto Reconcile Payments
                </button>
            </form>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h5><i class="fas fa-file-invoice me-2"></i>Unpaid Invoices</h5>
                        <h2><?= $stats['unpaid_invoices'] ?></h2>
                        <p class="mb-0">Pending Payments</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h5><i class="fas fa-exchange-alt me-2"></i>Unmatched</h5>
                        <h2><?= $stats['unmatched_transactions'] ?></h2>
                        <p class="mb-0">Bank Transactions</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h5><i class="fas fa-check-circle me-2"></i>Today's Reconciliations</h5>
                        <h2><?= $stats['today_reconciliations'] ?></h2>
                        <p class="mb-0">Successful Matches</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h5><i class="fas fa-money-bill-wave me-2"></i>Today's Collection</h5>
                        <h2>₹<?= number_format($stats['today_collection'] ?? 0, 2) ?></h2>
                        <p class="mb-0">Total Received</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Unmatched Bank Transactions -->
            <div class="col-md-6">
                <div class="card">
                    <!-- <div class="card-header bg-warning text-dark"> -->
                        <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-unlink me-2"></i>Unmatched Bank Transactions</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 500px;">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Ref No</th>
                                        <th>Remarks</th>
                                        <th>Amount (₹)</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($unmatched_transactions->num_rows > 0): ?>
                                        <?php while ($transaction = $unmatched_transactions->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <small><?= formatDate($transaction['value_date']) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= h($transaction['trans_id']) ?></span>
                                            </td>
                                            <td>
                                                <small title="<?= h($transaction['transaction_remarks']) ?>">
                                                    <?= strlen($transaction['transaction_remarks']) > 30 ? substr($transaction['transaction_remarks'], 0, 30) . '...' : h($transaction['transaction_remarks']) ?>
                                                </small>
                                            </td>
                                            <td class="amount-cell text-success">
                                                <strong>₹<?= number_format($transaction['deposit'], 2) ?></strong>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#matchModal"
                                                        data-transaction-id="<?= $transaction['id'] ?>"
                                                        data-transaction-amount="<?= $transaction['deposit'] ?>"
                                                        data-transaction-remarks="<?= h($transaction['transaction_remarks']) ?>">
                                                    <i class="fas fa-link me-1"></i> Match
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4">
                                                <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                                                <p class="text-muted mb-0">All transactions are matched</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Unpaid Invoices -->
            <div class="col-md-6">
                <div class="card">
                    <!-- <div class="card-header bg-danger text-white"> -->
                        <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Unpaid Invoices</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 500px;">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Invoice No</th>
                                        <th>Client</th>
                                        <th>Date</th>
                                        <th>Template</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($unpaid_invoices->num_rows > 0): ?>
                                        <?php while ($invoice = $unpaid_invoices->fetch_assoc()): ?>
                                        <tr class="<?= ($invoice['currency'] !== 'INR') ? 'foreign-currency-row' : '' ?>">
                                            <td>
                                                <span class="badge bg-primary"><?= h($invoice['invoice_no']) ?></span>
                                            </td>
                                            <td>
                                                <div><strong><?= h($invoice['client_name']) ?></strong></div>
                                                <small class="text-muted"><?= h($invoice['company_name']) ?></small>
                                                <?php if ($invoice['currency'] !== 'INR'): ?>
                                                    <span class="badge bg-warning currency-badge"><?= h($invoice['currency']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?= formatDate($invoice['invoice_date']) ?></small>
                                            </td>
                                            <td>
                                                <small class="badge bg-info"><?= h($invoice['template'] ?? 'No Template') ?></small>
                                            </td>
                                            <td class="amount-cell">
                                                <strong>₹<?= number_format($invoice['grand_total'], 2) ?></strong>
                                                <?php if ($invoice['currency'] !== 'INR'): ?>
                                                    <br>
                                                    <small class="conversion-info">
                                                        (in <?= $invoice['currency'] ?>)
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-warning">Pending</span>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4">
                                                <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                                                <p class="text-muted mb-0">All invoices are paid</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Reconciliations -->
        <div class="card mt-4">
            <!-- <div class="card-header bg-success text-white"> -->
                <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Payment Reconciliations</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Invoice No</th>
                                <th>Transaction Ref</th>
                                <th>Invoice Amount</th>
                                <th>TDS Amount</th>
                                <th>Net Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($reconciliations->num_rows > 0): ?>
                                <?php while ($recon = $reconciliations->fetch_assoc()): ?>
                                <tr class="<?= ($recon['original_currency'] !== 'INR' && $recon['original_currency'] !== null) ? 'foreign-currency-row' : '' ?>">
                                    <td>
                                        <small><?= formatDate($recon['reconciled_date']) ?></small>
                                    </td>
                                    <td>
                                        <?= h($recon['client_name']) ?>
                                        <?php if ($recon['original_currency'] !== 'INR' && $recon['original_currency'] !== null): ?>
                                            <span class="badge bg-warning currency-badge"><?= h($recon['original_currency']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?= h($recon['invoice_no']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= h($recon['transaction_ref']) ?></span>
                                    </td>
                                    <td class="amount-cell">
                                        ₹<?= number_format($recon['invoice_amount'], 2) ?>
                                        <?php if ($recon['original_currency'] !== 'INR' && $recon['original_currency'] !== null): ?>
                                            <br>
                                            <small class="conversion-info">
                                                (<?= $recon['original_currency'] ?> <?= number_format($recon['original_amount'], 2) ?>)
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount-cell text-danger">
                                        <?= $recon['tds_amount'] > 0 ? '₹' . number_format($recon['tds_amount'], 2) : '—' ?>
                                    </td>
                                    <td class="amount-cell text-success">
                                        <strong>₹<?= number_format($recon['net_amount'], 2) ?></strong>
                                        <?php if ($recon['exchange_rate'] && $recon['exchange_rate'] != 1): ?>
                                            <br>
                                            <small class="conversion-info">
                                                Rate: 1 <?= $recon['original_currency'] ?> = ₹<?= number_format($recon['exchange_rate'], 2) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-success"><?= h($recon['status']) ?></span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="fas fa-info-circle text-muted fa-2x mb-2"></i>
                                        <p class="text-muted mb-0">No reconciliations found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Match Modal -->
    <div class="modal fade" id="matchModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Manual Payment Match</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="manual_match">
                    <input type="hidden" name="transaction_id" id="modal_transaction_id">
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Transaction Details:</strong>
                                <div id="transaction_details"></div>
                            </div>
                            <div class="col-md-6">
                                <strong>Select Invoice to Match:</strong>
                                <select class="form-select" name="invoice_id" id="invoice_select" required>
                                    <option value="">Select Invoice</option>
                                    <?php 
                                    $unpaid_invoices->data_seek(0);
                                    while ($invoice = $unpaid_invoices->fetch_assoc()): 
                                    ?>
                                        <option value="<?= $invoice['invoice_id'] ?>" 
                                                data-amount="<?= $invoice['grand_total'] ?>"
                                                data-currency="<?= $invoice['currency'] ?>">
                                            <?= h($invoice['invoice_no']) ?> - <?= h($invoice['client_name']) ?> 
                                            (₹<?= number_format($invoice['grand_total'], 2) ?>)
                                            <?php if ($invoice['currency'] !== 'INR'): ?>
                                                [<?= $invoice['currency'] ?> Client]
                                            <?php endif; ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div id="amount_match_status"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="confirm_match_btn">Confirm Match</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const matchModal = document.getElementById('matchModal');
            
            matchModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const transactionId = button.getAttribute('data-transaction-id');
                const transactionAmount = button.getAttribute('data-transaction-amount');
                const transactionRemarks = button.getAttribute('data-transaction-remarks');
                
                document.getElementById('modal_transaction_id').value = transactionId;
                document.getElementById('transaction_details').innerHTML = `
                    <div>Amount: <strong>₹${parseFloat(transactionAmount).toLocaleString('en-IN', {minimumFractionDigits: 2})}</strong></div>
                    <div>Remarks: <small>${transactionRemarks}</small></div>
                `;
                
                // Reset form
                document.getElementById('invoice_select').value = '';
                document.getElementById('amount_match_status').innerHTML = '';
                document.getElementById('confirm_match_btn').disabled = true;
            });
            
            // Check amount match
            document.getElementById('invoice_select').addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const invoiceAmount = selectedOption.getAttribute('data-amount');
                const invoiceCurrency = selectedOption.getAttribute('data-currency');
                const transactionAmount = parseFloat(document.querySelector('[data-transaction-amount]').getAttribute('data-transaction-amount'));
                
                const statusDiv = document.getElementById('amount_match_status');
                const confirmBtn = document.getElementById('confirm_match_btn');
                
                if (invoiceAmount) {
                    const invAmount = parseFloat(invoiceAmount);
                    let message = '';
                    
                    if (invoiceCurrency !== 'INR') {
                        message = `<div class="alert alert-info mt-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Foreign currency client (${invoiceCurrency}). Amount will be converted to INR during matching.
                        </div>`;
                        confirmBtn.disabled = false;
                    } else if (Math.abs(invAmount - transactionAmount) < 0.01) {
                        message = '<div class="alert alert-success mt-2"><i class="fas fa-check-circle me-1"></i>Amounts match perfectly!</div>';
                        confirmBtn.disabled = false;
                    } else {
                        message = `<div class="alert alert-warning mt-2">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            Amount mismatch! Invoice: ₹${invAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}, 
                            Transaction: ₹${transactionAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}
                        </div>`;
                        confirmBtn.disabled = false;
                    }
                    
                    statusDiv.innerHTML = message;
                } else {
                    statusDiv.innerHTML = '';
                    confirmBtn.disabled = true;
                }
            });
        });
    </script>
</body>
</html>