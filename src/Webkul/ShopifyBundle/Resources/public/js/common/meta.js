'use strict';

define(
    ['pim/form', 'underscore', 'oro/translator', 'shopify/template/common/meta'],
    function (BaseForm, _, __, template) {
        return BaseForm.extend({
            template: _.template(template),
            config: null,
            /**
             * {@inheritdoc}
             */
            initialize: function (config) {
                this.config = config.config;
                BaseForm.prototype.initialize.apply(this, arguments);
            },

            /**
             * {@inheritdoc}
             */
            render: function () {
                this.$el.html(this.template({
                    label: __(this.config.text),
                    __: __
                }));

                return this;
            }
        });
    }
);
