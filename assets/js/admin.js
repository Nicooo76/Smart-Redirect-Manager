/**
 * Smart Redirect Manager - Admin JavaScript
 *
 * Handles all admin-side interactions including redirect CRUD,
 * 404 management, regex testing, conditions, and bulk actions.
 *
 * @package SmartRedirectManager
 */
jQuery(document).ready(function ($) {

    // -------------------------------------------------------------------------
    // 1. REDIRECT FORM SUBMISSION
    // -------------------------------------------------------------------------
    $('#srm-redirect-form').on('submit', function (e) {
        e.preventDefault();

        var formData = $(this).serializeArray();

        // Collect condition rows into structured data
        var conditions = [];
        $('.srm-condition-row').each(function () {
            var row = $(this);
            conditions.push({
                condition_type: row.find('.srm-condition-type').val(),
                operator: row.find('.srm-condition-operator').val(),
                value: row.find('.srm-condition-value').val()
            });
        });

        formData.push({ name: 'action', value: 'srm_save_redirect' });
        formData.push({ name: 'nonce', value: srm_ajax.nonce });
        formData.push({ name: 'conditions', value: JSON.stringify(conditions) });

        $.ajax({
            url: srm_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function (response) {
                if (response.success) {
                    showNotice(response.data.message || 'Weiterleitung erfolgreich gespeichert.', 'success');
                    setTimeout(function () {
                        if (response.data.redirect_url) {
                            window.location.href = response.data.redirect_url;
                        } else {
                            window.location.reload();
                        }
                    }, 1000);
                } else {
                    showNotice(response.data.message || 'Fehler beim Speichern der Weiterleitung.', 'error');
                }
            },
            error: function () {
                showNotice('Ein Serverfehler ist aufgetreten. Bitte versuchen Sie es erneut.', 'error');
            }
        });
    });

    // -------------------------------------------------------------------------
    // 2. DELETE REDIRECT
    // -------------------------------------------------------------------------
    $(document).on('click', '.srm-delete-redirect', function (e) {
        e.preventDefault();

        if (!confirm('Redirect wirklich löschen?')) {
            return;
        }

        var button = $(this);
        var id = button.data('id');
        var row = button.closest('tr');

        $.ajax({
            url: srm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'srm_delete_redirect',
                id: id,
                nonce: srm_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    if (row.length) {
                        row.fadeOut(300, function () {
                            $(this).remove();
                        });
                    } else {
                        window.location.reload();
                    }
                } else {
                    showNotice(response.data.message || 'Fehler beim Löschen.', 'error');
                }
            },
            error: function () {
                showNotice('Ein Serverfehler ist aufgetreten.', 'error');
            }
        });
    });

    // -------------------------------------------------------------------------
    // 3. TOGGLE REDIRECT STATUS
    // -------------------------------------------------------------------------
    $(document).on('click', '.srm-status-toggle', function (e) {
        e.preventDefault();

        var button = $(this);
        var id = button.data('id');
        var currentlyActive = button.hasClass('srm-active');
        var newStatus = currentlyActive ? 0 : 1;

        $.ajax({
            url: srm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'srm_toggle_redirect',
                id: id,
                is_active: newStatus,
                nonce: srm_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    if (newStatus === 1) {
                        button.removeClass('srm-inactive').addClass('srm-active');
                        button.html('<span class="dashicons dashicons-yes-alt" style="color:green;"></span>');
                    } else {
                        button.removeClass('srm-active').addClass('srm-inactive');
                        button.html('<span class="dashicons dashicons-dismiss" style="color:red;"></span>');
                    }
                } else {
                    showNotice(response.data.message || 'Status konnte nicht geändert werden.', 'error');
                }
            },
            error: function () {
                showNotice('Ein Serverfehler ist aufgetreten.', 'error');
            }
        });
    });

    // -------------------------------------------------------------------------
    // 4. BULK ACTIONS
    // -------------------------------------------------------------------------
    $('#srm-bulk-form').on('submit', function (e) {
        e.preventDefault();

        var bulkAction = $(this).find('select[name="bulk_action"]').val();
        if (!bulkAction || bulkAction === '-1') {
            showNotice('Bitte wählen Sie eine Aktion aus.', 'warning');
            return;
        }

        var ids = [];
        $(this).find('input[name="redirect_ids[]"]:checked').each(function () {
            ids.push($(this).val());
        });

        if (ids.length === 0) {
            showNotice('Bitte wählen Sie mindestens einen Eintrag aus.', 'warning');
            return;
        }

        if (bulkAction === 'delete') {
            if (!confirm('Ausgewählte Einträge wirklich löschen?')) {
                return;
            }
        }

        $.ajax({
            url: srm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'srm_bulk_action',
                bulk_action: bulkAction,
                ids: ids,
                nonce: srm_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    showNotice(response.data.message || 'Fehler bei der Massenaktion.', 'error');
                }
            },
            error: function () {
                showNotice('Ein Serverfehler ist aufgetreten.', 'error');
            }
        });
    });

    // -------------------------------------------------------------------------
    // 5. 404 MODAL
    // -------------------------------------------------------------------------

    // Open modal
    $(document).on('click', '.srm-create-redirect-from-404', function (e) {
        e.preventDefault();

        var button = $(this);
        var url = button.data('url');
        var id = button.data('id');

        var modal = $('#srm-404-modal');
        modal.find('input[name="source_url"]').val(url);
        modal.find('input[name="404_id"]').val(id);
        modal.find('input[name="target_url"]').val('');

        modal.addClass('active');
    });

    // Close modal
    $(document).on('click', '.srm-modal-close, #srm-404-modal', function (e) {
        if (e.target === this) {
            $('#srm-404-modal').removeClass('active');
        }
    });

    // Submit modal form
    $(document).on('click', '#srm-404-modal .srm-modal-submit', function (e) {
        e.preventDefault();

        var modal = $('#srm-404-modal');
        var notFoundId = modal.find('input[name="404_id"]').val();
        var targetUrl = modal.find('input[name="target_url"]').val();

        if (!targetUrl) {
            showNotice('Bitte geben Sie eine Ziel-URL ein.', 'warning');
            return;
        }

        $.ajax({
            url: srm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'srm_create_redirect_from_404',
                '404_id': notFoundId,
                target_url: targetUrl,
                nonce: srm_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    modal.removeClass('active');

                    // Update the row status badge
                    var row = $('tr[data-id="' + notFoundId + '"]');
                    if (row.length) {
                        row.find('.srm-status-badge')
                            .removeClass('srm-status-open')
                            .addClass('srm-status-resolved')
                            .text('Gelöst');
                    }

                    showNotice(response.data.message || 'Weiterleitung erstellt und 404 als gelöst markiert.', 'success');
                } else {
                    showNotice(response.data.message || 'Fehler beim Erstellen der Weiterleitung.', 'error');
                }
            },
            error: function () {
                showNotice('Ein Serverfehler ist aufgetreten.', 'error');
            }
        });
    });

    // -------------------------------------------------------------------------
    // 6. RESOLVE 404
    // -------------------------------------------------------------------------
    $(document).on('click', '.srm-resolve-404', function (e) {
        e.preventDefault();

        var button = $(this);
        var id = button.data('id');
        var row = button.closest('tr');

        $.ajax({
            url: srm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'srm_resolve_404',
                id: id,
                nonce: srm_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    row.find('.srm-status-badge')
                        .removeClass('srm-status-open')
                        .addClass('srm-status-resolved')
                        .text('Gelöst');
                } else {
                    showNotice(response.data.message || 'Fehler beim Aktualisieren.', 'error');
                }
            },
            error: function () {
                showNotice('Ein Serverfehler ist aufgetreten.', 'error');
            }
        });
    });

    // -------------------------------------------------------------------------
    // 7. DELETE 404
    // -------------------------------------------------------------------------
    $(document).on('click', '.srm-delete-404', function (e) {
        e.preventDefault();

        if (!confirm('404-Eintrag wirklich löschen?')) {
            return;
        }

        var button = $(this);
        var id = button.data('id');
        var row = button.closest('tr');

        $.ajax({
            url: srm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'srm_delete_404',
                id: id,
                nonce: srm_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    row.fadeOut(300, function () {
                        $(this).remove();
                    });
                } else {
                    showNotice(response.data.message || 'Fehler beim Löschen.', 'error');
                }
            },
            error: function () {
                showNotice('Ein Serverfehler ist aufgetreten.', 'error');
            }
        });
    });

    // -------------------------------------------------------------------------
    // 8. REGEX TESTER
    // -------------------------------------------------------------------------

    // Toggle regex tester visibility
    $('#srm-is-regex').on('change', function () {
        if ($(this).is(':checked')) {
            $('.srm-regex-tester').show();
        } else {
            $('.srm-regex-tester').hide();
            $('.srm-regex-result').empty();
        }
    });

    // Initialize visibility on page load
    if ($('#srm-is-regex').length && !$('#srm-is-regex').is(':checked')) {
        $('.srm-regex-tester').hide();
    }

    // Test regex
    $(document).on('click', '.srm-test-regex', function (e) {
        e.preventDefault();

        var pattern = $('input[name="source_url"]').val();
        var testUrl = $('.srm-test-url').val();

        if (!pattern || !testUrl) {
            showNotice('Bitte geben Sie ein Muster und eine Test-URL ein.', 'warning');
            return;
        }

        $.ajax({
            url: srm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'srm_test_regex',
                pattern: pattern,
                test_url: testUrl,
                nonce: srm_ajax.nonce
            },
            success: function (response) {
                var resultDiv = $('.srm-regex-result');
                resultDiv.empty();

                if (response.success) {
                    if (response.data.match) {
                        resultDiv
                            .removeClass('srm-no-match')
                            .addClass('srm-match')
                            .text('Treffer! Die URL passt zum Muster.');
                    } else {
                        resultDiv
                            .removeClass('srm-match')
                            .addClass('srm-no-match')
                            .text('Kein Treffer. Die URL passt nicht zum Muster.');
                    }
                } else {
                    resultDiv
                        .removeClass('srm-match')
                        .addClass('srm-no-match')
                        .text(response.data.message || 'Fehler beim Testen des Musters.');
                }
            },
            error: function () {
                $('.srm-regex-result')
                    .removeClass('srm-match')
                    .addClass('srm-no-match')
                    .text('Ein Serverfehler ist aufgetreten.');
            }
        });
    });

    // -------------------------------------------------------------------------
    // 9. CONDITIONS
    // -------------------------------------------------------------------------

    // Operator options per condition type
    var conditionOperators = {
        user_role: [
            { value: 'is', label: 'ist' },
            { value: 'is_not', label: 'ist nicht' }
        ],
        device: [
            { value: 'is', label: 'ist' },
            { value: 'is_not', label: 'ist nicht' }
        ],
        language: [
            { value: 'is', label: 'ist' },
            { value: 'is_not', label: 'ist nicht' },
            { value: 'starts_with', label: 'beginnt mit' }
        ],
        cookie: [
            { value: 'exists', label: 'existiert' },
            { value: 'not_exists', label: 'existiert nicht' },
            { value: 'equals', label: 'ist gleich' },
            { value: 'not_equals', label: 'ist nicht gleich' }
        ],
        query_param: [
            { value: 'exists', label: 'existiert' },
            { value: 'not_exists', label: 'existiert nicht' },
            { value: 'equals', label: 'ist gleich' },
            { value: 'not_equals', label: 'ist nicht gleich' },
            { value: 'contains', label: 'enthält' }
        ],
        ip_address: [
            { value: 'is', label: 'ist' },
            { value: 'is_not', label: 'ist nicht' },
            { value: 'in_range', label: 'im Bereich' }
        ],
        time: [
            { value: 'before', label: 'vor' },
            { value: 'after', label: 'nach' },
            { value: 'between', label: 'zwischen' }
        ],
        referrer: [
            { value: 'contains', label: 'enthält' },
            { value: 'not_contains', label: 'enthält nicht' },
            { value: 'equals', label: 'ist gleich' },
            { value: 'is_empty', label: 'ist leer' }
        ]
    };

    // Add condition row
    $(document).on('click', '.srm-condition-add', function (e) {
        e.preventDefault();

        var template = $('.srm-condition-template').first();
        if (!template.length) {
            return;
        }

        var newRow = template.clone();
        newRow.removeClass('srm-condition-template').addClass('srm-condition-row');
        newRow.show();

        // Reset field values
        newRow.find('select, input').val('');

        $('.srm-conditions-container').append(newRow);
    });

    // Remove condition row
    $(document).on('click', '.srm-condition-remove', function (e) {
        e.preventDefault();
        $(this).closest('.srm-condition-row').remove();
    });

    // Update operators when condition type changes
    $(document).on('change', '.srm-condition-type', function () {
        var type = $(this).val();
        var operatorSelect = $(this).closest('.srm-condition-row').find('.srm-condition-operator');
        var operators = conditionOperators[type] || [];

        operatorSelect.empty();
        operatorSelect.append('<option value="">-- Operator wählen --</option>');

        $.each(operators, function (index, op) {
            operatorSelect.append(
                $('<option></option>').val(op.value).text(op.label)
            );
        });
    });

    // -------------------------------------------------------------------------
    // 10. SELECT ALL CHECKBOX
    // -------------------------------------------------------------------------
    $('#cb-select-all-1').on('change', function () {
        var isChecked = $(this).is(':checked');
        $('input[name="redirect_ids[]"]').prop('checked', isChecked);
    });

    // -------------------------------------------------------------------------
    // 11. NOTICES
    // -------------------------------------------------------------------------
    function showNotice(message, type) {
        type = type || 'info';

        var cssClass = 'notice notice-' + type + ' is-dismissible srm-notice';
        var notice = $(
            '<div class="' + cssClass + '">' +
                '<p>' + $('<span>').text(message).html() + '</p>' +
                '<button type="button" class="notice-dismiss">' +
                    '<span class="screen-reader-text">Hinweis ausblenden.</span>' +
                '</button>' +
            '</div>'
        );

        // Dismiss button handler
        notice.find('.notice-dismiss').on('click', function () {
            notice.fadeOut(200, function () {
                $(this).remove();
            });
        });

        // Prepend to .wrap
        $('.wrap').prepend(notice);

        // Auto-dismiss after 5 seconds
        setTimeout(function () {
            notice.fadeOut(400, function () {
                $(this).remove();
            });
        }, 5000);
    }

    // -------------------------------------------------------------------------
    // 13. CONFIRM DELETE (generic)
    // -------------------------------------------------------------------------
    $(document).on('click', '.srm-confirm-action', function (e) {
        var confirmMessage = $(this).data('confirm') || 'Sind Sie sicher?';
        if (!confirm(confirmMessage)) {
            e.preventDefault();
        }
    });

});
