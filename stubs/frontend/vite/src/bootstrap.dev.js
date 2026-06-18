/**
 * Dev-only bootstrap when Vite runs without PHP/Twig.
 * Production uses pinoox_bootstrap() in partials/scripts.twig.
 */

function trimSlashes(value, { leading = true, trailing = false } = {}) {
    let result = String(value ?? '');

    if (leading) {
        result = result.replace(/^\/+/, '');
    }

    if (trailing) {
        result = result.replace(/\/+$/, '');
    }

    return result;
}

function joinPath(...segments) {
    return `/${segments.map((part) => trimSlashes(part)).filter(Boolean).join('/')}`.replace(/\/+/g, '/');
}

function joinOrigin(origin, path) {
    const base = String(origin ?? '').replace(/\/+$/, '');

    if (!base) {
        return path.startsWith('/') ? path : `/${path}`;
    }

    return `${base}${path.startsWith('/') ? path : `/${path}`}`.replace(/([^:]\/)\/+/g, '$1');
}

function ensureTrailingSlash(path) {
    return path.endsWith('/') ? path : `${path}/`;
}

export function resolveDevBootstrap(options = {}) {
    const server = String(import.meta.env.VITE_SERVER_URL ?? '').replace(/\/+$/, '');
    const projectPath = trimSlashes(import.meta.env.VITE_PROJECT_PATH ?? '', { trailing: true });
    const appSegment = trimSlashes(
        import.meta.env.VITE_APP_PATH ?? options.defaultAppPath ?? '/',
        { trailing: true },
    );
    const appPath = joinPath(projectPath, appSegment);
    const appPathSlash = ensureTrailingSlash(appPath);
    const apiPath = import.meta.env.VITE_API_PATH
        ? (import.meta.env.VITE_API_PATH.startsWith('/') ? import.meta.env.VITE_API_PATH : `/${import.meta.env.VITE_API_PATH}`)
        : joinPath(appPath, 'api/v1/');

    const site = server || (typeof window !== 'undefined' ? window.location.origin : '/');
    const app = server ? joinOrigin(server, appPath) : appPath;
    const api = server ? joinOrigin(server, apiPath) : apiPath;

    return {
        locale: import.meta.env.VITE_LOCALE ?? options.defaultLocale ?? 'en',
        url: {
            APP: app,
            BASE: appPathSlash,
            API: ensureTrailingSlash(api),
            SITE: site,
        },
        ...(options.page ?? {}),
    };
}

export function applyDevBootstrap(options = {}) {
    if (import.meta.env.PROD || globalThis.__PINOOX__ != null) {
        return;
    }

    globalThis.__PINOOX__ = resolveDevBootstrap(options);
}

applyDevBootstrap();
