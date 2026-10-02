# Tracking Change Journal

A journal of the tracking tags and consent mode defaults in your WordPress pages, checked after every plugin, theme and core update.

- Detects Google Tag Manager, Google Analytics 4, Google Ads, Google tag, Universal Analytics, Meta Pixel, Microsoft Clarity, Microsoft Advertising UET, Hotjar, LinkedIn, TikTok, Pinterest, Matomo, Plausible, Fathom and Umami in the HTML of the pages you choose, including preload hints and the IDs PixelYourSite passes to its script
- Records how each tag is included (directly, noscript, held back until consent or a loader script), the loader host, duplicates and Google consent mode defaults
- Checks one minute after updates, plugin and theme changes, and once a day; each entry lists the plugin, theme and core changes since the previous check
- CSV export and optional email; requests pages of the site only, stores no page content or visitor data

Tools > Tracking Journal. Requires WordPress 6.5 and PHP 7.4. Details in [readme.txt](readme.txt).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
