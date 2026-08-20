import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

/**
 * Vue component tests.
 *
 * Kept separate from vite.config.ts so the Laravel, Inertia and Wayfinder plugins
 * (which expect a running backend and generate route files) stay out of the test run.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'happy-dom',
        include: ['resources/js/**/*.{test,spec}.ts'],
        globals: true,
    },
});
