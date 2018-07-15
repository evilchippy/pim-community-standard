'use strict';

define([
    'underscore',
    'pim/job/common/edit/field/field',
    'shopify/template/common/password'
], function (
    _,
    BaseField,
    fieldTemplate
) {
    return BaseField.extend({
        fieldTemplate: _.template(fieldTemplate),
        events: {
            'change input': 'updateState'
        },

        /**
         * Get the field dom value
         *
         * @return {string}
         */
        getFieldValue: function () {
            return this.$('input').val();
        }
    });
});
