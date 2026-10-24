<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Controller\Webhook;

use JoePham\BinancePay\Model\Api\WebhookVerifier;
use JoePham\BinancePay\Model\OrderLocator;
use JoePham\BinancePay\Model\OrderSync;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Binance Pay order and refund notifications.
 *
 * The signature is checked with Binance's public key, then the order is re-queried from Binance (OrderSync),
 * so the notification body is only used to find the order. Binance retries a notification that does not get
 * {"returnCode":"SUCCESS"} up to six times.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @param HttpRequest $request
     * @param JsonFactory $jsonFactory
     * @param WebhookVerifier $verifier
     * @param OrderLocator $orderLocator
     * @param OrderSync $orderSync
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly WebhookVerifier $verifier,
        private readonly OrderLocator $orderLocator,
        private readonly OrderSync $orderSync,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(): Json
    {
        $body = (string)$this->request->getContent();
        $payload = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($payload)) {
            return $this->reply(400, false, 'Malformed body');
        }
        $data = $payload['data'] ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true, 512, JSON_BIGINT_AS_STRING);
        }
        $data = is_array($data) ? $data : [];

        $order = $this->orderLocator->locate(
            (string)($data['merchantTradeNo'] ?? ''),
            isset($data['passThroughInfo']) ? (int)$data['passThroughInfo'] : null
        );

        try {
            $valid = $this->verifier->verify(
                $body,
                (string)$this->request->getHeader('BinancePay-Timestamp'),
                (string)$this->request->getHeader('BinancePay-Nonce'),
                (string)$this->request->getHeader('BinancePay-Signature'),
                (string)$this->request->getHeader('BinancePay-Certificate-SN'),
                $order ? (int)$order->getStoreId() : null
            );
        } catch (\Exception $e) {
            $this->logger->error('Binance Pay webhook could not be verified', ['error' => $e->getMessage()]);
            return $this->reply(500, false, 'Verification unavailable');
        }
        if (!$valid) {
            $this->logger->warning('Binance Pay webhook with an invalid signature', ['body' => $body]);
            return $this->reply(401, false, 'Invalid signature');
        }
        if ($order === null) {
            $this->logger->info('Binance Pay webhook for an unknown order', ['body' => $body]);
            return $this->reply(200, true);
        }

        try {
            $bizType = (string)($payload['bizType'] ?? '');
            if ($bizType === 'PAY') {
                $this->orderSync->sync((int)$order->getId());
            } elseif ($bizType === 'PAY_REFUND') {
                $this->orderSync->addRefundNotice(
                    (int)$order->getId(),
                    (string)($payload['bizStatus'] ?? ''),
                    (array)($data['refundInfo'] ?? [])
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Binance Pay webhook failed', [
                'order' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
            return $this->reply(500, false, 'Processing failed');
        }

        return $this->reply(200, true);
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Server-to-server call authenticated by the RSA signature instead of a form key.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Response in the format Binance expects.
     *
     * @param int $httpCode
     * @param bool $success
     * @param string|null $message
     * @return Json
     */
    private function reply(int $httpCode, bool $success, ?string $message = null): Json
    {
        return $this->jsonFactory->create()
            ->setHttpResponseCode($httpCode)
            ->setData(['returnCode' => $success ? 'SUCCESS' : 'FAIL', 'returnMessage' => $message]);
    }
}
