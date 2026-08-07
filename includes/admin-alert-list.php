<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * admin-alert-list.php  v3.2.0
 *
 * 打刻ツール > アラート一覧（要件定義書 §7）。
 *
 * アラートは保存せず表示時に動的計算するため、期間内の日次行を取得してから
 * PHP 側でアラート判定・絞り込みを行う。
 * 修正モーダルは admin-alert-modal.php を共用し、保存後は Ajax で一覧を部分更新する。
 */

// =========================================================
//  メニュー登録
// =========================================================

add_action( 'admin_menu', 'mat_register_alert_list_menu', 21 );
function mat_register_alert_list_menu() {
	add_submenu_page(
		'my-attendance-settings',
		'アラート一覧',
		'⚠ アラート一覧',
		'manage_options',
		'mat-alert-list',
		'mat_alert_list_page_render'
	);
}

// admin-settings.php の mat_admin_enqueue が対象ページを配列で持っているため追加する
add_filter( 'mat_admin_pages', function ( $pages ) {
	$pages[] = 'mat-alert-list';
	return $pages;
} );

// =========================================================
//  データ取得
// =========================================================

/**
 * 絞り込み条件に一致するアラート行を取得する。
 *
 * @param array $filters year_month / affiliation_id / job_types / status
 * @return array<int,array>
 */
function mat_get_alert_rows( array $filters ) {
	global $wpdb;

	$year_month = $filters['year_month'];
	if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $year_month ) ) return array();

	$start = $year_month . '-01';
	$end   = date( 'Y-m-t', strtotime( $start ) );

	// --- 対象従業員の決定（所属・職種で絞り込み） ---
	$emp_args = array();
	if ( ! empty( $filters['affiliation_id'] ) ) {
		$emp_args['affiliation_id'] = (int) $filters['affiliation_id'];
	}
	$employees = (array) emp_get_active_employees( $emp_args );

	$emp_by_code = array();
	foreach ( $employees as $emp ) {
		// 職種未設定の社員は職種フィルターの対象外（常に含める）
		$job_type = isset( $emp->job_type_name ) ? $emp->job_type_name : '';
		if ( $job_type !== '' && ! empty( $filters['job_types'] )
			&& ! in_array( $job_type, $filters['job_types'], true ) ) {
			continue;
		}
		$emp_by_code[ (string) $emp->employee_code ] = $emp;
	}
	if ( empty( $emp_by_code ) ) return array();

	// --- 期間内の日次行 ---
	$codes        = array_keys( $emp_by_code );
	$placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
	$params       = array_merge( $codes, array( $start, $end ) );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM " . MAT_DAILY_TABLE . "
		 WHERE employee_code IN ({$placeholders})
		   AND work_date BETWEEN %s AND %s
		   AND is_holiday = 0
		   AND ( clock_in IS NOT NULL OR clock_out IS NOT NULL )
		 ORDER BY work_date ASC, CAST(employee_code AS UNSIGNED) ASC",
		$params
	) );
	if ( empty( $rows ) ) return array();

	$req_map = mat_get_work_requests_by_daily_ids( wp_list_pluck( $rows, 'id' ) );

	$out = array();
	foreach ( $rows as $r ) {
		$requests = $req_map[ (int) $r->id ] ?? array();
		$alerts   = mat_build_row_alerts( $r, $requests );
		if ( empty( $alerts ) ) continue;

		// アラート種別フィルタ（要件定義書 §7.5）：選択された種別に該当するアラートだけに絞り込む
		if ( ! empty( $filters['alert_types'] ) ) {
			$alerts = array_values( array_filter( $alerts, function ( $a ) use ( $filters ) {
				$type = mat_alert_code_to_request_type( $a['code'] );
				return $type && in_array( $type, $filters['alert_types'], true );
			} ) );
			if ( empty( $alerts ) ) continue;
		}

		// 「未対応のみ」＝対応ステータス・承認ステータスの両方が未選択のアラート行
		if ( ( $filters['status'] ?? 'unresolved' ) === 'unresolved' && ! mat_alerts_has_unresolved( $alerts ) ) {
			continue;
		}

		$emp  = $emp_by_code[ (string) $r->employee_code ] ?? null;
		$data = mat_decorate_daily_row( $r, $requests );

		$out[] = array(
			'daily_id'      => (int) $r->id,
			'employee_code' => $r->employee_code,
			'employee_name' => $emp ? $emp->name : '',
			'work_date'     => $r->work_date,
			'data'          => $data,
			'alerts'        => $alerts,
			'requests'      => $requests,
		);
	}
	return $out;
}

// =========================================================
//  ページ描画
// =========================================================

function mat_alert_list_page_render() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$filters = mat_alert_list_read_filters();

	$affiliations = function_exists( 'emp_get_affiliations' ) ? (array) emp_get_affiliations() : array();
	$job_types    = function_exists( 'emp_get_job_types' )    ? (array) emp_get_job_types()    : array();
	?>
	<div class="wrap">
		<h1>⚠ アラート一覧</h1>
		<p style="color:#666; max-width:820px;">
			基準外の休憩・残業が発生した打刻を一覧します。アラートは保存されず、休憩マスタと設定に基づいて表示のたびに判定されます。
		</p>

		<div class="card" style="max-width:100%; margin-top:16px; padding:15px;">
			<form method="get" id="mat-alert-filter-form">
				<input type="hidden" name="page" value="mat-alert-list">

				<div style="display:flex; flex-wrap:wrap; align-items:center; gap:18px;">
					<label>
						期間（必須）：
						<input type="month" name="year_month" required
							value="<?php echo esc_attr( $filters['year_month'] ); ?>">
					</label>

					<?php if ( ! empty( $affiliations ) ) : ?>
					<label>
						所属：
						<select name="affiliation_id">
							<option value="">すべて</option>
							<?php foreach ( $affiliations as $a ) : ?>
								<option value="<?php echo esc_attr( $a->id ); ?>"
									<?php selected( $filters['affiliation_id'], (int) $a->id ); ?>>
									<?php echo esc_html( $a->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<?php endif; ?>

					<label>
						ステータス：
						<label style="margin-left:6px;">
							<input type="radio" name="status" value="unresolved"
								<?php checked( $filters['status'], 'unresolved' ); ?>> 未対応のみ
						</label>
						<label style="margin-left:10px;">
							<input type="radio" name="status" value="all"
								<?php checked( $filters['status'], 'all' ); ?>> すべて
						</label>
					</label>

					<label>
						アラート種別：
						<?php
						$alert_type_options = array(
							'break_exception' => '例外休憩',
							'overtime'         => '残業',
							'midnight_break'   => '深夜休憩',
						);
						foreach ( $alert_type_options as $val => $label ) :
							$on = empty( $filters['alert_types'] ) || in_array( $val, $filters['alert_types'], true );
						?>
							<label style="margin-left:8px;">
								<input type="checkbox" name="alert_types[]" value="<?php echo esc_attr( $val ); ?>"
									<?php checked( $on ); ?>> <?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
						<span style="font-size:0.78em; color:#888; margin-left:6px;">※全てOFFの場合は全種別が対象になります</span>
					</label>

					<input type="submit" class="button button-primary" value="表示">
				</div>

				<?php if ( ! empty( $job_types ) ) : ?>
				<div style="margin-top:12px; display:flex; align-items:center; flex-wrap:wrap; gap:6px;">
					<span style="font-size:0.85em; font-weight:600; color:#555;">職種フィルター：</span>
					<?php foreach ( $job_types as $jt ) :
						$on = empty( $filters['job_types'] ) || in_array( $jt->name, $filters['job_types'], true );
					?>
						<label class="mat-alert-chip" style="display:inline-flex; align-items:center; gap:5px;
							padding:4px 12px; border-radius:20px; border:1.5px solid #2271b1; cursor:pointer;
							font-size:0.82em; font-weight:600; line-height:1.5;
							background:<?php echo $on ? '#2271b1' : '#fff'; ?>;
							color:<?php echo $on ? '#fff' : '#2271b1'; ?>;">
							<input type="checkbox" name="job_types[]" value="<?php echo esc_attr( $jt->name ); ?>"
								<?php checked( $on ); ?> style="margin:0;">
							<?php echo esc_html( $jt->name ); ?>
						</label>
					<?php endforeach; ?>
					<span style="font-size:0.78em; color:#888;">※ 全てOFFの場合は全職種が対象になります</span>
				</div>
				<?php endif; ?>
			</form>
		</div>

		<div id="mat-alert-list-wrap" style="margin-top:16px;">
			<?php mat_alert_list_render_table( mat_get_alert_rows( $filters ) ); ?>
		</div>
	</div>

	<?php mat_render_alert_modal(); ?>

	<script>
	jQuery(function($) {
		// 保存後は一覧を Ajax で部分更新する（「未対応のみ」表示中は該当行が消える）
		window.matAlertModalOnSaved = function() {
			var $wrap = $('#mat-alert-list-wrap').css('opacity', '.4');
			$.post(ajaxurl, {
				action: 'mat_refresh_alert_list',
				nonce:  '<?php echo esc_js( wp_create_nonce( 'mat_alert_list_nonce' ) ); ?>',
				filters: $('#mat-alert-filter-form').serialize()
			}, function(res) {
				$wrap.css('opacity', '1');
				if (res.success) { $wrap.html(res.data.html); } else { location.reload(); }
			}).fail(function() { location.reload(); });
		};

		// 職種チップの見た目をチェック状態に追従させる
		$(document).on('change', '.mat-alert-chip input', function() {
			var on = $(this).is(':checked');
			$(this).closest('.mat-alert-chip').css({
				background: on ? '#2271b1' : '#fff',
				color:      on ? '#fff'    : '#2271b1'
			});
		});
	});
	</script>
	<?php
}

/**
 * GET パラメータから絞り込み条件を読み取る。
 */
function mat_alert_list_read_filters( $source = null ) {
	$src = is_array( $source ) ? $source : $_GET;

	$year_month = isset( $src['year_month'] ) ? sanitize_text_field( $src['year_month'] ) : '';
	if ( ! preg_match( '/^\d{4}-\d{2}$/', $year_month ) ) {
		$year_month = current_time( 'Y-m' );
	}

	$status = ( ( $src['status'] ?? 'unresolved' ) === 'all' ) ? 'all' : 'unresolved';

	// アラート種別フィルタ（要件定義書 §7.5）：全てOFFの場合は job_types と同様、全種別を対象にする
	$valid_alert_types = array( 'break_exception', 'overtime', 'midnight_break' );
	$alert_types = isset( $src['alert_types'] )
		? array_values( array_intersect( (array) $src['alert_types'], $valid_alert_types ) )
		: array();
	if ( count( $alert_types ) === count( $valid_alert_types ) ) $alert_types = array(); // 全選択＝絞り込みなし

	return array(
		'year_month'     => $year_month,
		'affiliation_id' => isset( $src['affiliation_id'] ) ? (int) $src['affiliation_id'] : 0,
		'job_types'      => isset( $src['job_types'] ) ? array_map( 'sanitize_text_field', (array) $src['job_types'] ) : array(),
		'status'         => $status,
		'alert_types'    => $alert_types,
	);
}

/**
 * 一覧テーブルを描画する（Ajax 部分更新でも同じ関数を使う）。
 */
function mat_alert_list_render_table( array $rows ) {
	$dow = array( '日', '月', '火', '水', '木', '金', '土' );
	?>
	<div style="max-width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch;">
	<table class="widefat striped" style="min-width:1180px; table-layout:auto;">
		<thead>
			<tr>
				<th style="width:80px;">社員CD</th>
				<th style="width:110px;">氏名</th>
				<th style="width:110px;">日付</th>
				<th style="width:75px;">出勤<br><span style="font-weight:400;font-size:.85em;">(打刻)</span></th>
				<th style="width:75px;">退勤<br><span style="font-weight:400;font-size:.85em;">(打刻)</span></th>
				<th style="width:60px;">休憩</th>
				<th style="width:65px;">始業</th>
				<th style="width:65px;">終業</th>
				<th style="width:80px;">残業時間</th>
				<th style="width:80px;">深夜時間</th>
				<th>アラート内容</th>
				<th style="width:70px;">修正</th>
				<th style="width:110px;">修正後ステータス</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="13" style="text-align:center; padding:24px; color:#666;">
					該当するアラートはありません。
				</td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) :
					$d  = $row['data'];
					$ts = strtotime( $row['work_date'] );
				?>
					<tr>
						<td><?php echo esc_html( $row['employee_code'] ); ?></td>
						<td><?php echo esc_html( $row['employee_name'] ); ?></td>
						<td><?php echo esc_html( date( 'm/d', $ts ) . '(' . $dow[ date( 'w', $ts ) ] . ')' ); ?></td>
						<td><?php echo esc_html( $d['clock_in'] ?: '-' ); ?></td>
						<td>
							<?php echo esc_html( $d['clock_out'] ?: '-' ); ?>
							<?php if ( ! empty( $d['is_overnight'] ) ) echo ' <span title="日跨ぎ">⏰</span>'; ?>
							<?php if ( ! empty( $d['break_out_start'] ) && ! empty( $d['break_out_end'] ) ) echo ' <span title="中抜けあり">✂</span>'; ?>
						</td>
						<td><?php echo $d['break_minutes'] === null ? '-' : esc_html( $d['break_minutes'] ) . '分'; ?></td>
						<td><?php echo esc_html( $d['rounded_clock_in'] ?: '−' ); ?></td>
						<td><?php echo esc_html( $d['rounded_clock_out'] ?: '−' ); ?></td>
						<td><?php echo $d['overtime_minutes'] ? esc_html( mat_minutes_to_hm( $d['overtime_minutes'] ) ) : ''; ?></td>
						<td><?php echo $d['midnight_minutes'] ? esc_html( mat_minutes_to_hm( $d['midnight_minutes'] ) ) : '-'; ?></td>
						<td><?php echo mat_render_alert_badges( $row['alerts'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td>
							<button class="button button-small mat-alert-fix-btn"
								data-daily-id="<?php echo esc_attr( $row['daily_id'] ); ?>">修正</button>
						</td>
						<td><?php echo mat_render_status_badges( $row['requests'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</div>
	<p style="margin-top:8px; color:#666; font-size:0.9em;">
		該当件数：<strong><?php echo count( $rows ); ?></strong> 件
	</p>
	<?php
}

// =========================================================
//  Ajax：一覧の部分更新
// =========================================================

add_action( 'wp_ajax_mat_refresh_alert_list', 'mat_refresh_alert_list_handler' );
function mat_refresh_alert_list_handler() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '権限がありません。' );
	check_ajax_referer( 'mat_alert_list_nonce', 'nonce' );

	// フォームのクエリ文字列をそのまま受け取り、同じ条件で再描画する
	parse_str( (string) wp_unslash( $_POST['filters'] ?? '' ), $parsed );
	$filters = mat_alert_list_read_filters( $parsed );

	ob_start();
	mat_alert_list_render_table( mat_get_alert_rows( $filters ) );
	wp_send_json_success( array( 'html' => ob_get_clean() ) );
}
