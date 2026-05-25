<?php
/**
 * xLabo メインプラグインクラス。
 *
 * @package xLabo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * プラグイン全体の初期化を担当する。
 */
final class XLabo_Plugin {

	public const OPTION_KEY = 'xlabo_settings';

	public const META_TWEET_ID = '_xlabo_tweet_id';

	public const META_SHARED_AT = '_xlabo_shared_at';

	public const META_MEDIA_ID = '_xlabo_media_id';

	/**
	 * @var XLabo_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var XLabo_Settings
	 */
	public $settings;

	/**
	 * @var XLabo_OAuth
	 */
	public $oauth;

	/**
	 * @var XLabo_Api_Client
	 */
	public $api;

	/**
	 * @var XLabo_Auto_Poster
	 */
	public $auto_poster;

	/**
	 * @var XLabo_Twitter_Cards
	 */
	public $twitter_cards;

	/**
	 * @return XLabo_Plugin
	 */
	public static function instance(): XLabo_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->settings    = new XLabo_Settings( $this );
		$this->oauth       = new XLabo_OAuth( $this );
		$this->api            = new XLabo_Api_Client( $this );
		$this->auto_poster    = new XLabo_Auto_Poster( $this );
		$this->twitter_cards  = new XLabo_Twitter_Cards( $this );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * 翻訳ファイルを読み込む。
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'xlabo', false, dirname( plugin_basename( XLABO_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * 有効化時の初期設定。
	 */
	public static function activate(): void {
		if ( false === get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, self::default_settings() );
		}
	}

	/**
	 * 無効化時のクリーンアップ（トランジエントのみ）。
	 */
	public static function deactivate(): void {
		delete_transient( 'xlabo_oauth_state' );
		delete_transient( 'xlabo_oauth_code_verifier' );
	}

	/**
	 * デフォルト設定。
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'enabled'           => 0,
			'auth_method'       => 'oauth2',
			'client_id'         => '',
			'client_secret'     => '',
			'api_key'           => '',
			'api_secret'        => '',
			'access_token'      => '',
			'access_token_secret' => '',
			'oauth2_access_token'  => '',
			'oauth2_refresh_token' => '',
			'oauth2_token_expires' => 0,
			'connected_username'   => '',
			'post_types'        => array( 'post' ),
			'tweet_template'    => "{title}\n{url}",
			'include_hashtags'  => 0,
			'append_hashtags'   => '',
			'share_on_update'   => 0,
			'include_featured_image' => 1,
			'enable_twitter_card_meta' => 1,
			'log_entries'       => array(),
		);
	}

	/**
	 * 設定を取得する。
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$settings = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, self::default_settings() );
	}

	/**
	 * 設定を保存する。
	 *
	 * @param array<string, mixed> $settings 設定配列。
	 */
	public function update_settings( array $settings ): void {
		update_option( self::OPTION_KEY, wp_parse_args( $settings, self::default_settings() ) );
	}

	/**
	 * 単一設定値を取得する。
	 *
	 * @param string $key     キー。
	 * @param mixed  $default デフォルト値。
	 * @return mixed
	 */
	public function get_setting( string $key, $default = null ) {
		$settings = $this->get_settings();

		return $settings[ $key ] ?? $default;
	}

	/**
	 * X API との接続が利用可能か。
	 */
	public function is_connected(): bool {
		$settings = $this->get_settings();
		$method   = $settings['auth_method'] ?? 'oauth2';

		if ( 'oauth1' === $method ) {
			return '' !== trim( (string) $settings['api_key'] )
				&& '' !== trim( (string) $settings['api_secret'] )
				&& '' !== trim( (string) $settings['access_token'] )
				&& '' !== trim( (string) $settings['access_token_secret'] );
		}

		return '' !== trim( (string) $settings['oauth2_access_token'] )
			&& '' !== trim( (string) $settings['client_id'] );
	}

	/**
	 * 機密文字列を暗号化して保存用に変換する。
	 *
	 * @param string $value 平文。
	 */
	public function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return base64_encode( $value );
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = openssl_random_pseudo_bytes( 16 );

		if ( false === $iv ) {
			return base64_encode( $value );
		}

		$encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return base64_encode( $value );
		}

		return base64_encode( $iv . $encrypted );
	}

	/**
	 * 暗号化文字列を復号する。
	 *
	 * @param string $value 暗号文。
	 */
	public function decrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			$decoded = base64_decode( $value, true );

			return false !== $decoded ? $decoded : '';
		}

		$decoded = base64_decode( $value, true );

		if ( false === $decoded || strlen( $decoded ) < 17 ) {
			$fallback = base64_decode( $value, true );

			return false !== $fallback ? $fallback : '';
		}

		$iv        = substr( $decoded, 0, 16 );
		$encrypted = substr( $decoded, 16 );
		$key       = hash( 'sha256', wp_salt( 'auth' ), true );
		$plain     = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		return false !== $plain ? $plain : '';
	}

	/**
	 * ログエントリを追加する。
	 *
	 * @param string               $message メッセージ。
	 * @param string               $level   レベル (info|error|success)。
	 * @param array<string, mixed> $context 追加情報。
	 */
	public function add_log( string $message, string $level = 'info', array $context = array() ): void {
		$settings = $this->get_settings();
		$entries  = is_array( $settings['log_entries'] ?? null ) ? $settings['log_entries'] : array();

		array_unshift(
			$entries,
			array(
				'time'    => current_time( 'mysql' ),
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			)
		);

		$settings['log_entries'] = array_slice( $entries, 0, 50 );
		$this->update_settings( $settings );
	}
}
