"use strict";

define(
    [
        'underscore',
        'oro/translator',
        'pim/form',
        'shopify/template/configuration/tab/documentation',
        'routing'
    ],
    function(
        _,
        __,
        BaseForm,
        template,
        Routing
    ) {
        return BaseForm.extend({
            isGroup: true,
            label: __('shopify.documentation'),
            template: _.template(template),
            code: 'shopify.documentation.tab',
            events: {
                'click .wk_toggler': 'toggleClass',
            },
            /**
             * {@inheritdoc}
             */
            configure: function () {
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
                this.$el.html(this.template({
                    fields: this.fields,
                    model: this.getFormData()['mappings'],
                }));

                this.delegateEvents();

                return BaseForm.prototype.render.apply(this, arguments);
            },

            toggleClass: function() {
                $('.wk_toggler').toggleClass('active');
            },
        });
    }
);
