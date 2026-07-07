import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import pinoox from '@pinooxhq/vite-plugin';

export default defineConfig({
    plugins: [
        pinoox(['src/main.jsx']),
        react(),
    ],
});