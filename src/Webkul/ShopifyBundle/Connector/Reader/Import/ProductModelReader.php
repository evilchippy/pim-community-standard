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

class ProductModelReader extends BaseReader implements ItemReaderInterface, InitializableInterface, StepExecutionAwareInterface
{
    protected $itemIterator;

    protected $locale;

    /** @var FileStorerInterface */
    protected $storer;

    /** @var FileInfoRepositoryInterface */
    protected $fileInfoRepository;

    protected $scope;

    protected $family;

    protected $mappedFields; 

    protected $defailtsValues;

     /** @var EntityManager */
    protected $em;

    protected $category;

    protected $uploadDir;

    protected $items;

    protected $page;

    protected $otherImportMappedFields;

    const ACTION_GET_PRODUCTS_BY_PAGE = "getProductsByPage";

    const AKENEO_ENTITY_NAME = 'product';

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
            if($data['field'] === 'categories' && !empty($data['value'][0])) {
                $this->category = $data['value'][0];
            }
        }
        $this->otherImportMappedFields = $this->connectorService->getSettings('shopify_connector_otherimportsetting');
        $this->family = !empty($this->otherImportMappedFields['family'])? $this->otherImportMappedFields['family'] : '';
        if(!$this->mappedFields) {
            $this->mappedFields = $this->connectorService->getSettings();
            $this->defailtsValues = $this->connectorService->getSettings('shopify_connector_defaults');
        }

    }

    public function read()
    {
        if($this->itemIterator === null) {
            $this->page++;
            $this->items = $this->getProductModelByPage($this->page);
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
            $this->items = $this->getProductModelByPage($this->page);
            if(!empty($this->items)) {
                $this->itemIterator = new \ArrayIterator($this->items);
            }
            $item = $this->itemIterator->current();
            if($item !== null) {
                $this->stepExecution->incrementSummaryInfo('read');
                $this->itemIterator->next();
            }
        }

        return  $item;
    }
 
    protected function getProductModelByPage($page) 
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
        
        foreach($products as $product) {
            $count = 0;
            foreach($product['options'] as $option) {
                if($option['name'] !== 'Title') {
                    $count++;
                }
            }
            if($count>0) {
            
            $OptionAttributes = $this->connectorService->getOptionAttributes($product);
            if($OptionAttributes) {
                $attributeInDb = $OptionAttributes;
            }
            $categories = $this->connectorService->findCategories($product['id']);
            $code = $this->connectorService->findCodeByExternalId($product['id'], 'product') ? : $this->verify($product['handle']);

            $familyvariantcode = preg_replace(["/,/", "/[^a-zA-Z_]/"] , ["_",""], json_encode($attributeInDb));
            $family_variant = $this->connectorService->findFamilyVariantByCode($code, 'productmodel') ? : $familyvariantcode;
            
            $formated = array(
                'code' => $code,
                'family_variant' => $family_variant,  //$code ? '' : $familyvariantcode, 
                'categories' =>  isset($categories)? $categories : [],
                'values' => $this->formateValue($product),    
            );

            $this->connectorService->mappedAfterImport($product['id'] , $code , $this::AKENEO_ENTITY_NAME , $this->stepExecution->getJobExecution()->getId());
            
            $items[] = $formated;  
            }
        }
        
        return $items;
    }


    protected function formateValue($product = array())
    {
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
        
        foreach($this->mappedFields as $name => $field){

            $results = $this->connectorService->getAttributeByLocaleScope($field);

            $localizable = isset($results[0]['localizable']) ? $results[0]['localizable'] : 0;
            $scopable = isset($results[0]['scopable']) ? $results[0]['scopable'] : 0 ;
                
            if(in_array($name, $this->productIndexes)) {
                $formated[$field] = [
                    array(
                        'locale' => $localizable ? $this->locale : null,
                        'scope' => $scopable ? $this->scope : null,
                        'data' => isset($product[$name]) ? $product[$name] : ''
                    )
                ];   
            } else if(in_array($name , ['metafields_global_title_tag', 'metafields_global_description_tag'])) {
                $metaField = $this->connectorService->getMetaField($name, $metaFields);
                $formated[$field] = [
                    array(
                        'locale' => $localizable ? $this->locale : null,
                        'scope' => $scopable ? $this->scope : null,
                        'data'=> $metaField
                    )
                ];
            } else if(array_key_exists(strtolower($name) , array_change_key_case($metaFields) ) ) {
                $formated[$field] = [
                    array(
                        'locale' => $localizable ? $this->locale : null,
                        'scope' => $scopable ? $this->scope : null,
                        'data'=> $metaFields[$name]
                    )
                ];
            }
                    
        }

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
                $formated[$field] = [
                    array(
                    'locale' => null,
                    'scope' => null,
                    'data' => $this->imageStorer($images[$counter]),
                    )
                ];
                $counter++;
            }
        }
        
        
        return $formated;
    }
    

    protected function verify($code)
    {
        $code = str_replace("-", "_", $code);
        $code = preg_replace("/[^a-zA-Z0-9_]/", "", $code);

        return $code;
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