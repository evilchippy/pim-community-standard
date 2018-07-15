<?php

namespace Webkul\ShopifyBundle\Connector\Writer;

use Akeneo\Component\Batch\Item\ItemWriterInterface;
use Akeneo\Component\Batch\Step\StepExecutionAwareInterface;
use Akeneo\Component\Batch\Model\StepExecution;
use Akeneo\Component\Batch\Item\DataInvalidItem;
use Webkul\ShopifyBundle\Traits\DataMappingTrait;
use Webkul\ShopifyBundle\Entity\DataMapping;
use Symfony\Component\HttpFoundation\Response;

/**
 * Add products to Shopify
 *
 * @author    Webkul
 * @copyright 2010-2017 Webkul pvt. ltd.
 * @license   https://store.webkul.com/license.html
 */
class ProductWriter extends BaseWriter implements ItemWriterInterface
{
    use DataMappingTrait;

    const AKENEO_ENTITY_NAME = 'product';
    const AKENEO_ATTRIBUTE_ENTITY_NAME = 'attribute';
    const AKENEO_CATEGORY_ENTITY_NAME = 'category';
    const ACTION_ADD = 'addProduct';
    const ACTION_GET_METAFIELDS = 'getProductMetafields';
    const ACTION_UPDATE_METAFIELD = 'updateProductMetafield';
    const ACTION_DELETE_METAFIELD = 'deleteProductMetafield';
    const ACTION_UPDATE = 'updateProduct';
    const ACTION_ADD_VARIATION = 'addVariation';
    const ACTION_UPDATE_VARIATION = 'updateVariation';
    const ACTION_IMAGE_ADD = 'addImage';
    const ACTION_IMAGE_UPDATE = 'updateImage';

    const CODE_ALREADY_EXIST = 'na';
    const CODE_DUPLICATE_EXIST = 'na';
    const CODE_NOT_EXIST = 404;
    const RELATED_INDEX = null;
    const RESOURCE_WRAPPER = 'product';

    const ADD_TO_CATEGORY = 'addToCategory';

    const DEFAULTS_SECTION = 'shopify_connector_defaults';
    const GET_VARIATION = 'getVariation';
    const OTHER_SETTING_SECTION = 'shopify_connector_others';

    protected $locale;
    protected $baseCurrency;
    protected $channel;

    protected $mainAttributes = [
        'sku',
        'name'
    ];

    protected $mappedFields;
    protected $defaultValues;

    protected $addedParents = [];

    /**
     * write products to Shopify and adds writer counter based on that
     * @param array $items (consiting normalized product, attributeAsImage, variantAttributes , allVariantAttributes)
     */
    public function write(array $items)
    {
        $this->locale = $this->getDefaultLanguage();
        $this->baseCurrency = $this->getDefaultCurrency();
        $this->channel = $this->getDefaultScope();

        if(!$this->mappedFields) {
            $this->mappedFields = $this->connectorService->getSettings();
            $this->defaultValues = $this->connectorService->getSettings(self::DEFAULTS_SECTION);
        }

        foreach($items as $item) {
            if(!empty($item['parent'])) {
                $item['code'] = $item['parent'];
                $item['type'] = 'variable';
            } else {
                $item['code'] = $item['identifier'];
                $item['type'] = 'simple';
            }
            $mapping = $this->checkMappingInDb($item);
            $skipParent = ('variable' === $item['type'] && $mapping && $mapping->getJobInstanceId() === $this->stepExecution->getJobExecution()->getId() );
            $variantId = null;
            $result = null;
            $reResult = null;

            if(!$skipParent) {
                $formattedData = $this->formatData($item);
                if($mapping && !empty($formattedData[self::RESOURCE_WRAPPER]['metafields'])) {
                    $formattedData[self::RESOURCE_WRAPPER]['metafields'] = $this->filterNewMetaFieldsOnly($mapping, $formattedData[self::RESOURCE_WRAPPER]['metafields']);
                }

                if( $item['type'] == 'simple') {
                    if(empty($formattedData[self::RESOURCE_WRAPPER]['variants'][0]['price'])) {
                        $this->stepExecution->incrementSummaryInfo('skip');
                        $this->stepExecution->addWarning('empty price' , [], new DataInvalidItem(['identifier' => $item['identifier'] ]));
                        continue;
                    }
                }
            }
            $inventoryQuantity = 0;
            // Inventory quantity managed by Inventory Level API
            if(isset($formattedData['product']['variants'][0]['inventory_quantity'])) {
                $inventoryQuantity = $formattedData['product']['variants'][0]['inventory_quantity'];
                unset($formattedData['product']['variants'][0]['inventory_quantity']);
            }
            
            if($mapping) {
                if(!$skipParent) {
                    $result = $this->connectorService->requestApiAction(
                                    self::ACTION_UPDATE, 
                                    $formattedData,
                                    ['id' => $mapping->getExternalId() ]
                                );
                    
                    $reResult = $this->handleAfterApiRequest($item, $result, $mapping);
                    //update quantity using Inventory level api
                    if(isset($reResult['variants'][0]['inventory_item_id'])) {
                        $inventoryItemId = $reResult['variants'][0]['inventory_item_id'];
                        if(!empty($reResult['variants'][0]['fulfillment_service'])) {
                            $location = $reResult['variants'][0]['fulfillment_service'];
                            $response = $this->updateInventoryQuantity($inventoryQuantity, $inventoryItemId, $location);
                        } else {
                            $response = $this->updateInventoryQuantity($inventoryQuantity, $inventoryItemId);
                        }
                        if($response['code'] === 404) {
                            $this->stepExecution->addWarning('Error to update Quantity', ['debug line'=> __LINE__], new DataInvalidItem([$response]));
                        }
                    }
                    
                    if(!empty($reResult[self::RESOURCE_WRAPPER])) {
                        $reResult = $reResult[self::RESOURCE_WRAPPER];
                    }
                    $id = !empty($reResult['id']) ? $reResult['id'] : null;

                    $variantId = !empty($reResult['variants'][0]['id']) ? $reResult['variants'][0]['id'] : null;
                } else {
                    $id = $mapping->getExternalId();
                }

            } else {
                $result = $this->connectorService->requestApiAction(
                                self::ACTION_ADD, 
                                $formattedData
                            );
                $reResult = $this->handleAfterApiRequest($item, $result);
               
                //update quantity using Inventory level api
                if(isset($reResult['variants'][0]['inventory_item_id'])) {
                    $inventoryItemId = $reResult['variants'][0]['inventory_item_id'];
                    if(!empty($reResult['variants'][0]['fulfillment_service'])) {
                        $location = $reResult['variants'][0]['fulfillment_service'];
                        $response = $this->updateInventoryQuantity($inventoryQuantity, $inventoryItemId, $location);
                    } else {
                        $response = $this->updateInventoryQuantity($inventoryQuantity, $inventoryItemId);
                    }
                    if($response['code'] === 404) {
                        $this->stepExecution->addWarning('Error to update Quantity', [], new DataInvalidItem([$response]));
                    }
                }
                

                if(!empty($reResult[self::RESOURCE_WRAPPER])) {
                    $reResult = $reResult[self::RESOURCE_WRAPPER];
                }
                $id = !empty($reResult['id']) ? $reResult['id'] : null;
             
                $variantId = !empty($reResult['variants'][0]['id']) ? $reResult['variants'][0]['id'] : null;
            }

            if(!empty($id) && !$skipParent) {
                $this->quickExportActions($item);
                /* add category */ 
                $this->addCollectionsToProduct($item, $id);
            }


            /* add variants */
            if( !empty($item['parent']) && !empty($id) && !empty($item['variantAttributes'])) {
                $varMapping = $this->checkMappingInDb([ 'code' => $item['identifier'] ]);
                if($variantId && $varMapping) {
                    $this->connectorService->deleteMapping($varMapping);
                    $varMapping = null;
                }

                if($varMapping && $varMapping->getExternalId()) {
                    $variantId = $varMapping->getExternalId();
                    if(!$this->checkVariantExists($variantId)) {
                        unset($variantId);
                    }                     
                }
                $formatedData = $this->formatVariation($item);

                if(empty($formatedData['variant']['price'])) {
                    $this->stepExecution->addWarning('empty price' , [], new DataInvalidItem(['identifier' => $item['identifier'] ]));
                    continue;
                }
                // Inventory quantity managed by Inventory Level API
                if(isset($formatedData['variant']['inventory_quantity'])) {
                    $inventoryQuantity = $formatedData['variant']['inventory_quantity'];
                    unset($formatedData['variant']['inventory_quantity']);
                }

                if(empty($variantId)) {
                    $varResult = $this->connectorService->requestApiAction(
                                    self::ACTION_ADD_VARIATION, 
                                    $formatedData,
                                    [ 'product' => $id ]
                                );
                } else {
                    $varResult = $this->connectorService->requestApiAction(
                                    self::ACTION_UPDATE_VARIATION, 
                                    $formatedData,
                                    [
                                       'id' => $variantId 
                                    ]
                                );
                }
            }

            if(!empty($varResult['code']) && ($varResult['code'] == Response::HTTP_CREATED || $varResult['code'] == Response::HTTP_OK)) {
                $this->addVariantImages($id, $varResult['variant']['id'], $item);

                 //update quantity using Inventory level api
                if(isset($reResult['variant']['inventory_item_id'])) {
                    $inventoryItemId = $reResult['variant']['inventory_item_id'];
                    if(!empty($reResult['variant']['fulfillment_service'])) {
                        $location = $reResult['variant']['fulfillment_service'];
                        $response = $this->updateInventoryQuantity($inventoryQuantity, $inventoryItemId, $location);
                    } else {
                        $response = $this->updateInventoryQuantity($inventoryQuantity, $inventoryItemId);
                    }
                    if($response['code'] === 404) {
                        $this->stepExecution->addWarning('Error to update Quantity', [], new DataInvalidItem([$response]));
                    }
                }
                
                $this->connectorService->addOrUpdateMapping(
                    $varMapping,
                    $item['identifier'], 
                    self::AKENEO_ENTITY_NAME,
                    $varResult['variant']['id'], 
                    $varResult['variant']['product_id']
                );                                        
            }

            /* increment write count */
            $this->stepExecution->incrementSummaryInfo('write');
        }
    }

    protected function formatData($item)
    {
        $formatted = [
            'title' => $item['code'],
        ];
       
        $values = $item['values'];
        if(!empty($item['allVariantAttributes'])) {
            
            foreach($values as $key => $value) {
                if(in_array($key, $item['allVariantAttributes']) && !in_array($key, $item['variantAttributes'])) {
                    unset($values[$key]);
                }
            }
        }

         
        $attributes = $this->formatAttributes($values);

        $variant = [];

        /* main attributes */
        foreach($this->mappedFields as $name => $field) {
            if(is_array($attributes) && array_key_exists($field, $attributes)) {
                if(in_array($name, $this->variantIndexes)) {
                    $variant[$name] = $attributes[$field];            
                } else {
                    $formatted[$name] = $attributes[$field];
                }
            }
        }

        /* default values */ 
        foreach($this->defaultValues as $name => $value) {
            if(in_array($name, $this->variantIndexes)) {
                $variant[$name] = $value;           
            } else {
                $formatted[$name] = $value;
            }            
        }

        /* image attributes */
        if($this->stepExecution->getJobExecution()->getJobParameters()->has('with_media') && $this->stepExecution->getJobExecution()->getJobParameters()->get('with_media')) {
            $imageAttrsCodes = $this->connectorService->getImageAttributeCodes();
            $imageAttrs = [];
            foreach($attributes as $code => $value) {
                if(!in_array($code, $this->mainAttributes)) {
                
                    if(in_array($code, $imageAttrsCodes) ) {
                        $imageUrl = $this->connectorService->generateImageUrl($value);

                        if($imageUrl) {
                            if($value) {
                                $attrAsImage = (!empty($item['attributeAsImage']) && $item['attributeAsImage'] === $code);
                                $imageAttrs[] = [
                                    'src' => $imageUrl,
                                ];
                            }
                        }
                    } else {
                    }
                }
            }
            
            $formatted['images'] = $imageAttrs;
            if(!empty($mainImage)) {
                $formatted['image'] = $mainImage;
            }
        }


        if('variable' == $item['type']) {
            $formatted['options'] = [];
            
            foreach($item['variantAttributes'] as $key => $attrCode) {
                if($key < 3) {

                    if(!empty($attributes[$attrCode]) && !empty($attrCode)) {
                        $formatted['options'][] = [
                            'name' => $attrCode,
                            // 'value' => $attributes[$attrCode]
                        ];

                        
                        
                        $variant['option' . (1+$key)] = $attributes[$attrCode];
                    }
                }
            }
        }

        if(isset($variant['inventory_quantity'])) {
            $variant['inventory_quantity'] = (int)$variant['inventory_quantity'];
            $variant['inventory_management'] = 'shopify';
        }
        if(isset($variant['taxable'])) {
            $variant['taxable'] = (boolean)$variant['taxable'];
        }
        if(!empty($variant['inventory_policy'])) {
            $variant['inventory_policy'] = $variant['inventory_policy'] == 'continue' ? 'continue' : 'deny';
        }

        if(!empty($variant['weight']) ) {
            if($variant['weight'] != 0) {
                $variant['requires_shipping'] = true;
            }
            $weight = $variant['weight'];
            $variant['weight'] = $weight["amount"];
            $unit = $weight["unit"];
            if(in_array($unit , array_keys($this->weightUnit))) {
                $variant['weight_unit'] = $this->weightUnit[$unit];
            }
        }

        $metaFieldsArray = $this->createMetafieldsFromAttributes($attributes);
        if(!empty($metaFieldsArray)) {
            $formatted['metafields'] = $metaFieldsArray;
        }
        
        $variant['sku'] = $item['identifier'];
        // if('variable' !== $item['type']) {
            $formatted['variants'] = [
                    $variant
            ];
        // }  
        


        return [ self::RESOURCE_WRAPPER => $formatted ];
    }

    protected function formatVariation($item)
    {
        $variant = [];
        $attributes = $this->formatAttributes($item['values']);

        /* main attributes */
        foreach($this->mappedFields as $name => $field) {
            if(is_array($attributes) && array_key_exists($field, $attributes)) {
                if(in_array($name, $this->variantIndexes)) {
                    $variant[$name] = $attributes[$field];
                }
            }
        }
        /* default values */ 
        foreach($this->defaultValues as $name => $value) {
            if(in_array($name, $this->variantIndexes)) {
                $variant[$name] = $value;          
            }         
        }
        foreach($item['variantAttributes'] as $key => $attrCode) {
            if($key < 3) {
                if(!empty($attributes[$attrCode])) {
                    $optionValue = $this->connectorService->getOptionNameByCodeAndLocale($attrCode.'.'.$attributes[$attrCode], $this->locale);
                    $variant['option' . (1+$key)] = $optionValue;
                }
            }
        }
        if(isset($variant['inventory_quantity'])) {
            $variant['inventory_quantity'] = (int)$variant['inventory_quantity'];            
            $variant['inventory_management'] = 'shopify';
        }
        if(isset($variant['taxable'])) {
            $variant['taxable'] = (boolean)$variant['taxable'];
        }
        
        if(!empty($variant['weight']) ) {
            if($variant['weight'] != 0) {
                $variant['requires_shipping'] = true;
            }
            $weight = $variant['weight'];
            $variant['weight'] = $weight["amount"];
            $unit = $weight["unit"];
            if(in_array($unit , array_keys($this->weightUnit))) {
                $variant['weight_unit'] = $this->weightUnit[$unit];
            }
        }

        
        /* metafields customisation */ 
        $metaFieldsArray = $this->createMetafieldsFromAttributes($attributes);
        if(!empty($metaFieldsArray)) {
            $variant['metafields'] = $metaFieldsArray;
        }
        
        $variant['sku'] = $item['identifier'];
        
        return [ 'variant' => $variant ];
    }

    protected function addVariantImages($productId, $variantId, $item)
    {
        $values = $item['values'];
        $imageAttrsCodes = $this->connectorService->getImageAttributeCodes();

        if(!empty($item['allVariantAttributes'])) {
            foreach($imageAttrsCodes as $attrCode) {
                if(in_array($attrCode, $item['allVariantAttributes']) && isset($values[$attrCode]) ) {
                    $val = $this->formatValue($values[$attrCode]);
                    $imageUrl = $this->connectorService->generateImageUrl($val);
                    $concatCode = $productId . '-' . $variantId . '-' . $attrCode;
                    if(!$imageUrl) {
                        continue;
                    }

                    $formattedData = [
                        "image" => [
                            "src" => $imageUrl,
                            "variant_ids" => [
                                $variantId
                            ],
                        ]
                    ];

                    $mapping = $this->checkMappingInDb(['code' => $concatCode], 'image');
                    $result = null;

                    if($mapping) {
                        $result = $this->connectorService->requestApiAction(
                                        self::ACTION_IMAGE_UPDATE, 
                                        $formattedData,
                                        [
                                            'product' => $productId,
                                            'id' => $mapping->getExternalId() 
                                        ]
                                    );
                    }

                    if(!$mapping || (isset($result['code']) && $result['code'] == Response::HTTP_NOT_FOUND) ) {
                        $result = $this->connectorService->requestApiAction(
                                        self::ACTION_IMAGE_ADD,
                                        $formattedData,
                                        [
                                            'product' => $productId,
                                        ]
                                    );

                        if(!empty($result['image']['id'])) {
                            $this->connectorService->addOrUpdateMapping(
                                $mapping,
                                $concatCode, 
                                'image',
                                $result['image']['id'], 
                                null
                            );
                        }
                    }
                }
            }
        }
    }

    protected function checkVariantExists($variantId)
    {
        $result = $this->connectorService->requestApiAction(
                self::GET_VARIATION, 
                [],
                ['id' => $variantId]
            );

        return !empty($result['code']) && $result['code'] == Response::HTTP_OK;
    }

    protected function createMetafieldsFromAttributes($attributes)
    {
        $otherSettings = $this->connectorService->getScalarSettings(self::OTHER_SETTING_SECTION);
        $metaFieldsArray = [];

        if(!empty($otherSettings['meta_fields'])) {
            foreach($otherSettings['meta_fields'] as $metaField) {
                if(isset($attributes[$metaField])) {
                    $metaFieldsArray[] = [
                        "key"        => $metaField,
                        "value"      => (string)$attributes[$metaField],
                        "value_type" => "string",
                        "namespace"  => $this->connectorService->getAttributeGroupCodeByAttributeCode($metaField) ? : 'global',
                    ];
                }
            }
        }

        return $metaFieldsArray;
    }

    protected function formatAttributes($attributes)
    {
        foreach($attributes as $name => $value) {
            $key = in_array($name, $this->mappedFields) ? array_flip($this->mappedFields)[$name] : null;
            $attributes[$name] = $this->formatValue($value , $key);
        }

        return $attributes; 
    }

    /* increase write counter for models in case of quick export */ 
    protected function quickExportActions(array $item)
    {
        if(isset($item['type']) && $item['type'] == 'variable' && $this->isQuickExport()) {
            $this->stepExecution->incrementSummaryInfo('write');
        }        
    }

    protected function addCollectionsToProduct($item, $id)
    {
        foreach($item['categories'] as $categoryCode) {
            $categoryMapping = $this->connectorService->getMappingByCode($categoryCode, 'category');
            if($categoryMapping) {
                $data = [
                    'collect' => [
                        'product_id' => $id,
                        'collection_id' => $categoryMapping->getExternalId(),
                    ]
                ];
                $result = $this->connectorService->requestApiAction(
                        self::ADD_TO_CATEGORY, 
                        $data,
                        []
                    );
                // collect // delete previous links 
            }
        }
    }

    protected function filterNewMetaFieldsOnly($mapping, $metaFields)
    {
        $existingMetaFields = $this->connectorService->requestApiAction(
            self::ACTION_GET_METAFIELDS, 
            null,
            [ 'id' => $mapping->getExternalId() ]
        );

        $indexedMetafields = [];
        if(!empty($existingMetaFields['metafields'])) {
            foreach($existingMetaFields['metafields'] as $key => $value) {
                $indexedMetafields[ $value['namespace'] . '-' . $value['key'] ] = $value;
            }

            if(!empty($indexedMetafields)) {
                /* update meta fields */ 
                foreach($metaFields as $key => $value) {
                    $mfName = $value['namespace'] . '-' . $value['key'];
                    if(in_array($mfName, array_keys($indexedMetafields))) {
                        unset($metaFields[$key]);

                        if($indexedMetafields[$mfName]['value'] !== $value['value']) {
                            $updatedMetaField = $this->connectorService->requestApiAction(
                                self::ACTION_UPDATE_METAFIELD, 
                                [ 
                                    'metafield' => array_merge($value, ['id' => $indexedMetafields[$mfName]['id'] ] )
                                ],
                                [
                                    'product' => $mapping->getExternalId(),
                                    'id' => $indexedMetafields[$mfName]['id']
                                ]
                            );
                        }
                        unset($indexedMetafields[$mfName]);                    
                    }
                }

                /* delete meta fields */ 
                foreach($indexedMetafields as $key => $value) {
                    $this->connectorService->requestApiAction(
                        self::ACTION_DELETE_METAFIELD, 
                        [],
                        [
                            'product' => $mapping->getExternalId(),
                            'id' => $value['id']
                        ]
                    );
                }

            }
        }

        return array_values($metaFields);
    }


    private function formatValue($value , $name = NULL)
    {
        if(is_array($value)) {
            foreach($value as $key => $aValue) {
                if(is_array($aValue) ) {
                    if(isset($aValue['scope']) &&  $aValue['scope'] !== $this->channel) {
                        continue;
                    }
                    if(array_key_exists('locale', $aValue)) {
                        if(!$aValue['locale'] || $aValue['locale'] == $this->locale) {
                            $newValue = $aValue['data']; 
                            break;
                        }
                    } else {
                        break;
                    }
                } else {
                    break;
                }
            }
        }
        $value = isset($newValue) ? $newValue : null;
        if($value && is_array($value) ) {
            /* price */             
            foreach($value as $key => $aValue) {
                if(is_array($aValue)) {
                    if(array_key_exists('currency', $aValue)) {
                        if(!$aValue['currency'] || $aValue['currency'] == $this->baseCurrency) {
                            $value = $aValue['amount'];
                            break;
                        }
                        if($key == count($value)-1) {
                            $value = !empty($value[0]['amount']) ? $value[0]['amount'] : null ;
                        }
                    }

                } else {
                    break;
                }
            }
            /* metric */
            if(is_array($value) && array_key_exists('unit', $value)) {
                if($name === "weight") {
                    $value = !empty($value['amount']) ? $value : null;
                } else {
                    $value = !empty($value['amount']) ? $value['amount'] : null;
                }
            }            
        }

        return $value;
    }  


    protected function updateInventoryQuantity($inventoryQuantity, $inventoryItemId, $locationId = null) 
    {
        if($locationId == null) {
            $locations = $this->connectorService->requestApiAction('locations', []);
            $location = !empty($locations['locations'][0]['id']) ? $locations['locations'][0]['id'] : null; 
            
        } else {
            $locations = $this->connectorService->requestApiAction('locations', []);
            if($locations['locations']) {
                $locations = $locations['locations'];
                foreach($locations as $location) {
                    if($location['name'] == $locationId) {
                        $location = $location['id'];
                        break;
                    }
                }
            }
        }
        if($location) {
            $payload =  [
                "location_id" => $location,
                "inventory_item_id" => $inventoryItemId,
                "available" => $inventoryQuantity
            ];
            $response = $this->connectorService->requestApiAction(
                'set_inventory_levels',
                $payload
            );    
        } else {

            $response = [
                'code' => 404,
                'error' => 'location not found'
            ];
        }

        return $response;
    }

    protected $productIndexes = [
        'body_html',
        'handle',
        'title',
        // 'metafields_global_title_tag', 
        // 'metafields_global_description_tag',        
        'vendor',
        'product_type',
        'tags',
        // 'images', 'options', 'template_suffix', 'images'
    ];

    protected $variantIndexes = [
        'barcode',
        'compare_at_price',
        'price',
        'sku',
        'weight', 
        'inventory_management',
        'inventory_quantity', 
        'taxable',
        'requires_shipping',
        'inventory_policy',
        'fulfillment_service',
        // 'weight_unit', 'inventory_policy', 'option1', 'grams', 'variant_title',
    ];

    protected $weightUnit = [
        'POUND'     => 'lb',
        'OUNCE'     => 'oz',
        'KILOGRAM'  => 'kg',
        'GRAM'      => 'g',
    ];
}
