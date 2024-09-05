<?php
/**
 *
 */

use Bitrix\Main\ModuleManager;

class stratosfera_exchange extends CModule
{
    public $MODULE_ID = 'izifir.exchange';

    public function __construct()
    {
        $arVersion = [];
        include (dirname(__FILE__) . '/version.php');

        $this->MODULE_VERSION = $arVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arVersion['VERSION_DATE'];

        $this->MODULE_NAME = 'Обмен с 1С';
        $this->MODULE_DESCRIPTION = 'Обмен данными с 1С';

        $this->PARTNER_NAME = 'Стратосфера';
        $this->PARTNER_URI = 'https://izifir.ru';
    }

    public function DoInstall()
    {
        ModuleManager::registerModule($this->MODULE_ID);
    }

    public function DoUninstall()
    {
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }
}
