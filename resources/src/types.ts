export interface Config {
    endpoint: string;
    sample_rate: number | string;
    dirs: {
        admin: string;
        includes: string;
        plugin: string;
        theme: string;
    }
}

export interface NetworkRequest {
    url: string;
    type: string;
    body_size: number;
    transfer_size: number;
    blocking: boolean;
    status: number;
}

export interface TypeMetrics {
    count: number;
    blocking: number;
    body_size: number;
    transfer_size: number;
}

export interface ComponentMetrics extends TypeMetrics {
    blocking: number;
}

export interface NetworkRequests {
    count: number;
    total_body_size: number;
    total_transfer_size: number;
    by_type: {
        [key: string]: TypeMetrics;
    };
    by_component: {
        [key: string]: {
            [key: string]: ComponentMetrics;
        };
    };
}

export interface BrowserMetrics {
    cls?: number;
    connect_ms?: number;
    dns_ms?: number;
    fcp_ms?: number;
    lcp_ms?: number;
    ttfb_ms?: number;
    html_body_size?: number;
    html_transfer_size?: number;
    requests: NetworkRequests;
    timestamp_ms: number;
}

export interface UserInfo {
    browser: {
        name: string;
        version: string | null;
    },
    os: {
        name: string;
        version: string | null;
    },
    platform: string;
    session_duration_ms: number;
}

export interface Payload {
    server: string;
    browser: BrowserMetrics;
    user: UserInfo;
}

declare global {
    interface Window {
        DebugHawkConfig: Config;
        DebugHawk: string;
    }
}
