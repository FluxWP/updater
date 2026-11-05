<div align="center">

**English** · [Türkçe](README.tr.md)

# FluxWP Update Server

A small PHP server that turns GitHub releases into WordPress updates. Sites running **FluxWP Panel** (flx-setup) see your private and public themes and plugins on the normal **Dashboard › Updates** screen.

![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white) ![No database](https://img.shields.io/badge/database-none-2fbf8f) ![Shared hosting](https://img.shields.io/badge/runs%20on-shared%20hosting-21759b) ![Docker](https://img.shields.io/badge/Docker-optional-2496ed?logo=docker&logoColor=white)

</div>

The official FluxWP server is `https://updater.fluxwp.com`. The same code runs your own server.

- **Public repositories**: packages are served to everyone, no key needed.
- **Private repositories**: packages are served only with a **site key**, limited to the packages assigned to that key.

There are no default repositories. You pick them in /admin › GitHub; a repository added only to Public is served without a key. The GitHub token stays on this server and never reaches the sites.

```
GitHub repositories (push → Actions → <slug>-v<version> release + <slug>.zip)
        │ token (private repositories, Contents: Read-only)
        ▼
update server   /admin (panel) · /v1/check · /v1/download/<slug>/<version>.zip · /v1/app/* · /v1/webhook
        ▲ no key: public packages · X-FluxWP-Site-Key: + the key's private packages
WordPress sites (FluxWP Panel)
```

Everything is set in the **/admin** panel; `config.php` is not needed.

## Install (shared hosting, zip)

1. Create a subdomain (e.g. `updater.example.com`) as a plain PHP 7.4+ site and turn on SSL.
2. Download `flx-update-server.zip` from [Releases](../../releases/latest) and extract it into the subdomain's root folder.
3. Open `https://<subdomain>/admin` right away and complete **First-time setup** (username + password). Whoever does this first becomes the admin.
4. **GitHub** card: pick the private and public repositories, paste the token, then *Save and test*. It should show **Connected**.
   Token: GitHub › Settings › Developer settings › Fine-grained tokens, only the private repositories, **Contents: Read-only**. Public-only servers need no token.
5. **Add site**: name, private packages (all or selected), channel, then *Generate key*. The key is shown **once**; copy it and check it with *Test this key*.
6. On the WordPress site: install `flx-setup.zip` once, open **Setup › Settings**, paste the key and *Save and check*. The plugin uses `https://updater.fluxwp.com` by default; enter your own address if you run your own server. A site without a key sees only public packages.
7. Optional, **instant publish**: *Generate secret*, then add it to the package repository under Settings › Secrets › Actions as `UPDATE_WEBHOOK_SECRET`. Without it a new version shows up within 5 minutes.

To upgrade, extract the new zip over the old one. Settings live in `data/settings.json`, which is not in the zip. `data/` must be writable.

### Check

- `https://<server>/` returns `{"ok":true,…,"configured":true,"public":true}`
- `https://<server>/data/settings.json` returns 403
- `https://<server>/admin` shows the login page

## Features

### Site keys

- Only the key's sha256 is stored. A lost key cannot be recovered: delete the site and add it again.
- **Issue a key to a connected site:** under Overview › Connected sites, a site that connected without a key gets an "Issue key" button. The site picks the key up on its next update check (or right away with "Test connection" under Setup › Settings). Needs FluxWP Panel (or FluxWP Panel 1.7.0+), which sends a random install ID only to this server; the key is handed only to a request carrying that ID, so a site that fakes another site's address cannot take it. An unclaimed key expires after 7 days.
- **Enable/disable:** "Disable" turns a key off. A disabled key still identifies the site but only unlocks public packages; the site shows "key disabled on server".
- **Scope:** "All private packages", or selected repositories and packages. Ticking "all" for a repository also covers packages added later.

### Panel

- **Overview:** sites that checked for updates in the last 7/30/90 days (with and without a key), checks, downloads, data sent, a daily chart, installs per package, a country map and the connected sites. Stored in `data/stats.json` (daily totals 120 days, sites 180 days after last seen). IPs are not stored; the country is looked up from the request's IP while it is handled (see *Countries and security* below).
- **Resources:** disk, `data/` breakdown, GitHub API quota, package cache age, PHP limits. Old zips and the access log are cleaned from here.
- **Repository picker:** private, public and app repositories are chosen as tags from the list GitHub returns for the token. Type `owner/repo` and press Enter to add one that is not listed.
- **Language:** English (default) and Turkish, switched with EN/TR at the top.

### Packages

- Public packages are served without a key, except beta versions. With a key, the key's private packages are added to the same response.
- If a repository cannot be read, its packages come from the last known list and the GitHub card shows "Unreadable".
- `/v1/check` returns `requires_plugins` for every package (the `Requires Plugins:` header), so FluxWP Panel can install missing plugins together.
- A version containing `-` (e.g. `1.3.0-beta.1`) is visible only on the beta channel.

### Zip storage

`data/zips` keeps the last 5 versions of each package. An older version is fetched from the GitHub release again and deleted 24 hours after its last download. Cleanup runs after each download and once an hour; the limits are in /admin › Files. Nothing is deleted while GitHub is unreachable.

### Privacy: what a site sends

Every `/v1/check` carries the installed FluxWP packages and versions, the FluxWP Panel version, a random install ID (one per server, not derived from the site) and the site key if there is one. This is what updates need.

FluxWP Panel 1.0.8+ adds the site address, WordPress and PHP version, time zone and language only when the admin opted in ("Help us improve FluxWP" in the setup wizard or FluxWP Panel › Settings), and sends `"usage": false` otherwise. Without opt-in the server stores none of those and identifies the site by its install ID. The country code is derived from the request's IP regardless of opt-in (the IP is not stored). When a site withdraws, its earlier record with the address is merged and dropped on the next check. Older clients without the `usage` field are treated as opted in.

IP addresses are not written to the statistics. `data/access.log` keeps the IP only for failed key attempts (security) and drops lines older than 90 days. Daily counters are kept 120 days, site records 180 days after the last check.

### App updates

- **Mobile/desktop app:** releases tagged `v<version>` in the app repository (panel › GitHub › App repository). File names decide the platform: `*windows*`, `*macos*`, `*.apk`, `*ios*`.
- **FluxWP desktop:** `fluxapp-v<version>` releases in the package repository (or `FLX_UPDATE_FLUXAPP_REPO`). File names carry the brand: `fluxapp-<brand>-<version>-macos-universal.dmg`, `.app.tar.gz`, `.app.tar.gz.sig` and `fluxapp-<brand>-<version>-windows-x64-setup.exe`, `-setup.exe.sig`, `.msi` (uploaded by `fluxapp-desktop.yml` in FluxWP/FluxWP). The app asks the `tauri` endpoint and installs signed bundles in the background.

### Site address and country (1.10.1)

Every update check records the site address (scheme + host, plus subdirectory; no query) and the country code, regardless of the usage-data opt-in, like a strictly necessary cookie. The address comes from the request body or WordPress's User-Agent. IPs are never stored. WordPress/PHP versions, time zone and locale still need the opt-in.

### Countries and security (1.10.0)

- **Country lookup:** every check and download is mapped to a country; only the country code and a counter are stored, the IP is never written anywhere or sent to an outside service. Source: `CF-IPCountry` when the request really comes from a Cloudflare IP (the header is ignored otherwise, so it cannot be spoofed), else the local database `data/geo/country.mmdb` (DB-IP IP to Country Lite, CC BY 4.0). The panel downloads it and refreshes it monthly (db-ip.com, falling back to the jsDelivr/npm copy); a MaxMind GeoLite2-Country.mmdb can be uploaded too.
- **Traffic by country (Overview › Countries):** sites, checks, downloads, data, blocked requests and top packages per country; click a row for that country's packages (installed on, downloads).
- **Emergency stop:** `/v1/check`, package and app endpoints return `503` (`"code":"paused"`), keyed sites included. The panel and webhook keep working; `/v1/health` shows `"paused": true`.
- **Turn keyless off:** keyless requests and disabled keys, `latest.zip` and the app endpoints get `401` (`"code":"public_disabled"`). Sites with an active key are not affected; keys issued from the panel are still delivered.
- **Country access:** *Block selected countries* or *Allow only selected countries*. A blocked request gets `403` (`"code":"region_blocked"`). An unknown country passes in block mode and is blocked in allow mode. Sites with an active key are exempt by default.
- Security changes are written to `data/access.log`.

## Endpoints

| | |
|---|---|
| `GET /v1/health` | status |
| `POST /v1/check` | no key: public packages; with a site key: plus allowed private packages. Wrong key: 403 |
| `GET /v1/download/<slug>/<version>.zip` | public package without a key, private package with a site key; cached in `data/zips` |
| `GET /v1/download/<slug>/latest.zip` | latest stable version the caller may see (for website download buttons); public without a key, private still needs a site key unless the package is ticked under GitHub › **Public packages** (e.g. `flx-setup` for the fluxwp.com download button) |
| `GET /v1/app/latest?channel=stable\|beta` | public; latest app version and platform files |
| `GET /v1/app/download/<tag>/<file>` | public; 302 to GitHub's short-lived download URL |
| `GET /v1/fluxapp/<brand>/latest?channel=stable\|beta` | public; latest FluxWP desktop (dmg, setup.exe, msi + update bundle) |
| `GET /v1/fluxapp/<brand>/download/<macos\|windows\|windows-msi>` | public; stable link, 302 to the latest installer (for download buttons) |
| `GET /v1/fluxapp/<brand>/tauri/<target>/<arch>/<version>` | public; Tauri updater format (`darwin` → `.app.tar.gz`, `windows` → `setup.exe`), 204 if there is no newer version |
| `GET /v1/fluxapp/download/<tag>/<file>` | public; 302 to the GitHub download URL |
| `POST /v1/webhook` | signed with `X-Hub-Signature-256`; clears the release cache |
| `GET /admin` | admin panel (`/status` redirects here) |

## Docker / Coolify (optional)

Build pack **Dockerfile**, persistent storage `/var/www/html/data`. Settings can still be entered in /admin; environment variables work too, and panel values override them.

| Variable | Description |
|---|---|
| `FLX_UPDATE_GITHUB_TOKEN` | GitHub token |
| `FLX_UPDATE_REPO` | private repositories, comma separated |
| `FLX_UPDATE_PUBLIC_REPOS` | public repositories, comma separated (empty: no keyless updates) |
| `FLX_UPDATE_APP_REPO` | app repository (tags `v<version>`) |
| `FLX_UPDATE_FLUXAPP_REPO` | FluxWP desktop repository, if not the package repository |
| `FLX_UPDATE_SITES` | JSON: `{"<sha256>":{"name":"…","packages":"*","channel":"stable"}}` (read-only in the panel) |
| `FLX_UPDATE_WEBHOOK_SECRET` | webhook secret |
| `FLX_UPDATE_ADMIN_USER` / `FLX_UPDATE_ADMIN_PASSWORD` | panel login (instead of first setup); `FLX_UPDATE_ADMIN_PASSWORD_HASH` takes a `password_hash()` value |
| `FLX_UPDATE_BASE_URL` | taken from the request if empty |
| `FLX_UPDATE_CACHE_TTL` | release cache in seconds (300) |
| `FLX_UPDATE_DATA_DIR` | default `./data` |
| `FLX_UPDATE_LANG` | panel default language: `en` (default) or `tr` |

An old `config.php` keeps working: its password logs in and its sites show read-only in the panel.

### Nginx

```
location ~ ^/(config\.php|config\.sample\.php|lib/|data/|bin/|\.) { deny all; }
location / { try_files $uri /index.php$is_args$args; }
location = /index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_pass php; }
```

## Troubleshooting

| Symptom | Where to look |
|---|---|
| "Could not resolve host" on the site | Wrong server address, or the subdomain/DNS is not there yet. Official server: `https://updater.fluxwp.com` |
| "Invalid site key" on the site | /admin › *Test an existing key*; add the site again if it was deleted |
| "HTTP 502" on the site | Is the GitHub card red? The token expired or is wrong |
| New version does not show up | Did the package repository's Actions run pass? /admin › *Reload packages* |
| Logs | `data/access.log`, PHP error log |

## Maintainers

FluxWP Update Server is led and maintained by **Ahmet Yılmaz** ([@sharkpaw](https://github.com/sharkpaw)).

<table><tr><td align="center"><a href="https://github.com/sharkpaw"><img src="https://github.com/sharkpaw.png?size=120" width="64" alt="Ahmet Yılmaz"><br><b>Ahmet Yılmaz</b></a><br><sub>Project lead · Maintainer</sub></td></tr></table>

### Contributors

Issues and pull requests are welcome. Contributors will be listed here.
