# Changelog

## 1.21.0
- "Check now" no longer waits out the 5-minute release cache. A `/v1/check` body with `fresh: true` makes the server revalidate the release list (conditional request with the stored ETags: 304 when nothing changed, which does not count against the GitHub rate limit; a new release shows up at once). The limit is server-wide: GitHub is asked at most once every 15 seconds no matter how many sites or clicks, and a repository in back-off is not asked at all. Normal background checks are unchanged.

## 1.20.0
- Package signatures: a release asset `<slug>.zip.sig` (Ed25519, made by the package repository's GitHub Actions) is served at `/v1/download/<slug>/<version>.zip.sig` with the same access rules as the zip. `/v1/check` marks each package `signed`. This server cannot sign; it only carries the file, so FluxWP Panel can refuse a zip that a compromised server, panel account or repository list would hand out. An unsigned version answers 404 `unsigned`. The signature is cached next to the zip and removed with it.

## 1.19.0
- Connected sites: tick rows and *Remove selected from list* (with confirmation) to drop leftover records, e.g. duplicate `localhost` rows from reinstalled Docker sites. The rows are also removed from the unique-site counts; check totals stay. Keys and pending key handouts are not touched, and an install that checks again shows up as a new row. Panel action / admin API action `forget_clients` (`cid[]`).

## 1.18.1
- The admin panel no longer waits for GitHub. When the release list cache had expired (5 minutes, so almost every panel visit), the page itself re-read every release page of every repository before drawing (FluxWP/fluxwp alone is 9 pages, ~5 MB), which held the page for 10-40 s. The panel and the admin API now draw from the cached list and refresh it after the response (`?ajax=releases` in the panel, after the JSON is sent in the API).
- Release refresh uses a conditional request on the first page of each repository. If nothing changed GitHub answers 304 (not counted against the rate limit) and the cached releases are kept, so a refresh is two small requests instead of a dozen large ones. Each repository is still read in full once an hour, and the webhook and Reload packages still force a full read.

## 1.18.0
- Mobile OTA beta: devices (random code the app sends) and site users (`site#userID`) on the beta list get the `beta` channel's update for their runtime; everyone else stays on the channel their build asks for. *Promote to stable* serves the same signed beta update on `production`. Device records (model, platform, runtime, account ids, first/last seen) are kept 180 days, at most 2000. Panel: Mobile OTA updates › Beta. Admin API: actions `ota_beta_device`, `ota_beta_accounts`, `ota_promote`; `GET /v1/admin/ota` includes devices and beta lists, plus `manifest_url`, `key` (`set`, `hint`, `created`, `env`) and `cert` (`set`, `pem`, `cn`, `valid_to`, `env`) so a manager screen can show setup status.

## 1.17.0
- Connected sites has filter tabs with counts: All, Keyed, Public, Local / test, Disabled, Awaiting delivery and Possible impersonation (the last three only appear when they have rows). The list shows 15 rows per tab with a Show all button. Local and test addresses (`localhost`, `127.x`, `.local`, `.test`, `.localhost`, ddev, Lando) get their own tab and no longer raise the possible-impersonation warning, since any install can report them.

## 1.16.3
- The panel no longer calls GitHub while the page is being built. The GitHub package previews table is drawn from the cache and refreshed by a separate request after the page opens. Before, every panel view after the cache expired waited for two GitHub requests per repository (10-15 s timeout each), which stalled the page below the country map.

## 1.16.2
- Mobile OTA panel section caches each brand's disk usage for 10 minutes (`disk.json`) instead of walking `assets/` and `updates/` on every panel view.

## 1.16.1
- The admin panel opens without waiting for slow parts. The Resources section loads after the page, from a separate request, so the usage overview, chart and tables no longer stall behind it.
- The `data/` folder size walk (zips, previews, locks) is cached for 10 minutes instead of running on every panel view and admin API call. Pruning zips or clearing the log refreshes it.
- The GitHub API quota check times out after 4 seconds, keeps the last good value when GitHub is slow and does not retry for 60 seconds after a failure.
- Statistics fold prunes old days and sites once per batch instead of once per event.

## 1.16.0
- Mobile OTA updates over the Expo Updates protocol v1 (`/v1/expo/<brand>/manifest`, content-addressed assets), replacing EAS Update for the FluxWP mobile app. Manifests and directives are signed in CI; the server stores the signed bytes and never holds the private key. Publish key and optional certificate in /admin › Mobile OTA updates (`FLX_UPDATE_OTA_KEY`, `FLX_UPDATE_OTA_CERT`). Panel shows each channel/platform/runtime, last device check and the last 8 updates, and can serve an earlier one again or pause. Admin API `GET /v1/admin/ota`.

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
