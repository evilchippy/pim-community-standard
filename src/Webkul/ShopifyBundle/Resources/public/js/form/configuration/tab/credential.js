"use strict";

define(
    [
        'underscore',
        'oro/translator',
        'pim/form',
        'shopify/template/configuration/tab/credential',
        'routing',
    ],
    function(
        _,
        __,
        BaseForm,
        template,
        Routing,
    ) {
        return BaseForm.extend({
            isGroup: true,
            label: __('shopify.credentials.tab'),
            template: _.template(template),
            code: 'shopify_connector_credential',
            credentialsUrl: 'webkul_shopify_connector_configuration_post',
            controls: [{
                    'label' : 'shopify.form.properties.shop_url.title',
                    'placeholder': 'https://example.myshopify.com',
                    'name': 'shopUrl',
                    'type': 'text'
                }, {
                    'label' : 'shopify.form.properties.api_key.title',
                    'placeholder': '',                       
                    'name': 'apiKey',
                    'type': 'text'
                }, {
                    'label' : 'shopify.form.properties.password.title',
                    'name': 'apiPassword',
                    'placeholder': '',
                    'type': 'password'
                }],
            errors: [],
            events: {
                'change .AknFormContainer-Credential input': 'updateModel',
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
                $('#container .AknButtonList[data-drop-zone="buttons"] div:nth-of-type(1)').show();
                var credentials = typeof(this.getFormData()['credentials']) !== 'undefined' ? this.getFormData()['credentials'] : JSON.parse('{}');
                this.$el.html(this.template({
                    controls: this.controls,
                    credentials: credentials,
                    errors: this.errors
                }));

                this.delegateEvents();

                return BaseForm.prototype.render.apply(this, arguments);
            },
            dataWrapper: 'credentials',

            /**
             * Update model after value change
             *
             * @param {Event} event
             */
            updateModel: function (event) {
                var data = this.getFormData();
                if(typeof(data[this.dataWrapper]) === 'undefined' || typeof(data[this.dataWrapper]) !== 'object' || data[this.dataWrapper] instanceof Array) {
                    data[this.dataWrapper] = {};
                }
                data[this.dataWrapper][$(event.target).attr('name')] = event.target.value;
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
            },
        });
    }
);
