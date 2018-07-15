<?php

namespace Webkul\ShopifyBundle\Connector\Processor;

use Akeneo\Component\Batch\Item\DataInvalidItem;
use Akeneo\Component\Batch\Job\JobInterface;
use Akeneo\Component\Batch\Job\JobParameters;
use Akeneo\Component\Batch\Model\StepExecution;
use Akeneo\Component\StorageUtils\Detacher\ObjectDetacherInterface;
use Pim\Bundle\EnrichBundle\Connector\Processor\AbstractProcessor;
use Pim\Component\Catalog\AttributeTypes;
use Pim\Component\Catalog\Model\ProductInterface;
use Pim\Component\Catalog\Repository\AttributeRepositoryInterface;
use Pim\Component\Catalog\Repository\ChannelRepositoryInterface;
use Pim\Component\Catalog\ValuesFiller\EntityWithFamilyValuesFillerInterface;
use Pim\Component\Connector\Processor\BulkMediaFetcher;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Pim\Component\Catalog\Model\VariantProductInterface;

/**
 * Product processor to process and normalize entities to the standard format, normailze product and add variantAttribute to it
 *
 * This processor doesn't fetch media to directory nor does filter attribute & doesn't use the channel in configuration field but from job configuration
 *
 * @author    ankit yadav <ankit.yadav726@webkul.com>
 * @copyright webkul (http://webkul.com)
 * @license   http://store.webkul.com/license.html
 */
class ProductQuickProcessor extends AbstractProcessor
{
    /** @var NormalizerInterface */
    protected $normalizer;

    /** @var ChannelRepositoryInterface */
    protected $channelRepository;

    /** @var AttributeRepositoryInterface */
    protected $attributeRepository;

    /** @var EntityWithFamilyValuesFillerInterface */
    protected $valuesFiller;

    /** @var ObjectDetacherInterface */
    protected $detacher;

    /** @var UserProviderInterface */
    protected $userProvider;

    /** @var TokenStorageInterface */
    protected $tokenStorage;

    /** @var BulkMediaFetcher */
    protected $mediaFetcher;

    const FIELD_MAIN_IMAGE = 'attributeAsImage';
    const FIELD_VARIANT_ATTRIBUTES = 'variantAttributes';
    const FIELD_VARIANT_ALL_ATTRIBUTES = 'allVariantAttributes';    
    const FIELD_PARENT = 'parent';

    /**
     * @param NormalizerInterface                   $normalizer
     * @param ChannelRepositoryInterface            $channelRepository
     * @param AttributeRepositoryInterface          $attributeRepository
     * @param EntityWithFamilyValuesFillerInterface $valuesFiller
     * @param ObjectDetacherInterface               $detacher
     * @param UserProviderInterface                 $userProvider
     * @param TokenStorageInterface                 $tokenStorage
     * @param BulkMediaFetcher                      $mediaFetcher
     */
    public function __construct(
        NormalizerInterface $normalizer,
        ChannelRepositoryInterface $channelRepository,
        AttributeRepositoryInterface $attributeRepository,
        EntityWithFamilyValuesFillerInterface $valuesFiller,
        ObjectDetacherInterface $detacher,
        UserProviderInterface $userProvider,
        TokenStorageInterface $tokenStorage,
        BulkMediaFetcher $mediaFetcher
    ) {
        $this->normalizer = $normalizer;
        $this->channelRepository = $channelRepository;
        $this->attributeRepository = $attributeRepository;
        $this->valuesFiller = $valuesFiller;
        $this->detacher = $detacher;
        $this->userProvider = $userProvider;
        $this->tokenStorage = $tokenStorage;
        $this->mediaFetcher = $mediaFetcher;
    }

    /**
     * {@inheritdoc}
     */
    public function process($product)
    {
        $this->initSecurityContext($this->stepExecution);

        $parameters = $this->stepExecution->getJobParameters();
        $normalizerContext = $this->getNormalizerContext($parameters);

        if($product instanceof ProductInterface) {
            $productStandard = $this->normalizer->normalize($product, 'standard', $normalizerContext);
        } else {
            $productStandard = null;
        }

        if($productStandard) {
            /* add attributeAsImage */
            if($product->getFamily()) {
                $productStandard[self::FIELD_MAIN_IMAGE] = $product->getFamily()->getAttributeAsImage() ? $product->getFamily()->getAttributeAsImage()->getCode() : null ;
            }

            /* add variantAttributes (axes), allVariantAttributes (attributes in family_variant) */
            if ($this->isVariantProduct($product) && null !== $product->getParent()) {
                $productStandard[self::FIELD_PARENT] = $product->getParent()->getCode();

                if($product->getParent() && $product->getParent()->getParent()) {
                    $productStandard[self::FIELD_PARENT] = $product->getParent()->getParent()->getCode();                
                }
                $productStandard[self::FIELD_VARIANT_ATTRIBUTES] = $this->getVariantAxes($product->getParent());
                $productStandard[self::FIELD_VARIANT_ALL_ATTRIBUTES] = $this->getVariantAttributes($product->getParent());            
            } else {
                $productStandard[self::FIELD_PARENT] = null;
            }
        }

        // $this->detacher->detach($product);

        return $productStandard;
    }

    /**
     * @param JobParameters $parameters
     *
     * @throws \InvalidArgumentException
     *
     * @return array
     */
    protected function getNormalizerContext(JobParameters $parameters)
    {
        if (!$parameters->has('scope')) {
            throw new \InvalidArgumentException('No channel found');
        }

        $normalizerContext = [
            'channels'     => [$parameters->get('scope')],
            'locales'      => $this->getLocaleCodes($parameters->get('scope')),
            'filter_types' => [
                'pim.transform.product_value.structured',
                'pim.transform.product_value.structured.quick_export'
            ]
        ];

        return $normalizerContext;
    }

    /**
     * Get locale codes for a channel
     *
     * @param string $channelCode
     *
     * @return array
     */
    protected function getLocaleCodes($channelCode)
    {
        $channel = $this->channelRepository->findOneByIdentifier($channelCode);

        return $channel->getLocaleCodes();
    }

    /**
     * Initialize the SecurityContext from the given $stepExecution
     *
     * @param StepExecution $stepExecution
     */
    protected function initSecurityContext(StepExecution $stepExecution)
    {
        $username = $stepExecution->getJobExecution()->getUser();
        $user = $this->userProvider->loadUserByUsername($username);

        $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);
    }

    /* get variant axes of products */
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

    /* get variant attributes of product */
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
}
