<?php

namespace Webkul\ShopifyBundle\Connector\Reader\Import;

use Akeneo\Component\Batch\Item\ItemReaderInterface;
use Akeneo\Component\Batch\Item\InitializableInterface;
use Akeneo\Component\Batch\Step\StepExecutionAwareInterface;
use Symfony\Component\HttpFoundation\Response;
use Webkul\ShopifyBundle\Services\ShopifyConnector;
use Symfony\Component\HttpFoundation\Request;


class AttributeReader extends BaseReader implements ItemReaderInterface, InitializableInterface, StepExecutionAwareInterface
{
    protected $itemIterator;
    protected $locale;
    protected $items;
    protected $page;
     
     /** @var EntityManager */
    protected $em;
   
    const ACTION_GET_PRODUCTS_BY_FIELDS = "getProductsByFields";
    const AKENEO_ENTITY_NAME = "attribute";

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
        if($this->itemIterator === null) {
            $this->page++;
            $this->items = $this->getAttributeByPage($this->page);
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
            $this->items = $this->getAttributeByPage($this->page);
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

    protected function getAttributeByPage($page) 
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

    protected function formateData($response) {
        $optionName= [];
        if($response['products']) {
            foreach($response['products'] as $product) {
                foreach($product as $options) {
                    foreach($options as $option) {
                        if($option['name'] !== 'Title') {
                            $attributeInDb = $this->attributeCheckInDB($option['name']);
                            if(!$attributeInDb) {
                                //if code already not present in database
                                if(!strpos(json_encode($optionName), strtolower($option['name']))) { 
                                    $code = $this->connectorService->verifyCode(strtolower($option['name']));
                                    $optionName[] = [
                                        'labels' =>  array(
                                            $this->locale => $option['name'],
                                        ),
                                        'code' => $code,
                                        'type' => 'pim_catalog_simpleselect',
                                        'group' =>'other'
                                    ]; 
                                }
                            } else {
                                //if code already present in database
                                if(!strpos(json_encode($optionName),$option['name'])) { 
                                    $code = $attributeInDb;
                                    $optionName[] = [
                                        'labels' =>  array(
                                            $this->locale => $option['name'],
                                        ),
                                        'code' => $code,
                                        'type' => 'pim_catalog_simpleselect',
                                        'group' =>'other'
                                    ];    
                                }
                            }
                            $relatedSourece = !empty($option['values']) ? json_encode($option['values']) : [];
                            
                            if(!empty($code)) {
                                //mapping in database                            
                                $this->connectorService->mappedAfterImport($code , $code, $this::AKENEO_ENTITY_NAME , $this->stepExecution->getJobExecution()->getId(), null, $relatedSourece );
                            }
                        }
                    }
                }
            }
        }
        
        return $optionName;
    }

    public function attributeCheckInDB($option)
    {
        $results = $this->connectorService->getAttributeByLocaleScope($option);
        if($results === null) {

            return null;
        } else {
            foreach($results as $result) {

                return $result['code'];
            }
        }   
    }

}