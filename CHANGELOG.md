# Changelog

## 1.15.6
- Default setup-wizard category for Flux Ads (`flx-ads`): Growth, after Flux Analytics. A category saved in the panel still wins.

## 1.15.5
- Default setup-wizard category for Flux Files (`flx-files`): System. A category saved in the panel still wins.

## 1.15.4
- Default setup-wizard category for Flux Block Editor (`flx-blocks`): Growth. A category saved in the panel still wins.

## 1.15.3
- Append each update check to `data/stats.journal` and fold it into `data/stats.json` in batches (or when the panel, the admin API, or a key handout reads statistics). A check no longer rewrites the statistics file. The fold stores the last applied sequence, so a journal left behind after a crash is not counted twice.
- When the package catalog expires, one request refreshes it from GitHub. Other checks keep serving the previous catalog and do not wait. If there is no catalog yet, they wait up to 20 seconds for that refresh instead of calling GitHub themselves. A failed refresh keeps the previous catalog and backs off for 30 seconds. The webhook and Reload packages still force a refresh.

## 1.15.2
- Bind a keyless site record to its install ID. A second install ID for the same declared address is a separate row and cannot replace the first install ID.
- Hand a panel-issued key only to the first request that presents that exact install ID. The handout is cleared under the settings lock, and it expires after 24 hours if nobody claims it.
- When several install IDs report one address, the connected-sites table warns to verify the site before issuing a key, and shows first seen, last seen and a short install-ID prefix on each row.
- Reject an install ID that is not 40 lowercase hex characters. Cap keyless rows (drop the stalest) so a flood of install IDs cannot grow `data/stats.json` without limit.

## 1.15.1
- Require a setup token before the first admin account can be created. The token comes from `FLX_UPDATE_SETUP_TOKEN` or is generated once into `data/.setup-token` and the PHP error log; it is compared in constant time and is not shown on the setup page.
- Throttle failed panel, setup and manager-key attempts in a locked file (site keys are 192-bit random and are not throttled, so one misconfigured site cannot lock out others behind the same shared-hosting IP). Store only a short-lived salted hash of the client, never the IP. Failed key attempts in `data/access.log` record the country, not the IP.
- A new site key no longer receives every private package. Omitting `packages` means no private access; `"*"` is still all private packages. The panel's custom selection still includes Flux Setup.
- Mask a saved webhook secret in the panel and in `GET /v1/admin/settings`. The full value is shown only when it is generated.
- Skip a second download of a release zip when a copy of the expected size is already stored, including concurrent requests waiting on the same lock.

## 1.14.0
- Preserve manager APIs, desktop repository selection, site records and wizard placement from current main. Replace the former ZIP/tag media path and server-side package copy with the GitHub catalog as the only preview source.
- Mirror localized package descriptions, publisher links, icons and galleries from each configured GitHub repository's `catalog/packages.json`.
- Pin metadata to its latest manifest commit and media to immutable Git blobs; fetch and verify media lazily, then cache and distribute it from the updater origin.
- Keep repository credentials and source paths on the server. Apply existing site/package/country permissions to signed image and SVG icon URLs, including revocation.
- Bound GitHub responses, validate manifest paths/locale keys/HTTPS links, re-encode raster media and sanitize SVG icons with a restricted content security policy.
- Show GitHub synchronization status and an authenticated refresh action in the admin panel. Preserve last good metadata during GitHub outages and avoid duplicate cold-media downloads.
