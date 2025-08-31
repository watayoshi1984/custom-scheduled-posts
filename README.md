# カスタム予約投稿プラグイン

[English Version](#english-version)

## 概要

このWordPressプラグインは、下書きの予約投稿、過去の投稿記事の再投稿、および記事タイトルとパーマリンクの重複チェック機能を提供します。ユーザーは時間間隔と件数を設定することで、効率的にコンテンツを管理できます。

## 主な機能

### 1. 予約投稿機能
- 下書きを指定された時間間隔で自動的に公開
- 各間隔ごとの投稿件数をカスタマイズ可能
- 特定の記事を予約投稿から除外可能

### 2. 再投稿機能
- 指定された日数より古い投稿を自動的に再投稿
- カテゴリーごとに異なる再投稿期間を設定可能

### 3. 重複チェック機能
- 記事タイトルとパーマリンクの重複を自動検出
- 重複した記事を自動的に削除

### 4. 内部リンク可視化機能
- ショートコード `[custom_posts_link]` で記事の内部リンク構造をグラフィカルに表示
- カテゴリー、タグ、投稿タイプごとの表示/非表示切り替え
- カテゴリーごとの色分け表示
- アンカーテキストの表示/非表示切り替え

## インストール方法

1. このリポジトリをダウンロードまたはクローンします
2. `custom-scheduled-posts` フォルダをWordPressの `wp-content/plugins/` ディレクトリにアップロードします
3. WordPress管理画面からプラグインを有効化します

## 使い方

### 基本設定
1. 管理画面の「カスタム予約投稿」→「基本設定」から設定ページにアクセスします
2. 以下の項目を設定します:
   - 下書きの予約投稿間隔（時間）
   - 各間隔ごとの下書き投稿件数
   - 再投稿する日数
   - 予約投稿を除外する記事ID（カンマ区切り）

### カテゴリー別設定
1. 管理画面の「カスタム予約投稿」→「カテゴリー別設定」から設定ページにアクセスします
2. 各カテゴリーごとに再投稿期間（日数）を設定します

### 内部リンク可視化
1. 投稿または固定ページの編集中に、以下のショートコードを挿入します:
   ```
   [custom_posts_link]
   ```
2. グラフ表示ではなくリスト表示にする場合は、以下のように指定します:
   ```
   [custom_posts_link display="list"]
   ```

## 要件

- WordPress 5.0以上
- PHP 7.4以上

## ライセンス

GPLv3またはそれ以降のバージョン

---

# English Version

## Overview

This WordPress plugin provides scheduled post publishing, reposting of old articles, and duplicate checking functionality for post titles and permalinks. Users can efficiently manage content by setting time intervals and quantities.

## Key Features

### 1. Scheduled Posting
- Automatically publish drafts at specified time intervals
- Customize the number of posts per interval
- Exclude specific articles from scheduled posting

### 2. Reposting
- Automatically repost articles older than a specified number of days
- Set different reposting periods for each category

### 3. Duplicate Checking
- Automatically detect duplicates in article titles and permalinks
- Automatically delete duplicate articles

### 4. Internal Link Visualization
- Graphically display article internal link structure with the shortcode `[custom_posts_link]`
- Toggle display/hide by category, tag, and post type
- Color-coded display by category
- Toggle display/hide of anchor text

## Installation

1. Download or clone this repository
2. Upload the `custom-scheduled-posts` folder to your WordPress `wp-content/plugins/` directory
3. Activate the plugin from the WordPress admin panel

## Usage

### Basic Settings
1. Access the settings page from "Custom Scheduled Posts" → "Basic Settings" in the admin panel
2. Configure the following items:
   - Draft scheduled posting interval (hours)
   - Number of draft posts per interval
   - Reposting days
   - Article IDs to exclude from scheduled posting (comma separated)

### Category Settings
1. Access the settings page from "Custom Scheduled Posts" → "Category Settings" in the admin panel
2. Set the reposting period (days) for each category

### Internal Link Visualization
1. Insert the following shortcode while editing a post or page:
   ```
   [custom_posts_link]
   ```
2. To display as a list instead of a graph, specify as follows:
   ```
   [custom_posts_link display="list"]
   ```

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher

## License

GPLv3 or later