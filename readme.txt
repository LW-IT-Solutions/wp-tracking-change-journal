=== Tracking Change Journal ===
Contributors: lwitsolutions
Tags: google tag manager, analytics, consent mode, tracking, monitoring
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A journal of the tracking tags and consent mode defaults in your public pages, checked after every plugin, theme and core update.

== Description ==

Tracking rarely breaks on purpose. A plugin update brings its own Google tag, a theme switch drops the Tag Manager
snippet, a consent tool update changes the consent mode defaults, and the first sign is a dip in the reports a week
later.

Tracking Change Journal checks the pages you choose one minute after every plugin, theme or WordPress update, after a
plugin is activated, deactivated or deleted, after a theme switch, and once a day. It compares the result with the
previous check and writes a journal under Tools > Tracking Journal:

* **Tags:** Google Tag Manager containers, Google Analytics 4, Google Ads, Google tag and Universal Analytics IDs, Meta Pixel, Microsoft Clarity, Microsoft Advertising UET, Hotjar, LinkedIn Insight Tag, TikTok Pixel, Pinterest Tag, Matomo, Plausible, Fathom and Umami, including the pixel and measurement IDs that PixelYourSite passes to its script.
* **How each tag is included:** loaded, configured or preloaded directly, as a noscript fallback, or held back until consent or a loader script (as consent tools and script delay features do), and from which host the loader comes.
* **Duplicates:** a container or measurement ID loaded or configured more than once on the same page, which can count hits twice.
* **Google consent mode defaults** set in the page.
* **Context:** each entry lists the plugins, themes and WordPress versions that changed since the previous check, so a removed tag sits next to the update that removed it.

Checks after a change are always written to the journal, also when nothing changed, as a record that the update left
tracking intact. Daily checks are written only when something changed. The journal can be exported as CSV, and an
optional email tells the site administrator about tracking changes.

= Privacy =

The plugin loads pages of this site only, without cookies, the way a first-time visitor receives them. It stores tag
names, IDs, loader host names, consent defaults, HTTP status codes and the versions of plugins, themes and WordPress.
It stores no page content and no visitor data, sets no cookies and contacts no external service. Uninstalling removes
all stored data.

= Limits =

* The check reads the HTML the server delivers. Tags that a Tag Manager container or another script adds later in the browser are not visible; for a container, its ID and how it is loaded are recorded.
* Pages behind a login, and content that differs per visitor, are checked as a visitor without cookies sees them.
* Up to ten pages per site and 200 journal entries.
* Each page is read up to 3 MB. A check that takes longer than a minute keeps the previous result for the remaining pages and says so in the journal. If the site cannot load its own home page (some hosts block such loopback requests), the journal shows the error.

== Installation ==

1. Upload the tracking-change-journal folder to wp-content/plugins, or install the ZIP, and activate it.
2. Open Tools > Tracking Journal. The first check runs a few seconds after activation; "Check now" runs one at once.
3. Under Settings, add the pages that load different tags, for example a checkout or a landing page.

== Frequently Asked Questions ==

= Why does the check add a query argument to the address? =

The argument trcj-check passes page caches, so the check sees what WordPress renders after an update and not a page
cached before it. WordPress ignores the argument.

= A tag from my Tag Manager container is missing. Why? =

Tags inside a container are loaded by the container in the browser and are not part of the page HTML. The journal
records the container ID and how it is loaded. Changes inside the container are versioned in Google Tag Manager itself.

= What does "waits for consent or a loader script" mean? =

The code is in the page, but marked so that the browser does not run it on load, for example with type="text/plain"
or a data-src attribute. Consent tools and script delay features work this way. A change from waiting to loading
directly is recorded.

= What does uninstalling remove? =

All options and scheduled checks of the plugin. Nothing else is stored.

== Screenshots ==

1. The journal: a Tag Manager plugin activated and deactivated, an update without tracking change, a theme switch that removed a Google Analytics tag, and a consent tool update that lets the Meta Pixel load without waiting for consent.
2. Tools > Tracking Journal: the tags each checked page loads now, how they are included, and the consent mode defaults.

== Changelog ==

= 0.1.0 =
* First version: tag and consent default detection, checks after updates and daily, journal with component changes, CSV export, optional email.
