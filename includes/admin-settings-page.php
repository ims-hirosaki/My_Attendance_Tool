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
        'manage_options',
        'mat-settings',
        'mat_settings_page_render'
    );
}

/**
 * 設定の保存処理
 */
add_action( 'admin_post_mat_save_settings', 'mat_save_settings_handler' );
function mat_save_settings_handler() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( '権限がありません。' );
    }
    check_admin_referer( 'mat_save_settings' );

    update_option( 'mat_use_password_auth',        isset( $_POST['mat_use_password_auth'] )        ? 1 : 0 );
    update_option( 'mat_use_paid_leave_approval',   isset( $_POST['mat_use_paid_leave_approval'] )   ? 1 : 0 );
    update_option( 'mat_show_paid_leave_request',   isset( $_POST['mat_show_paid_leave_request'] )   ? 1 : 0 );
    update_option( 'mat_allow_log_edit',             isset( $_POST['mat_allow_log_edit'] )             ? 1 : 0 );
    update_option( 'mat_closing_day',               intval( $_POST['mat_closing_day'] ?? 0 ) );

    // 勤務時間入力単位（15 / 30 / 60分のみ許可）
    $time_unit = intval( $_POST['mat_time_unit'] ?? 30 );
    if ( ! in_array( $time_unit, array( 15, 30, 60 ), true ) ) $time_unit = 30;
    update_option( 'mat_time_unit', $time_unit );

    // 例外休憩アラートの基準
    $alert_mode = ( $_POST['mat_break_alert_mode'] ?? 'auto' ) === 'fixed' ? 'fixed' : 'auto';
    update_option( 'mat_break_alert_mode', $alert_mode );

    // 残業判定の基準労働時間（分）
    $threshold = intval( $_POST['mat_overtime_threshold'] ?? 480 );
    update_option( 'mat_overtime_threshold', $threshold > 0 ? $threshold : 480 );

    wp_redirect( admin_url( 'admin.php?page=mat-settings&saved=1' ) );
    exit;
}

/**
 * 設定画面のレンダリング
 */
function mat_settings_page_render() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $use_password         = (bool) get_option( 'mat_use_password_auth', 1 );
    $use_approval         = (bool) get_option( 'mat_use_paid_leave_approval', 1 );
    $show_paid_leave_req  = (bool) get_option( 'mat_show_paid_leave_request', 1 );
    $allow_log_edit       = (bool) get_option( 'mat_allow_log_edit', 0 );
    $closing_day     = (int)  get_option( 'mat_closing_day', 0 );
    $time_unit            = mat_get_time_unit();
    $break_alert_mode     = mat_get_break_alert_mode();
    $overtime_threshold   = mat_get_overtime_threshold();

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

        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
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

                <!-- 勤務時間入力単位 -->
                <tr>
                    <th scope="row">勤務時間入力単位</th>
                    <td>
                        <select name="mat_time_unit">
                            <?php foreach ( array( 15, 30, 60 ) as $unit ) : ?>
                                <option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $time_unit, $unit ); ?>>
                                    <?php echo esc_html( $unit ); ?>分
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            出勤・退勤の打刻時刻を丸め込む単位です。<br>
                            <strong>出勤は繰り上げ（遅い方）、退勤は切り捨て（早い方）</strong>に丸め込まれます。<br>
                            例）30分単位のとき　出勤 8:25 → 始業 8:30 ／ 出勤 8:31 → 始業 9:00 ／ 退勤 17:55 → 終業 17:30<br>
                            実打刻（clock_in / clock_out）は改変されず、丸め込み後の値は「始業 / 終業」として別に保持されます。
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

            </table>

            <?php submit_button( '設定を保存' ); ?>
        </form>

        <?php mat_render_break_master_section(); ?>
    </div>
    <?php
}
