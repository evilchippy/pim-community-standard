"use strict";

define(
    [
        'underscore',
        'oro/translator',
        'pim/form',
        'shopify/template/configuration/tab/importsettings',
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
            label: __('shopify.importsettings.tab'),
            template: _.template(template),
            code: 'shopify_connector_importsettings',
            errors: [],
            events: {
                'change .AknFormContainer-Mappings input': 'updateModel',
                'change .shopify-importsettings select.label-field': 'updateModel',
                'click .field-add': 'addField', 
                'click .family-change': 'changeFamily',
                'click .AknIconButton--remove.delete-row': 'removeField', 
                // 'click .shopify-importsettings .ak-view-all': 'showAllMappings',                
            },
            fields: null,
            attributes: null,
            fieldsUrl: 'webkul_shopify_connector_configuration_action',
            families: [],
            familiyfields: null,

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
                var families;
                var familiyfields;

                if(this.fields && this.attributes) {
                    fields = this.fields;
                    attributes = this.attributes;
                   
                } else {
                    attributes = FetcherRegistry.getFetcher('attribute').search({options: {'page': 1, 'limit': 10000 } });
                    fields = new Array();
                }

                if(this.familiyfields && this.families) {
                    familiyfields = this.familiyfields;
                    families = this.families;
                   
                } else {
                    families = FetcherRegistry.getFetcher('family').search({options: {'page': 1, 'limit': 10000 } });
                    familiyfields = new Array();
                }
                
                var self = this; 
                Promise.all([attributes, fields, families, familiyfields]).then(function (values) {
                    $('#container .AknButtonList[data-drop-zone="buttons"] div:nth-of-type(1)').show();
                    self.attributes = values[0];
                    self.fields = values[1];
                    self.families = values[2];
                    self.familiyfields = values[3];

                    var formData = self.getFormData();  
                    
                    
                    //check if family not present
                    if(formData['otherimportsetting'].length<1){
                        var families = Object.values(self.families);
                        if(typeof(families[0]) === "undefined") 
                        { 
                            families[0] = {'code' : ''}
                        }
                        // formData['otherimportsetting'] = {'family' : families[0]['code']};
                        // this.setData(formData);
                    }
                    
                    if(formData) {
                        var extraFields = this.generateExtraFields(self.fields, formData);
                        $.each(extraFields, function(key,field) {
                            self.fields.push(field); 
                        });
                    }
                    
                    self.$el.html(self.template({
                        fields: self.fields,
                        model: formData,
                        errors: self.errors,
                        attributes: self.attributes,
                        families: self.families,
                        familiyfields: self.familiyfields,
                        currentLocale: UserContext.get('uiLocale'),
                    }));

                    $('.shopify-importsettings .select2').each(function(key, select) {
                        if($(select).attr('readonly')) {
                            $(select).select2().select2('readonly', true);
                        } else {
                            $(select).select2();
                        }
                    });

                    $('.shopify-importsettings *[data-toggle="tooltip"]').tooltip();
                    self.$('.switch').bootstrapSwitch();

                    loadingMask.hide().$el.remove();
                }.bind(this));

                this.delegateEvents();

                return BaseForm.prototype.render.apply(this, arguments);
            },

            
            /**
             * {@inheritdoc}
             */
            postRender: function () {
                this.$('.switch').bootstrapSwitch();
            },

            generateExtraFields: function(fields, formData) {
                
                var extraFields = [];
                var fieldCodes = [];
                $.each(fields, function(key, value) {
                    fieldCodes.push(value.name);
                });

                if('undefined' !== typeof(formData['importsettings']) && formData['importsettings']) {
                    $.each(formData['importsettings'], function(key, value) {
                        if(-1 === fieldCodes.indexOf(key)) {
                            extraFields.push({
                                    'name': key,
                                    'types': null,
                                    'dynamic': true
                            });
                        }
                    });

                }

                return extraFields;
                
            },


            dataWrappers: ['importsettings', 'otherimportsetting'],
            /**
             * Update model after value change
             *
             * @param {Event} event
             */
            updateModel: function (event) {
                
                var data = this.getFormData();
                
                var index = $(event.target).attr('data-wrapper') ? $(event.target).attr('data-wrapper') : 'others';
                
                $.each(this.dataWrappers, function(key, value) {
                    if(typeof(data[value]) === 'undefined' || typeof(data[value]) !== 'object' || data[value] instanceof Array) {
                        data[value] = {};
                    }
                }); 
                 
                    var target = $(event.target); 
                    var selectorStr = 'attributes'  ;
                    var otherElem = $('*[name=' + $(event.target).attr("name") + '][data-wrapper=' + selectorStr + ']');
                    
                    var attrValue;

                    if(['commonimage'].indexOf($(event.target).attr('name')) !== -1 ){
                        attrValue = $(event.target).val() ? $(event.target).val() : [];
                    }else if(['variantimage'].indexOf($(event.target).attr('name')) !== -1 ){
                        attrValue = $(event.target).val() !== 'Select Akeneo Attribute' ? $(event.target).val() : '';
                    }else{
                        attrValue = $(event.target).val();
                    }
                    
                    if( $(event.target).attr('name') == "smart_collection"){
                        var val = $(event.target).is(':checked');
                        data[index][$(event.target).attr('name')] = val;    
                        
                    }else{
                        data[index][$(event.target).attr('name')] = attrValue;
                    }
                    
                
                this.setData(data);
            },

            addField: function(e) {
                this.resetDynamicErrors(e);

                var field = $('#dynamic-filed-input');
                var val = field.val();
                
                if(val && this.reservedAttributes.indexOf(val.toLowerCase()) === -1 ) {
                    field.val('');
                    var newField = {
                                'name': val,
                                'types': null,
                                'dynamic': true
                            };
                    
                    this.fields.push(newField);
                     
                    this.render();
                } else {
                    this.addDynamicErrors(e);
                }
            },

            changeFamily: function(e){
                
                var field = $('#pim_enrich_entity_form_family');
                if(field.attr('readonly') == 'readonly'){
                    // alert('If you Change Family After Import family_variant property cannot be modified');
                    field.removeAttr("readonly");
                }
            },

            addDynamicErrors: function(e) {
                $(e.target).closest('.field-group').append('<span class="AknFieldContainer-validationError"><i class="icon-warning-sign"></i><span class="error-message">This value is not valid.</span></span>');
            },

            removeField: function(e) {
                var fieldId = $(e.target).attr('data-id');
                var fieldName = $(event.target).attr('data-name');
                this.fields.splice(fieldId, 1);

                var data = this.getFormData();
                if('undefined' !== typeof(data['importsettings'])) {
                    delete data['importsettings'][fieldName];
                }
                this.setData(data);

                this.render();                
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