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
            metrics['html_body_size'] = navigationTiming.decodedBodySize
        }

        if (navigationTiming.encodedBodySize) {
            metrics['html_transfer_size'] = navigationTiming.encodedBodySize
        }

        return metrics;
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
