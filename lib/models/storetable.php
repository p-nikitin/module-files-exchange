<?php

namespace Izifir\Exchange\Models;

use Bitrix\Main\Entity;
use Exception;

class StoreTable extends ModelBaseTable
{
    /**
     * @return string
     */
    public static function getTableName(): string
    {
        return 'izi_exchange_store';
    }

    /**
     * @return array
     * @throws Exception
     */
    public static function getMap(): array
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true
            ]),
            new Entity\StringField('STORE_ID', [
                'required' => true
            ]),
            new Entity\StringField('NAME', [
                'required' => true,
            ]),
            new Entity\StringField('CITY', [
                'required' => true
            ])
        ];
    }

}
