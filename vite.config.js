import {defineConfig} from 'vite';
import {resolve} from 'path';

export default defineConfig({
    build: {
        outDir: 'resources/dist',
        lib: {
            entry: resolve(__dirname, 'resources/src/beacon.ts'),
            name: 'DebugHawk',
            fileName: 'beacon',
            formats: ['iife']
        },
        rollupOptions: {
            output: {
                entryFileNames: `[name].js`,
                chunkFileNames: `[name].js`,
                assetFileNames: `[name].[ext]`
            }
        }
    }
});