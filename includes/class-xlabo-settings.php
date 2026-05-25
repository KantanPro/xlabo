<?php
/**
 * 管理画面設定。
 *
 * @package xLabo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定ページと保存処理を担当する。
 */
class XLabo_Settings {

	/**
	 * @var XLabo_Plugin
	 */
	private $plugin;

	/**
	 * @param XLabo_Plugin $plugin プラグインインスタンス。
	 */
	public function __construct( XLabo_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_post_xlabo_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_xlabo_test_tweet', array( $this, 'handle_test_tweet' ) );
		add_action( 'admin_post_xlabo_clear_log', array( $this, 'handle_clear_log' ) );
	}

	/**
	 * 設定メニューを登録する。
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'xLabo 設定', 'xlabo' ),
			__( 'xLabo', 'xlabo' ),
			'manage_options',
			'xlabo',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * 設定を登録する。
	 */
	public function register_settings(): void {
		register_setting(
			'xlabo_settings_group',
			XLabo_Plugin::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => XLabo_Plugin::default_settings(),
			)
		);
	}

	/**
	 * 設定値をサニタイズする。
	 *
	 * @param mixed $input 入力値。
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ): array {
		$current  = $this->plugin->get_settings();
		$defaults = XLabo_Plugin::default_settings();
		$input    = is_array( $input ) ? $input : array();
		$output   = wp_parse_args( $input, $defaults );

		$output['enabled']         = ! empty( $input['enabled'] ) ? 1 : 0;
		$output['include_hashtags'] = ! empty( $input['include_hashtags'] ) ? 1 : 0;
		$output['share_on_update']  = ! empty( $input['share_on_update'] ) ? 1 : 0;
		$output['include_featured_image'] = ! empty( $input['include_featured_image'] ) ? 1 : 0;
		$output['enable_twitter_card_meta'] = ! empty( $input['enable_twitter_card_meta'] ) ? 1 : 0;

		$output['auth_method'] = in_array( $output['auth_method'] ?? '', array( 'oauth2', 'oauth1' ), true )
			? $output['auth_method']
			: 'oauth2';

		$output['client_id']     = sanitize_text_field( (string) ( $input['client_id'] ?? '' ) );
		$output['api_key']       = sanitize_text_field( (string) ( $input['api_key'] ?? '' ) );
		$output['access_token']  = sanitize_text_field( (string) ( $input['access_token'] ?? '' ) );
		$output['tweet_template'] = sanitize_textarea_field( (string) ( $input['tweet_template'] ?? $defaults['tweet_template'] ) );
		$output['append_hashtags'] = sanitize_text_field( (string) ( $input['append_hashtags'] ?? '' ) );

		$secret_fields = array(
			'client_secret'       => 'client_secret',
			'api_secret'          => 'api_secret',
			'access_token_secret' => 'access_token_secret',
		);

		foreach ( $secret_fields as $field => $current_key ) {
			$value = isset( $input[ $field ] ) ? (string) $input[ $field ] : '';

			if ( '' !== trim( $value ) ) {
				$output[ $field ] = $this->plugin->encrypt( $value );
			} else {
				$output[ $field ] = $current[ $current_key ] ?? '';
			}
		}

		$post_types = isset( $input['post_types'] ) && is_array( $input['post_types'] )
			? array_map( 'sanitize_key', $input['post_types'] )
			: array( 'post' );

		$output['post_types'] = array_values( array_filter( $post_types ) );

		if ( empty( $output['post_types'] ) ) {
			$output['post_types'] = array( 'post' );
		}

		$output['oauth2_access_token']  = $current['oauth2_access_token'] ?? '';
		$output['oauth2_refresh_token']   = $current['oauth2_refresh_token'] ?? '';
		$output['oauth2_token_expires']   = (int) ( $current['oauth2_token_expires'] ?? 0 );
		$output['connected_username']     = sanitize_text_field( (string) ( $current['connected_username'] ?? '' ) );
		$output['log_entries']            = is_array( $current['log_entries'] ?? null ) ? $current['log_entries'] : array();

		return $output;
	}

	/**
	 * 管理画面アセット。
	 *
	 * @param string $hook フック名。
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_xlabo' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'xlabo-admin',
			XLABO_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			XLABO_VERSION
		);

		wp_enqueue_script(
			'xlabo-admin',
			XLABO_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			XLABO_VERSION,
			true
		);
	}

	/**
	 * 通知を表示する。
	 */
	public function render_admin_notices(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || 'xlabo' !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['xlabo_notice'] ) ? sanitize_key( wp_unslash( $_GET['xlabo_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['xlabo_message'] ) ? rawurldecode( wp_unslash( $_GET['xlabo_message'] ) ) : '';

		$class = 'notice notice-info';

		if ( 'success' === $notice ) {
			$class = 'notice notice-success is-dismissible';
		} elseif ( 'error' === $notice ) {
			$class = 'notice notice-error';
		}

		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * OAuth 接続解除。
	 */
	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'xlabo' ) );
		}

		check_admin_referer( 'xlabo_disconnect' );

		$this->plugin->oauth->disconnect();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'xlabo',
					'xlabo_notice'  => 'success',
					'xlabo_message' => rawurlencode( __( 'X 接続を解除しました。', 'xlabo' ) ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * テスト投稿。
	 */
	public function handle_test_tweet(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'xlabo' ) );
		}

		check_admin_referer( 'xlabo_test_tweet' );

		$this->plugin->oauth->maybe_refresh_token();

		$result = $this->plugin->api->post_tweet(
			sprintf(
				/* translators: %s: site name */
				__( 'xLabo 接続テスト from %s', 'xlabo' ),
				get_bloginfo( 'name' )
			)
		);

		if ( $result['success'] ) {
			$this->plugin->add_log( __( '接続テスト投稿に成功しました。', 'xlabo' ), 'success' );
			$notice  = 'success';
			$message = __( 'テスト投稿に成功しました。', 'xlabo' );
		} else {
			$this->plugin->add_log(
				sprintf(
					/* translators: %s: error message */
					__( '接続テスト投稿に失敗: %s', 'xlabo' ),
					$result['error'] ?? ''
				),
				'error'
			);
			$notice  = 'error';
			$message = $result['error'] ?? __( 'テスト投稿に失敗しました。', 'xlabo' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'xlabo',
					'xlabo_notice'  => $notice,
					'xlabo_message' => rawurlencode( $message ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * ログをクリアする。
	 */
	public function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'xlabo' ) );
		}

		check_admin_referer( 'xlabo_clear_log' );

		$settings = $this->plugin->get_settings();
		$settings['log_entries'] = array();
		$this->plugin->update_settings( $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'xlabo',
					'xlabo_notice'  => 'success',
					'xlabo_message' => rawurlencode( __( 'ログをクリアしました。', 'xlabo' ) ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * 設定ページを描画する。
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = $this->plugin->get_settings();
		$connected  = $this->plugin->is_connected();
		$auth_url   = $this->plugin->oauth->get_authorization_url();
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		?>
		<div class="wrap xlabo-settings">
			<h1><?php echo esc_html__( 'xLabo 設定', 'xlabo' ); ?></h1>
			<p><?php echo esc_html__( 'WordPress の投稿を公開したタイミングで X（旧 Twitter）へ自動シェアします。', 'xlabo' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'xlabo_settings_group' ); ?>

				<h2 class="title"><?php echo esc_html__( '一般設定', 'xlabo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( '自動シェア', 'xlabo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
								<?php echo esc_html__( '投稿公開時に X へ自動シェアする', 'xlabo' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( '対象投稿タイプ', 'xlabo' ); ?></th>
						<td>
							<?php
							$selected_types = is_array( $settings['post_types'] ?? null ) ? $settings['post_types'] : array( 'post' );

							foreach ( $post_types as $post_type ) :
								?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $selected_types, true ) ); ?> />
									<?php echo esc_html( $post_type->labels->singular_name ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( '更新時の再シェア', 'xlabo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[share_on_update]" value="1" <?php checked( ! empty( $settings['share_on_update'] ) ); ?> />
								<?php echo esc_html__( '初回公開後に更新された場合も再シェアする', 'xlabo' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( '通常は初回公開時のみシェアします。', 'xlabo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php echo esc_html__( '投稿テンプレート', 'xlabo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="xlabo-tweet-template"><?php echo esc_html__( 'ツイート本文', 'xlabo' ); ?></label></th>
						<td>
							<textarea id="xlabo-tweet-template" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[tweet_template]" rows="4" class="large-text code"><?php echo esc_textarea( (string) ( $settings['tweet_template'] ?? '' ) ); ?></textarea>
							<p class="description">
								<?php echo esc_html__( '利用可能なプレースホルダ: {title}, {url}, {excerpt}', 'xlabo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'タグをハッシュタグ化', 'xlabo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[include_hashtags]" value="1" <?php checked( ! empty( $settings['include_hashtags'] ) ); ?> />
								<?php echo esc_html__( '投稿タグを #ハッシュタグ として末尾に追加', 'xlabo' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xlabo-append-hashtags"><?php echo esc_html__( '固定ハッシュタグ', 'xlabo' ); ?></label></th>
						<td>
							<input id="xlabo-append-hashtags" type="text" class="regular-text" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[append_hashtags]" value="<?php echo esc_attr( (string) ( $settings['append_hashtags'] ?? '' ) ); ?>" placeholder="#WordPress #Blog" />
						</td>
					</tr>
				</table>

				<h2 class="title"><?php echo esc_html__( 'アイキャッチ / Twitter Card', 'xlabo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'アイキャッチ画像', 'xlabo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[include_featured_image]" value="1" <?php checked( ! empty( $settings['include_featured_image'] ) ); ?> />
								<?php echo esc_html__( 'X 投稿時にアイキャッチ画像を添付する（大画像表示）', 'xlabo' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'アイキャッチがある場合、X API 経由で画像をアップロードし、タイムライン上で大きく表示されます。', 'xlabo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Twitter Card メタタグ', 'xlabo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[enable_twitter_card_meta]" value="1" <?php checked( ! empty( $settings['enable_twitter_card_meta'] ) ); ?> />
								<?php echo esc_html__( '記事ページに summary_large_image メタタグを出力する', 'xlabo' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'URL プレビュー用に twitter:card=summary_large_image と og:image を出力します。SEO プラグイン利用時は競合に注意してください。', 'xlabo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php echo esc_html__( 'X API 認証', 'xlabo' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: developer portal URL */
						esc_html__( 'X Developer Portal（%s）でアプリを作成し、Callback URL に以下を登録してください。', 'xlabo' ),
						'https://developer.x.com/'
					);
					?>
				</p>
				<p><code><?php echo esc_html( $this->plugin->oauth->get_redirect_uri() ); ?></code></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( '認証方式', 'xlabo' ); ?></th>
						<td>
							<label style="margin-right:16px;">
								<input type="radio" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[auth_method]" value="oauth2" class="xlabo-auth-method" <?php checked( 'oauth2', $settings['auth_method'] ?? 'oauth2' ); ?> />
								OAuth 2.0（推奨）
							</label>
							<label>
								<input type="radio" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[auth_method]" value="oauth1" class="xlabo-auth-method" <?php checked( 'oauth1', $settings['auth_method'] ?? 'oauth2' ); ?> />
								OAuth 1.0a
							</label>
						</td>
					</tr>
				</table>

				<div class="xlabo-auth-panel xlabo-auth-oauth2" <?php echo ( 'oauth1' === ( $settings['auth_method'] ?? 'oauth2' ) ) ? 'style="display:none;"' : ''; ?>>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="xlabo-client-id"><?php echo esc_html__( 'Client ID', 'xlabo' ); ?></label></th>
							<td>
								<input id="xlabo-client-id" type="text" class="regular-text" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[client_id]" value="<?php echo esc_attr( (string) ( $settings['client_id'] ?? '' ) ); ?>" autocomplete="off" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="xlabo-client-secret"><?php echo esc_html__( 'Client Secret', 'xlabo' ); ?></label></th>
							<td>
								<input id="xlabo-client-secret" type="password" class="regular-text" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[client_secret]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr__( '変更する場合のみ入力', 'xlabo' ); ?>" />
							</td>
						</tr>
					</table>

					<?php if ( $connected && 'oauth2' === ( $settings['auth_method'] ?? 'oauth2' ) ) : ?>
						<div class="xlabo-connection-status is-connected">
							<p>
								<strong><?php echo esc_html__( '接続済み', 'xlabo' ); ?></strong>
								<?php if ( ! empty( $settings['connected_username'] ) ) : ?>
									@<?php echo esc_html( (string) $settings['connected_username'] ); ?>
								<?php endif; ?>
							</p>
						</div>
					<?php endif; ?>
				</div>

				<div class="xlabo-auth-panel xlabo-auth-oauth1" <?php echo ( 'oauth1' !== ( $settings['auth_method'] ?? 'oauth2' ) ) ? 'style="display:none;"' : ''; ?>>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="xlabo-api-key"><?php echo esc_html__( 'API Key', 'xlabo' ); ?></label></th>
							<td><input id="xlabo-api-key" type="text" class="regular-text" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[api_key]" value="<?php echo esc_attr( (string) ( $settings['api_key'] ?? '' ) ); ?>" autocomplete="off" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="xlabo-api-secret"><?php echo esc_html__( 'API Secret', 'xlabo' ); ?></label></th>
							<td><input id="xlabo-api-secret" type="password" class="regular-text" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[api_secret]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr__( '変更する場合のみ入力', 'xlabo' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="xlabo-access-token"><?php echo esc_html__( 'Access Token', 'xlabo' ); ?></label></th>
							<td><input id="xlabo-access-token" type="text" class="regular-text" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[access_token]" value="<?php echo esc_attr( (string) ( $settings['access_token'] ?? '' ) ); ?>" autocomplete="off" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="xlabo-access-token-secret"><?php echo esc_html__( 'Access Token Secret', 'xlabo' ); ?></label></th>
							<td><input id="xlabo-access-token-secret" type="password" class="regular-text" name="<?php echo esc_attr( XLabo_Plugin::OPTION_KEY ); ?>[access_token_secret]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr__( '変更する場合のみ入力', 'xlabo' ); ?>" /></td>
						</tr>
					</table>
					<p class="description"><?php echo esc_html__( 'Developer Portal の Keys and Tokens から Access Token / Secret を生成して入力できます。', 'xlabo' ); ?></p>
				</div>

				<?php submit_button( __( '設定を保存', 'xlabo' ) ); ?>
			</form>

			<hr />

			<h2><?php echo esc_html__( '接続操作', 'xlabo' ); ?></h2>
			<div class="xlabo-actions">
				<?php if ( 'oauth2' === ( $settings['auth_method'] ?? 'oauth2' ) && '' !== $auth_url ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $auth_url ); ?>">
						<?php echo esc_html__( 'X アカウントを接続', 'xlabo' ); ?>
					</a>
				<?php endif; ?>

				<?php if ( $connected ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:8px;">
						<?php wp_nonce_field( 'xlabo_test_tweet' ); ?>
						<input type="hidden" name="action" value="xlabo_test_tweet" />
						<?php submit_button( __( '接続テスト投稿', 'xlabo' ), 'secondary', 'submit', false ); ?>
					</form>

					<?php if ( 'oauth2' === ( $settings['auth_method'] ?? 'oauth2' ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:8px;">
							<?php wp_nonce_field( 'xlabo_disconnect' ); ?>
							<input type="hidden" name="action" value="xlabo_disconnect" />
							<?php submit_button( __( '接続を解除', 'xlabo' ), 'delete', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<h2><?php echo esc_html__( 'シェアログ', 'xlabo' ); ?></h2>
			<?php $this->render_log_table( $settings ); ?>
		</div>
		<?php
	}

	/**
	 * ログテーブルを描画する。
	 *
	 * @param array<string, mixed> $settings 設定。
	 */
	private function render_log_table( array $settings ): void {
		$entries = is_array( $settings['log_entries'] ?? null ) ? $settings['log_entries'] : array();

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'ログはまだありません。', 'xlabo' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped xlabo-log-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( '日時', 'xlabo' ) . '</th>';
		echo '<th>' . esc_html__( 'レベル', 'xlabo' ) . '</th>';
		echo '<th>' . esc_html__( 'メッセージ', 'xlabo' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			printf(
				'<tr><td>%1$s</td><td><span class="xlabo-log-level xlabo-log-level-%2$s">%2$s</span></td><td>%3$s</td></tr>',
				esc_html( (string) ( $entry['time'] ?? '' ) ),
				esc_attr( (string) ( $entry['level'] ?? 'info' ) ),
				esc_html( (string) ( $entry['message'] ?? '' ) )
			);
		}

		echo '</tbody></table>';

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
			<?php wp_nonce_field( 'xlabo_clear_log' ); ?>
			<input type="hidden" name="action" value="xlabo_clear_log" />
			<?php submit_button( __( 'ログをクリア', 'xlabo' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}
}
