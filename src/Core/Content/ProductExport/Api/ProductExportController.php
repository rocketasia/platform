<?php declare(strict_types=1);

namespace Shopware\Core\Content\ProductExport\Api;

use Shopware\Core\Content\ProductExport\Error\Error;
use Shopware\Core\Content\ProductExport\Exception\RenderFooterException;
use Shopware\Core\Content\ProductExport\Exception\RenderHeaderException;
use Shopware\Core\Content\ProductExport\Exception\RenderProductException;
use Shopware\Core\Content\ProductExport\Exception\SalesChannelDomainNotFoundException;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Content\ProductExport\Service\ProductExportGeneratorInterface;
use Shopware\Core\Content\ProductExport\Struct\ExportBehavior;
use Shopware\Core\Content\ProductExport\Struct\ProductExportResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Translation\Translator;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"api"})
 */
class ProductExportController extends AbstractController
{
    /** @var SalesChannelContextFactory */
    private $salesChannelContextFactory;

    /** @var EntityRepositoryInterface */
    private $salesChannelDomainRepository;

    /** @var ProductExportGeneratorInterface */
    private $productExportGenerator;

    /** @var Translator */
    private $translator;

    public function __construct(
        SalesChannelContextFactory $salesChannelContextFactory,
        EntityRepositoryInterface $salesChannelDomainRepository,
        ProductExportGeneratorInterface $productExportGenerator,
        Translator $translator
    ) {
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->salesChannelDomainRepository = $salesChannelDomainRepository;
        $this->productExportGenerator = $productExportGenerator;
        $this->translator = $translator;
    }

    /**
     * @Route("/api/v{version}/_action/product-export/validate", name="api.action.product_export.validate",
     *                                                           methods={"POST"})
     *
     * @throws RenderHeaderException
     * @throws RenderProductException
     * @throws RenderFooterException
     */
    public function validate(RequestDataBag $dataBag, Context $context): JsonResponse
    {
        $result = $this->generateExportPreview($dataBag, $context);

        if ($result->hasErrors()) {
            $errors = $result->getErrors();
            $errorMessages = array_merge(
                ...array_map(
                    function (Error $error) {
                        return $error->getErrorMessages();
                    },
                    $errors
                )
            );

            return new JsonResponse(
                [
                    'content' => mb_convert_encoding(
                        $result->getContent(),
                        'UTF-8',
                        $dataBag->get('encoding')
                    ),
                    'errors' => $errorMessages,
                ]
            );
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @Route("/api/v{version}/_action/product-export/preview", name="api.action.product_export.preview",
     *                                                           methods={"POST"})
     *
     * @throws RenderHeaderException
     * @throws RenderProductException
     * @throws RenderFooterException
     */
    public function preview(RequestDataBag $dataBag, Context $context): JsonResponse
    {
        $result = $this->generateExportPreview($dataBag, $context);

        return new JsonResponse(
            [
                'content' => mb_convert_encoding(
                    $result->getContent(),
                    'UTF-8',
                    $dataBag->get('encoding')
                ),
                'errors' => $result->getErrors(),
            ]
        );
    }

    private function createEntity(RequestDataBag $dataBag): ProductExportEntity
    {
        $entity = new ProductExportEntity();

        $entity->setId('');
        $entity->setAccessKey('preview-access-key');
        $entity->setFileName('preview-file-name');
        $entity->setHeaderTemplate($dataBag->get('header_template'));
        $entity->setBodyTemplate($dataBag->get('body_template'));
        $entity->setFooterTemplate($dataBag->get('footer_template'));
        $entity->setProductStreamId($dataBag->get('product_stream_id'));
        $entity->setIncludeVariants($dataBag->get('include_variants'));
        $entity->setEncoding($dataBag->get('encoding'));
        $entity->setFileFormat($dataBag->get('file_format'));
        $entity->setSalesChannelId($dataBag->get('sales_channel_id'));
        $entity->setSalesChannelDomainId($dataBag->get('sales_channel_domain_id'));

        return $entity;
    }

    private function generateExportPreview(RequestDataBag $dataBag, Context $context): ProductExportResult
    {
        $salesChannelDomainId = $dataBag->get('sales_channel_domain_id');

        $salesChannelDomain = $this->salesChannelDomainRepository->search(
            (new Criteria([$salesChannelDomainId]))->addAssociation('language.locale'),
            $context
        )->get($salesChannelDomainId);

        if (!($salesChannelDomain instanceof SalesChannelDomainEntity)) {
            throw new SalesChannelDomainNotFoundException($salesChannelDomainId);
        }

        $salesChannelContext = $this->salesChannelContextFactory->create(
            Uuid::randomHex(),
            $salesChannelDomain->getSalesChannelId()
        );
        $productExportEntity = $this->createEntity($dataBag);
        $productExportEntity->setSalesChannelDomain($salesChannelDomain);

        $exportBehavior = new ExportBehavior(true, true, true);

        $this->translator->injectSettings(
            $salesChannelDomain->getSalesChannelId(),
            $salesChannelDomain->getLanguageId(),
            $salesChannelDomain->getLanguage()->getLocaleId(),
            $context
        );

        return $this->productExportGenerator->generate($productExportEntity, $exportBehavior, $salesChannelContext);
    }
}
