import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
import { createPinooxViteConfig, pinooxVueTemplateOptions } from './vite.pinoox.mjs';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return createPinooxViteConfig({
        env,
        stack: 'vue',
        plugins: [vue(pinooxVueTemplateOptions())],
        resolve: {
            alias: {
                '@': fileURLToPath(new URL('./src', import.meta.url)),
            },
        },
    });
});
