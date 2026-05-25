<?php
/**
 * X API クライアント。
 *
 * @package xLabo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * X API v2 へのリクエストを担当する。
 */
class XLabo_Api_Client {

	/**
	 * @var XLabo_Plugin
	 */
	private $plugin;

	/**
	 * @param XLabo_Plugin $plugin プラグインインスタンス。
	 */
	public function __construct( XLabo_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * ツイートを投稿する。
	 *
	 * @param string               $text    投稿本文。
	 * @param array<string, mixed> $options image_path, image_mime 等。
	 * @return array{success: bool, tweet_id?: string, media_id?: string, error?: string}
	 */
	public function post_tweet( string $text, array $options = array() ): array {
		$text = $this->truncate_text( $text );

		if ( '' === trim( $text ) ) {
			return array(
				'success' => false,
				'error'   => __( '投稿本文が空です。', 'xlabo' ),
			);
		}

		$media_ids = array();

		if ( ! empty( $options['image_path'] ) && is_readable( (string) $options['image_path'] ) ) {
			$upload = $this->upload_media(
				(string) $options['image_path'],
				(string) ( $options['image_mime'] ?? 'image/jpeg' )
			);

			if ( $upload['success'] && ! empty( $upload['media_id'] ) ) {
				$media_ids[] = $upload['media_id'];
			} elseif ( ! empty( $options['require_image'] ) ) {
				return array(
					'success' => false,
					'error'   => $upload['error'] ?? __( '画像のアップロードに失敗しました。', 'xlabo' ),
				);
			}
		}

		$settings = $this->plugin->get_settings();
		$method   = $settings['auth_method'] ?? 'oauth2';

		if ( 'oauth1' === $method ) {
			return $this->post_tweet_oauth1( $text, $settings, $media_ids );
		}

		return $this->post_tweet_oauth2( $text, $settings, $media_ids, $options );
	}

	/**
	 * 画像を X にアップロードする。
	 *
	 * @param string $file_path ローカルファイルパス。
	 * @param string $mime_type MIME タイプ。
	 * @return array{success: bool, media_id?: string, error?: string}
	 */
	public function upload_media( string $file_path, string $mime_type ): array {
		if ( ! is_readable( $file_path ) ) {
			return array(
				'success' => false,
				'error'   => __( '画像ファイルを読み込めません。', 'xlabo' ),
			);
		}

		$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

		if ( ! in_array( $mime_type, $allowed_mimes, true ) ) {
			return array(
				'success' => false,
				'error'   => __( 'X がサポートしていない画像形式です。', 'xlabo' ),
			);
		}

		$max_bytes = 5 * 1024 * 1024;

		if ( filesize( $file_path ) > $max_bytes ) {
			return array(
				'success' => false,
				'error'   => __( '画像サイズが 5MB を超えています。', 'xlabo' ),
			);
		}

		$settings = $this->plugin->get_settings();
		$method   = $settings['auth_method'] ?? 'oauth2';

		if ( 'oauth1' === $method ) {
			return $this->upload_media_oauth1( $file_path, $settings );
		}

		return $this->upload_media_oauth2( $file_path, $mime_type, $settings );
	}

	/**
	 * OAuth 2.0 でツイート投稿。
	 *
	 * @param string               $text      本文。
	 * @param array<string, mixed> $settings  設定。
	 * @param array<int, string>   $media_ids メディア ID 一覧。
	 * @param array<string, mixed> $options   再試行用オプション。
	 * @return array{success: bool, tweet_id?: string, media_id?: string, error?: string}
	 */
	private function post_tweet_oauth2( string $text, array $settings, array $media_ids = array(), array $options = array() ): array {
		$access_token = $this->plugin->decrypt( (string) ( $settings['oauth2_access_token'] ?? '' ) );

		if ( '' === $access_token ) {
			return array(
				'success' => false,
				'error'   => __( 'X アカウントが接続されていません。', 'xlabo' ),
			);
		}

		$payload = array( 'text' => $text );

		if ( ! empty( $media_ids ) ) {
			$payload['media'] = array(
				'media_ids' => array_values( $media_ids ),
			);
		}

		$response = wp_remote_post(
			'https://api.x.com/2/tweets',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code ) {
			$refreshed = $this->plugin->oauth->refresh_access_token();

			if ( $refreshed ) {
				return $this->post_tweet( $text, $options );
			}
		}

		if ( $code >= 200 && $code < 300 && is_array( $body ) && ! empty( $body['data']['id'] ) ) {
			$result = array(
				'success'  => true,
				'tweet_id' => (string) $body['data']['id'],
			);

			if ( ! empty( $media_ids[0] ) ) {
				$result['media_id'] = (string) $media_ids[0];
			}

			return $result;
		}

		$error_message = $this->extract_error_message( $body );

		return array(
			'success' => false,
			'error'   => $error_message,
		);
	}

	/**
	 * OAuth 2.0 で画像をアップロードする。
	 *
	 * @param string               $file_path ファイルパス。
	 * @param string               $mime_type MIME タイプ。
	 * @param array<string, mixed> $settings  設定。
	 * @return array{success: bool, media_id?: string, error?: string}
	 */
	private function upload_media_oauth2( string $file_path, string $mime_type, array $settings ): array {
		$access_token = $this->plugin->decrypt( (string) ( $settings['oauth2_access_token'] ?? '' ) );

		if ( '' === $access_token ) {
			return array(
				'success' => false,
				'error'   => __( 'X アカウントが接続されていません。', 'xlabo' ),
			);
		}

		$boundary = 'xlabo' . wp_generate_password( 16, false, false );
		$body     = $this->build_multipart_body(
			array(
				'media_category' => 'tweet_image',
				'media_type'     => $mime_type,
			),
			'media',
			$file_path,
			$mime_type,
			$boundary
		);

		$response = wp_remote_post(
			'https://api.x.com/2/media/upload',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code ) {
			$refreshed = $this->plugin->oauth->refresh_access_token();

			if ( $refreshed ) {
				return $this->upload_media( $file_path, $mime_type );
			}
		}

		$media_id = $this->extract_media_id( $response_body );

		if ( $code >= 200 && $code < 300 && '' !== $media_id ) {
			return array(
				'success'  => true,
				'media_id' => $media_id,
			);
		}

		return array(
			'success' => false,
			'error'   => $this->extract_error_message( $response_body ),
		);
	}

	/**
	 * OAuth 1.0a で画像をアップロードする。
	 *
	 * @param string               $file_path ファイルパス。
	 * @param array<string, mixed> $settings  設定。
	 * @return array{success: bool, media_id?: string, error?: string}
	 */
	private function upload_media_oauth1( string $file_path, array $settings ): array {
		$consumer_key    = trim( (string) ( $settings['api_key'] ?? '' ) );
		$consumer_secret = $this->plugin->decrypt( (string) ( $settings['api_secret'] ?? '' ) );
		$token           = trim( (string) ( $settings['access_token'] ?? '' ) );
		$token_secret    = $this->plugin->decrypt( (string) ( $settings['access_token_secret'] ?? '' ) );

		if ( '' === $consumer_key || '' === $consumer_secret || '' === $token || '' === $token_secret ) {
			return array(
				'success' => false,
				'error'   => __( 'OAuth 1.0a の認証情報が不足しています。', 'xlabo' ),
			);
		}

		$file_contents = file_get_contents( $file_path );

		if ( false === $file_contents ) {
			return array(
				'success' => false,
				'error'   => __( '画像ファイルを読み込めません。', 'xlabo' ),
			);
		}

		$url    = 'https://upload.twitter.com/1.1/media/upload.json';
		$params = array(
			'media_data' => base64_encode( $file_contents ),
		);

		$oauth_header = $this->build_oauth1_header(
			'POST',
			$url,
			$params,
			$consumer_key,
			$consumer_secret,
			$token,
			$token_secret
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => $oauth_header,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => $params,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$media_id = $this->extract_media_id( $body );

		if ( $code >= 200 && $code < 300 && '' !== $media_id ) {
			return array(
				'success'  => true,
				'media_id' => $media_id,
			);
		}

		return array(
			'success' => false,
			'error'   => $this->extract_error_message( $body ),
		);
	}

	/**
	 * OAuth 1.0a でツイート投稿。
	 *
	 * @param string               $text      本文。
	 * @param array<string, mixed> $settings  設定。
	 * @param array<int, string>   $media_ids メディア ID 一覧。
	 * @return array{success: bool, tweet_id?: string, media_id?: string, error?: string}
	 */
	private function post_tweet_oauth1( string $text, array $settings, array $media_ids = array() ): array {
		$consumer_key    = trim( (string) ( $settings['api_key'] ?? '' ) );
		$consumer_secret = $this->plugin->decrypt( (string) ( $settings['api_secret'] ?? '' ) );
		$token           = trim( (string) ( $settings['access_token'] ?? '' ) );
		$token_secret    = $this->plugin->decrypt( (string) ( $settings['access_token_secret'] ?? '' ) );

		if ( '' === $consumer_key || '' === $consumer_secret || '' === $token || '' === $token_secret ) {
			return array(
				'success' => false,
				'error'   => __( 'OAuth 1.0a の認証情報が不足しています。', 'xlabo' ),
			);
		}

		$url     = 'https://api.x.com/2/tweets';
		$method  = 'POST';
		$payload = array( 'text' => $text );

		if ( ! empty( $media_ids ) ) {
			$payload['media'] = array(
				'media_ids' => array_values( $media_ids ),
			);
		}

		$body = wp_json_encode( $payload );

		$oauth_header = $this->build_oauth1_header(
			$method,
			$url,
			array(),
			$consumer_key,
			$consumer_secret,
			$token,
			$token_secret
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => $oauth_header,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && is_array( $data ) && ! empty( $data['data']['id'] ) ) {
			$result = array(
				'success'  => true,
				'tweet_id' => (string) $data['data']['id'],
			);

			if ( ! empty( $media_ids[0] ) ) {
				$result['media_id'] = (string) $media_ids[0];
			}

			return $result;
		}

		return array(
			'success' => false,
			'error'   => $this->extract_error_message( $data ),
		);
	}

	/**
	 * multipart/form-data ボディを組み立てる。
	 *
	 * @param array<string, string> $fields    テキストフィールド。
	 * @param string                $file_field ファイルフィールド名。
	 * @param string                $file_path  ファイルパス。
	 * @param string                $mime_type  MIME タイプ。
	 * @param string                $boundary   境界文字列。
	 */
	private function build_multipart_body(
		array $fields,
		string $file_field,
		string $file_path,
		string $mime_type,
		string $boundary
	): string {
		$body = '';

		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$body .= $value . "\r\n";
		}

		$file_contents = file_get_contents( $file_path );
		$filename      = wp_basename( $file_path );

		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="' . $file_field . '"; filename="' . $filename . '"' . "\r\n";
		$body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
		$body .= $file_contents . "\r\n";
		$body .= '--' . $boundary . '--' . "\r\n";

		return $body;
	}

	/**
	 * メディアアップロードレスポンスから media_id を抽出する。
	 *
	 * @param mixed $body レスポンス。
	 */
	private function extract_media_id( $body ): string {
		if ( ! is_array( $body ) ) {
			return '';
		}

		if ( ! empty( $body['data']['id'] ) ) {
			return (string) $body['data']['id'];
		}

		if ( ! empty( $body['media_id_string'] ) ) {
			return (string) $body['media_id_string'];
		}

		if ( ! empty( $body['media_id'] ) ) {
			return (string) $body['media_id'];
		}

		return '';
	}

	/**
	 * 接続テスト用に認証済みユーザー情報を取得する。
	 *
	 * @return array{success: bool, username?: string, error?: string}
	 */
	public function verify_credentials(): array {
		$settings = $this->plugin->get_settings();
		$method   = $settings['auth_method'] ?? 'oauth2';

		if ( 'oauth1' === $method ) {
			return $this->verify_credentials_oauth1( $settings );
		}

		$access_token = $this->plugin->decrypt( (string) ( $settings['oauth2_access_token'] ?? '' ) );

		if ( '' === $access_token ) {
			return array(
				'success' => false,
				'error'   => __( 'X アカウントが接続されていません。', 'xlabo' ),
			);
		}

		$response = wp_remote_get(
			'https://api.x.com/2/users/me',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code ) {
			$refreshed = $this->plugin->oauth->refresh_access_token();

			if ( $refreshed ) {
				return $this->verify_credentials();
			}
		}

		if ( $code >= 200 && $code < 300 && is_array( $body ) && ! empty( $body['data']['username'] ) ) {
			return array(
				'success'  => true,
				'username' => (string) $body['data']['username'],
			);
		}

		return array(
			'success' => false,
			'error'   => $this->extract_error_message( $body ),
		);
	}

	/**
	 * OAuth 1.0a でユーザー情報を取得する。
	 *
	 * @param array<string, mixed> $settings 設定。
	 * @return array{success: bool, username?: string, error?: string}
	 */
	private function verify_credentials_oauth1( array $settings ): array {
		$consumer_key    = trim( (string) ( $settings['api_key'] ?? '' ) );
		$consumer_secret = $this->plugin->decrypt( (string) ( $settings['api_secret'] ?? '' ) );
		$token           = trim( (string) ( $settings['access_token'] ?? '' ) );
		$token_secret    = $this->plugin->decrypt( (string) ( $settings['access_token_secret'] ?? '' ) );

		$url          = 'https://api.x.com/2/users/me';
		$method       = 'GET';
		$oauth_header = $this->build_oauth1_header(
			$method,
			$url,
			array(),
			$consumer_key,
			$consumer_secret,
			$token,
			$token_secret
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => $oauth_header,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && is_array( $body ) && ! empty( $body['data']['username'] ) ) {
			return array(
				'success'  => true,
				'username' => (string) $body['data']['username'],
			);
		}

		return array(
			'success' => false,
			'error'   => $this->extract_error_message( $body ),
		);
	}

	/**
	 * OAuth 1.0a Authorization ヘッダーを生成する。
	 *
	 * @param string               $method         HTTP メソッド。
	 * @param string               $url            リクエスト URL。
	 * @param array<string, mixed> $params         クエリパラメータ。
	 * @param string               $consumer_key   Consumer Key。
	 * @param string               $consumer_secret Consumer Secret。
	 * @param string               $token          Access Token。
	 * @param string               $token_secret   Access Token Secret。
	 */
	private function build_oauth1_header(
		string $method,
		string $url,
		array $params,
		string $consumer_key,
		string $consumer_secret,
		string $token,
		string $token_secret
	): string {
		$oauth_params = array(
			'oauth_consumer_key'     => $consumer_key,
			'oauth_nonce'            => wp_generate_password( 32, false, false ),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => (string) time(),
			'oauth_token'            => $token,
			'oauth_version'          => '1.0',
		);

		$signature_params = array_merge( $params, $oauth_params );
		ksort( $signature_params );

		$encoded_pairs = array();

		foreach ( $signature_params as $key => $value ) {
			$encoded_pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		$base_string = strtoupper( $method ) . '&'
			. rawurlencode( $url ) . '&'
			. rawurlencode( implode( '&', $encoded_pairs ) );

		$signing_key = rawurlencode( $consumer_secret ) . '&' . rawurlencode( $token_secret );
		$signature   = base64_encode( hash_hmac( 'sha1', $base_string, $signing_key, true ) );

		$oauth_params['oauth_signature'] = $signature;

		$header_parts = array();

		foreach ( $oauth_params as $key => $value ) {
			$header_parts[] = rawurlencode( (string) $key ) . '="' . rawurlencode( (string) $value ) . '"';
		}

		return 'OAuth ' . implode( ', ', $header_parts );
	}

	/**
	 * API エラーメッセージを抽出する。
	 *
	 * @param mixed $body レスポンスボディ。
	 */
	private function extract_error_message( $body ): string {
		if ( ! is_array( $body ) ) {
			return __( 'X API から不明なエラーが返されました。', 'xlabo' );
		}

		if ( ! empty( $body['detail'] ) && is_string( $body['detail'] ) ) {
			return $body['detail'];
		}

		if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
			$messages = array();

			foreach ( $body['errors'] as $error ) {
				if ( is_array( $error ) && ! empty( $error['message'] ) ) {
					$messages[] = (string) $error['message'];
				}
			}

			if ( ! empty( $messages ) ) {
				return implode( ' ', $messages );
			}
		}

		if ( ! empty( $body['title'] ) && is_string( $body['title'] ) ) {
			return $body['title'];
		}

		return __( 'X API リクエストに失敗しました。', 'xlabo' );
	}

	/**
	 * 280 文字以内に切り詰める（URL は t.co 換算を簡易考慮）。
	 *
	 * @param string $text 本文。
	 */
	private function truncate_text( string $text ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );

		if ( mb_strlen( $text ) <= 280 ) {
			return $text;
		}

		return mb_substr( $text, 0, 277 ) . '...';
	}
}
