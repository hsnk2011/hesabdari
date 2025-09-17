// /js/settings-manager.js

const SettingsManager = (function () {
    const config = {
        tableName: 'settings',
        apiName: 'app_settings',
        formId: '#settings-form',
    };
    let appSettings = {};
    let businessEntities = [];

    function renderForm() {
        // Render App Title
        $('#setting-app-title').val(appSettings.app_title || 'سیستم حسابداری');

        // Render Business Entities
        const container = $('#business-entities-container').empty();
        if (businessEntities && businessEntities.length > 0) {
            businessEntities.forEach(entity => {
                const field = $(`<div class="mb-2 row">
                    <label for="entity-name-${entity.id}" class="col-sm-3 col-form-label">نام مجموعه #${entity.id}</label>
                    <div class="col-sm-9">
                        <input type="text" class="form-control" id="entity-name-${entity.id}" data-entity-id="${entity.id}" value="${entity.name}">
                    </div>
                </div>`);
                container.append(field);
            });
        }
    }

    async function load() {
        UI.showLoader();
        const response = await Api.call('get_app_settings');
        UI.hideLoader();
        if (response) {
            appSettings = response.settings || {};
            businessEntities = response.entities || [];
            renderForm();
        }
    }

    async function handleFormSubmit(e) {
        e.preventDefault();
        UI.hideModalError(config.formId);

        const settingsData = {
            app_title: $('#setting-app-title').val(),
        };

        const entitiesData = [];
        $('#business-entities-container input').each(function () {
            entitiesData.push({
                id: $(this).data('entity-id'),
                name: $(this).val()
            });
        });

        const dataToSend = {
            settings: settingsData,
            entities: entitiesData
        };

        UI.showLoader();
        const result = await Api.call('save_app_settings', dataToSend);
        UI.hideLoader();

        if (result?.success) {
            UI.showSuccess('تنظیمات با موفقیت ذخیره شد. برنامه مجدداً بارگذاری می‌شود...');
            // Reload the page to ensure all components are updated with new settings
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else if (result?.error) {
            UI.showModalError(config.formId, result.error);
        }
    }

    function init() {
        $(config.formId).on('submit', handleFormSubmit);
    }

    return {
        init,
        load,
        config
    };
})();