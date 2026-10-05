<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 設定画面の登録・処理
 */
add_action( 'admin_menu', 'mat_register_settings_page', 13 );
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

    $policy_settings = mat_validate_policy_settings( wp_unslash( $_POST ) );
    if ( is_wp_error( $policy_settings ) ) {
        wp_die( esc_html( $policy_settings->get_error_message() ), '設定エラー', array( 'back_link' => true ) );
    }
    foreach ( $policy_settings as $key => $value ) update_option( $key, $value );

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

        <style>
            .mat-guide { max-width:960px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:12px 18px; margin:12px 0 4px; }
            .mat-guide p { margin:4px 0; }
            .mat-guide a { text-decoration:none; font-weight:600; }
            .mat-sec-head { max-width:960px; margin:28px 0 0; padding:12px 18px; border-radius:4px 4px 0 0; color:#fff; box-sizing:border-box; }
            .mat-sec-head h2 { color:#fff; margin:0; padding:0; font-size:1.25em; }
            .mat-sec-head p { margin:4px 0 0; color:#fff; opacity:.95; }
            .mat-sec-head.mat-user  { background:#2271b1; }
            .mat-sec-head.mat-admin { background:#8a4b08; }
            .mat-sec-sub { max-width:960px; margin:18px 0 0; padding:6px 12px; background:#f0f0f1; border-left:4px solid #8c8f94; font-weight:600; box-sizing:border-box; }
            .mat-sec-table { max-width:960px; background:#fff; border:1px solid #c3c4c7; border-top:0; }
            .mat-sec-table th { width:210px; padding-left:18px; }
            .mat-onoff { margin:8px 0 4px; padding:0; list-style:none; }
            .mat-onoff li { margin:3px 0; padding:6px 10px; border-radius:3px; line-height:1.6; }
            .mat-onoff .mat-on  { background:#edf7ed; }
            .mat-onoff .mat-off { background:#f6f7f7; }
            .mat-onoff-tag { display:inline-block; min-width:38px; margin-right:8px; padding:0 6px; border-radius:3px; color:#fff; font-size:.8em; font-weight:700; text-align:center; }
            .mat-on .mat-onoff-tag { background:#1a7f37; }
            .mat-off .mat-onoff-tag { background:#6c7781; }
            .mat-sec-table .description { max-width:760px; }
        </style>

        <div class="mat-guide">
            <p><strong>この画面は2つに分かれています。</strong></p>
            <p><a href="#mat-sec-user" style="color:#2271b1;">👤 社員向けの設定</a>：社員が使う打刻画面の見え方や、できること（パスワード、休憩の入力、有給申請など）を決めます。</p>
            <p><a href="#mat-sec-admin" style="color:#8a4b08;">🛠 管理者向けの設定</a>：勤務時間の計算ルールや、管理画面に出るアラートの基準を決めます。社員の画面には表示されません。</p>
            <p class="description">設定は、ページ下の「設定を保存」ボタンを押すまで反映されません。「休憩時間マスタ」と「深夜時間の再計算」だけは、それぞれ専用のボタンで保存・実行します。</p>
        </div>

        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" id="mat-settings-form">
            <?php wp_nonce_field( 'mat_save_settings' ); ?>
            <input type="hidden" name="action" value="mat_save_settings">

            <!-- ============ 社員向け ============ -->
            <div class="mat-sec-head mat-user" id="mat-sec-user">
                <h2>👤 社員向けの設定</h2>
                <p>社員が使う打刻画面（フロント画面）の動きや見え方が変わります。</p>
            </div>
            <table class="form-table mat-sec-table" role="presentation">
                <tr>
                    <th scope="row">ログイン時のパスワード</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mat_use_password_auth" value="1" <?php checked( $use_password ); ?>>
                            パスワードを使う
                        </label>
                        <?php mat_render_onoff_desc(
                            '社員はログイン時に「社員コード」と「パスワード」の両方を入力します。他の人のなりすましを防げます。',
                            '「社員コード」だけでログインできます。手軽ですが、社員コードを知っていれば誰でも打刻できてしまいます。'
                        ); ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">有給希望日の申請欄</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mat_show_paid_leave_request" value="1" <?php checked( $show_paid_leave_req ); ?>>
                            打刻画面に表示する
                        </label>
                        <?php mat_render_onoff_desc(
                            '社員の打刻画面に「有給希望日の申請」欄が出ます。履歴にも「有給」列が表示されます。',
                            '申請欄と履歴の「有給」列が社員の画面から消えます。社員は打刻画面から有給を申請できなくなります。'
                        ); ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">有給申請の承認</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mat_use_paid_leave_approval" value="1" <?php checked( $use_approval ); ?>>
                            管理者の承認を必要にする（paid-leave-manager と連携）
                        </label>
                        <?php mat_render_onoff_desc(
                            '社員が有給希望日を申請すると paid-leave-manager に送られ、管理者が承認するまで確定しません。',
                            '承認の手続きはありません。申請した日は、そのまま打刻データに記録されるだけです。',
                            '※上の「有給希望日の申請欄」をOFFにしている場合は、社員が申請できないため、この設定は使われません。'
                        ); ?>
                        <?php if ( ! function_exists( 'pl_get_request_status' ) ) : ?>
                            <p class="description" style="color:#d63638;">
                                ⚠️ paid-leave-manager プラグインが有効になっていません。ONにしても承認の連携は動きません。
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">打刻の修正（社員本人）</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mat_allow_log_edit" value="1" <?php checked( $allow_log_edit ); ?>>
                            社員が自分で打刻を直せるようにする
                        </label>
                        <?php mat_render_onoff_desc(
                            '社員が、自分の打刻（出勤・退勤・休憩など）を自分で修正できます。直せるのは「今月」の分だけです（今月の範囲は「月次締め日」で決まります）。',
                            '社員は修正できません。間違いがあったときは、管理者が管理画面から直します。'
                        ); ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">退勤ボタンを押したときの確認メッセージ</th>
                    <td>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="checkbox" name="mat_show_overnight_message" value="1" <?php checked( $show_overnight_msg ); ?>>
                            <strong>日またぎの確認</strong>（前日の退勤打刻を忘れているとき）
                        </label>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="checkbox" name="mat_show_break_message" value="1" <?php checked( $show_break_msg ); ?>>
                            <strong>休憩の確認</strong>（休憩時間がいつもと違うとき）
                        </label>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="checkbox" name="mat_show_overtime_message" value="1" <?php checked( $show_overtime_msg ); ?>>
                            <strong>残業の確認</strong>（残業時間が出たとき）
                        </label>
                        <label style="display:block;">
                            <input type="checkbox" name="mat_show_midnight_message" value="1" <?php checked( $show_midnight_msg ); ?>>
                            <strong>深夜の休憩確認</strong>（深夜の時間帯に働いたとき）
                        </label>
                        <?php mat_render_onoff_desc(
                            'その条件に当てはまる日に退勤すると、社員の画面に確認メッセージが表示されます。社員が気づいて直すきっかけになります。',
                            'メッセージが表示されなくなります。メッセージを出さないだけなので、打刻の保存・勤務時間や残業の計算・管理画面のアラート一覧は今までどおりです。',
                            '※4つそれぞれ個別に切り替えられます。'
                        ); ?>
                    </td>
                </tr>

                <?php mat_render_policy_settings_employee(); ?>
            </table>

            <!-- ============ 管理者向け：計算ルール ============ -->
            <div class="mat-sec-head mat-admin" id="mat-sec-admin">
                <h2>🛠 管理者向けの設定</h2>
                <p>管理画面での集計や判定のルールです。社員の画面には表示されません。</p>
            </div>
            <div class="mat-sec-sub">① 勤務時間の計算ルール</div>
            <table class="form-table mat-sec-table" role="presentation">
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
                            「今月」の区切りになる日です。社員が打刻を直せる期間や、有給の月ごとの集計に使われます。<br>
                            例）20日締めの場合、2月15日時点の「今月」は 1月21日〜2月20日 です。
                        </p>
                        <?php
                        $period = mat_get_current_period();
                        echo '<p class="description" style="color:#0073aa;">'
                            . '現在の「今月」の期間：<strong>'
                            . esc_html( $period['start'] ) . ' 〜 ' . esc_html( $period['end'] )
                            . '</strong></p>';
                        ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">勤務時間の丸め込み</th>
                    <td>
                        <label>始業（出勤）
                        <select name="mat_clock_in_unit">
                            <?php foreach ( array( 0, 15, 30, 60 ) as $unit ) : ?>
                                <option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $clock_in_unit, $unit ); ?>>
                                    <?php echo $unit === 0 ? '丸め込みなし' : esc_html( $unit ) . '分'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select></label>
                        <label style="margin-left:16px;">終業（退勤）
                        <select name="mat_clock_out_unit">
                            <?php foreach ( array( 0, 15, 30, 60 ) as $unit ) : ?>
                                <option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $clock_out_unit, $unit ); ?>>
                                    <?php echo $unit === 0 ? '丸め込みなし' : esc_html( $unit ) . '分'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select></label>
                        <p class="description">
                            給与計算に使う「始業」「終業」の時刻を、打刻した時刻から何分単位で整えるかを決めます。<br>
                            ・始業は<strong>切り上げ</strong>（例：30分単位なら 8:10 に出勤 → 始業 8:30）<br>
                            ・終業は<strong>切り捨て</strong>（例：30分単位なら 17:20 に退勤 → 終業 17:00）<br>
                            「丸め込みなし」にすると、打刻した時刻がそのまま始業・終業になります。<br>
                            実際に打刻した時刻（実打刻）は変わらず、そのまま残ります。
                        </p>

                        <fieldset style="margin-top:12px; padding:10px 12px; border:1px solid #c3c4c7; max-width:620px;">
                            <legend><strong>変更をいつの分から反映するか</strong></legend>
                            <label style="display:block;"><input type="radio" name="mat_rounding_apply_scope" value="future" checked> 今後の打刻だけ（過去の記録は変えない）</label>
                            <label style="display:block; margin-top:6px;"><input type="radio" name="mat_rounding_apply_scope" value="month"> 今月1日から今日までの記録にも反映する</label>
                            <label style="display:block; margin-top:6px;"><input type="radio" name="mat_rounding_apply_scope" value="custom"> 期間を指定して過去の記録にも反映する</label>
                            <div style="margin:6px 0 0 24px;">
                                <input type="date" name="mat_rounding_start"> 〜 <input type="date" name="mat_rounding_end">
                            </div>
                            <p class="description">過去の記録にも反映すると、実打刻から始業・終業と深夜関連の時間を計算し直します。迷ったら「今後の打刻だけ」を選んでください。</p>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th scope="row">残業にする基準</th>
                    <td>
                        <input type="number" name="mat_overtime_threshold" min="1" max="1440" step="1"
                            value="<?php echo esc_attr( $overtime_threshold ); ?>" class="small-text"> 分
                        <p class="description">
                            1日の実働時間（拘束時間 − 休憩時間）がこの時間を超えた分を「残業」として計算します。<br>
                            初期値は480分（＝8時間）です。例：480分のとき、9時間働いた日の残業は1時間になります。
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">深夜とみなす時間帯</th>
                    <td>
                        開始
                        <input type="text" name="mat_midnight_start" class="small-text" placeholder="HH:MM"
                            value="<?php echo esc_attr( mat_minutes_to_hm( $midnight_window['start'] ) ); ?>">
                        〜 終了
                        <input type="text" name="mat_midnight_end" class="small-text" placeholder="HH:MM"
                            value="<?php echo esc_attr( mat_minutes_to_hm( $midnight_window['end'] ) ); ?>">
                        <p class="description">
                            この時間帯に働いた分を「深夜時間」として集計します。翌日の時刻は24時間を超えた形で入力します（例：翌朝5:00 → 29:00）。<br>
                            労働基準法では、深夜の割増賃金は 22:00〜翌5:00 が基本です。特別な事情がなければ初期値のままにしてください。
                        </p>
                    </td>
                </tr>
            </table>

            <!-- ============ 管理者向け：アラート ============ -->
            <div class="mat-sec-sub">② 管理画面に出すアラートの基準</div>
            <table class="form-table mat-sec-table" role="presentation">
                <tr>
                    <th scope="row">休憩アラートの判定方法</th>
                    <td>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="radio" name="mat_break_alert_mode" value="auto" <?php checked( $break_alert_mode, 'auto' ); ?>>
                            <strong>勤務時間に合わせて自動で判定する（おすすめ）</strong>
                        </label>
                        <label style="display:block;">
                            <input type="radio" name="mat_break_alert_mode" value="fixed" <?php checked( $break_alert_mode, 'fixed' ); ?>>
                            <strong>いつも同じ基準で判定する</strong>（「休憩時間マスタ」で「既定」にした休憩時間）
                        </label>
                        <p class="description">
                            休憩が基準と違う日に、管理画面でアラートを出すときの「基準」の決め方です。<br>
                            ・<strong>自動</strong>：拘束時間に応じた休憩時間を基準にします（下の「休憩時間マスタ」の自動判定の行）。法律上は、6時間を超えると45分以上、8時間を超えると60分以上の休憩が必要です。6〜8時間働いた日は45分が基準になるため、不要なアラートが出ません。<br>
                            ・<strong>固定</strong>：勤務時間に関係なく、常に既定の休憩時間（例：60分）と比べます。短時間の日でもアラートが出やすくなります。
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">深夜アラートの開始日</th>
                    <td>
                        <input type="date" name="mat_midnight_alert_since" class="regular-text"
                            value="<?php echo esc_attr( $midnight_alert_since ); ?>">
                        <p class="description">
                            深夜の休憩アラート（赤）を出す対象を、この日以降の勤務日に限ります。<br>
                            空欄のままだと過去すべての日が対象になり、過去の深夜勤務がまとめて赤いアラートになります。<strong>この機能を使い始める日（導入日）を入れておくのがおすすめです。</strong>
                        </p>
                    </td>
                </tr>

                <?php mat_render_policy_settings_admin(); ?>
            </table>

            <?php submit_button( '設定を保存' ); ?>
        </form>

        <div class="mat-sec-head mat-admin" style="margin-top:36px;">
            <h2>🛠 管理者向け：マスタ設定・まとめて計算</h2>
            <p>上の「設定を保存」とは別のボタンで保存・実行します。</p>
        </div>
        <div style="max-width:960px; background:#fff; border:1px solid #c3c4c7; border-top:0; padding:0 18px 18px; box-sizing:border-box;">
            <?php mat_render_break_master_section(); ?>
            <?php mat_render_midnight_recalc_section(); ?>
        </div>

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
        <h2 style="margin-top:0;">🌙 深夜時間の再計算（過去分のやり直し）</h2>
        <p style="color:#666; font-size:0.9em;">
            選んだ月の記録について、始業・終業の時刻から「深夜に働いた時間」を計算し直します。<br>
            社員が申告した深夜の休憩は変わりません。「深夜とみなす時間帯」を変更した後や、この機能を使い始めた直後に、過去の記録へ反映したいときに実行してください。
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
