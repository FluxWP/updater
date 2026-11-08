# Changelog

## 1.14.0
- Mirror localized package descriptions, publisher links, icons and galleries from each configured GitHub repository's `catalog/packages.json`.
- Pin metadata to its latest manifest commit and media to immutable Git blobs; fetch and verify media lazily, then cache and distribute it from the updater origin.
- Keep repository credentials and source paths on the server. Apply existing site/package/country permissions to signed image and SVG icon URLs, including revocation.
- Bound GitHub responses, validate manifest paths/locale keys/HTTPS links, re-encode raster media and sanitize SVG icons with a restricted content security policy.
- Show GitHub synchronization status and an authenticated refresh action in the admin panel. Preserve last good metadata during GitHub outages and avoid duplicate cold-media downloads.
