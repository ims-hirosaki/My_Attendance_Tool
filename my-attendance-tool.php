<?php
/*
Plugin Name: My Attendance Tool
Description: 出退勤を記録するツール。employee-manager と連携して動作します。
Version: 3.1.0
Author: 株式会社Ｉ・Ｍ・Ｓ
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// ===== 定数定義 =====
define( 'MAT_VERSION',  '3.1.0' );
define( 'MAT_PATH',     plugin_dir_path( __FILE__ ) );
define( 'MAT_URL',      plugin_dir_url( __FILE__ ) );

// テーブル名定数
global $wpdb;
define( 'MAT_LOG_TABLE',   $wpdb->prefix . 'my_attendance_logs' );   // 旧テーブル（移行完了まで維持）
define( 'MAT_AUTH_TABLE',  $wpdb->prefix . 'my_attendance_auth' );
define( 'MAT_DAILY_TABLE', $wpdb->prefix . 'mat_attendance_daily' ); // 新テーブル（v3.1.0〜）

// ===== ファイル読み込み =====
require_once MAT_PATH . 'includes/database-setup.php';
require_once MAT_PATH . 'includes/ajax-handlers.php';
require_once MAT_PATH . 'includes/admin-settings.php';
require_once MAT_PATH . 'includes/admin-auth-management.php';
require_once MAT_PATH . 'includes/admin-settings-page.php';
require_once MAT_PATH . 'includes/admin-test-data.php';
require_once MAT_PATH . 'includes/admin-csv-import.php';
require_once MAT_PATH . 'includes/admin-migrate.php'; // フェーズ1〜3 移行ツール
require_once MAT_PATH . 'includes/frontend-shortcode.php';

// ===== 有効化フック =====
register_activation_hook( __FILE__, 'mat_activate' );
function mat_activate() {
    if ( ! function_exists( 'emp_get_active_employees' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            '<p><strong>My Attendance Tool</strong> を有効化するには、先に <strong>employee-manager</strong> プラグインを有効化してください。</p>',
            'プラグインの有効化エラー',
            array( 'back_link' => true )
        );
    }
    mat_create_tables();
}

// ===== 初期化 =====
add_action( 'plugins_loaded', 'mat_init' );
function mat_init() {
    if ( ! function_exists( 'emp_get_active_employees' ) ) {
        add_action( 'admin_notices', 'mat_missing_dependency_notice' );
        return;
    }

    // DBバージョンチェック（マイグレーション対応）
    if ( get_option( 'mat_db_version' ) !== MAT_VERSION ) {
        mat_create_tables();
    }
}

function mat_missing_dependency_notice() {
    echo '<div class="notice notice-error"><p>'
        . '<strong>My Attendance Tool:</strong> '
        . '<strong>employee-manager</strong> プラグインが必要です。先に有効化してください。'
        . '</p></div>';
}

// =========================================================
//  設定ヘルパー関数
// =========================================================

/**
 * 設定値を取得する
 *
 * @param string $key      設定キー（mat_ プレフィックスなし）
 * @param mixed  $default  デフォルト値
 * @return mixed
 */
function mat_get_setting( $key, $default = false ) {
    $value = get_option( 'mat_' . $key );
    if ( $value === false ) {
        return $default;
    }
    return $value;
}
