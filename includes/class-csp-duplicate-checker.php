<?php
/**
 * カスタム予約投稿プラグイン - 重複チェッククラス
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CSP_Duplicate_Checker')) :

/**
 * 重複チェッククラス
 */
class CSP_Duplicate_Checker {

    /**
     * インスタンス
     */
    private static $instance = null;

    /**
     * インスタンスを取得する
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * フックの初期化
     */
    private function init_hooks() {
        // 必要なフックをここに追加
    }

    /**
     * 記事タイトルとパーマリンクの重複チェックと削除
     */
    public function check_for_duplicates() {
        try {
            global $wpdb;

            // 重複した記事タイトルを取得（パフォーマンス改善版）
            $duplicate_titles = $wpdb->get_results($wpdb->prepare("
                SELECT post_title, COUNT(*) as count
                FROM $wpdb->posts
                WHERE post_type = %s AND post_status = %s
                GROUP BY post_title
                HAVING COUNT(*) > 1
            ", 'post', 'publish'), 'ARRAY_A');

            // 重複した記事パーマリンクを取得（パフォーマンス改善版）
            $duplicate_slugs = $wpdb->get_results($wpdb->prepare("
                SELECT post_name, COUNT(*) as count
                FROM $wpdb->posts
                WHERE post_type = %s AND post_status = %s
                GROUP BY post_name
                HAVING COUNT(*) > 1
            ", 'post', 'publish'), 'ARRAY_A');

            // 重複した記事を削除（バッチ処理対応版）
            $deleted_count = 0;
            $batch_size = 50; // バッチサイズを設定
            
            if (!empty($duplicate_titles)) {
                foreach ($duplicate_titles as $duplicate) {
                    $title = $duplicate['post_title'];
                    $posts = get_posts(array(
                        'post_type' => 'post',
                        'post_status' => 'publish',
                        'title' => $title,
                        'posts_per_page' => -1,
                    ));
                    
                    // 最初の1件を残し、残りを削除
                    array_shift($posts);
                    
                    // バッチ処理で削除
                    foreach (array_chunk($posts, $batch_size) as $batch) {
                        foreach ($batch as $post) {
                            wp_delete_post($post->ID, true);
                            $deleted_count++;
                        }
                        // メモリ解放
                        wp_cache_flush();
                    }
                }
            }

            if (!empty($duplicate_slugs)) {
                foreach ($duplicate_slugs as $duplicate) {
                    $slug = $duplicate['post_name'];
                    $posts = get_posts(array(
                        'post_type' => 'post',
                        'post_status' => 'publish',
                        'name' => $slug,
                        'posts_per_page' => -1,
                    ));
                    
                    // 最初の1件を残し、残りを削除
                    array_shift($posts);
                    
                    // バッチ処理で削除
                    foreach (array_chunk($posts, $batch_size) as $batch) {
                        foreach ($batch as $post) {
                            wp_delete_post($post->ID, true);
                            $deleted_count++;
                        }
                        // メモリ解放
                        wp_cache_flush();
                    }
                }
            }

            // 削除した記事数を保存
            update_option('csp_last_deleted_count', $deleted_count);
            update_option('csp_last_duplicate_check_time', current_time('timestamp'));
            
            // ログ出力
            if (function_exists('csp_log')) {
                csp_log(sprintf('重複チェックを実行し、%d件の記事を削除しました。', $deleted_count));
            }

            return $deleted_count;
        } catch (Exception $e) {
            if (function_exists('csp_log')) {
                csp_log('重複チェックエラー: ' . $e->getMessage(), 'ERROR');
            }
            return 0;
        }
    }

    /**
     * 最後に削除された記事の数を取得
     */
    public function get_last_deleted_count() {
        return get_option('csp_last_deleted_count', 0);
    }
}

endif;