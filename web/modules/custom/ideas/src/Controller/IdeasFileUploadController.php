<?php

namespace Drupal\ideas\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\global_module\Service\FileUploadService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class IdeasFileUploadController extends ControllerBase {

  protected $fileUploadService;

  public function __construct(FileUploadService $fileUploadService) {
    $this->fileUploadService = $fileUploadService;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('global_module.file_upload_service')
    );
  }

  public function upload(Request $request) {
    $response = $this->fileUploadService->uploadFile($request);
    
    if ($response instanceof JsonResponse) {
      $data = json_decode($response->getContent(), TRUE);
      if (!empty($data['fileName'])) {
        return new JsonResponse(['fileUrl' => $data['fileName']]);
      }
      return new JsonResponse(['error' => $data['error'] ?? 'Unknown error'], 400);
    }
    return new JsonResponse(['error' => 'File upload failed'], 400);
  }
}
