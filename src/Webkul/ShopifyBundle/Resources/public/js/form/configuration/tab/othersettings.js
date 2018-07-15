"use strict";

define(
    [
        'underscore',
        'oro/translator',
        'pim/form',
        'shopify/template/configuration/tab/othersettings',
        'jquery',
        'routing',
        'pim/fetcher-registry',
        'pim/user-context',
        'oro/loading-mask',
        'pim/initselect2',
        'bootstrap.bootstrapswitch'
    ],
    function(
        _,
        __,
        BaseForm,
        template,
        $,
        Routing,
        FetcherRegistry,
        UserContext,
        LoadingMask,
        initSelect2        
    ) {
        return BaseForm.extend({
            isGroup: true,
            label: __('shopify.othersettings.tab'),
            template: _.template(template),
            code: 'shopify_connector_othersettings',
            errors: [],
            log_path: null,
            events: {
                'change .AknFormContainer-Mappings input': 'updateModel',
            },

            /**
             * {@inheritdoc}
             */
            configure: function () {
                this.listenTo(
                    this.getRoot(),
                    'pim_enrich:form:entity:bad_request',
                    this.setValidationErrors.bind(this)
                );

                this.listenTo(
                    this.getRoot(),
                    'pim_enrich:form:entity:pre_save',
                    this.resetValidationErrors.bind(this)
                );

                this.listenTo(
                    this.getRoot(),
                    'pim_enrich:form:entity:post_fetch',
                    this.render.bind(this)
                );                

                this.trigger('tab:register', {
                    code: this.code,
                    label: this.label
                });

                return BaseForm.prototype.configure.apply(this, arguments);
            },

            /**
             * {@inheritdoc}
             */
            render: function () {
                var loadingMask = new LoadingMask();
                loadingMask.render().$el.appendTo(this.getRoot().$el).show();
                var log_path;
                if(this.log_path) {
                    log_path = this.log_path;
                } else {
                    log_path = Routing.generate('webkul_shopify_connector_configuration_get_logger_path');
                }
                var self = this; 
                Promise.all([log_path]).then(function (values) {
                    $('#container .AknButtonList[data-drop-zone="buttons"] div:nth-of-type(1)').show();
                    self.log_path = values[0];
                    self.$el.html(self.template({
                        model: self.getFormData(),
                        log_path: self.log_path,
                    }));

                  
                    $('.shopify-importsettings *[data-toggle="tooltip"]').tooltip();
                    self.$('.switch').bootstrapSwitch();

                    loadingMask.hide().$el.remove();
                }.bind(this));

                this.delegateEvents();

                return BaseForm.prototype.render.apply(this, arguments);
            },
 
            updateModel: function(event){
                var data = this.getFormData();
                var index = $(event.target).attr('data-wrapper') ? $(event.target).attr('data-wrapper') : 'others';

                if( $(event.target).attr('type') === 'checkbox') {
                    var val = $(event.target).is(':checked');
                    data[index][$(event.target).attr('name')] = val; 
                }

                this.setData(data);
            },

            addDynamicErrors: function(e) {
                $(e.target).closest('.field-group').append('<span class="AknFieldContainer-validationError"><i class="icon-warning-sign"></i><span class="error-message">This value is not valid.</span></span>');
            }, 

            resetDynamicErrors: function(e) {
                $(e.target).closest('.field-group').find('.AknFieldContainer-validationError').remove();
            },
            reservedAttributes: ['sku', 'status', 'name', 'weight', 'price', 'description', 'short_description', 'quantity', 'meta_title', 'meta_keyword', 'meta_description', 'url_key', 'id', 'type_id', 'created_at', 'updated_at', 'attribute_set_id', 'category_ids'],
            /**
             * Sets errors
             *
             * @param {Object} errors
             */
            setValidationErrors: function (errors) {
                this.errors = errors.response;
                this.render();
            },

            /**
             * Resets errors
             */
            resetValidationErrors: function () {
                this.errors = {};
                this.render();
            }
        });
    }
);