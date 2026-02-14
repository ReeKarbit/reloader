<?php
// download.php - Proxy to force file download
// Usage: download.php?url=...&filename=...

if (!isset($_GET['url'])) {
    die("Error: No URL provided.");
}

$url = $_GET['url'];
$filename = $_GET['filename'] ?? 'download.mp4';

// Basic validation
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    die("Error: Invalid URL.");
}

// Security: simple check to prevent local file access
if (strpos($url, 'http') !== 0) {
    die("Error: Only HTTP/HTTPS URLs allowed.");
}

// Clean filename
$filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

// Initialize cURL to fetch headers first
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
$headers = curl_exec($ch);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode != 200) {
    // If HEAD fails (some servers block it), rely on GET stream
    // Just proceed
}

// Set Headers for Download
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream'); // Force download
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');

// Stream the file with proper headers for YouTube CDN
// Stream the file with proper headers for YouTube CDN
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Write directly to stdout
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// Add headers for YouTube CDN compatibility
$headers = [
    'Accept: */*',
    'Accept-Language: en-US,en;q=0.9',
    'Accept-Encoding: identity',
    'Connection: keep-alive',
    'Referer: https://www.youtube.com/'
];

// Check if range request
if (isset($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Execute and handle errors
$result = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Log debug info
// Log debug info
// file_put_contents('debug_proxy.log', date('[Y-m-d H:i:s] ') . "URL: $url | HTTP: $httpCode | Error: $error\n", FILE_APPEND);

if ($error || ($httpCode != 200 && $httpCode != 206)) {
    // Only set 500 if we haven't started streaming (though headers strictly should be before output, 
    // if curl_exec outputted error body, browser sees 200 + error text. 
    // If curl_exec outputted nothing (e.g. 404 with empty body), we can set 500).
    http_response_code(500);
    die("Error downloading file: " . ($error ?: "HTTP $httpCode"));
}
exit;
?>
