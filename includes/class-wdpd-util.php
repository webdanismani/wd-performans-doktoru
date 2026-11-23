<?php
/**
 * Yardımcı fonksiyonlar.
 *
 * @package WD_Performans_Doktoru
 */

defined( 'ABSPATH' ) || exit;

class WDPD_Util {

	/**
	 * Bayt değerini Türkçe biçimde döndürür (1,2 MB).
	 */
	public static function bytes( $bytes, $dec = 1 ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$b     = max( 0, (float) $bytes );
		$i     = 0;
		while ( $b >= 1024 && $i < 4 ) {
			$b /= 1024;
			$i++;
		}
		return number_format( $b, $i ? $dec : 0, ',', '.' ) . ' ' . $units[ $i ];
	}

	/**
	 * Sayıyı Türkçe binlik ayraçla döndürür (12.480).
	 */
	public static function num( $n ) {
		return number_format( (float) $n, 0, ',', '.' );
	}

	/**
	 * Milisaniye değerini okunur hale getirir.
	 */
	public static function ms( $ms ) {
		if ( $ms >= 1000 ) {
			return number_format( $ms / 1000, 2, ',', '.' ) . ' sn';
		}
		return self::num( round( $ms ) ) . ' ms';
	}

	/**
	 * Tarihi göreli biçimde döndürür.
	 */
	public static function ago( $ts ) {
		$diff = time() - (int) $ts;
		if ( $diff < 60 ) {
			return 'az önce';
		}
		if ( $diff < HOUR_IN_SECONDS ) {
			return floor( $diff / 60 ) . ' dk önce';
		}
		if ( $diff < DAY_IN_SECONDS ) {
			return floor( $diff / HOUR_IN_SECONDS ) . ' saat önce';
		}
		if ( $diff < 30 * DAY_IN_SECONDS ) {
			return floor( $diff / DAY_IN_SECONDS ) . ' gün önce';
		}
		return wp_date( 'd.m.Y', $ts );
	}

	/**
	 * Uzun yolları kısaltır.
	 */
	public static function short_url( $url, $max = 70 ) {
		$url = preg_replace( '#^https?://#', '', (string) $url );
		$url = preg_replace( '#\?.*$#', '', $url );
		if ( strlen( $url ) <= $max ) {
			return $url;
		}
		return substr( $url, 0, 28 ) . '…' . substr( $url, -( $max - 29 ) );
	}

	/**
	 * Uzun sürecek işlemler için süre sınırını genişletir.
	 */
	public static function extend_time( $seconds = 120 ) {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( $seconds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * WordPress'in autoload sayılan değerleri (6.6+ "on", "auto-on", "auto" ekledi).
	 */
	public static function autoload_values() {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			return wp_autoload_values_to_autoload();
		}
		return array( 'yes' );
	}

	/**
	 * SQL IN listesi için güvenli string.
	 */
	public static function sql_in( array $values ) {
		return "'" . implode( "','", array_map( 'esc_sql', $values ) ) . "'";
	}

	/**
	 * Tablo var mı?
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
