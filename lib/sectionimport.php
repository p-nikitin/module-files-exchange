<?php

namespace Izifir\Exchange;

use Bitrix\Main;
use Bitrix\Main\Loader;
use CIBlockSection;
use CUtil;
use Exception;
use SimpleXMLElement;
use Izifir\Core\Helpers\Iblock;
use Izifir\Exchange\Models\SectionTable;
use Bitrix\Iblock\SectionTable as IblockSectionTable;

class SectionImport
{
    const PAGE_SECTIONS_COUNT = 10; // Количество разделов обрабатываемых за один раз

    /**
     * @param SimpleXMLElement $xml
     * @throws Exception
     */
    public static function parse(SimpleXMLElement $xml)
    {
        if ($xml->Раздел->count()) {
            SectionTable::createTable();
            echo "Временная таблица разделов создана<br>";
            foreach ($xml->Раздел as $xmlSection) {
                $sectionFields = [
                    'XML_ID' => (string)$xmlSection->Код,
                    'PARENT' => (string)$xmlSection->КодРодителя,
                    'NAME' => (string)$xmlSection->Название,
                ];
                SectionTable::add($sectionFields);
            }
            echo "Разделы в файле импорта обработаны<br>";
        }
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

        $section = IblockSectionTable::getList([
            'filter' => [
                'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_catalog'),
                'XML_ID' => $xmlId
            ],
            'select' => ['ID'],
        ])->fetch();

        return $section['ID'];
    }

    /**
     * @param $status
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    public static function process(&$status)
    {
        Loader::includeModule('iblock');
        $cnt = SectionTable::getCount();
        $xmlSectionIterator = SectionTable::getList([
            'limit' => self::PAGE_SECTIONS_COUNT,
            'offset' => ($status['PAGE'] * self::PAGE_SECTIONS_COUNT),
        ])->fetchAll();
        if (count($xmlSectionIterator) > 0) {
            foreach ($xmlSectionIterator as $xmlSection) {
                $sectionFields = [
                    'NAME' => $xmlSection['NAME'],
                ];
                if (!empty($xmlSection['PARENT'])) {
                    $parentSectionId = self::getSectionByXmlId($xmlSection['PARENT']);
                    if ($parentSectionId) {
                        $sectionFields['IBLOCK_SECTION_ID'] = $parentSectionId;
                    }
                }
                $sectionId = self::getSectionByXmlId($xmlSection['XML_ID']);
                $obSection = new CIBlockSection();
                if ($sectionId) {
                    $obSection->Update($sectionId, $sectionFields);
                    echo "Обновлен раздел [{$sectionId}] {$sectionFields['NAME']}<br>";
                } else {
                    $sectionFields['CODE'] = self::getSectionCode($xmlSection['NAME']);
                    $sectionFields['IBLOCK_ID'] = Iblock::getIblockIdByCode('eshop_catalog');
                    $sectionFields['ACTIVE'] = 'Y';
                    $sectionFields['XML_ID'] = $xmlSection['XML_ID'];
                    $sectionId = $obSection->Add($sectionFields);
                    if ($sectionId > 0) {
                        echo "Создан раздел [{$sectionId}] {$sectionFields['NAME']}<br>";
                    } else {
                        echo "Ошибка при создании раздела [{$sectionFields['XML_ID']}] {$sectionFields['NAME']} - {$obSection->LAST_ERROR}<br>";
                    }
                }
            }
            $processedCount = ($status['PAGE'] + 1) * self::PAGE_SECTIONS_COUNT;
            if ($processedCount > $cnt)
                $processedCount = $cnt;
            echo "Обработано {$processedCount} из {$cnt} категорий<br>";
            ++$status['PAGE'];
        } else {
            echo "Обработка разделов завершена<br>";
            $status['PAGE'] = 0;
            SectionTable::dropTable();
        }
    }

    /**
     * @param string $name
     * @return string|void
     * @throws Main\ObjectPropertyException
     * @throws Main\SystemException
     */
    protected static function getSectionCode(string $name)
    {
        $name = CUtil::translit($name, 'ru', [
            'replace_space' => '-',
            'replace_other' => '-'
        ]);
        $cnt = IblockSectionTable::getCount([
            'IBLOCK_ID' => Iblock::getIblockIdByCode('eshop_catalog'),
            'CODE' => $name
        ]);
        if ($cnt > 0)
            $name .= '-' . $cnt;

        return $name;
    }
}
