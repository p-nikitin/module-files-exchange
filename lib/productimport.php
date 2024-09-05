<?php

namespace Izifir\Exchange;

use Bitrix\Iblock\{ElementTable, PropertyEnumerationTable, PropertyIndex\Manager, PropertyTable, SectionTable, PropertyIndex};
use Bitrix\Main;
use Bitrix\Main\{Diag\Debug, Loader, Web\Json};
use Bitrix\Catalog\{MeasureRatioTable, MeasureTable, Model\Price, Model\Product};
use CIBlockElement;
use CIBlockProperty;
use CIBlockSectionPropertyLink;
use CUtil;
use SimpleXMLElement;
use Izifir\{Core\Helpers\Iblock, Exchange\Models\ProductTable};

class ProductImport
{
    const PAGE_ELEMENTS_COUNT = 50;

    public static function parse(SimpleXMLElement $xml)
    {
        if ($xml->Товар->count()) {
            ProductTable::createTable();
            echo "Временная таблица товаров создана<br>";
            self::checkRequiredProperties();
            echo "Обязательные свойства товаров созданы<br>";
            foreach ($xml->Товар as $xmlProduct) {
                $productFields = [
                    'XML_ID' => (string)$xmlProduct->Код,
                    'NAME' => (string)$xmlProduct->Название,
                    'ARTICLE' => (string)$xmlProduct->Артикул,
                    'PRICE' => preg_replace('/\s/', '', (string)$xmlProduct->Цена),
                    'SECTION' => (string)$xmlProduct->Раздел,
                    'DESCRIPTION' => (string)$xmlProduct->Описание,
                ];

                $stores = [];
                if ($xmlProduct->Склады->Склад->count()) {
                    foreach ($xmlProduct->Склады->Склад as $xmlStore) {
                        $stores[] = [
                            'ID' => (string)$xmlStore->СкладID,
                            'QUANTITY' => (string)$xmlStore->Количество
                        ];
                    }
                    $productFields['STORES'] = Json::encode($stores);
                }

                $properties = [];
                if ($xmlProduct->Свойства->Свойство->count()) {
                    foreach ($xmlProduct->Свойства->Свойство as $xmlProperty) {
                        $properties[] = [
                            'ID' => (string)$xmlProperty->Код,
                            'NAME' => (string)$xmlProperty->Название,
                            'VALUE' => (string)$xmlProperty->Значение,
                        ];
                    }
                    $productFields['PROPERTIES'] = Json::encode($properties);
                }

                ProductTable::add($productFields);
            }
            echo "Товары в файле импорта обработаны<br>";
        }
    }

    public static function process(&$status)
    {
        $cnt = ProductTable::getCount();

        $xmlProductIterator = ProductTable::getList([
            'limit' => self::PAGE_ELEMENTS_COUNT,
            'offset' => ($status['PAGE'] * self::PAGE_ELEMENTS_COUNT)
        ])->fetchAll();
        if (count($xmlProductIterator) > 0) {
            foreach ($xmlProductIterator as $xmlProduct) {
                $productId = self::getProductByXmlId($xmlProduct['XML_ID']);
                if ($productId) {
                    $xmlProduct['PRODUCT_ID'] = $productId;
                    self::updateProduct($xmlProduct);
                } else {
                    self::addProduct($xmlProduct);
                }
            }
            $processedCount = ($status['PAGE'] + 1) * self::PAGE_ELEMENTS_COUNT;
            if ($processedCount > $cnt)
                $processedCount = $cnt;
            echo "Обработано {$processedCount} из {$cnt} товаров<br>";
            ++$status['PAGE'];
        } else {
            $status['PAGE'] = 0;
            ProductTable::dropTable();
            self::updateIblockIndexes();
            echo "Обработка товаров завершена<br>";
        }
    }

    /**
     * Возвращает список обязательных свойств, которые не будут создаваться автоматически
     *
     * @return array{array}
     */
    protected static function getRequiredProperties(): array
    {
        return [
            'ARTICLE' => [
                'NAME' => 'Артикул',
                'CODE' => 'ARTICLE',
                'TYPE' => PropertyTable::TYPE_STRING,
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SMART_FILTER' => 'N',
                'USER_TYPE' => '',
                'LINK_IBLOCK_ID' => '',
                'USER_TYPE_SETTINGS' => '{}'
            ],
            'BRAND' => [
                'NAME' => 'Бренд',
                'CODE' => 'BRAND',
                'TYPE' => PropertyTable::TYPE_ELEMENT,
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SMART_FILTER' => 'N',
                'LINK_IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_brands'),
                'USER_TYPE' => 'EAutocomplete',
                'USER_TYPE_SETTINGS' => Json::encode([
                    'VIEW' => 'E',
                    'SHOW_ADD' => 'Y',
                    'IBLOCK_MESS' => 'Y'
                ])
            ],
            'COLLECTION' => [
                'NAME' => 'Коллекция',
                'CODE' => 'COLLECTION',
                'TYPE' => PropertyTable::TYPE_ELEMENT,
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SMART_FILTER' => 'N',
                'LINK_IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_collections'),
                'USER_TYPE' => 'EAutocomplete',
                'USER_TYPE_SETTINGS' => Json::encode([
                    'VIEW' => 'E',
                    'SHOW_ADD' => 'Y',
                    'IBLOCK_MESS' => 'Y'
                ])
            ],
            'ESHOP_ARTICLE' => [
                'NAME' => 'Артикул для ИМ',
                'CODE' => 'ESHOP_ARTICLE',
                'TYPE' => PropertyTable::TYPE_STRING,
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SMART_FILTER' => 'N',
                'USER_TYPE' => '',
                'LINK_IBLOCK_ID' => '',
                'USER_TYPE_SETTINGS' => '{}'
            ],
            'OLD_PRICE' => [
                'NAME' => 'Зачеркнутая цена',
                'CODE' => 'OLD_PRICE',
                'TYPE' => PropertyTable::TYPE_NUMBER,
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SMART_FILTER' => 'N',
                'USER_TYPE' => '',
                'LINK_IBLOCK_ID' => '',
                'USER_TYPE_SETTINGS' => '{}'
            ],
            'WIDTH' => [
                'NAME' => 'Ширина',
                'CODE' => 'WIDTH',
                'TYPE' => PropertyTable::TYPE_NUMBER,
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SMART_FILTER' => 'N',
                'USER_TYPE' => '',
                'LINK_IBLOCK_ID' => '',
                'USER_TYPE_SETTINGS' => '{}'
            ],
            'LENGTH' => [
                'NAME' => 'Длина',
                'CODE' => 'LENGTH',
                'TYPE' => PropertyTable::TYPE_NUMBER,
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SMART_FILTER' => 'N',
                'USER_TYPE' => '',
                'LINK_IBLOCK_ID' => '',
                'USER_TYPE_SETTINGS' => '{}'
            ],
        ];
    }

    /**
     * Проверяет наличие обязательных свойств и при необходимости создает их
     */
    protected static function checkRequiredProperties()
    {
        Loader::includeModule('iblock');

        $requiredProperties = self::getRequiredProperties();
        $currentProperties = [];

        $currentPropertyIterator = PropertyTable::getList([
            'filter' => [
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_catalog'),
                'CODE' => array_keys($requiredProperties)
            ],
            'select' => ['CODE']
        ]);
        while ($currentProperty = $currentPropertyIterator->fetch()) {
            $currentProperties[$currentProperty['CODE']] = $currentProperty;
        }

        $propertyDiff = array_diff(array_keys($requiredProperties), array_keys($currentProperties));

        if (!empty($propertyDiff)) {
            $obProperty = new CIBlockProperty();
            foreach ($propertyDiff as $propertyCode) {
                $prop = $requiredProperties[$propertyCode];
                $propId = $obProperty->Add([
                    'CODE' => $prop['CODE'],
                    'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_catalog'),
                    'NAME' => $prop['NAME'],
                    'ACTIVE' => 'Y',
                    'IS_REQUIRED' => $prop['IS_REQUIRED'],
                    'PROPERTY_TYPE' => $prop['TYPE'],
                    'MULTIPLE' => $prop['MULTIPLE'],
                    'FILTRABLE' => 'Y',
                    'LINK_IBLOCK_ID' => $prop['LINK_IBLOCK_ID'],
                    'USER_TYPE' => $prop['USER_TYPE'],
                    'USER_TYPE_SETTINGS' => $prop['USER_TYPE_SETTINGS']
                ]);
                if ($prop['SMART_FILTER'] == 'Y') {
                    CIBlockSectionPropertyLink::Add(null, $propId, ['SMART_FILTER' => 'Y']);
                }
            }
        }
    }

    /**
     * Возвращает ID товара по внешнему коду
     *
     * @param string $xmlId
     * @return mixed
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function getProductByXmlId(string $xmlId)
    {
        Loader::includeModule('iblock');

        $product = ElementTable::getList([
            'filter' => [
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_catalog'),
                'XML_ID' => $xmlId
            ],
            'select' => ['ID']
        ])->fetch();

        return $product['ID'];
    }

    /**
     * Создает новый товар
     *
     * @param array $xmlProduct
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectNotFoundException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function addProduct(array $xmlProduct)
    {
        Loader::includeModule('catalog');

        $productFields = [
            'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_catalog'),
            'CODE' => self::getProductCode($xmlProduct['NAME']),
            'NAME' => $xmlProduct['NAME'],
            'ACTIVE' => self::getProductActive($xmlProduct),
            'XML_ID' => $xmlProduct['XML_ID']
        ];
        if (!empty($xmlProduct['SECTION'])) {
            $sectionId = self::getSectionByXmlId($xmlProduct['SECTION']);
            if ($sectionId) {
                $productFields['IBLOCK_SECTION_ID'] = $sectionId;
            }
        }

        $xmlProperties = Json::decode($xmlProduct['PROPERTIES']);
        $properties = self::prepareProductProperties($xmlProperties);
        $properties['PROPERTY_VALUES']['ARTICLE'] = $xmlProduct['ARTICLE'];

        $productFields['PROPERTY_VALUES'] = $properties['PROPERTY_VALUES'];

        $obElement = new CIBlockElement();
        $productId = $obElement->Add($productFields);
        if ($productId > 0) {
            echo "Элемент [{$productId}] {$productFields['NAME']} успешно создан<br>";
            Manager::updateElementIndex(
                Iblock::getIblockIdByCode('eshop_catalog'),
                $productId
            );

            $productResult = Product::add([
                'ID' => $productId,
                'CAN_BUY_ZERO' => 'Y',
                'MEASURE' => self::getMeasureByName($properties['MEASURE_NAME']),
                'QUANTITY' => self::getAllQuantity($xmlProduct)
            ]);

            if (!$productResult->isSuccess()) {
                $productErrors = implode(', ', $productResult->getErrorMessages());
                echo "Ошибка создания товара [{$productId}] {$productFields['NAME']} - {$productErrors}<br>";
            }

            $measureRatioResult = self::setMeasureRatio($productId, $properties['MEASURE_RATIO']);
            if (!$measureRatioResult->isSuccess()) {
                $measureErrors = implode(', ', $measureRatioResult->getErrorMessages());
                echo "Ошибка создания коэф. ед. измерения товара [{$productId}] {$productFields['NAME']} - {$measureErrors}<br>";
            }

            $priceResult = self::setPrice($productId, $xmlProduct['PRICE']);

            if (!$priceResult->isSuccess()) {
                $priceErrors = implode(', ', $priceResult->getErrorMessages());
                echo "Ошибка создания цены для товара [{$productId}] {$productFields['NAME']} - {$priceErrors}<br>";
            }
        } else {
            echo "Ошибка создания товара [{$productFields['XML_ID']}] {$productFields['NAME']} - {$obElement->LAST_ERROR}<br>";
        }
    }

    /**
     * Обновляет существующий товар
     *
     * @param array $xmlProduct
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectNotFoundException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function updateProduct(array $xmlProduct)
    {
        $productFields = [
            'NAME' => $xmlProduct['NAME'],
            'ACTIVE' => self::getProductActive($xmlProduct),
        ];
        if (!empty($xmlProduct['SECTION'])) {
            $sectionId = self::getSectionByXmlId($xmlProduct['SECTION']);
            if ($sectionId) {
                $productFields['IBLOCK_SECTION_ID'] = $sectionId;
            }
        }

        $xmlProperties = Json::decode($xmlProduct['PROPERTIES']);
        $properties = self::prepareProductProperties($xmlProperties);
        $properties['PROPERTY_VALUES']['ARTICLE'] = $xmlProduct['ARTICLE'];

        $productFields['PROPERTY_VALUES'] = $properties['PROPERTY_VALUES'];

        $obElement = new CIBlockElement();
        $updateResult = $obElement->Update($xmlProduct['PRODUCT_ID'], $productFields);

        if ($updateResult)
            echo "Элемент [{$xmlProduct['PRODUCT_ID']}] {$productFields['NAME']} успешно обновлен<br>";
        else
            echo "Ошибка обновления элемента [{$xmlProduct['PRODUCT_ID']}] {$productFields['NAME']} - {$obElement->LAST_ERROR}<br>";

        $productResult = Product::update($xmlProduct['PRODUCT_ID'], [
            'MEASURE' => self::getMeasureByName($properties['MEASURE_NAME']),
            'QUANTITY' => self::getAllQuantity($xmlProduct)
        ]);
        if (!$productResult->isSuccess()) {
            $productErrors = implode(', ', $productResult->getErrorMessages());
            echo "Ошибка обновления товара [{$xmlProduct['PRODUCT_ID']}] {$productFields['NAME']} - {$productErrors}<br>";
        }

        $measureRatioResult = self::setMeasureRatio($xmlProduct['PRODUCT_ID'], $properties['MEASURE_RATIO']);
        if (!$measureRatioResult->isSuccess()) {
            $measureErrors = implode(', ', $measureRatioResult->getErrorMessages());
            echo "Ошибка обновления коэф. ед. измерения товара [{$xmlProduct['PRODUCT_ID']}] {$productFields['NAME']} - {$measureErrors}<br>";
        }

        $priceResult = self::setPrice($xmlProduct['PRODUCT_ID'], $xmlProduct['PRICE']);
        if (!$priceResult->isSuccess()) {
            $priceErrors = implode(', ', $priceResult->getErrorMessages());
            echo "Ошибка создания цены для товара [{$xmlProduct['PRODUCT_ID']}] {$productFields['NAME']} - {$priceErrors}<br>";
        }

        Manager::updateElementIndex(
            Iblock::getIblockIdByCode('eshop_catalog'),
            $xmlProduct['PRODUCT_ID']
        );
    }

    /**
     * Подготавливает и возвращает корректный символьный код товара
     *
     * @param string $name
     * @return string
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function getProductCode(string $name): string
    {
        Loader::includeModule('iblock');
        $name = CUtil::translit($name, 'ru', [
            'replace_space' => '-',
            'replace_other' => '-'
        ]);
        $cnt = ElementTable::getCount([
            'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_catalog'),
            'CODE' => $name . '%'
        ]);
        if ($cnt > 0)
            $name .= '-' . $cnt;

        return $name;
    }

    /**
     * @param array $xmlProduct
     * @return string
     * @throws Main\ArgumentException
     */
    protected static function getProductActive(array $xmlProduct): string
    {
        $active = 'Y';
        $xmlProperties = Json::decode($xmlProduct['PROPERTIES']);
        foreach ($xmlProperties as $xmlProperty) {
            if ($xmlProperty['ID'] == 'Не показывать на сайте' && $xmlProperty['VALUE'] == 'Да')
                $active = 'N';
        }
        return $active;
    }

    /**
     * Возвращает ID раздела по внешнему коду
     *
     * @param string $xmlId
     * @return mixed
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function getSectionByXmlId(string $xmlId)
    {
        Loader::includeModule('iblock');

        $section = SectionTable::getList([
            'filter' => [
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_catalog'),
                'XML_ID' => $xmlId
            ],
            'select' => ['ID'],
        ])->fetch();

        return $section['ID'];
    }

    /**
     * Подготавливает свойства товара для сохранения
     *
     * @param array $xmlProperties
     * @return array[]
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function prepareProductProperties(array $xmlProperties): array
    {
        $result = ['PROPERTY_VALUES' => []];
        foreach ($xmlProperties as $xmlProperty) {
            switch ($xmlProperty['ID']) {
                case 'ЕдиницаИзмерения': // Единица измерения
                    $result['MEASURE_NAME'] = $xmlProperty['VALUE'];
                    break;
                case 'ОбъемУпаковки': // Коэффициент ед. измерения
                    if (!empty($xmlProperty['VALUE']))
                        $result['MEASURE_RATIO'] = $xmlProperty['VALUE'];
                    else
                        $result['MEASURE_RATIO'] = 1;
                    break;
                case 'Бренд': // Бренд
                    if (!empty($xmlProperty['VALUE'])) {
                        $result['PROPERTY_VALUES']['BRAND'] = self::getBrandByName($xmlProperty['VALUE']);
                    }
                    break;
                case 'Коллекции': // Коллекция
                    if (!empty($xmlProperty['VALUE'])) {
                        $result['PROPERTY_VALUES']['COLLECTION'] = self::getCollectionByName($xmlProperty['VALUE']);
                    }
                    break;
                case 'Артикул для интернет-магазина': // Артикул для ИМ
                    $result['PROPERTY_VALUES']['ESHOP_ARTICLE'] = $xmlProperty['VALUE'];
                    break;
                case 'Ширина':
                    $result['PROPERTY_VALUES']['WIDTH'] = $xmlProperty['VALUE'];
                    break;
                case 'Длина':
                    $result['PROPERTY_VALUES']['LENGTH'] = $xmlProperty['VALUE'];
                    break;
                case 'Зачеркнутая цена': // Зачеркнутая цена
                    $oldPrice = str_replace('&nbsp;', '', $xmlProperty['VALUE']);
                    $oldPrice = str_replace(' ', '', $oldPrice);
                    $oldPrice = preg_replace('!\s++!u', '', $oldPrice);
                    $result['PROPERTY_VALUES']['OLD_PRICE'] = $oldPrice;
                    break;
                case 'Не показывать на сайте':
                    // Эти свойства игнорируем
                    break;
                default:
                    $propertyData = self::getProperty($xmlProperty);
                    $result['PROPERTY_VALUES'][$propertyData['PROPERTY_CODE']] = $propertyData['VALUE_ID'];
                    break;
            }
        }
        return $result;
    }

    /**
     * Возвращает ID бренда по названию
     * В случае отсутствия, создает новый бренд
     *
     * @param string $name
     * @return false|mixed
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function getBrandByName(string $name)
    {
        Loader::includeModule('iblock');

        $brand = ElementTable::getList([
            'filter' => [
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_brands'),
                'NAME' => $name
            ],
            'select' => ['ID']
        ])->fetch();

        if (!$brand) {
            $obElement = new CIBlockElement();
            $code = CUtil::translit($name, 'ru', [
                'replace_space' => '-',
                'replace_other' => '-'
            ]);
            $cnt = ElementTable::getCount([
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_brands'),
                'CODE' => $code . '%'
            ]);
            if ($cnt > 0)
                $code .= '-' . $cnt;

            $brand['ID'] = $obElement->Add([
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_brands'),
                'NAME' => $name,
                'CODE' => $code,
                'ACTIVE' => 'Y'
            ]);
        }
        return $brand['ID'];
    }

    /**
     * Возвращает ID коллекции по названию
     * Если коллекции не существует, будет создана новая
     *
     * @param string $name
     * @return false|mixed
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function getCollectionByName(string $name)
    {
        Loader::includeModule('iblock');

        $collection = ElementTable::getList([
            'filter' => [
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_collection'),
                'NAME' => $name
            ],
            'select' => ['ID']
        ])->fetch();

        if (!$collection) {
            $obElement = new CIBlockElement();

            $code = CUtil::translit($name, 'ru', [
                'replace_space' => '-',
                'replace_other' => '-'
            ]);
            $cnt = ElementTable::getCount([
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_collection'),
                'CODE' => $code . '%'
            ]);
            if ($cnt > 0)
                $code .= '-' . $cnt;

            $collection['ID'] = $obElement->Add([
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_collection'),
                'NAME' => $name,
                'CODE' => $code,
                'ACTIVE' => 'Y'
            ]);
        }

        return $collection['ID'];
    }

    /**
     * Возвращает код свойства и код значения свойства для сохранения у товара
     *
     * @param array $xmlProperty
     * @return array
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function getProperty(array $xmlProperty): array
    {
        Loader::includeModule('iblock');

        $propertyXmlId = CUtil::translit($xmlProperty['ID'], 'ru');

        $property = PropertyTable::getList([
            'filter' => [
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_catalog'),
                'XML_ID' => $propertyXmlId
            ],
            'select' => ['ID', 'CODE']
        ])->fetch();

        if (!$property) {
            $obProperty = new CIBlockProperty();
            $property['ID'] = $obProperty->Add([
                'CODE' => mb_strtoupper($propertyXmlId),
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_catalog'),
                'NAME' => $xmlProperty['ID'],
                'XML_ID' => $propertyXmlId,
                'ACTIVE' => 'Y',
                'IS_REQUIRED' => 'N',
                'PROPERTY_TYPE' => PropertyTable::TYPE_LIST,
                'MULTIPLE' => 'N',
                'FILTRABLE' => 'Y',
            ]);
            CIBlockSectionPropertyLink::Set(0, $property['ID'], ['SMART_FILTER' => 'Y']);
            $property['CODE'] = mb_strtoupper($propertyXmlId);
        }

        $propertyValue = [];
        if (!empty($xmlProperty['VALUE'])) {
            $propertyValueXmlId = 's_' . (CUtil::translit($xmlProperty['VALUE'], 'ru'));
            $propertyValue = PropertyEnumerationTable::getList([
                'filter' => [
                    'PROPERTY_ID' => $property['ID'],
                    'XML_ID' => $propertyValueXmlId
                ],
                'select' => ['ID']
            ])->fetch();
            if (!$propertyValue) {
                $cnt = PropertyEnumerationTable::getCount(['XML_ID' => $propertyValueXmlId . '%']);
                if ($cnt > 0)
                    $propertyValueXmlId .= '_' . $cnt;

                $propertyValueResult = PropertyEnumerationTable::add([
                    'PROPERTY_ID' => $property['ID'],
                    'XML_ID' => $propertyValueXmlId,
                    'VALUE' => $xmlProperty['VALUE']
                ]);
                $propertyValue['ID'] = $propertyValueResult->getId();
            }
        }

        return [
            'PROPERTY_CODE' => $property['CODE'],
            'VALUE_ID' => $propertyValue['ID']
        ];
    }

    /**
     * Возвращает ID единицы измерения по его обозначению
     *
     * @param string $name
     * @return mixed
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function getMeasureByName(string $name = '')
    {
        Loader::includeModule('catalog');

        if (empty($name))
            $name = 'шт';

        if ($name == 'м2')
            $name = 'м<sup>2</sup>';

        $measure = MeasureTable::getList([
            'filter' => ['SYMBOL' => $name],
            'select' => ['ID']
        ])->fetch();

        return $measure['ID'];
    }

    /**
     * Возвращает общее кол-во товара по всем складам
     *
     * @param array $xmlProduct
     * @return int|mixed
     * @throws Main\ArgumentException
     */
    protected static function getAllQuantity(array $xmlProduct)
    {
        $quantity = 0;
        if (!empty($xmlProduct['STORES'])) {
            $stores = Json::decode($xmlProduct['STORES']);
            foreach ($stores as $store) {
                $quantity += $store['QUANTITY'];
            }
        }
        return $quantity;
    }

    /**
     * Устанавливает цену на товар
     *
     * @param $productId
     * @param $price
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectNotFoundException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function setPrice($productId, $price)
    {
        Loader::includeModule('catalog');

        $price = str_replace('&nbsp;', '', $price);
        $price = str_replace(' ', '', $price);
        $price = preg_replace('!\s++!u', '', $price);
        $catalogGroupId = \CCatalogGroup::GetBaseGroupId();

        $priceItem = Price::getList([
            'filter' => [
                'PRODUCT_ID' => $productId,
                'CATALOG_GROUP_ID' => $catalogGroupId
            ],
            'select' => ['ID']
        ])->fetch();

        if ($priceItem) {
            $result = Price::update($priceItem['ID'], [
                'PRICE' => $price
            ]);
        } else {
            $result = Price::add([
                'PRODUCT_ID' => $productId,
                'CATALOG_GROUP_ID' => $catalogGroupId,
                'PRICE' => $price,
                'CURRENCY' => 'RUB'
            ]);
        }

        return $result;
    }

    /**
     * Устанавливает коэффициент единицы измерения
     *
     * @param $productId
     * @param $ratio
     * @return Main\Entity\AddResult|Main\Entity\UpdateResult|Main\ORM\Data\AddResult|Main\ORM\Data\UpdateResult
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected function setMeasureRatio($productId, $ratio)
    {
        Loader::includeModule('catalog');

        $measureRatio = MeasureRatioTable::getList([
            'filter' => ['PRODUCT_ID' => $productId]
        ])->fetch();

        if ($measureRatio) {
            $result = MeasureRatioTable::update($measureRatio['ID'], ['RATIO' => $ratio]);
        } else {
            $result = MeasureRatioTable::add(['PRODUCT_ID' => $productId, 'RATIO' => $ratio]);
        }

        return $result;
    }

    /**
     * Сбрасывает кеш и обновляет фасетный индекс
     */
    protected static function updateIblockIndexes()
    {
        $iblockId = Iblock::getIblockIdByCode('eshop_catalog');

        PropertyIndex\Manager::DeleteIndex($iblockId);
        PropertyIndex\Manager::markAsInvalid($iblockId);
        $index = PropertyIndex\Manager::createIndexer($iblockId);
        $index->startIndex();
        $index->continueIndex();
        $index->endIndex();
        PropertyIndex\Manager::checkAdminNotification();
        \CBitrixComponent::clearComponentCache("bitrix:catalog.smart.filter");
        \CBitrixComponent::clearComponentCache("bitrix:catalog");
        \CIBlock::clearIblockTagCache($iblockId);
    }
}
