<?php
namespace Tabby\Feed\Model;

use Tabby\Feed\Model\Api\Feed as FeedApi;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Store\Model\StoreManager;
use Magento\Catalog\Model\ResourceModel\Url as CatalogUrl;
use Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use Magento\CatalogInventory\Api\StockRegistryInterface as StockRegistry;

class Store
{
    private const TABBY_FEED_PRODUCTS_LIMIT = 100;
    private const TABBY_FEED_MIN_BULK = 5;
    private const TABBY_FEED_NAX_REQUESTS = 5;
    /**
     * @var FeedApi
     */
    protected $_api;
    /**
     * @var ProductFactory
     */
    protected $_productFactory;
    /**
     * @var MediaConfig
     */
    protected $_mediaConfig;
    /**
     * @var StoreManager
     */
    protected $_storeManager;
    /**
     * @var CatalogUrl
     */
    protected $_catalogUrl;
    /**
     * @var GalleryReadHandler
     */
    protected $_galleryReader;
    /**
     * @var StockRegistry
     */
    protected $_stockRegistry;
    /**
     * @var array
     */
    protected $_config;
    /**
     * Class constructor
     *
     * @param FeedApi $api
     * @param ProductFactory $productFactory
     * @param MediaConfig $mediaConfig
     * @param StoreManager $storeManager
     * @param CatalogUrl $catalogUrl
     * @param GalleryReadHandler $galleryReader
     * @param StockRegistry $stockRegistry
     * @param Array $config
     */
    public function __construct(
        FeedApi $api,
        ProductFactory $productFactory,
        MediaConfig $mediaConfig,
        StoreManager $storeManager,
        CatalogUrl $catalogUrl,
        GalleryReadHandler $galleryReader,
        StockRegistry $stockRegistry,
        Array $config = []
    ) {
        $this->_api = $api;
        $this->_productFactory = $productFactory;
        $this->_mediaConfig = $mediaConfig;
        $this->_storeManager = $storeManager;
        $this->_catalogUrl = $catalogUrl;
        $this->_galleryReader = $galleryReader;
        $this->_stockRegistry = $stockRegistry;
        $this->_config = $config;

        $this->_api->setConfig($this->_config);
    }
    /**
     * Register feed store with Tabby
     */
    public function register()
    {
        return $this->_api->register();
    }
    /**
     * Unregister feed store with Tabby
     */
    public function unregister()
    {
        return $this->_api->unregister();
    }
    /**
     * Get products for initial sync
     */
    public function getInitialSyncProductsList()
    {
        // get products collection
        foreach ($this->getStoreProductCollection() as $product) {
            // get product record
            $products[] = $product->getId();
        }
        return $products;
    }
    /**
     * Get products for current Magento store
     */
    public function getStoreProductCollection()
    {
        return $this->_productFactory->create()
            ->setStoreId($this->_config['storeId'])
            ->getCollection()
            ->addAttributeToSelect('*');
    }
    /**
     * Mark deleted products as unavailable in Tabby marketplace
     *
     * @param \Magento\Catalog\Model\Product $product
     */
    public function deleteProduct($product)
    {
        switch ($product->getTypeId()) {
            case 'simple':
                $rec = $this->getDeletedProductAvailabilityBody($product);
                $this->_api->sendAvailability([$rec]);
                break;
            case 'configurable':
                // get all children ids
                $children = $product->getTypeInstance()->getUsedProducts($product);
                $recs = [];
                foreach ($children as $child) {
                    $recs[] = $this->getDeletedProductAvailabilityBody($child);
                }
                if (!empty($recs)) {
                    $this->_api->sendAvailability($recs);
                }
                break;
        }
    }
    /**
     * Get availablity request body for single product
     *
     * @param \Magento\Catalog\Model\Product $product
     */
    public function getDeletedProductAvailabilityBody($product)
    {
        $data = [
            "id"            => $product->getId(),
            "isAvailable"   => false
        ];
        return $data;
    }
    /**
     * Sync list of products to Tabby marketplace
     *
     * @param array $ids
     */
    public function syncProducts($ids)
    {
        $totalCount = count($ids);
        $bulkSize = $this->getBulkSize(count($ids));
        $bulks = [];
        $records = [];
        foreach ($this->getProductCollectionByIds($ids) as $product) {
            $recs = [];
            switch ($product->getTypeId()) {
                case 'simple':
                    $recs[] = $this->getSingleStoreProduct($product);
                    break;
                case 'configurable':
                    // get all children ids
                    $children = $product->getTypeInstance()->getUsedProducts($product);
                    if (($key = array_search($product->getId(), $ids)) !== false) {
                        unset($ids[$key]);
                    }
                    foreach ($children as $child) {
                        $recs[] = $this->getSingleStoreProduct($child, $product);
                        $ids[] = $child->getId();
                    }
                    break;
                default:
                    // ignore other product types
                    continue 2;
            }
            foreach ($recs as $rec) {
                if ($this->validateProduct($rec)) {
                    $records[$rec['id']] = $rec;
                    if (count($records) == $bulkSize) {
                        $bulks[] = $records;
                        $records = [];
                    }
                } else {
                    // unset not valid product id
                    $ids = array_diff($ids, [$rec['id']]);
                }
            }
        }
        if (!empty($records)) {
            $bulks[] = $records;
        }
        foreach ($bulks as $bulk) {
            if ($this->_api->updateProducts(array_values($bulk))) {
                $ids = array_diff($ids, array_keys($bulk));
            }
        }
        return $ids;
    }
    /**
     * Check product is valid record for Tabby marketplace
     *
     * @param array $record
     */
    public function validateProduct($record)
    {
        return (
            (array_key_exists('images', $record) && !empty($record['images'])) &&
            (array_key_exists('price', $record) && !empty($record['price']))
        );
    }
    /**
     * Retrive product collection by ids provided
     *
     * @param array $ids
     */
    public function getProductCollectionByIds($ids)
    {
        return $this->_productFactory->create()
            ->setStoreId($this->_config['storeId'])
            ->getCollection()
            ->addFieldToFilter('entity_id', ['in' => $ids])
            ->addAttributeToSelect('*');
    }
    /**
     * Get request bulk size
     *
     * @param int $num
     */
    public function getBulkSize($num)
    {
        $sizeNeeded = (int)ceil($num / self::TABBY_FEED_NAX_REQUESTS);
        return ($sizeNeeded > self::TABBY_FEED_PRODUCTS_LIMIT)
            ? self::TABBY_FEED_PRODUCTS_LIMIT
            : ($sizeNeeded < self::TABBY_FEED_MIN_BULK ? self::TABBY_FEED_MIN_BULK : $sizeNeeded);
    }
    /**
     * Get single product record
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param \Magento\Catalog\Model\Product|null $parent
     */
    public function getSingleStoreProduct($product, $parent = null)
    {
        $data = [];
        $data['id'] = (string)$product->getId();
        // TODO: check for parent IDs
        $parents = $product->getTypeInstance()->getParentIdsByChild($product->getId());
        if ($parent !== null) {
            $data['group_id'] = (string)$parent->getId();
        }
        // get product is vailable for sale
        $data['isAvailable'] = (bool)$this->getTabbyIsAvailable($product);
        // get product images
        $data['images'] = $this->getTabbyProductImages($product);
        if (empty($data['images']) && ($parent !== null)) {
            $data['images'] = $this->getTabbyProductImages($parent);
        }
        $data = $this->addPriceData($data, $product);
        // get language specific variables
        foreach ($this->_config['languages'] as $code => $stores) {
            if (empty($stores)) {
                continue;
            }
            $data[$code] = $this->getProductLanguageData($product, $stores[0], $parent);
        }
        return $data;
    }
    /**
     * Add price data to record
     *
     * @param array $data
     * @param \Magento\Catalog\Model\Product $product
     */
    public function addPriceData($data, $product)
    {
        // get needed currency object and fill price
        $store = $this->_storeManager->getStore($this->_config['storeId']);
        $baseCurrency = $store->getBaseCurrency();
        $localCurrency = $store->setCurrentCurrencyCode($this->_config['currency'])->getCurrentCurrency();
        // TODO: price more then zero
        $specialPrice = $product->getSpecialPrice();
        $price = $product->getPrice();
        $data['price'] = strip_tags($localCurrency->format($baseCurrency->convert($price, $this->_config['currency'])));
        if ($specialPrice) {
            $data['salePrice'] = strip_tags($localCurrency->format(
                $baseCurrency->convert($specialPrice, $this->_config['currency'])
            ));
        }
        return $data;
    }
    /**
     * Get images URLs for product record
     *
     * @param \Magento\Catalog\Model\Product $product
     */
    public function getTabbyProductImages($product)
    {
        $images = [];
        $this->_galleryReader->execute($product);
        foreach ($product->getMediaGalleryImages() as $image) {
            $images[] = $image->getUrl();
        }
        return array_unique($images);
    }
    /**
     * Get language specific data for product record
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param int $storeId
     * @param \Magento\Catalog\Model\Product|null $parent
     */
    public function getProductLanguageData($product, $storeId, $parent = null)
    {
        // TODO: parent data in title and description
        return [
            'title'         => $this->getTabbyProductTitle($product, $storeId, $parent),
            'description'   => $this->getTabbyProductDescription($product, $storeId, $parent),
            'categories'    => $this->getTabbyCategories($product, $storeId, $parent),
            'attributes'    => $this->getTabbyAttributes($product, $storeId, $parent),
            'link'          => $this->getProductUrl($parent ?: $product, $storeId)
        ];
    }
    /**
     * Get product title
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param int $storeId
     * @param \Magento\Catalog\Model\Product|null $parent
     */
    public function getTabbyProductTitle($product, $storeId, $parent)
    {
        $title = $product->getResource()->getAttributeRawValue($product->getId(), 'name', $storeId);
        if ((!is_string($title) || empty($title)) && ($parent !== null)) {
            $title = $parent->getResource()->getAttributeRawValue($parent->getId(), 'name', $storeId);
        }
        return is_string($title) ? $title : '';
    }
    /**
     * Get product description
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param int $storeId
     * @param \Magento\Catalog\Model\Product|null $parent
     */
    public function getTabbyProductDescription($product, $storeId, $parent)
    {
        $description = $product->getResource()
            ->getAttributeRawValue($product->getId(), 'description', $storeId);
        if (!is_string($description) || empty($description)) {
            $description = $product->getResource()
                ->getAttributeRawValue($product->getId(), 'short_description', $storeId);
        }
        if (!is_string($description) || empty($description)) {
            if ($parent !== null) {
                $description = $parent->getResource()
                    ->getAttributeRawValue($parent->getId(), 'description', $storeId);
                if (!is_string($description) || empty($description)) {
                    $description = $parent->getResource()
                        ->getAttributeRawValue($parent->getId(), 'short_description', $storeId);
                }
            }
        }
        return is_string($description) ? $description : '';
    }
    /**
     * Get product categories
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param int $storeId
     * @param \Magento\Catalog\Model\Product|null $parent
     */
    public function getTabbyCategories($product, $storeId, $parent)
    {
        $rootCategoryId = $this->_storeManager->getStore($storeId)->getRootCategoryId();
        $categories = [];
        if ($cIds = $product->getCategoryIds()) {
            foreach ($cIds as $cId) {
                $categories[] = $this->getTabbyCategoryPath($rootCategoryId, $cId, $product, $storeId);
            }
        }
        if (empty($categories) && ($parent !== null) && ($cIds = $parent->getCategoryIds())) {
            foreach ($cIds as $cId) {
                $categories[] = $this->getTabbyCategoryPath($rootCategoryId, $cId, $parent, $storeId);
            }
        }
        if (empty($categories)) {
            $categories[] = ['path' => 'Uncategorized'];
        }
        return $categories;
    }
    /**
     * Get array with parent categories names
     *
     * @param int $rootCategoryId
     * @param int $cId
     * @param \Magento\Catalog\Model\Product|null $product
     * @param int $storeId
     */
    public function getTabbyCategoryPath($rootCategoryId, $cId, $product, $storeId)
    {
        $path = [];
        // use product as category repository
        $category = $product->setCategory(null)->setCategoryId($cId)->getCategory();
        // recursion until root category
        while ($category->getParentId() && ($rootCategoryId != $category->getId())) {
            $path[] = $category->getResource()->getAttributeRawValue($category->getId(), 'name', $storeId);
            $category = $category->getParentCategory();
        }
        return ['path' => array_reverse($path)];
    }
    /**
     * Get attributes for product
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param int $storeId
     * @param \Magento\Catalog\Model\Product|null $parent
     */
    public function getTabbyAttributes($product, $storeId, $parent)
    {
        $result = [];
        if ($parent) {
            $productTypeInstance = $parent->getTypeInstance();
            $productTypeInstance->setStoreFilter($storeId, $parent);
            $attributes = $productTypeInstance->getConfigurableAttributes($parent);
            $superAttributeList = [];
            foreach ($attributes as $_attribute) {
                $attributeCode = $_attribute->getProductAttribute()->getAttributeCode();
                ;
                //$superAttributeList[$_attribute->getAttributeId()] = $attributeCode;
                $result[] = [
                    'name'  => $attributeCode,
                    'values'=> [(string)$product->getResource()->getAttribute($attributeCode)->getSource()
                        ->getOptionText(
                            $product->getResource()
                                ->getAttributeRawValue($product->getId(), $attributeCode, $storeId)
                        )
                    ]
                ];
            }
        } else {
            $options = $product->getProductOptionsCollection();
            foreach ($options as $option) {
                $optionData = $option->getData();
                switch ($option->getType()) {
                    case 'drop_down':
                    case 'multiple':
                    case 'radio':
                    case 'checkbox':
                        $values = [];
                        foreach ($option->getValues() as $value) {
                            $values[] = $value->getTitle();
                        };
                        $result[] = [
                            'name'      => $option->getTitle(),
                            'values'    => $values
                        ];
                        break;
                }
            }
        }
        return $result;
    }
    /**
     * Get product URL in store
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param int $storeId
     */
    public function getProductUrl($product, $storeId)
    {
        $objects = $this->_catalogUrl->getRewriteByProductStore([$product->getId() => $storeId]);
        if (isset($objects[$product->getId()])) {
            $object = new \Magento\Framework\DataObject($objects[$product->getId()]);
            $product->setUrlDataObject($object);
        }
        return $product->getUrlInStore();
    }
    /**
     * Get availability for product
     *
     * @param \Magento\Catalog\Model\Product $product
     */
    public function getTabbyIsAvailable($product)
    {
        $stockItem = $this->_stockRegistry->getStockItem($product->getId());
        return $product->isSalable() && $stockItem->getIsInStock();
    }
}
