import {onCLS, onFCP, onINP, onLCP, onTTFB} from 'web-vitals';
import Bowser from "bowser";

const DebugHawk = {
    beaconSent: false,
    timestamp: Math.floor(Date.now() / 1000),
    queue: new Set(),

    init(config) {
        if (!this.shouldSendBeacon(config)) {
            return;
        }

        this.initBrowserMetrics();

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
        this.queue.forEach((value) => {
            payload[value.name.toLowerCase()] = value.value;
        });

        payload.device = this.getDeviceInfo();

        if (payload.page_cache?.timestamp) {
            payload.page_cache.age = this.timestamp - payload.page_cache.timestamp;
        }

        return payload;
    },

    getDeviceInfo() {
        const browser = Bowser.getParser(window.navigator.userAgent);

        return {
            browser: browser.getBrowser(),
            os: browser.getOS(),
            type: browser.getPlatformType(),
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
};

if (typeof window.DebugHawkConfig !== 'undefined') {
    DebugHawk.init(window.DebugHawkConfig);
}
