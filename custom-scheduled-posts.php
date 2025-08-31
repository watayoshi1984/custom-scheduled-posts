<?php
/**
 * Plugin Name: カスタム予約投稿
 * Description: 下書きの予約投稿時間と件数、過去の投稿記事の再投稿日数を設定し、パーマリンクやタイトルが重複している記事を検出および削除するプラグイン。
 * Version: 1.4.1
 * Author: Watayoshi
 * Mail: dti.watayoshi@gmail.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * カスタム予約投稿プラグインのメインクラス
 */
class Custom_Scheduled_Posts {

    /**
     * プラグインのバージョン
     */
    const VERSION = '1.4.1';

    /**
     * プラグインのインスタンス
     */
    private static $instance = null;

    /**
     * プラグインのインスタンスを取得する
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
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * 定数の定義
     */
    private function define_constants() {
        define('CSP_PLUGIN_FILE', __FILE__);
        define('CSP_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('CSP_PLUGIN_URL', plugin_dir_url(__FILE__));
        define('CSP_VERSION', self::VERSION);
    }

    /**
     * 必要なファイルを読み込む
     */
    private function includes() {
        // 設定管理クラス
        require_once CSP_PLUGIN_DIR . 'includes/class-csp-settings.php';
        
        // スケジュール処理クラス
        require_once CSP_PLUGIN_DIR . 'includes/class-csp-scheduler.php';
        
        // 投稿処理クラス
        require_once CSP_PLUGIN_DIR . 'includes/class-csp-post-handler.php';
        
        // 重複チェッククラス
        require_once CSP_PLUGIN_DIR . 'includes/class-csp-duplicate-checker.php';
        
        // 内部リンク分析クラス
        require_once CSP_PLUGIN_DIR . 'includes/class-csp-link-analyzer.php';
        
        // ダッシュボードウィジェットクラス
        require_once CSP_PLUGIN_DIR . 'includes/class-csp-dashboard-widget.php';
        
        // ショートコードクラス
        require_once CSP_PLUGIN_DIR . 'includes/class-csp-shortcode.php';
    }

    /**
     * フックの初期化
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('plugins_loaded', array($this, 'init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * プラグイン有効化時の処理
     */
    public function activate() {
        // デフォルト設定の追加
        $this->add_default_settings();
        
        // スケジュールイベントの設定
        CSP_Scheduler::schedule_events();
    }

    /**
     * プラグイン無効化時の処理
     */
    public function deactivate() {
        // スケジュールイベントのクリア
        CSP_Scheduler::clear_scheduled_events();
    }

    /**
     * プラグイン初期化
     */
    public function init() {
        // 各クラスの初期化
        CSP_Settings::get_instance();
        CSP_Scheduler::get_instance();
        CSP_Post_Handler::get_instance();
        CSP_Duplicate_Checker::get_instance();
        CSP_Link_Analyzer::get_instance();
        CSP_Dashboard_Widget::get_instance();
        CSP_Shortcode::get_instance();
    }

    /**
     * 管理画面用スクリプトの読み込み
     */
    public function enqueue_admin_scripts($hook) {
        // ダッシュボードウィジェット用CSSの読み込み
        if ('index.php' === $hook) {
            wp_enqueue_style(
                'csp-dashboard-widget',
                CSP_PLUGIN_URL . 'assets/css/dashboard-widget.css',
                array(),
                CSP_VERSION
            );
        }
    }

    /**
     * デフォルト設定の追加
     */
    private function add_default_settings() {
        try {
            $default_options = array(
                'csp_interval' => 1,
                'csp_drafts_per_interval' => 1,
                'csp_repost_days' => 7,
                'csp_exclude_post_ids' => '',
            );

            if (!get_option('csp_options')) {
                update_option('csp_options', $default_options);
                // ログ出力は各クラスで行うため、ここではコメントアウト
                // csp_log('デフォルト設定が正常に追加されました。');
            }
        } catch (Exception $e) {
            // エラーログは各クラスで行うため、ここではコメントアウト
            // csp_log('デフォルト設定追加エラー: ' . $e->getMessage(), 'ERROR');
        }
    }
}

/**
 * プラグインのインスタンスを取得する関数
 */
function custom_scheduled_posts() {
    return Custom_Scheduled_Posts::get_instance();
}

// プラグインを実行
custom_scheduled_posts();
