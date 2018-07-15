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
                'click *': 'gotoJobs'
            },
            intialize: function() {
                this.render();
            },
            /**
             * {@inheritdoc}
             */
            render: function () {
                this.$el.html(this.template({
                    label: _.__('shopify.export.view.jobs')
                }));
                this.$el.find('.AknButton.AknButton--apply.save').css('margin', '0px 2px');
            },

            /**
             * {@inheritdoc}
             */
            gotoJobs: function() {
                sessionStorage.setItem('export-profile-grid.filters','f%5Bjob_name%5D%5Bvalue%5D%5B%5D=shopify_category_export&f%5Bjob_name%5D%5Bvalue%5D%5B%5D=shopify_export&f%5Bjob_name%5D%5Bvalue%5D%5B%5D=shopify_product_export&t=export-profile-grid');
                var route = '#'+Routing.generate('pim_importexport_export_profile_index', {});
                window.location = route;
            },
        });
    }
);