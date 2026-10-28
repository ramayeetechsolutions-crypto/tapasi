<?php
// download_and_run.php
// Run this file first to download everything automatically

echo "<h2>Setting up DomPDF...</h2>";

// Create necessary directories
$dirs = ['src', 'lib', 'vendor/composer'];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

// Download DomPDF directly from GitHub
$dompdfFiles = [
    'src/Dompdf.php' => 'https://raw.githubusercontent.com/dompdf/dompdf/master/src/Dompdf.php',
    'src/Options.php' => 'https://raw.githubusercontent.com/dompdf/dompdf/master/src/Options.php',
    'src/Canvas.php' => 'https://raw.githubusercontent.com/dompdf/dompdf/master/src/Canvas.php',
    'src/CanvasFactory.php' => 'https://raw.githubusercontent.com/dompdf/dompdf/master/src/CanvasFactory.php',
    'src/FrameReflower/Page.php' => 'https://raw.githubusercontent.com/dompdf/dompdf/master/src/FrameReflower/Page.php',
    'src/FrameDecorator/Page.php' => 'https://raw.githubusercontent.com/dompdf/dompdf/master/src/FrameDecorator/Page.php',
    'autoload.inc.php' => 'https://raw.githubusercontent.com/dompdf/dompdf/master/autoload.inc.php',
];

echo "Downloading DomPDF files...<br>";

foreach ($dompdfFiles as $file => $url) {
    $dir = dirname($file);
    if (!is_dir($dir) && $dir != '.') {
        mkdir($dir, 0777, true);
    }
    
    echo "Downloading: $file<br>";
    $content = @file_get_contents($url);
    if ($content) {
        file_put_contents($file, $content);
        echo "✓ Saved: $file<br>";
    } else {
        echo "✗ Failed: $file<br>";
    }
}

echo "<h3>✅ Setup Complete!</h3>";
echo '<a href="pdf.php">Click here to generate PDF</a>';
?>