<?php

namespace Izifir\Exchange;

use Bitrix\Currency\CurrencyManager;
use Bitrix\Main;
use Bitrix\Main\{Application, Entity\Base};
use Bitrix\Sale\{Basket, BasketItem, Order, ShipmentItem, Delivery, PaySystem, Shipment, Payment};
use Izifir\Core\Helpers\{Iblock, Phone};
use Izifir\Exchange\Models\OrderTable;
use CIBlockElement;
use CUser;
use ErrorException;
use Exception;
use SimpleXMLElement;

class OrderImport
{
    const PAGE_ORDERS_COUNT = 10; // Количество заказов обрабатываемых за один раз

    /**
     * Парсит полученный xml и сохраняет данные во временную таблицу
     *
     * @param SimpleXMLElement $xml
     * @throws Main\ArgumentException
     * @throws Main\ObjectException
     * @throws Exception
     */
    public static function parse(SimpleXMLElement $xml)
    {
        self::createTable();

        foreach ($xml as $order) {
            $fields = [
                'ORDER_ID' => (int)$order->Ид,
                'ORDER_ID_1C' => (int)$order->Ид_1С,
                'DATE_INSERT' => new Main\Type\DateTime((string)$order->ДатаСоздания, 'd.m.Y, H:i:s'),
                'STATUS_ID' => (string)$order->СтатусЗаказаID,
                'STATUS_NAME' => (string)$order->СтатусЗаказа,
                'DELIVERY_ID' => (string)$order->СпособДоставкиID,
                'DELIVERY_NAME' => (string)$order->СпособДоставки,
                'PAY_SYSTEM_ID' => (string)$order->СпособОплатыID,
                'PAY_SYSTEM_NAME' => (string)$order->СпособОплаты,
                'DELIVERY_PRICE' => (string)$order->СтоимостьДоставки,
                'IS_PAID' => ((string)$order->ЗаказОплачен === 'true' ? 'Y' : 'N'),
                'IS_CANCELED' => ((string)$order->Отменен === 'true' ? 'Y' : 'N'),
                'DATE_STATUS' => new Main\Type\DateTime((string)$order->ДатаИзмененияСтатуса, 'd.m.Y, H:i:s'),
                'NAME' => (string)$order->Имя,
                'EMAIL' => (string)$order->Email,
                'CITY' => (string)$order->Город,
                'PHONE' => (string)$order->НомерТелефона,
            ];

            $products = [];

            foreach ($order->Товары->Товар as $product) {
                $products[] = [
                    'ID' => (string)$product->Ид,
                    'NAME' => (string)$product->Наименование,
                    'QUANTITY' => (string)$product->Количество,
                    'PRICE' => (string)$product->Цена,
                    'CURRENCY' => (string)$product->Валюта,
                    'MEASURE' => (string)$product->ЕдиницаИзмерения,
                ];
            }

            $fields['PRODUCTS'] = Main\Web\Json::encode($products);

            OrderTable::add($fields);
        }
    }

    /**
     * Создает временную таблицу для обмена, если таблица уже существует, то очищает ее
     */
    public static function createTable()
    {
        $connection = Application::getConnection();
        $table = Base::getInstance(OrderTable::class);
        if (!$connection->isTableExists($table->getDBTableName())) {
            $table->createDbTable();
        } else {
            $connection->truncateTable($table->getDBTableName());
        }
    }

    /**
     * Удаляет временную таблицу
     *
     * @throws Main\DB\SqlQueryException
     */
    public static function dropTable()
    {
        $connection = Application::getConnection();
        $table = Base::getInstance(OrderTable::class);
        if ($connection->isTableExists($table->getDBTableName())) {
            $connection->dropTable($table->getDBTableName());
        }
    }

    /**
     * @param $status
     * @throws Main\ArgumentException
     * @throws Main\ArgumentNullException
     * @throws Main\ArgumentOutOfRangeException
     * @throws Main\DB\SqlQueryException
     * @throws Main\LoaderException
     * @throws Main\NotImplementedException
     * @throws Main\ObjectException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException|ErrorException
     */
    public static function process(&$status)
    {
        Main\Loader::includeModule('sale');

        $cnt = OrderTable::getCnt([], self::PAGE_ORDERS_COUNT, ($status['PAGE'] * self::PAGE_ORDERS_COUNT));

        // Проверяем, есть ли доступные записи для обработки
        // Если записей нет, то удаляем таблицу
        if ($cnt) {
            $xmlOrderIterator = OrderTable::getList([
                'filter' => [],
                'limit' => self::PAGE_ORDERS_COUNT,
                'offset' => ($status['PAGE'] * self::PAGE_ORDERS_COUNT)
            ]);

            while ($xmlOrder = $xmlOrderIterator->fetch()) {
                $orderId = self::getOrderId($xmlOrder);
                if ($orderId) {
                    $order = Order::load($orderId);
                    self::updateOrder($order, $xmlOrder);
                } else {
                    self::createOrder($xmlOrder);
                }
            }
            ++$status['PAGE'];
        } else {
            $status['PAGE'] = 0;
            self::dropTable();
        }
    }

    /**
     * Обновляет данные заказа
     *
     * @param Order $order
     * @param array $xmlData
     * @throws Main\ArgumentException
     * @throws Main\ArgumentNullException
     * @throws Main\ArgumentOutOfRangeException
     * @throws Main\ArgumentTypeException
     * @throws Main\LoaderException
     * @throws Main\NotImplementedException
     * @throws Main\NotSupportedException
     * @throws Main\ObjectNotFoundException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     * @throws ErrorException
     */
    protected static function updateOrder(Order $order, array $xmlData)
    {
        $products = Main\Web\Json::decode($xmlData['PRODUCTS']);
        $basket = $order->getBasket();

        $order->setFields([
            'STATUS_ID' => $xmlData['STATUS_ID'],
            'CANCELED' => $xmlData['CANCELED'] == 'Y',
            'DATE_STATUS' => $xmlData['DATE_STATUS'],
            'DATE_UPDATE' => new Main\Type\DateTime(),
            'UPDATED_1C' => 'Y',
            'ID_1C' => $xmlData['ORDER_ID_1C']
        ]);

        // Обновим информацию о доставке
        $shipmentCollection = $order->getShipmentCollection();
        /** @var Shipment $shipment */
        foreach ($shipmentCollection as $shipment) {
            if (!$shipment->isSystem()) {
                $service = Delivery\Services\Manager::getObjectById($xmlData['DELIVERY_ID']);
                $shipment->setDeliveryService($service);
                $shipment->setBasePriceDelivery($xmlData['DELIVERY_PRICE'], true);
                $shipment->setFields([
                    'PRICE_DELIVERY' => $xmlData['DELIVERY_PRICE'],
                    'CUSTOM_PRICE_DELIVERY' => 'Y'
                ]);

                // Обновим добавленные в отгрузку товары
                $shipmentItemCollection = $shipment->getShipmentItemCollection();
                /** @var ShipmentItem $shipmentItem */
                foreach ($shipmentItemCollection as $shipmentItem) {
                    $shipmentItemFields = $shipmentItem->getFieldValues();
                    $basketItem = $basket->getItemById($shipmentItemFields['BASKET_ID']);
                    $productXmlId = $basketItem->getField('PRODUCT_XML_ID');
                    // Получим товар из 1С по XML_ID
                    $product = current(array_filter($products, static function($a) use ($productXmlId) {
                        return $a['ID'] == $productXmlId;
                    }));
                    // Если нашли товар - обновим количество
                    // Иначе удаляем товар из отгрузки
                    if ($product) {
                        $shipmentItem->setQuantity($product['QUANTITY']);
                    } else {
                        $shipmentItem->delete();
                    }
                }
            }
        }

        // Обновим информацию об оплате
        $paymentCollection = $order->getPaymentCollection();
        /** @var Payment $payment */
        foreach ($paymentCollection as $payment) {
            $paySystem = PaySystem\Manager::getObjectById($xmlData['PAY_SYSTEM_ID']);
            $payment->setPaySystemService($paySystem);
            $payment->setPaid($xmlData['IS_PAID']);
        }

        // Обновим свойства заказа
        $propertyCollection = $order->getPropertyCollection();
        foreach ($propertyCollection as $propertyValue) {
            $propertyFields = $propertyValue->getFieldValues();
            switch ($propertyFields['CODE']) {
                case 'NAME':
                    $propertyValue->setField('VALUE', $xmlData['NAME']);
                    break;
                case 'EMAIL':
                    $propertyValue->setField('VALUE', $xmlData['EMAIL']);
                    break;
                case 'CITY':
                    $propertyValue->setField('VALUE', $xmlData['CITY']);
                    break;
                case 'PHONE':
                    $propertyValue->setField('VALUE', $xmlData['PHONE']);
                    break;
            }
        }

        // Обновим товары в корзине
        $productsId = array_column($products, 'ID');

        /** @var BasketItem $item */
        foreach ($basket as $item) {
            $itemFields = $item->getFieldValues();
            // Если товара нет в заказе, удалим его
            if (!in_array($itemFields['PRODUCT_XML_ID'], $productsId)) {
                $item->delete();
            }
            foreach ($products as $k => $product) {
                // Обновим информацию о товаре в корзине
                // удалим из массива обработанный элемент
                if ($product['ID'] == $itemFields['PRODUCT_XML_ID']) {
                    $item->setFields([
                        'QUANTITY' => $product['QUANTITY'],
                        'PRICE' => $product['PRICE'],
                        'CUSTOM_PRICE' => 'Y'
                    ]);
                    unset($products[$k]);
                }
            }
        }

        // Все что осталось в массиве - не было в корзине, нужно добавить
        foreach ($products as $product) {
            $element = self::getProductByXmlId($product['ID']);
            if ($element) {
                // Добавим в корзину
                $item = $basket->createItem('catalog', $element['ID']);
                $item->setFields([
                    'QUANTITY' => $product['QUANTITY'],
                    'CURRENCY' => CurrencyManager::getBaseCurrency(),
                    'LID' => Main\Context::getCurrent()->getSite(),
                    'PRODUCT_PROVIDER_CLASS' => 'CCatalogProductProvider',
                    'PRICE' => $product['PRICE'],
                    'CUSTOM_PRICE' => 'Y'
                ]);
                $properties = $item->getPropertyCollection();
                if ($element['PROPERTY_COLLECTION_VALUE']) {
                    $articleProp = $properties->createItem();
                    $articleProp->setFields([
                        'NAME' => 'Артикул',
                        'CODE' => 'ARTICLE',
                        'SORT' => '100',
                        'VALUE' => $element['PROPERTY_COLLECTION_VALUE']
                    ]);
                }

                // Добавим в отгрузку
                foreach ($shipmentCollection as $shipment) {
                    $shipmentItemCollection = $shipment->getShipmentItemCollection();
                    $shipmentItem = $shipmentItemCollection->createItem($item);
                    $shipmentItem->setQuantity($item->getQuantity());
                }
            }
        }

        $order->save();
    }

    /**
     * Создает новый заказ
     *
     * @param array $xmlData
     * @throws Main\ArgumentException
     * @throws Main\ArgumentNullException
     * @throws Main\ArgumentOutOfRangeException
     * @throws Main\ArgumentTypeException
     * @throws Main\LoaderException
     * @throws Main\NotImplementedException
     * @throws Main\NotSupportedException
     * @throws Main\ObjectException
     * @throws Main\ObjectNotFoundException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function createOrder(array $xmlData)
    {
        $user = self::getUserByPhone($xmlData['PHONE']);
        if ($user) {
            $order = Order::create(Main\Context::getCurrent()->getSite(), $user['ID'], CurrencyManager::getBaseCurrency());
            $order->setPersonTypeId(1);

            $order->setFields([
                'STATUS_ID' => $xmlData['STATUS_ID'],
                'CANCELED' => $xmlData['CANCELED'] == 'Y',
                'DATE_STATUS' => $xmlData['DATE_STATUS'],
                'DATE_UPDATE' => new Main\Type\DateTime(),
                'UPDATED_1C' => 'Y',
                'ID_1C' => $xmlData['ORDER_ID_1C']
            ]);

            $products = Main\Web\Json::decode($xmlData['PRODUCTS']);
            $basket = Basket::create(Main\Context::getCurrent()->getSite());
            foreach ($products as $product) {
                $element = self::getProductByXmlId($product['ID']);
                if ($element) {
                    $item = $basket->createItem('catalog', $element['ID']);
                    $item->setFields([
                        'QUANTITY' => $product['QUANTITY'],
                        'CURRENCY' => CurrencyManager::getBaseCurrency(),
                        'LID' => Main\Context::getCurrent()->getSite(),
                        'PRODUCT_PROVIDER_CLASS' => 'CCatalogProductProvider',
                        'PRICE' => $product['PRICE'],
                        'CUSTOM_PRICE' => 'Y'
                    ]);
                    $properties = $item->getPropertyCollection();
                    if ($element['PROPERTY_COLLECTION_VALUE']) {
                        $articleProp = $properties->createItem();
                        $articleProp->setFields([
                            'NAME' => 'Артикул',
                            'CODE' => 'ARTICLE',
                            'SORT' => '100',
                            'VALUE' => $element['PROPERTY_COLLECTION_VALUE']
                        ]);
                    }
                }
            }
            $order->setBasket($basket);

            $propertyCollection = $order->getPropertyCollection();
            foreach ($propertyCollection as $propertyValue) {
                $propertyFields = $propertyValue->getFieldValues();
                switch ($propertyFields['CODE']) {
                    case 'NAME':
                        $propertyValue->setField('VALUE', $xmlData['NAME']);
                        break;
                    case 'EMAIL':
                        $propertyValue->setField('VALUE', $xmlData['EMAIL']);
                        break;
                    case 'CITY':
                        $propertyValue->setField('VALUE', $xmlData['CITY']);
                        break;
                    case 'PHONE':
                        $propertyValue->setField('VALUE', $xmlData['PHONE']);
                        break;
                }
            }

            $shipmentCollection = $order->getShipmentCollection();
            $service = Delivery\Services\Manager::getObjectById($xmlData['DELIVERY_ID']);
            $shipment = $shipmentCollection->createItem($service);

            $shipmentItemCollection = $shipment->getShipmentItemCollection();
            foreach ($basket as $basketItem) {
                $item = $shipmentItemCollection->createItem($basketItem);
                $item->setQuantity($basketItem->getQuantity());
            }

            $shipment->setBasePriceDelivery($xmlData['DELIVERY_PRICE'], true);
            $shipment->setFields([
                'PRICE_DELIVERY' => $xmlData['DELIVERY_PRICE'],
                'CUSTOM_PRICE_DELIVERY' => 'Y'
            ]);

            $paymentCollection = $order->getPaymentCollection();
            $paySystem = PaySystem\Manager::getObjectById($xmlData['PAY_SYSTEM_ID']);
            $payment = $paymentCollection->createItem($paySystem);
            $payment->setPaid($xmlData['IS_PAID']);
            $payment->setField("SUM", $order->getPrice());
            $payment->setField("CURRENCY", $order->getCurrency());

            $propertyCollection = $order->getPropertyCollection();
            foreach ($propertyCollection as $propertyValue) {
                $propertyFields = $propertyValue->getFieldValues();
                switch ($propertyFields['CODE']) {
                    case 'NAME':
                        $propertyValue->setField('VALUE', $xmlData['NAME']);
                        break;
                    case 'EMAIL':
                        $propertyValue->setField('VALUE', $xmlData['EMAIL']);
                        break;
                    case 'CITY':
                        $propertyValue->setField('VALUE', $xmlData['CITY']);
                        break;
                    case 'PHONE':
                        $propertyValue->setField('VALUE', $xmlData['PHONE']);
                        break;
                }
            }

            $order->save();
        }
    }

    /**
     * Находит товар по внешнему коду
     *
     * @param $xmlId
     * @return array|false|void
     * @throws Main\LoaderException
     */
    protected static function getProductByXmlId($xmlId)
    {
        Main\Loader::includeModule('iblock');

        return CIBlockElement::GetList(
            [],
            ['XML_ID' => $xmlId, 'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_catalog')],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_ARTICLE']
        )->Fetch();
    }

    /**
     * Находит пользователя по номеру телефона
     *
     * @param $phone
     * @return array|false|mixed|void
     * @throws Main\ArgumentException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function getUserByPhone($phone)
    {
        // Проверим корректность номера телефона
        $parser = Phone::getParser($phone);
        $user = null;
        if ($parser->isValid()) {
            $phoneNumber = $parser->getCountryCode() . $parser->getNationalNumber();
            // Пробуем найти пользователя по номеру
            // Если не находим, то создадим нового
            $user = Main\UserTable::getList([
                'filter' => [
                    [
                        'LOGIC' => 'OR',
                        ['LOGIN' => $phoneNumber],
                        ['PERSONAL_PHONE' => $phoneNumber]
                    ]
                ],
                'select' => ['ID']
            ])->fetch();

            if (!$user) {
                $password = Main\Security\Random::getString(8);
                $user['ID'] = (new CUser())->Add([
                    'LOGIN' => $phoneNumber,
                    'PASSWORD' => $password,
                    'CONFIRM_PASSWORD' => $password,
                    'ACTIVE' => 'Y',
                    'PERSONAL_PHONE' => $phoneNumber
                ]);
            }
        }
        return $user;
    }

    /**
     * Получает идентификатор заказа в системе по переданным из 1С идентификаторам
     *
     * @param array $xmlData
     * @return mixed
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     */
    protected static function getOrderId(array $xmlData)
    {
        Main\Loader::includeModule('sale');

        $order = Order::getList([
            'filter' => [
                [
                    'LOGIC' => 'OR',
                    ['ID' => $xmlData['ORDER_ID']],
                    ['ID_1C' => $xmlData['ORDER_ID_1C']]
                ]
            ],
            'select' => ['ID']
        ])->fetch();

        return $order['ID'];
    }
}
