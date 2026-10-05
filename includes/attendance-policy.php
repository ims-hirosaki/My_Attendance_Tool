<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** 職種別休憩。職種名ではなくマスタIDで紐付ける。 */
function mat_get_job_break_policy( $employee ) {
    if ( get_option( 'mat_show_break_controls', 1 ) ) return null;
    $default = null;
    foreach ( (array) get_option( 'mat_job_break_rules', array() ) as $rule ) {
        if ( ! empty( $rule['is_default'] ) ) $default = $rule;
        if ( ! empty( $employee->job_type_id ) && in_array( (int) $employee->job_type_id, $rule['job_types'], true ) ) {
            return array( 'minutes' => (int) $rule['minutes'], 'fallback' => false );
        }
    }
    return $default === null ? null : array( 'minutes' => (int) $default['minutes'], 'fallback' => true );
}

/** 自動休憩の確認と確定で同じ判定を使う。修正値は必ずサーバーで検証する。 */
function mat_resolve_job_break( $employee, $row, $rounded_in, $rounded_out, $input = null, $final = false ) {
    $policy = mat_get_job_break_policy( $employee );
    if ( $policy === null ) return null;
    $calc = mat_calc_work_minutes( $rounded_in, $rounded_out, 0, $row->break_out_start ?? null, $row->break_out_end ?? null );
    $master = mat_get_auto_break_master( $calc['kousoku'] ) ?: mat_get_default_break_master();
    $standard = $master ? (int) $master->break_minutes : null;
    $needs_fix = (bool) get_option( 'mat_short_break_alert', 1 ) && $standard !== null && $policy['minutes'] > $standard;
    $minutes = $policy['minutes'];
    if ( $input !== null && $input !== '' ) {
        if ( ! is_scalar( $input ) || ! preg_match( '/^\d+$/', (string) $input ) || (int) $input > 1440 ) {
            return new WP_Error( 'break_invalid', '休憩時間は0〜1440の整数（分）で入力してください。' );
        }
        $minutes = (int) $input;
        if ( $calc['kousoku'] === null || $minutes > $calc['kousoku'] ) {
            return new WP_Error( 'break_invalid', '休憩時間は拘束時間以下にしてください。' );
        }
        if ( $needs_fix && $minutes === $policy['minutes'] ) {
            return new WP_Error( 'break_unchanged', '固定休憩のまま退勤できません。実際の休憩時間に修正してください。' );
        }
        $needs_fix = false;
    }
    if ( $final && $needs_fix ) return new WP_Error( 'break_required', '勤務時間に対して固定休憩が長いため、休憩時間を修正してください。' );
    return array( 'minutes' => $minutes, 'fixed_minutes' => $policy['minutes'], 'standard' => $standard, 'needs_fix' => $needs_fix );
}

/** 全設定の更新前に検証する。 */
function mat_validate_policy_settings( $source ) {
    $enabled = isset( $source['mat_clockin_alert_enabled'] );
    $since = sanitize_text_field( $source['mat_clockin_alert_since'] ?? '' );
    if ( $since !== '' ) {
        $parts = explode( '-', $since );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) || ! checkdate( (int) ( $parts[1] ?? 0 ), (int) ( $parts[2] ?? 0 ), (int) $parts[0] ) ) {
            return new WP_Error( 'date', '出勤アラートの適用開始日が正しくありません。' );
        }
    }
    if ( $enabled && $since === '' ) return new WP_Error( 'date', '出勤アラートの適用開始日を指定してください。' );
    $rules = array();
    $seen = array();
    $defaults = 0;
    $valid_ids = array_map( 'intval', wp_list_pluck( (array) emp_get_job_types(), 'id' ) );
    foreach ( (array) ( $source['mat_job_break_rules'] ?? array() ) as $key => $raw ) {
        if ( ! is_array( $raw ) || ! isset( $raw['minutes'] ) || ! is_scalar( $raw['minutes'] ) || ! preg_match( '/^\d+$/', (string) $raw['minutes'] ) || (int) $raw['minutes'] > 1440 ) {
            return new WP_Error( 'minutes', '固定休憩は0〜1440の整数（分）で入力してください。' );
        }
        $ids = array_map( 'intval', (array) ( $raw['job_types'] ?? array() ) );
        foreach ( $ids as $id ) {
            if ( ! in_array( $id, $valid_ids, true ) ) return new WP_Error( 'job', '存在しない職種が選択されています。職種設定を確認してください。' );
            if ( in_array( $id, $seen, true ) ) return new WP_Error( 'duplicate', '同じ職種を複数の休憩ルールに登録できません。' );
            $seen[] = $id;
        }
        $is_default = (string) ( $source['mat_job_break_default'] ?? '' ) === (string) $key;
        if ( $is_default ) $defaults++;
        $rules[] = array( 'job_types' => $ids, 'minutes' => (int) $raw['minutes'], 'is_default' => $is_default );
    }
    $show = isset( $source['mat_show_break_controls'] );
    if ( ( ! $show || count( $rules ) > 0 ) && $defaults !== 1 ) return new WP_Error( 'default', '固定休憩ルールのデフォルトを1件選択してください。' );
    return array(
        'mat_clockin_alert_enabled' => $enabled ? 1 : 0,
        'mat_clockin_alert_since' => $since,
        'mat_show_break_controls' => $show ? 1 : 0,
        'mat_short_break_alert' => isset( $source['mat_short_break_alert'] ) ? 1 : 0,
        'mat_job_break_rules' => $rules,
    );
}

/** 設定画面の「ONにすると／OFFにすると」説明。 */
function mat_render_onoff_desc( $on, $off, $note = '' ) {
    ?>
    <ul class="mat-onoff">
        <li class="mat-on"><span class="mat-onoff-tag">ON</span><?php echo wp_kses_post( $on ); ?></li>
        <li class="mat-off"><span class="mat-onoff-tag">OFF</span><?php echo wp_kses_post( $off ); ?></li>
    </ul>
    <?php if ( $note !== '' ) : ?><p class="description"><?php echo wp_kses_post( $note ); ?></p><?php endif;
}

/** 社員の打刻画面に関わる休憩設定。 */
function mat_render_policy_settings_employee() {
    $rules = (array) get_option( 'mat_job_break_rules', array() );
    $jobs = (array) emp_get_job_types();
    ?>
    <tr><th>休憩の入力方法</th><td>
        <label><input type="checkbox" name="mat_show_break_controls" <?php checked( get_option( 'mat_show_break_controls', 1 ) ); ?>>社員が自分で休憩時間を入力する</label>
        <?php mat_render_onoff_desc(
            '社員の打刻画面に「休憩スライダー」と「休憩打刻ボタン」が表示され、社員が休憩時間を自分で入力します。',
            '休憩の入力欄が消え、退勤するときに下の表で決めた休憩時間が自動で登録されます。',
            '※OFFにする場合は、下の表で職種ごとの休憩時間を必ず設定してください。表にない職種の社員には「デフォルト」の時間が使われ、画面に「職種が未登録です。管理者にお伝えください。」と表示されます。'
        ); ?>
        <p><strong>職種ごとの自動休憩（上の設定をOFFにした場合に使われます）</strong></p>
        <table class="widefat" id="mat-job-break-rules"><thead><tr><th>デフォルト</th><th>対象の職種（複数選択可）</th><th>自動で入れる休憩（分）</th><th>操作</th></tr></thead><tbody>
        <?php foreach ( $rules as $key => $rule ) mat_render_job_break_rule( $key, $rule, $jobs ); ?>
        </tbody></table>
        <p><button type="button" class="button" id="mat-add-job-break">ルールを追加</button></p>
        <p class="description">「デフォルト」は、どのルールにも当てはまらない職種の社員に使う休憩時間です。1件だけ選んでください。同じ職種を複数のルールに入れることはできません。すでに保存された過去の休憩時間は変わりません。</p>
        <template id="mat-job-break-template"><?php mat_render_job_break_rule( '__INDEX__', array( 'job_types' => array(), 'minutes' => 60, 'is_default' => false ), $jobs ); ?></template>
    </td></tr>
    <tr><th>休憩が長すぎる場合の修正依頼</th><td>
        <label><input type="checkbox" name="mat_short_break_alert" <?php checked( get_option( 'mat_short_break_alert', 1 ) ); ?>>有効にする</label>
        <?php mat_render_onoff_desc(
            '自動休憩の時間が、勤務時間の長さに対する基準（下の「休憩時間マスタ」）より長いとき、社員は退勤前に実際の休憩時間へ修正しないと退勤できません。例：短時間勤務なのに固定休憩が60分入ってしまう場合。',
            '修正は求められず、自動休憩がそのまま登録されます。',
            '※上の「休憩の入力方法」をOFFにしている場合にだけ働く設定です。'
        ); ?>
    </td></tr>
    <script>
    jQuery(function($) {
        var next = <?php echo count( $rules ); ?>;
        $('#mat-add-job-break').on('click', function() {
            $('#mat-job-break-rules tbody').append($('#mat-job-break-template').html().replace(/__INDEX__/g, String(next++)));
        });
        $('#mat-job-break-rules').on('click', '.mat-remove-job-break', function() { $(this).closest('tr').remove(); });
    });
    </script>
    <?php
}

/** 管理者向けのアラート設定（出勤時刻）。 */
function mat_render_policy_settings_admin() {
    ?>
    <tr><th>8時以外の出勤アラート</th><td>
        <label><input type="checkbox" name="mat_clockin_alert_enabled" <?php checked( get_option( 'mat_clockin_alert_enabled', 0 ) ); ?>>有効にする</label>
        <?php mat_render_onoff_desc(
            '始業時刻（丸めた後）が8:00以外の日が、管理画面のアラート一覧と打刻履歴に黄色で表示されます。承認されたものはグレーになり、未承認・却下のものは「未対応」として残ります。',
            '出勤時刻についてのアラートは出ません。'
        ); ?>
        <p><label>この日以降を対象にする <input type="date" name="mat_clockin_alert_since" value="<?php echo esc_attr( get_option( 'mat_clockin_alert_since', '' ) ); ?>"></label></p>
        <p class="description">全社員が対象です。ONにする場合は開始日が必須です。あわせて「勤務時間の丸め込み」の始業を60分にしてください（60分以外だと8:00ちょうどになりにくく、アラートが出すぎます）。</p>
    </td></tr>
    <?php
}

function mat_render_job_break_rule( $key, $rule, $jobs ) {
    ?>
    <tr><td><input type="radio" name="mat_job_break_default" aria-label="デフォルト" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! empty( $rule['is_default'] ) ); ?>></td>
    <td><?php foreach ( $jobs as $job ) : ?>
        <label style="display:inline-block; margin:4px 12px 4px 0;"><input type="checkbox" name="mat_job_break_rules[<?php echo esc_attr( $key ); ?>][job_types][]" value="<?php echo (int) $job->id; ?>" <?php checked( in_array( (int) $job->id, $rule['job_types'], true ) ); ?>><?php echo esc_html( $job->name ); ?></label>
    <?php endforeach; ?></td>
    <td><input type="number" aria-label="自動反映時間（分）" min="0" max="1440" step="1" required name="mat_job_break_rules[<?php echo esc_attr( $key ); ?>][minutes]" value="<?php echo (int) $rule['minutes']; ?>" style="width:90px;"></td>
    <td><button type="button" class="button mat-remove-job-break">削除</button></td></tr>
    <?php
}
