import {onCLS, onFCP, onINP, onLCP, onTTFB} from 'web-vitals';
import Bowser from "bowser";

const DebugHawk = {
    beaconSent: false,
    sessionStart: performance.now(),
    queue: new Set(),

    init(config) {
        if (!this.shouldSendBeacon(config)) {
            return;
        }

        this.initBrowserMetrics();

        // Report all available metrics whenever the page is backgrounded or unloaded
        addEventListener('visibilitychange', () => this.sendBeacon(config));
    },

    shouldSendBeacon(config) {
        const sampleRate = Number(config.sample_rate);

        if (typeof sampleRate !== 'number' || sampleRate < 0 || sampleRate > 1) {
            console.warn('DebugHawk: Invalid sampling rate, defaulting to sending metrics');
            return true;
        }

        if (sampleRate === 1) return true;
        if (sampleRate === 0) return false;

        return Math.random() < sampleRate;
    },

    sendBeacon(config) {
        if (this.beaconSent || typeof window.DebugHawkMetrics === 'undefined' || document.visibilityState === 'visible') {
            return;
        }

        const payload = this.preparePayload(window.DebugHawkMetrics);

        navigator.sendBeacon(config.endpoint, JSON.stringify(payload));

        this.beaconSent = true;
    },

    preparePayload(payload) {
        payload.browser = this.getBrowserMetrics();
        payload.user = this.getUserInfo();

        return payload;
    },

    getBrowserMetrics() {
        let metrics = {};

        this.queue.forEach((metric) => {
            const name = metric.name.toLowerCase();

            if (name !== 'cls') {
                metrics[name + '_ms'] = this.roundToDecimals(metric.value);
            } else {
                metrics[name] = this.roundToDecimals(metric.value, 5);
            }

            if (name === 'ttfb' && metric.entries[0]) {
                metrics = {...metrics, ...this.calculateTtfbMetrics(metric.entries[0])};
            }
        });

        metrics['requests'] = this.processNetworkRequests();

        return metrics;
    },

    calculateTtfbMetrics(navigationTiming) {
        let metrics = {};

        if (navigationTiming.domainLookupStart && navigationTiming.connectStart) {
            metrics['dns_ms'] = this.roundToDecimals(navigationTiming.connectStart - navigationTiming.domainLookupStart);
        }

        if (navigationTiming.connectStart && navigationTiming.connectEnd) {
            metrics['connect_ms'] = this.roundToDecimals(navigationTiming.connectEnd - navigationTiming.connectStart);
        }

        if (navigationTiming.decodedBodySize) {
            metrics['html_body_size'] = navigationTiming.decodedBodySize;
        }

        if (navigationTiming.encodedBodySize) {
            metrics['html_transfer_size'] = navigationTiming.encodedBodySize;
        }

        return metrics;
    },

    processNetworkRequests() {
        const requests = new Set();

        performance.getEntriesByType('navigation').forEach(entry => requests.add(this.processPerformanceEntry(entry)));
        performance.getEntriesByType('resource').forEach(entry => requests.add(this.processPerformanceEntry(entry)));

        let metrics = {
            count: requests.size,
            total_body_size: 0,
            total_transfer_size: 0,
            by_type: {},
        };

        requests.forEach(request => {
            metrics.total_body_size += request.body_size || 0;
            metrics.total_transfer_size += request.transfer_size || 0;

            if (!metrics.by_type[request.type]) {
                metrics.by_type[request.type] = {
                    count: 0,
                    body_size: 0,
                    transfer_size: 0
                };
            }

            metrics.by_type[request.type].count++;
            metrics.by_type[request.type].body_size += request.body_size || 0;
            metrics.by_type[request.type].transfer_size += request.transfer_size || 0;
        });

        return metrics;
    },

    processPerformanceEntry(entry) {
        return {
            url: entry.name,
            type: this.getTypeFromPerformanceEntry(entry),
            body_size: entry.decodedBodySize,
            transfer_size: entry.encodedBodySize,
            blocking: entry.renderBlockingStatus === "blocking",
            status: entry.responseStatus,
        };
    },

    getTypeFromPerformanceEntry(entry) {
        if (entry.initiatorType === 'navigation' || entry.initiatorType === 'iframe') {
            return 'html';
        }

        if (entry.initiatorType === 'script') {
            return 'js';
        }

        if (entry.initiatorType === 'img') {
            return 'img';
        }

        if (entry.initiatorType === 'fetch' || entry.initiatorType === 'xmlhttprequest') {
            return 'xhr';
        }

        const url = entry.name.replace(/\?.*$/, '');
        const match = url.match(/\.([^.]+)$/);
        const ext = match ? match[1].toLowerCase() : '';

        const types = {
            // Styles
            'css': 'css',

            // Scripts
            'js': 'js',
            'mjs': 'js',

            // Fonts
            'woff': 'font',
            'woff2': 'font',
            'ttf': 'font',
            'otf': 'font',
            'eot': 'font',

            // Images
            'png': 'img',
            'jpg': 'img',
            'jpeg': 'img',
            'gif': 'img',
            'svg': 'img',
            'webp': 'img',
            'ico': 'img',
            'avif': 'img',

            // Media
            'mp4': 'media',
            'webm': 'media',
            'ogv': 'media',
            'mp3': 'media',
            'ogg': 'media',
            'wav': 'media',
            'flac': 'media',
        };

        return types[ext] || 'other';
    },

    getUserInfo() {
        const browser = Bowser.getParser(window.navigator.userAgent);
        const sessionEnd = performance.now();

        return {
            browser: browser.getBrowser(),
            os: {
                name: browser.getOSName(),
                version: browser.getOSVersion(),
            },
            platform: browser.getPlatformType(),
            session_duration_ms: this.roundToDecimals(sessionEnd - this.sessionStart),
        };
    },

    addToQueue(metric) {
        this.queue.add(metric);
    },

    initBrowserMetrics() {
        const addToQueue = this.addToQueue.bind(this);

        onCLS(addToQueue);
        onFCP(addToQueue);
        onINP(addToQueue);
        onLCP(addToQueue);
        onTTFB(addToQueue);
    },

    roundToDecimals(number, precision = 1) {
        const multiplier = Math.pow(10, precision);
        return Math.round(number * multiplier) / multiplier;
    }
};

if (typeof window.DebugHawkConfig !== 'undefined') {
    DebugHawk.init(window.DebugHawkConfig);
}
