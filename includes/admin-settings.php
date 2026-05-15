<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 管理メニュー登録
 */
add_action( 'admin_menu', 'mat_register_admin_menu' );
function mat_register_admin_menu() {
    add_menu_page(
        '打刻ツール',
        '打刻ツール',
        'manage_options',
        'my-attendance-settings',
        'mat_history_page_render',
        'dashicons-calendar-alt',
        30
    );
    add_submenu_page(
        'my-attendance-settings',
        '打刻',
        '打刻',
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

    $emp_css = WP_PLUGIN_DIR . '/employee-manager/admin/assets/admin.css';
    if ( file_exists( $emp_css ) ) {
        wp_enqueue_style( 'employee-manager-admin', plugins_url( 'employee-manager/admin/assets/admin.css' ) );
    }
}

/**
 * 管理画面：勤怠編集 Ajax（管理者用）
 * ※ 他者実装：休日フラグ（is_holiday）対応を維持
 */
add_action( 'wp_ajax_mat_admin_edit_log', 'mat_admin_edit_log_handler' );
function mat_admin_edit_log_handler() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '権限がありません。' );
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;
    $id         = intval( $_POST['id'] ?? 0 );
    $clock_in   = sanitize_text_field( $_POST['clock_in']   ?? '' );
    $clock_out  = sanitize_text_field( $_POST['clock_out']  ?? '' );
    $break_hhmm = sanitize_text_field( $_POST['break_time'] ?? '00:00' );
    $paid_leave = sanitize_text_field( $_POST['paid_leave'] ?? '' );
    $note       = sanitize_textarea_field( $_POST['note']   ?? '' );
    $is_holiday = ( ( $_POST['is_holiday'] ?? '0' ) === '1' ); // 休日フラグ

    if ( ! preg_match( '/^\d{2}:\d{2}$/', $break_hhmm ) ) $break_hhmm = '00:00';

    $log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . MAT_LOG_TABLE . " WHERE id = %d", $id ) );
    if ( ! $log ) wp_send_json_error( 'レコードが見つかりません。' );

    if ( $is_holiday ) {
        $update = array( 'item_name' => '休日', 'paid_leave_date' => null );
    } else {
        $parts = array();
        if ( $clock_in  !== '' ) $parts[] = "出勤: {$clock_in}";
        if ( $clock_out !== '' ) $parts[] = "退勤: {$clock_out}";
        $parts[] = "休憩: {$break_hhmm}";
        if ( $note !== '' )      $parts[] = "備考: {$note}";
        $update = array( 'item_name' => implode( ' | ', $parts ) );
        $update['paid_leave_date'] = ( $paid_leave !== '' ) ? $paid_leave : null;
    }

    $updated = $wpdb->update( MAT_LOG_TABLE, $update, array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
    if ( $updated === false ) wp_send_json_error( '更新失敗' );

    wp_send_json_success();
}

/**
 * 管理画面：勤怠削除 Ajax（管理者用）
 * ※ 他者実装：そのまま維持
 */
add_action( 'wp_ajax_mat_admin_delete_log', 'mat_admin_delete_log_handler' );
function mat_admin_delete_log_handler() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '権限がありません。' );
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;
    $id = intval( $_POST['id'] ?? 0 );
    if ( $id <= 0 ) wp_send_json_error( 'IDが不正です。' );

    $deleted = $wpdb->delete( MAT_LOG_TABLE, array( 'id' => $id ), array( '%d' ) );
    if ( $deleted !== false ) {
        wp_send_json_success( '削除しました。' );
    } else {
        wp_send_json_error( '削除に失敗しました。' );
    }
}

/**
 * 勤怠履歴ページのレンダリング
 */
function mat_history_page_render() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // 在籍社員一覧を取得
    $employees = emp_get_active_employees();

    // [追加] 職種マスタを動的取得（employee-manager から）
    $job_types = function_exists( 'emp_get_job_types' ) ? emp_get_job_types() : array();

    // [追加] JS 用：全従業員データ（job_type_name 付き）
    $emp_js_data = array();
    foreach ( $employees as $emp ) {
        $emp_js_data[] = array(
            'code'     => $emp->employee_code,
            'name'     => $emp->name,
            'job_type' => isset( $emp->job_type_name ) ? $emp->job_type_name : '',
        );
    }

    // [追加] JS 用：職種名一覧
    $job_type_names = array();
    foreach ( $job_types as $jt ) {
        $job_type_names[] = $jt->name;
    }

    $selected_code = isset( $_GET['employee_code'] )
        ? sanitize_text_field( $_GET['employee_code'] )
        : ( ! empty( $employees ) ? $employees[0]->employee_code : '' );

    $view_month = isset( $_GET['view_month'] )
        ? sanitize_text_field( $_GET['view_month'] )
        : date( 'Y-m' );

    $selected_emp = null;
    foreach ( $employees as $emp ) {
        if ( $emp->employee_code === $selected_code ) { $selected_emp = $emp; break; }
    }

    $logs = array();
    if ( $selected_emp ) {
        $data = mat_get_grouped_data( $selected_emp->id, $view_month );
        $logs = $data['logs'];
    }
    ?>
    <div class="wrap">
        <h1>📋 従業員打刻履歴</h1>

        <div class="card" style="max-width:100%; margin-top:20px; padding:15px;">

            <?php if ( ! empty( $job_types ) ) : ?>
            <!-- [追加] 職種フィルターチップ -->
            <div style="margin-bottom:12px; display:flex; align-items:center; flex-wrap:wrap; gap:6px;">
                <span style="font-size:0.85em; font-weight:600; color:#555; white-space:nowrap;">
                    職種フィルター：
                </span>
                <div id="mat-job-type-chips" style="display:inline-flex; flex-wrap:wrap; gap:6px;">
                    <?php foreach ( $job_types as $jt ) : ?>
                        <button type="button"
                            class="mat-chip"
                            data-job-type="<?php echo esc_attr( $jt->name ); ?>"
                            style="
                                display:inline-flex; align-items:center; gap:4px;
                                padding:4px 12px; border-radius:20px;
                                border:1.5px solid #2271b1;
                                background:#2271b1; color:#fff;
                                font-size:0.82em; font-weight:600;
                                cursor:pointer; line-height:1.5;
                                transition:background .15s, color .15s;
                            ">
                            <span class="mat-chip-dot" style="
                                display:inline-block; width:7px; height:7px;
                                border-radius:50%; background:#fff;
                            "></span>
                            <?php echo esc_html( $jt->name ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="mat-chip-all-on"
                    style="font-size:0.78em; color:#2271b1; background:none; border:none; cursor:pointer; text-decoration:underline;">
                    全ON
                </button>
                <button type="button" id="mat-chip-all-off"
                    style="font-size:0.78em; color:#888; background:none; border:none; cursor:pointer; text-decoration:underline;">
                    全OFF
                </button>
            </div>
            <?php endif; ?>

            <!-- 絞り込みフォーム（既存のまま、select に data-job-type を追加） -->
            <form method="get">
                <input type="hidden" name="page" value="my-attendance-settings">
                従業員：
                <select name="employee_code" id="mat-employee-select">
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
            <table class="widefat fixed striped" style="margin-top:20px;">
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
                                <td><?php echo esc_html( $day['in'] ); ?></td>
                                <td><?php echo esc_html( $day['out'] ); ?></td>
                                <td><?php echo esc_html( $day['break'] ); ?></td>
                                <td style="font-weight:bold; color:#d63638;"><?php echo esc_html( $day['paid_leave'] ); ?></td>
                                <td><?php echo esc_html( is_array( $day['notes'] ) ? implode( ' / ', $day['notes'] ) : '' ); ?></td>
                                <td>
                                    <button class="button button-small edit-log"
                                        data-id="<?php echo esc_attr( $day['id'] ); ?>"
                                        data-in="<?php echo esc_attr( $day['in'] === '-' ? '' : $day['in'] ); ?>"
                                        data-out="<?php echo esc_attr( $day['out'] === '-' ? '' : $day['out'] ); ?>"
                                        data-break="<?php echo esc_attr( $day['break'] === '-' ? '00:00' : $day['break'] ); ?>"
                                        data-paid="<?php echo esc_attr( $day['paid_leave'] === '-' ? '' : $day['paid_leave'] ); ?>"
                                        data-notes="<?php echo esc_attr( is_array( $day['notes'] ) ? implode( ' / ', $day['notes'] ) : '' ); ?>"
                                        data-holiday="<?php echo $day['is_holiday'] ? '1' : '0'; ?>">
                                        編集
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- 編集モーダル（他者実装：休日フラグ・削除ボタンをそのまま維持） -->
    <div id="mat-edit-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:8px; padding:28px; width:440px; max-width:90%;">
            <h3 style="margin:0 0 20px;">打刻データの編集</h3>
            <table class="form-table" style="margin:0;">
                <tr><th>出勤</th><td><input type="time" id="edit-in" class="regular-text"></td></tr>
                <tr><th>退勤</th><td><input type="time" id="edit-out" class="regular-text"></td></tr>
                <tr><th>休憩</th><td><input type="time" id="edit-break" class="regular-text" value="00:00"></td></tr>
                <tr><th>有給希望日</th><td><input type="date" id="edit-paid" class="regular-text"></td></tr>
                <tr><th>備考</th><td><textarea id="edit-notes" class="regular-text" rows="2"></textarea></td></tr>
                <tr style="border-top: 1px solid #eee;">
                    <th>休日設定</th>
                    <td>
                        <label style="color:#d63638; font-weight:bold;">
                            <input type="checkbox" id="edit-holiday"> この日を「休日」にする
                        </label>
                    </td>
                </tr>
            </table>
            <p id="edit-error" style="color:#d63638; margin:10px 0 0; display:none;"></p>
            <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" id="edit-delete" class="button" style="margin-right:auto; color:#d63638; border-color:#d63638;">🗑 削除する</button>
                <button type="button" id="edit-cancel" class="button">キャンセル</button>
                <button type="button" id="edit-save" class="button button-primary">💾 保存する</button>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {

        // =========================================================
        //  [追加] 職種フィルターチップ
        // =========================================================

        var STORAGE_KEY  = 'mat_history_job_type_filter';
        // デフォルトOFFにする職種名（localStorageに記録がない初回のみ適用）
        var DEFAULT_OFF  = ['長距離', '郵便'];

        var allEmployees = <?php echo json_encode( $emp_js_data,    JSON_UNESCAPED_UNICODE ); ?>;
        var allJobTypes  = <?php echo json_encode( $job_type_names, JSON_UNESCAPED_UNICODE ); ?>;
        var selectedCode = <?php echo json_encode( $selected_code ); ?>;

        /**
         * localStorage からチップ状態を読み込む
         * - 保存済みキー → そのまま引き継ぐ
         * - 新しい職種（保存なし）→ DEFAULT_OFF になければ自動ON
         * - 削除された職種 → localStorage には残るが UI に表示されないので無害
         */
        function loadChipState() {
            var stored = {};
            try { stored = JSON.parse( localStorage.getItem(STORAGE_KEY) || '{}' ); } catch(e) {}
            var state = {};
            allJobTypes.forEach(function(name) {
                if ( typeof stored[name] !== 'undefined' ) {
                    state[name] = stored[name];
                } else {
                    state[name] = ( DEFAULT_OFF.indexOf(name) === -1 );
                }
            });
            return state;
        }

        function saveChipState(state) {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch(e) {}
        }

        function renderChips(state) {
            $('#mat-job-type-chips .mat-chip').each(function() {
                var name = $(this).data('job-type');
                var on   = !! state[name];
                if (on) {
                    $(this).css({ background:'#2271b1', color:'#fff', 'border-color':'#2271b1' });
                    $(this).find('.mat-chip-dot').css('background', '#fff');
                } else {
                    $(this).css({ background:'#f0f0f1', color:'#777', 'border-color':'#ccc' });
                    $(this).find('.mat-chip-dot').css('background', '#bbb');
                }
            });
        }

        /**
         * チップ状態に基づいてセレクトを再構築
         * ※ <option> の show/hide はブラウザ互換性の問題があるため DOM 再構築を採用
         */
        function rebuildSelect(state) {
            var $select    = $('#mat-employee-select');
            var currentVal = $select.val() || selectedCode;
            var activeTypes = [];
            allJobTypes.forEach(function(name) { if (state[name]) activeTypes.push(name); });

            // 職種なし（空文字）の社員は常に表示
            var filtered = allEmployees.filter(function(emp) {
                if (emp.job_type === '' || emp.job_type === null) return true;
                return activeTypes.indexOf(emp.job_type) !== -1;
            });

            $select.empty();
            var foundCurrent = false;
            filtered.forEach(function(emp) {
                var opt = $('<option>', { value: emp.code, text: '[' + emp.code + '] ' + emp.name });
                if (emp.code === currentVal) { opt.prop('selected', true); foundCurrent = true; }
                $select.append(opt);
            });
            if (!foundCurrent && filtered.length > 0) {
                $select.find('option:first').prop('selected', true);
            }

            // 絞り込み件数表示
            var $label = $('#mat-chip-filter-count');
            if ($label.length === 0) {
                $label = $('<span id="mat-chip-filter-count" style="font-size:0.8em; color:#666; margin-left:8px;"></span>');
                $select.after($label);
            }
            $label.text('（' + filtered.length + ' / ' + allEmployees.length + ' 名）');
        }

        // チップ初期化
        if (allJobTypes.length > 0) {
            var chipState = loadChipState();
            renderChips(chipState);
            rebuildSelect(chipState);

            $('#mat-job-type-chips').on('click', '.mat-chip', function() {
                var name = $(this).data('job-type');
                chipState[name] = !chipState[name];
                saveChipState(chipState);
                renderChips(chipState);
                rebuildSelect(chipState);
            });
            $('#mat-chip-all-on').on('click', function() {
                allJobTypes.forEach(function(name) { chipState[name] = true; });
                saveChipState(chipState); renderChips(chipState); rebuildSelect(chipState);
            });
            $('#mat-chip-all-off').on('click', function() {
                allJobTypes.forEach(function(name) { chipState[name] = false; });
                saveChipState(chipState); renderChips(chipState); rebuildSelect(chipState);
            });
        }

        // =========================================================
        //  編集モーダル（他者実装のロジックをそのまま維持）
        // =========================================================
        var currentId = null;
        var nonce     = '<?php echo wp_create_nonce( 'mat_admin_nonce' ); ?>';
        var viewMonth = '<?php echo esc_js( $view_month ); ?>';

        function paidDisplayToInput(mmdd) {
            if (!mmdd || mmdd === '-') return '';
            var parts = mmdd.split('/');
            if (parts.length === 2) return viewMonth.split('-')[0] + '-' + parts[0] + '-' + parts[1];
            return mmdd;
        }

        function toggleHolidayUI(isHoliday) {
            var opacity = isHoliday ? '0.5' : '1';
            $('#edit-in, #edit-out, #edit-break').prop('disabled', isHoliday).parent().css('opacity', opacity);
        }

        $(document).on('click', '.edit-log', function() {
            currentId = $(this).data('id');
            $('#edit-in').val($(this).data('in') || '');
            $('#edit-out').val($(this).data('out') || '');
            $('#edit-break').val($(this).data('break') || '00:00');
            $('#edit-paid').val(paidDisplayToInput($(this).data('paid')));
            $('#edit-notes').val($(this).data('notes') || '');
            var isHoliday = $(this).data('holiday') == '1';
            $('#edit-holiday').prop('checked', isHoliday);
            toggleHolidayUI(isHoliday);
            $('#edit-error').hide();
            $('#mat-edit-modal').css('display', 'flex');
        });

        $('#edit-holiday').on('change', function() { toggleHolidayUI($(this).is(':checked')); });

        $('#edit-delete').on('click', function() {
            if (!currentId || !confirm('このデータを完全に削除しますか？')) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('削除中...');
            $.post(ajaxurl, { action: 'mat_admin_delete_log', id: currentId, nonce: nonce }, function(res) {
                if (res.success) { location.reload(); } else { alert(res.data); $btn.prop('disabled', false).text('🗑 削除する'); }
            });
        });

        $('#edit-cancel, #mat-edit-modal').on('click', function(e) {
            if (e.target === this) { $('#mat-edit-modal').hide(); currentId = null; }
        });
        $(document).on('keydown', function(e) { if (e.key === 'Escape') { $('#mat-edit-modal').hide(); } });

        $('#edit-save').on('click', function() {
            if (!currentId) return;
            $(this).prop('disabled', true).text('保存中...');
            $.post(ajaxurl, {
                action:     'mat_admin_edit_log',
                id:         currentId,
                clock_in:   $('#edit-in').val(),
                clock_out:  $('#edit-out').val(),
                break_time: $('#edit-break').val() || '00:00',
                paid_leave: $('#edit-paid').val(),
                note:       $('#edit-notes').val(),
                is_holiday: $('#edit-holiday').is(':checked') ? '1' : '0',
                nonce:      nonce
            }, function(res) {
                if (res.success) { location.reload(); } else { alert(res.data); $('#edit-save').prop('disabled', false).text('💾 保存する'); }
            });
        });

    }); // jQuery ready
    </script>
    <?php
}
