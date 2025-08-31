<?php
/**
 * カスタム予約投稿プラグイン - スケジュール処理クラス
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CSP_Scheduler')) :

/**
 * スケジュール処理クラス
 */
class CSP_Scheduler {

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
        add_action('wp', array($this, 'schedule_duplicate_check'));
        add_action('csp_hourly_event', array($this, 'publish_drafts'));
        add_action('csp_daily_event', array($this, 'repost_old_posts'));
        add_action('csp_weekly_event', array($this, 'check_for_duplicates'));
        add_action('admin_post_csp_manual_update', array($this, 'manual_update'));
    }

    /**
     * スケジュールイベントの設定
     */
    public static function schedule_events() {
        try {
            if (!wp_next_scheduled('csp_hourly_event')) {
                wp_schedule_event(time(), 'hourly', 'csp_hourly_event');
            }
            if (!wp_next_scheduled('csp_daily_event')) {
                wp_schedule_event(time(), 'daily', 'csp_daily_event');
            }
            if (!wp_next_scheduled('csp_weekly_event')) {
                wp_schedule_event(time(), 'weekly', 'csp_weekly_event');
            }
            
            // ログ出力
            if (function_exists('csp_log')) {
                csp_log('スケジュールイベントが正常に設定されました。');
            }
        } catch (Exception $e) {
            if (function_exists('csp_log')) {
                csp_log('スケジュールイベント設定エラー: ' . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * スケジュールイベントのクリア
     */
    public static function clear_scheduled_events() {
        try {
            wp_clear_scheduled_hook('csp_hourly_event');
            wp_clear_scheduled_hook('csp_daily_event');
            wp_clear_scheduled_hook('csp_weekly_event');
            
            // ログ出力
            if (function_exists('csp_log')) {
                csp_log('スケジュールイベントが正常にクリアされました。');
            }
        } catch (Exception $e) {
            if (function_exists('csp_log')) {
                csp_log('スケジュールイベントクリアエラー: ' . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * 手動更新
     */
    public function manual_update() {
        try {
            // nonceの検証
            if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'csp_manual_update')) {
                throw new Exception('セキュリティエラー：不正なアクセスが検出されました。');
            }
            
            // 各処理を実行
            $this->publish_drafts();
            $this->repost_old_posts();
            $this->check_for_duplicates();
            
            // ログ出力
            if (function_exists('csp_log')) {
                csp_log('手動更新が正常に完了しました。');
            }
            
            wp_redirect(admin_url('index.php'));
            exit;
        } catch (Exception $e) {
            if (function_exists('csp_log')) {
                csp_log('手動更新エラー: ' . $e->getMessage(), 'ERROR');
            }
            wp_die(esc_html($e->getMessage()));
        }
    }

    /**
     * 下書きの予約投稿
     */
    public function publish_drafts() {
        // このメソッドはCSP_Post_Handlerクラスに移動するため、ここでは空にする
        // 互換性のために残しておく
    }

    /**
     * 過去の投稿の再投稿
     */
    public function repost_old_posts() {
        // このメソッドはCSP_Post_Handlerクラスに移動するため、ここでは空にする
        // 互換性のために残しておく
    }

    /**
     * 重複チェック
     */
    public function check_for_duplicates() {
        // このメソッドはCSP_Duplicate_Checkerクラスに移動するため、ここでは空にする
        // 互換性のために残しておく
    }

    /**
     * 重複チェックのスケジューリング
     */
    public function schedule_duplicate_check() {
        try {
            if (!wp_next_scheduled('csp_weekly_event')) {
                wp_schedule_event(time(), 'weekly', 'csp_weekly_event');
            }
            if (function_exists('csp_log')) {
                csp_log('重複チェックスケジュールが正常に設定されました。');
            }
        } catch (Exception $e) {
            if (function_exists('csp_log')) {
                csp_log('重複チェックスケジュール設定エラー: ' . $e->getMessage(), 'ERROR');
            }
        }
    }
}

endif;