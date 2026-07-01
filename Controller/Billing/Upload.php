<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Controller\Billing;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

/**
 * Persists the Tax Exempt Certificate file picked on the billing step under
 * pub/media/customcheckout/tax_exempt/<quote_id>/. Billing/Save.php later
 * writes the relative path this endpoint returns into quote.tax_exempt_file
 * (previously only the filename text was kept; the file itself was discarded).
 */
class Upload implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const UPLOAD_DIR = 'customcheckout/tax_exempt';
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly CheckoutSession $checkoutSession,
        private readonly UploaderFactory $uploaderFactory,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId()) {
                return $result->setData(['success' => false, 'message' => __('Your cart is empty.')]);
            }

            $file = $this->request->getFiles('tax_exempt_file');
            if (!$file || empty($file['name'])) {
                return $result->setData(['success' => false, 'message' => __('No file was uploaded.')]);
            }
            if ((int) ($file['size'] ?? 0) > self::MAX_FILE_SIZE) {
                return $result->setData(['success' => false, 'message' => __('The file exceeds the 5MB size limit.')]);
            }

            $uploader = $this->uploaderFactory->create(['fileId' => 'tax_exempt_file']);
            $uploader->setAllowedExtensions(self::ALLOWED_EXTENSIONS);
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);

            $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $relativeDir = self::UPLOAD_DIR . '/' . $quote->getId();
            $mediaDirectory->create($relativeDir);

            $uploadResult = $uploader->save($mediaDirectory->getAbsolutePath($relativeDir));
            $relativePath = $relativeDir . '/' . $uploadResult['file'];

            return $result->setData([
                'success' => true,
                'path' => $relativePath,
                'name' => (string) ($uploadResult['name'] ?? $uploadResult['file']),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('INSEAD tax exempt upload: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => __('Unable to upload the file. Please use a PDF, JPG or PNG under 5MB.'),
            ]);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeyValidator->validate($request);
    }
}
