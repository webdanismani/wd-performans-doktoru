<?php
/**
 * Eklenti silindiğinde verilerini temizler ve autoload değişikliklerini geri alır.
 *
 * @package WD_Performans_Doktoru
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Kullanıcının kapattığı autoload ayarlarını eski haline getir.
$wdpd_log = get_option( 'wdpd_autoload_changed', array() );
if ( is_array( $wdpd_log ) ) {
	foreach ( $wdpd_log as $wdpd_name => $wdpd_row ) {
		if ( isset( $wdpd_row['orig'] ) ) {
			$wpdb->update( $wpdb->options, array( 'autoload' => $wdpd_row['orig'] ), array( 'option_name' => $wdpd_name ) );
		}
	}
}

foreach ( array( 'wdpd_opt', 'wdpd_last_scan', 'wdpd_history', 'wdpd_autoload_changed' ) as $wdpd_opt ) {
	delete_option( $wdpd_opt );
}

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_wdpd_' ) . '%', $wpdb->esc_like( '_transient_timeout_wdpd_' ) . '%' ) );

wp_cache_delete( 'alloptions', 'options' );
