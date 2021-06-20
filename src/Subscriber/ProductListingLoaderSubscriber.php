<?php declare(strict_types=1);
namespace SasProductSoldOutToEnd\Subscriber;

use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingLoader;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\FilterAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductListingLoaderSubscriber implements EventSubscriberInterface
{
    private const NOT_OUT_OF_STOCK_FILTER_NAME = 'notOutOfStockFilter';
    private const COUNT_PRODUCT_SOLD_OUT_AGGREGATION = 'count-product-sold-out';
    private const COUNT_PRODUCT_NOT_SOLD_OUT_AGGREGATION = 'count-product-not-sold-out';

    private ProductListingLoader $listingLoader;

    public function __construct(ProductListingLoader $listingLoader)
    {
        $this->listingLoader = $listingLoader;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductSearchCriteriaEvent::class => ['onProductListingCriteriaLoaded', -200],
            ProductListingCriteriaEvent::class => ['onProductListingCriteriaLoaded', -200],
            ProductSearchResultEvent::class => 'onProductListingLoaded',
            ProductListingResultEvent::class => 'onProductListingLoaded',
        ];
    }

    public function onProductListingCriteriaLoaded(ProductListingCriteriaEvent $event): void
    {
        $criteria = $event->getCriteria();

        $notOutOfStockFilter = $this->getProductNotSoldOutCriteria();

        $notOutOfStockFilter->assign([
            'name' => self::NOT_OUT_OF_STOCK_FILTER_NAME
        ]);

        $criteria->addAggregation(new FilterAggregation(
                'product-sold-out',
                new CountAggregation(self::COUNT_PRODUCT_SOLD_OUT_AGGREGATION, 'id'),
                [
                    $this->getProductSoldOutCriteria()
                ]
            )
        );

        $criteria->addAggregation(new FilterAggregation(
                'product-not-sold-out',
                new CountAggregation(self::COUNT_PRODUCT_NOT_SOLD_OUT_AGGREGATION, 'id'),
                [
                    $this->getProductNotSoldOutCriteria()
                ]
            )
        );

        $criteria->addPostFilter($notOutOfStockFilter);
    }

    public function onProductListingLoaded(ProductListingResultEvent $event): void
    {
        $result = $event->getResult();
        /** @var CountResult|null $countSoldOutAgg */
        $countSoldOutAgg = $result->getAggregations()->get(self::COUNT_PRODUCT_SOLD_OUT_AGGREGATION);

        if ($countSoldOutAgg === null || $countSoldOutAgg->getCount() === 0) {
            return;
        }

        /** @var CountResult|null $countNotSoldOutAgg */
        $countNotSoldOutAgg = $result->getAggregations()->get(self::COUNT_PRODUCT_NOT_SOLD_OUT_AGGREGATION);

        if ($countNotSoldOutAgg === null || $countNotSoldOutAgg->getCount() === 0) {
            return;
        }

        $isLastPage = $countNotSoldOutAgg->getCount() < $result->getPage() * $result->getLimit();

        if (!$isLastPage) {
            return;
        }

        $totalNotSoldOutProduct = $countNotSoldOutAgg->getCount();
        $countSoldOutProduct = $countSoldOutAgg->getCount();

        $limitSoldOutThisPage = $result->getLimit() - $result->count();

        $criteria = Criteria::createFrom($result->getCriteria());
        $criteria->setLimit($limitSoldOutThisPage);
        $offset = $result->getPage() * $result->getLimit() - $totalNotSoldOutProduct - $limitSoldOutThisPage;

        $criteria->setOffset($offset > 0 ? $offset : 0);
        $criteria->resetPostFilters();
        $criteria->resetAggregations();

        $criteria->addFilter($this->getProductSoldOutCriteria());

        $outOfStock = $this->listingLoader->load($criteria, $event->getSalesChannelContext());
        if ($outOfStock->count() === 0) {
            return;
        }

        $result->getEntities()->merge($outOfStock->getEntities());
        $result->merge($outOfStock->getEntities());
        $result->assign([
            'total' => $totalNotSoldOutProduct + $countSoldOutProduct
        ]);
    }

    private function getProductSoldOutCriteria(): Filter
    {
        return new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('stock', 0),
            new EqualsFilter('isCloseout', true),
        ]);
    }

    private function getProductNotSoldOutCriteria(): Filter
    {
        return new NotFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('stock', 0),
            new EqualsFilter('isCloseout', true),
        ]);
    }
}


