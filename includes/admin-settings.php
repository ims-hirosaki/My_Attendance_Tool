<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * admin-settings.php  v3.1.0
 *
 * 変更点（v3.1.0）:
 *  - mat_admin_edit_log_handler()   → MAT_DAILY_TABLE へ書き込み
 *  - mat_admin_delete_log_handler() → MAT_DAILY_TABLE から削除
 *  - mat_history_page_render()      → 全日付表示・空行グレー表示
 */

// =========================================================
//  管理メニュー登録
// =========================================================

add_action( 'admin_menu', 'mat_register_admin_menu' );
function mat_register_admin_menu() {
    add_menu_page(
        '打刻ツール', '打刻ツール', 'manage_options',
        'my-attendance-settings', 'mat_history_page_render',
        'dashicons-calendar-alt', 30
    );
    add_submenu_page(
        'my-attendance-settings', '打刻', '打刻', 'manage_options',
        'my-attendance-settings', 'mat_history_page_render'
    );
}

// =========================================================
//  管理画面用スクリプト読み込み
// =========================================================

add_action( 'admin_enqueue_scripts', 'mat_admin_enqueue' );
function mat_admin_enqueue( $hook ) {
    $page = $_GET['page'] ?? '';
    $mat_pages = array(
        'my-attendance-settings',
        'mat-auth-management',
        'mat-settings',
        'mat-test-data',
        'mat-migrate',
    );
    if ( ! in_array( $page, $mat_pages, true ) ) return;

    $emp_css = WP_PLUGIN_DIR . '/employee-manager/admin/assets/admin.css';
    if ( file_exists( $emp_css ) ) {
        wp_enqueue_style( 'employee-manager-admin', plugins_url( 'employee-manager/admin/assets/admin.css' ) );
    }
}

// =========================================================
//  管理画面：勤怠編集 Ajax（新テーブル版）
// =========================================================

add_action( 'wp_ajax_mat_admin_edit_log', 'mat_admin_edit_log_handler' );
function mat_admin_edit_log_handler() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '権限がありません。' );
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;
    $id         = intval( $_POST['id'] ?? 0 );
    $clock_in   = sanitize_text_field( $_POST['clock_in']   ?? '' );
    $clock_out  = sanitize_text_field( $_POST['clock_out']  ?? '' );
    $break_hhmm = sanitize_text_field( $_POST['break_time'] ?? '00:00' );
    $note       = sanitize_textarea_field( $_POST['note']   ?? '' );
    $is_holiday = ( ( $_POST['is_holiday'] ?? '0' ) === '1' );

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . MAT_DAILY_TABLE . " WHERE id = %d", $id
    ) );
    if ( ! $row ) wp_send_json_error( 'レコードが見つかりません。' );

    if ( $is_holiday ) {
        $update = array(
            'clock_in'      => null,
            'clock_out'     => null,
            'break_minutes' => null,
            'is_holiday'    => 1,
            'note'          => null,
        );
    } else {
        $break_minutes = mat_hhmm_to_minutes( $break_hhmm );
        $update = array(
            'clock_in'      => $clock_in  ?: null,
            'clock_out'     => $clock_out ?: null,
            'break_minutes' => $break_minutes,
            'is_holiday'    => 0,
            'note'          => $note ?: null,
        );
    }

    $updated = $wpdb->update( MAT_DAILY_TABLE, $update, array( 'id' => $id ) );
    if ( $updated === false ) wp_send_json_error( '更新に失敗しました。' );

    wp_send_json_success();
}

// =========================================================
//  管理画面：勤怠削除 Ajax（新テーブル版）
// =========================================================

add_action( 'wp_ajax_mat_admin_delete_log', 'mat_admin_delete_log_handler' );
function mat_admin_delete_log_handler() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '権限がありません。' );
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;
    $id = intval( $_POST['id'] ?? 0 );
    if ( $id <= 0 ) wp_send_json_error( 'IDが不正です。' );

    $deleted = $wpdb->delete( MAT_DAILY_TABLE, array( 'id' => $id ), array( '%d' ) );
    if ( $deleted !== false ) {
        wp_send_json_success( '削除しました。' );
    } else {
        wp_send_json_error( '削除に失敗しました。' );
    }
}

// =========================================================
//  勤怠履歴ページのレンダリング（全日付表示対応）
// =========================================================

function mat_history_page_render() {
    if ( ! current_user_can( 'manage_options' ) ) return;

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

    $selected_code = isset( $_GET['employee_code'] )
        ? sanitize_text_field( $_GET['employee_code'] )
        : ( ! empty( $employees ) ? $employees[0]->employee_code : '' );

    $view_month = isset( $_GET['view_month'] )
        ? sanitize_text_field( $_GET['view_month'] )
        : date( 'Y-m' );

    // ★【新仕様】送信されたフィルターの状態を回収（未送信ならデフォルト値を設定）
    $saved_filters = isset( $_GET['mat_filters'] ) ? array_map( 'sanitize_text_field', (array) $_GET['mat_filters'] ) : null;

    $selected_emp = null;
    foreach ( $employees as $emp ) {
        if ( $emp->employee_code === $selected_code ) { $selected_emp = $emp; break; }
    }

    $logs            = array();
    $work_days_count = 0;
    $total_days      = 0;
    if ( $selected_emp ) {
        $data            = mat_get_grouped_data( $selected_emp->id, $view_month );
        $logs            = $data['logs'];
        $work_days_count = $data['work_days_count'];
        $total_days      = $data['total_days'];
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

            <table class="widefat fixed striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th style="width:110px;">日付</th>
                        <th style="width:80px;">出勤</th>
                        <th style="width:80px;">退勤</th>
                        <th style="width:80px;">休憩</th>
                        <th>備考</th>
                        <th style="width:100px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr><td colspan="6" style="text-align:center;padding:20px;">データがありません。</td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $day ) :
                            $is_empty   = ! $day['has_data'];
                            $is_holiday = $day['is_holiday'];
                            $row_style  = $is_empty ? 'color:#bbb; background:#fafafa;' : '';
                            if ( $is_holiday ) $row_style = 'background:#fff8e1;';
                        ?>
                            <tr data-id="<?php echo esc_attr( $day['id'] ); ?>"
                                style="<?php echo $row_style; ?>">
                                <td><?php echo esc_html( $day['date'] ); ?></td>
                                <td><?php echo esc_html( $day['in'] ?? '-' ); ?></td>
                                <td><?php echo esc_html( $day['out'] ?? '-' ); ?></td>
                                <td><?php echo esc_html( $day['break'] ?? '-' ); ?></td>
                                <td><?php echo esc_html( is_array( $day['notes'] ) ? implode( ' / ', $day['notes'] ) : '' ); ?></td>
                                <td>
                                    <?php if ( ! $is_empty ) : ?>
                                    <button class="button button-small edit-log"
                                        data-id="<?php echo esc_attr( $day['id'] ); ?>"
                                        data-in="<?php echo esc_attr( $day['in'] ?? '' ); ?>"
                                        data-out="<?php echo esc_attr( $day['out'] ?? '' ); ?>"
                                        data-break="<?php echo esc_attr( $day['break'] ?? '00:00' ); ?>"
                                        data-notes="<?php echo esc_attr( is_array( $day['notes'] ) ? implode( ' / ', $day['notes'] ) : '' ); ?>"
                                        data-holiday="<?php echo $is_holiday ? '1' : '0'; ?>"
                                        data-date-label="<?php echo esc_attr( $day['date'] ); ?>">
                                        編集
                                    </button>
                                    <?php else : ?>
                                        <span style="color:#ddd;font-size:0.8em;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
                <tr><th>出勤</th><td><input type="time" id="edit-in" class="regular-text"></td></tr>
                <tr><th>退勤</th><td><input type="time" id="edit-out" class="regular-text"></td></tr>
                <tr><th>休憩</th><td><input type="time" id="edit-break" class="regular-text" value="00:00"></td></tr>
                <tr><th>備考</th><td><textarea id="edit-notes" class="regular-text" rows="2"></textarea></td></tr>
                <tr>
                    <th>休日</th>
                    <td><label>
                        <input type="checkbox" id="edit-holiday"> 休日として登録する
                    </label></td>
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

    <script>
    jQuery(function($) {
        var nonce    = '<?php echo wp_create_nonce( "mat_admin_nonce" ); ?>';
        var currentId = null;

        var selectedEmpCode = '<?php echo esc_js( $selected_emp ? $selected_emp->employee_code : '' ); ?>';
        var selectedEmpName = '<?php echo esc_js( $selected_emp ? $selected_emp->name : '' ); ?>';

        // 職種チップフィルター
        var empData      = <?php echo wp_json_encode( $emp_js_data ); ?>;
        var jobTypeNames = <?php echo wp_json_encode( $job_type_names ); ?>;
        var activeTypes  = {};

        // ★【ここを全面修正】URLからリロード前の状態を引き継ぐ
        var savedFilters = <?php echo $saved_filters !== null ? wp_json_encode($saved_filters) : 'null'; ?>;
        
        if (savedFilters !== null) {
            // 前回の送信データがあれば、それをStateとして完全再現
            jobTypeNames.forEach(function(jt) {
                activeTypes[jt] = savedFilters.indexOf(jt) !== -1;
            });
        } else {
            // 初回表示時のみのデフォルト初期値
            jobTypeNames.forEach(function(jt) {
                activeTypes[jt] = (jt !== '長距離' && jt !== '郵便');
            });
        }

        function applyChipStyles() {
            $('.mat-chip').each(function() {
                var jt = $(this).data('job-type');
                var on = activeTypes[jt] !== false;
                $(this).css({
                    background: on ? '#2271b1' : '#fff',
                    color:      on ? '#fff'     : '#2271b1',
                });
            });
            // 【新仕様】フォーム送信用の隠しinputタグをリアルタイム同期更新
            updateHiddenFields();
        }

        // 選択されているチップをformのパラメータに変換する処理
        function updateHiddenFields() {
            var $container = $('#mat-hidden-filter-inputs').empty();
            Object.keys(activeTypes).forEach(function(jt) {
                if (activeTypes[jt]) {
                    $container.append($('<input type="hidden" name="mat_filters[]">').val(jt));
                }
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

            if (selectedEmpCode !== '') {
                var $activeOpt = $sel.find('option[value="' + selectedEmpCode + '"]');
                if ($activeOpt.length > 0 && !$activeOpt.prop('disabled')) {
                    $sel.val(selectedEmpCode);
                } else {
                    $sel.val('');
                }
            } else {
                $sel.val('');
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

        // 休日チェック時のUI切り替え
        function toggleHolidayUI(isHoliday) {
            var opacity = isHoliday ? '0.5' : '1';
            $('#edit-in, #edit-out, #edit-break').prop('disabled', isHoliday)
                .closest('tr').css('opacity', opacity);
        }

        // 編集ボタン
        $(document).on('click', '.edit-log', function() {
            currentId = $(this).data('id');
            if (selectedEmpCode !== '') {
                $('#mat-modal-meta-emp').text('[' + selectedEmpCode + '] ' + selectedEmpName);
            } else {
                $('#mat-modal-meta-emp').text('--');
            }
            $('#mat-modal-meta-date').text($(this).data('date-label') || '--');

            $('#edit-in').val($(this).data('in') || '');
            $('#edit-out').val($(this).data('out') || '');
            $('#edit-break').val($(this).data('break') || '00:00');
            $('#edit-notes').val($(this).data('notes') || '');
            var isHoliday = $(this).data('holiday') == '1';
            $('#edit-holiday').prop('checked', isHoliday);
            toggleHolidayUI(isHoliday);
            $('#edit-error').hide();
            $('#mat-edit-modal').css('display', 'flex');
        });

        $('#edit-holiday').on('change', function() { toggleHolidayUI($(this).is(':checked')); });

        // 削除ボタン
        $('#edit-delete').on('click', function() {
            if (!currentId || !confirm('このデータを完全に削除しますか？')) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('削除中...');
            $.post(ajaxurl, { action: 'mat_admin_delete_log', id: currentId, nonce: nonce }, function(res) {
                if (res.success) { location.reload(); }
                else { alert(res.data); $btn.prop('disabled', false).text('🗑 削除する'); }
            });
        });

        // モーダルを閉じる
        $('#edit-cancel, #mat-edit-modal').on('click', function(e) {
            if (e.target === this) { $('#mat-edit-modal').hide(); currentId = null; }
        });
        $(document).on('keydown', function(e) { if (e.key === 'Escape') $('#mat-edit-modal').hide(); });

        // 保存ボタン
        $('#edit-save').on('click', function() {
            if (!currentId) return;
            $(this).prop('disabled', true).text('保存中...');
            $.post(ajaxurl, {
                action:     'mat_admin_edit_log',
                id:         currentId,
                clock_in:   $('#edit-in').val(),
                clock_out:  $('#edit-out').val(),
                break_time: $('#edit-break').val() || '00:00',
                note:       $('#edit-notes').val(),
                is_holiday: $('#edit-holiday').is(':checked') ? '1' : '0',
                nonce:      nonce,
            }, function(res) {
                if (res.success) { location.reload(); }
                else {
                    $('#edit-error').text(res.data).show();
                    $('#edit-save').prop('disabled', false).text('💾 保存する');
                }
            });
        });
    });
    </script>
    <?php
}
