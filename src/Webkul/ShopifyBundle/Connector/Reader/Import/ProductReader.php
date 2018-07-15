<?php

namespace Webkul\ShopifyBundle\Connector\Reader\Import;

use Akeneo\Component\Batch\Item\ItemReaderInterface;
use Akeneo\Component\Batch\Item\InitializableInterface;
use Akeneo\Component\Batch\Step\StepExecutionAwareInterface;
use Symfony\Component\HttpFoundation\Response;
use Akeneo\Component\Classification\Repository\CategoryRepositoryInterface;
use Pim\Bundle\CatalogBundle\Doctrine\ORM\Repository\ChannelRepository;
use Webkul\ShopifyBundle\Services\ShopifyConnector;
use Akeneo\Component\FileStorage\File\FileStorerInterface;
use Akeneo\Component\FileStorage\Repository\FileInfoRepositoryInterface;
use Pim\Component\Catalog\FileStorage;
use Symfony\Component\HttpFoundation\Request;
use Pim\Bundle\EnrichBundle\Controller\FileController;
use Akeneo\Component\Batch\Item\DataInvalidItem;

class ProductReader extends BaseReader implements ItemReaderInterface,InitializableInterface, StepExecutionAwareInterface
{
    protected $itemIterator;

    protected $locale;

    protected $scope;

    protected $family;

    protected $mappedFields; 
    
    protected $defailtsValues;

    protected $importMapping;

     /** @var EntityManager */
    protected $em;

    protected $category;

     /** @var FileStorerInterface */
    protected $storer;

    protected $items;

     /** @var FileInfoRepositoryInterface */
    protected $fileInfoRepository;

    protected $uploadDir;

    protected $page;

    protected $otherImportMappedFields;

    protected $skuCollections;

    const ACTION_GET_PRODUCTS_BY_PAGE = "getProductsByPage";

    const AKENEO_ENTITY_NAME = 'product';

    const AKENEO_VARIANT_ENTITY_NAME = 'variant';

    public function __construct (
        ShopifyConnector $connectorService,
        \Doctrine\ORM\EntityManager $em,
        FileStorerInterface $storer,
        FileInfoRepositoryInterface $fileInfoRepository,
        $uploadDir
    )
    { 
        parent::__construct($connectorService);
        $this->em = $em;
        $this->storer = $storer;
        $this->fileInfoRepository = $fileInfoRepository;
        $this->uploadDir = $uploadDir;
    }

    public function initialize()
    {
        $this->page = 0;   
        $filters = $this->stepExecution->getJobParameters()->get('filters');
        $this->scope = !empty($filters['structure']['scope']) ? $filters['structure']['scope'] : '';
        $this->locale = !empty($filters['structure']['locale']) ? $filters['structure']['locale'] : '';
        $this->currency = !empty($filters['structure']['currency']) ? $filters['structure']['currency'] : '';
        $this->data = !empty($filters['data']) ? $filters['data'] : '';

        foreach($this->data as $data){  
            if($data['field'] === 'categories'){
                $this->category = !empty($data['value'][0]) ? $data['value'][0] : null;
            }
        }
        
        $this->otherImportMappedFields = $this->connectorService->getSettings('shopify_connector_otherimportsetting');
        $this->family = !empty($this->otherImportMappedFields['family'])? $this->otherImportMappedFields['family'] : '';

        if(!$this->mappedFields){
            $this->mappedFields = $this->connectorService->getSettings();
            $this->defailtsValues = $this->connectorService->getSettings('shopify_connector_defaults');
            $this->importMapping = $this->connectorService->getSettings('shopify_connector_importsettings');
        }

    }

    public function read()
    { 
        if($this->itemIterator === null){
            $this->page++;
            $this->items = $this->getProductsByPage($this->page);
            $this->itemIterator = new \ArrayIterator([]);
            if(!empty($this->items)) {
                $this->itemIterator = new \ArrayIterator($this->items);
            }
        }    
        $item = $this->itemIterator->current();
        
        if($item !== null) {
            $this->stepExecution->incrementSummaryInfo('read');
            $this->itemIterator->next();
        } else {
            $this->page++;
            $this->items = $this->getProductsByPage($this->page);
            if(!empty($this->items)) {
                $this->itemIterator = new \ArrayIterator($this->items);
            }
            $item = $this->itemIterator->current();
            if($item !== null) {
                $this->stepExecution->incrementSummaryInfo('read');
                $this->itemIterator->next();
            }
        }
        
        return $item;
        return  $item;
    }
 
    protected function getProductsByPage($page) 
    {
        $items = [];
        $response = $this->connectorService->requestApiAction(
                        self::ACTION_GET_PRODUCTS_BY_PAGE, 
                        [],
                        ['page' => $page]
                    );

        if($response['code'] === Response::HTTP_OK){
            $products = $response['products'];
            try{
                $items = $this->formateData($products);
                
            }catch(Exception $e){
                $this->stepExecution->incrementSummaryInfo('skip');
            }
        }
     
        return $items;
    }
        
    protected function formateData($products = array())
    {
        $items = [];
        $this->skuCollections = [];
        
        foreach($products as $product){
            $count = 0;
            $productType = 'simple';

            //check product is simple or variant
            foreach($product['options'] as $option){
                if($option['name'] !== 'Title'){
                    $count++;
                    $productType = 'variable';
                    break;
                }
            }

            //for simple product
            if($productType === 'simple'){
                $formated = $this->commonProduct($product);
                $formated = $this->formateValue($product['variants'][0], $formated);

                $otherSetting = $this->connectorService->getSettings('shopify_connector_others');
                if(!empty($otherSetting['meta_fields'])) {
                    $metaFields = json_decode($otherSetting['meta_fields']);
                    foreach($metaFields as $metaField) {
                        $this->mappedFields[$metaField] = $metaField;
                    } 
                }

                $response = $this->connectorService->requestApiAction(
                                'getProductMetafields', 
                                '',
                                ['id' => $product['id']]
                            );

                $metaFields = !empty($response['metafields']) ? $this->connectorService->NormalizeMetaFieldArray($response['metafields']) : [];
        
                foreach($this->mappedFields as $name => $field) {
                    $results = $this->connectorService->getAttributeByLocaleScope($field);
                    $localizable = isset($results[0]['localizable']) ? $results[0]['localizable'] : 0;
                    $scopable = isset($results[0]['scopable']) ? $results[0]['scopable'] : 0 ;
                    if(in_array($name, $this->productIndexes)) {
                        $formated['values'][$field] = [
                            array(
                                'locale' => $localizable ? $this->locale : null,
                                'scope' => $scopable ? $this->scope : null,
                                'data'=> isset($product[$name]) ? $product[$name] : ''
                            )
                        ];
                    } else if(in_array($name, ['metafields_global_title_tag', 'metafields_global_description_tag'])) {
                        $metaField = $this->connectorService->getMetaField($name, $metaFields);
                        $formated['values'][$field] = [
                            array(
                                'locale' => $localizable ? $this->locale : null,
                                'scope' => $scopable ? $this->scope : null,
                                'data'=> $metaField
                            )
                        ];
                    } else if(array_key_exists($name , $metaFields) ) {
                        $formated['values'][$field] = [
                            array(
                                'locale' => $localizable ? $this->locale : null,
                                'scope' => $scopable ? $this->scope : null,
                                'data'=> $metaFields[$name]
                            )
                        ];
                    }

                }

                $skucode = !empty($product['variants'][0]['sku']) ? $product['variants'][0]['sku'] : strval($product['variants'][0]['id']);
                //check already exist sku in array
                if(in_array($skucode, $this->skuCollections)){
                    $this->stepExecution->incrementSummaryInfo('skip');
                    $this->stepExecution->addWarning('Duplicate SKU', [], new DataInvalidItem(['sku'=>$skucode, 'Shopify productId' => $product['id']]));
                    continue;
                }
                array_push($this->skuCollections, $skucode);
                
                $formated['values']['sku'] = [
                    array(
                        'locale' => null,
                        'scope' => null,
                        'data'=>  $skucode
                    )
                ];
                $formated['identifier'] = $skucode;
                $formated['family'] = $this->connectorService->findFamilyByCode($skucode, 'product') ? : $this->family;

                

                // images for simple product
                        $images = [];
                        foreach($product['images'] as $image ){
                            if(count($image['variant_ids']) < 1 ){
                                $images[] = $image['src'];
                            }
                        }
                       
                       $commonImages = !empty($this->otherImportMappedFields['commonimage']) ? json_decode($this->otherImportMappedFields['commonimage']) : [];
                       $counter = 0;
                       foreach($commonImages as $field){
                            if($counter < count($images)){
                                $formated['values'][$field] = [
                                            array(
                                            'locale' => null,
                                            'scope' => null,
                                            'data' => $this->imageStorer($images[$counter]),
                                            )
                                        ];
                                    ++$counter;
                            }   
                        }
                
                $this->connectorService->mappedAfterImport($product['id'] , $skucode , $this::AKENEO_ENTITY_NAME , $this->stepExecution->getJobExecution()->getId());
                $items[] = $formated;

            }else{
                //for  varriant product
                $formated = $this->commonProduct($product);
                $optionName = '';
                    foreach($product['variants'] as $variant) {
                        $formated = $this->formateValue($variant, $formated);
                        $i = 1;    
                        foreach($product['options'] as $option) {   
                            $code =  $this->connectorService->verifyCode(strtolower($option['name']));
                            $results = $this->connectorService->getAttributeByLocaleScope($code);
                            $value = null;
                            if($results !== null) {
                                foreach($results as $result){
                                    $value =  $result['code'];
                                }  
                            }
                            if(!empty($value)) {
                                $formated['values'][$value] = [
                                    array(
                                        'locale' => null,
                                        'scope' => null,
                                        'data'=>  $this->connectorService->verifyCode(strtolower($variant['option'.$i]))
                                    )
                                ];
                            }
                            $i++;
                        }
                        $formated['values']['sku'] = [
                            array(
                                'locale' => null,
                                'scope' => null,
                                'data'=>  !empty($variant['sku'])? $variant['sku']:strval($variant['id'])
                            )
                        ];

                        $variantsku = !empty($variant['sku'])? $variant['sku']:strval($variant['id']);
                        $parentCode = $this->connectorService->findCodeByExternalId($product['id'] , 'product') ? : $this->connectorService->verifyCode($product['handle']);
                        
                        //check already exist sku in array
                        if(in_array($variantsku, $this->skuCollections)) {
                            $this->stepExecution->incrementSummaryInfo('skip');
                            $this->stepExecution->addWarning('Duplicate SKU', [], new DataInvalidItem(['sku'=> $variantsku, 'Shopify ProductId' => $variant['id']]));
                            continue;
                        }
                        array_push($this->skuCollections, $variantsku);
                        $formated['identifier'] = $variantsku;
                        $formated['family'] = $this->connectorService->findFamilyByCode($parentCode, 'productmodel') ;
                        $formated['parent'] = $parentCode;
                        
                        //variant image
                        foreach($product['images'] as $image ) {
                            if(count($image['variant_ids']) > 0 ) {
                                foreach($image['variant_ids'] as $variantId) {
                                    if($variantId === $variant['id']) {
                                        $variantImageAttribute = $this->otherImportMappedFields['variantimage'] ? $this->otherImportMappedFields['variantimage'] : '';
                                        $formated['values'][$variantImageAttribute] = [
                                            array(
                                                'locale' => null,
                                                'scope' => null,
                                                'data' => $this->imageStorer($image['src']),
                                            )
                                        ];
                                    }
                                }   
                            }
                        }
                        
                        $this->connectorService->mappedAfterImport($variant['id'] , $variantsku , $this::AKENEO_VARIANT_ENTITY_NAME , $this->stepExecution->getJobExecution()->getId(), $product['id']);

                        $items[] = $formated;  
                    }
                }
        }
 
        
        return $items;
    }

   
    protected function commonProduct($product= array()){
         
            $categories = $this->connectorService->findCategories($product['id']);
        
            $formated = [         
                'categories' => isset($categories)? $categories : [],
                'enabled' => true,
                'family' => '',
                'groups' => [],
                'values' => [],
                // 'identifier' =>  !empty($variant['sku'])? $variant['sku']:$variant['id']
            ];


        return $formated;
    }

    
    protected function formateValue($product, $formated)
    {
            //formate as per mapped fields 
           foreach($this->mappedFields as $name => $field){
                    //check atribute is localizable or scopable from database
                    $results = $this->connectorService->getAttributeByLocaleScope($field);

                    $localizable = isset($results[0]['localizable']) ? $results[0]['localizable'] : 0;
                    $scopable = isset($results[0]['scopable']) ? $results[0]['scopable'] : 0 ;
                    
                if(in_array($name, $this->variantIndexes)){
                 
                    if($name == 'price'){
                        $formated['values'][$field] = [
                            array(
                                'locale' => $localizable ? $this->locale : null,
                                'scope' => $scopable ? $this->scope : null,
                                'data'=> [
                                    array(
                                        'amount' => isset($product[$name]) ? $product[$name] : '',
                                        'currency' => $this->currency,
                                    ) ]  
                            )
                        ];
                    }else if($name == 'compare_at_price'){
                        $formated['values'][$field] = [
                            array(
                                'locale' => $localizable ? $this->locale : null,
                                'scope' => $scopable ? $this->scope : null,
                                'data'=> [
                                    array(
                                        'amount' => isset($product[$name]) ? $product[$name] : '',
                                        'currency' => $this->currency,
                                    ) ]  
                            )
                        ];
                    }else if($name == 'weight'){

                        $formated['values'][$field] = [
                            array(
                                'locale' => $localizable ? $this->locale : null,
                                'scope' => $scopable ? $this->scope : null,
                                'data'=> [
                                        'amount' => isset($product[$name]) ? $product[$name] : '',
                                        'unit' => isset($product['weight_unit']) ? $this->weightUnit[$product['weight_unit']] : '',
                                    ]  
                            )
                        ];
                    }else{
                        $formated['values'][$field] = [
                            array(
                                'locale' => $localizable ? $this->locale : null,
                                'scope' => $scopable ? $this->scope : null,
                                'data'=> isset($product[$name]) ? $product[$name] : ''
                            )
                        ];
                    }
                } 
                
           }

           return $formated;
    }
    


    protected function imageStorer($filePath)
    {
        $filePath = $this->getImagePath($filePath);
        
        $rawFile = new \SplFileInfo($filePath);
        $file = $this->storer->store($rawFile, FileStorage::CATALOG_STORAGE_ALIAS);
        
        return $filePath;
    }

    protected function getImagePath($filePath)
    {
        $fileName = explode('/', $filePath);
        $fileName = explode('?',$fileName[count($fileName)-1])[0];
        
        $localpath = $this->uploadDir."/tmpstorage/".$fileName;
       
        if(!file_exists(dirname($localpath)))
            mkdir(dirname($localpath), 0777, true);

        $check = file_put_contents($localpath, file_get_contents($filePath));
        
       return $localpath;
    }

    
  
   protected $productIndexes = [
        'body_html',
        'handle',
        'title', 
        'vendor',
        'product_type',
        'tags',
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
    ];
    
    protected $weightUnit = [
        'lb' => 'POUND',
        'oz' => 'OUNCE',
        'kg' => 'KILOGRAM',
        'g' => 'GRAM',
    ];
    
}

 


    

    

       
       
        