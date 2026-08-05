<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * admin-alert-modal.php  v3.2.0
 *
 * 「従業員打刻履歴」と「アラート一覧」で共用する打刻修正モーダル（要件定義書 §6.3 / §7）。
 *
 * 使い方：
 *   mat_render_alert_modal();  // ページ末尾で1回だけ呼ぶ（HTML + JS を出力）
 *   <button class="mat-alert-fix-btn" data-daily-id="123">修正</button>
 *
 * 保存後の挙動はページ側で window.matAlertModalOnSaved を差し替えて変更できる。
 */

// =========================================================
//  Ajax：モーダル表示用データの取得
// =========================================================

add_action( 'wp_ajax_mat_admin_get_alert_row', 'mat_admin_get_alert_row_handler' );
function mat_admin_get_alert_row_handler() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '権限がありません。' );
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
 * モーダルに渡すデータを組み立てる。
 */
function mat_build_alert_modal_payload( $row ) {
	$requests = mat_get_work_requests_by_daily( (int) $row->id );
	$data     = mat_decorate_daily_row( $row, $requests );

	$emp  = function_exists( 'emp_get_employee_by_code' ) ? emp_get_employee_by_code( $row->employee_code ) : null;
	$dow  = array( '日', '月', '火', '水', '木', '金', '土' );
	$ts   = strtotime( $row->work_date );

	// 申請理由（従業員入力）は残業→例外休憩の順で拾う
	$reason = '';
	foreach ( array( 'overtime', 'break_exception' ) as $type ) {
		if ( ! empty( $requests[ $type ] ) && trim( (string) $requests[ $type ]->reason ) !== '' ) {
			$label   = $type === 'overtime' ? '残業' : '例外休憩';
			$reason .= ( $reason === '' ? '' : "\n" ) . '【' . $label . '】' . $requests[ $type ]->reason;
		}
	}

	// ステータス・コメントは既存申請から引き継ぐ（複数ある場合は残業を優先）
	$primary = $requests['overtime'] ?? ( $requests['break_exception'] ?? null );

	return array(
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
		'note'            => (string) $row->note,
		'alerts'          => $data['alerts'],
		'reason'          => $reason,
		'review_status'   => $primary ? (int) $primary->review_status : 0,
		'approval_status' => $primary ? (int) $primary->approval_status : 0,
		'admin_comment'   => $primary ? (string) $primary->admin_comment : '',
	);
}

// =========================================================
//  Ajax：保存
// =========================================================

add_action( 'wp_ajax_mat_admin_save_alert_fix', 'mat_admin_save_alert_fix_handler' );
function mat_admin_save_alert_fix_handler() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '権限がありません。' );
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

	$wpdb->update( MAT_DAILY_TABLE, array(
		'clock_in'      => mat_minutes_to_time_sql( $in_min ),
		'clock_out'     => mat_minutes_to_time_sql( $out_min ),
		'break_minutes' => $break_in === '' ? null : (int) $break_in,
		'note'          => $note !== '' ? $note : null,
	), array( 'id' => $daily_id ) );

	// 出勤・退勤・休憩を修正したので始業／終業／残業を再計算する（§6.4）
	$row = mat_recalc_daily_row( $daily_id );

	// ---- ステータスの保存 ----
	$review   = intval( $_POST['review_status']   ?? 0 );
	$approval = intval( $_POST['approval_status'] ?? 0 );
	$comment  = sanitize_textarea_field( $_POST['admin_comment'] ?? '' );

	if ( ! in_array( $review, array( 0, 1, 2, 3 ), true ) )   $review   = 0;
	if ( ! in_array( $approval, array( 0, 1, 2, 3 ), true ) ) $approval = 0;

	$requests = mat_get_work_requests_by_daily( $daily_id );
	$alerts   = mat_build_row_alerts( $row, $requests );

	// 保存前または現在のアラートに対応する申請種別を決定する。
	// 時刻・休憩の修正でアラートが解消した場合も、選択されたステータスを保存する。
	$types = array();
	foreach ( array_merge( $original_alerts, $alerts ) as $a ) {
		if ( $a['code'] === 'BREAK_IRREGULAR' ) $types[] = 'break_exception';
		if ( $a['code'] === 'OVERTIME_REQUESTED' || $a['code'] === 'OVERTIME_NO_REQUEST' ) $types[] = 'overtime';
	}
	// アラートが解消していても、既存申請があればステータスは反映する
	$types = array_unique( array_merge( $types, array_keys( $requests ) ) );

	foreach ( $types as $type ) {
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
 */
function mat_render_status_badges( array $requests ) {
	$primary = $requests['overtime'] ?? ( $requests['break_exception'] ?? null );
	if ( ! $primary ) return '';

	$html   = '<div style="display:flex; flex-wrap:wrap; gap:4px;">';
	$review = (int) $primary->review_status;
	$appr   = (int) $primary->approval_status;

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
		<div style="background:#fff; border-radius:8px; padding:26px; width:540px; max-width:92%;
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
					<th>休憩</th>
					<td>
						<input type="number" id="mat-alert-break" class="small-text" min="0" max="1440" style="width:90px;"> 分
						<span style="margin-left:14px; color:#50575e;">基準：<strong id="mat-alert-standard">−</strong>分</span>
					</td>
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

			<div id="mat-alert-reason-box" style="display:none; margin:14px 0; padding:10px 14px;
				background:#f6f7f7; border-left:4px solid #8c8f94; border-radius:0 4px 4px 0;">
				<div style="font-size:0.85em; font-weight:600; color:#50575e; margin-bottom:4px;">申請理由（従業員入力）</div>
				<div id="mat-alert-reason" style="white-space:pre-wrap; font-size:0.92em;"></div>
			</div>

			<table class="form-table" style="margin:0;">
				<tr>
					<th style="width:110px;">対応ステータス</th>
					<td>
						<select id="mat-alert-review">
							<?php foreach ( MAT_REVIEW_LABELS as $v => $label ) : ?>
								<option value="<?php echo esc_attr( $v ); ?>">
									<?php echo $v === 0 ? '選択してください' : esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th>承認ステータス</th>
					<td>
						<select id="mat-alert-approval">
							<?php foreach ( MAT_APPROVAL_LABELS as $v => $label ) : ?>
								<option value="<?php echo esc_attr( $v ); ?>">
									<?php echo $v === 0 ? '選択してください' : esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description" style="margin:4px 0 0;">
							どちらか一方でも選択すればアラートは「対応済み」になります。
						</p>
					</td>
				</tr>
				<tr>
					<th>管理者コメント</th>
					<td><textarea id="mat-alert-comment" class="large-text" rows="2"></textarea></td>
				</tr>
			</table>

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

		function fillModal(d) {
			currentDailyId = d.daily_id;
			$('#mat-alert-meta').text('[' + d.employee_code + '] ' + d.employee_name + ' ／ ' + d.date_label);
			$('#mat-alert-in').val(d.clock_in);
			$('#mat-alert-out').val(d.clock_out);
			$('#mat-alert-break').val(d.break_minutes);
			$('#mat-alert-note').val(d.note);
			$('#mat-alert-rounded-in').text(d.rounded_in);
			$('#mat-alert-rounded-out').text(d.rounded_out);
			$('#mat-alert-standard').text(d.standard_break === null ? '−' : d.standard_break);
			$('#mat-alert-overtime').text(d.overtime_text);
			$('#mat-alert-review').val(d.review_status);
			$('#mat-alert-approval').val(d.approval_status);
			$('#mat-alert-comment').val(d.admin_comment);

			var badges = '';
			$.each(d.alerts || [], function(_, a) { badges += badgeHtml(a); });
			$('#mat-alert-badges').html(badges || '<span style="color:#bbb;">アラートはありません</span>');

			if (d.reason) {
				$('#mat-alert-reason').text(d.reason);
				$('#mat-alert-reason-box').show();
			} else {
				$('#mat-alert-reason-box').hide();
			}
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

			$.post(ajaxurl, {
				action:          'mat_admin_save_alert_fix',
				daily_id:        currentDailyId,
				clock_in:        $('#mat-alert-in').val(),
				clock_out:       $('#mat-alert-out').val(),
				break_minutes:   $('#mat-alert-break').val(),
				note:            $('#mat-alert-note').val(),
				review_status:   $('#mat-alert-review').val(),
				approval_status: $('#mat-alert-approval').val(),
				admin_comment:   $('#mat-alert-comment').val(),
				nonce:           alertNonce
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
