<?php
defined( 'ABSPATH' ) || exit;

final class TWTW_Admin {
	private static $instance;
	private $menu_slug = 'twtw-wallet';

	public static function instance() : self {
		if ( ! self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {
		// Menus têm de ficar registados cedo e sempre.
		add_action( 'admin_menu', array( $this, 'register_menus' ), 0 );

		// Handlers admin-post (evita Settings API a mandar para options.php)
		add_action( 'admin_post_twtw_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_twtw_upload_credentials', array( $this, 'handle_upload_credentials' ) );
		add_action( 'admin_post_twtw_test_credentials', array( $this, 'handle_test_credentials' ) );
		add_action( 'admin_post_twtw_save_fields', array( $this, 'handle_save_fields' ) );
		add_action( 'admin_post_twtw_save_mapping', array( $this, 'handle_save_mapping' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( (string) $hook, $this->menu_slug ) === false && strpos( (string) $hook, 'nfcw-' ) === false ) {
			return;
		}
		wp_enqueue_style( 'twtw-admin', TWTW_PLUGIN_URL . 'assets/admin.css', array(), '3.1.0' );
	}

	public function register_menus() {
		$cap = 'manage_options';

		add_menu_page(
			'TWT Wallet',
			'TWT Wallet',
			$cap,
			$this->menu_slug,
			array( $this, 'page_settings' ),
			'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a00" d="M3 3h14v14H3z"/></svg>'),
			58
		);

		add_submenu_page( $this->menu_slug, 'Configuração', 'Configuração', $cap, 'twtw-settings', array( $this, 'page_settings' ) );
		add_submenu_page( $this->menu_slug, 'Campos', 'Campos', $cap, 'twtw-fields', array( $this, 'page_fields' ) );
		add_submenu_page( $this->menu_slug, 'Mapeamento', 'Mapeamento', $cap, 'twtw-mapping', array( $this, 'page_mapping' ) );
		add_submenu_page( $this->menu_slug, 'Layouts', 'Layouts', $cap, 'twtw-layouts', array( $this, 'page_layouts' ) );
		add_submenu_page( $this->menu_slug, 'Templates e NFC', 'Templates e NFC', $cap, 'twtw-templates', array( $this, 'page_templates' ) );
		add_submenu_page( $this->menu_slug, 'Shortcodes', 'Shortcodes', $cap, 'twtw-shortcodes', array( $this, 'page_shortcodes' ) );

		// Aliases para compatibilidade com URLs antigas
		add_submenu_page( null, 'Mapeamento', 'Mapeamento', $cap, 'nfcw-mapping', array( $this, 'page_mapping' ) );
		add_submenu_page( null, 'Configuração', 'Configuração', $cap, 'nfcw-settings', array( $this, 'page_settings' ) );
		add_submenu_page( null, 'Campos', 'Campos', $cap, 'nfcw-fields', array( $this, 'page_fields' ) );

		// Failsafe: garantir que o WordPress resolve sempre o parent correto,
		// mesmo com plugins de menus ou parâmetros extra na querystring.
		global $_wp_real_parent_file;
		if ( ! is_array( $_wp_real_parent_file ) ) { $_wp_real_parent_file = array(); }
		$aliases = array(
			'twtw-settings',
			'twtw-fields',
			'twtw-mapping',
			'twtw-layouts',
			'twtw-templates',
			'twtw-shortcodes',
			'nfcw-settings',
			'nfcw-fields',
			'nfcw-mapping',
		);
		foreach ( $aliases as $slug ) {
			$_wp_real_parent_file[ $slug ] = $this->menu_slug;
		}
	}

	private function header( string $title ) {
		$logo = TWTW_PLUGIN_URL . 'assets/logo.png';
		$site = (string) get_bloginfo( 'name' );
		if ( $site === '' ) { $site = 'Site'; }

		echo '<div class="wrap twtw-wrap">';
		echo '<div class="twtw-header">';
		echo '<img class="twtw-logo" src="' . esc_url( $logo ) . '" alt="TWT">';
		echo '<div class="twtw-headings">';
		echo '<h1>' . esc_html( $site ) . '</h1>';
		echo '<div class="twtw-subtitle">' . esc_html( $title ) . '</div>';
		echo '<div class="twtw-ip">Propriedade intelectual da ' . esc_html( $site ) . '.</div>';
		echo '</div>';
		echo '</div>';

		if ( isset( $_GET['twtw_message'] ) ) {
			$msg = sanitize_text_field( (string) $_GET['twtw_message'] );
			$map = array(
				'saved' => array( 'success', 'Alterações guardadas.' ),
				'field_deleted' => array( 'success', 'Campo removido da biblioteca.' ),
				'upload_ok' => array( 'success', 'credentials.json carregado com sucesso.' ),
				'upload_fail' => array( 'error', 'Falha no upload. Verifica permissões e o ficheiro.' ),
				'bad_json' => array( 'error', 'O ficheiro enviado não é um credentials.json válido.' ),
				'test_ok' => array( 'success', 'JWT gerado e assinado com sucesso.' ),
				'test_fail' => array( 'error', 'Falha ao assinar JWT. Verifica Issuer ID e credenciais.' ),
			);
			if ( isset( $map[$msg] ) ) {
				$cls = $map[$msg][0];
				$txt = $map[$msg][1];
				echo '<div class="notice notice-' . esc_attr($cls) . ' is-dismissible"><p>' . esc_html($txt) . '</p></div>';
			}
		}
	}

	private function footer() {
		echo '</div>';
	}

	public function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissões.' ); }
		$this->header( 'Google Wallet' );

		$issuer_id = get_option( 'twtw_issuer_id', '' );
		$issuer_name = get_option( 'twtw_issuer_name', '' );
		$origins = (array) get_option( 'twtw_allowed_origins', array() );
		$debug = (int) get_option( 'twtw_debug', 0 );
		$cred_path = TWTW_Helpers::credentials_path();
		$cred_exists = file_exists( $cred_path );
		$writable = TWTW_Helpers::ensure_uploads_dir();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="twtw_save_settings">';
		wp_nonce_field( 'twtw_save_settings', 'twtw_nonce' );

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">Issuer ID</th><td><input class="regular-text" type="text" name="issuer_id" value="' . esc_attr( $issuer_id ) . '" placeholder="3388000000000000000"></td></tr>';
		echo '<tr><th scope="row">Issuer Name</th><td><input class="regular-text" type="text" name="issuer_name" value="' . esc_attr( $issuer_name ) . '" placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '"><p class="description">Nome mostrado no cartão (cardTitle). Se vazio, usa o nome do site.</p></td></tr>';
		echo '<tr><th scope="row">Allowed Origins</th><td><textarea name="allowed_origins" rows="4" class="large-text" placeholder="https://exemplo.com">' . esc_textarea( implode("\n", $origins ) ) . '</textarea><p class="description">Uma origem por linha.</p></td></tr>';
		echo '<tr><th scope="row">Debug logging</th><td><label><input type="checkbox" name="debug" value="1" ' . checked( 1, $debug, false ) . '> Ativar debug</label></td></tr>';
		echo '</tbody></table>';

		submit_button( 'Guardar alterações' );
		echo '</form>';

		echo '<hr>';
		echo '<h2>Credenciais</h2>';
		echo '<p>O ficheiro credentials.json fica guardado em <code>' . esc_html( $cred_path ) . '</code>.</p>';
		if ( ! $writable ) {
			echo '<div class="notice notice-error"><p>A pasta de uploads para o plugin não está gravável. Verifique permissões em <code>wp-content/uploads</code>.</p></div>';
		}
		echo '<p><strong>Estado:</strong> ' . ( $cred_exists ? '<span class="twtw-ok">encontrado</span>' : '<span class="twtw-bad">não encontrado</span>' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" class="twtw-inline">';
		echo '<input type="hidden" name="action" value="twtw_upload_credentials">';
		wp_nonce_field( 'twtw_upload_credentials', 'twtw_nonce' );
		echo '<input type="file" name="credentials" accept="application/json"> ';
		submit_button( 'Upload', 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="twtw-inline">';
		echo '<input type="hidden" name="action" value="twtw_test_credentials">';
		wp_nonce_field( 'twtw_test_credentials', 'twtw_nonce' );
		submit_button( 'Testar', 'secondary', 'submit', false );
		echo '</form>';

		echo '<hr>';
		echo '<h2>Shortcodes</h2>';
		echo '<p>Os shortcodes estão no submenu <strong>Shortcodes</strong>. Atalhos rápidos:</p>';
		echo '<pre>[google_wallet_button]\n[google_wallet_button post_id="123" return="url"]\n[google_wallet_image]</pre>';

		$this->footer();
	}

	public function page_shortcodes() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissões.' ); }
		$this->header( 'Shortcodes' );
		echo '<div class="twtw-card">';
		echo '<h2>Botão</h2>';
		echo '<pre>[google_wallet_button]</pre>';
		echo '<p class="description">Usa o post atual. Para Elementor, podes pedir só a URL.</p>';
		echo '<pre>[google_wallet_button post_id="123" return="url"]</pre>';
		echo '</div>';
		echo '<div class="twtw-card">';
		echo '<h2>Imagem do botão</h2>';
		echo '<pre>[google_wallet_image]</pre>';
		echo '</div>';
		$this->footer();
	}

	public function page_fields() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissões.' ); }

		$post_types = TWTW_Helpers::get_public_post_types();
		$fields = (array) get_option( 'twtw_fields', array() );
		$assoc = (array) get_option( 'twtw_posttype_fields', array() );

		// Post type selecionado
		$selected_pt = isset( $_GET['pt'] ) ? TWTW_Helpers::sanitize_post_type( (string) $_GET['pt'] ) : '';
		if ( $selected_pt === '' || ! isset( $post_types[ $selected_pt ] ) ) {
			$selected_pt = isset( $post_types['post'] ) ? 'post' : (string) array_key_first( $post_types );
		}
		$selected_obj = isset( $post_types[ $selected_pt ] ) ? $post_types[ $selected_pt ] : null;

		$this->header( 'Campos' );

		echo '<div class="twtw-topbar">';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="twtw-fields">';
		echo '<select name="pt" class="twtw-select">';
		foreach ( $post_types as $pt => $obj ) {
			$label = isset( $obj->labels->name ) ? (string) $obj->labels->name : $pt;
			echo '<option value="' . esc_attr( $pt ) . '" ' . selected( $pt, $selected_pt, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		submit_button( 'Alterar', 'secondary', '', false );
		echo '</form>';
		echo '</div>';

		echo '<div class="twtw-grid">';

		// Biblioteca de campos
		echo '<div class="twtw-card">';
		echo '<h2>Biblioteca de campos</h2>';
		echo '<p class="description">Cria campos internos ou importa meta keys existentes. Depois ativa por post type no painel ao lado.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="twtw_save_fields">';
		echo '<input type="hidden" name="pt" value="' . esc_attr( $selected_pt ) . '">';
		wp_nonce_field( 'twtw_save_fields', 'twtw_nonce' );

		echo '<table class="widefat striped"><thead><tr><th>Chave</th><th>Label</th><th>Tipo</th><th>Ativo</th><th>Remover</th></tr></thead><tbody>';
		if ( empty( $fields ) ) {
			echo '<tr><td colspan="5">Ainda não tens campos internos.</td></tr>';
		} else {
			foreach ( $fields as $key => $f ) {
				$key_s = sanitize_key( $key );
				$label = isset( $f['label'] ) ? (string) $f['label'] : $key_s;
				$type = isset( $f['type'] ) ? (string) $f['type'] : 'text';
				$active = ! empty( $f['active'] ) ? 1 : 0;

				echo '<tr>';
				echo '<td><input type="text" name="fields[' . esc_attr( $key_s ) . '][key]" value="' . esc_attr( $key_s ) . '" class="regular-text" readonly></td>';
				echo '<td><input type="text" name="fields[' . esc_attr( $key_s ) . '][label]" value="' . esc_attr( $label ) . '" class="regular-text"></td>';
				echo '<td><select name="fields[' . esc_attr( $key_s ) . '][type]">';
				foreach ( $this->field_types() as $t => $tl ) {
					echo '<option value="' . esc_attr( $t ) . '" ' . selected( $t, $type, false ) . '>' . esc_html( $tl ) . '</option>';
				}
				echo '</select></td>';
				echo '<td><label><input type="checkbox" name="fields[' . esc_attr( $key_s ) . '][active]" value="1" ' . checked( 1, $active, false ) . '> Ativo</label></td>';
				echo '<td><label><input type="checkbox" name="delete_fields[' . esc_attr( $key_s ) . ']" value="1"> Remover</label></td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		echo '<h3>Criar novo campo</h3>';
		echo '<p><input type="text" name="new_key" placeholder="twtcf_nome" class="regular-text"> ';
		echo '<input type="text" name="new_label" placeholder="Nome" class="regular-text"> ';
		echo '<select name="new_type">';
		foreach ( $this->field_types() as $t => $tl ) {
			echo '<option value="' . esc_attr( $t ) . '">' . esc_html( $tl ) . '</option>';
		}
		echo '</select></p>';

		echo '<h3>Importar meta key existente</h3>';
		$meta_keys = $this->get_meta_keys_for_post_type( $selected_pt );
		echo '<p class="description">Dica: escolhe uma meta key já existente no post type, ou escreve manualmente.</p>';
		echo '<p>';
		echo '<select id="twtw-import-meta" class="twtw-select">';
		echo '<option value="">Selecionar meta key</option>';
		foreach ( $meta_keys as $mk ) {
			echo '<option value="' . esc_attr( $mk ) . '">' . esc_html( $mk ) . '</option>';
		}
		echo '</select> ';
		echo '<input type="text" id="twtw-import-meta-input" name="import_key" placeholder="meta_key_existente" class="regular-text"> ';
		echo '<span class="description">Isto cria um campo interno com a mesma chave.</span>';
		echo '</p>';
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var s=document.getElementById("twtw-import-meta");var i=document.getElementById("twtw-import-meta-input");if(!s||!i){return;}s.addEventListener("change",function(){i.value=s.value||"";});});</script>';

		submit_button( 'Guardar biblioteca' );
		echo '</form>';
		echo '</div>';

		// Ativar por post type selecionado
		echo '<div class="twtw-card">';
		echo '<h2>Ativar campos por post type</h2>';
		if ( $selected_obj ) {
			echo '<p class="description">Post type selecionado: <strong>' . esc_html( $selected_obj->labels->name ) . '</strong> <span class="twtw-muted">(' . esc_html( $selected_pt ) . ')</span></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="twtw_save_fields">';
		echo '<input type="hidden" name="pt" value="' . esc_attr( $selected_pt ) . '">';
		wp_nonce_field( 'twtw_save_fields', 'twtw_nonce' );

		$pt_assoc = isset( $assoc[ $selected_pt ] ) && is_array( $assoc[ $selected_pt ] ) ? $assoc[ $selected_pt ] : array();

		if ( empty( $fields ) ) {
			echo '<p class="description">Cria ou importa campos na biblioteca primeiro.</p>';
		} else {
			echo '<div class="twtw-list">';
			foreach ( $fields as $k => $f ) {
				$key = sanitize_key( $k );
				$label = isset( $f['label'] ) ? (string) $f['label'] : $key;
				$checked = isset( $pt_assoc[ $key ] ) ? 1 : 0;
				$disabled = empty( $f['active'] ) ? ' disabled' : '';
				$hint = empty( $f['active'] ) ? ' <span class="twtw-muted">(inativo na biblioteca)</span>' : '';
				echo '<label class="twtw-check"><input type="checkbox" name="assoc[' . esc_attr( $selected_pt ) . '][' . esc_attr( $key ) . ']" value="1" ' . checked( 1, $checked, false ) . $disabled . '> ' . esc_html( $label ) . ' <span class="twtw-muted">' . esc_html( $key ) . '</span>' . $hint . '</label>';
			}
			echo '</div>';
		}

		submit_button( 'Guardar ativações' );
		echo '</form>';
		echo '</div>';

		echo '</div>';

		$this->footer();
	}

	public function page_mapping() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissões.' ); }
		$this->header( 'Mapeamento' );

		$post_types = TWTW_Helpers::get_public_post_types();
		$selected = '';
		if ( isset( $_GET['post_type'] ) ) {
			$selected = TWTW_Helpers::sanitize_post_type( $_GET['post_type'] );
		}
		if ( $selected === '' ) {
			$keys = array_keys( $post_types );
			$selected = $keys ? $keys[0] : '';
		}

		$fields = (array) get_option( 'twtw_fields', array() );
		$assoc = (array) get_option( 'twtw_posttype_fields', array() );
		$active_fields = isset($assoc[$selected]) && is_array($assoc[$selected]) ? array_keys( array_filter($assoc[$selected]) ) : array();
		// Só permitir selecionar campos que existam e estejam ativos na biblioteca.
		$active_fields = array_values( array_filter( $active_fields, function( $k ) use ( $fields ) {
			$k = sanitize_key( (string) $k );
			return $k !== '' && isset( $fields[ $k ] ) && ! empty( $fields[ $k ]['active'] );
		} ) );

		$map_all = (array) get_option( 'twtw_wallet_mapping', array() );
		$map = isset( $map_all[$selected] ) && is_array( $map_all[$selected] ) ? $map_all[$selected] : array();

			echo '<form method="get" action="' . esc_url( admin_url('admin.php') ) . '" class="twtw-inline">';
			echo '<input type="hidden" name="page" value="twtw-mapping">';
			echo '<select name="post_type" id="twtw-post-type">';
		foreach ( $post_types as $pt => $obj ) {
			echo '<option value="' . esc_attr($pt) . '" ' . selected($pt,$selected,false) . '>' . esc_html($obj->labels->name) . '</option>';
		}
		echo '</select> ';
			// Evitar parâmetros extra (ex: submit=Alterar) que em alguns ambientes podem levar o WP a não resolver a página.
			echo '<button type="button" class="button" id="twtw-change-pt">Alterar</button>';
		echo '</form>';

		// Redirecionamento via JS para evitar GET com submit e reduzir interferência de plugins que mexem no menu.
		$base = TWTW_Helpers::admin_url_page( 'twtw-mapping', array() );
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var b=document.getElementById("twtw-change-pt");var s=document.getElementById("twtw-post-type");if(!b||!s){return;}b.addEventListener("click",function(e){e.preventDefault();var pt=s.value||"";var url="' . esc_js( $base ) . '";url += (url.indexOf("?")>-1?"&":"?") + "post_type=" + encodeURIComponent(pt);window.location.href=url;});});</script>';

		if ( $selected === '' ) {
			echo '<p>Não há post types públicos disponíveis.</p>';
			$this->footer();
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '">';
		echo '<input type="hidden" name="action" value="twtw_save_mapping">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr($selected) . '">';
		wp_nonce_field( 'twtw_save_mapping', 'twtw_nonce' );

		echo '<p class="description">Mapeia campos do cartão para campos do teu post type. Só aparecem campos ativos neste post type.</p>';

		$wallet_fields = $this->wallet_fields();
		echo '<table class="widefat striped"><thead><tr><th>Campo do cartão</th><th>Campo do post</th></tr></thead><tbody>';
		foreach ( $wallet_fields as $wf_key => $wf_label ) {
			$current = isset( $map[$wf_key] ) ? sanitize_key( (string) $map[$wf_key] ) : '';
			echo '<tr>';
			echo '<td><strong>' . esc_html( $wf_label ) . '</strong><div class="twtw-muted">' . esc_html($wf_key) . '</div></td>';
			echo '<td><select name="map[' . esc_attr($wf_key) . ']" class="twtw-select">';
			echo '<option value="">Sem mapeamento</option>';
			echo '<optgroup label="Campos do WordPress">';
			$builtin = array(
				'__post_title' => 'Título do post',
				'__post_excerpt' => 'Excerto',
				'__post_permalink' => 'Permalink',
				'__featured_image' => 'Imagem destacada (URL)',
			);
			foreach ( $builtin as $bk => $bl ) {
				echo '<option value="' . esc_attr( $bk ) . '" ' . selected( $bk, $current, false ) . '>' . esc_html( $bl ) . ' (' . esc_html( $bk ) . ')</option>';
			}
			echo '</optgroup>';
			echo '<optgroup label="Campos ativos deste post type">';
			foreach ( $active_fields as $fk ) {
				$label = isset($fields[$fk]['label']) ? (string)$fields[$fk]['label'] : $fk;
				echo '<option value="' . esc_attr($fk) . '" ' . selected($fk,$current,false) . '>' . esc_html($label) . ' (' . esc_html($fk) . ')</option>';
			}
			echo '</optgroup>';
			echo '</select></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		submit_button( 'Guardar mapeamento' );
		echo '</form>';

		$this->footer();
	}

	public function page_layouts() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissões.' ); }
		$this->header( 'Layouts' );
		echo '<p class="description">Nesta fase, os layouts são guardados por post type. Integração no JWT entra no próximo passo.</p>';
		$this->footer();
	}

	public function page_templates() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissões.' ); }
		$this->header( 'Templates e NFC' );
		echo '<p class="description">Nesta fase, templates e NFC ficam registados no BO. Integração completa entra no próximo passo.</p>';
		$this->footer();
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissões.' ); }
		check_admin_referer( 'twtw_save_settings', 'twtw_nonce' );

		$issuer = isset($_POST['issuer_id']) ? sanitize_text_field( (string) $_POST['issuer_id'] ) : '';
		$issuer_name = isset($_POST['issuer_name']) ? sanitize_text_field( (string) $_POST['issuer_name'] ) : '';
		$allowed_raw = isset($_POST['allowed_origins']) ? (string) $_POST['allowed_origins'] : '';
		$debug = ! empty($_POST['debug']) ? 1 : 0;

		$lines = preg_split( '/\r\n|\r|\n/', $allowed_raw );
		$clean = array();
		foreach ( $lines as $l ) {
			$l = trim( (string) $l );
			if ( $l === '' ) { continue; }
			if ( ! preg_match('~^https?://~i', $l) ) {
				$l = 'https://' . $l;
			}
			if ( filter_var( $l, FILTER_VALIDATE_URL ) ) {
				$clean[] = esc_url_raw( $l );
			}
		}
		$clean = array_values( array_unique( $clean ) );

		update_option( 'twtw_issuer_id', $issuer );
		update_option( 'twtw_issuer_name', $issuer_name );
		update_option( 'twtw_allowed_origins', $clean );
		update_option( 'twtw_debug', $debug );

		wp_safe_redirect( TWTW_Helpers::admin_url_page( 'twtw-settings', array( 'twtw_message' => 'saved' ) ) );
		exit;
	}

	public function handle_upload_credentials() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissões.' ); }
		check_admin_referer( 'twtw_upload_credentials', 'twtw_nonce' );

		if ( empty( $_FILES['credentials'] ) || empty( $_FILES['credentials']['tmp_name'] ) ) {
			wp_safe_redirect( TWTW_Helpers::admin_url_page( 'twtw-settings', array( 'twtw_message' => 'upload_fail' ) ) );
			exit;
		}

		if ( ! TWTW_Helpers::ensure_uploads_dir() ) {
			wp_safe_redirect( TWTW_Helpers::admin_url_page( 'twtw-settings', array( 'twtw_message' => 'upload_fail' ) ) );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$overrides = array( 'test_form' => false, 'mimes' => array( 'json' => 'application/json' ) );
		$uploaded = wp_handle_upload( $_FILES['credentials'], $overrides );

		if ( isset( $uploaded['error'] ) ) {
			wp_safe_redirect( TWTW_Helpers::admin_url_page( 'twtw-settings', array( 'twtw_message' => 'upload_fail' ) ) );
			exit;
		}

		$tmp = $uploaded['file'] ?? '';
		if ( ! $tmp || ! file_exists( $tmp ) ) {
			wp_safe_redirect( TWTW_Helpers::admin_url_page( 'twtw-settings', array( 'twtw_message' => 'upload_fail' ) ) );
			exit;
		}

		$raw = file_get_contents( $tmp );
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) || ! TWTW_Helpers::validate_credentials_array( $data ) ) {
			@unlink( $tmp );
			wp_safe_redirect( TWTW_Helpers::admin_url_page( 'twtw-settings', array( 'twtw_message' => 'bad_json' ) ) );
			exit;
		}

		$dest = TWTW_Helpers::credentials_path();
		$ok = false;

		// Usar WP_Filesystem quando possível
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}
		if ( $wp_filesystem && method_exists( $wp_filesystem, 'put_contents' ) ) {
			$ok = (bool) $wp_filesystem->put_contents( $dest, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), FS_CHMOD_FILE );
		} else {
			$ok = (bool) file_put_contents( $dest, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			if ( $ok ) { @chmod( $dest, 0644 ); }
		}

		@unlink( $tmp );

		wp_safe_redirect( TWTW_Helpers::admin_url_page( 'twtw-settings', array( 'twtw_message' => $ok ? 'upload_ok' : 'upload_fail' ) ) );
		exit;
	}

	public function handle_test_credentials() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissões.' ); }
		check_admin_referer( 'twtw_test_credentials', 'twtw_nonce' );

		$data = TWTW_Helpers::read_credentials();
		$issuer = (string) get_option( 'twtw_issuer_id', '' );

		$ok = ( $issuer !== '' ) && TWTW_Helpers::validate_credentials_array( $data ) && function_exists( 'openssl_sign' );

		wp_safe_redirect( TWTW_Helpers::admin_url_page( 'twtw-settings', array( 'twtw_message' => $ok ? 'test_ok' : 'test_fail' ) ) );
		exit;
	}

	/**
	 * Lista meta keys existentes para um post type (amostra leve, para UX).
	 */
	private function get_meta_keys_for_post_type( string $post_type ) : array {
		$post_type = TWTW_Helpers::sanitize_post_type( $post_type );
		if ( $post_type === '' ) { return array(); }

		$q = new WP_Query( array(
			'post_type' => $post_type,
			'post_status' => 'any',
			'posts_per_page' => 50,
			'fields' => 'ids',
			'no_found_rows' => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		) );

		$keys = array();
		if ( $q->have_posts() ) {
			foreach ( $q->posts as $pid ) {
				$pid = absint( $pid );
				if ( ! $pid ) { continue; }
				$meta = get_post_meta( $pid );
				if ( ! is_array( $meta ) ) { continue; }
				foreach ( array_keys( $meta ) as $k ) {
					$k = sanitize_key( (string) $k );
					if ( $k === '' ) { continue; }
					if ( strpos( $k, '_' ) === 0 ) { continue; } // esconder meta interna
					$keys[ $k ] = true;
				}
			}
		}
		$keys = array_keys( $keys );
		sort( $keys );
		return $keys;
	}

	public function handle_save_fields() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissões.' ); }
		check_admin_referer( 'twtw_save_fields', 'twtw_nonce' );

		$fields = (array) get_option( 'twtw_fields', array() );
		$assoc = (array) get_option( 'twtw_posttype_fields', array() );
		$mapping_all = (array) get_option( 'twtw_wallet_mapping', array() );

		// Atualizar biblioteca existente
		if ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ) {
			$new_fields = array();
			foreach ( $_POST['fields'] as $k => $f ) {
				$key = sanitize_key( $k );
				if ( $key === '' ) { continue; }
				$label = isset($f['label']) ? sanitize_text_field( (string) $f['label'] ) : $key;
				$type = isset($f['type']) ? sanitize_key( (string) $f['type'] ) : 'text';
				$active = ! empty($f['active']) ? 1 : 0;
				$new_fields[$key] = array( 'label' => $label, 'type' => $this->field_types_sanitized($type), 'active' => $active );
			}
			$fields = $new_fields;
		}

		// Novo campo
		$new_key = isset($_POST['new_key']) ? sanitize_key( (string) $_POST['new_key'] ) : '';
		if ( $new_key !== '' ) {
			$fields[$new_key] = array(
				'label' => isset($_POST['new_label']) ? sanitize_text_field( (string) $_POST['new_label'] ) : $new_key,
				'type'  => $this->field_types_sanitized( isset($_POST['new_type']) ? sanitize_key((string)$_POST['new_type']) : 'text' ),
				'active'=> 1,
			);
		}

		// Import meta key
		$import_key = isset($_POST['import_key']) ? sanitize_key( (string) $_POST['import_key'] ) : '';
		if ( $import_key !== '' && ! isset($fields[$import_key]) ) {
			$fields[$import_key] = array( 'label' => $import_key, 'type' => 'text', 'active' => 1 );
		}

		// Remover campos (limpa também associações e mapeamentos)
		$deleted_any = false;
		if ( isset( $_POST['delete_fields'] ) && is_array( $_POST['delete_fields'] ) ) {
			foreach ( array_keys( $_POST['delete_fields'] ) as $k ) {
				$k = sanitize_key( (string) $k );
				if ( $k === '' || ! isset( $fields[ $k ] ) ) { continue; }
				unset( $fields[ $k ] );
				$deleted_any = true;
				// Remover de associações
				foreach ( $assoc as $pt => $pairs ) {
					if ( isset( $assoc[ $pt ][ $k ] ) ) {
						unset( $assoc[ $pt ][ $k ] );
					}
				}
				// Remover de mapeamentos
				foreach ( $mapping_all as $pt => $map ) {
					if ( ! is_array( $map ) ) { continue; }
					foreach ( $map as $wk => $fk ) {
						if ( sanitize_key( (string) $fk ) === $k ) {
							$mapping_all[ $pt ][ $wk ] = '';
						}
					}
				}
			}
		}

		// Associações
		if ( isset($_POST['assoc']) && is_array($_POST['assoc']) ) {
			foreach ( $_POST['assoc'] as $pt => $pairs ) {
				$pt = TWTW_Helpers::sanitize_post_type( $pt );
				if ( $pt === '' ) { continue; }
				$assoc[$pt] = array();
				if ( is_array($pairs) ) {
					foreach ( $pairs as $fk => $on ) {
						$fk = sanitize_key( $fk );
						if ( $fk === '' ) { continue; }
						$assoc[$pt][$fk] = 1;
					}
				}
			}
		}

		update_option( 'twtw_fields', $fields );
		update_option( 'twtw_posttype_fields', $assoc );
		update_option( 'twtw_wallet_mapping', $mapping_all );

		$pt = isset($_POST['pt']) ? TWTW_Helpers::sanitize_post_type( (string) $_POST['pt'] ) : '';
		$args = array( 'twtw_message' => $deleted_any ? 'field_deleted' : 'saved' );
		if ( $pt !== '' ) { $args['pt'] = $pt; }
		wp_safe_redirect( TWTW_Helpers::admin_url_page( 'twtw-fields', $args ) );
		exit;
	}

	public function handle_save_mapping() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissões.' ); }
		check_admin_referer( 'twtw_save_mapping', 'twtw_nonce' );

		$pt = isset($_POST['post_type']) ? TWTW_Helpers::sanitize_post_type( $_POST['post_type'] ) : '';
		if ( $pt === '' ) {
			wp_safe_redirect( TWTW_Helpers::admin_url_page( 'twtw-mapping', array( 'twtw_message' => 'saved' ) ) );
			exit;
		}

		$map_all = (array) get_option( 'twtw_wallet_mapping', array() );
		$map = array();
		if ( isset($_POST['map']) && is_array($_POST['map']) ) {
			foreach ( $_POST['map'] as $wk => $fk ) {
				$wk = sanitize_key( $wk );
				$fk = sanitize_key( (string) $fk );
				$map[$wk] = $fk;
			}
		}
		$map_all[$pt] = $map;
		update_option( 'twtw_wallet_mapping', $map_all );

		wp_safe_redirect( TWTW_Helpers::admin_url_page( 'twtw-mapping', array( 'post_type' => $pt, 'twtw_message' => 'saved' ) ) );
		exit;
	}

	private function field_types() : array {
		return array(
			'text' => 'Texto',
			'number' => 'Número',
			'date' => 'Data',
			'color' => 'Cor',
			'email' => 'Email',
			'url' => 'URL',
			'image' => 'Imagem, foto',
		);
	}

	private function field_types_sanitized( string $type ) : string {
		$types = array_keys( $this->field_types() );
		return in_array( $type, $types, true ) ? $type : 'text';
	}

	private function wallet_fields() : array {
		return array(
			'name' => 'Nome',
			'email' => 'Email',
			'phone' => 'Telefone',
			'company' => 'Empresa',
			'position' => 'Cargo',
			'address' => 'Morada',
			'website' => 'Website',
			'photo' => 'Foto',
			'qr_value' => 'QR, valor',
			'bg_color' => 'Cor de fundo',
			'font_color' => 'Cor do texto',
		);
	}
}
