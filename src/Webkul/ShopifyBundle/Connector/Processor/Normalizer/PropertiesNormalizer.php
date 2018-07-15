<?php

namespace Webkul\ShopifyBundle\Connector\Processor\Normalizer;

use Doctrine\Common\Collections\ArrayCollection;
use Pim\Bundle\CatalogBundle\Filter\CollectionFilterInterface;
use Pim\Component\Catalog\Model\ProductInterface;
use Pim\Component\Catalog\Model\ValueCollectionInterface;
use Pim\Component\Catalog\Model\VariantProductInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerAwareTrait;
use Pim\Component\Catalog\Model\ProductModel;

/**
 * Transform the properties of a product object (fields and product values)
 * to a standardized array
 */
class PropertiesNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    use SerializerAwareTrait;

    const FIELD_IDENTIFIER = 'identifier';
    const FIELD_FAMILY = 'family';
    const FIELD_PARENT = 'parent';
    const FIELD_GROUPS = 'groups';
    const FIELD_CATEGORIES = 'categories';
    const FIELD_ENABLED = 'enabled';
    const FIELD_VALUES = 'values';
    const FIELD_CREATED = 'created';
    const FIELD_UPDATED = 'updated';
    const FIELD_MAIN_IMAGE = 'attributeAsImage';
    const FIELD_VARIANT_ATTRIBUTES = 'variantAttributes';
    const FIELD_VARIANT_ALL_ATTRIBUTES = 'allVariantAttributes';

    /** @var CollectionFilterInterface */
    private $filter;

    /**
     * @param CollectionFilterInterface $filter The collection filter
     */
    public function __construct(CollectionFilterInterface $filter)
    {
        $this->filter = $filter;
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($product, $format = null, array $context = [])
    {

        if (!$this->serializer instanceof NormalizerInterface) {
            throw new \LogicException('Serializer must be a normalizer');
        }

        $context = array_merge(['filter_types' => ['pim.transform.product_value.structured']], $context);
        $data = [];

        $data[self::FIELD_IDENTIFIER] = $product->getIdentifier();
        $data[self::FIELD_FAMILY] = $product->getFamily() ? $product->getFamily()->getCode() : null;
        if($product->getFamily()) {
            $data[self::FIELD_MAIN_IMAGE] = $product->getFamily()->getAttributeAsImage() ? $product->getFamily()->getAttributeAsImage()->getCode() : null ;
        }

        if ($this->isVariantProduct($product) && null !== $product->getParent()) {
            $data[self::FIELD_PARENT] = $product->getParent()->getCode();
            if($product->getParent() && $product->getParent()->getParent()) {
                $data[self::FIELD_PARENT] = $product->getParent()->getParent()->getCode();                
            }
            $data[self::FIELD_VARIANT_ATTRIBUTES] = $this->getVariantAxes($product->getParent());
            $data[self::FIELD_VARIANT_ALL_ATTRIBUTES] = $this->getVariantAttributes($product->getParent());            
        } else {
            $data[self::FIELD_PARENT] = null;
        }
        $data[self::FIELD_GROUPS] = $product->getGroupCodes();
        $data[self::FIELD_CATEGORIES] = $product->getCategoryCodes();
        $data[self::FIELD_ENABLED] = (bool) $product->isEnabled();
        $data[self::FIELD_VALUES] = $this->normalizeValues($product->getValues(), $format, $context);
        $data[self::FIELD_CREATED] = $this->serializer->normalize($product->getCreated(), $format);
        $data[self::FIELD_UPDATED] = $this->serializer->normalize($product->getUpdated(), $format);

        return $data;
    }

    protected function getVariantAxes($product)
    {
        $result = [];
        $varAttributeSets = $product->getFamilyVariant()->getVariantAttributeSets();
        foreach($varAttributeSets as $attrSet) {
            $axises = $attrSet->getAxes();
            foreach($axises as $axis) {
                $result[] = $axis->getCode();
            }
        }
        return $result;
    }    

    protected function getVariantAttributes($product)
    {
        $result = [];
        $varAttributeSets = $product->getFamilyVariant()->getVariantAttributeSets();
        foreach($varAttributeSets as $attrSet) {
            $axises = $attrSet->getAttributes();

            foreach($axises as $axis) {
                $result[] = $axis->getCode();
            }
        }

        return $result;
    }

    protected function isVariantProduct($product)
    {
        $flag = false;
        if(method_exists($product, 'isVariant')) {
            $flag = $product->isVariant();
        } else {
            $flag = ($product instanceof \Pim\Component\Catalog\Model\VariantProductInterface);            
        }

        return $flag;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null)
    {
        return $data instanceof ProductInterface && 'standard' === $format;
    }

    /**
     * Normalize the values of the product
     *
     * @param ValueCollectionInterface $values
     * @param string                   $format
     * @param array                    $context
     *
     * @return ArrayCollection
     */
    private function normalizeValues(ValueCollectionInterface $values, $format, array $context = [])
    {
        foreach ($context['filter_types'] as $filterType) {
            $values = $this->filter->filterCollection($values, $filterType, $context);
        }

        $data = $this->serializer->normalize($values, $format, $context);

        return $data;
    }
}