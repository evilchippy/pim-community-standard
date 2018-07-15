'use strict';

define([
        'underscore',
        'jquery',
        'routing',
        'pim/form/common/save',
        'pim/template/form/save'
    ],
    function(
        _,
        $,
        Routing,
        SaveForm,
        template
    ) {
        return SaveForm.extend({
            template: _.template(template),
            currentKey: 'current_form_tab',
            events: {
                'click .save': 'save'
            },

            /**
             * {@inheritdoc}
             */
            render: function () {
                this.$el.html(this.template({
                    label: _.__('pim_enrich.entity.save.label')
                }));
            },

            /**
             * {@inheritdoc}
             */
            save: function () {
                this.getRoot().trigger('pim_enrich:form:entity:pre_save', this.getFormData());
                this.showLoadingMask();

                var data = this.stringify(this.getFormData());

                $.ajax({
                    method: 'POST',
                    url: this.getSaveUrl(),
                    contentType: 'application/json',
                    data: data
                })
                .then(this.postSave.bind(this))
                .fail(this.fail.bind(this))
                .always(this.hideLoadingMask.bind(this));
            },
            /**
             * @var dataWrapper 
             */
            dataWrapper: 'credentials',

            stringify: function(formData) {
                if('undefined' != typeof(formData['settings']) && formData['settings'] instanceof Array) {
                    formData['settings'] = $.extend({}, formData['settings']);
                }
                if(typeof(formData[this.dataWrapper]) === 'undefined' || typeof(formData[this.dataWrapper]) !== 'object' || formData[this.dataWrapper] instanceof Array) {
                    formData[this.dataWrapper] = {};
                }
                formData[this.dataWrapper]['hostname'] = window.location.hostname;

                return JSON.stringify(formData);                
            },

            /**
             * {@inheritdoc}
             */
            getSaveUrl: function () {
                var tab = null;
                switch(sessionStorage.getItem(this.currentKey)) {
                    case 'webkul-magento2-connector-configuration-tab-credential':
                        tab = 'credential';
                        break;
                    case 'webkul-magento2-connector-configuration-tab-store-mapping':
                        tab = 'storeMapping';
                        break;
                    case 'webkul-magento2-connector-configuration-tab-mapping':
                        tab = 'mapping';
                        break;
                    case 'webkul-magento2-connector-configuration-tab-otherSettings':
                        tab = 'otherSettings';
                        break;   
                    case 'webkul-magento2-connector-configuration-tab-association':
                        tab = 'association';
                        break;
                }
                var route = Routing.generate(__moduleConfig.route);
                (sessionStorage.getItem(this.currentKey));

                
                return route;
            },

            /**
             * {@inheritdoc}
             */
            postSave: function (data) {

                this.setData(data);
                this.getRoot().trigger('pim_enrich:form:entity:post_fetch', data);
                SaveForm.prototype.postSave.apply(this, arguments);
            }     
        });
    }
);
