<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// NOTE: Ensure 'db.php' defines the $mysqli connection object (mysqli object)
require_once 'db.php';
require_once 'header.php';
// NOTE: Use FPDF instead of TCPDF - Download from http://www.fpdf.org/
require_once('fpdf/fpdf.php');

// Add FPDF Rounded Rect extension if needed (optional, for rounded corners)
class PDF extends FPDF {
    
    // 1. The main RoundedRect function
    function RoundedRect($x, $y, $w, $h, $r, $style = 'D') {
        $this->SetDrawColor(136, 136, 136);
        $this->SetFillColor(255, 255, 255);
        
        // Use the Arc method to draw the four corners
        $this->Arc($x + $r, $y + $r, $r, 180, 270, $style);         // Top left
        $this->Arc($x + $w - $r, $y + $r, $r, 270, 360, $style);    // Top right
        $this->Arc($x + $w - $r, $y + $h - $r, $r, 0, 90, $style);  // Bottom right
        $this->Arc($x + $r, $y + $h - $r, $r, 90, 180, $style);     // Bottom left
        
        // Draw the four connecting lines
        $this->Line($x + $r, $y, $x + $w - $r, $y);                 // Top
        $this->Line($x + $w, $y + $r, $x + $w, $y + $h - $r);       // Right
        $this->Line($x + $r, $y + $h, $x + $w - $r, $y + $h);       // Bottom
        $this->Line($x, $y + $r, $x, $y + $h - $r);                 // Left
    }

    // 2. The Circle method (helper for Arc)
    function Circle($x, $y, $r, $style = 'D') {
        $this->Arc($x, $y, $r, 0, 360, $style);
    }

    // 3. The Arc method (This is what was missing!)
    function Arc($x, $y, $r, $a1, $a2, $style = 'D') {
        $this->_Arc($x, $y, $r, $a1, $a2, $style);
    }

    // 4. Low-level PDF command generator for curves
    function _Arc($x, $y, $r, $a1, $a2, $style) {
        $k = $this->k;
        $hp = $this->h;
        if($style=='F') $op='f';
        elseif($style=='FD' || $style=='DF') $op='B';
        else $op='S';
        
        $a1 *= M_PI/180;
        $a2 *= M_PI/180;
        $d = $a2 - $a1;
        if($d > 0) $h = 1; else $h = -1;
        if(abs($d) > M_PI/2) $d = $h * M_PI/2;
        
        $cx = $x + $r * cos($a1);
        $cy = $y + $r * sin($a1);
        $this->_out(sprintf('%.2F %.2F m', $cx*$k, ($hp-$cy)*$k));
        
        $l = $r * tan($d/4) * 4/3;
        $p1x = $cx - $l * sin($a1);
        $p1y = $cy + $l * cos($a1);
        $p3x = $x + $r * cos($a1 + $d);
        $p3y = $y + $r * sin($a1 + $d);
        $p2x = $p3x + $l * sin($a1 + $d);
        $p2y = $p3y - $l * cos($a1 + $d);
        
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', 
            $p1x*$k, ($hp-$p1y)*$k, 
            $p2x*$k, ($hp-$p2y)*$k, 
            $p3x*$k, ($hp-$p3y)*$k));
        $this->_out($op);
    }
}

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
            sgst,
            igst,
            tds_percentage,
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
            cgst,
            sgst,
            igst,
            tds_percentage,
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
            sgst,
            igst,
            tds_percentage,
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
     * Get all clients with jobs completed in the specified month/year.
     * Includes CE-only, CE+PAG, and XML completed jobs.
     */
    public function getClientsWithCompletedJobs($month = null, $year = null) {
        if ($month === null || $year === null) {
            $lastMonth = date('Y-m', strtotime('first day of previous month'));
            list($year, $month) = explode('-', $lastMonth);
        }
        
        $year = (int) $year;
        $month = (int) $month;
        
        $query = "
        SELECT DISTINCT 
            cm.client_id, cm.client_name, cm.company_name, cm.contact_person, cm.email,
            cm.phone, cm.address, cm.country, cm.gst_no, cm.preferred_communication,
            cm.word_rate, cm.page_rate, cm.math_rate, cm.gst_per, cm.currency
        FROM client_master cm
        INNER JOIN jobs j ON cm.client_id = j.client_id
        INNER JOIN ce ON j.job_id = ce.job_id AND ce.status = 'completed'
        LEFT JOIN pag ON j.job_id = pag.job_id
        LEFT JOIN unique_xml_jobs x ON j.job_id = x.job_id
        WHERE 
            cm.client_id != ''
            AND YEAR(ce.ce_date) = ?
            AND MONTH(ce.ce_date) = ?
            AND (
                (pag.job_id IS NULL) 
                OR
                (pag.status = 'completed' 
                 AND YEAR(pag.pag_date) = ?
                 AND MONTH(pag.pag_date) = ?)
            )
        ORDER BY cm.client_name";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('iiii', $year, $month, $year, $month);
        $stmt->execute();
        return $stmt->get_result();
    }
    
    /**
     * Get completed jobs for a specific client (including XML jobs).
     */
    public function getClientCompletedJobs($client_id, $month, $year) {
        $year = (int) $year;
        $month = (int) $month;
        
        $query = "
        SELECT 
            j.job_id, j.job_date, j.journal_issue, j.article,
            ce.ce_date, ce.done_by as ce_done_by, ce.word_count,
            pag.pag_date, pag.done_by as pag_done_by, pag.pages,
            x.rec_date, x.journal_name_client, x.issue, x.articles,
            x.xml_status, x.WPContent_user, x.HTML_user,
            x.scielo_ebsco_status, x.pubmed_biblat_status, x.html_status,
            x.Image_user, x.Table_user
        FROM jobs j
        INNER JOIN ce ON j.job_id = ce.job_id AND ce.status = 'completed'
        LEFT JOIN pag ON j.job_id = pag.job_id
        LEFT JOIN unique_xml_jobs x ON j.job_id = x.job_id
        WHERE 
            j.client_id = ?
            AND YEAR(ce.ce_date) = ?
            AND MONTH(ce.ce_date) = ?
            AND (
                (pag.job_id IS NULL) 
                OR
                (pag.status = 'completed' 
                 AND YEAR(pag.pag_date) = ?
                 AND MONTH(pag.pag_date) = ?)
            )
        ORDER BY j.job_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('siiii', $client_id, $year, $month, $year, $month);
        $stmt->execute();
        return $stmt->get_result();
    }

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
                'sgst' => (float)($client_data['sgst'] ?? 0),
                'igst' => (float)($client_data['igst'] ?? 0),
                'tds_percentage' => (float)($client_data['tds_percentage'] ?? 0),
                'paypal_percentage' => (float)($client_data['paypal_percentage'] ?? 0),
                'currency' => $client_data['currency'] ?? 'USD',
                'email' => $client_data['email'] ?? '',
                'phone' => $client_data['phone'] ?? '',
                'country' => $client_data['country'] ?? ''
            ];
        } else {
            // Return default client data if not found
            return [
                'client_id' => 'UNKNOWN',
                'client_name' => $client_name,
                'company_name' => $client_name,
                'contact_person' => 'Contact Person',
                'address' => 'Client Address',
                'gst_no' => 'GSTIN_NOT_AVAILABLE',
                'word_rate' => 0.10,
                'page_rate' => 5.00,
                'math_rate' => 2.00,
                'gst_per' => 18.0,
                'cgst' => 0,
                'sgst' => 0,
                'igst' => 0,
                'tds_percentage' => 0,
                'paypal_percentage' => 0,
                'currency' => 'USD',
                'email' => '',
                'phone' => '',
                'country' => ''
            ];
        }
    }
 
    /**
     * Calculate pricing including XML pages
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
        $client_sgst = (float)($client_rates['sgst'] ?? 0);
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
            case 'EUR':
                $currency_symbol = '€';
                break;
            default:
                $currency_symbol = '$';
        }
        
        $total_gross_amount = 0;
        $job_details = [];
        $total_word_count = 0;
        $total_pages = 0;
        $total_math_count = 0;
        $total_xml_pages = 0;
        
        foreach ($jobs as $job) {
            $pages = $is_adjusted ? (float)($job['pages'] ?? 0) : (float)($job['pages'] ?? 0);
            $word_count = $is_adjusted ? (int)($job['word_count'] ?? 0) : (int)($job['word_count'] ?? 0);
            $math_count = $is_adjusted ? (int)($job['math'] ?? 0) : (int)($job['math'] ?? 0);
            
            // Extract XML pages from WPContent_user and HTML_user fields
            $xml_pages = 0;
            $wpc_user = $job['WPContent_user'] ?? '';
            $html_user = $job['HTML_user'] ?? '';
            
            // Extract page count from format like "TPS007/07-02/600"
            if (!empty($wpc_user)) {
                $wpc_parts = explode('/', $wpc_user);
                if (count($wpc_parts) >= 3) {
                    $xml_pages += (int)$wpc_parts[2];
                }
            }
            
            if (!empty($html_user)) {
                $html_parts = explode('/', $html_user);
                if (count($html_parts) >= 3) {
                    $xml_pages += (int)$html_parts[2];
                }
            }
            
            // Calculate total pages (regular pages + XML pages)
            $total_pages_for_job = $pages + $xml_pages;
            
            $amount = 0;
            $rate_used = 0;
            $unit_used = 0;
            $description = "";
            $unit_type = "Unit";

            // Pricing Logic - Include XML pages in calculation
            // Priority: Math > Pages (including XML) > Words
            if ($client_math_rate > 0 && $math_count > 0) {
                $rate_used = $client_math_rate;
                $unit_used = $math_count;
                $amount = $math_count * $client_math_rate;
                $description = "Math Equations";
                $unit_type = "Equations";
            } elseif ($client_page_rate > 0 && $total_pages_for_job > 0) {
                $rate_used = $client_page_rate;
                $unit_used = $total_pages_for_job;
                $amount = $total_pages_for_job * $client_page_rate;
                $description = "Pages (Including XML)";
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
                $unit_used = $total_pages_for_job; 
                $amount = $total_pages_for_job * $client_page_rate;
                $description = "Pages (Including XML)";
                $unit_type = "Pages";
            }
            
            $job_details[] = [
                'job_id' => $job['job_id'],
                'journal_issue' => $job['journal_issue'] ?? '',
                'article' => $job['article'] ?? '',
                'word_count' => $word_count,
                'pages' => $pages,
                'xml_pages' => $xml_pages,
                'total_pages' => $total_pages_for_job,
                'math_count' => $math_count,
                'sac_code' => $sac_code,
                'rate_used' => $rate_used,
                'unit_used' => $unit_used, 
                'unit_type' => $unit_type,
                'description' => $description,
                'total_job_amount' => $amount,
                'wp_content_user' => $wpc_user,
                'html_user' => $html_user
            ];
            
            $total_gross_amount += $amount;
            $total_word_count += $word_count;
            $total_pages += $pages;
            $total_xml_pages += $xml_pages;
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
        $sgst_amount = 0;
        $igst_amount = 0;
        
        if ($tax_type === 'cgst_sgst') {
            // Use specific CGST and SGST percentages
            $cgst_rate = $client_cgst > 0 ? $client_cgst : ($gst_rate_final / 2);
            $sgst_rate = $client_sgst > 0 ? $client_sgst : ($gst_rate_final / 2);
            $cgst_amount = $subtotal * ($cgst_rate / 100);
            $sgst_amount = $subtotal * ($sgst_rate / 100);
            $gst_amount = $cgst_amount + $sgst_amount;
        } elseif ($tax_type === 'igst') {
            // Use IGST percentage
            $igst_amount = $subtotal * ($gst_rate_final / 100);
            $gst_amount = $igst_amount;
        } else {
            // Single GST calculation - split equally between CGST and SGST
            $gst_amount = $subtotal * ($gst_rate_final / 100);
            $cgst_amount = $gst_amount / 2;
            $sgst_amount = $gst_amount / 2;
        }
        
        $final_grand_total = $subtotal + $gst_amount;
        
        return [
            'job_details' => $job_details,
            'subtotal' => $subtotal,
            'gst_rate' => $gst_rate_final,
            'gst_amount' => $gst_amount,
            'cgst_amount' => $cgst_amount,
            'sgst_amount' => $sgst_amount,
            'igst_amount' => $igst_amount,
            'grand_total_after_fees' => $final_grand_total,
            'total_jobs' => count($jobs),
            'total_words' => $total_word_count,
            'total_pages' => $total_pages,
            'total_xml_pages' => $total_xml_pages,
            'total_math' => $total_math_count,
            'currency_symbol' => $currency_symbol,
            'currency_display' => $currency_symbol,
            'client_rates' => $client_rates,
            'tax_type' => $tax_type,
            'tax_label' => $tax_label,
            'tax_calculation' => $tax_calculation,
            'fees_rate' => 0
        ];
    }

    private function calculateFinalGstRateAndType($client_rates, $user_override_rate = null) {
        // If user provided an override rate in the form, use that with default label
        if ($user_override_rate !== null && (float)$user_override_rate > 0) {
            return [
                'rate' => (float)$user_override_rate,
                'type' => 'gst',
                'label' => 'GST'
            ];
        }
        
        $gst_per = (float)($client_rates['gst_per'] ?? 0);
        $cgst = (float)($client_rates['cgst'] ?? 0);
        $sgst = (float)($client_rates['sgst'] ?? 0);
        $igst = (float)($client_rates['igst'] ?? 0);
        $paypal_percentage = (float)($client_rates['paypal_percentage'] ?? 0);
        
        // Priority 1: Use CGST+SGST if both available and > 0
        if ($cgst > 0 && $sgst > 0) {
            return [
                'rate' => $cgst + $sgst,
                'type' => 'cgst_sgst',
                'label' => 'CGST & SGST',
                'cgst_rate' => $cgst,
                'sgst_rate' => $sgst
            ];
        }
        
        // Priority 2: Use IGST if available and > 0
        if ($igst > 0) {
            return [
                'rate' => $igst,
                'type' => 'igst',
                'label' => 'IGST'
            ];
        }
        
        // Priority 3: Use gst_per if available and > 0
        if ($gst_per > 0) {
            return [
                'rate' => $gst_per,
                'type' => 'gst',
                'label' => 'GST'
            ];
        }
        
        // Priority 4: Use paypal_percentage if available and > 0
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
            'type' => 'gst',
            'label' => 'GST'
        ];
    }

    /**
     * Calculate GST percentage based on available fields
     */
    private function calculateGstPercentage($client_data) {
        // Priority 1: Use sum of cgst and sgst if both available and > 0
        $cgst = (float)($client_data['cgst'] ?? 0);
        $sgst = (float)($client_data['sgst'] ?? 0);
        if ($cgst > 0 && $sgst > 0) {
            return $cgst + $sgst;
        }
        
        // Priority 2: Use igst if available and > 0
        $igst = (float)($client_data['igst'] ?? 0);
        if ($igst > 0) {
            return $igst;
        }
        
        // Priority 3: Use gst_per if available and > 0
        if (isset($client_data['gst_per']) && (float)$client_data['gst_per'] > 0) {
            return (float)$client_data['gst_per'];
        }
        
        // Priority 4: Use paypal_percentage if available and > 0
        if (isset($client_data['paypal_percentage']) && (float)$client_data['paypal_percentage'] > 0) {
            return (float)$client_data['paypal_percentage'];
        }
        
        // Default fallback
        return 18.0;
    }

    /**
     * Calculate GST values based on client_master percentages
     */
    private function calculateGSTValues($pricing_data, $client_data) {
        $tax_type = $pricing_data['tax_type'] ?? 'gst';
        
        // Get GST percentages from client_master
        $cgst_percent = (float)($client_data['cgst'] ?? 0);
        $sgst_percent = (float)($client_data['sgst'] ?? 0);
        $gst_percent = (float)($client_data['gst_per'] ?? 0);
        
        $subtotal = $pricing_data['subtotal'] ?? 0;
        
        if ($tax_type === 'cgst_sgst') {
            // Use specific CGST and SGST percentages
            $cgst_amount = $subtotal * ($cgst_percent / 100);
            $sgst_amount = $subtotal * ($sgst_percent / 100);
            $gst_amount = $cgst_amount + $sgst_amount;
            
            return [
                'cgst_amount' => $cgst_amount,
                'sgst_amount' => $sgst_amount,
                'gst_amount' => $gst_amount
            ];
        } elseif ($tax_type === 'gst' && $gst_percent > 0) {
            // For single GST, split equally between CGST and SGST
            $gst_amount = $subtotal * ($gst_percent / 100);
            $split_amount = $gst_amount / 2;
            
            return [
                'cgst_amount' => $split_amount,
                'sgst_amount' => $split_amount,
                'gst_amount' => $gst_amount
            ];
        } else {
            // Default fallback - split equally (9% each for CGST/SGST = 18% total)
            $gst_amount = $pricing_data['gst_amount'] ?? ($subtotal * 0.18);
            $split_amount = $gst_amount / 2;
            
            return [
                'cgst_amount' => $split_amount,
                'sgst_amount' => $split_amount,
                'gst_amount' => $gst_amount
            ];
        }
    }

    /**
     * Calculate TDS deduction percentage and amount from client_master
     */
    private function calculateTDSDeduction($client_data, $grand_total) {
        // Fetch TDS percentage from client_master
        $tds_percentage = (float)($client_data['tds_percentage'] ?? 0);
        
        // If no TDS percentage in client_master, use business logic
        if ($tds_percentage <= 0) {
            $country = strtoupper($client_data['country'] ?? '');
            
            // If client is from India, apply 2% TDS, otherwise 10% for international
            if ($country === 'INDIA' || $country === 'IN') {
                $tds_percentage = 2.00;
            } else {
                $tds_percentage = 10.00;
            }
        }
        
        // Calculate TDS amount
        $tds_amount = $grand_total * ($tds_percentage / 100);
        
        return [
            'tds_percentage' => $tds_percentage,
            'tds_amount' => $tds_amount
        ];
    }

    /**
     * Calculate Turn Around Time (TAT) in days
     */
    private function calculateTAT($job_details) {
        if (empty($job_details)) {
            return 0;
        }
        
        // Calculate average TAT based on job completion dates
        $total_days = 0;
        $count = 0;
        
        foreach ($job_details as $job) {
            // You might need to modify this based on your actual date fields
            if (isset($job['completion_date'])) {
                // This is a placeholder - implement your actual TAT logic
                $total_days += rand(1, 7); // Sample random TAT
                $count++;
            }
        }
        
        return $count > 0 ? ceil($total_days / $count) : 0;
    }

    /**
     * Store invoice details in the database
     */
    private function storeInvoiceInDatabase($client_data, $pricing_data, $month, $year, $pdf_filename) {
        try {
            // Generate invoice number
            $invoice_number = 'TPS' . mt_rand(10, 99) . '/' . date('y') . str_pad($month, 2, '0', STR_PAD_LEFT) . '/' . $client_data['client_id'];
            
            // Calculate dates
            $invoice_date = date('Y-m-d');
            $worked_month = date('Y-m-d', strtotime("$year-$month-01"));
            $raised_month = date('Y-m-d');

            $worked_month = date('F', strtotime($worked_month));
            $raised_month = date('F', strtotime($raised_month));
            
            // Determine process type based on job details
            $process = 'CE';
            if ($pricing_data['total_pages'] > 0 || $pricing_data['total_xml_pages'] > 0) {
                $process = 'CE+PAG+XML';
            } elseif ($pricing_data['total_pages'] > 0) {
                $process = 'CE+PAG';
            } elseif ($pricing_data['total_xml_pages'] > 0) {
                $process = 'XML';
            }
            
            // Calculate GST values based on client_master percentages
            $gst_values = $this->calculateGSTValues($pricing_data, $client_data);
            
            // Calculate TDS deduction and amount
            $tds_calculation = $this->calculateTDSDeduction($client_data, $pricing_data['grand_total_after_fees']);
            $tds_percentage = $tds_calculation['tds_percentage'];
            $tds_amount = $tds_calculation['tds_amount'];
            
            // Calculate final amount after TDS deduction
            $final_amount_after_tds = $pricing_data['grand_total_after_fees'] - $tds_amount;
            
            // Calculate TAT (Turn Around Time) in days
            $tat = $this->calculateTAT($pricing_data['job_details']);
            
            // Get the first job ID for reference
            $first_job_id = !empty($pricing_data['job_details']) ? $pricing_data['job_details'][0]['job_id'] : null;
            
            $status = 'generated';
            $created_by = $_SESSION['user_id'] ?? 'system';
            $created_date = date('Y-m-d H:i:s');
            $country = $client_data['country'] ?? '';

            // Prepare the insert query
            $query = "
            INSERT INTO invoices (
                invoice_no, client_id, job_id, invoice_date, process, total_amount, 
                status, created_by, created_date, worked_month, raised_month, 
                country, cgst_9_percent, sgst_9_percent, gst_18_percent, 
                tds_deduction_percent, tds_amount, final_amount_after_tds,
                tat, remarks, grand_total, total_pages, total_xml_pages, total_words
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            // Use the correct type string: 13 strings + 9 decimals + 2 integers = 24 characters
            $type_string = 'sssssdsssssdddddddisdddi';
            
            $bind_result = $stmt->bind_param(
                $type_string,
                $invoice_number,
                $client_data['client_id'],
                $first_job_id,
                $invoice_date,
                $process,
                $pricing_data['grand_total_after_fees'],
                $status,
                $created_by,
                $created_date,
                $worked_month,
                $raised_month,
                $country,
                $gst_values['cgst_amount'],
                $gst_values['sgst_amount'],
                $gst_values['gst_amount'],
                $tds_percentage,
                $tds_amount,
                $final_amount_after_tds,
                $tat,
                $pdf_filename,
                $pricing_data['grand_total_after_fees'],
                $pricing_data['total_pages'],
                $pricing_data['total_xml_pages'],
                $pricing_data['total_words']
            );
            
            if (!$bind_result) {
                throw new Exception("Bind failed: " . $stmt->error);
            }
            
            $result = $stmt->execute();
            
            if (!$result) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $invoice_id = $stmt->insert_id;
            $stmt->close();
            
            return $invoice_id;
            
        } catch (Exception $e) {
            error_log("Error in storeInvoiceInDatabase: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate Professional PDF Invoice using FPDF (No cURL dependency)
     */
    public function generateClientPDF($client_data, $pricing_data, $month, $year) {
        // --- 1. CREATE FPDF OBJECT ---
        $pdf = new PDF('P', 'mm', 'A4');
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 30);
        $pdf->AddPage();
        
        // --- 2. GET COMPANY DETAILS FROM DATABASE ---
        $company_data = $this->getCompanyDetails();
        
        if (!$company_data) {
            // Fallback default values if company details not found
            $company = [
                'name' => 'TAPASI Solutions LLP',
                'address' => 'No.9. Sabapathi Avenue, Srinivasampillai Road, Thanjavur 613 001. Tel (04362) 465488',
                'pan' => 'ASCFT9468Q',
                'gstin' => '33ABVFT9468Q1ZS',
                'bank_name' => 'ICICI Bank',
                'ac_name' => 'TAPASI Solutions LLP',
                'ac_no' => 'XXXXXXXXXXX',
                'ifsc' => 'ICIC0006128',
                'swift' => 'ICICINBBCTS',
                'logo_path' => 'tlogo.jpg',
                'sac' => '998319'
            ];
        } else {
            $company = [
                'name' => $company_data['company_name'] ?? 'TAPASI Solutions LLP',
                'address' => $company_data['company_address'] ?? $company_data['reg_address'] ?? 'No.9. Sabapathi Avenue, Srinivasampillai Road, Thanjavur 613 001. Tel (04362) 465488',
                'pan' => $company_data['pan_number'] ?? 'ASCFT9468Q',
                'gstin' => $company_data['gstin'] ?? '33ABVFT9468Q1ZS',
                'bank_name' => $company_data['bank_name'] ?? 'ICICI Bank',
                'ac_name' => $company_data['bank_account_name'] ?? 'TAPASI Solutions LLP',
                'ac_no' => $company_data['bank_account_number'] ?? 'XXXXXXXXXXX',
                'ifsc' => $company_data['bank_ifsc_code'] ?? 'ICIC0006128',
                'swift' => $company_data['bank_swift_code'] ?? 'ICICINBBCTS',
                'logo_path' => 'tlogo.jpg',
                'sac' => $company_data['sac'] ?? '998319'
            ];
        }

        // --- 3. GENERATE INVOICE NUMBER AND DATE ---
        $invoice_number = 'TPS' . mt_rand(10, 99) . '/' . date('y') . str_pad($month, 2, '0', STR_PAD_LEFT) . '/' . $client_data['client_id'];
        $invoice_date = date('d-M-y');
        
        // --- 4. HEADER LOGO & TAX-INVOICE TITLE ---
        // Logo
        if (file_exists($company['logo_path'])) {
            $pdf->Image($company['logo_path'], 10, 8, 48);
        } else {
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(190, 10, $company['name'], 0, 1, 'L');
        }
        
        // Tax Invoice title
        $pdf->SetFont('Arial', 'BI', 20);
        $pdf->SetXY(120, 18);
        $pdf->Cell(60, 13, 'Tax-Invoice', 0, 1, 'R');
        
        // Orange double lines
        $pdf->SetDrawColor(245, 130, 32);
        $pdf->SetLineWidth(0.6);
        $pdf->Line(10, 25, 140, 25);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(10, 26.5, 140, 26.5);
        
        // Black double lines
        $pdf->SetDrawColor(245, 130, 32);
        $pdf->SetLineWidth(0.6);
        $pdf->Line(180, 25, 200, 25);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(180, 26.5, 200, 26.5);
        
        $pdf->Ln(10);
        
        // --- 5. DRAW ROUNDED BOXES FOR CUSTOMER AND INVOICE INFO ---
        $startY = 40;
        $boxHeight = 45;
        
        // Customer box (left)
        $pdf->SetDrawColor(136, 136, 136);
        $pdf->RoundedRect(10, $startY, 98, $boxHeight, 3.5);
        
        // Invoice box (right)
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->RoundedRect(112, $startY, 88, $boxHeight, 3.5);
        
        // Internal underscore lines
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->SetLineWidth(0.1);
        for ($i = 1; $i <= 6; $i++) {
            $lineY = $startY + 7 + ($i * 5.5);
            $pdf->Line(28, $lineY, 103, $lineY);
            $pdf->Line(130, $lineY, 195, $lineY);
        }
        
        // --- 6. CUSTOMER DETAILS INSIDE BOX ---
        $pdf->SetXY(12, $startY + 2);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(50, 6, 'Customer', 0, 1, 'L');
        
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY(12, $startY + 8);
        $pdf->Cell(50, 5, 'Company: ' . htmlspecialchars($client_data['company_name']), 0, 1, 'L');
        
        $pdf->SetXY(12, $startY + 13);
        $address = htmlspecialchars($client_data['address'] ?? '');
        $pdf->Cell(50, 5, 'Address: ' . substr($address, 0, 25), 0, 1, 'L');
        
        if (!empty($client_data['country'])) {
            $pdf->SetXY(12, $startY + 18);
            $pdf->Cell(50, 5, '         ' . htmlspecialchars($client_data['country']), 0, 1, 'L');
        }
        
        if (!empty($client_data['gst_no'])) {
            $pdf->SetXY(12, $startY + 23);
            $pdf->Cell(50, 5, 'GST No: ' . htmlspecialchars($client_data['gst_no']), 0, 1, 'L');
        }
        
        // --- 7. INVOICE DETAILS INSIDE BOX ---
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY(114, $startY + 2);
        $pdf->Cell(50, 5, 'Date: ' . $invoice_date, 0, 1, 'L');
        
        $pdf->SetXY(114, $startY + 7);
        $pdf->Cell(50, 5, 'From: ' . htmlspecialchars($company['name']), 0, 1, 'L');
        
        $pdf->SetXY(114, $startY + 12);
        $pdf->Cell(50, 5, 'Page: 1 of 1', 0, 1, 'L');
        
        $pdf->SetXY(114, $startY + 22);
        $pdf->Cell(50, 5, 'Invoice No: ' . $invoice_number, 0, 1, 'L');
        
        $pdf->SetXY(114, $startY + 27);
        $pdf->Cell(50, 5, 'PAN: ' . htmlspecialchars($company['pan']), 0, 1, 'L');
        
        $pdf->SetXY(114, $startY + 32);
        $pdf->Cell(50, 5, 'GST: ' . htmlspecialchars($company['gstin']), 0, 1, 'L');
        
        // --- 8. DESCRIPTION TEXT ---
        $pdf->SetY($startY + $boxHeight + 5);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(190, 5, 'We are pleased to invoice you the following for your kind consideration.', 0, 1, 'L');
        $pdf->Ln(2);
        
        // --- 9. MAIN INVOICE TABLE ---
        $unit_type = $pricing_data['total_jobs'] > 0 ? $pricing_data['job_details'][0]['unit_type'] : 'Pages';
        $currency = $pricing_data['currency_symbol'];
        
        // Table header
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(15, 8, 'Item', 1, 0, 'C', true);
        $pdf->Cell(80, 8, 'Description', 1, 0, 'C', true);
        $pdf->Cell(20, 8, "Qty ($unit_type)", 1, 0, 'C', true);
        $pdf->Cell(35, 8, "Unit Price ($currency)", 1, 0, 'C', true);
        $pdf->Cell(40, 8, "Amount ($currency)", 1, 1, 'C', true);
        
        // Table rows
        $pdf->SetFont('Arial', '', 8);
        $item_counter = 1;
        foreach ($pricing_data['job_details'] as $job) {
            $description = htmlspecialchars($job['journal_issue']);
            if (!empty($job['article'])) {
                $description .= ' - ' . htmlspecialchars(substr($job['article'], 0, 20));
            }
            
            $pdf->Cell(15, 6, $item_counter++, 1, 0, 'C');
            $pdf->Cell(80, 6, substr($description, 0, 35), 1, 0, 'L');
            $pdf->Cell(20, 6, number_format($job['unit_used'], 0), 1, 0, 'R');
            $pdf->Cell(35, 6, number_format($job['rate_used'], 2), 1, 0, 'R');
            $pdf->Cell(40, 6, number_format($job['total_job_amount'], 2), 1, 1, 'R');
        }
        
        // Add empty rows to maintain consistent layout
        $max_rows = 12;
        $current_rows = count($pricing_data['job_details']);
        for ($i = $current_rows; $i < $max_rows; $i++) {
            $pdf->Cell(15, 6, '', 1, 0, 'C');
            $pdf->Cell(80, 6, '', 1, 0, 'L');
            $pdf->Cell(20, 6, '', 1, 0, 'R');
            $pdf->Cell(35, 6, '', 1, 0, 'R');
            $pdf->Cell(40, 6, '', 1, 1, 'R');
        }
        
        // Subtotal row
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(115, 6, '', 0, 0, 'L');
        $pdf->Cell(35, 6, 'Total', 1, 0, 'R');
        $pdf->Cell(40, 6, number_format($pricing_data['subtotal'], 2), 1, 1, 'R');
        
        // Tax rows based on tax type
        $pdf->SetFont('Arial', '', 8);
        $tax_type = $pricing_data['tax_type'] ?? 'gst';
        $tax_label = $pricing_data['tax_label'] ?? 'GST';
        
        if ($tax_type === 'cgst_sgst') {
            $cgst_rate = number_format($pricing_data['tax_calculation']['cgst_rate'] ?? 0, 0);
            $sgst_rate = number_format($pricing_data['tax_calculation']['sgst_rate'] ?? 0, 0);
            
            $pdf->Cell(115, 6, '', 0, 0, 'L');
            $pdf->Cell(35, 6, "CGST {$cgst_rate}%", 1, 0, 'R');
            $pdf->Cell(40, 6, number_format($pricing_data['cgst_amount'], 2), 1, 1, 'R');
            
            $pdf->Cell(115, 6, '', 0, 0, 'L');
            $pdf->Cell(35, 6, "SGST {$sgst_rate}%", 1, 0, 'R');
            $pdf->Cell(40, 6, number_format($pricing_data['sgst_amount'], 2), 1, 1, 'R');
        } elseif ($tax_type === 'igst') {
            $igst_rate = number_format($pricing_data['gst_rate'], 0);
            $pdf->Cell(115, 6, '', 0, 0, 'L');
            $pdf->Cell(35, 6, "IGST {$igst_rate}%", 1, 0, 'R');
            $pdf->Cell(40, 6, number_format($pricing_data['igst_amount'], 2), 1, 1, 'R');
        } else {
            $gst_rate_display = number_format($pricing_data['gst_rate'], 0);
            $pdf->Cell(115, 6, '', 0, 0, 'L');
            $pdf->Cell(35, 6, "{$tax_label} {$gst_rate_display}%", 1, 0, 'R');
            $pdf->Cell(40, 6, number_format($pricing_data['gst_amount'], 2), 1, 1, 'R');
        }
        
        // Grand total row
        $pdf->SetFillColor(238, 238, 238);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(115, 6, '', 0, 0, 'L');
        $pdf->Cell(35, 6, "Grand Total ($currency)", 1, 0, 'R', true);
        $pdf->Cell(40, 6, number_format($pricing_data['grand_total_after_fees'], 2), 1, 1, 'R', true);
       // --- 10. BANK DETAILS & SIGNATURE SECTION ---
$pdf->Ln(10); // Give some space after the totals
$start_y = $pdf->GetY(); // Store current Y position to keep columns aligned

// --- LEFT COLUMN: BANK DETAILS ---
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(95, 7, "Our Banker's", 0, 1, 'L');

$pdf->SetFont('Arial', '', 11);
$pdf->Cell(95, 5, 'Account Name: ' . htmlspecialchars($company['ac_name']), 0, 1, 'L');
$pdf->Cell(95, 5, 'Bank Name: ' . htmlspecialchars($company['bank_name']), 0, 1, 'L');
$pdf->Cell(95, 5, 'Account Number: ' . htmlspecialchars($company['ac_no']), 0, 1, 'L');
$pdf->Cell(95, 5, 'IFSC Code: ' . htmlspecialchars($company['ifsc']), 0, 1, 'L');
if (!empty($company['swift'])) {
    $pdf->Cell(95, 5, 'Swift Code: ' . htmlspecialchars($company['swift']), 0, 1, 'L');
}

// --- RIGHT COLUMN: SIGNATURE ---
// We use SetXY to move back up to where the Bank Details started
$pdf->SetXY(110, $start_y); 

//$pdf->SetFont('Arial', 'B', 12);
//$pdf->Cell(85, 7, 'For ' . htmlspecialchars($company['name']), 0, 1, 'R');

// Place the Signature Image (Larger width: 55)
// We align it to the right side (X=140 is a good starting point for a 55mm image)
$current_y_for_sig = $pdf->GetY();
$pdf->Image('digital_sign.png', 140, $current_y_for_sig, 55); 

// Move Y down past the image height so the text doesn't overlap
$pdf->SetY($current_y_for_sig + 22); 

//$pdf->SetFont('Arial', '', 10);
//$pdf->Cell(150, 7, 'Authorised Signatory', 0, 1, 'R');
        
        // --- 11. THANK YOU AND ADDRESS ---
        $pdf->Ln(10);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(190, 6, 'Thank you', 0, 1, 'C');
        
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(190, 3, htmlspecialchars($company['address']), 0, 1, 'C');
        
        // --- 12. SAVE TO SPECIFIED PATH ---
        // Sanitize client name for folder structure
        $client_name = $client_data['client_name'];
        $safe_client_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $client_name);
        $month_year_folder = date('Y-m');
        
        // Target directory format: ../Super_Admin/invoices/ClientName_YYYY-MM/
        $combined_folder_name = $safe_client_name . '_' . $month_year_folder;
        $base_upload_dir = '../Super_Admin/invoices/';
        $final_upload_dir = $base_upload_dir . $combined_folder_name . '/';
        
        // Create directory if it doesn't exist
        if (!is_dir($final_upload_dir)) {
            if (!mkdir($final_upload_dir, 0777, true)) {
                throw new Exception("Failed to create directory: " . $final_upload_dir);
            }
        }
        
        $filename = $final_upload_dir . 'INV_' . $client_data['client_id'] . '_' . $year . '_' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.pdf';
        $full_path = dirname(__FILE__) . '/' . $filename;
        
        // Output PDF
        $pdf->Output('F', $full_path);
        
        // Store invoice details in database
        try {
            $invoice_id = $this->storeInvoiceInDatabase($client_data, $pricing_data, $month, $year, $filename);
            return [
                'filename' => $filename,
                'invoice_id' => $invoice_id
            ];
        } catch (Exception $e) {
            error_log("PDF generated but database storage failed: " . $e->getMessage());
            return [
                'filename' => $filename,
                'invoice_id' => null,
                'error' => $e->getMessage()
            ];
        }
    }
    
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
                gstin,
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
    
    // --- Helper Methods ---

    public function getReviewData($client_id, $month, $year) {
        $client_query = "SELECT * FROM client_master WHERE client_id = ?";
        $stmt = $this->conn->prepare($client_query);
        $stmt->bind_param('s', $client_id);
        $stmt->execute();
        $client_data = $stmt->get_result()->fetch_assoc();
        
        if (!$client_data) {
            throw new Exception("Client not found");
        }

        $client_rates = [
            'word_rate' => $client_data['word_rate'],
            'page_rate' => $client_data['page_rate'],
            'math_rate' => $client_data['math_rate'],
            'gst_per' => $client_data['gst_per'],
            'cgst' => $client_data['cgst'],
            'sgst' => $client_data['sgst'],
            'igst' => $client_data['igst'],
            'currency' => $client_data['currency'],
        ];
        
        $jobs_result = $this->getClientCompletedJobs($client_id, $month, $year);
        $jobs = [];
        
        while ($job = $jobs_result->fetch_assoc()) {
            $jobs[] = $job;
        }
        
        if (empty($jobs)) {
            throw new Exception("No completed jobs found for this client in the specified period");
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
    
    public function generateSingleClientInvoiceFromAdjustedData($client_id, $month, $year, $adjusted_jobs) {
        // Get client details again (for name, address, rates, etc.)
        $client_query = "SELECT * FROM client_master WHERE client_id = ?";
        $stmt = $this->conn->prepare($client_query);
        $stmt->bind_param('s', $client_id);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        
        if (!$client) {
            throw new Exception("Client not found for final generation.");
        }

        $client_rates = [
            'word_rate' => $client['word_rate'],
            'page_rate' => $client['page_rate'],
            'math_rate' => $client['math_rate'],
            'gst_per' => $client['gst_per'],
            'cgst' => $client['cgst'],
            'sgst' => $client['sgst'],
            'igst' => $client['igst'],
            'currency' => $client['currency'],
        ];
        
        // Recalculate pricing with the adjusted job data
        $pricing_data = $this->calculateJobPricing($adjusted_jobs, $client_rates, true);
        
        $result = $this->generateClientPDF($client, $pricing_data, $month, $year);
        
        return [
            'client_id' => $client_id,
            'client_name' => $client['client_name'],
            'company_name' => $client['company_name'],
            'invoice_file' => $result['filename'],
            'invoice_id' => $result['invoice_id'] ?? null,
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
$current_client_id = $_REQUEST['client_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_review']) && $current_client_id) {
        // Step 1: Request to review data
        try {
            $review_data = $invoiceGenerator->getReviewData($current_client_id, $current_month, $current_year);
            $message = "Reviewing jobs for " . $review_data['client']['company_name'] . ". Please make adjustments below.";
        } catch (Exception $e) {
            $message = "Error during review lookup: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['generate_final']) && $current_client_id) {
        // Step 2: Final generation with adjusted data
        try {
            // Process adjusted data from the form
            $adjusted_jobs = [];
            foreach ($_POST['job_id'] as $index => $job_id) {
                $adjusted_jobs[] = [
                    'job_id' => $job_id,
                    'journal_issue' => $_POST['journal_issue'][$index],
                    'article' => $_POST['article'][$index], 
                    'word_count' => $_POST['word_count'][$index],
                    'pages' => $_POST['pages'][$index],
                    'xml_pages' => $_POST['xml_pages'][$index] ?? 0,
                    'rate_per_page' => $_POST['rate_per_page'][$index],
                ];
            }

            $result = $invoiceGenerator->generateSingleClientInvoiceFromAdjustedData($current_client_id, $current_month, $current_year, $adjusted_jobs);
            
            $message = "Invoice generated successfully for " . $result['company_name'] . 
                       ($result['invoice_id'] ? " (Invoice ID: " . $result['invoice_id'] . "). " : ". ") . 
                       "<a href='" . htmlspecialchars($result['invoice_file']) . "' target='_blank'>Download PDF</a>";
            $results[] = $result;

        } catch (Exception $e) {
            $message = "PDF generation failed: " . $e->getMessage();
        }
    }
}

// Get clients for dropdown
$clients_result = $mysqli->query("SELECT client_id, client_name, company_name FROM client_master ORDER BY company_name");
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
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #0056b3; padding-bottom: 20px; }
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
        
        /* Review Table Styles */
        .review-table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 0.9em; }
        .review-table th, .review-table td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .review-table th { background-color: #f0f0f0; }
        .review-table input { width: 100%; padding: 5px; box-sizing: border-box; }
        .totals-box { margin-top: 20px; padding: 15px; border: 1px solid #007bff; background: #e6f0ff; border-radius: 5px; }
        .totals-box label { display: inline; margin-right: 10px; }
        .totals-box input { width: 80px; }
        .warning-text { color: red; font-weight: bold; }
    </style>

    <script>
        function calculateTotals() {
            let totalSubtotal = 0;
            const rateInputs = document.querySelectorAll('input[name^="rate_per_page"]');
            const pageInputs = document.querySelectorAll('input[name^="pages"]');
            const xmlPageInputs = document.querySelectorAll('input[name^="xml_pages"]');
            const wordInputs = document.querySelectorAll('input[name^="word_count"]');

            const totalPageInput = document.getElementById('total_pages_display');
            const totalXmlPageInput = document.getElementById('total_xml_pages_display');
            const totalCombinedPagesInput = document.getElementById('total_combined_pages_display');
            const totalWordInput = document.getElementById('total_words_display');
            const subtotalInput = document.getElementById('subtotal_display');
            const gstRateInput = document.getElementById('gst_rate');
            const gstAmountInput = document.getElementById('gst_amount_display');
            const grandTotalInput = document.getElementById('grand_total_display');
            
            let totalPages = 0;
            let totalXmlPages = 0;
            let totalCombinedPages = 0;
            let totalWords = 0;

            // 1. Calculate Subtotal, Total Pages, Total Words
            pageInputs.forEach((pageInput, index) => {
                const rate = parseFloat(rateInputs[index].value) || 0;
                const pages = parseFloat(pageInput.value) || 0;
                const xmlPages = parseFloat(xmlPageInputs[index].value) || 0;
                const wordCount = parseFloat(wordInputs[index].value) || 0;
                
                let amount = 0;
                
                // Use Page Rate if > 0, otherwise use Word Rate.
                const clientPageRate = parseFloat(document.getElementById('client_page_rate').value) || 0;
                const clientWordRate = parseFloat(document.getElementById('client_word_rate').value) || 0;

                // Calculate total pages (regular + XML)
                const combinedPages = pages + xmlPages;
                
                if (clientPageRate > 0 && combinedPages > 0) {
                    // Page rate is dominant in the PHP logic
                    amount = combinedPages * rate; 
                } else if (clientWordRate > 0 && wordCount > 0) {
                    amount = wordCount * rate; 
                } else {
                    amount = combinedPages * rate; // Default to combined pages * rate
                }
                
                document.getElementById('amount_display_' + index).innerText = '$' + amount.toFixed(2);
                
                totalSubtotal += amount;
                totalPages += pages;
                totalXmlPages += xmlPages;
                totalCombinedPages += combinedPages;
                totalWords += wordCount;
            });

            // 2. Display Basic Totals
            totalPageInput.innerText = totalPages.toFixed(2);
            totalXmlPageInput.innerText = totalXmlPages.toFixed(0);
            totalCombinedPagesInput.innerText = totalCombinedPages.toFixed(2);
            totalWordInput.innerText = totalWords.toFixed(0);
            subtotalInput.innerText =  totalSubtotal.toFixed(2);

            // 3. Calculate GST
            const gstRate = parseFloat(gstRateInput.value) || 0;
            const gstAmount = totalSubtotal * (gstRate / 100);
            const grandTotal = totalSubtotal + gstAmount;

            // 4. Display Final Totals
            gstAmountInput.innerText =  gstAmount.toFixed(2);
            grandTotalInput.innerText =  grandTotal.toFixed(2);
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
            
           <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">Invoice List</h4>
                <button 
                    type="button" 
                    name="generate" 
                    onclick="window.location.href='gen_invoice1.php'" 
                    style="
                        background: linear-gradient(135deg, #28a745, #218838);
                        color: #fff;
                        border: none;
                        border-radius: 6px;
                        padding: 8px 16px;
                        font-size: 15px;
                        font-weight: 500;
                        cursor: pointer;
                        box-shadow: 0 2px 5px rgba(0,0,0,0.15);
                        transition: all 0.3s ease;
                        display: inline-flex;
                        align-items: center;
                        gap: 6px;
                    "
                    onmouseover="this.style.background='linear-gradient(135deg, #218838, #1e7e34)';"
                    onmouseout="this.style.background='linear-gradient(135deg, #28a745, #218838)';"
                >
                    <i class="bi bi-file-earmark-excel"></i> Generate Invoice I/O
                </button>
            </div>

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
                    <label for="client_id">Select Client:</label>
                    <select name="client_id" id="client_id" required style="width: 300px;">
                        <option value="">-- Select Client --</option>
                        <?php 
                        if ($clients_result && $clients_result->num_rows > 0) {
                            $clients_result->data_seek(0);
                        }
                        while($client = $clients_result->fetch_assoc()): ?>
                            <option value="<?php echo $client['client_id']; ?>" <?php echo $client['client_id'] == $current_client_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['company_name'] . ' (' . $client['client_name'] . ')'); ?>
                            </option>
                        <?php endwhile; ?>
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
                    <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($review_data['client']['client_id']); ?>">
                    <input type="hidden" name="month" value="<?php echo $current_month; ?>">
                    <input type="hidden" name="year" value="<?php echo $current_year; ?>">

                    <table class="review-table">
                        <thead>
                            <tr>
                                <th style="width: 10%;">Job ID</th>
                                <th style="width: 30%;">Journal/Issue</th>
                                <th style="width: 8%;">Word Count</th>
                                <th style="width: 8%;">Pages</th>
                                <th style="width: 8%;">XML Pages</th>
                                <th style="width: 8%;">Total Pages</th>
                                <th style="width: 10%;">Rate (<?php echo htmlspecialchars($review_data['pricing']['currency_symbol']); ?>)</th>
                                <th style="width: 10%;">Amount (<?php echo htmlspecialchars($review_data['pricing']['currency_symbol']); ?>)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $initial_pricing_details = $review_data['pricing']['job_details'];
                            foreach($review_data['jobs'] as $index => $job): 
                                $job_pricing = $initial_pricing_details[$index];
                                $xml_pages = $job_pricing['xml_pages'] ?? 0;
                                $total_pages = $job_pricing['total_pages'] ?? 0;
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
                                    <td style="text-align: center;">
                                        <?php echo $xml_pages; ?>
                                        <input type="hidden" name="xml_pages[]" value="<?php echo $xml_pages; ?>">
                                    </td>
                                    <td style="text-align: center; font-weight: bold;">
                                        <?php echo $total_pages; ?>
                                    </td>
                                    <td>
                                        <input type="number" name="rate_per_page[]" value="<?php echo number_format($job_pricing['rate_used'], 2, '.', ''); ?>" step="0.0001" style="width: 70px;">
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
                            <label>Regular Pages:</label> <strong id="total_pages_display">0</strong>
                        </div>
                        <div class="form-group">
                            <label>XML Pages:</label> <strong id="total_xml_pages_display">0</strong>
                        </div>
                        <div class="form-group">
                            <label>Total Pages (Regular + XML):</label> <strong id="total_combined_pages_display">0</strong>
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
                            <input type="number" id="gst_rate" name="gst_rate" value="<?php echo $review_data['pricing']['gst_rate']; ?>" step="any" style="width: 50px;">
                            (Amount: <strong id="gst_amount_display">0.00</strong>)
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
                        <?php if ($result['invoice_id']): ?>
                            Invoice ID: <?php echo $result['invoice_id']; ?><br>
                        <?php endif; ?>
                        Total: <?php echo number_format($result['total_amount'], 2); ?><br>
                        File: <a href="<?php echo htmlspecialchars($result['invoice_file']); ?>" class="download-link" target="_blank">Download Invoice</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>