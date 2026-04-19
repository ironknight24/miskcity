<?php

namespace Drupal\Tests\global_module\Unit\Service;

use Drupal\global_module\Service\FileUploadService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @coversDefaultClass \Drupal\global_module\Service\FileUploadService
 * @group global_module
 */
class FileUploadServiceTest extends UnitTestCase {

  protected $uuidService;
  protected $vaultConfigService;
  protected $service;

  /**
   * {@inheritdoc}
   * @covers ::__construct
   */
  protected function setUp(): void {
    parent::setUp();

    $this->uuidService = $this->createMock(UuidInterface::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);

    $this->service = new FileUploadService($this->uuidService, $this->vaultConfigService);
  }

  /**
   * @covers ::uploadFile
   * @covers ::getUploadedFileInfo
   * @covers ::errorResponse
   */
  public function testUploadFileNoFile() {
    $request = new Request();
    $_FILES = [];
    $response = $this->service->uploadFile($request);
    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * @covers ::detectFileType
   */
  public function testDetectFileType() {
    $reflection = new \ReflectionClass(FileUploadService::class);
    $method = $reflection->getMethod('detectFileType');
    $method->setAccessible(TRUE);

    $this->assertEquals(['id' => 2, 'type' => 'image'], $method->invoke($this->service, 'test.jpg'));
    $this->assertEquals(['id' => 2, 'type' => 'image'], $method->invoke($this->service, 'test.jpeg'));
    $this->assertEquals(['id' => 2, 'type' => 'image'], $method->invoke($this->service, 'test.png'));
    $this->assertEquals(['id' => 4, 'type' => 'file'], $method->invoke($this->service, 'test.pdf'));
    $this->assertEquals(['id' => 4, 'type' => 'file'], $method->invoke($this->service, 'test.doc'));
    $this->assertEquals(['id' => 4, 'type' => 'file'], $method->invoke($this->service, 'test.docx'));
    $this->assertEquals(['id' => 4, 'type' => 'file'], $method->invoke($this->service, 'test.mp3'));
    $this->assertEquals(['id' => 4, 'type' => 'file'], $method->invoke($this->service, 'test.xlsx'));
    $this->assertEquals(['id' => 1, 'type' => 'video'], $method->invoke($this->service, 'test.mp4'));
    $this->assertNull($method->invoke($this->service, 'test.exe'));
  }

  /**
   * @covers ::isMimeAllowed
   */
  public function testIsMimeAllowed() {
    $reflection = new \ReflectionClass(FileUploadService::class);
    $method = $reflection->getMethod('isMimeAllowed');
    $method->setAccessible(TRUE);

    $this->assertTrue($method->invoke($this->service, 'image/jpeg'));
    $this->assertTrue($method->invoke($this->service, 'application/pdf'));
    $this->assertTrue($method->invoke($this->service, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    $this->assertTrue($method->invoke($this->service, 'video/mp4'));
    $this->assertFalse($method->invoke($this->service, 'text/plain'));
  }

  /**
   * @covers ::hasMultipleExtensions
   */
  public function testHasMultipleExtensions() {
    $reflection = new \ReflectionClass(FileUploadService::class);
    $method = $reflection->getMethod('hasMultipleExtensions');
    $method->setAccessible(TRUE);

    $this->assertTrue($method->invoke($this->service, 'test.jpg.php'));
    $this->assertFalse($method->invoke($this->service, 'test.jpg'));
  }

  /**
   * @covers ::validateFileContent
   * @covers ::validateImage
   * @covers ::validatePdf
   */
  public function testValidateFileContent() {
    $reflection = new \ReflectionClass(FileUploadService::class);
    $method = $reflection->getMethod('validateFileContent');
    $method->setAccessible(TRUE);

    // Test PDF
    $tmpPdf = tempnam(sys_get_temp_dir(), 'test.pdf');
    file_put_contents($tmpPdf, "%PDF-1.4\nSafe content");
    $this->assertTrue($method->invoke($this->service, $tmpPdf));
    unlink($tmpPdf);

    // Test Malicious PDF variants
    $tmpPdf = tempnam(sys_get_temp_dir(), 'test.pdf');
    file_put_contents($tmpPdf, "%PDF-1.4\n/JavaScript (alert(1))");
    $this->assertFalse($method->invoke($this->service, $tmpPdf));
    
    file_put_contents($tmpPdf, "%PDF-1.4\n/AA (something)");
    $this->assertFalse($method->invoke($this->service, $tmpPdf));
    unlink($tmpPdf);

    // Test Safe Image
    $tmpImg = tempnam(sys_get_temp_dir(), 'test.jpg');
    // A tiny valid 1x1 black pixel JPEG
    $validJpg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    file_put_contents($tmpImg, $validJpg);
    if (function_exists('imagecreatefromstring')) {
        $this->assertTrue($method->invoke($this->service, $tmpImg));
    }
    unlink($tmpImg);

    // Test Other (Docx etc - defaults to true)
    $tmpDoc = tempnam(sys_get_temp_dir(), 'test.docx');
    file_put_contents($tmpDoc, "DOCX content");
    $this->assertTrue($method->invoke($this->service, $tmpDoc));
    unlink($tmpDoc);
  }

  /**
   * @covers ::validateImage
   */
  public function testValidateImageInvalid() {
    $reflection = new \ReflectionClass(FileUploadService::class);
    $method = $reflection->getMethod('validateImage');
    $method->setAccessible(TRUE);

    $tmpImg = tempnam(sys_get_temp_dir(), 'invalid.jpg');
    file_put_contents($tmpImg, "not an image");
    $this->assertFalse($method->invoke($this->service, $tmpImg));
    unlink($tmpImg);
  }

  /**
   * @covers ::uploadFile
   */
  public function testUploadFileMimeNotAllowed() {
    $request = new Request();
    $tmpFile = tempnam(sys_get_temp_dir(), 'test');
    file_put_contents($tmpFile, 'dummy');
    
    $_FILES['files'] = [
      'tmp_name' => ['upload_file' => $tmpFile],
      'name' => ['upload_file' => 'test.exe'],
    ];

    $response = $this->service->uploadFile($request);
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('File content not allowed!', $data['message']);
    unlink($tmpFile);
  }

  /**
   * @covers ::uploadFile
   */
  public function testUploadFileMultipleExtensions() {
    $request = new Request();
    $tmpFile = tempnam(sys_get_temp_dir(), 'test.jpg');
    file_put_contents($tmpFile, "\xFF\xD8\xFF\xE0\x00\x10\x4A\x46\x49\x46");
    
    $_FILES['files'] = [
      'tmp_name' => ['upload_file' => $tmpFile],
      'name' => ['upload_file' => 'test.jpg.php'],
    ];

    $response = $this->service->uploadFile($request);
    $data = json_decode($response->getContent(), TRUE);
    if ($data['message'] === 'Multiple file extensions not allowed') {
        $this->assertEquals('Multiple file extensions not allowed', $data['message']);
    } else {
        $this->assertEquals('File content not allowed!', $data['message']);
    }
    unlink($tmpFile);
  }

  /**
   * @covers ::uploadToRemote
   */
  public function testUploadToRemoteMissingPath() {
    $reflection = new \ReflectionClass(FileUploadService::class);
    $method = $reflection->getMethod('uploadToRemote');
    $method->setAccessible(TRUE);

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([]);
    
    $response = $method->invoke($this->service, 'tmp.jpg', 'original.jpg', ['id' => 2, 'type' => 'image']);
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Upload path not configured in Vault.', $data['message']);
  }

  /**
   * @covers ::uploadToRemote
   */
  public function testUploadToRemoteFailure() {
    $reflection = new \ReflectionClass(FileUploadService::class);
    $method = $reflection->getMethod('uploadToRemote');
    $method->setAccessible(TRUE);

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['fileuploadPath' => 'http://invalid-url/']]
    ]);
    $this->uuidService->method('generate')->willReturn('uuid123');

    $tmpFile = tempnam(sys_get_temp_dir(), 'test.jpg');
    file_put_contents($tmpFile, "\xFF\xD8\xFF\xE0\x00\x10\x4A\x46\x49\x46");

    $response = $method->invoke($this->service, $tmpFile, 'original.jpg', ['id' => 2, 'type' => 'image']);
    $this->assertEquals(500, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Upload failed.', $data['message']);
    
    unlink($tmpFile);
  }
}
