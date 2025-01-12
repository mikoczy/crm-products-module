<?php

namespace Crm\ProductsModule\Repository;

use Crm\ApplicationModule\Repository;
use Crm\ApplicationModule\Repository\AuditLogRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\ProductsModule\Distribution\AmountSpentDistribution;
use Crm\ProductsModule\Distribution\PaymentCountsDistribution;
use Crm\ProductsModule\Distribution\ProductDaysFromLastOrderDistribution;
use Crm\ProductsModule\Distribution\ProductShopCountsDistribution;
use Crm\ProductsModule\PaymentItem\ProductPaymentItem;
use DateTime;
use Nette\Database\Context;
use Nette\Database\Table\IRow;
use Nette\Database\Table\Selection;

class ProductsRepository extends Repository
{
    protected $tableName = 'products';

    private $amountSpentDistribution;

    private $paymentCountDistribution;

    private $productDaysFromLastOrderDistribution;

    private $productShopCountsDistribution;

    public function __construct(
        Context $database,
        AuditLogRepository $auditLogRepository,
        AmountSpentDistribution $amountSpentDistribution,
        PaymentCountsDistribution $paymentCountDistribution,
        ProductDaysFromLastOrderDistribution $productDaysFromLastOrderDistribution,
        ProductShopCountsDistribution $productShopCountsDistribution
    ) {
        parent::__construct($database);
        $this->auditLogRepository = $auditLogRepository;
        $this->amountSpentDistribution = $amountSpentDistribution;
        $this->paymentCountDistribution = $paymentCountDistribution;
        $this->productDaysFromLastOrderDistribution = $productDaysFromLastOrderDistribution;
        $this->productShopCountsDistribution = $productShopCountsDistribution;
    }

    final public function all(string $search = null, array $tags = []): Selection
    {
        $all = $this->getTable()->order('-sorting DESC, name ASC');

        if (empty($tags) && ($search === null || empty(trim($search)))) {
            return $all;
        }

        $searchText = "%{$search}%";
        $conditions = [
            'name LIKE ?' => $searchText,
            'code LIKE ?' => $searchText,
            'user_label LIKE ?' => $searchText,
        ];

        // check if searched text is number (replace comma with period; otherwise is_numeric won't work)
        $searchNum = str_replace(',', '.', $search);
        if (is_numeric($searchNum)) {
            $searchFloat = (float) $searchNum;
            $conditions = array_merge($conditions, [
                'price = ?' => $searchFloat,
                'catalog_price = ?' => $searchFloat,
            ]);
        }

        if (!empty($tags)) {
            $all->where(':product_tags.tag_id IN (?)', $tags);
        }

        return $all->whereOr($conditions);
    }

    final public function getByCode($code)
    {
        return $this->getTable()->where(['code' => $code])->fetch();
    }

    final public function getShopProducts($visibleOnly = true, $availableOnly = true, $tag = null, $order = 'sorting')
    {
        $where = ['products.shop' => true];
        if ($visibleOnly === true) {
            $where['products.visible'] = true;
        }
        if ($availableOnly === true) {
            $where['products.stock > ?'] = 0;
        }
        if (isset($tag)) {
            $where[':product_tags.tag_id'] = $tag->id;
        }

        return $this->getTable()->where($where)->order($order);
    }

    final public function relatedProducts(IRow $product, $limit = 4)
    {
        return $this->getShopProducts(true, true, null, 'RAND()')
            ->where('id != ?', $product->id)
            ->limit($limit);
    }

    final public function mostSoldProducts(DateTime $from = null, DateTime $to = null)
    {
        $products = $this->getShopProducts(true, true, null, 'sold_count DESC')
            ->select('products.*, SUM(:payment_items.count) AS sold_count')
            ->where([
                ':payment_items.type' => ProductPaymentItem::TYPE,
                ':payment_items.payment.status' => PaymentsRepository::STATUS_PAID,
            ])
            ->group('products.id');

        if ($from) {
            $products->where(':payment_items.payment.paid_at >= ?', $from);
        }
        if ($to) {
            $products->where(':payment_items.payment.paid_at < ?', $to);
        }

        return $products;
    }

    final public function findByIds($ids)
    {
        return $this->getTable()->where('id', (array)$ids)->fetchAll();
    }

    final public function updateSorting($newSorting, $oldSorting = null)
    {
        if ($newSorting == $oldSorting) {
            return;
        }

        if ($oldSorting !== null) {
            $this->getTable()->where('sorting > ?', $oldSorting)->update(['sorting-=' => 1]);
        }

        $this->getTable()->where('sorting >= ?', $newSorting)->update(['sorting+=' => 1]);
    }

    final public function userAmountSpentDistribution($levels, $productId)
    {
        return $this->amountSpentDistribution->distribution($productId, $levels);
    }

    final public function userAmountSpentDistributionList($fromLevel, $toLevel, $productId)
    {
        return $this->amountSpentDistribution->distributionList($productId, $fromLevel, $toLevel);
    }

    final public function userPaymentCountsDistribution($levels, $productId)
    {
        return $this->paymentCountDistribution->distribution($productId, $levels);
    }

    final public function userPaymentCountsDistributionList($fromLevel, $toLevel, $productId)
    {
        return $this->paymentCountDistribution->distributionList($productId, $fromLevel, $toLevel);
    }

    final public function productDaysFromLastOrderDistribution($levels, $productId)
    {
        return $this->productDaysFromLastOrderDistribution->distribution($productId, $levels);
    }

    final public function productDaysFromLastOrderDistributionList($fromlevel, $toLevel, $productId)
    {
        return $this->productDaysFromLastOrderDistribution->distributionList($productId, $fromlevel, $toLevel);
    }

    final public function productShopCountsDistribution($levels, $productId)
    {
        return $this->productShopCountsDistribution->distribution($productId, $levels);
    }

    final public function productShopCountsDistributionList($fromlevel, $toLevel, $productId)
    {
        return $this->productShopCountsDistribution->distributionList($productId, $fromlevel, $toLevel);
    }

    final public function decreaseStock(IRow &$product, $count = 1)
    {
        $this->update($product, ['stock-=' => $count]);
    }

    final public function exists($code)
    {
        return $this->getTable()->where('code', $code)->count('*') > 0;
    }


    final public function stats(DateTime $from = null, DateTime $to = null): Selection
    {
        $selection = $this->getTable()
            ->select('SUM(:payment_items.count) AS product_count, SUM(:payment_items.amount * :payment_items.count) AS product_amount, products.id AS product_id')
            ->where(':payment_items.type = ?', ProductPaymentItem::TYPE)
            ->where(':payment_items.payment.status = ?', PaymentsRepository::STATUS_PAID)
            ->group('products.id');

        if ($to === null) {
            $to = new DateTime();
        }

        if ($from !== null) {
            $selection->where(':payment_items.payment.paid_at BETWEEN ? AND ?', $from, $to);
        }

        return $selection;
    }
}
