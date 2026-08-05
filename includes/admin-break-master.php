<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * admin-break-master.php  v3.2.0
 *
 * 休憩時間マスタの登録UI（要件定義書 §4.2）。
 * 既存の「設定」ページ（mat-settings）内にセクションとして描画する。
 *
 * 保存は admin-post.php（action = mat_save_break_master）。
 * 削除は is_active = 0 の論理削除（過去データ参照のため物理削除しない）。
 */

// =========================================================
//  保存処理
// =========================================================

add_action( 'admin_post_mat_save_break_master', 'mat_save_break_master_handler' );
function mat_save_break_master_handler() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( '権限がありません。' );
	check_admin_referer( 'mat_save_break_master' );

	global $wpdb;

	$posted     = isset( $_POST['mat_break'] ) && is_array( $_POST['mat_break'] ) ? $_POST['mat_break'] : array();
	$default_ix = isset( $_POST['mat_break_default'] ) ? sanitize_text_field( $_POST['mat_break_default'] ) : '';

	$rows = array();
	foreach ( $posted as $ix => $raw ) {
		$is_active = empty( $raw['deleted'] );

		// 完全な空行（新規追加したまま何も入力しなかった行）はスキップ
		$is_new = empty( $raw['id'] );
		if ( $is_new && trim( (string) ( $raw['label'] ?? '' ) ) === '' && ! $is_active ) continue;
		if ( $is_new && trim( (string) ( $raw['label'] ?? '' ) ) === '' && (string) ( $raw['break_minutes'] ?? '' ) === '' ) continue;

		$is_auto = ! empty( $raw['is_auto'] );
		// 自動判定OFFの行は input が disabled のため POST に含まれない
		$min_raw = isset( $raw['min_minutes'] ) ? trim( (string) $raw['min_minutes'] ) : '';
		$max_raw = isset( $raw['max_minutes'] ) ? trim( (string) $raw['max_minutes'] ) : '';

		$rows[] = array(
			'id'            => (int) ( $raw['id'] ?? 0 ),
			'label'         => sanitize_text_field( $raw['label'] ?? '' ),
			// 自動判定OFFの行は閾値を保持しない
			'min_minutes'   => ( $is_auto && $min_raw !== '' ) ? (int) $min_raw : null,
			'max_minutes'   => ( $is_auto && $max_raw !== '' ) ? (int) $max_raw : null,
			'break_minutes' => (string) ( $raw['break_minutes'] ?? '' ),
			'is_auto'       => $is_auto ? 1 : 0,
			'is_default'    => ( (string) $ix === (string) $default_ix ) ? 1 : 0,
			'sort_order'    => (int) ( $raw['sort_order'] ?? 0 ),
			'is_active'     => $is_active ? 1 : 0,
		);
	}

	$errors = mat_validate_break_master_rows( $rows );
	if ( ! empty( $errors ) ) {
		set_transient( 'mat_break_master_errors_' . get_current_user_id(), $errors, 60 );
		set_transient( 'mat_break_master_input_' . get_current_user_id(), $rows, 60 );
		wp_redirect( admin_url( 'admin.php?page=mat-settings&break_error=1#mat-break-master' ) );
		exit;
	}

	foreach ( $rows as $row ) {
		$data = array(
			'label'         => $row['label'],
			'min_minutes'   => $row['min_minutes'],
			'max_minutes'   => $row['max_minutes'],
			'break_minutes' => (int) $row['break_minutes'],
			'is_auto'       => $row['is_auto'],
			'is_default'    => $row['is_default'],
			'sort_order'    => $row['sort_order'],
			'is_active'     => $row['is_active'],
		);

		if ( $row['id'] > 0 ) {
			$wpdb->update( MAT_BREAK_MASTER_TABLE, $data, array( 'id' => $row['id'] ) );
		} else {
			$wpdb->insert( MAT_BREAK_MASTER_TABLE, $data );
		}
	}

	wp_redirect( admin_url( 'admin.php?page=mat-settings&break_saved=1#mat-break-master' ) );
	exit;
}

// =========================================================
//  セクション描画
// =========================================================

function mat_render_break_master_section() {
	$user_id = get_current_user_id();
	$errors  = get_transient( 'mat_break_master_errors_' . $user_id );
	$input   = get_transient( 'mat_break_master_input_' . $user_id );
	delete_transient( 'mat_break_master_errors_' . $user_id );
	delete_transient( 'mat_break_master_input_' . $user_id );

	// エラー時は入力値を復元、通常時は DB から取得
	if ( is_array( $input ) && ! empty( $input ) ) {
		$rows = array();
		foreach ( $input as $r ) {
			if ( ! $r['is_active'] ) continue;
			$rows[] = (object) $r;
		}
	} else {
		$rows = mat_get_break_master();
	}
	?>
	<hr style="margin:32px 0 24px;">

	<h2 id="mat-break-master">🍱 休憩時間マスタ</h2>

	<?php if ( isset( $_GET['break_saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>休憩時間マスタを保存しました。</p></div>
	<?php endif; ?>

	<?php if ( ! empty( $errors ) ) : ?>
		<div class="notice notice-error">
			<p><strong>休憩時間マスタを保存できませんでした。</strong></p>
			<ul style="margin:0 0 8px 18px; list-style:disc;">
				<?php foreach ( $errors as $e ) : ?>
					<li><?php echo esc_html( $e ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<p class="description" style="max-width:820px;">
		フロントエンドの休憩スライダーの選択肢と、拘束時間から自動判定される「基準休憩」を定義します。<br>
		「自動判定」ONの行は、拘束時間（終業−始業）の下限以上・上限未満のときに適用されます。
		<strong>0分〜上限なしまでを隙間なく・重複なくカバー</strong>してください。<br>
		「自動判定」OFFの行はスライダーの選択肢としてのみ機能します（フリーラベル行）。
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mat-break-master-form">
		<?php wp_nonce_field( 'mat_save_break_master' ); ?>
		<input type="hidden" name="action" value="mat_save_break_master">

		<table class="widefat striped" id="mat-break-master-table" style="max-width:1000px; margin-top:12px;">
			<thead>
				<tr>
					<th style="width:50px;">ID</th>
					<th>ラベル</th>
					<th style="width:110px;">拘束下限(分)</th>
					<th style="width:110px;">拘束上限(分)</th>
					<th style="width:100px;">休憩(分)</th>
					<th style="width:80px;">自動判定</th>
					<th style="width:60px;">既定</th>
					<th style="width:80px;">並び順</th>
					<th style="width:70px;">操作</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $i => $row ) : ?>
					<?php echo mat_break_master_row_html( $i, $row ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:10px;">
			<button type="button" class="button" id="mat-break-add-row">＋ 行を追加</button>
		</p>

		<?php submit_button( '休憩マスタを保存' ); ?>
	</form>

	<script type="text/template" id="mat-break-row-template">
		<?php echo mat_break_master_row_html( '__INDEX__', null ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</script>

	<script>
	jQuery(function($) {
		var nextIndex = <?php echo (int) count( $rows ); ?>;

		function toggleAutoInputs($tr) {
			var isAuto = $tr.find('.mat-break-auto').is(':checked');
			$tr.find('.mat-break-min, .mat-break-max')
				.prop('disabled', !isAuto)
				.css({ background: isAuto ? '' : '#f0f0f0' });
			if (!isAuto) { $tr.find('.mat-break-min, .mat-break-max').val(''); }
		}

		$('#mat-break-master-table tbody tr').each(function() { toggleAutoInputs($(this)); });

		$(document).on('change', '.mat-break-auto', function() {
			toggleAutoInputs($(this).closest('tr'));
		});

		$('#mat-break-add-row').on('click', function() {
			var html = $('#mat-break-row-template').html().replace(/__INDEX__/g, 'new' + nextIndex);
			var $tr = $(html);
			$tr.find('.mat-break-sort').val(nextIndex + 1);
			$('#mat-break-master-table tbody').append($tr);
			toggleAutoInputs($tr);
			nextIndex++;
		});

		// 削除（論理削除）：行を非表示にして hidden で deleted=1 を送る
		$(document).on('click', '.mat-break-delete', function() {
			var $tr = $(this).closest('tr');
			if ($tr.find('.mat-break-default').is(':checked')) {
				alert('「既定」に設定されている行は削除できません。先に別の行を既定に設定してください。');
				return;
			}
			if (!confirm('この行を削除しますか？（過去データ参照のため論理削除されます）')) return;
			$tr.find('.mat-break-deleted').val('1');
			$tr.hide();
		});
	});
	</script>
	<?php
}

/**
 * 休憩マスタ1行分の HTML を生成する。
 *
 * @param int|string  $index 行インデックス（テンプレート時は '__INDEX__'）
 * @param object|null $row   既存行（新規は null）
 */
function mat_break_master_row_html( $index, $row = null ) {
	$name = 'mat_break[' . $index . ']';

	$id            = $row ? (int) $row->id : 0;
	$label         = $row ? $row->label : '';
	$min           = $row && $row->min_minutes !== null ? (int) $row->min_minutes : '';
	$max           = $row && $row->max_minutes !== null ? (int) $row->max_minutes : '';
	$break_minutes = $row ? (int) $row->break_minutes : 0;
	$is_auto       = $row ? (bool) $row->is_auto : true;
	$is_default    = $row ? (bool) $row->is_default : false;
	$sort_order    = $row ? (int) $row->sort_order : 0;

	ob_start();
	?>
	<tr>
		<td>
			<?php echo $id > 0 ? esc_html( $id ) : '<span style="color:#999;">新規</span>'; ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" class="mat-break-deleted" name="<?php echo esc_attr( $name ); ?>[deleted]" value="0">
		</td>
		<td>
			<input type="text" class="regular-text" style="width:100%;"
				name="<?php echo esc_attr( $name ); ?>[label]"
				value="<?php echo esc_attr( $label ); ?>" placeholder="例：6時間以上8時間未満">
		</td>
		<td>
			<input type="number" class="mat-break-min small-text" min="0" max="1440"
				name="<?php echo esc_attr( $name ); ?>[min_minutes]"
				value="<?php echo esc_attr( $min ); ?>" placeholder="なし">
		</td>
		<td>
			<input type="number" class="mat-break-max small-text" min="0" max="1440"
				name="<?php echo esc_attr( $name ); ?>[max_minutes]"
				value="<?php echo esc_attr( $max ); ?>" placeholder="なし">
		</td>
		<td>
			<input type="number" class="small-text" min="0" max="480" required
				name="<?php echo esc_attr( $name ); ?>[break_minutes]"
				value="<?php echo esc_attr( $break_minutes ); ?>">
		</td>
		<td style="text-align:center;">
			<input type="checkbox" class="mat-break-auto" value="1"
				name="<?php echo esc_attr( $name ); ?>[is_auto]" <?php checked( $is_auto ); ?>>
		</td>
		<td style="text-align:center;">
			<input type="radio" class="mat-break-default" name="mat_break_default"
				value="<?php echo esc_attr( $index ); ?>" <?php checked( $is_default ); ?>>
		</td>
		<td>
			<input type="number" class="mat-break-sort small-text" min="0" max="999"
				name="<?php echo esc_attr( $name ); ?>[sort_order]"
				value="<?php echo esc_attr( $sort_order ); ?>">
		</td>
		<td>
			<button type="button" class="button button-small mat-break-delete">削除</button>
		</td>
	</tr>
	<?php
	return ob_get_clean();
}
