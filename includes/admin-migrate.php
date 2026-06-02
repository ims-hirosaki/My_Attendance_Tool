<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 移行ツール
 * 旧テーブル（wp_my_attendance_logs）→ 新テーブル（wp_mat_attendance_daily）への
 * データ移行を管理画面から安全に実行するためのツール。
 *
 * フェーズ2：移行スクリプトのテスト・検証
 * フェーズ3：本番移行の実行
 *
 * 移行完了後、このファイルは削除しても構わない（メニューが消えるだけで他に影響なし）。
 */

// =========================================================
//  管理メニュー登録
// =========================================================

add_action( 'admin_menu', 'mat_register_migrate_menu', 99 );
function mat_register_migrate_menu() {
    add_submenu_page(
        'my-attendance-settings',
        'DB移行ツール',
        '⚙ DB移行',
        'manage_options',
        'mat-migrate',
        'mat_migrate_page_render'
    );
}

// =========================================================
//  移行ページのレンダリング
// =========================================================

function mat_migrate_page_render() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;

    // 現在の件数を取得
    $old_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . MAT_LOG_TABLE );
    $new_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . MAT_DAILY_TABLE );
    $migration_done = (bool) get_option( 'mat_migration_status' );

    // プレビュー・変換確認用（件数のみ）
    $preview = mat_migrate_preview();

    ?>
    <div class="wrap">
        <h1>⚙ DB移行ツール</h1>
        <p style="color:#666; max-width:700px;">
            旧テーブル（<code>wp_my_attendance_logs</code>）のデータを
            正規化済みの新テーブル（<code>wp_mat_attendance_daily</code>）へ移行します。<br>
            移行は上書き（UPSERT）方式のため、何度実行しても安全です。旧テーブルは移行後も削除しません。
        </p>

        <!-- ステータスカード -->
        <div style="display:flex; gap:20px; flex-wrap:wrap; margin:20px 0;">
            <?php
            $cards = array(
                array( '旧テーブル件数', $old_count . ' 件', '#f0f6ff', '#2271b1' ),
                array( '新テーブル件数', $new_count . ' 件', '#f0fff4', '#00a32a' ),
                array( '変換可能件数',   $preview['convertible'] . ' 件', '#fffbf0', '#dba617' ),
                array( '変換不能件数',   $preview['skipped'] . ' 件',
                    $preview['skipped'] > 0 ? '#fff5f5' : '#f9f9f9',
                    $preview['skipped'] > 0 ? '#d63638' : '#888' ),
            );
            foreach ( $cards as $c ) : ?>
                <div style="background:<?php echo $c[2]; ?>; border:1px solid <?php echo $c[3]; ?>33;
                            border-radius:6px; padding:16px 24px; min-width:150px;">
                    <div style="font-size:0.8em; color:#555; margin-bottom:4px;"><?php echo esc_html($c[0]); ?></div>
                    <div style="font-size:1.6em; font-weight:700; color:<?php echo $c[3]; ?>;">
                        <?php echo esc_html($c[1]); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ( $migration_done ) : ?>
            <div class="notice notice-success" style="max-width:700px;">
                <p>✅ 移行が完了しています（最終実行：<?php echo esc_html( get_option( 'mat_migration_last_run', '不明' ) ); ?>）</p>
            </div>
        <?php endif; ?>

        <!-- 変換不能データの内訳 -->
        <?php if ( ! empty( $preview['skip_details'] ) ) : ?>
            <div class="notice notice-warning" style="max-width:700px;">
                <p><strong>変換不能データが <?php echo count($preview['skip_details']); ?> 件あります。</strong>
                   これらは新テーブルに移行されません（旧テーブルには残ります）。</p>
                <details>
                    <summary style="cursor:pointer; color:#2271b1;">内訳を見る</summary>
                    <table class="widefat" style="margin-top:8px; max-width:100%;">
                        <thead><tr><th>ID</th><th>社員コード</th><th>timestamp</th><th>item_name</th><th>理由</th></tr></thead>
                        <tbody>
                            <?php foreach ( $preview['skip_details'] as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html($row['id']); ?></td>
                                    <td><?php echo esc_html($row['employee_code']); ?></td>
                                    <td><?php echo esc_html($row['timestamp']); ?></td>
                                    <td style="font-size:0.8em; word-break:break-all;"><?php echo esc_html($row['item_name']); ?></td>
                                    <td style="color:#d63638;"><?php echo esc_html($row['reason']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            </div>
        <?php endif; ?>

        <!-- 実行ボタン -->
        <div style="margin-top:24px; padding:20px; background:#fff; border:1px solid #ddd;
                    border-radius:6px; max-width:600px;">
            <h3 style="margin-top:0;">移行を実行する</h3>
            <p style="color:#666; font-size:0.9em;">
                移行は何度でも安全に実行できます（同一日のデータは上書きされます）。<br>
                変換不能なデータはスキップされ、旧テーブルに残ります。
            </p>

            <?php if ( isset( $_GET['mat_migrate_done'] ) ) : ?>
                <div class="notice notice-success inline">
                    <p><?php echo esc_html( urldecode( $_GET['mat_migrate_done'] ) ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <?php wp_nonce_field( 'mat_run_migration' ); ?>
                <input type="hidden" name="action" value="mat_run_migration">
                <input type="submit" class="button button-primary"
                    value="移行を実行する（<?php echo $preview['convertible']; ?> 件）"
                    onclick="return confirm('旧テーブルのデータを新テーブルへ移行します。よろしいですか？');">
            </form>
        </div>

        <!-- 注意事項 -->
        <div style="margin-top:30px; max-width:700px; font-size:0.85em; color:#666;">
            <h4>注意事項</h4>
            <ul>
                <li>旧テーブルは移行後も削除されません。フェーズ4（コード切り替え）完了後に別途削除します。</li>
                <li>現在のシステムは引き続き旧テーブルを使用しています。新テーブルはまだ参照されていません。</li>
                <li>移行後に旧テーブルへ新規打刻があった場合は、再度この画面で「移行を実行する」を押してください。</li>
            </ul>
        </div>
    </div>
    <?php
}

// =========================================================
//  移行実行（admin-post）
// =========================================================

add_action( 'admin_post_mat_run_migration', 'mat_run_migration_handler' );
function mat_run_migration_handler() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '権限がありません。' );
    check_admin_referer( 'mat_run_migration' );

    $result = mat_migrate_execute();

    update_option( 'mat_migration_status', true );
    update_option( 'mat_migration_last_run', current_time( 'Y-m-d H:i:s' ) );

    $msg = "移行完了：成功 {$result['success']} 件 / スキップ {$result['skipped']} 件";
    wp_redirect( admin_url( 'admin.php?page=mat-migrate&mat_migrate_done=' . urlencode( $msg ) ) );
    exit;
}

// =========================================================
//  移行プレビュー（実行前の件数確認）
// =========================================================

function mat_migrate_preview() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT id, employee_code, registered_user_id, timestamp, item_name
         FROM " . MAT_LOG_TABLE . "
         ORDER BY timestamp ASC"
    );

    $convertible  = 0;
    $skipped      = 0;
    $skip_details = array();

    foreach ( $rows as $r ) {
        $parsed = mat_migrate_parse_row( $r );
        if ( $parsed['ok'] ) {
            $convertible++;
        } else {
            $skipped++;
            if ( count( $skip_details ) < 50 ) { // 表示は最大50件
                $skip_details[] = array(
                    'id'            => $r->id,
                    'employee_code' => $r->employee_code,
                    'timestamp'     => $r->timestamp,
                    'item_name'     => $r->item_name,
                    'reason'        => $parsed['reason'],
                );
            }
        }
    }

    return compact( 'convertible', 'skipped', 'skip_details' );
}

// =========================================================
//  移行実行（本体）
// =========================================================

function mat_migrate_execute() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT id, employee_code, registered_user_id, timestamp, item_name
         FROM " . MAT_LOG_TABLE . "
         ORDER BY timestamp ASC"
    );

    $success = 0;
    $skipped = 0;

    foreach ( $rows as $r ) {
        $parsed = mat_migrate_parse_row( $r );
        if ( ! $parsed['ok'] ) {
            $skipped++;
            continue;
        }

        $data = array(
            'employee_id'   => (int) $r->registered_user_id,
            'employee_code' => $r->employee_code,
            'work_date'     => $parsed['work_date'],
            'clock_in'      => $parsed['clock_in'],
            'clock_out'     => $parsed['clock_out'],
            'break_minutes' => $parsed['break_minutes'],
            'is_holiday'    => $parsed['is_holiday'],
            'note'          => $parsed['note'],
        );

        // UPSERT（同一 employee_id + work_date は上書き）
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . MAT_DAILY_TABLE . " WHERE employee_id = %d AND work_date = %s",
            $data['employee_id'],
            $data['work_date']
        ) );

        if ( $existing_id ) {
            $wpdb->update( MAT_DAILY_TABLE, $data, array( 'id' => $existing_id ) );
        } else {
            $wpdb->insert( MAT_DAILY_TABLE, $data );
        }

        $success++;
    }

    return compact( 'success', 'skipped' );
}

// =========================================================
//  1行分の item_name をパースして新テーブル用データに変換
// =========================================================

function mat_migrate_parse_row( $row ) {
    $item      = trim( (string) $row->item_name );
    $timestamp = $row->timestamp;

    // work_date の取得
    $work_date = substr( $timestamp, 0, 10 );
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $work_date ) ) {
        return array( 'ok' => false, 'reason' => 'timestamp が不正な形式' );
    }

    // employee_id の確認
    if ( empty( $row->registered_user_id ) ) {
        return array( 'ok' => false, 'reason' => 'employee_id が空' );
    }

    // 休日
    if ( $item === '休日' ) {
        return array(
            'ok'           => true,
            'work_date'    => $work_date,
            'clock_in'     => null,
            'clock_out'    => null,
            'break_minutes'=> null,
            'is_holiday'   => 1,
            'note'         => null,
        );
    }

    // 出勤・退勤・休憩・備考のパース
    $clock_in      = null;
    $clock_out     = null;
    $break_minutes = null;
    $note          = null;

    if ( preg_match( '/出勤:\s*(\d{2}:\d{2})/', $item, $m ) ) {
        $clock_in = $m[1] . ':00'; // HH:MM → HH:MM:SS
    }
    if ( preg_match( '/退勤:\s*(\d{2}:\d{2})/', $item, $m ) ) {
        $clock_out = $m[1] . ':00';
    }
    if ( preg_match( '/休憩:\s*(\d{2}):(\d{2})/', $item, $m ) ) {
        $minutes = (int)$m[1] * 60 + (int)$m[2];
        $break_minutes = ( $minutes > 0 ) ? $minutes : null; // 00:00 は null 扱い
    }

    // 備考（複数あれば " / " で結合）
    if ( preg_match_all( '/備考:\s*([^|]+)/', $item, $matches ) ) {
        $notes = array_map( 'trim', $matches[1] );
        $notes = array_filter( $notes );
        if ( ! empty( $notes ) ) {
            $note = implode( ' / ', $notes );
        }
    }

    // 何もデータがない行（item_name が空など）はスキップ
    if ( $clock_in === null && $clock_out === null && $break_minutes === null && $note === null ) {
        // item_name に元データが何かあればスキップ扱いにする
        if ( $item !== '' && $item !== '休日' ) {
            return array( 'ok' => false, 'reason' => '解析できるデータがない: ' . mb_substr($item, 0, 40) );
        }
    }

    return array(
        'ok'            => true,
        'work_date'     => $work_date,
        'clock_in'      => $clock_in,
        'clock_out'     => $clock_out,
        'break_minutes' => $break_minutes,
        'is_holiday'    => 0,
        'note'          => $note,
    );
}
