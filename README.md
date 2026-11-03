# FluxWP Update Server

GitHub release'lerini WordPress sitelerine "güncelleme var" olarak sunan, kendi sunucunuzda çalışan
güncelleme sunucusu. FluxWP'nin resmi sunucusu `https://updater.fluxwp.com`; aynı kodla kendi sunucunuzu da kurabilirsiniz.

- **Public depolar** (varsayılan `FluxWP/wp-extras`): paketleri herkese **anahtarsız** sunulur.
- **Private depolar** (varsayılan `FluxWP/fluxwp`): paketleri yalnız **site anahtarıyla**, anahtara tanımlı paketler kadar görünür.

GitHub token yalnız bu sunucuda durur; siteler Flux Updater eklentisiyle bağlanır.

```
GitHub depoları (push → Actions → <slug>-v<sürüm> release + <slug>.zip)
        │ token (private depolar, Contents: Read-only)
        ▼
update server   /admin (panel) · /v1/check · /v1/download/<slug>/<sürüm>.zip · /v1/app/* · /v1/webhook
        ▲ anahtarsız: public paketler · X-FluxWP-Site-Key: + anahtarın private paketleri
WordPress siteleri (Flux Updater)
```

Tüm ayarlar **/admin** panelinden girilir; `config.php` gerekmez.

## Kurulum (Hostinger, zip)

1. hPanel'de bir subdomain açın (ör. `updater.example.com` (normal PHP sitesi, PHP 7.4+), SSL'i açın.
2. [Releases](../../releases/latest) sayfasından `wp-update-server.zip`'i indirin, Dosya Yöneticisi'nde
   subdomain'in kök klasörüne yükleyip açın.
3. Hemen `https://<subdomain>/admin` adresini açın › **İlk kurulum**: kullanıcı adı + parola.
   (İlk kurulumu yapan yönetici olur; zip'i açar açmaz yapın.)
4. **GitHub** kartı: private ve public depoları yazın, token'ı yapıştırın › *Kaydet ve test et* › **Bağlı** görünmeli.
   Token: GitHub › Settings › Developer settings › Fine-grained tokens › yalnız private depolar,
   Repository permissions › **Contents: Read-only**. Yalnız public depo sunacaksanız token gerekmez.
5. **Site ekle**: ad, private paketler (hepsi ya da seçili), kanal › *Anahtar üret*. Anahtar **bir kez** gösterilir;
   kopyalayın, *Bu anahtarı test et* ile deneyin.
6. Sitede: `flx-updater.zip`'i bir kez kurun › **Ayarlar › Flux Updater** › anahtarı yapıştırın
   › *Kaydet ve kontrol et* › **Bağlı**. Eklentinin varsayılan sunucusu `https://updater.fluxwp.com`; kendi sunucunuzu
   kullanıyorsanız adresini yazın. Anahtarsız site yalnız public paketleri görür.
7. (İsteğe bağlı) **Anında yayın** › *Secret üret* › paket deposu › Settings › Secrets › Actions'a
   `UPDATE_WEBHOOK_SECRET` adıyla ekleyin. Yoksa yeni sürüm en geç 5 dakikada görünür.

Sonraki sürümlerde aynı zip'i üstüne açmanız yeterli: ayarlar `data/settings.json`'da, zip'te yok, korunur.

Panelde: GitHub durumu, siteler (bağlı mı, son görülme, kurulu sürümler), site düzenle/sil
(silinen sitenin anahtarı hemen çalışmaz), paket listesi, parola değiştirme.
Sunucuda anahtarın kendisi değil yalnız sha256'sı durur. Anahtarı kaybederseniz siteyi silip yenisini ekleyin.

`data/` yazılabilir olmalı.

## Kontrol

- `https://<sunucu>/` → `{"ok":true,…,"configured":true,"public":true}`
- `https://<sunucu>/data/settings.json` → 403
- `https://<sunucu>/admin` → giriş sayfası

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
| `FLX_UPDATE_REPO` | private depolar, virgülle (varsayılan `FluxWP/fluxwp`) |
| `FLX_UPDATE_PUBLIC_REPOS` | public depolar, virgülle (varsayılan `FluxWP/wp-extras`) |
| `FLX_UPDATE_CACHE_TTL` | release önbelleği, sn (300) |
| `FLX_UPDATE_DATA_DIR` | varsayılan `./data` |

Eski `config.php` varsa çalışmaya devam eder: oradaki parola ile giriş yapılır, oradaki siteler
panelde salt okunur görünür. Panelden girilen değerler `config.php`'yi ezer.

## Uç noktalar

| | |
|---|---|
| `GET /v1/health` | durum |
| `POST /v1/check` | anahtarsız: public paketler; site anahtarıyla: + izinli private paketler. Yanlış anahtar 403 |
| `GET /v1/download/<slug>/<sürüm>.zip` | public paket anahtarsız, private paket site anahtarıyla; zip'i GitHub'dan bir kez alıp `data/zips`'te saklar |
| `GET /v1/app/latest?channel=stable\|beta` | herkese açık; uygulamanın son sürümü ve platform dosyaları |
| `GET /v1/app/download/<tag>/<dosya>` | herkese açık; GitHub'ın kısa ömürlü indirme adresine 302 (dosya bu sunucuda tutulmaz) |
| `GET /v1/fluxapp/<marka>/latest?channel=stable\|beta` | herkese açık; FluxWP masaüstü son sürümü (dmg + güncelleme paketi) |
| `GET /v1/fluxapp/<marka>/tauri/<target>/<arch>/<sürüm>` | herkese açık; Tauri updater biçimi (imzalı `.app.tar.gz`), yeni sürüm yoksa 204 |
| `GET /v1/fluxapp/download/<tag>/<dosya>` | herkese açık; GitHub indirme adresine 302 |
| `POST /v1/webhook` | `X-Hub-Signature-256` imzalı; release önbelleğini boşaltır |
| `GET /admin` | yönetim paneli (`/status` buraya yönlenir) |

### Public paketler (1.7.0)

Sunucu artık anahtarsız sitelere de cevap verir: public depolardaki (varsayılan `FluxWP/wp-extras`) paketler herkese
açıktır, beta sürümleri hariç. Site anahtarı girilmişse aynı yanıta anahtara tanımlı private paketler eklenir.
Panelde "Private paketler" seçimi yalnız private depoları kapsar. Public depo listesi boşaltılırsa sunucu eskisi gibi
yalnız anahtarla çalışır. Bir depo okunamazsa yalnız o deponun paketleri son bilinen listeden gelir; GitHub kartında
"Okunamadı" satırı çıkar. Site anahtarı `X-FluxWP-Site-Key` (eski `X-Flx-Site-Key` da olur) ya da `Authorization: Bearer` ile gelir.

### Dosya saklama (1.6.0)

`data/zips` sınırlıdır: paket başına son 5 sürümün zip'i kalır. Daha eski bir sürüm istenirse GitHub release'ten yeniden indirilir ve son indirmeden 24 saat sonra silinir. Temizlik her indirmeden sonra o paket için, saatte bir de tüm paketler için kendiliğinden yapılır; /admin › Dosyalar'dan sayılar değiştirilir ve "Şimdi temizle" ile hemen çalışır. GitHub okunamazsa hiçbir dosya silinmez.

### Gerekli eklentiler (1.5.0)

`/v1/check` her paket için `requires_plugins` da döndürür (paketin `Requires Plugins:` başlığı, release notundaki
`flx-meta` satırından). Flux Updater 1.3.0 bununla kurmadan önce eksik eklentileri gösterir ve birlikte kurar.
1.5.0'a geçerken yalnız `lib/server.php` değişir.

### Uygulama güncellemeleri (1.3.0)

Masaüstü/mobil uygulama `FluxWP/app` deposunun `v<sürüm>` release'lerinden güncellenir (panel › GitHub › Uygulama deposu). Depo private olduğundan token'ın bu depoyu da okuyabilmesi gerekir (fine-grained token › Repository access'e uygulama deposunu ekleyin). Dosya adı platformu belirler: `*windows*`, `*macos*`, `*.apk` (Android), `*ios*`. `-` içeren sürüm (ör. `0.3.0-beta.1`) yalnız beta kanalında görünür.

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
| Sitede "Could not resolve host" | Sunucu adresi yanlış ya da subdomain/DNS henüz yok. Resmi sunucu: `https://updater.fluxwp.com` |
| Sitede "Site anahtarı geçersiz" | /admin › *Var olan bir anahtarı test et*; site silindiyse yeniden ekleyin |
| Sitede "HTTP 502" | /admin › GitHub kartı kırmızı mı: token süresi dolmuş/yanlış |
| Yeni sürüm görünmüyor | Paket deposunun Actions işi yeşil mi; /admin › *Paketleri yeniden oku* |
| Kayıtlar | `data/access.log`, PHP hata günlüğü |
