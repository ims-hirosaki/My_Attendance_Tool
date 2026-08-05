<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * フロントエンドショートコード [my_attendance_tool]
 */
add_shortcode( 'my_attendance_tool', 'mat_shortcode_render' );

function mat_shortcode_render() {
    wp_enqueue_style(
        'mat-style',
        MAT_URL . 'css/style.css',
        array(),
        MAT_VERSION
    );
    wp_enqueue_script(
        'mat-js',
        MAT_URL . 'js/main.js',
        array( 'jquery' ),
        MAT_VERSION,
        true
    );

    // 休憩スライダーの離散ステップ（休憩マスタ・sort_order 昇順）
    $break_master  = mat_get_break_master();
    $break_steps   = array();
    $default_index = 0;
    foreach ( $break_master as $i => $bm ) {
        $break_steps[] = array(
            'id'      => (int) $bm->id,
            'label'   => $bm->label,
            'minutes' => (int) $bm->break_minutes,
        );
        if ( (int) $bm->is_default === 1 ) $default_index = $i;
    }

    wp_localize_script( 'mat-js', 'matAjax', array(
        'ajaxurl'              => admin_url( 'admin-ajax.php' ),
        'nonce'                => wp_create_nonce( 'mat_nonce' ),
        'todayYmd'             => current_time( 'Y-m-d' ),
        'usePasswordAuth'      => mat_get_setting( 'use_password_auth', true )    ? '1' : '0',
        'usePaidLeaveApproval' => mat_get_setting( 'use_paid_leave_approval', true ) ? '1' : '0',
        'allowLogEdit'         => mat_get_setting( 'allow_log_edit', false )      ? '1' : '0',
        'showPaidLeaveRequest' => mat_get_setting( 'show_paid_leave_request', true ) ? '1' : '0',
        'breakSteps'           => $break_steps,
        'breakDefaultIndex'    => $default_index,
        'timeUnit'             => mat_get_time_unit(),
    ) );

    ob_start();
    ?>
    <div class="mat-wrap">

        <!-- ======================== 時計 ======================== -->
        <div class="mat-clock" id="mat-clock">00:00:00</div>

        <!-- ======================== SECTION: 社員コード入力 ======================== -->
        <div class="mat-section" id="mat-section-code">
            <h2 class="mat-section-title">社員コードを入力してください</h2>
            <input type="text" id="mat-employee-code"
                class="mat-input mat-input-center"
                placeholder="例：1234"
                autocomplete="off"
                inputmode="numeric"
                pattern="[0-9]*">
            <button id="mat-btn-verify-code" class="mat-btn mat-btn-primary mat-btn-full">
                次へ
            </button>
            <p class="mat-error" id="mat-error-code"></p>
        </div>

        <!-- ======================== SECTION: パスワード新規設定（初回） ======================== -->
        <div class="mat-section" id="mat-section-set-password" style="display:none;">
            <h2 class="mat-section-title">パスワードを設定してください</h2>
            <p class="mat-hint">初回ログインです。6文字以上のパスワードを設定してください。</p>
            <input type="password" id="mat-new-password"
                class="mat-input"
                placeholder="新しいパスワード（6文字以上）">
            <input type="password" id="mat-new-password2"
                class="mat-input"
                placeholder="パスワード（確認）"
                style="margin-top:8px;">
            <button id="mat-btn-set-password" class="mat-btn mat-btn-primary mat-btn-full">
                パスワードを設定してログイン
            </button>
            <p class="mat-error" id="mat-error-set-password"></p>
            <p class="mat-back-link">
                <a href="#" id="mat-back-to-code-from-setpw">← 社員コード入力に戻る</a>
            </p>
        </div>

        <!-- ======================== SECTION: パスワード入力 ======================== -->
        <div class="mat-section" id="mat-section-enter-password" style="display:none;">
            <h2 class="mat-section-title">パスワードを入力してください</h2>
            <p class="mat-greeting" id="mat-greeting-password"></p>
            <input type="password" id="mat-password"
                class="mat-input"
                placeholder="パスワード">
            <button id="mat-btn-login" class="mat-btn mat-btn-primary mat-btn-full">
                ログイン
            </button>
            <p class="mat-error" id="mat-error-login"></p>
            <p class="mat-back-link">
                <a href="#" id="mat-back-to-code-from-login">← 社員コード入力に戻る</a>
            </p>
            <p class="mat-back-link" style="margin-top:0;">
                <a href="#" id="mat-forgot-password">パスワードを忘れた場合</a>
            </p>
        </div>

        <!-- ======================== SECTION: パスワードリセット申請 ======================== -->
        <div class="mat-section" id="mat-section-reset-request" style="display:none;">
            <h2 class="mat-section-title">パスワードリセット申請</h2>
            <p class="mat-hint">管理者がパスワードをリセットします。<br>社員コードを入力して申請してください。</p>
            <input type="text" id="mat-reset-code"
                class="mat-input mat-input-center"
                placeholder="社員コード">
            <button id="mat-btn-reset-request" class="mat-btn mat-btn-secondary mat-btn-full">
                リセットを申請する
            </button>
            <p class="mat-success" id="mat-success-reset" style="display:none;"></p>
            <p class="mat-error" id="mat-error-reset"></p>
            <p class="mat-back-link">
                <a href="#" id="mat-back-to-code-from-reset">← 戻る</a>
            </p>
        </div>

        <!-- ======================== SECTION: 打刻メイン ======================== -->
        <div class="mat-section" id="mat-section-main" style="display:none;">

            <!-- 1. 社員名 -->
            <div class="mat-user-badge">
                <span id="mat-user-name"></span>
            </div>

            <!-- 2. 出勤・退勤ボタン -->
            <div class="mat-punch-buttons">
                <button class="mat-btn mat-btn-checkin mat-punch-btn"
                    data-label="出勤">出勤</button>
                <button class="mat-btn mat-btn-checkout mat-punch-btn"
                    data-label="退勤">退勤</button>
            </div>

            <!-- 3. 休憩（休憩マスタ連動の離散スライダー） -->
            <div class="mat-break-box">
                <div class="mat-break-header">
                    <span>休憩時間</span>
                    <span class="mat-break-value" id="mat-break-display">--</span>
                </div>
                <input type="range" id="mat-break-slider"
                    class="mat-range"
                    min="0" max="<?php echo esc_attr( max( 0, count( $break_steps ) - 1 ) ); ?>"
                    step="1" value="<?php echo esc_attr( $default_index ); ?>">
                <p class="mat-break-label" id="mat-break-label"></p>
                <button class="mat-btn mat-btn-break mat-punch-btn mat-btn-full"
                    data-label="休憩">休憩を登録</button>
            </div>

            <!-- 4. 備考 -->
            <div class="mat-note-box">
                <label class="mat-label">備考</label>
                <textarea id="mat-note" class="mat-textarea" placeholder="備考があれば入力"></textarea>
                <button id="mat-btn-save-note" class="mat-btn mat-btn-secondary mat-btn-full" type="button">
                    備考を登録
                </button>
            </div>

            <!-- 5. 打刻ログ -->
            <div class="mat-history-header">
                打刻ログ
                <input type="month" id="mat-view-month"
                    class="mat-month-picker"
                    value="<?php echo esc_attr( current_time( 'Y-m' ) ); ?>">
            </div>

            <div class="mat-history-scroll">
                <table class="mat-history-table" id="mat-history-table">
                    <thead>
                        <tr>
                            <th>日付</th>
                            <th>出勤</th>
                            <th>退勤</th>
                            <th>休憩</th>
                            <th>備考</th>
                            <th>休日</th>
                            <?php if ( mat_get_setting( 'allow_log_edit', false ) ) : ?>
                                <th>編集</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="mat-history-body">
                        <tr><td colspan="7" class="mat-loading">読み込み中...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ★ 6. 休日登録 -->
            <div class="mat-holiday-section">
                <div class="mat-history-header" style="margin-top:20px;">
                    休日の登録
                </div>
                <p class="mat-hint" style="margin:6px 0 10px;">
                    打刻忘れか休みか不明な日付を「休日」として登録できます。
                </p>
                <div class="mat-paid-leave-form">
                    <input type="date" id="mat-holiday-date" class="mat-input mat-input-date">
                    <button id="mat-btn-register-holiday" class="mat-btn mat-btn-secondary">休日として登録</button>
                </div>
                <p class="mat-error" id="mat-error-holiday" style="display:none;"></p>
                <p class="mat-success" id="mat-success-holiday" style="display:none;"></p>
            </div>

            <!-- 7. 有給希望日の申請 -->
            <?php if ( mat_get_setting( 'show_paid_leave_request', true ) ) : ?>
            <div class="mat-paid-leave-section" id="mat-paid-leave-section">

                <div class="mat-history-header" style="margin-top:20px;">
                    有給希望日の申請
                </div>

                <!-- 申請フォーム -->
                <div class="mat-paid-leave-form">
                    <input type="date" id="mat-paid-leave-date" class="mat-input mat-input-date">
                    <button id="mat-btn-paid-leave" class="mat-btn mat-btn-danger">希望申請</button>
                </div>
                <p class="mat-error" id="mat-error-paid-leave" style="display:none;"></p>

                <!-- 申請履歴テーブル -->
                <div class="mat-history-scroll" style="margin-top:10px;">
                    <table class="mat-history-table" id="mat-paid-leave-table">
                        <thead>
                            <tr>
                                <th>申請日</th>
                                <th>有給希望日</th>
                                <th>状態</th>
                            </tr>
                        </thead>
                        <tbody id="mat-paid-leave-body">
                            <tr><td colspan="3" class="mat-loading">読み込み中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ログアウト -->
            <p class="mat-logout-row">
                <a href="#" id="mat-logout">ログアウト</a>
            </p>
        </div>

        <!-- ======================== 打刻編集モーダル ======================== -->
        <div class="mat-modal" id="mat-edit-modal" style="display:none;">
            <div class="mat-modal-inner">
                <h3 class="mat-modal-title">打刻データの編集</h3>
                <div class="mat-modal-row">
                    <label>出勤</label>
                    <input type="time" id="mat-edit-in" class="mat-input">
                </div>
                <div class="mat-modal-row">
                    <label>退勤</label>
                    <input type="time" id="mat-edit-out" class="mat-input">
                </div>
                <div class="mat-modal-row">
                    <label>休憩</label>
                    <input type="time" id="mat-edit-break" class="mat-input" value="00:00">
                </div>
                <div class="mat-modal-row">
                    <label>備考</label>
                    <textarea id="mat-edit-note" class="mat-textarea" rows="2"></textarea>
                </div>
                <p class="mat-error" id="mat-edit-error" style="display:none;"></p>
               <div class="mat-modal-actions">
    <button type="button" id="mat-edit-delete" class="mat-btn mat-btn-danger" style="margin-right:auto;">🗑 削除する</button>
    <button type="button" id="mat-edit-cancel" class="mat-btn mat-btn-secondary">キャンセル</button>
    <button type="button" id="mat-edit-save" class="mat-btn mat-btn-primary">💾 保存する</button>
</div>
            </div>
        </div>

        <!-- ======================== ① 日跨ぎ確認ポップアップ ======================== -->
        <div class="mat-modal" id="mat-overnight-modal" style="display:none;">
            <div class="mat-modal-inner">
                <h3 class="mat-modal-title">前日の退勤打刻が未完了です</h3>
                <p class="mat-modal-text" id="mat-overnight-text"></p>
                <div class="mat-modal-actions">
                    <button type="button" id="mat-overnight-cancel" class="mat-btn mat-btn-secondary">キャンセル</button>
                    <button type="button" id="mat-overnight-ok" class="mat-btn mat-btn-primary">前日の退勤として登録</button>
                </div>
            </div>
        </div>

        <!-- ======================== ② 例外休憩ポップアップ ======================== -->
        <div class="mat-modal" id="mat-break-exception-modal" style="display:none;">
            <div class="mat-modal-inner">
                <h3 class="mat-modal-title">休憩時間がイレギュラーです</h3>
                <div class="mat-modal-text">
                    <div class="mat-kv">
                        <span class="mat-kv-k">選択された休憩時間</span>
                        <strong class="mat-kv-v" id="mat-be-selected">--</strong>
                    </div>
                    <div class="mat-kv">
                        <span class="mat-kv-k">基準の休憩時間</span>
                        <strong class="mat-kv-v" id="mat-be-standard">--</strong>
                    </div>
                </div>

                <label class="mat-radio-row">
                    <input type="radio" name="mat-be-choice" value="request" checked>
                    <span class="mat-radio-text">例外休憩を申請して登録する</span>
                </label>
                <textarea id="mat-be-reason" class="mat-textarea mat-radio-child" rows="2"
                    placeholder="理由（必須）"></textarea>

                <label class="mat-radio-row">
                    <input type="radio" name="mat-be-choice" value="fix">
                    <span class="mat-radio-text">休憩時間を<span id="mat-be-fix-label">基準</span>に修正して登録する</span>
                </label>

                <p class="mat-error" id="mat-be-error" style="display:none;"></p>
                <div class="mat-modal-actions">
                    <button type="button" id="mat-be-cancel" class="mat-btn mat-btn-secondary">キャンセル</button>
                    <button type="button" id="mat-be-ok" class="mat-btn mat-btn-primary">登録</button>
                </div>
            </div>
        </div>

        <!-- ======================== ③ 残業確認ポップアップ ======================== -->
        <div class="mat-modal" id="mat-overtime-modal" style="display:none;">
            <div class="mat-modal-inner">
                <h3 class="mat-modal-title">残業時間が発生しています</h3>
                <div class="mat-modal-text" id="mat-ot-summary"></div>

                <label class="mat-radio-row">
                    <input type="radio" name="mat-ot-choice" value="request" checked>
                    <span class="mat-radio-text">残業を申請して登録する</span>
                </label>
                <textarea id="mat-ot-reason" class="mat-textarea mat-radio-child" rows="2"
                    placeholder="理由（必須）"></textarea>

                <label class="mat-radio-row">
                    <input type="radio" name="mat-ot-choice" value="fix">
                    <span class="mat-radio-text">退勤時刻を修正する</span>
                </label>
                <input type="time" id="mat-ot-time" class="mat-input mat-radio-child">

                <p class="mat-error" id="mat-ot-error" style="display:none;"></p>
                <div class="mat-modal-actions">
                    <button type="button" id="mat-ot-cancel" class="mat-btn mat-btn-secondary">キャンセル</button>
                    <button type="button" id="mat-ot-ok" class="mat-btn mat-btn-primary">登録</button>
                </div>
            </div>
        </div>

    </div><!-- /.mat-wrap -->
    <?php
    return ob_get_clean();
}