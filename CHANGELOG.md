# Changelog

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
