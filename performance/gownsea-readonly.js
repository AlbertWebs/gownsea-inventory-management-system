import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const dashboardLatency = new Trend('dashboard_read_ms');
const searchLatency = new Trend('global_search_read_ms');
const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');
const sessionCookie = __ENV.SESSION_COOKIE || '';
const cookieName = __ENV.SESSION_COOKIE_NAME || `${(__ENV.APP_NAME || 'GOWNSEA Operations').toLowerCase().replace(/[^a-z0-9]+/g, '-')}-session`;
const searchTerm = __ENV.SEARCH_TERM || 'gown';

export const options = {
    scenarios: {
        authenticated_reads: {
            executor: 'ramping-vus',
            startVUs: 1,
            stages: [
                { duration: __ENV.WARMUP || '1m', target: 5 },
                { duration: __ENV.PLATEAU || '5m', target: Number(__ENV.VUS || 25) },
                { duration: '1m', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        dashboard_read_ms: ['p(95)<2500'],
        global_search_read_ms: ['p(95)<400'],
    },
};

export function setup() {
    if (!baseUrl) {
        throw new Error('Set BASE_URL to the isolated GOWNSEA staging URL.');
    }
    if (!sessionCookie) {
        throw new Error('Set SESSION_COOKIE to a short-lived authenticated staging session.');
    }
    return { baseUrl };
}

export default function ({ baseUrl: url }) {
    const jar = http.cookieJar();
    jar.set(url, cookieName, sessionCookie, { path: '/', secure: url.startsWith('https://'), httpOnly: true });

    const dashboard = http.get(`${url}/`, { tags: { operation: 'dashboard' }, redirects: 0 });
    dashboardLatency.add(dashboard.timings.duration);
    check(dashboard, { 'dashboard renders for the authenticated user': (response) => response.status === 200 });

    const search = http.get(`${url}/search?q=${encodeURIComponent(searchTerm)}`, { tags: { operation: 'global_search' } });
    searchLatency.add(search.timings.duration);
    check(search, {
        'global search returns JSON': (response) => response.status === 200 && response.headers['Content-Type']?.includes('application/json'),
    });

    sleep(1);
}
