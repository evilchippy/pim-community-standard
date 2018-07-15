<?php

namespace Webkul\ShopifyBundle\Connector\Reader;

use Pim\Component\Connector\Reader\Database\CategoryReader as BaseReader;
use Akeneo\Component\Classification\Repository\CategoryRepositoryInterface;
use Pim\Bundle\CatalogBundle\Doctrine\ORM\Repository\ChannelRepository;
use Doctrine\Common\Collections\ArrayCollection;

class CategoryReader extends BaseReader
{
    /**
     * @param CategoryRepositoryInterface $repository
     */
    public function __construct(CategoryRepositoryInterface $repository,ChannelRepository $channelRepo)
    {
        $this->repository = $repository;
        $this->channelRepo = $channelRepo;
    }

    /**
     * {@inheritdoc}
     */
    protected function getResults()
    {
        $filters = $this->stepExecution->getJobParameters()->get('filters');
        $channelCode = !empty($filters['structure']['scope']) ? $filters['structure']['scope'] : '';
        $channel = $channelCode ? $this->channelRepo->findOneByIdentifier($channelCode) : null;
        $rootCategory = $channel && $channel->getCategory() ? $channel->getCategory() : null; 
        $rootCategoryId = $rootCategory ? $rootCategory->getId() : null;


        $filteredCategories = $this->getCategoriesFromFilter($filters['data']);
        if(count($filteredCategories) == 1 && $rootCategory->getCode() === reset($filteredCategories)) {
            if($rootCategoryId) {
                $categories = $this->repository->findBy(
                                [ 'root' => $rootCategoryId ],
                                [ 'root' => 'ASC', 'left' => 'ASC' ]
                            );
            } else {
                $categories = $this->repository->getOrderedAndSortedByTreeCategories();
            }

            foreach($categories as $key => $category) {
                if($rootCategoryId == $category->getId()) {
                    unset($categories[$key]);
                    break;
                }
            }
        } else {
            $categories = $this->repository->getCategoriesByCodes($filteredCategories);
            if($categories instanceof ArrayCollection) {
                $categories = $categories->toArray();
            }
        }

        return new \ArrayIterator($categories);
    }

    private function getCategoriesFromFilter($data)
    {
        $result = null;
        foreach($data as $key => $value) {
            if(!empty($value['field']) && 'categories' == $value['field']  ) {
                $result = $value['value'];
            }
        }
        return $result;
    }
}
