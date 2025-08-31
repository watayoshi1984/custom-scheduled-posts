<?php
/**
 * カスタム予約投稿プラグイン - 設定管理クラス
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CSP_Settings')) :

/**
 * 設定管理クラス
 */
class CSP_Settings {

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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('admin_init', array($this, 'category_settings_init'));
        add_action('admin_post_csp_save_settings', array($this, 'save_settings'));
        add_action('admin_post_csp_save_category_settings', array($this, 'save_category_settings'));
    }

    /**
     * メニューの追加
     */
    public function add_admin_menu() {
        add_menu_page(
            'カスタム予約投稿', 
            'カスタム予約投稿', 
            'manage_options', 
            'custom_scheduled_posts', 
            array($this, 'options_page'),
            'dashicons-calendar-alt',
            25
        );
        
        add_submenu_page(
            'custom_scheduled_posts',
            '基本設定',
            '基本設定',
            'manage_options',
            'custom_scheduled_posts',
            array($this, 'options_page')
        );
        
        // カテゴリー別設定のサブメニューページを追加
        add_submenu_page(
            'custom_scheduled_posts',
            'カテゴリー別設定',
            'カテゴリー別設定',
            'manage_options',
            'csp_category_settings',
            array($this, 'category_settings_page')
        );
        
        // 内部リンク分析のサブメニューページを追加（ダミー）
        add_submenu_page(
            'custom_scheduled_posts',
            '内部リンク分析',
            '内部リンク分析',
            'manage_options',
            'csp_link_analysis',
            array($this, 'link_analysis_page')
        );
    }

    /**
     * カテゴリー別設定ページ
     */
    public function category_settings_page() {
        // カテゴリー一覧を取得
        $categories = get_categories(array('hide_empty' => false));
        $category_options = get_option('csp_category_options', array());
        
        echo '<div class="wrap">';
        echo '<h1>カテゴリー別設定</h1>';
        echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
        echo '<input type="hidden" name="action" value="csp_save_category_settings">';
        wp_nonce_field('csp_save_category_settings', 'csp_nonce');
        
        echo '<table class="form-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>カテゴリー名</th>';
        echo '<th>更新期間（日数）</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($categories as $category) {
            $repost_days = isset($category_options[$category->term_id]) ? 
                intval($category_options[$category->term_id]) : 7;
            
            echo '<tr>';
            echo '<td>' . esc_html($category->name) . '</td>';
            echo '<td>';
            echo '<input type="number" name="csp_category_options[' . $category->term_id . ']" value="' . $repost_days . '" min="1">';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    /**
     * カテゴリー別設定の初期化
     */
    public function category_settings_init() {
        register_setting('csp_category_settings', 'csp_category_options');
    }

    /**
     * カテゴリー別設定保存処理
     */
    public function save_category_settings() {
        try {
            // nonceの検証
            if (!isset($_POST['csp_nonce']) || !wp_verify_nonce($_POST['csp_nonce'], 'csp_save_category_settings')) {
                throw new Exception('セキュリティエラー：不正なアクセスが検出されました。');
            }
            
            // 権限の確認
            if (!current_user_can('manage_options')) {
                throw new Exception('権限がありません。');
            }
            
            // 入力値のサニタイズとバリデーション
            $category_options = array();
            if (isset($_POST['csp_category_options']) && is_array($_POST['csp_category_options'])) {
                foreach ($_POST['csp_category_options'] as $category_id => $repost_days) {
                    $category_options[intval($category_id)] = max(1, intval($repost_days));
                }
            }
            
            update_option('csp_category_options', $category_options);
            
            // ログ出力
            if (function_exists('csp_log')) {
                csp_log('カテゴリー別設定が正常に保存されました。');
            }
            
            // 設定保存後のリダイレクト
            wp_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=csp_category_settings')));
            exit;
        } catch (Exception $e) {
            if (function_exists('csp_log')) {
                csp_log('カテゴリー別設定保存エラー: ' . $e->getMessage(), 'ERROR');
            }
            wp_die(esc_html($e->getMessage()));
        }
    }

    /**
     * 設定の初期化
     */
    public function settings_init() {
        register_setting('csp_settings', 'csp_options');
        
        add_settings_section(
            'csp_settings_section',
            __('基本設定', 'wordpress'),
            array($this, 'settings_section_callback'),
            'csp_settings'
        );

        add_settings_field(
            'csp_interval',
            __('下書きの予約投稿間隔（時間）', 'wordpress'),
            array($this, 'interval_render'),
            'csp_settings',
            'csp_settings_section'
        );

        add_settings_field(
            'csp_drafts_per_interval',
            __('各間隔ごとの下書き投稿件数', 'wordpress'),
            array($this, 'drafts_per_interval_render'),
            'csp_settings',
            'csp_settings_section'
        );

        add_settings_field(
            'csp_repost_days',
            __('再投稿する日数（現在日時より経過した日数）', 'wordpress'),
            array($this, 'repost_days_render'),
            'csp_settings',
            'csp_settings_section'
        );

        add_settings_field(
            'csp_exclude_post_ids',
            __('予約投稿を除外する記事ID（カンマ区切り）', 'wordpress'),
            array($this, 'exclude_post_ids_render'),
            'csp_settings',
            'csp_settings_section'
        );
    }

    /**
     * 設定保存処理
     */
    public function save_settings() {
        try {
            // nonceの検証
            if (!isset($_POST['csp_nonce']) || !wp_verify_nonce($_POST['csp_nonce'], 'csp_save_settings')) {
                throw new Exception('セキュリティエラー：不正なアクセスが検出されました。');
            }
            
            // 権限の確認
            if (!current_user_can('manage_options')) {
                throw new Exception('権限がありません。');
            }
            
            // 入力値のサニタイズとバリデーション
            $options = get_option('csp_options');
            $options['csp_interval'] = isset($_POST['csp_options']['csp_interval']) ? 
                max(1, intval($_POST['csp_options']['csp_interval'])) : 1;
            $options['csp_drafts_per_interval'] = isset($_POST['csp_options']['csp_drafts_per_interval']) ? 
                max(1, intval($_POST['csp_options']['csp_drafts_per_interval'])) : 1;
            $options['csp_repost_days'] = isset($_POST['csp_options']['csp_repost_days']) ? 
                max(1, intval($_POST['csp_options']['csp_repost_days'])) : 7;
            $options['csp_exclude_post_ids'] = isset($_POST['csp_options']['csp_exclude_post_ids']) ? 
                sanitize_text_field($_POST['csp_options']['csp_exclude_post_ids']) : '';
            
            update_option('csp_options', $options);
            
            // ログ出力
            if (function_exists('csp_log')) {
                csp_log('設定が正常に保存されました。');
            }
            
            // 設定保存後のリダイレクト
            wp_redirect(add_query_arg('settings-updated', 'true', $_POST['_wp_http_referer']));
            exit;
        } catch (Exception $e) {
            if (function_exists('csp_log')) {
                csp_log('設定保存エラー: ' . $e->getMessage(), 'ERROR');
            }
            wp_die(esc_html($e->getMessage()));
        }
    }

    /**
     * 下書きの予約投稿間隔のレンダリング
     */
    public function interval_render() {
        $options = get_option('csp_options');
        ?>
        <input type='number' name='csp_options[csp_interval]' value='<?php echo esc_attr($options['csp_interval']); ?>' min='1'>
        <p class="description">指定された時間ごとに下書きを予約投稿します。</p>
        <?php
    }

    /**
     * 各間隔ごとの下書き投稿件数のレンダリング
     */
    public function drafts_per_interval_render() {
        $options = get_option('csp_options');
        ?>
        <input type='number' name='csp_options[csp_drafts_per_interval]' value='<?php echo esc_attr($options['csp_drafts_per_interval']); ?>' min='1'>
        <p class="description">各間隔ごとに予約投稿する下書きの件数を指定します。</p>
        <?php
    }

    /**
     * 再投稿する日数のレンダリング
     */
    public function repost_days_render() {
        $options = get_option('csp_options');
        ?>
        <input type='number' name='csp_options[csp_repost_days]' value='<?php echo esc_attr($options['csp_repost_days']); ?>' min='1'>
        <p class="description">指定された日数より古い投稿を再投稿します。</p>
        <?php
    }

    /**
     * 予約投稿を除外する記事IDのレンダリング
     */
    public function exclude_post_ids_render() {
        $options = get_option('csp_options');
        ?>
        <input type='text' name='csp_options[csp_exclude_post_ids]' value='<?php echo esc_attr($options['csp_exclude_post_ids']); ?>'>
        <p class="description">予約投稿から除外する記事IDをカンマで区切って入力します。</p>
        <?php
    }

    /**
     * 設定セクションのコールバック
     */
    public function settings_section_callback() {
        echo __('カスタム予約投稿の基本設定を行います。以下のオプションを設定してください。', 'wordpress');
    }

    /**
     * オプションページの表示
     */
    public function options_page() {
        ?>
        <div class="wrap">
            <h1>カスタム予約投稿</h1>
            <nav class="nav-tab-wrapper">
                <a href="?page=custom_scheduled_posts" class="nav-tab nav-tab-active">基本設定</a>
                <a href="?page=csp_category_settings" class="nav-tab">カテゴリー別設定</a>
                <a href="?page=csp_link_analysis" class="nav-tab">内部リンク分析</a>
            </nav>
            <form action='<?php echo admin_url('admin-post.php'); ?>' method='post'>
                <?php wp_nonce_field('csp_save_settings', 'csp_nonce'); ?>
                <input type="hidden" name="action" value="csp_save_settings">
                <h2>基本設定</h2>
                <?php
                settings_fields('csp_settings');
                do_settings_sections('csp_settings');
                submit_button();
                ?>
            </form>
        </div>
        <style>
            .nav-tab-wrapper {
                margin-bottom: 20px;
            }
        </style>
        <?php
    }

    /**
     * カテゴリーごとの更新期間を取得する
     */
    public function get_category_repost_days($category_id) {
        $category_options = get_option('csp_category_options', array());
        return isset($category_options[$category_id]) ? intval($category_options[$category_id]) : 7;
    }

    /**
     * 内部リンク分析ページ
     */
    public function link_analysis_page() {
        echo '<div class="wrap">';
        echo '<h1>内部リンク分析</h1>';
        
        // ショートコードをデモとして表示
        echo '<h2>ショートコードの使用例</h2>';
        echo '<p>以下のショートコードを投稿や固定ページに追加することで、内部リンク構造をグラフィカルに表示できます：</p>';
        echo '<pre>[custom_posts_link]</pre>';
        echo '<p>リスト形式で表示する場合は以下のように指定します：</p>';
        echo '<pre>[custom_posts_link display="list"]</pre>';
        
        // 実際の表示例
        echo '<h2>表示例</h2>';
        echo do_shortcode('[custom_posts_link]');
        
        echo '</div>';
    }
}

endif;