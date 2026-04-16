/* global $, m4pAjaxUrl, m4pBatchSize */
(function ($) {
    'use strict';

    var M4pConverter = {

        offset: 0,
        total: 0,
        counts: { converted: 0, skipped: 0, error: 0 },

        init: function () {
            $('#m4p-convert-btn').on('click', function () {
                M4pConverter.start();
            });
        },

        start: function () {
            this.offset = 0;
            this.total = 0;
            this.counts = { converted: 0, skipped: 0, error: 0 };

            $('#m4p-convert-btn').prop('disabled', true);
            $('#m4p-progress-wrap').show();
            $('#m4p-log').empty();
            this.setBar(0, true);
            this.setSummary('');

            this.processBatch();
        },

        processBatch: function () {
            $.ajax({
                url: m4pAjaxUrl,
                method: 'POST',
                data: {
                    offset: M4pConverter.offset,
                    batchSize: m4pBatchSize
                },
                dataType: 'json',
                success: function (response) {
                    if (!response || !response.success) {
                        M4pConverter.appendLog({
                            status: 'error',
                            id_image: '-',
                            product_name: 'Server',
                            file: null
                        });
                        M4pConverter.onDone();
                        return;
                    }

                    M4pConverter.total = response.total;
                    M4pConverter.offset = response.offset;

                    $.each(response.results, function (i, item) {
                        M4pConverter.counts[item.status] =
                            (M4pConverter.counts[item.status] || 0) + 1;
                        M4pConverter.appendLog(item);
                    });

                    var pct = M4pConverter.total > 0
                        ? Math.round((M4pConverter.offset / M4pConverter.total) * 100)
                        : 100;
                    M4pConverter.setBar(pct, false);
                    M4pConverter.setSummary(
                        M4pConverter.offset + ' / ' + M4pConverter.total + ' &nbsp;|&nbsp; '
                        + '<span style="color:#3c763d">&#10003; ' + M4pConverter.counts.converted + '</span>'
                        + ' &nbsp; <span style="color:#31708f">&#8631; ' + M4pConverter.counts.skipped + '</span>'
                        + ' &nbsp; <span style="color:#a94442">&#10007; ' + M4pConverter.counts.error + '</span>'
                    );

                    if (response.done) {
                        M4pConverter.onDone();
                    } else {
                        M4pConverter.processBatch();
                    }
                },
                error: function (xhr) {
                    M4pConverter.appendLog({
                        status: 'error',
                        id_image: '-',
                        product_name: 'AJAX error ' + xhr.status,
                        file: null
                    });
                    M4pConverter.onDone();
                }
            });
        },

        appendLog: function (item) {
            var map = {
                converted: { cls: 'success', icon: '&#10003;' },
                skipped:   { cls: 'info',    icon: '&#8631;'  },
                error:     { cls: 'danger',  icon: '&#10007;' }
            };
            var style = map[item.status] || { cls: 'default', icon: '' };
            var text = style.icon + ' [img #' + item.id_image + '] '
                + item.product_name + ' &mdash; ' + item.status;
            if (item.file) {
                text += ' <small style="opacity:.7">&rarr; ' + item.file + '</small>';
            }

            var $li = $('<li>')
                .addClass('list-group-item list-group-item-' + style.cls)
                .css({ padding: '4px 10px', 'font-size': '12px' })
                .html(text);

            $('#m4p-log').append($li);

            var wrap = document.getElementById('m4p-log-wrap');
            if (wrap) {
                wrap.scrollTop = wrap.scrollHeight;
            }
        },

        setBar: function (pct, animated) {
            var $bar = $('#m4p-progress-bar');
            $bar.css('width', pct + '%')
                .attr('aria-valuenow', pct)
                .text(pct + '%');

            if (animated) {
                $bar.addClass('active');
            } else {
                $bar.removeClass('active');
            }
        },

        setSummary: function (html) {
            $('#m4p-summary').html(html);
        },

        onDone: function () {
            this.setBar(100, false);
            $('#m4p-progress-bar').addClass('progress-bar-success').removeClass('progress-bar-warning');
            $('#m4p-convert-btn').prop('disabled', false);
        }
    };

    $(document).ready(function () {
        if ($('#m4p-convert-btn').length) {
            M4pConverter.init();
        }
    });

})(jQuery);
