<?php
/**
 * Tanı motoru: siteyi yoran sorunları tespit eder.
 *
 * Her kontrol şu yapıda döner:
 * id, title, status (critical|warning|good|info), value, impact (high|medium|low),
 * why (neden önemli), solution (ne yapmalı), fix (tek tık çözüm), details (tablo), code (kopyalanabilir kod).
 *
 * @package WD_Performans_Doktoru
 */

defined( 'ABSPATH' ) || exit;

class WDPD_Checks {

	const SCAN_OPTION    = 'wdpd_last_scan';
	const HISTORY_OPTION = 'wdpd_history';

	/** @var array|null Ana sayfa ölçüm önbelleği */
	private static $home = null;

	public static function categories() {
		$cats = array(
			'server'      => array( 'label' => 'Sunucu & PHP', 'icon' => 'server', 'desc' => 'PHP sürümü, bellek, OPcache, nesne önbelleği' ),
			'wordpress'   => array( 'label' => 'WordPress', 'icon' => 'wp', 'desc' => 'Eklentiler, cron, hata ayıklama, güncellemeler' ),
			'database'    => array( 'label' => 'Veritabanı', 'icon' => 'db', 'desc' => 'Autoload, revizyonlar, geçici veriler, tablolar' ),
			'frontend'    => array( 'label' => 'Sayfa Yükü', 'icon' => 'gauge', 'desc' => 'Yanıt süresi, önbellek, sıkıştırma, dosya sayısı' ),
			'woocommerce' => array( 'label' => 'WooCommerce', 'icon' => 'cart', 'desc' => 'Arka plan kuyruğu, oturumlar, sipariş depolama' ),
		);
		if ( ! self::wc_active() ) {
			unset( $cats['woocommerce'] );
		}
		return $cats;
	}

	public static function wc_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Bir kategoriyi çalıştırır.
	 */
	public static function run( $cat ) {
		$cats = self::categories();
		if ( ! isset( $cats[ $cat ] ) ) {
			return array();
		}
		WDPD_Util::extend_time( 90 );
		$items = call_user_func( array( __CLASS__, 'check_' . $cat ) );
		foreach ( $items as &$it ) {
			$it['category'] = $cat;
		}
		return $items;
	}

	private static function item( $id, $title, $status, $value, $impact, $why, $solution, $extra = array() ) {
		return array_merge(
			array(
				'id'       => $id,
				'title'    => $title,
				'status'   => $status,
				'value'    => $value,
				'impact'   => $impact,
				'why'      => $why,
				'solution' => $solution,
				'fix'      => null,
				'details'  => null,
				'code'     => null,
			),
			$extra
		);
	}

	private static function fix_action( $action, $label, $confirm = '' ) {
		return array( 'type' => 'action', 'action' => $action, 'label' => $label, 'confirm' => $confirm );
	}

	private static function fix_tab( $tab, $label ) {
		return array( 'type' => 'tab', 'tab' => $tab, 'label' => $label );
	}

	private static function fix_link( $url, $label ) {
		return array( 'type' => 'link', 'url' => $url, 'label' => $label );
	}

	/* ------------------------------------------------------------------
	 * SUNUCU
	 * ------------------------------------------------------------------ */

	private static function check_server() {
		global $wpdb;
		$out = array();

		// PHP sürümü.
		$v = PHP_VERSION;
		if ( version_compare( $v, '7.4', '<' ) ) {
			$st = 'critical';
		} elseif ( version_compare( $v, '8.2', '<' ) ) {
			$st = 'warning';
		} else {
			$st = 'good';
		}
		$out[] = self::item(
			'php_version',
			'PHP sürümü',
			$st,
			$v,
			'high',
			'PHP, WordPress\'i çalıştıran motordur. Her yeni ana sürüm aynı kodu daha hızlı ve daha az bellekle çalıştırır. Desteği biten sürümler ayrıca güvenlik güncellemesi almaz.',
			'good' === $st
				? 'Güncel bir PHP sürümü kullanıyorsunuz. Yeni sürümler çıktıkça yükseltmeyi alışkanlık haline getirin.'
				: 'Hosting panelinizden (cPanel › PHP Seçici, Plesk › PHP Ayarları, aaPanel › Site › PHP) PHP 8.3 veya üstüne geçin. Geçişten önce yedek alın, ardından siteyi ve eklentileri kontrol edin.'
		);

		// Bellek limiti.
		$raw   = (string) ini_get( 'memory_limit' );
		$bytes = ( '-1' === $raw ) ? -1 : wp_convert_hr_to_bytes( $raw );
		$wpmem = defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '—';
		if ( -1 === $bytes ) {
			$st = 'good';
		} elseif ( $bytes < 128 * MB_IN_BYTES ) {
			$st = 'critical';
		} elseif ( $bytes < 256 * MB_IN_BYTES ) {
			$st = 'warning';
		} else {
			$st = 'good';
		}
		$out[] = self::item(
			'memory_limit',
			'PHP bellek limiti',
			$st,
			( -1 === $bytes ? 'Sınırsız' : WDPD_Util::bytes( $bytes, 0 ) ) . ' · WP: ' . $wpmem,
			'critical' === $st ? 'high' : 'medium',
			'Bellek yetersizse ağır sayfalar (sepet, ödeme, sayfa oluşturucular) yavaşlar veya "Allowed memory size exhausted" hatasıyla beyaz ekran verir.',
			'good' === $st
				? 'Bellek limiti yeterli.'
				: 'Hosting panelinden PHP memory_limit değerini en az 256M yapın. Ardından wp-config.php dosyasına aşağıdaki satırı "That\'s all, stop editing!" satırının üstüne ekleyin.',
			array( 'code' => 'good' === $st ? null : "define( 'WP_MEMORY_LIMIT', '256M' );" )
		);

		// OPcache.
		$op_ini = filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );
		$op     = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_array( $op ) && ! empty( $op['opcache_enabled'] ) ) {
			$mem   = isset( $op['memory_usage'] ) ? $op['memory_usage'] : array();
			$used  = isset( $mem['used_memory'] ) ? $mem['used_memory'] : 0;
			$free  = isset( $mem['free_memory'] ) ? $mem['free_memory'] : 0;
			$waste = isset( $mem['wasted_memory'] ) ? $mem['wasted_memory'] : 0;
			$total = $used + $free + $waste;
			$fpct  = $total ? ( $free / $total ) * 100 : 100;
			$hit   = isset( $op['opcache_statistics']['opcache_hit_rate'] ) ? (float) $op['opcache_statistics']['opcache_hit_rate'] : 100;
			$st    = ( $fpct < 5 || $hit < 90 ) ? 'warning' : 'good';
			$out[] = self::item(
				'opcache',
				'OPcache (PHP kod önbelleği)',
				$st,
				'Açık · isabet %' . number_format( $hit, 1, ',', '' ) . ' · boş bellek %' . number_format( $fpct, 0 ),
				'medium',
				'OPcache, PHP dosyalarını derlenmiş halde bellekte tutar; böylece her istekte yüzlerce dosya yeniden derlenmez. Belleği dolarsa önbellek sürekli boşaltılır ve kazanç kaybolur.',
				'good' === $st
					? 'OPcache sağlıklı çalışıyor.'
					: 'OPcache belleği dolmuş veya isabet oranı düşük. php.ini içinde opcache.memory_consumption değerini artırın (ör. 256) ve opcache.max_accelerated_files değerini 20000 yapın.',
				array( 'code' => 'good' === $st ? null : "opcache.memory_consumption=256\nopcache.interned_strings_buffer=16\nopcache.max_accelerated_files=20000" )
			);
		} elseif ( $op_ini ) {
			$out[] = self::item( 'opcache', 'OPcache (PHP kod önbelleği)', 'good', 'Açık (ayrıntı kısıtlı)', 'medium', 'OPcache, PHP dosyalarını derlenmiş halde bellekte tutar.', 'OPcache açık görünüyor; sunucu istatistik paylaşımını kısıtladığı için ayrıntı okunamadı.' );
		} else {
			$out[] = self::item(
				'opcache',
				'OPcache (PHP kod önbelleği)',
				'critical',
				'Kapalı',
				'high',
				'OPcache kapalıyken her sayfa açılışında WordPress\'in ve eklentilerin tüm PHP dosyaları baştan derlenir. Bu, sunucu tarafında en büyük ve en kolay giderilebilen yavaşlık nedenlerinden biridir.',
				'Hosting panelinizdeki PHP eklentileri bölümünden "opcache" eklentisini etkinleştirin. VPS kullanıyorsanız php.ini içinde aşağıdaki ayarları açıp PHP-FPM\'i yeniden başlatın.',
				array( 'code' => "opcache.enable=1\nopcache.memory_consumption=256\nopcache.interned_strings_buffer=16\nopcache.max_accelerated_files=20000\nopcache.validate_timestamps=1\nopcache.revalidate_freq=60" )
			);
		}

		// Kalıcı nesne önbelleği.
		if ( wp_using_ext_object_cache() ) {
			$cls   = isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] ) ? get_class( $GLOBALS['wp_object_cache'] ) : '';
			$type  = stripos( $cls, 'redis' ) !== false || defined( 'WP_REDIS_VERSION' ) ? 'Redis' : ( stripos( $cls, 'memcache' ) !== false ? 'Memcached' : 'Etkin' );
			$out[] = self::item( 'object_cache', 'Kalıcı nesne önbelleği', 'good', $type, 'medium', 'Nesne önbelleği tekrar eden veritabanı sorgularının sonuçlarını bellekte tutar.', 'Kalıcı nesne önbelleği çalışıyor.' );
		} else {
			$out[] = self::item(
				'object_cache',
				'Kalıcı nesne önbelleği',
				'warning',
				'Yok',
				self::wc_active() ? 'high' : 'medium',
				'Nesne önbelleği olmadan WordPress aynı ayarları, menüleri ve ürün verilerini her istekte veritabanından tekrar okur. Özellikle WooCommerce, üyelik ve yoğun trafikli sitelerde veritabanını ciddi şekilde yorar.',
				'Hostinginiz Redis veya Memcached destekliyorsa ilgili servisi açıp "Redis Object Cache" eklentisini kurun. LiteSpeed sunucularda LiteSpeed Cache eklentisinin Object Cache özelliğini kullanabilirsiniz.'
			);
		}

		// Veritabanı sunucusu.
		$info  = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : (string) $wpdb->get_var( 'SELECT VERSION()' );
		$maria = stripos( $info, 'mariadb' ) !== false;
		$ver   = '0';
		if ( $maria && preg_match( '/(\d+\.\d+\.\d+)-MariaDB/i', $info, $m ) ) {
			$ver = $m[1];
		} elseif ( preg_match( '/(\d+\.\d+(?:\.\d+)?)/', $info, $m ) ) {
			$ver = $m[1];
		}
		if ( $maria ) {
			$st = version_compare( $ver, '10.4', '<' ) ? 'warning' : 'good';
		} else {
			$st = version_compare( $ver, '5.7', '<' ) ? 'critical' : ( version_compare( $ver, '8.0', '<' ) ? 'warning' : 'good' );
		}
		$out[] = self::item(
			'db_version',
			'Veritabanı sunucusu',
			$st,
			( $maria ? 'MariaDB ' : 'MySQL ' ) . $ver,
			'critical' === $st ? 'medium' : 'low',
			'Yeni MySQL/MariaDB sürümleri sorgu planlayıcısı ve InnoDB tarafında önemli hız iyileştirmeleri içerir.',
			'good' === $st ? 'Veritabanı sürümü güncel.' : 'Hosting firmanızdan MySQL 8.0+ veya MariaDB 10.6+ sürümüne geçiş talep edin.'
		);

		// HTTP protokolü.
		$proto = isset( $_SERVER['SERVER_PROTOCOL'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) : '';
		if ( '' !== $proto ) {
			$modern = (bool) preg_match( '#HTTP/(2|3)#i', $proto );
			$out[]  = self::item(
				'http_protocol',
				'HTTP protokolü',
				$modern ? 'good' : 'info',
				$proto,
				'low',
				'HTTP/2 ve HTTP/3, tek bağlantı üzerinden çok sayıda dosyayı paralel indirir. HTTP/1.1\'de her dosya sıraya girer.',
				$modern ? 'Sunucunuz modern protokol kullanıyor.' : 'Sunucu bu isteğe HTTP/1.1 ile yanıt verdi. Cloudflare gibi bir CDN kullanıyorsanız ziyaretçileriniz büyük olasılıkla zaten HTTP/2-3 alıyordur. Kullanmıyorsanız hostinginizden HTTP/2 desteği isteyin.'
			);
		}

		// Sunucu yükü.
		if ( function_exists( 'sys_getloadavg' ) ) {
			$load  = @sys_getloadavg(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$cores = self::cpu_cores();
			if ( is_array( $load ) && $cores > 0 ) {
				$ratio = $load[0] / $cores;
				$st    = $ratio > 1.5 ? 'critical' : ( $ratio > 0.9 ? 'warning' : 'good' );
				$out[] = self::item(
					'server_load',
					'Anlık sunucu yükü',
					$st,
					number_format( $load[0], 2, ',', '' ) . ' / ' . $cores . ' çekirdek',
					'high',
					'Yük, çekirdek sayısını aşıyorsa işlemci kuyrukta bekleyen işlerle dolu demektir; her istek yavaşlar. Paylaşımlı hostinglerde bu değer diğer siteleri de kapsayabilir.',
					'good' === $st ? 'Sunucu şu an rahat.' : 'Sayfa önbelleği ekleyin, yoğun cron görevlerini azaltın ve bot trafiğini (ör. Cloudflare ile) sınırlayın. Sorun sürerse daha güçlü bir pakete/VPS\'e geçmeyi değerlendirin.'
				);
			}
		}

		return $out;
	}

	private static function cpu_cores() {
		$n = 0;
		if ( @is_readable( '/proc/cpuinfo' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$c = @file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore
			if ( $c ) {
				$n = preg_match_all( '/^processor\s*:/m', $c );
			}
		}
		return (int) $n;
	}

	/* ------------------------------------------------------------------
	 * WORDPRESS
	 * ------------------------------------------------------------------ */

	private static function check_wordpress() {
		global $wp_version;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$out = array();
		$opt = WDPD_Optimizer::get();

		// Çekirdek güncellemesi.
		$upd = get_site_transient( 'update_core' );
		$new = null;
		if ( $upd && ! empty( $upd->updates ) ) {
			foreach ( $upd->updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response ) {
					$new = $u->current;
					break;
				}
			}
		}
		$out[] = self::item(
			'wp_core',
			'WordPress sürümü',
			$new ? 'warning' : 'good',
			$wp_version . ( $new ? ' → ' . $new : '' ),
			'medium',
			'Her WordPress sürümü sorgu, önbellek ve betik yükleme tarafında performans iyileştirmeleri içerir.',
			$new ? 'Yedek aldıktan sonra WordPress\'i güncelleyin.' : 'WordPress güncel.',
			array( 'fix' => $new ? self::fix_link( admin_url( 'update-core.php' ), 'Güncellemeye git' ) : null )
		);

		// Eklenti güncellemeleri.
		$pu = get_site_transient( 'update_plugins' );
		$n  = ( $pu && ! empty( $pu->response ) ) ? count( (array) $pu->response ) : 0;
		$out[] = self::item(
			'plugin_updates',
			'Bekleyen eklenti güncellemeleri',
			0 === $n ? 'good' : 'warning',
			0 === $n ? 'Yok' : $n . ' eklenti',
			$n > 3 ? 'medium' : 'low',
			'Eklenti geliştiricileri yavaş sorguları ve gereksiz yüklemeleri çoğu zaman güncellemelerle düzeltir. Güncel olmayan eklentiler aynı zamanda güvenlik açığıdır.',
			0 === $n ? 'Tüm eklentiler güncel.' : 'Yedek aldıktan sonra eklentileri güncelleyin.',
			array( 'fix' => $n ? self::fix_link( admin_url( 'plugins.php?plugin_status=upgrade' ), 'Eklentilere git' ) : null )
		);

		// Aktif eklenti sayısı.
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$ac    = count( array_unique( $active ) );
		$st    = $ac <= 20 ? 'good' : ( $ac <= 35 ? 'warning' : 'warning' );
		$out[] = self::item(
			'active_plugins',
			'Aktif eklenti sayısı',
			$st,
			$ac . ' eklenti',
			$ac > 35 ? 'medium' : 'low',
			'Her aktif eklentinin kodu her sayfa açılışında yüklenir. Sayının kendisinden çok eklentinin ne yaptığı önemlidir; yine de aynı işi yapan birden fazla eklenti (iki SEO, iki önbellek, iki form eklentisi) ciddi yük getirir.',
			'good' === $st ? 'Eklenti sayısı makul.' : 'Aynı işi yapan eklentileri birleştirin, tek bir küçük iş için kurulan eklentileri kaldırın. "Sayfa Yükü" bölümündeki dosya dağılımı, ana sayfanıza en çok dosya ekleyen eklentileri gösterir.'
		);

		// Pasif eklentiler.
		$all      = get_plugins();
		$inactive = max( 0, count( $all ) - $ac );
		$out[]    = self::item(
			'inactive_plugins',
			'Pasif eklentiler',
			$inactive > 5 ? 'warning' : 'good',
			$inactive . ' eklenti',
			'low',
			'Pasif eklentiler siteyi yavaşlatmaz ancak güncellenmedikleri için güvenlik açığı oluşturur ve güncelleme kontrollerini uzatır.',
			$inactive > 5 ? 'Kullanmadığınız eklentileri tamamen silin.' : 'Sorun yok.',
			array( 'fix' => $inactive > 5 ? self::fix_link( admin_url( 'plugins.php?plugin_status=inactive' ), 'Pasif eklentiler' ) : null )
		);

		// Hata ayıklama.
		$dbg = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$sq  = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		if ( $sq ) {
			$st  = 'critical';
			$val = 'SAVEQUERIES açık';
		} elseif ( $dbg ) {
			$st  = 'warning';
			$val = 'WP_DEBUG açık';
		} else {
			$st  = 'good';
			$val = 'Kapalı';
		}
		$out[] = self::item(
			'debug_mode',
			'Hata ayıklama modu',
			$st,
			$val,
			'medium',
			'WP_DEBUG açıkken her uyarı işlenip diske yazılır. SAVEQUERIES ise her veritabanı sorgusunu bellekte saklar; canlı sitede belleği ve süreyi belirgin şekilde artırır.',
			'good' === $st ? 'Canlı site için doğru ayar.' : 'wp-config.php dosyasında WP_DEBUG ve SAVEQUERIES değerlerini false yapın (Ayrıntı bölümünde hazır kod var).',
			array( 'code' => 'good' === $st ? null : "define( 'WP_DEBUG', false );\ndefine( 'SAVEQUERIES', false );" )
		);

		// debug.log boyutu.
		$log = WDPD_Cleaner::debug_log_path();
		if ( $log && file_exists( $log ) ) {
			$size  = (int) @filesize( $log ); // phpcs:ignore
			$st    = $size > 100 * MB_IN_BYTES ? 'critical' : ( $size > 10 * MB_IN_BYTES ? 'warning' : 'good' );
			$out[] = self::item(
				'debug_log',
				'debug.log dosyası',
				$st,
				WDPD_Util::bytes( $size ),
				'critical' === $st ? 'medium' : 'low',
				'Sürekli büyüyen bir hata günlüğü, sitede tekrar eden bir hata olduğunu gösterir ve her yazımda disk işlemi yapar. Herkese açık konumdaysa bilgi sızıntısına da yol açabilir.',
				'good' === $st ? 'Günlük dosyası küçük.' : 'Dosyayı boşaltın, ardından tekrar hızla büyüyorsa içindeki hatayı inceleyin (çoğunlukla güncel olmayan bir eklentidir).',
				array( 'fix' => 'good' === $st ? null : self::fix_action( 'truncate_debug_log', 'Günlüğü boşalt', 'debug.log dosyasının içeriği silinecek. Devam edilsin mi?' ) )
			);
		}

		// WP-Cron.
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$crons    = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
		$events   = 0;
		$overdue  = 0;
		$now      = time();
		foreach ( $crons as $ts => $hooks ) {
			foreach ( (array) $hooks as $hook => $items ) {
				$cnt     = count( (array) $items );
				$events += $cnt;
				if ( $ts < $now - 15 * MINUTE_IN_SECONDS ) {
					$overdue += $cnt;
				}
			}
		}
		$cron_cmd = '*/5 * * * * wget -q -O - "' . site_url( 'wp-cron.php?doing_wp_cron' ) . '" >/dev/null 2>&1';
		if ( $disabled ) {
			if ( $overdue > 10 ) {
				$st  = 'critical';
				$imp = 'high';
				$sol = 'DISABLE_WP_CRON açık ancak gecikmiş görev sayısı yüksek; sunucu cron\'u çalışmıyor olabilir. Hosting panelinizdeki Cron Jobs bölümüne aşağıdaki komutu ekleyin.';
			} else {
				$st  = 'good';
				$imp = 'medium';
				$sol = 'Zamanlanmış görevler sunucu cron\'u ile çalışıyor; doğru yapılandırma.';
			}
		} else {
			$st  = 'warning';
			$imp = $overdue > 20 ? 'high' : 'medium';
			$sol = 'Ziyaretçi tetiklemeli cron\'u kapatıp gerçek sunucu cron\'una geçin: 1) wp-config.php dosyasına define( \'DISABLE_WP_CRON\', true ); satırını ekleyin. 2) Hosting panelindeki Cron Jobs bölümüne aşağıdaki komutu 5 dakikada bir çalışacak şekilde ekleyin.';
		}
		$out[] = self::item(
			'wp_cron',
			'Zamanlanmış görevler (WP-Cron)',
			$st,
			( $disabled ? 'Sunucu cron' : 'Ziyaretçi tetiklemeli' ) . ' · ' . $events . ' görev · ' . $overdue . ' gecikmiş',
			$imp,
			'Varsayılan WP-Cron, ziyaretçi sayfaya girdiğinde arka plan işlerini (yedek, e-posta, stok senkronu) tetikler. Yoğun saatlerde ziyaretçinin isteğine ek yük bindirir, düşük trafikte ise görevler gecikir.',
			$sol,
			array( 'code' => 'good' === $st ? null : ( $disabled ? $cron_cmd : "define( 'DISABLE_WP_CRON', true );\n\n# Cron Jobs (5 dakikada bir):\n" . $cron_cmd ) )
		);

		// Sahipsiz cron görevleri.
		$orph = self::orphan_cron_hooks( $crons );
		if ( $orph ) {
			$rows = array();
			foreach ( $orph as $hook => $info ) {
				$rows[] = array(
					$hook,
					$info['count'],
					$info['next'] < $now ? 'Gecikmiş' : wp_date( 'd.m.Y H:i', $info['next'] ),
					array( 'fix' => self::fix_action( 'delete_cron_hook', 'Kaldır', $hook . ' görevi zamanlamadan kaldırılacak. Silinmiş bir eklentiye ait olduğundan eminseniz devam edin.' ), 'arg' => $hook ),
				);
			}
			$out[] = self::item(
				'orphan_cron',
				'Sahipsiz olabilecek cron görevleri',
				'warning',
				count( $orph ) . ' görev',
				'low',
				'Kaldırılan eklentiler zamanlanmış görevlerini çoğu zaman temizlemez. Bu görevler hiçbir işe yaramadan cron kuyruğunu doldurur ve her cron çalışmasında boşuna işlenir.',
				'Listedeki görevler şu an hiçbir kodun dinlemediği kancalardır. Tanıdığınız, silinmiş bir eklentiye ait olanları kaldırın. Emin olmadıklarınızı bırakın.',
				array(
					'details' => array(
						'cols' => array( 'Kanca', 'Adet', 'Sonraki' ),
						'rows' => $rows,
					),
				)
			);
		} else {
			$out[] = self::item( 'orphan_cron', 'Sahipsiz cron görevleri', 'good', 'Yok', 'low', 'Kaldırılan eklentilerden kalan zamanlanmış görevler cron kuyruğunu doldurur.', 'Sahipsiz görev bulunamadı.' );
		}

		// Heartbeat.
		$hb    = $opt['heartbeat'];
		$out[] = self::item(
			'heartbeat',
			'Heartbeat API',
			'default' === $hb ? 'info' : 'good',
			'default' === $hb ? 'Varsayılan' : WDPD_Optimizer::heartbeat_label( $hb ),
			'low',
			'Heartbeat, yönetim panelinde açık her sekmeden 15-60 saniyede bir sunucuya istek gönderir. Birden fazla yönetici ve açık sekme olduğunda paylaşımlı hostinglerde CPU sınırına takılmanın sık rastlanan nedenidir.',
			'default' === $hb ? 'Optimizasyonlar sekmesinden Heartbeat\'i yalnızca yazı düzenleyicide çalışacak şekilde sınırlayın.' : 'Heartbeat sınırlandırılmış.',
			array( 'fix' => 'default' === $hb ? self::fix_tab( 'optimize', 'Ayarı aç' ) : null )
		);

		// Revizyon sınırı.
		$limit = null;
		if ( (int) $opt['revisions_limit'] > 0 ) {
			$limit = (int) $opt['revisions_limit'];
		} elseif ( defined( 'WP_POST_REVISIONS' ) && true !== WP_POST_REVISIONS && is_numeric( WP_POST_REVISIONS ) && (int) WP_POST_REVISIONS >= 0 ) {
			$limit = (int) WP_POST_REVISIONS;
		}
		$out[] = self::item(
			'revisions_limit',
			'Revizyon sınırı',
			null === $limit ? 'warning' : 'good',
			null === $limit ? 'Sınırsız' : 'En fazla ' . $limit,
			'low',
			'WordPress her kayıtta yazının tam bir kopyasını saklar. Sınır yoksa uzun içerikli ve sık düzenlenen sitelerde wp_posts tablosu gereksiz yere şişer.',
			null === $limit ? 'Optimizasyonlar sekmesinden revizyon sayısını 5 ile sınırlayın.' : 'Revizyonlar sınırlandırılmış.',
			array( 'fix' => null === $limit ? self::fix_tab( 'optimize', 'Sınır koy' ) : null )
		);

		// Hafifletme ayarları.
		$light = array(
			'disable_emojis'         => 'Emoji betiği',
			'disable_embeds'         => 'oEmbed betiği',
			'remove_jquery_migrate'  => 'jQuery Migrate',
			'disable_xmlrpc'         => 'XML-RPC',
			'disable_self_pingbacks' => 'Kendine pingback',
			'head_cleanup'           => '<head> fazlalıkları',
		);
		$off = array();
		foreach ( $light as $k => $label ) {
			if ( empty( $opt[ $k ] ) ) {
				$off[] = $label;
			}
		}
		$out[] = self::item(
			'lightweight',
			'Gereksiz varsayılan yükler',
			count( $off ) > 2 ? 'info' : 'good',
			count( $off ) ? count( $off ) . ' açık' : 'Hafifletilmiş',
			'low',
			'WordPress, çoğu sitenin kullanmadığı bazı betikleri (emoji, oEmbed, jQuery Migrate) her sayfada yükler. XML-RPC ise bot saldırılarının sık kullandığı ve sunucuyu yoran bir kapıdır.',
			count( $off ) ? 'Hâlâ açık olanlar: ' . implode( ', ', $off ) . '. Optimizasyonlar sekmesinden tek tıkla kapatabilirsiniz.' : 'Gereksiz yükler kapatılmış.',
			array( 'fix' => count( $off ) ? self::fix_tab( 'optimize', 'Hafiflet' ) : null )
		);

		return $out;
	}

	/**
	 * Hiçbir callback'i olmayan cron kancaları. Çekirdek ve bilinen koşullu
	 * kancalar yanlış alarm vermemek için dışarıda bırakılır.
	 */
	public static function orphan_cron_hooks( $crons = null ) {
		if ( null === $crons ) {
			$crons = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
		}
		$skip_prefix = array( 'wp_', 'wdpd_', 'woocommerce', 'wc_', 'action_scheduler', 'recovery_mode', 'delete_expired_transients', 'jetpack', 'publish_future_post', 'do_pings', 'importer_scheduled_cleanup', 'upgrader_scheduled_cleanup', 'generate_category_feed' );
		$out         = array();
		foreach ( $crons as $ts => $hooks ) {
			foreach ( (array) $hooks as $hook => $items ) {
				foreach ( $skip_prefix as $p ) {
					if ( 0 === strpos( $hook, $p ) ) {
						continue 2;
					}
				}
				if ( has_action( $hook ) ) {
					continue;
				}
				if ( ! isset( $out[ $hook ] ) ) {
					$out[ $hook ] = array( 'count' => 0, 'next' => $ts );
				}
				$out[ $hook ]['count'] += count( (array) $items );
				$out[ $hook ]['next']   = min( $out[ $hook ]['next'], $ts );
			}
		}
		return array_slice( $out, 0, 30, true );
	}

	/* ------------------------------------------------------------------
	 * VERİTABANI
	 * ------------------------------------------------------------------ */

	public static function tables() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_count, DATA_LENGTH AS data, INDEX_LENGTH AS idx, DATA_FREE AS free
				 FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			),
			ARRAY_A
		);
		$rows = $rows ? $rows : array();
		usort(
			$rows,
			function ( $a, $b ) {
				return ( $b['data'] + $b['idx'] ) <=> ( $a['data'] + $a['idx'] );
			}
		);
		return $rows;
	}

	public static function autoload_stats() {
		global $wpdb;
		$in  = WDPD_Util::sql_in( WDPD_Util::autoload_values() );
		$row = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)),0) AS size FROM {$wpdb->options} WHERE autoload IN ($in)", ARRAY_A ); // phpcs:ignore
		return array(
			'count' => (int) ( $row ? $row['cnt'] : 0 ),
			'size'  => (int) ( $row ? $row['size'] : 0 ),
		);
	}

	public static function autoload_top( $limit = 15 ) {
		global $wpdb;
		$in = WDPD_Util::sql_in( WDPD_Util::autoload_values() );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name AS name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload IN ($in) ORDER BY size DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore
	}

	private static function check_database() {
		$out = array();
		$c   = WDPD_Cleaner::counts();

		// Toplam boyut.
		$tables = self::tables();
		$total  = 0;
		$free   = 0;
		$myisam = array();
		foreach ( $tables as $t ) {
			$total += (int) $t['data'] + (int) $t['idx'];
			$free  += (int) $t['free'];
			if ( 'MyISAM' === $t['engine'] ) {
				$myisam[] = $t['name'];
			}
		}
		$rows = array();
		foreach ( array_slice( $tables, 0, 8 ) as $t ) {
			$rows[] = array( $t['name'], WDPD_Util::num( $t['row_count'] ), WDPD_Util::bytes( (int) $t['data'] + (int) $t['idx'] ) );
		}
		$out[] = self::item(
			'db_size',
			'Veritabanı boyutu',
			$total > GB_IN_BYTES ? 'info' : 'good',
			WDPD_Util::bytes( $total ) . ' · ' . count( $tables ) . ' tablo',
			'low',
			'Boyutun kendisi tek başına sorun değildir; önemli olan gereksiz verinin oranıdır. Aşağıda en büyük tablolar listelenir.',
			'Tablolardan biri beklenmedik derecede büyükse (log, istatistik, oturum tabloları) ilgili eklentinin ayarlarından kayıt süresini kısaltın.',
			array(
				'details' => array(
					'cols' => array( 'Tablo', 'Satır', 'Boyut' ),
					'rows' => $rows,
				),
			)
		);

		// Autoload.
		$al = self::autoload_stats();
		$st = $al['size'] > 2 * MB_IN_BYTES ? 'critical' : ( $al['size'] > 800 * KB_IN_BYTES ? 'warning' : 'good' );
		$out[] = self::item(
			'autoload',
			'Otomatik yüklenen ayarlar (autoload)',
			$st,
			WDPD_Util::bytes( $al['size'] ) . ' · ' . WDPD_Util::num( $al['count'] ) . ' kayıt',
			'high',
			'Autoload işaretli ayarlar, ziyaretçi hangi sayfayı açarsa açsın her istekte veritabanından topluca okunur. Kaldırılmış eklentilerden kalan veya büyük veri saklayan ayarlar bu yükü şişirir. 800 KB üzeri yavaşlık nedenidir.',
			'good' === $st ? 'Autoload boyutu sağlıklı.' : 'Veritabanı sekmesinde en büyük autoload kayıtlarını inceleyin. Kaldırılmış eklentilere ait olanların otomatik yüklenmesini kapatın. Bu işlem geri alınabilir.',
			array( 'fix' => 'good' === $st ? null : self::fix_tab( 'database', 'Kayıtları incele' ) )
		);

		// Süresi dolmuş geçici veriler.
		$n     = $c['transients'];
		$out[] = self::item(
			'transients',
			'Süresi dolmuş geçici veriler',
			$n > 500 ? 'warning' : ( $n > 50 ? 'warning' : 'good' ),
			WDPD_Util::num( $n ) . ' kayıt',
			$n > 500 ? 'medium' : 'low',
			'Eklentilerin geçici önbellek kayıtları (transient) süresi dolduğunda her zaman silinmez ve wp_options tablosunu şişirir.',
			$n > 50 ? 'Süresi dolmuş kayıtları silin; aktif önbelleklere dokunulmaz.' : 'Temiz.',
			array( 'fix' => $n > 50 ? self::fix_action( 'transients', 'Temizle' ) : null )
		);

		// Revizyonlar.
		$n     = $c['revisions'];
		$out[] = self::item(
			'revisions',
			'Yazı revizyonları',
			$n > 300 ? 'warning' : 'good',
			WDPD_Util::num( $n ) . ' revizyon',
			$n > 3000 ? 'medium' : 'low',
			'Her revizyon wp_posts tablosunda tam bir yazı kopyasıdır ve ilgili meta verileriyle birlikte sorguları yavaşlatır.',
			$n > 300 ? 'Eski revizyonları silin ve Optimizasyonlar sekmesinden revizyon sınırı koyun.' : 'Makul seviyede.',
			array( 'fix' => $n > 300 ? self::fix_action( 'revisions', 'Revizyonları sil', 'Tüm yazıların eski revizyonları kalıcı olarak silinecek. Yayındaki içerik etkilenmez. Devam edilsin mi?' ) : null )
		);

		// Taslak ve çöp.
		$n     = $c['autodrafts'] + $c['trash_posts'];
		$out[] = self::item(
			'trash_posts',
			'Otomatik taslaklar ve çöp kutusu',
			$n > 100 ? 'warning' : 'good',
			WDPD_Util::num( $n ) . ' kayıt',
			'low',
			'Çöp kutusundaki yazılar ve terk edilmiş otomatik taslaklar sorgularda taranmaya devam eder.',
			$n > 100 ? 'Çöp kutusunu ve otomatik taslakları boşaltın.' : 'Temiz.',
			array( 'fix' => $n > 100 ? self::fix_tab( 'database', 'Temizlik' ) : null )
		);

		// Yorumlar.
		$n     = $c['spam_comments'] + $c['trash_comments'];
		$out[] = self::item(
			'spam_comments',
			'Spam ve çöp yorumlar',
			$n > 500 ? 'warning' : 'good',
			WDPD_Util::num( $n ) . ' yorum',
			$n > 5000 ? 'medium' : 'low',
			'Spam yorumlar wp_comments ve wp_commentmeta tablolarını büyütür; yorum sayımları ve yönetim ekranı yavaşlar.',
			$n > 500 ? 'Spam ve çöpteki yorumları silin.' : 'Temiz.',
			array( 'fix' => $n > 500 ? self::fix_action( 'spam_comments', 'Spam yorumları sil' ) : null )
		);

		// Sahipsiz meta.
		$n     = $c['orphan_postmeta'] + $c['orphan_commentmeta'];
		$out[] = self::item(
			'orphan_meta',
			'Sahipsiz meta kayıtları',
			$n > 1000 ? 'warning' : 'good',
			WDPD_Util::num( $n ) . ' kayıt',
			'low',
			'Silinmiş yazı ve yorumlardan geride kalan meta kayıtlarıdır; hiçbir şeye bağlı olmadıkları halde indeksleri büyütürler.',
			$n > 1000 ? 'Sahipsiz meta kayıtlarını temizleyin.' : 'Temiz.',
			array( 'fix' => $n > 1000 ? self::fix_tab( 'database', 'Temizlik' ) : null )
		);

		// Tablo şişkinliği.
		$out[] = self::item(
			'overhead',
			'Tablo şişkinliği (boşluk)',
			$free > 50 * MB_IN_BYTES ? 'warning' : 'good',
			WDPD_Util::bytes( $free ),
			'low',
			'Silinen satırların bıraktığı boşluklar tablo dosyasında yer kaplamaya devam eder. Yoğun silme işlemlerinden sonra tabloları optimize etmek disk okumasını azaltır.',
			$free > 50 * MB_IN_BYTES ? 'Tabloları optimize edin. Büyük tablolarda işlem birkaç dakika sürebilir; düşük trafikli saatlerde yapın.' : 'Önemli bir şişkinlik yok.',
			array( 'fix' => $free > 50 * MB_IN_BYTES ? self::fix_action( 'optimize_tables', 'Optimize et', 'Tablolar optimize edilecek. Büyük sitelerde birkaç dakika sürebilir ve bu sürede tablolar kısa süre kilitlenebilir. Devam edilsin mi?' ) : null )
		);

		// MyISAM.
		if ( $myisam ) {
			$out[] = self::item(
				'myisam',
				'Eski depolama motoru (MyISAM)',
				'warning',
				count( $myisam ) . ' tablo',
				'medium',
				'MyISAM tabloları her yazma işleminde tüm tabloyu kilitler; eşzamanlı ziyaretçide sorgular birbirini bekler. InnoDB satır bazlı kilit ve çökme koruması sunar.',
				'Yedek aldıktan sonra tabloları InnoDB\'ye dönüştürün.',
				array(
					'fix'     => self::fix_action( 'convert_innodb', 'InnoDB\'ye dönüştür', 'MyISAM tablolar InnoDB\'ye dönüştürülecek. İşlemden önce veritabanı yedeği almanızı öneririz. Devam edilsin mi?' ),
					'details' => array(
						'cols' => array( 'Tablo' ),
						'rows' => array_map(
							function ( $n ) {
								return array( $n );
							},
							$myisam
						),
					),
				)
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * SAYFA YÜKÜ (ana sayfaya gerçek istek)
	 * ------------------------------------------------------------------ */

	private static function fetch_home() {
		if ( null !== self::$home ) {
			return self::$home;
		}
		$url  = home_url( '/' );
		$args = array(
			'timeout'     => 25,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 WDPerformansDoktoru/' . WDPD_VERSION,
			'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
		);

		$t1   = microtime( true );
		$r1   = wp_remote_get( $url, $args );
		$cold = ( microtime( true ) - $t1 ) * 1000;

		$t2   = microtime( true );
		$r2   = wp_remote_get( $url, $args );
		$warm = ( microtime( true ) - $t2 ) * 1000;

		$res = is_wp_error( $r2 ) ? $r1 : $r2;

		self::$home = array(
			'url'   => $url,
			'res'   => $res,
			'cold'  => $cold,
			'warm'  => is_wp_error( $r2 ) ? $cold : $warm,
			'error' => is_wp_error( $res ) ? $res->get_error_message() : null,
		);
		return self::$home;
	}

	private static function header_map( $res ) {
		$h = wp_remote_retrieve_headers( $res );
		if ( is_object( $h ) && method_exists( $h, 'getAll' ) ) {
			$h = $h->getAll();
		}
		$out = array();
		foreach ( (array) $h as $k => $v ) {
			$out[ strtolower( $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
		}
		return $out;
	}

	private static function attr( $attrs, $name ) {
		if ( preg_match( '#\b' . preg_quote( $name, '#' ) . '\s*=\s*(["\'])(.*?)\1#is', $attrs, $m ) ) {
			return html_entity_decode( $m[2], ENT_QUOTES );
		}
		if ( preg_match( '#\b' . preg_quote( $name, '#' ) . '\s*=\s*([^\s>]+)#i', $attrs, $m ) ) {
			return $m[1];
		}
		return null;
	}

	private static function has_attr( $attrs, $name ) {
		return (bool) preg_match( '#(?:^|\s)' . preg_quote( $name, '#' ) . '(?:\s*=|\s|$)#i', $attrs );
	}

	/**
	 * URL'nin hangi eklentiden / temadan geldiğini bulur.
	 */
	private static function asset_owner( $url, $site_host, $plugin_names ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( $host && ! self::same_site( $host, $site_host ) ) {
			return 'Harici: ' . $host;
		}
		if ( preg_match( '#/plugins/([^/]+)/#', $path, $m ) ) {
			return isset( $plugin_names[ $m[1] ] ) ? $plugin_names[ $m[1] ] : $m[1];
		}
		if ( preg_match( '#/themes/([^/]+)/#', $path, $m ) ) {
			return 'Tema: ' . $m[1];
		}
		if ( false !== strpos( $path, '/wp-includes/' ) ) {
			return 'WordPress çekirdek';
		}
		if ( false !== strpos( $path, '/uploads/' ) || false !== strpos( $path, '/cache/' ) ) {
			return 'Önbellek / birleştirilmiş dosyalar';
		}
		return 'Diğer';
	}

	private static function same_site( $host, $site_host ) {
		$a = preg_replace( '#^www\.#', '', strtolower( $host ) );
		$b = preg_replace( '#^www\.#', '', strtolower( $site_host ) );
		return $a === $b || substr( $a, -strlen( '.' . $b ) ) === '.' . $b;
	}

	private static function check_frontend() {
		$out  = array();
		$home = self::fetch_home();

		if ( $home['error'] || 200 !== (int) wp_remote_retrieve_response_code( $home['res'] ) ) {
			$code  = $home['error'] ? $home['error'] : 'HTTP ' . wp_remote_retrieve_response_code( $home['res'] );
			$out[] = self::item(
				'home_fetch',
				'Ana sayfa ölçümü yapılamadı',
				'warning',
				$code,
				'medium',
				'Sayfa yükü testleri için sunucu kendi ana sayfasına istek gönderir. Bu istek engellendiğinde (güvenlik duvarı, bakım modu, şifre koruması) ölçüm yapılamaz. Ayrıca sunucunun kendine erişememesi WP-Cron ve bazı eklentilerin de çalışmamasına yol açar.',
				'Bakım modu veya site şifresi varsa geçici olarak kapatıp tekrar tarayın. Güvenlik duvarınız (Cloudflare, Wordfence, ModSecurity) sunucunun kendi IP adresini engelliyorsa beyaz listeye ekleyin.'
			);
			return $out;
		}

		$res     = $home['res'];
		$html    = (string) wp_remote_retrieve_body( $res );
		$headers = self::header_map( $res );
		$site    = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		// Yanıt süresi.
		$ms    = $home['warm'];
		$st    = $ms < 500 ? 'good' : ( $ms < 1200 ? 'warning' : 'critical' );
		$out[] = self::item(
			'ttfb',
			'Ana sayfa yanıt süresi',
			$st,
			WDPD_Util::ms( $ms ) . ( abs( $home['cold'] - $ms ) > 150 ? ' (ilk istek ' . WDPD_Util::ms( $home['cold'] ) . ')' : '' ),
			'high',
			'Sunucunun sayfayı hazırlayıp göndermeye başlaması için geçen süredir. Ölçüm sunucunun kendi içinden yapıldığı için ağ gecikmesini içermez; bu değer yüksekse sorun doğrudan PHP, eklentiler veya veritabanıdır. Google, 800 ms altını hedef olarak önerir.',
			'good' === $st ? 'Sunucu hızlı yanıt veriyor.' : 'En büyük kazanç sayfa önbelleğidir. Ardından OPcache, nesne önbelleği ve autoload sonuçlarını kontrol edin. Önbellek varken bile yüksekse ana sayfadaki ağır eklentileri (slider, sosyal medya akışı, canlı sayaçlar) gözden geçirin.'
		);

		// Sayfa önbelleği.
		$signals = array();
		$keys    = array( 'x-cache', 'x-litespeed-cache', 'x-proxy-cache', 'x-varnish', 'x-sg-cache', 'x-kinsta-cache', 'x-nginx-cache', 'x-fastcgi-cache', 'x-cache-status', 'x-hcdn-cache-status', 'x-rocket-nginx-serving-static', 'x-cache-enabled', 'x-wpe-cached', 'x-srcache-fetch-status' );
		$miss    = false;
		foreach ( $keys as $k ) {
			if ( isset( $headers[ $k ] ) ) {
				$signals[] = $k . ': ' . $headers[ $k ];
				if ( preg_match( '/\b(miss|bypass|expired)\b/i', $headers[ $k ] ) ) {
					$miss = true;
				}
			}
		}
		if ( isset( $headers['cf-cache-status'] ) && preg_match( '/hit/i', $headers['cf-cache-status'] ) ) {
			$signals[] = 'cf-cache-status: ' . $headers['cf-cache-status'];
		}
		if ( isset( $headers['age'] ) && (int) $headers['age'] > 0 ) {
			$signals[] = 'age: ' . $headers['age'];
		}
		$tail     = substr( $html, -3000 );
		$comments = array( 'WP Super Cache', 'W3 Total Cache', 'WP Rocket', 'This website is like a Rocket', 'LiteSpeed Cache', 'WP Fastest Cache', 'Cache Enabler', 'Comet Cache', 'Hummingbird', 'Breeze', 'Swift Performance', 'FlyingPress', 'WP-Optimize page cache', 'cached@' );
		foreach ( $comments as $sig ) {
			if ( stripos( $tail, $sig ) !== false ) {
				$signals[] = 'HTML imzası: ' . $sig;
				break;
			}
		}
		$adv = defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
		if ( $adv ) {
			$signals[] = 'advanced-cache.php etkin';
		}

		if ( $signals && ! $miss ) {
			$st  = 'good';
			$val = 'Etkin';
		} elseif ( $signals && $miss ) {
			$st  = 'warning';
			$val = 'Var ama isabet yok';
		} else {
			$st  = $ms > 800 ? 'critical' : 'warning';
			$val = 'Algılanamadı';
		}
		$out[] = self::item(
			'page_cache',
			'Sayfa önbelleği',
			$st,
			$val,
			'high',
			'Sayfa önbelleği olmadan her ziyaretçi için WordPress baştan çalışır: tüm eklentiler yüklenir, onlarca sorgu atılır. Önbellekle sayfa hazır HTML olarak sunulur ve sunucu yükü genellikle 5-20 kat azalır. Bir WordPress sitesine yapılabilecek en etkili iyileştirmedir.',
			'good' === $st
				? 'Sayfa önbelleği çalışıyor.'
				: ( 'warning' === $st && $signals
					? 'Önbellek kurulu ancak ana sayfa önbellekten sunulmadı. Önbellek eklentinizin ana sayfayı hariç tutmadığından, çerez/oturum kurallarının herkesi kapsamadığından emin olun.'
					: 'Sunucunuz LiteSpeed ise "LiteSpeed Cache", değilse "WP Super Cache" veya "Cache Enabler" gibi bir sayfa önbelleği eklentisi kurun. Tek bir önbellek eklentisi kullanın; ikisini birden çalıştırmak çakışma yaratır.' ),
			array(
				'details' => $signals ? array(
					'cols' => array( 'Algılanan sinyal' ),
					'rows' => array_map(
						function ( $s ) {
							return array( $s );
						},
						$signals
					),
				) : null,
			)
		);

		// Sıkıştırma.
		$enc   = isset( $headers['content-encoding'] ) ? strtolower( $headers['content-encoding'] ) : '';
		$ok    = (bool) preg_match( '/gzip|br|zstd|deflate/', $enc );
		$out[] = self::item(
			'compression',
			'Sıkıştırma (Gzip / Brotli)',
			$ok ? 'good' : 'warning',
			$ok ? strtoupper( $enc ) : 'Kapalı',
			'high',
			'Sıkıştırma, HTML, CSS ve JS dosyalarının boyutunu %70-80 oranında küçültür. Kapalıysa özellikle mobil bağlantılarda sayfa belirgin şekilde geç açılır.',
			$ok ? 'Yanıtlar sıkıştırılarak gönderiliyor.' : 'Apache/LiteSpeed sunucularda aşağıdaki kodu .htaccess dosyasının en üstüne ekleyin. Nginx kullanıyorsanız "gzip on;" ayarını hosting firmanızdan isteyin. Cloudflare kullanmak da sorunu çözer.',
			array( 'code' => $ok ? null : "<IfModule mod_deflate.c>\n  AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json application/xml image/svg+xml font/ttf font/otf\n</IfModule>" )
		);

		// HTML boyutu.
		$size  = strlen( $html );
		$st    = $size > 500 * KB_IN_BYTES ? 'critical' : ( $size > 200 * KB_IN_BYTES ? 'warning' : 'good' );
		$out[] = self::item(
			'html_size',
			'Ana sayfa HTML boyutu',
			$st,
			WDPD_Util::bytes( $size ),
			'critical' === $st ? 'medium' : 'low',
			'Büyük HTML, tarayıcının işlemesi gereken öğe (DOM) sayısının çok yüksek olduğunu gösterir. Sayfa oluşturucularla yapılmış iç içe bölümler ve sayfaya gömülü büyük CSS/JSON blokları başlıca nedenlerdir.',
			'good' === $st ? 'HTML boyutu makul.' : 'Ana sayfadaki bölüm sayısını azaltın, uzun ürün/yazı listelerini sayfalayın ve sayfa oluşturucunuzun "satır içi CSS" seçeneklerini dosyaya yazacak şekilde ayarlayın.'
		);

		// Betik ve stil dosyaları.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin_names = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugin_names[ dirname( $file ) ] = $data['Name'];
		}
		$head = '';
		if ( preg_match( '#<head\b[^>]*>(.*?)</head>#is', $html, $hm ) ) {
			$head = $hm[1];
		}
		$owners   = array();
		$scripts  = array();
		$blocking = array();
		$third    = array();
		$assets   = array();

		preg_match_all( '#<script\b([^>]*)>#i', $html, $sm );
		foreach ( $sm[1] as $attrs ) {
			$src = self::attr( $attrs, 'src' );
			if ( ! $src || 0 === strpos( $src, 'data:' ) ) {
				continue;
			}
			$src       = self::absolutize( $src );
			$scripts[] = $src;
			$o         = self::asset_owner( $src, $site, $plugin_names );
			if ( ! isset( $owners[ $o ] ) ) {
				$owners[ $o ] = array( 0, 0 );
			}
			$owners[ $o ][0]++;
			$assets[] = $src;
		}
		preg_match_all( '#<script\b([^>]*)>#i', $head, $hsm );
		foreach ( $hsm[1] as $attrs ) {
			$src  = self::attr( $attrs, 'src' );
			$type = strtolower( (string) self::attr( $attrs, 'type' ) );
			if ( ! $src || self::has_attr( $attrs, 'async' ) || self::has_attr( $attrs, 'defer' ) || 'module' === $type ) {
				continue;
			}
			$blocking[] = self::absolutize( $src );
		}
		$styles = array();
		preg_match_all( '#<link\b([^>]*)>#i', $html, $lm );
		foreach ( $lm[1] as $attrs ) {
			$rel = strtolower( (string) self::attr( $attrs, 'rel' ) );
			if ( false === strpos( $rel, 'stylesheet' ) ) {
				continue;
			}
			$href = self::attr( $attrs, 'href' );
			if ( ! $href ) {
				continue;
			}
			$href     = self::absolutize( $href );
			$styles[] = $href;
			$o        = self::asset_owner( $href, $site, $plugin_names );
			if ( ! isset( $owners[ $o ] ) ) {
				$owners[ $o ] = array( 0, 0 );
			}
			$owners[ $o ][1]++;
			$assets[] = $href;
		}

		$js    = count( $scripts );
		$css   = count( $styles );
		$st    = ( $js > 25 || $css > 15 ) ? 'warning' : ( ( $js > 15 || $css > 10 ) ? 'warning' : 'good' );
		$imp   = ( $js > 25 || $css > 15 ) ? 'medium' : 'low';
		uasort(
			$owners,
			function ( $a, $b ) {
				return ( $b[0] + $b[1] ) <=> ( $a[0] + $a[1] );
			}
		);
		$rows = array();
		foreach ( array_slice( $owners, 0, 12, true ) as $name => $cnt ) {
			$rows[] = array( $name, $cnt[0], $cnt[1] );
		}
		$out[] = self::item(
			'asset_count',
			'Yüklenen JS ve CSS dosyaları',
			$st,
			$js . ' JS · ' . $css . ' CSS',
			$imp,
			'Her dosya ayrı bir indirme ve işleme demektir. Birçok eklenti, kullanılmadığı sayfalarda bile dosyalarını yükler (ör. iletişim formu betiğinin tüm sayfalarda yüklenmesi). Aşağıdaki tablo ana sayfanıza en çok dosya ekleyen kaynakları gösterir.',
			'good' === $st ? 'Dosya sayısı makul.' : 'Tablonun üstündeki eklentilerin ayarlarında "yalnızca gerekli sayfalarda yükle" seçeneği arayın. Önbellek eklentinizin CSS/JS birleştirme ve küçültme özelliklerini etkinleştirin.',
			array(
				'details' => $rows ? array(
					'cols' => array( 'Kaynak', 'JS', 'CSS' ),
					'rows' => $rows,
				) : null,
			)
		);

		// Engelleyici betikler.
		$n     = count( $blocking );
		$out[] = self::item(
			'render_blocking',
			'Sayfayı engelleyen betikler',
			$n > 3 ? 'warning' : 'good',
			$n . ' betik',
			$n > 6 ? 'medium' : 'low',
			'<head> içinde async veya defer olmadan yüklenen betikler indirilip çalıştırılana kadar tarayıcı sayfayı çizmeye başlayamaz. Ziyaretçi bu sürede boş ekran görür.',
			$n > 3 ? 'Önbellek eklentinizin "JS erteleme (defer)" veya "JS gecikmeli yükleme" özelliğini açın. jQuery\'ye bağımlı betikler için önce test sitesinde deneyin.' : 'Engelleyici betik sayısı az.',
			array(
				'details' => $n ? array(
					'cols' => array( 'Betik' ),
					'rows' => array_map(
						function ( $u ) {
							return array( WDPD_Util::short_url( $u, 80 ) );
						},
						array_slice( $blocking, 0, 12 )
					),
				) : null,
			)
		);

		// Üçüncü taraf alan adları.
		preg_match_all( '#<(?:script|img|iframe)\b[^>]*\bsrc\s*=\s*["\']([^"\']+)#i', $html, $um );
		$all_urls = array_merge( $um[1], $styles );
		foreach ( $all_urls as $u ) {
			$u = self::absolutize( $u );
			$h = (string) wp_parse_url( $u, PHP_URL_HOST );
			if ( $h && ! self::same_site( $h, $site ) ) {
				$third[ $h ] = isset( $third[ $h ] ) ? $third[ $h ] + 1 : 1;
			}
		}
		arsort( $third );
		$n     = count( $third );
		$out[] = self::item(
			'third_party',
			'Üçüncü taraf alan adları',
			$n > 6 ? 'warning' : 'good',
			$n . ' alan adı',
			$n > 10 ? 'medium' : 'low',
			'Her farklı alan adı için tarayıcı DNS sorgusu, bağlantı ve SSL el sıkışması yapar. Sohbet balonları, reklam, analiz ve sosyal medya kodları sayfanın geç açılmasının sık nedenidir.',
			$n > 6 ? 'Kullanmadığınız izleme kodlarını kaldırın; Google Fonts gibi kaynakları yerel olarak barındırın; sohbet/sosyal medya kodlarını kullanıcı etkileşiminden sonra yükleyin.' : 'Harici kaynak sayısı makul.',
			array(
				'details' => $n ? array(
					'cols' => array( 'Alan adı', 'İstek' ),
					'rows' => array_map(
						function ( $h, $c ) {
							return array( $h, $c );
						},
						array_keys( array_slice( $third, 0, 12, true ) ),
						array_values( array_slice( $third, 0, 12, true ) )
					),
				) : null,
			)
		);

		// Görsel boyut nitelikleri.
		preg_match_all( '#<img\b([^>]*)>#i', $html, $im );
		$imgs    = 0;
		$no_size = 0;
		$no_lazy = 0;
		foreach ( $im[1] as $i => $attrs ) {
			$src = self::attr( $attrs, 'src' );
			if ( $src && 0 === strpos( $src, 'data:' ) && ! self::attr( $attrs, 'data-src' ) ) {
				continue;
			}
			$imgs++;
			if ( ! self::attr( $attrs, 'width' ) || ! self::attr( $attrs, 'height' ) ) {
				$no_size++;
			}
			if ( $i > 2 && 'lazy' !== strtolower( (string) self::attr( $attrs, 'loading' ) ) && ! self::attr( $attrs, 'data-src' ) && ! self::attr( $attrs, 'data-lazy-src' ) ) {
				$no_lazy++;
			}
		}
		if ( $imgs ) {
			$bad   = $no_size + $no_lazy;
			$out[] = self::item(
				'images_markup',
				'Görsel yükleme düzeni',
				( $no_size > 5 || $no_lazy > 8 ) ? 'warning' : 'good',
				$imgs . ' görsel · ' . $no_size . ' boyutsuz · ' . $no_lazy . ' tembel yüklemesiz',
				'low',
				'Genişlik/yükseklik bilgisi olmayan görseller yüklenirken sayfanın kaymasına (CLS) neden olur. Ekranın altındaki görsellerin tembel yüklenmemesi ise ilk açılışta gereksiz indirme yapar.',
				$bad ? 'Tema veya sayfa oluşturucunuzun görsellere boyut ekleme ve "lazy load" seçeneklerini açın. Görselleri WebP/AVIF formatına dönüştürmek boyutlarını ayrıca %30-50 azaltır.' : 'Görseller doğru yükleniyor.'
			);
		}

		// Tarayıcı önbelleği.
		$local = null;
		foreach ( $assets as $a ) {
			$h = (string) wp_parse_url( $a, PHP_URL_HOST );
			if ( $h && self::same_site( $h, $site ) && preg_match( '#\.(css|js)(\?|$)#', $a ) ) {
				$local = $a;
				break;
			}
		}
		if ( $local ) {
			$hr = wp_remote_get(
				$local,
				array(
					'timeout'             => 10,
					'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
					'limit_response_size' => 2048,
				)
			);
			if ( ! is_wp_error( $hr ) ) {
				$ah    = self::header_map( $hr );
				$cc    = isset( $ah['cache-control'] ) ? $ah['cache-control'] : '';
				$maxage = preg_match( '/max-age=(\d+)/', $cc, $mm ) ? (int) $mm[1] : 0;
				$ok    = $maxage >= 7 * DAY_IN_SECONDS || ( ! $maxage && isset( $ah['expires'] ) && strtotime( $ah['expires'] ) > time() + 7 * DAY_IN_SECONDS );
				$out[] = self::item(
					'browser_cache',
					'Tarayıcı önbelleği (statik dosyalar)',
					$ok ? 'good' : 'warning',
					$maxage ? 'max-age ' . WDPD_Util::num( round( $maxage / DAY_IN_SECONDS ) ) . ' gün' : ( $ok ? 'Expires başlığı' : 'Tanımsız / kısa' ),
					'medium',
					'Tarayıcı önbelleği, geri dönen ziyaretçinin CSS, JS ve görselleri tekrar indirmemesini sağlar. Süre tanımlanmamışsa her sayfa geçişinde dosyalar yeniden doğrulanır.',
					$ok ? 'Statik dosyalar uzun süre önbelleğe alınıyor.' : 'Apache/LiteSpeed sunucularda aşağıdaki kodu .htaccess dosyasına ekleyin. Nginx için "expires 30d;" kuralını hosting firmanızdan isteyin.',
					array( 'code' => $ok ? null : "<IfModule mod_expires.c>\n  ExpiresActive On\n  ExpiresByType text/css \"access plus 1 year\"\n  ExpiresByType application/javascript \"access plus 1 year\"\n  ExpiresByType image/webp \"access plus 1 year\"\n  ExpiresByType image/avif \"access plus 1 year\"\n  ExpiresByType image/jpeg \"access plus 1 year\"\n  ExpiresByType image/png \"access plus 1 year\"\n  ExpiresByType image/svg+xml \"access plus 1 year\"\n  ExpiresByType font/woff2 \"access plus 1 year\"\n</IfModule>" )
				);
			}
		}

		return $out;
	}

	private static function absolutize( $url ) {
		$url = trim( $url );
		if ( 0 === strpos( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return untrailingslashit( home_url() ) . $url;
		}
		return $url;
	}

	/* ------------------------------------------------------------------
	 * WOOCOMMERCE
	 * ------------------------------------------------------------------ */

	private static function check_woocommerce() {
		global $wpdb;
		$out = array();
		$c   = WDPD_Cleaner::counts();
		$opt = WDPD_Optimizer::get();

		$at = $wpdb->prefix . 'actionscheduler_actions';
		if ( WDPD_Util::table_exists( $at ) ) {
			// Gecikmiş kuyruk.
			$pastdue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$at} WHERE status = 'pending' AND scheduled_date_gmt < %s", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore
			$st      = $pastdue > 200 ? 'critical' : ( $pastdue > 30 ? 'warning' : 'good' );
			$out[]   = self::item(
				'as_pastdue',
				'Arka plan kuyruğu (Action Scheduler)',
				$st,
				WDPD_Util::num( $pastdue ) . ' gecikmiş görev',
				'high',
				'WooCommerce; e-postaları, stok/ödeme güncellemelerini ve analitik hesaplarını bu kuyrukta işler. Görevler birikiyorsa cron çalışmıyor veya bir görev sürekli hata veriyor demektir; kuyruk büyüdükçe her çalıştırma daha uzun sürer.',
				'good' === $st ? 'Kuyruk akıyor.' : 'Önce WordPress bölümündeki WP-Cron önerisini uygulayın. Ardından Araçlar › Zamanlanmış Eylemler › Beklemede ekranında hangi görevin biriktiğine bakın; genellikle bağlantısı kesilmiş bir pazaryeri/ERP entegrasyonudur.',
				array( 'fix' => 'good' === $st ? null : self::fix_link( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&status=past-due' ), 'Kuyruğu aç' ) )
			);

			// Kuyruk şişkinliği.
			$n     = $c['action_scheduler'];
			$st    = $n > 100000 ? 'critical' : ( $n > 10000 ? 'warning' : 'good' );
			$out[] = self::item(
				'as_bloat',
				'Tamamlanmış kuyruk kayıtları',
				$st,
				WDPD_Util::num( $n ) . ' kayıt',
				'critical' === $st ? 'high' : 'medium',
				'Tamamlanan, iptal edilen ve hata veren görevler ve kayıtları (log) tablolarda birikir. Yüz binlerce satıra ulaştığında kuyruğun her çalışması ve yönetim paneli yavaşlar. Birçok mağazada veritabanının en büyük tablosu budur.',
				'good' === $st ? 'Kuyruk tabloları makul boyutta.' : '7 günden eski tamamlanmış, iptal edilmiş ve hatalı görevleri silin. Bekleyen görevlere dokunulmaz.',
				array( 'fix' => 'good' === $st ? null : self::fix_action( 'action_scheduler', 'Eski kayıtları sil' ) )
			);
		}

		// Oturumlar.
		$n     = $c['wc_sessions'];
		$out[] = self::item(
			'wc_sessions',
			'Süresi dolmuş müşteri oturumları',
			$n > 1000 ? 'warning' : 'good',
			WDPD_Util::num( $n ) . ' oturum',
			'low',
			'Sepet verileri woocommerce_sessions tablosunda tutulur. Süresi dolanlar normalde günlük temizlenir; cron sorunluysa birikip tabloyu büyütür.',
			$n > 1000 ? 'Süresi dolmuş oturumları silin.' : 'Temiz.',
			array( 'fix' => $n > 1000 ? self::fix_action( 'wc_sessions', 'Oturumları temizle' ) : null )
		);

		// HPOS.
		$hpos = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		$out[] = self::item(
			'hpos',
			'Yüksek performanslı sipariş depolama (HPOS)',
			$hpos ? 'good' : 'info',
			$hpos ? 'Etkin' : 'Kapalı',
			'medium',
			'HPOS, siparişleri yazılarla aynı tabloda değil kendi optimize tablolarında tutar. Sipariş sayısı arttıkça yönetim paneli ve raporlar belirgin şekilde hızlanır.',
			$hpos ? 'Siparişler yeni tablolarda tutuluyor.' : 'Eklentilerinizin HPOS uyumlu olduğundan emin olduktan sonra WooCommerce › Ayarlar › Gelişmiş › Özellikler ekranından etkinleştirin.',
			array( 'fix' => $hpos ? null : self::fix_link( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' ), 'Ayara git' ) )
		);

		// Sepet parçaları.
		$out[] = self::item(
			'cart_fragments',
			'Sepet parçaları (cart fragments)',
			empty( $opt['disable_cart_fragments'] ) ? 'info' : 'good',
			empty( $opt['disable_cart_fragments'] ) ? 'Varsayılan' : 'Sınırlandırıldı',
			'medium',
			'Mini sepet kullanan temalarda her sayfa açılışında sepeti güncellemek için ek bir AJAX isteği yapılır. Bu istek önbelleği atlar ve doğrudan sunucuyu çalıştırır.',
			empty( $opt['disable_cart_fragments'] ) ? 'Başlıkta canlı güncellenen bir mini sepete ihtiyacınız yoksa Optimizasyonlar sekmesinden yalnızca sepet ve ödeme sayfalarında çalışacak şekilde sınırlayın.' : 'Sınırlandırılmış.',
			array( 'fix' => empty( $opt['disable_cart_fragments'] ) ? self::fix_tab( 'optimize', 'Ayarı aç' ) : null )
		);

		return $out;
	}

	/* ------------------------------------------------------------------
	 * PUAN VE KAYIT
	 * ------------------------------------------------------------------ */

	public static function penalty( $c ) {
		$p = array(
			'critical' => array( 'high' => 15, 'medium' => 10, 'low' => 5 ),
			'warning'  => array( 'high' => 7, 'medium' => 4, 'low' => 2 ),
		);
		return isset( $p[ $c['status'] ][ $c['impact'] ] ) ? $p[ $c['status'] ][ $c['impact'] ] : 0;
	}

	public static function compile( array $checks ) {
		$score  = 100;
		$counts = array( 'critical' => 0, 'warning' => 0, 'good' => 0, 'info' => 0 );
		foreach ( $checks as $c ) {
			$score -= self::penalty( $c );
			if ( isset( $counts[ $c['status'] ] ) ) {
				$counts[ $c['status'] ]++;
			}
		}
		$score = max( 0, min( 100, $score ) );
		$data  = array(
			'time'   => time(),
			'score'  => $score,
			'counts' => $counts,
			'checks' => $checks,
		);
		update_option( self::SCAN_OPTION, $data, false );

		$hist   = (array) get_option( self::HISTORY_OPTION, array() );
		$hist[] = array( 't' => $data['time'], 's' => $score );
		$hist   = array_slice( $hist, -14 );
		update_option( self::HISTORY_OPTION, $hist, false );

		return $data;
	}

	public static function grade( $score ) {
		if ( $score >= 90 ) {
			return 'A';
		}
		if ( $score >= 80 ) {
			return 'B';
		}
		if ( $score >= 65 ) {
			return 'C';
		}
		if ( $score >= 50 ) {
			return 'D';
		}
		return 'F';
	}

	public static function verdict( $score, $critical = null ) {
		if ( $score < 45 && 0 === $critical ) {
			$score = 45;
		}
		if ( $score >= 85 ) {
			return array( 'Sağlıklı', 'Siteniz iyi durumda. Kalan küçük iyileştirmeler isteğe bağlı.' );
		}
		if ( $score >= 65 ) {
			return array( 'İyileştirilebilir', 'Birkaç önemli sorun var. Öncelikli reçeteyi uygulamak belirgin hız kazandırır.' );
		}
		if ( $score >= 45 ) {
			return array( 'Yorgun', 'Siteniz gereğinden fazla yük taşıyor. Kritik bulgularla başlayın.' );
		}
		return array( 'Kritik', 'Ziyaretçileriniz yavaşlığı hissediyor. Aşağıdaki reçeteyi sırayla uygulayın.' );
	}

	/**
	 * Etkisine göre sıralanmış öncelikli sorunlar.
	 */
	public static function priorities( array $checks, $limit = 6 ) {
		$issues = array_filter(
			$checks,
			function ( $c ) {
				return in_array( $c['status'], array( 'critical', 'warning' ), true );
			}
		);
		usort(
			$issues,
			function ( $a, $b ) {
				return self::penalty( $b ) <=> self::penalty( $a );
			}
		);
		return array_slice( $issues, 0, $limit );
	}
}
