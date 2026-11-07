<div align="center">

[English](README.md) · **Türkçe**

# FluxWP Update Server

GitHub release'lerini WordPress güncellemesine çeviren küçük bir PHP sunucusu. **FluxWP Panel** (flx-setup) kurulu siteler private ve public tema ve eklentilerinizi WordPress'in normal **Başlangıç › Güncellemeler** ekranında görür.

![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white) ![Veritabanı yok](https://img.shields.io/badge/veritaban%C4%B1-yok-2fbf8f) ![Paylaşımlı hosting](https://img.shields.io/badge/payla%C5%9F%C4%B1ml%C4%B1-hosting-21759b) ![Docker](https://img.shields.io/badge/Docker-iste%C4%9Fe%20ba%C4%9Fl%C4%B1-2496ed?logo=docker&logoColor=white)

</div>

FluxWP'nin resmi sunucusu `https://updater.fluxwp.com`. Aynı kodla kendi sunucunuzu kurabilirsiniz.

- **Public depolar**: paketler herkese **anahtarsız** sunulur.
- **Private depolar**: paketler yalnız **site anahtarıyla**, anahtara tanımlı paketler kadar görünür.

Varsayılan depo yoktur; depolar /admin › GitHub kartından seçilir. Yalnız Public'e eklenen depo anahtarsız dağıtılır. GitHub token yalnız bu sunucuda durur, sitelere gitmez.

```
GitHub depoları (push → Actions → <slug>-v<sürüm> release + <slug>.zip)
        │ token (private depolar, Contents: Read-only)
        ▼
update server   /admin (panel) · /v1/check · /v1/download/<slug>/<sürüm>.zip · /v1/app/* · /v1/webhook
        ▲ anahtarsız: public paketler · X-FluxWP-Site-Key: + anahtarın private paketleri
WordPress siteleri (FluxWP Panel)
```

Tüm ayarlar **/admin** panelinden girilir; `config.php` gerekmez.

## Kurulum (paylaşımlı hosting, zip)

1. Bir subdomain açın (ör. `updater.example.com`, normal PHP 7.4+ sitesi) ve SSL'i açın.
2. [Releases](../../releases/latest) sayfasından `flx-update-server.zip`'i indirip subdomain'in kök klasörüne açın.
3. Hemen `https://<subdomain>/admin` adresinde **İlk kurulum**'u yapın (kullanıcı adı + parola). İlk yapan yönetici olur.
4. **GitHub** kartı: private ve public depoları seçin, token'ı yapıştırın, *Kaydet ve test et*. **Bağlı** görünmeli.
   Token: GitHub › Settings › Developer settings › Fine-grained tokens, yalnız private depolar, **Contents: Read-only**. Yalnız public depo sunacaksanız token gerekmez.
5. **Site ekle**: ad, private paketler (hepsi ya da seçili), kanal, *Anahtar üret*. Anahtar **bir kez** gösterilir; kopyalayıp *Bu anahtarı test et* ile deneyin.
6. Sitede: `flx-setup.zip`'i bir kez kurun, **Setup › Ayarlar**'da anahtarı yapıştırıp *Kaydet ve kontrol et*. Eklentinin varsayılan sunucusu `https://updater.fluxwp.com`; kendi sunucunuz varsa adresini yazın. Anahtarsız site yalnız public paketleri görür.
7. İsteğe bağlı, **anında yayın**: *Secret üret*, sonra paket deposunda Settings › Secrets › Actions'a `UPDATE_WEBHOOK_SECRET` adıyla ekleyin. Yoksa yeni sürüm en geç 5 dakikada görünür.

Güncellemek için yeni zip'i üstüne açın. Ayarlar zip'te olmayan `data/settings.json`'da durur. `data/` yazılabilir olmalı.

### Kontrol

- `https://<sunucu>/` → `{"ok":true,…,"configured":true,"public":true}`
- `https://<sunucu>/data/settings.json` → 403
- `https://<sunucu>/admin` → giriş sayfası

## Özellikler

### Site anahtarları

- Sunucuda anahtarın yalnız sha256'sı durur. Kaybedilen anahtar geri alınamaz; siteyi silip yeniden ekleyin.
- **Bağlı siteye anahtar ver:** Genel bakış › Bağlanan siteler'de anahtarsız bağlanan sitenin satırında "Anahtar ver" var. Site anahtarı bir sonraki kontrolde (ya da FluxWP Panel'de "Bağlantıyı test et" ile hemen) alır. FluxWP Panel 1.7.0+ gerekir: eklenti yalnız bu sunucuya rastgele bir kurulum kimliği gönderir, anahtar yalnız o kimliği taşıyan isteğe teslim edilir; site adresini taklit eden alamaz. 7 gün içinde alınmayan anahtarın süresi dolar.
- **Aktif/pasif:** pasif anahtarlı site tanınır ama yalnız public paketleri görür; sitede "anahtar sunucuda pasif" yazar.
- **Kapsam:** "Tüm private paketler" ya da seçilen depolar ve paketler. Bir depoda "tümü" işaretlenirse sonradan eklenen paketler de girer.

### Panel

- **Genel bakış:** son 7/30/90 günde güncelleme denetleyen siteler (anahtarlı ve anahtarsız), kontrol, indirme, aktarılan veri, günlük grafik, paket başına kurulum, ülke haritası ve bağlanan siteler. `data/stats.json`'da durur (günlük toplamlar 120 gün, siteler son görülmeden 180 gün). IP saklanmaz; ülke istek anında IP'den bulunur (aşağıda *Ülke ve güvenlik*).
- **Kaynaklar:** disk, `data/` dağılımı, GitHub API kotası, paket önbelleğinin yaşı, PHP sınırları. Eski zipler ve erişim günlüğü buradan temizlenir.
- **Depo seçici:** private, public ve uygulama depoları, token'ın gördüğü listeden etiket olarak seçilir. Listede olmayan `owner/repo` yazılıp Enter ile eklenir.
- **Dil:** İngilizce (varsayılan) ve Türkçe; üstteki EN/TR ile değişir.

### Paketler

- Public paketler anahtarsız sunulur, beta sürümleri hariç. Anahtar varsa aynı yanıta anahtarın private paketleri eklenir.
- Bir depo okunamazsa paketleri son bilinen listeden gelir, GitHub kartında "Okunamadı" çıkar.
- `/v1/check` her paket için `requires_plugins` döndürür (`Requires Plugins:` başlığı); FluxWP Panel eksik eklentileri birlikte kurar.
- Sürümünde `-` olan (ör. `1.3.0-beta.1`) yalnız beta kanalında görünür.

### Kurulum sihirbazı (1.11.0)

FluxWP Panel'in kurulum sihirbazındaki eklenti listesini sunucu belirler. `/v1/check` kategorileri (anahtar, sıra, TR/EN ad ve açıklama) ve her paket için `category`, `order`, `rec` (önerilen), kısa `desc` (TR/EN) ve `icons` (`svg`, `pastel`) döndürür. /admin › Paketler ve /admin › Kategoriler'den düzenlenir; kaydedilene kadar yerleşik varsayılanlar kullanılır. Private paketler sihirbazda hiç görünmez. İkonlar paketin kendi zip'inden (`assets/icon.svg`, `assets/icon-pastel.svg`) okunur, imzalı adresten verilir.

### Zip saklama

`data/zips` paket başına son 5 sürümü tutar. Daha eski sürüm istenirse GitHub release'ten yeniden alınır ve son indirmeden 24 saat sonra silinir. Temizlik her indirmeden sonra ve saatte bir çalışır; sınırlar /admin › Dosyalar'da. GitHub okunamazken hiçbir dosya silinmez.

### Uygulama güncellemeleri

- **Mobil/masaüstü uygulama:** uygulama deposundaki `v<sürüm>` release'leri (panel › GitHub › Uygulama deposu). Dosya adı platformu belirler: `*windows*`, `*macos*`, `*.apk`, `*ios*`.
- **FluxWP masaüstü:** paket deposundaki (ya da `FLX_UPDATE_FLUXAPP_REPO`) `fluxapp-v<sürüm>` release'leri. Dosya adı markayı taşır: `fluxapp-<marka>-<sürüm>-macos-universal.dmg`, `.app.tar.gz`, `.app.tar.gz.sig` ve `fluxapp-<marka>-<sürüm>-windows-x64-setup.exe`, `-setup.exe.sig`, `.msi` (FluxWP/FluxWP `fluxapp-desktop.yml` yükler). Uygulama `tauri` ucunu sorar, imzalı paketi arka planda kurar.

### Gizlilik: site ne gönderir

Her `/v1/check` isteğinde kurulu FluxWP paketleri ve sürümleri, FluxWP Panel sürümü, rastgele kurulum kimliği (sunucu başına bir tane, siteden türetilmez) ve varsa site anahtarı gider. Güncelleme için gereken bu kadar.

FluxWP Panel 1.0.8+ site adresini, WordPress ve PHP sürümünü, saat dilimini ve dili yalnız yönetici izin verdiyse ekler (kurulum sihirbazında ya da FluxWP Panel › Ayarlar'da "Ürünü geliştirmemize yardımcı olun"); izin yoksa `"usage": false` gönderir. İzin yokken sunucu bunların hiçbirini saklamaz ve siteyi kurulum kimliğiyle tanır. Ülke kodu izinden bağımsız olarak isteğin IP'sinden çıkarılır (IP saklanmaz). İzni geri alan sitenin adresli eski kaydı bir sonraki denetimde birleştirilip silinir. `usage` alanı göndermeyen eski istemciler izinli sayılır.

IP adresi istatistiklere yazılmaz. `data/access.log` IP'yi yalnız hatalı anahtar denemelerinde (güvenlik) tutar, 90 günden eski satırları siler. Günlük sayaçlar 120 gün, site kayıtları son denetimden sonra 180 gün saklanır.

### Site adresi ve ülke (1.10.2)

Her güncelleme denetimi, kullanım verisi izninden bağımsız olarak site adresini (şema + host, varsa alt dizin; sorgu yok) ve ülke kodunu kaydeder; zorunlu çerez gibi. Adres istek gövdesinden ya da WordPress User-Agent'ından alınır. IP hiç saklanmaz. WordPress/PHP sürümü, saat dilimi ve dil yine izin ister.

### Ülke ve güvenlik (1.10.0)

- **Ülke tespiti:** her kontrol ve indirmede isteğin ülkesi bulunur, yalnız ülke kodu ve sayaç saklanır; IP hiçbir yere yazılmaz ve harici servise gönderilmez. Kaynak: istek gerçekten Cloudflare IP'sinden geliyorsa `CF-IPCountry` (başka yerden gelen sahte başlık yok sayılır), değilse sunucudaki ülke veritabanı `data/geo/country.mmdb` (DB-IP IP to Country Lite, CC BY 4.0). Veritabanı panelden indirilir ve ayda bir kendiliğinden yenilenir (db-ip.com, olmazsa jsDelivr/npm kopyası); MaxMind GeoLite2-Country.mmdb de elle yüklenebilir.
- **Ülke trafiği (Genel bakış › Ülkeler):** ülke başına site, kontrol, indirme, veri, engellenen istek ve en çok inen paketler; satıra tıklayınca o ülkedeki paketler (kurulu site, indirme).
- **Acil durdurma:** tüm `/v1/check`, paket ve uygulama uçları `503` (`"code":"paused"`) döner, anahtarlı siteler dahil. Panel ve webhook çalışır. `/v1/health` `"paused": true` gösterir.
- **Anahtarsız dağıtımı kapat:** anahtarsız ve pasif anahtarlı istekler, `latest.zip` ve uygulama uçları `401` (`"code":"public_disabled"`) alır; aktif anahtarlı siteler etkilenmez, panelden verilen anahtarın teslimi sürer.
- **Ülke erişimi:** *Seçilen ülkeleri engelle* ya da *Yalnız seçilen ülkelere izin ver*. Engellenen istek `403` (`"code":"region_blocked"`) alır. Ülkesi bulunamayan istek engelle kipinde geçer, izin kipinde engellenir. Varsayılan olarak aktif anahtarlı siteler muaftır.
- Güvenlik değişiklikleri `data/access.log`'a yazılır.

## Uç noktalar

| | |
|---|---|
| `GET /v1/health` | durum |
| `POST /v1/check` | anahtarsız: public paketler; site anahtarıyla: + izinli private paketler. Yanlış anahtar 403 |
| `GET /v1/download/<slug>/<sürüm>.zip` | public paket anahtarsız, private paket site anahtarıyla; `data/zips`'te saklanır |
| `GET /v1/app/latest?channel=stable\|beta` | herkese açık; uygulamanın son sürümü ve platform dosyaları |
| `GET /v1/app/download/<tag>/<dosya>` | herkese açık; GitHub'ın kısa ömürlü indirme adresine 302 |
| `GET /v1/fluxapp/<marka>/latest?channel=stable\|beta` | herkese açık; FluxWP masaüstü son sürümü (dmg, setup.exe, msi + güncelleme paketi) |
| `GET /v1/fluxapp/<marka>/download/<macos\|windows\|windows-msi>` | herkese açık; kalıcı bağlantı, son kurulum dosyasına 302 (indirme düğmeleri için) |
| `GET /v1/fluxapp/<marka>/tauri/<target>/<arch>/<sürüm>` | herkese açık; Tauri updater biçimi (`darwin` → `.app.tar.gz`, `windows` → `setup.exe`), yeni sürüm yoksa 204 |
| `GET /v1/fluxapp/download/<tag>/<dosya>` | herkese açık; GitHub indirme adresine 302 |
| `GET /v1/icon/<slug>/<sürüm>/<default\|pastel>.svg?t=` | paketin release zip'indeki ikon; imzalı adres `/v1/check`'ten gelir |
| `POST /v1/webhook` | `X-Hub-Signature-256` imzalı; release önbelleğini boşaltır |
| `GET /admin` | yönetim paneli (`/status` buraya yönlenir) |

## Docker / Coolify (isteğe bağlı)

Build pack **Dockerfile**, kalıcı depolama `/var/www/html/data`. Ayarlar yine /admin'den girilir; ortam değişkenleri de olur, panel değerleri onları ezer.

| Değişken | Açıklama |
|---|---|
| `FLX_UPDATE_GITHUB_TOKEN` | GitHub token |
| `FLX_UPDATE_REPO` | private depolar, virgülle |
| `FLX_UPDATE_PUBLIC_REPOS` | public depolar, virgülle (boşsa anahtarsız güncelleme kapalı) |
| `FLX_UPDATE_APP_REPO` | uygulama deposu (tag `v<sürüm>`) |
| `FLX_UPDATE_FLUXAPP_REPO` | FluxWP masaüstü deposu, paket deposundan ayrıysa |
| `FLX_UPDATE_SITES` | JSON: `{"<sha256>":{"name":"…","packages":"*","channel":"stable"}}` (panelde salt okunur) |
| `FLX_UPDATE_WEBHOOK_SECRET` | webhook secret |
| `FLX_UPDATE_ADMIN_USER` / `FLX_UPDATE_ADMIN_PASSWORD` | panel girişi (ilk kurulum yerine); `FLX_UPDATE_ADMIN_PASSWORD_HASH` `password_hash()` çıktısı alır |
| `FLX_UPDATE_BASE_URL` | boşsa istekten bulunur |
| `FLX_UPDATE_CACHE_TTL` | release önbelleği, sn (300) |
| `FLX_UPDATE_DATA_DIR` | varsayılan `./data` |
| `FLX_UPDATE_LANG` | panelin varsayılan dili: `en` (varsayılan) ya da `tr` |

Eski `config.php` çalışmaya devam eder: parolasıyla giriş yapılır, siteleri panelde salt okunur görünür.

### Nginx

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

## Geliştirici

FluxWP Update Server, **Ahmet Yılmaz** ([@sharkpaw](https://github.com/sharkpaw)) tarafından yürütülmekte ve geliştirilmektedir.

<table><tr><td align="center"><a href="https://github.com/sharkpaw"><img src="https://github.com/sharkpaw.png?size=120" width="64" alt="Ahmet Yılmaz"><br><b>Ahmet Yılmaz</b></a><br><sub>Proje lideri · Maintainer</sub></td></tr></table>

### Katkıda bulunanlar

Issue ve pull request'ler açıktır. Katkıda bulunanlar burada listelenecek.

### FluxWP masaüstü kaynağı (1.10.1)

/admin › GitHub içindeki **FluxWP masaüstü deposu**, `fluxapp-v<sürüm>` yayınlarını okur. Resmî uygulama için `FluxWP/FluxWP` seçin. Bu alan, `v<sürüm>` etiketli genel uygulama deposundan ve WordPress paket kaynaklarından ayrıdır. Boş bırakılınca eski ilk-private-depo davranışı korunur.
