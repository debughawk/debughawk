=== DebugHawk - WordPress Performance Monitoring & Debugging ===
Contributors: A5hleyRich
Tags: performance, monitoring, debug, slow, speed, database, queries, core web vitals, optimization, profiling, cache, site health
Tested up to: 6.9
Stable tag: 1.1.1
Requires at least: 6.3
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Monitor WordPress performance, debug slow sites, track Core Web Vitals, database queries, memory usage, and cache effectiveness. Performance monitoring built specifically for WordPress.

== Description ==

**Monitor and debug WordPress performance issues with DebugHawk** - the performance monitoring tool built specifically for WordPress developers and site owners who need to identify what's slowing down their site.

= Why WordPress Sites Are Slow =

Slow WordPress sites lose visitors, conversions, and search rankings. But identifying the root cause is difficult when frontend tools like Google PageSpeed Insights only show part of the picture.

DebugHawk monitors both frontend and backend performance so you can see exactly what's slowing down your WordPress site - whether it's slow database queries, external API calls, or frontend Core Web Vitals problems.

= What DebugHawk Monitors =

**Frontend Performance Metrics:**

* **Core Web Vitals** - LCP (Largest Contentful Paint), INP (Interaction to Next Paint), CLS (Cumulative Layout Shift), TTFB (Time to First Byte), FCP (First Contentful Paint)
* **Network Performance** - DNS lookup time, connection time
* **Browser Resources** - HTTP requests, page weight, transfer sizes

**Backend Performance Tracking:**

* **Database Query Performance** - Slow queries, query count, total database time
* **PHP Execution** - Execution time, memory usage
* **External HTTP Requests** - API call timing, external service performance
* **Object Cache Performance** - Hit ratio, cache effectiveness
* **Page Cache Effectiveness** - Cache hits vs misses
* **Redirect Detection** - Unnecessary redirects slowing page loads

= Debug Slow WordPress Sites =

Unlike generic performance tools, DebugHawk understands WordPress architecture. It shows you:

* Which plugins are causing slow database queries
* Which external APIs are timing out
* Whether your caching is working effectively

Perfect for WordPress developers, agencies managing multiple sites, and site owners who need to diagnose performance issues quickly.

= How It Works =

1. **Automatic Monitoring** - Once configured, DebugHawk tracks performance on every page load
2. **Real User Monitoring (RUM)** - See actual performance data from your real visitors, not synthetic tests
3. **Historical Data** - Track performance over time, identify when issues started
4. **Encrypted Transmission** - All data is encrypted before sending to DebugHawk's dashboard

No impact on site performance - the monitoring overhead is negligible.

= Perfect For =

* **WordPress Developers** debugging slow queries and performance bottlenecks
* **Agencies** monitoring client site performance across multiple WordPress sites
* **Site Owners** who need to understand why their WordPress site is slow
* **WooCommerce Stores** tracking checkout performance and database optimization
* **Membership Sites** monitoring server load and query performance

= Get Started =

1. Sign up for a free trial at [DebugHawk.com](https://debughawk.com)
2. Install and activate this plugin
3. Add your site configuration to `wp-config.php`
4. Start monitoring your WordPress performance

== Installation ==

**Automatic Installation:**

1. Log in to your WordPress admin dashboard
2. Go to Plugins > Add New
3. Search for "DebugHawk"
4. Click "Install Now" and then "Activate"

**Manual Installation:**

1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/debughawk` directory
3. Activate the plugin through the 'Plugins' screen in WordPress

**Configuration:**

1. Sign up for a [DebugHawk account](https://debughawk.com) (free trial available)
2. Copy your site configuration from the DebugHawk dashboard
3. Add the configuration to your `wp-config.php` file, above the line `/* That's all, stop editing! */`

Example configuration:

```php
define( 'DEBUGHAWK_CONFIG', [
    'enabled'  => true,
    'endpoint' => 'https://ingest.debughawk.com/your-endpoint',
    'secret'   => 'your-secret-key',
] );
```

That's it! DebugHawk will now monitor your WordPress site's performance automatically.

== Frequently Asked Questions ==

= How does DebugHawk help debug slow WordPress sites? =

DebugHawk monitors both frontend and backend performance. While tools like Google PageSpeed Insights only show frontend metrics, DebugHawk also tracks slow database queries, PHP execution time, external API calls, and memory usage - the backend issues that often cause WordPress sites to be slow.

= Will DebugHawk slow down my WordPress site? =

No. DebugHawk has negligible performance impact. The monitoring code is lightweight and optimized to avoid affecting your site speed.

= What's the difference between DebugHawk and Query Monitor? =

Query Monitor shows performance data for your current admin session only. DebugHawk tracks performance across all visitors over time, stores historical data, and lets you identify patterns and trends. Think of Query Monitor as a debugger, and DebugHawk as production monitoring.

= Can DebugHawk identify which plugins are slowing down my site? =

Yes. DebugHawk tracks database queries, HTTP requests, and execution time, showing you which plugins are creating performance bottlenecks.

= Does DebugHawk work with WooCommerce? =

Absolutely. DebugHawk is perfect for WooCommerce sites where slow checkout pages can cost you sales. Monitor database query performance, external payment gateway API calls, and Core Web Vitals on your product and checkout pages.

= How is Core Web Vitals data collected? =

DebugHawk uses Real User Monitoring (RUM) to collect Core Web Vitals from actual visitors' browsers, giving you accurate field data instead of synthetic lab tests.

= Do I need a DebugHawk account? =

Yes. The plugin sends performance data to DebugHawk's dashboard where you can analyze trends, set up alerts, and monitor multiple WordPress sites. Start with a free trial at [debughawk.com](https://debughawk.com).

= Can I monitor multiple WordPress sites? =

Yes. DebugHawk is designed for agencies and developers managing multiple WordPress sites. Monitor all your sites from one dashboard.

= What data does DebugHawk collect? =

DebugHawk collects performance metrics only: page load times, database query times, memory usage, Core Web Vitals, etc. No personal user data or content is collected. All data is encrypted during transmission.

= How do I debug slow database queries? =

DebugHawk automatically tracks all database queries, showing you slow queries, duplicate queries, and total database time. View this data in your DebugHawk dashboard to identify optimization opportunities.

== Changelog ==

= 1.1.1 =
* Ensure db.php drop-in is updated and removed on plugin deactivation

= 1.1.0 =
* Beacon script now served from global CDN for faster performance
* Send DebugHawk plugin version as part of telemetry payload
* Improved performance monitoring accuracy

= 1.0.1 =
* Only track redirects originating from the same domain
* Enhanced redirect detection accuracy

= 1.0.0 =
* Official stable release
* Don't track /wp-login.php redirects to reduce noise

= 0.8.2 =
* Fixed PHP 8.4 deprecation warning for better compatibility

= 0.8.1 =
* Initial release on WordPress.org
* Production-ready performance monitoring
* Core Web Vitals monitoring
* Database query performance tracking
* PHP execution time and memory monitoring
* External HTTP request timing
* Object cache and page cache effectiveness

== Upgrade Notice ==

= 1.1.1 =
Important update: Ensures db.php drop-in is properly managed during plugin deactivation.
