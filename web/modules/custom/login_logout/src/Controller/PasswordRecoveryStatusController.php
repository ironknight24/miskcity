<?php

namespace Drupal\login_logout\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PasswordRecoveryStatusController extends ControllerBase
{
    protected $tempStoreFactory;

    public function __construct(PrivateTempStoreFactory $temp_store_factory)
    {
        $this->tempStoreFactory = $temp_store_factory;
    }

    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('tempstore.private')
        );
    }

    public function statusPage()
    {

        $tempstore = $this->tempStoreFactory->get('login_logout');
        $email = $tempstore->get('recovery_email');
        return [
            '#theme' => 'password_recovery_status',
            '#email' => $email,
        ];
    }
}
