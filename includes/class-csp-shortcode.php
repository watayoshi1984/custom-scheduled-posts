<?php
/**
 * カスタム予約投稿プラグイン - ショートコードクラス
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CSP_Shortcode')) :

/**
 * ショートコードクラス
 */
class CSP_Shortcode {

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
        // ショートコードの登録はCSP_Link_Analyzerクラスで行うため、ここでは空にする
    }
}

endif;