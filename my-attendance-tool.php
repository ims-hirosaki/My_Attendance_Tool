<?php
/*
Plugin Name: My Attendance Tool
Description: 出退勤を記録するツール。employee-manager と連携して動作します。
Version: 3.0.0
Author: 株式会社Ｉ・Ｍ・Ｓ
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// ===== 定数定義 =====
define( 'MAT_VERSION',  '3.0.0' );
define( 'MAT_PATH',     plugin_dir_path( __FILE__ ) );
define( 'MAT_URL',      plugin_dir_url( __FILE__ ) );

// テーブル名定数（グローバル変数が確定してから定義）
global $wpdb;
define( 'MAT_LOG_TABLE',  $wpdb->prefix . 'my_attendance_logs' );
define( 'MAT_AUTH_TABLE', $wpdb->prefix . 'my_attendance_auth' );

// ===== ファイル読み込み =====
require_once MAT_PATH . 'includes/database-setup.php';
require_once MAT_PATH . 'includes/ajax-handlers.php';
require_once MAT_PATH . 'includes/admin-settings.php';
require_once MAT_PATH . 'includes/admin-auth-management.php';
require_once MAT_PATH . 'includes/admin-settings-page.php';
require_once MAT_PATH . 'includes/admin-test-data.php';
require_once MAT_PATH . 'includes/admin-csv-import.php'; 
require_once MAT_PATH . 'includes/frontend-shortcode.php';

// ===== 有効化フック =====
register_activation_hook( __FILE__, 'mat_activate' );
function mat_activate() {
    // employee-manager の依存チェック
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
    // employee-manager が無効化された場合の警告
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
    $value = get_option( 'mat_' . $key, null );
    if ( $value === null ) return $default;
    // bool 系設定
    $bool_keys = array( 'use_password_auth', 'use_paid_leave_approval', 'show_paid_leave_request', 'allow_log_edit' );
    if ( in_array( $key, $bool_keys, true ) ) {
        return (bool) $value;
    }
    return $value;
}

/**
 * 締め日設定をもとに「当月」の開始日・終了日を返す
 *
 * @param string|null $base_date  基準日（Y-m-d 形式、省略時は今日）
 * @return array { start: 'Y-m-d', end: 'Y-m-d' }
 */
function mat_get_current_period( $base_date = null ) {
    $closing = (int) get_option( 'mat_closing_day', 0 );
    $today   = $base_date ? new DateTime( $base_date ) : new DateTime();

    // 末日締め（closing = 0）
    if ( $closing === 0 ) {
        return array(
            'start' => $today->format( 'Y-m-01' ),
            'end'   => $today->format( 'Y-m-t' ),
        );
    }

    // 指定日締め
    $current_day = (int) $today->format( 'd' );

    if ( $current_day <= $closing ) {
        // 今月の締め日以前 → 前月の締め日翌日〜今月の締め日
        $start_dt = clone $today;
        $start_dt->modify( 'first day of last month' );
        $start_dt->setDate(
            (int) $start_dt->format( 'Y' ),
            (int) $start_dt->format( 'm' ),
            $closing + 1
        );
        $end_dt = clone $today;
        $end_dt->setDate(
            (int) $today->format( 'Y' ),
            (int) $today->format( 'm' ),
            $closing
        );
    } else {
        // 今月の締め日より後 → 今月の締め日翌日〜来月の締め日
        $start_dt = clone $today;
        $start_dt->setDate(
            (int) $today->format( 'Y' ),
            (int) $today->format( 'm' ),
            $closing + 1
        );
        $end_dt = clone $today;
        $end_dt->modify( 'first day of next month' );
        $end_dt->setDate(
            (int) $end_dt->format( 'Y' ),
            (int) $end_dt->format( 'm' ),
            $closing
        );
    }

    return array(
        'start' => $start_dt->format( 'Y-m-d' ),
        'end'   => $end_dt->format( 'Y-m-d' ),
    );
}

/**
 * 指定日が「当月（編集可能期間）」に含まれるか判定する
 *
 * @param string $date  判定する日付（Y-m-d 形式）
 * @return bool
 */
function mat_is_in_current_period( $date ) {
    $period = mat_get_current_period();
    return ( $date >= $period['start'] && $date <= $period['end'] );
}
