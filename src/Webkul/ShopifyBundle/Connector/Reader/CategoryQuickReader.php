<?php

namespace Webkul\ShopifyBundle\Connector\Reader;

use Pim\Component\Connector\Reader\Database\CategoryReader as BaseReader;
use Akeneo\Component\Classification\Repository\CategoryRepositoryInterface;
use Pim\Bundle\CatalogBundle\Doctrine\ORM\Repository\ChannelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;

class CategoryQuickReader extends BaseReader
{
    protected $em;
    protected $channelRepo;

    public function __construct(EntityManager $em, ChannelRepository $channelRepo)
    {
        $this->em = $em;
        $this->channelRepo = $channelRepo;
    }

    /**
     * {@inheritdoc}
     */
    protected function getResults()
    {
        $filters = $this->stepExecution->getJobParameters()->get('filters');
        $channelCode = !empty($filters[0]['context']['scope']) ? $filters[0]['context']['scope'] : null;
        $channel = $channelCode ? $this->channelRepo->findOneByIdentifier($channelCode) : null;
        $rootCategory = $channel && $channel->getCategory() ? $channel->getCategory() : null; 
        $rootCategoryId = $rootCategory ? $rootCategory->getId() : null;

        $categoriesIds = $this->getCategoriesIdsByProductFilters($filters, $channel);
        $categoryRepo = $this->em->getRepository('PimCatalogBundle:Category');
        $categories = $categoryRepo->findBy(
                        [ 'root' => $rootCategoryId, 'id' => $categoriesIds ],
                        [ 'root' => 'ASC', 'left' => 'ASC' ]
                    );

        return new \ArrayIterator($categories);
    }

    private function getCategoriesIdsByProductFilters($filters, $channel)
    {
        $options = ['filters' => $filters];

        if (null !== $channel) {
            $options['default_scope'] = $channel->getCode();
        }

        /* get product models */ 
        $productData = $filters[0]['value'];
        $productIds = [];
        $productModelsIds = [];
        foreach($productData as $key => $value) {
            if(0 === strpos($value, 'product_model_')) {
                $productModelsIds[] = str_replace('product_model_', '', $value);
            } else if(0 === strpos($value, 'product_')) {
                $productIds[] = str_replace('product_', '', $value);
            }
        }
        $childs = $this->em->getRepository('Pim\Component\Catalog\Model\Product')->findBy(['parent' => $productModelsIds ]);
        $childProductIds = array_map(function($p) {return $p->getId(); }, $childs);
        $productIds = array_merge($productIds, $childProductIds);

        /* get model categories */
        $conn = $this->em->getConnection();
        $ids = implode("','", $productModelsIds);
        $query = "select DISTINCT(category_id) from pim_catalog_category_product_model where product_model_id IN ('".$ids."')";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $productModelCategories = $stmt->fetchAll();

        /* get product categories */
        $conn = $this->em->getConnection();
        $ids = implode("','", $productIds);
        $query = "select DISTINCT(category_id) from pim_catalog_category_product where product_id IN ('".$ids."')";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $productCategories = $stmt->fetchAll();

        return array_values(array_unique(array_merge(
            array_map( [$this, 'getValuesByCategoryIndex' ], $productModelCategories ),
            array_map( [$this, 'getValuesByCategoryIndex' ], $productCategories )
        )));
    }

    private function getValuesByCategoryIndex($val)
    {
        return !empty($val['category_id']) ? $val['category_id'] : null;
    }
}
