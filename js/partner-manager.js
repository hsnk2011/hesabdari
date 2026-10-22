// /js/partner-manager.js

const PartnerManager = (function () {

    function renderPartnersList(partners) {
        const list = $('#partners-list').empty();
        if (!partners || partners.length === 0) return;

        partners.forEach(p => {
            const item = $(`<li class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="my-0">${UI.escapeHtml(p.name)}</h6>
                    <small class="text-muted">سهم: ${p.share * 100}%${p.phone ? ' — ' + UI.escapeHtml(p.phone) : ''}</small>
                </div>
                <span>
                    <button class="btn btn-sm btn-outline-warning btn-edit" data-type="partner" data-permission-action="manage_settings"><i class="bi bi-pencil-square"></i></button> 
                    <button class="btn btn-sm btn-outline-danger btn-delete" data-type="partner" data-id="${p.id}" data-permission-action="manage_settings"><i class="bi bi-trash"></i></button>
                </span>
            </li>`);
            item.find('.btn-edit').data('entity', p);
            list.append(item);
        });
        App.applyPermissions();
    }

    function preparePartnerModal(partner = null) {
        UI.hideModalError('#partnerModal');
        $('#partner-form')[0].reset();
        $('#partner-id').val('');
        if (partner) {
            $('#partnerModalTitle').text('ویرایش شریک');
            $('#partner-id').val(partner.id);
            $('#partner-name').val(partner.name);
            $('#partner-phone').val(partner.phone || '');
            $('#partner-share').val(partner.share);
        } else {
            $('#partnerModalTitle').text('افزودن شریک');
        }
        $('#partnerModal').modal('show');
    }

    async function handlePartnerFormSubmit(e) {
        e.preventDefault();
        UI.hideModalError('#partnerModal');
        const data = {
            id: $('#partner-id').val(),
            name: $('#partner-name').val(),
            phone: $('#partner-phone').val(),
            share: $('#partner-share').val()
        };
        UI.showLoader();
        const result = await Api.call('save_partner', data);
        UI.hideLoader();
        if (result?.success) {
            UI.showSuccess('اطلاعات شریک با موفقیت ذخیره شد.');
            $('#partnerModal').modal('hide');
            await App.updateCache(['partners', 'accounts']); // Update cache
            refreshUI(); // Refresh the displayed list
        } else if (result?.error) {
            UI.showModalError('#partnerModal', result.error);
        }
    }

    async function handlePartnerDelete(id) {
        UI.confirmAction(`آیا از حذف این شریک مطمئن هستید؟`, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                const result = await Api.call(`delete_partner`, { id });
                UI.hideLoader();
                if (result?.success) {
                    UI.showSuccess('شریک با موفقیت حذف شد.');
                    await App.updateCache(['partners', 'accounts']);
                    refreshUI();
                }
            }
        });
    }

    function attachEvents() {
        $('#add-partner-btn').on('click', () => preparePartnerModal(null));
        $('#partner-form').on('submit', handlePartnerFormSubmit);

        $('body').on('click', '#partners-list .btn-edit', function () {
            preparePartnerModal($(this).data('entity'));
        });
        $('body').on('click', '#partners-list .btn-delete', function () {
            handlePartnerDelete($(this).data('id'));
        });
    }

    function refreshUI() {
        renderPartnersList(App.getCache().partners || []);
    }

    async function load() {
        refreshUI();
    }

    function init() {
        attachEvents();
    }

    return {
        init: init,
        load,
        refreshUI,
    };
})();