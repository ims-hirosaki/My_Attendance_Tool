<?php
// Run: php tests/policy-regression.php (no WordPress database required).
error_reporting( E_ALL );
set_error_handler( function( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
define( 'ABSPATH', __DIR__ );
define( 'MAT_BREAK_MASTER_TABLE', 'break_master' );
define( 'MAT_DAILY_TABLE', 'daily' );
define( 'MAT_WORK_REQUEST_TABLE', 'requests' );
class WP_Error {
    public $code; private $message;
    function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    function get_error_message() { return $this->message; }
}
class JsonResult extends Exception {
    public $success; public $data;
    function __construct( $success, $data ) { $this->success = $success; $this->data = $data; }
}
class SavedResult extends Exception { public $data; function __construct( $data ) { $this->data = $data; } }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_action( ...$args ) {}
function check_ajax_referer( ...$args ) {}
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
function wp_list_pluck( $rows, $field ) { return array_map( function( $r ) use ( $field ) { return $r->$field; }, $rows ); }
function emp_get_job_types() { return array( (object) array( 'id' => 1, 'name' => '運転手' ), (object) array( 'id' => 2, 'name' => '事務' ) ); }
function emp_get_employee_by_code( $code ) { return (object) array( 'id' => 1, 'job_type_id' => 1 ); }
function current_time( $format ) { return date( $format, strtotime( '2026-09-24 14:00:00' ) ); }
function wp_send_json_error( $data ) { throw new JsonResult( false, $data ); }
function wp_send_json_success( $data ) { throw new JsonResult( true, $data ); }
class TestDB {
    public $daily;
    public $masters;
    function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    function get_results( $sql ) { return $this->masters; }
    function get_row( $query ) {
        if ( is_array( $query ) ) {
            list( $sql, $args ) = $query;
            if ( strpos( $sql, 'FROM daily' ) !== false ) return ( $args[1] ?? '' ) === $this->daily->work_date ? $this->daily : null;
            if ( strpos( $sql, 'FROM break_master' ) !== false ) {
                foreach ( $this->masters as $m ) if ( $m->id === $args[0] ) return $m;
            }
            return null;
        }
        return $this->masters[2];
    }
    function update( $table, $data, $where ) { throw new SavedResult( $data ); }
}
$wpdb = new TestDB();
$wpdb->masters = array();
foreach ( array( array( 0, 360, 0 ), array( 360, 480, 45 ), array( 480, null, 60 ) ) as $i => $r ) {
    $wpdb->masters[] = (object) array( 'id' => $i + 1, 'min_minutes' => $r[0], 'max_minutes' => $r[1], 'break_minutes' => $r[2], 'is_auto' => 1, 'is_active' => 1, 'label' => '休憩' );
}
require __DIR__ . '/../includes/mat-core.php';
require __DIR__ . '/../includes/attendance-policy.php';
require __DIR__ . '/../includes/ajax-handlers.php';
$checks = 0;
function expect( $condition, $label ) { global $checks; $checks++; if ( ! $condition ) throw new Exception( 'FAIL: ' . $label ); }
function alerts_for( $row, $requests = array() ) {
    return array_values( array_filter( mat_build_row_alerts( $row, $requests ), function( $a ) { return $a['code'] === 'CLOCKIN_IRREGULAR'; } ) );
}
$options = array( 'mat_clockin_alert_enabled' => 1, 'mat_clockin_alert_since' => '2026-09-24', 'mat_clock_in_unit' => 60, 'mat_clock_out_unit' => 30 );
$row = (object) array( 'id' => 1, 'employee_id' => 1, 'employee_code' => '1', 'work_date' => '2026-09-24', 'is_holiday' => 0, 'clock_in' => '07:01:00', 'clock_out' => null, 'rounded_clock_in' => '08:00:00', 'break_minutes' => null, 'break_master_id' => null, 'midnight_break_minutes' => null );
foreach ( array( '06:50' => true, '07:00' => true, '07:01' => false, '08:00' => false, '08:01' => true ) as $time => $expected ) {
    $r = clone $row; $r->clock_in = $time; $r->rounded_clock_in = mat_round_clock_in( $time, 60 );
    expect( (bool) alerts_for( $r ) === $expected, 'clock-in boundary ' . $time );
}
$r = clone $row; $r->clock_in = '06:50'; $r->rounded_clock_in = '07:00';
foreach ( array( 0, 1, 2, 3 ) as $approval ) {
    $req = (object) array( 'approval_status' => $approval, 'review_status' => 3 );
    $alerts = alerts_for( $r, array( 'clockin_exception' => $req ) );
    expect( $alerts[0]['resolved'] === ( $approval === 2 ), 'only approved resolves clock-in ' . $approval );
    expect( $alerts[0]['color'] === 'yellow', 'clock-in base color' );
}
$r->work_date = '2026-09-23'; expect( ! alerts_for( $r ), 'before start date' );
$r->work_date = '2026-09-24'; $r->is_holiday = 1; expect( ! alerts_for( $r ), 'holiday excluded' );
$r->is_holiday = 0; $r->clock_in = null; expect( ! alerts_for( $r ), 'missing punch excluded' );
$options['mat_clockin_alert_enabled'] = 0; $r->clock_in = '06:50'; expect( ! alerts_for( $r ), 'disabled alert' );
expect( mat_get_clock_out_unit() === 30, 'outgoing rounding unchanged' );
$options['mat_show_break_controls'] = 0;
$options['mat_job_break_rules'] = array(
    array( 'job_types' => array( 1 ), 'minutes' => 60, 'is_default' => false ),
    array( 'job_types' => array( 2 ), 'minutes' => 45, 'is_default' => true ),
);
$emp = (object) array( 'job_type_id' => 1 );
expect( mat_get_job_break_policy( $emp ) === array( 'minutes' => 60, 'fallback' => false ), 'matching job' );
foreach ( array( null, 99 ) as $id ) {
    expect( mat_get_job_break_policy( (object) array( 'job_type_id' => $id ) ) === array( 'minutes' => 45, 'fallback' => true ), 'default for missing/unmapped job' );
}
$options['mat_show_break_controls'] = 1; expect( mat_get_job_break_policy( $emp ) === null, 'visible controls use legacy mode' );
$options['mat_show_break_controls'] = 0;
expect( mat_resolve_job_break( $emp, $row, '08:00', '14:00' )['needs_fix'], '60 vs 45 short alert' );
expect( ! mat_resolve_job_break( $emp, $row, '08:00', '16:00' )['needs_fix'], 'equal break does not alert' );
expect( mat_resolve_job_break( $emp, $row, '08:00', '14:00', null, true )->code === 'break_required', 'cannot bypass required correction' );
expect( mat_resolve_job_break( $emp, $row, '08:00', '14:00', '60', true )->code === 'break_unchanged', 'cannot submit unchanged fixed break' );
foreach ( array( '-1', '1.5', '1441', '361', array( 0 ) ) as $invalid ) {
    expect( is_wp_error( mat_resolve_job_break( $emp, $row, '08:00', '14:00', $invalid, true ) ), 'invalid correction' );
}
expect( mat_resolve_job_break( $emp, $row, '08:00', '14:00', '45', true )['minutes'] === 45, 'corrected break accepted' );
expect( mat_resolve_job_break( $emp, $row, '08:00', '10:00', '0', true )['minutes'] === 0, 'zero break accepted' );
$options['mat_short_break_alert'] = 0;
expect( mat_resolve_job_break( $emp, $row, '08:00', '14:00', null, true )['minutes'] === 60, 'short warning off' );
$options['mat_short_break_alert'] = 1;
$r = clone $row; $r->clock_out = '14:00'; $r->rounded_clock_out = '14:00'; $r->break_minutes = 60;
expect( in_array( 'BREAK_IRREGULAR', array_column( mat_build_row_alerts( $r ), 'code' ), true ), 'admin still uses break master' );
$source = array( 'mat_job_break_rules' => array( array( 'job_types' => array( '1', '2' ), 'minutes' => '60' ) ), 'mat_job_break_default' => '0' );
expect( ! is_wp_error( mat_validate_policy_settings( $source ) ), 'valid settings' );
$bad = $source; unset( $bad['mat_job_break_default'] ); expect( is_wp_error( mat_validate_policy_settings( $bad ) ), 'default required' );
$bad = $source; $bad['mat_job_break_rules'][] = array( 'job_types' => array( '1' ), 'minutes' => '30' ); expect( is_wp_error( mat_validate_policy_settings( $bad ) ), 'duplicate jobs rejected' );
$bad = $source; $bad['mat_job_break_rules'][0]['job_types'] = array( 99 ); expect( is_wp_error( mat_validate_policy_settings( $bad ) ), 'unknown job rejected' );
$bad = $source; $bad['mat_clockin_alert_enabled'] = '1'; expect( is_wp_error( mat_validate_policy_settings( $bad ) ), 'start date required' );
$bad['mat_clockin_alert_since'] = '2026-02-30'; expect( is_wp_error( mat_validate_policy_settings( $bad ) ), 'invalid date rejected' );
$bad['mat_clockin_alert_since'] = '2026-09-24'; expect( ! is_wp_error( mat_validate_policy_settings( $bad ) ), 'valid start date' );

// Exercise actual Ajax prepare and final-save paths. The DB double captures writes.
$wpdb->daily = $row;
$_POST = array( 'emp_master_id' => 1, 'employee_code' => '1', 'break_master_id' => 3 );
try { mat_prepare_clockout_handler(); } catch ( JsonResult $result ) {
    expect( $result->success && $result->data['needs_short_break_fix'], 'prepare returns required correction' );
    expect( $result->data['break_minutes'] === 60, 'prepare uses job break' );
}
try { mat_handle_clockout( 1, '1' ); } catch ( JsonResult $result ) {
    expect( ! $result->success, 'final endpoint blocks missing correction' );
}
$_POST['job_break_minutes'] = '45';
try { mat_prepare_clockout_handler(); } catch ( JsonResult $result ) {
    expect( $result->success && ! $result->data['needs_short_break_fix'], 'prepare accepts correction' );
    expect( $result->data['labor_minutes'] === 315, 'labor recalculated after correction' );
}
try { mat_handle_clockout( 1, '1' ); } catch ( SavedResult $result ) {
    expect( $result->data['break_minutes'] === 45 && $result->data['break_master_id'] === null, 'final writes corrected minutes with no stale master' );
    expect( $result->data['clock_out_unit'] === 30, 'save preserves outgoing rounding' );
}
echo "PASS: {$checks} checks\n";
