<?php
defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Cybersalt\Component\TalkbackMigrate\Administrator\Extension\TalkbackmigrateComponent;

return new class implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Cybersalt\\Component\\TalkbackMigrate'));
        $container->registerServiceProvider(new MVCFactory('\\Cybersalt\\Component\\TalkbackMigrate'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new TalkbackmigrateComponent($container->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));
                return $component;
            }
        );
    }
};
