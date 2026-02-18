<?php
defined( 'ABSPATH' ) || exit;

final class TWTW_Shortcodes {
	private static $instance;

	public static function instance() : self {
		if ( ! self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'google_wallet_button', array( $this, 'sc_button' ) );
		add_shortcode( 'google_wallet_image', array( $this, 'sc_image' ) );
	}

	private function resolve_post_id( $atts ) : int {
		$atts = shortcode_atts( array( 'post_id' => '', 'card_id' => '' ), $atts, 'google_wallet_button' );
		$id = absint( $atts['post_id'] ?: $atts['card_id'] );
		if ( ! $id ) { $id = get_the_ID(); }
		return (int) $id;
	}

	private function build_save_url( int $post_id ) {
		$issuer = (string) get_option( 'twtw_issuer_id', '' );
		$creds = TWTW_Helpers::read_credentials();
		if ( $issuer === '' || ! TWTW_Helpers::validate_credentials_array( $creds ) ) {
			return new WP_Error( 'twtw_missing_creds', 'Credenciais não configuradas.' );
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'twtw_no_openssl', 'OpenSSL não disponível no servidor.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'twtw_missing_post', 'Conteúdo não encontrado.' );
		}
		$post_type = (string) $post->post_type;
		if ( $post_type === '' ) {
			return new WP_Error( 'twtw_bad_post_type', 'Post type inválido.' );
		}

		$mapping_all = (array) get_option( 'twtw_wallet_mapping', array() );
		$mapping = ( isset( $mapping_all[ $post_type ] ) && is_array( $mapping_all[ $post_type ] ) ) ? $mapping_all[ $post_type ] : array();

		$values = array();
		foreach ( $mapping as $wallet_key => $meta_key ) {
			$wallet_key = sanitize_key( (string) $wallet_key );
			$meta_key_raw = (string) $meta_key;
			$meta_key = sanitize_key( $meta_key_raw );
			if ( $wallet_key === '' || $meta_key === '' ) { continue; }

			// Fontes "built-in" do WordPress, para reduzir fricção no BO.
			$v = '';
			switch ( $meta_key ) {
				case '__post_title':
					$v = get_the_title( $post_id );
					break;
				case '__post_excerpt':
					$v = get_the_excerpt( $post_id );
					break;
				case '__post_permalink':
					$v = get_permalink( $post_id );
					break;
				case '__featured_image':
					$v = get_the_post_thumbnail_url( $post_id, 'full' );
					break;
				default:
					$v = get_post_meta( $post_id, $meta_key, true );
					break;
			}

			if ( is_array( $v ) ) { continue; }
			$v = trim( (string) $v );
			if ( $v === '' ) { continue; }
			$values[ $wallet_key ] = $v;
		}

		$client_email = (string) ( $creds['client_email'] ?? '' );
		$private_key  = (string) ( $creds['private_key'] ?? '' );
		if ( $client_email === '' || $private_key === '' ) {
			return new WP_Error( 'twtw_bad_creds', 'Credenciais inválidas.' );
		}

		$class_suffix = 'twtw_' . sanitize_key( $post_type );
		$class_id = $issuer . '.' . $class_suffix;
		$object_id = $issuer . '.' . absint( $post_id );

		$title = isset( $values['name'] ) ? (string) $values['name'] : get_the_title( $post_id );
		if ( $title === '' ) { $title = 'Cartão'; }

		// Nome do emissor (usado também no cardTitle, que é obrigatório no GenericObject)
		$issuer_name = (string) get_option( 'twtw_issuer_name', '' );
		if ( $issuer_name === '' ) { $issuer_name = (string) get_bloginfo( 'name' ); }
		if ( $issuer_name === '' ) { $issuer_name = 'The Wild Theory'; }

		$class = array(
			'id' => $class_id,
			'issuerName' => $issuer_name,
			'reviewStatus' => 'UNDER_REVIEW',
		);

		$object = array(
			'id' => $object_id,
			'classId' => $class_id,
			'state' => 'ACTIVE',
			'cardTitle' => array( 'defaultValue' => array( 'language' => 'pt-PT', 'value' => $issuer_name ) ),
			'header' => array( 'defaultValue' => array( 'language' => 'pt-PT', 'value' => $title ) ),
			'subheader' => array( 'defaultValue' => array( 'language' => 'pt-PT', 'value' => ucfirst( $post_type ) ) ),
		);

		// Cores
		if ( ! empty( $values['bg_color'] ) ) {
			$object['hexBackgroundColor'] = (string) $values['bg_color'];
		}

		// Foto
		if ( ! empty( $values['photo'] ) ) {
			$photo_url = $values['photo'];
			if ( ctype_digit( $photo_url ) ) {
				$u = wp_get_attachment_url( (int) $photo_url );
				if ( $u ) { $photo_url = $u; }
			}
			if ( filter_var( $photo_url, FILTER_VALIDATE_URL ) ) {
				$object['heroImage'] = array(
					'sourceUri' => array( 'uri' => esc_url_raw( $photo_url ) ),
					'contentDescription' => array( 'defaultValue' => array('language' => 'pt-PT', 'value' => 'Foto') ),
				);
			}
		}

		// QR
		if ( ! empty( $values['qr_value'] ) ) {
			$object['barcode'] = array(
				'type' => 'QR_CODE',
				'value' => (string) $values['qr_value'],
			);
		}

		// Text modules
		$labels = array(
			'email' => 'Email',
			'phone' => 'Telefone',
			'company' => 'Empresa',
			'position' => 'Cargo',
			'address' => 'Morada',
			'website' => 'Website',
		);
		$text_modules = array();
		foreach ( $labels as $k => $lbl ) {
			if ( empty( $values[ $k ] ) ) { continue; }
			$text_modules[] = array(
				'id' => 'twtw_' . $k,
				'header' => $lbl,
				'body' => (string) $values[ $k ],
			);
		}
		if ( $text_modules ) {
			$object['textModulesData'] = $text_modules;
		}

		$claims = array(
			'iss' => $client_email,
			'aud' => 'google',
			'typ' => 'savetowallet',
			'iat' => time(),
			'origins' => array_values( array_filter( (array) get_option( 'twtw_allowed_origins', array() ) ) ),
			'payload' => array(
				'genericClasses' => array( $class ),
				'genericObjects' => array( $object ),
			),
		);

		$jwt = $this->jwt_encode_rs256( $claims, $private_key );
		if ( is_wp_error( $jwt ) ) {
			TWTW_Helpers::log( 'Falha JWT: ' . $jwt->get_error_code() . ' ' . $jwt->get_error_message() );
			return $jwt;
		}

		return 'https://pay.google.com/gp/v/save/' . $jwt;
	}

	private function b64url( string $data ) : string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private function jwt_encode_rs256( array $claims, string $private_key ) {
		$header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
		$segments = array(
			$this->b64url( wp_json_encode( $header ) ),
			$this->b64url( wp_json_encode( $claims ) ),
		);
		$signing_input = implode( '.', $segments );

		$pkey = openssl_pkey_get_private( $private_key );
		if ( ! $pkey ) {
			return new WP_Error( 'twtw_bad_private_key', 'Chave privada inválida.' );
		}

			$signature = '';
			$ok = openssl_sign( $signing_input, $signature, $pkey, OPENSSL_ALGO_SHA256 );
			// Em versões recentes de PHP, as funções openssl_*_free() estão deprecated.
			// O recurso é libertado automaticamente pelo garbage collector, por isso evitamos chamadas explícitas.
			$pkey = null;
		if ( ! $ok ) {
			return new WP_Error( 'twtw_sign_failed', 'Falha a assinar o JWT.' );
		}

		$segments[] = $this->b64url( $signature );
		return implode( '.', $segments );
	}

	public function sc_button( $atts ) {
		$atts = shortcode_atts(
			array(
				'post_id' => '',
				'card_id' => '',
				'class' => 'google-wallet-button',
				'target' => '_blank',
				'rel' => 'noopener noreferrer',
				'button_text' => 'Adicionar ao Google Wallet',
				'return' => 'button',
			),
			$atts,
			'google_wallet_button'
		);

		$post_id = $this->resolve_post_id( $atts );
		if ( ! $post_id ) { return ''; }

		$url = $this->build_save_url( $post_id );
		if ( $atts['return'] === 'url' ) {
			return is_wp_error( $url ) ? '' : esc_url( (string) $url );
		}

		if ( is_wp_error( $url ) ) {
				$debug = (int) get_option( 'twtw_debug', 0 );
				$extra = '';
				// Mostrar detalhe só para administradores, e apenas com debug ligado.
				if ( $debug && current_user_can( 'manage_options' ) ) {
					$extra = '<div style="margin-top:6px;font-size:12px;opacity:.85">' . esc_html( $url->get_error_code() . ': ' . $url->get_error_message() ) . '</div>';
				}
				// Registar em log do WordPress quando debug está ativo.
				if ( $debug ) {
					TWTW_Helpers::log( 'Erro ao gerar link: ' . $url->get_error_code() . ' ' . $url->get_error_message() );
				}
				return '<div class="twtw-wallet-error" style="padding:10px;border-radius:10px;background:#fff3f3;border:1px solid #f2b8b8;color:#8a1f1f">Não foi possível gerar o link para Google Wallet. Contacte o administrador.' . $extra . '</div>';
		}

		return sprintf(
			'<a class="%1$s" href="%2$s" target="%3$s" rel="%4$s">%5$s</a>',
			esc_attr( $atts['class'] ),
			esc_url( (string) $url ),
			esc_attr( $atts['target'] ),
			esc_attr( $atts['rel'] ),
			esc_html( $atts['button_text'] )
		);
	}

	public function sc_image( $atts ) {
		$img = '<img alt="Google Wallet" style="max-width:100%;height:auto" src="https://developers.google.com/wallet/images/add-to-google-wallet-button.svg">';
		return $img;
	}
}
