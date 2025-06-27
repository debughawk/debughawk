=== DebugHawk ===
Contributors:
Tags: performance, monitoring, debug, debugging, query monitor
Tested up to: 6.8
Stable tag: 0.8
Requires at least: 6.3
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WordPress performance debugging and monitoring, simplified.

== Description ==

WordPress performance debugging and monitoring, simplified. [DebugHawk](https://debughawk.com) gives you deep insight into your WordPress site's front-end and back-end performance, with the clarity only a tool purpose-built for WordPress can offer.

Here's an overview of what's tracked:

**Front-end Performance**

* Core Web Vitals (LCP, INP, CLS, TTFB, FCP)
* Network performance metrics (DNS lookup, connection time)
* Browser resource tracking (requests, page weight, transfer sizes)

**Back-end Performance**

* PHP execution time and memory usage
* Database query performance and count
* External HTTP request timing
* Object cache performance
* Page cache effectiveness
* Redirects

== Installation ==

1. Sign up for a [DebugHawk](https://debughawk.com) account.
2. Upload the plugin files to the `/wp-content/plugins/debughawk` directory, or install the plugin through the 'Plugins' screen.
3. Activate the plugin through the 'Plugins' screen.
4. Configure the plugin by adding the configuration provided by DebugHawk to your `wp-config.php` file, above the line that says `/* That's all, stop editing! */`:

```
define( 'DEBUGHAWK_CONFIG', [
    'enabled' => true,
    'endpoint' => '...',
    'secret' => '...',
] );
```

== Configuration ==

You can further customize DebugHawk, with the following additional options:

```
define('DEBUGHAWK_CONFIG', [
    'enabled'  => true,
    'endpoint' => '...',
    'secret' => '...',
    'sample_rate' => 0.25,           // Optional: Sampling rate (0-1, default: 1.0)
    'slow_queries_threshold' => 50,  // Optional: Slow query threshold in ms (default: 50)
    'trace_redirects' => true,       // Optional: Track redirects (default: true)
    'trace_admin_pages' => true,     // Optional: Track admin pages (default: true)
]);
```

== How It Works ==

Once configured, DebugHawk automatically:

1. **Monitors Performance** - Tracks PHP execution time, database queries, and memory usage on each page load
2. **Collects Browser Metrics** - Injects a lightweight JavaScript beacon to collect Core Web Vitals and resource timing
3. **Encrypts Data** - All collected data is encrypted before transmission

The plugin works transparently without any additional user interaction after configuration.
