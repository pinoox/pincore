import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import pinoox, { pinooxVueTemplateOptions } from '@pinoox/vite-plugin';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        pinoox(['src/main.js']),
        vue(pinooxVueTemplateOptions()),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },
});