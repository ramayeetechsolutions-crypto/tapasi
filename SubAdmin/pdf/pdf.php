<?php
// 1. Path to your TCPDF library folder [cite: 5]
require_once('TCPDF-main/tcpdf.php');

class TAPASIInvoiceGenerator {
    
    public function generateProfessionalPDF($savePath = 'generated_pdfs/') {
        // --- 1. SET UP TCPDF OBJECT ---
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        // --- 2. DEFINE STATIC DATA [cite: 6, 15, 21, 24] ---
        $company = [
            'name' => 'TAPASI Solutions LLP',
            'address' => 'No.9. Sabapathi Avenue, Srinivasampillai Road, Thanjavur 613 001. Tel (04362) 465488',
            'pan' => 'ASCFT9468Q',
            'gstin' => '33ABVFT9468Q1ZS',
            'bank_name' => 'ICICI Bank',
            'ac_name' => 'TAPASI Solutions LLP',
            'ac_no' => 'XXXXXXXXXXX',
            'ifsc' => 'ICIC0006128',
            'swift' => 'ICICINBBCTS'
        ];

        // --- 3. HEADER LOGO & TAX-INVOICE TITLE  ---
        // Custom double lines for header
        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(245, 130, 32))); // Orange
        $pdf->Line(10, 22, 140, 22);
        $pdf->SetLineStyle(array('width' => 0.2, 'color' => array(245, 130, 32)));
        $pdf->Line(10, 23.5, 140, 23.5);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0))); // Black
        $pdf->Line(180, 22, 200, 22);
        $pdf->SetLineStyle(array('width' => 0.2, 'color' => array(0, 0, 0)));
        $pdf->Line(180, 23.5, 200, 23.5);

        $header_html = '
        <table style="width:100%;">
            <tr>
                <td style="width:50%;"><img src="headerT.png" height="40"></td>
                <td style="width:50%; text-align:right; vertical-align:bottom;">
                    <span style="font-style:italic; font-weight:bold; font-size:20pt;">Tax-Invoice</span>
                </td>
            </tr>
        </table>';
        $pdf->writeHTML($header_html, true, false, true, false, '');

        // --- 4. DRAW ROUNDED BOXES & INTERNAL LINES ---
        $startY = 40; 
        $boxHeight = 45;
        
        $style_left = array('width' => 0.3, 'color' => array(136, 136, 136)); // Gray
        $style_right = array('width' => 0.3, 'color' => array(0, 0, 0)); // Black

        $pdf->RoundedRect(10, $startY, 98, $boxHeight, 3.5, '1111', 'D', $style_left);
        $pdf->RoundedRect(112, $startY, 88, $boxHeight, 3.5, '1111', 'D', $style_right);

        // Internal underscore lines 
        $pdf->SetLineStyle(array('width' => 0.1, 'color' => array(0, 0, 0)));
        for ($i = 1; $i <= 6; $i++) {
            $lineY = $startY + 7 + ($i * 5.5);
            $pdf->Line(28, $lineY, 103, $lineY); 
            $pdf->Line(130, $lineY, 195, $lineY);
        }

        // --- 5. POSITION TEXT INSIDE BOXES [cite: 7, 8, 15] ---
        $pdf->SetXY(10, $startY + 2); 
        $boxes_html = '
        <table cellpadding="5" style="width:100%;">
            <tr>
                <td style="width:53%; text-align:left; font-size:9pt;">
                    <span style="font-size:10pt;"><strong>Customer</strong></span><br>
                    Company: VWI<br>
                    Address: YYYYY<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;DDDD<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;PPPP
                </td>
                <td style="width:6%;"></td> 
                <td style="width:41%; text-align:left; font-size:9pt;">
                    <strong>Date:</strong> 07-Aug-25<br>
                    <strong>From:</strong> ' . $company['name'] . '<br>
                    <strong>Page:</strong> 1 of 1<br><br>
                    <strong>Invoice No:</strong> TPS26/173/VWI<br>
                    <strong>PAN:</strong> ' . $company['pan'] . '<br>
                    <strong>GST:</strong> ' . $company['gstin'] . '
                </td>
            </tr>
        </table>';
        $pdf->writeHTML($boxes_html, true, false, true, false, '');

        // --- 6. MAIN INVOICE TABLE [cite: 12, 13] ---
        $pdf->SetY($startY + $boxHeight + 5); 
        $table_html = '
        <br><br>
        <span style="font-size:9pt;">We are pleased to invoice you the following for your kind consideration.</span><br><br>
        <table border="1" cellpadding="5" style="width:100%; border-collapse:collapse;">
            <tr style="background-color:#f2f2f2; font-weight:bold; text-align:center;">
                <th width="10%">Item</th>
                <th width="50%">Description</th>
                <th width="10%">Qty</th>
                <th width="15%">Unit Price</th>
                <th width="15%">Amount</th>
            </tr>
            <tr>
                <td align="center">1</td>
                <td>Copyediting+Indesign formatting charges for IJO_11(2)_25</td>
                <td align="center">13</td>
                <td align="right">60.00</td>
                <td align="right">780.00</td>
            </tr>
            <tr>
                <td align="center">2</td>
                <td>Copyediting+Indesign formatting charges for VWI_18(7)_25</td>
                <td align="center">287</td>
                <td align="right">60.00</td>
                <td align="right">17,220.00</td>
            </tr>
            <tr>
                <td colspan="4" align="right"><strong>Total</strong></td>
                <td align="right">37,035.00</td>
            </tr>
            <tr>
                <td colspan="4" align="right">IGST 18%</td>
                <td align="right">6,666.30</td>
            </tr>
            <tr style="background-color:#eee;">
                <td colspan="4" align="right"><strong>Grand Total (Rs)</strong></td>
                <td align="right"><strong>43,701.30</strong></td>
            </tr>
        </table>';
        $pdf->writeHTML($table_html, true, false, true, false, '');

        // --- 7. BANK DETAILS & SIGNATURE [cite: 18, 22, 25, 26] ---
        $footer_html = '
        <div style="font-size:9pt;">
            <br><strong>Our Banker\'s</strong><br>
            Account Name: ' . $company['ac_name'] . '<br>
            Bank Name: ' . $company['bank_name'] . '<br>
            Account Number: ' . $company['ac_no'] . '<br>
            IFSC Code: ' . $company['ifsc'] . '<br>
            Swift Code: ' . $company['swift'] . '
        </div>
        <div style="text-align:right;">
            For <strong>TAPASI SOLUTIONS LLP</strong><br><br>
            <img src="digital_sign.png" height="50"><br>
            Authorised Signatory
        </div>';
        $pdf->writeHTML($footer_html, true, false, true, false, '');

        // --- 8. SAVE TO SPECIFIED PATH ---
        if (!is_dir($savePath)) {
            mkdir($savePath, 0777, true);
        }

        $fileName = 'TPS26_173_VWI_Invoice.pdf';
        $destination = __DIR__ . DIRECTORY_SEPARATOR . $savePath . $fileName;

        // Mode 'F' saves to the server path 
        $pdf->Output($destination, 'F');
        
        return $destination;
    }
}

// --- EXECUTION ---
$generator = new TAPASIInvoiceGenerator();
// Define your folder path here
$savedLocation = $generator->generateProfessionalPDF('my_invoices/'); 
echo "PDF successfully generated at: " . $savedLocation;
?>