<?php

namespace Webkul\ShopifyBundle\Connector\Reader\Import;

use Akeneo\Component\Batch\Item\InitializableInterface;
use Akeneo\Component\Batch\Step\StepExecutionAwareInterface;
use Akeneo\Component\Batch\Item\ItemReaderInterface;
use Symfony\Component\HttpFoundation\Response;
use Akeneo\Component\Classification\Repository\CategoryRepositoryInterface;
use Pim\Bundle\CatalogBundle\Doctrine\ORM\Repository\ChannelRepository;
use Webkul\ShopifyBundle\Services\ShopifyConnector;
use Webkul\ShopifyBundle\Entity\DataMapping;
use Webkul\ShopifyBundle\Traits\DataMappingTrait;


class CategoryReader extends BaseReader implements ItemReaderInterface, InitializableInterface, StepExecutionAwareInterface
{
    use DataMappingTrait;
    protected $itemIterator;
    protected $repository;
    protected $channelRepo;
    protected static $locale;
    protected static $masterCode;
    protected $items;
    protected $page;

    const ACTION_GET_CATEGORIES = "getCategoriesByLimitPage";
    const ACTION_GET_SMART_CATEGORIES = "getSmartCategoriesByLimitPage";
    const AKENEO_ENTITY_NAME = 'category';
    const SMART_COLLECTION_ENTITY = 'smart_collection';


    public function __construct(ShopifyConnector $connectorService, CategoryRepositoryInterface $repository,ChannelRepository $channelRepo){
        parent::__construct($connectorService);
        $this->repository = $repository;
        $this->channelRepo = $channelRepo;
    }
    
    public function initialize()
    {
        $this->items = [];
        $this->page = 0;
        $filters = $this->stepExecution->getJobParameters()->get('filters');
        $channelCode = !empty($filters['structure']['scope']) ? $filters['structure']['scope'] : '';
        $channel = $channelCode ? $this->channelRepo->findOneByIdentifier($channelCode) : null;
        $rootCategory = $channel && $channel->getCategory() ? $channel->getCategory() : null; 
        $this::$masterCode = $rootCategory ? $rootCategory->getCode() : null;
        $this::$locale = !empty($filters['structure']['locale']) ? $filters['structure']['locale'] : '';
    }

    public function read()
    {   
        if($this->itemIterator === null){
            $this->page++;
            $this->items = $this->getCategoriesByPage($this->page);
            $this->itemIterator = new \ArrayIterator([]);
            if(!empty($this->items)) {
                $this->itemIterator = new \ArrayIterator($this->items);
            }
        }
        
        $item = $this->itemIterator->current();

        if($item !== null){
            $this->stepExecution->incrementSummaryInfo('read');
            $this->itemIterator->next();
        } else {
            $this->page++;
            $this->items = $this->getCategoriesByPage($this->page);
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


    protected function getCategoriesByPage($page) 
    {
        $items = [];
        $response2 = [];
        $response = $this->connectorService->requestApiAction(
                                    self::ACTION_GET_CATEGORIES, 
                                    [],
                                    ['page' => $page , 'limit' => 50]
                                );
            
        $setting = $this->connectorService->getSettings('shopify_connector_others');
        if(!empty($setting['smart_collection']) && $setting['smart_collection'] == "true") {
            $response2 = $this->connectorService->requestApiAction(
                                                self::ACTION_GET_SMART_CATEGORIES, 
                                                [],
                                                ['page' => $page , 'limit' => 50]
                                            );
        }
        
                                
        if($response['code'] === Response::HTTP_OK){
            $collections = !empty($response['custom_collections']) ? $response['custom_collections'] : [];
            $smartcollections = !empty($response2['smart_collections']) ? $response2['smart_collections'] : [];
            try{
                $collections = $this->formate($collections, $this::AKENEO_ENTITY_NAME );
                $smartcollections = $this->formate($smartcollections, $this::SMART_COLLECTION_ENTITY);
                if(!empty($smartcollections)) {
                    $items = $this->combineCollection($collections, $smartcollections); //$this->items = $items;
                } else {
                    $items = $collections;
                }
                
            }catch(Exception $e){
                $this->stepExecution->incrementSummaryInfo('skip');
            }
        }
        
        return $items;       
    }


    //formate the data according to akeneo category writer

    protected function formate($collections = array(), $entity)
    {
        $items = [];

        foreach($collections as $collection){

            $item['labels'] = [
                $this::$locale => $collection['title'],
            ];
            //check code exist in db mapping
            $categoryCodeExistInDB = $this->connectorService->categoryCodeFindInDb($collection['id']);
            
            $item['code'] = !empty($categoryCodeExistInDB) ? $categoryCodeExistInDB : $this->connectorService->verifyCode($collection['handle']);
            
            if($this::$masterCode != null){
                $item['parent'] = $this::$masterCode;
            }
            
            $items[] = $item;  
            $this->connectorService->mappedAfterImport($collection['id'] , $item['code'], $entity , $this->stepExecution->getJobExecution()->getId() );
        }
        
        return $items;  
    }

    //for combine collection and smart collection
    protected function combineCollection($items, $items2){
        $count = count($items);
        foreach($items2 as $key => $value ){
            array_push($items, $value);
        }
        return $items;
    }
    
}