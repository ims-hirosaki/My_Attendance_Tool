<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * admin-alert-modal.php  v3.3.0
 *
 * 「従業員打刻履歴」と「アラート一覧」で共用する打刻修正モーダル（要件定義書 §6.3 / §7）。
 *
 * 使い方：
 *   mat_render_alert_modal();  // ページ末尾で1回だけ呼ぶ（HTML + JS を出力）
 *   <button class="mat-alert-fix-btn" data-daily-id="123">修正</button>
 *
 * 保存後の挙動はページ側で window.matAlertModalOnSaved を差し替えて変更できる。
 *
 * 変更点（v3.3.0）：
 * - 深夜休憩フィールド・深夜時間（自動計算）表示を追加（§7.3）。
 * - 1日に例外休憩・残業・深夜休憩の最大3種類の申請が同時に存在し得るため、
 *   対応ステータス・承認ステータス・管理者コメントを申請種別ごとに独立させた
 *  （申請理由の下に種別ごとのカードを積む方式。タブは使わない）。
 */

// =========================================================
//  Ajax：モーダル表示用データの取得
// =========================================================

add_action( 'wp_ajax_mat_admin_get_alert_row', 'mat_admin_get_alert_row_handler' );
function mat_admin_get_alert_row_handler() {
	if ( ! current_user_can( 'access_custom_plugins' ) ) wp_send_json_error( '権限がありません。' );
	check_ajax_referer( 'mat_alert_nonce', 'nonce' );

	global $wpdb;
	$daily_id = intval( $_POST['daily_id'] ?? 0 );
	if ( $daily_id <= 0 ) wp_send_json_error( '対象データが指定されていません。' );

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . MAT_DAILY_TABLE . " WHERE id = %d", $daily_id
	) );
	if ( ! $row ) wp_send_json_error( 'データが見つかりません。' );

	wp_send_json_success( mat_build_alert_modal_payload( $row ) );
}

/**
 * 種別ラベル（申請カード・ステータスバッジ共通）。
 */
function mat_request_type_labels() {
	return array(
		'break_exception' => '例外休憩',
		'overtime'         => '残業',
		'midnight_break'   => '深夜休憩',
	);
}

/**
 * モーダルに渡すデータを組み立てる。
 */
function mat_build_alert_modal_payload( $row ) {
	$requests = mat_get_work_requests_by_daily( (int) $row->id );
	$data     = mat_decorate_daily_row( $row, $requests );

	$emp  = function_exists( 'emp_get_employee_by_code' ) ? emp_get_employee_by_code( $row->employee_code ) : null;
	$dow  = array( '日', '月', '火', '水', '木', '金', '土' );
	$ts   = strtotime( $row->work_date );

	// 表示する申請種別 = 現在のアラートに該当する種別 ∪ 既存申請がある種別
	$relevant_types = array();
	foreach ( $data['alerts'] as $a ) {
		$t = mat_alert_code_to_request_type( $a['code'] );
		if ( $t ) $relevant_types[] = $t;
	}
	$relevant_types = array_unique( array_merge( $relevant_types, array_keys( $requests ) ) );

	$labels   = mat_request_type_labels();
	$sections = array();
	foreach ( array( 'break_exception', 'overtime', 'midnight_break' ) as $type ) {
		if ( ! in_array( $type, $relevant_types, true ) ) continue;
		$req = $requests[ $type ] ?? null;
		$sections[] = array(
			'type'            => $type,
			'label'           => $labels[ $type ],
			'reason'          => $req ? (string) $req->reason : '',
			'requested_by'    => $req ? $req->requested_by : '',
			'review_status'   => $req ? (int) $req->review_status : 0,
			'approval_status' => $req ? (int) $req->approval_status : 0,
			'admin_comment'   => $req ? (string) $req->admin_comment : '',
		);
	}

	// 深夜該当時間・拘束時間はクライアント側で出勤・退勤からライブ再計算するため、丸め単位のみ渡す
	$unit = ! empty( $row->time_unit ) ? (int) $row->time_unit : mat_get_time_unit();

	return array(
		'time_unit'       => $unit,
		'daily_id'        => (int) $row->id,
		'employee_code'   => $row->employee_code,
		'employee_name'   => $emp ? $emp->name : '',
		'work_date'       => $row->work_date,
		'date_label'      => (int) date( 'n', $ts ) . '月' . (int) date( 'j', $ts ) . '日('
			. $dow[ date( 'w', $ts ) ] . ')',
		'clock_in'        => $data['clock_in'],
		'clock_out'       => $data['clock_out'],
		'break_minutes'   => $data['break_minutes'] === null ? '' : $data['break_minutes'],
		'rounded_in'      => $data['rounded_clock_in'] ?: '−',
		'rounded_out'     => $data['rounded_clock_out'] ?: '−',
		'overtime_text'   => $data['overtime_minutes'] ? mat_minutes_to_hm( $data['overtime_minutes'] ) : '0:00',
		'standard_break'  => $data['standard_break'],
		'midnight_break_minutes' => $data['midnight_break_minutes'],
		'break_out_start' => $data['break_out_start'],
		'break_out_end'   => $data['break_out_end'],
		'note'     => (string) $row->note,
		'alerts'   => $data['alerts'],
		'sections' => $sections,
	);
}

// =========================================================
//  Ajax：保存
// =========================================================

add_action( 'wp_ajax_mat_admin_save_alert_fix', 'mat_admin_save_alert_fix_handler' );
function mat_admin_save_alert_fix_handler() {
	if ( ! current_user_can( 'edit_custom_plugins' ) ) wp_send_json_error( '権限がありません。' );
	check_ajax_referer( 'mat_alert_nonce', 'nonce' );

	global $wpdb;
	$daily_id = intval( $_POST['daily_id'] ?? 0 );
	if ( $daily_id <= 0 ) wp_send_json_error( '対象データが指定されていません。' );

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . MAT_DAILY_TABLE . " WHERE id = %d", $daily_id
	) );
	if ( ! $row ) wp_send_json_error( 'データが見つかりません。' );

	// 修正によってアラートが解消しても、保存前に存在したアラートへの
	// 対応ステータスを履歴として残せるよう、更新前の状態を保持する。
	$original_requests = mat_get_work_requests_by_daily( $daily_id );
	$original_alerts   = mat_build_row_alerts( $row, $original_requests );

	$clock_in  = sanitize_text_field( $_POST['clock_in']  ?? '' );
	$clock_out = sanitize_text_field( $_POST['clock_out'] ?? '' );
	$break_in  = sanitize_text_field( $_POST['break_minutes'] ?? '' );
	$note      = sanitize_textarea_field( $_POST['note'] ?? '' );

	$in_min  = $clock_in  === '' ? null : mat_parse_time_to_minutes( $clock_in );
	$out_min = $clock_out === '' ? null : mat_parse_time_to_minutes( $clock_out );
	if ( $clock_in !== '' && $in_min === null )   wp_send_json_error( '出勤時刻の形式が正しくありません（HH:MM）。' );
	if ( $clock_out !== '' && $out_min === null ) wp_send_json_error( '退勤時刻の形式が正しくありません（HH:MM）。' );

	if ( $break_in !== '' && ( ! is_numeric( $break_in ) || (int) $break_in < 0 ) ) {
		wp_send_json_error( '休憩時間は0以上の整数（分）で入力してください。' );
	}
	$break_minutes = $break_in === '' ? 0 : (int) $break_in;

	$unit = ! empty( $row->time_unit ) ? (int) $row->time_unit : mat_get_time_unit();
	$rounded_in_preview  = $in_min  !== null ? mat_minutes_to_time_sql( mat_round_in_minutes( $in_min, $unit ) )  : null;
	$rounded_out_preview = $out_min !== null ? mat_minutes_to_time_sql( mat_round_out_minutes( $out_min, $unit ) ) : null;

	// ---- 中抜け（要件定義書 §12.4） ----
	$break_out_enabled = ( ( $_POST['break_out_enabled'] ?? '0' ) === '1' );
	$break_out_start   = null;
	$break_out_end     = null;
	if ( $break_out_enabled ) {
		$bo_start_min = mat_parse_time_to_minutes( sanitize_text_field( $_POST['break_out_start'] ?? '' ) );
		$bo_end_min   = mat_parse_time_to_minutes( sanitize_text_field( $_POST['break_out_end']   ?? '' ) );
		if ( $bo_start_min === null || $bo_end_min === null ) {
			wp_send_json_error( '中抜けの開始・終了時刻を入力してください。' );
		}
		$rounded_in_min_val  = mat_parse_time_to_minutes( $rounded_in_preview );
		$rounded_out_min_val = mat_parse_time_to_minutes( $rounded_out_preview );
		if ( $rounded_in_min_val === null || $rounded_out_min_val === null
			|| $bo_start_min < $rounded_in_min_val || $bo_end_min <= $bo_start_min || $bo_end_min > $rounded_out_min_val ) {
			wp_send_json_error( '中抜け時間は始業〜終業の範囲内で指定してください。' );
		}
		$break_out_start = mat_minutes_to_time_sql( $bo_start_min );
		$break_out_end   = mat_minutes_to_time_sql( $bo_end_min );
	}

	// ---- 深夜休憩（要件定義書 §7.3） ----
	$midnight_span_preview = mat_calc_midnight_span_minutes( $rounded_in_preview, $rounded_out_preview, $break_out_start, $break_out_end );

	$midnight_input         = sanitize_text_field( $_POST['midnight_break_minutes'] ?? '' );
	$midnight_break_minutes = null;
	if ( $midnight_input !== '' ) {
		if ( ! is_numeric( $midnight_input ) || (int) $midnight_input < 0 ) {
			wp_send_json_error( '深夜休憩は0以上の整数（分）で入力してください。' );
		}
		$midnight_break_minutes = (int) $midnight_input;

		if ( $midnight_span_preview !== null && $midnight_break_minutes > $midnight_span_preview ) {
			wp_send_json_error( sprintf( '深夜休憩は深夜該当時間（%s）を超えられません。', mat_format_minutes_jp_padded( $midnight_span_preview ) ) );
		}
		if ( $midnight_break_minutes > $break_minutes ) {
			wp_send_json_error( '深夜休憩が休憩時間を超えています。休憩時間をご確認ください。' );
		}
	}

	// ---- 拘束時間と休憩の整合チェック（要件定義書 §12.4 バリデーション3） ----
	$kousoku_preview = mat_calc_work_minutes( $rounded_in_preview, $rounded_out_preview, $break_minutes, $break_out_start, $break_out_end )['kousoku'];
	if ( $kousoku_preview !== null && $kousoku_preview - $break_minutes < 0 ) {
		wp_send_json_error( '休憩時間が拘束時間を超えています。中抜けと休憩の入力をご確認ください。' );
	}

	$wpdb->update( MAT_DAILY_TABLE, array(
		'clock_in'               => mat_minutes_to_time_sql( $in_min ),
		'clock_out'              => mat_minutes_to_time_sql( $out_min ),
		'break_minutes'          => $break_in === '' ? null : $break_minutes,
		'note'                   => $note !== '' ? $note : null,
		'midnight_break_minutes' => $midnight_break_minutes,
		'break_out_start'        => $break_out_start,
		'break_out_end'          => $break_out_end,
	), array( 'id' => $daily_id ) );

	// 出勤・退勤・休憩・深夜休憩を修正したので始業／終業／残業／深夜時間を再計算する（§6.4）
	$row = mat_recalc_daily_row( $daily_id );

	// ---- ステータスの保存（種別ごと・要件定義書 §7.3） ----
	$statuses = is_array( $_POST['statuses'] ?? null ) ? $_POST['statuses'] : array();

	$requests = mat_get_work_requests_by_daily( $daily_id );
	$alerts   = mat_build_row_alerts( $row, $requests );

	// 保存前または現在のアラートに対応する申請種別を決定する。
	// 時刻・休憩の修正でアラートが解消した場合も、選択されたステータスを保存する。
	$types = array();
	foreach ( array_merge( $original_alerts, $alerts ) as $a ) {
		$t = mat_alert_code_to_request_type( $a['code'] );
		if ( $t ) $types[] = $t;
	}
	// アラートが解消していても、既存申請やフォーム送信があればステータスは反映する
	$types = array_unique( array_merge( $types, array_keys( $requests ), array_keys( $statuses ) ) );

	foreach ( $types as $type ) {
		if ( ! in_array( $type, array( 'break_exception', 'overtime', 'midnight_break' ), true ) ) continue;

		$s        = is_array( $statuses[ $type ] ?? null ) ? $statuses[ $type ] : array();
		$review   = intval( $s['review_status']   ?? 0 );
		$approval = intval( $s['approval_status'] ?? 0 );
		$comment  = sanitize_textarea_field( $s['admin_comment'] ?? '' );

		if ( ! in_array( $review, array( 0, 1, 2, 3 ), true ) )   $review   = 0;
		if ( ! in_array( $approval, array( 0, 1, 2, 3 ), true ) ) $approval = 0;

		// 申請が未作成のうちは、ステータス・コメントが1つも入力されていなければ作成しない
		$exists = isset( $requests[ $type ] );
		if ( ! $exists && $review === 0 && $approval === 0 && $comment === '' ) continue;

		mat_upsert_work_request( array(
			'daily_id'        => $daily_id,
			'request_type'    => $type,
			'review_status'   => $review,
			'approval_status' => $approval,
			'admin_comment'   => $comment !== '' ? $comment : null,
			'reviewed_by'     => get_current_user_id(),
			// 赤アラート（申請なし）への管理者対応ケースは requested_by = 'admin'
			'requested_by'    => $exists ? $requests[ $type ]->requested_by : 'admin',
		) );
	}

	wp_send_json_success( mat_build_alert_modal_payload(
		$wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . MAT_DAILY_TABLE . " WHERE id = %d", $daily_id ) )
	) );
}

// =========================================================
//  表示ヘルパー：アラートバッジ
// =========================================================

/**
 * アラート配列をバッジHTMLに変換する。
 * 対応済み（resolved）のバッジはグレーアウト表示で履歴として残す（§6.3）。
 */
function mat_render_alert_badges( array $alerts ) {
	if ( empty( $alerts ) ) return '<span style="color:#bbb;">−</span>';

	$colors = array(
		'yellow' => array( '#fff8e1', '#dba617', '#8a6100' ),
		'blue'   => array( '#f0f6fc', '#2271b1', '#0a4b78' ),
		'red'    => array( '#fcf0f1', '#d63638', '#8a1f21' ),
	);

	$html = '<div style="display:flex; flex-wrap:wrap; gap:4px;">';
	foreach ( $alerts as $a ) {
		$c = $colors[ $a['color'] ] ?? $colors['yellow'];
		if ( ! empty( $a['resolved'] ) ) $c = array( '#f2f2f2', '#c3c4c7', '#8c8f94' );

		$html .= sprintf(
			'<span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.78em; font-weight:600;
				background:%s; border:1px solid %s; color:%s; line-height:1.6; white-space:nowrap;">%s</span>',
			esc_attr( $c[0] ), esc_attr( $c[1] ), esc_attr( $c[2] ), esc_html( $a['label'] )
		);
	}
	return $html . '</div>';
}

/**
 * 対応ステータス・承認ステータスのバッジ。
 * 種別が2つ以上あれば種別名を先頭に付けて区別する（要件定義書 §7.3）。
 */
function mat_render_status_badges( array $requests ) {
	$labels = mat_request_type_labels();
	$active = array();
	foreach ( array( 'break_exception', 'overtime', 'midnight_break' ) as $type ) {
		$req = $requests[ $type ] ?? null;
		if ( $req && ( (int) $req->review_status > 0 || (int) $req->approval_status > 0 ) ) {
			$active[ $type ] = $req;
		}
	}
	if ( empty( $active ) ) return '';

	$multi = count( $active ) > 1;
	$html  = '<div style="display:flex; flex-direction:column; gap:3px;">';

	foreach ( $active as $type => $req ) {
		$review = (int) $req->review_status;
		$appr   = (int) $req->approval_status;

		$html .= '<div style="display:flex; flex-wrap:nowrap; gap:4px; align-items:center; white-space:nowrap;">';
		if ( $multi ) {
			$html .= '<span style="font-size:0.72em; color:#888;">' . esc_html( $labels[ $type ] ) . '</span>';
		}
		if ( $review > 0 ) {
			$html .= '<span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.78em;
				background:#f0f6fc; border:1px solid #2271b1; color:#0a4b78;">'
				. esc_html( MAT_REVIEW_LABELS[ $review ] ) . '</span>';
		}
		if ( $appr > 0 ) {
			$bg = $appr === 2 ? '#f0fff4' : ( $appr === 3 ? '#fcf0f1' : '#fffbf0' );
			$bd = $appr === 2 ? '#00a32a' : ( $appr === 3 ? '#d63638' : '#dba617' );
			$html .= '<span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.78em;
				background:' . $bg . '; border:1px solid ' . $bd . '; color:#1d2327;">'
				. esc_html( MAT_APPROVAL_LABELS[ $appr ] ) . '</span>';
		}
		$html .= '</div>';
	}
	return $html . '</div>';
}

// =========================================================
//  モーダル本体（HTML + JS）
// =========================================================

function mat_render_alert_modal() {
	static $rendered = false;
	if ( $rendered ) return;
	$rendered = true;
	?>
	<div id="mat-alert-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
		z-index:100000; align-items:center; justify-content:center;">
		<div style="background:#fff; border-radius:8px; padding:26px; width:560px; max-width:92%;
			max-height:90vh; overflow:auto; box-shadow:0 4px 16px rgba(0,0,0,.2);">

			<h3 style="margin:0 0 14px; color:#2271b1;">
				打刻データの修正 ─ <span id="mat-alert-meta">--</span>
			</h3>

			<div id="mat-alert-badges" style="margin-bottom:14px;"></div>

			<table class="form-table" style="margin:0;">
				<tr>
					<th style="width:110px;">出勤（打刻）</th>
					<td>
						<input type="text" id="mat-alert-in" class="small-text" placeholder="HH:MM" style="width:90px;">
						<span style="margin-left:14px; color:#50575e;">始業：<strong id="mat-alert-rounded-in">−</strong></span>
					</td>
				</tr>
				<tr>
					<th>退勤（打刻）</th>
					<td>
						<input type="text" id="mat-alert-out" class="small-text" placeholder="HH:MM" style="width:90px;">
						<span style="margin-left:14px; color:#50575e;">終業：<strong id="mat-alert-rounded-out">−</strong></span>
						<p class="description" style="margin:4px 0 0;">日跨ぎは 25:10 のように24時間超で入力してください。</p>
					</td>
				</tr>
				<tr>
					<th>中抜け</th>
					<td>
						<label><input type="checkbox" id="mat-alert-break-out-enabled"> 中抜けあり（同日2回勤務）</label>
						<div id="mat-alert-break-out-fields" style="display:none; margin-top:6px;">
							<input type="text" id="mat-alert-break-out-start" class="small-text" placeholder="HH:MM" style="width:80px;">
							〜
							<input type="text" id="mat-alert-break-out-end" class="small-text" placeholder="HH:MM" style="width:80px;">
							<span style="margin-left:10px; color:#50575e;">中抜け時間：<strong id="mat-alert-break-out-minutes">−</strong></span>
						</div>
					</td>
				</tr>
				<tr>
					<th>休憩</th>
					<td>
						<input type="number" id="mat-alert-break" class="small-text" min="0" max="1440" style="width:90px;"> 分
						<span style="margin-left:14px; color:#50575e;">基準：<strong id="mat-alert-standard">−</strong>分</span>
					</td>
				</tr>
				<tr id="mat-alert-midnight-row" style="display:none;">
					<th>深夜休憩</th>
					<td>
						<input type="number" id="mat-alert-midnight-break" class="small-text" min="0" style="width:90px;"> 分
						<span style="margin-left:14px; color:#50575e;">深夜該当：<strong id="mat-alert-midnight-span">−</strong></span>
					</td>
				</tr>
				<tr id="mat-alert-calc-row" style="display:none;">
					<th>拘束時間</th>
					<td><strong id="mat-alert-calc-kousoku">0:00</strong>（自動計算）</td>
				</tr>
				<tr id="mat-alert-midnight-minutes-row" style="display:none;">
					<th>深夜時間</th>
					<td><strong id="mat-alert-midnight-minutes">0:00</strong>（自動計算）</td>
				</tr>
				<tr>
					<th>残業時間</th>
					<td><strong id="mat-alert-overtime">0:00</strong>（自動計算）</td>
				</tr>
				<tr>
					<th>備考</th>
					<td><textarea id="mat-alert-note" class="large-text" rows="2"></textarea></td>
				</tr>
			</table>

			<div id="mat-alert-sections" style="margin-top:14px;"></div>

			<p id="mat-alert-error" style="color:#d63638; display:none; margin:10px 0 0;"></p>

			<div style="margin-top:20px; display:flex; gap:8px; justify-content:flex-end;">
				<button type="button" class="button button-link-delete" id="mat-alert-delete" style="margin-right:auto;">🗑 削除する</button>
				<button type="button" class="button" id="mat-alert-cancel">キャンセル</button>
				<button type="button" class="button button-primary" id="mat-alert-save">💾 保存</button>
			</div>
		</div>
	</div>

	<script>
	jQuery(function($) {
		var alertNonce = '<?php echo esc_js( wp_create_nonce( 'mat_alert_nonce' ) ); ?>';
		var deleteNonce = '<?php echo esc_js( wp_create_nonce( 'mat_admin_nonce' ) ); ?>';
		var currentDailyId = 0;
		var currentTimeUnit = 30;
		var reviewOptions = <?php echo wp_json_encode( MAT_REVIEW_LABELS ); ?>;
		var approvalOptions = <?php echo wp_json_encode( MAT_APPROVAL_LABELS ); ?>;

		var matMidnightWindow = {
			start: <?php echo (int) mat_get_midnight_window()['start']; ?>,
			end:   <?php echo (int) mat_get_midnight_window()['end']; ?>
		};
		var matMidnightWindowLabel = '<?php
			$w = mat_get_midnight_window();
			echo esc_js( mat_minutes_to_hm( $w['start'] ) . ' 〜 ' . mat_minutes_to_hm( $w['end'] ) );
		?>';

		function matParseHM(s) {
			var m = /^(\d{1,3}):(\d{2})$/.exec($.trim(s || ''));
			if (!m) return null;
			return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
		}
		function matFormatMinutesJpPadded(min) {
			return Math.floor(min / 60) + '時間' + String(min % 60).padStart(2, '0') + '分';
		}
		function matFormatHM(min) {
			if (min === null || isNaN(min)) return '--';
			var sign = min < 0 ? '-' : '';
			min = Math.abs(min);
			return sign + String(Math.floor(min / 60)).padStart(2, '0') + ':' + String(min % 60).padStart(2, '0');
		}

		var matOvertimeThreshold = <?php echo (int) mat_get_overtime_threshold(); ?>;

		// 中抜けを除外した実勤務区間（要件定義書 §12.3）
		function matGetWorkedRanges(inMin, outMin, boStartMin, boEndMin) {
			if (boStartMin === null || boEndMin === null || boEndMin <= boStartMin || boStartMin < inMin || boEndMin > outMin) {
				return [[inMin, outMin]];
			}
			var ranges = [];
			if (boStartMin > inMin) ranges.push([inMin, boStartMin]);
			if (boEndMin < outMin)  ranges.push([boEndMin, outMin]);
			return ranges;
		}

		function matCalcMidnightSpanFromRanges(workedRanges) {
			var start = matMidnightWindow.start, end = matMidnightWindow.end;
			var midnightRanges = [];
			var prevStart = Math.max(0, start - 1440), prevEnd = Math.max(0, end - 1440);
			if (prevEnd > prevStart) midnightRanges.push([prevStart, prevEnd]);
			midnightRanges.push([start, end]);

			var span = 0;
			workedRanges.forEach(function (worked) {
				midnightRanges.forEach(function (r) {
					var overlap = Math.min(worked[1], r[1]) - Math.max(worked[0], r[0]);
					if (overlap > 0) span += overlap;
				});
			});
			return span;
		}

		// 出勤・退勤（実打刻）→ 丸め値・中抜け除外 → 拘束・深夜該当時間のライブプレビュー（サーバ側の再計算が正とする・§6.4・§12.4）
		function updatePreview() {
			var inMin = matParseHM($('#mat-alert-in').val());
			var outMin = matParseHM($('#mat-alert-out').val());
			if (inMin === null || outMin === null) {
				$('#mat-alert-midnight-row, #mat-alert-midnight-minutes-row, #mat-alert-calc-row').hide();
				$('#mat-alert-break-out-minutes').text('−');
				return;
			}

			var roundedIn  = Math.ceil(inMin / currentTimeUnit) * currentTimeUnit;
			var roundedOut = Math.floor(outMin / currentTimeUnit) * currentTimeUnit;
			if (roundedOut <= roundedIn) roundedOut += 1440;

			var boEnabled  = $('#mat-alert-break-out-enabled').is(':checked');
			var boStartMin = boEnabled ? matParseHM($('#mat-alert-break-out-start').val()) : null;
			var boEndMin   = boEnabled ? matParseHM($('#mat-alert-break-out-end').val())   : null;
			var boValid = (boEnabled && boStartMin !== null && boEndMin !== null
				&& boEndMin > boStartMin && boStartMin >= roundedIn && boEndMin <= roundedOut);
			$('#mat-alert-break-out-minutes').text(boValid ? matFormatMinutesJpPadded(boEndMin - boStartMin) : '−');

			var workedRanges    = matGetWorkedRanges(roundedIn, roundedOut, boValid ? boStartMin : null, boValid ? boEndMin : null);
			var breakOutMinutes = boValid ? (boEndMin - boStartMin) : 0;
			var breakMinutes    = parseInt($('#mat-alert-break').val(), 10) || 0;
			var kousoku         = roundedOut - roundedIn - breakOutMinutes;
			var overtime        = Math.max(0, Math.max(0, kousoku - breakMinutes) - matOvertimeThreshold);

			$('#mat-alert-calc-row').show();
			$('#mat-alert-calc-kousoku').text(matFormatHM(kousoku));
			$('#mat-alert-overtime').text(matFormatHM(overtime));

			var span = matCalcMidnightSpanFromRanges(workedRanges);
			if (!span || span <= 0) {
				$('#mat-alert-midnight-row, #mat-alert-midnight-minutes-row').hide();
				return;
			}
			$('#mat-alert-midnight-row, #mat-alert-midnight-minutes-row').show();
			$('#mat-alert-midnight-span').text(matFormatMinutesJpPadded(span) + '（' + matMidnightWindowLabel + '）');

			var breakVal = parseInt($('#mat-alert-midnight-break').val(), 10);
			var minutes = isNaN(breakVal) ? span : Math.max(0, span - breakVal);
			$('#mat-alert-midnight-minutes').text(matFormatHM(minutes));
		}
		$(document).on('input', '#mat-alert-in, #mat-alert-out, #mat-alert-break, #mat-alert-midnight-break, #mat-alert-break-out-start, #mat-alert-break-out-end', updatePreview);
		$('#mat-alert-break-out-enabled').on('change', function () {
			$('#mat-alert-break-out-fields').toggle($(this).is(':checked'));
			updatePreview();
		});

		// 保存後の挙動はページ側で差し替え可能
		if (typeof window.matAlertModalOnSaved !== 'function') {
			window.matAlertModalOnSaved = function() { location.reload(); };
		}

		function badgeHtml(a) {
			var colors = {
				yellow: ['#fff8e1', '#dba617', '#8a6100'],
				blue:   ['#f0f6fc', '#2271b1', '#0a4b78'],
				red:    ['#fcf0f1', '#d63638', '#8a1f21']
			};
			var c = a.resolved ? ['#f2f2f2', '#c3c4c7', '#8c8f94'] : (colors[a.color] || colors.yellow);
			return '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:0.82em;'
				+ 'font-weight:600;margin:0 4px 4px 0;background:' + c[0] + ';border:1px solid ' + c[1]
				+ ';color:' + c[2] + ';">' + $('<div>').text(a.label).html() + '</span>';
		}

		function selectHtml(name, options, selected) {
			var html = '<select class="' + name + '">';
			$.each(options, function(v, label) {
				v = parseInt(v, 10);
				html += '<option value="' + v + '"' + (v === selected ? ' selected' : '') + '>'
					+ (v === 0 ? '選択してください' : $('<div>').text(label).html()) + '</option>';
			});
			return html + '</select>';
		}

		// 種別ごとの申請カードを積んで表示する（1日に例外休憩・残業・深夜休憩が同時に存在し得るため）
		function sectionHtml(s) {
			var html = '<div class="mat-alert-section" data-type="' + s.type + '" '
				+ 'style="margin-top:12px; padding:12px 14px; background:#f6f7f7; border-left:4px solid #8c8f94; border-radius:0 4px 4px 0;">';
			html += '<div style="font-size:0.9em; font-weight:700; color:#1d2327; margin-bottom:8px;">' + $('<div>').text(s.label).html() + '</div>';

			if (s.reason) {
				html += '<div style="font-size:0.85em; color:#50575e; margin-bottom:8px;">'
					+ '<span style="font-weight:600;">申請理由（従業員入力）：</span>'
					+ '<span style="white-space:pre-wrap;">' + $('<div>').text(s.reason).html() + '</span></div>';
			}

			html += '<table class="form-table" style="margin:0;">';
			html += '<tr><th style="width:100px;">対応ステータス</th><td>' + selectHtml('mat-alert-review', reviewOptions, s.review_status) + '</td></tr>';
			html += '<tr><th>承認ステータス</th><td>' + selectHtml('mat-alert-approval', approvalOptions, s.approval_status) + '</td></tr>';
			html += '<tr><th>管理者コメント</th><td><textarea class="mat-alert-comment large-text" rows="2">' + $('<div>').text(s.admin_comment).html() + '</textarea></td></tr>';
			html += '</table>';
			html += '</div>';
			return html;
		}

		function fillModal(d) {
			currentDailyId = d.daily_id;
			currentTimeUnit = d.time_unit || 30;
			$('#mat-alert-meta').text('[' + d.employee_code + '] ' + d.employee_name + ' ／ ' + d.date_label);
			$('#mat-alert-in').val(d.clock_in);
			$('#mat-alert-out').val(d.clock_out);
			$('#mat-alert-break').val(d.break_minutes);
			$('#mat-alert-note').val(d.note);
			$('#mat-alert-rounded-in').text(d.rounded_in);
			$('#mat-alert-rounded-out').text(d.rounded_out);
			$('#mat-alert-standard').text(d.standard_break === null ? '−' : d.standard_break);
			$('#mat-alert-overtime').text(d.overtime_text);
			$('#mat-alert-midnight-break').val(d.midnight_break_minutes === null ? '' : d.midnight_break_minutes);

			var hasBreakOut = !!(d.break_out_start && d.break_out_end);
			$('#mat-alert-break-out-enabled').prop('checked', hasBreakOut);
			$('#mat-alert-break-out-start').val(d.break_out_start || '');
			$('#mat-alert-break-out-end').val(d.break_out_end || '');
			$('#mat-alert-break-out-fields').toggle(hasBreakOut);

			updatePreview();

			var badges = '';
			$.each(d.alerts || [], function(_, a) { badges += badgeHtml(a); });
			$('#mat-alert-badges').html(badges || '<span style="color:#bbb;">アラートはありません</span>');

			var sections = '';
			$.each(d.sections || [], function(_, s) { sections += sectionHtml(s); });
			$('#mat-alert-sections').html(sections);

			$('#mat-alert-error').hide();
			$('#mat-alert-delete').prop('disabled', false).text('🗑 削除する');
		}

		$(document).on('click', '.mat-alert-fix-btn', function() {
			var dailyId = $(this).data('daily-id');
			if (!dailyId) return;
			$.post(ajaxurl, {
				action: 'mat_admin_get_alert_row',
				daily_id: dailyId,
				nonce: alertNonce
			}, function(res) {
				if (!res.success) { alert(res.data); return; }
				fillModal(res.data);
				$('#mat-alert-modal').css('display', 'flex');
			});
		});

		function closeModal() {
			$('#mat-alert-modal').hide();
			currentDailyId = 0;
		}

		$('#mat-alert-cancel').on('click', closeModal);
		$('#mat-alert-modal').on('click', function(e) { if (e.target === this) closeModal(); });
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && $('#mat-alert-modal').is(':visible')) closeModal();
		});

		$('#mat-alert-delete').on('click', function() {
			if (!currentDailyId || !confirm('この打刻データを完全に削除しますか？\n関連する申請・対応履歴も削除されます。')) return;

			var deletingId = currentDailyId;
			var $btn = $(this).prop('disabled', true).text('削除中...');
			$.post(ajaxurl, {
				action: 'mat_admin_delete_log',
				id: deletingId,
				nonce: deleteNonce
			}, function(res) {
				if (!res.success) {
					$btn.prop('disabled', false).text('🗑 削除する');
					$('#mat-alert-error').text(res.data).show();
					return;
				}
				closeModal();
				window.matAlertModalOnSaved({ deleted: true, daily_id: deletingId });
			}).fail(function() {
				$btn.prop('disabled', false).text('🗑 削除する');
				$('#mat-alert-error').text('通信エラーが発生しました。').show();
			});
		});

		$('#mat-alert-save').on('click', function() {
			if (!currentDailyId) return;
			var $btn = $(this).prop('disabled', true).text('保存中...');

			var statuses = {};
			$('#mat-alert-sections .mat-alert-section').each(function() {
				var type = $(this).data('type');
				statuses[type] = {
					review_status:   $(this).find('.mat-alert-review').val(),
					approval_status: $(this).find('.mat-alert-approval').val(),
					admin_comment:   $(this).find('.mat-alert-comment').val()
				};
			});

			$.post(ajaxurl, {
				action:                  'mat_admin_save_alert_fix',
				daily_id:                currentDailyId,
				clock_in:                $('#mat-alert-in').val(),
				clock_out:               $('#mat-alert-out').val(),
				break_minutes:           $('#mat-alert-break').val(),
				midnight_break_minutes:  $('#mat-alert-midnight-row').is(':visible') ? $('#mat-alert-midnight-break').val() : '',
				break_out_enabled:       $('#mat-alert-break-out-enabled').is(':checked') ? '1' : '0',
				break_out_start:         $('#mat-alert-break-out-start').val(),
				break_out_end:           $('#mat-alert-break-out-end').val(),
				note:                    $('#mat-alert-note').val(),
				statuses:                statuses,
				nonce:                   alertNonce
			}, function(res) {
				$btn.prop('disabled', false).text('💾 保存');
				if (!res.success) { $('#mat-alert-error').text(res.data).show(); return; }
				closeModal();
				window.matAlertModalOnSaved(res.data);
			}).fail(function() {
				$btn.prop('disabled', false).text('💾 保存');
				$('#mat-alert-error').text('通信エラーが発生しました。').show();
			});
		});
	});
	</script>
	<?php
}
