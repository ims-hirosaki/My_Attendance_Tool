<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ajax-handlers.php  v3.2.0
 *
 * 変更点（v3.2.0）:
 * - 出勤／退勤打刻時に丸め込み後の始業・終業（rounded_clock_in / rounded_clock_out）を同時保存。
 * - 日跨ぎ退勤（前日行への 25:10:00 形式での保存）に対応。
 * - 休憩マスタ連動（break_master_id）と、例外休憩・残業の申請登録に対応。
 * - 退勤前の判定情報を返す mat_prepare_clockout を追加。
 *
 * 変更点（v3.1.1）:
 * - 【フロント側バグ修正】備考が先入れされている状態で出勤・退勤・休憩ボタンが押された際、
 * 既存の備考（note）が空で上書きされて消滅しないようサーバー側で既存データを保護・マージするロジックを実装。
 */

// =========================================================
//  ヘルパー：締め日を考慮した「現在期間」判定
// =========================================================

function mat_is_in_current_period( $date_ymd ) {
    $period = mat_get_current_period();

    return $date_ymd >= $period['start'] && $date_ymd <= $period['end'];
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

    // 日跨ぎ退勤の待機状態（当日に出勤なし／前日が退勤未済／正午以前）。
    // この場合は当日に出勤打刻が無くても退勤ボタンを押せるようにする（§5.3①）。
    $pending_overnight = false;
    if ( ! $row || is_null( $row->clock_in ) ) {
        $prev = mat_get_date_row( $emp_master_id, date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 day' ) ) );
        $pending_overnight = $prev
            && ! is_null( $prev->clock_in )
            && is_null( $prev->clock_out )
            && mat_parse_time_to_minutes( current_time( 'H:i' ) ) <= 720;
    }

    if ( ! $row ) {
        return array(
            'is_holiday'      => false,
            'has_clockin'     => false,
            'has_clockout'    => false,
            'has_break_time'  => false,
            'has_meaningful_data' => false,
            'has_notes'       => false,
            'pending_overnight' => $pending_overnight,
        );
    }
    return array(
        'is_holiday'          => (bool) $row->is_holiday,
        'has_clockin'         => ! is_null( $row->clock_in ),
        'has_clockout'        => ! is_null( $row->clock_out ),
        'has_break_time'      => ! is_null( $row->break_minutes ) && (int) $row->break_minutes > 0,
        'has_meaningful_data' => true,
        'has_notes'           => ! is_null( $row->note ) && trim( $row->note ) !== '',
        'pending_overnight'   => $pending_overnight,
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
                'id'           => 0,
                'date'         => $date_label,
                'date_ymd'     => $ymd,
                'in'           => null,
                'out'          => null,
                'break'        => null,
                'rounded_in'   => null,
                'rounded_out'  => null,
                'overtime'     => null,
                'is_overnight' => false,
                'midnight'     => null,
                'midnight_unconfirmed' => false,
                'midnight_break_minutes' => null,
                'has_break_out' => false,
                'break_out_start' => '',
                'break_out_end'   => '',
                'notes'        => array(),
                'paid_leave'   => null,
                'is_holiday'   => false,
                'can_edit'     => false,
                'has_data'     => false,
                'long_distance' => false,
            );
            continue;
        }

        $is_holiday = (bool) $r->is_holiday;

        $rounded_in   = null;
        $rounded_out  = null;
        $overtime     = null;
        $is_overnight = false;
        $midnight_display     = null;
        $midnight_unconfirmed = false;
        $has_break_out        = false;

        if ( $is_holiday ) {
            $in    = '休日';
            $out   = null;
            $break = null;
        } else {
            // TIME型は "HH:MM:SS" で返るので先頭5文字。24時間超（25:10）もそのまま表示する。
            $in    = $r->clock_in  ? mat_format_time_display( $r->clock_in )  : null;
            $break = mat_minutes_to_hhmm( $r->break_minutes );

            // 実打刻の退勤：日跨ぎは 24時間超表記に変換（§5.4）
            $out_min = mat_parse_time_to_minutes( $r->clock_out );
            $in_min  = mat_parse_time_to_minutes( $r->clock_in );
            if ( $out_min !== null && $in_min !== null && $out_min <= $in_min ) $out_min += 1440;
            $out = $out_min === null ? null : mat_minutes_to_hm( $out_min );

            $is_overnight = ! empty( $r->is_overnight ) || ( $out_min !== null && $out_min >= 1440 );

            $rounded_in  = mat_format_time_display( $r->rounded_clock_in  ?? null ) ?: null;
            $rounded_out = mat_format_time_display( $r->rounded_clock_out ?? null ) ?: null;

            $calc     = mat_calc_work_minutes(
                $r->rounded_clock_in  ?? $r->clock_in,
                $r->rounded_clock_out ?? $r->clock_out,
                $r->break_minutes,
                $r->break_out_start ?? null,
                $r->break_out_end   ?? null
            );
            $overtime = $calc['overtime'];

            // 深夜（要件定義書 §6.8）：未確認（NULL）かつ該当時間ありの行はマークを付与する
            $midnight_minutes_val = isset( $r->midnight_minutes )      ? (int) $r->midnight_minutes      : null;
            $midnight_span_val    = isset( $r->midnight_span_minutes ) ? (int) $r->midnight_span_minutes : null;
            $midnight_unconfirmed = ( $midnight_span_val !== null && $midnight_span_val > 0 && $r->midnight_break_minutes === null );
            $midnight_display     = ( $midnight_minutes_val !== null && $midnight_minutes_val > 0 ) ? mat_minutes_to_hm( $midnight_minutes_val ) : null;
            // 中抜け（要件定義書 §12.4）：管理者専用項目。識別マークは日付／退勤セルに付与する
            $has_break_out      = ! empty( $r->break_out_start ) && ! empty( $r->break_out_end );
            $break_out_start_hm = $has_break_out ? mat_format_time_display( $r->break_out_start ) : '';
            $break_out_end_hm   = $has_break_out ? mat_format_time_display( $r->break_out_end )   : '';

            if ( $in ) $work_days_count++;
        }

        $can_edit = ! $is_holiday
            && mat_get_setting( 'allow_log_edit', false )
            && mat_is_in_current_period( $ymd );

        $logs[] = array(
            'id'           => (int) $r->id,
            'date'         => $date_label,
            'date_ymd'     => $ymd,
            'in'           => $in,
            'out'          => $out,
            'break'        => $break,
            'rounded_in'   => $rounded_in,
            'rounded_out'  => $rounded_out,
            'overtime'     => $overtime ? mat_minutes_to_hm( $overtime ) : null,
            'is_overnight' => $is_overnight,
            'midnight'     => $midnight_display,
            'midnight_unconfirmed' => $midnight_unconfirmed,
            'midnight_break_minutes' => isset( $r->midnight_break_minutes ) ? (int) $r->midnight_break_minutes : null,
            'has_break_out' => $has_break_out,
            'break_out_start' => $break_out_start_hm,
            'break_out_end'   => $break_out_end_hm,
            'notes'        => $r->note ? array( $r->note ) : array(),
            'paid_leave'   => null,
            'is_holiday'   => $is_holiday,
            'can_edit'     => $can_edit,
            'has_data'     => true,
            'long_distance' => ! empty( $r->long_distance ),
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
//  2-0. 退勤前の判定（日跨ぎ／例外休憩／残業ポップアップ用）
//       要件定義書 §5.3
// =========================================================

/**
 * 退勤対象の行と退勤時刻（分）を決定する。
 *
 * @return array|WP_Error array( 'row' => object, 'clock_out_min' => int, 'is_overnight' => bool )
 */
function mat_resolve_clockout_target( $emp_master_id, $override_hhmm = '', $forced_date = '' ) {
    $today    = current_time( 'Y-m-d' );
    $now_min  = mat_parse_time_to_minutes( current_time( 'H:i' ) );
    $prev_ymd = date( 'Y-m-d', strtotime( $today . ' -1 day' ) );

    $today_row = mat_get_date_row( $emp_master_id, $today );
    $prev_row  = mat_get_date_row( $emp_master_id, $prev_ymd );

    // 日跨ぎ判定：当日に出勤なし／前日が出勤済み・退勤未済／正午以前（§5.3①）
    $is_overnight_case = ( ! $today_row || is_null( $today_row->clock_in ) )
        && $prev_row && ! is_null( $prev_row->clock_in ) && is_null( $prev_row->clock_out )
        && $now_min <= 720;

    // フロントで「前日の退勤として登録」を選択済みの場合は日付指定で確定させる
    if ( $forced_date === $prev_ymd && $prev_row && is_null( $prev_row->clock_out ) ) {
        $is_overnight_case = true;
    }

    if ( $is_overnight_case ) {
        $row          = $prev_row;
        $is_overnight = true;
    } else {
        if ( ! $today_row || is_null( $today_row->clock_in ) ) {
            return new WP_Error( 'no_clockin', '出勤打刻がありません。先に出勤を打刻してください。' );
        }
        if ( ! is_null( $today_row->clock_out ) ) {
            return new WP_Error( 'already', '本日はすでに退勤打刻済みです。' );
        }
        $row          = $today_row;
        $is_overnight = false;
    }

    // 退勤時刻（前日行に保存する場合は前日 0:00 起点の経過分）
    if ( $override_hhmm !== '' ) {
        $clock_out_min = mat_parse_time_to_minutes( $override_hhmm );
        if ( $clock_out_min === null ) {
            return new WP_Error( 'bad_time', '退勤時刻の形式が正しくありません。' );
        }
    } else {
        $clock_out_min = $is_overnight ? $now_min + 1440 : $now_min;
    }

    $in_min = mat_parse_time_to_minutes( $row->clock_in );
    if ( $in_min !== null && $clock_out_min <= $in_min ) {
        // 手入力で出勤より前になった場合は翌日扱いとする
        $clock_out_min += 1440;
        $is_overnight   = true;
    }
    if ( $clock_out_min >= 1440 ) $is_overnight = true;

    return array(
        'row'           => $row,
        'clock_out_min' => $clock_out_min,
        'is_overnight'  => $is_overnight,
        'prev_ymd'      => $prev_ymd,
        'today_ymd'     => $today,
        'overnight_candidate' => $is_overnight_case,
    );
}

add_action( 'wp_ajax_mat_prepare_clockout',        'mat_prepare_clockout_handler' );
add_action( 'wp_ajax_nopriv_mat_prepare_clockout', 'mat_prepare_clockout_handler' );
function mat_prepare_clockout_handler() {
    check_ajax_referer( 'mat_nonce', 'nonce' );

    $emp_master_id   = intval( $_POST['emp_master_id'] ?? 0 );
    $employee_code   = sanitize_text_field( $_POST['employee_code'] ?? '' );
    $break_master_id = intval( $_POST['break_master_id'] ?? 0 );
    $override        = sanitize_text_field( $_POST['clock_out_override'] ?? '' );
    $forced_date     = sanitize_text_field( $_POST['target_date'] ?? '' );

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp || (int) $emp->id !== $emp_master_id ) {
        wp_send_json_error( '社員情報が一致しません。ログアウトしてから再度お試しください。' );
    }

    $target = mat_resolve_clockout_target( $emp_master_id, $override, $forced_date );
    if ( is_wp_error( $target ) ) wp_send_json_error( $target->get_error_message() );

    $row  = $target['row'];
    $unit = ! empty( $row->time_unit ) ? (int) $row->time_unit : mat_get_time_unit();

    // 休憩：スライダーの選択を優先、なければ登録済みの値、それもなければ既定行
    $break_master = $break_master_id ? mat_get_break_master_by_id( $break_master_id ) : null;
    if ( $break_master && ! $break_master->is_active ) $break_master = null;

    if ( $break_master ) {
        $break_minutes = (int) $break_master->break_minutes;
    } elseif ( ! is_null( $row->break_minutes ) ) {
        $break_minutes = (int) $row->break_minutes;
        $break_master  = $row->break_master_id ? mat_get_break_master_by_id( $row->break_master_id ) : null;
    } else {
        $break_master  = mat_get_default_break_master();
        $break_minutes = $break_master ? (int) $break_master->break_minutes : 0;
    }

    $rounded_in_min  = mat_round_in_minutes( mat_parse_time_to_minutes( $row->clock_in ), $unit );
    $rounded_out_min = mat_round_out_minutes( $target['clock_out_min'], $unit );

    $rounded_in_time  = mat_minutes_to_time_sql( $rounded_in_min );
    $rounded_out_time = mat_minutes_to_time_sql( $rounded_out_min );

    $calc     = mat_calc_work_minutes( $rounded_in_time, $rounded_out_time, $break_minutes, $row->break_out_start ?? null, $row->break_out_end ?? null );
    $standard_row     = mat_get_break_alert_mode() === 'auto'
        ? ( mat_get_auto_break_master( $calc['kousoku'] ) ?: mat_get_default_break_master() )
        : mat_get_default_break_master();
    $standard_minutes = mat_get_standard_break_minutes( $calc['kousoku'] );

    // 深夜該当時間の判定（要件定義書 §6.2・§6.7）。まだ深夜休憩の回答が無い日のみポップアップ④の対象とする。
    $midnight_span          = mat_calc_midnight_span_minutes( $rounded_in_time, $rounded_out_time, $row->break_out_start ?? null, $row->break_out_end ?? null );
    $midnight_window        = mat_get_midnight_window();
    $midnight_window_label  = mat_minutes_to_hm( $midnight_window['start'] ) . ' 〜 ' . mat_minutes_to_hm( $midnight_window['end'] );
    $needs_midnight_confirm = ! empty( $midnight_span ) && empty( $row->is_holiday ) && is_null( $row->midnight_break_minutes );

    $dow = array( '日', '月', '火', '水', '木', '金', '土' );
    $ts  = strtotime( $row->work_date );

    wp_send_json_success( array(
        'target_date'          => $row->work_date,
        'target_date_label'    => (int) date( 'n', $ts ) . '月' . (int) date( 'j', $ts ) . '日(' . $dow[ date( 'w', $ts ) ] . ')',
        'is_overnight'         => (bool) $target['is_overnight'],
        // 日跨ぎ確認ポップアップは「前日行に切り替わる」ケースかつ未確定のときのみ
        'needs_overnight_confirm' => ( $target['overnight_candidate'] && $forced_date !== $row->work_date ),
        'clock_in'             => mat_format_time_display( $row->clock_in ),
        'clock_out'            => mat_minutes_to_hm( $target['clock_out_min'] ),
        'rounded_in'           => mat_minutes_to_hm( $rounded_in_min ),
        'rounded_out'          => mat_minutes_to_hm( $rounded_out_min ),
        'break_minutes'        => $break_minutes,
        'break_master_id'      => $break_master ? (int) $break_master->id : 0,
        'standard_break'       => $standard_minutes,
        'standard_master_id'   => $standard_row ? (int) $standard_row->id : 0,
        'standard_label'       => $standard_row ? $standard_row->label : '',
        'kousoku_minutes'      => $calc['kousoku'],
        'labor_minutes'        => $calc['labor'],
        'labor_text'           => mat_format_minutes_jp( $calc['labor'] ),
        'overtime_minutes'     => $calc['overtime'],
        'overtime_text'        => mat_format_minutes_jp( $calc['overtime'] ),
        'needs_break_confirm'  => ( $standard_minutes !== null && $break_minutes !== (int) $standard_minutes ),
        'needs_overtime_confirm' => ( ! empty( $calc['overtime'] ) && $calc['overtime'] > 0 ),
        'needs_midnight_confirm' => $needs_midnight_confirm,
        'midnight_span'          => $midnight_span,
        'midnight_span_text'     => mat_format_minutes_jp_padded( $midnight_span ),
        'midnight_window_label'  => $midnight_window_label,
    ) );
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
    $long_distance = ( ( $_POST['long_distance'] ?? '0' ) === '1' ) ? 1 : 0;
    $today         = current_time( 'Y-m-d' );
    $now_time      = current_time( 'H:i:s' );
    $time_unit     = mat_get_time_unit();

    $emp = emp_get_employee_by_code( $employee_code );
    if ( ! $emp ) wp_send_json_error( '社員情報が見つかりません。' );
    if ( (int) $emp->id !== $emp_master_id ) {
        wp_send_json_error( '社員情報が一致しません。ログアウトしてから再度お試しください。' );
    }

    // 本日の既存レコード取得
    $row = mat_get_today_row( $emp_master_id );

    if ( $label === '出勤' ) {
        if ( $row && $row->is_holiday ) {
            wp_send_json_error( '本日は休日として登録されています。' );
        }
        if ( $row && ! is_null( $row->clock_in ) ) {
            wp_send_json_error( '本日はすでに出勤打刻済みです。' );
        }

        // 丸め込み（繰り上げ）した始業を同時保存（§5.1）
        $rounded_in = mat_round_clock_in( $now_time, $time_unit );

        if ( $row ) {
            // 【重要】備考先入れ後に一回出勤ボタンが押された場合
            $wpdb->update( MAT_DAILY_TABLE,
                array(
                    'clock_in'         => $now_time,
                    'rounded_clock_in' => $rounded_in,
                    'time_unit'        => $time_unit,
                    'long_distance'    => ( ! empty( $row->long_distance ) || $long_distance ) ? 1 : 0,
                ),
                array( 'id' => (int) $row->id )
            );
        } else {
            $wpdb->insert( MAT_DAILY_TABLE, array(
                'employee_id'      => $emp_master_id,
                'employee_code'    => $employee_code,
                'work_date'        => $today,
                'clock_in'         => $now_time,
                'rounded_clock_in' => $rounded_in,
                'time_unit'        => $time_unit,
                'long_distance'    => $long_distance,
                'note'             => $note_input ?: null,
            ) );
        }

    } elseif ( $label === '退勤' ) {
        mat_handle_clockout( $emp_master_id, $employee_code );
        // mat_handle_clockout 内で wp_send_json_* が呼ばれる

    } elseif ( $label === '休憩' ) {
        if ( ! $row || is_null( $row->clock_in ) ) {
            wp_send_json_error( '出勤打刻がありません。先に出勤を打刻してください。' );
        }

        $break_master_id = intval( $_POST['break_master_id'] ?? 0 );
        $break_master    = $break_master_id ? mat_get_break_master_by_id( $break_master_id ) : null;

        if ( $break_master && $break_master->is_active ) {
            $break_minutes = (int) $break_master->break_minutes;
        } else {
            $break_master    = null;
            $break_master_id = null;
            $break_hhmm      = sanitize_text_field( $_POST['break_hhmm'] ?? '00:00' );
            $break_minutes   = mat_parse_time_to_minutes( $break_hhmm );
            if ( is_null( $break_minutes ) ) {
                wp_send_json_error( '休憩時間が不正です。' );
            }
        }

        $wpdb->update( MAT_DAILY_TABLE,
            array(
                'break_minutes'   => $break_minutes,
                'break_master_id' => $break_master ? (int) $break_master->id : null,
                'long_distance'   => ( ! empty( $row->long_distance ) || $long_distance ) ? 1 : 0,
            ),
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

/**
 * 退勤打刻の保存（要件定義書 §5.1 / §5.3）。
 *
 * 実打刻・丸め込み後の終業・休憩・申請レコードをまとめて確定させる。
 * 応答は wp_send_json_* で返すため、呼び出し元は以降の処理を行わない。
 */
function mat_handle_clockout( $emp_master_id, $employee_code ) {
    global $wpdb;

    $override    = sanitize_text_field( $_POST['clock_out_override'] ?? '' );
    $forced_date = sanitize_text_field( $_POST['target_date'] ?? '' );
    $long_distance = ( ( $_POST['long_distance'] ?? '0' ) === '1' ) ? 1 : 0;

    $target = mat_resolve_clockout_target( $emp_master_id, $override, $forced_date );
    if ( is_wp_error( $target ) ) wp_send_json_error( $target->get_error_message() );

    $row  = $target['row'];
    $unit = ! empty( $row->time_unit ) ? (int) $row->time_unit : mat_get_time_unit();

    // ---- 休憩の確定 ----
    $break_master_id = intval( $_POST['break_master_id'] ?? 0 );
    $break_master    = $break_master_id ? mat_get_break_master_by_id( $break_master_id ) : null;
    if ( $break_master && ! $break_master->is_active ) $break_master = null;

    if ( $break_master ) {
        $break_minutes = (int) $break_master->break_minutes;
    } elseif ( ! is_null( $row->break_minutes ) ) {
        $break_minutes = (int) $row->break_minutes;
    } else {
        $default       = mat_get_default_break_master();
        $break_master  = $default;
        $break_minutes = $default ? (int) $default->break_minutes : 0;
    }

    // ---- 打刻の確定 ----
    $clock_out     = mat_minutes_to_time_sql( $target['clock_out_min'] );
    $rounded_out   = mat_minutes_to_time_sql( mat_round_out_minutes( $target['clock_out_min'], $unit ) );
    $rounded_in    = $row->rounded_clock_in
        ?: mat_minutes_to_time_sql( mat_round_in_minutes( mat_parse_time_to_minutes( $row->clock_in ), $unit ) );

    // ---- 深夜休憩の確定（要件定義書 §6.7）----
    // midnight_span_minutes は該当があれば常にスナップショット保存する。
    // midnight_break_minutes は POST が無ければ既存値（通常はNULL＝未確認）を維持し、事後修正はしない。
    $midnight_span          = mat_calc_midnight_span_minutes( $rounded_in, $rounded_out, $row->break_out_start ?? null, $row->break_out_end ?? null );
    $midnight_break_minutes = $row->midnight_break_minutes === null ? null : (int) $row->midnight_break_minutes;
    $midnight_reason        = '';
    $midnight_input         = $_POST['midnight_break_minutes'] ?? '';

    if ( $midnight_input !== '' ) {
        if ( ! is_numeric( $midnight_input ) || (int) $midnight_input < 0 ) {
            wp_send_json_error( '深夜休憩の分数を入力してください。' );
        }
        $entered = (int) $midnight_input;

        if ( $midnight_span !== null && $entered > $midnight_span ) {
            wp_send_json_error( sprintf( '深夜休憩は深夜該当時間（%s）を超えられません。', mat_format_minutes_jp_padded( $midnight_span ) ) );
        }
        if ( $entered > $break_minutes ) {
            wp_send_json_error( '深夜休憩が本日の休憩を超えています。休憩時間をご確認ください。' );
        }

        $midnight_break_minutes = $entered;
        $midnight_reason        = sanitize_textarea_field( $_POST['midnight_break_reason'] ?? '' );
        if ( $midnight_reason === '' ) $midnight_reason = sprintf( '深夜休憩 %d分', $entered );
    }

    $midnight_minutes = mat_calc_midnight_minutes( $rounded_in, $rounded_out, $midnight_break_minutes, $row->break_out_start ?? null, $row->break_out_end ?? null );

    $data = array(
        'clock_out'               => $clock_out,
        'rounded_clock_in'        => $rounded_in,
        'rounded_clock_out'       => $rounded_out,
        'is_overnight'            => $target['is_overnight'] ? 1 : 0,
        'break_minutes'           => $break_minutes,
        'break_master_id'         => $break_master ? (int) $break_master->id : $row->break_master_id,
        'time_unit'               => $unit,
        'midnight_span_minutes'   => $midnight_span,
        'midnight_break_minutes'  => $midnight_break_minutes,
        'midnight_minutes'        => $midnight_minutes,
        'long_distance'           => ( ! empty( $row->long_distance ) || $long_distance ) ? 1 : 0,
    );

    // 退勤時刻を修正した場合は監査用に備考へ追記する（§5.3③）
    if ( $override !== '' ) {
        $original = sanitize_text_field( $_POST['clock_out_original'] ?? '' );
        if ( $original === '' ) $original = current_time( 'H:i' );

        $auto_note = sprintf(
            '退勤時刻修正 %s → %s（打刻時修正）',
            $original,
            mat_minutes_to_hm( $target['clock_out_min'] )
        );
        $data['note'] = $row->note ? $row->note . ' / ' . $auto_note : $auto_note;
    }

    $updated = $wpdb->update( MAT_DAILY_TABLE, $data, array( 'id' => (int) $row->id ) );
    if ( $updated === false ) {
        wp_send_json_error( '退勤の登録に失敗しました。管理者にお問い合わせください。' );
    }

    // ---- 申請レコードの登録 ----
    $break_reason    = sanitize_textarea_field( $_POST['break_reason'] ?? '' );
    $overtime_reason = sanitize_textarea_field( $_POST['overtime_reason'] ?? '' );

    if ( $break_reason !== '' ) {
        mat_upsert_work_request( array(
            'daily_id'      => (int) $row->id,
            'request_type'  => 'break_exception',
            'reason'        => $break_reason,
            'requested_by'  => 'employee',
        ) );
    }
    if ( $overtime_reason !== '' ) {
        mat_upsert_work_request( array(
            'daily_id'      => (int) $row->id,
            'request_type'  => 'overtime',
            'reason'        => $overtime_reason,
            'requested_by'  => 'employee',
        ) );
    }
    if ( $midnight_input !== '' ) {
        mat_upsert_work_request( array(
            'daily_id'      => (int) $row->id,
            'request_type'  => 'midnight_break',
            'reason'        => $midnight_reason,
            'requested_by'  => 'employee',
        ) );
    }

    wp_send_json_success( mat_get_grouped_data( $emp_master_id, substr( $row->work_date, 0, 7 ) ) );
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
            array(
                'clock_in'          => null,
                'clock_out'         => null,
                'rounded_clock_in'  => null,
                'rounded_clock_out' => null,
                'is_overnight'      => 0,
                'break_minutes'     => null,
                'break_master_id'   => null,
                'is_holiday'        => 1,
                'note'              => null,
            ),
            array( 'id' => (int) $existing->id )
        );
        // 休日化した日の申請は不要になるため削除する
        $wpdb->delete( MAT_WORK_REQUEST_TABLE, array( 'daily_id' => (int) $existing->id ), array( '%d' ) );
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

    // 深夜休憩（midnight_break_minutes）は従業員による事後修正を禁止しているため、
    // POST に含まれていても意図的に読み取らない（要件定義書 §6.6）。
    $wpdb->update( MAT_DAILY_TABLE, array(
        'clock_in'      => $clock_in  ? mat_minutes_to_time_sql( mat_parse_time_to_minutes( $clock_in ) )  : null,
        'clock_out'     => $clock_out ? mat_minutes_to_time_sql( mat_parse_time_to_minutes( $clock_out ) ) : null,
        'break_minutes' => $break_minutes,
        'note'          => $note ?: null,
    ), array( 'id' => $id ) );

    // 始業・終業・日跨ぎフラグをサーバ側で再計算する
    mat_recalc_daily_row( $id );

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
