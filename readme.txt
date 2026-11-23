=== WD Performans Doktoru ===
Contributors: webdanismani
Tags: performance, speed, optimization, database cleanup, woocommerce
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: CC BY-NC-SA 4.0
License URI: https://creativecommons.org/licenses/by-nc-sa/4.0/

WordPress sitenizi en çok yoran sorunları tespit eder, etkisine göre sıralar ve güvenli tek tık çözümler sunar.

== Description ==

**WD Performans Doktoru**, sitenizin neden yavaş olduğunu tahmin etmek yerine ölçer. Sunucu ayarlarından veritabanına, eklentilerin ana sayfaya yüklediği dosyalardan önbellek durumuna kadar yavaşlığın gerçek nedenlerini bulur ve her biri için ne yapmanız gerektiğini Türkçe, sade bir dille anlatır.

Tarama hiçbir ayarı değiştirmez. Çözümleri siz onayladığınızda uygular.

= Neleri kontrol eder? =

**Sunucu ve PHP**
* PHP sürümü, bellek limiti
* OPcache durumu, isabet oranı ve bellek doluluğu
* Kalıcı nesne önbelleği (Redis / Memcached)
* MySQL / MariaDB sürümü, HTTP/2, anlık sunucu yükü

**WordPress**
* Çekirdek ve eklenti güncellemeleri, aktif/pasif eklenti sayısı
* WP_DEBUG, SAVEQUERIES ve büyüyen debug.log
* WP-Cron yapılandırması, gecikmiş görevler ve silinmiş eklentilerden kalan sahipsiz cron görevleri
* Heartbeat API, revizyon sınırı, gereksiz varsayılan betikler

**Veritabanı**
* Otomatik yüklenen ayarların (autoload) boyutu ve en büyük kayıtlar
* Süresi dolmuş geçici veriler, revizyonlar, taslaklar, spam yorumlar
* Sahipsiz meta kayıtları, tablo şişkinliği, MyISAM tablolar

**Sayfa yükü** (ana sayfanıza gerçek istek gönderilerek)
* Sunucu yanıt süresi
* Sayfa önbelleği algılama (LiteSpeed, Cloudflare, Varnish, WP Rocket ve diğerleri)
* Gzip / Brotli sıkıştırma ve tarayıcı önbelleği
* Hangi eklentinin ana sayfaya kaç JS/CSS dosyası yüklediği
* Sayfayı engelleyen betikler, üçüncü taraf alan adları, görsel yükleme düzeni

**WooCommerce** (kuruluysa)
* Action Scheduler kuyruğunda gecikmiş ve birikmiş görevler
* Süresi dolmuş müşteri oturumları
* HPOS (yüksek performanslı sipariş depolama) durumu
* Sepet parçaları (cart fragments) isteği

= Tek tık çözümler =

* Güvenli veritabanı temizliği (büyük tablolarda zaman aşımına düşmeden parça parça çalışır)
* Autoload kayıtlarını kapatma ve geri alma
* Tablo optimizasyonu ve MyISAM → InnoDB dönüşümü
* Sahipsiz cron görevlerini kaldırma
* Emoji, oEmbed, jQuery Migrate, XML-RPC, Dashicons ve Heartbeat ayarları

= Destek =

Sorularınız ve önerileriniz için [oblifex.com](https://oblifex.com) webmaster destek forumunu kullanabilirsiniz. Eklentideki "Destek" sekmesinden forumda paylaşabileceğiniz raporu tek tıkla kopyalayabilirsiniz.

Profesyonel hız optimizasyonu hizmeti için: [webdanismani.com](https://webdanismani.com)

= Lisans =

Eklenti ücretsiz paylaşılır. Satışı, ücretli bir pakete eklenmesi veya ticari amaçla dağıtılması yasaktır. Paylaşırken Web Danışmanı'nı kaynak olarak belirtmeniz ve değiştirilmiş sürümleri aynı lisansla paylaşmanız gerekir (CC BY-NC-SA 4.0).

== Installation ==

1. Eklentiyi Eklentiler › Yeni Ekle › Eklenti Yükle ekranından ZIP olarak yükleyin ve etkinleştirin.
2. Sol menüdeki **Performans** bağlantısına tıklayın.
3. **Siteyi tara** düğmesine basın. Tarama yaklaşık 20 saniye sürer.

== Frequently Asked Questions ==

= Tarama sitemi değiştirir mi? =

Hayır. Tarama yalnızca okuma yapar. Temizlik ve optimizasyon işlemleri yalnızca siz düğmeye bastığınızda çalışır.

= Bir önbellek eklentisinin yerini tutar mı? =

Hayır. Performans Doktoru sorunları teşhis eder ve kolay olanları düzeltir. Sayfa önbelleği için sunucunuza uygun bir önbellek eklentisi önerir.

= Ana sayfa ölçümü yapılamadı uyarısı alıyorum =

Sunucunuz kendi sitesine istek gönderemiyor demektir. Bakım modu, site şifresi veya sunucu IP'sini engelleyen bir güvenlik duvarı olabilir. Bu durum WP-Cron'un çalışmasını da engeller.

= Eklentiyi silersem ne olur? =

Tüm ayarları ve tarama geçmişi silinir; kapattığınız autoload kayıtları otomatik olarak eski haline getirilir.

== Changelog ==

= 1.0.0 =
* İlk sürüm.
