<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================
//  社員コード認証
// =========================================================

add_action( 'wp_ajax_mat_verify_code',        'mat_verify_code_handler' );
add_action( 'wp_ajax_nopriv_mat_verify_code', 'mat_verify_code_handler' );

function mat_verify_code_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );

    $employee_code = isset( $_POST['employee_code'] )
        ? sanitize_text_field( $_POST['employee_code'] )
        : '';

    if ( empty( $employee_code ) ) {
        wp_send_json_error( '社員コードを入力してください。' );
    }

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp || ! $emp->is_active ) {
        wp_send_json_error( '社員コードが見つかりません。' );
    }

    $use_password = mat_get_setting( 'use_password_auth', true );

    if ( $use_password ) {
        global $wpdb;
        $auth = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . MAT_AUTH_TABLE . " WHERE employee_code = %s",
            $employee_code
        ) );

        if ( ! $auth || empty( $auth->password_hash ) ) {
            wp_send_json_success( array(
                'status'        => 'needs_password_setup',
                'emp_master_id' => (int) $emp->id,
                'user_name'     => $emp->name,
            ) );
        } else {
            wp_send_json_success( array(
                'status'    => 'needs_password',
                'user_name' => $emp->name,
            ) );
        }
    } else {
        wp_send_json_success( array(
            'status'        => 'logged_in',
            'emp_master_id' => (int) $emp->id,
            'employee_code' => $emp->employee_code,
            'user_name'     => $emp->name,
        ) );
    }
}


// =========================================================
//  パスワード新規設定
// =========================================================

add_action( 'wp_ajax_mat_set_password',        'mat_set_password_handler' );
add_action( 'wp_ajax_nopriv_mat_set_password', 'mat_set_password_handler' );

function mat_set_password_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );

    $employee_code = isset( $_POST['employee_code'] ) ? sanitize_text_field( $_POST['employee_code'] ) : '';
    $password      = isset( $_POST['password'] )      ? $_POST['password']                             : '';

    if ( empty( $employee_code ) || empty( $password ) ) {
        wp_send_json_error( '入力が不足しています。' );
    }
    if ( strlen( $password ) < 6 ) {
        wp_send_json_error( 'パスワードは6文字以上で設定してください。' );
    }

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp || ! $emp->is_active ) {
        wp_send_json_error( '社員コードが見つかりません。' );
    }

    global $wpdb;
    $hash = password_hash( $password, PASSWORD_DEFAULT );

    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM " . MAT_AUTH_TABLE . " WHERE employee_code = %s",
        $employee_code
    ) );

    if ( $existing ) {
        $wpdb->update(
            MAT_AUTH_TABLE,
            array(
                'password_hash'       => $hash,
                'reset_token'         => null,
                'reset_token_expires' => null,
            ),
            array( 'employee_code' => $employee_code ),
            array( '%s', '%s', '%s' ),
            array( '%s' )
        );
    } else {
        $wpdb->insert(
            MAT_AUTH_TABLE,
            array(
                'emp_master_id' => (int) $emp->id,
                'employee_code' => $employee_code,
                'password_hash' => $hash,
            ),
            array( '%d', '%s', '%s' )
        );
    }

    wp_send_json_success( array(
        'status'        => 'logged_in',
        'emp_master_id' => (int) $emp->id,
        'employee_code' => $emp->employee_code,
        'user_name'     => $emp->name,
    ) );
}


// =========================================================
//  パスワードログイン
// =========================================================

add_action( 'wp_ajax_mat_login',        'mat_login_handler' );
add_action( 'wp_ajax_nopriv_mat_login', 'mat_login_handler' );

function mat_login_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );

    $employee_code = isset( $_POST['employee_code'] ) ? sanitize_text_field( $_POST['employee_code'] ) : '';
    $password      = isset( $_POST['password'] )      ? $_POST['password']                             : '';

    if ( empty( $employee_code ) || empty( $password ) ) {
        wp_send_json_error( '入力が不足しています。' );
    }

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp || ! $emp->is_active ) {
        wp_send_json_error( '社員コードまたはパスワードが正しくありません。' );
    }

    global $wpdb;
    $auth = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . MAT_AUTH_TABLE . " WHERE employee_code = %s",
        $employee_code
    ) );

    if ( ! $auth || ! password_verify( $password, $auth->password_hash ) ) {
        wp_send_json_error( '社員コードまたはパスワードが正しくありません。' );
    }

    wp_send_json_success( array(
        'status'        => 'logged_in',
        'emp_master_id' => (int) $emp->id,
        'employee_code' => $emp->employee_code,
        'user_name'     => $emp->name,
    ) );
}


// =========================================================
//  パスワードリセット申請
// =========================================================

add_action( 'wp_ajax_mat_request_password_reset',        'mat_request_password_reset_handler' );
add_action( 'wp_ajax_nopriv_mat_request_password_reset', 'mat_request_password_reset_handler' );

function mat_request_password_reset_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );

    $employee_code = isset( $_POST['employee_code'] )
        ? sanitize_text_field( $_POST['employee_code'] )
        : '';

    if ( empty( $employee_code ) ) {
        wp_send_json_error( '社員コードを入力してください。' );
    }

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp || ! $emp->is_active ) {
        wp_send_json_success( array( 'message' => '管理者にお問い合わせください。' ) );
        return;
    }

    global $wpdb;
    $token   = bin2hex( random_bytes( 32 ) );
    $expires = date( 'Y-m-d H:i:s', time() + 86400 );

    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM " . MAT_AUTH_TABLE . " WHERE employee_code = %s",
        $employee_code
    ) );

    if ( $existing ) {
        $wpdb->update(
            MAT_AUTH_TABLE,
            array(
                'reset_token'         => $token,
                'reset_token_expires' => $expires,
            ),
            array( 'employee_code' => $employee_code ),
            array( '%s', '%s' ),
            array( '%s' )
        );
    } else {
        $wpdb->insert(
            MAT_AUTH_TABLE,
            array(
                'emp_master_id'       => (int) $emp->id,
                'employee_code'       => $employee_code,
                'reset_token'         => $token,
                'reset_token_expires' => $expires,
            ),
            array( '%d', '%s', '%s', '%s' )
        );
    }

    wp_send_json_success( array( 'message' => '管理者にお問い合わせください。管理者がパスワードをリセットします。' ) );
}


// =========================================================
//  打刻処理（出勤・退勤・休憩）
//
//  【排他制御の方針】
//  ・出勤打刻のみ INSERT なので、PC・スマホからの二重打刻が発生しうる。
//  ・退勤・休憩は当日の出勤行を UPDATE するだけなので重複は起きない。
//
//  【実装：MySQL Advisory Lock】
//  ・出勤打刻時に GET_LOCK('mat_punch_{id}_{date}', 5) を取得。
//  ・ロック保持中は他のリクエストが同じ社員・同日のロックを取れず待機 or タイムアウト。
//  ・ロック取得後に「当日出勤レコードが存在しないか」を再確認してから INSERT。
//  ・処理完了後（または途中終了時）に RELEASE_LOCK で解放。
//    ※ PHP リクエスト終了時に MySQL 接続が切れれば自動解放されるが明示的に解放する。
//  ・既存レコード（本修正以前の重複データ）はそのまま保持され影響を受けない。
// =========================================================

add_action( 'wp_ajax_mat_attendance_update',        'mat_attendance_update_handler' );
add_action( 'wp_ajax_nopriv_mat_attendance_update', 'mat_attendance_update_handler' );

function mat_attendance_update_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    global $wpdb;

    $emp_master_id = isset( $_POST['emp_master_id'] ) ? intval( $_POST['emp_master_id'] )              : 0;
    $employee_code = isset( $_POST['employee_code'] ) ? sanitize_text_field( $_POST['employee_code'] ) : '';
    $label         = isset( $_POST['label'] )         ? sanitize_text_field( $_POST['label'] )          : '';
    $note          = isset( $_POST['note'] )          ? sanitize_textarea_field( $_POST['note'] )       : '';

    if ( ! $emp_master_id || ! $employee_code ) {
        wp_send_json_error( 'ユーザー情報が不正です。' );
    }

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp || ! $emp->is_active ) {
        wp_send_json_error( '社員情報が見つかりません。' );
    }

    $today = current_time( 'Y-m-d' );
    $now   = current_time( 'Y-m-d H:i:s' );

    // 打刻内容の組み立て
    $item = '';
    switch ( $label ) {
        case '出勤':
            $time = current_time( 'H:i' );
            $item = "出勤: {$time}";
            break;
        case '退勤':
            $time = current_time( 'H:i' );
            $item = "退勤: {$time}";
            break;
        case '休憩':
            $break_hhmm = isset( $_POST['break_hhmm'] )
                ? sanitize_text_field( $_POST['break_hhmm'] )
                : '00:00';
            if ( ! preg_match( '/^\d{2}:\d{2}$/', $break_hhmm ) ) {
                $break_hhmm = '00:00';
            }
            $item = "休憩: {$break_hhmm}";
            break;
        default:
            wp_send_json_error( '不正な打刻種別です。' );
            return;
    }

    if ( $note !== '' ) {
        $item .= " | 備考: {$note}";
    }

    $insert_data = array(
        'registered_user_id'   => $emp_master_id,
        'registered_user_name' => $emp->name,
        'employee_code'        => $employee_code,
        'item_name'            => $item,
        'timestamp'            => $now,
    );

    // -----------------------------------------------------------------
    //  出勤打刻：Advisory Lock で二重打刻を防止
    // -----------------------------------------------------------------
    if ( $label === '出勤' ) {

        // ロックキー例: "mat_punch_7_2026-05-13"
        // ・プラグイン固有プレフィックス "mat_punch_" で他プラグインと干渉しない
        // ・社員ID + 日付の組み合わせで同一人物の同日リクエストのみをシリアライズ
        $lock_key = 'mat_punch_' . $emp_master_id . '_' . $today;

        // ロック取得（最大5秒待機）
        // 戻り値: 1=取得成功 / 0=タイムアウト / NULL=エラー
        $locked = $wpdb->get_var(
            $wpdb->prepare( "SELECT GET_LOCK(%s, 5)", $lock_key )
        );

        if ( $locked !== '1' && $locked !== 1 ) {
            // タイムアウト or エラー（別リクエストが長時間ロックを保持）
            wp_send_json_error( '処理が混み合っています。しばらく待ってから再度お試しください。' );
            return;
        }

        // ── ロック取得成功 ──────────────────────────────────────────
        // ロック保持中に当日の出勤レコードを確認する（TOC/TOU防止）
        $already_clocked_in = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . MAT_LOG_TABLE
            . " WHERE registered_user_id = %d"
            . "   AND timestamp LIKE %s"
            . "   AND item_name LIKE '出勤%%'"
            . " LIMIT 1",
            $emp_master_id,
            $today . '%'
        ) );

        if ( $already_clocked_in ) {
            // 既に出勤済み → ロック解放してエラー返却
            $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_key ) );
            wp_send_json_error( '本日はすでに出勤打刻済みです。' );
            return;
        }

        // 出勤レコードを INSERT
        $result = $wpdb->insert( MAT_LOG_TABLE, $insert_data );

        // ロックを明示的に解放
        $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_key ) );

        if ( $result === false ) {
            wp_send_json_error( 'DB保存エラー: ' . $wpdb->last_error );
            return;
        }

        wp_send_json_success( mat_get_grouped_data( $emp_master_id, date( 'Y-m' ) ) );
        return;
    }

    // -----------------------------------------------------------------
    //  退勤・休憩：当日の出勤行に追記（UPDATE）
    //  ※ UPDATE のみなので新規行は増えない → Advisory Lock 不要
    // -----------------------------------------------------------------

    // 出勤打刻の存在確認（退勤・休憩の事前チェック）
    $today_record = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, item_name FROM " . MAT_LOG_TABLE
        . " WHERE registered_user_id = %d AND timestamp LIKE %s"
        . " ORDER BY timestamp DESC LIMIT 1",
        $emp_master_id,
        $today . '%'
    ) );

    if ( ! $today_record || ! preg_match( '/出勤:\s*\d{2}:\d{2}/', $today_record->item_name ) ) {
        wp_send_json_error( '出勤打刻がありません。先に出勤を打刻してください。' );
        return;
    }

    // 出勤行を特定して追記
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, item_name FROM " . MAT_LOG_TABLE
        . " WHERE registered_user_id = %d AND item_name LIKE '出勤%%' AND timestamp LIKE %s"
        . " ORDER BY timestamp DESC LIMIT 1",
        $emp_master_id,
        $today . '%'
    ) );

    if ( $existing ) {
        $new_item_name = $existing->item_name . ' | ' . $item;
        $result = $wpdb->update(
            MAT_LOG_TABLE,
            array( 'item_name' => $new_item_name ),
            array( 'id' => $existing->id )
        );
    } else {
        $result = $wpdb->insert( MAT_LOG_TABLE, $insert_data );
    }

    if ( $result === false ) {
        wp_send_json_error( 'DB保存エラー: ' . $wpdb->last_error );
        return;
    }

    wp_send_json_success( mat_get_grouped_data( $emp_master_id, date( 'Y-m' ) ) );
}


// =========================================================
//  有給希望日の申請（打刻と完全独立）
// =========================================================

add_action( 'wp_ajax_mat_submit_paid_leave',        'mat_submit_paid_leave_handler' );
add_action( 'wp_ajax_nopriv_mat_submit_paid_leave', 'mat_submit_paid_leave_handler' );

function mat_submit_paid_leave_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );

    $emp_master_id   = isset( $_POST['emp_master_id'] )   ? intval( $_POST['emp_master_id'] )              : 0;
    $employee_code   = isset( $_POST['employee_code'] )   ? sanitize_text_field( $_POST['employee_code'] ) : '';
    $paid_leave_date = isset( $_POST['paid_leave_date'] ) ? sanitize_text_field( $_POST['paid_leave_date'] ) : '';

    if ( ! $emp_master_id || ! $employee_code ) {
        wp_send_json_error( 'ユーザー情報が不正です。' );
    }

    if ( empty( $paid_leave_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $paid_leave_date ) ) {
        wp_send_json_error( '有給希望日が正しくありません。' );
    }

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp || ! $emp->is_active ) {
        wp_send_json_error( '社員情報が見つかりません。' );
    }

    // paid-leave-manager の PL_Request クラスを使って直接登録
    if ( class_exists( 'PL_Request' ) ) {
        $result = PL_Request::create( $employee_code, $paid_leave_date, '' );
    } else {
        wp_send_json_error( 'paid-leave-manager が有効化されていません。' );
        return;
    }

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
        return;
    }

    wp_send_json_success( mat_get_paid_leave_list( $employee_code ) );
}


// =========================================================
//  有給申請一覧取得
// =========================================================

add_action( 'wp_ajax_mat_get_paid_leave_requests',        'mat_get_paid_leave_requests_handler' );
add_action( 'wp_ajax_nopriv_mat_get_paid_leave_requests', 'mat_get_paid_leave_requests_handler' );

function mat_get_paid_leave_requests_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );

    $employee_code = isset( $_POST['employee_code'] )
        ? sanitize_text_field( $_POST['employee_code'] )
        : '';

    if ( empty( $employee_code ) ) {
        wp_send_json_error( '社員コードが不正です。' );
    }

    wp_send_json_success( mat_get_paid_leave_list( $employee_code ) );
}

/**
 * 指定社員の有給申請一覧を取得する共通関数
 *
 * @param string $employee_code
 * @return array
 */
function mat_get_paid_leave_list( $employee_code ) {
    global $wpdb;

    $table = $wpdb->prefix . 'paidleave_requests';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, request_date, status, created_at
         FROM {$table}
         WHERE employee_code = %s
         ORDER BY created_at DESC
         LIMIT 50",
        $employee_code
    ) );

    $status_map = array(
        'pending'  => '申請中',
        'approved' => '受理済み',
        'rejected' => '却下',
    );

    $list = array();
    foreach ( $rows as $r ) {
        $list[] = array(
            'id'              => (int) $r->id,
            'request_date'    => $r->created_at
                ? date( 'Y/m/d', strtotime( $r->created_at ) )
                : '-',
            'paid_leave_date' => $r->request_date
                ? date( 'Y/m/d', strtotime( $r->request_date ) )
                : '-',
            'status'          => isset( $status_map[ $r->status ] )
                ? $status_map[ $r->status ]
                : $r->status,
            'status_key'      => $r->status,
        );
    }

    return array( 'requests' => $list );
}


// =========================================================
//  履歴取得
// =========================================================

add_action( 'wp_ajax_mat_get_logs',        'mat_get_logs_handler' );
add_action( 'wp_ajax_nopriv_mat_get_logs', 'mat_get_logs_handler' );

function mat_get_logs_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );

    $emp_master_id = isset( $_POST['emp_master_id'] ) ? intval( $_POST['emp_master_id'] ) : 0;
    $month         = isset( $_POST['month'] )         ? sanitize_text_field( $_POST['month'] ) : date( 'Y-m' );

    wp_send_json_success( mat_get_grouped_data( $emp_master_id, $month ) );
}


// =========================================================
//  打刻編集（社員側）
// =========================================================
add_action( 'wp_ajax_mat_admin_delete_log', 'mat_admin_delete_log_handler' );

function mat_admin_delete_log_handler() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '権限がありません。' );
    }
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;

    $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
    if ( $id <= 0 ) {
        wp_send_json_error( 'IDが不正です。' );
    }

    // 対象レコードの存在確認
    $log = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM " . MAT_LOG_TABLE . " WHERE id = %d", $id
    ) );
    if ( ! $log ) {
        wp_send_json_error( '対象レコードが見つかりません。' );
    }

    $deleted = $wpdb->delete(
        MAT_LOG_TABLE,
        array( 'id' => $id ),
        array( '%d' )
    );

    if ( $deleted === false ) {
        wp_send_json_error( '削除失敗: ' . $wpdb->last_error );
    }

    wp_send_json_success( array( 'deleted_id' => $id ) );
}
add_action( 'wp_ajax_mat_edit_log',        'mat_edit_log_handler' );
add_action( 'wp_ajax_nopriv_mat_edit_log', 'mat_edit_log_handler' );

function mat_edit_log_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );

    if ( ! mat_get_setting( 'allow_log_edit', false ) ) {
        wp_send_json_error( '打刻編集は許可されていません。' );
    }

    global $wpdb;

    $id            = isset( $_POST['id'] )            ? intval( $_POST['id'] )                          : 0;
    $clock_in      = isset( $_POST['clock_in'] )      ? sanitize_text_field( $_POST['clock_in'] )       : '';
    $clock_out     = isset( $_POST['clock_out'] )     ? sanitize_text_field( $_POST['clock_out'] )      : '';
    $break_hhmm    = isset( $_POST['break_time'] )    ? sanitize_text_field( $_POST['break_time'] )     : '00:00';
    $note          = isset( $_POST['note'] )          ? sanitize_textarea_field( $_POST['note'] )        : '';
    $emp_master_id = isset( $_POST['emp_master_id'] ) ? intval( $_POST['emp_master_id'] )               : 0;

    if ( ! preg_match( '/^\d{2}:\d{2}$/', $break_hhmm ) ) {
        $break_hhmm = '00:00';
    }

    if ( $id <= 0 || ! $emp_master_id ) {
        wp_send_json_error( 'パラメータが不正です。' );
    }

    $log = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . MAT_LOG_TABLE . " WHERE id = %d AND registered_user_id = %d",
        $id, $emp_master_id
    ) );

    if ( ! $log ) {
        wp_send_json_error( '対象レコードが見つかりません。' );
    }

    $log_date = date( 'Y-m-d', strtotime( $log->timestamp ) );
    if ( ! mat_is_in_current_period( $log_date ) ) {
        wp_send_json_error( '当月期間外のレコードは編集できません。' );
    }

    $parts = array();
    if ( $clock_in  !== '' ) $parts[] = "出勤: {$clock_in}";
    if ( $clock_out !== '' ) $parts[] = "退勤: {$clock_out}";
    $parts[] = "休憩: {$break_hhmm}";
    if ( $note !== '' )      $parts[] = "備考: {$note}";

    $new_item_name = implode( ' | ', $parts );

    $updated = $wpdb->update(
        MAT_LOG_TABLE,
        array( 'item_name' => $new_item_name ),
        array( 'id' => $id ),
        array( '%s' ),
        array( '%d' )
    );

    if ( $updated === false ) {
        wp_send_json_error( '更新に失敗しました: ' . $wpdb->last_error );
    }

    wp_send_json_success( array(
        'clock_in'   => $clock_in  !== '' ? $clock_in  : '-',
        'clock_out'  => $clock_out !== '' ? $clock_out : '-',
        'break_time' => $break_hhmm,
        'note'       => $note,
    ) );
}


// =========================================================
//  共通データ取得関数
// =========================================================

/**
 * 指定ユーザーの月次打刻データを取得して整形する
 *
 * @param int    $emp_master_id  wp_emp_master.id
 * @param string $month          'Y-m' 形式
 * @return array
 */
function mat_get_grouped_data( $emp_master_id, $month = null ) {
    global $wpdb;
    if ( ! $month ) $month = date( 'Y-m' );

    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM " . MAT_LOG_TABLE
        . " WHERE registered_user_id = %d AND timestamp LIKE %s"
        . " ORDER BY timestamp ASC",
        $emp_master_id,
        $month . '%'
    ) );

    $logs            = array();
    $work_days_count = 0;
    $total_days      = (int) date( 't', strtotime( $month . '-01' ) );

    foreach ( $results as $r ) {
        $dow_map    = array( '日', '月', '火', '水', '木', '金', '土' );
        $dow        = $dow_map[ (int) date( 'w', strtotime( $r->timestamp ) ) ];
        $date       = date( 'm/d', strtotime( $r->timestamp ) ) . '(' . $dow . ')';
        $in_time    = '-';
        $out_time   = '-';
        $break_time = '-';

        if ( preg_match( '/出勤:\s*(\d{2}:\d{2})/', $r->item_name, $m ) ) {
            $in_time = $m[1];
            $work_days_count++;
        }
        if ( preg_match( '/退勤:\s*(\d{2}:\d{2})/', $r->item_name, $m ) ) {
            $out_time = $m[1];
        }
        if ( preg_match( '/休憩:\s*(\d{2}:\d{2})/', $r->item_name, $m ) ) {
            $break_time = $m[1];
        }

        preg_match_all( '/備考:\s*([^|]+)/', $r->item_name, $matches );
        $notes = isset( $matches[1] ) ? array_map( 'trim', $matches[1] ) : array();

        $log_date = date( 'Y-m-d', strtotime( $r->timestamp ) );
        $can_edit = mat_get_setting( 'allow_log_edit', false )
                    && mat_is_in_current_period( $log_date );

        $logs[] = array(
            'id'       => (int) $r->id,
            'date'     => $date,
            'in'       => $in_time,
            'out'      => $out_time,
            'break'    => $break_time,
            'notes'    => $notes,
            'can_edit' => $can_edit,
        );
    }

    return array(
        'logs'            => $logs,
        'work_days_count' => $work_days_count,
        'total_days'      => $total_days,
    );
}