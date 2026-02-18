<?php
defined( 'ABSPATH' ) || exit;

final class TWTW_Helpers {
	public static function uploads_dir() : array {
		$u = wp_upload_dir();
		$dir = trailingslashit( $u['basedir'] ) . 'twt-wallet';
		$url = trailingslashit( $u['baseurl'] ) . 'twt-wallet';
		return array( 'dir' => $dir, 'url' => $url );
	}

	public static function ensure_uploads_dir() : bool {
		$u = self::uploads_dir();
		if ( ! file_exists( $u['dir'] ) ) {
			wp_mkdir_p( $u['dir'] );
		}
		return is_dir( $u['dir'] ) && wp_is_writable( $u['dir'] );
	}

	public static function credentials_path() : string {
		$u = self::uploads_dir();
		return trailingslashit( $u['dir'] ) . 'credentials.json';
	}

	public static function read_credentials() : array {
		$path = self::credentials_path();
		if ( ! file_exists( $path ) ) {
			return array();
		}
		$json = file_get_contents( $path );
		if ( $json === false ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	public static function validate_credentials_array( array $data ) : bool {
		return ! empty( $data['client_email'] ) && ! empty( $data['private_key'] );
	}

	public static function admin_url_page( string $page, array $args = array() ) : string {
		// Evitar valores null que em PHP 8.1+ podem disparar avisos internos do WordPress.
		$clean = array( 'page' => (string) $page );
		foreach ( $args as $k => $v ) {
			if ( $v === null ) { continue; }
			$clean[ (string) $k ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
		}
		return add_query_arg( $clean, admin_url( 'admin.php' ) );
	}

	public static function sanitize_post_type( $post_type ) : string {
		$post_type = sanitize_key( (string) $post_type );
		return post_type_exists( $post_type ) ? $post_type : '';
	}

	public static function get_public_post_types() : array {
		$pts = get_post_types( array( 'public' => true ), 'objects' );
		$out = array();
		foreach ( $pts as $k => $o ) {
			// evitar anexos
			if ( $k === 'attachment' ) { continue; }
			$out[ $k ] = $o;
		}
		return $out;
	}

	public static function log( string $message ) : void {
		$debug = (int) get_option( 'twtw_debug', 0 );
		if ( ! $debug ) { return; }
		self::ensure_uploads_dir();
		$u = self::uploads_dir();
		$path = trailingslashit( $u['dir'] ) . 'twtw.log';
		$line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
		// Tentar escrever em ficheiro. Se falhar, cair para error_log.
		$ok = @file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
		if ( $ok === false ) {
			error_log( '[TWT Wallet] ' . $message );
		}
	}

}
