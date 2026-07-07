import { defineConfig, loadEnv } from 'vite';
import { createPinooxViteConfig } from '@pinoox/vite-plugin';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return createPinooxViteConfig({
        env,
        stack: 'vite',
    });
});
