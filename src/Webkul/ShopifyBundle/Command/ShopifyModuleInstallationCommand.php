<?php
namespace Webkul\ShopifyBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Console\Input\InputArgument;

class ShopifyModuleInstallationCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('shopify:setup:install')
            ->setDescription('Install Shopify Akeneo connector setup')
            ->setHelp('setups shopify bundle installation');
    }

    protected $commandExecutor;

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // $this->runCommand(
        //         'cache:clear',
        //         ['--no-warmup' => true],
        //         $output
        //     );
        $this->runCommand(
                'cache:warmup',
                [],
                $output                
            );
        $this->runCommand(
                'pim:install:asset',
                [],
                $output
            );

        $this->runCommand(
            'assets:install', 
            [
                //    'web'  => null,
                '--symlink' => null,
            ], 
            $output
        );

        $this->runCommand(
            'doctrine:schema:update', 
            [
                '--force' => null,
            ], 
            $output
        );        
    }

    protected function runCommand($name, array $args, $output)
    {
        $command = $this->getApplication()->find($name);
        $commandInput = new ArrayInput(
            $args
        );
        $command->run($commandInput, $output);        
    }
}