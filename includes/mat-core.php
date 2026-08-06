<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * mat-core.php  v3.2.0
 *
 * 丸め込み（始業／終業）・休憩マスタ・例外休憩／残業アラートの共通ロジック。
 * attendance-manager など他プラグインから参照される公開APIもここに集約する。
 *
 * 用語（要件定義書 §2）
 *  - 実打刻   : clock_in / clock_out（従業員がボタンを押した実時刻）
 *  - 始業     : rounded_clock_in（実打刻の出勤を単位時間で繰り上げ）
 *  - 終業     : rounded_clock_out（実打刻の退勤を単位時間で切り捨て）
 *  - 拘束時間 : 終業 − 始業（分）
 *  - 労働時間 : 拘束時間 − 休憩時間（分）
 *  - 残業時間 : 労働時間 − 480分（> 0 のときのみ計上）
 *  - 基準休憩 : 拘束時間から休憩マスタで自動判定される休憩時間
 */

// =========================================================
//  1. 設定値
// =========================================================

/**
 * 勤務時間入力単位（分）。15 / 30 / 60 のいずれか。
 */
function mat_get_time_unit() {
	$unit = (int) get_option( 'mat_time_unit', 30 );
	return in_array( $unit, array( 15, 30, 60 ), true ) ? $unit : 30;
}

/**
 * 残業判定の基準労働時間（分）。
 */
function mat_get_overtime_threshold() {
	$threshold = (int) get_option( 'mat_overtime_threshold', 480 );
	return $threshold > 0 ? $threshold : 480;
}

/**
 * 例外休憩アラートの基準の決め方。
 *  - 'auto'  : その日の拘束時間から自動判定した区分の break_minutes と比較（既定・推奨）
 *  - 'fixed' : 常に「既定」行（is_default = 1）の break_minutes と比較
 *
 * 要件定義書 §11 未1 の推奨に従い、既定は 'auto'。
 */
function mat_get_break_alert_mode() {
	return get_option( 'mat_break_alert_mode', 'auto' ) === 'fixed' ? 'fixed' : 'auto';
}

// =========================================================
//  2. 時刻ヘルパー（24時間超対応）
// =========================================================

/**
 * "HH:MM" / "HH:MM:SS" を分数に変換する。24時間超（"25:10"）も許容。
 *
 * @return int|null 変換できない場合は null
 */
function mat_parse_time_to_minutes( $time ) {
	if ( $time === null || $time === '' ) return null;
	if ( ! preg_match( '/^(\d{1,3}):(\d{2})(?::(\d{2}))?$/', trim( (string) $time ), $m ) ) return null;
	return (int) $m[1] * 60 + (int) $m[2];
}

/**
 * 分数を MySQL TIME 形式（"HH:MM:SS"）に変換する。25:00:00 等もそのまま生成。
 */
function mat_minutes_to_time_sql( $minutes ) {
	if ( $minutes === null || $minutes === '' ) return null;
	$minutes = (int) $minutes;
	if ( $minutes < 0 ) return null;
	return sprintf( '%02d:%02d:00', intdiv( $minutes, 60 ), $minutes % 60 );
}

/**
 * 分数を表示用 "HH:MM" に変換する（24時間超はそのまま "25:10"）。
 */
function mat_minutes_to_hm( $minutes ) {
	if ( $minutes === null || $minutes === '' ) return '';
	$minutes = (int) $minutes;
	$sign    = $minutes < 0 ? '-' : '';
	$minutes = abs( $minutes );
	return $sign . sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
}

/**
 * TIME カラムの値を表示用 "HH:MM" に変換する（24時間超対応）。
 */
function mat_format_time_display( $time ) {
	$minutes = mat_parse_time_to_minutes( $time );
	return $minutes === null ? '' : mat_minutes_to_hm( $minutes );
}

/**
 * 分数を「1時間30分」形式に変換する。
 */
function mat_format_minutes_jp( $minutes ) {
	if ( $minutes === null || $minutes === '' ) return '';
	$minutes = (int) $minutes;
	$sign    = $minutes < 0 ? '-' : '';
	$minutes = abs( $minutes );
	$h       = intdiv( $minutes, 60 );
	$m       = $minutes % 60;
	if ( $h === 0 ) return $sign . $m . '分';
	if ( $m === 0 ) return $sign . $h . '時間';
	return $sign . $h . '時間' . $m . '分';
}

// =========================================================
//  3. 丸め込み（要件定義書 §5.1）
// =========================================================

/**
 * 出勤（始業）の丸め込み：単位時間で繰り上げ。
 */
function mat_round_in_minutes( $minutes, $unit = null ) {
	if ( $minutes === null ) return null;
	$unit = $unit ? (int) $unit : mat_get_time_unit();
	if ( $unit <= 0 ) $unit = 30;
	return (int) ( ceil( (int) $minutes / $unit ) * $unit );
}

/**
 * 退勤（終業）の丸め込み：単位時間で切り捨て。
 */
function mat_round_out_minutes( $minutes, $unit = null ) {
	if ( $minutes === null ) return null;
	$unit = $unit ? (int) $unit : mat_get_time_unit();
	if ( $unit <= 0 ) $unit = 30;
	return (int) ( floor( (int) $minutes / $unit ) * $unit );
}

/**
 * TIME 文字列 → 丸め込み済み TIME 文字列（始業）。
 */
function mat_round_clock_in( $time, $unit = null ) {
	$minutes = mat_parse_time_to_minutes( $time );
	if ( $minutes === null ) return null;
	return mat_minutes_to_time_sql( mat_round_in_minutes( $minutes, $unit ) );
}

/**
 * TIME 文字列 → 丸め込み済み TIME 文字列（終業）。
 */
function mat_round_clock_out( $time, $unit = null ) {
	$minutes = mat_parse_time_to_minutes( $time );
	if ( $minutes === null ) return null;
	return mat_minutes_to_time_sql( mat_round_out_minutes( $minutes, $unit ) );
}

// =========================================================
//  4. 休憩マスタ
// =========================================================

/**
 * 休憩マスタ一覧を取得する（公開API）。
 *
 * @param bool $include_inactive true で論理削除行も含める
 * @return array<int,object>
 */
function mat_get_break_master( $include_inactive = false ) {
	global $wpdb;
	$where = $include_inactive ? '' : 'WHERE is_active = 1';
	return (array) $wpdb->get_results(
		"SELECT * FROM " . MAT_BREAK_MASTER_TABLE . " {$where} ORDER BY sort_order ASC, id ASC"
	);
}

/**
 * 休憩マスタを ID で取得する。
 */
function mat_get_break_master_by_id( $id ) {
	global $wpdb;
	$id = (int) $id;
	if ( $id <= 0 ) return null;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . MAT_BREAK_MASTER_TABLE . " WHERE id = %d", $id
	) );
}

/**
 * 既定行（is_default = 1）を取得する。存在しなければ先頭行。
 */
function mat_get_default_break_master() {
	global $wpdb;
	$row = $wpdb->get_row(
		"SELECT * FROM " . MAT_BREAK_MASTER_TABLE . " WHERE is_active = 1 AND is_default = 1 ORDER BY sort_order ASC LIMIT 1"
	);
	if ( $row ) return $row;
	return $wpdb->get_row(
		"SELECT * FROM " . MAT_BREAK_MASTER_TABLE . " WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 1"
	);
}

/**
 * 拘束時間（分）から自動判定される休憩マスタ行を返す。
 *
 * @param int|null $kousoku_minutes
 * @return object|null
 */
function mat_get_auto_break_master( $kousoku_minutes ) {
	if ( $kousoku_minutes === null ) return null;
	$kousoku_minutes = (int) $kousoku_minutes;

	foreach ( mat_get_break_master() as $row ) {
		if ( ! (int) $row->is_auto ) continue;
		$min = $row->min_minutes === null ? null : (int) $row->min_minutes;
		$max = $row->max_minutes === null ? null : (int) $row->max_minutes;
		if ( $min !== null && $kousoku_minutes < $min ) continue;
		if ( $max !== null && $kousoku_minutes >= $max ) continue;
		return $row;
	}
	return null;
}

/**
 * その日の「基準休憩（分）」を返す。
 *
 * mat_break_alert_mode = 'auto'  → 拘束時間から自動判定した区分の break_minutes
 *                        'fixed' → 既定行の break_minutes
 * 判定できない場合は既定行にフォールバックする。
 *
 * @return int|null 休憩マスタが1件もない場合は null
 */
function mat_get_standard_break_minutes( $kousoku_minutes = null ) {
	if ( mat_get_break_alert_mode() === 'auto' && $kousoku_minutes !== null ) {
		$row = mat_get_auto_break_master( $kousoku_minutes );
		if ( $row ) return (int) $row->break_minutes;
	}
	$default = mat_get_default_break_master();
	return $default ? (int) $default->break_minutes : null;
}

/**
 * 休憩マスタ行の入力値を検証する（要件定義書 §4.2）。
 *
 * @param array $rows array( array('label'=>, 'min_minutes'=>, 'max_minutes'=>, 'break_minutes'=>, 'is_auto'=>, 'is_default'=>, 'sort_order'=>, 'is_active'=>), ... )
 * @return array<int,string> エラーメッセージ配列（空なら OK）
 */
function mat_validate_break_master_rows( $rows ) {
	$errors     = array();
	$active     = array();
	$auto_rows  = array();
	$default_ct = 0;

	foreach ( $rows as $i => $r ) {
		if ( empty( $r['is_active'] ) ) continue;
		$active[] = $r;

		$no    = $i + 1;
		$label = trim( (string) ( $r['label'] ?? '' ) );
		$bm    = $r['break_minutes'];

		if ( $label === '' ) {
			$errors[] = "{$no}行目：ラベルを入力してください。";
		}
		if ( ! is_numeric( $bm ) || (int) $bm < 0 || (int) $bm > 480 ) {
			$errors[] = "{$no}行目：休憩時間は 0〜480 の整数で入力してください。";
		}
		if ( ! empty( $r['is_default'] ) ) {
			$default_ct++;
		}
		if ( ! empty( $r['is_auto'] ) ) {
			$min = $r['min_minutes'] === null || $r['min_minutes'] === '' ? null : (int) $r['min_minutes'];
			$max = $r['max_minutes'] === null || $r['max_minutes'] === '' ? null : (int) $r['max_minutes'];
			if ( $min !== null && $max !== null && $min >= $max ) {
				$errors[] = "{$no}行目：拘束下限は拘束上限より小さい値を入力してください。";
			}
			$auto_rows[] = array( 'no' => $no, 'min' => $min, 'max' => $max );
		}
	}

	if ( empty( $active ) ) {
		$errors[] = '有効な休憩マスタが1件もありません。最低1件は登録してください。';
		return $errors;
	}
	if ( empty( $auto_rows ) ) {
		$errors[] = '自動判定ONの行が必要です。最低1件は「自動判定」を有効にしてください。';
	}
	if ( $default_ct !== 1 ) {
		$errors[] = '「既定」はちょうど1件だけ選択してください（現在 ' . $default_ct . ' 件）。';
	}

	// 自動判定行が 0分〜∞ を隙間なく被りなくカバーしているか
	if ( ! empty( $auto_rows ) && empty( array_filter( $errors, function ( $e ) { return strpos( $e, '拘束下限' ) !== false; } ) ) ) {
		usort( $auto_rows, function ( $a, $b ) {
			$am = $a['min'] === null ? 0 : $a['min'];
			$bm = $b['min'] === null ? 0 : $b['min'];
			return $am <=> $bm;
		} );

		$cursor = 0;
		foreach ( $auto_rows as $row ) {
			$min = $row['min'] === null ? 0 : $row['min'];
			$max = $row['max'];

			if ( $cursor === null ) {
				$errors[] = '自動判定の閾値が重複しています（上限なしの行より後ろに行があります）。';
				break;
			}
			if ( $min > $cursor ) {
				$errors[] = "拘束時間 {$cursor}〜{$min}分 が判定できません。閾値の隙間を埋めてください。";
			} elseif ( $min < $cursor ) {
				$errors[] = "拘束時間 {$min}〜{$cursor}分 が重複して判定されます。閾値を見直してください。";
			}
			$cursor = $max; // null なら上限なし
		}
		if ( $cursor !== null ) {
			$errors[] = "拘束時間 {$cursor}分以上 が判定できません。上限なしの行を1件作成してください。";
		}
	}

	return $errors;
}

// =========================================================
//  5. 労働時間・残業計算
// =========================================================

/**
 * 始業・終業・休憩から拘束／労働／残業を算出する。
 *
 * @param string|null $rounded_in  TIME（"08:30:00"）
 * @param string|null $rounded_out TIME（"25:00:00" 可）
 * @param int|null    $break_minutes
 * @return array{kousoku:int|null,labor:int|null,overtime:int|null,break:int}
 */
function mat_calc_work_minutes( $rounded_in, $rounded_out, $break_minutes ) {
	$break = (int) ( $break_minutes ?? 0 );
	$in    = mat_parse_time_to_minutes( $rounded_in );
	$out   = mat_parse_time_to_minutes( $rounded_out );

	if ( $in === null || $out === null ) {
		return array( 'kousoku' => null, 'labor' => null, 'overtime' => null, 'break' => $break );
	}

	// rounded_clock_out は 25:00 形式で保存されるが、
	// 旧データ等で out <= in の場合のみ翌日補正を行う。
	if ( $out <= $in ) $out += 1440;

	$kousoku = $out - $in;
	$labor   = max( 0, $kousoku - $break );

	return array(
		'kousoku'  => $kousoku,
		'labor'    => $labor,
		'overtime' => max( 0, $labor - mat_get_overtime_threshold() ),
		'break'    => $break,
	);
}

/**
 * 残業時間（分）を返す（公開API）。
 */
function mat_calc_overtime_minutes( $rounded_in, $rounded_out, $break_minutes ) {
	$calc = mat_calc_work_minutes( $rounded_in, $rounded_out, $break_minutes );
	return $calc['overtime'] === null ? 0 : (int) $calc['overtime'];
}

// =========================================================
//  5.5. 深夜時間管理（要件定義書 §3・§5）
// =========================================================

/**
 * 深夜帯の設定値を取得する（work_date 0:00 起点の分）。
 *
 * @return array{start:int,end:int}
 */
function mat_get_midnight_window() {
	$start = (int) get_option( 'mat_midnight_start', 1320 );
	$end   = (int) get_option( 'mat_midnight_end', 1740 );
	if ( $start < 0 || $end <= $start ) {
		$start = 1320;
		$end   = 1740;
	}
	return array( 'start' => $start, 'end' => $end );
}

/**
 * 深夜帯の判定区間を返す（work_date 0:00 起点。前夜分＋当夜分）。
 *
 * @return array<int,array{0:int,1:int}>
 */
function mat_get_midnight_ranges() {
	$window = mat_get_midnight_window();
	$start  = $window['start'];
	$end    = $window['end'];

	$ranges = array();

	$prev_start = max( 0, $start - 1440 );
	$prev_end   = max( 0, $end - 1440 );
	if ( $prev_end > $prev_start ) $ranges[] = array( $prev_start, $prev_end );

	$ranges[] = array( $start, $end );

	return $ranges;
}

/**
 * 2つの区間の重なり（分）を返す。
 */
function mat_calc_range_overlap( $a_start, $a_end, $b_start, $b_end ) {
	$overlap = min( $a_end, $b_end ) - max( $a_start, $b_start );
	return $overlap > 0 ? $overlap : 0;
}

/**
 * 始業・終業から深夜該当時間（分）を算出する。
 *
 * @param string|null $rounded_in  TIME（"08:30:00"）
 * @param string|null $rounded_out TIME（"25:00:00" 可）
 * @return int|null 判定不能時は null
 */
function mat_calc_midnight_span_minutes( $rounded_in, $rounded_out ) {
	$in  = mat_parse_time_to_minutes( $rounded_in );
	$out = mat_parse_time_to_minutes( $rounded_out );
	if ( $in === null || $out === null ) return null;
	if ( $out <= $in ) $out += 1440;

	$span = 0;
	foreach ( mat_get_midnight_ranges() as $range ) {
		$span += mat_calc_range_overlap( $in, $out, $range[0], $range[1] );
	}
	return $span;
}

/**
 * 深夜時間（分）＝ 深夜該当時間 − 深夜休憩時間 を算出する（公開API）。
 *
 * @param string|null $rounded_in
 * @param string|null $rounded_out
 * @param int|null    $midnight_break NULL のときは控除しない
 * @return int|null
 */
function mat_calc_midnight_minutes( $rounded_in, $rounded_out, $midnight_break = null ) {
	$span = mat_calc_midnight_span_minutes( $rounded_in, $rounded_out );
	if ( $span === null ) return null;
	if ( $midnight_break === null ) return $span;
	return max( 0, $span - (int) $midnight_break );
}

/**
 * 深夜アラートの対象期間内か（mat_midnight_alert_since による判定）。
 */
function mat_is_midnight_alert_target( $work_date ) {
	$since = (string) get_option( 'mat_midnight_alert_since', '' );
	if ( $since === '' ) return true;
	return (string) $work_date >= $since;
}

// =========================================================
//  6. 申請テーブル（wp_mat_work_request）
// =========================================================

const MAT_REVIEW_LABELS   = array( 0 => '未選択', 1 => '未確認', 2 => '確認中', 3 => '修正済み' );
const MAT_APPROVAL_LABELS = array( 0 => '未選択', 1 => '未承認', 2 => '承認済', 3 => '申請却下' );

/**
 * 指定 daily_id の申請を種別ごとの連想配列で返す。
 *
 * @return array<string,object> array( 'overtime' => row, 'break_exception' => row )
 */
function mat_get_work_requests_by_daily( $daily_id ) {
	global $wpdb;
	$daily_id = (int) $daily_id;
	if ( $daily_id <= 0 ) return array();

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM " . MAT_WORK_REQUEST_TABLE . " WHERE daily_id = %d", $daily_id
	) );
	$map = array();
	foreach ( (array) $rows as $r ) { $map[ $r->request_type ] = $r; }
	return $map;
}

/**
 * 複数 daily_id の申請をまとめて取得する（N+1 回避）。
 *
 * @return array<int,array<string,object>>
 */
function mat_get_work_requests_by_daily_ids( array $daily_ids ) {
	global $wpdb;
	$ids = array_values( array_filter( array_map( 'intval', $daily_ids ) ) );
	if ( empty( $ids ) ) return array();

	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM " . MAT_WORK_REQUEST_TABLE . " WHERE daily_id IN ({$placeholders})",
		$ids
	) );

	$map = array();
	foreach ( (array) $rows as $r ) {
		$map[ (int) $r->daily_id ][ $r->request_type ] = $r;
	}
	return $map;
}

/**
 * 申請を UPSERT する（daily_id + request_type で一意）。
 *
 * @param array $args daily_id / request_type は必須
 * @return int|false 申請ID
 */
function mat_upsert_work_request( array $args ) {
	global $wpdb;

	$daily_id = (int) ( $args['daily_id'] ?? 0 );
	$type     = (string) ( $args['request_type'] ?? '' );
	if ( $daily_id <= 0 || ! in_array( $type, array( 'break_exception', 'overtime', 'midnight_break' ), true ) ) {
		return false;
	}

	$daily = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . MAT_DAILY_TABLE . " WHERE id = %d", $daily_id
	) );
	if ( ! $daily ) return false;

	$existing = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . MAT_WORK_REQUEST_TABLE . " WHERE daily_id = %d AND request_type = %s",
		$daily_id, $type
	) );

	$data = array(
		'daily_id'      => $daily_id,
		'employee_id'   => (int) $daily->employee_id,
		'employee_code' => $daily->employee_code,
		'work_date'     => $daily->work_date,
		'request_type'  => $type,
	);

	foreach ( array( 'reason', 'requested_by', 'admin_comment' ) as $key ) {
		if ( array_key_exists( $key, $args ) ) $data[ $key ] = $args[ $key ];
	}
	foreach ( array( 'review_status', 'approval_status' ) as $key ) {
		if ( array_key_exists( $key, $args ) ) $data[ $key ] = (int) $args[ $key ];
	}
	if ( array_key_exists( 'reviewed_by', $args ) ) {
		$data['reviewed_by'] = $args['reviewed_by'] ? (int) $args['reviewed_by'] : null;
		$data['reviewed_at'] = current_time( 'mysql' );
	}

	if ( $existing ) {
		$wpdb->update( MAT_WORK_REQUEST_TABLE, $data, array( 'id' => (int) $existing->id ) );
		return (int) $existing->id;
	}

	if ( ! isset( $data['requested_by'] ) ) $data['requested_by'] = 'employee';
	$wpdb->insert( MAT_WORK_REQUEST_TABLE, $data );
	return (int) $wpdb->insert_id;
}

/**
 * 申請が「対応済み」か判定する。
 * 対応ステータス・承認ステータスの **どちらか一方でも選択されていれば対応済み**（要件定義書 §6.3）。
 */
function mat_is_request_resolved( $request ) {
	if ( ! $request ) return false;
	return (int) $request->review_status > 0 || (int) $request->approval_status > 0;
}

// =========================================================
//  7. アラート判定（要件定義書 §6.2）
//     保存せず、表示時に動的計算する
// =========================================================

/**
 * 日次行からアラート配列を生成する。
 *
 * @param object|array $row      wp_mat_attendance_daily の行
 * @param array        $requests mat_get_work_requests_by_daily() の戻り値
 * @return array<int,array{code:string,color:string,label:string,resolved:bool}>
 */
function mat_build_row_alerts( $row, array $requests = array() ) {
	$row = (object) $row;

	// 休日行・打刻なし行は判定対象外
	if ( ! empty( $row->is_holiday ) ) return array();
	if ( empty( $row->clock_in ) && empty( $row->clock_out ) ) return array();

	$rounded_in  = $row->rounded_clock_in  ?? null;
	$rounded_out = $row->rounded_clock_out ?? null;
	if ( ! $rounded_in )  $rounded_in  = $row->clock_in  ?? null;
	if ( ! $rounded_out ) $rounded_out = $row->clock_out ?? null;

	$calc     = mat_calc_work_minutes( $rounded_in, $rounded_out, $row->break_minutes ?? 0 );
	$standard = mat_get_standard_break_minutes( $calc['kousoku'] );
	$alerts   = array();

	// ① 休憩が基準外
	if ( $standard !== null && (int) ( $row->break_minutes ?? 0 ) !== (int) $standard ) {
		$req = $requests['break_exception'] ?? null;
		$alerts[] = array(
			'code'     => 'BREAK_IRREGULAR',
			'color'    => 'yellow',
			'label'    => sprintf( '休憩%d分（基準%d分）', (int) ( $row->break_minutes ?? 0 ), (int) $standard ),
			'resolved' => mat_is_request_resolved( $req ),
		);
	}

	// ②③ 残業
	if ( ! empty( $calc['overtime'] ) && $calc['overtime'] > 0 ) {
		$req = $requests['overtime'] ?? null;
		if ( $req ) {
			$alerts[] = array(
				'code'     => 'OVERTIME_REQUESTED',
				'color'    => 'blue',
				'label'    => '残業申請あり（' . mat_format_minutes_jp( $calc['overtime'] ) . '）',
				'resolved' => mat_is_request_resolved( $req ),
			);
		} else {
			$alerts[] = array(
				'code'     => 'OVERTIME_NO_REQUEST',
				'color'    => 'red',
				'label'    => '残業時間が発生しています。残業理由を確認してください。',
				'resolved' => false,
			);
		}
	}

	return $alerts;
}

/**
 * アラートが未対応（＝対応ステータス・承認ステータスの両方が未選択）かどうか。
 */
function mat_alerts_has_unresolved( array $alerts ) {
	foreach ( $alerts as $a ) {
		if ( empty( $a['resolved'] ) ) return true;
	}
	return false;
}

// =========================================================
//  8. 日次行の再計算
// =========================================================

/**
 * clock_in / clock_out から rounded_* を再計算して保存する。
 * 管理者が打刻を修正した場合などに呼び出す（要件定義書 §6.4）。
 *
 * @param int $daily_id
 * @return object|null 更新後の行
 */
function mat_recalc_daily_row( $daily_id ) {
	global $wpdb;
	$daily_id = (int) $daily_id;

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . MAT_DAILY_TABLE . " WHERE id = %d", $daily_id
	) );
	if ( ! $row ) return null;

	// 打刻時点の単位時間を優先（後から設定変更されても再現可能にする）
	$unit = ! empty( $row->time_unit ) ? (int) $row->time_unit : mat_get_time_unit();

	$in_min  = mat_parse_time_to_minutes( $row->clock_in );
	$out_min = mat_parse_time_to_minutes( $row->clock_out );

	// 実打刻が翌日にまたがる場合（out <= in）は 24時間超表記に正規化する
	$is_overnight = (int) ( $row->is_overnight ?? 0 );
	if ( $in_min !== null && $out_min !== null && $out_min <= $in_min ) {
		$out_min      += 1440;
		$is_overnight  = 1;
	} elseif ( $out_min !== null && $out_min >= 1440 ) {
		$is_overnight = 1;
	} elseif ( $in_min !== null && $out_min !== null ) {
		$is_overnight = 0;
	}

	$rounded_in  = $in_min  === null ? null : mat_minutes_to_time_sql( mat_round_in_minutes( $in_min, $unit ) );
	$rounded_out = $out_min === null ? null : mat_minutes_to_time_sql( mat_round_out_minutes( $out_min, $unit ) );

	// 深夜該当時間を丸め値から再計算し、既存の深夜休憩（NULL＝未確認はそのまま維持）を用いて深夜時間を再計算する（要件定義書 §5.1）
	$midnight_span = mat_calc_midnight_span_minutes( $rounded_in, $rounded_out );
	$midnight_break = $row->midnight_break_minutes === null ? null : (int) $row->midnight_break_minutes;
	$midnight_minutes = $midnight_span === null ? null : mat_calc_midnight_minutes( $rounded_in, $rounded_out, $midnight_break );

	$data = array(
		'rounded_clock_in'        => $rounded_in,
		'rounded_clock_out'       => $rounded_out,
		'is_overnight'            => $is_overnight,
		'time_unit'               => $unit,
		'midnight_span_minutes'   => $midnight_span,
		'midnight_minutes'        => $midnight_minutes,
	);
	$wpdb->update( MAT_DAILY_TABLE, $data, array( 'id' => $daily_id ) );

	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . MAT_DAILY_TABLE . " WHERE id = %d", $daily_id
	) );
}

// =========================================================
//  9. 公開API（要件定義書 §8.3）
//     他プラグインからは直接 SQL を書かず、これらを使用する
// =========================================================

/**
 * 日次行に計算値を付与した配列を返す。
 */
function mat_decorate_daily_row( $row, array $requests = array() ) {
	$row = (object) $row;

	$rounded_in  = $row->rounded_clock_in  ?? null;
	$rounded_out = $row->rounded_clock_out ?? null;
	$has_rounded = ! empty( $rounded_in ) || ! empty( $rounded_out );

	// 丸め値を優先、NULL のときのみ実打刻にフォールバック（既存データ対策）
	$calc_in  = ! empty( $rounded_in )  ? $rounded_in  : ( $row->clock_in  ?? null );
	$calc_out = ! empty( $rounded_out ) ? $rounded_out : ( $row->clock_out ?? null );

	$calc = mat_calc_work_minutes( $calc_in, $calc_out, $row->break_minutes ?? 0 );

	// 実打刻の退勤表示（日跨ぎは 24時間超表記）
	$out_min = mat_parse_time_to_minutes( $row->clock_out ?? null );
	$in_min  = mat_parse_time_to_minutes( $row->clock_in ?? null );
	if ( $out_min !== null && $in_min !== null && $out_min <= $in_min ) $out_min += 1440;

	return array(
		'id'                 => (int) $row->id,
		'employee_id'        => (int) $row->employee_id,
		'employee_code'      => $row->employee_code,
		'work_date'          => $row->work_date,
		'is_holiday'         => (bool) $row->is_holiday,
		'clock_in'           => mat_format_time_display( $row->clock_in ?? null ),
		'clock_out'          => $out_min === null ? '' : mat_minutes_to_hm( $out_min ),
		'break_minutes'      => isset( $row->break_minutes ) ? (int) $row->break_minutes : null,
		'rounded_clock_in'   => mat_format_time_display( $rounded_in ),
		'rounded_clock_out'  => mat_format_time_display( $rounded_out ),
		'has_rounded'        => $has_rounded,
		'is_overnight'       => (bool) ( $row->is_overnight ?? 0 ),
		'break_master_id'    => isset( $row->break_master_id ) ? (int) $row->break_master_id : null,
		'time_unit'          => isset( $row->time_unit ) ? (int) $row->time_unit : null,
		'kousoku_minutes'    => $calc['kousoku'],
		'labor_minutes'      => $calc['labor'],
		'overtime_minutes'   => $calc['overtime'],
		'standard_break'     => mat_get_standard_break_minutes( $calc['kousoku'] ),
		'midnight_span_minutes'  => isset( $row->midnight_span_minutes )  ? (int) $row->midnight_span_minutes  : null,
		'midnight_break_minutes' => isset( $row->midnight_break_minutes ) ? (int) $row->midnight_break_minutes : null,
		'midnight_minutes'       => isset( $row->midnight_minutes )       ? (int) $row->midnight_minutes       : null,
		'midnight_confirmed'     => isset( $row->midnight_break_minutes ) && $row->midnight_break_minutes !== null,
		'note'               => $row->note,
		'alerts'             => mat_build_row_alerts( $row, $requests ),
		'requests'           => $requests,
	);
}

/**
 * 丸め値・残業込みの日次配列を返す（公開API）。
 *
 * @param string $employee_code
 * @param string $year_month "Y-m"
 * @return array<string,array> work_date をキーとした配列
 */
function mat_get_daily_by_month( $employee_code, $year_month ) {
	global $wpdb;

	$start = $year_month . '-01';
	if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $year_month ) ) return array();
	$end = date( 'Y-m-t', strtotime( $start ) );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM " . MAT_DAILY_TABLE . "
		 WHERE employee_code = %s AND work_date BETWEEN %s AND %s
		 ORDER BY work_date ASC",
		$employee_code, $start, $end
	) );
	if ( empty( $rows ) ) return array();

	$req_map = mat_get_work_requests_by_daily_ids( wp_list_pluck( $rows, 'id' ) );

	$out = array();
	foreach ( $rows as $r ) {
		$out[ $r->work_date ] = mat_decorate_daily_row( $r, $req_map[ (int) $r->id ] ?? array() );
	}
	return $out;
}

/**
 * 指定従業員・指定月のアラート／申請を work_date をキーにまとめて返す。
 * 管理画面の一覧描画で N+1 クエリを避けるために使用する。
 *
 * @return array<string,array{alerts:array,requests:array}>
 */
function mat_get_month_alerts( $employee_id, $month ) {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM " . MAT_DAILY_TABLE . " WHERE employee_id = %d AND work_date LIKE %s",
		(int) $employee_id,
		$month . '%'
	) );
	if ( empty( $rows ) ) return array();

	$req_map = mat_get_work_requests_by_daily_ids( wp_list_pluck( $rows, 'id' ) );

	$out = array();
	foreach ( $rows as $r ) {
		$requests = $req_map[ (int) $r->id ] ?? array();
		$out[ $r->work_date ] = array(
			'alerts'   => mat_build_row_alerts( $r, $requests ),
			'requests' => $requests,
		);
	}
	return $out;
}

/**
 * 申請・承認状況を返す（公開API）。
 *
 * @return array<int,object> work_date 昇順
 */
function mat_get_work_requests( $employee_code, $year_month ) {
	global $wpdb;
	if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $year_month ) ) return array();

	$start = $year_month . '-01';
	$end   = date( 'Y-m-t', strtotime( $start ) );

	return (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM " . MAT_WORK_REQUEST_TABLE . "
		 WHERE employee_code = %s AND work_date BETWEEN %s AND %s
		 ORDER BY work_date ASC, request_type ASC",
		$employee_code, $start, $end
	) );
}
