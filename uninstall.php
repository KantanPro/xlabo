<?php
/**
 * xLabo アンインストール処理。
 *
 * @package xLabo
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'xlabo_settings' );
delete_transient( 'xlabo_oauth_state' );
delete_transient( 'xlabo_oauth_code_verifier' );

global $wpdb;

if ( isset( $wpdb ) ) {
		$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s)",
			'_xlabo_tweet_id',
			'_xlabo_shared_at',
			'_xlabo_media_id'
		)
	);
}
