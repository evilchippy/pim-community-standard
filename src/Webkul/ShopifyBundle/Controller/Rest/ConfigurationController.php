<?php

namespace Webkul\ShopifyBundle\Controller\Rest;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Oro\Bundle\SecurityBundle\Annotation\AclAncestor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Optional;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Form\FormError;
use Oro\Bundle\ConfigBundle\Entity\ConfigValue;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Akeneo\Component\Batch\Model\JobInstance;
use Symfony\Component\Intl\Intl;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Configuration rest controller in charge of the shopify connector configuration managements
 */
class ConfigurationController extends Controller
{
    const SECTION = 'shopify_connector';
    const SETTING_SECTION = 'shopify_connector_settings';
    const IMPORT_SETTING_SECTION = 'shopify_connector_importsettings';
    const IMPORT_FAMILY_SETTING_SECTION = 'shopify_connector_otherimportsetting';
    const DEFAULTS_SECTION = 'shopify_connector_defaults';
    const OTHER_SETTING_SECTION = 'shopify_connector_others';
    const QUICK_EXPORT_SETTING_SECTION = 'shopify_connector_quickexport';
    const QUICK_EXPORT_CODE = 'shopify_product_quick_export';

    /**
     * Get the current configuration
     *
     *
     * @return JsonResponse
     */
    public function credentialAction(Request $request)
    {
        $connectorService = $this->get('shopify.connector.service');
        
        switch($request->getMethod()) {
            case 'POST':
                $params = $request->request->all() ? : json_decode($request->getContent(), true);
                $form = $this->getConfigForm();
                $credentials = !empty($params['credentials']) ? $params['credentials'] : [];
                $form->submit($credentials);
                
                if(isset($params['importsettings'])) {
                $connectorService->saveAttributeMapping($params['importsettings'], self::IMPORT_SETTING_SECTION);
                
                }
                if(!empty($params['otherimportsetting'])) {
                    $connectorService->saveSettings($params['otherimportsetting'], self::IMPORT_FAMILY_SETTING_SECTION);
                }
                
                if(!empty($params['settings'])) {
                    $connectorService->saveSettings($params['settings']);
                }
                if(isset($params['defaults'])) {
                    $connectorService->saveSettings($params['defaults'], self::DEFAULTS_SECTION);
                }

                if(isset($params['others'])) {
                    $connectorService->saveSettings($params['others'], self::OTHER_SETTING_SECTION);
                }                

                if(isset($params['quicksettings'])) {
                    $connectorService->saveSettings($params['quicksettings'], self::QUICK_EXPORT_SETTING_SECTION);
                }

                if($form->isValid() && $connectorService->checkCredentials($credentials)) {
                    $connectorService->saveCredentials($credentials);
                    $this->checkAndSaveQuickJob();
                    return new JsonResponse($params);
                } else {
                    $form->get('apiKey')->addError(new FormError('invalid details'));
                    return new JsonResponse($this->getFormErrors($form), Response::HTTP_BAD_REQUEST); 
                }
                break;


            case 'GET':
                $data = [];
                $data['credentials'] = $connectorService->getCredentials();
                $data['settings']    = $connectorService->getSettings();
                $data['defaults']    = $connectorService->getSettings(self::DEFAULTS_SECTION);
                $data['others']      = $connectorService->getScalarSettings(self::OTHER_SETTING_SECTION);
                $data['quicksettings'] = $connectorService->getScalarSettings(self::QUICK_EXPORT_SETTING_SECTION);
                $data['importsettings'] = $connectorService->getScalarSettings(self::IMPORT_SETTING_SECTION);
                $data['otherimportsetting'] = $connectorService->getScalarSettings( self::IMPORT_FAMILY_SETTING_SECTION);
                
                return new JsonResponse($data);
                break;
        }
        exit(0);
    }

    public function getDataAction()
    {
        return new JsonResponse($this->mappingFields);
    }

    protected function checkAndSaveQuickJob()
    {
        $jobInstance = $this->get('pim_enrich.repository.job_instance')->findOneBy(['code' => self::QUICK_EXPORT_CODE]);
    
        if(!$jobInstance) {
            $em = $this->getDoctrine()->getManager();
            $jobInstance = new JobInstance();
            $jobInstance->setCode(self::QUICK_EXPORT_CODE);            
            $jobInstance->setJobName('shopify_quick_export');
            $jobInstance->setLabel('Shopify quick export');
            $jobInstance->setConnector('Shopify Export Connector');
            $jobInstance->setType('quick_export');
            $em->persist($jobInstance);
            $em->flush();
        }    
    }
    private function getConfigForm() 
    {
        $form = $this->createFormBuilder(null, [
                    'allow_extra_fields' => true,
                    'csrf_protection' => false
                ]);
        $form->add('shopUrl', null, [
            'constraints' => [
                new Url(),
                new NotBlank()                
            ]
        ]);
        $form->add('apiKey', null, [
            'constraints' => [
                new NotBlank()                
            ]
        ]);
        $form->add('apiPassword', null, [
            'constraints' => [
                new NotBlank()                
            ]
        ]);                

        return $form->getForm();
    }

    private function getFormErrors($form) 
    {
    	$errorContext = [];
        foreach ($form->getErrors(true) as $key => $error) {
            $errorContext[$error->getOrigin()->getName()] = $error->getMessage();
        }

        return $errorContext;
    }

    /**
    * returns curl response for given route
    *
    * @param string $url
    * @param string $method like GET, POST
    * @param array headers (optional)
    *
    * @return string $response
    */
    protected function requestByCurl($url, $method, $payload = null, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if($payload) {
            if(empty($headers)) {
                $headers = [
                    'Content-Type: application/json',
                ];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        if(!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);

        return $response;
    }    

    public function getActiveCurrenciesAction(){
        $currencies = [];
        $repo = $this->getDoctrine()->getRepository('PimCatalogBundle:Currency');
        $codes = $repo->getActivatedCurrencyCodes();
        
        foreach (Intl::getCurrencyBundle()->getCurrencyNames() as $currencyCode => $currencyName) {
            foreach($codes as $code)
            {
                if($currencyCode == $code){
                    $currencies[$currencyCode] = $currencyName;
                }
            }
        }
        
        return new JsonResponse($currencies);
    }

    public function getLogFileAction() 
    {
        $log_dir = $this->getParameter('logs_dir');
        $env = $this->getParameter('kernel.environment');
        $path = $log_dir."/webkul_shopify_batch.".$env.".log";
        
        $fs=new Filesystem();
        if(!$fs->exists($path)) {
            $fs->touch($path);
        }
        
        $response = new Response();
        $response->headers->set('Content-type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', "webkul_shopify_batch.".$env.".log" ));
        $response->setContent(file_get_contents($path));
        $response->setStatusCode(200);
        $response->headers->set('Content-Transfer-Encoding', 'binary');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        
        return $response;
    }

    

    private $mappingFields = [
        [
            'name' => 'title',
            'label' => 'shopify.useas.name',
            'types' => [
                'pim_catalog_text',
            ],
            'tooltip' => 'supported attributes types: text',      
        ],        
        [
            'name' => 'body_html',
            'label' => 'shopify.useas.description',
            'types' => [
                'pim_catalog_text',
                'pim_catalog_textarea',
            ],
            'tooltip' => 'supported attributes types: text, textarea',            
        ],
        [ 
            'name' => 'price',
            'label' => 'shopify.useas.price',
            'types' => [
                'pim_catalog_price_collection',
                // 'pim_catalog_number',
            ],
            'tooltip' => 'supported attributes types: price',                      
        ],
        [ 
            'name' => 'weight',
            'label' => 'shopify.useas.weight',
            'types' => [
                'pim_catalog_metric',
                'pim_catalog_number',
            ],
            'tooltip' => 'supported attributes types: number, metric',
        ],
        [ 
            'name' => 'inventory_quantity',
            'label' => 'shopify.useas.quantity',
            'types' => [
                'pim_catalog_number',
            ],
            'tooltip' => 'supported attributes types: number',
        ],        
        [ 
            'name' => 'vendor',
            'label' => 'shopify.useas.vendor',
            'types' => [
                'pim_catalog_text',
                'pim_catalog_simpleselect',
            ],
            'tooltip' => 'supported attributes types: text, simple select',
        ],
        [ 
            'name' => 'product_type',
            'label' => 'shopify.useas.product_type',
            'types' => [
                'pim_catalog_text',
                'pim_catalog_simpleselect',
            ], 
            'tooltip' => 'supported attributes types: text, simple select',                       
        ],
        [ 
            'name' => 'tags',
            'label' => 'shopify.useas.tags.comma.separated',
            'types' => [
                'pim_catalog_textarea',
                'pim_catalog_text',
            ],
            'tooltip' => 'supported attributes types: textarea, text',
        ],
        [ 
            'name' => 'barcode',
            'label' => 'shopify.useas.barcode',
            'types' => [
                'pim_catalog_text',
                'pim_catalog_number',
            ],
            'tooltip' => 'supported attributes types: text, number',
        ],         
        [
            'name' => 'compare_at_price',
            'label' => 'shopify.useas.compare_at_price',
            'types' => [
                'pim_catalog_price_collection',
            ],
            'tooltip' => 'supported attributes types: price',            
        ],
        [
            'name' => 'metafields_global_title_tag',
            'label' => 'shopify.useas.seo_title',
            'types' => [
                'pim_catalog_text',
            ],
            'tooltip' => 'supported attributes types: text',            
        ],
        [ 
            'name' => 'metafields_global_description_tag',
            'label' => 'shopify.useas.seo_description',
            'types' => [
                'pim_catalog_text',
                'pim_catalog_textarea',                
            ],
            'tooltip' => 'supported attributes types: text, textarea',            
        ],
        [
            'name' => 'handle',
            'label' => 'shopify.useas.handle',
            'types' => [
                'pim_catalog_text',            
            ],
            'tooltip' => 'supported attributes types: text',            
        ],
        [
            'name' => 'taxable',
            'label' => 'shopify.useas.taxable',
            'types' => [
                'pim_catalog_boolean',            
            ],
            'tooltip' => 'supported attributes types: boolean',            
        ],
        [
            'name' => 'fulfillment_service',
            'label' => 'shopify.useas.fulfillment_service',
            'types' => [
                'pim_catalog_text',
                'pim_catalog_simpleselect',
            ],
            'tooltip' => 'supported attributes types: text, simple select',                     
        ],
        // [
        //     'name' => 'requires_shipping',
        //     'label' => 'shopify.useas.requires_shipping',
        // ],
    ];

}
