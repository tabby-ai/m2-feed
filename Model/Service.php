<?php
namespace Tabby\Feed\Model;

use Magento\Framework\FlagManager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManager;
use Magento\Framework\Lock\Backend\Database as LockManagerDatabase;
use Magento\Catalog\Model\ProductFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Tabby\Checkout\Gateway\Config\Config;

class Service
{
    public const ALLOWED_CURRENCIES = ['AED', 'BHD', 'KWD', 'SAR', 'QAR'];
    public const TABBY_FEED_FLAG_INSTALLED_STORES = 'tabby_feed_installed_stores';
    public const TABBY_FEED_FLAG_STORE = 'tabby_feed_store';
    public const TABBY_FEED_LANG_MAP = ['en' => 'eng', 'ar' => 'ara'];

    /**
     * @var FlagManager
     */
    protected $_flagManager;

    /**
     * @var StoreFactory
     */
    protected $_storeFactory;

    /**
     * @var ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @var LockManagerDatabase
     */
    protected $_lockManager;

    /**
     * @var ProductFactory
     */
    protected $_productFactory;

    /**
     * @var ConfigurableType
     */
    protected $_configurableType;

    /**
     * @var StoreManager
     */
    protected $_storeManager;

    /**
     * Feed constructor.
     *
     * @param FlagManager $flagManager
     * @param StoreFactory $storeFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param LockManagerDatabase $lockManager
     * @param ProductFactory $productFactory
     * @param ConfigurableType $configurableType
     * @param StoreManager $storeManager
     */
    public function __construct(
        FlagManager $flagManager,
        StoreFactory $storeFactory,
        ScopeConfigInterface $scopeConfig,
        LockManagerDatabase $lockManager,
        ProductFactory $productFactory,
        ConfigurableType $configurableType,
        StoreManager $storeManager
    ) {
        $this->_flagManager = $flagManager;
        $this->_storeFactory = $storeFactory;
        $this->_scopeConfig = $scopeConfig;
        $this->_lockManager = $lockManager;
        $this->_productFactory = $productFactory;
        $this->_configurableType = $configurableType;
        $this->_storeManager = $storeManager;
    }

    /**
     * On product deleted functionality
     *
     * @param \Magento\Catalog\Model\Product $product
     */
    public function onProductDeleted($product)
    {
        // get product website Ids
        $websiteIds = $product->getWebsiteIds();
        $registeredStores = $this->getRegisteredStores();
        foreach ($registeredStores as $storeCode => $storeConfig) {
            if (array_key_exists('websiteId', $storeConfig) && in_array($storeConfig['websiteId'], $websiteIds)) {
                $this->notifyStoreProductDeleted($storeConfig, $product);
            }
        }
    }

    /**
     * On product updated functionality
     *
     * @param \Magento\Catalog\Model\Product $product
     */
    public function onProductUpdated($product)
    {
        // get product website Ids
        $websiteIds = $product->getWebsiteIds();
        $registeredStores = $this->getRegisteredStores();
        foreach ($registeredStores as $storeCode => $storeConfig) {
            if (array_key_exists('websiteId', $storeConfig) && in_array($storeConfig['websiteId'], $websiteIds)) {
                $this->addStoreProductUpdated($storeCode, $product);
            }
        }
    }

    /**
     * On product attributes updated functionality
     *
     * @param array $ids
     */
    public function onProductAttributesUpdated($ids)
    {
        foreach ($ids as $pId) {
            $product = $this->_productFactory->create()->load($pId);
            $this->onProductUpdated($product);
        }
    }

    /**
     * Notify store product deleted, remove products from update list
     *
     * @param array $storeConfig
     * @param \Magento\Catalog\Model\Product $product
     */
    public function notifyStoreProductDeleted($storeConfig, $product)
    {
        $tabbyStore = $this->_storeFactory->create(['config' => $storeConfig]);
        $tabbyStore->deleteProduct($product);
        $this->removeStoreProductsUpdated($storeConfig['code'], $product->getId());
    }

    /**
     * Remove products from update list
     *
     * @param string $storeCode
     * @param int $pId
     */
    public function removeStoreProductsUpdated($storeCode, $pId)
    {
        $lockName = $this->getStoreProductsFlagName($storeCode) . '_lock';
        $this->_lockManager->lock($lockName, 10);
        $ids = $this->getStoreProducts($storeCode);
        $saveNeeded = false;
        if (($index = array_search($pId, $ids)) !== false) {
            unset($ids[$index]);
            $saveNeeded = true;
        }
        if ($saveNeeded) {
            $this->setStoreProducts($storeCode, $ids);
        }
        $this->_lockManager->unlock($lockName);
    }

    /**
     * Add products to update list
     *
     * @param string $storeCode
     * @param \Magento\Catalog\Model\Product $product
     */
    public function addStoreProductUpdated($storeCode, $product)
    {
        // configurable products
        $updated = $this->_configurableType->getParentIdsByChild($product->getId());
        // simple products
        if (empty($updated)) {
            $updated[] = $product->getId();
        }
        $lockName = $this->getStoreProductsFlagName($storeCode) . '_lock';
        $this->_lockManager->lock($lockName, 10);
        $ids = $this->getStoreProducts($storeCode);
        $saveNeeded = false;
        foreach ($updated as $pId) {
            if (!in_array($pId, $ids)) {
                $ids[] = $pId;
                $saveNeeded = true;
            }
        }
        if ($saveNeeded) {
            $this->setStoreProducts($storeCode, $ids);
        }
        $this->_lockManager->unlock($lockName);
    }

    /**
     * Service feed module
     */
    public function onServiceRequested()
    {
        $lockName = self::TABBY_FEED_FLAG_INSTALLED_STORES . '_lock';
        $this->_lockManager->lock($lockName, 10);
        $configuredStores = $this->getConfiguredStores();
        $registeredStores = $this->getRegisteredStores();
        // register stores
        foreach ($configuredStores as $merchantCode => $storeConfig) {
            if (array_key_exists($merchantCode, $registeredStores)) {
                // check secret keys are different
                if (strcmp($storeConfig['key'], $registeredStores[$merchantCode]['key']) !== 0) {
                    // unregister old store if key changed
                    $tabbyStore = $this->_storeFactory->create(['config' => $registeredStores[$merchantCode]]);
                    if ($tabbyStore->unregister()) {
                        unset($registeredStores[$merchantCode]);
                    }
                }
            }
            if (!array_key_exists($merchantCode, $registeredStores)) {
                if (!$this->needToRegisterStore($merchantCode)) {
                    continue;
                }
                $tabbyStore = $this->_storeFactory->create(['config' => $storeConfig]);
                if ($token = $tabbyStore->register()) {
                    $configuredStores[$merchantCode]['token'] = $token;
                    $registeredStores[$merchantCode] = $configuredStores[$merchantCode];
                    $products = $tabbyStore->getInitialSyncProductsList();
                    $this->setStoreProducts($merchantCode, $products);
                } else {
                    $this->noteStoreRegistrationFailed($merchantCode);
                }
            } else {
                if (!array_key_exists('websiteId', $registeredStores[$merchantCode])) {
                    $registeredStores[$merchantCode]['websiteId'] = $configuredStores[$merchantCode]['websiteId'];
                }
            }
        }
        // unregister old registered stores with configuration changes
        foreach ($registeredStores as $merchantCode => $storeConfig) {
            if (!array_key_exists($merchantCode, $configuredStores)) {
                $tabbyStore = $this->_storeFactory->create(['config' => $storeConfig]);
                if ($tabbyStore->unregister()) {
                    unset($registeredStores[$merchantCode]);
                }
            }
        }
        // save registered stores for future use
        $this->setRegisteredStores($registeredStores);
        // sync registered stores
        $this->syncStores($registeredStores);
        $this->_lockManager->unlock($lockName);
    }

    /**
     * Sync registered store products
     *
     * @param array $stores
     */
    public function syncStores($stores)
    {
        foreach ($stores as $storeCode => $storeConfig) {
            if ($products = $this->getStoreProducts($storeCode)) {
                if (is_array($products) && !empty($products)) {
                    $tabbyStore = $this->_storeFactory->create(['config' => $storeConfig]);
                    $new_products = $tabbyStore->syncProducts(array_values($products));
                    $this->setStoreProducts($storeCode, $new_products);
                }
            }
        }
    }

    /**
     * Check store register request failed in last 4 hours
     *
     * @param string $merchantCode
     * @return bool
     */
    public function needToRegisterStore($merchantCode)
    {
        $last = (int)$this->_flagManager->getFlagData($this->getStoreFlagName($merchantCode));
        // try to register store once per 4 hours
        return (time() - $last > 4 * 60 * 60);
    }

    /**
     * Set flag with time store register request failed
     *
     * @param string $merchantCode
     */
    public function noteStoreRegistrationFailed($merchantCode)
    {
        // save current time for note
        $this->_flagManager->saveFlag($this->getStoreFlagName($merchantCode), time());
    }

    /**
     * Save flag with store products updated to DB
     *
     * @param string $merchantCode
     * @param array $products
     * @return $this
     */
    public function setStoreProducts($merchantCode, $products)
    {
        $this->_flagManager->saveFlag(
            $this->getStoreProductsFlagName($merchantCode),
            $products
        );
        return $this;
    }

    /**
     * Retrive store products from DB
     *
     * @param string $merchantCode
     * @return array
     */
    public function getStoreProducts($merchantCode)
    {
        return $this->_flagManager->getFlagData(
            $this->getStoreProductsFlagName($merchantCode),
        );
    }

    /**
     * Get flag name for last register attempt
     *
     * @param string $merchantCode
     * @return string
     */
    public function getStoreLastFlagName($merchantCode)
    {
        return $this->getStoreFlagName($merchantCode) . '_last';
    }

    /**
     * Get flag name for store products
     *
     * @param string $merchantCode
     * @return string
     */
    public function getStoreProductsFlagName($merchantCode)
    {
        return $this->getStoreFlagName($merchantCode) . '_products';
    }

    /**
     * Get flag name for store
     *
     * @param string $merchantCode
     * @return string
     */
    public function getStoreFlagName($merchantCode)
    {
        return self::TABBY_FEED_FLAG_STORE . '_' . $merchantCode;
    }

    /**
     * Save registered stores array in flag table
     *
     * @param array $stores
     * @return $this
     */
    public function setRegisteredStores($stores)
    {
        $this->_flagManager->saveFlag(self::TABBY_FEED_FLAG_INSTALLED_STORES, $stores);
        return $this;
    }

    /**
     * Retrive registered stores from DB
     *
     * @return array
     */
    public function getRegisteredStores()
    {
        $registered = $this->_flagManager->getFlagData(self::TABBY_FEED_FLAG_INSTALLED_STORES);
        return is_array($registered) ? $registered : [];
    }

    /**
     * Build configured stores list
     *
     * @return array
     */
    public function getConfiguredStores()
    {
        // build stores list need to be installed
        $websites = [];
        foreach ($this->_storeManager->getWebsites(false, true) as $websiteCode => $website) {
            if ($secretKey = $this->getWebsiteSecretKey($websiteCode)) {
                // bypass test keys
                if ($this->isTestKey($secretKey)) {
                    continue;
                }
                // bypass disabled websites
                if (!$this->isFeedEnabledForWebsite($websiteCode)) {
                    continue;
                }

                $websites[$website->getId()] = $secretKey;
            }
        }
        $stores = $this->_storeManager->getStores();
        $configured = [];
        foreach ($stores as $store) {
            if (!array_key_exists($store->getWebsiteId(), $websites)) {
                continue;
            }
            if ($this->isMethodActive($store->getId())) {
                $use_local = $this->_scopeConfig->getValue(
                    'tabby/tabby_api/local_currency',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $store->getStoreId()
                );
                $locale = $this->_scopeConfig->getValue(
                    'general/locale/code',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $store->getStoreId()
                );
                $lang = substr($locale, 0, 2);
                if (array_key_exists($lang, self::TABBY_FEED_LANG_MAP)) {
                    $lang = self::TABBY_FEED_LANG_MAP[$lang];
                } else {
                    $lang = 'eng';
                }
                // get group code
                $group = $this->_storeManager->getGroup($store->getGroupId());
                $groupCode = $group->getCode();
                $defaultStoreId = $group->getDefaultStoreId();
                $baseCurrencyCode = $store->getBaseCurrencyCode();
                if ($use_local) {
                    $currencies = [];
                    foreach ($store->getAvailableCurrencyCodes() as $code) {
                        // bypass unsupported currencies
                        if (!in_array($code, self::ALLOWED_CURRENCIES)) {
                            continue;
                        }
                        if (!in_array($code, $currencies)) {
                            $currencies[] = $code;
                        }
                    }
                    foreach ($currencies as $currency) {
                        $storeCode = $groupCode . '_' . $currency;
                        if (!array_key_exists($storeCode, $configured)) {
                            $configured[$storeCode] = $this->createStoreConfig(
                                $store->getWebsiteId(),
                                $store->getGroupId(),
                                $defaultStoreId,
                                $storeCode,
                                $websites[$store->getWebsiteId()],
                                $this->getStoreDomain($store),
                                $currency
                            );
                        };
                        if (!in_array($store->getId(), $configured[$storeCode]['languages'][$lang])) {
                            $configured[$storeCode]['languages'][$lang][] = $store->getId();
                        }
                    }
                } else {
                    // bypass unsupported currencies
                    if (!in_array($baseCurrencyCode, self::ALLOWED_CURRENCIES)) {
                        continue;
                    }
                    if (!array_key_exists($groupCode, $configured)) {
                        $configured[$groupCode] = $this->createStoreConfig(
                            $store->getWebsiteId(),
                            $store->getGroupId(),
                            $defaultStoreId,
                            $groupCode,
                            $websites[$store->getWebsiteId()],
                            $this->getStoreDomain($store),
                            $baseCurrencyCode
                        );
                    };
                    if (!in_array($store->getId(), $configured[$groupCode]['languages'][$lang])) {
                        $configured[$groupCode]['languages'][$lang][] = $store->getId();
                    }
                }
            }
        }
        return $configured;
    }

    /**
     * Retrive store domain from Base url
     *
     * @param \Magento\Store\Model\Store $store
     * @return string
     */
    private function getStoreDomain($store)
    {
        $url = $store->getBaseUrl();
        $storeHost = 'unknown';
        if (preg_match("/https?\:\/\/([^\/]+)\/?/is", $url, $matches)) {
            $storeHost = $matches[1];
        }
        return $storeHost;
    }

    /**
     * Build single store configuration
     *
     * @param int $websiteId
     * @param int $groupId
     * @param int $storeId
     * @param string $storeCode
     * @param string $key
     * @param string $domain
     * @param string $currency
     * @return bool
     */
    private function createStoreConfig($websiteId, $groupId, $storeId, $storeCode, $key, $domain, $currency)
    {
        return [
            'websiteId' => $websiteId,
            'groupId'   => $groupId,
            'storeId'   => $storeId,
            'code'      => $storeCode,
            'key'       => $key,
            'domain'    => $domain,
            'currency'  => $currency,
            'languages' => [
                'eng'   => [],
                'ara'   => []
            ]
        ];
    }

    /**
     * Retrive website Secret key
     *
     * @param string $code
     * @return string
     */
    private function getWebsiteSecretKey($code)
    {
        return $this->_scopeConfig->getValue("tabby/tabby_api/secret_key", ScopeInterface::SCOPE_WEBSITE, $code);
    }

    /**
     * Check if Secret key is test
     *
     * @param string $key
     * @return bool
     */
    private function isTestKey($key)
    {
        return (bool)preg_match('#^sk_test#', $key);
    }

    /**
     * Check if share feed check box selected for website
     *
     * @param string $code
     * @return bool
     */
    public function isFeedEnabledForWebsite($code)
    {
        return $this->_scopeConfig->getValue("tabby/tabby_feed/share_feed", ScopeInterface::SCOPE_WEBSITE, $code);
    }

    /**
     * Check at least one method active for given store id
     *
     * @param int $storeId
     * @return bool
     */
    private function isMethodActive($storeId)
    {
        $active = false;
        foreach (Config::ALLOWED_SERVICES as $method => $title) {
            if ($this->_scopeConfig->getValue(
                'payment/' . $method . '/active',
                ScopeInterface::SCOPE_STORE,
                $storeId
            )) {
                $active = true;
            }
        }
        return $active;
    }

    /**
     * Return config value by website code
     *
     * @param string $path
     * @param string $websiteCode
     * @return mixed
     */
    private function getWebsiteConfigValue($path, $websiteCode)
    {
        return $this->_scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITE, $websiteCode);
    }
    /**
     * Log exceptions
     *
     * @param \Exception $e
     */
    public function log(\Exception $e)
    {
        return true;
    }
}
