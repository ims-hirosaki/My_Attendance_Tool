<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 従業員認証管理ページ
 */
add_action( 'admin_menu', 'mat_register_auth_management_page', 15 );
function mat_register_auth_management_page() {
    add_submenu_page(
        'my-attendance-settings',
        '従業員認証管理',
        '従業員認証管理',
        'access_custom_plugins',
        'mat-auth-management',
        'mat_auth_management_page_render'
    );
}

/**
 * パスワードリセット処理（Ajax）
 */
add_action( 'wp_ajax_mat_admin_reset_password', 'mat_admin_reset_password_handler' );
function mat_admin_reset_password_handler() {
    if ( ! current_user_can( 'edit_custom_plugins' ) ) {
        wp_send_json_error( '権限がありません。' );
    }
    check_ajax_referer( 'mat_admin_nonce', 'nonce' );

    $employee_codes = isset( $_POST['employee_codes'] )
        ? array_map( 'sanitize_text_field', (array) $_POST['employee_codes'] )
        : array();

    if ( empty( $employee_codes ) ) {
        wp_send_json_error( '対象の社員を選択してください。' );
    }

    global $wpdb;
    $reset_count = 0;

    foreach ( $employee_codes as $code ) {
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . MAT_AUTH_TABLE . " WHERE employee_code = %s",
            $code
        ) );

        if ( $existing ) {
            $wpdb->update(
                MAT_AUTH_TABLE,
                array(
                    'password_hash'      => null,
                    'is_registered'      => 0,
                    'login_failed_count' => 0,
                    'locked_until'       => null,
                    'reset_token'        => null,
                    'reset_token_expires' => null,
                ),
                array( 'employee_code' => $code ),
                array( '%s', '%d', '%d', '%s', '%s', '%s' ),
                array( '%s' )
            );
        }
        // 認証レコードがない社員はリセット不要（まだパスワード未設定）
        $reset_count++;
    }

    wp_send_json_success( array(
        'message' => "{$reset_count}名のパスワードをリセットしました。次回ログイン時に再設定が必要になります。",
    ) );
}

/**
 * 認証管理画面のレンダリング
 */
function mat_auth_management_page_render() {
    if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( '権限がありません。', '', array( 'response' => 403 ) );

    // 在籍社員一覧を employee-manager から取得
    $employees = emp_get_active_employees();

    // 認証状況を一括取得
    global $wpdb;
    $auth_records = $wpdb->get_results(
        "SELECT employee_code, is_registered, login_failed_count, locked_until, reset_token, updated_at
         FROM " . MAT_AUTH_TABLE
    );
    $auth_map = array();
    foreach ( $auth_records as $auth ) {
        $auth_map[ $auth->employee_code ] = $auth;
    }
    ?>
    <div class="wrap">
        <h1>🔐 従業員認証管理</h1>
        <p>社員のパスワード登録状況を確認し、リセットができます。</p>

        <?php if ( ! mat_get_setting( 'use_password_auth', true ) ) : ?>
            <div class="notice notice-info">
                <p>現在パスワード認証が <strong>OFF</strong> に設定されています。パスワードリセットは設定をONにした際に有効になります。</p>
            </div>
        <?php endif; ?>

        <div style="margin-bottom:16px;">
            <button id="mat-reset-selected" class="button button-primary" disabled>
                選択した社員のパスワードをリセット
            </button>
            <span id="mat-reset-result" style="margin-left:12px; font-weight:bold;"></span>
        </div>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="mat-check-all"></th>
                    <th style="width:120px;">社員コード</th>
                    <th>氏名</th>
                    <th style="width:140px;">パスワード状態</th>
                    <th style="width:120px;">ログイン失敗</th>
                    <th style="width:160px;">リセット申請</th>
                    <th style="width:160px;">最終更新</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $employees ) ) : ?>
                    <tr><td colspan="7" style="text-align:center; padding:20px;">在籍社員がいません。</td></tr>
                <?php else : ?>
                    <?php foreach ( $employees as $emp ) :
                        $auth   = $auth_map[ $emp->employee_code ] ?? null;
                        $status = '未ログイン';
                        $status_class = 'color:#888;';

                        if ( $auth ) {
                            if ( $auth->is_registered ) {
                                $status = '✅ 設定済み';
                                $status_class = 'color:#46b450; font-weight:bold;';
                            } else {
                                $status = '⚠️ 未設定';
                                $status_class = 'color:#f0a500; font-weight:bold;';
                            }
                        }

                        $is_locked = $auth && $auth->locked_until && strtotime( $auth->locked_until ) > time();
                        $has_reset_request = $auth && $auth->reset_token;
                    ?>
                    <tr <?php if ( $is_locked ) echo 'style="background:#fff5f5;"'; ?>>
                        <td>
                            <input type="checkbox" class="mat-emp-check"
                                value="<?php echo esc_attr( $emp->employee_code ); ?>">
                        </td>
                        <td><?php echo esc_html( $emp->employee_code ); ?></td>
                        <td><?php echo esc_html( $emp->name ); ?></td>
                        <td style="<?php echo $status_class; ?>">
                            <?php echo $status; ?>
                            <?php if ( $is_locked ) : ?>
                                <br><small style="color:#d63638;">🔒 ロック中（<?php echo date( 'H:i', strtotime( $auth->locked_until ) ); ?>まで）</small>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ( $auth ) : ?>
                                <?php
                                $count = (int) $auth->login_failed_count;
                                $color = $count >= 3 ? 'color:#d63638; font-weight:bold;' : '';
                                ?>
                                <span style="<?php echo $color; ?>"><?php echo $count; ?> 回</span>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $has_reset_request ) : ?>
                                <span style="color:#d63638; font-weight:bold;">📩 申請あり</span>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.85em; color:#666;">
                            <?php echo $auth ? esc_html( $auth->updated_at ) : '-'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var nonce = '<?php echo wp_create_nonce("mat_admin_nonce"); ?>';

        // 全選択チェックボックス
        $('#mat-check-all').on('change', function() {
            $('.mat-emp-check').prop('checked', $(this).is(':checked'));
            updateResetButton();
        });

        $('.mat-emp-check').on('change', function() {
            updateResetButton();
        });

        function updateResetButton() {
            var checked = $('.mat-emp-check:checked').length;
            $('#mat-reset-selected')
                .prop('disabled', checked === 0)
                .text(checked > 0 ? checked + ' 名のパスワードをリセット' : '選択した社員のパスワードをリセット');
        }

        // リセット実行
        $('#mat-reset-selected').on('click', function() {
            var codes = [];
            $('.mat-emp-check:checked').each(function() {
                codes.push($(this).val());
            });

            if (codes.length === 0) return;

            if (!confirm(codes.length + ' 名のパスワードをリセットします。よろしいですか？\n次回ログイン時にパスワードの再設定が必要になります。')) return;

            var $btn = $(this).prop('disabled', true).text('処理中...');

            $.post(ajaxurl, {
                action: 'mat_admin_reset_password',
                nonce: nonce,
                employee_codes: codes
            }, function(response) {
                $btn.prop('disabled', false).text('選択した社員のパスワードをリセット');
                if (response.success) {
                    $('#mat-reset-result').css('color', '#46b450').text('✅ ' + response.data.message);
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    $('#mat-reset-result').css('color', '#d63638').text('❌ ' + response.data);
                }
            });
        });
    });
    </script>
    <?php
}
