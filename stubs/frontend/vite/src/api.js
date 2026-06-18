import { getAppUrl } from './boot.js';

/**
 * Resolve a path against window.__PINOOX__.url.APP (from pinoox_bootstrap in Twig).
 */
export function appUrl(path = '') {
    const base = getAppUrl();
    const segment = String(path ?? '').replace(/^\/+/, '');

    if (!base) {
        return segment ? `/${segment}` : '/';
    }

    const normalized = base.replace(/\/+$/, '');

    return segment ? `${normalized}/${segment}`.replace(/([^:]\/)\/+/g, '$1') : normalized;
}

/**
 * fetch() helper for theme AJAX — no jQuery; uses __PINOOX__.url.APP as base.
 *
 * @param {string} path relative to APP (e.g. "api/v1/contact")
 * @param {RequestInit} [init]
 */
export async function fetchApp(path, init = {}) {
    const headers = new Headers(init.headers ?? {});

    if (!headers.has('Accept')) {
        headers.set('Accept', 'application/json');
    }

    if (!headers.has('X-Requested-With')) {
        headers.set('X-Requested-With', 'XMLHttpRequest');
    }

    if (init.body != null && !(init.body instanceof FormData) && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }

    const response = await fetch(appUrl(path), {
        credentials: 'same-origin',
        ...init,
        headers,
    });

    const contentType = response.headers.get('content-type') ?? '';
    const payload = contentType.includes('application/json')
        ? await response.json()
        : await response.text();

    if (!response.ok) {
        const error = new Error(typeof payload === 'object' && payload?.message
            ? String(payload.message)
            : `Request failed (${response.status})`);
        error.response = response;
        error.payload = payload;
        throw error;
    }

    return payload;
}
