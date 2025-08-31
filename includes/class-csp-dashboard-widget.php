<?php
/**
 * カスタム予約投稿プラグイン - ダッシュボードウィジェットクラス
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CSP_Dashboard_Widget')) :

/**
 * ダッシュボードウィジェットクラス
 */
class CSP_Dashboard_Widget {

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
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));
    }

    /**
     * ダッシュボードウィジェットの追加
     */
    public function add_dashboard_widgets() {
        wp_add_dashboard_widget(
            'csp_dashboard_widget', 
            'カスタム予約投稿の状態', 
            array($this, 'dashboard_widget_render')
        );
    }

    /**
     * ダッシュボードウィジェットの表示
     */
    public function dashboard_widget_render() {
        try {
            $options = get_option('csp_options');
            $interval = !empty($options['csp_interval']) ? intval($options['csp_interval']) : '未設定';
            $drafts_per_interval = !empty($options['csp_drafts_per_interval']) ? intval($options['csp_drafts_per_interval']) : '未設定';
            $repost_days = !empty($options['csp_repost_days']) ? intval($options['csp_repost_days']) : '未設定';
            $exclude_post_ids = !empty($options['csp_exclude_post_ids']) ? esc_html($options['csp_exclude_post_ids']) : 'なし';

            $next_publish_time = wp_next_scheduled('csp_hourly_event');
            $next_repost_check_time = wp_next_scheduled('csp_daily_event');
            $last_duplicate_check_time = get_option('csp_last_duplicate_check_time', '未実行');
            $last_deleted_count = CSP_Duplicate_Checker::get_instance()->get_last_deleted_count();
            $total_published_count = get_option('csp_total_published_count', 0);
            $last_published_count = get_option('csp_last_published_count', 0);

            $wp_timezone = wp_timezone();
            $next_publish_time_local = $next_publish_time ? wp_date('Y-m-d H:i:s', $next_publish_time, $wp_timezone) : '未設定';
            $next_repost_check_time_local = $next_repost_check_time ? wp_date('Y-m-d H:i:s', $next_repost_check_time, $wp_timezone) : '未設定';
            $last_duplicate_check_time_local = $last_duplicate_check_time !== '未実行' ? wp_date('Y-m-d H:i:s', $last_duplicate_check_time, $wp_timezone) : '未実行';

            echo '<h3>#設定状態</h3>';
            echo '<p><strong>・下書きの予約投稿間隔（時間）:</strong> ' . esc_html($interval) . '</p>';
            echo '<p><strong>・各間隔ごとの下書き投稿件数:</strong> ' . esc_html($drafts_per_interval) . '</p>';
            echo '<p><strong>・予約投稿を除外する記事ID:</strong> ' . esc_html($exclude_post_ids) . '</p>';
            echo '<p><strong>・再投稿をする日数:</strong> ' . esc_html($repost_days) . '</p>';

            echo '<h3>#進捗状態</h3>';
            echo '<p><strong>・次の予約投稿時間:</strong> ' . esc_html($next_publish_time_local) . '</p>';
            echo '<p><strong>・次の再投稿チェック時間:</strong> ' . esc_html($next_repost_check_time_local) . '</p>';
            echo '<p><strong>・最後の重複チェック実行日時:</strong> ' . esc_html($last_duplicate_check_time_local) . '</p>';

            echo '<h3>#結果状態</h3>';
            echo '<p><strong>・前回の予約投稿件数:</strong> ' . esc_html($last_published_count) . '</p>';
            echo '<p><strong>・累計の予約投稿件数:</strong> ' . esc_html($total_published_count) . '</p>';

            // 進捗状態を表示
            $drafts = get_posts(array(
                'post_status' => 'draft',
                'post_type' => 'post',
                'posts_per_page' => -1,
            ));
            $old_posts = get_posts(array(
                'post_status' => 'publish',
                'post_type' => 'post',
                'date_query' => array(
                    array(
                        'column' => 'post_date_gmt',
                        'before' => $repost_days . ' days ago',
                    ),
                ),
                'posts_per_page' => -1,
            ));

            echo '<p><strong>・下書きの数:</strong> ' . count($drafts) . '</p>';
            echo '<p><strong>・再投稿が必要な投稿の数:</strong> ' . count($old_posts) . '</p>';

            echo '<p><strong>・重複チェックで削除された記事数:</strong> ' . esc_html($last_deleted_count) . '</p>';

            echo '<a href="' . admin_url('options-general.php?page=custom_scheduled_posts') . '" class="button button-primary">設定画面へ</a>';
            echo ' <a href="' . wp_nonce_url(admin_url('admin-post.php?action=csp_manual_update'), 'csp_manual_update') . '" class="button">更新</a>';
        } catch (Exception $e) {
            if (function_exists('csp_log')) {
                csp_log('ダッシュボードウィジェット表示エラー: ' . $e->getMessage(), 'ERROR');
            }
            echo '<p>状態の取得中にエラーが発生しました。詳細はエラーログを確認してください。</p>';
        }
    }
}

endif;