<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * テストデータ削除ページ
 */
add_action( 'admin_menu', 'mat_register_test_data_page', 25 );
function mat_register_test_data_page() {
    add_submenu_page(
        'my-attendance-settings',
        'テストデータ削除',
        'テストデータ削除',
        'manage_custom_plugin_settings',
        'mat-test-data',
        'mat_test_data_page_render'
    );
}

// =========================================================
//  勤怠ログ削除 Ajax（既存）
// =========================================================

add_action( 'wp_ajax_mat_delete_test_data', 'mat_delete_test_data_handler' );
function mat_delete_test_data_handler() {
    if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) {
        wp_send_json_error( '権限がありません。' );
    }
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;

    $mode          = isset( $_POST['mode'] )          ? sanitize_text_field( $_POST['mode'] )          : '';
    $employee_code = isset( $_POST['employee_code'] ) ? sanitize_text_field( $_POST['employee_code'] ) : '';
    $month         = isset( $_POST['month'] )         ? sanitize_text_field( $_POST['month'] )         : '';

    if ( $mode === 'all' ) {
        // 全件削除
        $deleted = $wpdb->query( "DELETE FROM " . MAT_LOG_TABLE );
        wp_send_json_success( array( 'message' => "勤怠ログを全件削除しました（{$deleted}件）。" ) );

    } elseif ( $mode === 'employee' && $employee_code ) {
        // 社員別全件削除
        $deleted = $wpdb->delete(
            MAT_LOG_TABLE,
            array( 'employee_code' => $employee_code ),
            array( '%s' )
        );
        wp_send_json_success( array( 'message' => "[{$employee_code}] のログを全件削除しました（{$deleted}件）。" ) );

    } elseif ( $mode === 'employee_month' && $employee_code && $month ) {
        // 社員別・月別削除
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM " . MAT_LOG_TABLE . " WHERE employee_code = %s AND timestamp LIKE %s",
            $employee_code,
            $month . '%'
        ) );
        wp_send_json_success( array( 'message' => "[{$employee_code}] {$month} のログを削除しました（{$deleted}件）。" ) );

    } else {
        wp_send_json_error( 'パラメータが不正です。' );
    }
}

/**
 * 勤怠ログ プレビュー取得 Ajax（既存）
 */
add_action( 'wp_ajax_mat_preview_delete', 'mat_preview_delete_handler' );
function mat_preview_delete_handler() {
    if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) {
        wp_send_json_error( '権限がありません。' );
    }
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;

    $mode          = isset( $_POST['mode'] )          ? sanitize_text_field( $_POST['mode'] )          : '';
    $employee_code = isset( $_POST['employee_code'] ) ? sanitize_text_field( $_POST['employee_code'] ) : '';
    $month         = isset( $_POST['month'] )         ? sanitize_text_field( $_POST['month'] )         : '';

    if ( $mode === 'all' ) {
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . MAT_LOG_TABLE );
        wp_send_json_success( array( 'count' => $count, 'message' => "全社員のログ {$count} 件が削除されます。" ) );

    } elseif ( $mode === 'employee' && $employee_code ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . MAT_LOG_TABLE . " WHERE employee_code = %s",
            $employee_code
        ) );
        wp_send_json_success( array( 'count' => $count, 'message' => "[{$employee_code}] のログ {$count} 件が削除されます。" ) );

    } elseif ( $mode === 'employee_month' && $employee_code && $month ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . MAT_LOG_TABLE . " WHERE employee_code = %s AND timestamp LIKE %s",
            $employee_code,
            $month . '%'
        ) );
        wp_send_json_success( array( 'count' => $count, 'message' => "[{$employee_code}] {$month} のログ {$count} 件が削除されます。" ) );

    } else {
        wp_send_json_error( 'パラメータが不正です。' );
    }
}

// =========================================================
//  有給申請ログ プレビュー取得 Ajax（新規追加）
// =========================================================

add_action( 'wp_ajax_mat_preview_delete_paid_leave', 'mat_preview_delete_paid_leave_handler' );
function mat_preview_delete_paid_leave_handler() {
    if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) {
        wp_send_json_error( '権限がありません。' );
    }
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'paidleave_requests';

    // テーブル存在確認
    if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        wp_send_json_error( 'paidleave_requests テーブルが存在しません。paid-leave-manager が有効化されているか確認してください。' );
    }

    $mode          = isset( $_POST['mode'] )          ? sanitize_text_field( $_POST['mode'] )          : '';
    $employee_code = isset( $_POST['employee_code'] ) ? sanitize_text_field( $_POST['employee_code'] ) : '';

    if ( $mode === 'all' ) {
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        wp_send_json_success( array( 'count' => $count, 'message' => "全社員の有給申請ログ {$count} 件が削除されます。" ) );

    } elseif ( $mode === 'employee' && $employee_code ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE employee_code = %s",
            $employee_code
        ) );
        wp_send_json_success( array( 'count' => $count, 'message' => "[{$employee_code}] の有給申請ログ {$count} 件が削除されます。" ) );

    } else {
        wp_send_json_error( 'パラメータが不正です。' );
    }
}

// =========================================================
//  有給申請ログ 削除 Ajax（新規追加）
// =========================================================

add_action( 'wp_ajax_mat_delete_paid_leave', 'mat_delete_paid_leave_handler' );
function mat_delete_paid_leave_handler() {
    if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) {
        wp_send_json_error( '権限がありません。' );
    }
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'paidleave_requests';

    // テーブル存在確認
    if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        wp_send_json_error( 'paidleave_requests テーブルが存在しません。paid-leave-manager が有効化されているか確認してください。' );
    }

    $mode          = isset( $_POST['mode'] )          ? sanitize_text_field( $_POST['mode'] )          : '';
    $employee_code = isset( $_POST['employee_code'] ) ? sanitize_text_field( $_POST['employee_code'] ) : '';

    if ( $mode === 'all' ) {
        $deleted = $wpdb->query( "DELETE FROM {$table}" );
        wp_send_json_success( array( 'message' => "有給申請ログを全件削除しました（{$deleted}件）。" ) );

    } elseif ( $mode === 'employee' && $employee_code ) {
        $deleted = $wpdb->delete(
            $table,
            array( 'employee_code' => $employee_code ),
            array( '%s' )
        );
        wp_send_json_success( array( 'message' => "[{$employee_code}] の有給申請ログを全件削除しました（{$deleted}件）。" ) );

    } else {
        wp_send_json_error( 'パラメータが不正です。' );
    }
}

// =========================================================
//  テストデータ削除ページのレンダリング
// =========================================================

function mat_test_data_page_render() {
    if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die( '権限がありません。', '', array( 'response' => 403 ) );

    $employees = emp_get_active_employees();
    ?>
    <div class="wrap">
        <h1>🗑️ テストデータ削除</h1>
        <div class="notice notice-warning">
            <p><strong>注意：</strong>削除したデータは復元できません。テスト環境でのみ使用してください。</p>
        </div>

        <!-- ======================================================
             セクション1：勤怠ログ
        ====================================================== -->
        <h2 style="margin-top:28px; padding-bottom:6px; border-bottom:2px solid #ddd;">
            📋 勤怠ログ（打刻データ）
        </h2>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; max-width:960px; margin-top:16px;">

            <!-- 社員別・月別削除 -->
            <div class="postbox">
                <div class="postbox-header"><h2 class="hndle">社員別・月別削除</h2></div>
                <div class="inside">
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th>社員</th>
                            <td>
                                <select id="mat-del-emp">
                                    <option value="">-- 選択 --</option>
                                    <?php foreach ( $employees as $emp ) : ?>
                                        <option value="<?php echo esc_attr( $emp->employee_code ); ?>">
                                            [<?php echo esc_html( $emp->employee_code ); ?>] <?php echo esc_html( $emp->name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>月</th>
                            <td>
                                <input type="month" id="mat-del-month" value="<?php echo date('Y-m'); ?>">
                                <br>
                                <label style="margin-top:6px; display:inline-block;">
                                    <input type="checkbox" id="mat-del-all-months"> 全月を対象にする
                                </label>
                            </td>
                        </tr>
                    </table>
                    <div style="margin-top:12px;">
                        <button class="button" id="mat-preview-emp">プレビュー</button>
                        <button class="button button-primary" id="mat-delete-emp" style="margin-left:8px;" disabled>削除する</button>
                    </div>
                    <p id="mat-preview-emp-result" style="margin-top:8px; font-weight:bold;"></p>
                </div>
            </div>

            <!-- 全件削除 -->
            <div class="postbox" style="border:2px solid #d63638;">
                <div class="postbox-header"><h2 class="hndle" style="color:#d63638;">⚠️ 全件削除</h2></div>
                <div class="inside">
                    <p>全社員・全期間の勤怠ログをすべて削除します。</p>
                    <button class="button" id="mat-preview-all">プレビュー</button>
                    <button class="button" id="mat-delete-all"
                        style="margin-left:8px; background:#d63638; color:#fff; border-color:#d63638;" disabled>
                        全件削除する
                    </button>
                    <p id="mat-preview-all-result" style="margin-top:8px; font-weight:bold;"></p>
                </div>
            </div>

        </div>

        <!-- ======================================================
             セクション2：有給申請ログ（新規追加）
        ====================================================== -->
        <h2 style="margin-top:36px; padding-bottom:6px; border-bottom:2px solid #ddd;">
            📅 有給申請ログ（paidleave_requests）
        </h2>
        <?php if ( ! class_exists( 'PL_Request' ) ) : ?>
        <div class="notice notice-error inline" style="margin-top:12px;">
            <p>⚠️ <strong>paid-leave-manager</strong> が有効化されていません。有給申請ログの削除は使用できません。</p>
        </div>
        <?php else : ?>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; max-width:960px; margin-top:16px;">

            <!-- 社員別削除 -->
            <div class="postbox">
                <div class="postbox-header"><h2 class="hndle">社員別削除</h2></div>
                <div class="inside">
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th>社員</th>
                            <td>
                                <select id="mat-pl-del-emp">
                                    <option value="">-- 選択 --</option>
                                    <?php foreach ( $employees as $emp ) : ?>
                                        <option value="<?php echo esc_attr( $emp->employee_code ); ?>">
                                            [<?php echo esc_html( $emp->employee_code ); ?>] <?php echo esc_html( $emp->name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="description" style="margin-top:8px;">
                        選択した社員の有給申請ログ（申請中・受理済み・却下を含む全件）を削除します。
                    </p>
                    <div style="margin-top:12px;">
                        <button class="button" id="mat-pl-preview-emp">プレビュー</button>
                        <button class="button button-primary" id="mat-pl-delete-emp" style="margin-left:8px;" disabled>削除する</button>
                    </div>
                    <p id="mat-pl-preview-emp-result" style="margin-top:8px; font-weight:bold;"></p>
                </div>
            </div>

            <!-- 全件削除 -->
            <div class="postbox" style="border:2px solid #d63638;">
                <div class="postbox-header"><h2 class="hndle" style="color:#d63638;">⚠️ 全件削除</h2></div>
                <div class="inside">
                    <p>全社員の有給申請ログをすべて削除します。</p>
                    <p class="description">申請中・受理済み・却下を問わず、全レコードが対象になります。</p>
                    <button class="button" id="mat-pl-preview-all">プレビュー</button>
                    <button class="button" id="mat-pl-delete-all"
                        style="margin-left:8px; background:#d63638; color:#fff; border-color:#d63638;" disabled>
                        全件削除する
                    </button>
                    <p id="mat-pl-preview-all-result" style="margin-top:8px; font-weight:bold;"></p>
                </div>
            </div>

        </div>
        <?php endif; ?>

        <div id="mat-delete-message" style="margin-top:16px; font-size:1.1em; font-weight:bold;"></div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var nonce = '<?php echo wp_create_nonce("mat_admin_nonce"); ?>';

        // =========================================================
        //  共通ヘルパー（勤怠ログ用）
        // =========================================================
        function doPreview(mode, empCode, month, $resultEl, $deleteBtn) {
            $resultEl.text('確認中...').css('color', '#888');
            $deleteBtn.prop('disabled', true);
            $.post(ajaxurl, {
                action: 'mat_preview_delete',
                nonce: nonce,
                mode: mode,
                employee_code: empCode,
                month: month
            }, function(res) {
                if (res.success) {
                    var color = res.data.count > 0 ? '#d63638' : '#888';
                    $resultEl.css('color', color).text('📋 ' + res.data.message);
                    $deleteBtn.prop('disabled', res.data.count === 0);
                } else {
                    $resultEl.css('color', '#d63638').text('❌ ' + res.data);
                }
            });
        }

        function doDelete(mode, empCode, month, $resultEl, $deleteBtn) {
            $.post(ajaxurl, {
                action: 'mat_delete_test_data',
                nonce: nonce,
                mode: mode,
                employee_code: empCode,
                month: month
            }, function(res) {
                if (res.success) {
                    $resultEl.css('color', '#46b450').text('✅ ' + res.data.message);
                    $deleteBtn.prop('disabled', true);
                } else {
                    $resultEl.css('color', '#d63638').text('❌ ' + res.data);
                    $deleteBtn.prop('disabled', false);
                }
            });
        }

        // --- 勤怠ログ：社員別プレビュー ---
        $('#mat-preview-emp').on('click', function() {
            var empCode = $('#mat-del-emp').val();
            if (!empCode) { alert('社員を選択してください。'); return; }
            var allMonths = $('#mat-del-all-months').is(':checked');
            var month = allMonths ? '' : $('#mat-del-month').val();
            var mode  = allMonths ? 'employee' : 'employee_month';
            doPreview(mode, empCode, month, $('#mat-preview-emp-result'), $('#mat-delete-emp'));
        });

        // --- 勤怠ログ：社員別削除 ---
        $('#mat-delete-emp').on('click', function() {
            var empCode = $('#mat-del-emp').val();
            var allMonths = $('#mat-del-all-months').is(':checked');
            var month = allMonths ? '' : $('#mat-del-month').val();
            var mode  = allMonths ? 'employee' : 'employee_month';
            if (!confirm('削除します。よろしいですか？この操作は取り消せません。')) return;
            $(this).prop('disabled', true);
            doDelete(mode, empCode, month, $('#mat-preview-emp-result'), $(this));
        });

        // --- 勤怠ログ：全件プレビュー ---
        $('#mat-preview-all').on('click', function() {
            doPreview('all', '', '', $('#mat-preview-all-result'), $('#mat-delete-all'));
        });

        // --- 勤怠ログ：全件削除 ---
        $('#mat-delete-all').on('click', function() {
            if (!confirm('全件削除します。よろしいですか？\nこの操作は取り消せません。')) return;
            if (!confirm('本当によろしいですか？すべての勤怠ログが削除されます。')) return;
            $(this).prop('disabled', true);
            doDelete('all', '', '', $('#mat-preview-all-result'), $(this));
        });

        // 月の入力をチェックボックスと連動
        $('#mat-del-all-months').on('change', function() {
            $('#mat-del-month').prop('disabled', $(this).is(':checked'));
            $('#mat-delete-emp').prop('disabled', true);
            $('#mat-preview-emp-result').text('');
        });

        // =========================================================
        //  共通ヘルパー（有給申請ログ用）
        // =========================================================
        function doPlPreview(mode, empCode, $resultEl, $deleteBtn) {
            $resultEl.text('確認中...').css('color', '#888');
            $deleteBtn.prop('disabled', true);
            $.post(ajaxurl, {
                action: 'mat_preview_delete_paid_leave',
                nonce: nonce,
                mode: mode,
                employee_code: empCode
            }, function(res) {
                if (res.success) {
                    var color = res.data.count > 0 ? '#d63638' : '#888';
                    $resultEl.css('color', color).text('📋 ' + res.data.message);
                    $deleteBtn.prop('disabled', res.data.count === 0);
                } else {
                    $resultEl.css('color', '#d63638').text('❌ ' + res.data);
                }
            });
        }

        function doPlDelete(mode, empCode, $resultEl, $deleteBtn) {
            $.post(ajaxurl, {
                action: 'mat_delete_paid_leave',
                nonce: nonce,
                mode: mode,
                employee_code: empCode
            }, function(res) {
                if (res.success) {
                    $resultEl.css('color', '#46b450').text('✅ ' + res.data.message);
                    $deleteBtn.prop('disabled', true);
                } else {
                    $resultEl.css('color', '#d63638').text('❌ ' + res.data);
                    $deleteBtn.prop('disabled', false);
                }
            });
        }

        // --- 有給申請ログ：社員別プレビュー ---
        $('#mat-pl-preview-emp').on('click', function() {
            var empCode = $('#mat-pl-del-emp').val();
            if (!empCode) { alert('社員を選択してください。'); return; }
            doPlPreview('employee', empCode, $('#mat-pl-preview-emp-result'), $('#mat-pl-delete-emp'));
        });

        // --- 有給申請ログ：社員別削除 ---
        $('#mat-pl-delete-emp').on('click', function() {
            var empCode = $('#mat-pl-del-emp').val();
            if (!confirm('選択した社員の有給申請ログを削除します。\nこの操作は取り消せません。')) return;
            $(this).prop('disabled', true);
            doPlDelete('employee', empCode, $('#mat-pl-preview-emp-result'), $(this));
        });

        // --- 有給申請ログ：全件プレビュー ---
        $('#mat-pl-preview-all').on('click', function() {
            doPlPreview('all', '', $('#mat-pl-preview-all-result'), $('#mat-pl-delete-all'));
        });

        // --- 有給申請ログ：全件削除 ---
        $('#mat-pl-delete-all').on('click', function() {
            if (!confirm('全社員の有給申請ログを全件削除します。\nこの操作は取り消せません。')) return;
            if (!confirm('本当によろしいですか？すべての有給申請ログが削除されます。')) return;
            $(this).prop('disabled', true);
            doPlDelete('all', '', $('#mat-pl-preview-all-result'), $(this));
        });
    });
    </script>
    <?php
}
