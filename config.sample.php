<?php
/**
 * GEREK YOK: tüm ayarlar /admin panelinden girilir (data/settings.json).
 *
 * Yalnız dosyayla yönetmek isterseniz config.php adıyla kopyalayın. Buradaki dolu
 * değerler ortam değişkenlerini ezer; panelde girilenler de bunları ezer.
 * Buradaki siteler panelde salt okunur görünür. config.php git'e girmez.
 */
return array(
	'base_url'            => '',   // boşsa istekten bulunur, ör. https://updater.fluxwp.com
	'repo'                => '',      // private depolar (virgülle); paketleri yalnız site anahtarıyla
	'public_repos'        => '',   // public depolar (virgülle); paketleri herkese anahtarsız
	'github_token'        => '',   // fine-grained token, private depolar, Contents: Read-only
	'webhook_secret'      => '',   // Actions secret UPDATE_WEBHOOK_SECRET ile aynı
	'admin_user'          => 'admin',
	'admin_password'      => '',   // /admin parolası, en az 10 karakter (ya da admin_password_hash)
	'admin_password_hash' => '',   // php bin/keygen.php --password
	'setup_token'         => '',   // ilk kurulum, en az 16 karakter; boşsa data/.setup-token ve PHP hata günlüğü

	// Anahtar = site anahtarının sha256'sı. Üretmek: /admin › "Site ekle"
	// ya da SSH varsa php bin/keygen.php "Site adı".
	// packages boş ya da yoksa private paket yok; '*' tüm private paketler.
	// channel: 'stable' | 'beta'.
	'sites' => array(
		// 'a1b2…' => array( 'name' => 'Örnek Vakfı', 'packages' => '*', 'channel' => 'stable' ),
	),
);
