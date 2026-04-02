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
        
        function toggleMaxUses() {
            if ($('#qentry-usage-multiple').is(':checked')) {
                $('#qentry-max-uses-container').slideDown(200);
            } else {
                $('#qentry-max-uses-container').slideUp(200);
                $('#qentry-max-uses').val(0);
            }
        }
        
        $(document).on('change', 'input[name="qentry_usage_type"]', toggleMaxUses);
        toggleMaxUses();
        
        $('.qentry-date-picker').datepicker({
            dateFormat: 'mm/dd/yy',
            minDate: 0,
            changeMonth: true,
            changeYear: true
        });
        
        $(document).on('submit', '#qentry-create-form', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $btn = $('#qentry-create-btn');
            var $spinner = $('#qentry-loading');
            
            var role = $('#qentry-role').val();
            var email = $('#qentry-email').val();
            var expiryDate = $('#qentry-expiration-date').val();
            var expiryTime = $('#qentry-expiration-time').val();
            
            if (!role || !email || !expiryDate || !expiryTime) {
                alert('Please fill in all required fields.');
                return;
            }
            
            $btn.prop('disabled', true);
            $spinner.show();
            
            var usageType = $('input[name="qentry_usage_type"]:checked').val();
            var maxUses = usageType === 'one_time' ? 1 : parseInt($('#qentry-max-uses').val()) || 0;
            
            $.ajax({
                url: qentry_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'qentry_create_login',
                    nonce: qentry_data.nonce,
                    qentry_role: role,
                    qentry_email: email,
                    qentry_expiration_date: expiryDate,
                    qentry_expiration_time: expiryTime,
                    qentry_usage_type: usageType,
                    qentry_max_uses: maxUses
                },
                success: function(response) {
                    if (response.success) {
                        $('#qentry-generated-url').val(response.data.url);
                        $('#qentry-modal').fadeIn(200);
                        
                        $form[0].reset();
                        $('#qentry-expiration-time').val('23:59');
                        $('#qentry-max-uses').val(0);
                        toggleMaxUses();
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
            var $notice = $('<div class="notice ' + className + ' is-dismissible"><p>' + message + '</p></div>');
            
            $('.notice').remove();
            
            $('.qentry-admin-wrap > h1').after($notice);
            
            setTimeout(() => {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }
    });
    
})(jQuery);