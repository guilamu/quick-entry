/**
 * QuickEntry - Admin JavaScript
 */

(function($) {
    'use strict';
    
    if (typeof qentry_data === 'undefined') {
        console.error('qentry_data is undefined!');
        return;
    }

    $(document).ready(function() {
        console.log('QENTRY: Document ready, URL:', window.location.href);
        
        $(window).on('popstate', function(e) {
            if (e.originalEvent.state && e.originalEvent.state.tab) {
                var tab = e.originalEvent.state.tab;
                $('.nav-tab').removeClass('nav-tab-active');
                $('.nav-tab[href*="qentry_tab=' + tab + '"]').addClass('nav-tab-active');
                loadTabContent(tab);
            }
        });
        
        function loadTabContent(tab) {
            if (!tab) return;
            $.ajax({
                url: ajaxurl || '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: {
                    action: 'qentry_get_tab_content',
                    nonce: qentry_data.nonce,
                    qentry_tab: tab
                },
                beforeSend: function() {
                    $('#qentry-tab-content').html('<p style="padding:20px;text-align:center;color:#666;">Loading...</p>');
                },
                success: function(response) {
                    if (response.success) {
                        $('#qentry-tab-content').html(response.data.html);
                        $('.qentry-date-picker').datepicker({
                            dateFormat: 'mm/dd/yy',
                            minDate: 0,
                            changeMonth: true,
                            changeYear: true
                        });
                        toggleMaxUses();
                    } else {
                        $('#qentry-tab-content').html('<p>Error loading content.</p>');
                    }
                },
                error: function() {
                    $('#qentry-tab-content').html('<p>Error loading content.</p>');
                }
            });
        }
        
        $(document).on('click', '.nav-tab-wrapper a.nav-tab', function(e) {
            e.preventDefault();
            var href = $(this).attr('href');
            console.log('QENTRY: Tab clicked, href:', href);
            
            var urlParams = new URLSearchParams(href.split('?')[1]);
            var tab = urlParams.get('qentry_tab');
            console.log('QENTRY: Tab value:', tab);
            
            if (!tab) return;
            
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            var newUrl = 'admin.php?page=quick-entry&qentry_tab=' + tab;
            history.pushState({tab: tab}, '', newUrl);
            
            $.ajax({
                url: ajaxurl || '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: {
                    action: 'qentry_get_tab_content',
                    nonce: qentry_data.nonce,
                    qentry_tab: tab
                },
                beforeSend: function() {
                    $('#qentry-tab-content').html('<p style="padding:20px;text-align:center;color:#666;">Loading...</p>');
                },
                success: function(response) {
                    if (response.success) {
                        $('#qentry-tab-content').html(response.data.html);
                        $('.qentry-date-picker').datepicker({
                            dateFormat: 'mm/dd/yy',
                            minDate: 0,
                            changeMonth: true,
                            changeYear: true
                        });
                        toggleMaxUses();
                    } else {
                        $('#qentry-tab-content').html('<p>Error loading content.</p>');
                    }
                },
                error: function() {
                    $('#qentry-tab-content').html('<p>Error loading content.</p>');
                }
            });
        });
        
        $('.qentry-date-picker').datepicker({
            dateFormat: 'mm/dd/yy',
            minDate: 0,
            changeMonth: true,
            changeYear: true
        });

        function isSkipConfirmationEnabled() {
            return $('#qentry-skip-confirmation-code').is(':checked');
        }

        function updateModalInstructions(skipConfirmation) {
            $('#qentry-modal-step-2').text(
                skipConfirmation ? qentry_data.i18n.modal_step_two_skip : qentry_data.i18n.modal_step_two_standard
            );
            $('#qentry-modal-step-3').text(
                skipConfirmation ? qentry_data.i18n.modal_step_three_skip : qentry_data.i18n.modal_step_three_standard
            );
        }

        function updateSkipConfirmationState() {
            var skipConfirmation = isSkipConfirmationEnabled();
            var $emailField = $('#qentry-field-email');
            var $email = $('#qentry-email');
            var $emailHint = $('#qentry-email-hint');
            var $optionCard = $emailField.find('.qentry-option-card');
            var $maxUses = $('#qentry-max-uses');
            var $maxUsesHint = $('#qentry-max-uses-hint');

            if (!$email.length || !$maxUses.length) {
                updateModalInstructions(skipConfirmation);
                updateSummary();
                return;
            }

            if (typeof $email.data('default-placeholder') === 'undefined') {
                $email.data('default-placeholder', $email.attr('placeholder') || '');
            }

            if (skipConfirmation) {
                $email.data('stored-value', $email.val());
                $email.val('');
                $email.prop('disabled', true);
                $email.prop('required', false);
                $email.attr('placeholder', qentry_data.i18n.email_placeholder_skip);
                $emailField.addClass('qentry-field-disabled');
                $optionCard.addClass('is-active');
                $emailHint.text(qentry_data.i18n.email_hint_skip);

                $maxUses.data('stored-value', $maxUses.val());
                $maxUses.val(1);
                $maxUses.prop('disabled', true);
                $maxUsesHint.text(qentry_data.i18n.max_uses_hint_skip);
            } else {
                $email.prop('disabled', false);
                $email.prop('required', true);
                $email.attr('placeholder', $email.data('default-placeholder') || '');
                $emailField.removeClass('qentry-field-disabled');
                $optionCard.removeClass('is-active');
                $emailHint.text(qentry_data.i18n.email_hint_default);

                if (typeof $email.data('stored-value') !== 'undefined') {
                    $email.val($email.data('stored-value'));
                }

                $maxUses.prop('disabled', false);
                $maxUses.val(
                    typeof $maxUses.data('stored-value') !== 'undefined' && $maxUses.data('stored-value') !== ''
                        ? $maxUses.data('stored-value')
                        : 0
                );
                $maxUsesHint.text(qentry_data.i18n.max_uses_hint_default);
            }

            updateModalInstructions(skipConfirmation);
            updateSummary();
        }

        // Number of uses change
        $(document).on('change input', '#qentry-max-uses', function() {
            updateSummary();
        });

        $(document).on('change', '#qentry-skip-confirmation-code', function() {
            updateSkipConfirmationState();
        });

        // Reset button
        $(document).on('click', '#qentry-reset-btn', function() {
            var $form = $('#qentry-create-form');
            $form[0].reset();
            $('#qentry-skip-confirmation-code').prop('checked', false);
            $('#qentry-email').data('stored-value', '');
            $('#qentry-max-uses').data('stored-value', 0);
            $('#qentry-max-uses').val(0);
            $('#qentry-expiration-time').val('23:59');
            updateSkipConfirmationState();
        });

        // Dynamic summary text
        function updateSummary() {
            var role = $('#qentry-role option:selected');
            var roleText = role.val() ? role.text() : '';
            var dateVal = $('#qentry-expiration-date').val();
            var timeVal = $('#qentry-expiration-time').val();
            var maxUses = parseInt($('#qentry-max-uses').val()) || 0;
            var skipConfirmation = isSkipConfirmationEnabled();

            if (!roleText && !dateVal) {
                $('#qentry-summary-text').text(qentry_data.i18n.summary_default);
                return;
            }

            var parts = [];
            parts.push('Create a temporary');

            if (roleText) {
                parts.push(roleText);
            }

            parts.push(skipConfirmation ? 'direct-login link' : 'login');

            if (dateVal) {
                // Parse mm/dd/yy or mm/dd/yyyy
                var dateParts = dateVal.split('/');
                if (dateParts.length === 3) {
                    var month = parseInt(dateParts[0], 10);
                    var day = parseInt(dateParts[1], 10);
                    var year = parseInt(dateParts[2], 10);
                    if (year < 100) year += 2000;

                    var months = ['January', 'February', 'March', 'April', 'May', 'June',
                                  'July', 'August', 'September', 'October', 'November', 'December'];
                    var ordinal = function(n) {
                        var s = ['th','st','nd','rd'];
                        var v = n % 100;
                        return n + (s[(v-20)%10] || s[v] || s[0]);
                    };

                    var dateStr = months[month - 1] + ' ' + ordinal(day) + ', ' + year;

                    if (timeVal) {
                        var timeParts = timeVal.split(':');
                        var hours = parseInt(timeParts[0], 10);
                        var minutes = timeParts[1] || '00';
                        var ampm = hours >= 12 ? 'PM' : 'AM';
                        var h12 = hours % 12 || 12;
                        dateStr += ' at ' + h12 + ':' + minutes + ' ' + ampm;
                    }

                    parts.push('which will expire ' + dateStr);
                }
            }

            if (maxUses === 1) {
                parts.push('or after a single use.');
            } else if (maxUses > 1) {
                parts.push('or after ' + maxUses + ' uses.');
            } else {
                // unlimited
                var lastIdx = parts.length - 1;
                parts[lastIdx] = parts[lastIdx] + '.';
            }

            $('#qentry-summary-text').text(parts.join(' '));
        }

        // Bind summary updates to form fields
        $(document).on('change', '#qentry-role, #qentry-expiration-date, #qentry-expiration-time', updateSummary);
        $(document).on('input', '#qentry-max-uses', updateSummary);

        $(document).on('submit', '#qentry-create-form', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $('#qentry-create-btn');
            var $spinner = $('#qentry-loading');

            var role = $('#qentry-role').val();
            var email = $('#qentry-email').val();
            var expiryDate = $('#qentry-expiration-date').val();
            var expiryTime = $('#qentry-expiration-time').val();
            var maxUses = parseInt($('#qentry-max-uses').val()) || 0;
            var skipConfirmation = isSkipConfirmationEnabled();

            // Validate required fields
            if (!role) {
                $('#qentry-role').focus();
                return;
            }
            if (!skipConfirmation && !email) {
                alert(qentry_data.i18n.required_fields);
                return;
            }

            $btn.prop('disabled', true);
            $spinner.show();

            $.ajax({
                url: qentry_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'qentry_create_login',
                    nonce: qentry_data.nonce,
                    qentry_role: role,
                    qentry_email: email,
                    qentry_skip_confirmation_code: skipConfirmation ? 1 : 0,
                    qentry_expiration_date: expiryDate,
                    qentry_expiration_time: expiryTime,
                    qentry_max_uses: maxUses
                },
                success: function(response) {
                    if (response.success) {
                        $('#qentry-generated-url').val(response.data.url);
                        updateModalInstructions(!!response.data.skip_confirmation_code);
                        $('#qentry-modal').fadeIn(200);

                        $form[0].reset();
                        $('#qentry-skip-confirmation-code').prop('checked', false);
                        $('#qentry-email').data('stored-value', '');
                        $('#qentry-max-uses').data('stored-value', 0);
                        $('#qentry-expiration-time').val('23:59');
                        $('#qentry-max-uses').val(0);
                        updateSkipConfirmationState();
                    } else {
                        alert(response.data.message || qentry_data.i18n.error);
                    }
                },
                error: function() {
                    alert(qentry_data.i18n.error);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $spinner.hide();
                }
            });
        });
        
        $('#qentry-modal').on('click', '.qentry-modal-close, .qentry-modal-close-btn', function() {
            $('#qentry-modal').fadeOut(200);
        });
        
        $('#qentry-modal').on('click', function(e) {
            if ($(e.target).hasClass('qentry-modal')) {
                $(this).fadeOut(200);
            }
        });
        
        $('#qentry-copy-url').on('click', function() {
            var $input = $('#qentry-generated-url');
            $input.select();
            $input[0].setSelectionRange(0, 99999);
            
            try {
                document.execCommand('copy');
                $(this).html('<span class="dashicons dashicons-yes"></span> Copied!');
                setTimeout(() => {
                    $(this).html('<span class="dashicons dashicons-clipboard"></span> Copy');
                }, 2000);
            } catch (err) {
                navigator.clipboard.writeText($input.val()).then(() => {
                    $(this).html('<span class="dashicons dashicons-yes"></span> Copied!');
                    setTimeout(() => {
                        $(this).html('<span class="dashicons dashicons-clipboard"></span> Copy');
                    }, 2000);
                });
            }
        });
        
        $(document).on('click', '.qentry-copy-btn', function() {
            var url = $(this).data('url');
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(() => {
                    showNotification(qentry_data.i18n.copy_success, 'success');
                });
            } else {
                var $temp = $('<input>');
                $('body').append($temp);
                $temp.val(url).select();
                try {
                    document.execCommand('copy');
                    showNotification(qentry_data.i18n.copy_success, 'success');
                } catch(e) {
                    showNotification('Failed to copy URL', 'error');
                }
                $temp.remove();
            }
        });
        
        $(document).on('click', '.qentry-delete-btn', function() {
            var $btn = $(this);
            var id = $btn.data('id');
            
            if (!confirm(qentry_data.i18n.confirm_delete)) {
                return;
            }
            
            $.ajax({
                url: qentry_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'qentry_delete_login',
                    nonce: qentry_data.nonce,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        $btn.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                        });
                        showNotification(qentry_data.i18n.deleted, 'success');
                    } else {
                        showNotification(response.data.message || 'Failed to delete', 'error');
                    }
                },
                error: function() {
                    showNotification('An error occurred', 'error');
                }
            });
        });
        
        $(document).on('click', '.qentry-resend-btn', function() {
            var $btn = $(this);
            var id = $btn.data('id');
            var email = $btn.data('email');
            
            $btn.prop('disabled', true).html('<span class="qentry-spinner"></span> Sending...');
            
            $.ajax({
                url: qentry_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'qentry_resend_code',
                    nonce: qentry_data.nonce,
                    id: id,
                    email: email
                },
                success: function(response) {
                    if (response.success) {
                        showNotification('Code resent to ' + email, 'success');
                        $btn.html('<span class="dashicons dashicons-yes"></span>');
                        setTimeout(() => {
                            $btn.html('<span class="dashicons dashicons-email"></span>');
                            $btn.prop('disabled', false);
                        }, 3000);
                    } else {
                        showNotification(response.data.message || 'Failed to resend code', 'error');
                        $btn.html('<span class="dashicons dashicons-email"></span>');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    showNotification('An error occurred', 'error');
                    $btn.html('<span class="dashicons dashicons-email"></span>');
                    $btn.prop('disabled', false);
                }
            });
        });
        
        function showNotification(message, type) {
            var className = type === 'success' ? 'notice-success' : 'notice-error';
            var $notice = $('<div class="notice ' + className + ' is-dismissible"><p></p></div>');
            $notice.find('p').text(message); // Use .text() (textContent) instead of HTML injection to prevent XSS

            $('.notice').remove();

            $('.qentry-admin-wrap > h1').after($notice);

            setTimeout(() => {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }

        // Activity logging toggle - hide filters/table on load if disabled
        if (!$('#qentry-logging-toggle').is(':checked')) {
            $('.qentry-activity-section').removeClass('qentry-logging-enabled');
            $('.qentry-activity-table-wrap').hide();
            $('.qentry-log-filters-left').hide();
            $('.qentry-logging-disabled').show();
        }

        $('#qentry-logging-toggle').on('change', function() {
            var enabled = $(this).is(':checked');

            $.ajax({
                url: qentry_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'qentry_toggle_logging',
                    nonce: $('#qentry-logging-nonce').val(),
                    enabled: enabled.toString()
                },
                success: function(response) {
                    if (response.success) {
                        showNotification(response.data.message, 'success');
                        if (enabled) {
                            $('.qentry-activity-section').addClass('qentry-logging-enabled');
                            $('.qentry-activity-table-wrap').show();
                            $('.qentry-log-filters-left').show();
                            $('.qentry-logging-disabled').hide();
                        } else {
                            $('.qentry-activity-section').removeClass('qentry-logging-enabled');
                            $('.qentry-activity-table-wrap').hide();
                            $('.qentry-log-filters-left').hide();
                            $('.qentry-logging-disabled').show();
                        }
                    } else {
                        showNotification('Failed to update logging setting', 'error');
                        // Revert toggle on failure
                        $('#qentry-logging-toggle').prop('checked', !enabled);
                    }
                },
                error: function() {
                    showNotification('An error occurred', 'error');
                    $('#qentry-logging-toggle').prop('checked', !enabled);
                }
            });
        });

        updateSkipConfirmationState();
    });

})(jQuery);
