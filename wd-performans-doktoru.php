<?php
/**
 * Plugin Name:       WD Performans Doktoru
 * Plugin URI:        https://webdanismani.com
 * Description:       WordPress sitenizi en çok yoran sorunları tespit eder, etkisine göre önceliklendirir ve güvenli tek tık çözümler sunar. Ücretsizdir, satılamaz. Destek: oblifex.com webmaster destek forumu.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Web Danışmanı
 * Author URI:        https://webdanismani.com
 * License:           CC BY-NC-SA 4.0
 * License URI:       https://creativecommons.org/licenses/by-nc-sa/4.0/
 * Text Domain:       wd-performans-doktoru
 *
 * Copyright (c) 2026 Web Danışmanı (https://webdanismani.com)
 * Ücretsiz paylaşılır; satışı ve ticari amaçla dağıtımı yasaktır.
 * Free to share; selling or commercial redistribution is prohibited.
 *
 * @package WD_Performans_Doktoru
 */

defined( 'ABSPATH' ) || exit;

define( 'WDPD_VERSION', '1.0.0' );
define( 'WDPD_FILE', __FILE__ );
define( 'WDPD_DIR', plugin_dir_path( __FILE__ ) );
define( 'WDPD_URL', plugin_dir_url( __FILE__ ) );
define( 'WDPD_SLUG', 'wd-performans-doktoru' );
define( 'WDPD_AUTHOR_URL', 'https://webdanismani.com' );
define( 'WDPD_SUPPORT_URL', 'https://oblifex.com' );

require_once WDPD_DIR . 'includes/class-wdpd-util.php';
require_once WDPD_DIR . 'includes/class-wdpd-optimizer.php';
require_once WDPD_DIR . 'includes/class-wdpd-cleaner.php';
require_once WDPD_DIR . 'includes/class-wdpd-checks.php';
require_once WDPD_DIR . 'includes/class-wdpd-admin.php';

WDPD_Optimizer::init();

if ( is_admin() ) {
	WDPD_Admin::init();
}

register_activation_hook( __FILE__, 'wdpd_activate' );

/**
 * Varsayılan ayarları oluşturur. Ayarlar her sayfada gerektiği için autoload açık,
 * tarama sonuçları ise yalnızca panelde okunduğu için autoload kapalı tutulur.
 */
function wdpd_activate() {
	if ( false === get_option( WDPD_Optimizer::OPTION ) ) {
		add_option( WDPD_Optimizer::OPTION, WDPD_Optimizer::defaults(), '', true );
	}
}
