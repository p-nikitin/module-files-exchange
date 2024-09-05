<?php

namespace Izifir\Exchange;

use Bitrix\Main;
use Bitrix\Main\{Application, Diag\Debug};
use Bitrix\Main\IO\{Directory, File as IoFile};

class File
{
    const ROOT_DIRECTORY = '/upload/exchange', // Общая директория обмена
        UPLOAD_DIRECTORY = '/upload/', // Директория для загрузки новых файлов
        PROCESS_DIRECTORY = '/process/', // Директория для текущей обработки
        COMPLETED_DIRECTORY = '/completed/', // Директория с завершенными обработками
        IMAGES_DIRECTORY = '/images/'; // Директория для хранения изображений

    public function __construct()
    {
        $this->checkDirectories();
    }

    private function checkDirectories()
    {
        (new Directory($this->getUploadDir(true)))->create();
        (new Directory($this->getProcessDir(true)))->create();
        (new Directory($this->getCompletedDir(true)))->create();
        (new Directory($this->getImagesDir(true)))->create();
    }

    /**
     * Возвращает путь до директории с новыми файлами
     *
     * @param bool $absolute
     * @return string
     */
    public function getUploadDir(bool $absolute = false): string
    {
        $path = '';
        if ($absolute)
            $path .= Application::getDocumentRoot();

        $path .= self::ROOT_DIRECTORY . self::UPLOAD_DIRECTORY;

        return $path;
    }

    /**
     * Возвращает путь до директории с текущими обработками
     *
     * @param bool $absolute
     * @return string
     */
    public function getProcessDir(bool $absolute = false): string
    {
        $path = '';
        if ($absolute)
            $path .= Application::getDocumentRoot();

        $path .= self::ROOT_DIRECTORY . self::PROCESS_DIRECTORY;

        return $path;
    }

    /**
     * Возвращает путь до директории с завершенными обработками
     *
     * @param bool $absolute
     * @return string
     */
    public function getCompletedDir(bool $absolute = false): string
    {
        $path = '';
        if ($absolute)
            $path .= Application::getDocumentRoot();

        $path .= self::ROOT_DIRECTORY . self::COMPLETED_DIRECTORY;

        return $path;
    }

    /**
     * Возвращает путь до директории с изображениями
     *
     * @param bool $absolute
     * @return string
     */
    public function getImagesDir(bool $absolute = false): string
    {
        $path = '';
        if ($absolute)
            $path .= Application::getDocumentRoot();

        $path .= self::ROOT_DIRECTORY . self::IMAGES_DIRECTORY;

        return $path;
    }

    /**
     * Возвращает самый старый файл, доступный для обработки
     *
     * @return IoFile|Main\IO\FileSystemEntry|null
     * @throws Main\IO\FileNotFoundException
     */
    public function getNextFile()
    {
        $directory = new Directory(self::getUploadDir(true));
        $files = $directory->getChildren();
        $modificationsTime = 0;
        $xml = null;
        foreach ($files as $file) {
            // Учитываем только файлы с расширением xml
            if ($file instanceof IoFile && $file->getExtension() == 'xml') {
                if ($modificationsTime == 0 || $file->getModificationTime() < $modificationsTime) {
                    $modificationsTime = $file->getModificationTime();
                    $xml = $file;
                }
            }
        }
        return $xml;
    }

    /**
     * Перемещает файл в директорию текущих обработок
     *
     * @param IoFile $file
     */
    public function moveToProcessDir(IoFile &$file)
    {
        $file->rename(self::getProcessDir(true) . $file->getName());
    }

    /**
     * Перемещает файл в директорию завершенных обработок
     *
     * @param IoFile $file
     */
    public function moveToCompletedDir(IoFile &$file)
    {
        $file->rename(self::getCompletedDir(true) . $file->getName());
    }
}
