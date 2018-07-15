<?php

namespace Webkul\ShopifyBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @link http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class ShopifyExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        $loader->load('jobs.yml');
        $loader->load('job_parameters.yml');
        $loader->load('form_entry.yml');
        $loader->load('steps.yml');
        $loader->load('readers.yml');
        $loader->load('processors.yml');
        $loader->load('writers.yml');

        /* version wise loading */
        $versionClass = new \Pim\Bundle\CatalogBundle\Version();
        $version = $versionClass::VERSION;
        $versionDirectoryPrefix = '2.x/';
        if($version > '2.2') {
            $versionDirectoryPrefix = '2.2/';
        }        
        $loader->load($versionDirectoryPrefix . 'jobs.yml');
        $loader->load($versionDirectoryPrefix . 'processors.yml');
    }
}
