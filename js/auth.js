// =================================================================
// AUTHENTICATION MODULE (js/auth.js)
// =================================================================
const Auth = (function () {
    let currentUser = null;

    function showLoginScreen() {
        $('.main-container').hide();
        $('#logout-btn, #show-change-password-modal-btn').addClass('d-none');
        $('#login-overlay').css('display', 'flex');
    }

    function showMainApp(username) {
        currentUser = username;
        $('#login-overlay').hide();
        $('.main-container').show();
        $('#logout-btn, #show-change-password-modal-btn').removeClass('d-none');
        $('#user-info').text(`کاربر: ${username}`);
        App.initMainApp(username); // Notify main app to continue initialization
    }

    async function checkSession() {
        UI.showLoader();
        const session = await Api.call('check_session', {}, false);
        if (session && session.loggedIn) {
            await showMainApp(session.username);
        } else {
            showLoginScreen();
        }
        UI.hideLoader();
    }

    function getCurrentUser() {
        return currentUser;
    }

    function attachEvents() {
        $('#login-form').on('submit', async function (e) {
            e.preventDefault();
            UI.showLoader();
            const result = await Api.call('login', { username: $('#username').val(), password: $('#password').val() }, false);
            if (result && result.success) {
                await showMainApp(result.username);
            } else {
                $('#login-error').text(result ? result.error : 'خطای ناشناخته در ارتباط با سرور.');
            }
            UI.hideLoader();
        });

        $('#logout-btn').on('click', async () => {
            await Api.call('logout');
            location.reload();
        });

        // User management modals
        $('#show-register-modal-btn').on('click', (e) => {
            e.preventDefault();
            $('#register-form')[0].reset();
            $('#register-error').text('');
            $('#registerModal').modal('show');
        });

        $('#register-form').on('submit', async (e) => {
            e.preventDefault();
            const username = $('#register-username').val(),
                password = $('#register-password').val(),
                confirm = $('#register-password-confirm').val(),
                errorDiv = $('#register-error').text('');

            if (password !== confirm) { errorDiv.text('رمزهای عبور مطابقت ندارند.'); return; }
            if (password.length < 6) { errorDiv.text('رمز عبور باید حداقل ۶ کاراکتر باشد.'); return; }
            const result = await Api.call('register', { username, password });
            if (result?.success) {
                alert('کاربر جدید ثبت شد.');
                $('#registerModal').modal('hide');
                App.getManager('users').load();
            } else if (result) {
                errorDiv.text(result.error);
            }
        });

        $('#show-change-password-modal-btn').on('click', () => {
            $('#change-password-form')[0].reset();
            $('#change-password-error').text('');
            $('#changePasswordModal').modal('show');
        });

        $('#change-password-form').on('submit', async (e) => {
            e.preventDefault();
            const current_password = $('#current-password').val(),
                new_password = $('#new-password').val(),
                confirm = $('#new-password-confirm').val(),
                errorDiv = $('#change-password-error').text('');
            if (new_password !== confirm) { errorDiv.text('رمزهای جدید مطابقت ندارند.'); return; }
            if (new_password.length < 6) { errorDiv.text('رمز عبور جدید باید حداقل ۶ کاراکتر باشد.'); return; }
            const result = await Api.call('change_password', { current_password, new_password });
            if (result?.success) {
                alert('رمز عبور با موفقیت تغییر کرد.');
                $('#changePasswordModal').modal('hide');
            } else if (result) {
                errorDiv.text(result.error);
            }
        });

        $('body').on('click', '.btn-reset-password', function () {
            $('#reset-user-id').val($(this).data('id'));
            $('#reset-username-display').text($(this).data('username'));
            $('#reset-password-form')[0].reset();
            $('#reset-password-error').text('');
            $('#resetPasswordModal').modal('show');
        });

        $('#reset-password-form').on('submit', async (e) => {
            e.preventDefault();
            const userId = $('#reset-user-id').val(),
                newPassword = $('#reset-new-password').val(),
                confirm = $('#reset-new-password-confirm').val(),
                errorDiv = $('#reset-password-error').text('');
            if (newPassword !== confirm) { errorDiv.text('رمزهای عبور مطابقت ندارند.'); return; }
            if (newPassword.length < 6) { errorDiv.text('رمز عبور جدید باید حداقل ۶ کاراکتر باشد.'); return; }
            const result = await Api.call('admin_reset_password', { userId, newPassword });
            if (result?.success) {
                alert('رمز عبور کاربر بازنشانی شد.');
                $('#resetPasswordModal').modal('hide');
            } else if (result) {
                errorDiv.text(result.error);
            }
        });
    }

    return {
        init: () => {
            attachEvents();
            checkSession();
        },
        getCurrentUser
    };
})();