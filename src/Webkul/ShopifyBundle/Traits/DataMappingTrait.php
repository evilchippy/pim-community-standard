<?php

namespace Webkul\ShopifyBundle\Traits;

use Webkul\ShopifyBundle\Entity\DataMapping;
use Symfony\Component\HttpFoundation\Response;
use Akeneo\Component\Batch\Item\DataInvalidItem;

/**
* data mapping 
*/ 
trait DataMappingTrait
{
    /**
    * get default language for job
    *
    * @return String $locale from context for quick export else from structures for normal jobs
    */ 
    protected function getDefaultLanguage()
    {
        try {
            $filters = $this->stepExecution->getJobParameters()->get('filters');
            if(isset($filters[0]['context']['locale'])) {
                $locale =$filters[0]['context']['locale'];
            } else {
                $locale = $this->stepExecution->getJobParameters()->get('filters')['structure']['locale'];
            }
        } catch(\Exception $e) {
            $locale = '';
        }
        
        return $locale;
    }

    /**
    * get default currency for job
    *  @return String $currency from context for quick export else from structures for normal jobs
    */ 
    protected function getDefaultCurrency()
    {
        try {
            $filters = $this->stepExecution->getJobParameters()->get('filters');
            if(isset($filters['structure']['currency'])) {
                $currency = $this->stepExecution->getJobParameters()->get('filters')['structure']['currency'];
            } else {
                $quickSetting = $this->connectorService->getSettings('shopify_connector_quickexport');
                $currency = !empty($quickSetting['qcurrency']) ? $quickSetting['qcurrency'] : null;    
            }
            
        } catch(\Exception $e) {
            $currency = '';
        }
         
        return $currency;
    }

    protected function getDefaultScope()
    {
        try {
            $filters = $this->stepExecution->getJobParameters()->get('filters');
            if(isset($filters['structure']['scope'])){
                $scope = $this->stepExecution->getJobParameters()->get('filters')['structure']['scope'];
            }
            else {
                $scope = $this->stepExecution->getJobParameters()->get('filters')[0]['context']['scope'];                
            }
        } catch(\Exception $e) {
                $scope = '';
        }
         
        return $scope;
    }

    protected function checkMappingInDb($item, $entity = self::AKENEO_ENTITY_NAME)
    {
        $code = array_key_exists('code', $item) ? $item['code'] : null;
        if($code) {
            return $this->connectorService->getMappingByCode($code, $entity);
        }
    }

    protected function handleAfterApiRequest($item, $result, DataMapping $mapping = null)
    {
        $code = !empty($result['code']) ? $result['code'] : 0;
        $requiredParams = !empty($this->requiredApiParams) ? $this->requiredApiParams : [];

        switch($code) {
            case self::CODE_ALREADY_EXIST:
                return;
                $resourceId = !empty($result['data']['resource_id']) ? $result['data']['resource_id'] : null;

                if($resourceId) {
                    $reResult = $this->connectorService->requestApiAction(
                            self::ACTION_UPDATE, 
                            $this->formatData($item), 
                            array_merge($requiredParams, [ 'id' => $resourceId ])
                        );
 
                    if(!self::RELATED_INDEX || isset($reResult[self::RELATED_INDEX])) {
                        $this->connectorService->addOrUpdateMapping(
                            $mapping,
                            $item['code'], 
                            self::AKENEO_ENTITY_NAME,
                            $resourceId, 
                            (!empty($reResult) && !empty($reResult['variants'][0]['id']) ) ? $reResult['variants'][0]['id'] : null,
                            $this->stepExecution->getJobExecution()->getId()
                        );

                        return $reResult;
                    } 
                }
                break;
            case self::CODE_DUPLICATE_EXIST:
                if($mapping) {
                    $this->connectorService->deleteMapping(
                        $mapping
                    );
                }
                try{
                    $this->stepExecution->addWarning( (!empty($item['code']) ? 'for code: ' . $item['code'] : '')  +  'duplicate result already exists', [], new DataInvalidItem(['code' => $item['code'] ]));
                } catch(\Exception $e) {
                }
                break;
            case self::CODE_NOT_EXIST:
                $reResult = $this->connectorService->requestApiAction(
                                self::ACTION_ADD, 
                                $this->formatData($item),
                                $requiredParams
                            );

                if(!empty($reResult['code']) && ($reResult['code'] === Response::HTTP_OK || $reResult['code'] === Response::HTTP_CREATED)) {
                    $resourceId = !empty($reResult[self::RESOURCE_WRAPPER]['id']) ? $reResult[self::RESOURCE_WRAPPER]['id'] : null;
                } elseif(!empty($reResult['code']) && $reResult['code'] == self::CODE_ALREADY_EXIST) {
                    // $resourceId = !empty($reResult['data']['resource_id']) ? $reResult['data']['resource_id'] : null;
                }

                if($mapping && !empty($resourceId) ) {
                    $this->connectorService->addOrUpdateMapping(
                        $mapping, 
                        $item['code'], 
                        self::AKENEO_ENTITY_NAME, 
                        $resourceId, 
                        (!empty($reResult) && !empty($reResult['variants'][0]['id']) ) ? $reResult['variants'][0]['id'] : null,
                        $this->stepExecution->getJobExecution()->getId()
                    );
                    
                    return $reResult;                
                }
                break;

            case Response::HTTP_OK:
            case Response::HTTP_CREATED:
                    if(!empty(self::RESOURCE_WRAPPER)) {
                        $result = $result[self::RESOURCE_WRAPPER];
                    }
                    $resourceId = !empty($result['id']) ? $result['id'] : null;

                    if($resourceId) {
                        $this->connectorService->addOrUpdateMapping(
                            $mapping,
                            $item['code'],
                            self::AKENEO_ENTITY_NAME,
                            $resourceId, 
                            (!empty($result) && !empty($result['variants'][0]['id']) ) ? $result['variants'][0]['id'] : null,
                            $this->stepExecution->getJobExecution()->getId()                             
                        );

                        return $result;                        
                    }            
                break;
            case 0:            
            default:
                if(!empty($item['code'])) {
                    $this->stepExecution->addWarning( json_encode($result), [], new DataInvalidItem(['code' => $item['code'] ]));
                }
        }
    }

    /* check quick export based on context job param */
    protected function isQuickExport()
    {
        $filters = $this->stepExecution->getJobParameters()->get('filters');
        return isset($filters[0]['context']);             
    }
}
