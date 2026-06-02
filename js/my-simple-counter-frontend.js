// my-simple-counter/js/my-simple-counter-frontend.js

jQuery(document).ready(function($) {

    // --- 日付ピッカーの初期化 ---
    // my-counter-date-input クラスを持つ要素にDatepickerを適用する
    function initializeDatepicker(selector) {
        $(selector).each(function() {
            if (!$(this).hasClass('hasDatepicker')) {
                $(this).datepicker({
                    dateFormat: mySimpleCounterAjax.date_format,
                    dayNamesMin: mySimpleCounterAjax.day_names_min,
                    changeMonth: true,
                    changeYear: true
                });
            }
        });
    }
    initializeDatepicker('.my-counter-date-input'); // 他のタブでの日付入力は残る

    // --- 日付入力フィールドの曜日表示を更新 ---
    $(document).on('change', '.my-counter-date-input', function() {
        var dateString = $(this).val();
        var dateParts = dateString.split('/');
        // YYYY/MM/DD 形式から Date オブジェクトを作成 (月は0-11)
        var date = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);

        var dayOfWeek = date.getDay(); // 0 (日曜日)から 6 (土曜日)
        var dayNames = ['日', '月', '火', '水', '木', '金', '土'];
        $(this).siblings('.my-counter-weekday-display').text(' (' + dayNames[dayOfWeek] + ')');
    });

    // 初期ロード時に日付入力フィールドの曜日を更新（今日の日付で）
    $('.my-counter-date-input').each(function() {
        var $this = $(this);
        var dateString = $this.val();
        if (dateString) {
            var dateParts = dateString.split('/');
            var date = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);
            var dayOfWeek = date.getDay();
            var dayNames = ['日', '月', '火', '水', '木', '金', '土'];
            $this.siblings('.my-counter-weekday-display').text(' (' + dayNames[dayOfWeek] + ')');
        }
    });

    // --- カウンター更新のAJAXハンドラ ---
    $(document).on('click', '.my-counter-button', function() {
        var $button = $(this);
        var $cell = $button.closest('.my-counter-cell');
        var itemName = $cell.data('item-name');
        var actionType = $button.data('action'); // 'increment'
        var $currentCountDisplay = $cell.find('.my-counter-display');
        var logDate = $cell.find('.my-counter-date-input').val(); // YYYY/MM/DD形式で取得
        var registeredUserId = $cell.find('.my-counter-user-select').val();

        $('.my-counter-ajax-message').hide().text('');
        $('.my-counter-error-message').hide().text('');

        $.ajax({
            url: mySimpleCounterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'my_simple_counter_update',
                item_name: itemName,
                action_type: actionType,
                log_date: logDate,
                registered_user_id: registeredUserId,
                nonce: mySimpleCounterAjax.update_nonce
            },
            success: function(response) {
                if (response.success) {
                    $currentCountDisplay.text(response.data.new_count);
                    $('.my-counter-ajax-message').text('カウントを更新しました！').fadeIn();
                    // ログ表示タブがアクティブな場合は、表示を更新
                    if ($('.my-counter-summary-tabs .tab-button.active').data('tab') === 'detailed-log-tab') {
                        // 現在表示している月を再取得して表示を更新
                        fetchAndDisplayLog(window.currentLogDate);
                    }
                } else {
                    $('.my-counter-error-message').text('エラー: ' + (response.data.message || '不明なエラー')).fadeIn();
                }
            },
            error: function() {
                $('.my-counter-error-message').text('通信エラーが発生しました。').fadeIn();
            }
        });
    });

    // --- タブ切り替え ---
    $(document).on('click', '.my-counter-summary-tabs .tab-button', function() {
        var targetTab = $(this).data('tab');

        $('.my-counter-summary-tabs .tab-button').removeClass('active');
        $(this).addClass('active');

        $('.my-counter-summary-tab-content').hide();
        $('#' + targetTab).show();

        // 実施履歴タブがアクティブになったら、現在の月のログをロード
        if (targetTab === 'detailed-log-tab') {
            // currentLogDate が定義されていない可能性があるのでチェック
            if (typeof window.currentLogDate === 'undefined') {
                window.currentLogDate = new Date(); // 初期化
            }
            fetchAndDisplayLog(window.currentLogDate);
        }
    });

    // 初期ロード時に最初のタブをアクティブにする (CSSで制御されている場合もあるが、JSで確実に)
    // .tab-button.active が存在しない場合を考慮
    if ($('.my-counter-summary-tabs .tab-button.active').length === 0) {
        $('.my-counter-summary-tabs .tab-button:first').addClass('active');
        $('.my-counter-summary-tab-content:first').show();
    }

    // --- 月別集計ボタンのイベントハンドラ ---
    $(document).on('click', '#fetch_monthly_summary_button', function() {
        var year = $('#monthly_summary_year').val();
        var month = $('#monthly_summary_month').val();

        $('.my-counter-ajax-message').hide().text('');
        $('.my-counter-error-message').hide().text('');

        $.ajax({
            url: mySimpleCounterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'my_simple_counter_fetch_monthly_summary',
                year: year,
                month: month
            },
            success: function(response) {
                if (response.success) {
                    $('#monthly-summary .my-counter-summary-inner').html(response.data.html);
                } else {
                    $('.my-counter-error-message').text('エラー: ' + (response.data.message || '不明なエラー')).fadeIn();
                }
            },
            error: function() {
                $('.my-counter-error-message').text('通信エラーが発生しました。').fadeIn();
            }
        });
    });

    // 初期ロード時に月別集計を表示
    $('#fetch_monthly_summary_button').click();


    // --- 年別集計ボタンのイベントハンドラ ---
    $(document).on('click', '#fetch_yearly_summary_button', function() {
        var year = $('#yearly_summary_year').val();

        $('.my-counter-ajax-message').hide().text('');
        $('.my-counter-error-message').hide().text('');

        $.ajax({
            url: mySimpleCounterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'my_simple_counter_fetch_yearly_summary',
                year: year
            },
            success: function(response) {
                if (response.success) {
                    $('#yearly-summary .my-counter-summary-inner').html(response.data.html);
                } else {
                    $('.my-counter-error-message').text('エラー: ' + (response.data.message || '不明なエラー')).fadeIn();
                }
            },
            error: function() {
                $('.my-counter-error-message').text('通信エラーが発生しました。').fadeIn();
            }
        });
    });

    // 初期ロード時に年別集計を表示
    $('#fetch_yearly_summary_button').click();

    // --- 期間指定集計のイベントハンドラ ---
    $(document).on('click', '#fetch_period_summary_button', function() {
        var startDate = $('#period_start_date').val();
        var endDate = $('#period_end_date').val();

        $('.my-counter-ajax-message').hide().text('');
        $('.my-counter-error-message').hide().text('');

        $.ajax({
            url: mySimpleCounterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'my_simple_counter_fetch_period_summary',
                start_date: startDate,
                end_date: endDate
            },
            success: function(response) {
                if (response.success) {
                    $('#period-summary .my-counter-period-summary-results').html(response.data.html);
                } else {
                    $('.my-counter-error-message').text('エラー: ' + (response.data.message || '不明なエラー')).fadeIn();
                }
            },
            error: function() {
                $('.my-counter-error-message').text('通信エラーが発生しました。').fadeIn();
            }
        });
    });

    // --- 実施履歴表示ロジック ---
    // 現在表示しているログの月を保持するDateオブジェクト
    window.currentLogDate = new Date(); // 初期値は現在の日付

    // ログの月表示を更新する関数
    function updateLogMonthDisplay(date) {
        var year = date.getFullYear();
        var month = date.getMonth() + 1; // getMonth()は0-11を返すため+1
        $('#current_log_month_display').text(year + '年' + month + '月');
    }

    // ログを取得して表示を更新する関数
    function fetchAndDisplayLog(date) {
        var year = date.getFullYear();
        var month = date.getMonth(); // getMonth()は0-11
        var firstDay = new Date(year, month, 1);
        var lastDay = new Date(year, month + 1, 0); // 翌月の0日は当月の最終日

        var startDateStr = firstDay.getFullYear() + '/' + (firstDay.getMonth() + 1).toString().padStart(2, '0') + '/' + firstDay.getDate().toString().padStart(2, '0');
        var endDateStr = lastDay.getFullYear() + '/' + (lastDay.getMonth() + 1).toString().padStart(2, '0') + '/' + lastDay.getDate().toString().padStart(2, '0');

        $('.my-counter-ajax-message').hide().text('');
        $('.my-counter-error-message').hide().text('');
        // ログを読み込む前に削除ボタンを非表示にする
        // 管理者権限に依らず、ログがない場合は非表示にしたいので、一旦ここで非表示
        $('#delete_selected_logs_button').hide();

        $.ajax({
            url: mySimpleCounterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'my_simple_counter_fetch_detailed_log',
                start_date: startDateStr,
                end_date: endDateStr
            },
            success: function(response) {
                if (response.success) {
                    var logData = response.data.log_data;
                    var logHtml = '';

                    if (logData.length > 0) {
                        logHtml += '<table class="wp-list-table widefat striped fixed my-counter-log-table">';
                        logHtml += '<thead><tr><th><input type="checkbox" id="select_all_logs"></th><th>日時</th><th>曜日</th><th>項目名</th><th>担当者</th></tr></thead>';
                        logHtml += '<tbody>';
                        $.each(logData, function(index, log) {
                            logHtml += '<tr>';
                            // **** 変更点: 管理者権限のチェックを削除し、ログインユーザーならチェックボックスを表示 ****
                            // if (mySimpleCounterAjax.is_admin) {
                            logHtml += '<td><input type="checkbox" class="log-checkbox" value="' + log.id + '"></td>';
                            // } else {
                            //     logHtml += '<td></td>'; // 管理者以外は空セル
                            // }
                            logHtml += '<td>' + log.formatted_timestamp + '</td>';
                            logHtml += '<td>' + log.day_of_week_jp + '</td>';
                            logHtml += '<td>' + log.item_name + '</td>';
                            logHtml += '<td>' + log.registered_user_name + '</td>';
                            logHtml += '</tr>';
                        });
                        logHtml += '</tbody></table>';
                        // ログが存在する場合、削除ボタンを表示
                        // **** 変更点: 管理者権限のチェックを削除し、常に削除ボタンを表示 ****
                        // if (mySimpleCounterAjax.is_admin) {
                            $('#delete_selected_logs_button').show();
                        // }
                    } else {
                        logHtml = '<p>この月には実施記録がありません。</p>';
                    }
                    $('#detailed-log-tab .my-counter-detailed-log-inner').html(logHtml);
                    updateLogMonthDisplay(date); // 月表示を更新
                } else {
                    $('.my-counter-error-message').text('エラー: ' + (response.data.message || '不明なエラー')).fadeIn();
                }
            },
            error: function() {
                $('.my-counter-error-message').text('通信エラーが発生しました。').fadeIn();
            }
        });
    }

    // 「前月」ボタンのクリックイベント
    $(document).on('click', '#prev_month_log_button', function() {
        window.currentLogDate.setMonth(window.currentLogDate.getMonth() - 1);
        fetchAndDisplayLog(window.currentLogDate);
    });

    // 「次月」ボタンのクリックイベント
    $(document).on('click', '#next_month_log_button', function() {
        window.currentLogDate.setMonth(window.currentLogDate.getMonth() + 1);
        fetchAndDisplayLog(window.currentLogDate);
    });

    // CSVダウンロードボタンのクリックイベント
    $(document).on('click', '#download_log_csv_button', function() {
        var year = window.currentLogDate.getFullYear();
        var month = window.currentLogDate.getMonth(); // 0-11
        var firstDay = new Date(year, month, 1);
        var lastDay = new Date(year, month + 1, 0);

        var startDateStr = firstDay.getFullYear() + '/' + (firstDay.getMonth() + 1).toString().padStart(2, '0') + '/' + firstDay.getDate().toString().padStart(2, '0');
        var endDateStr = lastDay.getFullYear() + '/' + (lastDay.getMonth() + 1).toString().padStart(2, '0') + '/' + lastDay.getDate().toString().padStart(2, '0');

        // CSVダウンロードのURLを生成し、新しいタブで開く
        var downloadUrl = mySimpleCounterAjax.ajaxurl +
                          '?action=my_simple_counter_download_csv' +
                          '&start_date=' + encodeURIComponent(startDateStr) +
                          '&end_date=' + encodeURIComponent(endDateStr) +
                          '&nonce=' + mySimpleCounterAjax.download_csv_nonce; // ノンスをGETパラメータに追加
        window.open(downloadUrl, '_blank');
    });

    // 「選択したログを削除」ボタンのクリックイベント
    $(document).on('click', '#delete_selected_logs_button', function() {
        var selectedLogIds = [];
        $('.log-checkbox:checked').each(function() {
            selectedLogIds.push($(this).val());
        });

        if (selectedLogIds.length === 0) {
            alert('削除するログを選択してください。');
            return;
        }

        if (!confirm('本当に選択したログを削除しますか？')) {
            return;
        }

        $('.my-counter-ajax-message').hide().text('');
        $('.my-counter-error-message').hide().text('');

        $.ajax({
            url: mySimpleCounterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'my_simple_counter_delete_logs',
                log_ids: selectedLogIds,
                nonce: mySimpleCounterAjax.delete_log_nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.my-counter-ajax-message').text(response.data.message).fadeIn();
                    fetchAndDisplayLog(window.currentLogDate); // ログを再読み込みして表示を更新
                } else {
                    // PHP側でログインチェックを追加したので、ここでログインエラーを処理
                    if (response.data && response.data.message === 'ログを削除するにはログインが必要です。') {
                        alert(response.data.message);
                        // 必要であればログインページへリダイレクト
                        // window.location.href = mySimpleCounterAjax.login_url; // mySimpleCounterAjaxにlogin_urlを追加する必要あり
                    } else {
                        $('.my-counter-error-message').text('エラー: ' + (response.data.message || '不明なエラー')).fadeIn();
                    }
                }
            },
            error: function() {
                $('.my-counter-error-message').text('通信エラーが発生しました。').fadeIn();
            }
        });
    });

    // 全選択チェックボックスのイベント
    $(document).on('change', '#select_all_logs', function() {
        $('.log-checkbox').prop('checked', $(this).prop('checked'));
    });


    // --- ページリロードボタン ---
    $(document).on('click', '.my-counter-reload-button', function() {
        location.reload();
    });

    // 初期ロード時に実施履歴タブがアクティブな場合、ログをロード
    // `DOMContentLoaded` 後にタブの初期アクティブ状態を確認し、必要ならロード
    // ショートコードの初期表示で実施履歴タブがアクティブになっている可能性があるため、
    // ページロード時に一度チェックして自動でログを読み込む
    if ($('.my-counter-summary-tabs .tab-button.active').data('tab') === 'detailed-log-tab') {
        fetchAndDisplayLog(window.currentLogDate);
    }


    // ====================================================================================
    // 管理画面の機能 (frontend.js に含めるかは運用によるが、現状ファイルが共通なので残す)
    // ====================================================================================

    // 管理画面タブ切り替え
    jQuery(document).ready(function($) {
        // タブ切り替え
        $('.nav-tab-wrapper a').on('click', function(e) {
            e.preventDefault();
            var targetTab = $(this).attr('href');

            $('.nav-tab-wrapper a').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            $('.tab-content').hide();
            $(targetTab).show();

            // URLのハッシュを更新 (ブラウザの戻る/進むボタンに対応)
            history.pushState(null, null, targetTab);
        });

        // ページロード時にURLのハッシュに基づいてアクティブなタブを表示
        // デフォルトは #counter-items-section
        var initialTab = window.location.hash || '#counter-items-section';
        // ハッシュがtab-contentのIDと一致するか確認
        if ($(initialTab).hasClass('tab-content')) {
            $('.nav-tab-wrapper a[href="' + initialTab + '"]').click();
        } else {
            // 無効なハッシュの場合はデフォルトタブを表示
            $('.nav-tab-wrapper a[href="#counter-items-section"]').click();
        }
    });

}); // end of jQuery(document).ready