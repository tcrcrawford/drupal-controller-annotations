<?php

namespace Drupal\controller_annotations\EventSubscriber;

use Drupal\controller_annotations\Configuration\ParamConverter;
use Drupal\controller_annotations\Request\ParamConverter\ParamConverterManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ParamConverterEventSubscriber implements EventSubscriberInterface
{
    /**
     * @var ParamConverterManager
     */
    protected $manager;

    protected $autoConvert;

    /**
     * @var bool
     */
    private $isParameterTypeSupported;

    /**
     * Constructor.
     *
     * @param ParamConverterManager $manager     A ParamConverterManager instance
     * @param bool                  $autoConvert Auto convert non-configured objects
     */
    public function __construct(ParamConverterManager $manager, $autoConvert = true)
    {
        $this->manager = $manager;
        $this->autoConvert = $autoConvert;
        $this->isParameterTypeSupported = method_exists('ReflectionParameter', 'getType');
    }

    /**
     * Modifies the ParamConverterManager instance.
     *
     * @param FilterControllerEvent $event A FilterControllerEvent instance
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        $controller = $event->getController();
        $request = $event->getRequest();
        $configurations = array();

        if ($configuration = $request->attributes->get('_converters')) {
            foreach (is_array($configuration) ? $configuration : array($configuration) as $configuration) {
                $configurations[$configuration->getName()] = $configuration;
            }
        }

        if (is_array($controller)) {
            $r = new \ReflectionMethod($controller[0], $controller[1]);
        } elseif (is_object($controller) && is_callable($controller, '__invoke')) {
            $r = new \ReflectionMethod($controller, '__invoke');
        } else {
            $r = new \ReflectionFunction($controller);
        }

        // automatically apply conversion for non-configured objects
        if ($this->autoConvert) {
            $configurations = $this->autoConfigure($r, $request, $configurations);
        }

        $this->manager->apply($request, $configurations);
    }

    private function autoConfigure(\ReflectionFunctionAbstract $r, Request $request, $configurations)
    {
        foreach ($r->getParameters() as $param) {
            if ($param->getClass() && $param->getClass()->isInstance($request)) {
                continue;
            }

            $name = $param->getName();
            $class = $param->getClass();
            $hasType = $this->isParameterTypeSupported && $param->hasType();

            if ($class || $hasType) {
                if (!isset($configurations[$name])) {
                    $configuration = new ParamConverter([]);
                    $configuration->setName($name);

                    $configurations[$name] = $configuration;
                }

                if ($class && null === $configurations[$name]->getClass()) {
                    $configurations[$name]->setClass($class->getName());
                }
            }

            if (isset($configurations[$name])) {
                $configurations[$name]->setIsOptional($param->isOptional() || $param->isDefaultValueAvailable() || $hasType && $param->getType()->allowsNull());
            }
        }

        return $configurations;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::CONTROLLER => [
                ['onKernelController', 100],
            ],
        ];
    }
}
