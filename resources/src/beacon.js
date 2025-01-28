import {onCLS, onFCP, onINP, onLCP, onTTFB} from 'web-vitals';
import Bowser from "bowser";

const queue = new Set();
const now = Math.floor(Date.now() / 1000);
let beaconSent = false;

function addToQueue(metric) {
    queue.add(metric);
}

onCLS(addToQueue);
onFCP(addToQueue);
onINP(addToQueue);
onLCP(addToQueue);
onTTFB(addToQueue);

addEventListener('visibilitychange', () => {
    if (beaconSent || typeof window.debughawkMetrics === 'undefined' || document.visibilityState === 'visible') {
        return;
    }

    let payload = window.debughawkMetrics;

    queue.forEach(value => {
        payload[value.name.toLowerCase()] = value.value;
    });

    const browser = Bowser.getParser(window.navigator.userAgent);

    payload['device'] = {
        browser: browser.getBrowser(),
        os: browser.getOS(),
        type: browser.getPlatformType(),
    };

    payload.page_cache.age = now - payload.page_cache.generation_time;

    navigator.sendBeacon(window.debughawkConfig.endpoint, JSON.stringify(window.debughawkMetrics));

    beaconSent = true;
});
