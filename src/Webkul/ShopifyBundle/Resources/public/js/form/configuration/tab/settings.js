"use strict";

define(
    [
        'underscore',
        'oro/translator',
        'pim/form',
        'shopify/template/configuration/tab/settings',
        'jquery',
        'routing',
        'pim/fetcher-registry',
        'pim/user-context',
        'oro/loading-mask',
        'pim/initselect2'
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
            label: __('shopify.settings.tab'),
            template: _.template(template),
            code: 'shopify_connector_settings',
            errors: [],
            events: {
                'change .AknFormContainer-Mappings input': 'updateModel',
                'change .shopify-settings select.label-field': 'updateModel',
                // 'click .shopify-settings .ak-view-all': 'showAllMappings',                
            },
            fields: null,
            attributes: null,
            fieldsUrl: 'webkul_shopify_connector_configuration_action',
            currencies: [],

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

                var fields;
                var attributes;
                var currencies;
                if(this.fields && this.attributes) {
                    fields = this.fields;
                    attributes = this.attributes;
                } else {
                    fields = FetcherRegistry.getFetcher('shopify-fields').fetchAll();
                    attributes = FetcherRegistry.getFetcher('attribute').search({options: {'page': 1, 'limit': 10000 } });
                }

                currencies = FetcherRegistry.getFetcher('shopify-quickcurrencies').fetchAll();
                
                Promise.all([fields, attributes, currencies]).then(function (values) {
                    $('#container .AknButtonList[data-drop-zone="buttons"] div:nth-of-type(1)').show();
                    this.fields = values[0];
                    this.attributes = values[1];
                    this.currencies = values[2];
                    this.$el.html(this.template({
                        fields: this.fields,
                        model: this.getFormData(),
                        errors: this.errors,
                        attributes: this.attributes,
                        currencies: this.currencies,
                        currentLocale: UserContext.get('uiLocale'),
                    }));

                    $('.shopify-settings .select2').each(function(key, select) {
                        if($(select).attr('readonly')) {
                            $(select).select2().select2('readonly', true);
                        } else {
                            $(select).select2();
                        }
                    });

                    $('.shopify-settings *[data-toggle="tooltip"]').tooltip();

                    loadingMask.hide().$el.remove();
                }.bind(this));

                this.delegateEvents();

                return BaseForm.prototype.render.apply(this, arguments);
            },

            dataWrappers: [ 'defaults', 'settings', 'others', 'quicksettings' ],
            /**
             * Update model after value change
             *
             * @param {Event} event
             */
            updateModel: function (event) {
                var index = $(event.target).attr('data-wrapper') ? $(event.target).attr('data-wrapper') : 'others';
                var data = this.getFormData();

                $.each(this.dataWrappers, function(key, value) {
                    if(typeof(data[value]) === 'undefined' || typeof(data[value]) !== 'object' || data[value] instanceof Array) {
                        data[value] = {};
                    }
                }); 

                if($(event.target).hasClass('quicksettings')){
                    index = 'quicksettings';
                }
                
                if(['defaults', 'settings'].indexOf(index) !== -1) {
                    var target = $(event.target); 
                    var selectorStr = (index == 'defaults') ? 'attributes' : 'defaults';
                    var otherElem = $('*[name=' + $(event.target).attr("name") + '][data-wrapper=' + selectorStr + ']');
                    /* if value is set  */
                    if('undefined' !== typeof(target.val()) && (target.val() || 0 === target.val()) && (target.val().indexOf(' ') === -1 || index === 'defaults') ) {
                        if(otherElem.is('select')) {
                            otherElem.select2('readonly', true);
                        } else {
                            otherElem.attr('readonly', 'readonly');
                        }
                        
                        if(index === 'defaults') {
                            data['settings'][$(event.target).attr('name')] = '';
                        } else if(index === 'settings') {
                            data['defaults'][$(event.target).attr('name')] = '';
                        }
                    } else {
                        /* if value is unset  */
                        $(event.target).val('');
                        if(otherElem.is('select')) {
                            otherElem.select2('readonly', false);
                        } else {
                            otherElem.removeAttr('readonly');
                        }
                    }
                }

                var attrValue;
                if(['meta_fields'].indexOf($(event.target).attr('name')) !== -1) {
                    attrValue = $(event.target).val() ? $(event.target).val() : [];
                } else {
                    attrValue = $(event.target).val();
                }

                data[index][$(event.target).attr('name')] = attrValue;
                this.setData(data);
            },
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
