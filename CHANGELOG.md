# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0]

### Added
- Updates plugins and themes that declare `Update URI: https://github.com/<org>/<repo>` from GitHub Releases, using the core `update_plugins_github.com` / `update_themes_github.com` filters.
- Private asset downloads: the token is sent only to the API asset URL of a managed repo, and the redirect to the signed download URL is followed without it.
- 6 h cache per repo (`hzf_updater_cache_ttl`), ETag revalidation, back-off on errors and rate limits, last good data kept.
- Release assets named `<slug>.zip` or `<slug>-<version>.zip` are accepted.
- GitHub is only contacted from admin, cron and WP-CLI, never on front-end requests.
- "View details" release notes (`plugins_api` / `themes_api`).
- Self-update, extracted-folder rename safety (`upgrader_source_selection`).
- Tools -> HZF Updater status page with "Check now" (Settings -> HZF Updater in the network admin), admin notice for a missing or rejected token.
- `wp hzf-updater status` and `wp hzf-updater check`.
- `hzf_updater_api_base` filter to use a proxy that holds the token.
- `fa_IR` translation; uninstall removes stored data; multisite (network-activated) support.
