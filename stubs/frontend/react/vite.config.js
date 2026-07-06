import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { createPinooxViteConfig } from './vite.pinoox.mjs';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return createPinooxViteConfig({
        env,
        stack: 'react',
        plugins: [react()],
    });
});
