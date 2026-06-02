jQuery(document).ready(function($) {
    // 削除ボタンがクリックされたときの処理
    $(document).on('click', '.my-counter-delete-aggregation', function() {
        var $button = $(this);
        var itemName = $button.data('item-name');
        var year = $button.data('year');
        var month = $button.data('month'); // 月次集計の場合のみ存在
        var registeredUserId = $button.data('registered-user-id'); // 追加: ユーザーID

        var confirmMessage = '本当に項目「' + itemName + '」の' + year + '年';
        if (month) {
            confirmMessage += month + '月の';
        }
        // registeredUserId === 0 は PHP側でログインユーザーを意味するように定義
        // registeredUserId が null または undefined の場合は、ユーザー指定なしと見なす
        if (registeredUserId === 0) {
             confirmMessage += 'ログインユーザーの';
        } else if (registeredUserId) {
            // ここでユーザー名を表示したい場合、DOMから取得するか、PHP側でlocalize_scriptで渡す必要がある
            // 現状ではIDを表示
            confirmMessage += '登録ユーザーID:' + registeredUserId + 'の';
        }

        confirmMessage += '全てのログを削除しますか？\nこの操作は元に戻せません。';

        if (!confirm(confirmMessage)) {
            return; // キャンセルされたら何もしない
        }

        // Ajaxリクエストの送信
        $.ajax({
            url: mySimpleCounterAggregationAjax.ajaxurl, // wp_localize_script で渡されたAjax URL
            type: 'POST',
            data: {
                action: 'my_simple_counter_delete_aggregation', // PHP側で定義したアクション名
                item_name: itemName,
                year: year,
                month: month, // 月次削除の場合のみ送信
                registered_user_id: registeredUserId, // ユーザーIDを送信
                nonce: mySimpleCounterAggregationAjax.nonce // Nonce
            },
            beforeSend: function() {
                $button.prop('disabled', true); // ボタンを無効化
                $button.text('削除中...');
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    // 削除が成功したら、該当する行をテーブルから削除
                    $button.closest('tr').remove();
                } else {
                    console.error('ログ削除エラー:', response.data);
                    alert('ログの削除に失敗しました: ' + (response.data.message || '不明なエラー。'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Ajaxエラー:', status, error);
                alert('通信エラーが発生しました。');
            },
            complete: function() {
                $button.prop('disabled', false).text('この' + (month ? '月の' : '年の') + 'ログを削除');
            }
        });
    });
});