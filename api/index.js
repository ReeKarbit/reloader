// Vercel Serverless Function (Node.js 18+)
// Uses native fetch (no dependencies required)

module.exports = async (req, res) => {
    // Enable CORS
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    // Handle OPTIONS (Preflight)
    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    // Handle GET (Health Check)
    if (req.method === 'GET') {
        return res.status(200).json({
            status: 'ok',
            message: 'MediaDownloader API V3 is Online',
            server_time: new Date().toISOString()
        });
    }

    // Handle invalid methods
    if (req.method !== 'POST') {
        return res.status(405).json({ status: 'error', text: 'Method not allowed' });
    }

    try {
        // Parse Body - Handle both JSON object and stringified JSON
        let input = req.body;
        if (typeof input === 'string') {
            try {
                input = JSON.parse(input);
            } catch (e) {
                // If parsing fails, input remains string (likely invalid)
            }
        }

        if (!input || typeof input !== 'object' || !input.url) {
            return res.status(200).json({ status: 'error', text: 'URL diperlukan' });
        }

        const url = input.url.trim();
        const format = input.downloadMode || 'auto';
        const quality = input.videoQuality || '720';
        const debugLog = [];

        const platform = detectPlatform(url);

        // TikTok providers chain
        const providers = [providerTikwm, providerSnaptik];
        let finalResult = null;
        let lastError = '';

        for (const provider of providers) {
            try {
                if (finalResult) break; // Stop if we have a result

                debugLog.push(`Trying provider: ${provider.name}`);
                const result = await provider(url, format, quality, platform, debugLog);

                if (result && result.status && result.status !== 'error') {
                    finalResult = result;
                    debugLog.push(`Success with ${provider.name}`);
                    break;
                }

                if (result && result.text) {
                    lastError = result.text;
                    debugLog.push(`Failed ${provider.name}: ${result.text}`);
                }
            } catch (e) {
                lastError = e.message;
                debugLog.push(`Exception ${provider.name}: ${e.message}`);
            }
        }

        if (finalResult) {
            if (req.query && req.query.debug) finalResult.debug = debugLog;
            return res.status(200).json(finalResult);
        }

        const errResponse = {
            status: 'error',
            text: `Gagal memproses link. ${lastError || 'Server sibuk atau timeout'}.`,
        };
        if (req.query && req.query.debug) errResponse.debug = debugLog;
        return res.status(200).json(errResponse);

    } catch (globalError) {
        // Critical Error Handler - Ensure JSON is always returned
        return res.status(200).json({
            status: 'error',
            text: 'Server Error: ' + (globalError.message || 'Unknown error'),
            stack: process.env.NODE_ENV === 'development' ? globalError.stack : undefined
        });
    }
};

// ===== PROVIDERS =====

async function providerTikwm(url, format, quality, platform, log) {
    if (platform !== 'tiktok') return null;

    const apiUrl = 'https://www.tikwm.com/api/';
    const postData = new URLSearchParams({ url: url, hd: '1' });

    log.push('TikWM trying POST');
    let resp = await fetchUrl(apiUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json',
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        },
        body: postData
    });

    let data = null;
    if (resp) { try { data = JSON.parse(resp); } catch (e) { } }

    // If POST failed, try GET
    if (!data || (data.code !== undefined && data.code !== 0)) {
        log.push('TikWM POST failed/empty, trying GET');
        resp = await fetchUrl(`${apiUrl}?url=${encodeURIComponent(url)}&hd=1`);
        if (resp) { try { data = JSON.parse(resp); } catch (e) { } }
    }

    if (!data) return { status: 'error', text: 'TikWM no response' };
    if (data.code === undefined || data.code !== 0) {
        return { status: 'error', text: 'TikWM: ' + (data.msg || 'Unknown error') };
    }

    const video = data.data;
    const variants = [];

    const hdUrl = video.hdplay || video.play || null;
    const sdUrl = video.play || video.hdplay || null;
    const musicUrl = video.music || null;
    const wmUrl = video.wmplay || null;

    if (hdUrl) {
        variants.push({
            type: 'video-hd',
            name: 'HD NO WATERMARK (MP4)',
            url: hdUrl,
            size_bytes: video.hd_size || video.size || null
        });
    }

    if (sdUrl) {
        variants.push({
            type: 'video-sd',
            name: 'NO WATERMARK (MP4)',
            url: sdUrl,
            size_bytes: video.size || video.hd_size || null
        });
    }

    const audioUrl = musicUrl || hdUrl;
    if (audioUrl) {
        variants.push({
            type: 'audio',
            name: 'MP3 AUDIO',
            url: audioUrl,
            size_bytes: video.music_info?.size || null
        });
    }

    if (wmUrl) {
        variants.push({
            type: 'video-watermark',
            name: 'WITH WATERMARK (MP4)',
            url: wmUrl,
            size_bytes: video.wm_size || null
        });
    }

    const mainUrl = hdUrl || sdUrl || wmUrl || musicUrl || null;
    if (mainUrl) {
        return {
            status: 'tunnel',
            url: mainUrl,
            filename: 'tiktok_' + (video.id || 'video') + '.mp4',
            thumb: video.cover || video.origin_cover || null,
            title: video.title || 'TikTok Video',
            author: (video.author && video.author.nickname) || 'TikTok User',
            variants: variants
        };
    }

    return { status: 'error', text: 'Video URL not found in TikWM' };
}

async function providerSnaptik(url, format, quality, platform, log) {
    if (platform !== 'tiktok') return null;

    const apiUrl = 'https://api.tik.fail/api/grab';
    log.push('Snaptik(tik.fail) trying');

    const params = new URLSearchParams({ url: url });
    const resp = await fetchUrl(apiUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: params
    });

    if (!resp) return null;
    let data;
    try { data = JSON.parse(resp); } catch (e) { return null; }

    if (!data || data.status !== 'success') {
        log.push('Snaptik failed: ' + (data?.status || 'unknown'));
        return null;
    }

    return {
        status: 'tunnel',
        url: data.video || data.nwm_video_url || '',
        filename: 'tiktok_snaptik.mp4',
        title: data.desc || 'TikTok Video'
    };
}

async function providerDouyin(url, format, quality, platform, log) {
    // Disabled to prevent timeouts and blocking
    return null;
}

// ===== UTILITIES =====

function detectPlatform(url) {
    if (/tiktok\.com/i.test(url)) return 'tiktok';
    if (/youtu\.?be/i.test(url)) return 'youtube';
    if (/instagram\.com/i.test(url)) return 'instagram';
    if (/twitter\.com|x\.com/i.test(url)) return 'twitter';
    if (/facebook\.com|fb\.watch/i.test(url)) return 'facebook';
    return 'unknown';
}

async function fetchUrl(url, options = {}) {
    try {
        // Reduce timeout to 7s (safe margin for Vercel 10s limit)
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 7000);

        const defaultHeaders = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        };

        const finalOptions = {
            ...options,
            headers: { ...defaultHeaders, ...(options.headers || {}) },
            redirect: 'follow',
            signal: controller.signal
        };

        const response = await fetch(url, finalOptions);
        clearTimeout(timeoutId);

        return await response.text();
    } catch (e) {
        return null; // Graceful failure on timeout/network error
    }
}
