<?php

// --- 1. Configuration (REPLACE THIS WITH YOUR ACTUAL KEY) ---
$api_key = "6aab63da7e1eed3b15b34503"; // <<<--- ***CRITICAL: Replace with your actual key***
$base_currency = 'USD';
$target_currency_inr = 'INR';
$other_currency_eur = 'EUR';

// API Endpoint for ExchangeRate-API (or a similar service)
// The API URL now includes your unique key for authenticated access.
$api_url = "https://v6.exchangerate-api.com/v6/{$api_key}/latest/{$base_currency}";

$amount_usd = 1000.00;
$amount_eur = 500.00;

// --- 2. Robust Data Fetching with cURL ---
function fetch_exchange_data_curl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout after 5 seconds
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        curl_close($ch);
        return ['error' => "cURL Error: " . curl_error($ch)];
    }
    
    curl_close($ch);

    if ($http_code !== 200) {
        return ['error' => "HTTP Error Code: $http_code. Check API key and URL."];
    }

    return json_decode($response, true);
}

// --- 3. Execution and Output ---
$data = fetch_exchange_data_curl($api_url);

echo "<h1>📊 Live Currency Conversion to Indian Rupee (INR)</h1>";
echo "<h3>Base Currency: **{$base_currency}**</h3>";
echo "<hr>";

// Check for errors in the fetched data
if (isset($data['error'])) {
    die("<h3>🚨 Retrieval Failed!</h3>" . 
        "<p style='color: red;'>**Error Details:** {$data['error']}</p>" .
        "<p>Please ensure your **\$api_key** is correct and you have not exceeded your monthly limit.</p>");
}

$rates = $data['conversion_rates'];

// --- USD to INR Conversion (Direct Rate) ---
if (isset($rates[$target_currency_inr])) {
    $usd_to_inr_rate = $rates[$target_currency_inr];
    $usd_to_inr_result = $amount_usd * $usd_to_inr_rate;

    echo "<h2>💵 USD to INR Conversion</h2>";
    echo "<li>Amount to Convert: **$" . number_format($amount_usd, 2) . "**</li>";
    echo "<li>Live Rate (1 USD = INR): **Rs. " . number_format($usd_to_inr_rate, 4) . "**</li>";
    echo "<li>**Result:** Rs. " . number_format($usd_to_inr_result, 2) . "</li>";
} 

echo "<hr>";

// --- EUR to INR Conversion (Cross-Rate Calculation) ---
if (isset($rates[$other_currency_eur]) && isset($rates[$target_currency_inr])) {
    
    // Cross-rate calculation: Rate_EUR_to_INR = Rate_USD_to_INR / Rate_USD_to_EUR
    $usd_to_eur_rate = $rates[$other_currency_eur];
    $usd_to_inr_rate_for_cross = $rates[$target_currency_inr];
    
    $eur_to_inr_rate = $usd_to_inr_rate_for_cross / $usd_to_eur_rate;
    $eur_to_inr_result = $amount_eur * $eur_to_inr_rate;

    echo "<h2>🇪🇺 EUR to INR Conversion</h2>";
    echo "<li>Amount to Convert: **&euro;" . number_format($amount_eur, 2) . "**</li>";
    echo "<li>Live Rate (1 EUR = INR): **Rs. " . number_format($eur_to_inr_rate, 4) . "**</li>";
    echo "<li>**Result:** Rs. " . number_format($eur_to_inr_result, 2) . "</li>";
}

echo "<hr>";
echo "<p><em>Data Time: " . date("Y-m-d H:i:s", $data['time_last_update_unix']) . " UTC</em></p>";

?>