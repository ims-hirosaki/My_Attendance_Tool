jQuery(document).ready(function ($) {

    // =========================================================
    //  設定・グローバル状態
    // =========================================================
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

    // =========================================================
    //  時計
    // =========================================================
    function tickClock() {
        var now = new Date();
        var h = String(now.getHours()).padStart(2, '0');
        var m = String(now.getMinutes()).padStart(2, '0');
        var s = String(now.getSeconds()).padStart(2, '0');
        $('#mat-clock').text(h + ':' + m + ':' + s);
    }
    tickClock();
    setInterval(tickClock, 1000);

    // =========================================================
    //  ユーティリティ
    // =========================================================
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
            $btn.prop('disabled', true)
                .data('original-text', $btn.text())
                .text('処理中...');
        } else {
            $btn.prop('disabled', false)
                .text($btn.data('original-text') || $btn.text());
        }
    }
    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function getCurrentYearMonth() {
        var now = new Date();
        return now.getFullYear() + '-'
            + String(now.getMonth() + 1).padStart(2, '0');
    }

    // =========================================================
    //  休憩スライダー
    // =========================================================
    $('#mat-break-slider').on('input', function () {
        $('#mat-break-display').text(minsToHHMM($(this).val()));
    });

    // =========================================================
    //  社員コード認証
    //  → ajax-handlers.php の mat_check_employee に合わせる
    //    status: 'needs_setup' / 'needs_password' / 'logged_in'
    // =========================================================
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

    // =========================================================
    //  パスワード新規設定
    //  → mat_setup_password
    // =========================================================
    $('#mat-btn-set-password').on('click', function () {
        var pw1 = $('#mat-new-password').val();
        var pw2 = $('#mat-new-password2').val();
        clearError('mat-error-set-password');

        if (pw1.length < 4) {
            setError('mat-error-set-password', 'パスワードは4文字以上で入力してください。');
            return;
        }
        if (pw1 !== pw2) {
            setError('mat-error-set-password', 'パスワードが一致しません。');
            return;
        }

        btnLoading($(this), true);

        $.post(ajaxurl, {
            action: 'mat_setup_password',
            employee_code: session.employeeCode,
            password: pw1,
            nonce: nonce,
        }, function (res) {
            btnLoading($('#mat-btn-set-password'), false);
            if (!res.success) {
                setError('mat-error-set-password', res.data);
                return;
            }
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

    // =========================================================
    //  パスワードログイン
    //  → mat_verify_password
    // =========================================================
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
            if (!res.success) {
                setError('mat-error-login', res.data);
                return;
            }
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

    // =========================================================
    //  パスワードリセット申請
    //  → mat_request_password_reset
    // =========================================================
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

    // =========================================================
    //  「戻る」リンク
    // =========================================================
    $('#mat-back-to-code-from-setpw, #mat-back-to-code-from-login, #mat-back-to-code-from-reset')
        .on('click', function (e) {
            e.preventDefault();
            session = { empMasterId: 0, employeeCode: '', userName: '', hasBreak: false, hasNote: false };
            $('#mat-employee-code').val('');
            clearError('mat-error-code');
            showSection('mat-section-code');
        });

    // =========================================================
    //  ログイン完了後の処理
    // =========================================================
    function onLoginComplete() {
        $('#mat-user-name').text(session.userName);
        showSection('mat-section-main');
        loadLogs();
        if (showPaidLeave) {
            loadPaidLeaveRequests();
        }
        refreshPunchButtons();
    }

    // =========================================================
    //  ログアウト
    // =========================================================
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

    // =========================================================
    //  打刻処理（出勤・退勤・休憩）
    // =========================================================
    $(document).on('click', '.mat-punch-btn', function () {
        if (isSubmitting) return;

        var label = $(this).data('label');

        if (!session.empMasterId) {
            alert('ログインしてください。');
            return;
        }

        if (label === '休憩' && session.hasBreak) {
            if (!confirm('すでに休憩が登録されています。上書きしますか？')) return;
        }

        var postData = {
            action: 'mat_attendance_update',
            emp_master_id: session.empMasterId,
            employee_code: session.employeeCode,
            label: label,
            nonce: nonce,
        };

        if (label === '休憩') {
            postData.break_hhmm = minsToHHMM($('#mat-break-slider').val());
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

    // =========================================================
    //  備考のみ登録（上書き保存）
    //  → mat_save_note
    // =========================================================
    $(document).on('click', '#mat-btn-save-note', function () {
        if (isSubmitting) return;

        if (!session.empMasterId) {
            alert('ログインしてください。');
            return;
        }

        var note = $.trim($('#mat-note').val());
        if (!note) {
            showToast('備考を入力してください。', 'error');
            return;
        }

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

    // =========================================================
    //  打刻ボタンの活性状態を更新
    // =========================================================
    function applyPunchButtons(status) {
        if (!status) return;

        var hasClockin  = !!status.has_clockin;
        var hasClockout = !!status.has_clockout;
        var isHoliday   = !!status.is_holiday;
        session.hasBreak = !!status.has_break_time;
        session.hasNote  = !!status.has_notes;

        var $btnIn  = $('.mat-wrap [data-label="出勤"]');
        var $btnOut = $('.mat-wrap [data-label="退勤"]');

        if (isHoliday || hasClockin) {
            $btnIn.prop('disabled', true)
                  .text(isHoliday ? '休日登録済' : '出勤済み')
                  .css('opacity', '0.5');
        } else {
            $btnIn.prop('disabled', false).text('出勤').css('opacity', '1');
        }

        if (isHoliday || !hasClockin || hasClockout) {
            $btnOut.prop('disabled', true)
                   .text(hasClockout ? '退勤済み' : '退勤')
                   .css('opacity', '0.5');
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

    // =========================================================
    //  休日登録
    // =========================================================
    $(document).on('click', '#mat-btn-register-holiday', function () {
        var holidayDate = $('#mat-holiday-date').val();
        clearError('mat-error-holiday');
        clearSuccess('mat-success-holiday');

        if (!holidayDate) {
            setError('mat-error-holiday', '日付を選択してください。');
            return;
        }
        if (!session.empMasterId) {
            setError('mat-error-holiday', 'ログインしてください。');
            return;
        }

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
                if (registeredMonth === viewingMonth) {
                    renderLogs(res.data);
                } else {
                    refreshPunchButtons();
                }
                setTimeout(function () { clearSuccess('mat-success-holiday'); }, 3000);
            } else {
                setError('mat-error-holiday', 'エラー: ' + res.data);
            }
        }).fail(function () {
            btnLoading($btn, false);
            setError('mat-error-holiday', '通信エラーが発生しました。');
        });
    });

    // =========================================================
    //  有給希望申請
    // =========================================================
    $('#mat-btn-paid-leave').on('click', function () {
        var paidDate = $('#mat-paid-leave-date').val();
        clearError('mat-error-paid-leave');

        if (!paidDate) {
            setError('mat-error-paid-leave', '有給希望日を選択してください。');
            return;
        }

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
        if ($(this).val()) {
            $(this).attr('data-has-value', '1');
        } else {
            $(this).removeAttr('data-has-value');
        }
    });

    // =========================================================
    //  有給申請一覧の取得・表示
    // =========================================================
    function loadPaidLeaveRequests() {
        $('#mat-paid-leave-body').html(
            '<tr><td colspan="3" class="mat-loading">読み込み中...</td></tr>'
        );
        $.post(ajaxurl, {
            action: 'mat_get_paid_leave_requests',
            employee_code: session.employeeCode,
            nonce: nonce,
        }, function (res) {
            if (res.success) {
                renderPaidLeaveRequests(res.data);
            } else {
                $('#mat-paid-leave-body').html(
                    '<tr><td colspan="3" style="text-align:center;padding:12px;color:#999;">取得できませんでした。</td></tr>'
                );
            }
        });
    }

    function renderPaidLeaveRequests(data) {
        var requests = data.requests || [];
        if (requests.length === 0) {
            $('#mat-paid-leave-body').html(
                '<tr><td colspan="3" class="mat-loading">申請はありません。</td></tr>'
            );
            return;
        }
        var statusClass = {
            'pending':  'mat-status-pending',
            'approved': 'mat-status-approved',
            'rejected': 'mat-status-rejected',
        };
        var html = '';
        $.each(requests, function (_, r) {
            var cls = statusClass[r.status_key] || '';
            html += '<tr>'
                + '<td>' + esc(r.request_date) + '</td>'
                + '<td>' + esc(r.paid_leave_date) + '</td>'
                + '<td><span class="mat-status-badge ' + cls + '">' + esc(r.status) + '</span></td>'
                + '</tr>';
        });
        $('#mat-paid-leave-body').html(html);
    }

    // =========================================================
    //  打刻履歴の取得・表示
    // =========================================================
    function loadLogs() {
        var month = $('#mat-view-month').val() || getCurrentYearMonth();
        $('#mat-history-body').html(
            '<tr><td colspan="7" class="mat-loading">読み込み中...</td></tr>'
        );
        $.post(ajaxurl, {
            action: 'mat_get_logs',
            emp_master_id: session.empMasterId,
            month: month,
            nonce: nonce,
        }, function (res) {
            if (res.success) {
                renderLogs(res.data);
            } else {
                $('#mat-history-body').html(
                    '<tr><td colspan="7" style="text-align:center;padding:16px;color:#999;">取得できませんでした。</td></tr>'
                );
            }
        });
    }

    function renderLogs(data) {
        if (data && data.today_ymd) matAjax.todayYmd = data.today_ymd;

        if (!data.logs || data.logs.length === 0) {
            $('#mat-history-body').html(
                '<tr><td colspan="7" class="mat-loading">データがありません。</td></tr>'
            );
            refreshPunchButtons();
            return;
        }

        var html = '';
        $.each(data.logs, function (_, row) {
            var isHoliday = !!row.is_holiday;
            var hasData   = !!row.has_data;
            var rowStyle  = isHoliday       ? ' style="background:#fff8e1;"'
                          : !hasData        ? ' style="background:#fafafa;color:#bbb;"'
                          : '';

            html += '<tr data-id="' + row.id + '"' + rowStyle + '>';
            html += '<td>' + esc(row.date) + '</td>';

            if (isHoliday) {
                html += '<td>-</td><td>-</td><td>-</td><td>-</td>';
                html += '<td style="text-align:center;font-size:.9em;">🗓 休日</td>';
                if (allowLogEdit) html += '<td style="color:#ccc;font-size:.8em;">-</td>';
            } else {
                html += '<td>' + esc(row.in  || '-') + '</td>';
                html += '<td>' + esc(row.out || '-') + '</td>';
                html += '<td>' + esc(row.break || '-') + '</td>';
                var notes = Array.isArray(row.notes) ? row.notes.join(' / ') : '';
                html += '<td style="text-align:left;">' + esc(notes) + '</td>';
                html += '<td style="color:#ccc;font-size:.8em;">-</td>';

                if (allowLogEdit) {
                    if (row.can_edit) {
                        html += '<td>'
                            + '<button class="mat-btn-sm mat-edit-btn"'
                            + ' data-id="'    + row.id + '"'
                            + ' data-in="'    + esc(row.in    || '') + '"'
                            + ' data-out="'   + esc(row.out   || '') + '"'
                            + ' data-break="' + esc(row.break || '') + '"'
                            + ' data-notes="' + esc(notes) + '"'
                            + '>編集</button></td>';
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

    // =========================================================
    //  打刻編集モーダル（社員側）
    // =========================================================
    $(document).on('click', '.mat-edit-btn', function () {
        editTargetId = $(this).data('id');
        $('#mat-edit-in').val($(this).data('in') || '');
        $('#mat-edit-out').val($(this).data('out') || '');
        $('#mat-edit-break').val($(this).data('break') || '00:00');
        $('#mat-edit-note').val($(this).data('notes') || '');
        clearError('mat-edit-error');
        $('#mat-edit-modal').fadeIn(150);
    });

    $('#mat-edit-cancel').on('click', function () {
        $('#mat-edit-modal').fadeOut(150);
        editTargetId = null;
    });

    $('#mat-edit-modal').on('click', function (e) {
        if ($(e.target).is('#mat-edit-modal')) {
            $(this).fadeOut(150);
            editTargetId = null;
        }
    });

    $('#mat-edit-save').on('click', function () {
        if (!editTargetId) return;
        clearError('mat-edit-error');
        btnLoading($(this), true);

        $.post(ajaxurl, {
            action: 'mat_edit_log',
            id: editTargetId,
            emp_master_id: session.empMasterId,
            clock_in:   $('#mat-edit-in').val(),
            clock_out:  $('#mat-edit-out').val(),
            break_time: $('#mat-edit-break').val(),
            note:       $('#mat-edit-note').val(),
            nonce: nonce,
        }, function (res) {
            btnLoading($('#mat-edit-save'), false);
            if (res.success) {
                $('#mat-edit-modal').fadeOut(150);
                editTargetId = null;
                loadLogs();
            } else {
                setError('mat-edit-error', res.data);
            }
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
            if (res.success) {
                $('#mat-edit-modal').fadeOut(150);
                editTargetId = null;
                loadLogs();
            } else {
                alert('削除に失敗しました: ' + res.data);
            }
        });
    });

});