import fs from 'node:fs';
import path from 'node:path';
import { createLogger } from 'vite';

const DEFAULT_ENTRIES = {
    vue: ['src/main.js'],
    react: ['src/main.jsx'],
    vite: ['src/main.js'],
};

const DEFAULT_REFRESH = [
    '**/*.twig',
    'partials/**/*.twig',
    'layouts/**/*.twig',
    'views/**/*.twig',
];

/**
 * @param {Record<string, string>} env
 * @param {{ paths?: string[]|boolean }} [options]
 * @returns {string[]}
 */
export function resolveRefreshPaths(env = {}, options = {}) {
    const paths = options.paths ?? true;
    let base;

    if (paths === false) {
        base = [];
    } else if (Array.isArray(paths)) {
        base = paths;
    } else {
        base = DEFAULT_REFRESH;
    }

    const extra = String(env.VITE_DEV_REFRESH || process.env.VITE_DEV_REFRESH || '')
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean);

    return [...base, ...extra];
}

/**
 * Hot-file path shared by Node (pinooxHot) and PHP (FrontendConfig::hotRelativePath).
 * Override with VITE_HOT_FILE in theme .env or dev.hot in frontend.config.php.
 */
export function resolveHotFile(env = {}, options = {}) {
    if (options.file) {
        return options.file;
    }

    return env.VITE_HOT_FILE || process.env.VITE_HOT_FILE || 'dist/hot';
}

function mergedEnv(env = {}) {
    return { ...process.env, ...env };
}

function resolveViteQuiet(env = {}, options = {}) {
    const raw = env.VITE_DEV_QUIET ?? process.env.VITE_DEV_QUIET ?? options.quiet;

    if (raw === undefined || raw === null || raw === '') {
        return true;
    }

    if (raw === 'false' || raw === '0' || raw === 'no') {
        return false;
    }

    return true;
}

function resolveViteHost(env = {}, options = {}) {
    const raw = env.VITE_DEV_HOST ?? process.env.VITE_DEV_HOST ?? options.host;

    if (raw === undefined || raw === null || raw === '') {
        return '127.0.0.1';
    }

    if (raw === 'true' || raw === '0.0.0.0' || raw === 'all' || raw === 'network') {
        return true;
    }

    if (raw === 'false' || raw === 'localhost') {
        return '127.0.0.1';
    }

    return raw;
}

function createPinooxViteLogger(env = {}, options = {}) {
    const quiet = resolveViteQuiet(env, options);
    const logger = createLogger('warn', { allowClearScreen: false });

    if (!quiet) {
        return logger;
    }

    const shouldSkip = (msg) => {
        const text = String(msg);

        return /^\s*➜\s+(Local|Network):/m.test(text)
            || /^\s*VITE v[\d.]+\s+ready in/m.test(text);
    };

    const info = logger.info.bind(logger);
    logger.info = (msg, opts) => {
        if (shouldSkip(msg)) {
            return;
        }

        info(msg, opts);
    };

    return logger;
}

function printPinooxDevBanner(env = {}, port = 5173) {
    const appUrl = env.VITE_SERVER_URL || process.env.VITE_SERVER_URL;

    if (!appUrl) {
        return;
    }

    const host = resolveViteHost(env);
    const hostname = host === true || host === '0.0.0.0' ? '127.0.0.1' : (host || '127.0.0.1');

    console.log('');
    console.log('  \x1b[32m\x1b[1m➜\x1b[0m  \x1b[36m\x1b[1mOpen app\x1b[0m  ' + appUrl);
    console.log('  \x1b[90mVite HMR\x1b[0m       http://' + hostname + ':' + port + ' \x1b[90m(background)\x1b[0m');
    console.log('  \x1b[90mPress Ctrl+C to stop\x1b[0m');
    console.log('');
}

/**
 * Writes theme/dist/hot (or VITE_HOT_FILE) so PHP ViteHelper injects HMR script tags.
 * @see \Pinoox\Component\Template\Frontend\FrontendConfig::hotRelativePath()
 */
export function pinooxHot(options = {}) {
    const pluginEnv = mergedEnv(options.env ?? {});

    const themeRoot = process.cwd();
    const hotRelative = resolveHotFile(options.env ?? {}, options);
    const hotFilePath = path.isAbsolute(hotRelative)
        ? hotRelative
        : path.join(themeRoot, hotRelative);

    const writeHot = (server) => {
        const host = server.config.server.host;
        const hostname = host === true || host === '0.0.0.0' ? '127.0.0.1' : (host || '127.0.0.1');
        const port = server.config.server.port ?? 5173;
        const devUrl = `http://${hostname}:${port}`;

        fs.mkdirSync(path.dirname(hotFilePath), { recursive: true });
        fs.writeFileSync(hotFilePath, devUrl);
    };

    const cleanup = () => {
        if (fs.existsSync(hotFilePath)) {
            fs.unlinkSync(hotFilePath);
        }
    };

    return {
        name: 'pinoox-hot-file',
        config() {
            return {
                customLogger: createPinooxViteLogger(pluginEnv, options),
            };
        },
        configureServer(server) {
            const updateHot = () => writeHot(server);

            server.httpServer?.once('listening', () => {
                updateHot();
                printPinooxDevBanner(pluginEnv, server.config.server.port ?? 5173);
            });

            if (server.httpServer?.listening) {
                updateHot();
            }

            const shutdown = () => {
                cleanup();

                const httpServer = server.httpServer;

                if (httpServer?.listening) {
                    httpServer.close();
                }
            };

            server.httpServer?.once('close', cleanup);
            process.once('SIGINT', shutdown);
            process.once('SIGTERM', shutdown);
            process.once('exit', cleanup);
        },
    };
}

export default pinooxHot;

/**
 * Vue SFC options so @/ assets resolve to the Vite dev server (not the PHP origin).
 */
export function pinooxVueTemplateOptions(extra = {}) {
    const { template: extraTemplate, ...rest } = extra;

    return {
        ...rest,
        template: {
            transformAssetUrls: {
                base: null,
                includeAbsolute: false,
            },
            ...(extraTemplate ?? {}),
        },
    };
}

/**
 * Rewrites root-absolute /src/... asset URLs to the Vite dev server during `vite dev`.
 * Needed when the page is served from PHP (e.g. /manager) but scripts load from Vite.
 */
export function pinooxDevAssets(env = {}) {
    let devServerUrl = resolveViteDevOrigin(env);

    return {
        name: 'pinoox-dev-assets',
        apply: 'serve',
        configureServer(server) {
            const updateDevServerUrl = () => {
                devServerUrl = resolveDevServerUrlFromInstance(server);
            };

            server.httpServer?.once('listening', updateDevServerUrl);

            if (server.httpServer?.listening) {
                updateDevServerUrl();
            }
        },
        transform(code) {
            if (!devServerUrl || !code.includes('/src/')) {
                return null;
            }

            const rewritten = code.replace(
                /(["'`])\/src\//g,
                (match, quote, offset) => {
                    const before = code.slice(Math.max(0, offset - devServerUrl.length), offset);

                    if (before.endsWith(devServerUrl)) {
                        return match;
                    }

                    return `${quote}${devServerUrl}/src/`;
                },
            );

            return rewritten === code ? null : rewritten;
        },
    };
}

/**
 * Full-page reload when Twig templates or app PHP (Flow, routes, controllers) change.
 *
 * @param {string[]|boolean} paths  Glob paths relative to theme root, or true for defaults
 * @param {Record<string, string>} [env]  loadEnv() result; merges VITE_DEV_REFRESH from process env
 */
export function pinooxRefresh(paths = true, env = {}) {
    const mergedEnv = { ...process.env, ...env };
    const watchGlobs = resolveRefreshPaths(mergedEnv, { paths: paths === false ? false : (Array.isArray(paths) ? paths : true) });

    return {
        name: 'pinoox-refresh',
        configureServer(server) {
            if (watchGlobs.length === 0) {
                return;
            }

            for (const pattern of watchGlobs) {
                server.watcher.add(pattern);
            }

            const shouldReload = (file) => {
                const normalized = file.replace(/\\/g, '/');

                return watchGlobs.some((pattern) => matchGlob(normalized, pattern));
            };

            server.watcher.on('change', (file) => {
                if (shouldReload(file)) {
                    server.ws.send({ type: 'full-reload', path: '*' });
                }
            });
        },
    };
}

/**
 * Zero-config Vite config factory.
 *
 * @param {{
 *   env?: Record<string, string>,
 *   stack?: 'vue'|'react'|'vite'|string,
 *   entries?: string[],
 *   refresh?: string[]|boolean,
 *   plugins?: import('vite').PluginOption[],
 *   resolve?: import('vite').UserConfig['resolve'],
 *   build?: import('vite').BuildOptions,
 *   server?: import('vite').ServerOptions,
 * }} [options]
 */
export function createPinooxViteConfig(options = {}) {
    const env = options.env ?? {};
    const stack = options.stack ?? 'vite';
    const entries = options.entries?.length
        ? options.entries
        : (DEFAULT_ENTRIES[stack] ?? DEFAULT_ENTRIES.vite);

    const refresh = options.refresh ?? true;
    const refreshPaths = resolveRefreshPaths(env, {
        paths: refresh === false ? false : (Array.isArray(refresh) ? refresh : true),
    });

    const plugins = [
        pinooxHot({ env }),
        pinooxDevAssets(env),
        ...(refreshPaths.length > 0 ? [pinooxRefresh(refresh, env)] : []),
        ...(options.plugins ?? []),
    ];

    const server = {
        ...pinooxServer(env),
        ...(options.server ?? {}),
    };

    return {
        base: './',
        build: {
            manifest: true,
            outDir: 'dist',
            rollupOptions: {
                input: entries,
            },
            ...(options.build ?? {}),
        },
        plugins,
        resolve: options.resolve,
        server,
    };
}

/**
 * Vite dev-server block from theme .env (VITE_DEV_PORT, VITE_SERVER_URL, VITE_DEV_PROXY).
 *
 * @param {Record<string, string>} env  loadEnv() result
 * @param {{ serverUrl?: string, port?: number, proxy?: string[], host?: boolean, strictPort?: boolean }} [options]
 */
export function pinooxServer(env = {}, options = {}) {
    const serverUrl = env.VITE_SERVER_URL || options.serverUrl || 'http://127.0.0.1:8000';
    const port = Number(env.VITE_DEV_PORT || options.port || 5173);
    const phpOrigin = parseOrigin(serverUrl);
    const viteOrigin = resolveViteDevOrigin(env, port, options);
    const strictPort = options.strictPort ?? false;
    const prefixes = resolveProxyPrefixes(env, options, serverUrl);
    const proxy = {};

    for (const prefix of prefixes) {
        proxy[prefix] = { target: phpOrigin, changeOrigin: true };
    }

    const server = {
        port,
        host: resolveViteHost(env, options),
        strictPort,
        proxy,
        printUrls: false,
    };

    if (strictPort) {
        server.origin = viteOrigin;
    }

    return server;
}

function resolveDevServerUrlFromInstance(server) {
    const host = server.config.server.host;
    const hostname = host === true || host === '0.0.0.0' ? '127.0.0.1' : (host || '127.0.0.1');
    const port = server.config.server.port ?? 5173;

    return `http://${hostname}:${port}`;
}

function resolveViteDevOrigin(env = {}, port = 5173, options = {}) {
    const fromEnv = env.VITE_DEV_SERVER || process.env.VITE_DEV_SERVER || options.viteOrigin;

    if (fromEnv) {
        return String(fromEnv).replace(/\/$/, '');
    }

    const host = options.host ?? true;
    const hostname = host === true || host === '0.0.0.0' ? '127.0.0.1' : (host || '127.0.0.1');

    return `http://${hostname}:${port}`;
}

function parseOrigin(serverUrl) {
    try {
        return new URL(serverUrl).origin;
    } catch {
        return 'http://127.0.0.1:8000';
    }
}

function resolveProxyPrefixes(env, options, serverUrl) {
    if (Array.isArray(options.proxy) && options.proxy.length > 0) {
        return options.proxy;
    }

    const fromEnv = String(env.VITE_DEV_PROXY || '')
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean);

    if (fromEnv.length > 0) {
        return fromEnv;
    }

    try {
        const mountPath = new URL(serverUrl).pathname.replace(/\/$/, '');

        if (mountPath && mountPath !== '/') {
            return [mountPath];
        }
    } catch {
        // ignore
    }

    return [];
}

function matchGlob(filePath, pattern) {
    const regex = globToRegExp(pattern);

    return regex.test(filePath) || regex.test(path.basename(filePath));
}

function globToRegExp(glob) {
    const escaped = glob
        .replace(/\\/g, '/')
        .replace(/[.+^${}()|[\]\\]/g, '\\$&')
        .replace(/\*\*/g, '§§')
        .replace(/\*/g, '[^/]*')
        .replace(/§§/g, '.*')
        .replace(/\?/g, '.');

    return new RegExp(`(^|/)${escaped}$`);
}
