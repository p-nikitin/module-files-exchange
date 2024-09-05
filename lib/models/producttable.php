<?php

namespace Izifir\Exchange\Models;

use Bitrix\Main\Entity;

class ProductTable extends ModelBaseTable
{
    /**
     * @return string
     */
    public static function getTableName(): string
    {
        return 'izi_exchange_product';
    }

    /**
     * @return array
     */
    public static function getMap(): array
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true,
            ]),
            new Entity\StringField('XML_ID', []),
            new Entity\StringField('NAME', []),
            new Entity\StringField('ARTICLE', []),
            new Entity\StringField('PRICE', []),
            new Entity\StringField('SECTION', []),
            new Entity\TextField('STORES', []),
            new Entity\StringField('DESCRIPTION', []),
            new Entity\TextField('PROPERTIES', []),
        ];
    }

}
