<?php

namespace Izifir\Exchange\Models;

use Bitrix\Main\Entity;

class OrderTable extends ModelBaseTable
{
    /**
     * @return string
     */
    public static function getTableName(): string
    {
        return 'izi_exchange_order';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', [
                'autocomplete' => true,
                'primary' => true
            ]),
            new Entity\StringField('ORDER_ID', [
                'required' => true
            ]),
            new Entity\StringField('ORDER_ID_1C', []),
            new Entity\DatetimeField('DATE_INSERT', []),
            new Entity\StringField('STATUS_ID', []),
            new Entity\StringField('STATUS_NAME', []),
            new Entity\StringField('DELIVERY_ID', []),
            new Entity\StringField('DELIVERY_NAME', []),
            new Entity\StringField('PAY_SYSTEM_ID', []),
            new Entity\StringField('PAY_SYSTEM_NAME', []),
            new Entity\StringField('DELIVERY_PRICE', []),
            new Entity\BooleanField('IS_PAID', [
                'values' => ['Y', 'N']
            ]),
            new Entity\BooleanField('IS_CANCELED', [
                'values' => ['Y', 'N']
            ]),
            new Entity\DatetimeField('DATE_STATUS', []),
            new Entity\StringField('NAME', []),
            new Entity\StringField('EMAIL', []),
            new Entity\StringField('CITY', []),
            new Entity\StringField('PHONE', []),
            new Entity\TextField('PRODUCTS', [])
        ];
    }
}
