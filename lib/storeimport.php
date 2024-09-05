<?php

namespace Izifir\Exchange;

use Bitrix\Main\Loader;
use SimpleXMLElement;
use Izifir\Exchange\Models\StoreTable;

class StoreImport
{
    const PAGE_STORES_COUNT = 10; // Количество складов обрабатываемых за один раз

    public static function parse(SimpleXMLElement $xml)
    {
        if ($xml->Склад->count()) {
            StoreTable::createTable();
            echo "Временная таблица складов создана<br>";
            foreach ($xml->Склад as $store) {
                StoreTable::add([
                    'STORE_ID' => (string)$store->Код,
                    'NAME' => (string)$store->Название,
                    'CITY' => (string)$store->Город,
                ]);
            }
            echo "Склады в файле импорта обработаны<br>";
        }
    }

    public static function process(&$status)
    {
        Loader::includeModule('catalog');

        $cnt = StoreTable::getCount();

        $storeIterator = StoreTable::getList([
            'limit' => self::PAGE_STORES_COUNT,
            'offset' => ($status['PAGE'] * self::PAGE_STORES_COUNT)
        ])->fetchAll();
        if (count($storeIterator) > 0) {
            foreach ($storeIterator as $rawStore) {
                $storeFields = [
                    'TITLE' => $rawStore['NAME'],
                    'ADDRESS' => $rawStore['CITY']
                ];

                $store = \CCatalogStore::GetList([], ['XML_ID' => $rawStore['STORE_ID']], false, false, ['ID'])->Fetch();
                if ($store) {
                    \CCatalogStore::Update($store['ID'], $storeFields);
                } else {
                    $storeFields['XML_ID'] = $rawStore['STORE_ID'];
                    \CCatalogStore::Add($storeFields);
                }
            }
            $processedCount = ($status['PAGE'] + 1) * self::PAGE_STORES_COUNT;
            if ($processedCount > $cnt)
                $processedCount = $cnt;
            echo "Обработано {$processedCount} из {$cnt} складов<br>";
            ++$status['PAGE'];
        } else {
            $status['PAGE'] = 0;
            StoreTable::dropTable();
            echo "Обработка складов завершена<br>";
        }
    }
}
