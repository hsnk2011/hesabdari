// /js/config.js
const AppConfig = {
    API_URL: 'api.php',
    TABLE_STATES: {
        accounts: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' }, // <<-- کاما در اینجا اضافه شد
        customers: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        suppliers: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        products: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        sales_invoices: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        purchase_invoices: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        expenses: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        partner_transactions: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        checks: { currentPage: 1, limit: 10, sortBy: 'dueDate', sortOrder: 'ASC', searchTerm: '' },
        consignment_sales: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        consignment_purchases: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        users: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        activity_log: { currentPage: 1, limit: 25, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
    },
    EXPENSE_CATEGORIES: [
        "اجاره",
        "حقوق و دستمزد",
        "قبوض (آب، برق، گاز، تلفن)",
        "حمل و نقل",
        "ملزومات اداری و فروشگاه",
        "تبلیغات و بازاریابی",
        "پذیرایی و تشریفات",
        "تعمیر و نگهداری",
        "نظافت و خدمات",
        "متفرقه"
    ],
    STANDARD_CARPET_DIMENSIONS: ["1x1.5", "1x2", "1x3", "1x4", "1.5x2.25", "1.7x2.4", "2x3", "2.5x3.5", "3x4"]
};