<?php

declare(strict_types=1);

namespace Magelearn\ContactAttachment\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;

class TransportBuilderPlugin
{
    private const CONTACT_ACTION = 'contact_index_post';

    private const FILE_FIELD = 'attachment';

    private const MAX_FILE_SIZE = 10485760; // 10 MB

    /**
     * Allowed file extensions.
     */
    private const ALLOWED_EXTENSIONS = [
        'txt',
        'csv',
        'jpg',
        'jpeg',
        'gif',
        'png',
        'pdf',
        'doc',
        'docx',
    ];

    /**
     * Allowed MIME types.
     */
    private const ALLOWED_MIME_TYPES = [
        'text/plain',
        'text/csv',
        'image/jpeg',
        'image/gif',
        'image/png',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * @param RequestInterface $request
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Add the Contact Us attachment to the generated email.
     *
     * @param TransportBuilder $subject
     * @param TransportInterface $transport
     * @return TransportInterface
     * @throws LocalizedException
     */
    public function afterGetTransport(
        TransportBuilder $subject,
        TransportInterface $transport
    ): TransportInterface {
        if ($this->request->getFullActionName() !== self::CONTACT_ACTION) {
            return $transport;
        }

        $file = $this->request->getFiles(self::FILE_FIELD);

        if (!is_array($file) || !$this->hasUploadedFile($file)) {
            return $transport;
        }

        $this->validateFile($file);

        $filePath = (string) $file['tmp_name'];
        $fileName = $this->sanitizeFileName((string) $file['name']);
        $mimeType = $this->detectMimeType($filePath);

        $message = $transport->getMessage();
        $symfonyMessage = $message->getSymfonyMessage();
        $body = $symfonyMessage->getBody();

        if ($body === null) {
            throw new LocalizedException(
                __('Unable to create the email attachment.')
            );
        }

        $attachment = DataPart::fromPath(
            $filePath,
            $fileName,
            $mimeType
        );

        $symfonyMessage->setBody(
            new MixedPart(
                $body,
                $attachment
            )
        );

        return $transport;
    }

    /**
     * Check whether a valid uploaded file exists.
     *
     * @param array<string, mixed> $file
     * @return bool
     */
    private function hasUploadedFile(array $file): bool
    {
        return isset(
            $file['error'],
            $file['tmp_name'],
            $file['name'],
            $file['size']
        ) && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Validate uploaded attachment.
     *
     * @param array<string, mixed> $file
     * @return void
     * @throws LocalizedException
     */
    private function validateFile(array $file): void
    {
        $uploadError = (int) $file['error'];

        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new LocalizedException(
                __('Unable to upload the attachment. Please try again.')
            );
        }

        $filePath = (string) $file['tmp_name'];

        if (!is_uploaded_file($filePath)) {
            throw new LocalizedException(
                __('The uploaded attachment is invalid.')
            );
        }

        if (!is_readable($filePath)) {
            throw new LocalizedException(
                __('The uploaded attachment cannot be read.')
            );
        }

        $fileSize = (int) $file['size'];

        if ($fileSize <= 0) {
            throw new LocalizedException(
                __('The uploaded attachment is empty.')
            );
        }

        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new LocalizedException(
                __('The attachment exceeds the maximum allowed size of 10 MB.')
            );
        }

        $extension = strtolower(
            (string) pathinfo(
                (string) $file['name'],
                PATHINFO_EXTENSION
            )
        );

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new LocalizedException(
                __('The selected attachment file type is not allowed.')
            );
        }

        $mimeType = $this->detectMimeType($filePath);

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new LocalizedException(
                __('The selected attachment file type is not allowed.')
            );
        }
    }

    /**
     * Detect the actual MIME type of the uploaded file.
     *
     * @param string $filePath
     * @return string
     * @throws LocalizedException
     */
    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        if (!is_string($mimeType) || $mimeType === '') {
            throw new LocalizedException(
                __('Unable to determine the attachment file type.')
            );
        }

        return $mimeType;
    }

    /**
     * Sanitize the attachment filename.
     *
     * @param string $fileName
     * @return string
     */
    private function sanitizeFileName(string $fileName): string
    {
        $fileName = basename($fileName);

        $sanitizedFileName = preg_replace(
            '/[^A-Za-z0-9._-]/',
            '_',
            $fileName
        );

        return is_string($sanitizedFileName) && $sanitizedFileName !== ''
            ? $sanitizedFileName
            : 'attachment';
    }
}