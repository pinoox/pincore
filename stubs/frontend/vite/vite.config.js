import { defineConfig } from 'vite';
import pinoox from '@pinooxhq/vite-plugin';

export default defineConfig({
    plugins: [
        pinoox(['src/main.js']),
    ],
});