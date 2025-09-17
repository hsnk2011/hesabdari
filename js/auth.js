// =================================================================
// AUTHENTICATION MODULE (js/auth.js)
// =================================================================
const Auth = (function () {
    const self = {}; // Use an internal object to manage scope
    self.currentUser = null;

    self.showLoginScreen = function () {
        $('.main-container').hide();
        $('#logout-btn, #show-change-password-modal-btn').addClass('d-none');
        $('#login-overlay').css('display', 'flex');
    };

    self.showMainApp = function (sessionData) {
        if (!sessionData || !sessionData.business_entities) {
            console.error("Critical Error: showMainApp called without business_entities in sessionData.", sessionData);
            UI.showError("خطای راه‌اندازی برنامه. لطفاً صفحه را رفرش کنید.");
            return;
        }
        self.currentUser = sessionData.username;
        $('#login-overlay').hide();
        $('.main-container').show();
        $('#logout-btn, #show-change-password-modal-btn').removeClass('d-none');
        $('#user-info').text(`کاربر: ${sessionData.username}`);
        App.initMainApp(sessionData);
    };

    self.checkSession = async function () {
        UI.showLoader();
        const session = await Api.call('check_session', {}, false);
        UI.hideLoader();
        return session;
    };

    self.getCurrentUser = function () {
        return self.currentUser;
    };

    self.attachEvents = function () {
        $('#login-form').on('submit', async function (e) {
            e.preventDefault();
            UI.showLoader();
            const errorDiv = $('#login-error');
            errorDiv.text('');

            // FIX: The login API now returns the full session object.
            const result = await Api.call('login', { username: $('#username').val(), password: $('#password').val() }, false);
            UI.hideLoader();

            if (result && result.success) {
                // No need to call checkSession anymore. The login result has everything.
                self.showMainApp(result);
            } else if (result && result.error) {
                errorDiv.text(result.error);
            } else {
                errorDiv.text('خطای ناشناخته در ارتباط با سرور.');
            }
        });

        $('#logout-btn').on('click', async () => {
            await Api.call('logout');
            location.reload();
        });

        $('#show-register-modal-btn').on('click', (e) => {
            e.preventDefault();
            UI.hideModalError('#registerModal');
            $('#register-form')[0].reset();
            $('#registerModal').modal('show');
        });

        $('#register-form').on('submit', async (e) => {
            e.preventDefault();
            UI.hideModalError('#registerModal');
            const username = $('#register-username').val();
            const password = $('#register-password').val();
            const confirm = $('#register-password-confirm').val();

            if (password !== confirm) {
                UI.showModalError('#registerModal', 'رمزهای عبور مطابقت ندارند.');
                return;
            }
            if (password.length < 6) {
                UI.showModalError('#registerModal', 'رمز عبور باید حداقل ۶ کاراکتر باشد.');
                return;
            }
            const result = await Api.call('register', { username, password });
            if (result?.success) {
                UI.showSuccess('کاربر جدید با موفقیت ثبت شد.');
                $('#registerModal').modal('hide');
                App.getManager('users').load();
            } else if (result?.error) {
                UI.showModalError('#registerModal', result.error);
            }
        });

        $('#show-change-password-modal-btn').on('click', () => {
            UI.hideModalError('#changePasswordModal');
            $('#change-password-form')[0].reset();
            $('#changePasswordModal').modal('show');
        });

        $('#change-password-form').on('submit', async (e) => {
            e.preventDefault();
            UI.hideModalError('#changePasswordModal');
            const current_password = $('#current-password').val();
            const new_password = $('#new-password').val();
            const confirm = $('#new-password-confirm').val();

            if (new_password !== confirm) {
                UI.showModalError('#changePasswordModal', 'رمزهای جدید مطابقت ندارند.');
                return;
            }
            if (new_password.length < 6) {
                UI.showModalError('#changePasswordModal', 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.');
                return;
            }

            const result = await Api.call('change_password', { current_password, new_password });
            if (result?.success) {
                UI.showSuccess('رمز عبور با موفقیت تغییر کرد.');
                $('#changePasswordModal').modal('hide');
            } else if (result?.error) {
                UI.showModalError('#changePasswordModal', result.error);
            }
        });

        $('body').on('click', '.btn-reset-password', function () {
            UI.hideModalError('#resetPasswordModal');
            $('#reset-user-id').val($(this).data('id'));
            $('#reset-username-display').text($(this).data('username'));
            $('#reset-password-form')[0].reset();
            $('#resetPasswordModal').modal('show');
        });

        $('#reset-password-form').on('submit', async (e) => {
            e.preventDefault();
            UI.hideModalError('#resetPasswordModal');
            const userId = $('#reset-user-id').val();
            const newPassword = $('#reset-new-password').val();
            const confirm = $('#reset-new-password-confirm').val();

            if (newPassword !== confirm) {
                UI.showModalError('#resetPasswordModal', 'رمزهای عبور مطابقت ندارند.');
                return;
            }
            if (newPassword.length < 6) {
                UI.showModalError('#resetPasswordModal', 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.');
                return;
            }

            const result = await Api.call('admin_reset_password', { userId, newPassword });
            if (result?.success) {
                UI.showSuccess('رمز عبور کاربر بازنشانی شد.');
                $('#resetPasswordModal').modal('hide');
            } else if (result?.error) {
                UI.showModalError('#resetPasswordModal', result.error);
            }
        });
    };

    // Public interface
    return {
        init: () => {
            self.attachEvents();
            self.checkSession().then(session => {
                if (session && session.loggedIn) {
                    self.showMainApp(session);
                } else {
                    self.showLoginScreen();
                }
            });
        },
        checkSession: self.checkSession,
        getCurrentUser: self.getCurrentUser
    };
})();