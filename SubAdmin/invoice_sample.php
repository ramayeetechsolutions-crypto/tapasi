<?php
require_once('tcpdf/tcpdf.php');

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Your Name');
$pdf->SetTitle('Invoice Details');
$pdf->SetSubject('Invoice Details Card Design');

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', '', 10);

// HTML content with inline CSS to match your design
$html = '
<style>
    .container {
        width: 100%;
    }
    .row {
        display: table;
        width: 100%;
    }
    .col-md-6 {
        display: table-cell;
        width: 50%;
        vertical-align: top;
    }
    .info-card {
        padding: 10px;
        height: 100px;
        border: 0.5px solid #ccc;
        border-radius: 3px;
        position: relative;
        overflow: hidden;
        background-color: white;
        margin: 0 5px;
    }
    .customer-info-card {
        padding-top: 15px;
    }
    .card-title-header {
        position: absolute;
        top: 2px;
        left: 5px;
        font-weight: bold;
        font-size: 9px;
    }
    .info-details p {
        margin: 1px 0;
        font-size: 8px;
        padding: 1px 0;
        border-bottom: 0.1px solid #eee;
        line-height: 1.2;
    }
    .info-details p:last-child {
        border-bottom: none;
    }
    strong {
        font-weight: bold;
    }
</style>

<div class="container">
    <div class="row">
        <div class="col-md-6">
            <div class="info-card customer-info-card">
                <p class="card-title-header">Customer</p>
                <div class="info-details">
                    <p><strong>Company:</strong> VWI</p>
                    <p><strong>Address:</strong> YYYYY</p>
                    <p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;DDDD</p>
                    <p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;PPPP</p>
                    <p>&nbsp;</p>
                    <p>&nbsp;</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="info-card invoice-info-card">
                <div class="info-details">
                    <p><strong>Date:</strong> 07-Aug-25</p>
                    <p><strong>From:</strong> TAPASI Solutions LLP</p>
                    <p><strong>Page:</strong> 1 of 1</p>
                    <p><strong>Invoice No:</strong> TPS26/173/VWI</p>
                    <p><strong>PAN:</strong> ASCFT9468Q</p>
                    <p><strong>GST:</strong> 33ABVFT9468Q1ZS</p>
                </div>
            </div>
        </div>
    </div>
</div>';

// Write HTML content
$pdf->writeHTML($html, true, false, true, false, '');

// Output the PDF
$pdf->Output('invoice_details.pdf', 'I');
?>