<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 設定画面の登録・処理
 */
add_action( 'admin_menu', 'mat_register_settings_page', 20 );
function mat_register_settings_page() {
    add_submenu_page(
        'my-attendance-settings',
        '打刻ツール設定',
        '設定',
        'manage_custom_plugin_settings',
        'mat-settings',
        'mat_settings_page_render'
    );
}

/**
 * 設定の保存処理
 */
add_action( 'admin_post_mat_save_settings', 'mat_save_settings_handler' );

/** 指定期間の実打刻から、現在選択した始業・終業の丸め値を一括再計算する。 */
function mat_bulk_apply_rounding_units( $start, $end, $in_unit, $out_unit ) {
    global $wpdb;
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM " . MAT_DAILY_TABLE . "
         WHERE work_date BETWEEN %s AND %s AND is_holiday = 0
           AND (clock_in IS NOT NULL OR clock_out IS NOT NULL)
         ORDER BY work_date, id",
        $start, $end
    ) );

    $updated = 0;
    foreach ( $ids as $id ) {
        if ( mat_recalc_daily_row( (int) $id, $in_unit, $out_unit ) ) $updated++;
    }
    return $updated;
}

function mat_save_settings_handler() {
    if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) {
        wp_die( '権限がありません。' );
    }
    check_admin_referer( 'mat_save_settings' );

    update_option( 'mat_use_password_auth',        isset( $_POST['mat_use_password_auth'] )        ? 1 : 0 );
    update_option( 'mat_use_paid_leave_approval',   isset( $_POST['mat_use_paid_leave_approval'] )   ? 1 : 0 );
    update_option( 'mat_show_paid_leave_request',   isset( $_POST['mat_show_paid_leave_request'] )   ? 1 : 0 );
    update_option( 'mat_allow_log_edit',             isset( $_POST['mat_allow_log_edit'] )             ? 1 : 0 );
    update_option( 'mat_show_overnight_message',     isset( $_POST['mat_show_overnight_message'] )     ? 1 : 0 );
    update_option( 'mat_show_break_message',         isset( $_POST['mat_show_break_message'] )         ? 1 : 0 );
    update_option( 'mat_show_overtime_message',      isset( $_POST['mat_show_overtime_message'] )      ? 1 : 0 );
    update_option( 'mat_show_midnight_message',      isset( $_POST['mat_show_midnight_message'] )      ? 1 : 0 );
    update_option( 'mat_closing_day',               intval( $_POST['mat_closing_day'] ?? 0 ) );

    // 始業・終業の丸め込み単位（0＝丸め込みなし）
    $allowed_units  = array( 0, 15, 30, 60 );
    $clock_in_unit  = intval( $_POST['mat_clock_in_unit']  ?? 30 );
    $clock_out_unit = intval( $_POST['mat_clock_out_unit'] ?? 30 );
    if ( ! in_array( $clock_in_unit, $allowed_units, true ) )   $clock_in_unit = 30;
    if ( ! in_array( $clock_out_unit, $allowed_units, true ) ) $clock_out_unit = 30;
    update_option( 'mat_clock_in_unit', $clock_in_unit );
    update_option( 'mat_clock_out_unit', $clock_out_unit );
    update_option( 'mat_time_unit', $clock_in_unit ); // 旧連携向け互換値

    // 例外休憩アラートの基準
    $alert_mode = ( $_POST['mat_break_alert_mode'] ?? 'auto' ) === 'fixed' ? 'fixed' : 'auto';
    update_option( 'mat_break_alert_mode', $alert_mode );

    // 残業判定の基準労働時間（分）
    $threshold = intval( $_POST['mat_overtime_threshold'] ?? 480 );
    update_option( 'mat_overtime_threshold', $threshold > 0 ? $threshold : 480 );

    // 深夜時間帯（要件定義書 §7.6）：不正な入力の場合は既存値を維持し、この設定だけ保存しない
    $midnight_error      = '';
    $midnight_start_min  = mat_parse_time_to_minutes( sanitize_text_field( $_POST['mat_midnight_start'] ?? '' ) );
    $midnight_end_min    = mat_parse_time_to_minutes( sanitize_text_field( $_POST['mat_midnight_end'] ?? '' ) );

    if ( $midnight_start_min === null || $midnight_end_min === null ) {
        $midnight_error = '深夜時間帯の形式が正しくありません（HH:MM で入力してください）。';
    } elseif ( $midnight_start_min < 0 || $midnight_start_min > 2880 || $midnight_end_min < 0 || $midnight_end_min > 2880 ) {
        $midnight_error = '深夜時間帯は 0〜2880分の範囲で入力してください。';
    } elseif ( $midnight_start_min >= $midnight_end_min ) {
        $midnight_error = '深夜時間帯の開始は終了より前の時刻にしてください。';
    } elseif ( $midnight_end_min - $midnight_start_min > 1440 ) {
        $midnight_error = '深夜時間帯は24時間（1440分）以内で指定してください。';
    } else {
        update_option( 'mat_midnight_start', $midnight_start_min );
        update_option( 'mat_midnight_end', $midnight_end_min );
    }

    // 深夜アラート開始日（空欄可）
    $midnight_alert_since = sanitize_text_field( $_POST['mat_midnight_alert_since'] ?? '' );
    if ( $midnight_alert_since !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $midnight_alert_since ) ) {
        if ( $midnight_error === '' ) $midnight_error = '深夜アラート開始日の形式が正しくありません。';
    } else {
        update_option( 'mat_midnight_alert_since', $midnight_alert_since );
    }

    // 過去データへの適用範囲。実打刻は変更せず、丸め値と関連する深夜値のみ再計算する。
    $rounding_scope = sanitize_text_field( $_POST['mat_rounding_apply_scope'] ?? 'future' );
    $rounding_error = '';
    $rounding_result = '';
    if ( $rounding_scope !== 'future' ) {
        if ( $rounding_scope === 'month' ) {
            $range_start = current_time( 'Y-m-01' );
            $range_end   = current_time( 'Y-m-d' );
        } else {
            $range_start = sanitize_text_field( $_POST['mat_rounding_start'] ?? '' );
            $range_end   = sanitize_text_field( $_POST['mat_rounding_end'] ?? '' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $range_start ?? '' )
            || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $range_end ?? '' )
            || $range_start > $range_end ) {
            $rounding_error = '丸め込みを適用する期間が正しくありません。';
        } else {
            $count = mat_bulk_apply_rounding_units( $range_start, $range_end, $clock_in_unit, $clock_out_unit );
            $rounding_result = sprintf( '%s〜%sの%d件を再計算しました。', $range_start, $range_end, $count );
        }
    }

    $redirect_url = admin_url( 'admin.php?page=mat-settings&saved=1' );
    if ( $midnight_error !== '' ) {
        $redirect_url .= '&mat_midnight_error=' . urlencode( $midnight_error );
    }
    if ( $rounding_error !== '' ) $redirect_url .= '&mat_rounding_error=' . urlencode( $rounding_error );
    if ( $rounding_result !== '' ) $redirect_url .= '&mat_rounding_result=' . urlencode( $rounding_result );
    wp_redirect( $redirect_url );
    exit;
}

/**
 * 深夜該当時間の一括再計算（要件定義書 §9.1）。
 * 指定年月の既存行について丸め値から midnight_span_minutes / midnight_minutes を再計算する。
 * midnight_break_minutes（従業員の申告）は変更しない。
 *
 * @return array{updated:int,skipped:int}
 */
function mat_bulk_recalc_midnight( $year_month ) {
    global $wpdb;
    if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $year_month ) ) {
        return array( 'updated' => 0, 'skipped' => 0 );
    }

    $start = $year_month . '-01';
    $end   = date( 'Y-m-t', strtotime( $start ) );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, clock_in, clock_out, rounded_clock_in, rounded_clock_out, midnight_break_minutes,
                break_out_start, break_out_end
         FROM " . MAT_DAILY_TABLE . "
         WHERE work_date BETWEEN %s AND %s AND is_holiday = 0",
        $start, $end
    ) );

    $updated = 0;
    $skipped = 0;

    foreach ( $rows as $r ) {
        $rounded_in  = ! empty( $r->rounded_clock_in )  ? $r->rounded_clock_in  : $r->clock_in;
        $rounded_out = ! empty( $r->rounded_clock_out ) ? $r->rounded_clock_out : $r->clock_out;

        // 中抜け（Phase 6）が設定されている場合は、その区間を除外して判定する（§12.3）
        $span = mat_calc_midnight_span_minutes( $rounded_in, $rounded_out, $r->break_out_start, $r->break_out_end );
        if ( $span === null ) {
            $skipped++;
            continue;
        }

        $midnight_break   = $r->midnight_break_minutes === null ? null : (int) $r->midnight_break_minutes;
        $midnight_minutes = mat_calc_midnight_minutes( $rounded_in, $rounded_out, $midnight_break, $r->break_out_start, $r->break_out_end );

        $wpdb->update( MAT_DAILY_TABLE,
            array( 'midnight_span_minutes' => $span, 'midnight_minutes' => $midnight_minutes ),
            array( 'id' => (int) $r->id )
        );
        $updated++;
    }

    return array( 'updated' => $updated, 'skipped' => $skipped );
}

add_action( 'admin_post_mat_recalc_midnight', 'mat_recalc_midnight_handler' );
function mat_recalc_midnight_handler() {
    if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) {
        wp_die( '権限がありません。' );
    }
    check_admin_referer( 'mat_recalc_midnight' );

    $year_month = sanitize_text_field( $_POST['mat_recalc_year_month'] ?? '' );
    if ( ! preg_match( '/^\d{4}-\d{2}$/', $year_month ) ) {
        wp_redirect( admin_url( 'admin.php?page=mat-settings&mat_recalc_error=' . urlencode( '対象年月を選択してください。' ) ) );
        exit;
    }

    $result = mat_bulk_recalc_midnight( $year_month );
    $msg    = sprintf( '%s の深夜該当時間を再計算しました（更新 %d件 / スキップ %d件）。', $year_month, $result['updated'], $result['skipped'] );

    wp_redirect( admin_url( 'admin.php?page=mat-settings&mat_recalc_done=' . urlencode( $msg ) ) );
    exit;
}

/**
 * 設定画面のレンダリング
 */
function mat_settings_page_render() {
    if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die( '権限がありません。', '', array( 'response' => 403 ) );

    $use_password         = (bool) get_option( 'mat_use_password_auth', 1 );
    $use_approval         = (bool) get_option( 'mat_use_paid_leave_approval', 1 );
    $show_paid_leave_req  = (bool) get_option( 'mat_show_paid_leave_request', 1 );
    $allow_log_edit       = (bool) get_option( 'mat_allow_log_edit', 0 );
    $show_overnight_msg   = mat_clockout_message_enabled( 'overnight' );
    $show_break_msg       = mat_clockout_message_enabled( 'break' );
    $show_overtime_msg    = mat_clockout_message_enabled( 'overtime' );
    $show_midnight_msg    = mat_clockout_message_enabled( 'midnight' );
    $closing_day     = (int)  get_option( 'mat_closing_day', 0 );
    $clock_in_unit        = mat_get_clock_in_unit();
    $clock_out_unit       = mat_get_clock_out_unit();
    $break_alert_mode     = mat_get_break_alert_mode();
    $overtime_threshold   = mat_get_overtime_threshold();
    $midnight_window      = mat_get_midnight_window();
    $midnight_alert_since = (string) get_option( 'mat_midnight_alert_since', '' );

    $closing_options = array(
        0  => '末日',
        10 => '10日',
        15 => '15日',
        20 => '20日',
        25 => '25日',
        28 => '28日',
    );
    ?>
    <div class="wrap">
        <h1>⚙️ 打刻ツール設定</h1>

        <?php if ( isset( $_GET['saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['mat_midnight_error'] ) ) : ?>
            <div class="notice notice-error is-dismissible">
                <p>深夜時間帯の設定は保存されませんでした：<?php echo esc_html( urldecode( $_GET['mat_midnight_error'] ) ); ?></p>
            </div>
        <?php endif; ?>
        <?php if ( isset( $_GET['mat_rounding_result'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( urldecode( $_GET['mat_rounding_result'] ) ); ?></p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['mat_rounding_error'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( urldecode( $_GET['mat_rounding_error'] ) ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" id="mat-settings-form">
            <?php wp_nonce_field( 'mat_save_settings' ); ?>
            <input type="hidden" name="action" value="mat_save_settings">

            <table class="form-table" role="presentation">

                <!-- パスワード認証 -->
                <tr>
                    <th scope="row">パスワード認証</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mat_use_password_auth" value="1"
                                <?php checked( $use_password ); ?>>
                            パスワード認証を使用する
                        </label>
                        <p class="description">
                            ONにすると社員コードに加えてパスワードでの認証が必要になります。<br>
                            OFFにすると社員コードのみでログインできます。
                        </p>
                    </td>
                </tr>

                <!-- 有給承認フロー -->
                <tr>
                    <th scope="row">有給申請の承認フロー</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mat_use_paid_leave_approval" value="1"
                                <?php checked( $use_approval ); ?>>
                            有給申請の承認フローを使用する（paid-leave-manager 連携）
                        </label>
                        <p class="description">
                            ONにすると有給希望日の申請が paid-leave-manager に送信され、管理者の承認が必要になります。<br>
                            OFFにすると打刻データに記録するのみで承認フローは発生しません。
                        </p>
                        <?php if ( ! function_exists( 'pl_get_request_status' ) ) : ?>
                            <p class="description" style="color:#d63638;">
                                ⚠️ paid-leave-manager が有効化されていません。ONにしても連携は機能しません。
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- 有給申請セクションの表示 -->
                <tr>
                    <th scope="row">有給希望日の申請</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mat_show_paid_leave_request" value="1"
                                <?php checked( $show_paid_leave_req ); ?>>
                            フロントエンドに有給希望日の申請セクションを表示する
                        </label>
                        <p class="description">
                            OFFにすると社員の打刻画面から有給希望日の申請欄と履歴の「有給」列を非表示にします。
                        </p>
                    </td>
                </tr>

                <!-- 打刻編集許可 -->
                <tr>
                    <th scope="row">社員による打刻編集</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mat_allow_log_edit" value="1"
                                <?php checked( $allow_log_edit ); ?>>
                            社員による打刻情報の編集を許可する
                        </label>
                        <p class="description">
                            ONにすると社員が自分の打刻データを編集できます。<br>
                            編集できる期間は「当月（締め日設定に基づく）」のみです。<br>
                            OFFにすると管理者のみが管理画面から編集できます。
                        </p>
                    </td>
                </tr>

                <!-- 締め日 -->
                <tr>
                    <th scope="row">月次締め日</th>
                    <td>
                        <select name="mat_closing_day">
                            <?php foreach ( $closing_options as $val => $label ) : ?>
                                <option value="<?php echo $val; ?>" <?php selected( $closing_day, $val ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            「当月」の区切り日を設定します。打刻編集の可否・有給の月次集計に影響します。<br>
                            例）20日締めの場合、2月15日時点での「当月」は 1月21日〜2月20日 になります。
                        </p>
                        <?php
                        // 当月の期間をプレビュー表示
                        $period = mat_get_current_period();
                        echo '<p class="description" style="color:#0073aa;">'
                            . '現在の当月期間：<strong>'
                            . esc_html( $period['start'] ) . ' 〜 ' . esc_html( $period['end'] )
                            . '</strong></p>';
                        ?>
                    </td>
                </tr>

                <!-- 始業・終業の丸め込み -->
                <tr>
                    <th scope="row">勤務時間の丸め込み</th>
                    <td>
                        <label>始業
                        <select name="mat_clock_in_unit">
                            <?php foreach ( array( 0, 15, 30, 60 ) as $unit ) : ?>
                                <option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $clock_in_unit, $unit ); ?>>
                                    <?php echo $unit === 0 ? '丸め込みなし' : esc_html( $unit ) . '分'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select></label>
                        <label style="margin-left:16px;">終業
                        <select name="mat_clock_out_unit">
                            <?php foreach ( array( 0, 15, 30, 60 ) as $unit ) : ?>
                                <option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $clock_out_unit, $unit ); ?>>
                                    <?php echo $unit === 0 ? '丸め込みなし' : esc_html( $unit ) . '分'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select></label>
                        <p class="description">
                            始業は繰り上げ、終業は切り捨てです。「丸め込みなし」では実打刻と同じ時刻になります。<br>
                            実打刻（clock_in / clock_out）は変更されません。
                        </p>

                        <fieldset style="margin-top:12px; padding:10px 12px; border:1px solid #c3c4c7; max-width:620px;">
                            <legend><strong>新しい設定の適用範囲</strong></legend>
                            <label style="display:block;"><input type="radio" name="mat_rounding_apply_scope" value="future" checked> 今後の打刻だけに適用</label>
                            <label style="display:block; margin-top:6px;"><input type="radio" name="mat_rounding_apply_scope" value="month"> 今月1日から本日までの既存データにも適用</label>
                            <label style="display:block; margin-top:6px;"><input type="radio" name="mat_rounding_apply_scope" value="custom"> 期間を指定して既存データにも適用</label>
                            <div style="margin:6px 0 0 24px;">
                                <input type="date" name="mat_rounding_start"> 〜 <input type="date" name="mat_rounding_end">
                            </div>
                            <p class="description">既存データへ適用すると、実打刻から始業・終業および深夜関連時間を再計算します。</p>
                        </fieldset>
                    </td>
                </tr>

                <!-- 退勤打刻時の確認メッセージ -->
                <tr>
                    <th scope="row">退勤打刻時の確認メッセージ</th>
                    <td>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="checkbox" name="mat_show_overnight_message" value="1"
                                <?php checked( $show_overnight_msg ); ?>>
                            日跨ぎ確認（前日の退勤打刻が未完了）
                        </label>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="checkbox" name="mat_show_break_message" value="1"
                                <?php checked( $show_break_msg ); ?>>
                            例外休憩確認（休憩時間がイレギュラー）
                        </label>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="checkbox" name="mat_show_overtime_message" value="1"
                                <?php checked( $show_overtime_msg ); ?>>
                            残業確認（残業時間が発生）
                        </label>
                        <label style="display:block;">
                            <input type="checkbox" name="mat_show_midnight_message" value="1"
                                <?php checked( $show_midnight_msg ); ?>>
                            深夜休憩確認（深夜時間帯の勤務）
                        </label>
                        <p class="description">
                            チェックを外したメッセージは退勤打刻時に表示されません。<br>
                            オフにしても、勤務時間・残業時間の計算、打刻の保存、管理画面のアラート一覧には影響しません。
                        </p>
                    </td>
                </tr>

                <!-- 残業判定の基準労働時間 -->
                <tr>
                    <th scope="row">残業判定の基準労働時間</th>
                    <td>
                        <input type="number" name="mat_overtime_threshold" min="1" max="1440" step="1"
                            value="<?php echo esc_attr( $overtime_threshold ); ?>" class="small-text"> 分
                        <p class="description">
                            労働時間（拘束時間 − 休憩時間）がこの分数を超えた分を残業時間として計上します。既定 480分（8時間）。
                        </p>
                    </td>
                </tr>

                <!-- 例外休憩アラートの基準 -->
                <tr>
                    <th scope="row">例外休憩アラートの基準</th>
                    <td>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="radio" name="mat_break_alert_mode" value="auto"
                                <?php checked( $break_alert_mode, 'auto' ); ?>>
                            拘束時間から自動判定した区分の休憩時間と比較する（推奨）
                        </label>
                        <label style="display:block;">
                            <input type="radio" name="mat_break_alert_mode" value="fixed"
                                <?php checked( $break_alert_mode, 'fixed' ); ?>>
                            常に「既定」に設定した休憩時間と比較する
                        </label>
                        <p class="description">
                            労働基準法第34条では労働時間6時間超で45分以上、8時間超で60分以上の休憩が必要です。<br>
                            「自動判定」を選ぶと、拘束6〜8時間の日は基準45分として扱われるため、不要なアラートが出ません。
                        </p>
                    </td>
                </tr>

                <!-- 深夜時間帯 -->
                <tr>
                    <th scope="row">深夜時間帯</th>
                    <td>
                        開始
                        <input type="text" name="mat_midnight_start" class="small-text" placeholder="HH:MM"
                            value="<?php echo esc_attr( mat_minutes_to_hm( $midnight_window['start'] ) ); ?>">
                        〜 終了
                        <input type="text" name="mat_midnight_end" class="small-text" placeholder="HH:MM"
                            value="<?php echo esc_attr( mat_minutes_to_hm( $midnight_window['end'] ) ); ?>">
                        <p class="description">
                            ※終了は24時間超（29:00＝翌5:00）で入力します。<br>
                            労働基準法第37条の深夜割増は 22:00〜翌5:00 が原則です。
                        </p>
                    </td>
                </tr>

                <!-- 深夜アラート開始日 -->
                <tr>
                    <th scope="row">深夜アラート開始日</th>
                    <td>
                        <input type="date" name="mat_midnight_alert_since" class="regular-text"
                            value="<?php echo esc_attr( $midnight_alert_since ); ?>">
                        <p class="description">
                            この日付以降の勤務日のみ深夜休憩アラートの対象とします。<br>
                            空欄にすると全期間が対象になります。運用開始前に導入日を設定してください（設定しないと過去の深夜勤務がすべて赤アラートになります）。
                        </p>
                    </td>
                </tr>

            </table>

            <?php submit_button( '設定を保存' ); ?>
        </form>

        <?php mat_render_break_master_section(); ?>

        <?php mat_render_midnight_recalc_section(); ?>
        <script>
        jQuery(function($) {
            $('#mat-settings-form').on('submit', function(e) {
                var scope = $('input[name="mat_rounding_apply_scope"]:checked').val();
                if (scope !== 'future' && !window.confirm('指定した既存データの始業・終業を、実打刻から再計算します。続行しますか？')) {
                    e.preventDefault();
                }
            });
        });
        </script>
    </div>
    <?php
}

/**
 * 深夜該当時間の一括再計算ツール（要件定義書 §9.1）。
 */
function mat_render_midnight_recalc_section() {
    ?>
    <div class="card" style="max-width:700px; margin-top:20px; padding:20px;">
        <h2 style="margin-top:0;">🌙 深夜該当時間の一括再計算</h2>
        <p style="color:#666; font-size:0.9em;">
            指定した年月の既存データについて、丸め値（始業・終業）から深夜該当時間・深夜時間を再計算します。<br>
            深夜休憩（従業員からの申告）は変更しません。深夜時間帯の設定を変更した後や、機能導入直後の既存データに対して実行してください。
        </p>

        <?php if ( isset( $_GET['mat_recalc_done'] ) ) : ?>
            <div class="notice notice-success inline"><p><?php echo esc_html( urldecode( $_GET['mat_recalc_done'] ) ); ?></p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['mat_recalc_error'] ) ) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html( urldecode( $_GET['mat_recalc_error'] ) ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
            <?php wp_nonce_field( 'mat_recalc_midnight' ); ?>
            <input type="hidden" name="action" value="mat_recalc_midnight">
            <input type="month" name="mat_recalc_year_month" required value="<?php echo esc_attr( current_time( 'Y-m' ) ); ?>">
            <input type="submit" class="button button-primary" value="再計算を実行"
                onclick="return confirm('指定した年月の深夜該当時間・深夜時間を再計算します。よろしいですか？');">
        </form>
    </div>
    <?php
}
