/* ========================================
   MEDIA GRAB — Core JavaScript
   ======================================== */

// --- DOM Elements ---
const urlInput = document.getElementById('urlInput');
const pasteBtn = document.getElementById('pasteBtn');
const clearBtn = document.getElementById('clearBtn');
const downloadBtn = document.getElementById('downloadBtn');
const detectedPlatform = document.getElementById('detectedPlatform');
const platformName = document.getElementById('platformName');
const loadingState = document.getElementById('loadingState');
const resultArea = document.getElementById('resultArea');
const errorArea = document.getElementById('errorArea');
const retryBtn = document.getElementById('retryBtn');
const newDownloadBtn = document.getElementById('newDownloadBtn');

// Result elements
const errorTitle = document.getElementById('errorTitle');
const errorMessage = document.getElementById('errorMessage');

// --- State ---
let selectedFormat = 'video-hd';
let selectedPlatform = 'all';
let detectedPlatformType = null;

// --- API Config ---
// Pakai PHP proxy lokal untuk bypass CORS
const API_URL = 'api.php';

// --- Platform Detection Patterns ---
const platformPatterns = {
    tiktok: {
        regex: /(?:https?:\/\/)?(?:www\.|vm\.|vt\.)?tiktok\.com\/.+/i,
        name: 'TikTok',
        icon: 'fab fa-tiktok',
        color: '#000000'
    },
    youtube: {
        regex: /(?:https?:\/\/)?(?:www\.|m\.)?(?:youtube\.com|youtu\.be)\/.+/i,
        name: 'YouTube',
        icon: 'fab fa-youtube',
        color: '#FF0000'
    },
    instagram: {
        regex: /(?:https?:\/\/)?(?:www\.)?instagram\.com\/.+/i,
        name: 'Instagram',
        icon: 'fab fa-instagram',
        color: '#E4405F'
    },
    twitter: {
        regex: /(?:https?:\/\/)?(?:www\.|mobile\.)?(?:twitter\.com|x\.com)\/.+/i,
        name: 'X (Twitter)',
        icon: 'fab fa-x-twitter',
        color: '#000000'
    },
    facebook: {
        regex: /(?:https?:\/\/)?(?:www\.|m\.|web\.)?(?:facebook\.com|fb\.watch)\/.+/i,
        name: 'Facebook',
        icon: 'fab fa-facebook',
        color: '#1877F2'
    }
};

// --- Platform Tab Switching ---
document.querySelectorAll('.platform-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.platform-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        selectedPlatform = tab.dataset.platform;

        // Update placeholder based on platform
        const placeholders = {
            all: 'Paste link video di sini... (TikTok, YouTube, Instagram, X, Facebook)',
            tiktok: 'Paste link TikTok di sini... (contoh: https://vt.tiktok.com/...)',
            youtube: 'Paste link YouTube di sini... (contoh: https://youtu.be/...)',
            instagram: 'Paste link Instagram di sini... (contoh: https://instagram.com/reel/...)',
            twitter: 'Paste link X/Twitter di sini... (contoh: https://x.com/...)',
            facebook: 'Paste link Facebook di sini... (contoh: https://facebook.com/...)'
        };
        urlInput.placeholder = placeholders[selectedPlatform] || placeholders.all;
    });
});

// --- Format Selection ---
document.querySelectorAll('.format-card').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('.format-card').forEach(c => c.classList.remove('active'));
        card.classList.add('active');
        selectedFormat = card.dataset.format;
    });
});

// --- Paste Button ---
pasteBtn.addEventListener('click', async () => {
    try {
        const text = await navigator.clipboard.readText();
        urlInput.value = text;
        urlInput.dispatchEvent(new Event('input'));

        // Visual feedback
        pasteBtn.innerHTML = '<i class="fas fa-check"></i>';
        setTimeout(() => {
            pasteBtn.innerHTML = '<i class="fas fa-paste"></i>';
        }, 1500);
    } catch (err) {
        // Fallback: focus input so user can paste manually
        urlInput.focus();
        pasteBtn.innerHTML = '<i class="fas fa-keyboard"></i>';
        setTimeout(() => {
            pasteBtn.innerHTML = '<i class="fas fa-paste"></i>';
        }, 1500);
    }
});

// --- Clear Button ---
clearBtn.addEventListener('click', () => {
    urlInput.value = '';
    clearBtn.classList.add('hidden');
    pasteBtn.classList.remove('hidden');
    detectedPlatform.classList.add('hidden');
    detectedPlatformType = null;
    urlInput.focus();
});

// --- URL Input Handler ---
urlInput.addEventListener('input', () => {
    const url = urlInput.value.trim();

    // Toggle clear/paste buttons
    if (url.length > 0) {
        clearBtn.classList.remove('hidden');
        pasteBtn.classList.add('hidden');
    } else {
        clearBtn.classList.add('hidden');
        pasteBtn.classList.remove('hidden');
        detectedPlatform.classList.add('hidden');
        detectedPlatformType = null;
        return;
    }

    // Auto-detect platform
    detectedPlatformType = null;
    for (const [key, platform] of Object.entries(platformPatterns)) {
        if (platform.regex.test(url)) {
            detectedPlatformType = key;
            platformName.innerHTML = `<i class="${platform.icon}"></i> ${platform.name} terdeteksi!`;
            detectedPlatform.classList.remove('hidden');

            // Auto-switch platform tab
            document.querySelectorAll('.platform-tab').forEach(t => t.classList.remove('active'));
            const matchingTab = document.querySelector(`.platform-tab[data-platform="${key}"]`);
            if (matchingTab) matchingTab.classList.add('active');
            selectedPlatform = key;
            break;
        }
    }

    if (!detectedPlatformType) {
        detectedPlatform.classList.add('hidden');
    }
});

// --- Download Button ---
downloadBtn.addEventListener('click', () => {
    const url = urlInput.value.trim();

    if (!url) {
        shakeElement(urlInput.closest('.input-wrapper'));
        urlInput.focus();
        return;
    }

    // Validate URL format
    if (!isValidUrl(url)) {
        showError('Link Tidak Valid', 'Pastikan kamu memasukkan link yang benar dari platform yang didukung.');
        return;
    }

    startDownload(url);
});

// Allow Enter key to trigger download
urlInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        downloadBtn.click();
    }
});

// --- Build API Request Body ---
function buildRequestBody(url, format) {
    const body = {
        url: url,
        videoQuality: '1080',
        audioFormat: 'mp3',
        audioBitrate: '128',
    };

    switch (format) {
        case 'video-hd':
            body.videoQuality = '1080';
            body.downloadMode = 'auto';
            break;
        case 'video-no-wm':
            body.videoQuality = '720';
            body.downloadMode = 'auto';
            break;
        case 'audio':
            body.downloadMode = 'audio';
            body.audioFormat = 'mp3';
            body.audioBitrate = '320';
            body.tiktokFullAudio = true;
            break;
        case 'video-mp4':
            body.videoQuality = '720';
            body.downloadMode = 'auto';
            body.youtubeVideoCodec = 'h264';
            break;
    }

    return body;
}

// --- Download Process ---
const COBALT_INSTANCES = [
    'https://co.eepy.today',
    'https://cobalt.xyzen.dev', // Likely v10
    'https://api.cobalt.tools',
    'https://cobalt.tools',
    'https://cobalt-api.meowing.de',
    'https://cobalt-backend.canine.tools',
    'https://kityune.imput.net',
    'https://dl.khub.aa.am',
    'https://api.wwebs.xyz'
];

async function startDownload(url) {
    hideAllStates();
    loadingState.classList.remove('hidden');
    // Reset loading text
    const loadingText = loadingState.querySelector('p');
    if (loadingText) loadingText.textContent = 'MEMPROSES DATA...';

    downloadBtn.disabled = true;

    // Scroll ke loading
    loadingState.scrollIntoView({ behavior: 'smooth', block: 'center' });

    try {
        // Derive platform if missing (robustness fix)
        if (!detectedPlatformType) {
            for (const [key, platform] of Object.entries(platformPatterns)) {
                if (platform.regex.test(url)) {
                    detectedPlatformType = key;
                    break;
                }
            }
        }

        const requestBody = buildRequestBody(url, selectedFormat);

        // Detect platform for routing
        const isYouTube = /youtube\.com|youtu\.be/i.test(url);
        const isInstagram = /instagram\.com/i.test(url);
        const isTikTok = /tiktok\.com|douyin\.com/i.test(url);

        // Unified Client-Side Strategy: YouTube, Instagram, TikTok (All via Medsoss)
        if (isYouTube || isInstagram || isTikTok) {
            try {
                let platformName = 'PLATFORM';
                if (isYouTube) platformName = 'YOUTUBE';
                else if (isInstagram) platformName = 'INSTAGRAM';
                else if (isTikTok) platformName = 'TIKTOK';
                if (loadingText) loadingText.textContent = `MENGAMBIL DATA ${platformName}...`;

                const medsossResponse = await fetch('https://medsoss-downloader.vercel.app/api/index', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': '*/*'
                    },
                    body: JSON.stringify({ url: url })
                });

                if (medsossResponse.ok) {
                    const medsossData = await medsossResponse.json();

                    if (medsossData.success && medsossData.data && medsossData.data.length > 0) {
                        console.log('Medsoss client-side success:', medsossData);

                        // Extract variants from Medsoss response
                        let allVariants = medsossData.data.map((item, index) => {
                            let downloadUrl = item.url;

                            // Direct URL (No Proxy) - trying to match IG success
                            // if (isYouTube) ... removed proxy logic

                            return {
                                type: item.type === 'audio' ? 'audio' : 'video-hd',
                                name: (item.quality || item.label || 'HD') + ' ' + (item.type || 'VIDEO').toUpperCase(),
                                url: downloadUrl,
                                rawUrl: item.url, // Original CDN URL (before proxy wrapping)
                                quality: item.quality || item.qualityLabel || 'hd',
                                rawType: item.type,
                                isAudio: item.is_audio,
                                extension: item.extension,
                                size: item.formattedSize || (item.size ? formatBytes(item.size) : 'UNKNOWN')
                            };
                        });

                        // For YouTube: Filter to show only complete video+audio files (not separate streams)
                        let variants = allVariants;
                        if (isYouTube) {
                            // Filter: Only videos that have built-in audio (is_audio === true)
                            // This excludes video-only streams that need audio merging
                            const completeVideos = allVariants.filter(v => v.rawType === 'video' && v.isAudio === true);

                            // Also add standalone audio files
                            const audioOnly = allVariants.filter(v => v.rawType === 'audio');

                            if (completeVideos.length > 0) {
                                // Use the first complete video (usually 360p or 720p with audio)
                                variants = [completeVideos[0]];

                                // Add audio options if available
                                if (audioOnly.length > 0) {
                                    variants.push(audioOnly[0]); // Add first audio option (usually best quality)
                                }
                            } else {
                                // Fallback: if no complete videos, show first variant
                                variants = [allVariants[0]];
                            }
                        }

                        let platform = 'instagram';
                        if (isYouTube) platform = 'youtube';
                        if (isTikTok) platform = 'tiktok';
                        const filename = `${platform}_video_` + Date.now() + '.mp4';

                        showResult({
                            downloadUrl: variants[0].url,
                            filename: filename,
                            title: getVideoTitle(url),
                            platform: platform,
                            format: selectedFormat,
                            variants: variants,
                            thumb: null
                        });
                        return;
                    }
                }
            } catch (medsossError) {
                console.log('Medsoss client-side failed:', medsossError.message);
                // Continue to server-side fallback
            }
        }

        // For TikTok: Try Client-Side Cobalt FIRST (Bypass Vercel Proxy)
        if (isTikTok) {
            if (loadingText) loadingText.textContent = 'MENGAMBIL DATA TIKTOK (CLIENT SIDE)...';
            const cobaltData = await tryCobaltClientSide(url);

            if (cobaltData) {
                console.log('Cobalt client-side success:', cobaltData);
                showResult({
                    downloadUrl: cobaltData.url,
                    filename: 'tiktok_cs_' + Date.now() + '.mp4',
                    title: getVideoTitle(url),
                    platform: 'tiktok',
                    format: selectedFormat,
                    variants: cobaltData.variants,
                    thumb: cobaltData.thumb || null
                });
                return;
            }
        }

        // Fallback to server-side API if client-side fails
        if (loadingText) loadingText.textContent = 'MENGAMBIL DATA TIKTOK...';

        const response = await fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestBody)
        });

        const responseText = await response.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error('API Parse Error:', e);
            console.error('Raw Response:', responseText);
            throw new Error(`Server Error: ${responseText.slice(0, 50) || 'Empty Response'} (Status: ${response.status})`);
        }

        // Cek error dari proxy atau API
        if (data.status === 'error') {
            // No fallback - Medsoss is the only provider and should handle all cases
            const errorText = data.text || data.error?.code || 'Gagal memproses link';
            throw new Error(translateError(errorText));
        }

        // Proses response berdasarkan status
        if (data.status === 'tunnel' || data.status === 'redirect') {
            // Direct download URL
            showResult({
                downloadUrl: data.url,
                filename: data.filename || 'download',
                title: getVideoTitle(url),
                platform: detectedPlatformType || 'unknown',
                format: selectedFormat,
                variants: data.variants, // Pass variants to enable stacked cards
                thumb: data.thumb // Pass thumb for poster
            });
        } else if (data.status === 'picker') {
            // Multiple items (carousel posts, etc)
            if (data.picker && data.picker.length > 0) {
                showPickerResult(data.picker, url);
            } else {
                throw new Error('Tidak ditemukan media yang bisa didownload.');
            }
        } else {
            throw new Error('Response tidak dikenali dari server.');
        }

    } catch (error) {
        console.error('Download error:', error);

        let errorMsg = error.message;

        // Friendly error messages
        if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
            errorMsg = 'Gagal menghubungi server. Pastikan XAMPP/Apache sedang berjalan dan coba lagi.';
        }

        showError('Oops! Terjadi Kesalahan', errorMsg);
    } finally {
        downloadBtn.disabled = false;
        // Reset loading text just in case
        if (loadingText) loadingText.textContent = 'MEMPROSES DATA...';
    }
}

/**
 * Client-Side Cobalt Fallback
 * Tries to fetch directly from public Cobalt instances if backend fails
 */
async function tryCobaltClientSide(url) {
    const loadingText = loadingState.querySelector('p');

    // We will try both v10 (root) and v7 (/api/json) paths for each instance
    // Diagnostic results showed co.eepy.today works on root /

    for (let i = 0; i < COBALT_INSTANCES.length; i++) {
        const instance = COBALT_INSTANCES[i];

        // Define paths to try: Root first (modern), then API/JSON (legacy)
        const paths = ['', '/api/json'];

        for (const path of paths) {
            try {
                const endpoint = `${instance}${path}`;
                console.log(`Trying fallback: ${endpoint}`);

                if (loadingText) loadingText.textContent = `MENCOBA SERVER CADANGAN ${i + 1}...`;

                // Cobalt v10 strict headers
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        url: url
                        // Minimal payload - tested and working with co.eepy.today
                    })
                });

                // If 404, it might be wrong path, try next path
                if (response.status === 404) continue;

                const data = await response.json();

                // Check for specific API error codes that mean "Server is working but failed to fetch content"
                // This means connection is GOOD, just content is hard.
                if (data.status === 'error') {
                    console.log(`Server ${endpoint} replied with error:`, data.error);
                    continue;
                }

                if (data.status === 'tunnel' || data.status === 'redirect' || data.status === 'picker') {
                    // Success!
                    // Construct standard variant structure
                    const mainUrl = data.url || (data.picker ? data.picker[0].url : null);
                    if (mainUrl) {
                        data.variants = [
                            { type: 'video-hd', name: 'HD VIDEO (MP4)', url: mainUrl },
                            { type: 'audio', name: 'MP3 AUDIO', url: mainUrl }
                        ];
                        data.url = mainUrl;
                        return data;
                    }
                }
            } catch (e) {
                console.warn(`Fallback failed for ${instance}${path}:`, e);
            }
        }
    }
    return null;
}

// --- Translate API Error Codes ---
function translateError(errorCode) {
    const errors = {
        'error.api.link.invalid': 'Link tidak valid atau tidak didukung.',
        'error.api.link.unsupported': 'Platform ini belum didukung.',
        'error.api.fetch.fail': 'Gagal mengambil data dari platform. Video mungkin private atau sudah dihapus.',
        'error.api.fetch.rate': 'Terlalu banyak request. Tunggu sebentar lalu coba lagi.',
        'error.api.content.video.unavailable': 'Video tidak tersedia atau sudah dihapus.',
        'error.api.content.video.live': 'Tidak bisa mendownload live stream.',
        'error.api.content.post.age': 'Konten terlalu lama dan tidak bisa didownload.',
        'error.api.unreachable': 'Semua server API sedang tidak tersedia. Coba lagi nanti.',
        'error.api.rate_exceeded': 'Rate limit tercapai. Tunggu beberapa menit.',
        'error.api.authentication': 'Server API membutuhkan autentikasi.',
    };

    // Match partial error codes
    for (const [key, msg] of Object.entries(errors)) {
        if (errorCode.includes(key)) return msg;
    }

    return errorCode;
}

// --- Show Single Result ---
function showResult(data) {
    hideAllStates();

    // Prepare list of variants
    let variants = data.variants || [];

    // Fallback if no variants but main URL exists
    if (variants.length === 0 && data.downloadUrl) {
        variants.push({
            type: 'video',
            name: 'VIDEO WITH WATERMARK',
            url: data.downloadUrl
        });
    }

    // Build Stacked HTML
    const stackHtml = variants.map((variant, index) => {
        // Imitate the latest screenshot's specific labeling
        // Screenshot shows: VIDEO_DATA [no_watermark]
        let typeInfo = '';
        if (variant.type === 'video-hd') typeInfo = 'VIDEO_DATA [hd_no_watermark]';
        else if (variant.type === 'video-sd') typeInfo = 'VIDEO_DATA [no_watermark]';
        else if (variant.type === 'video-watermark') typeInfo = 'VIDEO_DATA [watermark]';
        else if (variant.type === 'audio') typeInfo = 'AUDIO_STREAM';
        else typeInfo = 'MEDIA_DATA [unknown]';

        // Generate filename consistent with screenshot style
        // e.g. Rehan_DL_1771060346424_1.mp4

        const timestamp = Date.now();
        const ext = variant.type === 'audio' ? 'mp3' : 'mp4';
        const finalFilename = `Rehan_DL_${timestamp}_${index + 1}.${ext}`;
        const sizeId = `size-${timestamp}-${index}`;

        let displaySize = 'Menghitung...';
        if (variant.size) {
            displaySize = variant.size;
        } else if (variant.size_bytes) {
            displaySize = formatBytes(variant.size_bytes);
        } else {
            // Trigger async fetch
            setTimeout(() => fetchFileSize(variant.url, sizeId), 100);
        }

        return `
            <div class="result-item" data-raw-url="${variant.rawUrl || variant.url}">
                <div class="result-header-dark">
                    <span class="result-type">${typeInfo}</span>
                    <span class="success-badge">SUCCESS</span>
                </div>
                
                <div class="result-content">
                    <div class="result-preview">
                        ${variant.type === 'audio'
                ? `<audio controls src="${variant.url}"></audio>`
                : `<video controls autoplay muted loop playsinline webkit-playsinline src="${variant.url}" poster="${data.thumb || ''}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;"></video>`
            }
                    </div>
                    
                    <div class="result-details">
                        > FILENAME:<br>
                        ${finalFilename}<br>
                        > SIZE: <span id="${sizeId}">${displaySize}</span>
                    </div>
                    
                    <a href="download.php?url=${encodeURIComponent(variant.url)}&filename=${finalFilename}" target="_blank" class="btn-orange">
                        DOWNLOAD NOW
                    </a>
                </div>
            </div>
        `;
    }).join('');

    const containerHtml = `
        <div class="result-stack">
            ${stackHtml}
            
            <button id="newDownloadBtn" class="download-btn" style="background: white; color: black; font-size: 0.9rem; margin-top: 20px;">
                DOWNLOAD ANOTHER
            </button>
        </div>
    `;

    resultArea.innerHTML = containerHtml;
    resultArea.classList.remove('hidden');
    resultArea.scrollIntoView({ behavior: 'smooth', block: 'center' });

    // Attach event listener to new button
    document.getElementById('newDownloadBtn')?.addEventListener('click', () => {
        hideAllStates();
        urlInput.value = '';
        urlInput.focus();
    });

    // Force autoplay for all videos (fixes "static preview" issue)
    // Force autoplay for all videos (fixes "static preview" issue)
    const videos = resultArea.querySelectorAll('video');
    videos.forEach(video => {
        // Ensure critical attributes for mobile
        video.muted = true;
        video.setAttribute('playsinline', '');
        video.setAttribute('webkit-playsinline', '');

        // Aggressive autoplay attempt
        const tryPlay = () => {
            if (video.paused) {
                video.play().catch(e => console.log('Autoplay retry failed:', e));
            }
        };

        // Try immediately
        tryPlay();

        // Retry every 500ms for 3 seconds (brute force)
        const interval = setInterval(tryPlay, 500);
        setTimeout(() => clearInterval(interval), 3000);

        // Allow manual play/pause on click
        video.style.cursor = 'pointer';
        console.log('Video setup complete: autoplay + click listener');
        video.addEventListener('click', () => {
            if (video.paused) video.play();
            else video.pause();
        });
    });

    // Resolve UNKNOWN file sizes via server-side HEAD request
    resolveUnknownSizes();
}

// --- Resolve Unknown File Sizes ---
async function resolveUnknownSizes() {
    const sizeElements = resultArea.querySelectorAll('.result-details');

    for (const el of sizeElements) {
        if (!el.textContent.includes('UNKNOWN')) continue;

        // Find the parent result-item
        const resultItem = el.closest('.result-item');
        if (!resultItem) continue;

        // Get raw URL from data attribute (avoids double-encoding issue with YouTube proxy)
        let mediaUrl = resultItem.getAttribute('data-raw-url') || '';

        // If rawUrl is a proxy URL, parse the actual URL from it
        if (!mediaUrl || mediaUrl.startsWith('download.php')) {
            const downloadLink = resultItem.querySelector('.btn-orange');
            if (!downloadLink) continue;
            const href = downloadLink.getAttribute('href');
            if (!href) continue;
            try {
                const params = new URLSearchParams(href.split('?')[1]);
                mediaUrl = params.get('url') || '';
            } catch (e) {
                continue;
            }
        }

        // Skip if still no valid URL
        if (!mediaUrl || mediaUrl.startsWith('download.php')) continue;

        try {
            const response = await fetch(`filesize.php?url=${encodeURIComponent(mediaUrl)}`);
            const data = await response.json();

            if (data.formatted && data.formatted !== 'UNKNOWN') {
                el.innerHTML = el.innerHTML.replace('UNKNOWN', data.formatted);
            }
        } catch (err) {
            console.log('Size resolve failed:', err);
        }
    }
}

// --- Show Picker Result (Multiple Items) ---
function showPickerResult(items, originalUrl) {
    hideAllStates();

    // Create custom picker UI
    const pickerHtml = `
        <div class="result-card">
            <div class="result-header">
                <div class="result-icon"><i class="fas fa-images"></i></div>
                <h3 style="flex: 1;">${items.length} MEDIA FOUND</h3>
                <div style="font-weight: 900;">///</div>
            </div>
            <div class="card-body">
                <div class="picker-grid">
                    ${items.map((item, idx) => `
                        <div class="picker-item">
                            ${item.type === 'video'
            ? `<video autoplay muted loop playsinline webkit-playsinline src="${item.url}" poster="${item.thumb || ''}" style="width: 100%; height: 100%; object-fit: cover;"></video>`
            : (item.thumb ? `<img src="${item.thumb}" alt="Media ${idx + 1}">` : '')}
                            <div class="picker-content">
                                <p class="picker-title">
                                    ${item.type === 'video' ? '<i class="fas fa-video"></i>' : '<i class="fas fa-image"></i>'} 
                                    MEDIA ${idx + 1}
                                </p>
                                ${item.size || item.size_bytes ? `<p style="font-size: 0.7rem; font-weight: 700; color: #666; margin-bottom: 5px;">SIZE: ${item.size || formatBytes(item.size_bytes)}</p>` : ''}
                                <a href="${item.url}" target="_blank" rel="noopener" class="service-link">
                                    <i class="fas fa-download"></i> DOWNLOAD
                                </a>
                            </div>
                        </div>
                    `).join('')}
                </div>
                <div class="result-actions">
                    <button id="pickerNewBtn" class="download-btn" style="margin-top: 20px; background: white; color: black;">
                        DOWNLOAD ANOTHER
                    </button>
                </div>
            </div>
        </div>
    `;

    resultArea.innerHTML = pickerHtml;
    resultArea.classList.remove('hidden');
    resultArea.scrollIntoView({ behavior: 'smooth', block: 'center' });

    // Re-attach new download button
    document.getElementById('pickerNewBtn')?.addEventListener('click', () => {
        resetResultArea();
        hideAllStates();
        urlInput.value = '';
        urlInput.focus();
    });

    // Force autoplay for picker videos
    // Force autoplay for picker videos
    const videos = resultArea.querySelectorAll('video');
    videos.forEach(video => {
        video.muted = true;
        video.setAttribute('playsinline', '');
        video.setAttribute('webkit-playsinline', '');

        // Aggressive autoplay attempt
        const tryPlay = () => {
            if (video.paused) {
                video.play().catch(e => console.log('Picker autoplay retry failed:', e));
            }
        };

        tryPlay();
        const interval = setInterval(tryPlay, 500);
        setTimeout(() => clearInterval(interval), 3000);

        // Allow manual play/pause on click
        video.style.cursor = 'pointer';
        video.addEventListener('click', () => {
            if (video.paused) video.play();
            else video.pause();
        });
    });
}

// --- Show Services Result (Hybrid Approach) ---
function showServicesResult(data) {
    hideAllStates();

    const servicesHtml = `
        <div class="result-card">
            <div class="result-header">
                <div class="result-icon"><i class="fab fa-youtube"></i></div>
                <h3 style="flex: 1;">SELECT SERVER</h3>
                <div style="font-weight: 900;">///</div>
            </div>
            <div class="card-body" style="text-align: center;">
                <div class="result-thumbnail" style="margin: 0 auto 20px;">
                    <img src="${data.thumb}" alt="${data.title}" style="border: 3px solid black; box-shadow: 4px 4px 0px black; max-width: 100%;">
                </div>
                <div class="result-info">
                    <h4 class="result-title">${data.title}</h4>
                    <p class="result-author">AUTHOR: ${data.author || 'UNKNOWN'}</p>
                </div>
                
                <div class="picker-grid" style="margin-top: 20px;">
                    ${data.services.map(service => `
                        <a href="${service.url}" target="_blank" rel="noopener noreferrer" class="service-link">
                            <span class="service-name">${service.name}</span>
                            <span class="service-quality">${service.quality || 'Download'}</span>
                            <div class="service-tag">SERVER ONLINE</div>
                        </a>
                    `).join('')}
                </div>
                
                <div style="margin-top: 20px; font-size: 0.8rem; font-weight: 700; color: #666;">
                    /// CLICK A SERVER TO DOWNLOAD
                </div>

                <div class="result-actions">
                    <button id="servicesNewBtn" class="download-btn" style="margin-top: 20px; background: white; color: black;">
                        DOWNLOAD ANOTHER
                    </button>
                </div>
            </div>
        </div>
    `;

    resultArea.innerHTML = servicesHtml;
    resultArea.classList.remove('hidden');
    resultArea.scrollIntoView({ behavior: 'smooth', block: 'center' });

    document.getElementById('servicesNewBtn')?.addEventListener('click', () => {
        resetResultArea();
        hideAllStates();
        urlInput.value = '';
        urlInput.focus();
    });
}

// --- Reset Result Area to Original HTML ---
function resetResultArea() {
    resultArea.innerHTML = ``; // Keep empty as we inject dynamically
    // Re-bind DOM references
    bindResultElements();
}

// --- Bind Result DOM Elements ---
function bindResultElements() {
    // Only bind if elements exist (moved to dynamic injection)
}

// --- Show Error ---
function showError(title, message) {
    hideAllStates();

    if (errorTitle) {
        errorTitle.textContent = title.toUpperCase();
    }

    if (errorMessage) {
        // If no title element, prepend title to message
        if (!errorTitle) {
            errorMessage.textContent = `${title.toUpperCase()}: ${message.toUpperCase()}`;
        } else {
            errorMessage.textContent = message.toUpperCase();
        }
    }

    errorArea.classList.remove('hidden');
    errorArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// --- Hide All States ---
function hideAllStates() {
    loadingState.classList.add('hidden');
    resultArea.classList.add('hidden');
    errorArea.classList.add('hidden');
}

// --- Retry Button ---
retryBtn?.addEventListener('click', () => {
    hideAllStates();
    urlInput.focus();
});

// --- New Download Button ---
newDownloadBtn?.addEventListener('click', () => {
    hideAllStates();
    urlInput.value = '';
    urlInput.focus();
});

// --- Utilities ---
function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

function isValidUrl(string) {
    try {
        const url = new URL(string);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch (_) {
        return false;
    }
}

function getVideoTitle(url) {
    return 'MEDIA DOWNLOAD';
}

function shakeElement(el) {
    el.style.animation = 'none';
    el.offsetHeight; // trigger reflow
    el.style.animation = 'shake 0.4s ease';
    el.style.border = `3px solid var(--red)`;
    setTimeout(() => {
        el.style.border = `3px solid var(--black)`;
    }, 1500);
}

// Add shake keyframes dynamically
const shakeStyle = document.createElement('style');
shakeStyle.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        20% { transform: translateX(-8px); }
        40% { transform: translateX(8px); }
        60% { transform: translateX(-5px); }
        80% { transform: translateX(5px); }
    }
`;
document.head.appendChild(shakeStyle);

// --- FAQ Toggle ---
function toggleFaq(btn) {
    const card = btn.closest('.faq-card');
    const wasActive = card.classList.contains('active');

    // Close all
    document.querySelectorAll('.faq-card').forEach(c => c.classList.remove('active'));

    // Toggle clicked
    if (!wasActive) {
        card.classList.add('active');
    }
}

// --- Smooth Scroll for Nav Links ---
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

// --- Init on page load ---
window.addEventListener('DOMContentLoaded', () => {
    // Check URL params
    const params = new URLSearchParams(window.location.search);
    const urlParam = params.get('url');
    if (urlParam) {
        urlInput.value = urlParam;
        urlInput.dispatchEvent(new Event('input'));
    }
});

// --- Fetch File Size Async ---
async function fetchFileSize(url, elementId) {
    // Step 1: Try Server-Side (filesize.php / api/filesize)
    try {
        const apiUrl = `filesize.php?url=${encodeURIComponent(url)}`;
        const response = await fetch(apiUrl);
        if (response.ok) {
            const data = await response.json();
            if (data.formatted && data.formatted !== 'Unknown' && data.formatted !== '0 Bytes') {
                const el = document.getElementById(elementId);
                if (el) el.textContent = data.formatted;
                return; // Success!
            }
        }
    } catch (e) {
        console.error('Server-side size fetch failed:', e);
    }

    // Step 2: Fallback - Try Client-Side Direct HEAD Request (Bypass Proxy)
    try {
        console.log('Trying direct client-side HEAD request for size...');
        const clientRes = await fetch(url, { method: 'HEAD' });
        // Note: 'mode: cors' is default. If fails due to CORS, catch runs.

        if (clientRes.ok) {
            const size = clientRes.headers.get('content-length');
            if (size) {
                const formatted = formatBytes(parseInt(size, 10));
                const el = document.getElementById(elementId);
                if (el) el.textContent = formatted;
                return;
            }
        }
    } catch (e2) {
        console.warn('Client-side size fetch failed (CORS likely):', e2);
    }

    // Step 3: Give up gracefully
    const el = document.getElementById(elementId);
    if (el && el.textContent === 'Menghitung...') {
        el.textContent = 'Unknown Size';
    }
}
