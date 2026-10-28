<?php
// fetch_rate.php - AJAX endpoint for fetching single currency rate
require 'db.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Include the same functions from currency_master.php
function fetchRatesFromPublicAPI($baseCurrency = 'USD') {
    $PUBLIC_APIS = [
        ['url' => 'https://api.frankfurter.app/latest?from=%s', 'parser' => 'frankfurter'],
        ['url' => 'https://open.er-api.com/v6/latest/%s', 'parser' => 'erapi'],
        ['url' => 'https://api.vatcomply.com/rates?base=%s', 'parser' => 'vatcomply'],
        ['url' => 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/%s.json', 'parser' => 'freecurrency']
    ];
    
    foreach ($PUBLIC_APIS as $api) {
        $url = sprintf($api['url'], $baseCurrency);
        
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                $rates = [];
                
                switch ($api['parser']) {
                    case 'frankfurter':
                        if (isset($data['rates'])) $rates = $data['rates'];
                        break;
                    case 'erapi':
                        if (isset($data['rates'])) $rates = $data['rates'];
                        break;
                    case 'vatcomply':
                        if (isset($data['rates'])) $rates = $data['rates'];
                        break;
                    case 'freecurrency':
                        if (isset($data[$baseCurrency])) $rates = $data[$baseCurrency];
                        break;
                }
                
                if (!empty($rates)) {
                    return [
                        'success' => true,
                        'rates' => $rates,
                        'source' => $api['name'] ?? 'API',
                        'timestamp' => time()
                    ];
                }
            }
        } catch (Exception $e) {
            continue;
        }
    }
    
    return ['success' => false, 'rates' => [], 'source' => 'None', 'timestamp' => time()];
}

// Handle AJAX request
header('Content-Type: application/json');

if (isset($_GET['code'])) {
    $currencyCode = strtoupper($_GET['code']);
    $apiResult = fetchRatesFromPublicAPI('USD');
    
    if ($apiResult['success'] && isset($apiResult['rates'][$currencyCode])) {
        echo json_encode([
            'success' => true,
            'rate' => $apiResult['rates'][$currencyCode],
            'source' => $apiResult['source'],
            'timestamp' => $apiResult['timestamp']
        ]);
    } else {
        // Fallback to hardcoded rates
        $hardcoded = [
            'USD' => 1.0000, 'EUR' => 0.9200, 'GBP' => 0.7900, 'JPY' => 148.50,
            'INR' => 83.00, 'CAD' => 1.3500, 'AUD' => 1.5200, 'CHF' => 0.8800,
            'CNY' => 7.2500, 'AED' => 3.6725, 'SGD' => 1.3400
        ];
        
        if (isset($hardcoded[$currencyCode])) {
            echo json_encode([
                'success' => true,
                'rate' => $hardcoded[$currencyCode],
                'source' => 'Hardcoded',
                'timestamp' => time()
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Rate not available for ' . $currencyCode
            ]);
        }
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Currency code required'
    ]);
}
?>