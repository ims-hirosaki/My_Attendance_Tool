<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================
//  管理メニュー登録
// =========================================================

add_action( 'admin_menu', 'mat_register_csv_import_page', 22 );
function mat_register_csv_import_page() {
    add_submenu_page(
        'my-attendance-settings',
        'CSV一括インポート',
        'CSV一括インポート',
        'access_custom_plugins',
        'mat-csv-import',
        'mat_csv_import_page_render'
    );
}

// =========================================================
//  CSS / JS の読み込み（$_GET['page'] 方式）
// =========================================================

add_action( 'admin_enqueue_scripts', 'mat_csv_import_enqueue' );
function mat_csv_import_enqueue() {
    if ( ( $_GET['page'] ?? '' ) !== 'mat-csv-import' ) return;

    wp_enqueue_script(
        'mat-csv-import-js',
        MAT_URL . 'js/mat-csv-import.js',
        array( 'jquery' ),
        MAT_VERSION,
        true
    );
    wp_enqueue_style(
        'mat-csv-import-css',
        MAT_URL . 'css/mat-csv-import.css',
        array(),
        MAT_VERSION
    );
    wp_localize_script( 'mat-csv-import-js', 'matCsvImport', array(
        'ajaxurl'       => admin_url( 'admin-ajax.php' ),
        'nonce'         => wp_create_nonce( 'mat_csv_import_nonce' ),
        'downloadUrl'   => wp_nonce_url(
            admin_url( 'admin.php?mat_action=download_csv_template' ),
            'mat_csv_template_download'
        ),
    ) );
}

// =========================================================
//  CSVテンプレートダウンロード
// =========================================================

add_action( 'admin_init', 'mat_csv_template_download' );
function mat_csv_template_download() {
    if ( ( $_GET['mat_action'] ?? '' ) !== 'download_csv_template' ) return;
    if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( '権限がありません。' );
    check_admin_referer( 'mat_csv_template_download' );

    if ( ob_get_length() ) ob_clean();

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="mat_import_sample.csv"' );
    header( 'Cache-Control: no-cache, no-store, must-revalidate' );

    $out = fopen( 'php://output', 'w' );
    fwrite( $out, "\xEF\xBB\xBF" ); // BOM（Excel文字化け防止）

    fputcsv( $out, array( '社員コード', '勤務日', '出勤時刻', '退勤時刻', '休憩時間', '有給希望日', '備考' ) );

    $samples = array(
        array( '1001', '2025-03-03', '09:00', '18:00', '01:00', '',           ''         ),
        array( '1001', '2025-03-04', '08:30', '17:30', '01:00', '',           '早出'     ),
        array( '1001', '2025-03-05', '',      '',       '',      '2025-03-05', '有給取得' ),
        array( '1001', '2025-03-06', '09:00', '20:00', '01:00', '',           '残業あり' ),
        array( '1001', '2025-03-07', '09:00', '18:00', '00:30', '',           ''         ),
        array( '1002', '2025-03-03', '08:00', '17:00', '01:00', '',           ''         ),
        array( '1002', '2025-03-04', '08:00', '17:00', '01:00', '',           ''         ),
        array( '1002', '2025-03-05', '',      '',       '',      '2025-03-05', ''         ),
        array( '1002', '2025-03-06', '08:00', '19:30', '01:00', '',           '残業'     ),
        array( '1002', '2025-03-07', '08:00', '17:00', '01:00', '',           ''         ),
    );
    foreach ( $samples as $row ) {
        fputcsv( $out, $row );
    }

    fclose( $out );
    exit;
}

// =========================================================
//  AJAX: CSVプレビュー（バリデーション）
// =========================================================

add_action( 'wp_ajax_mat_csv_preview', 'mat_csv_preview_handler' );
function mat_csv_preview_handler() {
    if ( ! current_user_can( 'access_custom_plugins' ) ) wp_send_json_error( '権限がありません。' );
    check_ajax_referer( 'mat_csv_import_nonce', 'nonce' );

    $rows_json = isset( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : '[]';
    $rows      = json_decode( $rows_json, true );

    if ( ! is_array( $rows ) || empty( $rows ) ) {
        wp_send_json_error( 'データが空です。' );
    }

    global $wpdb;

    // 在籍社員を一括取得してマップ化（照合用）
    $employees_raw = $wpdb->get_results(
        "SELECT id, employee_code, name FROM {$wpdb->prefix}emp_master"
    );
    $emp_map = array();
    foreach ( $employees_raw as $e ) {
        $emp_map[ $e->employee_code ] = $e;
    }

    // 既存レコードの日付を一括取得（重複チェック用）
    $existing_raw = $wpdb->get_results(
        "SELECT employee_code, DATE(timestamp) AS work_date FROM " . MAT_LOG_TABLE
    );
    $existing_set = array();
    foreach ( $existing_raw as $ex ) {
        $existing_set[ $ex->employee_code . '_' . $ex->work_date ] = true;
    }

    $preview   = array();
    $error_cnt = 0;
    $warn_cnt  = 0;
    $ok_cnt    = 0;
    $dup_cnt   = 0;

    foreach ( $rows as $i => $row ) {
        $line_no   = $i + 2; // ヘッダー行を除いた行番号
        $status    = 'ok';   // ok / warn / error / duplicate
        $messages  = array();

        $employee_code   = trim( $row['employee_code']   ?? '' );
        $work_date       = trim( $row['work_date']       ?? '' );
        $clock_in        = trim( $row['clock_in']        ?? '' );
        $clock_out       = trim( $row['clock_out']       ?? '' );
        $break_time      = trim( $row['break_time']      ?? '' );
        $paid_leave_date = trim( $row['paid_leave_date'] ?? '' );
        $note            = trim( $row['note']            ?? '' );

        // --- 必須チェック ---
        if ( $employee_code === '' ) {
            $messages[] = '社員コードが空です';
            $status = 'error';
        } elseif ( ! isset( $emp_map[ $employee_code ] ) ) {
            $messages[] = "社員コード「{$employee_code}」は存在しません";
            $status = 'error';
        }

        if ( $work_date === '' ) {
            $messages[] = '勤務日が空です';
            $status = 'error';
        } elseif ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $work_date ) ) {
            $messages[] = '勤務日の形式が不正です（YYYY-MM-DD）';
            $status = 'error';
        }

        // --- 時刻形式チェック ---
        if ( $clock_in !== '' && ! preg_match( '/^\d{2}:\d{2}$/', $clock_in ) ) {
            $messages[] = '出勤時刻の形式が不正です（HH:MM）';
            $status = 'error';
        }
        if ( $clock_out !== '' && ! preg_match( '/^\d{2}:\d{2}$/', $clock_out ) ) {
            $messages[] = '退勤時刻の形式が不正です（HH:MM）';
            $status = 'error';
        }
        if ( $break_time !== '' && ! preg_match( '/^\d{2}:\d{2}$/', $break_time ) ) {
            $messages[] = '休憩時間の形式が不正です（HH:MM）';
            $status = 'error';
        }

        // --- 論理チェック ---
        if ( $clock_in !== '' && $clock_out !== '' && $clock_in >= $clock_out ) {
            $messages[] = '退勤時刻が出勤時刻以前になっています';
            if ( $status === 'ok' ) $status = 'warn';
        }
        if ( $clock_in === '' && $clock_out === '' && $break_time === '' && $paid_leave_date === '' ) {
            $messages[] = '出退勤・休憩時間・有給希望日がすべて空です';
            if ( $status === 'ok' ) $status = 'warn';
        }
        if ( $paid_leave_date !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $paid_leave_date ) ) {
            $messages[] = '有給希望日の形式が不正です（YYYY-MM-DD）';
            $status = 'error';
        }

        // --- 重複チェック ---
        $dup_key = $employee_code . '_' . $work_date;
        if ( $status !== 'error' && isset( $existing_set[ $dup_key ] ) ) {
            $messages[] = 'この日付のレコードが既に存在します';
            if ( $status === 'ok' ) $status = 'duplicate';
        }

        // カウント集計
        switch ( $status ) {
            case 'error':     $error_cnt++; break;
            case 'warn':      $warn_cnt++;  break;
            case 'duplicate': $dup_cnt++;   break;
            default:          $ok_cnt++;    break;
        }

        $emp_name = isset( $emp_map[ $employee_code ] ) ? $emp_map[ $employee_code ]->name : '（不明）';

        $preview[] = array(
            'line_no'         => $line_no,
            'status'          => $status,
            'messages'        => $messages,
            'employee_code'   => $employee_code,
            'emp_name'        => $emp_name,
            'work_date'       => $work_date,
            'clock_in'        => $clock_in,
            'clock_out'       => $clock_out,
            'break_time'      => $break_time,
            'paid_leave_date' => $paid_leave_date,
            'note'            => $note,
        );
    }

    wp_send_json_success( array(
        'preview'   => $preview,
        'summary'   => array(
            'total'     => count( $rows ),
            'ok'        => $ok_cnt,
            'warn'      => $warn_cnt,
            'duplicate' => $dup_cnt,
            'error'     => $error_cnt,
        ),
    ) );
}

// =========================================================
//  AJAX: CSVインポート（チャンク処理）
// =========================================================

add_action( 'wp_ajax_mat_csv_import_chunk', 'mat_csv_import_chunk_handler' );
function mat_csv_import_chunk_handler() {
    if ( ! current_user_can( 'edit_custom_plugins' ) ) wp_send_json_error( '権限がありません。' );
    check_ajax_referer( 'mat_csv_import_nonce', 'nonce' );

    // 大量データ処理のためタイムアウトを延長
    @ini_set( 'max_execution_time', 300 );

    $rows_json     = isset( $_POST['rows'] )            ? wp_unslash( $_POST['rows'] ) : '[]';
    $on_duplicate  = isset( $_POST['on_duplicate'] )    ? sanitize_text_field( $_POST['on_duplicate'] ) : 'skip';
    $rows          = json_decode( $rows_json, true );

    if ( ! is_array( $rows ) || empty( $rows ) ) {
        wp_send_json_error( 'データが空です。' );
    }

    global $wpdb;

    // 在籍社員マップ
    $employees_raw = $wpdb->get_results(
        "SELECT id, employee_code, name FROM {$wpdb->prefix}emp_master"
    );
    $emp_map = array();
    foreach ( $employees_raw as $e ) {
        $emp_map[ $e->employee_code ] = $e;
    }

    $result = array(
        'inserted' => 0,
        'updated'  => 0,
        'skipped'  => 0,
        'errors'   => array(),
    );

    foreach ( $rows as $row ) {
        $employee_code   = trim( $row['employee_code']   ?? '' );
        $work_date       = trim( $row['work_date']       ?? '' );
        $clock_in        = trim( $row['clock_in']        ?? '' );
        $clock_out       = trim( $row['clock_out']       ?? '' );
        $break_time      = trim( $row['break_time']      ?? '' );
        $paid_leave_date = trim( $row['paid_leave_date'] ?? '' );
        $note            = trim( $row['note']            ?? '' );
        $line_no         = intval( $row['line_no']       ?? 0 );

        // エラー行はスキップ
        if ( ( $row['status'] ?? '' ) === 'error' ) {
            $result['skipped']++;
            continue;
        }

        // 社員マスタ取得
        if ( ! isset( $emp_map[ $employee_code ] ) ) {
            $result['errors'][] = "{$line_no}行目：社員コード「{$employee_code}」が見つかりません";
            $result['skipped']++;
            continue;
        }
        $emp = $emp_map[ $employee_code ];

        // item_name を組み立てる
        $parts = array();
        if ( $clock_in  !== '' ) $parts[] = "出勤: {$clock_in}";
        if ( $clock_out !== '' ) $parts[] = "退勤: {$clock_out}";
        $break_val = ( $break_time !== '' ) ? $break_time : '00:00';
        $parts[] = "休憩: {$break_val}";
        if ( $note !== '' )      $parts[] = "備考: {$note}";
        $item_name = implode( ' | ', $parts );

        // timestamp = 勤務日 + 出勤時刻（出勤がなければ 00:00:00）
        $ts_time  = ( $clock_in !== '' ) ? $clock_in . ':00' : '00:00:00';
        $timestamp = $work_date . ' ' . $ts_time;

        // 重複確認
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . MAT_LOG_TABLE
            . " WHERE employee_code = %s AND DATE(timestamp) = %s"
            . " LIMIT 1",
            $employee_code,
            $work_date
        ) );

        if ( $existing_id ) {
            if ( $on_duplicate === 'skip' ) {
                $result['skipped']++;
                continue;
            }
            // 上書き（update）
            $update_data = array(
                'item_name'            => $item_name,
                'timestamp'            => $timestamp,
                'registered_user_name' => $emp->name,
                'paid_leave_date'      => $paid_leave_date !== '' ? $paid_leave_date : null,
            );
            $updated = $wpdb->update(
                MAT_LOG_TABLE,
                $update_data,
                array( 'id' => (int) $existing_id ),
                array( '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );
            if ( $updated === false ) {
                $result['errors'][] = "{$line_no}行目：更新失敗 - " . $wpdb->last_error;
            } else {
                $result['updated']++;
            }
        } else {
            // 新規挿入
            $insert_data = array(
                'item_name'            => $item_name,
                'timestamp'            => $timestamp,
                'registered_user_id'   => (int) $emp->id,
                'registered_user_name' => $emp->name,
                'employee_code'        => $employee_code,
                'paid_leave_date'      => $paid_leave_date !== '' ? $paid_leave_date : null,
            );
            $inserted = $wpdb->insert(
                MAT_LOG_TABLE,
                $insert_data,
                array( '%s', '%s', '%d', '%s', '%s', '%s' )
            );
            if ( $inserted === false ) {
                $result['errors'][] = "{$line_no}行目：挿入失敗 - " . $wpdb->last_error;
            } else {
                $result['inserted']++;
            }
        }
    }

    wp_send_json_success( $result );
}

// =========================================================
//  管理画面レンダリング
// =========================================================

function mat_csv_import_page_render() {
    if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( '権限がありません。', '', array( 'response' => 403 ) );

    $template_url = wp_nonce_url(
        admin_url( 'admin.php?mat_action=download_csv_template' ),
        'mat_csv_template_download'
    );
    ?>
    <div class="wrap mat-csv-wrap">
        <h1>📥 CSV一括インポート</h1>

        <!-- ============ サンプルダウンロード ============ -->
        <div class="mat-csv-card">
            <h2>① CSVサンプルのダウンロード</h2>
            <p>以下のボタンからサンプルCSVをダウンロードして、見出し行（1行目）はそのままにデータを入力してください。</p>
            <a href="<?php echo esc_url( $template_url ); ?>" class="button button-secondary mat-download-btn">
                📄 サンプルCSVをダウンロード
            </a>
            <table class="mat-col-table">
                <thead>
                    <tr>
                        <th>CSV見出し（日本語）</th>
                        <th>内容・形式</th>
                        <th>必須</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>社員コード</td><td>従業員マスタと一致する社員コード</td><td>✅</td></tr>
                    <tr><td>勤務日</td><td>YYYY-MM-DD（例：2025-03-01）</td><td>✅</td></tr>
                    <tr><td>出勤時刻</td><td>HH:MM（例：09:00）／空欄可</td><td>—</td></tr>
                    <tr><td>退勤時刻</td><td>HH:MM（例：18:00）／空欄可</td><td>—</td></tr>
                    <tr><td>休憩時間</td><td>HH:MM（例：01:00）／空欄＝00:00</td><td>—</td></tr>
                    <tr><td>有給希望日</td><td>YYYY-MM-DD／空欄可（有給日のみ入力）</td><td>—</td></tr>
                    <tr><td>備考</td><td>自由記述／空欄可</td><td>—</td></tr>
                </tbody>
            </table>
            <p class="mat-csv-note">⚠️ 休日・公休日は行ごと省略してください（出退勤、休憩時間、または有給希望日のいずれかがある日のみ行を作成）。</p>
        </div>

        <!-- ============ ファイル選択・設定 ============ -->
        <div class="mat-csv-card">
            <h2>② CSVファイルの選択</h2>
            <div class="mat-csv-row">
                <div>
                    <label class="mat-csv-label">CSVファイル（UTF-8 または Shift-JIS）</label>
                    <input type="file" id="mat-csv-file" accept=".csv" class="mat-file-input">
                </div>
                <div>
                    <label class="mat-csv-label">重複レコードの扱い</label>
                    <label class="mat-radio-label">
                        <input type="radio" name="mat_on_duplicate" value="skip" checked> スキップ（既存を維持）
                    </label>
                    <label class="mat-radio-label">
                        <input type="radio" name="mat_on_duplicate" value="overwrite"> 上書き
                    </label>
                </div>
            </div>
            <button id="mat-csv-preview-btn" class="button button-primary" disabled>
                🔍 プレビュー・バリデーション
            </button>
            <p id="mat-csv-file-error" class="mat-csv-error" style="display:none;"></p>
        </div>

        <!-- ============ プレビュー結果 ============ -->
        <div class="mat-csv-card" id="mat-preview-area" style="display:none;">
            <h2>③ プレビュー確認</h2>

            <div id="mat-preview-summary" class="mat-summary-bar"></div>

            <div class="mat-table-wrap" id="mat-preview-table-wrap">
                <table class="widefat mat-preview-table" id="mat-preview-table">
                    <thead>
                        <tr>
                            <th>行番号</th>
                            <th>状態</th>
                            <th>社員コード</th>
                            <th>氏名</th>
                            <th>勤務日</th>
                            <th>出勤</th>
                            <th>退勤</th>
                            <th>休憩</th>
                            <th>有給希望日</th>
                            <th>備考</th>
                            <th>メッセージ</th>
                        </tr>
                    </thead>
                    <tbody id="mat-preview-tbody"></tbody>
                </table>
            </div>

            <div class="mat-import-actions">
                <button id="mat-csv-import-btn" class="button button-primary mat-import-btn">
                    ✅ インポート実行
                </button>
                <span class="mat-import-note">エラー行は自動的にスキップされます。</span>
            </div>
        </div>

        <!-- ============ 進捗バー ============ -->
        <div class="mat-csv-card" id="mat-progress-area" style="display:none;">
            <h2>④ インポート進行中...</h2>
            <div class="mat-progress-bar-wrap">
                <div class="mat-progress-bar" id="mat-progress-bar" style="width:0%;">0%</div>
            </div>
            <p id="mat-progress-label">準備中...</p>
        </div>

        <!-- ============ 完了結果 ============ -->
        <div class="mat-csv-card" id="mat-result-area" style="display:none;">
            <h2>⑤ インポート完了</h2>
            <div id="mat-result-content"></div>
        </div>

    </div><!-- .mat-csv-wrap -->
    <?php
}
