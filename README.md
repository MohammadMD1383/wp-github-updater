# HZF Updater

Updates our own WordPress plugins and themes from **GitHub Releases in private
repositories**, so those packages need no update code of their own. Requires
WordPress 6.1+ and PHP 7.4+. No Composer runtime dependencies.

## Setup

1. **Install** `hzf-updater.zip` (from this repo's releases) and activate it
   (network-activate on multisite). It updates itself afterwards.
2. **Add the token** to `wp-config.php` (never to the database, never to a settings screen):

   ```php
   define( 'HZF_UPDATER_GITHUB_TOKEN', 'github_pat_...' );
   ```

   Use a **fine-grained personal access token**, scoped to the repositories you
   update, with **Contents: Read-only** (nothing else). Add this plugin's own
   repository too if it is private.
3. **Opt a package in** with one header.

   Plugin main file:

   ```php
   /**
    * Plugin Name: My Plugin
    * Version:     1.6.0
    * Update URI:  https://github.com/<org>/<repo>
    */
   ```

   Theme `style.css`:

   ```css
   /*
   Theme Name: My Theme
   Version:    1.0.0
   Update URI: https://github.com/<org>/<repo>
   */
   ```

   Packages without this header, or with any other host, are ignored completely.

## Release convention

- **Tag = version**, with or without a leading `v` (`v1.7.0` or `1.7.0`).
- Each release has a ZIP **asset** named `<slug>.zip` or `<slug>-<version>.zip`
  (e.g. `bitycle-integration-1.7.0.zip`) whose top-level folder is
  the slug (plugin folder / theme folder). GitHub's auto-generated source
  zipball is never used (its folder name is wrong).
- Drafts and prereleases are ignored. The release body (Markdown) is shown
  under "View details".
- Optional headers `Requires at least`, `Requires PHP` and `Tested up to` of the
  installed package are passed on to the update data.

## How it behaves

- Checks run on WordPress' normal update checks (admin, cron, WP-CLI) and are
  **never** made on front-end requests.
- Each repo's latest-release response is cached for 6 hours (filter
  `hzf_updater_cache_ttl`) in a non-autoloaded, network-wide option, and
  revalidated with `ETag` / `If-None-Match`, so unchanged checks do not count
  against the rate limit. On errors or rate limits the last good data is kept
  and checks back off (exponentially, or until the rate-limit reset).
  Dashboard -> Updates -> **Check again** and the **Check now** button clear it.
- Downloads use the API asset URL. The `Authorization` header is added only for
  that URL on the API host of a managed repo; GitHub's redirect to the signed
  download URL is followed **without** it.
- If an extracted ZIP's folder does not match the installed slug it is renamed.
- Without the token constant, no request is sent to GitHub and an admin notice is shown.
  A 401/403 from GitHub shows a notice too.

## Admin and WP-CLI

- **Tools -> HZF Updater** (multisite: **Network Admin -> Settings -> HZF Updater**):
  installed version, latest version, last check, last error, and "Check now".
- `wp hzf-updater status [--format=<format>]`
- `wp hzf-updater check` clears the cache and checks now.
- `wp plugin update --all` / `wp theme update --all` work as usual.

## Filters

| Filter | Purpose |
| --- | --- |
| `hzf_updater_api_base` | Base URL for GitHub API calls (default `https://api.github.com`). Point it at a self-hosted proxy that holds the token; with a custom base the token constant is optional and, if defined, is sent to that base. |
| `hzf_updater_cache_ttl` | Cache lifetime in seconds (default 6 h). |
| `hzf_updater_manage_repo` | Return `false` to refuse a repo (`owner/repo`), e.g. an allow-list of organisations. |

## Security

The token is read only from the constant. It is never printed in HTML, JS, REST
output, logs, error messages or `WP_Error` data, and is redacted from any stored
or displayed request error. Uninstalling removes the stored state.

## Development

```bash
composer install     # dev tools only: WPCS + PHPCompatibilityWP
vendor/bin/phpcs     # WordPress Coding Standards
```

Releases: bump `Version` in `hzf-updater.php`, add a `CHANGELOG.md` section,
tag `vX.Y.Z` and push the tag. The `Release` workflow builds `hzf-updater.zip`
(top-level folder `hzf-updater`) and attaches it to the GitHub release.
