<?php

namespace Izifir\Exchange\Models;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Entity;
use Bitrix\Main\Entity\Base;
use Bitrix\Main\Entity\ExpressionField;

class ModelBaseTable extends Entity\DataManager
{
    public static function getCnt($filter, $limit, $offset)
    {
        $query = static::query();

        $query->addSelect(new ExpressionField('CNT', 'COUNT(1)'));
        $query->setFilter($filter);
        $query->setLimit($limit);
        $query->setOffset($offset);

        $result = $query->exec()->fetch();

        return $result['CNT'];
    }

    /**
     *
     */
    public static function createTable()
    {
        $connection = Application::getConnection();
        $table = Base::getInstance(static::class);
        if (!$connection->isTableExists($table->getDBTableName())) {
            $table->createDbTable();
        } else {
            $connection->truncateTable($table->getDBTableName());
        }
    }

    /**
     * @throws SqlQueryException
     */
    public static function dropTable()
    {
        $connection = Application::getConnection();
        $table = Base::getInstance(static::class);
        if ($connection->isTableExists($table->getDBTableName())) {
            $connection->dropTable($table->getDBTableName());
        }
    }
}
