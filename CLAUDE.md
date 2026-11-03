# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build Commands

- `npm run build` - Build production assets
- `npm run watch` - Watch and rebuild assets during development
- `npm run typecheck` - Run TypeScript type checking
- `composer test` - Run PHPUnit tests (requires `composer install` with dev dependencies)
- `./build.sh` - Create distributable zip file (removes dev dependencies)

## Architecture Overview

DebugHawk is a WordPress performance monitoring plugin that collects metrics and sends them to an external endpoint.

### Core Components

1. **Plugin** (`src/Plugin.php`): Main orchestrator that initializes collectors and dispatchers
2. **CollectorManager**: Manages data collectors that gather performance metrics
3. **DispatcherManager**: Manages dispatchers that send data to the configured endpoint
4. **Config** (`src/Config.php`): Configuration management - requires `DEBUGHAWK_CONFIG` constant with `endpoint` and `secret`

### Data Flow

1. Collectors gather data during WordPress execution (database queries, cache hits, request info)
2. BeaconDispatcher injects JavaScript that collects browser performance metrics
3. Data is encrypted using AES-128-CTR before transmission
4. Frontend beacon.ts sends Core Web Vitals and network metrics to the endpoint

### Key Patterns

- **Database Drop-in**: `wp-content/db.php` extends wpdb to transparently monitor queries
- **Sampling**: Configurable sample rates to control data collection frequency
- **Attribution**: `Backtrace` blames a slow query or HTTP request on the innermost plugin, mu-plugin, theme or other wp-content file in the stack, skipping core, DebugHawk, the drop-in and files outside WordPress. A `WP_Hook` dispatch reached first means a core callback made the call, so it's attributed to core. Covered by `tests/BacktraceTest.php`
- **Interface-based Design**: Components implement `NeedsInitiatingInterface` when they need initialization
- **Manager Pattern**: Collectors and Dispatchers are managed by their respective managers for extensibility

### Configuration

Plugin requires a `DEBUGHAWK_CONFIG` constant in wp-config.php:
```php
define('DEBUGHAWK_CONFIG', [
    'endpoint' => 'https://your-endpoint.com',
    'secret' => 'your-secret-key',
    'sample_rate' => 1.0, // 0-1
    'slow_queries_threshold' => 50, // ms
]);
```

### Frontend Development

- TypeScript source in `resources/src/`
- Vite bundles to `resources/dist/`
- Main entry point is `beacon.ts` which collects browser metrics

### Database Monitoring

The plugin copies its `wp-content/db.php` to WordPress's `wp-content/db.php` on activation. This drop-in replacement for wpdb times every query and keeps running totals (count, time, per-type), passing only slow queries to `DatabaseCollector::track_slow_query()`, which it calls directly (not through a hook) so backtraces stay accurate. It doesn't store queries, so memory stays flat.

The installed copy only updates on an admin page load when its `Version` header is higher than the installed one, so bump it whenever `db.php` changes. Until then, and when another drop-in is in place (such as Query Monitor's) or `SAVEQUERIES` is on without ours, `DatabaseCollector` falls back to the `log_query_custom_data` filter and `$wpdb->queries`.