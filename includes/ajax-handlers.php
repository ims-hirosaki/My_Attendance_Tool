<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ---------------------------------------------------------
 * 1. 認証関連 (ログイン・初期設定)
 * ---------------------------------------------------------
 */

// 社員コード認証
add_action( 'wp_ajax_mat_verify_code',        'mat_verify_code_handler' );
add_action( 'wp_ajax_nopriv_mat_verify_code', 'mat_verify_code_handler' );
function mat_verify_code_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $employee_code = isset( $_POST['employee_code'] ) ? sanitize_text_field( $_POST['employee_code'] ) : '';
    if ( empty( $employee_code ) ) wp_send_json_error( '社員コードを入力してください。' );

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp || ! $emp->is_active ) wp_send_json_error( '社員コードが見つかりません。' );

    $use_password = mat_get_setting( 'use_password_auth', true );
    if ( $use_password ) {
        global $wpdb;
        $auth = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . MAT_AUTH_TABLE . " WHERE employee_code = %s", $employee_code ) );
        if ( ! $auth || empty( $auth->password_hash ) ) {
            wp_send_json_success( array( 'status' => 'needs_password_setup', 'emp_master_id' => (int) $emp->id, 'user_name' => $emp->name ) );
        } else {
            wp_send_json_success( array( 'status' => 'needs_password', 'user_name' => $emp->name ) );
        }
    } else {
        wp_send_json_success( array( 'status' => 'logged_in', 'emp_master_id' => (int) $emp->id, 'employee_code' => $emp->employee_code, 'user_name' => $emp->name ) );
    }
}

// パスワード新規設定
add_action( 'wp_ajax_mat_set_password',        'mat_set_password_handler' );
add_action( 'wp_ajax_nopriv_mat_set_password', 'mat_set_password_handler' );
function mat_set_password_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $employee_code = sanitize_text_field( $_POST['employee_code'] ?? '' );
    $password      = $_POST['password'] ?? '';
    if ( strlen( $password ) < 6 ) wp_send_json_error( 'パスワードは6文字以上で設定してください。' );

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp ) wp_send_json_error( '社員が見つかりません。' );

    global $wpdb;
    $hash = password_hash( $password, PASSWORD_DEFAULT );
    $wpdb->replace( MAT_AUTH_TABLE, array(
        'emp_master_id' => (int) $emp->id,
        'employee_code' => $employee_code,
        'password_hash' => $hash,
        'is_registered' => 1
    ), array( '%d', '%s', '%s', '%d' ) );

    wp_send_json_success( array( 'status' => 'logged_in', 'emp_master_id' => (int) $emp->id, 'employee_code' => $emp->employee_code, 'user_name' => $emp->name ) );
}

// パスワードログイン
add_action( 'wp_ajax_mat_login',        'mat_login_handler' );
add_action( 'wp_ajax_nopriv_mat_login', 'mat_login_handler' );
function mat_login_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $employee_code = sanitize_text_field( $_POST['employee_code'] ?? '' );
    $password      = $_POST['password'] ?? '';
    
    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp ) wp_send_json_error( '認証に失敗しました。' );

    global $wpdb;
    $auth = $wpdb->get_row( $wpdb->prepare( "SELECT password_hash FROM " . MAT_AUTH_TABLE . " WHERE employee_code = %s", $employee_code ) );
    if ( ! $auth || ! password_verify( $password, $auth->password_hash ) ) wp_send_json_error( 'パスワードが違います。' );

    wp_send_json_success( array( 'status' => 'logged_in', 'emp_master_id' => (int) $emp->id, 'employee_code' => $emp->employee_code, 'user_name' => $emp->name ) );
}

// パスワードリセット申請
add_action( 'wp_ajax_mat_request_password_reset', 'mat_request_password_reset_handler' );
add_action( 'wp_ajax_nopriv_mat_request_password_reset', 'mat_request_password_reset_handler' );
function mat_request_password_reset_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $employee_code = sanitize_text_field( $_POST['employee_code'] ?? '' );
    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp ) {
        wp_send_json_success( array( 'message' => '管理者へリセットを依頼してください。' ) );
        return;
    }
    global $wpdb;
    $wpdb->update( MAT_AUTH_TABLE, array( 'reset_token' => bin2hex(random_bytes(16)) ), array( 'employee_code' => $employee_code ) );
    wp_send_json_success( array( 'message' => 'リセット申請を送信しました。管理者が対応するまでお待ちください。' ) );
}

/**
 * ---------------------------------------------------------
 * 2. 打刻・休日登録・削除 (日付ベースの重複防止)
 * ---------------------------------------------------------
 */

// 打刻更新 (出勤・退勤・休憩)
add_action( 'wp_ajax_mat_attendance_update',        'mat_attendance_update_handler' );
add_action( 'wp_ajax_nopriv_mat_attendance_update', 'mat_attendance_update_handler' );
function mat_attendance_update_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    global $wpdb;

    $emp_master_id = intval( $_POST['emp_master_id'] ?? 0 );
    $employee_code = sanitize_text_field( $_POST['employee_code'] ?? '' );
    $label         = sanitize_text_field( $_POST['label'] ?? '' );
    $note          = sanitize_textarea_field( $_POST['note'] ?? '' );
    $today         = current_time( 'Y-m-d' );

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp ) wp_send_json_error( '社員情報が見つかりません。' );
    if ( (int) $emp->id !== $emp_master_id ) {
        wp_send_json_error( '社員情報が一致しません。ログアウトしてから再度お試しください。' );
    }

    $existing_row = mat_get_day_log_row( $emp_master_id, $today );
    $existing_id  = $existing_row ? (int) $existing_row->id : 0;
    $parsed       = mat_parse_attendance_item_name( $existing_row ? $existing_row->item_name : '' );

    if ( $label === '出勤' ) {
        if ( $parsed['is_holiday'] ) {
            wp_send_json_error( '本日は休日として登録されています。出勤する場合は、履歴の「編集」から削除するか、休日登録をやり直してください。' );
        }
        if ( $parsed['has_clockin'] ) {
            wp_send_json_error( '本日はすでに出勤打刻済みです。' );
        }

        $clock_in_str = '出勤: ' . current_time( 'H:i' );
        if ( $existing_id ) {
            $base     = trim( (string) $existing_row->item_name );
            $new_item = $base === '' ? $clock_in_str : $base . ' | ' . $clock_in_str;
            $ok       = $wpdb->update(
                MAT_LOG_TABLE,
                array( 'item_name' => $new_item ),
                array( 'id' => $existing_id ),
                array( '%s' ),
                array( '%d' )
            );
        } else {
            $ok = $wpdb->insert( MAT_LOG_TABLE, array(
                'registered_user_id'   => $emp_master_id,
                'registered_user_name' => $emp->name,
                'employee_code'        => $employee_code,
                'item_name'            => $clock_in_str,
                'timestamp'            => current_time( 'Y-m-d H:i:s' ),
            ), array( '%d', '%s', '%s', '%s', '%s' ) );
        }
        if ( $existing_id && $ok === false ) {
            wp_send_json_error( '打刻の保存に失敗しました。管理者にお問い合わせください。' );
        }
        if ( ! $existing_id && ! $ok ) {
            wp_send_json_error( '打刻の保存に失敗しました。管理者にお問い合わせください。' );
        }
    } else {
        if ( ! $existing_id || ! $parsed['has_clockin'] ) {
            wp_send_json_error( '本日のデータが見つかりません。先に出勤を打刻してください。' );
        }

        $time_val = ( $label === '休憩' ) ? sanitize_text_field( $_POST['break_hhmm'] ?? '00:00' ) : current_time( 'H:i' );
        if ( $label === '退勤' && $parsed['has_clockout'] ) {
            wp_send_json_error( '本日はすでに退勤打刻済みです。' );
        }
        if ( $label === '休憩' ) {
            $base     = preg_replace( '/\s*\|\s*休憩:\s*\d{2}:\d{2}/', '', $existing_row->item_name );
            $new_item = rtrim( $base ) . ' | 休憩: ' . $time_val;
        } else {
            $new_item = $existing_row->item_name . ' | ' . $label . ': ' . $time_val;
        }
        if ( ! empty( $note ) ) {
             $new_item .= ' | 備考: ' . $note;
         }

        $ok = $wpdb->update(
            MAT_LOG_TABLE,
            array( 'item_name' => $new_item ),
            array( 'id' => $existing_id ),
            array( '%s' ),
            array( '%d' )
        );
        if ( $ok === false ) {
            wp_send_json_error( '打刻の保存に失敗しました。管理者にお問い合わせください。' );
        }
    }
    wp_send_json_success( mat_get_grouped_data( $emp_master_id, current_time( 'Y-m' ) ) );
}

// 休日登録 (既存データを消して上書き)
add_action( 'wp_ajax_mat_register_holiday',        'mat_register_holiday_handler' );
add_action( 'wp_ajax_nopriv_mat_register_holiday', 'mat_register_holiday_handler' );
function mat_register_holiday_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    global $wpdb;
    $emp_master_id = intval( $_POST['emp_master_id'] );
    $employee_code = sanitize_text_field( $_POST['employee_code'] );
    $holiday_date  = sanitize_text_field( $_POST['holiday_date'] );

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp ) wp_send_json_error( '社員が見つかりません。' );
    if ( (int) $emp->id !== $emp_master_id ) {
        wp_send_json_error( '社員情報が一致しません。ログアウトしてから再度お試しください。' );
    }

    // ★ 強制上書き：その日の既存データを削除
    $wpdb->query( $wpdb->prepare( "DELETE FROM " . MAT_LOG_TABLE . " WHERE registered_user_id = %d AND DATE(timestamp) = %s", $emp_master_id, $holiday_date ) );
    
    $ok = $wpdb->insert(
        MAT_LOG_TABLE,
        array(
            'registered_user_id'   => $emp_master_id,
            'registered_user_name' => $emp->name,
            'employee_code'        => $employee_code,
            'item_name'            => '休日',
            'timestamp'            => $holiday_date . ' 00:00:00',
        ),
        array( '%d', '%s', '%s', '%s', '%s' )
    );
    if ( ! $ok ) {
        wp_send_json_error( '休日の登録に失敗しました。管理者にお問い合わせください。' );
    }
    wp_send_json_success( mat_get_grouped_data( $emp_master_id, substr( $holiday_date, 0, 7 ) ) );
}

// 打刻削除
add_action( 'wp_ajax_mat_delete_log',        'mat_delete_log_handler' );
add_action( 'wp_ajax_nopriv_mat_delete_log', 'mat_delete_log_handler' );
function mat_delete_log_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    global $wpdb;
    $id = intval( $_POST['id'] );
    $emp_master_id = intval( $_POST['emp_master_id'] );

    $log_date = $wpdb->get_var( $wpdb->prepare( "SELECT DATE(timestamp) FROM " . MAT_LOG_TABLE . " WHERE id = %d AND registered_user_id = %d", $id, $emp_master_id ) );
    if ( ! $log_date ) wp_send_json_error( 'データが見つかりません。' );
    
    // 期間外チェック
    if ( ! mat_is_in_current_period( $log_date ) ) wp_send_json_error( '確定済みの過去データは削除できません。' );

    $wpdb->delete( MAT_LOG_TABLE, array( 'id' => $id, 'registered_user_id' => $emp_master_id ) );
    wp_send_json_success();
}

/**
 * ---------------------------------------------------------
 * 3. 履歴取得・編集・有給連携
 * ---------------------------------------------------------
 */

// ログ取得
add_action( 'wp_ajax_mat_get_logs', 'mat_get_logs_handler' );
add_action( 'wp_ajax_nopriv_mat_get_logs', 'mat_get_logs_handler' );
function mat_get_logs_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $emp_id = intval( $_POST['emp_master_id'] );
    $month  = sanitize_text_field( $_POST['month'] ?? current_time( 'Y-m' ) );
    wp_send_json_success( mat_get_grouped_data( $emp_id, $month ) );
}

// 本日の打刻状態（ボタン制御用・表示月に依存しない）
add_action( 'wp_ajax_mat_get_today_status', 'mat_get_today_status_handler' );
add_action( 'wp_ajax_nopriv_mat_get_today_status', 'mat_get_today_status_handler' );
function mat_get_today_status_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $emp_id = intval( $_POST['emp_master_id'] ?? 0 );
    if ( ! $emp_id ) {
        wp_send_json_error( '社員情報が不正です。' );
    }
    $status = mat_get_today_punch_status( $emp_id );
    $status['today_ymd'] = current_time( 'Y-m-d' );
    wp_send_json_success( $status );
}

// ユーザーによる編集
add_action( 'wp_ajax_mat_edit_log', 'mat_edit_log_handler' );
add_action( 'wp_ajax_nopriv_mat_edit_log', 'mat_edit_log_handler' );
function mat_edit_log_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    if ( ! mat_get_setting( 'allow_log_edit', false ) ) wp_send_json_error( '編集は許可されていません。' );

    global $wpdb;
    $id = intval( $_POST['id'] );
    $emp_id = intval( $_POST['emp_master_id'] );
    
    $log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . MAT_LOG_TABLE . " WHERE id = %d AND registered_user_id = %d", $id, $emp_id ) );
    if ( ! $log || ! mat_is_in_current_period( date('Y-m-d', strtotime($log->timestamp)) ) ) {
        wp_send_json_error( '編集できないデータです。' );
    }

    $in = sanitize_text_field( $_POST['clock_in'] );
    $out = sanitize_text_field( $_POST['clock_out'] );
    $br = sanitize_text_field( $_POST['break_time'] ?? '00:00' );
    $note = sanitize_textarea_field( $_POST['note'] );

    $parts = array();
    if ( !empty($in) )  $parts[] = "出勤: $in";
    if ( !empty($out) ) $parts[] = "退勤: $out";
    $parts[] = "休憩: $br";
    if ( !empty($note) ) $parts[] = "備考: $note";

    $wpdb->update( MAT_LOG_TABLE, array( 'item_name' => implode( ' | ', $parts ) ), array( 'id' => $id ) );
    wp_send_json_success();
}

// 有給申請 (Paid Leave Manager連携)
add_action( 'wp_ajax_mat_submit_paid_leave', 'mat_submit_paid_leave_handler' );
add_action( 'wp_ajax_nopriv_mat_submit_paid_leave', 'mat_submit_paid_leave_handler' );
function mat_submit_paid_leave_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    if ( ! class_exists( 'PL_Request' ) ) wp_send_json_error( '有給管理システムが未稼働です。' );

    $code = sanitize_text_field( $_POST['employee_code'] );
    $date = sanitize_text_field( $_POST['paid_leave_date'] );
    $res = PL_Request::create( $code, $date, '勤怠ツールからの申請' );
    
    if ( is_wp_error( $res ) ) wp_send_json_error( $res->get_error_message() );
    wp_send_json_success( mat_get_paid_leave_list( $code ) );
}

add_action( 'wp_ajax_mat_get_paid_leave_requests', 'mat_get_paid_leave_requests_handler' );
add_action( 'wp_ajax_nopriv_mat_get_paid_leave_requests', 'mat_get_paid_leave_requests_handler' );
function mat_get_paid_leave_requests_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    wp_send_json_success( mat_get_paid_leave_list( sanitize_text_field($_POST['employee_code']) ) );
}

/**
 * ---------------------------------------------------------
 * 4. ヘルパー関数 (これらが消えると重大エラーになります)
 * ---------------------------------------------------------
 */

// 有給リスト取得
function mat_get_paid_leave_list( $employee_code ) {
    global $wpdb;
    $table = $wpdb->prefix . 'paidleave_requests';
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, request_date, status, created_at FROM {$table} WHERE employee_code = %s ORDER BY created_at DESC LIMIT 10", $employee_code ) );
    $map = array( 'pending' => '申請中', 'approved' => '受理済み', 'rejected' => '却下' );
    $list = array();
    foreach ( $rows as $r ) {
        $list[] = array( 
            'request_date' => date('Y/m/d', strtotime($r->created_at)), 
            'paid_leave_date' => date('Y/m/d', strtotime($r->request_date)), 
            'status' => $map[$r->status] ?? $r->status, 'status_key' => $r->status 
        );
    }
    return array( 'requests' => $list );
}

/**
 * item_name から打刻内容を解析（時刻が入っているかで判定）
 */
function mat_parse_attendance_item_name( $item_name ) {
    $item = trim( (string) $item_name );

    $is_holiday   = ( $item === '休日' );
    $has_clockin  = (bool) preg_match( '/出勤:\s*(\d{2}:\d{2})/', $item );
    $has_clockout = (bool) preg_match( '/退勤:\s*(\d{2}:\d{2})/', $item );
    $has_break    = (bool) preg_match( '/休憩:\s*(\d{2}:\d{2})/', $item, $br_m );
    $has_break_time = $has_break && isset( $br_m[1] ) && $br_m[1] !== '00:00';

    preg_match_all( '/備考:\s*([^|]+)/', $item, $notes_m );
    $has_notes = false;
    foreach ( $notes_m[1] ?? array() as $note ) {
        if ( trim( $note ) !== '' ) {
            $has_notes = true;
            break;
        }
    }

    $has_meaningful_data = $is_holiday || $has_clockin || $has_clockout || $has_break_time || $has_notes;
    $has_break    = (bool) preg_match( '/休憩:\s*(\d{2}:\d{2})/', $item, $br_m );
    $has_break_time = $has_break && isset( $br_m[1] ) && $br_m[1] !== '00:00';
    return array(
        'is_holiday'          => $is_holiday,
        'has_clockin'         => $has_clockin,
        'has_clockout'        => $has_clockout,
        'has_break'           => $has_break,
        'has_break_time'      => $has_break_time,
        'has_notes'           => $has_notes,
        'has_meaningful_data' => $has_meaningful_data,
    );
}

/** 指定日の代表レコード（時刻入りを優先） */
function mat_get_day_log_row( $emp_master_id, $date ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, item_name, timestamp FROM " . MAT_LOG_TABLE . " WHERE registered_user_id = %d AND DATE(timestamp) = %s ORDER BY id ASC",
        $emp_master_id, $date
    ) );
    if ( empty( $rows ) ) {
        return null;
    }

    $best      = $rows[0];
    $best_rank = -1;
    foreach ( $rows as $row ) {
        $p = mat_parse_attendance_item_name( $row->item_name );
        if ( $p['is_holiday'] ) {
            $rank = 100;
        } elseif ( $p['has_clockin'] ) {
            $rank = 90;
        } elseif ( $p['has_clockout'] ) {
            $rank = 80;
        } elseif ( $p['has_meaningful_data'] ) {
            $rank = 50;
        } else {
            $rank = 0;
        }
        if ( $rank >= $best_rank ) {
            $best_rank = $rank;
            $best      = $row;
        }
    }
    return $best;
}

// 本日の打刻状態
function mat_get_today_punch_status( $emp_master_id ) {
    $row = mat_get_day_log_row( $emp_master_id, current_time( 'Y-m-d' ) );
    if ( ! $row ) {
        return array(
            'is_holiday'   => false,
            'has_clockin'  => false,
            'has_clockout' => false,
        );
    }
    return mat_parse_attendance_item_name( $row->item_name );
}

// 履歴整形データ取得
function mat_get_grouped_data( $emp_master_id, $month = null ) {
    global $wpdb;
    if ( ! $month ) $month = current_time( 'Y-m' );
    $results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . MAT_LOG_TABLE . " WHERE registered_user_id = %d AND timestamp LIKE %s ORDER BY timestamp ASC", $emp_master_id, $month . '%' ) );
    
    $logs = array();
    $work_days_count = 0;
    foreach ( $results as $r ) {
        $ts = strtotime( $r->timestamp );
        $dow = array('日','月','火','水','木','金','土');
        $date_label = date('m/d', $ts) . '(' . $dow[date('w', $ts)] . ')';
        
        $parsed     = mat_parse_attendance_item_name( $r->item_name );
        $is_holiday = $parsed['is_holiday'];
        $in = $out = $br = '-';

        if ( $is_holiday ) {
            $in = '休日';
        } else {
            if ( preg_match( '/出勤:\s*(\d{2}:\d{2})/', $r->item_name, $m ) ) {
                $in = $m[1];
                $work_days_count++;
            }
            if ( preg_match( '/退勤:\s*(\d{2}:\d{2})/', $r->item_name, $m ) ) {
                $out = $m[1];
            }
            if ( preg_match( '/休憩:\s*(\d{2}:\d{2})/', $r->item_name, $m ) ) {
                $br = ( $m[1] === '00:00' ) ? '-' : $m[1];
            }
        }

        preg_match_all( '/備考:\s*([^|]+)/', $r->item_name, $matches );
        $can_edit = ! $is_holiday && mat_get_setting( 'allow_log_edit', false ) && mat_is_in_current_period( date( 'Y-m-d', $ts ) );
        $date_ymd = substr( $r->timestamp, 0, 10 );

        $logs[] = array(
            'id'         => (int) $r->id,
            'date'       => $date_label,
            'date_ymd'   => $date_ymd,
            'in'         => $in,
            'out'        => $out,
            'break'      => $br,
            'paid_leave' => ! empty( $r->paid_leave_date ) ? $r->paid_leave_date : '-',
            'notes'      => $matches[1] ?? array(),
            'can_edit'   => $can_edit,
            'is_holiday' => $is_holiday,
            'is_empty'   => ! $parsed['has_meaningful_data'],
        );
    }
    return array(
        'logs'            => $logs,
        'work_days_count' => $work_days_count,
        'total_days'      => (int) date( 't', strtotime( $month . '-01' ) ),
        'today_ymd'       => current_time( 'Y-m-d' ),
    );
}