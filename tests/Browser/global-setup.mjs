/**
 * Refuses to run against fixtures that no longer match the template.
 *
 * The fixtures are rendered by PHP — only Blade can produce them — so this suite reads HTML from
 * disk. That creates a trap worth closing loudly rather than documenting: edit the component, run
 * `npx playwright test` directly, and every assertion passes against the *previous* render. It was
 * found the way these things usually are, by breaking the runtime four ways and watching the
 * browser suite stay green through all of them.
 *
 * So the Pest test stamps a manifest with a hash of the template it rendered from, and this checks
 * it still matches. Stale fixtures fail here with instructions rather than passing silently.
 */
import { readFileSync, existsSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const manifestPath = join(here, '.tmp', 'manifest.json');

const REGENERATE = 'Run `vendor/bin/pest --group=browser`, which renders them and runs this suite.';

export default function globalSetup() {
    if (!existsSync(manifestPath)) {
        throw new Error(`No browser fixtures found at tests/Browser/.tmp. ${REGENERATE}`);
    }

    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

    for (const [file, expected] of Object.entries(manifest.sources)) {
        const path = join(here, '..', '..', file);

        if (!existsSync(path)) {
            throw new Error(`${file} no longer exists, so the fixtures cannot be trusted. ${REGENERATE}`);
        }

        const actual = createHash('sha256').update(readFileSync(path)).digest('hex');

        if (actual !== expected) {
            throw new Error(
                `${file} has changed since the fixtures were rendered, so this suite would test ` +
                    `the previous version of it and pass. ${REGENERATE}`,
            );
        }
    }

    for (const page of manifest.pages) {
        if (!existsSync(join(here, '.tmp', `${page}.html`))) {
            throw new Error(`The ${page} fixture is missing. ${REGENERATE}`);
        }
    }
}
