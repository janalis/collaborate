import { execFileSync } from 'node:child_process';
import { defineConfig } from 'vitepress';

const repo = 'https://github.com/janalis/collaborate';
const base = '/collaborate/';
const site = `https://janalis.github.io${base}`;

// Latest release tag, shown in the nav ("dev" before the first release or outside a git checkout).
function version(): string {
  try {
    return execFileSync('git', ['describe', '--tags', '--abbrev=0'], { stdio: ['ignore', 'pipe', 'ignore'] })
      .toString()
      .trim();
  } catch {
    return 'dev';
  }
}

export default defineConfig({
  title: 'collaborate',
  description:
    'Live collaboration for Symfony admin pages over Mercure: shared form drafts, presence and cursors, saved-view refresh.',
  lang: 'en-US',
  base,
  cleanUrls: true,
  lastUpdated: true,
  srcExclude: ['internals/**'],
  sitemap: { hostname: site },
  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: `${base}favicon.svg` }],
    ['meta', { name: 'theme-color', content: '#2f5bd3' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'collaborate' }],
  ],
  themeConfig: {
    logo: { src: '/logo.svg', alt: 'collaborate' },
    nav: [
      { text: 'Guide', link: '/guide/introduction', activeMatch: '/guide/' },
      { text: 'Contributing', link: '/contributing/', activeMatch: '/contributing/' },
      {
        text: version(),
        items: [
          { text: 'Changelog', link: '/changelog' },
          { text: 'Releases', link: `${repo}/releases` },
        ],
      },
    ],
    sidebar: {
      '/guide/': [
        {
          text: 'Introduction',
          items: [
            { text: 'What is collaborate?', link: '/guide/introduction' },
            { text: 'Installation', link: '/guide/installation' },
            { text: 'Getting started', link: '/guide/getting-started' },
          ],
        },
        {
          text: 'Integration',
          items: [
            { text: 'Configuration', link: '/guide/configuration' },
            { text: 'Identity and access', link: '/guide/identity-and-access' },
            { text: 'Rich text and scripts', link: '/guide/rich-text' },
            { text: 'EasyAdmin', link: '/guide/easyadmin' },
            { text: 'Without EasyAdmin', link: '/guide/without-easyadmin' },
          ],
        },
        {
          text: 'Behavior',
          items: [
            { text: 'Shared drafts', link: '/guide/shared-drafts' },
            { text: 'Cursors and presence', link: '/guide/cursors-and-presence' },
            { text: 'Saved-view refresh', link: '/guide/saved-views' },
            { text: 'Notifications', link: '/guide/notifications' },
            { text: 'Operations and security', link: '/guide/operations' },
          ],
        },
      ],
      '/contributing/': [
        {
          text: 'Contributing',
          items: [
            { text: 'Contributing', link: '/contributing/' },
            { text: 'Architecture', link: '/contributing/architecture' },
            { text: 'Testing', link: '/contributing/testing' },
            { text: 'Releasing', link: '/contributing/releasing' },
          ],
        },
      ],
    },
    search: { provider: 'local' },
    editLink: {
      pattern: 'https://github.com/janalis/collaborate/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },
    socialLinks: [{ icon: 'github', link: repo }],
    outline: { level: [2, 3] },
    footer: {
      message: 'Released under the MIT License.',
    },
  },
});
