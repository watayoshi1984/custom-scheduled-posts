<?php
/**
 * カスタム予約投稿プラグイン - 投稿処理クラス
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CSP_Post_Handler')) :

/**
 * 投稿処理クラス
 */
class CSP_Post_Handler {

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
     * 大量の下書きを処理するためのバッチ処理対応版関数
     */
    public function publish_drafts() {
        try {
            $options = get_option('csp_options');
            $drafts_per_interval = !empty($options['csp_drafts_per_interval']) ? intval($options['csp_drafts_per_interval']) : 1;
            $exclude_post_ids = !empty($options['csp_exclude_post_ids']) ? explode(',', $options['csp_exclude_post_ids']) : array();
            
            // 除外IDのサニタイズ
            $exclude_post_ids = array_map('intval', $exclude_post_ids);
            
            // バッチサイズを設定
            $batch_size = min($drafts_per_interval, 50);
            $published_count = 0;

            // バッチ処理で下書きを公開
            while ($published_count < $drafts_per_interval) {
                $remaining_count = $drafts_per_interval - $published_count;
                $current_batch_size = min($batch_size, $remaining_count);
                
                $drafts = get_posts(array(
                    'post_status' => 'draft',
                    'post_type' => 'post',
                    'posts_per_page' => $current_batch_size,
                    'exclude' => $exclude_post_ids,
                ));
                
                // これ以上下書きがない場合は終了
                if (empty($drafts)) {
                    break;
                }
                
                foreach ($drafts as $draft) {
                    wp_publish_post($draft->ID);
                    $published_count++;
                }
                
                // メモリ解放
                wp_cache_flush();
            }

            update_option('csp_last_published_count', $published_count);
            $total_published_count = get_option('csp_total_published_count', 0) + $published_count;
            update_option('csp_total_published_count', $total_published_count);
            
            // ログ出力
            if (function_exists('csp_log')) {
                csp_log(sprintf('下書きを%d件予約投稿しました。', $published_count));
            }
        } catch (Exception $e) {
            if (function_exists('csp_log')) {
                csp_log('下書き予約投稿エラー: ' . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * 大量の古い投稿を処理するためのバッチ処理対応版関数
     */
    public function repost_old_posts() {
        try {
            $options = get_option('csp_options');
            $repost_days = !empty($options['csp_repost_days']) ? intval($options['csp_repost_days']) : 7;
            
            // バッチサイズを設定
            $batch_size = 50;
            $reposted_count = 0;
            
            // バッチ処理で古い投稿を再投稿
            do {
                $old_posts = get_posts(array(
                    'post_status' => 'publish',
                    'post_type' => 'post',
                    'date_query' => array(
                        array(
                            'column' => 'post_date_gmt',
                            'before' => $repost_days . ' days ago',
                        ),
                    ),
                    'posts_per_page' => $batch_size,
                ));
                
                // これ以上古い投稿がない場合は終了
                if (empty($old_posts)) {
                    break;
                }
                
                foreach ($old_posts as $post) {
                    $post_data = array(
                        'ID' => $post->ID,
                        'post_date' => current_time('mysql'),
                        'post_date_gmt' => current_time('mysql', 1),
                    );
                    wp_update_post($post_data);
                    $reposted_count++;
                }
                
                // メモリ解放
                wp_cache_flush();
            } while (count($old_posts) === $batch_size); // バッチサイズ分の投稿が取得できた場合のみ継続
            
            // ログ出力
            if (function_exists('csp_log')) {
                csp_log(sprintf('%d件の古い投稿を再投稿しました。', $reposted_count));
            }
        } catch (Exception $e) {
            if (function_exists('csp_log')) {
                csp_log('古い投稿の再投稿エラー: ' . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * カテゴリーごとの再投稿処理
     */
    public function repost_old_posts_by_category() {
        try {
            // カテゴリー一覧を取得
            $categories = get_categories(array('hide_empty' => false));
            
            foreach ($categories as $category) {
                $repost_days = CSP_Settings::get_instance()->get_category_repost_days($category->term_id);
                
                // カテゴリーに属する古い投稿を取得
                $old_posts = get_posts(array(
                    'post_status' => 'publish',
                    'post_type' => 'post',
                    'category' => $category->term_id,
                    'date_query' => array(
                        array(
                            'column' => 'post_date_gmt',
                            'before' => $repost_days . ' days ago',
                        ),
                    ),
                    'posts_per_page' => -1,
                ));
                
                // 古い投稿を再投稿
                foreach ($old_posts as $post) {
                    $post_data = array(
                        'ID' => $post->ID,
                        'post_date' => current_time('mysql'),
                        'post_date_gmt' => current_time('mysql', 1),
                    );
                    wp_update_post($post_data);
                }
                
                // ログ出力
                if (function_exists('csp_log')) {
                    csp_log(sprintf('カテゴリー「%s」の%d件の古い投稿を再投稿しました。', $category->name, count($old_posts)));
                }
            }
        } catch (Exception $e) {
            if (function_exists('csp_log')) {
                csp_log('カテゴリー別再投稿エラー: ' . $e->getMessage(), 'ERROR');
            }
        }
    }
}

endif;