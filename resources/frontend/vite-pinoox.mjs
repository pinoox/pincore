import fs from 'node:fs';
import path from 'node:path';

/**
 * Writes theme/dist/hot (or PINOOX_HOT_FILE) so PHP ViteHelper injects HMR script tags.
 * @see \Pinoox\Component\Template\Frontend\FrontendConfig::hotRelativePath()
 */
export function pinooxHot(options = {}) {
    const themeRoot = process.cwd();
    const hotRelative = options.file ?? process.env.PINOOX_HOT_FILE ?? 'dist/hot';
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
        configureServer(server) {
            writeHot(server);

            server.httpServer?.once('close', cleanup);
            process.once('SIGINT', cleanup);
            process.once('SIGTERM', cleanup);
        },
    };
}

export default pinooxHot;

/**
 * Vite dev-server block from theme .env (VITE_DEV_PORT, VITE_SERVER_URL, VITE_DEV_PROXY).
 *
 * @param {Record<string, string>} env  loadEnv() result
 * @param {{ serverUrl?: string, port?: number, proxy?: string[], host?: boolean, strictPort?: boolean }} [options]
 */
export function pinooxServer(env = {}, options = {}) {
    const serverUrl = env.VITE_SERVER_URL || options.serverUrl || 'http://127.0.0.1:8000';
    const port = Number(env.VITE_DEV_PORT || options.port || 5173);
    const origin = parseOrigin(serverUrl);
    const prefixes = resolveProxyPrefixes(env, options, serverUrl);
    const proxy = {};

    for (const prefix of prefixes) {
        proxy[prefix] = { target: origin, changeOrigin: true };
    }

    return {
        port,
        host: options.host ?? true,
        strictPort: options.strictPort ?? true,
        proxy,
    };
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
