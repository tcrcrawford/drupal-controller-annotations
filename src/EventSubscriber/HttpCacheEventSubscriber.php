<?php

namespace Drupal\controller_annotations\EventSubscriber;

use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class HttpCacheEventSubscriber implements EventSubscriberInterface
{

    /**
     * @var \SplObjectStorage
     */
    private $lastModifiedDates;

    /**
     * @var \SplObjectStorage
     */
    private $eTags;

    /**
     * @var ExpressionLanguage
     */
    private $expressionLanguage;

    /**
     */
    public function __construct()
    {
        $this->lastModifiedDates = new \SplObjectStorage();
        $this->eTags = new \SplObjectStorage();
    }

    /**
     * Handles HTTP validation headers.
     *
     * @param FilterControllerEvent $event
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        $request = $event->getRequest();
        if (!$configuration = $request->attributes->get('_cache')) {
            return;
        }

        $response = new Response();

        $lastModifiedDate = '';
        if ($configuration->getLastModified()) {
            $lastModifiedDate = $this->getExpressionLanguage()->evaluate($configuration->getLastModified(), $request->attributes->all());
            $response->setLastModified($lastModifiedDate);
        }

        $eTag = '';
        if ($configuration->getETag()) {
            $eTag = hash('sha256', $this->getExpressionLanguage()->evaluate($configuration->getETag(), $request->attributes->all()));
            $response->setETag($eTag);
        }

        if ($response->isNotModified($request)) {
            $event->setController(function () use ($response) {
                return $response;
            });
            $event->stopPropagation();
        } else {
            if ($eTag) {
                $this->eTags[$request] = $eTag;
            }
            if ($lastModifiedDate) {
                $this->lastModifiedDates[$request] = $lastModifiedDate;
            }
        }
    }

    /**
     * Modifies the response to apply HTTP cache headers when needed.
     *
     * @param FilterResponseEvent $event
     */
    public function onKernelResponse(FilterResponseEvent $event)
    {
        $request = $event->getRequest();

        if (!$configuration = $request->attributes->get('_cache')) {
            return;
        }

        $response = $event->getResponse();

        // http://tools.ietf.org/html/draft-ietf-httpbis-p4-conditional-12#section-3.1
        if (!in_array($response->getStatusCode(), array(200, 203, 300, 301, 302, 304, 404, 410))) {
            return;
        }

        if (null !== $age = $configuration->getSMaxAge()) {
            if (!is_numeric($age)) {
                $now = microtime(true);

                $age = ceil(strtotime($configuration->getSMaxAge(), $now) - $now);
            }

            $response->setSharedMaxAge($age);
        }

        if (null !== $age = $configuration->getMaxAge()) {
            if (!is_numeric($age)) {
                $now = microtime(true);

                $age = ceil(strtotime($configuration->getMaxAge(), $now) - $now);
            }

            $response->setMaxAge($age);
        }

        if (null !== $configuration->getExpires()) {
            $date = \DateTime::createFromFormat('U', strtotime($configuration->getExpires()), new \DateTimeZone('UTC'));
            $response->setExpires($date);
        }

        if (null !== $configuration->getVary()) {
            $response->setVary($configuration->getVary());
        }

        if ($configuration->isPublic()) {
            $response->setPublic();
        }

        if ($configuration->isPrivate()) {
            $response->setPrivate();
        }

        if (isset($this->lastModifiedDates[$request])) {
            $response->setLastModified($this->lastModifiedDates[$request]);

            unset($this->lastModifiedDates[$request]);
        }

        if (isset($this->eTags[$request])) {
            $response->setETag($this->eTags[$request]);

            unset($this->eTags[$request]);
        }
    }

    /**
     * @codeCoverageIgnore
     * @return ExpressionLanguage
     */
    private function getExpressionLanguage()
    {
        if (null === $this->expressionLanguage) {
            if (!class_exists(ExpressionLanguage::class)) {
                throw new \RuntimeException('Unable to use expressions as the Symfony ExpressionLanguage component is not installed.');
            }
            $this->expressionLanguage = new ExpressionLanguage();
        }

        return $this->expressionLanguage;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::CONTROLLER => [
                ['onKernelController', 0],
            ],
            KernelEvents::RESPONSE => [
                ['onKernelResponse', 100],
            ],
        ];
    }
}
