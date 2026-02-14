<?php
/**
 * filesize.php - Get file size via HEAD request
 * Usage: filesize.php?url=...
 * Returns JSON: { "size": 12345, "formatted": "2.5 MB" }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (!isset($_GET['url'])) {
    echo json_encode(['error' => 'No URL', 'size' => 0, 'formatted' => 'UNKNOWN']);
    exit;
}

$url = $_GET['url'];

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['error' => 'Invalid URL', 'size' => 0, 'formatted' => 'UNKNOWN']);
    exit;
}

// Do HEAD request to get Content-Length
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: */*',
    'Accept-Language: en-US,en;q=0.9',
    'Referer: https://www.google.com/'
]);

curl_exec($ch);
$contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 400 && $contentLength > 0) {
    // Format bytes
    $bytes = (int)$contentLength;
    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    $formatted = round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    
    echo json_encode(['size' => $bytes, 'formatted' => $formatted]);
} else {
    echo json_encode(['size' => 0, 'formatted' => 'UNKNOWN']);
}
?>
