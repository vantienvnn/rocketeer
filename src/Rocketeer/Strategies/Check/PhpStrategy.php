<?php

/*
 * This file is part of Rocketeer
 *
 * (c) Maxime Fabre <ehtnam6@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace Rocketeer\Strategies\Check;

use Rocketeer\Container;
use Rocketeer\Services\Config\ContextualConfiguration;
use Rocketeer\Strategies\AbstractCheckStrategy;

class PhpStrategy extends AbstractCheckStrategy implements CheckStrategyInterface
{
    /**
     * @var string
     */
    protected $description = 'Checks if the server is ready to receive a PHP application';

    /**
     * The language of the strategy.
     *
     * @var string
     */
    protected $language = 'PHP';

    /**
     * The PHP extensions loaded on server.
     *
     * @var array
     */
    protected $extensions = [];

    /**
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->manager = $this->binary('composer');
    }

    //////////////////////////////////////////////////////////////////////
    /////////////////////////////// CHECKS ///////////////////////////////
    //////////////////////////////////////////////////////////////////////

    /**
     * Get the version constraint which should be checked against.
     *
     * @param string $manifest
     *
     * @return string
     */
    protected function getLanguageConstraint($manifest)
    {
        return $this->getLanguageConstraintFromJson($manifest, 'require.php');
    }

    /**
     * Get the current version in use.
     *
     * @return string
     */
    protected function getCurrentVersion()
    {
        return $this->php()->runLast('version');
    }

    /**
     * Check for the required extensions.
     *
     * @return array
     */
    public function extensions()
    {
        $extensions = $this->getExtensions();

        // Check PHP extensions
        $errors = [];
        foreach ($extensions as $check) {
            list($method, $extension) = $check;

            if (!$this->$method($extension)) {
                $errors[] = $extension;
            }
        }

        return $errors;
    }

    /**
     * Check for the required drivers.
     *
     * @return array
     */
    public function drivers()
    {
        return [];
    }

    //////////////////////////////////////////////////////////////////////
    ////////////////////////////// HELPERS ///////////////////////////////
    //////////////////////////////////////////////////////////////////////

    /**
     * Check the presence of the correct database PHP extension.
     *
     * @param string $database
     *
     * @return bool
     */
    public function checkDatabaseDriver($database)
    {
        switch ($database) {
            case 'sqlite':
                return $this->checkPhpExtension('pdo_sqlite');
            case 'mysql':
                return $this->checkPhpExtension('mysql') && $this->checkPhpExtension('pdo_mysql');
            default:
                return true;
        }
    }

    /**
     * Check the presence of the correct cache PHP extension.
     *
     * @param string $cache
     *
     * @return bool|string
     */
    public function checkCacheDriver($cache)
    {
        switch ($cache) {
            case 'memcached':
            case 'apc':
                return $this->checkPhpExtension($cache);
            case 'redis':
                return $this->which('redis-server');
            default:
                return true;
        }
    }

    /**
     * Check the presence of a PHP extension.
     *
     * @param string $extension The extension
     *
     * @return bool
     */
    public function checkPhpExtension($extension)
    {
        // Check for HHVM and built-in extensions
        if ($this->php()->isHhvm()) {
            $this->extensions = [
                '_hhvm',
                'apache',
                'asio',
                'bcmath',
                'bz2',
                'ctype',
                'curl',
                'debugger',
                'fileinfo',
                'filter',
                'gd',
                'hash',
                'hh',
                'iconv',
                'icu',
                'imagick',
                'imap',
                'json',
                'mailparse',
                'mcrypt',
                'memcache',
                'memcached',
                'mysql',
                'odbc',
                'openssl',
                'pcre',
                'phar',
                'reflection',
                'session',
                'soap',
                'std',
                'stream',
                'thrift',
                'url',
                'wddx',
                'xdebug',
                'zip',
                'zlib',
            ];
        }

        // Get the PHP extensions available
        if (!$this->extensions) {
            $this->extensions = (array) $this->bash->run($this->php()->extensions(), false, true);
        }

        return in_array($extension, $this->extensions, true);
    }

    /**
     * Get the required extensions.
     *
     * @return array
     */
    protected function getExtensions()
    {
        $extensions = [
            'database' => ['checkDatabaseDriver', $this->container->get(ContextualConfiguration::class)->get('database.default')],
            'cache' => ['checkCacheDriver', $this->container->get(ContextualConfiguration::class)->get('cache.driver')],
            'session' => ['checkCacheDriver', $this->container->get(ContextualConfiguration::class)->get('session.driver')],
        ];

        foreach ($this->getRequiredExtensionsFromComposer() as $extension) {
            $extensions[$extension] = ['checkPhpExtension', $extension];
        }

        return $extensions;
    }

    /**
     * Get a list of extensions specified in the composer.json file.
     *
     * @return string[]
     */
    private function getRequiredExtensionsFromComposer()
    {
        $extensions = [];

        if (!$manifest = $this->manager->getManifestContents()) {
            return $extensions;
        }

        $data = json_decode($manifest, true);
        $require = (array) array_get($data, 'require');
        foreach ($require as $package => $version) {
            if (substr($package, 0, 4) === 'ext-') {
                $extensions[] = substr($package, 4);
            }
        }

        return $extensions;
    }
}
