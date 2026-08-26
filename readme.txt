=== xLabo ===
Contributors: kantanpro
Tags: twitter, x, social, share, auto-post
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress の投稿を公開時に X（旧 Twitter）へ自動シェアするプラグインです。

== Description ==

xLabo は、WordPress で記事を公開したタイミングで X（旧 Twitter）へ自動的に投稿をシェアするプラグインです。

= 主な機能 =

* 投稿公開時の自動 X シェア
* OAuth 2.0（PKCE）による X アカウント接続
* OAuth 1.0a（API Key / Access Token）にも対応
* ツイート本文テンプレート（{title}, {url}, {excerpt}）
* 投稿タグのハッシュタグ化
* 投稿編集画面からの手動シェア
* アイキャッチ画像の X 投稿添付（大画像表示）
* Twitter Card（summary_large_image）メタタグ出力
* シェアログ

= 必要なもの =

* X Developer Portal で作成したアプリ
* 投稿権限（tweet.write）を持つ API アクセス

== Installation ==

1. `xLabo` フォルダを `/wp-content/plugins/` にアップロード
2. 管理画面の「プラグイン」から xLabo を有効化
3. 「設定 > xLabo」から X API の認証情報を設定
4. OAuth 2.0 の場合は「X アカウントを接続」をクリック
5. 「自動シェア」を有効化

== Frequently Asked Questions ==

= X Developer Portal の Callback URL は？ =

設定画面に表示されている URL を X アプリの Callback URL に登録してください。

= 画像もシェアできますか？ =

はい。アイキャッチ画像を X API 経由でアップロードし、タイムライン上で大きく表示されます。また、記事ページには Twitter Card（summary_large_image）用メタタグも出力できます。

== Changelog ==

= 1.1.0 =
* アイキャッチ画像の X 投稿添付に対応
* Twitter Card（summary_large_image）メタタグ出力を追加

= 1.0.0 =
* 初回リリース
