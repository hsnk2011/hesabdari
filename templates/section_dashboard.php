<div class="tab-pane fade show active" id="dashboard" role="tabpanel">
    <div class="row">
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="card text-white bg-primary h-100">
                <div class="card-body">
                    <h5 class="card-title">فروش ۳۰ روز گذشته</h5>
                    <p class="card-text fs-4" id="dashboard-total-sales">۰ تومان</p>
                </div>
            </div>
        </div>
        <div class="col-lg-8 col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-header">۵ تامین‌کننده‌ای که بیشترین بدهی را به آن‌ها دارید</div>
                <div class="card-body" style="height: 250px;">
                    <canvas id="topSuppliersChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">مشتریان تسویه نشده</div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 300px;">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>نام مشتری</th>
                                    <th>مبلغ بدهی</th>
                                </tr>
                            </thead>
                            <tbody id="dashboard-unsettled-customers"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">نمودار تفکیک هزینه‌ها</div>
                <div class="card-body" style="height: 300px;">
                    <canvas id="expensesPieChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="row mt-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">۵ چک دریافتی نزدیک به سررسید</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>شماره چک</th>
                                    <th>از طرف</th>
                                    <th>مبلغ</th>
                                    <th>تاریخ سررسید</th>
                                </tr>
                            </thead>
                            <tbody id="dashboard-due-received-checks"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">۵ چک پرداختی نزدیک به سررسید</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>شماره چک</th>
                                    <th>به نام</th>
                                    <th>مبلغ</th>
                                    <th>تاریخ سررسید</th>
                                </tr>
                            </thead>
                            <tbody id="dashboard-due-payable-checks"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>