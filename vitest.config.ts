import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * The JavaScript test runner. Added by the branch that closes
 * docs/handoff/tickets/no-javascript-test-runner.md — until it landed, 63k lines of
 * hand-written TS/TSX had nothing that could assert a single one of their behaviours,
 * and `bin/quality` had four steps that read resources/js without executing any of it.
 *
 * IT IS A SEPARATE CONFIG FROM vite.config.ts ON PURPOSE. Vitest reads vite.config.ts
 * when no vitest.config.ts exists, which would drag the laravel, inertia, tailwind and
 * wayfinder plugins into every test run — wayfinder in particular REGENERATES
 * resources/js/{routes,actions} as a side effect, so running the tests would mutate the
 * tree the gate is measuring. This config carries the one thing the tests actually need
 * from the app's build (the `@` alias) and nothing else.
 *
 * ENVIRONMENT IS NODE, NOT jsdom/happy-dom. The functions under test are pure — integer
 * arithmetic and Intl — and touch no DOM. Installing a DOM environment "just in case"
 * would add a dependency, seconds per run, and a second global object whose differences
 * from a browser's are their own source of false results. When a test needs to render a
 * component, THAT is the moment to add the environment and justify it, per-file via
 * `// @vitest-environment jsdom` or a second project here.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.ts', 'resources/js/**/*.test.tsx'],
    },
});
