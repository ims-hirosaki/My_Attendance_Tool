<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * テーブル作成・マイグレーション
 *
 * 変更点（v3.1.0）：
 *  - wp_mat_attendance_daily を新規作成（正規化済み新テーブル）
 *  - 旧テーブル wp_my_attendance_logs は移行完了まで維持（削除しない）
 *
 * 変更点（v3.0.0）：
 *  - wp_my_attendance_auth を新規作成（パスワード認証情報）
 *  - wp_my_attendance_users を廃止（employee-manager に統合）
 */
function mat_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // =========================================================
    // 1. 勤怠ログテーブル（旧・維持）
    //    ※ フェーズ4完了まで削除しない
    // =========================================================
    dbDelta( "CREATE TABLE " . MAT_LOG_TABLE . " (
        id                      BIGINT(20)      NOT NULL AUTO_INCREMENT,
        item_name               VARCHAR(255)    NOT NULL                    COMMENT '打刻内容（出勤: HH:MM | 退勤: HH:MM | ...）',
        timestamp               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '打刻日時',
        registered_user_id      BIGINT(20)      NOT NULL                    COMMENT 'wp_emp_master.id を参照',
        registered_user_name    VARCHAR(255)    NOT NULL DEFAULT ''         COMMENT '氏名（冗長保持）',
        employee_code           VARCHAR(50)     NOT NULL DEFAULT ''         COMMENT '社員コード（冗長保持）',
        paid_leave_date         DATE                NULL DEFAULT NULL       COMMENT '有給希望日',
        PRIMARY KEY (id),
        KEY idx_employee_code (employee_code),
        KEY idx_timestamp     (timestamp),
        KEY idx_user_id       (registered_user_id)
    ) $charset;" );

    // =========================================================
    // 2. 認証テーブル（v3.0.0〜）
    // =========================================================
    dbDelta( "CREATE TABLE " . MAT_AUTH_TABLE . " (
        id                  BIGINT(20)      NOT NULL AUTO_INCREMENT,
        emp_master_id       BIGINT(20)      NOT NULL                    COMMENT 'wp_emp_master.id',
        employee_code       VARCHAR(50)     NOT NULL                    COMMENT '社員コード（照合用に冗長保持）',
        password_hash       VARCHAR(255)        NULL DEFAULT NULL       COMMENT 'password_hash() で保存。NULL = 未設定',
        is_registered       TINYINT(1)      NOT NULL DEFAULT 0          COMMENT '0=未設定 / 1=設定済み',
        login_failed_count  TINYINT(3)      NOT NULL DEFAULT 0          COMMENT 'ログイン失敗回数',
        locked_until        DATETIME            NULL DEFAULT NULL       COMMENT 'ロック解除日時（5回失敗で30分）',
        reset_token         VARCHAR(64)         NULL DEFAULT NULL       COMMENT 'パスワードリセット用トークン',
        reset_token_expires DATETIME            NULL DEFAULT NULL       COMMENT 'トークン有効期限',
        created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_emp_master_id  (emp_master_id),
        UNIQUE KEY uq_employee_code  (employee_code)
    ) $charset;" );

    // =========================================================
    // 3. 勤怠日次テーブル（新・v3.1.0〜）
    //    正規化済み設計。item_name の文字列パースが不要。
    //    旧テーブルと並行稼働し、移行完了後に旧テーブルを削除する。
    // =========================================================
    dbDelta( "CREATE TABLE " . MAT_DAILY_TABLE . " (
        id              BIGINT(20)      NOT NULL AUTO_INCREMENT   COMMENT 'PK',
        employee_id     BIGINT(20)      NOT NULL                  COMMENT 'wp_emp_master.id',
        employee_code   VARCHAR(50)     NOT NULL DEFAULT ''       COMMENT '社員コード（冗長保持・検索用）',
        work_date       DATE            NOT NULL                  COMMENT '勤務日（1日1レコード）',
        clock_in        TIME                NULL DEFAULT NULL     COMMENT '出勤時刻',
        clock_out       TIME                NULL DEFAULT NULL     COMMENT '退勤時刻',
        break_minutes   SMALLINT UNSIGNED   NULL DEFAULT NULL     COMMENT '休憩時間（分）',
        is_holiday      TINYINT(1)      NOT NULL DEFAULT 0        COMMENT '休日フラグ 1=休日',
        note            TEXT                NULL DEFAULT NULL     COMMENT '備考',
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_employee_date  (employee_id, work_date),
        KEY idx_employee_code        (employee_code),
        KEY idx_work_date            (work_date)
    ) $charset;" );

    // =========================================================
    // 4. 旧テーブル（wp_my_attendance_users）の削除
    //    ※ v3.0.0 で廃止済み。空なら削除、データ残存なら警告のみ。
    // =========================================================
    $users_table = $wpdb->prefix . 'my_attendance_users';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $users_table ) ) ) {
        $row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$users_table}`" );
        if ( $row_count === 0 ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$users_table}`" );
        } else {
            add_action( 'admin_notices', 'mat_old_users_table_notice' );
        }
    }

    // =========================================================
    // 5. デフォルト設定の保存（初回のみ）
    // =========================================================
    $defaults = array(
        'mat_use_password_auth'       => 1,
        'mat_use_paid_leave_approval' => 1,
        'mat_show_paid_leave_request' => 1,
        'mat_allow_log_edit'          => 0,
        'mat_closing_day'             => 0,
    );
    foreach ( $defaults as $key => $value ) {
        if ( get_option( $key ) === false ) {
            add_option( $key, $value );
        }
    }

    update_option( 'mat_db_version', MAT_VERSION );
}

/**
 * 旧テーブル（wp_my_attendance_users）にデータが残っている場合の警告
 */
function mat_old_users_table_notice() {
    $users_table = $GLOBALS['wpdb']->prefix . 'my_attendance_users';
    echo '<div class="notice notice-warning"><p>'
        . '<strong>My Attendance Tool:</strong> '
        . "旧テーブル <code>{$users_table}</code> にデータが残っています。"
        . '内容を確認し、不要であれば手動で削除してください。'
        . '</p></div>';
}

/**
 * プラグインアンインストール時のテーブル削除（uninstall.php から呼び出す）
 */
function mat_drop_tables() {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS " . MAT_DAILY_TABLE );
    $wpdb->query( "DROP TABLE IF EXISTS " . MAT_LOG_TABLE );
    $wpdb->query( "DROP TABLE IF EXISTS " . MAT_AUTH_TABLE );
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}my_attendance_users`" );

    $option_keys = array(
        'mat_db_version',
        'mat_use_password_auth',
        'mat_use_paid_leave_approval',
        'mat_show_paid_leave_request',
        'mat_allow_log_edit',
        'mat_closing_day',
        'mat_migration_status',
    );
    foreach ( $option_keys as $key ) {
        delete_option( $key );
    }
}
