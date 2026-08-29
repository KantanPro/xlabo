<?php
/**
 * 投稿公開時の自動 X シェア。
 *
 * @package xLabo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress 投稿を X へ自動投稿する。
 */
class XLabo_Auto_Poster {

	/**
	 * @var XLabo_Plugin
	 */
	private $plugin;

	/**
	 * 同一リクエスト内での重複実行防止。
	 *
	 * @var array<int, bool>
	 */
	private $processed = array();

	/**
	 * @param XLabo_Plugin $plugin プラグインインスタンス。
	 */
	public function __construct( XLabo_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'transition_post_status', array( $this, 'maybe_share_post' ), 20, 3 );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'wp_ajax_xlabo_manual_share', array( $this, 'handle_manual_share' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_post_editor_assets' ) );
	}

	/**
	 * 公開時に X へシェアする。
	 *
	 * @param string  $new_status 新ステータス。
	 * @param string  $old_status 旧ステータス。
	 * @param WP_Post $post       投稿オブジェクト。
	 */
	public function maybe_share_post( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		if ( isset( $this->processed[ $post->ID ] ) ) {
			return;
		}

		$settings = $this->plugin->get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! $this->plugin->is_connected() ) {
			return;
		}

		$post_types = is_array( $settings['post_types'] ?? null ) ? $settings['post_types'] : array( 'post' );

		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		$is_new_publish = 'publish' !== $old_status;
		$is_republish   = 'publish' === $old_status && ! empty( $settings['share_on_update'] );

		if ( ! $is_new_publish && ! $is_republish ) {
			return;
		}

		if ( $is_new_publish ) {
			$already_shared = get_post_meta( $post->ID, XLabo_Plugin::META_TWEET_ID, true );

			if ( $already_shared && empty( $settings['share_on_update'] ) ) {
				return;
			}
		}

		$this->processed[ $post->ID ] = true;

		$this->share_post( $post );
	}

	/**
	 * 投稿を X へシェアする。
	 *
	 * @param WP_Post|int $post 投稿。
	 * @return array{success: bool, tweet_id?: string, error?: string}
	 */
	public function share_post( $post ): array {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return array(
				'success' => false,
				'error'   => __( '投稿が見つかりません。', 'xlabo' ),
			);
		}

		// 更新が必要だったのに失敗したら、死んだトークンで送っても必ず失敗する。
		// 画像アップロードを含む無駄な往復を避け、原因が分かる形で止める。
		if ( ! $this->plugin->oauth->maybe_refresh_token() ) {
			$error = __( 'アクセストークンの更新に失敗したため送信を中止しました。設定画面から X に接続し直してください。', 'xlabo' );

			$this->plugin->add_log(
				sprintf(
					/* translators: %s: post title */
					__( '「%s」の X シェアを中止: アクセストークンを更新できません。', 'xlabo' ),
					get_the_title( $post )
				),
				'error',
				array( 'post_id' => $post->ID )
			);

			return array(
				'success' => false,
				'error'   => $error,
			);
		}

		$text    = $this->build_tweet_text( $post );
		$options = $this->build_share_options( $post );
		$result  = $this->plugin->api->post_tweet( $text, $options );

		if ( $result['success'] && ! empty( $result['tweet_id'] ) ) {
			update_post_meta( $post->ID, XLabo_Plugin::META_TWEET_ID, sanitize_text_field( $result['tweet_id'] ) );
			update_post_meta( $post->ID, XLabo_Plugin::META_SHARED_AT, current_time( 'mysql' ) );

			if ( ! empty( $result['media_id'] ) ) {
				update_post_meta( $post->ID, XLabo_Plugin::META_MEDIA_ID, sanitize_text_field( $result['media_id'] ) );
			}

			$log_message = sprintf(
				/* translators: 1: post title, 2: tweet id */
				__( '「%1$s」を X にシェアしました（ID: %2$s）', 'xlabo' ),
				get_the_title( $post ),
				$result['tweet_id']
			);

			if ( ! empty( $result['media_id'] ) ) {
				$log_message .= sprintf(
					/* translators: %s: media id */
					__( ' / 画像付き（Media ID: %s）', 'xlabo' ),
					$result['media_id']
				);
			}

			$this->plugin->add_log(
				$log_message,
				'success',
				array(
					'post_id'  => $post->ID,
					'tweet_id' => $result['tweet_id'],
					'media_id' => $result['media_id'] ?? '',
				)
			);
		} else {
			$this->plugin->add_log(
				sprintf(
					/* translators: 1: post title, 2: error message */
					__( '「%1$s」の X シェアに失敗: %2$s', 'xlabo' ),
					get_the_title( $post ),
					$result['error'] ?? __( '不明なエラー', 'xlabo' )
				),
				'error',
				array(
					'post_id' => $post->ID,
				)
			);
		}

		return $result;
	}

	/**
	 * ツイート本文を組み立てる。
	 *
	 * @param WP_Post $post 投稿。
	 */
	public function build_tweet_text( WP_Post $post ): string {
		$settings = $this->plugin->get_settings();
		$template = (string) ( $settings['tweet_template'] ?? "{title}\n{url}" );

		$title   = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$url     = get_permalink( $post );
		$excerpt = $this->get_post_excerpt( $post );

		$replacements = array(
			'{title}'   => $title,
			'{url}'     => $url ? $url : '',
			'{excerpt}' => $excerpt,
		);

		$text = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );

		if ( ! empty( $settings['include_hashtags'] ) ) {
			$hashtags = $this->get_post_hashtags( $post );

			if ( '' !== $hashtags ) {
				$text = trim( $text ) . "\n" . $hashtags;
			}
		}

		$append = trim( (string) ( $settings['append_hashtags'] ?? '' ) );

		if ( '' !== $append ) {
			$text = trim( $text ) . "\n" . $append;
		}

		return trim( $text );
	}

	/**
	 * X シェア用オプション（アイキャッチ画像など）を組み立てる。
	 *
	 * @param WP_Post $post 投稿。
	 * @return array<string, mixed>
	 */
	public function build_share_options( WP_Post $post ): array {
		$settings = $this->plugin->get_settings();
		$options  = array();

		if ( empty( $settings['include_featured_image'] ) ) {
			return $options;
		}

		$image = $this->get_featured_image_for_upload( $post->ID );

		if ( null === $image ) {
			return $options;
		}

		$options['image_path'] = $image['path'];
		$options['image_mime'] = $image['mime'];

		return $options;
	}

	/**
	 * アップロード用アイキャッチ画像を取得する。
	 *
	 * @param int $post_id 投稿 ID。
	 * @return array{path: string, mime: string}|null
	 */
	public function get_featured_image_for_upload( int $post_id ): ?array {
		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( ! $thumbnail_id ) {
			return null;
		}

		$file_path = $this->resolve_attachment_file_path( $thumbnail_id );

		if ( null === $file_path ) {
			return null;
		}

		$mime = get_post_mime_type( $thumbnail_id );

		if ( ! is_string( $mime ) || ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			return null;
		}

		return array(
			'path' => $file_path,
			'mime' => $mime,
		);
	}

	/**
	 * 5MB 以下の添付ファイルパスを解決する。
	 *
	 * @param int $attachment_id 添付 ID。
	 */
	private function resolve_attachment_file_path( int $attachment_id ): ?string {
		$max_bytes = 5 * 1024 * 1024;
		$sizes     = array( 'large', 'medium_large', 'medium', 'full' );

		foreach ( $sizes as $size ) {
			$file_path = $this->get_attachment_size_path( $attachment_id, $size );

			if ( null === $file_path ) {
				continue;
			}

			if ( filesize( $file_path ) <= $max_bytes ) {
				return $file_path;
			}
		}

		return null;
	}

	/**
	 * 指定サイズの添付ファイルパスを取得する。
	 *
	 * @param int    $attachment_id 添付 ID。
	 * @param string $size          画像サイズ。
	 */
	private function get_attachment_size_path( int $attachment_id, string $size ): ?string {
		if ( 'full' === $size ) {
			$file_path = get_attached_file( $attachment_id );

			return ( is_string( $file_path ) && file_exists( $file_path ) ) ? $file_path : null;
		}

		$image = image_get_intermediate_size( $attachment_id, $size );

		if ( ! is_array( $image ) || empty( $image['path'] ) ) {
			return null;
		}

		$upload_dir = wp_get_upload_dir();
		$file_path  = trailingslashit( $upload_dir['basedir'] ) . $image['path'];

		return file_exists( $file_path ) ? $file_path : null;
	}

	/**
	 * 投稿の抜粋を取得する。
	 *
	 * @param WP_Post $post 投稿。
	 */
	private function get_post_excerpt( WP_Post $post ): string {
		if ( has_excerpt( $post ) ) {
			$excerpt = get_the_excerpt( $post );
		} else {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20, '...' );
		}

		return html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * 投稿タグからハッシュタグ文字列を生成する。
	 *
	 * @param WP_Post $post 投稿。
	 */
	private function get_post_hashtags( WP_Post $post ): string {
		$tags = get_the_tags( $post );

		if ( ! is_array( $tags ) || empty( $tags ) ) {
			return '';
		}

		$hashtags = array();

		foreach ( $tags as $tag ) {
			if ( ! isset( $tag->name ) ) {
				continue;
			}

			$name = preg_replace( '/\s+/u', '', $tag->name );

			if ( '' !== $name ) {
				$hashtags[] = '#' . $name;
			}
		}

		return implode( ' ', $hashtags );
	}

	/**
	 * 投稿編集画面にメタボックスを追加する。
	 */
	public function register_meta_box(): void {
		$settings   = $this->plugin->get_settings();
		$post_types = is_array( $settings['post_types'] ?? null ) ? $settings['post_types'] : array( 'post' );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'xlabo-share-status',
				__( 'X シェア', 'xlabo' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * メタボックスを描画する。
	 *
	 * @param WP_Post $post 投稿。
	 */
	public function render_meta_box( WP_Post $post ): void {
		$tweet_id  = get_post_meta( $post->ID, XLabo_Plugin::META_TWEET_ID, true );
		$shared_at = get_post_meta( $post->ID, XLabo_Plugin::META_SHARED_AT, true );
		$preview   = esc_html( $this->build_tweet_text( $post ) );
		$image_url = get_the_post_thumbnail_url( $post, 'medium' );

		echo '<div class="xlabo-metabox">';

		if ( $tweet_id ) {
			printf(
				'<p><strong>%s</strong><br><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_html__( 'シェア済み', 'xlabo' ),
				esc_url( 'https://x.com/i/web/status/' . rawurlencode( (string) $tweet_id ) ),
				esc_html( (string) $tweet_id )
			);

			if ( $shared_at ) {
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: datetime */
							__( 'シェア日時: %s', 'xlabo' ),
							$shared_at
						)
					)
				);
			}
		} else {
			echo '<p>' . esc_html__( 'まだ X にシェアされていません。', 'xlabo' ) . '</p>';
		}

		echo '<p><strong>' . esc_html__( 'プレビュー', 'xlabo' ) . '</strong></p>';
		echo '<p class="xlabo-preview">' . nl2br( $preview ) . '</p>';

		if ( $image_url ) {
			echo '<p><strong>' . esc_html__( 'アイキャッチ（Twitter Card 大）', 'xlabo' ) . '</strong></p>';
			echo '<p><img src="' . esc_url( $image_url ) . '" alt="" style="max-width:100%;height:auto;border:1px solid #dcdcde;" /></p>';
		} elseif ( ! empty( $this->plugin->get_settings()['include_featured_image'] ) ) {
			echo '<p class="description">' . esc_html__( 'アイキャッチが未設定のため、画像なしでシェアされます。', 'xlabo' ) . '</p>';
		}

		if ( $this->plugin->is_connected() && 'publish' === $post->post_status ) {
			wp_nonce_field( 'xlabo_manual_share', 'xlabo_manual_share_nonce' );
			echo '<p><button type="button" class="button button-secondary xlabo-manual-share" data-post-id="' . esc_attr( (string) $post->ID ) . '">';
			echo esc_html__( '今すぐ X にシェア', 'xlabo' );
			echo '</button></p>';
			echo '<p class="xlabo-manual-share-result description"></p>';
		}

		echo '</div>';
	}

	/**
	 * 手動シェア AJAX。
	 */
	public function handle_manual_share(): void {
		check_ajax_referer( 'xlabo_manual_share', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error(
				array( 'message' => __( '権限がありません。', 'xlabo' ) ),
				403
			);
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( '投稿が見つかりません。', 'xlabo' ) ),
				404
			);
		}

		$result = $this->share_post( $post_id );

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message'  => __( 'X にシェアしました。', 'xlabo' ),
					'tweet_id' => $result['tweet_id'] ?? '',
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => $result['error'] ?? __( 'シェアに失敗しました。', 'xlabo' ),
			),
			500
		);
	}

	/**
	 * 投稿編集画面用スクリプト。
	 *
	 * @param string $hook 現在の admin フック。
	 */
	public function enqueue_post_editor_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'xlabo-post-editor',
			XLABO_PLUGIN_URL . 'assets/js/post-editor.js',
			array( 'jquery' ),
			XLABO_VERSION,
			true
		);

		wp_localize_script(
			'xlabo-post-editor',
			'xlaboPostEditor',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'xlabo_manual_share' ),
				'i18n'    => array(
					'sharing' => __( 'シェア中...', 'xlabo' ),
					'share'   => __( '今すぐ X にシェア', 'xlabo' ),
				),
			)
		);
	}
}
