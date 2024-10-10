<?php

namespace Tabby\Feed\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Tabby\Checkout\Gateway\Config\Config;
use Tabby\Checkout\Model\Api\Http\Method as HttpMethod;
use Tabby\Checkout\Model\Api\Http\Client as HttpClient;
use Magento\Framework\Module\ModuleList;
use Tabby\Checkout\Model\Api\DdLog;

class Feed
{
    protected const API_BASE = 'https://plugins-api.tabby.ai/webhooks/%s/tabby/';
    protected const API_VERSION = 'v1';
    protected const API_PATH = '';

    /**
     * @var Array
     */
    protected $_config;

    /**
     * @var DdLog
     */
    protected $_ddlog;

    /**
     * @var ModuleList
     */
    protected $_moduleList;

    /**
     * @param DdLog $ddlog
     * @param ModuleList $moduleList
     */
    public function __construct(
        DdLog $ddlog,
        ModuleList $moduleList
    ) {
        $this->_ddlog = $ddlog;
        $this->_moduleList = $moduleList;
    }

    /**
     * Setter for store configuration
     *
     * @param array $config
     */
    public function setConfig($config)
    {
        $this->_config = $config;

        return $this;
    }
    /**
     * Plugin version retriver
     *
     * @return string
     */
    protected function getPluginVersion()
    {
        $moduleInfo = $this->_moduleList->getOne('Tabby_Feed');
        return $moduleInfo["setup_version"];
    }

    /**
     * API availability request for records provided
     *
     * @param array $records
     * @return bool
     */
    public function sendAvailability($records)
    {
        $result = $this->request(
            'availability',
            HttpMethod::METHOD_POST,
            ['availabilityInfo' => $records]
        );
        if (is_object($result) && !property_exists($result, 'errors')) {
            return true;
        }
        return false;
    }
    /**
     * API products request
     *
     * @param array $records
     * @return bool
     */
    public function updateProducts($records)
    {
        $result = $this->request(
            'products',
            HttpMethod::METHOD_POST,
            ['products' => $records]
        );
        if (is_object($result) && !property_exists($result, 'errors')) {
            return true;
        }
        return false;
    }
    /**
     * API register request
     *
     * @return bool|string
     */
    public function register()
    {
        $token = false;
        $result = $this->request(
            'register',
            HttpMethod::METHOD_POST,
            $this->getRegisterPostData()
        );
        if (is_object($result) && property_exists($result, 'token')) {
            $token = $result->token;
        }
        return $token;
    }
    /**
     * Request data for register request
     *
     * @return array
     */
    private function getRegisterPostData()
    {
        return [
            'secretKey'     => $this->getSecretKey(),
            'merchantCode'  => $this->getMerchantCode(),
            'domain'        => $this->getStoreDomain()
        ];
    }
    /**
     * API uninstall request
     *
     * @return array|bool
     */
    public function unregister()
    {
        $result = $this->request(
            'uninstall',
            HttpMethod::METHOD_POST,
            $this->getUninstallPostData()
        );
        return $result;
    }
    /**
     * Data for uninstall API request
     *
     * @return array
     */
    private function getUninstallPostData()
    {
        return [
            'merchantCode'  => $this->getMerchantCode(),
            'domain'        => $this->getStoreDomain()
        ];
    }
    /**
     * Secret Key getter
     *
     * @return string
     */
    private function getSecretKey()
    {
        return $this->_config['key'];
    }
    /**
     * Merchant Code getter
     *
     * @return string
     */
    private function getMerchantCode()
    {
        return $this->_config['code'];
    }
    /**
     * Store Domain getter
     *
     * @return string
     */
    private function getStoreDomain()
    {
        return $this->_config['domain'];
    }
    /**
     * Processing http request to Tabby Feed API
     *
     * @param string $endpoint
     * @param string $method
     * @param array|null $data
     * @return mixed
     * @throws LocalizedException
     */
    public function request($endpoint = '', $method = HttpMethod::METHOD_GET, $data = null)
    {
        $url = $this->getRequestURI($endpoint);

        $client = new HttpClient();
        $client->setTimeout(120);
        $client->addHeader('X-Tabby-Plugin-Platform', 'magento2');
        $client->addHeader('X-Tabby-Plugin-Version', $this->getPluginVersion());
        if ($endpoint != 'register') {
            $client->addHeader('X-Tabby-store-domain', $this->getStoreDomain());
            $client->addHeader('X-Tabby-merchant-code', $this->getMerchantCode());
        }
        if ($data && ($endpoint != 'register')) {
            $client->addHeader('X-Tabby-Sign', $this->getSignature($data));
        }

        $client->send($method, $url, $data);

        $this->logRequest($url, $client, $data);

        $result = false;

        switch ($client->getStatus()) {
            case 100:
            case 200:
                $result = json_decode($client->getBody());
                break;
            default:
                $body = $client->getBody();
                if (!empty($body)) {
                    $result = json_decode($body);
                }
        }

        return $result;
    }

    /**
     * Request signature provider
     *
     * @param array $data
     * @return string
     */
    protected function getSignature($data)
    {
        if (is_array($data)) {
            $data = json_encode($data);
        }
        return base64_encode(hash_hmac('sha256', $data, $this->_config['token'], true));
    }

    /**
     * Construct API request URL
     *
     * @param string $endpoint
     * @return string
     */
    protected function getRequestURI($endpoint)
    {
        return sprintf(self::API_BASE, static::API_VERSION) . static::API_PATH . $endpoint;
    }

    /**
     * Write request to logs
     *
     * @param string $url
     * @param HttpClient $client
     * @param array $requestData
     * @return $this
     */
    public function logRequest($url, $client, $requestData)
    {
        $logData = [
            "request.url" => $url,
            "request.body" => json_encode($requestData),
            "request.headers" => $client->getRequestHeaders(),
            "response.body" => $client->getBody(),
            "response.code" => $client->getStatus(),
            "response.headers" => $client->getHeaders()
        ];
        $this->_ddlog->log("info", "feed api call", null, $logData);

        return $this;
    }
}
