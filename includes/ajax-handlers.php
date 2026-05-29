<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ajax-handlers.php  v3.1.1
 *
 * 変更点:
 * - 【フロント側バグ修正】備考が先入れされている状態で出勤・退勤・休憩ボタンが押された際、
 * 既存の備考（note）が空で上書きされて消滅しないようサーバー側で既存データを保護・マージするロジックを実装。
 */

// =========================================================
//  ヘルパー：締め日を考慮した「現在期間」判定
// =========================================================

function mat_is_in_current_period( $date_ymd ) {
    $closing = (int) mat_get_setting( 'closing_day', 0 );
    $today   = current_time( 'Y-m-d' );

    if ( $closing === 0 ) {
        // 末日締め → 今月のみ編集可
        return substr( $date_ymd, 0, 7 ) === substr( $today, 0, 7 );
    }

    // 締め日締め → 前回締め日翌日〜今回締め日まで
    $y = (int) date( 'Y', strtotime( $today ) );
    $m = (int) date( 'm', strtotime( $today ) );
    $d = (int) date( 'd', strtotime( $today ) );

    if ( $d <= $closing ) {
        // 今月の締め日前 → 先月締め日翌日〜今月締め日
        $prev_m = $m === 1 ? 12 : $m - 1;
        $prev_y = $m === 1 ? $y - 1 : $y;
        $period_start = sprintf( '%04d-%02d-%02d', $prev_y, $prev_m, $closing + 1 );
        $period_end   = sprintf( '%04d-%02d-%02d', $y, $m, $closing );
    } else {
        // 今月の締め日後 → 今月締め日翌日〜来月締め日
        $next_m = $m === 12 ? 1 : $m + 1;
        $next_y = $m === 12 ? $y + 1 : $y;
        $period_start = sprintf( '%04d-%02d-%02d', $y, $m, $closing + 1 );
        $period_end   = sprintf( '%04d-%02d-%02d', $next_y, $next_m, $closing );
    }

    return $date_ymd >= $period_start && $date_ymd <= $period_end;
}

// =========================================================
//  ヘルパー：分 ⇔ HH:MM 変換
// =========================================================

/**
 * "HH:MM" を分数（int）に変換。不正な値は null を返す。
 */
function mat_hhmm_to_minutes( $hhmm ) {
    if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $hhmm ), $m ) ) return null;
    $minutes = (int) $m[1] * 60 + (int) $m[2];
    return $minutes > 0 ? $minutes : null;
}

/**
 * 分数（int）を "HH:MM" に変換。null は null のまま返す。
 */
function mat_minutes_to_hhmm( $minutes ) {
    if ( is_null( $minutes ) || (int) $minutes <= 0 ) return null;
    $min = (int) $minutes;
    return sprintf( '%02d:%02d', intdiv( $min, 60 ), $min % 60 );
}

// =========================================================
//  ヘルパー：新テーブルから今日のレコード取得
// =========================================================

function mat_get_today_row( $emp_master_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . MAT_DAILY_TABLE . " WHERE employee_id = %d AND work_date = %s",
        $emp_master_id,
        current_time( 'Y-m-d' )
    ) );
}

// =========================================================
//  ヘルパー：新テーブルから指定日のレコード取得
// =========================================================

function mat_get_date_row( $emp_master_id, $date_ymd ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . MAT_DAILY_TABLE . " WHERE employee_id = %d AND work_date = %s",
        $emp_master_id,
        $date_ymd
    ) );
}

// =========================================================
//  ヘルパー：本日の打刻状態（新テーブル版）
// =========================================================

function mat_get_today_punch_status( $emp_master_id ) {
    $row = mat_get_today_row( $emp_master_id );
    if ( ! $row ) {
        return array(
            'is_holiday'      => false,
            'has_clockin'     => false,
            'has_clockout'    => false,
            'has_break_time'  => false,
            'has_meaningful_data' => false,
            'has_notes'       => false,
        );
    }
    return array(
        'is_holiday'          => (bool) $row->is_holiday,
        'has_clockin'         => ! is_null( $row->clock_in ),
        'has_clockout'        => ! is_null( $row->clock_out ),
        'has_break_time'      => ! is_null( $row->break_minutes ) && (int) $row->break_minutes > 0,
        'has_meaningful_data' => true,
        'has_notes'           => ! is_null( $row->note ) && trim( $row->note ) !== '',
    );
}

// =========================================================
//  ヘルパー：月の全日付を生成し、新テーブルのデータをマージ
// =========================================================

function mat_get_grouped_data( $emp_master_id, $month = null ) {
    global $wpdb;
    if ( ! $month ) $month = current_time( 'Y-m' );

    // 新テーブルから該当月のデータを取得
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM " . MAT_DAILY_TABLE . " WHERE employee_id = %d AND work_date LIKE %s ORDER BY work_date ASC",
        $emp_master_id,
        $month . '%'
    ) );

    // work_date → レコード のインデックスを作成
    $row_by_date = array();
    foreach ( $rows as $r ) {
        $row_by_date[ $r->work_date ] = $r;
    }

    // 月の全日付を生成
    list( $year, $mon ) = explode( '-', $month );
    $days_in_month = (int) date( 't', mktime( 0, 0, 0, (int) $mon, 1, (int) $year ) );

    $dow = array( '日', '月', '火', '水', '木', '金', '土' );
    $logs            = array();
    $work_days_count = 0;

    for ( $d = 1; $d <= $days_in_month; $d++ ) {
        $ymd        = sprintf( '%s-%02d', $month, $d );
        $ts         = strtotime( $ymd );
        $date_label = date( 'm/d', $ts ) . '(' . $dow[ date( 'w', $ts ) ] . ')';
        $r          = $row_by_date[ $ymd ] ?? null;

        if ( ! $r ) {
            // データなし → 空行（JS側で '-' 表示）
            $logs[] = array(
                'id'         => 0,
                'date'       => $date_label,
                'date_ymd'   => $ymd,
                'in'         => null,
                'out'        => null,
                'break'      => null,
                'notes'      => array(),
                'paid_leave' => null,
                'is_holiday' => false,
                'can_edit'   => false,
                'has_data'   => false,
            );
            continue;
        }

        $is_holiday = (bool) $r->is_holiday;

        if ( $is_holiday ) {
            $in    = '休日';
            $out   = null;
            $break = null;
        } else {
            // TIME型は "HH:MM:SS" で返るので先頭5文字
            $in    = $r->clock_in    ? substr( $r->clock_in,  0, 5 ) : null;
            $out   = $r->clock_out   ? substr( $r->clock_out, 0, 5 ) : null;
            $break = mat_minutes_to_hhmm( $r->break_minutes );
            if ( $in ) $work_days_count++;
        }

        $can_edit = ! $is_holiday
            && mat_get_setting( 'allow_log_edit', false )
            && mat_is_in_current_period( $ymd );

        $logs[] = array(
            'id'         => (int) $r->id,
            'date'       => $date_label,
            'date_ymd'   => $ymd,
            'in'         => $in,
            'out'        => $out,
            'break'      => $break,
            'notes'      => $r->note ? array( $r->note ) : array(),
            'paid_leave' => null,
            'is_holiday' => $is_holiday,
            'can_edit'   => $can_edit,
            'has_data'   => true,
        );
    }

    return array(
        'logs'            => $logs,
        'work_days_count' => $work_days_count,
        'total_days'      => $days_in_month,
    );
}

// =========================================================
//  後方互換：item_name パーサー（旧テーブル参照箇所が残る場合に備えて維持）
// =========================================================

function mat_parse_attendance_item_name( $item_name ) {
    $item         = trim( (string) $item_name );
    $is_holiday   = ( $item === '休日' );
    $has_clockin  = (bool) preg_match( '/出勤:\s*(\d{2}:\d{2})/', $item );
    $has_clockout = (bool) preg_match( '/退勤:\s*(\d{2}:\d{2})/', $item );
    $has_break    = (bool) preg_match( '/休憩:\s*(\d{2}:\d{2})/', $item, $br_m );
    $has_break_time = $has_break && isset( $br_m[1] ) && $br_m[1] !== '00:00';
    preg_match_all( '/備考:\s*([^|]+)/', $item, $notes_m );
    $has_notes = false;
    foreach ( $notes_m[1] ?? array() as $note ) {
        if ( trim( $note ) !== '' ) { $has_notes = true; break; }
    }
    return array(
        'is_holiday'          => $is_holiday,
        'has_clockin'         => $has_clockin,
        'has_clockout'        => $has_clockout,
        'has_break'           => $has_break,
        'has_break_time'      => $has_break_time,
        'has_notes'           => $has_notes,
        'has_meaningful_data' => $is_holiday || $has_clockin || $has_clockout || $has_break_time || $has_notes,
    );
}

// =========================================================
//  1. 認証系（変更なし）
// =========================================================

add_action( 'wp_ajax_mat_check_employee',        'mat_check_employee_handler' );
add_action( 'wp_ajax_nopriv_mat_check_employee', 'mat_check_employee_handler' );
function mat_check_employee_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $code = sanitize_text_field( $_POST['employee_code'] ?? '' );
    $emp  = emp_get_employee_by_code( $code );
    if ( ! $emp ) wp_send_json_error( '社員コードが見つかりません。' );

    global $wpdb;
    $auth = $wpdb->get_row( $wpdb->prepare(
        "SELECT is_registered FROM " . MAT_AUTH_TABLE . " WHERE employee_code = %s", $code
    ) );

    if ( ! mat_get_setting( 'use_password_auth', true ) ) {
        wp_send_json_success( array( 'status' => 'logged_in', 'emp_master_id' => (int) $emp->id, 'employee_code' => $emp->employee_code, 'user_name' => $emp->name ) );
        return;
    }

    if ( ! $auth || ! $auth->is_registered ) {
        wp_send_json_success( array( 'status' => 'needs_setup', 'emp_master_id' => (int) $emp->id, 'employee_code' => $emp->employee_code, 'user_name' => $emp->name ) );
    } else {
        wp_send_json_success( array( 'status' => 'needs_password' ) );
    }
}

add_action( 'wp_ajax_mat_setup_password',        'mat_setup_password_handler' );
add_action( 'wp_ajax_nopriv_mat_setup_password', 'mat_setup_password_handler' );
function mat_setup_password_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $code     = sanitize_text_field( $_POST['employee_code'] ?? '' );
    $password = $_POST['password'] ?? '';
    if ( strlen( $password ) < 4 ) wp_send_json_error( 'パスワードは4文字以上で設定してください。' );
    $emp = emp_get_employee_by_code( $code );
    if ( ! $emp ) wp_send_json_error( '社員情報が見つかりません。' );
    global $wpdb;
    $hash = password_hash( $password, PASSWORD_DEFAULT );
    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . MAT_AUTH_TABLE . " WHERE employee_code = %s", $code ) );
    if ( $exists ) {
        $wpdb->update( MAT_AUTH_TABLE, array( 'password_hash' => $hash, 'is_registered' => 1 ), array( 'employee_code' => $code ) );
    } else {
        $wpdb->insert( MAT_AUTH_TABLE, array( 'emp_master_id' => $emp->id, 'employee_code' => $code, 'password_hash' => $hash, 'is_registered' => 1 ) );
    }
    wp_send_json_success( array( 'status' => 'logged_in', 'emp_master_id' => (int) $emp->id, 'employee_code' => $emp->employee_code, 'user_name' => $emp->name ) );
}

add_action( 'wp_ajax_mat_verify_password',        'mat_verify_password_handler' );
add_action( 'wp_ajax_nopriv_mat_verify_password', 'mat_verify_password_handler' );
function mat_verify_password_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $code     = sanitize_text_field( $_POST['employee_code'] ?? '' );
    $password = $_POST['password'] ?? '';
    $emp = emp_get_employee_by_code( $code );
    if ( ! $emp ) wp_send_json_error( '認証に失敗しました。' );
    global $wpdb;
    $auth = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . MAT_AUTH_TABLE . " WHERE employee_code = %s", $code ) );

    if ( $auth && $auth->locked_until && strtotime( $auth->locked_until ) > time() ) {
        wp_send_json_error( 'アカウントがロックされています。しばらく経ってから再試行してください。' );
    }

    if ( ! $auth || ! password_verify( $password, $auth->password_hash ) ) {
        if ( $auth ) {
            $fail = (int) $auth->login_failed_count + 1;
            $locked = $fail >= 5 ? date( 'Y-m-d H:i:s', strtotime( '+30 minutes' ) ) : null;
            $wpdb->update( MAT_AUTH_TABLE, array( 'login_failed_count' => $fail, 'locked_until' => $locked ), array( 'employee_code' => $code ) );
        }
        wp_send_json_error( 'パスワードが違います。' );
    }

    $wpdb->update( MAT_AUTH_TABLE, array( 'login_failed_count' => 0, 'locked_until' => null ), array( 'employee_code' => $code ) );
    wp_send_json_success( array( 'status' => 'logged_in', 'emp_master_id' => (int) $emp->id, 'employee_code' => $emp->employee_code, 'user_name' => $emp->name ) );
}

add_action( 'wp_ajax_mat_request_password_reset',        'mat_request_password_reset_handler' );
add_action( 'wp_ajax_nopriv_mat_request_password_reset', 'mat_request_password_reset_handler' );
function mat_request_password_reset_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $code = sanitize_text_field( $_POST['employee_code'] ?? '' );
    $emp  = emp_get_employee_by_code( $code );
    if ( ! $emp ) {
        wp_send_json_success( array( 'message' => '管理者へリセットを依頼してください。' ) );
        return;
    }
    global $wpdb;
    $wpdb->update( MAT_AUTH_TABLE, array( 'reset_token' => bin2hex( random_bytes( 16 ) ) ), array( 'employee_code' => $code ) );
    wp_send_json_success( array( 'message' => 'リセット申請を送信しました。管理者が対応するまでお待ちください。' ) );
}

// =========================================================
//  2. 打刻更新（新テーブル版・データ上書き対策適用済み）
// =========================================================

add_action( 'wp_ajax_mat_attendance_update',        'mat_attendance_update_handler' );
add_action( 'wp_ajax_nopriv_mat_attendance_update', 'mat_attendance_update_handler' );
function mat_attendance_update_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    global $wpdb;

    $emp_master_id = intval( $_POST['emp_master_id'] ?? 0 );
    $employee_code = sanitize_text_field( $_POST['employee_code'] ?? '' );
    $label         = sanitize_text_field( $_POST['label'] ?? '' );
    $note_input    = sanitize_textarea_field( $_POST['note'] ?? '' ); // フロント側から送られてくる現在の入力値
    $today         = current_time( 'Y-m-d' );
    $now_time      = current_time( 'H:i:s' );

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp ) wp_send_json_error( '社員情報が見つかりません。' );
    if ( (int) $emp->id !== $emp_master_id ) {
        wp_send_json_error( '社員情報が一致しません。ログアウトしてから再度お試しください。' );
    }

    // 本日の既存レコード取得
    $row = mat_get_today_row( $emp_master_id );

    // 備考欄に何をセットするか決定（バグ対策：既存の備考が存在する場合は消さずに保持・マージ）
    $existing_note = $row ? $row->note : null;
    if ( ! empty( $note_input ) ) {
        // 新しい入力がある場合、既存の備考と重複していなければ結合、空なら新規設定
        if ( ! empty( $existing_note ) ) {
            if ( strpos( $existing_note, $note_input ) === false ) {
                $final_note = $existing_note . ' / ' . $note_input;
            } else {
                $final_note = $existing_note; // すでに含まれているなら既存を維持
            }
        } else {
            $final_note = $note_input;
        }
    } else {
        // フロントから送られた備考が空の場合、既存の備考データをそのまま引き継ぐ（上書き消滅防止）
        $final_note = $existing_note;
    }

    if ( $label === '出勤' ) {
        if ( $row && $row->is_holiday ) {
            wp_send_json_error( '本日は休日として登録されています。' );
        }
        if ( $row && ! is_null( $row->clock_in ) ) {
            wp_send_json_error( '本日はすでに出勤打刻済みです。' );
        }

        if ( $row ) {
            // 【重要】備考先入れ後に一回出勤ボタンが押された場合
            $wpdb->update( MAT_DAILY_TABLE,
                array( 'clock_in' => $now_time, 'note' => $final_note ?: null ),
                array( 'id' => (int) $row->id )
            );
        } else {
            $wpdb->insert( MAT_DAILY_TABLE, array(
                'employee_id'   => $emp_master_id,
                'employee_code' => $employee_code,
                'work_date'     => $today,
                'clock_in'      => $now_time,
                'note'          => $final_note ?: null,
            ) );
        }

    } elseif ( $label === '退勤' ) {
        if ( ! $row || is_null( $row->clock_in ) ) {
            wp_send_json_error( '出勤打刻がありません。先に出勤を打刻してください。' );
        }
        if ( ! is_null( $row->clock_out ) ) {
            wp_send_json_error( '本日はすでに退勤打刻済みです。' );
        }

        $wpdb->update( MAT_DAILY_TABLE,
            array( 'clock_out' => $now_time, 'note' => $final_note ?: null ),
            array( 'id' => (int) $row->id )
        );

    } elseif ( $label === '休憩' ) {
        if ( ! $row || is_null( $row->clock_in ) ) {
            wp_send_json_error( '出勤打刻がありません。先に出勤を打刻してください。' );
        }

        $break_hhmm    = sanitize_text_field( $_POST['break_hhmm'] ?? '00:00' );
        $break_minutes = mat_hhmm_to_minutes( $break_hhmm );
        if ( is_null( $break_minutes ) ) {
            wp_send_json_error( '休憩時間が不正です。' );
        }

        $wpdb->update( MAT_DAILY_TABLE,
            array( 'break_minutes' => $break_minutes, 'note' => $final_note ?: null ),
            array( 'id' => (int) $row->id )
        );

    } elseif ( $label === '備考' ) {
        if ( ! $note_input ) {
            wp_send_json_error( '備考が入力されていません。' );
        }

        if ( $row ) {
            $wpdb->update( MAT_DAILY_TABLE,
                array( 'note' => $note_input ),
                array( 'id' => (int) $row->id )
            );
        } else {
            $wpdb->insert( MAT_DAILY_TABLE, array(
                'employee_id'   => $emp_master_id,
                'employee_code' => $employee_code,
                'work_date'     => $today,
                'note'          => $note_input,
            ) );
        }

        wp_send_json_success( mat_get_grouped_data( $emp_master_id, current_time( 'Y-m' ) ) );
    }

    wp_send_json_success( mat_get_grouped_data( $emp_master_id, current_time( 'Y-m' ) ) );
}

// =========================================================
//  3. 休日登録（新テーブル版）
// =========================================================

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

    $existing = mat_get_date_row( $emp_master_id, $holiday_date );
    if ( $existing ) {
        $ok = $wpdb->update( MAT_DAILY_TABLE,
            array( 'clock_in' => null, 'clock_out' => null, 'break_minutes' => null, 'is_holiday' => 1, 'note' => null ),
            array( 'id' => (int) $existing->id )
        );
    } else {
        $ok = $wpdb->insert( MAT_DAILY_TABLE, array(
            'employee_id'   => $emp_master_id,
            'employee_code' => $employee_code,
            'work_date'     => $holiday_date,
            'is_holiday'    => 1,
        ) );
    }

    if ( $ok === false ) {
        wp_send_json_error( '休日の登録に失敗しました。管理者にお問い合わせください。' );
    }

    wp_send_json_success( mat_get_grouped_data( $emp_master_id, substr( $holiday_date, 0, 7 ) ) );
}

// =========================================================
//  4. 打刻削除（新テーブル版）
// =========================================================

add_action( 'wp_ajax_mat_delete_log',        'mat_delete_log_handler' );
add_action( 'wp_ajax_nopriv_mat_delete_log', 'mat_delete_log_handler' );
function mat_delete_log_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    global $wpdb;

    $id            = intval( $_POST['id'] );
    $emp_master_id = intval( $_POST['emp_master_id'] );

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . MAT_DAILY_TABLE . " WHERE id = %d AND employee_id = %d",
        $id, $emp_master_id
    ) );
    if ( ! $row ) wp_send_json_error( 'データが見つかりません。' );
    if ( ! mat_is_in_current_period( $row->work_date ) ) {
        wp_send_json_error( '確定済みの過去データは削除できません。' );
    }

    $wpdb->delete( MAT_DAILY_TABLE, array( 'id' => $id, 'employee_id' => $emp_master_id ) );
    wp_send_json_success();
}

// =========================================================
//  5. ログ取得（新テーブル版・全日付）
// =========================================================

add_action( 'wp_ajax_mat_get_logs',        'mat_get_logs_handler' );
add_action( 'wp_ajax_nopriv_mat_get_logs', 'mat_get_logs_handler' );
function mat_get_logs_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $emp_id = intval( $_POST['emp_master_id'] );
    $month  = sanitize_text_field( $_POST['month'] ?? current_time( 'Y-m' ) );
    wp_send_json_success( mat_get_grouped_data( $emp_id, $month ) );
}

// =========================================================
//  6. 本日の打刻状態（ボタン制御用）
// =========================================================

add_action( 'wp_ajax_mat_get_today_status',        'mat_get_today_status_handler' );
add_action( 'wp_ajax_nopriv_mat_get_today_status', 'mat_get_today_status_handler' );
function mat_get_today_status_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    $emp_id = intval( $_POST['emp_master_id'] ?? 0 );
    if ( ! $emp_id ) wp_send_json_error( '社員情報が不正です。' );
    $status = mat_get_today_punch_status( $emp_id );
    $status['today_ymd'] = current_time( 'Y-m-d' );
    wp_send_json_success( $status );
}

// =========================================================
//  7. ユーザーによる打刻編集（新テーブル版）
// =========================================================

add_action( 'wp_ajax_mat_edit_log',        'mat_edit_log_handler' );
add_action( 'wp_ajax_nopriv_mat_edit_log', 'mat_edit_log_handler' );
function mat_edit_log_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    if ( ! mat_get_setting( 'allow_log_edit', false ) ) wp_send_json_error( '編集は許可されていません。' );

    global $wpdb;
    $id     = intval( $_POST['id'] );
    $emp_id = intval( $_POST['emp_master_id'] );

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . MAT_DAILY_TABLE . " WHERE id = %d AND employee_id = %d",
        $id, $emp_id
    ) );
    if ( ! $row || ! mat_is_in_current_period( $row->work_date ) ) {
        wp_send_json_error( '編集できないデータです。' );
    }

    $clock_in      = sanitize_text_field( $_POST['clock_in']  ?? '' );
    $clock_out     = sanitize_text_field( $_POST['clock_out'] ?? '' );
    $break_hhmm    = sanitize_text_field( $_POST['break_time'] ?? '00:00' );
    $note          = sanitize_textarea_field( $_POST['note'] ?? '' );
    $break_minutes = mat_hhmm_to_minutes( $break_hhmm );

    $wpdb->update( MAT_DAILY_TABLE, array(
        'clock_in'      => $clock_in  ?: null,
        'clock_out'     => $clock_out ?: null,
        'break_minutes' => $break_minutes,
        'note'          => $note ?: null,
    ), array( 'id' => $id ) );

    wp_send_json_success();
}

// =========================================================
//  備考のみ登録（上書き保存）新テーブル版
// =========================================================

add_action( 'wp_ajax_mat_save_note',        'mat_save_note_handler' );
add_action( 'wp_ajax_nopriv_mat_save_note', 'mat_save_note_handler' );
function mat_save_note_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    global $wpdb;

    $emp_master_id = intval( $_POST['emp_master_id'] ?? 0 );
    $employee_code = sanitize_text_field( $_POST['employee_code'] ?? '' );
    $note          = sanitize_textarea_field( $_POST['note'] ?? '' );
    $today         = current_time( 'Y-m-d' );

    if ( ! $emp_master_id || $employee_code === '' ) {
        wp_send_json_error( '社員情報が不正です。ログアウトしてから再度お試しください。' );
    }
    if ( trim( $note ) === '' ) {
        wp_send_json_error( '備考を入力してください。' );
    }

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp ) {
        wp_send_json_error( '社員情報が見つかりません。' );
    }
    if ( (int) $emp->id !== $emp_master_id ) {
        wp_send_json_error( '社員情報が一致しません。ログアウトしてから再度お試しください。' );
    }

    $row = mat_get_today_row( $emp_master_id );

    if ( $row && $row->is_holiday ) {
        wp_send_json_error( '本日は休日として登録されています。' );
    }

    if ( $row ) {
        $updated = $wpdb->update(
            MAT_DAILY_TABLE,
            array( 'note' => $note ),
            array( 'id' => (int) $row->id ),
            array( '%s' ),
            array( '%d' )
        );
        if ( $updated === false ) {
            wp_send_json_error( '備考の保存に失敗しました。管理者にお問い合わせください。' );
        }
    } else {
        $inserted = $wpdb->insert(
            MAT_DAILY_TABLE,
            array(
                'employee_id'   => $emp_master_id,
                'employee_code' => $employee_code,
                'work_date'     => $today,
                'note'          => $note,
            ),
            array( '%d', '%s', '%s', '%s' )
        );
        if ( ! $inserted ) {
            wp_send_json_error( '備考の保存に失敗しました。管理者にお問い合わせください。' );
        }
    }

    wp_send_json_success( mat_get_grouped_data( $emp_master_id, current_time( 'Y-m' ) ) );
}

// =========================================================
//  8. 有給申請（paid-leave-manager 連携・変更なし）
// =========================================================

add_action( 'wp_ajax_mat_submit_paid_leave',        'mat_submit_paid_leave_handler' );
add_action( 'wp_ajax_nopriv_mat_submit_paid_leave', 'mat_submit_paid_leave_handler' );
function mat_submit_paid_leave_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    if ( ! class_exists( 'PL_Request' ) ) wp_send_json_error( '有給管理システムが未稼働です。' );

    $code = sanitize_text_field( $_POST['employee_code'] );
    $date = sanitize_text_field( $_POST['paid_leave_date'] );
    $res  = PL_Request::create( $code, $date, '勤怠ツールからの申請' );

    if ( is_wp_error( $res ) ) wp_send_json_error( $res->get_error_message() );
    wp_send_json_success( mat_get_paid_leave_list( $code ) );
}

add_action( 'wp_ajax_mat_get_paid_leave_requests',        'mat_get_paid_leave_requests_handler' );
add_action( 'wp_ajax_nopriv_mat_get_paid_leave_requests', 'mat_get_paid_leave_requests_handler' );
function mat_get_paid_leave_requests_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );
    wp_send_json_success( mat_get_paid_leave_list( sanitize_text_field( $_POST['employee_code'] ) ) );
}

function mat_get_paid_leave_list( $employee_code ) {
    global $wpdb;
    $table = $wpdb->prefix . 'paidleave_requests';
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, request_date, status, created_at FROM {$table} WHERE employee_code = %s ORDER BY created_at DESC LIMIT 10",
        $employee_code
    ) );
    $map  = array( 'pending' => '申請中', 'approved' => '受理済み', 'rejected' => '却下' );
    $list = array();
    foreach ( $rows as $r ) {
        $list[] = array(
            'request_date'    => date( 'Y/m/d', strtotime( $r->created_at ) ),
            'paid_leave_date' => date( 'Y/m/d', strtotime( $r->request_date ) ),
            'status'          => $map[ $r->status ] ?? $r->status,
            'status_key'      => $r->status,
        );
    }
    return array( 'requests' => $list );
}