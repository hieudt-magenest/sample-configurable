<?php

namespace Magenest\Configurable\Controller\Index;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\InventoryApi\Api\Data\SourceItemInterface;

/**
 * Class Index
 * Magenest\Configurable\Controller\Index
 */
class Index extends Action
{
    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $_productFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product
     */
    protected $_resourceModel;

    /**
     * @var SourceItemInterface
     */
    protected $sourceItemsSaveInterface;

    /**
     * @var \Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory
     */
    protected $sourceItem;

    /**
     * @var \Magento\Eav\Api\AttributeRepositoryInterface
     */
    protected $attributeRepository;

    /**
     * @var \Magento\ConfigurableProduct\Helper\Product\Options\Factory
     */
    protected $_optionsFactory;
    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var \Magento\Catalog\Setup\CategorySetup
     */
    protected $categorySetup;

    /**
     * Index constructor.
     * @param \Magento\Catalog\Model\ProductFactory $productFactory
     * @param \Magento\Catalog\Model\ResourceModel\Product $resourceModel
     * @param SourceItemInterface $sourceItemsSaveInterface
     * @param \Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory $sourceItem
     * @param \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository
     * @param \Magento\ConfigurableProduct\Helper\Product\Options\Factory $optionsFactory
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     * @param \Magento\Catalog\Setup\CategorySetup $categorySetup
     * @param Context $context
     */
    public function __construct(
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Catalog\Model\ResourceModel\Product $resourceModel,
        \Magento\InventoryApi\Api\Data\SourceItemInterface $sourceItemsSaveInterface,
        \Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory $sourceItem,
        \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository,
        \Magento\ConfigurableProduct\Helper\Product\Options\Factory $optionsFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Catalog\Setup\CategorySetup $categorySetup,
        Context $context
    ) {
        parent::__construct($context);

        $this->_productFactory = $productFactory;
        $this->_resourceModel = $resourceModel;
        $this->sourceItemsSaveInterface = $sourceItemsSaveInterface;
        $this->sourceItem = $sourceItem;
        $this->attributeRepository = $attributeRepository;
        $this->_optionsFactory = $optionsFactory;
        $this->productRepository = $productRepository;
        $this->categorySetup = $categorySetup;
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        $product = $this->_productFactory->create();
        $colorAttr = $this->attributeRepository->get(Product::ENTITY, 'color');
        $bottomSetId =$this->categorySetup->getAttributeSetId(Product::ENTITY, 'Bottom');
        $associatedProductIds = [];
        $options = $colorAttr->getOptions();
        $values = [];
        $sourceItems = [];
        array_shift($options);
        try {
            //Create Simple produict
            foreach ($options as $index => $option) {
                $product->unsetData();
                $product->setTypeId(Type::TYPE_SIMPLE)
                    ->setAttributeSetId($bottomSetId)
                    ->setWebsiteIds([1])
                    ->setName('Sample-' . $option->getLabel())
                    ->setSku('simple_' . $index)
                    ->setPrice(10)
                    ->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE)
                    ->setStatus(Status::STATUS_ENABLED);
                $product->setCustomAttribute(
                    $colorAttr->getAttributeCode(),
                    $option->getValue()
                );
                $this->_resourceModel->save($product);

                // Update Stock Data
                $sourceItem = $this->sourceItem->create();
                $sourceItem->setSourceCode(null);
                $sourceItem->setSourceCode('default');
                $sourceItem->setQuantity(10);
                $sourceItem->setSku($product->getSku());
                $sourceItem->setStatus(SourceItemInterface::STATUS_IN_STOCK);
                $sourceItems[] = $sourceItem;

                $values[] = [
                    "value_index" => $option->getValue(),
                ];

                $associatedProductIds[] = $product->getId();
            }

            //Execute Update Stock Data
            $this->sourceItemsSaveInterface->execute($sourceItems);

            //Create Configurable Product
            $configurable = $product->unsetData();
            $configurable->setSku('sample_configurable');
            $configurable->setName('Sample Configurable');
            $configurable->setTypeId(Configurable::TYPE_CODE);
            $configurable->setPrice(10);
            $configurable->setAttributeSetId($this->categorySetup->getAttributeSetId(Product::ENTITY, 'Default'));
            $configurable->setCustomAttributes([
                [
                    "attribute_code" => $colorAttr->getAttributeCode(),
                    "value" => $colorAttr->getDefaultValue(),
                ],
            ]);

            //Assign simple products to the configurable product
            $extensionAttrs = $configurable->getExtensionAttributes();
            $extensionAttrs->setConfigurableProductLinks($associatedProductIds);
            $optionsFact = $this->_optionsFactory->create([
                [
                    "attribute_id" => $colorAttr->getId(),
                    "label" => $colorAttr->getDefaultFrontendLabel(),
                    "position" => 0,
                    "values" => $values,
                ]
            ]);
            $extensionAttrs->setConfigurableProductOptions($optionsFact);
            $configurable->setExtensionAttributes($extensionAttrs);

            $this->productRepository->save($configurable);
        } catch (\Exception $ex) {
            echo $ex->getMessage();
        }
    }
}
