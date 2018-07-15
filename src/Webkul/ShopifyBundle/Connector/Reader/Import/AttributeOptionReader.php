<?php

namespace Webkul\ShopifyBundle\Connector\Reader\Import;

use Akeneo\Component\Batch\Item\ItemReaderInterface;
use Akeneo\Component\Batch\Item\InitializableInterface;
use Akeneo\Component\Batch\Step\StepExecutionAwareInterface;
use Symfony\Component\HttpFoundation\Response;
use Webkul\ShopifyBundle\Services\ShopifyConnector;
use Symfony\Component\HttpFoundation\Request;


class AttributeOptionReader extends BaseReader implements ItemReaderInterface, InitializableInterface, StepExecutionAwareInterface
{
    protected $itemIterator;
    protected $locale;
    protected $items;
    protected $page;
     
     /** @var EntityManager */
    protected $em;
   
    const ACTION_GET_PRODUCTS = "getProducts";
    const ACTION_GET_PRODUCTS_BY_FIELDS = "getProductsByFields";

    public function __construct(
        ShopifyConnector $connectorService,
        \Doctrine\ORM\EntityManager $em
        ){ 

      parent::__construct($connectorService);
      $this->em = $em;
      
    }
    
    public function initialize()
    {
        $this->page = 0;
        $filters = $this->stepExecution->getJobParameters()->get('filters');
        $this->locale = !empty($filters['structure']['locale']) ? $filters['structure']['locale'] : '';
    
    }

    public function read()
    {
        if($this->itemIterator === null){
            $this->page++;
            $this->items = $this->getAttributeOptionByPage($this->page);
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
            $this->items = $this->getAttributeOptionByPage($this->page);
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

    protected function getAttributeOptionByPage($page) 
    {
        $items = [];
        $response = $this->connectorService->requestApiAction (
                        $this::ACTION_GET_PRODUCTS_BY_FIELDS, 
                        '',
                        ['fields' => 'options', 'page' => $page]
                    );

        if($response['code'] === Response::HTTP_OK) {
            try {
                $items = $this->formateData($response);
            } catch(Exception $e) {
                $this->stepExecution->incrementSummaryInfo('skip');
            }
        }

        return $items;
    }


    protected function formateData($response){
        $optionName = [];
        $attributeArray = []; 
        
        if($response['products']){
            foreach($response['products'] as $product){
                foreach($product as $options){
                    foreach($options as $option){
                        
                        if($option['name'] !== 'Title'){
                            foreach($option['values'] as $value){
                                $attributeInDb = $this->attributeOptionCheckInDB($value);
                                $value = $attributeInDb ? $attributeInDb : $value;
                                $attributeArray[$option['name']][$value] = $value;
                            }
                        }
                    }
                }
            }
        }   

        foreach($attributeArray as $attributeName => $attributeCode){   
            foreach($attributeCode as $code){
                $optionName[] = [
                    'labels' =>  array(
                        $this->locale => $code,
                    ),
                    'code' => $this->connectorService->verifyCode($code),
                    'attribute' => $this->connectorService->verifyCode(strtolower($attributeName)),
                    
                    ]; 
            }
            
        }
        
        
        return $optionName;
    }

    public function attributeOptionCheckInDB($value){

        $results = $this->em->createQueryBuilder()
        -> select('a.code')
        -> from('PimCatalogBundle:AttributeOption', 'a')
        -> where('a.code = :code')
        -> setParameter('code', $value)
        -> getQuery()->getResult();
        
        if($results === null){
            return null;
        }else{
            
            return !empty($results[0]) ? $results[0]['code']: null;
            
        }
        
    }

}      