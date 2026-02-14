module.exports = async (req, res) => {
    // Enable CORS
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    const { url } = req.query;

    if (!url) {
        return res.status(400).json({ error: 'URL parameter is required' });
    }

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 8000); // 8s timeout

        const response = await fetch(url, {
            method: 'HEAD',
            headers: {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                // Remove referrer as some sites block generic referer
            },
            signal: controller.signal
        });
        clearTimeout(timeoutId);

        let size = response.headers.get('content-length');
        const type = response.headers.get('content-type');

        // Fallback: If no size from HEAD, try GET with Range: bytes=0-0
        if (!size && response.ok) {
            try {
                const controller2 = new AbortController();
                const timeoutId2 = setTimeout(() => controller2.abort(), 5000); // 5s timeout

                const rangeResponse = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Range': 'bytes=0-0' // Request first byte only
                    },
                    signal: controller2.signal
                });
                clearTimeout(timeoutId2);

                if (rangeResponse.ok) {
                    // Check Content-Range: bytes 0-0/12345
                    const contentRange = rangeResponse.headers.get('content-range');
                    if (contentRange) {
                        const match = contentRange.match(/\/(\d+)$/);
                        if (match) size = match[1];
                    }
                    // Or sometimes Content-Length of full file is sent even with Range
                    if (!size) size = rangeResponse.headers.get('content-length');
                }
            } catch (e) {
                console.error('Range fallback failed:', e.message);
            }
        }

        return res.status(200).json({
            size: size ? parseInt(size, 10) : null,
            type: type,
            formatted: size ? formatBytes(size) : 'Unknown'
        });

    } catch (error) {
        return res.status(500).json({ error: error.message || 'Server error' });
    }
};

function formatBytes(bytes, decimals = 2) {
    if (!bytes) return 'Unknown';
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}
