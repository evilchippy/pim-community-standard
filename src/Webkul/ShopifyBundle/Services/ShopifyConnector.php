<?php

namespace Webkul\ShopifyBundle\Services;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Oro\Bundle\ConfigBundle\Entity\ConfigValue;
use Webkul\ShopifyBundle\Classes\ApiClient;
use Webkul\ShopifyBundle\Entity\DataMapping;
use Webkul\ShopifyBundle\Entity\CategoryMapping;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Akeneo\Component\Batch\Model\StepExecution;
use Akeneo\Component\Batch\Item\DataInvalidItem;

class ShopifyConnector
{
    const SECTION = 'shopify_connector';
    const SETTING_SECTION = 'shopify_connector_settings';
    const SECTION_ATTRIBUTE_MAPPING = 'shopify_connector_importsettings';

    private $em;
    private $container;
    private $stepExecution;
    private $settings = [];
    private $imageAttributeCodes = [];
    private $attributeGroupCodes = [];

    protected $requiredFields = ['shopUrl', 'apiKey', 'apiPassword', 'hostname'];

    public function __construct($container, \Doctrine\ORM\EntityManager $em)
    {
        $this->container = $container;
        $this->em = $em;
    }

    public function setStepExecution($stepExecution)
    {
        $this->stepExecution = $stepExecution;
    }

    public function getCredentials()
    {
        static $credentials;

        if(empty($credentials)) {
            /* job wise credentials */ 
            $params = $this->stepExecution ? $this->stepExecution->getJobparameters()->all() : null;
            /* common credentials */ 
            $repo = $this->em->getRepository('OroConfigBundle:ConfigValue');
            $configs = $repo->findBy([
                'section' => self::SECTION
                ]);
                
            $commonCredentials = $this->indexValuesByName($configs);

            if(!empty($this->stepExecution) && $this->stepExecution instanceOf StepExecution && 
                !empty($params['shopUrl']) && !empty($params['apiKey']) && !empty($params['apiPassword'])) {

                $credentials = [
                    'shopUrl' => $params['shopUrl'],
                    'apiKey' => $params['apiKey'],
                    'apiPassword' => $params['apiPassword'],
                    'hostname' => !empty($commonCredentials['hostname']) ? $commonCredentials['hostname'] : ''
                ];
            } else {
                $credentials = $commonCredentials;
            }
        }

        return $credentials;
    }

    public function checkCredentials($params)
    {
        $oauthClient = new ApiClient($params['shopUrl'], $params['apiKey'], $params['apiPassword']);
        $response = $oauthClient->request('getOneProduct', [], []);

        return !empty($response['code']) && Response::HTTP_OK == $response['code'];
    }

    public function saveCredentials($params)
    {
        $repo = $this->em->getRepository('OroConfigBundle:ConfigValue');
        foreach($params as $key => $value) {
            if(in_array($key, $this->requiredFields) && gettype($value) == 'string') {
                $field = $repo->findOneBy([
                    'section' => self::SECTION,
                    'name' => $key,
                    ]);
                if(!$field) {
                    $field = new ConfigValue();
                }
                $field->setName($key);
                $field->setSection(self::SECTION);
                $field->setValue($value);
                $this->em->persist($field);
            }
            $this->em->flush();                          
        }
    }

    private function indexValuesByName($values) 
    {
        $result = [];
        foreach($values as $value) {
            $result[$value->getName()] = $value->getValue();
        }    
        return $result;
    }

    public function getAttributeMappings()
    {
        $repo = $this->em->getRepository('OroConfigBundle:ConfigValue');        
        $attrMappings = $repo->findBy([
            'section' => self::SECTION_ATTRIBUTE_MAPPING
            
            ]);
        
        return $this->indexValuesByName($attrMappings);
    }

    public function saveAttributeMapping($attributeData, $section)
    {
         
        $repo =  $this->em->getRepository('OroConfigBundle:ConfigValue');
        /* remove extra mapping not recieved in new save request */ 
        
        $extraMappings = array_diff(array_keys($this->getAttributeMappings()), array_keys($attributeData));
        
        foreach($extraMappings as $mCode => $aCode) {
            $mapping = $repo->findOneBy([
                'name' => $aCode,
                'section' => self::SECTION_ATTRIBUTE_MAPPING
            ]);
            if($mapping) {
                $this->em->remove($mapping);
            }
        }

        /* save attribute mappings */
        foreach($attributeData as $mCode => $aCode) {
            $mCode = strip_tags($mCode);
            $aCode = strip_tags($aCode);
            
            $attribute = $repo->findOneBy([
                'name' => $mCode,
                'section' => self::SECTION_ATTRIBUTE_MAPPING
            ]);
            if($attribute) {
                $attribute->setValue($aCode);
                $this->em->persist($attribute);
            } else {
                $attribute = new ConfigValue();
                $attribute->setSection(self::SECTION_ATTRIBUTE_MAPPING);
                $attribute->setName($mCode);
                $attribute->setValue($aCode);
                $this->em->persist($attribute);
            }
        }

        $this->em->flush();
    }

    public function saveSettings($params, $section = self::SETTING_SECTION)
    {
        $repo = $this->em->getRepository('OroConfigBundle:ConfigValue');
        foreach($params as $key => $value) {
            if(gettype($value) === 'array') {
                $value = json_encode($value);
            }
            if(gettype($value) == 'boolean') {
                $value = ($value === true) ? "true" : "false";
            } 
            
            if(gettype($value) == 'string' || gettype($value) == 'NULL') {
                $field = $repo->findOneBy([
                    'section' => $section,
                    'name' => $key,
                    ]);
                    
                if(null != $value) {
                    if(!$field) {
                        $field = new ConfigValue();
                    }
                    $field->setName($key);
                    $field->setSection($section);
                    $field->setValue($value);
                    $this->em->persist($field);
                } else if($field) {
                    $this->em->remove($field);
                }
            }

            $this->em->flush();                             
        }
    }

    public function getSettings($section = self::SETTING_SECTION)
    {
        $repo = $this->em->getRepository('OroConfigBundle:ConfigValue');
        if(empty($this->settings[$section])) {
            $configs = $repo->findBy([
                'section' => $section
                ]);
                
                $this->settings[$section] = $this->indexValuesByName($configs);
        }
        
        return $this->settings[$section];
    }     

    public function getScalarSettings($section = self::SETTING_SECTION)
    {
        $settings = $this->getSettings($section);
        foreach($settings as $key => $value) {
            $value = json_decode($value);
            if($value !== null && json_last_error() === JSON_ERROR_NONE) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    public function getMappingByCode($code, $entity)
    {
        $credentials = $this->getCredentials();
        $apiUrl = array_key_exists('shopUrl', $credentials) ? $credentials['shopUrl']  : '';
        $apiUrl = $this->formatApiUrl($apiUrl);
        $mapping = $this->em->getRepository('ShopifyBundle:DataMapping')->findOneBy([
            'code'   => $code,
            'entityType' => $entity,
            'apiUrl' => $apiUrl,
        ]);

        return $mapping;
    }
    public function findCodeByExternalId($externalId, $entity){

        $credentials = $this->getCredentials();
        $apiUrl = array_key_exists('shopUrl', $credentials) ? $credentials['shopUrl']  : '';
        $apiUrl = $this->formatApiUrl($apiUrl);
            
        $mapping = $this->em->getRepository('ShopifyBundle:DataMapping')->findOneBy([
            'externalId'   => $externalId,
            'entityType' => $entity,
            'apiUrl' => $apiUrl,
        ]);

        return $mapping ? $mapping->getCode() : null;
    }

public function addOrUpdateMapping($mapping, $code, $entity, $externalId, $relatedId = null, $jobInstanceId = null, $relatedSource = null)
    {
        $credentials = $this->getCredentials();
        $apiUrl = array_key_exists('shopUrl', $credentials) ? $credentials['shopUrl']  : '';
        $apiUrl = $this->formatApiUrl($apiUrl);

        if(!($mapping && $mapping instanceof DataMapping)) {
            $mapping = new DataMapping();
        } 

        $mapping->setApiUrl($apiUrl);
        $mapping->setEntityType($entity);
        $mapping->setCode($code);
        $mapping->setExternalId($externalId);

        if($relatedSource) {
            $mapping->setRelatedSource($relatedSource);
        }
        if($relatedId) {
            $mapping->setRelatedId($relatedId);
        }
        if($jobInstanceId) {
            $mapping->setJobInstanceId($jobInstanceId);
        }        
        $this->em->persist($mapping);
        $this->em->flush();
    }

    public function deleteMapping($mapping)
    {
        if($mapping) {
            $this->em->remove($mapping);
            $this->em->flush();
        }
    }

    public function requestApiAction($action, $data, $parameters = [])
    {
        $credentials = $this->getCredentials();
        if(empty($credentials['shopUrl']) && $this->stepExecution) {
            $msg = 'Error! Save credentials first';
            $this->stepExecution->addWarning($msg, [] , new DataInvalidItem([]));
            exit();
        }
        
        $oauthClient = new ApiClient($credentials['shopUrl'], $credentials['apiKey'], $credentials['apiPassword']);
        $settings = $this->getSettings('shopify_connector_others');
        // logger set by user setting
        if(!empty($settings['enable_request_log']) && $settings['enable_request_log']== "true") {
            $logger = $this->container->get('webkul_shopify_jobs.logger');
        } else {
            $logger = null;
        }
        
        $response = $oauthClient->request($action, $parameters, $data, $logger);

        if(!empty($settings['enable_response_log']) && $settings['enable_response_log']== "true") {
            $logger = $this->container->get('webkul_shopify_jobs.logger');
            $logger->info("Response: " . json_encode($response));
        }

        return $response;
    }

    public function getAttributeGroupCodeByAttributeCode($code)
    {
        if(empty($this->attributeGroupCodes)) {
            $qb = $this->em->createQueryBuilder()
                    ->select('a.id, a.code as attributeCode, g.code as groupCode')
                    ->from('PimCatalogBundle:Attribute', 'a', 'a.code')
                    ->leftJoin('a.group', 'g');
    
            $results = $qb->getQuery()->getArrayResult();
            foreach($results as $key => $value) {
                if(isset($value['groupCode'])) {
                    $results[$key] = $value['groupCode'];
                }
            }

            $this->attributeGroupCodes = $results;
        }

        return array_key_exists($code, $this->attributeGroupCodes) ? $this->attributeGroupCodes[$code] : null;
    }

    public function getImageAttributeCodes()
    {
        if(empty($this->imageAttributeCodes)) {
            $this->imageAttributeCodes = $this->container->get('pim_catalog.repository.attribute')->getAttributeCodesByType(
                'pim_catalog_image'
            );
        }
        
        return $this->imageAttributeCodes;
    }

    public function generateImageUrl($filename, $host = null)
    {
        $filename = urldecode($filename);
        $credentials = $this->getCredentials();
        $host = !empty($credentials['hostname']) ? $credentials['hostname'] : null;
        if($host) {
            $context = $this->container->get('router')->getContext();
            $context->setHost($host);
            // $context->setScheme('https');
        }
        $request = new Request();
        try {
            $url = $this->container->get('liip_imagine.controller')->filterAction($request, $filename, 'preview')->getTargetUrl();            
            // $url = $this->container->get('router')->generate('pim_enrich_media_download', [
            //                             'filename' => urlencode($filename)
            //                          ]   , UrlGeneratorInterface::ABSOLUTE_URL);
        } catch(\Exception $e) {
            $url  = '';
        }

        return $url;
    }

     

    public function mappedAfterImport($itemId, $code, $entity, $jobInstanceId = null, $relatedId = null , $relatedSource = null)
    {
        $credentials = $this->getCredentials();
        $apiUrl = array_key_exists('shopUrl', $credentials) ? $credentials['shopUrl']  : '';
        
        $repo = $this->em->getRepository('ShopifyBundle:DataMapping');
        $mapping = $repo->findOneBy([
            'externalId' => $itemId,
            ]);
        if($mapping && !empty($relatedSource)) {
            $relatedSource = json_decode($relatedSource);
            $relatedSource2 = json_decode($mapping->getRelatedSource());
            if(is_array($relatedSource2)) {
                $relatedSource = array_merge($relatedSource, $relatedSource2);
            }
            $relatedSource = json_encode($relatedSource);
        }
        $externalId = $itemId;

        $this->addOrUpdateMapping($mapping, $code, $entity, $externalId, $relatedId, $jobInstanceId, $relatedSource);
    }


    public function findCategories($productId)
    {
        $categoriesByHandle = [];
        $custom_collections_response = $this->requestApiAction(
            'getCategoriesByProductId', 
            '',
            ['product_id' => $productId]
        );

        if(!empty($custom_collections_response['custom_collections'])) {
            foreach($custom_collections_response['custom_collections'] as $collection) {
                if(!empty($collection['handle']) ) {
                    $categoriesByHandle[] =  $this->verifyCode($collection['handle']);
                }
            }   
        }

        $setting = $this->getSettings('shopify_connector_others');
        if(!empty($setting['smart_collection']) && $setting['smart_collection'] == "true") {
            //for smart colletions
            $smart_collections_response = $this->requestApiAction(
                'getSmartCategoriesByProductId', 
                '',
                ['product_id' => $productId]
            );

            if(!empty($smart_collections_response['smart_collections'])) {
                foreach($smart_collections_response['smart_collections'] as $smartCollection) {
                    if(!empty($smartCollection['handle'])) {
                        $categoriesByHandle[] =  $this->verifyCode($smartCollection['handle']);
                    }
                }   
            }
        }
        
        return $categoriesByHandle;
    }

    public function verifyCode($code)
    {
        $code = str_replace("-", "_", $code);
        $code = str_replace(" ", "_", $code);
        $code = preg_replace("/[^a-zA-Z0-9_]/", "", $code);

        return $code;
    }

    public function categoryCodeFindInDb($categoryId){
        $categoryCode = $this->findCodeByExternalId($categoryId, 'category');
        return $categoryCode;
    }

    public function getOptionAttributes($product){
        $optionAttributes = [];
        foreach($product['options'] as $option){
            if($option['name']!== null){
                $code = $this->verifyCode(strtolower($option['name']));
                $results = $this->em->createQueryBuilder()
                -> select('a.code')
                -> from('PimCatalogBundle:Attribute', 'a')
                -> where('a.code = :code')
                -> setParameter('code', $code)
                -> getQuery()->getResult();
                
                if($results !== null){
                    foreach($results as $result){
                        $optionAttributes[] = $result['code'];
                    }
                }
            }
        }
        
        return $optionAttributes;
    }
    
    public function getAttributeByLocaleScope($field){
        
        $results = $this->em->createQueryBuilder()
                -> select('a.code, a.type, a.localizable as localizable, a.scopable as scopable')
                -> from('PimCatalogBundle:Attribute', 'a')
                -> where('a.code = :code')
                -> setParameter('code', $field)
                -> getQuery()->getResult();
        
                return $results;
    }

    public function getMetaField($name, $metaFields) 
    {

        if($name == 'metafields_global_description_tag') {
            if(array_key_exists('description_tag' , $metaFields)){
            
                return $metaFields['description_tag'];
            }
        }else if($name == 'metafields_global_title_tag') {
            
            if(array_key_exists('title_tag', $metaFields)){
                
                return $metaFields['title_tag'];
            }
        }
    }

    public function normalizeMetaFieldArray($metaFields) 
    {
        $items = [];
        foreach($metaFields as $metaField) {
            $items[$metaField["key"]] = $metaField["value"];
        }

        return $items;
    }

    public function getOptionNameByCodeAndLocale($code, $locale)
    {
        try {
            $option = $this->container->get('pim_catalog.repository.attribute_option')->findOneByIdentifier($code);
        } catch(\Exception $e) {
            $option = null;
        }
        
        if($option) {
            $option->setLocale($locale);
            $optionValue = $option->__toString();
            
            return $optionValue;
        }         
    }
    public function findFamilyVariantByCode($code, $entity) 
    {
        if($entity === 'productmodel'){
            try {
                $repo = $this->container->get('pim_catalog.repository.product_model');

                $result = $repo->createQueryBuilder('p')
                                ->leftJoin('p.familyVariant', 'f')
                                ->where('p.code = :code')
                                ->setParameter('code', $code)
                                ->select('f.code')
                                ->getQuery()->getResult();
                
                if(isset($result[0])){
                    return $result[0]['code'] ? $result[0]['code'] : null;
                }

            } catch(\Exception $e){
                $family = null;
            }
        }

    }

    public function findFamilyByCode($code, $entity){
        
        if($entity === 'product') {
            try {
                $repo = $this->container->get('pim_catalog.repository.product');

                $result = $repo->createQueryBuilder('p')
                                ->leftJoin('p.family', 'f')
                                ->where('p.identifier = :identifier')
                                ->setParameter('identifier', $code)
                                ->select('f.code')
                                ->getQuery()->getResult();
                                
                if(isset($result[0])){
                    return $result[0]['code'] ? $result[0]['code'] : null;
                }

            } catch(\Exception $e) {
                $family = null;
            }
        } else if($entity === 'productmodel') {
            try {
                $repo = $this->container->get('pim_catalog.repository.product_model');

                $result = $repo->createQueryBuilder('p')
                                ->leftJoin('p.familyVariant', 'fv')
                                ->leftJoin('fv.family', 'f')
                                ->where('p.code = :code')
                                ->setParameter('code', $code)
                                ->select('f.code')
                                ->getQuery()->getResult();
                                
                if(isset($result[0])) {
                    return $result[0]['code'] ? $result[0]['code'] : null;
                }

            } catch(\Exception $e){
                $family = null;
            }
        }
    }


    public function getFamilyVariantByIdentifier($identifier)
    {
        return $this->container->get('pim_catalog.repository.family_variant')->findOneByIdentifier($identifier); 
    }

    public function addVariant($variant)
    {
        $familyVariant = $this->container->get('pim_catalog.factory.family_variant')->create();

        try {
            $this->container->get('pim_catalog.updater.family_variant')->update($familyVariant, $variant);
        } catch (PropertyException $exception) {
            $error = true;
        }
        if(empty($error)) {
            $this->em->persist($familyVariant);
            $this->em->flush();

            return $familyVariant;
        }
    }

    public function getFamilyByCode($code)
    {
        return $this->container->get('pim_catalog.repository.family')->findOneByIdentifier($code);
    }

    protected function formatApiUrl($url)
    {
        $url = str_replace(['http://'], ['https://'], $url);

        return \rtrim($url, '/');
    }
}
