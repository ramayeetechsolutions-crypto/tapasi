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
        general_email,
        invoice_email,
        production_email,
        phone, 
        address, 
        country, 
        gst_no, 
        cgst,
        igst,
        paypal_email,
        paypal_percentage,
        preferred_communication, 
        preferred_language,
        created_date, 
        status, 
        word_rate, 
        page_rate,
        math_rate,
        gst_per, 
        currency
    FROM client_master 
    WHERE status = 'active'
    ORDER BY client_name";
    
    $stmt = $this->conn->prepare($query);
    $stmt->execute();
    return $stmt->get_result();
}
    /**
     * Get client details by client_id from client_master table
     */
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
            math_rate,
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

    /**
     * Get client details by client_name from client_master table
     */
/**
 * Get client details by client_name from client_master table
 */
public function getClientByName($client_name) {
    $query = "
    SELECT 
        client_id, 
        client_name, 
        company_name, 
        contact_person, 
        email, 
        general_email,
        invoice_email,
        production_email,
        phone, 
        address, 
        country, 
        gst_no, 
        cgst,
        igst,
        paypal_email,
        paypal_percentage,
        preferred_communication, 
        preferred_language,
        created_date, 
        status, 
        word_rate, 
        page_rate,
        math_rate,
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
    /**
     * Get jobs for a specific client from input_output table (all jobs, not just completed)
     */
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
            math,
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
    
    /**
     * Create client data from client_master table
     */
 /**
 * Create client data from client_master table with GST calculation logic
 */
private function createClientData($client_name) {
    // Try to get client from client_master table first
    $client_data = $this->getClientByName($client_name);
    
    if ($client_data) {
        // Calculate GST percentage based on available fields
        $gst_percentage = $this->calculateGstPercentage($client_data);
        
        // Client exists in client_master table
        return [
            'client_id' => $client_data['client_id'],
            'client_name' => $client_data['client_name'],
            'company_name' => $client_data['company_name'] ?? $client_data['client_name'],
            'contact_person' => $client_data['contact_person'] ?? 'Contact Person',
            'address' => $client_data['address'] ?? 'Client Address',
            'gst_no' => $client_data['gst_no'] ?? 'GSTIN_NOT_AVAILABLE',
            'word_rate' => (float)($client_data['word_rate'] ?? 0.10),
            'page_rate' => (float)($client_data['page_rate'] ?? 5.00),
            'math_rate' => (float)($client_data['math_rate'] ?? 2.00),
            'gst_per' => $gst_percentage,
            'cgst' => (float)($client_data['cgst'] ?? 0),
            'igst' => (float)($client_data['igst'] ?? 0),
            'paypal_percentage' => (float)($client_data['paypal_percentage'] ?? 0),
            'currency' => $client_data['currency'] ?? 'USD',
            'email' => $client_data['email'] ?? '',
            'phone' => $client_data['phone'] ?? '',
            'country' => $client_data['country'] ?? ''
        ];
    } 
}
    /**
     * Calculate pricing based on default rates
     */
  private function calculateJobPricing($jobs, $client_rates, $is_adjusted = false) {
    // Get company details for SAC code with safe fallback
    $company_data = $this->getCompanyDetails();
    $sac_code = '998319'; // Default value
    
    if ($company_data && isset($company_data['sac'])) {
        $sac_code = $company_data['sac'];
    }
    
    $client_page_rate = (float)($client_rates['page_rate'] ?? 5.00);
    $client_word_rate = (float)($client_rates['word_rate'] ?? 0.10);
    $client_math_rate = (float)($client_rates['math_rate'] ?? 2.00);
    $client_gst_per = (float)($client_rates['gst_per'] ?? 18);
    $client_cgst = (float)($client_rates['cgst'] ?? 0);
    $client_igst = (float)($client_rates['igst'] ?? 0);
    $client_paypal_percentage = (float)($client_rates['paypal_percentage'] ?? 0);
    $currency = $client_rates['currency'] ?? 'USD';
    
    // Set currency symbol based on currency code
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
        default:
            $currency_symbol = 'Rs.';
    }
    
    $total_gross_amount = 0;
    $job_details = [];
    $total_word_count = 0;
    $total_pages = 0;
    $total_math_count = 0;
    
    foreach ($jobs as $job) {
        $pages = $is_adjusted ? (float)($job['pages'] ?? 0) : (float)($job['pages'] ?? 0);
        $word_count = $is_adjusted ? (int)($job['word_count'] ?? 0) : (int)($job['word_count'] ?? 0);
        $math_count = $is_adjusted ? (int)($job['math'] ?? 0) : (int)($job['math'] ?? 0);

        $amount = 0;
        $rate_used = 0;
        $unit_used = 0;
        $description = "";
        $unit_type = "Unit";

        // Pricing Logic - Math has priority, then pages, then words
        if ($client_math_rate > 0 && $math_count > 0) {
            $rate_used = $client_math_rate;
            $unit_used = $math_count;
            $amount = $math_count * $client_math_rate;
            $description = "Math Equations";
            $unit_type = "Equations";
        } elseif ($client_page_rate > 0 && $pages > 0) {
            $rate_used = $client_page_rate;
            $unit_used = $pages;
            $amount = $pages * $client_page_rate;
            $description = "Pages";
            $unit_type = "Pages";
        } elseif ($client_word_rate > 0 && $word_count > 0) {
            $rate_used = $client_word_rate;
            $unit_used = $word_count;
            $amount = $word_count * $client_word_rate;
            $description = "Words";
            $unit_type = "Words";
        } else {
            // Default to pages if no specific calculation method
            $rate_used = $client_page_rate;
            $unit_used = $pages; 
            $amount = $pages * $client_page_rate;
            $description = "Pages";
            $unit_type = "Pages";
        }
        
        $job_details[] = [
            'job_id' => $job['job_id'],
            'journal_issue' => $job['journal_issue'] ?? '',
            'article' => $job['article'] ?? '',
            'word_count' => $word_count,
            'pages' => $pages,
            'math_count' => $math_count,
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
        $total_math_count += $math_count;
    }
    
     $subtotal = $total_gross_amount;
    
    // Calculate GST based on available fields and get tax type
    $tax_calculation = $this->calculateFinalGstRateAndType($client_rates, $_POST['gst_rate'] ?? null);
    $gst_rate_final = $tax_calculation['rate'];
    $tax_type = $tax_calculation['type'];
    $tax_label = $tax_calculation['label'];
    
    // Calculate tax amounts based on type
    $gst_amount = 0;
    $cgst_amount = 0;
    $igst_amount = 0;
    
    if ($tax_type === 'cgst_igst') {
        // Split the tax between CGST and IGST
        $cgst_rate = $tax_calculation['cgst_rate'];
        $igst_rate = $tax_calculation['igst_rate'];
        $cgst_amount = $subtotal * ($cgst_rate / 100);
        $igst_amount = $subtotal * ($igst_rate / 100);
        $gst_amount = $cgst_amount + $igst_amount;
    } else {
        // Single tax calculation
        $gst_amount = $subtotal * ($gst_rate_final / 100);
    }
    
    $final_grand_total = $subtotal + $gst_amount;
    
    return [
        'job_details' => $job_details,
        'subtotal' => $subtotal,
        'gst_rate' => $gst_rate_final,
        'gst_amount' => $gst_amount,
        'cgst_amount' => $cgst_amount,
        'igst_amount' => $igst_amount,
        'grand_total_after_fees' => $final_grand_total,
        'total_jobs' => count($jobs),
        'total_words' => $total_word_count,
        'total_pages' => $total_pages,
        'total_math' => $total_math_count,
        'currency_symbol' => $currency_symbol,
        'currency_display' => $currency_symbol,
        'client_rates' => $client_rates,
        'tax_type' => $tax_type,
        'tax_label' => $tax_label,
        'tax_calculation' => $tax_calculation
    ];
}
private function calculateFinalGstRateAndType($client_rates, $user_override_rate = null) {
    // If user provided an override rate in the form, use that with default label
    if ($user_override_rate !== null && (float)$user_override_rate > 0) {
        return [
            'rate' => (float)$user_override_rate,
            'type' => 'override',
            'label' => 'GST'
        ];
    }
    
    $gst_per = (float)($client_rates['gst_per'] ?? 0);
    $cgst = (float)($client_rates['cgst'] ?? 0);
    $igst = (float)($client_rates['igst'] ?? 0);
    $paypal_percentage = (float)($client_rates['paypal_percentage'] ?? 0);
    
    // Priority 1: Use gst_per if available and > 0
    if ($gst_per > 0) {
        return [
            'rate' => $gst_per,
            'type' => 'gst',
            'label' => 'GST'
        ];
    }
    
    // Priority 2: Use sum of cgst and igst if both available and > 0
    if ($cgst > 0 && $igst > 0) {
        return [
            'rate' => $cgst + $igst,
            'type' => 'cgst_igst',
            'label' => 'CGST & IGST',
            'cgst_rate' => $cgst,
            'igst_rate' => $igst,
            'cgst_amount' => 0, // Will be calculated later
            'igst_amount' => 0  // Will be calculated later
        ];
    }
    
    // Priority 3: Use paypal_percentage if available and > 0
    if ($paypal_percentage > 0) {
        return [
            'rate' => $paypal_percentage,
            'type' => 'paypal',
            'label' => 'PayPal Fee'
        ];
    }
    
    // Default fallback
    return [
        'rate' => 18.0,
        'type' => 'default',
        'label' => 'GST'
    ];
}
/**
 * Calculate final GST rate for pricing
 */
private function calculateFinalGstRate($client_rates, $user_override_rate = null) {
    // If user provided an override rate in the form, use that
    if ($user_override_rate !== null && (float)$user_override_rate > 0) {
        return (float)$user_override_rate;
    }
    
    // Otherwise use the calculated GST percentage from client data
    return (float)($client_rates['gst_per'] ?? 18.0);
}
/**
 * Calculate GST percentage based on available fields
 * Priority: gst_per > (cgst + igst) > paypal_percentage
 */
private function calculateGstPercentage($client_data) {
    // Priority 1: Use gst_per if available and > 0
    if (isset($client_data['gst_per']) && (float)$client_data['gst_per'] > 0) {
        return (float)$client_data['gst_per'];
    }
    
    // Priority 2: Use sum of cgst and igst if both available and > 0
    $cgst = (float)($client_data['cgst'] ?? 0);
    $igst = (float)($client_data['igst'] ?? 0);
    if ($cgst > 0 && $igst > 0) {
        return $cgst + $igst;
    }
    
    // Priority 3: Use paypal_percentage if available and > 0
    if (isset($client_data['paypal_percentage']) && (float)$client_data['paypal_percentage'] > 0) {
        return (float)$client_data['paypal_percentage'];
    }
    
    // Default fallback
    return 18.0; // Default GST percentage
}
    /**
     * Generate PDF invoice for a client using TCPDF
     */
    public function generateClientPDF($client_data, $pricing_data, $month, $year) {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        $pdf->SetCreator('Invoice System');
        $pdf->SetAuthor('Your Company');
        $pdf->SetTitle('Invoice - ' . $client_data['company_name']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        
        $html = $this->generateInvoiceHTML($client_data, $pricing_data, $month, $year);
        $pdf->writeHTML($html, true, false, true, false, '');
        
        
         // Sanitize client name for folder structure
       /* $safe_client_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $client_name);
        $month_year_folder = date('Y-m');
        
        // Target directory format: uploads/jobs/ClientName_YYYY-MM/ (Dynamic Folder)
        $combined_folder_name = $safe_client_name . '_' . $month_year_folder;
        $base_upload_dir = '../uploads/jobs/';
        $final_upload_dir = $base_upload_dir . $combined_folder_name . '/';
        
       /* // Create directory if it doesn't exist
        if (!is_dir($final_upload_dir)) {
            if (!mkdir($final_upload_dir, 0777, true)) {
                echo json_encode(['success' => false, 'message' => 'Failed to create upload directory. Check server permissions on ' . $base_upload_dir]);
                exit;
            }
        }*/
       
        
      //  $upload_dir = '../uploads/jobs/';
        $upload_dir = 'invoices/';
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

    /**
     * Generate HTML content for invoice
     */
   /**
 * Generate HTML content for invoice - EXACT MATCH to provided PDF design
 */
 public function getCompanyDetails() {
    try {
        // First check if the table exists
        $table_check = $this->conn->query("SHOW TABLES LIKE 'company_master'");
        if ($table_check->num_rows == 0) {
            error_log("company_master table does not exist");
            return null;
        }
        
        $query = "
        SELECT 
            id,
            company_name,
            pan_number,
            bank_name,
            bank_account_name,
            bank_account_number,
            bank_ifsc_code,
            bank_swift_code,
            company_address,
            reg_address,
            sac,
            created_at
        FROM company_master 
        LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return null;
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        } else {
            error_log("No company details found in company_master table");
            return null;
        }
        
    } catch (Exception $e) {
        error_log("Error in getCompanyDetails: " . $e->getMessage());
        return null;
    }
}
private function generateInvoiceHTML($client, $pricing, $month, $year) {
    // Get company details from database
    $company_data = $this->getCompanyDetails();
    
    if (!$company_data) {
        throw new Exception("Company details not found in database");
    }
    
    
    // Use database values with fallbacks
    $company = [
        'name' => $company_data['company_name'] ?? 'TAPASI Solutions LLP',
        'address' => $company_data['company_address'] ?? $company_data['reg_address'] ?? 'No.3, Sakapathi Avenue, Srinivasampillai Road, Thanjavur - 513 001. Tel (04362) 465488',
        'pan' => $company_data['pan_number'] ?? 'ASCFT9468Q',
        'gstin' => $company_data['gstin'] ?? '33ABVFT9468Q17S', // Note: gstin might not be in your table
        'bank_name' => $company_data['bank_name'] ?? 'ICICI Bank',
        'ac_name' => $company_data['bank_account_name'] ?? 'TAPASI Solutions LLP',
        'ac_no' => $company_data['bank_account_number'] ?? 'XXXXXXXXXXXX',
        'ifsc' => $company_data['bank_ifsc_code'] ?? 'ICIC0006128',
        'swift' => $company_data['bank_swift_code'] ?? 'ICICINBECTS',
        'logo_path' => 'headerT.png', // You might want to store this in database too
        'sac' => $company_data['sac'] ?? '998319'
    ];

    $invoice_number = 'TPS' . mt_rand(10, 99) . '/' . date('y') . str_pad($month, 2, '0', STR_PAD_LEFT) . '/' . $client['client_id'];
    $invoice_date = date('d-M-y', strtotime('now')); 
    $currency_display = $pricing['currency_display']; 
  
    
     $tax_type = $pricing['tax_type'] ?? 'gst';
    $tax_label = $pricing['tax_label'] ?? 'GST';
    $gst_rate_display = number_format($pricing['gst_rate'], 0);
    // Determine unit type for description
    $unit_type = $pricing['total_jobs'] > 0 ? $pricing['job_details'][0]['unit_type'] : 'Pages';
    
    $html = '
    <div style="font-family: Arial, sans-serif; font-size:10pt; color:#000; line-height:1.2;">

        <!-- Header Logo -->
        <table style="width:100%; border-collapse:collapse; margin-bottom:2px;">
            <tr>
                <td style="width:100%; text-align:left;">
                    <img src="' . $company['logo_path'] . '" height="45" width="400" alt="Company Logo">
                </td>
            </tr>
        </table>
        <br>
        <!-- Header section: Customer and Invoice Info -->
        <table style="width:100%; border-collapse:separate; margin-bottom:8px;">
            <tr>
                <!-- Customer Info -->
                <td style="width:50%; vertical-align:top; padding:2px; font-size:9pt; border-bottom-right-radius: 10px;border: 1px solid #888">
                    <strong>Customer</strong><br>
                    <strong>Company:</strong> ' . htmlspecialchars($client['company_name']) . '<br>
                    <strong>Address:</strong> ' . htmlspecialchars($client['address']) . '<br>
                    ' . (!empty($client['country']) ? '<strong>Country:</strong> ' . htmlspecialchars($client['country']) . '<br>' : '') . '
                    ' . (!empty($client['gst_no']) ? '<strong>GST No:</strong> ' . htmlspecialchars($client['gst_no']) . '' : '') . '
                </td>

                <!-- Invoice Info -->
                <td style="width:50%; vertical-align:top; text-align:right; padding:2px; font-size:9pt; border-bottom-right-radius: 10px;border: 1px solid black">
                    <strong>Date:</strong> ' . $invoice_date . '<br>
                    <strong>From:</strong> ' . htmlspecialchars($company['name']) . '<br>
                    <strong>Page:</strong> 1 of 1<br><br>
                    <strong>Invoice No:</strong> ' . $invoice_number . '<br>
                    <strong>PAN:</strong> ' . htmlspecialchars($company['pan']) . '<br>
                    ' . (!empty($company['gstin']) ? '<strong>GST:</strong> ' . htmlspecialchars($company['gstin']) . '' : '') . '
                </td>
            </tr>
        </table>
        <br><br>
        <!-- Description line -->
        <div style="margin:8px 0 5px 0; font-size:9pt;">
            We are pleased to invoice you the following for your kind consideration.
        </div>

        <!-- Item Table -->
        <table style="width:115%; border-collapse:separate;margin-bottom:8px;">
            <tr style="text-align:center;">
                <th style="border:1px solid #888; padding:15px; width:5%;"><strong>Item</strong></th>
                <th style="border:1px solid #888; padding:15px; width:45%; text-align:left;"><strong>Description</strong></th>
                <th style="border:1px solid #888; padding:15px; width:10%;"><strong>Qty (' . $unit_type . ')</strong></th>
                <th style="border:1px solid #888; padding:15px; width:15%;"><strong>Unit Price</strong></th>
                <th style="border:1px solid #888; padding:15px; width:15%;"><strong>Amount</strong></th>
            </tr>';
    
    $item_counter = 1;
    $total_count = 23;
    foreach ($pricing['job_details'] as $job) {
        $journal_issue = htmlspecialchars($job['journal_issue']);
        $article = htmlspecialchars($job['article'] ?? '');
        
        $html .= '
            <tr>
                <td style="border-left: 1px solid #888; border-right: 1px solid #888; padding:15px; text-align:center;">' . $item_counter++ . '</td>
                <td style="border-left: 1px solid #888; border-right: 1px solid #888; padding:15px;">' . $journal_issue . 
                (!empty($article) ? '<br>' . $article : '') . '</td>
                <td style="border-left: 1px solid #888; border-right: 1px solid #888; padding:15px; text-align:right;">' . number_format($job['unit_used'], 0) . '</td>
                <td style="border-left: 1px solid #888; border-right: 1px solid #888; padding:15px; text-align:right;">' . number_format($job['rate_used'], 2) . '</td>
                <td style="border-left: 1px solid #888; border-right: 1px solid #888; padding:15px; text-align:right;">' . number_format($job['total_job_amount'], 2) . '</td>
            </tr>';
    }
    
   
        for($count = 0; $count <= $total_count - $item_counter; $count++) {
            $html .= ' 
            <tr>
                <td style="border-left: 1px solid #888; border-right: 1px solid #888; padding:15px; text-align:center;"></td>
                <td style="border-left: 1px solid #888; border-right: 1px solid #888; padding:15px;"></td>
                <td style="border-left: 1px solid #888; border-right: 1px solid #888; padding:15px; text-align:right;"></td>
                <td style="border-left: 1px solid #888; border-right: 1px solid #888; padding:15px; text-align:right;"></td>
                <td style="border-left: 1px solid #888; border-right: 1px solid #888; padding:15px; text-align:right;"></td>
            </tr>';
        }
   
    
     $html .= '
            <tr>
                <td style="border:1px solid #888; padding:15px; width:5%"></td>
                <td style="border:1px solid #888; padding:15px; width:45%"></td>
                <td style="border:1px solid #888; padding:15px; text-align:right;" colspan="2"><strong>Total</strong></td>
                <td style="border:1px solid #888;padding:15px; text-align:right;">' . number_format($pricing['subtotal'], 2) . '</td>
            </tr>';
    
    // Dynamic tax rows based on tax type
    if ($tax_type === 'cgst_igst') {
        // Show CGST and IGST separately
        $cgst_rate = number_format($pricing['tax_calculation']['cgst_rate'] ?? 0, 0);
        $igst_rate = number_format($pricing['tax_calculation']['igst_rate'] ?? 0, 0);
        
        $html .= '
            <tr>
                <td style="border:1px solid #888; padding:15px; width:5%"></td>
                <td style="border:1px solid #888; padding:15px; width:45%"></td>
                <td style="border:1px solid #888;padding:15px; text-align:right;" colspan="2"><strong>CGST ' . $cgst_rate . '%</strong></td>
                <td style="border:1px solid #888;padding:15px; text-align:right;">' . number_format($pricing['cgst_amount'], 2) . '</td>
            </tr>
            <tr>
                <td style="border:1px solid #888; padding:15px; width:5%"></td>
                <td style="border:1px solid #888; padding:15px; width:45%"></td>
                <td style="border:1px solid #888;padding:15px; text-align:right;" colspan="2"><strong>IGST ' . $igst_rate . '%</strong></td>
                <td style="border:1px solid #888;padding:15px; text-align:right;">' . number_format($pricing['igst_amount'], 2) . '</td>
            </tr>';
    } else {
        // Show single tax row for GST, PayPal, or other types
        $html .= '
            <tr>
                <td style="border:1px solid #888; padding:15px; width:5%"></td>
                <td style="border:1px solid #888; padding:15px; width:45%"></td>
                <td style="border:1px solid #888;padding:15px; text-align:right;" colspan="2"><strong>' . $tax_label . ' ' . $gst_rate_display . '%</strong></td>
                <td style="border:1px solid #888;padding:15px; text-align:right;">' . number_format($pricing['gst_amount'], 2) . '</td>
            </tr>';
    }
    
    $html .= '
            <tr>
                <td style="border:1px solid #888; padding:15px; width:5%"></td>
                <td style="border:1px solid #888; padding:15px; width:45%"><strong>Payment transfer : Immediate</strong></td>
                <td style="border:1px solid #888;padding:15px; text-align:right; font-weight:bold;" colspan="2">Grand Total : (' . $pricing['currency_symbol'] . ')</td>
                <td style="border:1px solid #888;padding:15px; text-align:right; font-weight:bold;">' . number_format($pricing['grand_total_after_fees'], 2) . '</td>
            </tr>
        </table>
    
     
        <!-- Bank Details Section -->
        <div style="margin-top:15px; font-size:9pt;">
            <strong>Our Banker\'s</strong><br>
            Account Name: ' . htmlspecialchars($company['ac_name']) . '<br>
            Bank Name: ' . htmlspecialchars($company['bank_name']) . '<br>
            Account Number: ' . htmlspecialchars($company['ac_no']) . '<br>
            IFSC Code: ' . htmlspecialchars($company['ifsc']) . '<br>
            ' . (!empty($company['swift']) ? 'Swift Code: ' . htmlspecialchars($company['swift']) . '<br>' : '') . '
        </div>

        <!-- Signature Section -->
        <table style="width:100%; margin-top:20px;">
            <tr>
                <td style="text-align:right;">
                    <img src="digital_sign.png" height="70px" width="180px" border="1">
                </td>
            </tr>
        </table>

        <!-- Thank You and Address -->
        <div style="text-align:center; margin-top:15px; font-weight:bold;">Thank you</div>
        <div style="text-align:center; margin-top:5px; font-size:8pt;">' . htmlspecialchars($company['address']) . '</div>

    </div>';

    return $html;
}
    // --- Helper Methods ---

    // Update the getReviewData method to use client_master data
    public function getReviewData($client_name, $month, $year) {
        // Create client data from client_master table
        $client_data = $this->createClientData($client_name);
        
        $client_rates = [
            'word_rate' => $client_data['word_rate'],
            'page_rate' => $client_data['page_rate'],
            'math_rate' => $client_data['math_rate'],
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
        // Create client data from input_output table only
        $client = $this->createClientData($client_name);

        $client_rates = [
            'word_rate' => $client['word_rate'],
            'page_rate' => $client['page_rate'],
            'math_rate' => $client['math_rate'],
            'gst_per' => $client['gst_per'],
            'currency' => $client['currency'],
        ];
        
        // Recalculate pricing with the adjusted job data
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
                    'math' => $_POST['math'][$index],
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

// Get ALL clients from input_output table (no status filter)
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
        .review-table th:nth-child(1), .review-table td:nth-child(1) { width: 8%; }
        .review-table th:nth-child(2), .review-table td:nth-child(2) { width: 30%; }
        .review-table th:nth-child(3), .review-table td:nth-child(3) { width: 10%; text-align: right; }
        .review-table th:nth-child(4), .review-table td:nth-child(4) { width: 10%; text-align: right; }
        .review-table th:nth-child(5), .review-table td:nth-child(5) { width: 12%; text-align: right; }
        .review-table th:nth-child(6), .review-table td:nth-child(6) { width: 10%; text-align: right; }
        .review-table th:nth-child(7), .review-table td:nth-child(7) { width: 10%; text-align: right; }
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
            const mathInputs = document.querySelectorAll('input[name^="math"]');

            const totalPageInput = document.getElementById('total_pages_display');
            const totalWordInput = document.getElementById('total_words_display');
            const totalMathInput = document.getElementById('total_math_display');
            const subtotalInput = document.getElementById('subtotal_display');
            
            const gstRateInput = document.getElementById('gst_rate');
            const gstAmountInput = document.getElementById('gst_amount_display');
            const grandTotalInput = document.getElementById('grand_total_display');
            
            let totalPages = 0;
            let totalWords = 0;
            let totalMath = 0;

            // Get currency symbol from PHP
            const currencySymbol = '<?php echo isset($review_data) ? $review_data["pricing"]["currency_symbol"] : "$"; ?>';

            pageInputs.forEach((pageInput, index) => {
                const rate = parseFloat(rateInputs[index].value) || 0;
                const pages = parseFloat(pageInput.value) || 0;
                const wordCount = parseFloat(wordInputs[index].value) || 0;
                const mathCount = parseFloat(mathInputs[index].value) || 0;
                
                let amount = 0;
                const clientPageRate = parseFloat(document.getElementById('client_page_rate').value) || 0;
                const clientWordRate = parseFloat(document.getElementById('client_word_rate').value) || 0;
                const clientMathRate = parseFloat(document.getElementById('client_math_rate').value) || 0;

                // Same logic as PHP for calculation priority
                if (clientMathRate > 0 && mathCount > 0) {
                    amount = mathCount * rate; 
                } else if (clientPageRate > 0 && pages > 0) {
                    amount = pages * rate; 
                } else if (clientWordRate > 0 && wordCount > 0) {
                    amount = wordCount * rate; 
                } else {
                    amount = pages * rate;
                }
                
                // FIXED: Use dynamic currency symbol instead of hardcoded '$'
                document.getElementById('amount_display_' + index).innerText = currencySymbol + amount.toFixed(2);
                totalSubtotal += amount;
                totalPages += pages;
                totalWords += wordCount;
                totalMath += mathCount;
            });

            totalPageInput.innerText = totalPages.toFixed(2);
            totalWordInput.innerText = totalWords.toFixed(0);
            totalMathInput.innerText = totalMath.toFixed(0);
            subtotalInput.innerText = totalSubtotal.toFixed(2);

            const gstRate = parseFloat(gstRateInput.value) || 0;
            const gstAmount = totalSubtotal * (gstRate / 100);
            const grandTotal = totalSubtotal + gstAmount;

            // FIXED: Use dynamic currency symbol
            gstAmountInput.innerText = currencySymbol + gstAmount.toFixed(2);
            grandTotalInput.innerText = currencySymbol + grandTotal.toFixed(2);
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
                <input type="hidden" id="client_math_rate" value="<?php echo htmlspecialchars($review_data['client']['math_rate'] ?? 0); ?>">
                
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
                                <th>Math Equations</th>
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
                                        <input type="number" name="math[]" value="<?php echo (int)($job['math'] ?? 0); ?>" style="width: 70px;">
                                    </td>
                                    <td>
                                        <input type="number" name="rate_per_page[]" value="<?php echo number_format($job_pricing['rate_used'], 4, '.', ''); ?>" step="0.0001" style="width: 70px;">
                                    </td>
                                    <td id="amount_display_<?php echo $index; ?>">
                                        <?php echo htmlspecialchars($review_data['pricing']['currency_symbol']) . number_format($job_pricing['total_job_amount'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="totals-box">
                        <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                            <div class="form-group">
                                <label>Total Pages:</label> 
                                <strong id="total_pages_display">0</strong>
                            </div>
                            <div class="form-group">
                                <label>Total Words:</label> 
                                <strong id="total_words_display">0</strong>
                            </div>
                            <div class="form-group">
                                <label>Total Math:</label> 
                                <strong id="total_math_display">0</strong>
                            </div>
                        </div>
                        <hr>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Subtotal (<?php echo htmlspecialchars($review_data['pricing']['currency_symbol']); ?>):</label> 
                                <strong id="subtotal_display" style="font-size: 1.1em;">0.00</strong>
                            </div>
                        </div>

                       <div class="form-group">
    <label for="gst_rate">GST Rate (%):</label>
    <input type="number" id="gst_rate" name="gst_rate" 
           value="<?php echo htmlspecialchars($review_data['client']['gst_per']); ?>" 
           step="any" style="width: 50px;">
    (Amount: <strong id="gst_amount_display"><?php echo htmlspecialchars($review_data['pricing']['currency_symbol']); ?>0.00</strong>)
    <small style="display: block; color: #666;">
        Calculated from: 
        <?php 
        if (isset($review_data['client']['gst_per']) && $review_data['client']['gst_per'] > 0) {
            echo "GST Percentage";
        } elseif (($review_data['client']['cgst'] ?? 0) > 0 && ($review_data['client']['igst'] ?? 0) > 0) {
            echo "CGST (" . $review_data['client']['cgst'] . "%) + IGST (" . $review_data['client']['igst'] . "%)";
        } elseif (($review_data['client']['paypal_percentage'] ?? 0) > 0) {
            echo "PayPal Percentage";
        } else {
            echo "Default";
        }
        ?>
    </small>
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