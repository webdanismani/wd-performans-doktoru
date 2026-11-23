<?php
/**
 * Güvenli temizlik ve düzeltme işlemleri.
 *
 * Büyük tablolarda zaman aşımına düşmemek için silmeler toplu (batch) yapılır;
 * işlem bitmediyse "remaining" döner ve panel aynı işlemi tekrar çağırır.
 *
 * @package WD_Performans_Doktoru
 */

defined( 'ABSPATH' ) || exit;

class WDPD_Cleaner {

	const AUTOLOAD_LOG = 'wdpd_autoload_changed';
	const BATCH        = 1000;
	const TIME_BOX     = 15;

	/** @var array|null */
	private static $counts = null;

	/**
	 * Temizlik görevleri. "safe" olanlar "Güvenli temizliği çalıştır" ile toplu yapılır.
	 */
	public static function tasks() {
		$t = array(
			'transients'         => array(
				'label' => 'Süresi dolmuş geçici veriler',
				'desc'  => 'Eklentilerin süresi geçmiş önbellek kayıtları. Aktif önbelleklere dokunulmaz.',
				'safe'  => true,
			),
			'revisions'          => array(
				'label' => 'Yazı revizyonları',
				'desc'  => 'Yazı ve sayfaların eski kayıt kopyaları. Yayındaki içerik etkilenmez.',
				'safe'  => true,
			),
			'autodrafts'         => array(
				'label' => 'Otomatik taslaklar',
				'desc'  => '"Yeni ekle" açılıp kaydedilmeden bırakılan boş taslaklar.',
				'safe'  => true,
			),
			'spam_comments'      => array(
				'label' => 'Spam yorumlar',
				'desc'  => 'Spam olarak işaretlenmiş yorumlar ve meta verileri.',
				'safe'  => true,
			),
			'orphan_postmeta'    => array(
				'label' => 'Sahipsiz yazı meta verileri',
				'desc'  => 'Silinmiş yazılardan geride kalan meta kayıtları.',
				'safe'  => true,
			),
			'orphan_commentmeta' => array(
				'label' => 'Sahipsiz yorum meta verileri',
				'desc'  => 'Silinmiş yorumlardan geride kalan meta kayıtları.',
				'safe'  => true,
			),
			'oembed_cache'       => array(
				'label' => 'oEmbed önbelleği',
				'desc'  => 'Gömülü YouTube/Twitter önizlemelerinin önbelleği. Gerektiğinde yeniden oluşturulur.',
				'safe'  => true,
			),
			'trash_posts'        => array(
				'label'   => 'Çöp kutusundaki yazılar',
				'desc'    => 'Çöpe taşınmış yazı, sayfa ve ürünler kalıcı olarak silinir.',
				'safe'    => false,
				'confirm' => 'Çöp kutusundaki tüm içerikler kalıcı olarak silinecek ve geri alınamaz. Devam edilsin mi?',
			),
			'trash_comments'     => array(
				'label'   => 'Çöpteki yorumlar',
				'desc'    => 'Çöpe taşınmış yorumlar kalıcı olarak silinir.',
				'safe'    => false,
				'confirm' => 'Çöpteki yorumlar kalıcı olarak silinecek. Devam edilsin mi?',
			),
		);
		if ( WDPD_Checks::wc_active() ) {
			$t['action_scheduler'] = array(
				'label' => 'WooCommerce kuyruk kayıtları',
				'desc'  => '7 günden eski tamamlanmış, iptal edilmiş ve hatalı Action Scheduler görevleri ile logları.',
				'safe'  => true,
			);
			$t['wc_sessions']      = array(
				'label' => 'Süresi dolmuş müşteri oturumları',
				'desc'  => 'Terk edilmiş ve süresi geçmiş sepet oturumları.',
				'safe'  => true,
			);
		}
		return $t;
	}

	/**
	 * Her görev için silinebilecek kayıt sayısı.
	 */
	public static function counts( $refresh = false ) {
		global $wpdb;
		if ( null !== self::$counts && ! $refresh ) {
			return self::$counts;
		}
		$now = time();
		$c   = array();

		$c['transients'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				$now
			)
		);
		$c['revisions']          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$c['autodrafts']         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
		$c['trash_posts']        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );
		$c['spam_comments']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
		$c['trash_comments']     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('trash','post-trashed')" );
		$c['orphan_postmeta']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" );
		$c['orphan_commentmeta'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL" );
		$c['oembed_cache']       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_oembed_' ) . '%' ) );

		$c['action_scheduler'] = 0;
		$c['wc_sessions']      = 0;
		$at                    = $wpdb->prefix . 'actionscheduler_actions';
		if ( WDPD_Util::table_exists( $at ) ) {
			$c['action_scheduler'] = (int) $wpdb->get_var( self::as_where_sql( 'SELECT COUNT(*)' ) ); // phpcs:ignore
		}
		$st = $wpdb->prefix . 'woocommerce_sessions';
		if ( WDPD_Util::table_exists( $st ) ) {
			$c['wc_sessions'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$st} WHERE session_expiry < %d", $now ) ); // phpcs:ignore
		}

		self::$counts = $c;
		return $c;
	}

	private static function as_where_sql( $select ) {
		global $wpdb;
		$at     = $wpdb->prefix . 'actionscheduler_actions';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		return $wpdb->prepare(
			"{$select} FROM {$at} WHERE status IN ('complete','failed','canceled') AND ( last_attempt_gmt < %s OR last_attempt_gmt IS NULL )", // phpcs:ignore
			$cutoff
		);
	}

	/**
	 * İşlemi çalıştırır.
	 *
	 * @return array|WP_Error
	 */
	public static function run( $action, $arg = '' ) {
		WDPD_Util::extend_time( 120 );
		$tasks = self::tasks();

		if ( isset( $tasks[ $action ] ) ) {
			$method = 'clean_' . $action;
			$done   = (int) call_user_func( array( __CLASS__, $method ) );
			$left   = self::counts( true );
			$left   = isset( $left[ $action ] ) ? (int) $left[ $action ] : 0;
			return array(
				'done'      => $done,
				'remaining' => $left,
				'message'   => $left ? WDPD_Util::num( $done ) . ' kayıt silindi, devam ediliyor…' : $tasks[ $action ]['label'] . ': ' . WDPD_Util::num( $done ) . ' kayıt temizlendi.',
			);
		}

		switch ( $action ) {
			case 'truncate_debug_log':
				return self::truncate_debug_log();
			case 'optimize_tables':
				return self::optimize_tables();
			case 'convert_innodb':
				return self::convert_innodb();
			case 'delete_cron_hook':
				return self::delete_cron_hook( $arg );
			case 'autoload_off':
				return self::autoload_off( $arg );
			case 'autoload_restore':
				return self::autoload_restore( $arg );
		}
		return new WP_Error( 'wdpd_unknown', 'Bilinmeyen işlem.' );
	}

	/* ---------------- Temizlik görevleri ---------------- */

	private static function clean_transients() {
		global $wpdb;
		$before = self::counts( true )['transients'];
		if ( function_exists( 'delete_expired_transients' ) ) {
			delete_expired_transients( true );
		}
		// Çekirdek fonksiyonu yetişmezse (çok eski WP) elle temizle.
		$now  = time();
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 5000",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);
		foreach ( $rows as $name ) {
			$key = substr( $name, strlen( '_transient_timeout_' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", '_transient_' . $key, $name ) );
		}
		wp_cache_delete( 'alloptions', 'options' );
		return max( 0, $before - self::counts( true )['transients'] );
	}

	/**
	 * Yazıları ID listesiyle ham SQL ile siler (revizyon/otomatik taslak için güvenli).
	 */
	private static function delete_posts_raw( $where ) {
		global $wpdb;
		$done  = 0;
		$start = microtime( true );
		do {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE {$where} LIMIT " . self::BATCH ); // phpcs:ignore
			if ( ! $ids ) {
				break;
			}
			$in = implode( ',', array_map( 'intval', $ids ) );
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$in})" ); // phpcs:ignore
			$done += (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" ); // phpcs:ignore
		} while ( microtime( true ) - $start < self::TIME_BOX );
		return $done;
	}

	private static function clean_revisions() {
		return self::delete_posts_raw( "post_type = 'revision'" );
	}

	private static function clean_autodrafts() {
		return self::delete_posts_raw( "post_status = 'auto-draft'" );
	}

	/**
	 * Çöpteki yazılar ekleri ve kancalarıyla birlikte düzgün silinsin diye çekirdek fonksiyonla silinir.
	 */
	private static function clean_trash_posts() {
		global $wpdb;
		$done  = 0;
		$start = microtime( true );
		do {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT 100" );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				if ( wp_delete_post( (int) $id, true ) ) {
					$done++;
				}
			}
		} while ( microtime( true ) - $start < self::TIME_BOX );
		return $done;
	}

	private static function delete_comments_raw( $where ) {
		global $wpdb;
		$done  = 0;
		$start = microtime( true );
		do {
			$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE {$where} LIMIT " . self::BATCH ); // phpcs:ignore
			if ( ! $ids ) {
				break;
			}
			$in = implode( ',', array_map( 'intval', $ids ) );
			$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ({$in})" ); // phpcs:ignore
			$done += (int) $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ({$in})" ); // phpcs:ignore
		} while ( microtime( true ) - $start < self::TIME_BOX );

		if ( $done ) {
			// Yorum sayaçlarını güncelle.
			$posts = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE comment_count > 0 LIMIT 5000" );
			foreach ( $posts as $pid ) {
				wp_update_comment_count_now( (int) $pid );
			}
		}
		return $done;
	}

	private static function clean_spam_comments() {
		return self::delete_comments_raw( "comment_approved = 'spam'" );
	}

	private static function clean_trash_comments() {
		return self::delete_comments_raw( "comment_approved IN ('trash','post-trashed')" );
	}

	private static function clean_orphan_postmeta() {
		global $wpdb;
		$done  = 0;
		$start = microtime( true );
		do {
			$ids = $wpdb->get_col( "SELECT pm.meta_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL LIMIT 5000" );
			if ( ! $ids ) {
				break;
			}
			$done += (int) $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore
		} while ( microtime( true ) - $start < self::TIME_BOX );
		return $done;
	}

	private static function clean_orphan_commentmeta() {
		global $wpdb;
		$done  = 0;
		$start = microtime( true );
		do {
			$ids = $wpdb->get_col( "SELECT cm.meta_id FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL LIMIT 5000" );
			if ( ! $ids ) {
				break;
			}
			$done += (int) $wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE meta_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore
		} while ( microtime( true ) - $start < self::TIME_BOX );
		return $done;
	}

	private static function clean_oembed_cache() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_oembed_' ) . '%' ) );
	}

	private static function clean_action_scheduler() {
		global $wpdb;
		$at = $wpdb->prefix . 'actionscheduler_actions';
		$lt = $wpdb->prefix . 'actionscheduler_logs';
		if ( ! WDPD_Util::table_exists( $at ) ) {
			return 0;
		}
		$has_logs = WDPD_Util::table_exists( $lt );
		$done     = 0;
		$start    = microtime( true );
		do {
			$ids = $wpdb->get_col( self::as_where_sql( 'SELECT action_id' ) . ' LIMIT 2000' ); // phpcs:ignore
			if ( ! $ids ) {
				break;
			}
			$in = implode( ',', array_map( 'intval', $ids ) );
			if ( $has_logs ) {
				$wpdb->query( "DELETE FROM {$lt} WHERE action_id IN ({$in})" ); // phpcs:ignore
			}
			$done += (int) $wpdb->query( "DELETE FROM {$at} WHERE action_id IN ({$in})" ); // phpcs:ignore
		} while ( microtime( true ) - $start < self::TIME_BOX );

		// Eylemi silinmiş log kayıtları.
		if ( $has_logs && microtime( true ) - $start < self::TIME_BOX ) {
			$wpdb->query( "DELETE l FROM {$lt} l LEFT JOIN {$at} a ON a.action_id = l.action_id WHERE a.action_id IS NULL" ); // phpcs:ignore
		}
		return $done;
	}

	private static function clean_wc_sessions() {
		global $wpdb;
		$st = $wpdb->prefix . 'woocommerce_sessions';
		if ( ! WDPD_Util::table_exists( $st ) ) {
			return 0;
		}
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$st} WHERE session_expiry < %d", time() ) ); // phpcs:ignore
	}

	/* ---------------- Diğer düzeltmeler ---------------- */

	public static function debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && WP_DEBUG_LOG && ! in_array( strtolower( WP_DEBUG_LOG ), array( 'true', '1', 'false', '0' ), true ) ) {
			return WP_DEBUG_LOG;
		}
		return WP_CONTENT_DIR . '/debug.log';
	}

	private static function truncate_debug_log() {
		$path = self::debug_log_path();
		$real = realpath( $path );
		if ( ! $real || ! is_file( $real ) || '.log' !== substr( strtolower( $real ), -4 ) ) {
			return new WP_Error( 'wdpd_log', 'Günlük dosyası bulunamadı.' );
		}
		$roots = array_filter( array( realpath( ABSPATH ), realpath( WP_CONTENT_DIR ), realpath( dirname( ABSPATH ) ) ) );
		$ok    = false;
		foreach ( $roots as $root ) {
			if ( 0 === strpos( $real, $root ) ) {
				$ok = true;
			}
		}
		if ( ! $ok || ! is_writable( $real ) ) {
			return new WP_Error( 'wdpd_log', 'Günlük dosyasına yazma izni yok.' );
		}
		$size = filesize( $real );
		file_put_contents( $real, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array(
			'done'      => 1,
			'remaining' => 0,
			'message'   => 'debug.log boşaltıldı (' . WDPD_Util::bytes( $size ) . ' yer açıldı).',
		);
	}

	private static function optimize_tables() {
		global $wpdb;
		$start = microtime( true );
		$done  = 0;
		$left  = 0;
		foreach ( WDPD_Checks::tables() as $t ) {
			if ( (int) $t['free'] < MB_IN_BYTES ) {
				continue;
			}
			if ( microtime( true ) - $start > 40 ) {
				$left++;
				continue;
			}
			$wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $t['name'] ) . '`' ); // phpcs:ignore
			$done++;
		}
		return array(
			'done'      => $done,
			'remaining' => 0,
			'message'   => $done ? $done . ' tablo optimize edildi.' . ( $left ? ' ' . $left . ' tablo için işlemi tekrar çalıştırın.' : '' ) : 'Optimize edilecek belirgin boşluk bulunmadı.',
		);
	}

	private static function convert_innodb() {
		global $wpdb;
		$start = microtime( true );
		$done  = 0;
		$fail  = array();
		foreach ( WDPD_Checks::tables() as $t ) {
			if ( 'MyISAM' !== $t['engine'] ) {
				continue;
			}
			if ( microtime( true ) - $start > 40 ) {
				break;
			}
			$r = $wpdb->query( 'ALTER TABLE `' . esc_sql( $t['name'] ) . '` ENGINE=InnoDB' ); // phpcs:ignore
			if ( false === $r ) {
				$fail[] = $t['name'];
			} else {
				$done++;
			}
		}
		return array(
			'done'      => $done,
			'remaining' => 0,
			'message'   => $done . ' tablo InnoDB\'ye dönüştürüldü.' . ( $fail ? ' Dönüştürülemeyen: ' . implode( ', ', $fail ) : '' ),
		);
	}

	private static function delete_cron_hook( $hook ) {
		$hook = (string) $hook;
		$orph = WDPD_Checks::orphan_cron_hooks();
		if ( '' === $hook || ! isset( $orph[ $hook ] ) ) {
			return new WP_Error( 'wdpd_cron', 'Bu görev sahipsiz listesinde değil; güvenlik için kaldırılmadı.' );
		}
		$n = function_exists( 'wp_unschedule_hook' ) ? wp_unschedule_hook( $hook ) : wp_clear_scheduled_hook( $hook );
		return array(
			'done'      => (int) $n,
			'remaining' => 0,
			'message'   => $hook . ' zamanlamadan kaldırıldı.',
		);
	}

	/* ---------------- Autoload yönetimi ---------------- */

	/**
	 * Çekirdeğin her istekte ihtiyaç duyduğu, dokunulmaması gereken ayarlar.
	 */
	public static function is_protected_option( $name ) {
		global $wpdb;
		$exact = array(
			'siteurl', 'home', 'blogname', 'blogdescription', 'active_plugins', 'cron', 'rewrite_rules', 'template', 'stylesheet',
			$wpdb->prefix . 'user_roles', 'sidebars_widgets', 'uninstall_plugins', 'recently_activated', 'permalink_structure',
			'WPLANG', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'db_version', 'initial_db_version',
			'show_on_front', 'page_on_front', 'page_for_posts', 'users_can_register', 'default_role', 'blog_charset', 'can_compress_scripts',
			'current_theme', 'site_icon', 'auto_update_plugins', 'auto_update_themes', 'wp_force_deactivated_plugins', WDPD_Optimizer::OPTION,
		);
		if ( in_array( $name, $exact, true ) ) {
			return true;
		}
		foreach ( array( 'widget_', 'theme_mods_', '_transient_', '_site_transient_' ) as $p ) {
			if ( 0 === strpos( $name, $p ) ) {
				return true;
			}
		}
		return false;
	}

	private static function autoload_off( $name ) {
		global $wpdb;
		$name = (string) $name;
		if ( '' === $name || self::is_protected_option( $name ) ) {
			return new WP_Error( 'wdpd_protected', 'Bu ayar WordPress çekirdeği için gereklidir; değiştirilmedi.' );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT autoload, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( ! $row ) {
			return new WP_Error( 'wdpd_missing', 'Ayar bulunamadı.' );
		}
		if ( ! in_array( $row->autoload, WDPD_Util::autoload_values(), true ) ) {
			return array(
				'done'      => 0,
				'remaining' => 0,
				'message'   => 'Bu ayar zaten otomatik yüklenmiyor.',
			);
		}

		$log          = (array) get_option( self::AUTOLOAD_LOG, array() );
		$log[ $name ] = array(
			'orig' => $row->autoload,
			'size' => (int) $row->size,
			'time' => time(),
		);
		update_option( self::AUTOLOAD_LOG, $log, false );

		$off = in_array( 'on', WDPD_Util::autoload_values(), true ) ? 'off' : 'no';
		$wpdb->update( $wpdb->options, array( 'autoload' => $off ), array( 'option_name' => $name ) );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( $name, 'options' );

		return array(
			'done'      => 1,
			'remaining' => 0,
			'message'   => $name . ' artık otomatik yüklenmiyor (' . WDPD_Util::bytes( $row->size ) . ' kazanıldı).',
		);
	}

	private static function autoload_restore( $name ) {
		global $wpdb;
		$name = (string) $name;
		$log  = (array) get_option( self::AUTOLOAD_LOG, array() );
		if ( ! isset( $log[ $name ] ) ) {
			return new WP_Error( 'wdpd_missing', 'Bu ayar için kayıt bulunamadı.' );
		}
		$wpdb->update( $wpdb->options, array( 'autoload' => $log[ $name ]['orig'] ), array( 'option_name' => $name ) );
		unset( $log[ $name ] );
		update_option( self::AUTOLOAD_LOG, $log, false );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( $name, 'options' );
		return array(
			'done'      => 1,
			'remaining' => 0,
			'message'   => $name . ' eski haline getirildi.',
		);
	}
}
