<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Tests\Web\Admin\Store;

use Eccube\Entity\Template;
use Eccube\Repository\Master\DeviceTypeRepository;
use Eccube\Repository\TemplateRepository;
use Eccube\Tests\Web\Admin\AbstractAdminWebTestCase;
use Eccube\Util\StringUtil;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class TemplateControllerTest extends AbstractAdminWebTestCase
{
    /**
     * @var string
     */
    protected $dir;

    /**
     * @var UploadedFile
     */
    protected $file;

    /**
     * @var string
     */
    protected $code;

    /**
     * @var TemplateRepository
     */
    protected $templateRepository;

    /**
     * @var DeviceTypeRepository
     */
    protected $deviceTypeRepository;

    /**
     * @var string
     */
    protected $envFile;

    /**
     * @var string
     */
    protected $env;

    protected function setUp(): void
    {
        parent::setUp();

        $this->templateRepository = $this->entityManager->getRepository(\Eccube\Entity\Template::class);
        $this->deviceTypeRepository = $this->entityManager->getRepository(\Eccube\Entity\Master\DeviceType::class);

        $this->dir = \tempnam(\sys_get_temp_dir(), 'TemplateControllerTest');
        $fs = new Filesystem();
        $fs->remove($this->dir);
        $fs->mkdir($this->dir);

        $file = $this->dir.'/template.zip';
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        $zip->addEmptyDir('app');
        $zip->addEmptyDir('html');
        $zip->close();
        $this->file = new UploadedFile($file, 'dummy.zip', 'application/zip');

        $this->code = StringUtil::random(6);

        $this->envFile = static::getContainer()->getParameter('kernel.project_dir').'/.env';
        if (file_exists($this->envFile)) {
            $this->env = file_get_contents($this->envFile);
        }
    }

    protected function tearDown(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->dir);

        $templatePath = static::getContainer()->getParameter('kernel.project_dir').'/app/template/'.$this->code;
        if ($fs->exists($templatePath)) {
            $fs->remove($templatePath);
        }

        if ($this->env) {
            file_put_contents($this->envFile, $this->env);
        }

        parent::tearDown();
    }

    /**
     * ????????????
     */
    public function testDisplayList()
    {
        $this->client->request('GET', $this->generateUrl('admin_store_template'));
        $this->assertTrue($this->client->getResponse()->isSuccessful());
    }

    /**
     * ???????????????????????????
     *
     * @group cache-clear
     */
    public function testChangeTemplate()
    {
        // ???????????????????????????????????????
        $this->scenarioUpload();
        $this->verifyUpload();

        $Template = $this->templateRepository->findOneBy(['code' => $this->code]);

        // ???????????????????????????
        $this->client->request('POST', $this->generateUrl('admin_store_template'), [
            'form' => [
                '_token' => 'dummy',
                'selected' => $Template->getId(),
            ],
        ]);
        $this->assertTrue($this->client->getResponse()->isRedirection());

        // .env????????????????????????
        self::assertMatchesRegularExpression('/ECCUBE_TEMPLATE_CODE='.$Template->getCode().'/', file_get_contents($this->envFile));
    }

    /**
     * ??????????????????????????????
     */
    public function testDiaplayUpload()
    {
        $this->client->request('GET', $this->generateUrl('admin_store_template_install'));
        $this->assertTrue($this->client->getResponse()->isSuccessful());
    }

    /**
     * ??????????????????
     */
    public function testUpload()
    {
        // ???????????????????????????????????????
        $this->scenarioUpload();
        $this->verifyUpload();
    }

    /**
     * ??????????????????(?????????????????????)
     */
    public function testUploadWithUppercaseSuffix()
    {
        // ???????????????????????????????????????
        $this->scenarioUpload(true);
        $this->verifyUpload();
    }

    /**
     * ??????????????????
     */
    public function testDownload()
    {
        $this->markTestIncomplete("See: \Eccube\Controller\Admin\Store\TemplateController::L151");

        // ???????????????????????????????????????
        $this->scenarioUpload();
        $this->verifyUpload();

        $Template = $this->templateRepository->findOneBy(['code' => $this->code]);

        // XXX failed to open stream: No such file or directory???????????????
        $this->client->request('GET',
            $this->generateUrl('admin_store_template_download', ['id' => $Template->getId()]));
    }

    /**
     * ??????
     */
    public function testDelete()
    {
        // ???????????????????????????????????????
        $this->scenarioUpload();
        $this->verifyUpload();

        $Template = $this->templateRepository->findOneBy(['code' => $this->code]);

        $id = $Template->getId();
        $code = $Template->getCode();

        // ??????
        $this->client->request('DELETE',
            $this->generateUrl('admin_store_template_delete', ['id' => $Template->getId()]));

        $this->assertTrue($this->client->getResponse()->isRedirection());

        $Template = $this->templateRepository->find($id);
        self::assertNull($Template);
        self::assertFalse(file_exists(static::getContainer()->getParameter('kernel.project_dir').'/app/template/'.$code));
    }

    protected function scenarioUpload($uppercase = false)
    {
        $formData = $this->createFormData();
        $fileData = $this->createFileData($uppercase);

        return $this->client->request(
            'POST',
            $this->generateUrl('admin_store_template_install'),
            [
                'admin_template' => $formData,
            ],
            [
                'admin_template' => $fileData,
            ]);
    }

    protected function verifyUpload()
    {
        $Template = $this->templateRepository->findOneBy(['code' => $this->code]);
        self::assertInstanceOf(Template::class, $Template);
    }

    protected function createFormData()
    {
        return [
            '_token' => 'dummy',
            'code' => $this->code,
            'name' => 'template name',
        ];
    }

    protected function createFileData($uppercase = false)
    {
        if ($uppercase) {
            $file = $this->dir.'/template.ZIP';
            $zip = new \ZipArchive();
            $zip->open($file, \ZipArchive::CREATE);
            $zip->addEmptyDir('app');
            $zip->addEmptyDir('html');
            $zip->close();
            $this->file = new UploadedFile($file, 'dummy.ZIP', 'application/zip');
        }

        return [
            'file' => $this->file,
        ];
    }
}
