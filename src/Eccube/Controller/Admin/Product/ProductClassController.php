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

namespace Eccube\Controller\Admin\Product;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\NoResultException;
use Eccube\Controller\AbstractController;
use Eccube\Entity\ClassName;
use Eccube\Entity\Product;
use Eccube\Entity\ProductClass;
use Eccube\Entity\ProductStock;
use Eccube\Form\Type\Admin\ProductClassMatrixType;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Repository\ClassCategoryRepository;
use Eccube\Repository\ProductClassRepository;
use Eccube\Repository\ProductRepository;
use Eccube\Repository\TaxRuleRepository;
use Eccube\Util\CacheUtil;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class ProductClassController extends AbstractController
{
    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var ProductClassRepository
     */
    protected $productClassRepository;

    /**
     * @var ClassCategoryRepository
     */
    protected $classCategoryRepository;

    /**
     * @var BaseInfoRepository
     */
    protected $baseInfoRepository;

    /**
     * @var TaxRuleRepository
     */
    protected $taxRuleRepository;

    /**
     * ProductClassController constructor.
     *
     * @param ProductClassRepository $productClassRepository
     * @param ClassCategoryRepository $classCategoryRepository
     */
    public function __construct(
        ProductRepository $productRepository,
        ProductClassRepository $productClassRepository,
        ClassCategoryRepository $classCategoryRepository,
        BaseInfoRepository $baseInfoRepository,
        TaxRuleRepository $taxRuleRepository
    ) {
        $this->productRepository = $productRepository;
        $this->productClassRepository = $productClassRepository;
        $this->classCategoryRepository = $classCategoryRepository;
        $this->baseInfoRepository = $baseInfoRepository;
        $this->taxRuleRepository = $taxRuleRepository;
    }

    /**
     * ?????????????????????????????????????????????????????????, ???????????????????????????????????????????????????
     *
     * @Route("/%eccube_admin_route%/product/product/class/{id}", requirements={"id" = "\d+"}, name="admin_product_product_class", methods={"GET", "POST"})
     * @Template("@admin/Product/product_class.twig")
     */
    public function index(Request $request, $id, CacheUtil $cacheUtil)
    {
        $Product = $this->findProduct($id);
        if (!$Product) {
            throw new NotFoundHttpException();
        }

        $ClassName1 = null;
        $ClassName2 = null;

        if ($Product->hasProductClass()) {
            // ???????????????????????????????????????????????????.
            $ProductClasses = $Product->getProductClasses()
                ->filter(function ($pc) {
                    return $pc->getClassCategory1() !== null;
                });

            // ??????????????????????????????1, 2?????????(???????????????????????????????????????????????????????????????????????????)
            $FirstProductClass = $ProductClasses->first();
            $ClassName1 = $FirstProductClass->getClassCategory1()->getClassName();
            $ClassCategory2 = $FirstProductClass->getClassCategory2();
            $ClassName2 = $ClassCategory2 ? $ClassCategory2->getClassName() : null;

            // ?????????1/2?????????????????????????????????, DB????????????????????????????????????????????????.
            $ProductClasses = $this->mergeProductClasses(
                $this->createProductClasses($ClassName1, $ClassName2),
                $ProductClasses);

            // ?????????????????????????????????????????????.
            $form = $this->createMatrixForm($ProductClasses, $ClassName1, $ClassName2,
                ['product_classes_exist' => true]);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                // ??????????????????token???????????????????????????????????????????????????.
                $this->isTokenValid();

                $this->saveProductClasses($Product, $form['product_classes']->getData());

                $this->addSuccess('admin.common.save_complete', 'admin');

                $cacheUtil->clearDoctrineCache();

                if ($request->get('return_product_list')) {
                    return $this->redirectToRoute('admin_product_product_class', ['id' => $Product->getId(), 'return_product_list' => true]);
                }

                return $this->redirectToRoute('admin_product_product_class', ['id' => $Product->getId()]);
            }
        } else {
            // ??????????????????
            $form = $this->createMatrixForm();
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                // ??????????????????token???????????????????????????????????????????????????.
                $this->isTokenValid();

                // ??????,?????????????????????????????????????????????.
                $isSave = $form['save']->isClicked();

                // ?????????1/2???????????????????????????????????????????????????.
                $ClassName1 = $form['class_name1']->getData();
                $ClassName2 = $form['class_name2']->getData();
                $ProductClasses = $this->createProductClasses($ClassName1, $ClassName2);

                // ?????????????????????????????????????????????.
                // class_name1, class_name2????????????????????????submit????????????, ????????????????????????????????????????????????????????????????????????
                // submit?????????, ????????????????????????????????????????????????????????????????????????.
                $form = $this->createMatrixForm($ProductClasses, $ClassName1, $ClassName2,
                    ['product_classes_exist' => true]);

                // ????????????????????????
                if ($isSave) {
                    $form->handleRequest($request);
                    if ($form->isSubmitted() && $form->isValid()) {
                        $this->saveProductClasses($Product, $form['product_classes']->getData());

                        $this->addSuccess('admin.common.save_complete', 'admin');

                        $cacheUtil->clearDoctrineCache();

                        if ($request->get('return_product_list')) {
                            return $this->redirectToRoute('admin_product_product_class', ['id' => $Product->getId(), 'return_product_list' => true]);
                        }

                        return $this->redirectToRoute('admin_product_product_class', ['id' => $Product->getId()]);
                    }
                }
            }
        }

        return [
            'Product' => $Product,
            'form' => $form->createView(),
            'clearForm' => $this->createForm(FormType::class)->createView(),
            'ClassName1' => $ClassName1,
            'ClassName2' => $ClassName2,
            'return_product_list' => $request->get('return_product_list') ? true : false,
        ];
    }

    /**
     * ??????????????????????????????.
     *
     * @Route("/%eccube_admin_route%/product/product/class/{id}/clear", requirements={"id" = "\d+"}, name="admin_product_product_class_clear", methods={"POST"})
     */
    public function clearProductClasses(Request $request, Product $Product, CacheUtil $cacheUtil)
    {
        if (!$Product->hasProductClass()) {
            return $this->redirectToRoute('admin_product_product_class', ['id' => $Product->getId()]);
        }

        $form = $this->createForm(FormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ProductClasses = $this->productClassRepository->findBy([
                'Product' => $Product,
            ]);

            try {
                // ????????????????????????????????????????????????????????????
                foreach ($ProductClasses as $ProductClass) {
                    $ProductClass->setVisible(false);
                }
                foreach ($ProductClasses as $ProductClass) {
                    if (null === $ProductClass->getClassCategory1() && null === $ProductClass->getClassCategory2()) {
                        $ProductClass->setVisible(true);
                        break;
                    }
                }
                foreach ($ProductClasses as $ProductClass) {
                    if (!$ProductClass->isVisible()) {
                        $this->entityManager->remove($ProductClass);
                    }
                }

                $this->entityManager->flush();

                $this->addSuccess('admin.product.reset_complete', 'admin');

                $cacheUtil->clearDoctrineCache();
            } catch (ForeignKeyConstraintViolationException $e) {
                log_error('??????????????????????????????', [$e]);

                $message = trans('admin.common.delete_error_foreign_key', ['%name%' => trans('admin.product.product_class')]);
                $this->addError($message, 'admin');
            }
        }

        if ($request->get('return_product_list')) {
            return $this->redirectToRoute('admin_product_product_class', ['id' => $Product->getId(), 'return_product_list' => true]);
        }

        return $this->redirectToRoute('admin_product_product_class', ['id' => $Product->getId()]);
    }

    /**
     * ?????????1/2??????, ?????????????????????????????????????????????.
     *
     * @param ClassName $ClassName1
     * @param ClassName|null $ClassName2
     *
     * @return array|ProductClass[]
     */
    protected function createProductClasses(ClassName $ClassName1, ClassName $ClassName2 = null)
    {
        $ProductClasses = [];
        $ClassCategories1 = $this->classCategoryRepository->findBy(['ClassName' => $ClassName1], ['sort_no' => 'DESC']);
        $ClassCategories2 = [];
        if ($ClassName2) {
            $ClassCategories2 = $this->classCategoryRepository->findBy(['ClassName' => $ClassName2],
                ['sort_no' => 'DESC']);
        }
        foreach ($ClassCategories1 as $ClassCategory1) {
            // ??????1??????
            if (!$ClassName2) {
                $ProductClass = new ProductClass();
                $ProductClass->setClassCategory1($ClassCategory1);
                $ProductClasses[] = $ProductClass;
                continue;
            }
            // ??????1/2
            foreach ($ClassCategories2 as $ClassCategory2) {
                $ProductClass = new ProductClass();
                $ProductClass->setClassCategory1($ClassCategory1);
                $ProductClass->setClassCategory2($ClassCategory2);
                $ProductClasses[] = $ProductClass;
            }
        }

        return $ProductClasses;
    }

    /**
     * ???????????????????????????????????????.
     *
     * @param $ProductClassesForMatrix
     * @param $ProductClasses
     *
     * @return array|ProductClass[]
     */
    protected function mergeProductClasses($ProductClassesForMatrix, $ProductClasses)
    {
        $mergedProductClasses = [];
        foreach ($ProductClassesForMatrix as $pcfm) {
            foreach ($ProductClasses as $pc) {
                if ($pcfm->getClassCategory1()->getId() === $pc->getClassCategory1()->getId()) {
                    $cc2fm = $pcfm->getClassCategory2();
                    $cc2 = $pc->getClassCategory2();

                    if (null === $cc2fm && null === $cc2) {
                        $mergedProductClasses[] = $pc;
                        continue 2;
                    }

                    if ($cc2fm && $cc2 && $cc2fm->getId() === $cc2->getId()) {
                        $mergedProductClasses[] = $pc;
                        continue 2;
                    }
                }
            }

            $mergedProductClasses[] = $pcfm;
        }

        return $mergedProductClasses;
    }

    /**
     * ?????????????????????, ????????????.
     *
     * @param Product $Product
     * @param array|ProductClass[] $ProductClasses
     */
    protected function saveProductClasses(Product $Product, $ProductClasses = [])
    {
        foreach ($ProductClasses as $pc) {
            // ????????????????????????????????????????????????????????????????????????
            if (!$pc->getId() && !$pc->isVisible()) {
                continue;
            }

            // ????????????????????????????????????, ??????????????????????????????.
            if (!$pc->getId()) {
                /** @var ProductClass $ExistsProductClass */
                $ExistsProductClass = $this->productClassRepository->findOneBy([
                    'Product' => $Product,
                    'ClassCategory1' => $pc->getClassCategory1(),
                    'ClassCategory2' => $pc->getClassCategory2(),
                ]);

                // ????????????????????????????????????????????????????????????.
                if ($ExistsProductClass) {
                    $ExistsProductClass->copyProperties($pc, [
                        'id',
                        'price01_inc_tax',
                        'price02_inc_tax',
                        'create_date',
                        'update_date',
                        'Creator',
                    ]);
                    $pc = $ExistsProductClass;
                }
            }

            // ?????????, ?????????????????????????????????POST?????????????????????visible??????????????????.
            if ($pc->getId() && !$pc->isVisible()) {
                $this->entityManager->refresh($pc);
                $pc->setVisible(false);
                continue;
            }

            $pc->setProduct($Product);
            $this->entityManager->persist($pc);

            // ???????????????
            $ProductStock = $pc->getProductStock();
            if (!$ProductStock) {
                $ProductStock = new ProductStock();
                $ProductStock->setProductClass($pc);
                $this->entityManager->persist($ProductStock);
            }
            $ProductStock->setStock($pc->isStockUnlimited() ? null : $pc->getStock());

            if ($this->baseInfoRepository->get()->isOptionProductTaxRule()) {
                $rate = $pc->getTaxRate();
                $TaxRule = $pc->getTaxRule();
                if (is_numeric($rate)) {
                    if ($TaxRule) {
                        $TaxRule->setTaxRate($rate);
                    } else {
                        // ???????????????????????????????????????????????????
                        $TaxRule = $this->taxRuleRepository->newTaxRule();
                        $TaxRule->setProduct($Product);
                        $TaxRule->setProductClass($pc);
                        $TaxRule->setTaxRate($rate);
                        $TaxRule->setApplyDate(new \DateTime());
                        $this->entityManager->persist($TaxRule);
                    }
                } else {
                    if ($TaxRule) {
                        $this->taxRuleRepository->delete($TaxRule);
                        $pc->setTaxRule(null);
                    }
                }
            }
        }

        // ??????????????????????????????????????????.
        $DefaultProductClass = $this->productClassRepository->findOneBy([
            'Product' => $Product,
            'ClassCategory1' => null,
            'ClassCategory2' => null,
        ]);
        $DefaultProductClass->setVisible(false);

        $this->entityManager->flush();
    }

    /**
     * ?????????????????????????????????????????????.
     *
     * @param array $ProductClasses
     * @param ClassName|null $ClassName1
     * @param ClassName|null $ClassName2
     * @param array $options
     *
     * @return \Symfony\Component\Form\FormInterface
     */
    protected function createMatrixForm(
        $ProductClasses = [],
        ClassName $ClassName1 = null,
        ClassName $ClassName2 = null,
        array $options = []
    ) {
        $options = array_merge(['csrf_protection' => false], $options);
        $builder = $this->formFactory->createBuilder(ProductClassMatrixType::class, [
            'product_classes' => $ProductClasses,
            'class_name1' => $ClassName1,
            'class_name2' => $ClassName2,
        ], $options);

        return $builder->getForm();
    }

    /**
     * ?????????????????????.
     * ???????????????visible=true???????????????????????????, ???????????????sort_no=DESC???????????????????????????.
     *
     * @param $id
     *
     * @return Product|null
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    protected function findProduct($id)
    {
        $qb = $this->productRepository->createQueryBuilder('p')
            ->addSelect(['pc', 'cc1', 'cc2'])
            ->leftJoin('p.ProductClasses', 'pc')
            ->leftJoin('pc.ClassCategory1', 'cc1')
            ->leftJoin('pc.ClassCategory2', 'cc2')
            ->where('p.id = :id')
            ->andWhere('pc.visible = :pc_visible')
            ->setParameter('id', $id)
            ->setParameter('pc_visible', true)
            ->orderBy('cc1.sort_no', 'DESC')
            ->addOrderBy('cc2.sort_no', 'DESC');

        try {
            return $qb->getQuery()->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }
}
