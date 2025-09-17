// /js/dashboard.js

const Dashboard = (function () {

    function render(data) {
        if (!data) return;

        $('#dashboard-total-sales').text(UI.formatCurrency(data.salesLast30Days));

        const supplierChartCanvas = document.getElementById('topSuppliersChart');
        if (supplierChartCanvas) {
            const existingChart = Chart.getChart("topSuppliersChart");
            if (existingChart) {
                existingChart.destroy();
            }
            if (data.topIndebtedSuppliers && data.topIndebtedSuppliers.length > 0) {
                const labels = data.topIndebtedSuppliers.map(s => s.name);
                const values = data.topIndebtedSuppliers.map(s => s.total_debt);
                new Chart(supplierChartCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'مبلغ بدهی',
                            data: values,
                            backgroundColor: '#ffc107',
                            borderColor: '#ffc107',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (context) {
                                        return UI.formatCurrency(context.raw);
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                ticks: {
                                    callback: function (value, index, values) {
                                        if (value >= 1000000) return value / 1000000 + ' M';
                                        if (value >= 1000) return value / 1000 + ' K';
                                        return value;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        const unsettledCustomersBody = $('#dashboard-unsettled-customers');
        unsettledCustomersBody.empty();
        if (data.unsettledCustomers && data.unsettledCustomers.length > 0) {
            data.unsettledCustomers.forEach(c => {
                const row = $(`<tr class="clickable-row" data-report-type="persons" data-person-id="${c.id}" data-person-type="customer" style="cursor: pointer;">
                    <td>${c.name}</td>
                    <td class="text-danger fw-bold">${UI.formatCurrency(c.total_debt)}</td>
                </tr>`);
                unsettledCustomersBody.append(row);
            });
        } else {
            unsettledCustomersBody.html('<tr><td colspan="2" class="text-center">مشتری بدهکاری یافت نشد.</td></tr>');
        }

        let receivedHtml = '';
        (data.dueReceivedChecks || []).forEach(chk => {
            const dueDate = UI.gregorianToPersian(chk.dueDate);
            // FIX: Added personName and data-check-number for click functionality
            receivedHtml += `<tr class="clickable-row" data-report-type="checks" data-check-number="${chk.checkNumber}" style="cursor: pointer;">
                <td>${chk.checkNumber}</td>
                <td>${chk.personName}</td>
                <td>${UI.formatCurrency(chk.amount)}</td>
                <td>${dueDate}</td>
            </tr>`;
        });
        $('#dashboard-due-received-checks').html(receivedHtml || '<tr><td colspan="4" class="text-center">چکی یافت نشد.</td></tr>');

        let payableHtml = '';
        (data.duePayableChecks || []).forEach(chk => {
            const dueDate = UI.gregorianToPersian(chk.dueDate);
            // FIX: Added personName and data-check-number for click functionality
            payableHtml += `<tr class="clickable-row" data-report-type="checks" data-check-number="${chk.checkNumber}" style="cursor: pointer;">
                <td>${chk.checkNumber}</td>
                <td>${chk.personName}</td>
                <td>${UI.formatCurrency(chk.amount)}</td>
                <td>${dueDate}</td>
            </tr>`;
        });
        $('#dashboard-due-payable-checks').html(payableHtml || '<tr><td colspan="4" class="text-center">چکی یافت نشد.</td></tr>');

        const expenseChartCanvas = document.getElementById('expensesPieChart');
        if (expenseChartCanvas) {
            const existingChart = Chart.getChart("expensesPieChart");
            if (existingChart) {
                existingChart.destroy();
            }
            const expenseData = data.expensesByCategory;
            if (expenseData && typeof expenseData === 'object' && Object.keys(expenseData).length > 0) {
                const labels = Object.keys(expenseData);
                const dataValues = Object.values(expenseData);
                new Chart(expenseChartCanvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'مبلغ هزینه',
                            data: dataValues,
                            backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#E7E9ED', '#8B0000', '#2E8B57', '#B8860B'],
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
                                labels: { font: { family: "Vazirmatn" } }
                            }
                        }
                    }
                });
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