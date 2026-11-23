<?php
/**
 * Yönetim paneli: menü, sayfa, AJAX uçları ve pano bileşeni.
 *
 * @package WD_Performans_Doktoru
 */

defined( 'ABSPATH' ) || exit;

class WDPD_Admin {

	const PAGE  = 'wd-performans-doktoru';
	const NONCE = 'wdpd_nonce';

	/** @var string */
	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WDPD_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );

		foreach ( array( 'scan_start', 'scan_step', 'scan_finish', 'fix', 'save_opts' ) as $a ) {
			add_action( 'wp_ajax_wdpd_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Menü ve varlıklar
	 * ------------------------------------------------------------------ */

	public static function menu() {
		$scan  = get_option( WDPD_Checks::SCAN_OPTION );
		$crit  = $scan && ! empty( $scan['counts']['critical'] ) ? (int) $scan['counts']['critical'] : 0;
		$title = 'Performans' . ( $crit ? ' <span class="awaiting-mod">' . $crit . '</span>' : '' );
		$icon  = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17Zm3.9 9.3h-1.6l-1.4 3.1a.8.8 0 0 1-1.5-.1L8.1 8.6l-.9 1.8a.8.8 0 0 1-.7.4H4.4a.8.8 0 0 1 0-1.6h1.6l1.4-2.9a.8.8 0 0 1 1.5.1l1.3 5 .8-1.7a.8.8 0 0 1 .7-.5h2.2a.8.8 0 0 1 0 1.6Z"/></svg>' ); // phpcs:ignore

		self::$hook = add_menu_page( 'Performans Doktoru', $title, 'manage_options', self::PAGE, array( __CLASS__, 'render' ), $icon, 81 );
	}

	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::$hook && $screen->id === self::$hook ) {
			$classes .= ' wdpd-screen';
		}
		return $classes;
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'wdpd-admin', WDPD_URL . 'assets/css/admin.css', array(), WDPD_VERSION );
		wp_enqueue_script( 'wdpd-admin', WDPD_URL . 'assets/js/admin.js', array(), WDPD_VERSION, true );

		$cats = array();
		foreach ( WDPD_Checks::categories() as $k => $c ) {
			$cats[] = array(
				'key'   => $k,
				'label' => $c['label'],
				'desc'  => $c['desc'],
			);
		}
		wp_localize_script(
			'wdpd-admin',
			'WDPD',
			array(
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE ),
				'categories' => $cats,
				'pageUrl'    => admin_url( 'admin.php?page=' . self::PAGE ),
			)
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Panel</a>' );
		$links[] = '<a href="' . esc_url( WDPD_SUPPORT_URL ) . '" target="_blank" rel="noopener">Destek</a>';
		return $links;
	}

	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( WDPD_FILE ) === $file ) {
			$meta[] = '<a href="' . esc_url( WDPD_SUPPORT_URL ) . '" target="_blank" rel="noopener">Webmaster destek forumu (oblifex.com)</a>';
		}
		return $meta;
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	private static function guard() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Oturum süresi doldu. Sayfayı yenileyip tekrar deneyin.' ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Bu işlem için yönetici yetkisi gerekiyor.' ), 403 );
		}
	}

	private static function tmp_key() {
		return 'wdpd_scan_tmp_' . get_current_user_id();
	}

	public static function ajax_scan_start() {
		self::guard();
		delete_transient( self::tmp_key() );
		wp_send_json_success();
	}

	public static function ajax_scan_step() {
		self::guard();
		$cat   = isset( $_POST['cat'] ) ? sanitize_key( wp_unslash( $_POST['cat'] ) ) : '';
		$items = WDPD_Checks::run( $cat );
		$tmp   = get_transient( self::tmp_key() );
		$tmp   = is_array( $tmp ) ? $tmp : array();
		$tmp[ $cat ] = $items;
		set_transient( self::tmp_key(), $tmp, HOUR_IN_SECONDS );

		$issues = 0;
		foreach ( $items as $i ) {
			if ( in_array( $i['status'], array( 'critical', 'warning' ), true ) ) {
				$issues++;
			}
		}
		wp_send_json_success(
			array(
				'count'  => count( $items ),
				'issues' => $issues,
			)
		);
	}

	public static function ajax_scan_finish() {
		self::guard();
		$tmp = get_transient( self::tmp_key() );
		if ( ! is_array( $tmp ) || ! $tmp ) {
			wp_send_json_error( array( 'message' => 'Tarama verisi bulunamadı. Taramayı yeniden başlatın.' ) );
		}
		$checks = array();
		foreach ( array_keys( WDPD_Checks::categories() ) as $cat ) {
			if ( isset( $tmp[ $cat ] ) ) {
				$checks = array_merge( $checks, $tmp[ $cat ] );
			}
		}
		$data = WDPD_Checks::compile( $checks );
		delete_transient( self::tmp_key() );
		wp_send_json_success(
			array(
				'score'  => $data['score'],
				'grade'  => WDPD_Checks::grade( $data['score'] ),
				'counts' => $data['counts'],
			)
		);
	}

	public static function ajax_fix() {
		self::guard();
		$action = isset( $_POST['fix'] ) ? sanitize_key( wp_unslash( $_POST['fix'] ) ) : '';
		$arg    = isset( $_POST['arg'] ) ? sanitize_text_field( wp_unslash( $_POST['arg'] ) ) : '';
		$res    = WDPD_Cleaner::run( $action, $arg );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	public static function ajax_save_opts() {
		self::guard();
		$raw = isset( $_POST['opts'] ) && is_array( $_POST['opts'] ) ? map_deep( wp_unslash( $_POST['opts'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore
		WDPD_Optimizer::save( $raw );
		wp_send_json_success( array( 'message' => 'Optimizasyon ayarları kaydedildi.' ) );
	}

	/* ------------------------------------------------------------------
	 * Pano bileşeni
	 * ------------------------------------------------------------------ */

	public static function dashboard_widget() {
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'wdpd_dashboard', 'Performans Doktoru', array( __CLASS__, 'render_dashboard_widget' ) );
		}
	}

	public static function render_dashboard_widget() {
		$scan = get_option( WDPD_Checks::SCAN_OPTION );
		$url  = admin_url( 'admin.php?page=' . self::PAGE );
		echo '<style>.wdpd-dw{display:flex;align-items:center;gap:16px}.wdpd-dw b{font-size:40px;line-height:1;font-variant-numeric:tabular-nums}.wdpd-dw p{margin:0 0 4px}.wdpd-dw .c{color:#c8372d}.wdpd-dw .w{color:#a9740b}</style>';
		if ( ! $scan ) {
			echo '<p>Siteniz henüz taranmadı. İlk tarama yaklaşık 20 saniye sürer.</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">Siteyi tara</a></p>';
			return;
		}
		$color = $scan['score'] >= 85 ? '#2e8b57' : ( $scan['score'] >= 60 ? '#a9740b' : '#c8372d' );
		echo '<div class="wdpd-dw"><b style="color:' . esc_attr( $color ) . '">' . (int) $scan['score'] . '</b><div>';
		echo '<p><strong>' . esc_html( WDPD_Checks::verdict( $scan['score'], (int) $scan['counts']['critical'] )[0] ) . '</strong>, son tarama ' . esc_html( WDPD_Util::ago( $scan['time'] ) ) . '</p>';
		echo '<p><span class="c">' . (int) $scan['counts']['critical'] . ' kritik</span>, <span class="w">' . (int) $scan['counts']['warning'] . ' uyarı</span></p>';
		echo '</div></div><p style="margin-top:12px"><a href="' . esc_url( $url ) . '">Bulguları ve çözümleri gör</a></p>';
	}

	/* ------------------------------------------------------------------
	 * Yardımcılar
	 * ------------------------------------------------------------------ */

	public static function icon( $name, $size = 18 ) {
		$p = array(
			'server'  => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6"/>',
			'wp'      => '<circle cx="12" cy="12" r="9"/><path d="M4.5 8.5 9 20M8.2 8.5h4.6M10 8.5l3.4 10 2.8-8.2c.6-1.8.2-3-1.2-3.4M19.5 8.8l-3.3 9.7"/>',
			'db'      => '<ellipse cx="12" cy="5.5" rx="8" ry="2.8"/><path d="M4 5.5v6.5c0 1.5 3.6 2.8 8 2.8s8-1.3 8-2.8V5.5M4 12v6.5c0 1.5 3.6 2.8 8 2.8s8-1.3 8-2.8V12"/>',
			'gauge'   => '<path d="M4.2 17.5a9 9 0 1 1 15.6 0"/><path d="m12 13 4-5"/><circle cx="12" cy="13" r="1.4"/>',
			'cart'    => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3.5h2.6l2.4 11.2a1.6 1.6 0 0 0 1.6 1.3h8.3a1.6 1.6 0 0 0 1.5-1.2L21 7.5H6.1"/>',
			'check'   => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
			'alert'   => '<path d="M10.3 4.2 2.8 17.5A2 2 0 0 0 4.5 20.5h15a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0Z"/><path d="M12 9.5v4M12 17h.01"/>',
			'octagon' => '<path d="M8.2 2.8h7.6l5.4 5.4v7.6l-5.4 5.4H8.2l-5.4-5.4V8.2Z"/><path d="M12 8v5M12 16.5h.01"/>',
			'info'    => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8h.01"/>',
			'scan'    => '<path d="M3 12h3.5l2.5-6 4 12 2.5-6H21"/>',
			'sun'     => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
			'moon'    => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/>',
			'broom'   => '<path d="m14 4 6 6M17 7l-6.5 6.5M4 20c3.5 0 5.2-1 6.5-2.3l-4.2-4.2C5 14.8 4 16.5 4 20Z"/><path d="m8.5 12.3 3.2 3.2"/>',
			'sliders' => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
			'help'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.6"/><path d="m5.6 5.6 3.9 3.9M14.5 14.5l3.9 3.9M18.4 5.6l-3.9 3.9M9.5 14.5l-3.9 3.9"/>',
			'ext'     => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
			'copy'    => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5.5A1.5 1.5 0 0 0 14.5 4h-9A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8"/>',
			'chevron' => '<path d="m7 10 5 5 5-5"/>',
			'list'    => '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
			'forum'   => '<path d="M4 5h12a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H9l-4 3v-3H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/><path d="M17 9h3a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1h-1v3l-4-3h-4a1 1 0 0 1-1-1v-1"/>',
			'undo'    => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
		);
		if ( ! isset( $p[ $name ] ) ) {
			return '';
		}
		return '<svg class="wdpd-ico" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p[ $name ] . '</svg>';
	}

	private static function status_meta( $status ) {
		$m = array(
			'critical' => array( 'Kritik', 'octagon' ),
			'warning'  => array( 'Uyarı', 'alert' ),
			'good'     => array( 'Sağlıklı', 'check' ),
			'info'     => array( 'Öneri', 'info' ),
		);
		return isset( $m[ $status ] ) ? $m[ $status ] : $m['info'];
	}

	private static function impact_label( $impact ) {
		$m = array(
			'high'   => 'Yüksek etki',
			'medium' => 'Orta etki',
			'low'    => 'Düşük etki',
		);
		return isset( $m[ $impact ] ) ? $m[ $impact ] : '';
	}

	private static function val( $v ) {
		return str_replace( ' · ', ', ', (string) $v );
	}

	private static function fix_button( $fix, $arg = '', $extra_class = '' ) {
		if ( ! $fix ) {
			return '';
		}
		$cls = trim( 'wdpd-btn ' . $extra_class );
		if ( 'action' === $fix['type'] ) {
			return sprintf(
				'<button type="button" class="%s wdpd-btn--solid" data-wdpd-fix="%s" data-arg="%s" data-confirm="%s">%s</button>',
				esc_attr( $cls ),
				esc_attr( $fix['action'] ),
				esc_attr( $arg ),
				esc_attr( isset( $fix['confirm'] ) ? $fix['confirm'] : '' ),
				esc_html( $fix['label'] )
			);
		}
		if ( 'tab' === $fix['type'] ) {
			return sprintf( '<button type="button" class="%s" data-wdpd-goto="%s">%s</button>', esc_attr( $cls ), esc_attr( $fix['tab'] ), esc_html( $fix['label'] ) );
		}
		return sprintf( '<a class="%s" href="%s">%s</a>', esc_attr( $cls ), esc_url( $fix['url'] ), esc_html( $fix['label'] ) );
	}

	/**
	 * Kategori başına EKG atış çizgisi. Sağlıklı kategoriler düzenli,
	 * uyarı olanlar ekstra atımlı, kritik olanlar düzensiz çizilir.
	 */
	private static function trace_segment( $x0, $w, $state, $seed ) {
		$base  = 64;
		$beats = 3;
		$bw    = $w / $beats;
		$d     = sprintf( 'M%.1f %d', $x0, $base );
		for ( $b = 0; $b < $beats; $b++ ) {
			$x = $x0 + $b * $bw;
			$f = function ( $t ) use ( $x, $bw ) {
				return round( $x + $t * $bw, 1 );
			};
			if ( 'empty' === $state ) {
				$d .= ' L' . $f( 1 ) . ' ' . $base;
				continue;
			}
			if ( 'critical' === $state ) {
				$d .= ' L' . $f( 0.12 ) . ' ' . $base;
				for ( $k = 0; $k < 7; $k++ ) {
					$amp = 14 + abs( sin( ( $seed + $b * 7 + $k ) * 1.7 ) ) * 34;
					$dir = ( 0 === $k % 2 ) ? -1 : 0.55;
					$d  .= ' L' . $f( 0.16 + $k * 0.1 ) . ' ' . round( $base + $dir * $amp, 1 );
				}
				$d .= ' L' . $f( 0.9 ) . ' ' . $base . ' L' . $f( 1 ) . ' ' . $base;
				continue;
			}
			$r  = 'warning' === $state ? 38 : 46;
			$d .= ' L' . $f( 0.14 ) . ' ' . $base;
			$d .= ' Q' . $f( 0.19 ) . ' ' . ( $base - 9 ) . ' ' . $f( 0.24 ) . ' ' . $base;
			$d .= ' L' . $f( 0.32 ) . ' ' . $base;
			$d .= ' L' . $f( 0.35 ) . ' ' . ( $base + 6 );
			$d .= ' L' . $f( 0.4 ) . ' ' . ( $base - $r );
			$d .= ' L' . $f( 0.45 ) . ' ' . ( $base + 14 );
			$d .= ' L' . $f( 0.49 ) . ' ' . $base;
			$d .= ' L' . $f( 0.58 ) . ' ' . $base;
			$d .= ' Q' . $f( 0.66 ) . ' ' . ( $base - 15 ) . ' ' . $f( 0.74 ) . ' ' . $base;
			if ( 'warning' === $state && 1 === $b % 2 ) {
				$d .= ' L' . $f( 0.83 ) . ' ' . $base . ' L' . $f( 0.86 ) . ' ' . ( $base - 26 ) . ' L' . $f( 0.89 ) . ' ' . ( $base + 9 ) . ' L' . $f( 0.92 ) . ' ' . $base;
			}
			$d .= ' L' . $f( 1 ) . ' ' . $base;
		}
		return $d;
	}

	private static function category_state( $checks, $cat ) {
		$state = 'good';
		$n     = 0;
		foreach ( $checks as $c ) {
			if ( $c['category'] !== $cat ) {
				continue;
			}
			$n++;
			if ( 'critical' === $c['status'] ) {
				return 'critical';
			}
			if ( 'warning' === $c['status'] ) {
				$state = 'warning';
			}
		}
		return $n ? $state : 'empty';
	}

	/* ------------------------------------------------------------------
	 * Sayfa
	 * ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$scan   = get_option( WDPD_Checks::SCAN_OPTION );
		$checks = $scan && ! empty( $scan['checks'] ) ? $scan['checks'] : array();
		$issues = $scan ? (int) $scan['counts']['critical'] + (int) $scan['counts']['warning'] : 0;
		?>
		<div class="wdpd" data-theme="light">
			<header class="wdpd-top">
				<div class="wdpd-brand">
					<span class="wdpd-brand__mark" aria-hidden="true">
						<svg viewBox="0 0 32 32" width="34" height="34"><rect width="32" height="32" rx="9" fill="currentColor"/><path d="M5 17h5l2.5-6 4 11 3-8 1.5 3H27" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</span>
					<div>
						<h1 class="wdpd-brand__name">Performans Doktoru</h1>
						<p class="wdpd-brand__by">Geliştiren <a href="<?php echo esc_url( WDPD_AUTHOR_URL ); ?>" target="_blank" rel="noopener">Web Danışmanı</a></p>
					</div>
				</div>
				<div class="wdpd-top__actions">
					<?php if ( $scan ) : ?>
						<span class="wdpd-top__last">Son tarama <?php echo esc_html( WDPD_Util::ago( $scan['time'] ) ); ?></span>
					<?php endif; ?>
					<button type="button" class="wdpd-iconbtn" data-wdpd-theme aria-label="Açık / koyu tema">
						<span class="wdpd-show-light"><?php echo self::icon( 'moon' ); // phpcs:ignore ?></span>
						<span class="wdpd-show-dark"><?php echo self::icon( 'sun' ); // phpcs:ignore ?></span>
					</button>
					<button type="button" class="wdpd-btn wdpd-btn--primary" data-wdpd-scan>
						<?php echo self::icon( 'scan' ); // phpcs:ignore ?>
						<span><?php echo $scan ? 'Yeniden tara' : 'Siteyi tara'; ?></span>
					</button>
				</div>
			</header>

			<nav class="wdpd-tabs" role="tablist" aria-label="Bölümler">
				<?php
				$tabs = array(
					'overview' => array( 'Genel bakış', 'gauge' ),
					'findings' => array( 'Bulgular', 'list' ),
					'database' => array( 'Veritabanı', 'db' ),
					'optimize' => array( 'Optimizasyonlar', 'sliders' ),
					'support'  => array( 'Destek', 'help' ),
				);
				foreach ( $tabs as $key => $t ) {
					printf(
						'<a href="#%1$s" class="wdpd-tab" role="tab" data-tab="%1$s" aria-selected="false">%2$s<span>%3$s</span>%4$s</a>',
						esc_attr( $key ),
						self::icon( $t[1], 17 ), // phpcs:ignore
						esc_html( $t[0] ),
						( 'findings' === $key && $issues ) ? '<em class="wdpd-tab__count' . ( empty( $scan['counts']['critical'] ) ? ' is-warning' : '' ) . '">' . (int) $issues . '</em>' : '' // phpcs:ignore
					);
				}
				?>
			</nav>

			<main class="wdpd-main">
				<section class="wdpd-panel" data-panel="overview" role="tabpanel"><?php self::panel_overview( $scan, $checks ); ?></section>
				<section class="wdpd-panel" data-panel="findings" role="tabpanel" hidden><?php self::panel_findings( $scan, $checks ); ?></section>
				<section class="wdpd-panel" data-panel="database" role="tabpanel" hidden><?php self::panel_database(); ?></section>
				<section class="wdpd-panel" data-panel="optimize" role="tabpanel" hidden><?php self::panel_optimize(); ?></section>
				<section class="wdpd-panel" data-panel="support" role="tabpanel" hidden><?php self::panel_support( $scan, $checks ); ?></section>
			</main>

			<footer class="wdpd-foot">
				<span>WD Performans Doktoru <?php echo esc_html( WDPD_VERSION ); ?>, <a href="<?php echo esc_url( WDPD_AUTHOR_URL ); ?>" target="_blank" rel="noopener">Web Danışmanı</a> tarafından ücretsiz paylaşılır; satılamaz.</span>
				<span>Soru ve önerileriniz için <a href="<?php echo esc_url( WDPD_SUPPORT_URL ); ?>" target="_blank" rel="noopener">oblifex.com</a> webmaster destek forumu.</span>
			</footer>

			<?php self::modal(); ?>
			<div class="wdpd-toasts" aria-live="polite"></div>
		</div>
		<?php
	}

	private static function panel_overview( $scan, $checks ) {
		$cats = WDPD_Checks::categories();

		if ( ! $scan ) {
			?>
			<div class="wdpd-monitor wdpd-monitor--empty">
				<div class="wdpd-monitor__grid" aria-hidden="true"></div>
				<svg class="wdpd-trace wdpd-trace--idle" viewBox="0 0 1000 110" preserveAspectRatio="none" aria-hidden="true">
					<path d="<?php echo esc_attr( self::trace_segment( 0, 1000, 'good', 1 ) ); ?>"/>
				</svg>
				<div class="wdpd-intro">
					<h2>Sitenizi en çok neyin yorduğunu bulalım</h2>
					<p>Tarama sunucu ayarlarını, veritabanını, eklentilerin ana sayfaya yüklediği dosyaları ve önbellek durumunu kontrol eder. Hiçbir şeyi değiştirmez; yalnızca ölçer ve çözüm önerir. Yaklaşık 20 saniye sürer.</p>
					<button type="button" class="wdpd-btn wdpd-btn--primary wdpd-btn--lg" data-wdpd-scan><?php echo self::icon( 'scan' ); // phpcs:ignore ?><span>İlk taramayı başlat</span></button>
				</div>
			</div>
			<div class="wdpd-checklist">
				<?php foreach ( $cats as $c ) : ?>
					<div class="wdpd-checklist__item">
						<?php echo self::icon( $c['icon'], 20 ); // phpcs:ignore ?>
						<div><strong><?php echo esc_html( $c['label'] ); ?></strong><span><?php echo esc_html( $c['desc'] ); ?></span></div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
			return;
		}

		$score   = (int) $scan['score'];
		$verdict = WDPD_Checks::verdict( $score, (int) $scan['counts']['critical'] );
		$tone    = $score >= 85 ? 'good' : ( $score >= 60 ? 'warning' : 'critical' );
		$prio    = WDPD_Checks::priorities( $checks, 6 );
		$hist    = (array) get_option( WDPD_Checks::HISTORY_OPTION, array() );
		$n       = count( $cats );
		$segw    = 1000 / max( 1, $n );
		?>
		<div class="wdpd-monitor is-<?php echo esc_attr( $tone ); ?>">
			<div class="wdpd-monitor__grid" aria-hidden="true"></div>
			<div class="wdpd-vitals">
				<div class="wdpd-vitals__score">
					<span class="wdpd-vitals__num"><?php echo (int) $score; ?></span>
					<span class="wdpd-vitals__of">/100</span>
				</div>
				<div class="wdpd-vitals__text">
					<p class="wdpd-vitals__verdict"><?php echo esc_html( $verdict[0] ); ?> <span class="wdpd-grade"><?php echo esc_html( WDPD_Checks::grade( $score ) ); ?></span></p>
					<p><?php echo esc_html( $verdict[1] ); ?></p>
				</div>
			</div>
			<div class="wdpd-trace-wrap">
				<svg class="wdpd-trace" viewBox="0 0 1000 110" preserveAspectRatio="none" role="img" aria-label="Kategori bazında site sağlığı">
					<?php
					$i = 0;
					foreach ( $cats as $key => $c ) {
						$state = self::category_state( $checks, $key );
						printf(
							'<path class="is-%s" style="--i:%d" d="%s"/>',
							esc_attr( $state ),
							(int) $i,
							esc_attr( self::trace_segment( $i * $segw, $segw, $state, $i * 3 + 1 ) )
						);
						$i++;
					}
					?>
				</svg>
				<div class="wdpd-trace-labels" style="--n:<?php echo (int) $n; ?>">
					<?php
					foreach ( $cats as $key => $c ) {
						$state = self::category_state( $checks, $key );
						$cc    = array( 'critical' => 0, 'warning' => 0 );
						foreach ( $checks as $ch ) {
							if ( $ch['category'] === $key && isset( $cc[ $ch['status'] ] ) ) {
								$cc[ $ch['status'] ]++;
							}
						}
						$sum = array();
						if ( $cc['critical'] ) {
							$sum[] = $cc['critical'] . ' kritik';
						}
						if ( $cc['warning'] ) {
							$sum[] = $cc['warning'] . ' uyarı';
						}
						printf(
							'<button type="button" class="wdpd-trace-label is-%s" data-wdpd-goto="findings" data-cat="%s"><span class="wdpd-dot"></span><strong>%s</strong><small>%s</small></button>',
							esc_attr( $state ),
							esc_attr( $key ),
							esc_html( $c['label'] ),
							esc_html( $sum ? implode( ', ', $sum ) : 'Sorun yok' )
						);
					}
					?>
				</div>
			</div>
		</div>

		<div class="wdpd-split">
			<div class="wdpd-block">
				<div class="wdpd-block__head">
					<h2>Önce bunları yapın</h2>
					<p>Etkisi en yüksek sorundan başlayarak sıralandı.</p>
				</div>
				<?php if ( ! $prio ) : ?>
					<div class="wdpd-allclear">
						<?php echo self::icon( 'check', 28 ); // phpcs:ignore ?>
						<div><strong>Acil bir sorun bulunmadı.</strong><p>Bulgular sekmesinde isteğe bağlı önerileri inceleyebilirsiniz.</p></div>
					</div>
				<?php else : ?>
					<ol class="wdpd-rx">
						<?php foreach ( $prio as $c ) : ?>
							<li class="wdpd-rx__item is-<?php echo esc_attr( $c['status'] ); ?>">
								<div class="wdpd-rx__body">
									<div class="wdpd-rx__title">
										<strong><?php echo esc_html( $c['title'] ); ?></strong>
										<span class="wdpd-pill is-<?php echo esc_attr( $c['status'] ); ?>"><?php echo esc_html( self::status_meta( $c['status'] )[0] ); ?></span>
									</div>
									<p class="wdpd-rx__value"><?php echo esc_html( self::val( $c['value'] ) ); ?>, <?php echo esc_html( strtolower( self::impact_label( $c['impact'] ) ) ); ?></p>
									<p><?php echo esc_html( $c['solution'] ); ?></p>
								</div>
								<div class="wdpd-rx__act">
									<?php echo self::fix_button( $c['fix'] ); // phpcs:ignore ?>
									<button type="button" class="wdpd-link" data-wdpd-goto="findings" data-open="<?php echo esc_attr( $c['id'] ); ?>">Ayrıntı</button>
								</div>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</div>

			<aside class="wdpd-side">
				<div class="wdpd-block wdpd-block--tight">
					<h3>Bulgu dağılımı</h3>
					<?php
					$tot = max( 1, array_sum( $scan['counts'] ) );
					echo '<div class="wdpd-stack" role="img" aria-label="Bulgu dağılımı">';
					foreach ( array( 'critical', 'warning', 'info', 'good' ) as $s ) {
						if ( $scan['counts'][ $s ] ) {
							printf( '<span class="is-%s" style="flex:%d"></span>', esc_attr( $s ), (int) $scan['counts'][ $s ] );
						}
					}
					echo '</div><dl class="wdpd-legend">';
					foreach ( array( 'critical', 'warning', 'info', 'good' ) as $s ) {
						printf( '<div class="is-%s"><dt><span class="wdpd-dot"></span>%s</dt><dd>%d</dd></div>', esc_attr( $s ), esc_html( self::status_meta( $s )[0] ), (int) $scan['counts'][ $s ] );
					}
					echo '</dl>';
					?>
				</div>

				<?php if ( count( $hist ) > 1 ) : ?>
					<div class="wdpd-block wdpd-block--tight">
						<h3>Puan geçmişi</h3>
						<?php
						$pts = array();
						$hc  = count( $hist );
						foreach ( $hist as $k => $h ) {
							$pts[] = round( $k / ( $hc - 1 ) * 280, 1 ) . ',' . round( 70 - ( $h['s'] / 100 ) * 60, 1 );
						}
						$first = $hist[0]['s'];
						$last  = end( $hist )['s'];
						?>
						<svg class="wdpd-spark" viewBox="0 0 280 76" preserveAspectRatio="none" aria-hidden="true">
							<line x1="0" x2="280" y1="19" y2="19"/>
							<polyline points="<?php echo esc_attr( implode( ' ', $pts ) ); ?>"/>
						</svg>
						<p class="wdpd-muted">
							<?php echo (int) $hc; ?> taramada <?php echo (int) $first; ?> puandan <?php echo (int) $last; ?> puana
							<?php echo $last > $first ? 'yükseldi.' : ( $last < $first ? 'düştü.' : 'değişmedi.' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<div class="wdpd-block wdpd-block--tight wdpd-help">
					<?php echo self::icon( 'forum', 22 ); // phpcs:ignore ?>
					<h3>Takıldığınız bir adım mı var?</h3>
					<p>Sonuçlarınızı oblifex.com webmaster destek forumunda paylaşın, birlikte bakalım. Raporu tek tıkla kopyalayabilirsiniz.</p>
					<button type="button" class="wdpd-link" data-wdpd-goto="support">Raporu kopyala ve sor</button>
				</div>
			</aside>
		</div>
		<?php
	}

	private static function panel_findings( $scan, $checks ) {
		if ( ! $scan ) {
			echo '<div class="wdpd-empty">' . self::icon( 'scan', 28 ) . '<p>Bulguları görmek için önce siteyi tarayın.</p><button type="button" class="wdpd-btn wdpd-btn--primary" data-wdpd-scan>Siteyi tara</button></div>'; // phpcs:ignore
			return;
		}
		$c = $scan['counts'];
		?>
		<div class="wdpd-filter" role="group" aria-label="Duruma göre filtrele">
			<button type="button" class="is-active" data-filter="all">Tümü <em><?php echo (int) array_sum( $c ); ?></em></button>
			<button type="button" data-filter="problems">Sorunlar <em><?php echo (int) ( $c['critical'] + $c['warning'] ); ?></em></button>
			<button type="button" data-filter="info">Öneriler <em><?php echo (int) $c['info']; ?></em></button>
			<button type="button" data-filter="good">Sağlıklı <em><?php echo (int) $c['good']; ?></em></button>
		</div>
		<?php
		foreach ( WDPD_Checks::categories() as $key => $cat ) {
			$items = array_values(
				array_filter(
					$checks,
					function ( $x ) use ( $key ) {
						return $x['category'] === $key;
					}
				)
			);
			if ( ! $items ) {
				continue;
			}
			$order = array( 'critical' => 0, 'warning' => 1, 'info' => 2, 'good' => 3 );
			usort(
				$items,
				function ( $a, $b ) use ( $order ) {
					return $order[ $a['status'] ] <=> $order[ $b['status'] ];
				}
			);
			echo '<section class="wdpd-group" id="wdpd-cat-' . esc_attr( $key ) . '"><h2 class="wdpd-group__title">' . self::icon( $cat['icon'], 20 ) . esc_html( $cat['label'] ) . '</h2><div class="wdpd-findings">'; // phpcs:ignore
			foreach ( $items as $it ) {
				self::render_finding( $it );
			}
			echo '</div></section>';
		}
	}

	private static function render_finding( $it ) {
		$meta = self::status_meta( $it['status'] );
		$open = in_array( $it['status'], array( 'critical' ), true );
		?>
		<details class="wdpd-finding is-<?php echo esc_attr( $it['status'] ); ?>" data-status="<?php echo esc_attr( $it['status'] ); ?>" id="wdpd-f-<?php echo esc_attr( $it['id'] ); ?>"<?php echo $open ? ' open' : ''; ?>>
			<summary>
				<span class="wdpd-finding__icon"><?php echo self::icon( $meta[1], 18 ); // phpcs:ignore ?></span>
				<span class="wdpd-finding__title"><?php echo esc_html( $it['title'] ); ?></span>
				<span class="wdpd-finding__value"><?php echo esc_html( self::val( $it['value'] ) ); ?></span>
				<span class="wdpd-pill is-<?php echo esc_attr( $it['status'] ); ?>"><?php echo esc_html( $meta[0] ); ?></span>
				<span class="wdpd-finding__chev"><?php echo self::icon( 'chevron', 18 ); // phpcs:ignore ?></span>
			</summary>
			<div class="wdpd-finding__body">
				<div class="wdpd-finding__cols">
					<div>
						<h4>Neden önemli <span class="wdpd-impact is-<?php echo esc_attr( $it['impact'] ); ?>"><?php echo esc_html( self::impact_label( $it['impact'] ) ); ?></span></h4>
						<p><?php echo esc_html( $it['why'] ); ?></p>
					</div>
					<div>
						<h4>Ne yapmalı</h4>
						<p><?php echo esc_html( $it['solution'] ); ?></p>
						<?php if ( ! empty( $it['code'] ) ) : ?>
							<div class="wdpd-code">
								<pre><code><?php echo esc_html( $it['code'] ); ?></code></pre>
								<button type="button" class="wdpd-code__copy" data-wdpd-copy><?php echo self::icon( 'copy', 15 ); // phpcs:ignore ?><span>Kopyala</span></button>
							</div>
						<?php endif; ?>
						<?php if ( $it['fix'] ) : ?>
							<div class="wdpd-finding__act"><?php echo self::fix_button( $it['fix'] ); // phpcs:ignore ?></div>
						<?php endif; ?>
					</div>
				</div>
				<?php if ( ! empty( $it['details']['rows'] ) ) : ?>
					<div class="wdpd-table-wrap">
						<table class="wdpd-table">
							<thead><tr>
								<?php foreach ( $it['details']['cols'] as $i => $col ) : ?>
									<th<?php echo $i > 0 ? ' class="num"' : ''; ?>><?php echo esc_html( $col ); ?></th>
								<?php endforeach; ?>
								<?php
								$has_act = false;
								foreach ( $it['details']['rows'] as $r ) {
									if ( is_array( end( $r ) ) ) {
										$has_act = true;
									}
								}
								if ( $has_act ) {
									echo '<th></th>';
								}
								?>
							</tr></thead>
							<tbody>
								<?php foreach ( $it['details']['rows'] as $row ) : ?>
									<tr>
										<?php
										foreach ( $row as $i => $cell ) {
											if ( is_array( $cell ) ) {
												echo '<td class="act">' . self::fix_button( $cell['fix'], $cell['arg'], 'wdpd-btn--sm' ) . '</td>'; // phpcs:ignore
											} else {
												echo '<td' . ( $i > 0 ? ' class="num"' : '' ) . '>' . esc_html( (string) $cell ) . '</td>';
											}
										}
										?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	private static function panel_database() {
		$tasks  = WDPD_Cleaner::tasks();
		$counts = WDPD_Cleaner::counts();
		$al     = WDPD_Checks::autoload_stats();
		$top    = WDPD_Checks::autoload_top( 15 );
		$log    = (array) get_option( WDPD_Cleaner::AUTOLOAD_LOG, array() );
		$safe_n = 0;
		foreach ( $tasks as $k => $t ) {
			if ( $t['safe'] && $counts[ $k ] > 0 ) {
				$safe_n += $counts[ $k ];
			}
		}
		$tables = array_slice( WDPD_Checks::tables(), 0, 10 );
		?>
		<div class="wdpd-notice">
			<?php echo self::icon( 'info', 18 ); // phpcs:ignore ?>
			<p>Temizlik işlemleri kalıcıdır. Büyük işlemlerden önce hosting panelinizden veritabanı yedeği almanızı öneririz.</p>
		</div>

		<div class="wdpd-block">
			<div class="wdpd-block__head wdpd-block__head--row">
				<div>
					<h2>Veritabanı temizliği</h2>
					<p>Silinebilecek gereksiz kayıtlar. Sayılar şu anki durumu gösterir.</p>
				</div>
				<button type="button" class="wdpd-btn wdpd-btn--primary" data-wdpd-clean-all <?php disabled( 0, $safe_n ); ?>>
					<?php echo self::icon( 'broom' ); // phpcs:ignore ?><span>Güvenli temizliği çalıştır</span>
				</button>
			</div>
			<ul class="wdpd-tasks">
				<?php foreach ( $tasks as $key => $t ) : ?>
					<?php $n = (int) $counts[ $key ]; ?>
					<li class="wdpd-task<?php echo $n ? '' : ' is-clean'; ?>" data-task="<?php echo esc_attr( $key ); ?>" data-safe="<?php echo $t['safe'] ? '1' : '0'; ?>">
						<div class="wdpd-task__text">
							<strong><?php echo esc_html( $t['label'] ); ?></strong>
							<span><?php echo esc_html( $t['desc'] ); ?><?php echo $t['safe'] ? '' : ' Toplu temizliğe dahil değildir.'; ?></span>
						</div>
						<span class="wdpd-task__count" data-count><?php echo esc_html( WDPD_Util::num( $n ) ); ?></span>
						<button type="button" class="wdpd-btn wdpd-btn--sm wdpd-btn--solid" data-wdpd-fix="<?php echo esc_attr( $key ); ?>" data-confirm="<?php echo esc_attr( isset( $t['confirm'] ) ? $t['confirm'] : '' ); ?>" <?php disabled( 0, $n ); ?>>Temizle</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="wdpd-block">
			<div class="wdpd-block__head">
				<h2>Otomatik yüklenen ayarlar</h2>
				<p>Her sayfa açılışında topluca okunan ayarlar: toplam <strong><?php echo esc_html( WDPD_Util::bytes( $al['size'] ) ); ?></strong> (<?php echo esc_html( WDPD_Util::num( $al['count'] ) ); ?> kayıt). 800 KB altı sağlıklı kabul edilir. Kaldırdığınız bir eklentiye ait büyük kayıtların otomatik yüklenmesini kapatabilirsiniz; kayıt silinmez, istenirse geri alınır.</p>
			</div>
			<div class="wdpd-table-wrap">
				<table class="wdpd-table">
					<thead><tr><th>Ayar adı</th><th class="num">Boyut</th><th></th></tr></thead>
					<tbody>
						<?php foreach ( $top as $row ) : ?>
							<?php $prot = WDPD_Cleaner::is_protected_option( $row['name'] ); ?>
							<tr>
								<td><code><?php echo esc_html( $row['name'] ); ?></code></td>
								<td class="num"><?php echo esc_html( WDPD_Util::bytes( $row['size'] ) ); ?></td>
								<td class="act">
									<?php if ( $prot ) : ?>
										<span class="wdpd-muted" title="WordPress çekirdeğinin, temanın veya geçici önbelleğin kullandığı ayar; güvenlik için değiştirilemez.">Korunuyor</span>
									<?php else : ?>
										<button type="button" class="wdpd-btn wdpd-btn--sm" data-wdpd-fix="autoload_off" data-arg="<?php echo esc_attr( $row['name'] ); ?>" data-confirm="<?php echo esc_attr( $row['name'] . ' ayarının otomatik yüklenmesi kapatılacak. Ayar silinmez; ihtiyaç duyulduğunda ayrı bir sorguyla okunur. Devam edilsin mi?' ); ?>">Otomatik yüklemeyi kapat</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $log ) : ?>
				<h3 class="wdpd-subhead">Değiştirdiğiniz ayarlar</h3>
				<div class="wdpd-table-wrap">
					<table class="wdpd-table">
						<thead><tr><th>Ayar adı</th><th class="num">Boyut</th><th class="num">Tarih</th><th></th></tr></thead>
						<tbody>
							<?php foreach ( $log as $name => $l ) : ?>
								<tr>
									<td><code><?php echo esc_html( $name ); ?></code></td>
									<td class="num"><?php echo esc_html( WDPD_Util::bytes( $l['size'] ) ); ?></td>
									<td class="num"><?php echo esc_html( wp_date( 'd.m.Y', $l['time'] ) ); ?></td>
									<td class="act"><button type="button" class="wdpd-btn wdpd-btn--sm" data-wdpd-fix="autoload_restore" data-arg="<?php echo esc_attr( $name ); ?>"><?php echo self::icon( 'undo', 15 ); // phpcs:ignore ?>Geri al</button></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<div class="wdpd-block">
			<div class="wdpd-block__head wdpd-block__head--row">
				<div>
					<h2>En büyük tablolar</h2>
					<p>Beklenmedik derecede büyük bir log veya istatistik tablosu, ilgili eklentinin kayıt süresini kısaltmanız gerektiğini gösterir.</p>
				</div>
				<button type="button" class="wdpd-btn" data-wdpd-fix="optimize_tables" data-confirm="Boşluk içeren tablolar optimize edilecek. Büyük sitelerde birkaç dakika sürebilir. Devam edilsin mi?">Tabloları optimize et</button>
			</div>
			<div class="wdpd-table-wrap">
				<table class="wdpd-table">
					<thead><tr><th>Tablo</th><th class="num">Motor</th><th class="num">Satır</th><th class="num">Boyut</th><th class="num">Boşluk</th></tr></thead>
					<tbody>
						<?php foreach ( $tables as $t ) : ?>
							<tr>
								<td><code><?php echo esc_html( $t['name'] ); ?></code></td>
								<td class="num"><?php echo esc_html( (string) $t['engine'] ); ?></td>
								<td class="num"><?php echo esc_html( WDPD_Util::num( $t['row_count'] ) ); ?></td>
								<td class="num"><?php echo esc_html( WDPD_Util::bytes( (int) $t['data'] + (int) $t['idx'] ) ); ?></td>
								<td class="num"><?php echo esc_html( WDPD_Util::bytes( (int) $t['free'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private static function panel_optimize() {
		$o = WDPD_Optimizer::get();
		?>
		<form class="wdpd-block" data-wdpd-opts>
			<div class="wdpd-block__head wdpd-block__head--row">
				<div>
					<h2>Hafifletme ayarları</h2>
					<p>WordPress'in varsayılan olarak yüklediği ama çoğu sitenin kullanmadığı özellikleri kapatın. Her ayar tek başına açılıp kapatılabilir; bir sorun görürseniz kapatmanız yeterli.</p>
				</div>
				<button type="submit" class="wdpd-btn wdpd-btn--primary">Ayarları kaydet</button>
			</div>
			<ul class="wdpd-settings">
				<?php foreach ( WDPD_Optimizer::fields() as $key => $f ) : ?>
					<li class="wdpd-setting">
						<div class="wdpd-setting__text">
							<label for="wdpd-o-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $f['label'] ); ?></label>
							<p><?php echo esc_html( $f['desc'] ); ?></p>
							<span class="wdpd-tag"><?php echo esc_html( $f['impact'] ); ?></span>
						</div>
						<div class="wdpd-setting__ctl">
							<?php if ( 'toggle' === $f['type'] ) : ?>
								<label class="wdpd-switch">
									<input type="checkbox" id="wdpd-o-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( 1, (int) $o[ $key ] ); ?>>
									<span aria-hidden="true"></span>
								</label>
							<?php else : ?>
								<select id="wdpd-o-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" class="wdpd-select">
									<?php foreach ( $f['options'] as $v => $label ) : ?>
										<option value="<?php echo esc_attr( $v ); ?>" <?php selected( (string) $v, (string) $o[ $key ] ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</form>
		<?php
	}

	private static function system_info() {
		global $wpdb, $wp_version;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$theme   = wp_get_theme();
		$plugins = array();
		$all     = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
			if ( isset( $all[ $p ] ) ) {
				$plugins[] = '  - ' . $all[ $p ]['Name'] . ' ' . $all[ $p ]['Version'];
			}
		}
		$lines = array(
			'Site: ' . home_url(),
			'WordPress: ' . $wp_version . ( is_multisite() ? ' (multisite)' : '' ),
			'PHP: ' . PHP_VERSION . ', bellek ' . ini_get( 'memory_limit' ),
			'Sunucu: ' . ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '-' ),
			'Veritabanı: ' . ( method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : $wpdb->db_version() ),
			'Tema: ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'Nesne önbelleği: ' . ( wp_using_ext_object_cache() ? 'var' : 'yok' ),
			'Aktif eklentiler (' . count( $plugins ) . '):',
		);
		return implode( "\n", array_merge( $lines, $plugins ) );
	}

	private static function report_text( $scan, $checks ) {
		if ( ! $scan ) {
			return 'Henüz tarama yapılmadı.';
		}
		$out   = array();
		$out[] = 'WD Performans Doktoru raporu (' . wp_date( 'd.m.Y H:i', $scan['time'] ) . ')';
		$out[] = 'Sağlık puanı: ' . $scan['score'] . '/100 (' . WDPD_Checks::grade( $scan['score'] ) . ')';
		$out[] = '';
		foreach ( array( 'critical', 'warning', 'info' ) as $s ) {
			$rows = array_filter(
				$checks,
				function ( $c ) use ( $s ) {
					return $c['status'] === $s;
				}
			);
			if ( ! $rows ) {
				continue;
			}
			$out[] = '[' . self::status_meta( $s )[0] . ']';
			foreach ( $rows as $c ) {
				$out[] = '- ' . $c['title'] . ': ' . self::val( $c['value'] );
			}
			$out[] = '';
		}
		$out[] = '--- Sistem bilgisi ---';
		$out[] = self::system_info();
		return implode( "\n", $out );
	}

	private static function panel_support( $scan, $checks ) {
		?>
		<div class="wdpd-support">
			<div class="wdpd-block wdpd-support__forum">
				<?php echo self::icon( 'forum', 30 ); // phpcs:ignore ?>
				<h2>oblifex.com webmaster destek forumu</h2>
				<p>Bir bulguyu nasıl çözeceğinizden emin değilseniz ya da bir ayar sitenizde sorun çıkardıysa forumda yeni konu açın. Aşağıdaki raporu konunuza eklerseniz sorununuzu çok daha hızlı anlarız.</p>
				<a class="wdpd-btn wdpd-btn--primary" href="<?php echo esc_url( WDPD_SUPPORT_URL ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'ext', 16 ); // phpcs:ignore ?><span>Destek forumuna git</span></a>
			</div>
			<div class="wdpd-block wdpd-support__pro">
				<h2>Sitenizi biz hızlandıralım</h2>
				<p>Sunucu ayarı, önbellek kurulumu, veritabanı optimizasyonu ve Core Web Vitals iyileştirmesini sizin yerinize yapıyoruz. WordPress, WooCommerce ve özel PHP yazılımları için profesyonel hizmet.</p>
				<a class="wdpd-btn" href="<?php echo esc_url( WDPD_AUTHOR_URL ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'ext', 16 ); // phpcs:ignore ?><span>webdanismani.com</span></a>
			</div>
		</div>

		<div class="wdpd-block">
			<div class="wdpd-block__head wdpd-block__head--row">
				<div>
					<h2>Forumda paylaşılacak rapor</h2>
					<p>Tarama özeti ve sistem bilgisi. Şifre, e-posta veya kişisel veri içermez.</p>
				</div>
				<button type="button" class="wdpd-btn" data-wdpd-copy-target="wdpd-report"><?php echo self::icon( 'copy', 16 ); // phpcs:ignore ?><span>Raporu kopyala</span></button>
			</div>
			<textarea id="wdpd-report" class="wdpd-report" readonly rows="14"><?php echo esc_textarea( self::report_text( $scan, $checks ) ); ?></textarea>
		</div>
		<?php
	}

	private static function modal() {
		?>
		<div class="wdpd-modal" hidden>
			<div class="wdpd-modal__box" role="dialog" aria-modal="true" aria-labelledby="wdpd-modal-title">
				<div data-modal-scan hidden>
					<svg class="wdpd-trace wdpd-trace--live" viewBox="0 0 1000 110" preserveAspectRatio="none" aria-hidden="true">
						<path d="<?php echo esc_attr( self::trace_segment( 0, 1000, 'good', 1 ) ); ?>"/>
					</svg>
					<h2 id="wdpd-modal-title">Site taranıyor</h2>
					<p class="wdpd-muted">Ölçümler canlı sitenizde yapılıyor; hiçbir ayar değiştirilmiyor.</p>
					<ul class="wdpd-steps" data-steps></ul>
				</div>
				<div data-modal-confirm hidden>
					<h2>Emin misiniz?</h2>
					<p data-confirm-text></p>
					<div class="wdpd-modal__actions">
						<button type="button" class="wdpd-btn" data-confirm-no>Vazgeç</button>
						<button type="button" class="wdpd-btn wdpd-btn--primary" data-confirm-yes>Devam et</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
