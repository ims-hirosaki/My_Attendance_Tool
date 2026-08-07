jQuery(document).ready(function ($) {

    var ajaxurl = matAjax.ajaxurl;
    var nonce = matAjax.nonce;
    var allowLogEdit = matAjax.allowLogEdit === '1';
    var showPaidLeave = matAjax.showPaidLeaveRequest === '1';

    var isSubmitting = false;

    var session = {
        empMasterId: 0,
        employeeCode: '',
        userName: '',
        hasBreak: false,
        hasNote: false,
    };
    var editTargetId = null;

    // 時計
    function tickClock() {
        var now = new Date();
        var h = String(now.getHours()).padStart(2, '0');
        var m = String(now.getMinutes()).padStart(2, '0');
        var s = String(now.getSeconds()).padStart(2, '0');
        $('#mat-clock').text(h + ':' + m + ':' + s);
    }
    tickClock();
    setInterval(tickClock, 1000);

    function minsToHHMM(mins) {
        mins = parseInt(mins, 10) || 0;
        return String(Math.floor(mins / 60)).padStart(2, '0')
            + ':' + String(mins % 60).padStart(2, '0');
    }
    function showToast(msg, type) {
        var $toast = $('<div class="mat-toast mat-toast-' + (type || 'success') + '">' + msg + '</div>');
        $('body').append($toast);
        setTimeout(function () {
            $toast.addClass('mat-toast-fadeout');
            setTimeout(function () { $toast.remove(); }, 500);
        }, 2500);
    }
    function showSection(id) {
        $('.mat-section').hide();
        $('#' + id).show();
    }
    function setError(id, msg) {
        $('#' + id).text(msg || '').show();
    }
    function clearError(id) {
        $('#' + id).text('').hide();
    }
    function setSuccess(id, msg) {
        $('#' + id).text(msg || '').show();
    }
    function clearSuccess(id) {
        $('#' + id).text('').hide();
    }
    function btnLoading($btn, loading) {
        if (loading) {
            $btn.prop('disabled', true).data('original-text', $btn.text()).text('処理中...');
        } else {
            $btn.prop('disabled', false).text($btn.data('original-text') || $btn.text());
        }
    }
    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function getCurrentYearMonth() {
        var now = new Date();
        return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    }
    // .mat-modal は display:flex で中央寄せするため fadeIn（display:block）は使わない
    function showModal(selector, data) {
        $(selector).data('prepared', data || null).css('display', 'flex');
    }
    // ポップアップ本文のラベル／値 1行分（値は呼び出し側でエスケープ済みのHTML）
    function kvRow(key, valueHtml) {
        return '<div class="mat-kv"><span class="mat-kv-k">' + esc(key) + '</span>'
            + '<strong class="mat-kv-v">' + valueHtml + '</strong></div>';
    }

    // ===================== 休憩スライダー（休憩マスタ連動の離散ステップ） =====================
    var breakSteps = matAjax.breakSteps || [];

    function currentBreakStep() {
        if (!breakSteps.length) return null;
        var ix = parseInt($('#mat-break-slider').val(), 10);
        if (isNaN(ix) || ix < 0) ix = 0;
        if (ix > breakSteps.length - 1) ix = breakSteps.length - 1;
        return breakSteps[ix];
    }

    function renderBreakStep() {
        var step = currentBreakStep();
        if (!step) {
            $('#mat-break-display').text('--');
            $('#mat-break-label').text('休憩マスタが登録されていません。');
            $('.mat-wrap [data-label="休憩"]').prop('disabled', true).css('opacity', '0.5');
            return;
        }
        $('#mat-break-display').text(step.minutes + '分');
        $('#mat-break-label').text(step.label);
    }

    $('#mat-break-slider').on('input change', renderBreakStep);
    renderBreakStep();

    // 社員コード認証
    $('#mat-btn-verify-code').on('click', function () {
        var code = $.trim($('#mat-employee-code').val());
        if (!code) { setError('mat-error-code', '社員コードを入力してください。'); return; }

        clearError('mat-error-code');
        btnLoading($(this), true);

        $.post(ajaxurl, {
            action: 'mat_check_employee',
            employee_code: code,
            nonce: nonce,
        }, function (res) {
            btnLoading($('#mat-btn-verify-code'), false);
            if (!res.success) {
                setError('mat-error-code', res.data);
                return;
            }
            var d = res.data;
            session.employeeCode = code;

            if (d.status === 'needs_setup') {
                session.empMasterId = d.emp_master_id;
                session.employeeCode = d.employee_code;
                session.userName = d.user_name;
                clearError('mat-error-set-password');
                $('#mat-new-password').val('');
                $('#mat-new-password2').val('');
                showSection('mat-section-set-password');
            } else if (d.status === 'needs_password') {
                clearError('mat-error-login');
                $('#mat-password').val('');
                showSection('mat-section-enter-password');
            } else if (d.status === 'logged_in') {
                session.empMasterId = d.emp_master_id;
                session.employeeCode = d.employee_code;
                session.userName = d.user_name;
                onLoginComplete();
            }
        }).fail(function () {
            btnLoading($('#mat-btn-verify-code'), false);
            setError('mat-error-code', '通信エラーが発生しました。');
        });
    });

    $('#mat-employee-code').on('keydown', function (e) {
        if (e.key === 'Enter') $('#mat-btn-verify-code').trigger('click');
    });

    // パスワード新規設定
    $('#mat-btn-set-password').on('click', function () {
        var pw1 = $('#mat-new-password').val();
        var pw2 = $('#mat-new-password2').val();
        clearError('mat-error-set-password');

        if (pw1.length < 4) { setError('mat-error-set-password', 'パスワードは4文字以上で入力してください。'); return; }
        if (pw1 !== pw2) { setError('mat-error-set-password', 'パスワードが一致しません。'); return; }

        btnLoading($(this), true);

        $.post(ajaxurl, {
            action: 'mat_setup_password',
            employee_code: session.employeeCode,
            password: pw1,
            nonce: nonce,
        }, function (res) {
            btnLoading($('#mat-btn-set-password'), false);
            if (!res.success) { setError('mat-error-set-password', res.data); return; }
            var d = res.data;
            session.empMasterId = d.emp_master_id;
            session.employeeCode = d.employee_code;
            session.userName = d.user_name;
            onLoginComplete();
        }).fail(function () {
            btnLoading($('#mat-btn-set-password'), false);
            setError('mat-error-set-password', '通信エラーが発生しました。');
        });
    });

    // パスワードログイン
    $('#mat-btn-login').on('click', function () {
        var pw = $('#mat-password').val();
        clearError('mat-error-login');
        if (!pw) { setError('mat-error-login', 'パスワードを入力してください。'); return; }

        btnLoading($(this), true);

        $.post(ajaxurl, {
            action: 'mat_verify_password',
            employee_code: session.employeeCode,
            password: pw,
            nonce: nonce,
        }, function (res) {
            btnLoading($('#mat-btn-login'), false);
            if (!res.success) { setError('mat-error-login', res.data); return; }
            var d = res.data;
            session.empMasterId = d.emp_master_id;
            session.employeeCode = d.employee_code;
            session.userName = d.user_name;
            onLoginComplete();
        }).fail(function () {
            btnLoading($('#mat-btn-login'), false);
            setError('mat-error-login', '通信エラーが発生しました。');
        });
    });

    $('#mat-password').on('keydown', function (e) {
        if (e.key === 'Enter') $('#mat-btn-login').trigger('click');
    });

    // パスワードリセット申請
    $('#mat-forgot-password').on('click', function (e) {
        e.preventDefault();
        $('#mat-reset-code').val(session.employeeCode);
        clearError('mat-error-reset');
        $('#mat-success-reset').hide();
        showSection('mat-section-reset-request');
    });

    $('#mat-btn-reset-request').on('click', function () {
        var code = $.trim($('#mat-reset-code').val());
        if (!code) { setError('mat-error-reset', '社員コードを入力してください。'); return; }

        clearError('mat-error-reset');
        btnLoading($(this), true);

        $.post(ajaxurl, {
            action: 'mat_request_password_reset',
            employee_code: code,
            nonce: nonce,
        }, function (res) {
            btnLoading($('#mat-btn-reset-request'), false);
            if (res.success) {
                $('#mat-success-reset').text(res.data.message).show();
            } else {
                setError('mat-error-reset', res.data);
            }
        }).fail(function () {
            btnLoading($('#mat-btn-reset-request'), false);
            setError('mat-error-reset', '通信エラーが発生しました。');
        });
    });

    // 戻る
    $('#mat-back-to-code-from-setpw, #mat-back-to-code-from-login, #mat-back-to-code-from-reset').on('click', function (e) {
        e.preventDefault();
        session = { empMasterId: 0, employeeCode: '', userName: '', hasBreak: false, hasNote: false };
        $('#mat-employee-code').val('');
        clearError('mat-error-code');
        showSection('mat-section-code');
    });

    function onLoginComplete() {
        $('#mat-user-name').text(session.userName);
        showSection('mat-section-main');
        loadLogs();
        if (showPaidLeave) { loadPaidLeaveRequests(); }
        refreshPunchButtons();
    }

    $('#mat-logout').on('click', function (e) {
        e.preventDefault();
        session = { empMasterId: 0, employeeCode: '', userName: '', hasBreak: false, hasNote: false };
        editTargetId = null;
        $('#mat-employee-code').val('');
        $('#mat-note').val('');
        $('#mat-paid-leave-date').val('');
        $('#mat-holiday-date').val('');
        showSection('mat-section-code');
    });

    // 打刻処理（備考上書き保護をフロント側でも中継連携）
    $(document).on('click', '.mat-punch-btn', function () {
        if (isSubmitting) return;
        var label = $(this).data('label');
        if (!session.empMasterId) { alert('ログインしてください。'); return; }

        if (label === '休憩' && session.hasBreak) {
            if (!confirm('すでに休憩が登録されています。上書きしますか？')) return;
        }

        // 退勤は①日跨ぎ →②例外休憩 →③残業 →④深夜休憩 の順にポップアップで確認する
        if (label === '退勤') {
            startClockoutFlow($(this));
            return;
        }

        var postData = {
            action: 'mat_attendance_update',
            emp_master_id: session.empMasterId,
            employee_code: session.employeeCode,
            label: label,
            nonce: nonce,
        };

        if (label === '休憩') {
            var step = currentBreakStep();
            if (!step) { alert('休憩マスタが登録されていません。管理者にお問い合わせください。'); return; }
            postData.break_master_id = step.id;
            postData.break_hhmm = minsToHHMM(step.minutes);
        }

        var $btn = $(this);
        btnLoading($btn, true);
        isSubmitting = true;

        $.post(ajaxurl, postData, function (res) {
            btnLoading($btn, false);
            isSubmitting = false;

            if (res.success) {
                showToast(label + 'を登録しました ✓', 'success');
                renderLogs(res.data);
                refreshPunchButtons();
            } else {
                showToast(res.data, 'error');
                alert('エラー: ' + res.data);
            }
        }).fail(function () {
            btnLoading($btn, false);
            isSubmitting = false;
            alert('通信エラーが発生しました。');
        });
    });

    // =====================================================
    //  退勤フロー（① 日跨ぎ → ② 例外休憩 → ③ 残業 → ④ 深夜休憩）
    // =====================================================

    // フロー中の状態。修正が入るたびに prepare をやり直して再判定する。
    var clockout = {
        $btn: null,
        targetDate: '',
        override: '',
        breakMasterId: 0,
        breakReason: '',
        overtimeReason: '',
        overnightConfirmed: false,
        midnightBreak: null,   // null = ④未回答。0以上の整数になれば回答済み
        midnightReason: '',
    };

    // ④ の直前に受け取った prepare データ（救済フローからの再表示に使う）
    var lastMidnightData = null;

    function startClockoutFlow($btn) {
        var step = currentBreakStep();
        clockout = {
            $btn: $btn,
            targetDate: '',
            override: '',
            breakMasterId: step ? step.id : 0,
            breakReason: '',
            breakFixed: false,
            overtimeReason: '',
            overnightConfirmed: false,
            midnightBreak: null,
            midnightReason: '',
        };
        lastMidnightData = null;
        btnLoading($btn, true);
        isSubmitting = true;
        prepareClockout();
    }

    function endClockoutFlow() {
        if (clockout.$btn) btnLoading(clockout.$btn, false);
        isSubmitting = false;
    }

    function prepareClockout() {
        $.post(ajaxurl, {
            action: 'mat_prepare_clockout',
            emp_master_id: session.empMasterId,
            employee_code: session.employeeCode,
            break_master_id: clockout.breakMasterId,
            clock_out_override: clockout.override,
            target_date: clockout.targetDate,
            nonce: nonce,
        }, function (res) {
            if (!res.success) {
                endClockoutFlow();
                showToast(res.data, 'error');
                alert('エラー: ' + res.data);
                return;
            }
            handlePrepareResult(res.data);
        }).fail(function () {
            endClockoutFlow();
            alert('通信エラーが発生しました。');
        });
    }

    function handlePrepareResult(d) {
        clockout.targetDate = d.target_date;
        // 監査用：最初に判定した退勤時刻を「修正前」として保持する
        if (clockout.originalOut === undefined) clockout.originalOut = d.clock_out;

        // ① 日跨ぎ確認
        if (d.needs_overnight_confirm && !clockout.overnightConfirmed) {
            $('#mat-overnight-text').html(
                esc(d.target_date_label) + ' ' + esc(d.clock_in) + ' に出勤しています。<br>'
                + 'この退勤を「' + esc(d.target_date_label) + '」の退勤として登録します。よろしいですか？'
            );
            showModal('#mat-overnight-modal', d);
            return;
        }

        // ② 例外休憩
        if (d.needs_break_confirm && clockout.breakReason === '' && !clockout.breakFixed) {
            $('#mat-be-selected').text(d.break_minutes + '分');
            $('#mat-be-standard').text(d.standard_break + '分'
                + (d.standard_label ? '（' + d.standard_label + '）' : ''));
            $('#mat-be-fix-label').text(d.standard_break + '分');
            $('#mat-be-reason').val('');
            $('input[name="mat-be-choice"][value="request"]').prop('checked', true);
            clearError('mat-be-error');
            showModal('#mat-break-exception-modal', d);
            return;
        }

        // ③ 残業確認
        if (d.needs_overtime_confirm && clockout.overtimeReason === '') {
            $('#mat-ot-summary').html(
                kvRow('始業 / 終業', esc(d.rounded_in) + ' 〜 ' + esc(d.rounded_out))
                + kvRow('休憩', esc(d.break_minutes) + '分')
                + kvRow('労働時間', esc(d.labor_text))
                + kvRow('残業時間', '<span class="mat-kv-strong">' + esc(d.overtime_text) + '</span>')
            );
            $('#mat-ot-time').val(d.clock_out.length === 5 && parseInt(d.clock_out, 10) < 24 ? d.clock_out : '');
            $('#mat-ot-reason').val('');
            $('input[name="mat-ot-choice"][value="request"]').prop('checked', true);
            clearError('mat-ot-error');
            showModal('#mat-overtime-modal', d);
            return;
        }

        // ④ 深夜休憩確認（③で退勤時刻が修正された後の値で判定する）
        if (d.needs_midnight_confirm && clockout.midnightBreak === null) {
            openMidnightModal(d);
            return;
        }

        submitClockout();
    }

    function submitClockout() {
        $.post(ajaxurl, {
            action: 'mat_attendance_update',
            emp_master_id: session.empMasterId,
            employee_code: session.employeeCode,
            label: '退勤',
            target_date: clockout.targetDate,
            clock_out_override: clockout.override,
            clock_out_original: clockout.originalOut || '',
            break_master_id: clockout.breakMasterId,
            break_reason: clockout.breakReason,
            overtime_reason: clockout.overtimeReason,
            midnight_break_minutes: clockout.midnightBreak === null ? '' : clockout.midnightBreak,
            midnight_break_reason: clockout.midnightReason,
            nonce: nonce,
        }, function (res) {
            endClockoutFlow();
            if (res.success) {
                showToast('退勤を登録しました ✓', 'success');
                // 日跨ぎ退勤では前月の行を更新することがあるため、表示中の月で読み直す
                loadLogs();
                refreshPunchButtons();
            } else {
                showToast(res.data, 'error');
                alert('エラー: ' + res.data);
            }
        }).fail(function () {
            endClockoutFlow();
            alert('通信エラーが発生しました。');
        });
    }

    // ---- ① 日跨ぎ ----
    $('#mat-overnight-ok').on('click', function () {
        var d = $('#mat-overnight-modal').data('prepared') || {};
        clockout.overnightConfirmed = true;
        clockout.targetDate = d.target_date || clockout.targetDate;
        $('#mat-overnight-modal').fadeOut(150);
        prepareClockout();
    });
    $('#mat-overnight-cancel').on('click', function () {
        $('#mat-overnight-modal').fadeOut(150);
        endClockoutFlow();
    });

    // ---- ② 例外休憩 ----
    $('#mat-be-ok').on('click', function () {
        var d = $('#mat-break-exception-modal').data('prepared') || {};
        var choice = $('input[name="mat-be-choice"]:checked').val();

        if (choice === 'request') {
            var reason = $.trim($('#mat-be-reason').val());
            if (!reason) { setError('mat-be-error', '申請理由を入力してください。'); return; }
            clockout.breakReason = reason;
        } else {
            // 基準の休憩時間に修正して登録（申請レコードは作らない）
            clockout.breakMasterId = d.standard_master_id || clockout.breakMasterId;
            clockout.breakFixed = true;
            clockout.breakReason = '';
        }

        $('#mat-break-exception-modal').fadeOut(150);
        // 休憩を変えると残業判定も変わるため再判定する
        prepareClockout();
    });
    $('#mat-be-cancel').on('click', function () {
        $('#mat-break-exception-modal').fadeOut(150);
        endClockoutFlow();
    });

    // ---- ③ 残業 ----
    $('#mat-ot-ok').on('click', function () {
        var choice = $('input[name="mat-ot-choice"]:checked').val();

        if (choice === 'request') {
            var reason = $.trim($('#mat-ot-reason').val());
            if (!reason) { setError('mat-ot-error', '申請理由を入力してください。'); return; }
            clockout.overtimeReason = reason;
            $('#mat-overtime-modal').fadeOut(150);
            submitClockout();
            return;
        }

        var newTime = $('#mat-ot-time').val();
        if (!newTime) { setError('mat-ot-error', '修正後の退勤時刻を入力してください。'); return; }
        clockout.override = newTime;
        $('#mat-overtime-modal').fadeOut(150);
        // 修正後に再度①〜④の判定を行う
        prepareClockout();
    });
    $('#mat-ot-cancel').on('click', function () {
        $('#mat-overtime-modal').fadeOut(150);
        endClockoutFlow();
    });

    // ---- ④ 深夜休憩 ----
    function openMidnightModal(d) {
        lastMidnightData = d;
        $('#mat-mn-summary').html(
            kvRow('深夜該当時間', esc(d.midnight_window_label) + ' のうち ' + esc(d.midnight_span_text))
            + kvRow('本日の休憩', esc(d.break_minutes) + '分')
        );
        $('#mat-mn-window').text(d.midnight_window_label);
        $('#mat-mn-minutes').val('');
        $('#mat-mn-none-box').hide();
        $('#mat-mn-none-reason').val('');
        clearError('mat-mn-error');
        showModal('#mat-midnight-modal', d);
    }

    $('#mat-mn-register').on('click', function () {
        clearError('mat-mn-error');
        var d = $('#mat-midnight-modal').data('prepared') || lastMidnightData || {};
        var raw = $.trim($('#mat-mn-minutes').val());

        if (raw === '' || !/^\d+$/.test(raw)) {
            setError('mat-mn-error', '深夜休憩の分数を入力してください。');
            return;
        }
        var minutes = parseInt(raw, 10);

        if (typeof d.midnight_span === 'number' && minutes > d.midnight_span) {
            setError('mat-mn-error', '深夜休憩は深夜該当時間（' + esc(d.midnight_span_text) + '）を超えられません。');
            return;
        }
        if (minutes > (d.break_minutes || 0)) {
            $('#mat-midnight-modal').fadeOut(150);
            showMidnightRescue(minutes, d.break_minutes || 0);
            return;
        }

        clockout.midnightBreak = minutes;
        clockout.midnightReason = '';
        $('#mat-midnight-modal').fadeOut(150);
        submitClockout();
    });

    $('#mat-mn-none-toggle').on('click', function () {
        clearError('mat-mn-error');
        $('#mat-mn-none-box').show();
    });

    $('#mat-mn-none-ok').on('click', function () {
        var reason = $.trim($('#mat-mn-none-reason').val());
        if (!reason) { setError('mat-mn-error', '深夜時間に休憩を取らなかった理由を入力してください。'); return; }

        clockout.midnightBreak = 0;
        clockout.midnightReason = reason;
        $('#mat-midnight-modal').fadeOut(150);
        submitClockout();
    });

    $('#mat-mn-cancel').on('click', function () {
        $('#mat-midnight-modal').fadeOut(150);
        endClockoutFlow();
    });

    // ---- ④ 救済フロー（深夜休憩が本日の休憩を超えている場合） ----
    function showMidnightRescue(enteredMinutes, currentBreakMinutes) {
        var candidates = breakSteps.filter(function (s) { return s.minutes >= enteredMinutes; });
        candidates.sort(function (a, b) { return a.minutes - b.minutes; });
        var best = candidates.length ? candidates[0] : null;

        $('#mat-mnr-summary').html(
            '<p>深夜休憩（' + esc(enteredMinutes) + '分）が本日の休憩（' + esc(currentBreakMinutes) + '分）を超えています。</p>'
            + '<p>深夜休憩は休憩時間の内数です。本日の休憩合計が' + esc(enteredMinutes) + '分以上である必要があります。</p>'
        );

        if (best) {
            $('#mat-mnr-fix').show().text('本日の休憩を' + best.minutes + '分に修正して続ける').data('step-id', best.id);
            $('#mat-mnr-note').hide();
        } else {
            $('#mat-mnr-fix').hide();
            $('#mat-mnr-note').text('該当する休憩時間の設定がありません。管理者にご連絡ください。').show();
        }

        showModal('#mat-midnight-rescue-modal');
    }

    $('#mat-mnr-fix').on('click', function () {
        var stepId = $(this).data('step-id');
        if (stepId) clockout.breakMasterId = stepId;
        // 休憩額が変わるため、②③④はすべて再判定させる
        clockout.breakReason = '';
        clockout.breakFixed = false;
        clockout.midnightBreak = null;
        clockout.midnightReason = '';
        $('#mat-midnight-rescue-modal').fadeOut(150);
        prepareClockout();
    });

    $('#mat-mnr-retry').on('click', function () {
        $('#mat-midnight-rescue-modal').fadeOut(150);
        if (lastMidnightData) openMidnightModal(lastMidnightData);
    });

    // 備考のみ保存
    $(document).on('click', '#mat-btn-save-note', function () {
        if (isSubmitting) return;
        if (!session.empMasterId) { alert('ログインしてください。'); return; }

        var note = $.trim($('#mat-note').val());
        if (!note) { showToast('備考を入力してください。', 'error'); return; }

        if (session.hasNote) {
            if (!confirm('すでに備考が登録されています。上書きしますか？')) return;
        }

        var $btn = $(this);
        btnLoading($btn, true);
        isSubmitting = true;

        $.post(ajaxurl, {
            action: 'mat_save_note',
            emp_master_id: session.empMasterId,
            employee_code: session.employeeCode,
            note: note,
            nonce: nonce,
        }, function (res) {
            btnLoading($btn, false);
            isSubmitting = false;

            if (res.success) {
                showToast('備考を登録しました ✓', 'success');
                $('#mat-note').val('');
                renderLogs(res.data);
                refreshPunchButtons();
            } else {
                showToast(res.data, 'error');
                alert('エラー: ' + res.data);
            }
        }).fail(function () {
            btnLoading($btn, false);
            isSubmitting = false;
            alert('通信エラーが発生しました。');
        });
    });

    function applyPunchButtons(status) {
        if (!status) return;

        var hasClockin = !!status.has_clockin;
        var hasClockout = !!status.has_clockout;
        var isHoliday = !!status.is_holiday;
        // 前日の退勤が未完了で正午以前なら、当日に出勤打刻が無くても退勤を押せる
        var pendingOvernight = !!status.pending_overnight;
        session.hasBreak = !!status.has_break_time;
        session.hasNote = !!status.has_notes;

        var $btnIn = $('.mat-wrap [data-label="出勤"]');
        var $btnOut = $('.mat-wrap [data-label="退勤"]');

        if (isHoliday || hasClockin) {
            $btnIn.prop('disabled', true).text(isHoliday ? '休日登録済' : '出勤済み').css('opacity', '0.5');
        } else {
            $btnIn.prop('disabled', false).text('出勤').css('opacity', '1');
        }

        if (isHoliday || (!hasClockin && !pendingOvernight) || hasClockout) {
            $btnOut.prop('disabled', true).text(hasClockout ? '退勤済み' : '退勤').css('opacity', '0.5');
        } else {
            $btnOut.prop('disabled', false).text('退勤').css('opacity', '1');
        }
    }

    function refreshPunchButtons() {
        if (!session.empMasterId) return;
        $.post(ajaxurl, {
            action: 'mat_get_today_status',
            emp_master_id: session.empMasterId,
            nonce: nonce,
        }, function (res) {
            if (res.success) {
                if (res.data.today_ymd) matAjax.todayYmd = res.data.today_ymd;
                applyPunchButtons(res.data);
            }
        });
    }

    // 休日登録
    $(document).on('click', '#mat-btn-register-holiday', function () {
        var holidayDate = $('#mat-holiday-date').val();
        clearError('mat-error-holiday');
        clearSuccess('mat-success-holiday');

        if (!holidayDate) { setError('mat-error-holiday', '日付を選択してください。'); return; }
        if (!session.empMasterId) { setError('mat-error-holiday', 'ログインしてください。'); return; }

        var $btn = $(this);
        btnLoading($btn, true);

        $.post(ajaxurl, {
            action: 'mat_register_holiday',
            emp_master_id: session.empMasterId,
            employee_code: session.employeeCode,
            holiday_date: holidayDate,
            nonce: nonce,
        }, function (res) {
            btnLoading($btn, false);
            if (res.success) {
                $('#mat-holiday-date').val('');
                setSuccess('mat-success-holiday', '休日として登録しました。');
                var registeredMonth = holidayDate.substring(0, 7);
                var viewingMonth = $('#mat-view-month').val() || getCurrentYearMonth();
                if (registeredMonth === viewingMonth) { renderLogs(res.data); } else { refreshPunchButtons(); }
                setTimeout(function () { clearSuccess('mat-success-holiday'); }, 3000);
            } else {
                setError('mat-error-holiday', 'エラー: ' + res.data);
            }
        }).fail(function () {
            btnLoading($btn, false);
            setError('mat-error-holiday', '通信エラーが発生しました。');
        });
    });

    // 有給希望申請
    $('#mat-btn-paid-leave').on('click', function () {
        var paidDate = $('#mat-paid-leave-date').val();
        clearError('mat-error-paid-leave');
        if (!paidDate) { setError('mat-error-paid-leave', '有給希望日を選択してください。'); return; }

        var $btn = $(this);
        btnLoading($btn, true);

        $.post(ajaxurl, {
            action: 'mat_submit_paid_leave',
            emp_master_id: session.empMasterId,
            employee_code: session.employeeCode,
            paid_leave_date: paidDate,
            nonce: nonce,
        }, function (res) {
            btnLoading($btn, false);
            if (res.success) {
                $('#mat-paid-leave-date').val('');
                renderPaidLeaveRequests(res.data);
            } else {
                setError('mat-error-paid-leave', 'エラー: ' + res.data);
            }
        }).fail(function () {
            btnLoading($btn, false);
            setError('mat-error-paid-leave', '通信エラーが発生しました。');
        });
    });

    $('#mat-paid-leave-date').on('change', function () {
        if ($(this).val()) { $(this).attr('data-has-value', '1'); } else { $(this).removeAttr('data-has-value'); }
    });

    function loadPaidLeaveRequests() {
        $('#mat-paid-leave-body').html('<tr><td colspan="3" class="mat-loading">読み込み中...</td></tr>');
        $.post(ajaxurl, {
            action: 'mat_get_paid_leave_requests',
            employee_code: session.employeeCode,
            nonce: nonce,
        }, function (res) {
            if (res.success) { renderPaidLeaveRequests(res.data); } else {
                $('#mat-paid-leave-body').html('<tr><td colspan="3" style="text-align:center;padding:12px;color:#999;">取得できませんでした。</td></tr>');
            }
        });
    }

    function renderPaidLeaveRequests(data) {
        var requests = data.requests || [];
        if (requests.length === 0) {
            $('#mat-paid-leave-body').html('<tr><td colspan="3" class="mat-loading">申請はありません。</td></tr>');
            return;
        }
        var statusClass = { 'pending': 'mat-status-pending', 'approved': 'mat-status-approved', 'rejected': 'mat-status-rejected' };
        var html = '';
        $.each(requests, function (_, r) {
            var cls = statusClass[r.status_key] || '';
            html += '<tr><td>' + esc(r.request_date) + '</td><td>' + esc(r.paid_leave_date) + '</td><td><span class="mat-status-badge ' + cls + '">' + esc(r.status) + '</span></td></tr>';
        });
        $('#mat-paid-leave-body').html(html);
    }

    function loadLogs() {
        var month = $('#mat-view-month').val() || getCurrentYearMonth();
        $('#mat-history-body').html('<tr><td colspan="8" class="mat-loading">読み込み中...</td></tr>');
        $.post(ajaxurl, {
            action: 'mat_get_logs',
            emp_master_id: session.empMasterId,
            month: month,
            nonce: nonce,
        }, function (res) {
            if (res.success) { renderLogs(res.data); } else {
                $('#mat-history-body').html('<tr><td colspan="8" style="text-align:center;padding:16px;color:#999;">取得できませんでした。</td></tr>');
            }
        });
    }

    function renderLogs(data) {
        if (data && data.today_ymd) matAjax.todayYmd = data.today_ymd;
        if (!data.logs || data.logs.length === 0) {
            $('#mat-history-body').html('<tr><td colspan="8" class="mat-loading">データがありません。</td></tr>');
            refreshPunchButtons();
            return;
        }

        var html = '';
        $.each(data.logs, function (_, row) {
            var isHoliday = !!row.is_holiday;
            var hasData = !!row.has_data;
            var rowStyle = isHoliday ? ' style="background:#fff8e1;"' : !hasData ? ' style="background:#fafafa;color:#bbb;"' : '';

            html += '<tr data-id="' + row.id + '"' + rowStyle + '>';
            html += '<td>' + esc(row.date) + '</td>';

            if (isHoliday) {
                html += '<td>-</td><td>-</td><td>-</td><td>-</td><td>-</td><td style="text-align:center;font-size:.9em;">🗓 休日</td>';
                if (allowLogEdit) html += '<td style="color:#ccc;font-size:.8em;">-</td>';
            } else {
                html += '<td>' + esc(row.in || '-') + '</td>';
                // 24時以降の退勤は 25:10 のように24時間超表記＋識別マークを付ける
                var outText = row.out ? esc(row.out) + (row.is_overnight ? ' <span class="mat-overnight-mark" title="日跨ぎ">⏰</span>' : '') : '-';
                html += '<td>' + outText + '</td>';
                html += '<td>' + esc(row.break || '-') + '</td>';
                // 深夜休憩が未確認（NULL）かつ深夜該当時間がある行は識別マークを付ける
                var midnightText = row.midnight
                    ? esc(row.midnight) + (row.midnight_unconfirmed ? ' <span class="mat-midnight-mark" title="深夜休憩未確認">⚠</span>' : '')
                    : '-';
                html += '<td>' + midnightText + '</td>';
                var notes = Array.isArray(row.notes) ? row.notes.join(' / ') : '';
                html += '<td style="text-align:left;">' + esc(notes) + '</td>';
                html += '<td style="color:#ccc;font-size:.8em;">-</td>';

                if (allowLogEdit) {
                    if (row.can_edit) {
                        html += '<td><button class="mat-btn-sm mat-edit-btn" data-id="' + row.id + '" data-in="' + esc(row.in || '') + '" data-out="' + esc(row.out || '') + '" data-break="' + esc(row.break || '') + '" data-notes="' + esc(notes) + '">編集</button></td>';
                    } else {
                        html += '<td style="color:#ccc;font-size:.8em;">-</td>';
                    }
                }
            }
            html += '</tr>';
        });

        $('#mat-history-body').html(html);
        refreshPunchButtons();
    }

    $('#mat-view-month').on('change', function () {
        if (session.empMasterId) loadLogs();
    });

    // 打刻編集モーダル（深夜休憩は §6.6 により編集対象外のため、この画面には出さない）
    $(document).on('click', '.mat-edit-btn', function () {
        editTargetId = $(this).data('id');
        $('#mat-edit-in').val($(this).data('in') || '');
        $('#mat-edit-out').val($(this).data('out') || '');
        $('#mat-edit-break').val($(this).data('break') || '00:00');
        $('#mat-edit-note').val($(this).data('notes') || '');
        clearError('mat-edit-error');
        $('#mat-edit-modal').fadeIn(150);
    });

    $('#mat-edit-cancel').on('click', function () { $('#mat-edit-modal').fadeOut(150); editTargetId = null; });
    $('#mat-edit-modal').on('click', function (e) { if ($(e.target).is('#mat-edit-modal')) { $(this).fadeOut(150); editTargetId = null; } });

    $('#mat-edit-save').on('click', function () {
        if (!editTargetId) return;
        clearError('mat-edit-error');
        btnLoading($(this), true);

        $.post(ajaxurl, {
            action: 'mat_edit_log',
            id: editTargetId,
            emp_master_id: session.empMasterId,
            clock_in: $('#mat-edit-in').val(),
            clock_out: $('#mat-edit-out').val(),
            break_time: $('#mat-edit-break').val(),
            note: $('#mat-edit-note').val(),
            nonce: nonce,
        }, function (res) {
            btnLoading($('#mat-edit-save'), false);
            if (res.success) { $('#mat-edit-modal').fadeOut(150); editTargetId = null; loadLogs(); } else { setError('mat-edit-error', res.data); }
        }).fail(function () {
            btnLoading($('#mat-edit-save'), false);
            setError('mat-edit-error', '通信エラーが発生しました。');
        });
    });

    $('#mat-edit-delete').on('click', function () {
        if (!editTargetId) return;
        if (!confirm('この日のデータを完全に削除しますか？')) return;

        var $btn = $(this);
        btnLoading($btn, true);

        $.post(ajaxurl, {
            action: 'mat_delete_log',
            id: editTargetId,
            emp_master_id: session.empMasterId,
            nonce: nonce,
        }, function (res) {
            btnLoading($btn, false);
            if (res.success) { $('#mat-edit-modal').fadeOut(150); editTargetId = null; loadLogs(); } else { alert('削除に失敗しました: ' + res.data); }
        });
    });
});
