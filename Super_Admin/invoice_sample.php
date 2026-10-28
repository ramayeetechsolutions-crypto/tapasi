<?php
require_once('tcpdf_include.php');

// Create PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetTitle('Tax Invoice TPS26/173/VWI');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

// Add company logo (top-left corner)
$pdf->Image('logo.png', 15, 10, 30, '', '', '', 'T', false, 300);

// Move cursor below logo
$pdf->Ln(25);

// HTML content
$html = <<<EOD
<style>
    h2, h3 { text-align: center; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    td, th { border: 1px solid #000; padding: 5px; }
    .no-border td { border: none; }
    .section-title { font-weight: bold; background-color: #f2f2f2; }
</style>

<h2>TAPASI SOLUTIONS LLP</h2>
<h3>Tax-Invoice</h3>

<table class="no-border">
<tr><td colspan="2"><strong>Customer</strong></td></tr>
<tr><td>Company:</td><td>VWI</td></tr>
<tr><td>Address:</td><td>YYYYY</td></tr>
<tr><td></td><td>DDDD</td></tr>
<tr><td></td><td>PPPP</td></tr>
</table>

<br>

<table class="no-border">
<tr><td colspan="2">Date:</td><td>07-Aug-25</td></tr>
<tr><td colspan="2">From:</td><td>TAPASI Solutions LLP</td></tr>
<tr><td colspan="2">Page:</td><td>1 of 1</td></tr>
<tr><td colspan="2">Invoice No:</td><td>TPS26/173/VWI</td></tr>
<tr><td></td><td>PAN:</td><td>ASCFT9468Q</td></tr>
<tr><td colspan="2">GST:</td><td>33ABVFT9468Q1ZS</td></tr>
</table>

<br>
<p>We are pleased to invoice you the following for your kind consideration.</p>

<table>
<tr class="section-title">
<th>Item</th>
<th>Description</th>
<th>Qty (Pages)</th>
<th>Unit Price</th>
<th>Amount</th>
</tr>
<tr><td></td><td colspan="4">Copyediting+Indesign formatting charges for</td></tr>
<tr><td>1</td><td>IJO_11(2)_25</td><td>13</td><td>60.00</td><td>780.00</td></tr>
<tr><td>2</td><td>VWI_18(7)_25</td><td>287</td><td>60.00</td><td>17,220.00</td></tr>
<tr><td>3</td><td>VWI_18(8)_25</td><td>151</td><td>60.00</td><td>9,060.00</td></tr>
<tr><td></td><td colspan="4">XML Conversion charges for</td></tr>
<tr><td>1</td><td>VWI_2025_18_6</td><td>399</td><td>25.00</td><td>9,975.00</td></tr>
<tr><td colspan="4" style="text-align:right;"><strong>Total</strong></td><td>37,035.00</td></tr>
<tr><td colspan="4" style="text-align:right;"><strong>IGST 18%</strong></td><td>6,666.30</td></tr>
<tr><td colspan="2">Payment transfer : Immediate</td><td colspan="2"><strong>Grand Total : (Rs)</strong></td><td>43,701.30</td></tr>
</table>

<br><br>
<p><strong>Our Banker's</strong><br>
Account Name: TAPASI Solutions LLP<br>
Bank Name: ICICI Bank<br>
Account Number: XxxxxxxxXXX<br>
IFSC Code: ICIC0006128<br>
Swift Code: ICICINBBCTS</p>

<br><br>
<p>For TAPASI SOLUTIONS LLP</p>
EOD;

// Output HTML
$pdf->writeHTML($html, true, false, true, false, '');

// Add signature image (bottom-right corner)
$pdf->Image('signature.png', 150, $pdf->GetY(), 40, '', '', '', 'T', false, 300);

// Add name and title below signature
$pdf->SetY($pdf->GetY() + 25);
$pdf->Write(0, "J. Jhils\nAuthorised Signatory\nKey Accounts Manager", '', 0, 'L', true);

$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 8);
$pdf->Write(0, "No.9, Sabapathi Avenue, Srinivasampillai Road, Thanjavur - 613 001. Tel (04362) 465488", '', 0, 'L', true);

// Output PDF
$pdf->Output('TPS26_173_VWI_Invoice_Jul_25.pdf', 'D');
?>