// =================================================================
// DASHBOARD MODULE (js/dashboard.js)
// =================================================================
const Dashboard = (function () {
    function render(data) {
        if (!data) return;

        // Render summary cards
        $('#dashboard-total-sales').text(UI.formatCurrency(data.totalSales));
        $('#dashboard-total-purchases').text(UI.formatCurrency(data.totalPurchases));
        $('#dashboard-total-expenses').text(UI.formatCurrency(data.totalExpenses));
        $('#dashboard-profit-loss').text(UI.formatCurrency(data.profitLoss));

        // Render recent sales table
        let salesHtml = '';
        (data.recentSales || []).forEach(inv => {
            salesHtml += `<tr><td>${inv.id}</td><td>${inv.customerName || ''}</td><td>${UI.formatCurrency(inv.totalAmount)}</td></tr>`;
        });
        $('#dashboard-recent-sales').html(salesHtml || '<tr><td colspan="3" class="text-center">فاکتوری ثبت نشده است.</td></tr>');

        // Render due received checks table
        let receivedHtml = '';
        (data.dueReceivedChecks || []).forEach(chk => {
            receivedHtml += `<tr><td>${chk.checkNumber}</td><td>${UI.formatCurrency(chk.amount)}</td><td>${chk.dueDate}</td></tr>`;
        });
        $('#dashboard-due-received-checks').html(receivedHtml || '<tr><td colspan="3" class="text-center">چکی یافت نشد.</td></tr>');

        // Render due payable checks table
        let payableHtml = '';
        (data.duePayableChecks || []).forEach(chk => {
            payableHtml += `<tr><td>${chk.checkNumber}</td><td>${UI.formatCurrency(chk.amount)}</td><td>${chk.dueDate}</td></tr>`;
        });
        $('#dashboard-due-payable-checks').html(payableHtml || '<tr><td colspan="3" class="text-center">چکی یافت نشد.</td></tr>');

        // --- CORRECTED: Render Expenses Chart ---
        const chartCanvas = document.getElementById('expensesPieChart');
        if (chartCanvas) {
            // Destroy existing chart instance if it exists to prevent errors on reload
            const existingChart = Chart.getChart("expensesPieChart");
            if (existingChart) {
                existingChart.destroy();
            }

            const expenseData = data.expensesByCategory;

            // Check if expenseData is a valid, non-empty object
            if (expenseData && typeof expenseData === 'object' && Object.keys(expenseData).length > 0) {

                // Correctly get labels (categories) and data (amounts) from the object
                const labels = Object.keys(expenseData);
                const dataValues = Object.values(expenseData);

                new Chart(chartCanvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'مبلغ هزینه',
                            data: dataValues,
                            backgroundColor: [
                                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                                '#9966FF', '#FF9F40', '#E7E9ED', '#8B0000',
                                '#2E8B57', '#B8860B'
                            ],
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    font: {
                                        family: "Vazirmatn"
                                    }
                                }
                            },
                            title: {
                                display: false
                            }
                        }
                    }
                });
            } else {
                // If there is no data, show a message instead of a blank chart
                const ctx = chartCanvas.getContext('2d');
                ctx.clearRect(0, 0, chartCanvas.width, chartCanvas.height);
                const parent = chartCanvas.parentNode;
                if (parent.querySelector('.empty-chart-message') === null) {
                    parent.style.position = 'relative';
                    const message = document.createElement('div');
                    message.className = 'empty-chart-message';
                    message.style.cssText = 'position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #888;';
                    message.innerText = 'هزینه‌ای برای نمایش در نمودار وجود ندارد.';
                    parent.appendChild(message);
                }
            }
        }
    }

    async function load() {
        UI.showLoader();
        const data = await Api.call('get_dashboard_data');
        if (data) render(data);
        UI.hideLoader();
    }

    return {
        load: load
    };
})();