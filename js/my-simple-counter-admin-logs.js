// my-simple-counter/js/my-simple-counter-admin-logs.js

jQuery(document).ready(function($) {

    // 管理画面の実施履歴ページ用のグローバル変数
    // 日付オブジェクト
    if (!window.currentAdminLogDate) {
        window.currentAdminLogDate = new Date();
    }
    // 現在のページ番号
    if (!window.currentAdminLogPage) {
        window.currentAdminLogPage = 1;
    }
    // 現在の担当者フィルター
    if (!window.currentAdminLogUserFilter) {
        window.currentAdminLogUserFilter = '';
    }

    // 実施履歴表示関数
    function fetchAndDisplayAdminLog(dateObj) {
        if (!dateObj) {
            console.error('Invalid date object passed to fetchAndDisplayAdminLog.');
            return;
        }

        var year = dateObj.getFullYear();
        var month = String(dateObj.getMonth() + 1).padStart(2, '0'); // getMonth()は0-11なので+1
        var day = String(dateObj.getDate()).padStart(2, '0');
        var dateString = year + '/' + month + '/' + day;

        var userId = window.currentAdminLogUserFilter || ''; // 現在のフィルター値

        $('#current_log_date_display').text(dateString + ' (' + mySimpleCounterAdminLogsAjax.day_names_min[dateObj.getDay()] + ')');

        $.ajax({
            url: mySimpleCounterAdminLogsAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'my_simple_counter_fetch_detailed_log',
                nonce: mySimpleCounterAdminLogsAjax.nonce,
                date: dateString,
                user_id: userId,
                page: window.currentAdminLogPage // 現在のページ番号を送信
            },
            beforeSend: function() {
                $('#log_history_results').html('<p>読み込み中...</p>');
            },
            success: function(response) {
                if (response.success) {
                    $('#log_history_results').html(response.data.html);
                    $('#total_pages_display').text(response.data.total_pages);
                    $('#current_page_display').text(response.data.current_page);

                    // ページネーションボタンの有効/無効
                    $('#prev_page_button').prop('disabled', response.data.current_page <= 1);
                    $('#next_page_button').prop('disabled', response.data.current_page >= response.data.total_pages);

                    // CSVダウンロードボタンのURLを更新
                    var downloadUrl = mySimpleCounterAdminLogsAjax.ajax_url +
                        '?action=my_simple_counter_download_csv&start_date=' + dateString +
                        '&end_date=' + dateString +
                        '&user_id=' + userId + // フィルターもCSVに反映
                        '&nonce=' + mySimpleCounterAdminLogsAjax.download_csv_nonce;
                    $('#download_log_csv_button').attr('onclick', `window.location.href='${downloadUrl}'`);

                } else {
                    $('#log_history_results').html('<p>' + response.data.message + '</p>');
                    $('#total_pages_display').text('1');
                    $('#current_page_display').text('0');
                    $('#prev_page_button').prop('disabled', true);
                    $('#next_page_button').prop('disabled', true);
                }
            },
            error: function() {
                $('#log_history_results').html('<p>実施履歴の取得に失敗しました。</p>');
            }
        });
    }

    // 初期ロード時に実施履歴を表示
    fetchAndDisplayAdminLog(window.currentAdminLogDate);


    // 日付ナビゲーションボタン
    $(document).on('click', '#prev_day_button', function() {
        window.currentAdminLogDate.setDate(window.currentAdminLogDate.getDate() - 1);
        window.currentAdminLogPage = 1; // 日付変更でページをリセット
        fetchAndDisplayAdminLog(window.currentAdminLogDate);
    });

    $(document).on('click', '#next_day_button', function() {
        window.currentAdminLogDate.setDate(window.currentAdminLogDate.getDate() + 1);
        window.currentAdminLogPage = 1; // 日付変更でページをリセット
        fetchAndDisplayAdminLog(window.currentAdminLogDate);
    });

    $(document).on('click', '#today_button', function() {
        window.currentAdminLogDate = new Date(); // 今日
        window.currentAdminLogPage = 1; // 日付変更でページをリセット
        fetchAndDisplayAdminLog(window.currentAdminLogDate);
    });

    // 担当者フィルター変更
    $(document).on('change', '#log_history_user_filter', function() {
        window.currentAdminLogUserFilter = $(this).val();
        window.currentAdminLogPage = 1; // フィルター変更でページをリセット
        fetchAndDisplayAdminLog(window.currentAdminLogDate);
    });

    // ページネーションボタン
    $(document).on('click', '#prev_page_button', function() {
        var totalPages = parseInt($('#total_pages_display').text());
        if (window.currentAdminLogPage > 1) {
            window.currentAdminLogPage--;
            fetchAndDisplayAdminLog(window.currentAdminLogDate);
        }
    });

    $(document).on('click', '#next_page_button', function() {
        var totalPages = parseInt($('#total_pages_display').text());
        if (window.currentAdminLogPage < totalPages) {
            window.currentAdminLogPage++;
            fetchAndDisplayAdminLog(window.currentAdminLogDate);
        }
    });

    // ログの全選択/全解除
    $(document).on('change', '#select_all_logs', function() {
        $('.log-checkbox').prop('checked', $(this).prop('checked'));
    });

    // 選択したログの削除
    $(document).on('click', '#delete_selected_logs_button', function() {
        var selectedLogIds = [];
        $('.log-checkbox:checked').each(function() {
            selectedLogIds.push($(this).val());
        });

        if (selectedLogIds.length === 0) {
            alert('削除する履歴を選択してください。');
            return;
        }

        if (!confirm('選択された履歴を本当に削除しますか？')) {
            return;
        }

        $.ajax({
            url: mySimpleCounterAdminLogsAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'my_simple_counter_delete_logs',
                nonce: mySimpleCounterAdminLogsAjax.nonce,
                log_ids: selectedLogIds
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    // 削除後、現在のページを再読み込み
                    fetchAndDisplayAdminLog(window.currentAdminLogDate);
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                alert('履歴の削除に失敗しました。');
            }
        });
    });

    // 個別ログの削除
    $(document).on('click', '.delete-log-single-button', function() {
        var logId = $(this).data('log-id');
        if (!confirm('この履歴を本当に削除しますか？')) {
            return;
        }

        $.ajax({
            url: mySimpleCounterAdminLogsAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'my_simple_counter_delete_logs',
                nonce: mySimpleCounterAdminLogsAjax.nonce,
                log_ids: [logId] // 配列として送信
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    fetchAndDisplayAdminLog(window.currentAdminLogDate); // 削除後、現在のページを再読み込み
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                alert('履歴の削除に失敗しました。');
            }
        });
    });

});