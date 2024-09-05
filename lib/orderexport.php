<?php

namespace Izifir\Exchange;

use Bitrix\Main;
use Bitrix\Main\{
    Context,
    Loader,
    Diag\Debug,
    Type\DateTime
};
use Bitrix\Sale\{BasketItem, Delivery, Internals\StatusTable, Order, PaySystem, PropertyValue};
use DOMDocument;
use Exception;

class OrderExport
{
    protected static array $_statuses = [];
    protected static array $_deliveries = [];
    protected static array $_paySystems = [];

    /**
     * Формирует xml с заказа и отдает его в поток
     *
     * @throws Main\ArgumentException
     * @throws Main\ArgumentNullException
     * @throws Main\ArgumentOutOfRangeException
     * @throws Main\LoaderException
     * @throws Main\NotImplementedException
     * @throws Main\ObjectNotFoundException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    public static function run()
    {
        Loader::includeModule('sale');

        $orderIterator = self::getOrders();

        $dom = new DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElement('Заказы');

        foreach ($orderIterator as $orderId) {
            $order = Order::load($orderId);
            if ($order) {
                $orderRoot = $dom->createElement('Заказ');
                $orderRoot->appendChild($dom->createElement('Ид', $order->getId()));
                $orderRoot->appendChild($dom->createElement('Ид_1С', $order->getField('ID_1C')));
                $orderRoot->appendChild($dom->createElement('ДатаСоздания', $order->getDateInsert()->format('d.m.Y, H:i:s')));
                $orderRoot->appendChild($dom->createElement('СтатусЗаказаID', $order->getField('STATUS_ID')));
                $orderRoot->appendChild($dom->createElement('СтатусЗаказа', self::getStatusName($order->getField('STATUS_ID'))));
                $orderRoot->appendChild($dom->createElement('СпособДоставкиID', implode(',', $order->getDeliveryIdList())));
                $orderRoot->appendChild($dom->createElement('СпособДоставки', self::getDeliveryName($order->getDeliveryIdList())));
                $orderRoot->appendChild($dom->createElement('СпособОплатыID', implode(',', $order->getPaySystemIdList())));
                $orderRoot->appendChild($dom->createElement('СпособОплаты', self::getPaySystemName($order->getPaySystemIdList())));
                $orderRoot->appendChild($dom->createElement('СтоимостьДоставки', $order->getDeliveryPrice()));
                $orderRoot->appendChild($dom->createElement('ЗаказОплачен', ($order->isPaid() ? 'true' : 'false')));
                $orderRoot->appendChild($dom->createElement('Отменен', ($order->isCanceled() ? 'true' : 'false')));
                $orderRoot->appendChild($dom->createElement('ДатаИзмененияСтатуса', $order->getField('DATE_STATUS')->format('d.m.Y, H:i:s')));

                $propertyCollection = $order->getPropertyCollection();
                /** @var PropertyValue $propertyValue */
                foreach ($propertyCollection as $propertyValue) {
                    $orderRoot->appendChild($dom->createElement(self::preparePropertyName($propertyValue->getName()), $propertyValue->getValue()));
                }

                $orderGoods = $dom->createElement('Товары');
                $basket = $order->getBasket();

                /** @var BasketItem $basketItem */
                foreach ($basket as $basketItem) {
                    $good = $dom->createElement('Товар');
                    $good->appendChild($dom->createElement('Ид', $basketItem->getField('PRODUCT_XML_ID')));
                    $good->appendChild($dom->createElement('Наименование', $basketItem->getField('NAME')));
                    $good->appendChild($dom->createElement('Количество', $basketItem->getQuantity()));
                    $good->appendChild($dom->createElement('Цена', $basketItem->getPrice()));
                    $good->appendChild($dom->createElement('Валюта', $basketItem->getCurrency()));
                    $good->appendChild($dom->createElement('ЕдиницаИзмерения', strip_tags($basketItem->getField('MEASURE_NAME'))));
                    $orderGoods->appendChild($good);
                }

                $orderRoot->appendChild($orderGoods);
                $root->appendChild($orderRoot);
            }
        }

        $dom->appendChild($root);

        header('Content-type: text/xml');
        echo $dom->saveXML();
    }

    /**
     * Возвращает список ID заказов, подходящих под фильтр
     *
     * @return array
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Exception
     */
    protected static function getOrders(): array
    {
        Loader::includeModule('sale');

        $request = Context::getCurrent()->getRequest();
        $from = $request->getQuery('from');
        $to = $request->getQuery('to');

        $filter = [];
        if ($from) {
            $dateFrom = DateTime::createFromPhp(new \DateTime($from . ' 00:00:00'));
            $filter['>=DATE_INSERT'] = $dateFrom;
        }

        if ($to) {
            $dateTo = DateTime::createFromPhp(new \DateTime($to . ' 23:59:59'));
            $filter['<=DATE_INSERT'] = $dateTo;
        }

        $orderList = Order::getList([
            'filter' => $filter,
            'select' => ['ID'],
            'order' => ['DATE_INSERT' => 'DESC']
        ])->fetchAll();

        return array_column($orderList, 'ID');
    }

    /**
     * Возвращает название статуса заказа/доставки по ID
     *
     * @param $statusId
     * @return mixed
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     */
    protected static function getStatusName($statusId)
    {
        Loader::includeModule('sale');

        if (empty(self::$_statuses)) {
            $statusIterator = StatusTable::getList([
                'filter' => ['STATUS_LANG.LID' => 'ru'],
                'select' => ['ID', 'NAME' => 'STATUS_LANG.NAME']
            ]);
            while ($status = $statusIterator->fetch()) {
                self::$_statuses[$status['ID']] = $status['NAME'];
            }
        }

        return self::$_statuses[$statusId];
    }

    /**
     * Возвращает название служб доставки по ID
     *
     * @param array $deliveryId
     * @return string
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     */
    protected static function getDeliveryName(array $deliveryId): string
    {
        Loader::includeModule('sale');
        $result = [];
        if (empty(self::$_deliveries)) {
            $deliveryIterator = Delivery\Services\Manager::getList();
            while ($delivery = $deliveryIterator->fetch()) {
                self::$_deliveries[$delivery['ID']] = $delivery['NAME'];
            }
        }

        foreach ($deliveryId as $id) {
            $result[] = self::$_deliveries[$id];
        }

        return implode(',', $result);
    }

    /**
     * Возвращает названия платежных систем по ID
     *
     * @param array $paySystemId
     * @return string
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     */
    protected static function getPaySystemName(array $paySystemId): string
    {
        Loader::includeModule('sale');
        $result = array();
        if (empty(self::$_paySystems)) {
            $paySystemIterator = PaySystem\Manager::getList([]);
            while ($paySystem = $paySystemIterator->fetch()) {
                self::$_paySystems[$paySystem['ID']] = $paySystem['NAME'];
            }
        }

        foreach ($paySystemId as $id) {
            $result[] = self::$_paySystems[$id];
        }

        return implode(', ', $result);
    }

    /**
     * Подготавливает название свойства для использования в качестве имени узла в XML
     *
     * @param string $name
     * @return array|string|string[]|null
     */
    protected static function preparePropertyName(string $name)
    {
        $name = str_replace('-', '', $name);
        $name = mb_convert_case($name, MB_CASE_TITLE);
        return preg_replace('/\s/', '', $name);
    }
}
