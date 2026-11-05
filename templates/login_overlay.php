<div id="login-overlay">
    <div class="ui-login">
        <aside class="ui-login-side">
            <div class="ui-login-brand">
                <span class="ui-brand-mark"><i class="bi bi-journal-bookmark-fill"></i></span>
                <span><?php echo htmlspecialchars($appTitle ?? 'سیستم حسابداری', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="ui-login-pitch">
                <h2>حساب و کتاب کسب‌وکار، یک‌جا و مرتب</h2>
                <ul>
                    <li><i class="bi bi-receipt"></i>فاکتور فروش و خرید با محاسبه خودکار</li>
                    <li><i class="bi bi-credit-card-2-front"></i>پیگیری چک‌ها و سررسیدها</li>
                    <li><i class="bi bi-box-seam"></i>انبار، امانی و لیست قیمت</li>
                    <li><i class="bi bi-graph-up-arrow"></i>گزارش سود، مانده و جریان نقدی</li>
                </ul>
            </div>
        </aside>
        <div id="login-form-container">
            <h3 class="ui-login-title">ورود به حساب</h3>
            <p class="ui-login-sub">نام کاربری و رمز عبور خود را وارد کنید.</p>
            <form id="login-form">
                <div class="mb-3">
                    <label for="username" class="form-label">نام کاربری</label>
                    <div class="ui-input-icon">
                        <i class="bi bi-person"></i>
                        <input type="text" class="form-control" id="username" autocomplete="username" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">رمز عبور</label>
                    <div class="ui-input-icon">
                        <i class="bi bi-lock"></i>
                        <input type="password" class="form-control" id="password" autocomplete="current-password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 ui-login-submit">ورود</button>
                <div id="login-error" class="text-danger mt-3"></div>
            </form>
        </div>
    </div>
</div>
