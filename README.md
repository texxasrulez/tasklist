# Roundcube Tasklist — Nextcloud CalDAV

[![Packagist](https://img.shields.io/packagist/dt/texxasrulez/tasklist?style=plastic)](https://packagist.org/packages/texxasrulez/tasklist)
[![Packagist Version](https://img.shields.io/packagist/v/texxasrulez/tasklist?style=plastic)](https://packagist.org/packages/texxasrulez/tasklist)
[![Project license](https://img.shields.io/github/license/texxasrulez/tasklist?style=plastic)](https://github.com/texxasrulez/tasklist/LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/texxasrulez/tasklist?style=plastic&logo=github)](https://github.com/texxasrulez/tasklist/stargazers)
[![issues](https://img.shields.io/github/issues/texxasrulez/tasklist?style=plastic)](https://github.com/texxasrulez/tasklist/issues)
[![Donate to this project using Paypal](https://img.shields.io/badge/paypal-donate-blue.svg?style=plastic&logo=paypal)](https://www.paypal.me/texxasrulez)

This Tasklist plugin works with Nextcloud.

- Robust list discovery (skips non-VTODO and system folders; prioritizes user lists like **To Do**)
- Safer request parsing to avoid PHP *Undefined array key* notices (e.g. `description`, `id`, `uid`)
- Clean JSON/AJAX responses that the Tasklist UI expects
- Defensive saving: never writes to `inbox/`, `outbox/`, or `trashbin/`
- Debug logging: only logs when something is wrong; keeps your logs quiet when healthy

## Requirements

- Roundcube 1.6+ (PHP 8.1/8.2 compatible)
- Nextcloud with the **Tasks** and **Calendar** apps enabled
- A working CalDAV endpoint (e.g. `https://example.tld/cloud/remote.php/dav/`)

## Quick Install

1. **Backup** your existing Roundcube `plugins/tasklist` directory.
2. Unzip this package so it becomes `plugins/tasklist/` in your Roundcube install.
3. In `config/config.inc.php` (or `plugins/tasklist/config.inc.php`), set:

```php
// Core CalDAV endpoint (no credentials here; Roundcube uses your session)
$config['tasklist_caldav_server'] = 'https://YOUR.DOMAIN/cloud/remote.php/dav/';

// Optional: leave empty to let discovery pick a list,
// or set explicitly to your VTODO collection if you prefer.
// Use %u for full login, %p for local-part (before '@').
$config['nextcloud_tasks_collection'] = ''; // e.g. '/cloud/remote.php/dav/calendars/%u/e2ea7342.../'
```

> **Note**: If you set `nextcloud_tasks_collection`, keep `tasklist_caldav_server` **filled** as above.
> The server base is still used for principal discovery and remains required.

4. Enable the plugin in Roundcube `config/config.inc.php`:

```php
$config['plugins'][] = 'tasklist';
```

5. Clear Roundcube cache (optional):

```bash
rm -rf <roundcube>/temp/* <roundcube>/logs/*
```

## Usage Notes

- The plugin discovers all your CalDAV collections and only enables lists that support **VTODO**.
- If you have multiple task lists, saving defaults to the first VTODO list (e.g. **To Do**) unless you choose another.
- The UI sends AJAX and expects JSON. When you see a white JSON blob, it usually means the frontend JS didn't run.
  Refreshing the frame or clearing browser cache typically resolves it.

## Troubleshooting

- **403 on PUT**: You are writing into a system or read-only collection (e.g. `outbox/`, `inbox/`, `trashbin/`) or the server denies writes. Pick a user list like **To Do**.
- **Undefined array key** warnings: This build guards typical keys (`description`, `id`, `uid`). If you see a new one, note the key and open an issue.
- **No debug after success**: That's expected — the logs are quiet when healthy.
- **Two or more lists**: This build handles multi-list discovery and returns stable task `uid`s even when servers omit them.
- **Template errors**: Ensure the plugin folder name is exactly `tasklist/` and that `skins/` files are present.

## Updating

Overwrite the plugin folder with a newer build, then clear Roundcube caches. No database migrations are required.

---

