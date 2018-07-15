<?php

namespace Webkul\ShopifyBundle\JobParameters;

use Akeneo\Component\Batch\Job\JobInterface;
use Akeneo\Component\Batch\Job\JobParameters\ConstraintCollectionProviderInterface;
use Akeneo\Component\Batch\Job\JobParameters\DefaultValuesProviderInterface;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Constraints\Optional;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Blank;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Type;
use Pim\Component\Catalog\Query\Filter\Operators;
use Pim\Component\Connector\Validator\Constraints\FilterStructureLocale;
use Pim\Component\Connector\Validator\Constraints\FilterStructureAttribute;
use Pim\Component\Connector\Validator\Constraints\FilterData;
use Webkul\ShopifyBundle\Classes\Validators\Credential;

class ShopifyQuickExport implements
    ConstraintCollectionProviderInterface,
    DefaultValuesProviderInterface
{
    /** @var string[] */
    private $supportedJobNames;
    private $channelRepository;
    private $localeRepository;    

    /**
     * @param string[]                              $supportedJobNames
     */
    public function __construct(
        array $supportedJobNames,
        $channelRepository,
        $localeRepository ) {
        $this->supportedJobNames = $supportedJobNames;
        $this->channelRepository = $channelRepository;
        $this->localeRepository = $localeRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultValues()
    {
        $channels = $this->channelRepository->getFullChannels();
        $defaultChannelCode = (0 !== count($channels)) ? $channels[0]->getCode() : null;

        $localesCodes = $this->localeRepository->getActivatedLocaleCodes();
        $defaultLocaleCode = (0 !== count($localesCodes)) ? $localesCodes[0] : null;

        $parameters['selected_properties'] = null;
        $parameters['with_media'] = true;
        $parameters['filters'] = [
            'data'      => [
                [
                    'field'    => 'enabled',
                    'operator' => Operators::EQUALS,
                    'value'    => true,
                ],
                [
                    'field'    => 'completeness',
                    'operator' => 'ALL',
                    'value'    => 100,
                ],
                [
                    'field'    => 'categories',
                    'operator' => Operators::IN_CHILDREN_LIST,
                    'value'    => []
                ]
            ],
            'structure' => [
                'scope'   => $defaultChannelCode,
                'locales' => [],
            ],
        ];

        return $parameters;
    }

    /**
     * {@inheritdoc}
     */
    public function getConstraintCollection()
    {
        $constraintFields['user_to_notify'] = new Optional();
        $constraintFields['with_media'] = new Optional();
        $constraintFields['filters'] = new NotBlank();
        $constraintFields['product_only'] = new Optional();
        $constraintFields['selected_properties'] = new Optional();

        /* quick export specific */ 
        $constraintFields['locale'] = new Optional();
        $constraintFields['scope'] = new Optional();
        $constraintFields['ui_locale'] = new Optional();

        return new Collection([
                        'fields' => $constraintFields,
                        'allowExtraFields' => true,                    
                    ]);
    }

    /**
     * {@inheritdoc}
     */
    public function supports(JobInterface $job)
    {
        return in_array($job->getName(), $this->supportedJobNames);
    }
}
