<?php
/**
 * Twitter Card（summary_large_image）メタタグ出力。
 *
 * @package xLabo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 記事ページに Twitter Card 大画像用メタタグを出力する。
 */
class XLabo_Twitter_Cards {

	/**
	 * @var XLabo_Plugin
	 */
	private $plugin;

	/**
	 * @param XLabo_Plugin $plugin プラグインインスタンス。
	 */
	public function __construct( XLabo_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'wp_head', array( $this, 'output_meta_tags' ), 5 );
	}

	/**
	 * Twitter Card / OGP メタタグを出力する。
	 */
	public function output_meta_tags(): void {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$settings = $this->plugin->get_settings();

		if ( empty( $settings['enable_twitter_card_meta'] ) ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$post_types = is_array( $settings['post_types'] ?? null ) ? $settings['post_types'] : array( 'post' );

		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		/**
		 * Twitter Card メタタグ出力の可否。
		 *
		 * @param bool    $enabled 出力するか。
		 * @param WP_Post $post    投稿。
		 */
		if ( ! apply_filters( 'xlabo_output_twitter_card_meta', true, $post ) ) {
			return;
		}

		$image_url = $this->get_card_image_url( $post );

		if ( '' === $image_url ) {
			return;
		}

		$title       = wp_strip_all_tags( get_the_title( $post ) );
		$description = $this->get_card_description( $post );
		$url         = get_permalink( $post );
		$site_name   = get_bloginfo( 'name' );

		echo "\n<!-- xLabo Twitter Card -->\n";

		printf( '<meta name="twitter:card" content="%s" />' . "\n", esc_attr( 'summary_large_image' ) );
		printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );

		if ( '' !== $description ) {
			printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $image_url ) );

		if ( $url ) {
			printf( '<meta name="twitter:url" content="%s" />' . "\n", esc_url( $url ) );
		}

		if ( '' !== $site_name ) {
			printf( '<meta name="twitter:site" content="%s" />' . "\n", esc_attr( $this->get_twitter_site_handle() ) );
		}

		printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( 'article' ) );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );

		if ( '' !== $description ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		if ( $url ) {
			printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		}

		printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image_url ) );

		if ( '' !== $site_name ) {
			printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( $site_name ) );
		}

		echo "<!-- /xLabo Twitter Card -->\n";
	}

	/**
	 * Twitter Card 用画像 URL を取得する。
	 *
	 * summary_large_image 推奨: 最小 300x157、理想は 1200x630 前後。
	 *
	 * @param WP_Post $post 投稿。
	 */
	public function get_card_image_url( WP_Post $post ): string {
		$thumbnail_id = get_post_thumbnail_id( $post->ID );

		if ( ! $thumbnail_id ) {
			return '';
		}

		$size = apply_filters( 'xlabo_twitter_card_image_size', 'large', $post );
		$url  = get_the_post_thumbnail_url( $post, $size );

		if ( ! $url ) {
			$url = get_the_post_thumbnail_url( $post, 'full' );
		}

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Twitter Card 用説明文を取得する。
	 *
	 * @param WP_Post $post 投稿。
	 */
	private function get_card_description( WP_Post $post ): string {
		if ( has_excerpt( $post ) ) {
			$description = get_the_excerpt( $post );
		} else {
			$description = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '...' );
		}

		$description = html_entity_decode( $description, ENT_QUOTES, 'UTF-8' );

		return trim( $description );
	}

	/**
	 * @twitter:site 用ハンドルを取得する。
	 */
	private function get_twitter_site_handle(): string {
		$settings = $this->plugin->get_settings();
		$username = trim( (string) ( $settings['connected_username'] ?? '' ) );

		if ( '' === $username ) {
			return '';
		}

		return '@' . ltrim( $username, '@' );
	}
}
