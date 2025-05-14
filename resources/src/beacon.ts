import {Metric, onCLS, onFCP, onINP, onLCP, onTTFB} from 'web-vitals';
import {BrowserMetrics, Config, NetworkRequest, NetworkRequests, Payload, UserInfo} from "./types";
import Bowser from "bowser";

class DebugHawk {
    private beaconSent: boolean;
    private config: Config;
    private sessionStart: number;
    private timestamp_ms: number;
    private queue: Set<Metric>;

    constructor(config: Config) {
        this.beaconSent = false;
        this.config = config;
        this.sessionStart = performance.now();
        this.timestamp_ms = Math.floor(Date.now());
        this.queue = new Set();

        if (!this.shouldSendBeacon()) {
            return;
        }

        this.initBrowserMetrics();

        // Report all available metrics whenever the page is backgrounded or unloaded
        addEventListener('visibilitychange', () => this.sendBeacon());
    }

    private shouldSendBeacon(): boolean {
        const sampleRate: number = Number(this.config.sample_rate);

        if (sampleRate < 0 || sampleRate > 1) {
            console.warn('DebugHawk: Invalid sampling rate, defaulting to sending metrics');
            return true;
        }

        if (sampleRate === 1) return true;
        if (sampleRate === 0) return false;

        return Math.random() < sampleRate;
    }

    private sendBeacon(): void {
        if (this.beaconSent || typeof window.DebugHawk === 'undefined' || document.visibilityState === 'visible') {
            return;
        }

        const payload: Payload = this.preparePayload();
        const payloadJson = JSON.stringify(payload);

        if (this.shouldUseBeaconApi()) {
            navigator.sendBeacon(this.config.endpoint, payloadJson);

            this.beaconSent = true;
        } else {
            fetch(this.config.endpoint, {
                method: 'POST',
                body: payloadJson,
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json'
                }
            })
                .then(() => {
                    this.beaconSent = true;
                })
                .catch(error => {
                    console.warn('DebugHawk: Fetch error', error);
                });
        }
    }

    private shouldUseBeaconApi(): boolean {
        if (this.isBraveBrowser()) {
            return false;
        }

        return 'sendBeacon' in navigator;
    }

    private isBraveBrowser(): boolean {
        return 'brave' in navigator;
    }

    private preparePayload(): Payload {
        return {
            server: window.DebugHawk,
            browser: this.getBrowserMetrics(),
            user: this.getUserInfo(),
        };
    }

    private getBrowserMetrics(): BrowserMetrics {
        let metrics: Partial<BrowserMetrics> = {
            requests: this.processNetworkRequests(),
            timestamp_ms: this.timestamp_ms,
        };

        this.queue.forEach((metric: Metric) => {
            const name = metric.name.toLowerCase();

            if (name !== 'cls') {
                (metrics as any)[name + '_ms'] = this.roundToDecimals(metric.value);
            } else {
                metrics[name] = this.roundToDecimals(metric.value, 5);
            }

            if (name === 'ttfb' && metric.entries[0]) {
                const ttfbMetrics = this.calculateTtfbMetrics(metric.entries[0] as PerformanceNavigationTiming);
                Object.assign(metrics, ttfbMetrics);
            }
        });

        return metrics as BrowserMetrics;
    }

    private calculateTtfbMetrics(navigationTiming: PerformanceNavigationTiming): Partial<BrowserMetrics> {
        let metrics: Partial<BrowserMetrics> = {};

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
    }

    private processNetworkRequests(): NetworkRequests {
        const requests = new Set<NetworkRequest>();

        performance.getEntriesByType('navigation').forEach(entry =>
            requests.add(this.processPerformanceEntry(entry as PerformanceNavigationTiming))
        );
        performance.getEntriesByType('resource').forEach(entry =>
            requests.add(this.processPerformanceEntry(entry as PerformanceResourceTiming))
        );

        let metrics: NetworkRequests = {
            count: requests.size,
            total_body_size: 0,
            total_transfer_size: 0,
            by_type: {},
            by_component: {},
        };

        requests.forEach(request => {
            metrics.total_body_size += request.body_size || 0;
            metrics.total_transfer_size += request.transfer_size || 0;

            if (!metrics.by_type[request.type]) {
                metrics.by_type[request.type] = {
                    count: 0,
                    blocking: 0,
                    body_size: 0,
                    transfer_size: 0
                };
            }

            metrics.by_type[request.type].count++;
            metrics.by_type[request.type].body_size += request.body_size || 0;
            metrics.by_type[request.type].transfer_size += request.transfer_size || 0;

            if (request.blocking) {
                metrics.by_type[request.type].blocking++;
            }

            if (request.type === 'css' || request.type === 'js') {
                const component = this.getComponentFromNetworkRequest(request);

                if (!metrics.by_component[component]) {
                    metrics.by_component[component] = {};
                }

                if (!metrics.by_component[component][request.type]) {
                    metrics.by_component[component][request.type] = {
                        count: 0,
                        blocking: 0,
                        body_size: 0,
                        transfer_size: 0
                    };
                }

                metrics.by_component[component][request.type].count++;
                metrics.by_component[component][request.type].body_size += request.body_size || 0;
                metrics.by_component[component][request.type].transfer_size += request.transfer_size || 0;

                if (request.blocking) {
                    metrics.by_component[component][request.type].blocking++;
                }
            }
        });

        return metrics;
    }

    private processPerformanceEntry(entry: PerformanceNavigationTiming | PerformanceResourceTiming): NetworkRequest {
        return {
            url: entry.name,
            type: this.getTypeFromPerformanceEntry(entry),
            body_size: entry.decodedBodySize,
            transfer_size: entry.encodedBodySize,
            blocking: (entry as any).renderBlockingStatus === "blocking",
            status: entry.responseStatus,
        };
    }

    private getComponentFromNetworkRequest(networkRequest: NetworkRequest): string {
        if (networkRequest.url.startsWith(this.config.dirs.plugin)) {
            const pluginName = networkRequest.url.slice(this.config.dirs.plugin.length).split('/')[0];

            return `plugin:${pluginName}`;
        }

        if (networkRequest.url.startsWith(this.config.dirs.theme)) {
            return 'theme';
        }

        if (networkRequest.url.startsWith(this.config.dirs.admin) || networkRequest.url.startsWith(this.config.dirs.includes)) {
            return 'core';
        }

        return 'other';
    }

    private getTypeFromPerformanceEntry(entry: PerformanceNavigationTiming | PerformanceResourceTiming): string {
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

        const types: { [key: string]: string } = {
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
    }

    private getUserInfo(): UserInfo {
        const browser = Bowser.getParser(window.navigator.userAgent);
        const sessionEnd = performance.now();

        return {
            browser: {
                name: this.isBraveBrowser() ? 'Brave' : browser.getBrowserName(),
                version: this.isBraveBrowser() ? null : browser.getBrowserVersion(),
            },
            os: {
                name: browser.getOSName(),
                version: browser.getOSVersion(),
            },
            platform: browser.getPlatformType(),
            session_duration_ms: this.roundToDecimals(sessionEnd - this.sessionStart),
        };
    }

    private addToQueue(metric: Metric): void {
        this.queue.add(metric);
    }

    private initBrowserMetrics(): void {
        const addToQueue = this.addToQueue.bind(this);

        onCLS(addToQueue);
        onFCP(addToQueue);
        onINP(addToQueue);
        onLCP(addToQueue);
        onTTFB(addToQueue);
    }

    private roundToDecimals(number: number, precision: number = 1): number {
        const multiplier = Math.pow(10, precision);
        return Math.round(number * multiplier) / multiplier;
    }
}

if (typeof window.DebugHawkConfig !== 'undefined') {
    const debugHawk = new DebugHawk(window.DebugHawkConfig);
}