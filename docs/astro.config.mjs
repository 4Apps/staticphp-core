import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

export default defineConfig({
    // A GitHub Pages project page is served from a subdirectory, so `base` has to match
    // the repository name exactly or every asset and link 404s in production while
    // continuing to work in `astro dev`.
    site: 'https://gintsmurans.github.io',
    base: '/staticphp-core',

    integrations: [
        starlight({
            title: 'StaticPHP',
        }),
    ],
});
