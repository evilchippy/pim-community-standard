<?php
/**
 * Shopify REST API HTTP Client
 */

namespace Webkul\ShopifyBundle\Classes;


/**
 * Api cleint
 *
 */
class ApiClient
{

    /**
     * cURL handle.
     *
     * @var resource
     */
    protected $ch;

    /**
     * Store API URL.
     *
     * @var string
     */
    protected $url;

    /**
     * Consumer key.
     *
     * @var string
     */
    protected $consumerKey;

    /**
     * Consumer secret.
     *
     * @var string
     */
    protected $consumerSecret;

    /**
     * Client options.
     *
     * @var Options
     */
    protected $options;

    /**
     * Request.
     *
     * @var Request
     */
    private $request;

    /**
     * Response.
     *
     * @var Response
     */
    private $response;

    /**
     * Response headers.
     *
     * @var string
     */
    private $responseHeaders;

    /**
     * Initialize HTTP client.
     *
     * @param string $url            Store URL.
     * @param string $consumerKey    Api key.
     * @param string $consumerSecret Api Password.
     * @param array  $options        Client options.
     */
    public function __construct($url, $consumerKey, $consumerSecret, $options = [])
    {
        if (!\function_exists('curl_version')) {
            throw new HttpClientException('cURL is NOT installed on this server', -1, new Request(), new Response());
        }

        $this->options        = $options;
        $this->url            = $this->buildApiUrl($url);
        $this->consumerKey    = $consumerKey;
        $this->consumerSecret = $consumerSecret;
    }

    /**
     * Build API URL.
     *
     * @param string $url Store URL.
     *
     * @return string
     */
    protected function buildApiUrl($url)
    {
        $url = str_replace(['http://'], ['https://'], $url);

        return \rtrim($url, '/') . '/admin/';
    }

    /**
     * Build URL.
     *
     * @param string $url        URL.
     * @param array  $parameters Query string parameters.
     *
     * @return string
     */
    protected function buildUrlQuery($url, $parameters = [])
    {
        if (!empty($parameters)) {
            $url .= '?' . \http_build_query($parameters);
        }
        
        return $url;
    }

    /**
     * Authenticate.
     *
     * @param string $url        Request URL.
     * @param string $method     Request method.
     * @param array  $parameters Request parameters.
     *
     * @return array
     */
    protected function authenticate($url, $method, $parameters)
    {
        \curl_setopt($this->ch, CURLOPT_URL, $url);
        \curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->getRequestHeaders());
    }

    /**
     * Setup method.
     *
     * @param string $method Request method.
     */
    protected function setupMethod($method)
    {
        if ('POST' == $method) {
            \curl_setopt($this->ch, CURLOPT_POST, true);
        } else if (\in_array($method, ['PUT', 'DELETE', 'OPTIONS'])) {
            \curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);
        }
    }

    /**
     * Get request headers.
     *
     * @param  bool $sendData If request send data or not.
     *
     * @return array
     */
    protected function getRequestHeaders()
    {
        $headers = [
            'Accept: application/json',
            'Content-type: application/json',
            'Cache-Control: no-cache',
            'Cache-Control: max-age=0',
            'Authorization: Basic ' . base64_encode($this->consumerKey . ':' . $this->consumerSecret),
        ];

        return $headers;
    }

    /**
     * Create request.
     *
     * @param string $endpoint   Request endpoint.
     * @param string $method     Request method.
     * @param array  $data       Request data.
     * @param array  $parameters Request parameters.
     *
     * @return Request
     */
    protected function createRequest($endpoint, $parameters = [], $data = [], $logger = null)
    {
        if(array_key_exists($endpoint, $this->endpoints)) {
            $method = $this->endpoints[$endpoint]['method'];
            $endpoint = $this->endpoints[$endpoint]['url'];
            foreach($parameters as $key => $val) {
               $endpoint = str_replace('{_' . $key . '}', $val, $endpoint);
            }

        } else {
            return;
        }
        
        
        $body    = '';
        $url     = $this->url . $endpoint;
        // $url     = $this->buildUrlQuery($url, $parameters);
        $hasData = !empty($data);

        // Setup authentication.
        $this->authenticate($url, $method, $parameters);
        
        // Setup method.
        $this->setupMethod($method);
        // Include post fields.
        if ($hasData) {
            $body = json_encode($data);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $body);
        }

        if(!empty($logger) && $logger instanceof \Webkul\ShopifyBundle\Logger\ApiLogger) {
            $logger->info("Request URL: $url, Request Method: $method, Request Data: $body");
        }

        return;
    }

    /**
     * Get response headers.
     *
     * @return array
     */
    protected function getResponseHeaders()
    {
        $headers = [];
        $lines   = \explode("\n", $this->responseHeaders);
        $lines   = \array_filter($lines, 'trim');

        foreach ($lines as $index => $line) {
            // Remove HTTP/xxx params.
            if (strpos($line, ': ') === false) {
                continue;
            }

            list($key, $value) = \explode(': ', $line);

            $headers[$key] = isset($headers[$key]) ? $headers[$key] . ', ' . trim($value) : trim($value);
        }

        return $headers;
    }

    /**
     * Create response.
     *
     * @return Response
     */
    protected function createResponse()
    {
        // Get response data.
        
        $body    = \curl_exec($this->ch);
        $code    = \curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        
        try {
            $body = json_decode($body, true);
        } catch(\Exception $e) {
            $body = [];
        }
        if(!empty($body) && gettype($body) != 'integer' && gettype($body) != 'boolean' ) {
            $response = array_merge(['code' => $code], $body);
        } else {
            $response = [ 'code' => $code ];
        }

        // Register response.
        return $response;
    }

    /**
     * Set default cURL settings.
     */
    protected function setDefaultCurlSettings()
    {
        $verifySsl       = !empty($this->options['verifySsl']) ? $this->options['verifySsl'] : false;
        $timeout         = !empty($this->options['timeout']) ? $this->options['timeout'] : 60 ;
        $followRedirects = !empty($this->options['followRedirects']) ? $this->options['followRedirects'] : true;

        \curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        if (!$verifySsl) {
            \curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, $verifySsl);
        }
        if ($followRedirects) {
            \curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
        }
        \curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        \curl_setopt($this->ch, CURLOPT_TIMEOUT, $timeout);
        \curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    }


    /**
     * Make requests.
     *
     * @param string $endpoint   Request endpoint.
     * @param array  $parameters Request parameters.
     * @param array  $data       Request data.
     *
     * @return array
     */
    public function request($endpoint, $parameters = [], $payload = [] , $logger = null)
    {
        // Initialize cURL.
        $this->ch = \curl_init();
        // Set request args.
        $request = $this->createRequest($endpoint, $parameters, $payload, $logger);
        // Default cURL settings.
        $this->setDefaultCurlSettings();

        // Get response.
        $response = $this->createResponse();
        // Check for cURL errors.
        if (\curl_errno($this->ch)) {
            $response['error'] = \curl_error($this->ch);
            $response['code'] = 0;
        }

        \curl_close($this->ch);

        return $response;
    }

    /**
     * Get request data.
     *
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Get response data.
     *
     * @return Response
     */
    public function getResponse()
    {
        return $this->response;
    }

    protected $endpoints = [
        'addCategory' => [
            'url'    => 'custom_collections.json',
            'method' => 'POST',
        ],
        'updateCategory' => [
            'url'    => 'custom_collections/{_id}.json',
            'method' => 'PUT',
        ],        
        'getCategory' => [
            'url'    => 'custom_collections/{_id}.json',
            'method' => 'GET',
        ],
        'getCategories' => [
            'url'    => 'custom_collections.json',
            'method' => 'GET',
        ],
        'getSmartCategories' =>[
            'url'    => 'smart_collections.json',
            'method' => 'GET',
        ],
        'getCategoriesByLimitPage' => [
            'url'    => 'custom_collections.json?limit={_limit}&page={_page}',
            'method' => 'GET',
        ],
        'getSmartCategoriesByLimitPage' =>[
            'url'    => 'smart_collections.json?limit={_limit}&page={_page}',
            'method' => 'GET',
        ],
        'getCategoriesByProductId' => [
            'url'    => 'custom_collections.json?product_id={_product_id}',
            'method' => 'GET',
        ],
        'getSmartCategoriesByProductId' =>[
            'url'    => 'smart_collections.json?product_id={_product_id}',
            'method' => 'GET',
        ],
        'getProducts' => [
            'url'    => 'products.json',
            'method' => 'GET',
        ], 
        'getProductsByCollection' => [
            'url'    => 'products.json?collection_id={_collection_id}',
            'method' => 'GET',
        ],  
        'getProductsByFields' => [
            'url'    => 'products.json?fields={_fields}&page={_page}',
            'method' => 'GET',
        ],       
        'getProductsByPage' => [
            'url'    => 'products.json?limit=10&page={_page}',
            'method' => 'GET',
        ],       
        'getOneProduct' => [
            'url'    => 'products.json?limit=1',
            'method' => 'GET',
        ],        
        'addProduct' => [
            'url'    => 'products.json',
            'method' => 'POST',
        ],
        'getProduct' => [
            'url'    => 'products/{_id}.json',
            'method' => 'GET',
        ],        
        'updateProduct' => [
            'url'    => 'products/{_id}.json',
            'method' => 'PUT',
        ],
        'addImages' => [
            'url'    => 'products/{_product}/images.json',
            'method' => 'POST',
        ],
        'updateImages' => [
            'url'    => 'products/{_product}/images.json',
            'method' => 'PUT',
        ],
        'getVariations' => [
            'url'    => 'products/{_product}/variants.json',
            'method' => 'GET',
        ],
        'addVariation' => [
            'url'    => 'products/{_product}/variants.json',
            'method' => 'POST',
        ],
        'updateVariation' => [
            'url'    => 'variants/{_id}.json',
            'method' => 'PUT',
        ],
        'getVariation' => [
            'url'    => 'variants/{_id}.json',
            'method' => 'GET',
        ],      
        'addImage' => [
            'url'    => 'products/{_product}/images.json',
            'method' => 'POST',
        ],
        'updateImage' => [
            'url'    => 'products/{_product}/images/{_id}.json',
            'method' => 'PUT',
        ],                
        // 'addToCategory' => [
        //     'url'    => 'custom_collections/{_id}.json',
        //     'method' => 'PUT',
        // ],
        'addToCategory' => [
            'url'    => 'collects.json',
            'method' => 'POST',
        ],    
        'getCategoryId' => [
            'url' => 'collects.json?product_id={_id}',
            'method' => 'GET',
        ],
        'getProductMetafields' => [
            'url'    => 'products/{_id}/metafields.json',
            'method' => 'GET',
        ],
        'updateProductMetafield' => [
            'url'    => 'products/{_product}/metafields/{_id}.json',
            'method' => 'PUT',
        ],
        'deleteProductMetafield' => [
            'url'    => 'products/{_product}/metafields/{_id}.json',
            'method' => 'DELETE',
        ],
        'locations' => [
            'url'    => 'locations.json',
            'method' => 'GET',
        ],
        'set_inventory_levels' => [
            'url'    => 'inventory_levels/set.json',
            'method' => 'POST',
        ]
    ];
}
