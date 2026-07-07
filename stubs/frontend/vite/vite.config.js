import { defineConfig, loadEnv } from 'vite';
import { createPinooxViteConfig } from './vite.pinoox.mjs';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return createPinooxViteConfig({
        env,
        stack: 'vite',
    });
});
