export function getBoot() {
    return globalThis.__PINOOX__ ?? {};
}

export function getUrl() {
    return getBoot().url ?? {};
}

export function hasBoot() {
    const url = getUrl();

    return typeof url.APP === 'string' && url.APP !== '';
}

/** App base URL from pinoox_bootstrap() — use for same-app form/AJAX targets. */
export function getAppUrl() {
    return getUrl().APP ?? '';
}
