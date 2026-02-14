<?php
/**
 * MediaGrab — PHP API Proxy v2
 * Menggunakan beberapa provider API gratis sebagai fallback
 * Provider: AllVideoDownloader, SaveFrom-style endpoints, dll
 */

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'text' => 'Method not allowed']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['url'])) {
    respond(['status' => 'error', 'text' => 'URL diperlukan']);
}

$url = filter_var(trim($input['url']), FILTER_VALIDATE_URL);
if (!$url) {
    respond(['status' => 'error', 'text' => 'URL tidak valid']);
}

$format = isset($input['downloadMode']) ? $input['downloadMode'] : 'auto';
$quality = isset($input['videoQuality']) ? $input['videoQuality'] : '720';

// Debug log storage
$debug_log = [];

function debug_log($msg) {
    global $debug_log;
    $debug_log[] = $msg;
}

// Detect platform
$platform = detectPlatform($url);

// Try providers until one works
$providers = ['provider_tikwm', 'provider_snaptik', 'provider_douyin', 'provider_lovetik']; // TikTok providers (Instagram uses client-side Medsoss)
$lastError = '';

$finalResult = null;

foreach ($providers as $providerFunc) {
    try {
        debug_log("Trying provider: $providerFunc");
        $result = $providerFunc($url, $format, $quality, $platform);
        
        if ($result && isset($result['status']) && $result['status'] !== 'error') {
            $finalResult = $result;
            debug_log("Success with $providerFunc");
            break;
        }
        
        if ($result && isset($result['text'])) {
            $lastError = $result['text'];
            debug_log("Failed $providerFunc: " . $result['text']);
        }
    } catch (Exception $e) {
        $lastError = $e->getMessage();
        debug_log("Exception $providerFunc: " . $e->getMessage());
    }
}

if ($finalResult) {
    if (isset($_GET['debug'])) {
        $finalResult['debug'] = $debug_log;
    }
    respond($finalResult);
}

// Failure response
$response = [
    'status' => 'error', 
    'text' => "Tidak dapat memproses link saat ini. $lastError. Silakan coba lagi nanti."
];
if (isset($_GET['debug'])) {
    $response['debug'] = $debug_log;
}
respond($response);

// ============================================
// PROVIDERS
// ============================================

/**
 * Provider: TikWM (TikTok)
 */
function provider_tikwm($url, $format, $quality, $platform) {
    if ($platform !== 'tiktok') return null;
    
    $apiUrl = 'https://www.tikwm.com/api/';
    
    // Just send URL. Extra params sometimes cause issues.
    $postData = [
        'url' => $url,
        'hd' => 1
    ];
    
    debug_log("TikWM trying POST");
    $response = curlRequest($apiUrl, 'POST', http_build_query($postData), [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);
    
    $data = null;
    if ($response && $response['body']) {
        $data = json_decode($response['body'], true);
    }
    
    // If POST failed or returned error, try GET
    if (!$data || (isset($data['code']) && $data['code'] !== 0)) {
         debug_log("TikWM POST failed: " . ($data['msg'] ?? 'no data') . ". Trying GET");
         
         // Log the raw response if it was data but failed code
         if ($data) debug_log("TikWM POST raw: " . substr($response['body'], 0, 200));
         
         $response = curlRequest($apiUrl . '?' . http_build_query($postData), 'GET', null, [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
         ]);
         if ($response && $response['body']) {
             $data = json_decode($response['body'], true);
         }
    }

    if (!$data) return ['status' => 'error', 'text' => 'TikWM no response'];
    
    if (!isset($data['code']) || $data['code'] !== 0) {
        debug_log("TikWM error raw: " . substr(json_encode($data), 0, 200));
        return ['status' => 'error', 'text' => 'TikWM error: ' . ($data['msg'] ?? 'Unknown')];
    }
    
    $video = $data['data'];
    
    // Prepare variants with strict 3-column fallback for TikWM
    $variants = [];
    
    // We strive to always provide 3 options: HD, SD, Audio
    $hdUrl = !empty($video['hdplay']) ? $video['hdplay'] : ($video['play'] ?? null);
    $sdUrl = !empty($video['play']) ? $video['play'] : ($video['hdplay'] ?? null);
    $musicUrl = !empty($video['music']) ? $video['music'] : null;
    $wmUrl = !empty($video['wmplay']) ? $video['wmplay'] : null;

    // 1. HD No Watermark (Always populated if video exists)
    if ($hdUrl) {
        $variants[] = [
            'type' => 'video-hd',
            'name' => 'HD NO WATERMARK (MP4)',
            'url' => $hdUrl,
            'size_bytes' => isset($video['hd_size']) ? (int)$video['hd_size'] : (isset($video['size']) ? (int)$video['size'] : null)
        ];
    }
    
    // 2. No Watermark (Always populated if video exists)
    if ($sdUrl) {
        $variants[] = [
            'type' => 'video-sd',
            'name' => 'NO WATERMARK (MP4)',
            'url' => $sdUrl,
            'size_bytes' => isset($video['size']) ? (int)$video['size'] : (isset($video['hd_size']) ? (int)$video['hd_size'] : null)
        ];
    }

    // 3. Audio (MP3) - Always populated, fallback to video url if music missing (browsers play audio from video)
    $audioUrl = $musicUrl ?? $hdUrl;
    if ($audioUrl) {
        $variants[] = [
            'type' => 'audio',
            'name' => 'MP3 AUDIO',
            'url' => $audioUrl,
            'size_bytes' => isset($video['music_info']['size']) ? (int)$video['music_info']['size'] : null
        ];
    }
    
    // 4. With Watermark (Optional extra)
    if ($wmUrl) {
        $variants[] = [
            'type' => 'video-watermark',
            'name' => 'WITH WATERMARK (MP4)',
            'url' => $wmUrl,
            'size_bytes' => isset($video['wm_size']) ? (int)$video['wm_size'] : null
        ];
    }
    
    // Return result with variants
    $mainUrl = $hdUrl ?? $sdUrl ?? $wmUrl ?? $musicUrl ?? null;
    
    if ($mainUrl) {
        return [
            'status' => 'tunnel',
            'url' => $mainUrl, // Fallback
            'filename' => 'tiktok_' . ($video['id'] ?? 'video') . '.mp4',
            'thumb' => $video['cover'] ?? $video['origin_cover'] ?? null,
            'title' => $video['title'] ?? 'TikTok Video',
            'author' => $video['author']['nickname'] ?? 'TikTok User',
            'variants' => $variants
        ];
    }
    
    return ['status' => 'error', 'text' => 'Video URL not found in TikWM'];
}

/**
 * Provider: LoveTik (TikTok Backup)
 */
function provider_lovetik($url, $format, $quality, $platform) {
    if ($platform !== 'tiktok') return null;
    
    $apiUrl = 'https://lovetik.com/api/ajax/search';
    $postData = http_build_query(['query' => $url]);
    
    debug_log("LoveTik trying");
    $response = curlRequest($apiUrl, 'POST', $postData, [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'Accept: */*',
        'Origin: https://lovetik.com',
        'Referer: https://lovetik.com/'
    ]);
    
    if (!$response || !$response['body']) return null;
    
    $data = json_decode($response['body'], true);
    if (!$data || $data['status'] !== 'ok') {
        debug_log("LoveTik fail/invalid: " . substr($response['body'], 0, 200));
        return null;
    }
    
    // Debug keys
    debug_log("LoveTik keys: " . implode(',', array_keys($data)) . " Links keys: " . (isset($data['links']) ? implode(',', array_keys($data['links'])) : 'none'));
    if (isset($data['mess'])) debug_log("LoveTik message: " . $data['mess']);
    
    if ($format === 'audio' && isset($data['links']['mp3'])) {
         return [
             'status' => 'tunnel',
             'url' => $data['links']['mp3'],
             'filename' => ($data['desc'] ?? 'tiktok') . '.mp3',
             'title' => $data['desc'] ?? 'TikTok Audio'
         ];
    }
    
    if (isset($data['links']['no_watermark'])) {
        return [
            'status' => 'tunnel',
            'url' => $data['links']['no_watermark'],
            'filename' => 'tiktok_' . ($data['vid'] ?? 'video') . '.mp4',
            'title' => $data['desc'] ?? 'TikTok Video',
        ];
    }
    
    // Maybe keys changed?
    if (isset($data['links'][0]['a'])) {
        // Try alternate parsing
        return [
            'status' => 'tunnel',
            'url' => $data['links'][0]['a'],
            'filename' => 'tiktok_video.mp4'
        ];
    }
    
    return null;
}

/**
 * Provider: OceanSaver (SSYouTube - YouTube/FB/IG/TikTok)
 */
function provider_oceansaver($url, $format, $quality, $platform) {
    // This provider works for multiple platforms, not just YouTube
    
    debug_log("OceanSaver trying for $platform");
    
    $apiUrl = 'https://p.oceansaver.in/ajax/download.php';
    $params = [
        'copyright' => '0',
        'format' => $format === 'audio' ? 'mp3' : 'mp4',
        'url' => $url,
        'api' => 'dfcb6d76f2f6a9894gjkege8a4ab232222'
    ];
    
    // GET request
    $response = curlRequest($apiUrl . '?' . http_build_query($params), 'GET', null, [
        'Accept: application/json',
        'Origin: https://ssyoutube.com',
        'Referer: https://ssyoutube.com/'
    ]);
    
    if (!$response || !isset($response['body'])) {
         debug_log("OceanSaver no response");
         return null;
    }
    
    $data = json_decode($response['body'], true);
    if (!$data || !isset($data['success']) || !$data['success']) {
         debug_log("OceanSaver failed: " . ($data['text'] ?? 'unknown'));
         return null;
    }
    
    // OceanSaver returns 'url' directly often, or 'download_url'
    $downloadUrl = $data['url'] ?? $data['download_url'] ?? null;
    
    // Sometimes it returns a list of formats in 'info'
    if (!$downloadUrl && isset($data['info'])) {
        // Parse info for best quality
        // Implementation depends on structure, usually simpler to just take what we get
    }
    
    if ($downloadUrl) {
         return [
            'status' => 'tunnel',
            'url' => $downloadUrl,
            'filename' => ($data['meta']['title'] ?? 'video') . '.' . ($format === 'audio' ? 'mp3' : 'mp4'),
            'title' => $data['meta']['title'] ?? 'Video'
         ];
    }
    
    return null;
}
/**
 * Provider: Snaptik (TikTok)
 */
function provider_snaptik($url, $format, $quality, $platform) {
    if ($platform !== 'tiktok') return null;
    
    $apiUrl = 'https://api.tik.fail/api/grab';
    $postData = http_build_query(['url' => $url]);
    
    debug_log("Snaptik(tik.fail) trying");
    $response = curlRequest($apiUrl, 'POST', $postData, [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ]);
    
    if (!$response || !$response['body']) return null;
    
    $data = json_decode($response['body'], true);
    if (!$data || !isset($data['status']) || $data['status'] !== 'success') {
         debug_log("Snaptik failed: " . ($data['status'] ?? 'unknown'));
         return null;
    }
    
    return [
       'status' => 'tunnel',
       'url' => $data['video'] ?? $data['nwm_video_url'] ?? '',
       'filename' => 'tiktok_snaptik.mp4',
       'title' => $data['desc'] ?? 'TikTok Video'
    ];
}

/**
 * Provider: DDownr (YouTube)
 * Placeholder for now as it requires complex async logic.
 * Just returning null to prevent undefined function error.
 */
function provider_ddownr($url, $format, $quality, $platform) {
   return null;
}

/**
 * Provider: Cobalt Fallback
 */
function provider_cobalt_fallback($url, $format, $quality, $platform) {
    // Only try cobalt if others failed
    
    // Cobalt instances that might work
    // Removed DNS failing ones
    $instances = [
        'https://co.eepy.today',
        'https://api.cobalt.tools',
        'https://cobalt-api.meowing.de',
        'https://cobalt-backend.canine.tools',
        'https://kityune.imput.net',
    ];
    
    $requestBody = [
        'url' => $url,
        'videoQuality' => $quality,
        'audioFormat' => 'mp3',
        'filenameStyle' => 'basic',
    ];
    
    if ($format === 'audio') {
        $requestBody['downloadMode'] = 'audio';
    } else {
        $requestBody['downloadMode'] = 'auto';
    }
    
    foreach ($instances as $apiBase) {
        $apiUrl = rtrim($apiBase, '/') . '/';
        
        // Debug
        debug_log("Cobalt fallback trying: $apiUrl");
        
        $response = curlRequest($apiUrl, 'POST', json_encode($requestBody), [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: MediaGrab/1.0',
        ]);
        
        if (!$response || !$response['body']) {
            debug_log("Cobalt $apiUrl no response");
            continue;
        }
        
        $httpCode = $response['httpCode'] ?? 0;
        $data = json_decode($response['body'], true);
        
        if (!$data) {
             debug_log("Cobalt $apiUrl invalid JSON");
             continue;
        }
        
        // Check for specific error codes
        if (isset($data['status']) && $data['status'] === 'error') {
            $errCode = $data['error']['code'] ?? '';
            // Skip auth errors
            if (strpos($errCode, 'auth') !== false || strpos($errCode, 'jwt') !== false) {
                 debug_log("Cobalt $apiUrl auth required ($errCode)");
                 continue;
            }
            // For other errors (like invalid video), just return error
            return $data;
        }
        
        if (isset($data['status']) && in_array($data['status'], ['tunnel', 'redirect', 'picker'])) {
            // Construct variants for 3-Column UI consistency
            // Cobalt typically returns one high-quality 'url'
            $mainUrl = $data['url'] ?? '';
            
            if ($mainUrl) {
                $data['variants'] = [
                    [
                        'type' => 'video-hd',
                        'name' => 'HD NO WATERMARK (MP4)',
                        'url' => $mainUrl
                    ],
                    [
                        'type' => 'video-sd',
                        'name' => 'NO WATERMARK (MP4)',
                        'url' => $mainUrl
                    ],
                    [
                        'type' => 'audio',
                        'name' => 'MP3 AUDIO',
                        'url' => $mainUrl // Browser will play audio from mp4
                    ]
                ];
            }
            
            return $data;
        }
        
        if ($httpCode === 429 || $httpCode >= 500) {
            debug_log("Cobalt $apiUrl HTTP $httpCode");
            continue;
        }
    }
    
    return null; // Disable cobalt for now to test other providers
}

// ============================================
// INSTAGRAM PROVIDER
// ============================================

/**
 * Provider: Instagram (Reels, Videos, Posts)
 * Multi-strategy: SnapSave -> Embed scrape -> GraphQL
 */
function provider_instagram($url, $format, $quality, $platform) {
    if ($platform !== 'instagram') return null;
    
    debug_log("Instagram provider trying");
    
    // Strategy 1: SnapSave
    $result = snapsave_download($url);
    if ($result) {
        debug_log("Instagram SnapSave success");
        $hdUrl = $result[0] ?? null;
        $sdUrl = $result[1] ?? $result[0] ?? null;
        return build_variants_response($hdUrl, $sdUrl, 'Instagram Video', null);
    }
    
    // Strategy 2: Try embed endpoint (does not require login)
    $videoUrl = instagram_embed_scrape($url);
    if ($videoUrl) {
        debug_log("Instagram embed scrape success");
        return build_variants_response($videoUrl, $videoUrl, 'Instagram Video', null);
    }
    
    // Strategy 3: Try GraphQL API
    $videoUrl = instagram_graphql($url);
    if ($videoUrl) {
        debug_log("Instagram GraphQL success");
        return build_variants_response($videoUrl, $videoUrl, 'Instagram Video', null);
    }
    
    debug_log("Instagram all strategies failed");
    return null;
}

/**
 * Instagram embed scrape - uses /embed/ endpoint which often shows video without login
 */
function instagram_embed_scrape($url) {
    // Extract shortcode from URL
    if (preg_match('/\/(p|reel|tv)\/([A-Za-z0-9_-]+)/', $url, $m)) {
        $shortcode = $m[2];
        $embedUrl = "https://www.instagram.com/p/{$shortcode}/embed/";
    } else {
        return null;
    }
    
    debug_log("Instagram trying embed: $embedUrl");
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $embedUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$html || $httpCode !== 200) {
        debug_log("Instagram embed HTTP $httpCode");
        return null;
    }
    
    // Look for video URL in embed page
    // Pattern: "video_url":"https://..."
    if (preg_match('/"video_url"\s*:\s*"([^"]+)"/', $html, $m)) {
        $videoUrl = json_decode('"' . $m[1] . '"'); // Decode JSON unicode escapes
        debug_log("Instagram embed found video_url");
        return $videoUrl;
    }
    
    // Pattern: data-video-url="..."
    if (preg_match('/data-video-url="([^"]+)"/', $html, $m)) {
        debug_log("Instagram embed found data-video-url");
        return html_entity_decode($m[1]);
    }
    
    // Pattern: og:video in embed  
    if (preg_match('/property="og:video"\s+content="([^"]+)"/i', $html, $m)) {
        debug_log("Instagram embed found og:video");
        return html_entity_decode($m[1]);
    }
    
    // Pattern: video source in embed HTML
    if (preg_match('/<video[^>]*>\s*<source\s+src="([^"]+)"/i', $html, $m)) {
        debug_log("Instagram embed found <video><source>");
        return html_entity_decode($m[1]);
    }
    
    debug_log("Instagram embed: no video found");
    return null;
}

/**
 * Instagram GraphQL - try public graphql endpoint
 */
function instagram_graphql($url) {
    // Extract shortcode
    if (!preg_match('/\/(p|reel|tv)\/([A-Za-z0-9_-]+)/', $url, $m)) {
        return null;
    }
    $shortcode = $m[2];
    
    $apiUrl = "https://www.instagram.com/graphql/query/?query_hash=b3055c01b4b222b8a47dc12b090e4e64&variables=" . urlencode(json_encode(['shortcode' => $shortcode]));
    
    debug_log("Instagram trying GraphQL");
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest',
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$response || $httpCode !== 200) {
        debug_log("Instagram GraphQL HTTP $httpCode");
        return null;
    }
    
    $data = json_decode($response, true);
    $media = $data['data']['shortcode_media'] ?? null;
    
    if ($media && isset($media['video_url'])) {
        debug_log("Instagram GraphQL found video_url");
        return $media['video_url'];
    }
    
    debug_log("Instagram GraphQL: no video");
    return null;
}

// ============================================
// FACEBOOK PROVIDER
// ============================================

/**
 * Provider: Facebook (Videos, Reels)
 * Multi-strategy: SnapSave -> mbasic scrape -> normal scrape
 */
function provider_facebook($url, $format, $quality, $platform) {
    if ($platform !== 'facebook') return null;
    
    debug_log("Facebook provider trying");
    
    // Strategy 1: SnapSave
    $result = snapsave_download($url);
    if ($result) {
        debug_log("Facebook SnapSave success");
        $hdUrl = $result[0] ?? null;
        $sdUrl = $result[1] ?? $result[0] ?? null;
        return build_variants_response($hdUrl, $sdUrl, 'Facebook Video', null);
    }
    
    // Strategy 2: mbasic.facebook.com scrape (simpler HTML, less JS)
    $result = facebook_mbasic_scrape($url);
    if ($result) {
        debug_log("Facebook mbasic scrape success");
        $hdUrl = $result['hd'] ?? $result['sd'];
        $sdUrl = $result['sd'] ?? $result['hd'];
        return build_variants_response($hdUrl, $sdUrl, 'Facebook Video', null);
    }
    
    // Strategy 3: Regular Facebook scrape
    $result = facebook_direct_scrape($url);
    if ($result) {
        debug_log("Facebook direct scrape success");
        $hdUrl = $result['hd'] ?? $result['sd'];
        $sdUrl = $result['sd'] ?? $result['hd'];
        return build_variants_response($hdUrl, $sdUrl, 'Facebook Video', null);
    }
    
    debug_log("Facebook all strategies failed");
    return null;
}

/**
 * Facebook mbasic scrape - mbasic.facebook.com has simpler HTML
 */
function facebook_mbasic_scrape($url) {
    // Convert URL to mbasic version
    $mbasicUrl = preg_replace('/^https?:\/\/(www\.|m\.)?facebook\.com/', 'https://mbasic.facebook.com', $url);
    // Also handle fb.watch links
    if (preg_match('/fb\.watch/', $url)) {
        $mbasicUrl = $url; // Let it redirect
    }
    
    debug_log("Facebook mbasic trying: $mbasicUrl");
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $mbasicUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$html || $httpCode !== 200) {
        debug_log("Facebook mbasic HTTP $httpCode");
        return null;
    }
    
    $result = [];
    
    // mbasic often has direct video links
    if (preg_match('/href="([^"]*video[^"]*\.mp4[^"]*)"/i', $html, $m)) {
        $result['sd'] = html_entity_decode($m[1]);
        debug_log("Facebook mbasic found mp4 href");
    }
    
    // Look for video src
    if (preg_match('/<video[^>]*src="([^"]+)"/i', $html, $m)) {
        $result['sd'] = html_entity_decode($m[1]);
        debug_log("Facebook mbasic found video src");
    }
    
    // Look for td_video_url
    if (preg_match('/td_video_url":"([^"]+)"/', $html, $m)) {
        $result['sd'] = stripslashes($m[1]);
        debug_log("Facebook mbasic found td_video_url");
    }
    
    return !empty($result) ? $result : null;
}

/**
 * Facebook direct scrape - standard desktop page
 */
function facebook_direct_scrape($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$html || $httpCode !== 200) {
        debug_log("Facebook direct HTTP $httpCode");
        return null;
    }
    
    $result = [];
    
    // Try hd_src / sd_src
    if (preg_match('/"hd_src"\s*:\s*"([^"]+)"/i', $html, $m)) {
        $result['hd'] = str_replace(['\\/', '\\u0025'], ['/', '%'], stripslashes($m[1]));
    }
    if (preg_match('/"sd_src"\s*:\s*"([^"]+)"/i', $html, $m)) {
        $result['sd'] = str_replace(['\\/', '\\u0025'], ['/', '%'], stripslashes($m[1]));
    }
    
    // Try browser_native URLs
    if (empty($result['hd']) && preg_match('/"browser_native_hd_url"\s*:\s*"([^"]+)"/i', $html, $m)) {
        $result['hd'] = str_replace('\\/', '/', stripslashes($m[1]));
    }
    if (empty($result['sd']) && preg_match('/"browser_native_sd_url"\s*:\s*"([^"]+)"/i', $html, $m)) {
        $result['sd'] = str_replace('\\/', '/', stripslashes($m[1]));
    }
    
    // Try playable_url
    if (empty($result) && preg_match('/"playable_url"\s*:\s*"([^"]+)"/i', $html, $m)) {
        $result['sd'] = str_replace('\\/', '/', stripslashes($m[1]));
    }
    if (empty($result['hd']) && preg_match('/"playable_url_quality_hd"\s*:\s*"([^"]+)"/i', $html, $m)) {
        $result['hd'] = str_replace('\\/', '/', stripslashes($m[1]));
    }
    
    // Try og:video
    if (empty($result) && preg_match('/property="og:video"\s+content="([^"]+)"/i', $html, $m)) {
        $result['sd'] = html_entity_decode($m[1]);
    }
    
    return !empty($result) ? $result : null;
}

// ============================================
// SNAPSAVE CORE ENGINE
// ============================================

/**
 * SnapSave.app downloader - works for IG, FB, TikTok
 * Returns array of download URLs or null
 */
function snapsave_download($url) {
    // Step 1: Get the encoded response from SnapSave
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://snapsave.app/action.php',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['url' => $url]),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: */*',
            'Origin: https://snapsave.app',
            'Referer: https://snapsave.app/',
        ],
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$response || $httpCode !== 200) {
        debug_log("SnapSave HTTP $httpCode");
        return null;
    }
    
    // Step 2: Decode the obfuscated JS response
    $decoded = snapsave_decode($response);
    
    if (!$decoded) {
        debug_log("SnapSave decode failed");
        return null;
    }
    
    // Step 3: Extract download URLs from the decoded HTML
    $urls = snapsave_extract_urls($decoded);
    
    if (empty($urls)) {
        debug_log("SnapSave no URLs found in decoded HTML");
        return null;
    }
    
    return $urls;
}

/**
 * Decode SnapSave's obfuscated JS response
 * Uses eval(function(h,u,n,t,e,r){...}) packer format
 */
function snapsave_decode($response) {
    // Pattern: eval(function(h,u,n,t,e,r)...}("payload",31,"alphabet",15,4,8))
    // We need to extract the arguments passed to the anonymous function
    if (preg_match('/eval\(function\(h,u,n,t,e,r\).*?\((.*?)\)\)/s', $response, $matches)) {
        $argsStr = $matches[1];
        
        // Parse arguments - complicated because the first arg is a huge string with potential commas
        // But in SnapSave it's usually "string", number, "string", number, number, number
        
        // Extract the payload (h) - first quoted string
        if (preg_match('/^"([^"]+)"\s*,\s*(\d+)\s*,\s*"([^"]+)"\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $argsStr, $args)) {
            $h = $args[1];
            $u = intval($args[2]); // 31 (base used for something?)
            $n = $args[3];         // alphabet
            $t = intval($args[4]); // 15 (subtraction offset)
            $e = intval($args[5]); // 4 (base for unpacking)
            $r = intval($args[6]); // 8 (unused?)
            
            debug_log("SnapSave Params: u=$u, n=" . strlen($n) . " chars, t=$t, e=$e");
            
            $decoded = snapsave_unpack($h, $n, $t, $e);
            
            // Temporary Debug: Log decoded HTML
            if ($decoded) {
                 debug_log("HTML_DUMP_START");
                 debug_log(substr($decoded, 0, 2000));
                 debug_log("HTML_DUMP_END");
            }

            return $decoded ?: null;
        }
    }
    
    // Fallback: Try regex for specific parameter patterns if the above fails
    if (preg_match('/\}\("([^"]+)",\s*(\d+)\s*,\s*"([^"]+)"\s*,\s*(\d+)\s*,\s*(\d+)/', $response, $m)) {
        $h = $m[1];
        $n = $m[3];
        $t = intval($m[4]);
        $e = intval($m[5]);
        
        debug_log("SnapSave decode (fallback): h_len=" . strlen($h) . " n=$n t=$t e=$e");
        
        $decoded = snapsave_unpack($h, $n, $t, $e);
        
        if ($decoded) {
            // debug_log("HTML_DUMP_START (Fallback)");
            // debug_log("B64: " . base64_encode(substr($decoded, 0, 2000)));
            // debug_log("HTML_DUMP_END");
        }

        return $decoded ?: null;
    }

    return null;
}

/**
 * Unpack SnapSave encoded string
 * Implements the JS: for each segment between n[e] separators,
 * replace chars from n with their indices, convert from base e to decimal,
 * subtract t, and chr() the result
 */
function snapsave_unpack($h, $n, $t, $e) {
    $result = '';
    $i = 0;
    $len = strlen($h);
    
    // n[e] is the separator character (the char at index e in alphabet n)
    // If e >= strlen(n), there's no separator — each char is its own segment
    $separator = ($e < strlen($n)) ? $n[$e] : null;
    
    while ($i < $len) {
        $s = '';
        
        // Collect characters until we hit the separator
        if ($separator !== null) {
            while ($i < $len && $h[$i] !== $separator) {
                $s .= $h[$i];
                $i++;
            }
            $i++; // skip separator
        } else {
            $s = $h[$i];
            $i++;
        }
        
        if ($s === '') continue;
        
        // Replace each character in s with its index in n
        $numStr = '';
        for ($j = 0; $j < strlen($s); $j++) {
            $pos = strpos($n, $s[$j]);
            if ($pos !== false) {
                $numStr .= $pos;
            }
        }
        
        if ($numStr === '') continue;
        
        // Convert from base e to decimal
        $charCode = snapsave_base_to_dec($numStr, $e) - $t;
        
        if ($charCode > 0 && $charCode < 1114112) {
            // Handle UTF-8
            $result .= mb_chr($charCode, 'UTF-8');
        }
    }
    
    return $result;
}

/**
 * Convert a number string from given base to decimal
 */
function snapsave_base_to_dec($numStr, $base) {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ+/';
    $val = 0;
    $digits = str_split($numStr);
    
    foreach ($digits as $digit) {
        $pos = strpos($chars, $digit);
        if ($pos === false) {
            // Treat as numeric digit
            $pos = intval($digit);
        }
        $val = $val * $base + $pos;
    }
    
    return $val;
}

/**
 * Extract download URLs from decoded SnapSave HTML
 */
function snapsave_extract_urls($html) {
    $urls = [];
    
    // Look for download button hrefs
    if (preg_match_all('/href="(https?:\/\/[^"]*(?:\.mp4|\.mp3|video|reel|content)[^"]*)"/i', $html, $matches)) {
        foreach ($matches[1] as $url) {
            $url = html_entity_decode($url);
            $url = str_replace('\\/', '/', $url);
            if (!in_array($url, $urls)) {
                $urls[] = $url;
            }
        }
    }
    
    // Also try data-href or src attributes
    if (preg_match_all('/(?:data-href|src)="(https?:\/\/[^"]*(?:\.mp4|\.mp3|video)[^"]*)"/i', $html, $matches)) {
        foreach ($matches[1] as $url) {
            $url = html_entity_decode($url);
            $url = str_replace('\\/', '/', $url);
            if (!in_array($url, $urls)) {
                $urls[] = $url;
            }
        }
    }
    
    // Try any URL that looks like a CDN video link
    if (empty($urls) && preg_match_all('/(https?:\/\/[^\s"\'<>]+(?:\.mp4|\.mp3|scontent|fbcdn|cdninstagram)[^\s"\'<>]*)/i', $html, $matches)) {
        foreach ($matches[1] as $url) {
            $url = html_entity_decode($url);
            $url = str_replace(['\\/', '\\u0026'], ['/', '&'], $url);
            if (!in_array($url, $urls)) {
                $urls[] = $url;
            }
        }
    }
    
    debug_log("SnapSave extracted " . count($urls) . " URLs");
    return $urls;
}

// ============================================
// SHARED VARIANT BUILDER
// ============================================

/**
 * Build a standard 3-variant response for consistent UI
 */
function build_variants_response($hdUrl, $sdUrl, $title, $thumb) {
    $variants = [];
    
    if ($hdUrl) {
        $variants[] = [
            'type' => 'video-hd',
            'name' => 'HD NO WATERMARK (MP4)',
            'url' => $hdUrl
        ];
    }
    
    if ($sdUrl) {
        $variants[] = [
            'type' => 'video-sd',
            'name' => 'NO WATERMARK (MP4)',
            'url' => $sdUrl
        ];
    }
    
    // Audio variant (fallback to video URL)
    $audioUrl = $hdUrl ?? $sdUrl;
    if ($audioUrl) {
        $variants[] = [
            'type' => 'audio',
            'name' => 'MP3 AUDIO',
            'url' => $audioUrl
        ];
    }
    
    $mainUrl = $hdUrl ?? $sdUrl;
    
    return [
        'status' => 'tunnel',
        'url' => $mainUrl,
        'filename' => 'video_download.mp4',
        'thumb' => $thumb,
        'title' => $title,
        'variants' => $variants
    ];
}

// ============================================
// UTILITIES
// ============================================

function curlRequest($url, $method = 'GET', $body = null, $headers = []) {
    $ch = curl_init();
    
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];
    
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($body) $opts[CURLOPT_POSTFIELDS] = $body;
    }
    
    curl_setopt_array($ch, $opts);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        // Log to global debug
        global $debug_log;
        $debug_log[] = "cURL Error to $url: $error";
        return ['error' => $error];
    }
    
    return [
        'body' => $response,
        'httpCode' => $httpCode
    ];
}

function detectPlatform($url) {
    if (preg_match('/tiktok\.com/i', $url)) return 'tiktok';
    if (preg_match('/youtu\.?be/i', $url)) return 'youtube';
    if (preg_match('/instagram\.com/i', $url)) return 'instagram';
    if (preg_match('/twitter\.com|x\.com/i', $url)) return 'twitter';
    if (preg_match('/facebook\.com|fb\.watch/i', $url)) return 'facebook';
    return 'unknown';
}

/**
 * Provider: Douyin/TikTok (Fallback)
 */
function provider_douyin($url, $format, $quality, $platform) {
   if ($platform !== 'tiktok') return null;
   
   $apiUrl = 'https://api.douyin.wtf/api?url=' . urlencode($url);
   debug_log("Douyin trying GET");
   
   $response = curlRequest($apiUrl, 'GET');
   
   if (isset($response['error'])) {
       debug_log("Douyin curl error: " . $response['error']);
       return null;
   }
   
   $data = json_decode($response['body'], true);
   if (!$data || ($data['status'] ?? '') === 'failed') {
       debug_log("Douyin failed status: " . ($data['status'] ?? 'unknown'));
       return null;
   }
   
   // Parse douyin response (usually direct JSON)
   if (isset($data['video_data']['nwm_video_url'])) {
       return [
            'status' => 'tunnel',
            'url' => $data['video_data']['nwm_video_url'],
            'filename' => 'tiktok_' . ($data['aweme_id'] ?? 'video') . '.mp4',
            'title' => $data['desc'] ?? 'TikTok Video',
       ];
   }
   
   return null;
}

/**
 * Provider: Apify (Instagram Downloader)
 * Uses apilabs/instagram-downloader or compatible
 */
function provider_apify($url, $format, $quality, $platform) {
    if ($platform !== 'instagram') return null;

    debug_log("Apify provider trying...");
    
    $token = 'apify_api_b5YVtXxKW62rb6tPKO4BbBlY5c8w3k1e4uA1'; // User provided key
    $actorId = 'apilabs~instagram-downloader'; // Tilde format
    $apiUrl = "https://api.apify.com/v2/acts/$actorId/run-sync-get-dataset-items?token=$token";

    // Prepare input (Guessing standard format mostly used by these actors)
    $input = [
        "urls" => [$url],
        "directUrls" => [$url]
    ];

    $response = curlRequest($apiUrl, json_encode($input), [
        'Content-Type: application/json'
    ]);

    if (!$response || !isset($response['body'])) {
        debug_log("Apify no response");
        return null;
    }

    $json = json_decode($response['body'], true);
    
    if (!$json || !is_array($json)) {
        debug_log("Apify invalid JSON");
        return null;
    }
    
    if (empty($json)) {
         debug_log("Apify empty result []");
         return null;
    }

    // Try to find video URL in the first result
    $item = $json[0] ?? [];
    $videoUrl = $item['videoUrl'] ?? $item['downloadUrl'] ?? $item['video_url'] ?? null;
    
    if (!$videoUrl && isset($item['media']) && is_array($item['media'])) {
        // Sometimes nested in media
        foreach ($item['media'] as $m) {
             if (isset($m['url'])) {
                 $videoUrl = $m['url'];
                 break;
             }
        }
    }

    if ($videoUrl) {
        debug_log("Apify success: $videoUrl");
        return build_variants_response($videoUrl, $videoUrl, 'Instagram Video (Apify)', null);
    }
    
    debug_log("Apify no video URL found in response");
    return null;
}

/**
 * Provider: Medsoss Downloader (Vercel)
 * Discovered via network inspection - works reliably for Instagram
 * API: https://medsoss-downloader.vercel.app/api/index
 */
function provider_medsoss($url) {
    debug_log("Trying Medsoss provider");
    
    $apiUrl = 'https://medsoss-downloader.vercel.app/api/index';
    
    $payload = json_encode([
        'url' => $url
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: */*',
        'Origin: https://medsoss-downloader.vercel.app',
        'Referer: https://medsoss-downloader.vercel.app/'
    ]);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        debug_log("Medsoss cURL error: $error");
        return null;
    }
    
    if ($httpCode !== 200) {
        debug_log("Medsoss HTTP error: $httpCode");
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['success']) || $data['success'] !== true) {
        debug_log("Medsoss API returned error or invalid response");
        return null;
    }
    
    if (!isset($data['data']) || !is_array($data['data']) || empty($data['data'])) {
        debug_log("Medsoss no data array in response");
        return null;
    }
    
    // Extract video URLs from data array
    $variants = [];
    foreach ($data['data'] as $index => $item) {
        if (isset($item['url'])) {
            $quality = isset($item['quality']) ? $item['quality'] : 'HD';
            $type = isset($item['type']) ? $item['type'] : 'video';
            
            $variants[] = [
                'type' => $type === 'audio' ? 'audio' : 'video-hd',
                'name' => strtoupper($quality) . ' ' . strtoupper($type),
                'url' => $item['url']
            ];
        }
    }
    
    if (empty($variants)) {
        debug_log("Medsoss no valid URLs found in data");
        return null;
    }
    
    debug_log("Medsoss success: " . count($variants) . " variants found");
    
    // Return first variant as main URL, all variants for picker
    return [
        'status' => 'redirect',
        'url' => $variants[0]['url'],
        'filename' => 'instagram_video_' . time() . '.mp4',
        'variants' => $variants
    ];
}


function respond($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
