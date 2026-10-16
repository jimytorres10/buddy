<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tant que le wizard n'est pas terminé, l'utilisateur y est renvoyé.
 */
class OnboardingSubscriber implements EventSubscriberInterface
{
    public function __construct(private Security $security, private UrlGeneratorInterface $urls)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Après le firewall (priorité 8) pour que l'utilisateur soit connu.
        return [KernelEvents::REQUEST => ['onRequest', 0]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isOnboardingCompleted()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route');
        if (str_starts_with($route, 'app_onboarding') || str_starts_with($route, '_') || 'app_logout' === $route) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_onboarding')));
    }
}
