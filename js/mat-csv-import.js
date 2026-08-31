jQuery(document).ready(function ($) {

    // =========================================================
    //  設定
    // =========================================================
    var CHUNK_SIZE = 100;   // 1回のAJAXで送る最大行数
    var ajaxurl = matCsvImport.ajaxurl;
    var nonce = matCsvImport.nonce;

    var parsedRows = [];    // CSVをパースした全行データ
    var previewData = [];    // サーバーからのバリデーション結果

    // =========================================================
    //  ファイル選択
    // =========================================================
    $('#mat-csv-file').on('change', function () {
        var file = this.files[0];
        $('#mat-csv-file-error').hide().text('');
        $('#mat-csv-preview-btn').prop('disabled', true);
        $('#mat-preview-area, #mat-progress-area, #mat-result-area').hide();

        if (!file) return;

        // 拡張子チェック
        if (!file.name.match(/\.csv$/i)) {
            showFileError('CSVファイル（.csv）を選択してください。');
            return;
        }

        $('#mat-csv-preview-btn').prop('disabled', false);
    });

    // =========================================================
    //  プレビューボタン
    // =========================================================
    $('#mat-csv-preview-btn').on('click', function () {
        var file = $('#mat-csv-file')[0].files[0];
        if (!file) return;

        var $btn = $(this).prop('disabled', true).text('読み込み中...');
        $('#mat-preview-area, #mat-progress-area, #mat-result-area').hide();

        // Shift-JIS / UTF-8 を自動判定して読み込む
        readCsvFile(file, function (text) {
            parsedRows = parseCsv(text);

            if (parsedRows.length === 0) {
                showFileError('データ行が見つかりません。ヘッダー行の次の行からデータを入力してください。');
                $btn.prop('disabled', false).text('🔍 プレビュー・バリデーション');
                return;
            }

            // サーバーにバリデーション依頼
            $.post(ajaxurl, {
                action: 'mat_csv_preview',
                nonce: nonce,
                rows: JSON.stringify(parsedRows),
            }, function (res) {
                $btn.prop('disabled', false).text('🔍 プレビュー・バリデーション');

                if (!res.success) {
                    showFileError(res.data);
                    return;
                }

                previewData = res.data.preview;
                renderPreview(res.data);
                $('#mat-preview-area').show();

            }).fail(function () {
                $btn.prop('disabled', false).text('🔍 プレビュー・バリデーション');
                showFileError('通信エラーが発生しました。');
            });
        });
    });

    // =========================================================
    //  CSVファイル読み込み（Shift-JIS / UTF-8 自動判定）
    // =========================================================
    function readCsvFile(file, callback) {
        // まず Shift-JIS で試みる
        var reader = new FileReader();
        reader.onload = function (e) {
            var text = e.target.result;
            // 文字化けが含まれるかチェック（置換文字 U+FFFD）
            if (text.indexOf('\uFFFD') !== -1) {
                // UTF-8 で再試行
                var reader2 = new FileReader();
                reader2.onload = function (e2) {
                    callback(e2.target.result);
                };
                reader2.readAsText(file, 'UTF-8');
            } else {
                callback(text);
            }
        };
        reader.readAsText(file, 'Shift-JIS');
    }

    // =========================================================
    //  CSVパース（RFC 4180準拠）
    // =========================================================
    function parseCsv(text) {
        // 改行コード統一
        text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

        var lines = [];
        var current = '';
        var inQuote = false;

        for (var i = 0; i < text.length; i++) {
            var ch = text[i];
            if (ch === '"') {
                if (inQuote && text[i + 1] === '"') {
                    current += '"';
                    i++;
                } else {
                    inQuote = !inQuote;
                }
            } else if (ch === '\n' && !inQuote) {
                lines.push(current);
                current = '';
            } else {
                current += ch;
            }
        }
        if (current !== '') lines.push(current);

        // 空行除去
        lines = lines.filter(function (l) { return l.trim() !== ''; });

        if (lines.length < 2) return [];

        // 見出し行（日本語）→ DBカラム名 マッピング
        var colMap = {
            '社員コード': 'employee_code',
            '勤務日': 'work_date',
            '出勤時刻': 'clock_in',
            '退勤時刻': 'clock_out',
            '休憩時間': 'break_time',
            '有給希望日': 'paid_leave_date',
            '備考': 'note',
        };

        var headers = splitCsvLine(lines[0]).map(function (h) {
            return colMap[h.trim()] || h.trim();
        });

        var rows = [];
        for (var r = 1; r < lines.length; r++) {
            var cells = splitCsvLine(lines[r]);
            if (cells.length === 0) continue;
            var obj = { line_no: r + 1 };
            headers.forEach(function (key, idx) {
                obj[key] = (cells[idx] || '').trim();
            });
            normalizeRow(obj);
            rows.push(obj);
        }
        return rows;
    }

    /**
     * 日付・時刻フィールドを正規化する
     *  - 日付: 2026/3/2 → 2026-03-02（スラッシュ区切り・ゼロ埋め対応）
     *  - 時刻: 8:00:00 → 08:00（秒カット・ゼロ埋め対応）
     */
    function normalizeRow(obj) {
        ['work_date', 'paid_leave_date'].forEach(function (key) {
            var v = obj[key] || '';
            if (v && v.indexOf('/') !== -1) {
                var parts = v.split('/');
                obj[key] = parts[0]
                    + '-' + ('0' + parts[1]).slice(-2)
                    + '-' + ('0' + parts[2]).slice(-2);
            }
        });
        ['clock_in', 'clock_out', 'break_time'].forEach(function (key) {
            var v = obj[key] || '';
            if (!v) return;
            var t = v.split(':');
            // 秒あり(8:00:00) / 秒なし1桁(8:00) どちらもゼロ埋めして HH:MM に統一
            obj[key] = ('0' + t[0]).slice(-2) + ':' + ('0' + (t[1] || '00')).slice(-2);
        });
    }

    function splitCsvLine(line) {
        var cells = [];
        var current = '';
        var inQuote = false;
        for (var i = 0; i < line.length; i++) {
            var ch = line[i];
            if (ch === '"') {
                if (inQuote && line[i + 1] === '"') {
                    current += '"';
                    i++;
                } else {
                    inQuote = !inQuote;
                }
            } else if (ch === ',' && !inQuote) {
                cells.push(current);
                current = '';
            } else {
                current += ch;
            }
        }
        cells.push(current);
        return cells;
    }

    // =========================================================
    //  プレビューテーブル描画
    // =========================================================
    var STATUS_LABEL = {
        'ok': '<span class="mat-badge mat-badge-ok">正常</span>',
        'warn': '<span class="mat-badge mat-badge-warn">警告</span>',
        'duplicate': '<span class="mat-badge mat-badge-dup">重複</span>',
        'error': '<span class="mat-badge mat-badge-error">エラー</span>',
    };

    function renderPreview(data) {
        var s = data.summary;

        // サマリー
        var summaryHtml = '全 <strong>' + s.total + '</strong> 件'
            + ' ／ 正常：<strong class="c-ok">' + s.ok + '</strong>'
            + ' ／ 警告：<strong class="c-warn">' + s.warn + '</strong>'
            + ' ／ 重複：<strong class="c-dup">' + s.duplicate + '</strong>'
            + ' ／ エラー：<strong class="c-err">' + s.error + '</strong>';
        $('#mat-preview-summary').html(summaryHtml);

        // テーブル
        var html = '';
        data.preview.forEach(function (row) {
            var cls = 'mat-row-' + row.status;
            var msg = row.messages.length ? row.messages.join('<br>') : '—';
            html += '<tr class="' + cls + '">'
                + '<td>' + esc(row.line_no) + '</td>'
                + '<td>' + (STATUS_LABEL[row.status] || row.status) + '</td>'
                + '<td>' + esc(row.employee_code) + '</td>'
                + '<td>' + esc(row.emp_name) + '</td>'
                + '<td>' + esc(row.work_date) + '</td>'
                + '<td>' + esc(row.clock_in) + '</td>'
                + '<td>' + esc(row.clock_out) + '</td>'
                + '<td>' + esc(row.break_time) + '</td>'
                + '<td>' + esc(row.paid_leave_date) + '</td>'
                + '<td>' + esc(row.note) + '</td>'
                + '<td class="mat-msg">' + msg + '</td>'
                + '</tr>';
        });
        $('#mat-preview-tbody').html(html);

        // エラーのみの場合はインポートボタン無効化
        var canImport = (s.ok + s.warn + s.duplicate) > 0;
        $('#mat-csv-import-btn').prop('disabled', !canImport);
    }

    // =========================================================
    //  インポート実行（チャンク分割）
    // =========================================================
    $('#mat-csv-import-btn').on('click', function () {
        var onDuplicate = $('input[name="mat_on_duplicate"]:checked').val() || 'merge';
        var duplicateLabels = {
            merge: '入力項目だけ更新（空欄は既存を維持）',
            skip: 'スキップ',
            overwrite: '全項目を上書き',
        };

        if (!confirm(
            'インポートを実行します。\n'
            + '重複レコードの扱い：【' + duplicateLabels[onDuplicate] + '】\n\n'
            + 'よろしいですか？'
        )) return;

        $('#mat-preview-area').hide();
        $('#mat-progress-area').show();
        $('#mat-result-area').hide();

        var allRows = previewData;
        var total = allRows.length;
        var chunks = [];

        // チャンク分割
        for (var i = 0; i < total; i += CHUNK_SIZE) {
            chunks.push(allRows.slice(i, i + CHUNK_SIZE));
        }

        var totalResult = { inserted: 0, updated: 0, skipped: 0, errors: [] };
        var processed = 0;

        // チャンクを順番に送信
        function sendChunk(idx) {
            if (idx >= chunks.length) {
                // 全チャンク完了
                showResult(totalResult);
                return;
            }

            var chunk = chunks[idx];
            var percent = Math.round(((idx) / chunks.length) * 100);
            updateProgress(percent, (idx * CHUNK_SIZE) + ' / ' + total + ' 件処理中...');

            $.post(ajaxurl, {
                action: 'mat_csv_import_chunk',
                nonce: nonce,
                rows: JSON.stringify(chunk),
                on_duplicate: onDuplicate,
            }, function (res) {
                if (res.success) {
                    totalResult.inserted += res.data.inserted;
                    totalResult.updated += res.data.updated;
                    totalResult.skipped += res.data.skipped;
                    totalResult.errors = totalResult.errors.concat(res.data.errors);
                } else {
                    totalResult.errors.push('チャンク' + (idx + 1) + 'でエラー：' + res.data);
                }
                processed += chunk.length;
                sendChunk(idx + 1);

            }).fail(function () {
                totalResult.errors.push('チャンク' + (idx + 1) + 'で通信エラーが発生しました。');
                sendChunk(idx + 1);
            });
        }

        sendChunk(0);
    });

    // =========================================================
    //  プログレスバー更新
    // =========================================================
    function updateProgress(percent, label) {
        $('#mat-progress-bar')
            .css('width', percent + '%')
            .text(percent + '%');
        $('#mat-progress-label').text(label);
    }

    // =========================================================
    //  完了表示
    // =========================================================
    function showResult(result) {
        updateProgress(100, '完了');
        $('#mat-progress-area').hide();

        var hasError = result.errors.length > 0;
        var html = '<div class="mat-result-summary">'
            + '<p>' + (hasError ? '⚠️ インポート処理が完了しました（エラーあり）。' : '🎉 インポートが完了しました。') + '</p>'
            + '<table class="mat-result-table">'
            + '<tr><th>新規追加</th><td><strong>' + result.inserted + '</strong> 件</td></tr>'
            + '<tr><th>上書き更新</th><td><strong>' + result.updated + '</strong> 件</td></tr>'
            + '<tr><th>スキップ</th><td><strong>' + result.skipped + '</strong> 件</td></tr>'
            + '</table>'
            + '</div>';

        if (hasError) {
            html += '<div class="mat-result-errors">'
                + '<p>⚠️ 以下の行でエラーが発生しました：</p><ul>';
            result.errors.forEach(function (e) {
                html += '<li>' + esc(e) + '</li>';
            });
            html += '</ul></div>';
        }

        html += '<p><button class="button" id="mat-restart-btn">もう一度インポートする</button></p>';

        $('#mat-result-content').html(html);
        $('#mat-result-area').show();

        // リセット
        $('#mat-restart-btn').on('click', function () {
            $('#mat-csv-file').val('');
            parsedRows = [];
            previewData = [];
            $('#mat-preview-area, #mat-result-area').hide();
            $('#mat-csv-preview-btn').prop('disabled', true);
            $('html, body').animate({ scrollTop: 0 }, 300);
        });
    }

    // =========================================================
    //  ユーティリティ
    // =========================================================
    function showFileError(msg) {
        $('#mat-csv-file-error').text(msg).show();
    }

    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

});
