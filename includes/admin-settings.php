<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 管理メニュー登録
 */
add_action( 'admin_menu', 'mat_register_admin_menu' );
function mat_register_admin_menu() {
    add_menu_page(
        '打刻管理ツール',
        '打刻管理',
        'manage_options',
        'my-attendance-settings',
        'mat_history_page_render',
        'dashicons-calendar-alt',
        30
    );
    add_submenu_page(
        'my-attendance-settings',
        '打刻履歴',
        '打刻履歴',
        'manage_options',
        'my-attendance-settings',
        'mat_history_page_render'
    );
}

/**
 * 管理画面用スタイル・スクリプトの読み込み
 */
add_action( 'admin_enqueue_scripts', 'mat_admin_enqueue' );
function mat_admin_enqueue( $hook ) {
    $mat_pages = array(
        'toplevel_page_my-attendance-settings',
        '打刻管理_page_my-attendance-settings',
        '打刻管理_page_mat-auth-management',
        '打刻管理_page_mat-settings',
        '打刻管理_page_mat-test-data',
    );
    if ( ! in_array( $hook, $mat_pages, true ) ) return;

    // employee-manager の admin.css を流用
    $emp_css = WP_PLUGIN_DIR . '/employee-manager/admin/assets/admin.css';
    if ( file_exists( $emp_css ) ) {
        wp_enqueue_style(
            'employee-manager-admin',
            plugins_url( 'employee-manager/admin/assets/admin.css' )
        );
    }
}

/**
 * 管理画面：打刻編集 Ajax（管理者用）
 */
add_action( 'wp_ajax_mat_admin_edit_log', 'mat_admin_edit_log_handler' );
function mat_admin_edit_log_handler() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '権限がありません。' );
    }
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;

    $id         = intval( $_POST['id'] ?? 0 );
    $clock_in   = sanitize_text_field( $_POST['clock_in']   ?? '' );
    $clock_out  = sanitize_text_field( $_POST['clock_out']  ?? '' );
    $break_hhmm = sanitize_text_field( $_POST['break_time'] ?? '00:00' );
    $paid_leave = sanitize_text_field( $_POST['paid_leave'] ?? '' );
    $note       = sanitize_textarea_field( $_POST['note']   ?? '' );

    if ( ! preg_match( '/^\d{2}:\d{2}$/', $break_hhmm ) ) $break_hhmm = '00:00';

    $log = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . MAT_LOG_TABLE . " WHERE id = %d", $id
    ) );

    if ( ! $log ) {
        wp_send_json_error( 'レコードが見つかりません。' );
    }

    // item_name 再構築
    $parts = array();
    if ( $clock_in  !== '' ) $parts[] = "出勤: {$clock_in}";
    if ( $clock_out !== '' ) $parts[] = "退勤: {$clock_out}";
    $parts[] = "休憩: {$break_hhmm}";
    if ( $note !== '' )      $parts[] = "備考: {$note}";

    $update = array( 'item_name' => implode( ' | ', $parts ) );
    if ( $paid_leave !== '' ) {
        $update['paid_leave_date'] = $paid_leave;
    } else {
        $update['paid_leave_date'] = null;
    }

    $updated = $wpdb->update(
        MAT_LOG_TABLE,
        $update,
        array( 'id' => $id ),
        array( '%s', '%s' ),
        array( '%d' )
    );

    if ( $updated === false ) {
        wp_send_json_error( '更新失敗: ' . $wpdb->last_error );
    }

    wp_send_json_success( array(
        'clock_in'   => $clock_in  !== '' ? $clock_in  : '-',
        'clock_out'  => $clock_out !== '' ? $clock_out : '-',
        'break_time' => $break_hhmm,
        'paid_leave' => $paid_leave !== '' ? date( 'm/d', strtotime( $paid_leave ) ) : '-',
        'note'       => $note,
    ) );
}

/**
 * 打刻履歴ページのレンダリング
 */
function mat_history_page_render() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // employee-manager から在籍社員一覧・職種マスタを取得
    $employees = emp_get_active_employees();
    $job_types = emp_get_job_types(); // wp_mst_job_type から動的取得

    // ---- JS用: 全従業員データを配列化 ----
    $emp_js_data = array();
    foreach ( $employees as $emp ) {
        $emp_js_data[] = array(
            'code'     => $emp->employee_code,
            'name'     => $emp->name,
            'job_type' => isset( $emp->job_type_name ) ? $emp->job_type_name : '',
        );
    }

    // ---- JS用: 職種名一覧 ----
    $job_type_names = array();
    foreach ( $job_types as $jt ) {
        $job_type_names[] = $jt->name;
    }

    // 選択中の社員を決定
    $selected_code = isset( $_GET['employee_code'] )
        ? sanitize_text_field( $_GET['employee_code'] )
        : ( ! empty( $employees ) ? $employees[0]->employee_code : '' );

    $view_month = isset( $_GET['view_month'] )
        ? sanitize_text_field( $_GET['view_month'] )
        : date( 'Y-m' );

    // 選択社員の emp_master_id を取得
    $selected_emp = null;
    foreach ( $employees as $emp ) {
        if ( $emp->employee_code === $selected_code ) {
            $selected_emp = $emp;
            break;
        }
    }

    $logs            = array();
    $work_days_count = 0;
    $total_days      = (int) date( 't', strtotime( $view_month . '-01' ) );

    if ( $selected_emp ) {
        $data            = mat_get_grouped_data( $selected_emp->id, $view_month );
        $logs            = $data['logs'];
        $work_days_count = $data['work_days_count'];
        $total_days      = $data['total_days'];
    }
    ?>
    <div class="wrap">
        <h1>📋 従業員打刻履歴</h1>

        <!-- ========== 職種フィルターチップ + 絞り込みフォーム ========== -->
        <div class="card" style="max-width:100%; margin-top:20px; padding:15px;">

            <?php if ( ! empty( $job_types ) ) : ?>
            <!-- 職種フィルターチップ -->
            <div style="margin-bottom:12px;">
                <span style="font-size:0.85em; font-weight:600; color:#555; margin-right:8px; vertical-align:middle;">
                    職種フィルター：
                </span>
                <div id="mat-job-type-chips" style="display:inline-flex; flex-wrap:wrap; gap:6px; vertical-align:middle;">
                    <?php foreach ( $job_types as $jt ) : ?>
                        <button type="button"
                            class="mat-chip"
                            data-job-type="<?php echo esc_attr( $jt->name ); ?>"
                            style="
                                display:inline-flex; align-items:center; gap:4px;
                                padding:4px 12px; border-radius:20px; border:1.5px solid #2271b1;
                                background:#2271b1; color:#fff;
                                font-size:0.82em; font-weight:600; cursor:pointer;
                                transition:background .15s, color .15s;
                                line-height:1.5;
                            ">
                            <span class="mat-chip-dot" style="
                                display:inline-block; width:7px; height:7px;
                                border-radius:50%; background:#fff;
                                transition:background .15s;
                            "></span>
                            <?php echo esc_html( $jt->name ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="mat-chip-all-on"
                    style="margin-left:10px; font-size:0.78em; color:#2271b1; background:none; border:none; cursor:pointer; text-decoration:underline; vertical-align:middle;">
                    全ON
                </button>
                <button type="button" id="mat-chip-all-off"
                    style="margin-left:4px; font-size:0.78em; color:#888; background:none; border:none; cursor:pointer; text-decoration:underline; vertical-align:middle;">
                    全OFF
                </button>
            </div>
            <?php endif; ?>

            <!-- 絞り込みフォーム -->
            <form method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="page" value="my-attendance-settings">
                <label>従業員：
                    <select name="employee_code" id="mat-employee-select">
                        <?php foreach ( $employees as $emp ) : ?>
                            <option value="<?php echo esc_attr( $emp->employee_code ); ?>"
                                data-job-type="<?php echo esc_attr( isset( $emp->job_type_name ) ? $emp->job_type_name : '' ); ?>"
                                <?php selected( $selected_code, $emp->employee_code ); ?>>
                                [<?php echo esc_html( $emp->employee_code ); ?>] <?php echo esc_html( $emp->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>表示月：
                    <input type="month" name="view_month" value="<?php echo esc_attr( $view_month ); ?>">
                </label>
                <input type="submit" class="button button-primary" value="表示">
            </form>
        </div>

        <?php if ( $selected_emp ) : ?>
            <h2 style="margin-top:24px;">
                勤務実績：<strong><?php echo esc_html( $work_days_count ); ?></strong>
                / <?php echo esc_html( $total_days ); ?> 日
                <small style="font-size:0.7em; color:#666; margin-left:12px;">
                    （<?php echo esc_html( $view_month ); ?>）
                </small>
            </h2>

            <table class="widefat fixed striped" style="margin-top:10px;">
                <thead>
                    <tr>
                        <th style="width:110px;">日付</th>
                        <th style="width:80px;">出勤</th>
                        <th style="width:80px;">退勤</th>
                        <th style="width:80px;">休憩</th>
                        <th style="width:110px; color:#d63638;">有給希望日</th>
                        <th>備考</th>
                        <th style="width:100px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr><td colspan="7" style="text-align:center; padding:20px;">データがありません。</td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $day ) : ?>
                            <tr data-id="<?php echo esc_attr( $day['id'] ); ?>">
                                <td><?php echo esc_html( $day['date'] ); ?></td>
                                <td class="col-in"><?php echo esc_html( $day['in'] ); ?></td>
                                <td class="col-out"><?php echo esc_html( $day['out'] ); ?></td>
                                <td class="col-break"><?php echo esc_html( $day['break'] ); ?></td>
                                <td class="col-paid" style="font-weight:bold; color:#d63638;">
                                    <?php echo esc_html( $day['paid_leave'] ); ?>
                                </td>
                                <td class="col-notes">
                                    <?php echo esc_html( is_array( $day['notes'] ) ? implode( ' / ', $day['notes'] ) : '' ); ?>
                                </td>
                                <td>
                                    <button class="button button-small edit-log"
                                        data-id="<?php echo esc_attr( $day['id'] ); ?>"
                                        data-in="<?php echo esc_attr( $day['in'] === '-' ? '' : $day['in'] ); ?>"
                                        data-out="<?php echo esc_attr( $day['out'] === '-' ? '' : $day['out'] ); ?>"
                                        data-break="<?php echo esc_attr( $day['break'] === '-' ? '00:00' : $day['break'] ); ?>"
                                        data-paid="<?php echo esc_attr( isset( $day['paid_leave'] ) ? $day['paid_leave'] : '' ); ?>"
                                        data-notes="<?php echo esc_attr( is_array( $day['notes'] ) ? implode( ' / ', $day['notes'] ) : '' ); ?>">
                                        ✏️ 編集
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div><!-- /.wrap -->

    <!-- ========== 編集モーダル ========== -->
    <div id="mat-edit-modal" style="
        display:none; position:fixed; top:0; left:0; right:0; bottom:0;
        background:rgba(0,0,0,.5); z-index:99999;
        justify-content:center; align-items:center;">
        <div style="
            background:#fff; border-radius:8px; padding:24px;
            width:420px; max-width:90%; box-shadow:0 4px 20px rgba(0,0,0,.3);">
            <h3 style="margin:0 0 16px;">✏️ 打刻データの編集</h3>
            <table class="form-table" style="margin:0;">
                <tr>
                    <td style="padding:9px 4px; width:120px; font-weight:bold;">出勤時刻</td>
                    <td style="padding:9px 4px;">
                        <input type="time" id="edit-in" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                    </td>
                </tr>
                <tr>
                    <td style="padding:9px 4px; font-weight:bold;">退勤時刻</td>
                    <td style="padding:9px 4px;">
                        <input type="time" id="edit-out" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                    </td>
                </tr>
                <tr>
                    <td style="padding:9px 4px; font-weight:bold;">休憩時間</td>
                    <td style="padding:9px 4px;">
                        <input type="time" id="edit-break" value="00:00"
                            style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                        <small style="color:#999;">例: 1時間なら 01:00</small>
                    </td>
                </tr>
                <tr>
                    <td style="padding:9px 4px; font-weight:bold; color:#d63638;">有給希望日</td>
                    <td style="padding:9px 4px;">
                        <input type="date" id="edit-paid" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                        <small style="color:#999;">クリアする場合は日付を消してください</small>
                    </td>
                </tr>
                <tr>
                    <td style="padding:9px 4px; font-weight:bold; vertical-align:top; padding-top:13px;">備考</td>
                    <td style="padding:9px 4px;">
                        <textarea id="edit-notes" rows="3"
                            style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;"></textarea>
                    </td>
                </tr>
            </table>

            <p id="edit-error" style="color:#d63638; margin:10px 0 0; display:none; font-size:.9em;"></p>

<div style="margin-top:20px; display:flex; gap:10px; justify-content:space-between; align-items:center;">
    <button type="button" id="edit-delete" class="button"
        style="color:#d63638; border-color:#d63638;">
        🗑️ 削除
    </button>
    <div style="display:flex; gap:10px;">
        <button type="button" id="edit-cancel" class="button">キャンセル</button>
        <button type="button" id="edit-save" class="button button-primary">💾 保存する</button>
    </div>
</div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {

        // =========================================================
        //  職種フィルターチップ
        // =========================================================

        var STORAGE_KEY   = 'mat_history_job_type_filter';
        // デフォルトでOFFにする職種名リスト（職種マスタにあっても初回はOFF）
        var DEFAULT_OFF   = ['長距離', '郵便'];

        // PHPから渡された全従業員データ
        var allEmployees  = <?php echo json_encode( $emp_js_data, JSON_UNESCAPED_UNICODE ); ?>;
        // PHPから渡された全職種名
        var allJobTypes   = <?php echo json_encode( $job_type_names, JSON_UNESCAPED_UNICODE ); ?>;
        // 現在の選択社員コード
        var selectedCode  = <?php echo json_encode( $selected_code ); ?>;

        /**
         * localStorageからチップ状態を読み込む
         * - 保存済みのキーはそのまま使用
         * - 新しい職種（保存されていないキー）は DEFAULT_OFF に含まれなければ ON
         */
        function loadChipState() {
            var stored = {};
            try {
                stored = JSON.parse( localStorage.getItem( STORAGE_KEY ) || '{}' );
            } catch(e) {}

            var state = {};
            allJobTypes.forEach( function(name) {
                if ( typeof stored[name] !== 'undefined' ) {
                    // 保存済みの状態を引き継ぐ
                    state[name] = stored[name];
                } else {
                    // 新規職種: DEFAULT_OFF に含まれていなければ ON
                    state[name] = ( DEFAULT_OFF.indexOf(name) === -1 );
                }
            });
            return state;
        }

        /**
         * チップ状態を保存する
         */
        function saveChipState( state ) {
            try {
                localStorage.setItem( STORAGE_KEY, JSON.stringify(state) );
            } catch(e) {}
        }

        /**
         * チップのビジュアルを状態に合わせて更新する
         */
        function renderChips( state ) {
            $('#mat-job-type-chips .mat-chip').each( function() {
                var name = $(this).data('job-type');
                var on   = !! state[name];
                if (on) {
                    $(this).css({
                        'background' : '#2271b1',
                        'color'      : '#fff',
                        'border-color': '#2271b1',
                        'opacity'    : '1',
                    });
                    $(this).find('.mat-chip-dot').css('background', '#fff');
                } else {
                    $(this).css({
                        'background' : '#f0f0f1',
                        'color'      : '#777',
                        'border-color': '#ccc',
                        'opacity'    : '1',
                    });
                    $(this).find('.mat-chip-dot').css('background', '#bbb');
                }
            });
        }

        /**
         * チップ状態に基づいて従業員セレクトを再構築する
         */
        function rebuildSelect( state ) {
            var $select       = $('#mat-employee-select');
            var currentVal    = $select.val() || selectedCode;
            var activeTypes   = [];

            // ONになっている職種を収集
            allJobTypes.forEach( function(name) {
                if ( state[name] ) activeTypes.push(name);
            });

            // フィルター済み従業員リストを作成
            // 「職種なし（空文字）」の従業員は常に表示
            var filtered = allEmployees.filter( function(emp) {
                if ( emp.job_type === '' || emp.job_type === null ) return true;
                return activeTypes.indexOf( emp.job_type ) !== -1;
            });

            // セレクトを再構築
            $select.empty();
            var foundCurrent = false;
            filtered.forEach( function(emp) {
                var opt = $('<option>', {
                    value: emp.code,
                    text : '[' + emp.code + '] ' + emp.name,
                });
                if ( emp.code === currentVal ) {
                    opt.prop('selected', true);
                    foundCurrent = true;
                }
                $select.append(opt);
            });

            // 現在選択中の社員が非表示になった場合は先頭を選択
            if ( ! foundCurrent && filtered.length > 0 ) {
                $select.find('option:first').prop('selected', true);
            }

            // 絞り込まれた件数を表示
            var total = allEmployees.length;
            var shown = filtered.length;
            var $label = $('#mat-chip-filter-count');
            if ( $label.length === 0 ) {
                $label = $('<span id="mat-chip-filter-count" style="font-size:0.8em; color:#666; margin-left:8px;"></span>');
                $select.after($label);
            }
            $label.text( '（' + shown + ' / ' + total + ' 名）' );
        }

        // ---- 初期化 ----
        if ( allJobTypes.length > 0 ) {
            var chipState = loadChipState();
            renderChips( chipState );
            rebuildSelect( chipState );

            // チップのクリック
            $('#mat-job-type-chips').on( 'click', '.mat-chip', function() {
                var name = $(this).data('job-type');
                chipState[name] = ! chipState[name];
                saveChipState( chipState );
                renderChips( chipState );
                rebuildSelect( chipState );
            });

            // 全ON ボタン
            $('#mat-chip-all-on').on( 'click', function() {
                allJobTypes.forEach( function(name) { chipState[name] = true; });
                saveChipState( chipState );
                renderChips( chipState );
                rebuildSelect( chipState );
            });

            // 全OFF ボタン
            $('#mat-chip-all-off').on( 'click', function() {
                allJobTypes.forEach( function(name) { chipState[name] = false; });
                saveChipState( chipState );
                renderChips( chipState );
                rebuildSelect( chipState );
            });
        }

        // =========================================================
        //  打刻編集モーダル
        // =========================================================
        var currentId = null;
        var nonce     = '<?php echo wp_create_nonce("mat_admin_nonce"); ?>';
        var viewMonth = '<?php echo esc_js( $view_month ); ?>';

        function paidDisplayToInput(mmdd) {
            if (!mmdd || mmdd === '-') return '';
            var parts = mmdd.split('/');
            if (parts.length === 2) {
                return viewMonth.split('-')[0] + '-' + parts[0] + '-' + parts[1];
            }
            return mmdd;
        }

        // 編集ボタン
        $(document).on('click', '.edit-log', function() {
            currentId = $(this).data('id');
            $('#edit-in').val($(this).data('in') || '');
            $('#edit-out').val($(this).data('out') || '');
            $('#edit-break').val($(this).data('break') || '00:00');
            $('#edit-paid').val(paidDisplayToInput($(this).data('paid')));
            $('#edit-notes').val($(this).data('notes') || '');
            $('#edit-error').hide();
            $('#mat-edit-modal').css('display', 'flex');
        });

        $('#edit-cancel, #mat-edit-modal').on('click', function(e) {
            if (e.target === this) { $('#mat-edit-modal').hide(); currentId = null; }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') { $('#mat-edit-modal').hide(); }
        });

        // ---- 削除ボタン ----
$('#edit-delete').on('click', function () {
    if ( ! currentId ) return;

    if ( ! confirm(
        '【確認】この打刻レコードを削除しますか？\n\n' +
        'この操作は元に戻せません。'
    ) ) return;

    var $btn = $(this).prop('disabled', true).text('削除中...');
    $('#edit-error').hide();

    $.post( ajaxurl, {
        action : 'mat_admin_delete_log',
        id     : currentId,
        nonce  : nonce,
    }, function ( res ) {
        $btn.prop('disabled', false).text('🗑️ 削除');
        if ( res.success ) {
            // テーブル行をフェードアウトして削除
            $('tr[data-id="' + currentId + '"]').fadeOut( 300, function () {
                $(this).remove();
            });
            $('#mat-edit-modal').hide();
            currentId = null;
        } else {
            $('#edit-error').text( res.data ).show();
        }
    }).fail(function () {
        $btn.prop('disabled', false).text('🗑️ 削除');
        $('#edit-error').text('通信エラーが発生しました。').show();
    });
});

        $('#edit-save').on('click', function() {
            if (!currentId) return;
            $(this).prop('disabled', true).text('保存中...');
            $('#edit-error').hide();

            $.post(ajaxurl, {
                action:     'mat_admin_edit_log',
                id:         currentId,
                clock_in:   $('#edit-in').val(),
                clock_out:  $('#edit-out').val(),
                break_time: $('#edit-break').val() || '00:00',
                paid_leave: $('#edit-paid').val(),
                note:       $('#edit-notes').val(),
                nonce:      nonce
            }, function(res) {
                $('#edit-save').prop('disabled', false).text('💾 保存する');
                if (res.success) {
                    var d   = res.data;
                    var row = $('tr[data-id="' + currentId + '"]');
                    row.find('.col-in').text(d.clock_in);
                    row.find('.col-out').text(d.clock_out);
                    row.find('.col-break').text(d.break_time);
                    row.find('.col-paid').text(d.paid_leave);
                    row.find('.col-notes').text(d.note);
                    $('#mat-edit-modal').hide();
                    currentId = null;
                } else {
                    $('#edit-error').text(res.data).show();
                }
            });
        });

    }); // jQuery ready
    </script>
    <?php
}