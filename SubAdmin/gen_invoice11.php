<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';
require_once 'header.php';
require_once 'tcpdf/tcpdf.php';

class ClientInvoiceGenerator {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    public function getAllClients() {
        $query = "
        SELECT 
            client_id, 
            client_name, 
            company_name, 
            contact_person, 
            email, 
            phone, 
            address, 
            country, 
            gst_no, 
            preferred_communication, 
            created_date, 
            status, 
            word_rate, 
            page_rate, 
            gst_per, 
            currency
        FROM client_master 
        WHERE status = 'active'
        ORDER BY client_name";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getClientById($client_id) {
        $query = "
        SELECT 
            client_id, 
            client_name, 
            company_name, 
            contact_person, 
            email, 
            phone, 
            address, 
            country, 
            gst_no, 
            preferred_communication, 
            created_date, 
            status, 
            word_rate, 
            page_rate, 
            gst_per, 
            currency
        FROM client_master 
        WHERE client_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('s', $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    public function getClientByName($client_name) {
        $query = "
        SELECT 
            client_id, 
            client_name, 
            company_name, 
            contact_person, 
            email, 
            phone, 
            address, 
            country, 
            gst_no, 
            preferred_communication, 
            created_date, 
            status, 
            word_rate, 
            page_rate, 
            gst_per, 
            currency
        FROM client_master 
        WHERE client_name = ? AND status = 'active'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('s', $client_name);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    public function getClientJobs($client_name, $month, $year) {
        $year = (int) $year;
        $month = (int) $month;
        
        $query = "
        SELECT 
            id as job_id, 
            job_date, 
            journal as journal_issue, 
            articles as article,
            ce_date, 
            ce_done_by, 
            pages,
            '' as word_count
        FROM input_output
        WHERE 
            client = ?
            AND YEAR(job_date) = ?
            AND MONTH(job_date) = ?
        ORDER BY job_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('sii', $client_name, $year, $month);
        $stmt->execute();
        return $stmt->get_result();
    }
    
    private function createClientData($client_name) {
        $client_data = $this->getClientByName($client_name);
        
        if ($client_data) {
            return [
                'client_id' => $client_data['client_id'],
                'client_name' => $client_data['client_name'],
                'company_name' => $client_data['company_name'] ?? $client_data['client_name'],
                'contact_person' => $client_data['contact_person'] ?? 'Contact Person',
                'address' => $client_data['address'] ?? 'Client Address',
                'gst_no' => $client_data['gst_no'] ?? 'GSTIN_NOT_AVAILABLE',
                'word_rate' => (float)($client_data['word_rate'] ?? 0.10),
                'page_rate' => (float)($client_data['page_rate'] ?? 5.00),
                'gst_per' => (float)($client_data['gst_per']),
                'currency' => $client_data['currency'],
                'email' => $client_data['email'] ?? '',
                'phone' => $client_data['phone'] ?? '',
                'country' => $client_data['country'] ?? ''
            ];
        } 
    } 
    
    private function calculateJobPricing($jobs, $client_rates, $is_adjusted = false) {
        $client_page_rate = (float)($client_rates['page_rate'] ?? 5.00);
        $client_word_rate = (float)($client_rates['word_rate'] ?? 0.10);
        $client_gst_per = (float)($client_rates['gst_per'] ?? 18);
        $currency = $client_rates['currency'];
        
        $currency_symbol = '';
        switch(strtoupper($currency)) {
            case 'USD':
                $currency_symbol = '$';
                break;
            case 'INR':
                $currency_symbol = 'Rs.';
                break;
            case 'ERO':
                $currency_symbol = '€';
                break;
        }
        
        $sac_code = '998319';
        $total_gross_amount = 0;
        $job_details = [];
        $total_word_count = 0;
        $total_pages = 0;
        
        foreach ($jobs as $job) {
            $pages = $is_adjusted ? (float)$job['pages'] : (float)($job['pages'] ?? 0);
            $word_count = $is_adjusted ? (int)$job['word_count'] : (int)($job['word_count'] ?? 0);

            $amount = 0;
            $rate_used = 0;
            $unit_used = 0;
            $description = "";
            $unit_type = "Unit";

            if ($client_page_rate > 0 && $pages > 0) {
                $rate_used = $client_page_rate;
                $unit_used = $pages;
                $amount = $pages * $client_page_rate;
                $description = "";
                $unit_type = "Pages";
            } elseif ($client_word_rate > 0 && $word_count > 0) {
                $rate_used = $client_word_rate;
                $unit_used = $word_count;
                $amount = $word_count * $client_word_rate;
                $description = "";
                $unit_type = "Words";
            } else {
                $rate_used = $client_page_rate;
                $unit_used = $pages; 
                $amount = $pages * $client_page_rate;
                $description = "";
                $unit_type = "Pages";
            }
            
            $job_details[] = [
                'job_id' => $job['job_id'],
                'journal_issue' => $job['journal_issue'],
                'article' => $job['article'],
                'word_count' => $word_count,
                'pages' => $pages,
                'sac_code' => $sac_code,
                'rate_used' => $rate_used,
                'unit_used' => $unit_used, 
                'unit_type' => $unit_type,
                'description' => $description,
                'total_job_amount' => $amount
            ];
            
            $total_gross_amount += $amount;
            $total_word_count += $word_count;
            $total_pages += $pages;
        }
        
        $subtotal = $total_gross_amount;
        $gst_rate_final = $_POST['gst_rate'] ?? $client_gst_per; 
        $gst_amount = $subtotal * ($gst_rate_final / 100);
        $final_grand_total = $subtotal + $gst_amount;
        
        $currency_display = $currency_symbol;
        
        return [
            'job_details' => $job_details,
            'subtotal' => $subtotal,
            'gst_rate' => $gst_rate_final,
            'gst_amount' => $gst_amount,
            'grand_total_after_fees' => $final_grand_total,
            'total_jobs' => count($jobs),
            'total_words' => $total_word_count,
            'total_pages' => $total_pages,
            'currency_symbol' => $currency_symbol,
            'currency_display' => $currency_display,
            'client_rates' => $client_rates
        ];
    }
    
    public function generateClientPDF($client_data, $pricing_data, $month, $year) {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        $pdf->SetCreator('Invoice System');
        $pdf->SetAuthor('TAPASI Solutions LLP');
        $pdf->SetTitle('Invoice - ' . $client_data['company_name']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        
        $html = $this->generateInvoiceHTML($client_data, $pricing_data, $month, $year);
        $pdf->writeHTML($html, true, false, true, false, '');
        
        $upload_dir = 'invoices';
        $filename = $upload_dir . '/INV_' . $client_data['client_id'] . '_' . $year . '_' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.pdf';

        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) {
                throw new Exception("Failed to create directory: " . $upload_dir . ". Check permissions (CHMOD 775 or 777).");
            }
        }
        
        $full_path = dirname(__FILE__) . '/' . $filename; 
        $pdf->Output($full_path, 'F');
        
        return $filename;
    }
    
    private function generateInvoiceHTML($client, $pricing, $month, $year) {
        $company = [
            'name' => 'TAPASI Solutions LLP',
            'address' => 'No.5, Subramanian Avenue, Tambaram, Chennai - 600045', 
            'phone' => 'Tel: (044) 495-0000',
            'pan' => 'AANTT9458Q',
            'gstin' => '33AANTT9458Q1Z5',
            'bank_name' => 'ICICI Bank',
            'ac_name' => 'TAPASI Solutions LLP',
            'ac_no' => '777705385731',
            'ifsc' => 'ICIC0006128',
            'swift' => 'ICICINBECTS'
        ];

        $invoice_number = 'TPS' . date('y') . $month . $client['client_id'];
        $invoice_date = date('d-M-y', strtotime('now'));
        $currency_display = $pricing['currency_display']; 
        $gst_rate_display = number_format($pricing['gst_rate'], 0);
        
        $html = '
        <style>
            body { font-family: Arial, sans-serif; font-size: 10pt; color: #000; margin: 0; padding: 0; }
            .container { width: 100%; }
            .header { width: 100%; margin-bottom: 10px; }
            .tax-invoice-title { font-size: 22pt; font-weight: bold; color: #0056b3; text-align: right; margin: 0; padding: 0; }
            .client-section { width: 100%; margin-bottom: 15px; }
            .client-box { border: 1px solid #ccc; padding: 8px; background-color: #f9f9f9; font-size: 9pt; }
            .invoice-details { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
            .invoice-details td { padding: 3px 0; font-size: 9pt; }
            .invoice-details .label { width: 20%; font-weight: bold; }
            .items-table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9pt; }
            .items-table th, .items-table td { border: 1px solid #ddd; padding: 6px; }
            .items-table th { background-color: #e6f0ff; font-weight: bold; text-align: center; }
            .items-table td { text-align: left; }
            .items-table td:nth-child(1) { width: 5%; text-align: center; }
            .items-table td:nth-child(2) { width: 45%; }
            .items-table td:nth-child(3) { width: 10%; text-align: center; }
            .items-table td:nth-child(4) { width: 10%; text-align: right; }
            .items-table td:nth-child(5) { width: 15%; text-align: right; }
            .items-table td:nth-child(6) { width: 15%; text-align: right; }
            .footer-section { width: 100%; margin-top: 20px; }
            .bank-details { border: 1px solid #a0a0a0; padding: 8px; font-size: 8pt; background-color: #fff; margin-bottom: 10px; }
            .totals-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
            .totals-table td { border: 1px solid #a0a0a0; padding: 4px 6px; font-size: 9pt; }
            .totals-table .label { background-color: #f7f7f7; width: 60%; }
            .totals-table .value { text-align: right; width: 40%; font-weight: bold; }
            .grand-total { background-color: #0056b3 !important; color: white; font-weight: bold; }
            .signature-section { text-align: right; margin-top: 20px; }
            .thank-you { text-align: center; margin-top: 15px; font-weight: bold; }
            .company-address { text-align: center; font-size: 8pt; margin-top: 5px; }
            .hr-line { border-top: 2px solid #0056b3; margin: 5px 0 10px 0; }
        </style>
        
        <div class="container">
            <!-- Header -->
            <div class="header">
                <p class="tax-invoice-title">Tax-Invoice</p>
                <div class="hr-line"></div>
            </div>
            
            <!-- Customer Section -->
            <div class="client-section">
                <div class="client-box">
                    <strong>Customer</strong><br>
                    <strong>Company:</strong> ' . htmlspecialchars($client['company_name']) . '<br>
                    <strong>Address:</strong> ' . htmlspecialchars($client['address']) . '<br>
                    <strong>GST No:</strong> ' . htmlspecialchars($client['gst_no']) . '
                </div>
            </div>
            
            <!-- Invoice Details -->
            <table class="invoice-details">
                <tr>
                    <td class="label">Date:</td>
                    <td>' . $invoice_date . '</td>
                    <td style="width: 20%;"></td>
                    <td class="label">From:</td>
                    <td>' . htmlspecialchars($company['name']) . '</td>
                </tr>
                <tr>
                    <td class="label">Page:</td>
                    <td>1</td>
                    <td></td>
                    <td class="label">Invoice No:</td>
                    <td>' . $invoice_number . '</td>
                </tr>
                <tr>
                    <td class="label">PAN:</td>
                    <td>' . htmlspecialchars($company['pan']) . '</td>
                    <td></td>
                    <td class="label">GST:</td>
                    <td>' . htmlspecialchars($company['gstin']) . '</td>
                </tr>
            </table>
            
            <!-- Items Table -->
            <p>We are pleased to invoice you the following for your kind consideration.</p>
            
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Description</th>
                        <th>SAC Code</th>
                        <th>Qty (Pages)</th>
                        <th>Unit Price</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>';
        
        $item_counter = 1;
        foreach ($pricing['job_details'] as $job) {
            $html .= '
                    <tr>
                        <td>' . $item_counter++ . '</td>
                        <td>Copyediting+indesign formatting changes for ' . htmlspecialchars($job['journal_issue']) . '</td>
                        <td>' . htmlspecialchars($job['sac_code']) . '</td>
                        <td>' . number_format($job['unit_used'], 0) . '</td>
                        <td>' . $currency_display . ' ' . number_format($job['rate_used'], 2) . '</td>
                        <td>' . $currency_display . ' ' . number_format($job['total_job_amount'], 2) . '</td>
                    </tr>';
        }
        
        $html .= '
                </tbody>
            </table>
            
            <!-- Footer Section -->
            <div class="footer-section">
                <table width="100%">
                    <tr>
                        <td width="55%" valign="top">
                            <div class="bank-details">
                                <strong>Payment 2 weeks: Prices validity: 2 weeks</strong><br><br>
                                <strong>Our Banker\'s Account Name:</strong> ' . htmlspecialchars($company['ac_name']) . '<br>
                                <strong>Bank name:</strong> ' . htmlspecialchars($company['bank_name']) . '<br>
                                <strong>Account Number:</strong> ' . htmlspecialchars($company['ac_no']) . '<br>
                                <strong>IFSC Code:</strong> ' . htmlspecialchars($company['ifsc']) . '<br>
                                <strong>Swift Code:</strong> ' . htmlspecialchars($company['swift']) . '
                            </div>
                        </td>
                        <td width="45%" valign="top">
                            <table class="totals-table">
                                <tr>
                                    <td class="label">Total</td>
                                    <td class="value">' . $currency_display . ' ' . number_format($pricing['subtotal'], 2) . '</td>
                                </tr>
                                <tr>
                                    <td class="label">IGST (' . $gst_rate_display . '%)</td>
                                    <td class="value">' . $currency_display . ' ' . number_format($pricing['gst_amount'], 2) . '</td>
                                </tr>
                                <tr>
                                    <td class="label grand-total">Grand Total (' . $currency_display . ')</td>
                                    <td class="value grand-total">' . $currency_display . ' ' . number_format($pricing['grand_total_after_fees'], 2) . '</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <!-- Signature -->
                <div class="signature-section">
                    <p>For TAPASI SOLUTIONS LLP</p>
                    <p><strong>Authorised Signatory</strong><br>Key Accounts Manager</p>
                </div>
                
                <!-- Thank You and Address -->
                <div class="thank-you">Thank you</div>
                <div class="company-address">
                    ' . htmlspecialchars($company['address']) . ' - ' . htmlspecialchars($company['phone']) . '
                </div>
            </div>
        </div>';
        
        return $html;
    }
    
    public function getReviewData($client_name, $month, $year) {
        $client_data = $this->createClientData($client_name);
        
        $client_rates = [
            'word_rate' => $client_data['word_rate'],
            'page_rate' => $client_data['page_rate'],
            'gst_per' => $client_data['gst_per'],
            'currency' => $client_data['currency'],
        ];
        
        $jobs_result = $this->getClientJobs($client_name, $month, $year);
        $jobs = [];
        
        while ($job = $jobs_result->fetch_assoc()) {
            $jobs[] = $job;
        }
        
        if (empty($jobs)) {
            throw new Exception("No jobs found for this client in the specified period");
        }
        
        $pricing_data = $this->calculateJobPricing($jobs, $client_rates);
        
        return [
            'client' => $client_data,
            'jobs' => $jobs,
            'pricing' => $pricing_data,
            'month' => $month,
            'year' => $year
        ];
    } 
    
    public function generateSingleClientInvoiceFromAdjustedData($client_name, $month, $year, $adjusted_jobs) {
        $client = $this->createClientData($client_name);

        $client_rates = [
            'word_rate' => $client['word_rate'],
            'page_rate' => $client['page_rate'],
            'gst_per' => $client['gst_per'],
            'currency' => $client['currency'],
        ];
        
        $pricing_data = $this->calculateJobPricing($adjusted_jobs, $client_rates, true);
        
        $filename = $this->generateClientPDF($client, $pricing_data, $month, $year);
        
        return [
            'client_name' => $client_name,
            'company_name' => $client['company_name'],
            'invoice_file' => $filename,
            'total_jobs' => count($adjusted_jobs),
            'total_amount' => $pricing_data['grand_total_after_fees']
        ];
    }
}

// --- Main Script Execution ---

global $mysqli; 
$invoiceGenerator = new ClientInvoiceGenerator($mysqli);
$message = '';
$results = [];
$review_data = null;

$current_month = $_REQUEST['month'] ?? date('n');
$current_year = $_REQUEST['year'] ?? date('Y');
$current_client_name = $_REQUEST['client_name'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_review']) && $current_client_name) {
        try {
            $review_data = $invoiceGenerator->getReviewData($current_client_name, $current_month, $current_year);
            $message = "Reviewing jobs for " . htmlspecialchars($current_client_name) . ". Please make adjustments below.";
        } catch (Exception $e) {
            $message = "Error during review lookup: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['generate_final']) && $current_client_name) {
        try {
            $adjusted_jobs = [];
            foreach ($_POST['job_id'] as $index => $job_id) {
                $adjusted_jobs[] = [
                    'job_id' => $job_id,
                    'journal_issue' => $_POST['journal_issue'][$index],
                    'article' => $_POST['article'][$index], 
                    'word_count' => $_POST['word_count'][$index],
                    'pages' => $_POST['pages'][$index],
                    'rate_per_page' => $_POST['rate_per_page'][$index],
                ];
            }

            $result = $invoiceGenerator->generateSingleClientInvoiceFromAdjustedData($current_client_name, $current_month, $current_year, $adjusted_jobs);
            
            $message = "Invoice generated successfully for " . htmlspecialchars($current_client_name) . ". <a href='" . htmlspecialchars($result['invoice_file']) . "' target='_blank'>Download PDF</a>";
            $results[] = $result;

        } catch (Exception $e) {
            $message = "PDF generation failed: " . $e->getMessage();
        }
    }
}

$clients_result = $invoiceGenerator->getAllClients();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Invoice Generator</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .form-section { margin: 30px 0; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .form-group { margin: 15px 0; display: inline-block; margin-right: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        select, input[type="number"], input[type="text"] { padding: 8px; width: 150px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .message { padding: 15px; margin: 20px 0; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #721c24; }
        .results { margin-top: 30px; }
        .result-item { padding: 10px; border: 1px solid #ddd; margin: 10px 0; border-radius: 5px; }
        .download-link { color: #007bff; text-decoration: none; }
        .download-link:hover { text-decoration: underline; }
        .review-table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 0.9em; table-layout: fixed; }
        .review-table th, .review-table td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .review-table th { background-color: #f0f0f0; }
        .review-table input { width: 100%; padding: 5px; box-sizing: border-box; }
        .review-table th:nth-child(1), .review-table td:nth-child(1) { width: 10%; }
        .review-table th:nth-child(2), .review-table td:nth-child(2) { width: 40%; }
        .review-table th:nth-child(3), .review-table td:nth-child(3) { width: 10%; text-align: right; }
        .review-table th:nth-child(4), .review-table td:nth-child(4) { width: 10%; text-align: right; }
        .review-table th:nth-child(5), .review-table td:nth-child(5) { width: 10%; text-align: right; }
        .review-table th:nth-child(6), .review-table td:nth-child(6) { width: 10%; text-align: right; }
        .totals-box { margin-top: 20px; padding: 15px; border: 1px solid #007bff; background: #e6f0ff; border-radius: 5px; }
        .totals-box label { display: inline; margin-right: 10px; }
        .totals-box input { width: 80px; }
        .totals-box strong { color: #007bff; }
    </style>

    <script>
        function calculateTotals() {
            let totalSubtotal = 0;
            const rateInputs = document.querySelectorAll('input[name^="rate_per_page"]');
            const pageInputs = document.querySelectorAll('input[name^="pages"]');
            const wordInputs = document.querySelectorAll('input[name^="word_count"]');

            const totalPageInput = document.getElementById('total_pages_display');
            const totalWordInput = document.getElementById('total_words_display');
            const subtotalInput = document.getElementById('subtotal_display');
            
            const gstRateInput = document.getElementById('gst_rate');
            const gstAmountInput = document.getElementById('gst_amount_display');
            const grandTotalInput = document.getElementById('grand_total_display');
            
            let totalPages = 0;
            let totalWords = 0;

            rateInputs.forEach((rateInput, index) => {
                const rate = parseFloat(rateInput.value) || 0;
                const pages = parseFloat(pageInputs[index].value) || 0;
                const wordCount = parseFloat(wordInputs[index].value) || 0;
                
                let amount = 0;
                const clientPageRate = parseFloat(document.getElementById('client_page_rate').value) || 0;
                const clientWordRate = parseFloat(document.getElementById('client_word_rate').value) || 0;

                if (clientPageRate > 0 && pages > 0) {
                    amount = pages * rate; 
                } else if (clientWordRate > 0 && wordCount > 0) {
                    amount = wordCount * rate; 
                } else {
                    amount = pages * rate;
                }
                
                document.getElementById('amount_display_' + index).innerText = amount.toFixed(2);
                totalSubtotal += amount;
                totalPages += pages;
                totalWords += wordCount;
            });

            totalPageInput.innerText = totalPages.toFixed(2);
            totalWordInput.innerText = totalWords.toFixed(0);
            subtotalInput.innerText = totalSubtotal.toFixed(2);

            const gstRate = parseFloat(gstRateInput.value) || 0;
            const gstAmount = totalSubtotal * (gstRate / 100);
            const grandTotal = totalSubtotal + gstAmount;

            gstAmountInput.innerText = gstAmount.toFixed(2);
            grandTotalInput.innerText = grandTotal.toFixed(2);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const inputs = document.querySelectorAll('.review-table input, #gst_rate');
            inputs.forEach(input => {
                input.addEventListener('change', calculateTotals);
                input.addEventListener('keyup', calculateTotals);
            });
            
            if (document.querySelector('.review-table')) {
                calculateTotals();
            }
        });
    </script>
</head>
<body>
    <div class="container">
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, 'Error') !== false || strpos($message, 'failed') !== false ? 'error' : 'success'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="form-section">
            <h2>Select Client and Period for Review</h2>
            <form method="post">
                <div class="form-group">
                    <label for="month">Month:</label>
                    <select name="month" id="month" required>
                        <?php for($i = 1; $i <= 12; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $i == $current_month ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="year">Year:</label>
                    <select name="year" id="year" required>
                        <?php for($i = 2020; $i <= date('Y'); $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $i == $current_year ? 'selected' : ''; ?>>
                                <?php echo $i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="client_name">Select Client:</label>
                    <select name="client_name" id="client_name" required style="width: 300px;">
                        <option value="">-- Select Client --</option>
                        <?php 
                        if ($clients_result && $clients_result->num_rows > 0) {
                            $clients_result->data_seek(0);
                            while($client = $clients_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($client['client_name']); ?>" 
                                    <?php echo $client['client_name'] == $current_client_name ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($client['company_name'] ?: $client['client_name']); ?>
                                    (<?php echo htmlspecialchars($client['client_name']); ?>)
                                </option>
                            <?php endwhile;
                        } else {
                            echo '<option value="">No clients found</option>';
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" name="generate_review">Review Jobs for Invoice</button>
            </form>
        </div>

        <?php if ($review_data): ?>
            <div class="form-section">
                <h2>Invoice Review and Adjustment for <?php echo htmlspecialchars($review_data['client']['company_name']); ?></h2>
                
                <input type="hidden" id="client_page_rate" value="<?php echo htmlspecialchars($review_data['client']['page_rate'] ?? 0); ?>">
                <input type="hidden" id="client_word_rate" value="<?php echo htmlspecialchars($review_data['client']['word_rate'] ?? 0); ?>">

                <form method="post">
                    <input type="hidden" name="client_name" value="<?php echo htmlspecialchars($current_client_name); ?>">
                    <input type="hidden" name="month" value="<?php echo $current_month; ?>">
                    <input type="hidden" name="year" value="<?php echo $current_year; ?>">

                    <table class="review-table">
                        <thead>
                            <tr>
                                <th>Job ID</th>
                                <th>Journal/Issue</th>
                                <th>Word Count</th>
                                <th>Pages</th>
                                <th>Rate (<?php echo htmlspecialchars($review_data['pricing']['currency_symbol']); ?>)</th>
                                <th>Amount (<?php echo htmlspecialchars($review_data['pricing']['currency_symbol']); ?>)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $initial_pricing_details = $review_data['pricing']['job_details'];
                            foreach($review_data['jobs'] as $index => $job): 
                                $job_pricing = $initial_pricing_details[$index];
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($job['job_id']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($job['journal_issue']); ?>
                                        <input type="hidden" name="job_id[]" value="<?php echo htmlspecialchars($job['job_id']); ?>">
                                        <input type="hidden" name="article[]" value="<?php echo htmlspecialchars($job['article']); ?>">
                                        <input type="hidden" name="journal_issue[]" value="<?php echo htmlspecialchars($job['journal_issue']); ?>">
                                    </td>
                                    <td>
                                        <input type="number" name="word_count[]" value="<?php echo (int)($job['word_count'] ?? 0); ?>" style="width: 70px;">
                                    </td>
                                    <td>
                                        <input type="number" name="pages[]" value="<?php echo number_format((float)($job['pages'] ?? 0), 2, '.', ''); ?>" step="any" style="width: 70px;">
                                    </td>
                                    <td>
                                        <input type="number" name="rate_per_page[]" value="<?php echo number_format($job_pricing['rate_used'], 4, '.', ''); ?>" step="0.0001" style="width: 70px;">
                                    </td>
                                    <td id="amount_display_<?php echo $index; ?>">
                                        <?php echo number_format($job_pricing['total_job_amount'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="totals-box">
                        <div class="form-group">
                            <label>Total Pages:</label> <strong id="total_pages_display">0</strong>
                        </div>
                        <div class="form-group">
                            <label>Total Words:</label> <strong id="total_words_display">0</strong>
                        </div>
                        <hr>
                        <div class="form-group">
                            <label>Subtotal (<?php echo htmlspecialchars($review_data['pricing']['currency_symbol']); ?>):</label> <strong id="subtotal_display">0.00</strong>
                        </div>
                        
                        <div class="form-group">
                            <label for="gst_rate">GST Rate (%):</label>
                            <input type="number" id="gst_rate" name="gst_rate" value="<?php echo htmlspecialchars($review_data['client']['gst_per']); ?>" step="any" style="width: 50px;">
                            (Amount: <strong id="gst_amount_display"><?php echo htmlspecialchars($review_data['pricing']['currency_symbol']); ?>0.00</strong>)
                        </div>
                        <hr>
                        <div class="form-group" style="margin-right: 0;">
                            <label style="font-size: 1.2em;">Grand Total (<?php echo htmlspecialchars($review_data['pricing']['currency_symbol']); ?>):</label>
                            <strong id="grand_total_display" style="font-size: 1.2em; color: #007bff;">0.00</strong>
                        </div>
                    </div>
                    
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="submit" name="generate_final">Generate Final PDF Invoice</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!empty($results)): ?>
            <div class="results">
                <h2>Generated Invoices</h2>
                <?php foreach($results as $result): ?>
                    <div class="result-item success">
                        <strong><?php echo htmlspecialchars($result['company_name']); ?></strong><br>
                        Total: <?php echo htmlspecialchars($result['total_amount']); ?><br>
                        File: <a href="<?php echo htmlspecialchars($result['invoice_file']); ?>" class="download-link" target="_blank">Download Invoice</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>