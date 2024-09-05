<?php

namespace Izifir\Exchange;

use Bitrix\Main;
use Bitrix\Main\{Config\Option, Diag\Debug, Type\DateTime, Web\Json};
use DOMDocument;
use Exception;
use Izifir\Exchange\Models\OrderTable;
use Izifir\Exchange\Models\ProductTable;
use Izifir\Exchange\Models\SectionTable;
use Izifir\Exchange\Models\StoreTable;

class Import
{
    public static function agent()
    {
        self::run();
        return '\Izifir\Exchange\Import::agent();';
    }
    public static function run()
    {
        $status = self::getStatus();
        $file = new File();
        // Если импорт еще не запущен
        if ($status['IS_RUN'] == 'N') {
            // Получим файл для обработки
            $xml = $file->getNextFile();
            echo 'Начало импорта<br>';
            if ($xml) {
                // Переместим файл в директорию для текущих обработок
                $file->moveToProcessDir($xml);
                // распарсим файл
                self::parseXml($xml);

                $status['IS_RUN'] = 'Y';
                $status['DATE_START'] = time();
                $status['DATE_END'] = '';
                $status['PAGE'] = '0';
                $status['FILE_PATH'] = $xml->getPath();
                self::updateStatus($status);
            }
        } else {
            $connection = Main\Application::getConnection();
            $orderTable = Main\Entity\Base::getInstance(OrderTable::class);
            $storeTable = Main\Entity\Base::getInstance(StoreTable::class);
            $sectionTable = Main\Entity\Base::getInstance(SectionTable::class);
            $productTable = Main\Entity\Base::getInstance(ProductTable::class);
            // Проверяем по очереди наличие временных таблиц и при необходимости запускаем соответствующий обработчик
            // Если временных таблиц нет, завершаем импорт
            if ($connection->isTableExists($orderTable->getDBTableName())) {
                OrderImport::process($status);
            } elseif($connection->isTableExists($storeTable->getDBTableName())) {
                StoreImport::process($status);
            } elseif($connection->isTableExists($sectionTable->getDBTableName())) {
                SectionImport::process($status);
            } elseif($connection->isTableExists($productTable->getDBTableName())) {
                ProductImport::process($status);
            } else {
                $status['IS_RUN'] = 'N';
                $status['PAGE'] = '0';
                $status['DATE_END'] = time();
                // Переместим файл в директорию для завершенных обработок
                $xml = new Main\IO\File($status['FILE_PATH']);
                $file->moveToCompletedDir($xml);
                echo 'Импорт завершен';
            }
        }
        Debug::dump($status);
        self::updateStatus($status);
    }

    /**
     * Возвращает текущий статус импорта
     *
     * @return array|mixed
     * @throws Main\ArgumentException
     * @throws Main\ArgumentNullException
     * @throws Main\ArgumentOutOfRangeException
     */
    public static function getStatus()
    {
        $status = Option::get('izifir.exchange', 'importStatus', '{}');
        $status = Json::decode($status);
        if (empty($status))
            $status = [
                'IS_RUN' => 'N',
                'FILE_PATH' => '',
                'PAGE' => '0',
                'DATE_START' => '',
                'DATE_END' => ''
            ];
        return $status;
    }

    /**
     * Сохраняет текущий статус импорта
     *
     * @param $status
     * @throws Main\ArgumentException
     * @throws Main\ArgumentOutOfRangeException
     */
    public static function updateStatus($status)
    {
        $status = Json::encode($status);
        Option::set('izifir.exchange', 'importStatus', $status);
    }

    /**
     * @param Main\IO\File $xmlFile
     * @throws Main\ArgumentException
     * @throws Main\ObjectException
     * @throws Exception
     */
    public static function parseXml(Main\IO\File $xmlFile)
    {
        $xml = simplexml_load_file($xmlFile->getPath());
        if ($xml->Заказ->count()) {
            OrderImport::parse($xml);
        }
        if ($xml->Склады->count()) {
            StoreImport::parse($xml->Склады);
        }
        if ($xml->Разделы->count()) {
            SectionImport::parse($xml->Разделы);
        }
        if ($xml->Товары->count()) {
            ProductImport::parse($xml->Товары);
        }
    }
}
