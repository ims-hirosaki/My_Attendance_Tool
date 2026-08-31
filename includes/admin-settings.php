<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * admin-settings.php  v3.2.0
 *
 * 変更点（v3.2.0）:
 * - 打刻履歴に「始業 / 終業 / 残業時間 / アラート / 操作 / 修正ステータス」列を追加（要件定義書 §6.1）。
 * - アラートは保存せず、表示時に動的計算する（マスタ変更時の整合性を保つため）。
 * - 「修正」ボタンで admin-alert-modal.php の共通モーダルを開く。
 * - 管理者が打刻を修正した場合、始業・終業・残業をサーバ側で自動再計算する（§6.4）。
 *
 * 変更点（v3.1.4）:
 * - 【仕様改善】管理画面アクセス時のデフォルト状態を、特定の社員ではなく「--- 従業員を選択してください ---」が初期選択される未選択状態に修正。
 * - 【致命的バグ修正】新規登録時、表示月の桁数（1桁月/2桁月）によって不正な日付フォーマットがMySQLに送信されるJQueryのバグを完全修正。
 * - 【表示改善】日付データテーブルの上に対象者の「社員コード ｜ 氏名　勤務実績：0/00日」を左詰めでスマートに追加。
 * - 【表示改善】編集ポップアップ（モーダル）内に対象社員名と日付を表示するメタ領域を追加。
 * - 【バグ修正】職種チップ（ソート）操作時、および「全OFF」選択での検索リロード後も選択State（状態）を100%完全に維持するロジックを搭載。
 * - 【機能拡張】データの有無に関わらず、すべての日に「登録 / 編集」ボタンを常時出力。空行からでも管理者がダイレクトに新規追加（INSERT）できるように改修。
 */

// =========================================================
//  管理メニュー登録
// =========================================================
add_action( 'admin_menu', 'mat_register_admin_menu' );
function mat_register_admin_menu() {
    add_menu_page(
        '打刻ツール', '打刻ツール', 'access_custom_plugins',
        'my-attendance-settings', 'mat_history_page_render',
        'dashicons-clock', 30
    );
    add_submenu_page(
        'my-attendance-settings', '打刻', '打刻', 'access_custom_plugins',
        'my-attendance-settings', 'mat_history_page_render'
    );
}

// =========================================================
//  管理画面用スクリプト読み込み
// =========================================================
add_action( 'admin_enqueue_scripts', 'mat_admin_enqueue' );
function mat_admin_enqueue( $hook ) {
    $page = $_GET['page'] ?? '';
    $mat_pages = apply_filters( 'mat_admin_pages', array(
        'my-attendance-settings',
        'mat-auth-management',
        'mat-settings',
        'mat-test-data',
        'mat-migrate',
    ) );
    if ( ! in_array( $page, $mat_pages, true ) ) return;

    $emp_css = WP_PLUGIN_DIR . '/employee-manager/admin/assets/admin.css';
    if ( file_exists( $emp_css ) ) {
        wp_enqueue_style( 'employee-manager-admin', plugins_url( 'employee-manager/admin/assets/admin.css' ) );
    }
}

// =========================================================
//  管理画面：勤怠編集・新規登録 Ajax（バグ修正完全版）
// =========================================================
add_action( 'wp_ajax_mat_admin_edit_log', 'mat_admin_edit_log_handler' );
function mat_admin_edit_log_handler() {
    if ( ! current_user_can( 'edit_custom_plugins' ) ) wp_send_json_error( '権限がありません。' );
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;
    $id         = intval( $_POST['id'] ?? 0 );
    // 24時間超（"25:10"）も受け付けるため mat_parse_time_to_minutes で正規化する
    $clock_in_min  = mat_parse_time_to_minutes( sanitize_text_field( $_POST['clock_in']  ?? '' ) );
    $clock_out_min = mat_parse_time_to_minutes( sanitize_text_field( $_POST['clock_out'] ?? '' ) );
    $clock_in   = mat_minutes_to_time_sql( $clock_in_min );
    $clock_out  = mat_minutes_to_time_sql( $clock_out_min );
    $break_hhmm = sanitize_text_field( $_POST['break_time'] ?? '00:00' );
    $note       = sanitize_textarea_field( $_POST['note']   ?? '' );
    $is_holiday = ( ( $_POST['is_holiday'] ?? '0' ) === '1' );

    // 新規登録用の従業員コードと日付を回収
    $employee_code = isset( $_POST['employee_code'] ) ? sanitize_text_field( $_POST['employee_code'] ) : '';
    $work_date     = isset( $_POST['work_date'] )     ? sanitize_text_field( $_POST['work_date'] ) : '';

    $row = null;
    if ( $id > 0 ) {
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . MAT_DAILY_TABLE . " WHERE id = %d", $id
        ) );
    }

    if ( $is_holiday ) {
        $data_fields = array(
            'clock_in'      => null,
            'clock_out'     => null,
            'break_minutes' => null,
            'is_holiday'    => 1,
            'note'          => null,
        );
    } else {
        $break_minutes = mat_hhmm_to_minutes( $break_hhmm );

        $units = $row ? mat_get_row_rounding_units( $row ) : array( 'in' => mat_get_clock_in_unit(), 'out' => mat_get_clock_out_unit() );
        $rounded_in_preview  = $clock_in_min  !== null ? mat_minutes_to_time_sql( mat_round_in_minutes( $clock_in_min, $units['in'] ) )  : null;
        $rounded_out_preview = $clock_out_min !== null ? mat_minutes_to_time_sql( mat_round_out_minutes( $clock_out_min, $units['out'] ) ) : null;

        // ---- 中抜け（要件定義書 §12.4）----
        $break_out_enabled = ( ( $_POST['break_out_enabled'] ?? '0' ) === '1' );
        $break_out_start   = null;
        $break_out_end     = null;
        if ( $break_out_enabled ) {
            $bo_start_min = mat_parse_time_to_minutes( sanitize_text_field( $_POST['break_out_start'] ?? '' ) );
            $bo_end_min   = mat_parse_time_to_minutes( sanitize_text_field( $_POST['break_out_end']   ?? '' ) );
            if ( $bo_start_min === null || $bo_end_min === null ) {
                wp_send_json_error( '中抜けの開始・終了時刻を入力してください。' );
            }
            $rounded_in_min_val  = mat_parse_time_to_minutes( $rounded_in_preview );
            $rounded_out_min_val = mat_parse_time_to_minutes( $rounded_out_preview );
            if ( $rounded_in_min_val === null || $rounded_out_min_val === null
                || $bo_start_min < $rounded_in_min_val || $bo_end_min <= $bo_start_min || $bo_end_min > $rounded_out_min_val ) {
                wp_send_json_error( '中抜け時間は始業〜終業の範囲内で指定してください。' );
            }
            $break_out_start = mat_minutes_to_time_sql( $bo_start_min );
            $break_out_end   = mat_minutes_to_time_sql( $bo_end_min );
        }

        // ---- 深夜休憩（要件定義書 §7.2）----
        // 保存後の丸め値・中抜けから深夜該当時間を先に見積もり、バリデーションに使う。
        $midnight_span_preview = mat_calc_midnight_span_minutes( $rounded_in_preview, $rounded_out_preview, $break_out_start, $break_out_end );

        $midnight_input         = sanitize_text_field( $_POST['midnight_break_minutes'] ?? '' );
        $midnight_break_minutes = null;
        if ( $midnight_input !== '' ) {
            if ( ! is_numeric( $midnight_input ) || (int) $midnight_input < 0 ) {
                wp_send_json_error( '深夜休憩は0以上の整数（分）で入力してください。' );
            }
            $midnight_break_minutes = (int) $midnight_input;

            if ( $midnight_span_preview !== null && $midnight_break_minutes > $midnight_span_preview ) {
                wp_send_json_error( sprintf( '深夜休憩は深夜該当時間（%s）を超えられません。', mat_format_minutes_jp_padded( $midnight_span_preview ) ) );
            }
            if ( $midnight_break_minutes > (int) $break_minutes ) {
                wp_send_json_error( '深夜休憩が休憩時間を超えています。休憩時間をご確認ください。' );
            }
        }

        // ---- 拘束時間と休憩の整合チェック（要件定義書 §12.4 バリデーション3）----
        $kousoku_preview = mat_calc_work_minutes( $rounded_in_preview, $rounded_out_preview, $break_minutes, $break_out_start, $break_out_end )['kousoku'];
        if ( $kousoku_preview !== null && $kousoku_preview - (int) $break_minutes < 0 ) {
            wp_send_json_error( '休憩時間が拘束時間を超えています。中抜けと休憩の入力をご確認ください。' );
        }

        $data_fields = array(
            'clock_in'                => $clock_in  ?: null,
            'clock_out'               => $clock_out ?: null,
            'break_minutes'           => $break_minutes,
            'is_holiday'              => 0,
            'note'                    => $note ?: null,
            'midnight_break_minutes'  => $midnight_break_minutes,
            'break_out_start'         => $break_out_start,
            'break_out_end'           => $break_out_end,
        );
    }

    // 休日化した場合は丸め値・深夜3カラム・中抜け2カラムもクリアする
    if ( $is_holiday ) {
        $data_fields['rounded_clock_in']       = null;
        $data_fields['rounded_clock_out']      = null;
        $data_fields['is_overnight']           = 0;
        $data_fields['break_master_id']        = null;
        $data_fields['midnight_span_minutes']  = null;
        $data_fields['midnight_break_minutes'] = null;
        $data_fields['midnight_minutes']       = null;
        $data_fields['break_out_start']        = null;
        $data_fields['break_out_end']          = null;
    }

    if ( $row ) {
        // ① レコードが存在する場合は「UPDATE」
        $updated = $wpdb->update( MAT_DAILY_TABLE, $data_fields, array( 'id' => $id ) );
        if ( $updated === false ) wp_send_json_error( '更新に失敗しました。' );

        // 出勤・退勤・休憩を修正したので始業／終業を再計算（§6.4）
        if ( ! $is_holiday ) {
            mat_recalc_daily_row( $id );
        } else {
            $wpdb->delete( MAT_WORK_REQUEST_TABLE, array( 'daily_id' => $id ), array( '%d' ) );
        }
        $target_id = $id;
    } else {
        // ② 空行からの登録時は「INSERT」を実行
        if ( empty( $employee_code ) || empty( $work_date ) ) {
            wp_send_json_error( '新規登録に必要な情報（従業員または日付）が不足しています。' );
        }

        $emp = emp_get_employee_by_code( $employee_code );
        if ( ! $emp ) wp_send_json_error( '従業員情報が見つかりません。' );

        $data_fields['employee_id']   = (int) $emp->id;
        $data_fields['employee_code'] = $employee_code;
        $data_fields['work_date']     = $work_date;

        $inserted = $wpdb->insert( MAT_DAILY_TABLE, $data_fields );
        if ( ! $inserted ) wp_send_json_error( '新規データの登録に失敗しました。' );

        $target_id = (int) $wpdb->insert_id;
        if ( ! $is_holiday ) mat_recalc_daily_row( $target_id );
    }

    // 管理者が深夜休憩を入力し、該当の申請がまだ無い場合は admin 申請として新規作成する（既存の赤アラート対応と同じ挙動）
    if ( ! $is_holiday && isset( $midnight_break_minutes ) && $midnight_break_minutes !== null ) {
        $existing = mat_get_work_requests_by_daily( $target_id );
        if ( empty( $existing['midnight_break'] ) ) {
            mat_upsert_work_request( array(
                'daily_id'     => $target_id,
                'request_type' => 'midnight_break',
                'reason'       => $midnight_break_minutes === 0 ? '管理者による登録（休憩なし）' : sprintf( '管理者による登録（%d分）', $midnight_break_minutes ),
                'requested_by' => 'admin',
            ) );
        }
    }

    wp_send_json_success();
}

// =========================================================
//  管理画面：勤怠削除 Ajax（新テーブル版）
// =========================================================
add_action( 'wp_ajax_mat_admin_delete_log', 'mat_admin_delete_log_handler' );
function mat_admin_delete_log_handler() {
    if ( ! current_user_can( 'edit_custom_plugins' ) ) wp_send_json_error( '権限がありません。' );
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;
    $id = intval( $_POST['id'] ?? 0 );
    if ( $id <= 0 ) wp_send_json_error( 'IDが不正です。' );

    $deleted = $wpdb->delete( MAT_DAILY_TABLE, array( 'id' => $id ), array( '%d' ) );
    if ( $deleted !== false ) {
        // 日次データに紐づく申請・対応履歴も残さない。
        $wpdb->delete( MAT_WORK_REQUEST_TABLE, array( 'daily_id' => $id ), array( '%d' ) );
        wp_send_json_success( '削除しました。' );
    } else {
        wp_send_json_error( '削除に失敗しました。' );
    }
}

// =========================================================
//  勤怠履歴ページのレンダリング（全改修統合版）
// =========================================================
function mat_history_page_render() {
    if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( '権限がありません。', '', array( 'response' => 403 ) );

    $employees     = emp_get_active_employees();
    $job_types     = function_exists( 'emp_get_job_types' ) ? emp_get_job_types() : array();

    $emp_js_data = array();
    foreach ( $employees as $emp ) {
        $emp_js_data[] = array(
            'code'     => $emp->employee_code,
            'name'     => $emp->name,
            'job_type' => isset( $emp->job_type_name ) ? $emp->job_type_name : '',
        );
    }
    $job_type_names = array();
    foreach ( $job_types as $jt ) {
        $job_type_names[] = $jt->name;
    }

    // ★【修正】初回アクセス時は自動選択させず、デフォルトを空（未選択状態）にする
    $selected_code = isset( $_GET['employee_code'] ) ? sanitize_text_field( $_GET['employee_code'] ) : '';

    $view_month = isset( $_GET['view_month'] )
        ? sanitize_text_field( $_GET['view_month'] )
        : date( 'Y-m' );

    // 「全OFF」リロード時のState消失を防ぐフラグと送信配列の回収
    $filter_applied = isset( $_GET['mat_filter_applied'] ) ? true : false;
    $saved_filters  = isset( $_GET['mat_filters'] ) ? array_map( 'sanitize_text_field', (array) $_GET['mat_filters'] ) : array();

    $selected_emp = null;
    if ( ! empty( $selected_code ) ) {
        foreach ( $employees as $emp ) {
            if ( $emp->employee_code === $selected_code ) { $selected_emp = $emp; break; }
        }
    }

    $logs            = array();
    $work_days_count = 0;
    $total_days      = 0;
    $alert_map       = array();
    if ( $selected_emp ) {
        $data            = mat_get_grouped_data( $selected_emp->id, $view_month );
        $logs            = $data['logs'];
        $work_days_count = $data['work_days_count'];
        $total_days      = $data['total_days'];
        // アラートは保存せず表示時に動的計算する（§6.2）
        $alert_map       = mat_get_month_alerts( $selected_emp->id, $view_month );
    }
    ?>
    <div class="wrap">
        <h1>📋 従業員打刻履歴</h1>

        <div class="card" style="max-width:100%; margin-top:20px; padding:15px;">

            <?php if ( ! empty( $job_types ) ) : ?>
            <div style="margin-bottom:12px; display:flex; align-items:center; flex-wrap:wrap; gap:6px;">
                <span style="font-size:0.85em; font-weight:600; color:#555; white-space:nowrap;">
                    職種フィルター：
                </span>
                <div id="mat-job-type-chips" style="display:inline-flex; flex-wrap:wrap; gap:6px;">
                    <?php foreach ( $job_types as $jt ) : ?>
                        <button type="button" class="mat-chip"
                            data-job-type="<?php echo esc_attr( $jt->name ); ?>"
                            style="display:inline-flex;align-items:center;gap:4px;padding:4px 12px;
                                   border-radius:20px;border:1.5px solid #2271b1;
                                   background:#2271b1;color:#fff;font-size:0.82em;font-weight:600;
                                   cursor:pointer;line-height:1.5;transition:background .15s,color .15s;">
                            <span class="mat-chip-dot" style="display:inline-block;width:7px;height:7px;
                                border-radius:50%;background:#fff;"></span>
                            <?php echo esc_html( $jt->name ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="mat-chip-all-on"
                    style="font-size:0.78em;color:#2271b1;background:none;border:none;cursor:pointer;text-decoration:underline;">
                    全ON
                </button>
                <button type="button" id="mat-chip-all-off"
                    style="font-size:0.78em;color:#888;background:none;border:none;cursor:pointer;text-decoration:underline;">
                    全OFF
                </button>
            </div>
            <?php endif; ?>

            <form method="get" id="mat-filter-form">
                <input type="hidden" name="page" value="my-attendance-settings">
                
                <input type="hidden" name="mat_filter_applied" value="1">
                <div id="mat-hidden-filter-inputs"></div>

                従業員：
                <select name="employee_code" id="mat-employee-select">
                    <option value="">--- 従業員を選択してください ---</option>
                    <?php foreach ( $employees as $emp ) : ?>
                        <option value="<?php echo esc_attr( $emp->employee_code ); ?>"
                            data-job-type="<?php echo esc_attr( isset( $emp->job_type_name ) ? $emp->job_type_name : '' ); ?>"
                            <?php selected( $selected_code, $emp->employee_code ); ?>>
                            [<?php echo esc_html( $emp->employee_code ); ?>] <?php echo esc_html( $emp->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                表示月：<input type="month" name="view_month" value="<?php echo esc_attr( $view_month ); ?>">
                <input type="submit" class="button button-primary" value="表示">
            </form>
        </div>

        <?php if ( $selected_emp ) : ?>
            <div class="mat-admin-selected-info-bar" style="margin: 20px 0 10px; padding: 12px 16px; background: #fff; border-left: 4px solid #2271b1; border-radius: 0 4px 4px 0; box-shadow: 0 1px 3px rgba(0,0,0,.05); font-size: 1.05em; font-weight: bold; color: #1d2327; display: flex; align-items: center; flex-wrap: wrap; gap: 20px;">
                <div>
                    <span style="color: #2271b1;">[<?php echo esc_html( $selected_emp->employee_code ); ?>]</span> 
                    <span style="margin-left: 4px;"><?php echo esc_html( $selected_emp->name ); ?></span>
                </div>
                <div style="font-size: 0.9em; color: #50575e; font-weight: 600;">
                    勤務実績：<strong style="color: #1d2327; font-size: 1.1em;"><?php echo esc_html( $work_days_count ); ?></strong> / <?php echo esc_html( $total_days ); ?> 日
                </div>
            </div>

            <div style="margin-top:8px; max-width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch;">
            <table class="widefat striped" style="min-width:1250px; table-layout:auto;">
                <thead>
                    <tr>
                        <th style="width:100px;">日付</th>
                        <th style="width:70px;">長距離</th>
                        <th style="width:75px;">出勤<br><span style="font-weight:400;font-size:.85em;">(打刻)</span></th>
                        <th style="width:75px;">退勤<br><span style="font-weight:400;font-size:.85em;">(打刻)</span></th>
                        <th style="width:70px;">休憩</th>
                        <th style="width:140px;">備考</th>
                        <th style="width:70px;">始業</th>
                        <th style="width:70px;">終業</th>
                        <th style="width:80px;">残業時間</th>
                        <th style="width:80px;">深夜時間</th>
                        <th>アラート</th>
                        <th style="width:70px;">操作</th>
                        <th style="width:110px;">修正ステータス</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr><td colspan="13" style="text-align:center;padding:20px;">データがありません。</td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $day ) :
                            $is_empty   = ! $day['has_data'];
                            $is_holiday = $day['is_holiday'];
                            $row_style  = $is_empty ? 'color:#bbb; background:#fafafa;' : '';
                            if ( $is_holiday ) $row_style = 'background:#fff8e1;';

                            $meta      = $alert_map[ $day['date_ymd'] ] ?? array( 'alerts' => array(), 'requests' => array() );
                            $alerts    = $meta['alerts'];
                            $has_alert = ! empty( $alerts );
                            $has_request = ! empty( $meta['requests'] );
                        ?>
                            <tr data-id="<?php echo esc_attr( $day['id'] ); ?>" style="<?php echo $row_style; ?>">
                                <td><?php echo esc_html( $day['date'] ); ?></td>
                                <td style="text-align:center;"><?php echo ! empty( $day['long_distance'] ) ? 'ON' : '−'; ?></td>
                                <td><?php echo esc_html( $day['in'] ?? '-' ); ?></td>
                                <td>
                                    <?php echo esc_html( $day['out'] ?? '-' ); ?>
                                    <?php if ( ! empty( $day['is_overnight'] ) ) echo ' <span title="日跨ぎ">⏰</span>'; ?>
                                    <?php if ( ! empty( $day['has_break_out'] ) ) echo ' <span title="中抜けあり">✂</span>'; ?>
                                </td>
                                <td><?php echo esc_html( $day['break'] ?? '-' ); ?></td>
                                <?php $note_text = is_array( $day['notes'] ) ? implode( ' / ', $day['notes'] ) : ''; ?>
                                <td style="font-size:.9em; max-width:140px;">
                                    <div style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <?php echo esc_html( $note_text ); ?>
                                    </div>
                                </td>
                                <td><?php echo esc_html( $day['rounded_in'] ?: '−' ); ?></td>
                                <td><?php echo esc_html( $day['rounded_out'] ?: '−' ); ?></td>
                                <td><?php echo esc_html( $day['overtime'] ?: '' ); ?></td>
                                <td><?php echo esc_html( $day['midnight'] ?: '-' ); ?></td>
                                <td><?php echo mat_render_alert_badges( $alerts ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                <td>
                                    <?php if ( $has_alert || $has_request ) : ?>
                                        <button class="button button-small mat-alert-fix-btn"
                                            data-daily-id="<?php echo esc_attr( $day['id'] ); ?>"><?php echo $has_alert ? '修正' : '確認'; ?></button>
                                    <?php else : ?>
                                        <button class="button button-small edit-log"
                                            data-id="<?php echo esc_attr( $day['id'] ); ?>"
                                            data-in="<?php echo esc_attr( $day['in'] ?? '' ); ?>"
                                            data-out="<?php echo esc_attr( $day['out'] ?? '' ); ?>"
                                            data-clock-in-unit="<?php echo esc_attr( $day['clock_in_unit'] ?? mat_get_clock_in_unit() ); ?>"
                                            data-clock-out-unit="<?php echo esc_attr( $day['clock_out_unit'] ?? mat_get_clock_out_unit() ); ?>"
                                            data-break="<?php echo esc_attr( $day['break'] ?? '00:00' ); ?>"
                                            data-notes="<?php echo esc_attr( $note_text ); ?>"
                                            data-holiday="<?php echo $is_holiday ? '1' : '0'; ?>"
                                            data-date-label="<?php echo esc_attr( $day['date'] ); ?>"
                                            data-midnight-break="<?php echo esc_attr( $day['midnight_break_minutes'] === null ? '' : $day['midnight_break_minutes'] ); ?>"
                                            data-break-out-start="<?php echo esc_attr( $day['break_out_start'] ?? '' ); ?>"
                                            data-break-out-end="<?php echo esc_attr( $day['break_out_end'] ?? '' ); ?>">
                                            <?php echo $is_empty ? '登録' : '編集'; ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo mat_render_status_badges( $meta['requests'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        <?php else : ?>
            <div class="notice notice-info inline" style="margin-top: 20px; padding: 16px;">
                <p style="margin: 0; font-size: 14px; font-weight: 600; color: #1d2327;">
                    💡 上記の従業員選択メニューから従業員を選択し、「表示」ボタンを押すと打刻履歴が表示されます。
                </p>
            </div>
        <?php endif; ?>
    </div>

    <div id="mat-edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
         z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:8px;padding:28px;width:440px;max-width:90%; box-shadow: 0 4px 16px rgba(0,0,0,0.2);">
            <h3 style="margin:0 0 14px; color:#2271b1;">打刻データの編集</h3>
            
            <div class="mat-modal-target-meta" style="margin-bottom: 18px; padding: 10px 14px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 0 4px 4px 0; font-size: 0.92em; line-height: 1.6; color: #1d2327; font-weight: 600;">
                <div style="margin-bottom: 2px;">対象者：<span id="mat-modal-meta-emp" style="color: #1d2327;">--</span></div>
                <div>対象日：<span id="mat-modal-meta-date" style="color: #2271b1;">--</span></div>
            </div>

            <table class="form-table" style="margin:0;">
                <tr><th>出勤</th><td><input type="text" id="edit-in" class="regular-text" placeholder="HH:MM"></td></tr>
                <tr>
                    <th>退勤</th>
                    <td>
                        <input type="text" id="edit-out" class="regular-text" placeholder="HH:MM">
                        <p class="description" style="margin:4px 0 0;">日跨ぎは 25:10 のように24時間超で入力できます。</p>
                    </td>
                </tr>
                <tr>
                    <th>中抜け</th>
                    <td>
                        <label><input type="checkbox" id="edit-break-out-enabled"> 中抜けあり（同日2回勤務）</label>
                        <div id="edit-break-out-fields" style="display:none; margin-top:6px;">
                            <input type="text" id="edit-break-out-start" class="small-text" placeholder="HH:MM" style="width:80px;">
                            〜
                            <input type="text" id="edit-break-out-end" class="small-text" placeholder="HH:MM" style="width:80px;">
                            <span style="margin-left:10px; color:#50575e;">中抜け時間：<strong id="edit-break-out-minutes">--</strong></span>
                        </div>
                    </td>
                </tr>
                <tr><th>休憩</th><td><input type="time" id="edit-break" class="regular-text" value="00:00"></td></tr>
                <tr id="edit-midnight-row" style="display:none;">
                    <th>深夜休憩</th>
                    <td>
                        <input type="number" id="edit-midnight-break" class="small-text" min="0" step="1"> 分
                        <span style="margin-left:10px; color:#50575e;">
                            深夜該当：<strong id="edit-midnight-span">--</strong>（<span id="edit-midnight-window-label"></span>）
                        </span>
                        <p class="description" style="margin:4px 0 0;">
                            空欄のまま保存すると未確認（NULL）を維持します。
                        </p>
                    </td>
                </tr>
                <tr id="edit-calc-row" style="display:none;">
                    <th>自動計算</th>
                    <td style="font-size:0.92em; color:#1d2327; line-height:1.8;">
                        拘束時間：<strong id="edit-calc-kousoku">--</strong><br>
                        深夜時間：<strong id="edit-calc-midnight">--</strong><br>
                        残業時間：<strong id="edit-calc-overtime">--</strong>
                    </td>
                </tr>
                <tr><th>備考</th><td><textarea id="edit-notes" class="regular-text" rows="2"></textarea></td></tr>
                <tr>
                    <th>休日</th>
                    <td><label><input type="checkbox" id="edit-holiday"> 休日として登録する</label></td>
                </tr>
            </table>
            <p id="edit-error" style="color:#d63638;display:none;margin:10px 0 0;"></p>
            <div style="margin-top:20px;display:flex;gap:8px;justify-content:flex-end;">
                <button class="button button-link-delete" id="edit-delete">🗑 削除する</button>
                <button class="button" id="edit-cancel">キャンセル</button>
                <button class="button button-primary" id="edit-save">💾 保存する</button>
            </div>
        </div>
    </div>

    <?php mat_render_alert_modal(); ?>

    <script>
    jQuery(function($) {
        var nonce    = '<?php echo wp_create_nonce( "mat_admin_nonce" ); ?>';
        var currentId = null;
        var modalTargetDateYmd = '';

        // ---- 拘束・深夜該当時間のライブプレビュー（要件定義書 §7.2・§12.4） ----
        var matMidnightWindow = {
            start: <?php echo (int) mat_get_midnight_window()['start']; ?>,
            end:   <?php echo (int) mat_get_midnight_window()['end']; ?>
        };
        var matMidnightWindowLabel = '<?php
            $w = mat_get_midnight_window();
            echo esc_js( mat_minutes_to_hm( $w['start'] ) . ' 〜 ' . mat_minutes_to_hm( $w['end'] ) );
        ?>';
        var matOvertimeThreshold = <?php echo (int) mat_get_overtime_threshold(); ?>;
        var currentEditClockInUnit = <?php echo (int) mat_get_clock_in_unit(); ?>;
        var currentEditClockOutUnit = <?php echo (int) mat_get_clock_out_unit(); ?>;

        function matParseHM(s) {
            var m = /^(\d{1,3}):(\d{2})$/.exec($.trim(s || ''));
            if (!m) return null;
            return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
        }

        function matFormatMinutesJpPadded(min) {
            if (min === null) return '--';
            return Math.floor(min / 60) + '時間' + String(min % 60).padStart(2, '0') + '分';
        }

        function matFormatHM(min) {
            if (min === null || isNaN(min)) return '--';
            var sign = min < 0 ? '-' : '';
            min = Math.abs(min);
            return sign + Math.floor(min / 60) + ':' + String(min % 60).padStart(2, '0');
        }

        // 中抜けを除外した実勤務区間（要件定義書 §12.3）
        function matGetWorkedRanges(inMin, outMin, boStartMin, boEndMin) {
            if (boStartMin === null || boEndMin === null || boEndMin <= boStartMin || boStartMin < inMin || boEndMin > outMin) {
                return [[inMin, outMin]];
            }
            var ranges = [];
            if (boStartMin > inMin) ranges.push([inMin, boStartMin]);
            if (boEndMin < outMin)  ranges.push([boEndMin, outMin]);
            return ranges;
        }

        function matCalcMidnightSpanFromRanges(workedRanges) {
            var start = matMidnightWindow.start, end = matMidnightWindow.end;
            var midnightRanges = [];
            var prevStart = Math.max(0, start - 1440), prevEnd = Math.max(0, end - 1440);
            if (prevEnd > prevStart) midnightRanges.push([prevStart, prevEnd]);
            midnightRanges.push([start, end]);

            var span = 0;
            workedRanges.forEach(function (worked) {
                midnightRanges.forEach(function (r) {
                    var overlap = Math.min(worked[1], r[1]) - Math.max(worked[0], r[0]);
                    if (overlap > 0) span += overlap;
                });
            });
            return span;
        }

        function updatePreview() {
            var inMin = matParseHM($('#edit-in').val());
            var outMin = matParseHM($('#edit-out').val());

            var boEnabled  = $('#edit-break-out-enabled').is(':checked');
            var boStartMin = boEnabled ? matParseHM($('#edit-break-out-start').val()) : null;
            var boEndMin   = boEnabled ? matParseHM($('#edit-break-out-end').val())   : null;

            if (inMin === null || outMin === null) {
                $('#edit-midnight-row, #edit-calc-row').hide();
                $('#edit-break-out-minutes').text('--');
                return;
            }
            inMin  = currentEditClockInUnit === 0 ? inMin : Math.ceil(inMin / currentEditClockInUnit) * currentEditClockInUnit;
            outMin = currentEditClockOutUnit === 0 ? outMin : Math.floor(outMin / currentEditClockOutUnit) * currentEditClockOutUnit;
            if (outMin <= inMin) outMin += 1440;

            var boValid = (boEnabled && boStartMin !== null && boEndMin !== null
                && boEndMin > boStartMin && boStartMin >= inMin && boEndMin <= outMin);
            $('#edit-break-out-minutes').text(boValid ? matFormatMinutesJpPadded(boEndMin - boStartMin) : '--');

            var workedRanges     = matGetWorkedRanges(inMin, outMin, boValid ? boStartMin : null, boValid ? boEndMin : null);
            var breakOutMinutes  = boValid ? (boEndMin - boStartMin) : 0;
            var kousoku          = outMin - inMin - breakOutMinutes;
            var breakMinutes     = matParseHM($('#edit-break').val()) || 0;
            var labor            = Math.max(0, kousoku - breakMinutes);
            var overtime         = Math.max(0, labor - matOvertimeThreshold);
            var span             = matCalcMidnightSpanFromRanges(workedRanges);

            $('#edit-midnight-window-label').text(matMidnightWindowLabel);
            if (!span || span <= 0) {
                $('#edit-midnight-row').hide();
            } else {
                $('#edit-midnight-row').show();
                $('#edit-midnight-span').text(matFormatMinutesJpPadded(span));
            }

            var midnightBreakVal = parseInt($('#edit-midnight-break').val(), 10);
            var midnightMinutes  = isNaN(midnightBreakVal) ? span : Math.max(0, span - midnightBreakVal);

            $('#edit-calc-row').show();
            $('#edit-calc-kousoku').text(matFormatHM(kousoku));
            $('#edit-calc-midnight').text(matFormatHM(midnightMinutes));
            $('#edit-calc-overtime').text(matFormatHM(overtime));
        }
        $(document).on('input', '#edit-in, #edit-out, #edit-break, #edit-break-out-start, #edit-break-out-end, #edit-midnight-break', updatePreview);
        $('#edit-break-out-enabled').on('change', function () {
            $('#edit-break-out-fields').toggle($(this).is(':checked'));
            updatePreview();
        });

        var selectedEmpCode = '<?php echo esc_js( $selected_emp ? $selected_emp->employee_code : '' ); ?>';
        var selectedEmpName = '<?php echo esc_js( $selected_emp ? $selected_emp->name : '' ); ?>';

        var empData      = <?php echo wp_json_encode( $emp_js_data ); ?>;
        var jobTypeNames = <?php echo wp_json_encode( $job_type_names ); ?>;
        var activeTypes  = {};

        var isFilterApplied = <?php echo $filter_applied ? 'true' : 'false'; ?>;
        var savedFilters    = <?php echo wp_json_encode($saved_filters); ?>;
        
        if (isFilterApplied) {
            jobTypeNames.forEach(function(jt) {
                activeTypes[jt] = savedFilters.indexOf(jt) !== -1;
            });
        } else {
            jobTypeNames.forEach(function(jt) {
                activeTypes[jt] = (jt !== '長距離' && jt !== '郵便');
            });
        }

        function applyChipStyles() {
            $('.mat-chip').each(function() {
                var jt = $(this).data('job-type');
                var on = activeTypes[jt] !== false;
                $(this).css({ background: on ? '#2271b1' : '#fff', color: on ? '#fff' : '#2271b1' });
            });
            updateHiddenFields();
        }

        function updateHiddenFields() {
            var $container = $('#mat-hidden-filter-inputs').empty();
            Object.keys(activeTypes).forEach(function(jt) {
                if (activeTypes[jt]) { $container.append($('<input type="hidden" name="mat_filters[]">').val(jt)); }
            });
        }

        function filterEmployees() {
            var $sel = $('#mat-employee-select');
            $sel.find('option').each(function() {
                var val = $(this).val();
                if (val === '') return; 
                var jt = $(this).data('job-type') || '';
                var show = jt === '' || activeTypes[jt] !== false;
                $(this).prop('disabled', !show).toggle(show);
            });

            // 従業員が選ばれている場合のみStateを維持、未選択の時は「---選択してください---」のまま固定
            if (selectedEmpCode !== '') {
                var $activeOpt = $sel.find('option[value="' + selectedEmpCode + '"]');
                if ($activeOpt.length > 0 && !$activeOpt.prop('disabled')) { 
                    $sel.val(selectedEmpCode); 
                } else { 
                    $sel.val(''); 
                }
            } else {
                $sel.val(''); // ★デフォルト未選択状態をホールド
            }
        }

        applyChipStyles();
        filterEmployees();

        $(document).on('click', '.mat-chip', function() {
            var jt = $(this).data('job-type');
            activeTypes[jt] = !activeTypes[jt];
            applyChipStyles();
            filterEmployees();
        });
        $('#mat-chip-all-on').on('click', function() {
            jobTypeNames.forEach(function(jt) { activeTypes[jt] = true; });
            applyChipStyles(); filterEmployees();
        });
        $('#mat-chip-all-off').on('click', function() {
            jobTypeNames.forEach(function(jt) { activeTypes[jt] = false; });
            applyChipStyles(); filterEmployees();
        });

        function toggleHolidayUI(isHoliday) {
            var opacity = isHoliday ? '0.5' : '1';
            $('#edit-in, #edit-out, #edit-break, #edit-break-out-enabled').prop('disabled', isHoliday).closest('tr').css('opacity', opacity);
            if (isHoliday) {
                $('#edit-midnight-row, #edit-calc-row, #edit-break-out-fields').hide();
            } else {
                updatePreview();
            }
        }

        $(document).on('click', '.edit-log', function() {
            currentId = $(this).data('id');
            
            var dateLabel = $(this).data('date-label') || '';
            if (dateLabel) {
                var currentMonth = $('input[name="view_month"]').val(); 
                var dateMatch = dateLabel.match(/\/(\d{2})/); 
                if (dateMatch && currentMonth) {
                    var parts = currentMonth.split('-');
                    var year = parts[0];
                    var month = String(parts[1]).padStart(2, '0');
                    var day = String(dateMatch[1]).padStart(2, '0');
                    modalTargetDateYmd = year + '-' + month + '-' + day;
                }
            }

            if (selectedEmpCode !== '') { $('#mat-modal-meta-emp').text('[' + selectedEmpCode + '] ' + selectedEmpName); } else { $('#mat-modal-meta-emp').text('--'); }
            $('#mat-modal-meta-date').text(dateLabel || '--');

            $('#edit-in').val($(this).data('in') || '');
            $('#edit-out').val($(this).data('out') || '');
            currentEditClockInUnit = parseInt($(this).attr('data-clock-in-unit'), 10);
            currentEditClockOutUnit = parseInt($(this).attr('data-clock-out-unit'), 10);
            if (isNaN(currentEditClockInUnit)) currentEditClockInUnit = <?php echo (int) mat_get_clock_in_unit(); ?>;
            if (isNaN(currentEditClockOutUnit)) currentEditClockOutUnit = <?php echo (int) mat_get_clock_out_unit(); ?>;
            $('#edit-break').val($(this).data('break') || '00:00');
            $('#edit-notes').val($(this).data('notes') || '');
            var midnightBreak = $(this).data('midnight-break');
            $('#edit-midnight-break').val(midnightBreak === '' || midnightBreak === undefined ? '' : midnightBreak);

            var boStart = $(this).data('break-out-start') || '';
            var boEnd   = $(this).data('break-out-end') || '';
            var hasBreakOut = !!(boStart && boEnd);
            $('#edit-break-out-enabled').prop('checked', hasBreakOut);
            $('#edit-break-out-start').val(boStart);
            $('#edit-break-out-end').val(boEnd);
            $('#edit-break-out-fields').toggle(hasBreakOut);

            var isHoliday = $(this).data('holiday') == '1';
            $('#edit-holiday').prop('checked', isHoliday);
            toggleHolidayUI(isHoliday);
            updatePreview();
            $('#edit-error').hide();
            $('#mat-edit-modal').css('display', 'flex');
        });

        $('#edit-holiday').on('change', function() { toggleHolidayUI($(this).is(':checked')); });

        // 削除処理
        $('#edit-delete').on('click', function() {
            if (!currentId || !confirm('このデータを完全に削除しますか？')) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('削除中...');
            $.post(ajaxurl, { action: 'mat_admin_delete_log', id: currentId, nonce: nonce }, function(res) {
                if (res.success) { location.reload(); } else { alert(res.data); $btn.prop('disabled', false).text('🗑 削除する'); }
            });
        });

        $('#edit-cancel, #mat-edit-modal').on('click', function(e) { if (e.target === this) { $('#mat-edit-modal').hide(); currentId = null; } });
        $(document).on('keydown', function(e) { if (e.key === 'Escape') $('#mat-edit-modal').hide(); });

        // 保存・新規登録ボタン
        $('#edit-save').on('click', function() {
            $(this).prop('disabled', true).text('保存中...');
            $.post(ajaxurl, {
                action:        'mat_admin_edit_log',
                id:            currentId, 
                employee_code: selectedEmpCode,       
                work_date:     modalTargetDateYmd,    
                clock_in:      $('#edit-in').val(),
                clock_out:     $('#edit-out').val(),
                break_time:    $('#edit-break').val() || '00:00',
                note:          $('#edit-notes').val(),
                is_holiday:    $('#edit-holiday').is(':checked') ? '1' : '0',
                midnight_break_minutes: $('#edit-midnight-row').is(':visible') ? $('#edit-midnight-break').val() : '',
                break_out_enabled: $('#edit-break-out-enabled').is(':checked') ? '1' : '0',
                break_out_start:   $('#edit-break-out-start').val(),
                break_out_end:     $('#edit-break-out-end').val(),
                nonce:         nonce,
            }, function(res) {
                if (res.success) { location.reload(); } else {
                    $('#edit-error').text(res.data).show();
                    $('#edit-save').prop('disabled', false).text('💾 保存する');
                }
            });
        });
    });
    </script>
    <?php
}
