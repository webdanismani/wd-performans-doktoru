<?php
/**
 * Hafifletme ayarları: kullanıcının açtığı optimizasyonları ön yüzde uygular.
 *
 * @package WD_Performans_Doktoru
 */

defined( 'ABSPATH' ) || exit;

class WDPD_Optimizer {

	const OPTION = 'wdpd_opt';

	/** @var array|null */
	private static $opts = null;

	public static function defaults() {
		return array(
			'disable_emojis'         => 0,
			'disable_embeds'         => 0,
			'remove_jquery_migrate'  => 0,
			'disable_xmlrpc'         => 0,
			'disable_self_pingbacks' => 0,
			'head_cleanup'           => 0,
			'disable_dashicons'      => 0,
			'disable_cart_fragments' => 0,
			'heartbeat'              => 'default',
			'revisions_limit'        => 0,
		);
	}

	public static function get() {
		if ( null === self::$opts ) {
			self::$opts = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		}
		return self::$opts;
	}

	/**
	 * Panelde gösterilecek ayar tanımları.
	 */
	public static function fields() {
		$f = array(
			'heartbeat'              => array(
				'type'    => 'select',
				'label'   => 'Heartbeat API',
				'desc'    => 'Yönetim panelinde açık her sekmeden düzenli sunucu isteği gönderilir. Paylaşımlı hostinglerde CPU sınırına takılmanın sık nedenidir.',
				'impact'  => 'Sunucu yükü',
				'options' => array(
					'default'     => 'Varsayılan (dokunma)',
					'slow'        => 'Yavaşlat (60 sn)',
					'editor_only' => 'Yalnızca yazı düzenleyicide (önerilen)',
					'off_front'   => 'Ön yüzde kapat',
				),
			),
			'revisions_limit'        => array(
				'type'    => 'select',
				'label'   => 'Revizyon sınırı',
				'desc'    => 'Her yazı için saklanacak en fazla eski kopya sayısı. Mevcut revizyonları silmez; Veritabanı sekmesinden temizleyebilirsiniz.',
				'impact'  => 'Veritabanı',
				'options' => array(
					0  => 'Sınırsız (WordPress varsayılanı)',
					3  => '3 revizyon',
					5  => '5 revizyon (önerilen)',
					10 => '10 revizyon',
				),
			),
			'disable_emojis'         => array(
				'type'   => 'toggle',
				'label'  => 'Emoji betiğini kaldır',
				'desc'   => 'Modern tarayıcılar emojileri zaten gösterir. Her sayfadan bir JS dosyası ve satır içi stil kaldırılır.',
				'impact' => 'Sayfa yükü',
			),
			'disable_embeds'         => array(
				'type'   => 'toggle',
				'label'  => 'oEmbed betiğini kaldır',
				'desc'   => 'Başka WordPress sitelerinin yazılarınızı kart olarak gömmesini sağlayan betik. YouTube gömme işlemleri etkilenmez.',
				'impact' => 'Sayfa yükü',
			),
			'remove_jquery_migrate'  => array(
				'type'   => 'toggle',
				'label'  => 'jQuery Migrate\'i kaldır (ön yüz)',
				'desc'   => 'Eski jQuery kodları için uyumluluk katmanı. Güncel tema ve eklentilerde gereksizdir. Açtıktan sonra sitenizde slider/menü gibi öğeleri kontrol edin.',
				'impact' => 'Sayfa yükü',
			),
			'disable_dashicons'      => array(
				'type'   => 'toggle',
				'label'  => 'Ziyaretçiler için Dashicons\'u kaldır',
				'desc'   => 'Yönetim simge yazı tipi (~45 KB) giriş yapmamış ziyaretçilere yüklenmez. Temanız Dashicons kullanıyorsa açmayın.',
				'impact' => 'Sayfa yükü',
			),
			'head_cleanup'           => array(
				'type'   => 'toggle',
				'label'  => '<head> fazlalıklarını temizle',
				'desc'   => 'RSD, Windows Live Writer, kısa bağlantı ve sürüm etiketi gibi kullanılmayan satırlar kaldırılır.',
				'impact' => 'Sayfa yükü',
			),
			'disable_xmlrpc'         => array(
				'type'   => 'toggle',
				'label'  => 'XML-RPC\'yi kapat',
				'desc'   => 'Botların şifre denemesi için en çok kullandığı kapıdır ve sunucuyu yorar. Jetpack veya WordPress mobil uygulamasını kullanıyorsanız açmayın.',
				'impact' => 'Sunucu yükü',
			),
			'disable_self_pingbacks' => array(
				'type'   => 'toggle',
				'label'  => 'Kendine pingback göndermeyi engelle',
				'desc'   => 'Kendi yazılarınıza verdiğiniz bağlantılar için sitenin kendine bildirim göndermesini ve gereksiz yorum oluşmasını engeller.',
				'impact' => 'Sunucu yükü',
			),
		);
		if ( WDPD_Checks::wc_active() ) {
			$f['disable_cart_fragments'] = array(
				'type'   => 'toggle',
				'label'  => 'Sepet parçalarını sınırla (WooCommerce)',
				'desc'   => 'Önbelleği atlayan sepet güncelleme isteği yalnızca sepet ve ödeme sayfalarında çalışır. Başlıkta canlı güncellenen mini sepet kullanıyorsanız açmayın.',
				'impact' => 'Sunucu yükü',
			);
		}
		return $f;
	}

	public static function heartbeat_label( $v ) {
		$f = self::fields();
		return isset( $f['heartbeat']['options'][ $v ] ) ? $f['heartbeat']['options'][ $v ] : $v;
	}

	public static function save( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = self::defaults();
		foreach ( self::fields() as $key => $field ) {
			if ( 'toggle' === $field['type'] ) {
				$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			} elseif ( isset( $input[ $key ] ) && array_key_exists( $input[ $key ], $field['options'] ) ) {
				$clean[ $key ] = is_int( key( $field['options'] ) ) ? (int) $input[ $key ] : sanitize_key( $input[ $key ] );
			}
		}
		update_option( self::OPTION, $clean, true );
		self::$opts = $clean;
		return $clean;
	}

	/* ---------------- Uygulama ---------------- */

	public static function init() {
		$o = self::get();

		if ( $o['disable_emojis'] ) {
			add_action( 'init', array( __CLASS__, 'apply_emojis' ) );
		}
		if ( $o['disable_embeds'] ) {
			add_action( 'init', array( __CLASS__, 'apply_embeds' ), 9999 );
		}
		if ( $o['remove_jquery_migrate'] ) {
			add_action( 'wp_default_scripts', array( __CLASS__, 'apply_jquery_migrate' ) );
		}
		if ( $o['disable_xmlrpc'] ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
			add_filter( 'wp_headers', array( __CLASS__, 'remove_pingback_header' ) );
			remove_action( 'wp_head', 'rsd_link' );
		}
		if ( $o['disable_self_pingbacks'] ) {
			add_action( 'pre_ping', array( __CLASS__, 'apply_self_pingbacks' ) );
		}
		if ( $o['head_cleanup'] ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'wp_head', 'wp_generator' );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}
		if ( $o['disable_dashicons'] ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'apply_dashicons' ), 100 );
		}
		if ( $o['disable_cart_fragments'] ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'apply_cart_fragments' ), 999 );
		}
		if ( (int) $o['revisions_limit'] > 0 ) {
			add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'apply_revisions' ), 20 );
		}
		switch ( $o['heartbeat'] ) {
			case 'slow':
				add_filter( 'heartbeat_settings', array( __CLASS__, 'heartbeat_slow' ) );
				break;
			case 'editor_only':
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'heartbeat_editor_only' ), 1 );
				add_action( 'wp_enqueue_scripts', array( __CLASS__, 'heartbeat_off' ), 1 );
				add_filter( 'heartbeat_settings', array( __CLASS__, 'heartbeat_slow' ) );
				break;
			case 'off_front':
				add_action( 'wp_enqueue_scripts', array( __CLASS__, 'heartbeat_off' ), 1 );
				break;
		}
	}

	public static function apply_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter(
			'tiny_mce_plugins',
			function ( $plugins ) {
				return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
			}
		);
		add_filter(
			'wp_resource_hints',
			function ( $urls, $type ) {
				if ( 'dns-prefetch' === $type ) {
					$urls = array_filter(
						$urls,
						function ( $u ) {
							return false === strpos( is_array( $u ) ? ( isset( $u['href'] ) ? $u['href'] : '' ) : $u, 'svn/emoji' );
						}
					);
				}
				return $urls;
			},
			10,
			2
		);
	}

	public static function apply_embeds() {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		add_filter( 'embed_oembed_discover', '__return_false' );
		add_action(
			'wp_footer',
			function () {
				wp_dequeue_script( 'wp-embed' );
			}
		);
	}

	public static function apply_jquery_migrate( $scripts ) {
		if ( is_admin() || ! isset( $scripts->registered['jquery'] ) ) {
			return;
		}
		$jq = $scripts->registered['jquery'];
		if ( $jq->deps ) {
			$jq->deps = array_values( array_diff( $jq->deps, array( 'jquery-migrate' ) ) );
		}
	}

	public static function remove_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public static function apply_self_pingbacks( &$links ) {
		$home = home_url();
		foreach ( $links as $k => $link ) {
			if ( 0 === strpos( $link, $home ) ) {
				unset( $links[ $k ] );
			}
		}
	}

	public static function apply_dashicons() {
		if ( ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}

	public static function apply_cart_fragments() {
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			return;
		}
		wp_dequeue_script( 'wc-cart-fragments' );
	}

	public static function apply_revisions() {
		return (int) self::get()['revisions_limit'];
	}

	public static function heartbeat_slow( $settings ) {
		$settings['interval'] = 60;
		return $settings;
	}

	public static function heartbeat_editor_only( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	public static function heartbeat_off() {
		wp_deregister_script( 'heartbeat' );
	}
}
