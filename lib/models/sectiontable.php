<?php

namespace Izifir\Exchange\Models;

use Bitrix\Main\Entity;

class SectionTable extends ModelBaseTable
{
    /**
     * @return string
     */
    public static function getTableName(): string
    {
        return 'izi_exchange_sections';
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
            new Entity\StringField('PARENT', []),
            new Entity\StringField('NAME', [])
        ];
    }
}
