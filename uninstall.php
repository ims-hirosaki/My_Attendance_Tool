<?php
/**
 * プラグイン削除時に実行されるファイル
 * WordPress管理画面でプラグインを「削除」したときのみ呼ばれる。
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// database-setup.php はテーブル名定数に依存するため、本体を読み込まないこの文脈で定義しておく
global $wpdb;
if ( ! defined( 'MAT_LOG_TABLE' ) )          define( 'MAT_LOG_TABLE',          $wpdb->prefix . 'my_attendance_logs' );
if ( ! defined( 'MAT_AUTH_TABLE' ) )         define( 'MAT_AUTH_TABLE',         $wpdb->prefix . 'my_attendance_auth' );
if ( ! defined( 'MAT_DAILY_TABLE' ) )        define( 'MAT_DAILY_TABLE',        $wpdb->prefix . 'mat_attendance_daily' );
if ( ! defined( 'MAT_BREAK_MASTER_TABLE' ) ) define( 'MAT_BREAK_MASTER_TABLE', $wpdb->prefix . 'mat_break_master' );
if ( ! defined( 'MAT_WORK_REQUEST_TABLE' ) ) define( 'MAT_WORK_REQUEST_TABLE', $wpdb->prefix . 'mat_work_request' );

require_once plugin_dir_path( __FILE__ ) . 'includes/database-setup.php';
mat_drop_tables();
