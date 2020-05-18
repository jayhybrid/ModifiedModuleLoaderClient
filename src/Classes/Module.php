<?php

/*
 * This file is part of MMLC - ModifiedModuleLoaderClient.
 *
 * (c) Robin Wieschendorf <mail@robinwieschendorf.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RobinTheHood\ModifiedModuleLoaderClient;

use RobinTheHood\ModifiedModuleLoaderClient\App;
use RobinTheHood\ModifiedModuleLoaderClient\ShopInfo;
use RobinTheHood\ModifiedModuleLoaderClient\FileInfo;
use RobinTheHood\ModifiedModuleLoaderClient\ModuleFiler;
use RobinTheHood\ModifiedModuleLoaderClient\ModuleInfo;
use RobinTheHood\ModifiedModuleLoaderClient\DependencyManager;
use RobinTheHood\ModifiedModuleLoaderClient\Api\ApiRequest;
use RobinTheHood\ModifiedModuleLoaderClient\Loader\ModuleLoader;
use RobinTheHood\ModifiedModuleLoaderClient\Loader\LocalModuleLoader;
use RobinTheHood\ModifiedModuleLoaderClient\Helpers\ArrayHelper;
use RobinTheHood\ModifiedModuleLoaderClient\Helpers\FileHelper;
use RobinTheHood\ModifiedModuleLoaderClient\Helpers\ServerHelper;

class Module extends ModuleInfo
{
    private $localRootPath;
    private $urlRootPath;
    private $modulePath;
    private $iconPath;
    private $imagePaths;
    private $docFilePaths;
    private $srcFilePaths;
    private $isRemote;
    private $isLoadable;

    // .../ModifiedModuleLoaderClient
    public function getLocalRootPath()
    {
        return $this->localRootPath;
    }

    // ...shop.de
    public function getUrlRootPath()
    {
        return $this->urlRootPath;
    }

    public function getUrlOrLocalRootPath()
    {
        if ($this->isRemote()) {
            return $this->getUrlRootPath();
        } else {
            return $this->getLocalRootPath();
        }
    }

    // /Modules/vendor/module
    public function getModulePath()
    {
        return $this->modulePath;
    }

    // /Modules/vendor/module/icon.png
    public function getIconPath()
    {
        return $this->iconPath;
    }

    public function setIconPath($iconPath)
    {
        $this->iconPath = $iconPath;
    }

    // /Modules/vendor/module/images/image1.jpg
    public function getImagePaths()
    {
        return $this->imagePaths;
    }

    public function setImagePaths($imagePaths)
    {
        $this->imagePaths = $imagePaths;
    }

    // /Modules/vendor/module/docs/install.md
    public function getDocFilePaths()
    {
        return $this->docFilePaths;
    }

    public function setDocFilePaths($docFilePaths)
    {
        $this->docFilePaths = $docFilePaths;
    }

    // /admin/includes/...
    public function getSrcFilePaths()
    {
        return $this->srcFilePaths;
    }

    // /Modules/Vender/Module/new_files
    public function getSrcRootPath()
    {
        return $this->getModulePath() . '/' . $this->getSourceDir();
    }

    // ...shop.de/Modules/Vender/Module/icon.xxx
    public function getIconUri()
    {
        return $this->getUrlRootPath() . $this->getIconPath();
    }

    public function getImageUris()
    {
        return array_map(function($value) {
                return $this->getUrlRootPath() . $value;
            }, $this->getImagePaths()
        );
    }

    public function getDocFilePath($fileName)
    {
        foreach ($this->getDocFilePaths() as $docFilePath) {
            if (\substr_count($docFilePath, $fileName)) {
                return $docFilePath;
            }
        }
    }

    public function getInstallationMd()
    {
        $docFilePath = $this->getDocFilePath('install.md');
        if (!$docFilePath) {
            return;
        }
        $path = $this->getUrlOrLocalRootPath() . $docFilePath;
        return FileHelper::readMarkdown($path);
    }

    public function getUsageMd()
    {
        $docFilePath = $this->getDocFilePath('usage.md');
        if (!$docFilePath) {
            return;
        }
        $path = $this->getUrlOrLocalRootPath() . $docFilePath;
        return FileHelper::readMarkdown($path);
    }

    public function getChangeLogMd()
    {
        $path = $this->getUrlOrLocalRootPath() . $this->getModulePath() . '/changelog.md';
        return FileHelper::readMarkdown($path);
    }

    public function getHashFileName()
    {
        return 'modulehash.json';
    }

    public function getHashPath()
    {
        return App::getRoot() . $this->getModulePath() . '/' . $this->getHashFileName();
    }

    public function setRemote($value)
    {
        $this->isRemote = $value;
    }

    public function isRemote()
    {
        return $this->isRemote;
    }

    public function setLoadable($value)
    {
        $this->isLoadable = $value;
    }

    public function isLoadable()
    {
        return $this->isLoadable;
    }

    public function isInstalled()
    {
        if (file_exists($this->getHashPath())) {
            return true;
        }
        return false;
    }

    public function isChanged()
    {
        if ($this->getChancedFiles()) {
            return true;
        } else {
            return false;
        }
    }

    public function isLoaded()
    {
        if ($this->isRemote()) {
            $localModuleLoader = LocalModuleLoader::getModuleLoader();
            $localModules = $localModuleLoader->loadAll();

            foreach($localModules as $module) {
                if ($module->getArchiveName() != $this->getArchiveName()) {
                    continue;
                }

                if ($module->getVersion() != $this->getVersion()) {
                    continue;
                }
                return true;
            }
            return false;
        }
        return true;
    }

    public function isCompatible()
    {
        $installedVersion = ShopInfo::getModifiedVersion();
        $versions = $this->getModifiedCompatibility();

        foreach($versions as $version) {
            if ($installedVersion == $version) {
                return true;
            }
        }

        return false;
    }

    public function load($path)
    {
        $result = $this->loadFromJson($path . '/moduleinfo.json');

        if (!$result) {
            return false;
        }

        $this->localRootPath = App::getRoot();
        $this->urlRootPath = ServerHelper::getUri();
        $this->modulePath = FileHelper::stripBasePath(App::getRoot(), $path);

        $this->iconPath = $this->loadIconPath($path);
        $this->imagePaths = $this->loadImagePaths($path . '/images');
        $this->docFilePaths = $this->loadDocFilePaths($path . '/docs');
        $this->srcFilePaths = $this->loadSrcFilePaths($this->getLocalRootPath() . $this->getSrcRootPath());

        return true;
    }

    public function loadIconPath($path)
    {
        if (file_exists($path . '/icon.jpg')) {
            $iconPath = $this->getModulePath() . '/icon.jpg';
        } elseif (file_exists($path . '/icon.png')) {
            $iconPath = $this->getModulePath() . '/icon.png';
        } else {
            if ($this->getCategory() == 'library') {
                $iconPath = '/src/Templates/Images/icon_library.png';
            } else {
                $iconPath = '/src/Templates/Images/icon_module.png';
            }
        }

        return $iconPath;
    }

    public function loadImagePaths($path)
    {
        if (!is_dir($path)) {
            return [];
        }

        $images = [];

        $fileNames = scandir($path);
        foreach($fileNames as $fileName) {
            if (strpos($fileName, '.jpg') || strpos($fileName, '.png')) {
                $images[] = $path . '/' . $fileName;
            }
        }

        $images = FileHelper::stripAllBasePaths(App::getRoot(), $images);

        return $images;
    }

    public function loadDocFilePaths($path)
    {
        if (!is_dir($path)) {
            return [];
        }

        $docFiles = [];

        $fileNames = scandir($path);
        foreach($fileNames as $fileName) {
            if (strpos($fileName, '.md')) {
                $docFiles[] = $path . '/' . $fileName;
            }
        }

        $docFiles = FileHelper::stripAllBasePaths(App::getRoot(), $docFiles);

        return $docFiles;
    }

    public function loadSrcFilePaths($path)
    {
        $filePaths = FileHelper::scanDirRecursive($path, FileHelper::FILES_ONLY, true);
        $filePaths = FileHelper::stripAllBasePaths($path, $filePaths);
        return $filePaths;
    }

    public function getTemplateFiles($file)
    {
        $files = [];
        if (FileInfo::isTemplateFile($file)) {
            $templates = ShopInfo::getTemplates();
            foreach($templates as $template) {
                $files[] = str_replace('/templates/tpl_modified/', '/templates/' . $template . '/', $file);
            }
        } else {
            $files[] = $file;
        }
        return $files;
    }

    public function getInstalledVersion()
    {
        $modules = $this->getVersions();
        $modules = ModuleFilter::filterInstalled($modules);

        if(isset($modules[0])) {
            return $modules[0];
        }

        return null;
    }

    public function getNewestVersion()
    {
        $modules = $this->getVersions();
        $module = ModuleFilter::getNewestVersion($modules);
        return $module;
    }

    public function getVersions()
    {
        $moduleLoader = ModuleLoader::getModuleLoader();
        $modules = $moduleLoader->loadAllByArchiveName($this->getArchiveName());
        $modules = ModuleFilter::orderByVersion($modules);
        return $modules;
    }

    public function getUsedBy()
    {
        $dependencyManager = new DependencyManager();
        $installedModules = $dependencyManager->getInstalledModules();
        $usedByEntrys = $dependencyManager->getUsedByEntrys($this, $installedModules);

        $usedByModules = [];
        foreach($usedByEntrys as $usedByEntry) {
            $usedByModules[] = $usedByEntry['module'];
        }
        return $usedByModules;
    }

    public function getChancedFiles()
    {
        $moduleHasher = new ModuleHasher();
        $changedFiles = $moduleHasher->getModuleChanges($this);
        return $changedFiles;
    }

    public function loadFromArray(Array $array)
    {
        parent::loadFromArray($array);

        $this->localRootPath = ArrayHelper::getIfSet($array, 'localRootPath');
        $this->urlRootPath = ArrayHelper::getIfSet($array, 'urlRootPath');
        $this->modulePath = ArrayHelper::getIfSet($array, 'modulePath');
        $this->iconPath = ArrayHelper::getIfSet($array, 'iconPath');
        $this->imagePaths = ArrayHelper::getIfSet($array, 'imagePaths', []);
        $this->docFilePaths = ArrayHelper::getIfSet($array, 'docFilePaths', []);
        $this->srcFilePaths = ArrayHelper::getIfSet($array, 'rootPath');
        $this->isRemote = ArrayHelper::getIfSet($array, 'isRemote');
        $this->isLoadable = ArrayHelper::getIfSet($array, 'isLoadable');

        return true;
    }

    public function toArray()
    {
        $moduleInfoArray = parent::toArray();

        $moduleArray = [
            'localRootPath' => $this->getLocalRootPath(),
            'urlRootPath' => $this->getUrlRootPath(),
            'modulePath' => $this->getModulePath(),
            'iconPath' => $this->getIconPath(),
            'imagePaths' => $this->getImagePaths(),
            'docFilePaths' => $this->getDocFilePaths(),
            'srcFilePaths' => $this->getSrcFilePaths(),
            'isRemote' => $this->isRemote(),
            'isLoadable' => $this->isLoadable()
        ];

        return array_merge($moduleInfoArray, $moduleArray);
    }

    public function getPriceFormated()
    {
        if ($this->getPrice() === 'free') {
            return '<span class="price-free">Kostenlos</span>';
        } elseif (!$this->getPrice()) {
            return '<span class="price-request">Preis auf Anfrage</span>';
        } else {
            return '<span class="price-normal">' . number_format((float) $this->getPrice(), 2, ',', '.') . ' € </span>';
        }
    }
}