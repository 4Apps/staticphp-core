import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import starlightLinksValidator from 'starlight-links-validator';

export default defineConfig({
    // A GitHub Pages project page is served from a subdirectory, so `base` has to match
    // the repository name exactly or every asset and link 404s in production while
    // continuing to work in `astro dev`.
    site: 'https://gintsmurans.github.io',
    base: '/staticphp-core',

    integrations: [
        starlight({
            title: 'StaticPHP',
            description: 'A lightweight PHP framework.',

            social: [
                {
                    icon: 'github',
                    label: 'GitHub',
                    href: 'https://github.com/gintsmurans/staticphp-core',
                },
            ],

            editLink: {
                baseUrl: 'https://github.com/gintsmurans/staticphp-core/edit/master/docs/',
            },

            // Starlight emits twitter:card=summary_large_image unconditionally, so a site
            // with no og:image advertises a card it cannot fill. Both files live in
            // public/ and are copied verbatim; the url has to be absolute for a scraper.
            head: [
                {
                    tag: 'meta',
                    attrs: { property: 'og:image', content: 'https://gintsmurans.github.io/staticphp-core/og.png' },
                },
                {
                    tag: 'meta',
                    attrs: { name: 'twitter:image', content: 'https://gintsmurans.github.io/staticphp-core/og.png' },
                },
            ],

            // Fontsource ships the @font-face rules and the woff2 files as packages, which
            // keeps the fonts self-hosted without a build step or a third-party CDN call.
            customCss: [
                '@fontsource-variable/inter',
                '@fontsource/jetbrains-mono',
                './src/styles/custom.css',
            ],

            // Root-absolute links silently drop the base path and 404 only in production.
            // This turns that class of mistake into a failed build.
            plugins: [starlightLinksValidator()],

            // Starlight 0.39.0 removed the `{ label, autogenerate }` shorthand; the
            // autogenerate config must be wrapped in an `items` array instead.
            sidebar: [
                { label: 'Getting started', items: [{ autogenerate: { directory: 'getting-started' } }] },
                { label: 'Core', items: [{ autogenerate: { directory: 'core' } }] },
                { label: 'Database', items: [{ autogenerate: { directory: 'database' } }] },
                { label: 'Internationalisation', items: [{ autogenerate: { directory: 'i18n' } }] },
                { label: 'Utilities', items: [{ autogenerate: { directory: 'utilities' } }] },
                { label: 'Presentation', items: [{ autogenerate: { directory: 'presentation' } }] },
                { label: 'Guides', items: [{ autogenerate: { directory: 'guides' } }] },
            ],
        }),
    ],
});
