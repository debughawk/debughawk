=== DebugHawk - WordPress Performance Monitoring & Debugging ===
Contributors: A5hleyRich
Tags: performance, monitoring, debug, core web vitals, profiling
Tested up to: 7.1
Stable tag: 1.4.0
Requires at least: 6.3
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Performance monitoring built for WordPress. Core Web Vitals from real visitors, plus slow queries and requests traced to the plugin or theme.

== Description ==

**Find out why your WordPress site is slow without deactivating plugins one by one.** DebugHawk monitors every page load on your WordPress site, front end and back end. It shows you what's slowing each page down and which plugin or theme is behind it.

[Try the live demo](https://app.debughawk.com/demo?utm_source=wordpress.org&utm_medium=plugin-listing&utm_campaign=readme&utm_content=intro-demo), no account needed.

= Why WordPress sites slow down =

A slow WordPress site loses visitors, sales and search rankings. Finding the cause is hard when tools like Google PageSpeed Insights only show what happens in the browser.

DebugHawk shows both sides. You see Core Web Vitals from real visitors next to the server work behind each page: database queries, PHP time, external requests and caching. When a query or external request is slow, DebugHawk tells you which plugin or theme made it.

= See which plugin or theme is involved =

DebugHawk traces slow and duplicate database queries and external requests back to the plugin or theme that made them. That includes code run from hooks, symlinked plugins and themes, and custom layouts such as Bedrock. You go straight to the code that needs attention.

= What DebugHawk monitors =

**In the browser, from real visitors:**

* **Core Web Vitals:** LCP (Largest Contentful Paint), INP (Interaction to Next Paint) and CLS (Cumulative Layout Shift), plus TTFB (Time to First Byte) and FCP (First Contentful Paint)
* **Network:** DNS lookup and connection time
* **Page weight:** requests, transfer sizes and assets grouped by plugin or theme

**On the server:**

* **Database:** slow queries, duplicate queries, query count and total database time
* **PHP:** execution time and memory usage
* **External requests:** timing, failures and timeouts for API calls and other outgoing HTTP requests
* **Object cache:** hit ratio
* **Page cache:** hits and misses, so you know your caching is working

= What you get in the dashboard =

* **What to fix first:** the slowest pages and queries, and the plugins and themes behind them
* **All your sites together:** compare performance across every WordPress site you look after
* **Page by page detail:** your slowest pages and what makes each one slow
* **Single pageview traces:** open any pageview to see its slow queries, external requests and assets
* **wp-admin monitoring:** track the admin screens your team and clients use every day
* **Shareable reports:** send clients a performance report as a link
* **Public API (beta):** pull your performance data into your own tools

= Privacy =

* DebugHawk replaces database query values, such as emails, IDs and search terms, with placeholders before they leave your WordPress site
* It strips query strings and credentials from page and external request URLs before sending them
* It doesn't store visitor IP addresses

= Who it's for =

* **Agencies and freelancers** looking after many client WordPress sites
* **WordPress developers** tracking down slow queries and bottlenecks in production
* **WooCommerce stores** where a slow checkout costs sales
* **Membership and LMS sites** with busy logged-in traffic
* **Site owners** who want to know why their WordPress site is slow

= Plans =

Every plan includes every feature and unlimited team members. The Free plan covers one WordPress site and shows your latest 10,000 pageviews from up to the last 7 days. Paid plans are priced by the number of sites you look after and keep up to 90 days of history. See [pricing](https://debughawk.com/pricing?utm_source=wordpress.org&utm_medium=plugin-listing&utm_campaign=readme&utm_content=pricing).

= Get started =

1. Create a free account at [debughawk.com](https://debughawk.com/?utm_source=wordpress.org&utm_medium=plugin-listing&utm_campaign=readme&utm_content=getting-started-signup)
2. Install and activate this plugin
3. Add your site configuration to `wp-config.php`
4. Visit your WordPress site and watch the data arrive in your dashboard

== Installation ==

**From your WordPress dashboard:**

1. Go to Plugins > Add New Plugin
2. Search for "DebugHawk"
3. Click "Install Now", then "Activate"

**Manual installation:**

1. Download the plugin ZIP file
2. Upload it to the `/wp-content/plugins/debughawk` directory
3. Activate the plugin from the Plugins screen

**Connecting your site:**

1. Sign up for a [DebugHawk account](https://debughawk.com/?utm_source=wordpress.org&utm_medium=plugin-listing&utm_campaign=readme&utm_content=installation-signup). The Free plan needs no credit card.
2. Copy your site configuration from the DebugHawk dashboard
3. Add it to your `wp-config.php` file, above the line `/* That's all, stop editing! */`

Your configuration looks like this:

```php
define( 'DEBUGHAWK_CONFIG', [
    'enabled'  => true,
    'endpoint' => 'https://ingest.debughawk.com/your-endpoint',
    'secret'   => 'your-secret-key',
] );
```

We recommend adding the configuration to `wp-config.php`, as it keeps your settings out of the database. You can also enter them under Settings > DebugHawk, which also has options for the sample rate, wp-admin monitoring and the slow query threshold.

Once connected, DebugHawk starts monitoring your WordPress site's performance automatically.

== Frequently Asked Questions ==

= How does DebugHawk help debug slow WordPress sites? =

DebugHawk monitors front end and back end performance together. Alongside Core Web Vitals from real visitors, it records slow database queries, PHP execution time, external requests and memory usage. It then traces slow queries and requests to the plugin or theme that made them.

= Will DebugHawk slow down my WordPress site? =

Not noticeably. In our tests it adds around 1 ms of server time to a typical page, about 1% of the time WordPress takes to build it, and around 45 KB of memory. A heavy page running 1,000 queries adds around 7 ms. In the browser, the script is under 9 KB compressed, loads asynchronously and sends its data with the Beacon API when the visitor leaves, so it doesn't hold up your content.

= How does DebugHawk work with Query Monitor? =

DebugHawk works alongside Query Monitor. Query Monitor is great for debugging the page you're looking at right now. DebugHawk records performance for every visitor over time, so you can spot patterns, compare pages and see when a problem started.

= Can DebugHawk show which plugin or theme is slowing a page? =

Yes. DebugHawk attributes slow database queries and external requests to the plugin or theme that made them, so you can see where the time goes on each page.

= Does DebugHawk work with WooCommerce? =

Yes. On your product, cart and checkout pages, DebugHawk monitors database performance, Core Web Vitals and external requests, including calls to payment gateways.

= How is Core Web Vitals data collected? =

DebugHawk uses Real User Monitoring (RUM), which measures Core Web Vitals in your real visitors' browsers. You get field data from real visits rather than a one-off lab test.

= Do I need a DebugHawk account? =

Yes. The plugin sends performance data to your DebugHawk dashboard, where you can explore trends, share reports and monitor all your WordPress sites. You can [create a free account](https://debughawk.com/?utm_source=wordpress.org&utm_medium=plugin-listing&utm_campaign=readme&utm_content=faq-signup) in a couple of minutes.

= Is there a free plan? =

Yes. The Free plan covers one WordPress site with every feature included. DebugHawk records every pageview, and your dashboard shows your latest 10,000 pageviews from up to the last 7 days. Upgrading shows everything DebugHawk has recorded, with longer history and more sites.

= Can I try DebugHawk before installing it? =

Yes. [Explore the live demo](https://app.debughawk.com/demo?utm_source=wordpress.org&utm_medium=plugin-listing&utm_campaign=readme&utm_content=faq-demo) to see the DebugHawk dashboard monitoring a set of demo WordPress sites, with data that updates every minute.

= Can I monitor multiple WordPress sites? =

Yes. DebugHawk is built for agencies and developers who look after many WordPress sites. Paid plans cover 5 to 200 sites in one dashboard, with unlimited team members.

= What data does DebugHawk collect? =

Performance data only: timings, query and request details, memory usage, cache results and Core Web Vitals. Each pageview includes the page URL with query strings removed, plus the visitor's country, device type and browser. DebugHawk replaces database query values with placeholders before they leave your site and doesn't store visitor IP addresses. See the External services section below for details.

= How do I find slow database queries? =

DebugHawk records slow queries on every page, along with how long they took and the plugin or theme that ran them. The Slow queries page in your dashboard groups them, so you can see which ones cost the most time across your WordPress site.

= Can DebugHawk find duplicate database queries? =

Yes. DebugHawk spots queries that run over and over on the same page, such as a query run once for every post in a loop, or the same query run repeatedly when its result could be cached. It shows how many times each one ran, how long they took in total and the plugin or theme behind them.

== External services ==

This plugin connects to DebugHawk, a performance monitoring service, to record and display your WordPress site's performance. It only connects once you've added your site configuration.

* **What's sent:** performance data for each monitored page load. This includes page timings, Core Web Vitals, database query timings with values replaced by placeholders, external request timings, memory usage, cache results, the page URL with query strings removed, and the WordPress, PHP and plugin versions. DebugHawk records the visitor's country, device type and browser, but doesn't store IP addresses.
* **When it's sent:** after each monitored page has loaded, from the visitor's browser to the endpoint in your configuration. The sample rate setting controls how many page loads DebugHawk monitors.
* **Browser script:** monitored pages load the monitoring script from `cdn.debughawk.com`.

See the DebugHawk [terms of service](https://debughawk.com/terms) and [privacy policy](https://debughawk.com/privacy).

== Changelog ==

= 1.4.0 =
* Duplicate database queries are now tracked, such as a query run once for every post in a loop, and attributed to the plugin or theme that ran them
* Queries that run with exactly the same SQL each time are flagged, as their result could usually be cached
* Duplicate query values are replaced with placeholders before they leave your site, as slow query values are

= 1.3.0 =
* Slow queries and external requests are now attributed to the plugin or theme that made them, including code run from hooks, symlinked plugins and themes, and custom layouts such as Bedrock
* Slow query values, such as emails, IDs and search terms, are replaced with placeholders before they leave your site
* Query strings and credentials are removed from page and external request URLs before they're sent
* Up to 10 slow queries are now reported per page, up from 3, and long queries are kept up to 2,048 characters
* Failed and timed-out external requests now show how long they held up the page, along with their error message
* Lower memory use and overhead on pages that run thousands of database queries
* More accurate database time on pages that run nested queries
* Response caching plugins now work as expected for external requests while DebugHawk is active
* Admin screens shown in a popup, such as plugin details, no longer count as separate pageviews
* Customizer previews and the Site Editor are now excluded correctly, including on subdirectory installs
* An error collecting one metric no longer stops the rest of the page from loading
* Pages with unusual characters in a URL or query are now reported instead of being skipped
* Pages with many external requests now report the slowest 20, so the data stays within the browser's size limit
* Removed redirect monitoring and the "Monitor Redirects" setting

= 1.2.0 =
* Added a welcome notice that points new installs to the configuration steps
* Redirect to the settings page on first activation

= 1.1.1 =
* The db.php drop-in is now updated, and removed when the plugin is deactivated

= 1.1.0 =
* The browser script now loads from a global CDN
* Send the DebugHawk plugin version with performance data
* Improved performance monitoring accuracy

= 1.0.1 =
* Only track redirects from the same domain
* More accurate redirect detection

= 1.0.0 =
* Official stable release
* Don't track /wp-login.php redirects, to reduce noise

= 0.8.2 =
* Fixed a PHP 8.4 deprecation warning

= 0.8.1 =
* Initial release on WordPress.org
* Core Web Vitals monitoring
* Database query performance tracking
* PHP execution time and memory monitoring
* External HTTP request timing
* Object cache and page cache monitoring

== Upgrade Notice ==

= 1.4.0 =
Adds duplicate database query tracking, with the plugin or theme behind each one. The db.php drop-in updates the next time you visit wp-admin.

= 1.3.0 =
More accurate plugin and theme attribution for slow queries and external requests, and query values are no longer sent. Redirect monitoring has been removed.

= 1.1.1 =
Fixes the db.php drop-in so it's removed when you deactivate the plugin.
