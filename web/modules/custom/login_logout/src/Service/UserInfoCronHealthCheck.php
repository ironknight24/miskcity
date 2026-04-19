<?php

namespace Drupal\login_logout\Service;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

class UserInfoCronHealthCheck
{

  protected $validator;
  protected $logger;

  public function __construct(UserInfoValidator $validator, LoggerInterface $logger)
  {
    $this->validator = $validator;
    $this->logger = $logger;
  }

  public function run()
  {
    $this->logger->info('Running UserInfoCronHealthCheck.');
    $data = $this->validator->validate();

    if (!empty($data)) {
      $this->logger->info('Cron userinfo check passed for {email}', ['email' => $data['sub']]);
    } else {
      $this->logger->warning('Cron userinfo check failed.');
    }
  }
}
