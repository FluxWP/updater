# wp-update-server

`update.yesilturkiyeist.org`. Private `FluxWP/fluxwp` reposundaki release'leri
WordPress sitelerine "güncelleme var" olarak sunar. GitHub token yalnız bu sunucuda durur;
siteler kendi anahtarlarıyla bağlanır (Flux Updater eklentisi).

```
FluxWP/fluxwp (push → Actions → <slug>-v<sürüm> release + <slug>.zip)
        │ token (Contents: Read-only)
        ▼
wp-update-server   /admin (panel) · /v1/check · /v1/download/<slug>/<sürüm>.zip · /v1/app/* · /v1/webhook
        ▲ X-FluxWP-Site-Key
WordPress siteleri (Flux Updater)
```

Tüm ayarlar **/admin** panelinden girilir; `config.php` gerekmez.

## Kurulum (Hostinger, zip)

1. hPanel'de `update.yesilturkiyeist.org` subdomain'ini açın (normal PHP sitesi, PHP 7.4+), SSL'i açın.
2. [Releases](../../releases/latest) sayfasından `wp-update-server.zip`'i indirin, Dosya Yöneticisi'nde
   subdomain'in kök klasörüne yükleyip açın.
3. Hemen `https://update.yesilturkiyeist.org/admin` adresini açın › **İlk kurulum**: kullanıcı adı + parola.
   (İlk kurulumu yapan yönetici olur; zip'i açar açmaz yapın.)
4. **GitHub** kartı: token'ı yapıştırın › *Kaydet ve test et* › yeşil **Bağlı** görünmeli.
   Token: GitHub › Settings › Developer settings › Fine-grained tokens › owner `FluxWP`,
   yalnız `FluxWP/fluxwp`, Repository permissions › **Contents: Read-only**.
5. **Site ekle**: ad, paketler (hepsi ya da seçili), kanal › *Anahtar üret*. Anahtar **bir kez** gösterilir;
   kopyalayın, *Bu anahtarı test et* ile deneyin.
6. Sitede: `flx-updater.zip`'i bir kez kurun › **Ayarlar › Flux Updater** › anahtarı yapıştırın
   › *Kaydet ve kontrol et* › yeşil **Bağlı**. Sunucu adresi varsayılan olarak bu sunucudur.
7. (İsteğe bağlı) **Anında yayın** › *Secret üret* › FluxWP/fluxwp › Settings › Secrets › Actions'a
   `UPDATE_WEBHOOK_SECRET` adıyla ekleyin. Yoksa yeni sürüm en geç 5 dakikada görünür.

Sonraki sürümlerde aynı zip'i üstüne açmanız yeterli: ayarlar `data/settings.json`'da, zip'te yok, korunur.

Panelde: GitHub durumu, siteler (bağlı mı, son görülme, kurulu sürümler), site düzenle/sil
(silinen sitenin anahtarı hemen çalışmaz), paket listesi, parola değiştirme.
Sunucuda anahtarın kendisi değil yalnız sha256'sı durur. Anahtarı kaybederseniz siteyi silip yenisini ekleyin.

`data/` yazılabilir olmalı.

## Kontrol

- `https://update.yesilturkiyeist.org/` → `{"ok":true,…,"configured":true}`
- `https://update.yesilturkiyeist.org/data/settings.json` → 403
- `https://update.yesilturkiyeist.org/admin` → giriş sayfası

## Docker / Coolify (isteğe bağlı)

Build Pack: **Dockerfile**, kalıcı depolama `/var/www/html/data`. Ayarlar yine /admin'den girilebilir;
isterseniz ortam değişkeni de olur (panel değerleri bunları ezer):

| Değişken | Açıklama |
|---|---|
| `FLX_UPDATE_GITHUB_TOKEN` | GitHub token |
| `FLX_UPDATE_SITES` | JSON: `{"<sha256>":{"name":"…","packages":"*","channel":"stable"}}` (panelde salt okunur) |
| `FLX_UPDATE_WEBHOOK_SECRET` | webhook secret |
| `FLX_UPDATE_ADMIN_USER` / `FLX_UPDATE_ADMIN_PASSWORD` | panel girişi (ilk kurulum yerine) |
| `FLX_UPDATE_BASE_URL` | boşsa istekten bulunur |
| `FLX_UPDATE_REPO` | varsayılan `FluxWP/fluxwp` |
| `FLX_UPDATE_CACHE_TTL` | release önbelleği, sn (300) |
| `FLX_UPDATE_DATA_DIR` | varsayılan `./data` |

Eski `config.php` varsa çalışmaya devam eder: oradaki parola ile giriş yapılır, oradaki siteler
panelde salt okunur görünür. Panelden girilen değerler `config.php`'yi ezer.

## Uç noktalar

| | |
|---|---|
| `GET /v1/health` | durum |
| `POST /v1/check` | site anahtarıyla; izinli paketlerin son sürümleri |
| `GET /v1/download/<slug>/<sürüm>.zip` | site anahtarıyla; zip'i GitHub'dan bir kez alıp `data/zips`'te saklar |
| `GET /v1/app/latest?channel=stable\|beta` | herkese açık; uygulamanın son sürümü ve platform dosyaları |
| `GET /v1/app/download/<tag>/<dosya>` | herkese açık; GitHub'ın kısa ömürlü indirme adresine 302 (dosya bu sunucuda tutulmaz) |
| `GET /v1/fluxapp/<marka>/latest?channel=stable\|beta` | herkese açık; FluxWP masaüstü son sürümü (dmg + güncelleme paketi) |
| `GET /v1/fluxapp/<marka>/tauri/<target>/<arch>/<sürüm>` | herkese açık; Tauri updater biçimi (imzalı `.app.tar.gz`), yeni sürüm yoksa 204 |
| `GET /v1/fluxapp/download/<tag>/<dosya>` | herkese açık; GitHub indirme adresine 302 |
| `POST /v1/webhook` | `X-Hub-Signature-256` imzalı; release önbelleğini boşaltır |
| `GET /admin` | yönetim paneli (`/status` buraya yönlenir) |

### Dosya saklama (1.6.0)

`data/zips` sınırlıdır: paket başına son 5 sürümün zip'i kalır. Daha eski bir sürüm istenirse GitHub release'ten yeniden indirilir ve son indirmeden 24 saat sonra silinir. Temizlik her indirmeden sonra o paket için, saatte bir de tüm paketler için kendiliğinden yapılır; /admin › Dosyalar'dan sayılar değiştirilir ve "Şimdi temizle" ile hemen çalışır. GitHub okunamazsa hiçbir dosya silinmez.

### Gerekli eklentiler (1.5.0)

`/v1/check` her paket için `requires_plugins` da döndürür (paketin `Requires Plugins:` başlığı, release notundaki
`flx-meta` satırından). Flux Updater 1.3.0 bununla kurmadan önce eksik eklentileri gösterir ve birlikte kurar.
1.5.0'a geçerken yalnız `lib/server.php` değişir.

### Uygulama güncellemeleri (1.3.0)

Masaüstü/mobil uygulama `FluxWP/app` deposunun `v<sürüm>` release'lerinden güncellenir (panel › GitHub › Uygulama deposu). Depo private olduğundan token'ın bu depoyu da okuyabilmesi gerekir (fine-grained token › Repository access'e yt-app'i ekleyin). Dosya adı platformu belirler: `*windows*`, `*macos*`, `*.apk` (Android), `*ios*`. `-` içeren sürüm (ör. `0.3.0-beta.1`) yalnız beta kanalında görünür.

### FluxWP masaüstü güncellemeleri (1.4.0)

FluxWP, paket deposundaki (`repo`, ayrı depo için `FLX_UPDATE_FLUXAPP_REPO`) `fluxapp-v<sürüm>` release'lerinden
güncellenir. Dosya adları markayı taşır: `fluxapp-<marka>-<sürüm>-macos-universal.dmg`, `.app.tar.gz`, `.app.tar.gz.sig`
(FluxWP/fluxwp `fluxapp-desktop.yml` böyle yükler). Uygulama `tauri` ucunu sorar; imzalı paket varsa arka planda
indirip yeniden başlatınca kurar, yoksa `latest` ucundaki dmg adresini açar. Sürümünde `-` olan (beta) yalnız beta kanalında.
Ek ayar gerekmez; mevcut token paket deposunu zaten okuyor.

## Nginx

```
location ~ ^/(config\.php|config\.sample\.php|lib/|data/|bin/|\.) { deny all; }
location / { try_files $uri /index.php$is_args$args; }
location = /index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_pass php; }
```

## Sorun giderme

| Belirti | Bakılacak yer |
|---|---|
| Sitede "Could not resolve host" | Sunucu adresi yanlış ya da subdomain/DNS henüz yok. Adres: `https://update.yesilturkiyeist.org` |
| Sitede "Site anahtarı geçersiz" | /admin › *Var olan bir anahtarı test et*; site silindiyse yeniden ekleyin |
| Sitede "HTTP 502" | /admin › GitHub kartı kırmızı mı: token süresi dolmuş/yanlış |
| Yeni sürüm görünmüyor | FluxWP/fluxwp Actions işi yeşil mi; /admin › *Paketleri yeniden oku* |
| Kayıtlar | `data/access.log`, PHP hata günlüğü |
