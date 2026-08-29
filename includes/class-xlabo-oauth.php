<?php
/**
 * OAuth 2.0 (PKCE) 認証ハンドラ。
 *
 * @package xLabo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * X OAuth 2.0 認可フローを担当する。
 */
class XLabo_OAuth {

	/**
	 * 期限の何秒前からトークンを更新するか。
	 *
	 * X のアクセストークンは2時間で切れる。期限ちょうどまで使うと、判定を通ったあと
	 * 送信中に切れて投稿が落ちるため、手前に余裕を取る。
	 */
	private const REFRESH_SKEW = 300;

	/**
	 * @var XLabo_Plugin
	 */
	private $plugin;

	/**
	 * @param XLabo_Plugin $plugin プラグインインスタンス。
	 */
	public function __construct( XLabo_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
	}

	/**
	 * OAuth コールバック URL。
	 */
	public function get_redirect_uri(): string {
		return admin_url( 'options-general.php?page=xlabo&xlabo_oauth=callback' );
	}

	/**
	 * 認可 URL を生成する。
	 */
	public function get_authorization_url(): string {
		$settings = $this->plugin->get_settings();
		$client_id = trim( (string) ( $settings['client_id'] ?? '' ) );

		if ( '' === $client_id ) {
			return '';
		}

		$code_verifier  = $this->generate_code_verifier();
		$code_challenge = $this->generate_code_challenge( $code_verifier );
		$state          = wp_generate_password( 32, false, false );

		set_transient( 'xlabo_oauth_code_verifier', $code_verifier, 15 * MINUTE_IN_SECONDS );
		set_transient( 'xlabo_oauth_state', $state, 15 * MINUTE_IN_SECONDS );

		$params = array(
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'redirect_uri'          => $this->get_redirect_uri(),
			'scope'                 => 'tweet.read tweet.write users.read offline.access',
			'state'                 => $state,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => 'S256',
		);

		return 'https://x.com/i/oauth2/authorize?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * OAuth コールバックを処理する。
	 */
	public function handle_oauth_callback(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || 'xlabo' !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['xlabo_oauth'] ) || 'callback' !== $_GET['xlabo_oauth'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( '' !== $error ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : $error;
			$this->redirect_with_notice( 'error', $description );

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$saved = get_transient( 'xlabo_oauth_state' );

		if ( '' === $state || false === $saved || ! hash_equals( (string) $saved, $state ) ) {
			$this->redirect_with_notice( 'error', __( 'OAuth state が一致しません。もう一度お試しください。', 'xlabo' ) );

			return;
		}

		delete_transient( 'xlabo_oauth_state' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		if ( '' === $code ) {
			$this->redirect_with_notice( 'error', __( '認可コードが取得できませんでした。', 'xlabo' ) );

			return;
		}

		$code_verifier = get_transient( 'xlabo_oauth_code_verifier' );

		if ( false === $code_verifier || '' === $code_verifier ) {
			$this->redirect_with_notice( 'error', __( 'code_verifier の有効期限が切れました。', 'xlabo' ) );

			return;
		}

		delete_transient( 'xlabo_oauth_code_verifier' );

		$result = $this->exchange_code_for_tokens( $code, (string) $code_verifier );

		if ( ! $result['success'] ) {
			$this->redirect_with_notice( 'error', $result['error'] ?? __( 'トークン取得に失敗しました。', 'xlabo' ) );

			return;
		}

		$this->redirect_with_notice( 'success', __( 'X アカウントを接続しました。', 'xlabo' ) );
	}

	/**
	 * 認可コードをアクセストークンに交換する。
	 *
	 * @param string $code          認可コード。
	 * @param string $code_verifier PKCE verifier。
	 * @return array{success: bool, error?: string}
	 */
	public function exchange_code_for_tokens( string $code, string $code_verifier ): array {
		$settings      = $this->plugin->get_settings();
		$client_id     = trim( (string) ( $settings['client_id'] ?? '' ) );
		$client_secret = $this->plugin->decrypt( (string) ( $settings['client_secret'] ?? '' ) );

		if ( '' === $client_id ) {
			return array(
				'success' => false,
				'error'   => __( 'Client ID が設定されていません。', 'xlabo' ),
			);
		}

		$body = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => $this->get_redirect_uri(),
			'code_verifier' => $code_verifier,
			'client_id'     => $client_id,
		);

		$headers = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		);

		if ( '' !== $client_secret ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
		}

		$response = wp_remote_post(
			'https://api.x.com/2/oauth2/token',
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error = is_array( $data ) && ! empty( $data['error_description'] )
				? (string) $data['error_description']
				: __( 'アクセストークンの取得に失敗しました。', 'xlabo' );

			return array(
				'success' => false,
				'error'   => $error,
			);
		}

		$this->store_tokens( $data );

		$verify = $this->plugin->api->verify_credentials();

		if ( $verify['success'] && ! empty( $verify['username'] ) ) {
			$settings = $this->plugin->get_settings();
			$settings['connected_username'] = (string) $verify['username'];
			$this->plugin->update_settings( $settings );
		}

		return array( 'success' => true );
	}

	/**
	 * リフレッシュトークンでアクセストークンを更新する。
	 *
	 * 失敗はすべてログに残す。X のリフレッシュトークンは使い捨てでローテーションするため、
	 * 一度取りこぼすと再接続するまで復旧しない。黙って false を返すと自動シェアが
	 * 恒久的に止まったことに誰も気づけないので、必ず理由を記録する。
	 * ログにトークンや秘密情報そのものを出さないこと。
	 */
	public function refresh_access_token(): bool {
		$settings       = $this->plugin->get_settings();
		$client_id      = trim( (string) ( $settings['client_id'] ?? '' ) );
		$client_secret  = $this->plugin->decrypt( (string) ( $settings['client_secret'] ?? '' ) );
		$refresh_token  = $this->plugin->decrypt( (string) ( $settings['oauth2_refresh_token'] ?? '' ) );

		if ( '' === $client_id || '' === $refresh_token ) {
			$this->plugin->add_log(
				__( 'アクセストークンを更新できません: クライアントIDまたはリフレッシュトークンが未設定です。設定画面から X に接続し直してください。', 'xlabo' ),
				'error'
			);

			return false;
		}

		$body = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id'     => $client_id,
		);

		$headers = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		);

		if ( '' !== $client_secret ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
		}

		$response = wp_remote_post(
			'https://api.x.com/2/oauth2/token',
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->plugin->add_log(
				sprintf(
					/* translators: %s: error message */
					__( 'アクセストークンの更新に失敗しました（通信エラー）: %s', 'xlabo' ),
					$response->get_error_message()
				),
				'error'
			);

			return false;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			// X が返すのはエラー種別と説明のみ。トークンは含まれないのでそのまま記録してよい。
			$detail = '';

			if ( is_array( $data ) ) {
				$detail = trim( (string) ( $data['error_description'] ?? $data['error'] ?? '' ) );
			}

			if ( '' === $detail ) {
				$detail = __( '応答にアクセストークンが含まれていませんでした。', 'xlabo' );
			}

			$this->plugin->add_log(
				sprintf(
					/* translators: 1: HTTP status code, 2: error detail */
					__( 'アクセストークンの更新に失敗しました（HTTP %1$d）: %2$s / 設定画面から X に接続し直してください。', 'xlabo' ),
					$status,
					$detail
				),
				'error'
			);

			return false;
		}

		$this->store_tokens( $data );

		return true;
	}

	/**
	 * トークンを保存する。
	 *
	 * @param array<string, mixed> $data トークンレスポンス。
	 */
	private function store_tokens( array $data ): void {
		$settings = $this->plugin->get_settings();

		$settings['oauth2_access_token'] = $this->plugin->encrypt( (string) $data['access_token'] );

		if ( ! empty( $data['refresh_token'] ) ) {
			$settings['oauth2_refresh_token'] = $this->plugin->encrypt( (string) $data['refresh_token'] );
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 7200;
		$settings['oauth2_token_expires'] = time() + max( 60, $expires_in - 60 );

		$this->plugin->update_settings( $settings );
	}

	/**
	 * OAuth 接続を解除する。
	 */
	public function disconnect(): void {
		$settings = $this->plugin->get_settings();

		$settings['oauth2_access_token']  = '';
		$settings['oauth2_refresh_token'] = '';
		$settings['oauth2_token_expires'] = 0;
		$settings['connected_username']   = '';

		$this->plugin->update_settings( $settings );
	}

	/**
	 * 期限切れ前にトークンを更新する。
	 *
	 * @return bool トークンが使える見込みなら true。更新が必要だったのに失敗したときだけ false。
	 */
	public function maybe_refresh_token(): bool {
		$settings = $this->plugin->get_settings();

		if ( 'oauth2' !== ( $settings['auth_method'] ?? 'oauth2' ) ) {
			return true;
		}

		$expires = (int) ( $settings['oauth2_token_expires'] ?? 0 );

		// 期限が記録されていない場合は判断材料が無いので、送信を止めずにそのまま試す。
		if ( $expires <= 0 ) {
			return true;
		}

		// 期限ちょうどまで引っ張ると、判定を通ったあと送信中に切れる。手前で更新しておく。
		if ( time() < $expires - self::REFRESH_SKEW ) {
			return true;
		}

		return $this->refresh_access_token();
	}

	/**
	 * アクセストークンが期限切れ（または期限切れ間近）かどうか。
	 *
	 * 管理画面で「再接続が必要」を出すための判定。OAuth 1.0a には期限が無いので常に false。
	 */
	public function is_token_expired(): bool {
		$settings = $this->plugin->get_settings();

		if ( 'oauth2' !== ( $settings['auth_method'] ?? 'oauth2' ) ) {
			return false;
		}

		$expires = (int) ( $settings['oauth2_token_expires'] ?? 0 );

		if ( $expires <= 0 ) {
			return false;
		}

		return time() >= $expires - self::REFRESH_SKEW;
	}

	/**
	 * code_verifier を生成する。
	 */
	private function generate_code_verifier(): string {
		$bytes = random_bytes( 32 );

		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * code_challenge を生成する。
	 *
	 * @param string $code_verifier verifier。
	 */
	private function generate_code_challenge( string $code_verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * 管理画面へリダイレクトして通知を表示する。
	 *
	 * @param string $type    通知タイプ。
	 * @param string $message メッセージ。
	 */
	private function redirect_with_notice( string $type, string $message ): void {
		$redirect = add_query_arg(
			array(
				'page'          => 'xlabo',
				'xlabo_notice'  => $type,
				'xlabo_message' => rawurlencode( $message ),
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
