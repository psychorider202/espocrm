<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2022 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Mail;

use Espo\Core\Mail\Exceptions\NoSmtp;
use Espo\Core\Mail\Smtp\TransportFactory;
use Espo\ORM\Collection;
use Espo\ORM\EntityCollection;

use Espo\Core\Field\DateTime;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Mail\Account\SendingAccountProvider;
use Espo\Core\Mail\Exceptions\SendingError;
use Espo\Repositories\Attachment as AttachmentRepository;
use Espo\Entities\Attachment;
use Espo\Entities\Email;
use Espo\ORM\EntityManager;

use Laminas\{
    Mime\Message as MimeMessage,
    Mime\Part as MimePart,
    Mime\Mime as Mime,
    Mail\Header\Sender as SenderHeader,
    Mail\Header\MessageId as MessageIdHeader,
    Mail\Header\ContentType as ContentTypeHeader,
    Mail\Message,
    Mail\Transport\SmtpOptions,
    Mail\Transport\Envelope,
    Mail\Transport\Smtp as SmtpTransport,
    Mail\Protocol\Exception\RuntimeException as ProtocolRuntimeException,
};

use Exception;
use InvalidArgumentException;

/**
 * Sends emails. Builds parameters for sending. Should not be used directly.
 */
class Sender
{
    private ?SmtpTransport $transport = null;
    private bool $isGlobal = false;
    /** @var array<string,mixed>  */
    private array $params = [];
    /** @var array<string,mixed> */
    private array $overrideParams = [];
    private ?Envelope $envelope = null;
    private ?Message $message = null;
    /** @var iterable<Attachment>|null */
    private $attachmentList = null;

    private Config $config;
    private EntityManager $entityManager;
    private Log $log;
    private TransportFactory $transportFactory;
    private SendingAccountProvider $accountProvider;

    public function __construct(
        Config $config,
        EntityManager $entityManager,
        Log $log,
        TransportFactory $transportFactory,
        SendingAccountProvider $accountProvider
    ) {
        $this->config = $config;
        $this->entityManager = $entityManager;
        $this->log = $log;
        $this->transportFactory = $transportFactory;
        $this->accountProvider = $accountProvider;

        $this->useGlobal();
    }

    /**
     * @deprecated
     */
    public function resetParams(): self
    {
        $this->params = [];
        $this->envelope = null;
        $this->message = null;
        $this->attachmentList = null;
        $this->overrideParams = [];

        return $this;
    }

    /**
     * With parameters.
     *
     * @param SenderParams|array<string,mixed> $params
     */
    public function withParams($params): self
    {
        if ($params instanceof SenderParams) {
            $params = $params->toArray();
        }
        else if (!is_array($params)) {
            throw new InvalidArgumentException();
        }

        $paramList = [
            'fromAddress',
            'fromName',
            'replyToAddress',
            'replyToName',
        ];

        foreach (array_keys($params) as $key) {
            if (!in_array($key, $paramList)) {
                unset($params[$key]);
            }
        }

        $this->overrideParams = array_merge($this->overrideParams, $params);

        return $this;
    }

    /**
     * With specific SMTP parameters.
     *
     * @param SmtpParams|array<string,mixed> $params
     */
    public function withSmtpParams($params): self
    {
        if ($params instanceof SmtpParams) {
            $params = $params->toArray();
        }
        else if (!is_array($params)) {
            throw new InvalidArgumentException();
        }

        return $this->useSmtp($params);
    }

    /**
     * With specific attachments.
     *
     * @param iterable<Attachment> $attachmentList
     */
    public function withAttachments(iterable $attachmentList): self
    {
        $this->attachmentList = $attachmentList;

        return $this;
    }

    /**
     * With envelope options.
     *
     * @param array<string,mixed> $options
     */
    public function withEnvelopeOptions(array $options): self
    {
        return $this->setEnvelopeOptions($options);
    }

    /**
     * Set a message instance.
     */
    public function withMessage(Message $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * @deprecated
     * @param array<string,mixed> $params
     */
    public function setParams(array $params = []): self
    {
        $this->params = array_merge($this->params, $params);

        return $this;
    }


    /**
     * @deprecated
     * @param array<string,mixed> $params
     */
    public function useSmtp(array $params = []): self
    {
        $this->isGlobal = false;

        $this->applySmtp($params);

        return $this;
    }

    /**
     * @deprecated
     */
    public function useGlobal(): self
    {
        $this->params = [];

        $this->isGlobal = true;

        return $this;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function applySmtp(array $params = []): void
    {
        $this->params = $params;

        $this->transport = $this->transportFactory->create();

        $config = $this->config;

        $localHostName = $config->get('smtpLocalHostName', gethostname());

        $options = [
            'name' => $localHostName,
            'host' => $params['server'],
            'port' => $params['port'],
            'connectionConfig' => [],
        ];

        $connectionOptions = $params['connectionOptions'] ?? [];

        foreach ($connectionOptions as $key => $value) {
            $options['connectionConfig'][$key] = $value;
        }

        if ($params['auth'] ?? false) {
            $authMechanism = $params['authMechanism'] ?? $params['smtpAuthMechanism'] ?? null;

            if ($authMechanism) {
                $authMechanism = preg_replace("([\.]{2,})", '', $authMechanism);

                if (in_array($authMechanism, ['login', 'crammd5', 'plain'])) {
                    $options['connectionClass'] = $authMechanism;
                }
                else {
                    $options['connectionClass'] = 'login';
                }
            }
            else {
                $options['connectionClass'] = 'login';
            }

            $options['connectionConfig']['username'] = $params['username'];
            $options['connectionConfig']['password'] = $params['password'];
        }

        $authClassName = $params['authClassName'] ?? $params['smtpAuthClassName'] ?? null;

        if ($authClassName) {
            $options['connectionClass'] = $authClassName;
        }

        if ($params['security'] ?? null) {
            $options['connectionConfig']['ssl'] = strtolower($params['security']);
        }

        if (array_key_exists('fromName', $params)) {
            $this->params['fromName'] = $params['fromName'];
        }

        if (array_key_exists('fromAddress', $params)) {
            $this->params['fromAddress'] = $params['fromAddress'];
        }

        $this->transport->setOptions(
            new SmtpOptions($options)
        );

        if ($this->envelope) {
            $this->transport->setEnvelope($this->envelope);
        }
    }

    /**
     * @throws NoSmtp
     */
    private function applyGlobal(): void
    {
        $systemAccount = $this->accountProvider->getSystem();

        if (!$systemAccount) {
            throw new NoSmtp("No system SMTP settings.");
        }

        $smtpParams = $systemAccount->getSmtpParams();

        if (!$smtpParams) {
            throw new NoSmtp("No system SMTP settings.");
        }

        $this->applySmtp($smtpParams->toArray());
    }

    /**
     * @deprecated
     */
    public function hasSystemSmtp(): bool
    {
        if ($this->config->get('smtpServer')) {
            return true;
        }

        if ($this->accountProvider->getSystem()) {
            return true;
        }

        return false;
    }

    /**
     * Send an email.
     *
     * @param ?array<string,mixed> $params @deprecated
     * @param ?Message $message @deprecated
     * @param iterable<Attachment> $attachmentList @deprecated
     * @throws SendingError
     */
    public function send(
        Email $email,
        ?array $params = [],
        ?Message $message = null,
        iterable $attachmentList = []
    ): void {

        if ($this->isGlobal) {
            $this->applyGlobal();
        }

        $message = $this->message ?? $message ?? new Message();

        $params = $params ?? [];

        $config = $this->config;

        $params = array_merge(
            $this->params,
            $params,
            $this->overrideParams
        );

        $fromName = $params['fromName'] ?? $config->get('outboundEmailFromName');

        if ($email->get('from')) {
            $fromAddress = trim(
                $email->get('from')
            );
        }
        else {
            if (empty($params['fromAddress']) && !$config->get('outboundEmailFromAddress')) {
                throw new NoSmtp('outboundEmailFromAddress is not specified in config.');
            }

            $fromAddress = $params['fromAddress'] ?? $config->get('outboundEmailFromAddress');

            $email->set('from', $fromAddress);
        }

        $message->addFrom($fromAddress, $fromName);

        $fromString = '<' . $fromAddress . '>';

        if ($fromName) {
            $fromString = $fromName . ' ' . $fromString;
        }

        $email->set('fromString', $fromString);

        $senderHeader = new SenderHeader();

        $senderHeader->setAddress($fromAddress);

        $message->getHeaders()
            ->addHeader($senderHeader);

        if (!empty($params['replyToAddress'])) {
            $message->setReplyTo(
                $params['replyToAddress'],
                $params['replyToName'] ?? null
            );
        }

        $this->addAddresses($email, $message);

        $attachmentPartList = [];

        /** @var EntityCollection<Attachment> $attachmentCollection */
        $attachmentCollection = $this->entityManager
            ->getCollectionFactory()
            ->create(Attachment::ENTITY_TYPE);

        if (!$email->isNew()) {
            /** @var Collection<Attachment> $relatedAttachmentCollection */
            $relatedAttachmentCollection = $this->entityManager
                ->getRDBRepository(Email::ENTITY_TYPE)
                ->getRelation($email, 'attachments')
                ->find();

            foreach ($relatedAttachmentCollection as $attachment) {
                $attachmentCollection[] = $attachment;
            }
        }

        if ($this->attachmentList !== null) {
            $attachmentList = $this->attachmentList;
        }

        foreach ($attachmentList as $attachment) {
            $attachmentCollection[] = $attachment;
        }

        if (count($attachmentCollection)) {
            /** @var AttachmentRepository $attachmentRepository */
            $attachmentRepository = $this->entityManager->getRepository(Attachment::ENTITY_TYPE);

            foreach ($attachmentCollection as $a) {
                if ($a->get('contents')) {
                    $contents = $a->get('contents');
                }
                else {
                    $fileName = $attachmentRepository->getFilePath($a);

                    if (!is_file($fileName)) {
                        continue;
                    }

                    $contents = file_get_contents($fileName);
                }

                $attachment = new MimePart($contents);

                $attachment->disposition = Mime::DISPOSITION_ATTACHMENT;
                $attachment->encoding = Mime::ENCODING_BASE64;
                $attachment->filename ='=?utf-8?B?' . base64_encode($a->get('name')) . '?=';

                if ($a->get('type')) {
                    $attachment->type = $a->get('type');
                }

                $attachmentPartList[] = $attachment;
            }
        }

        $attachmentInlineList = $email->getInlineAttachmentList();

        if (!empty($attachmentInlineList)) {
            /** @var AttachmentRepository $attachmentRepository */
            $attachmentRepository = $this->entityManager->getRepository(Attachment::ENTITY_TYPE);

            foreach ($attachmentInlineList as $a) {
                if ($a->get('contents')) {
                    $contents = $a->get('contents');
                }
                else {
                    $fileName = $attachmentRepository->getFilePath($a);

                    if (!is_file($fileName)) {
                        continue;
                    }

                    $contents = file_get_contents($fileName);
                }

                $attachment = new MimePart($contents);

                $attachment->disposition = Mime::DISPOSITION_INLINE;
                $attachment->encoding = Mime::ENCODING_BASE64;
                $attachment->id = $a->id;

                if ($a->getType()) {
                    $attachment->type = $a->getType();
                }

                $attachmentPartList[] = $attachment;
            }
        }

        $message->setSubject($email->get('name'));

        $body = new MimeMessage();

        $textPart = new MimePart($email->getBodyPlainForSending());

        $textPart->type = 'text/plain';
        $textPart->encoding = Mime::ENCODING_QUOTEDPRINTABLE;
        $textPart->charset = 'utf-8';

        $htmlPart = null;

        $isHtml = $email->isHtml();

        if ($isHtml) {
            $htmlPart = new MimePart($email->getBodyForSending());

            $htmlPart->encoding = Mime::ENCODING_QUOTEDPRINTABLE;
            $htmlPart->type = 'text/html';
            $htmlPart->charset = 'utf-8';
        }

        if (!empty($attachmentPartList)) {
            $messageType = 'multipart/related';

            if ($isHtml) {
                $content = new MimeMessage();

                $content->addPart($textPart);
                $content->addPart($htmlPart);

                $messageType = 'multipart/mixed';

                $contentPart = new MimePart($content->generateMessage());

                $contentPart->type = "multipart/alternative;\n boundary=\"" .
                    $content->getMime()->boundary() . '"';

                $body->addPart($contentPart);
            }
            else {
                $body->addPart($textPart);
            }

            foreach ($attachmentPartList as $attachmentPart) {
                $body->addPart($attachmentPart);
            }
        }
        else {
            if ($isHtml) {
                $body->setParts([$textPart, $htmlPart]);

                $messageType = 'multipart/alternative';
            }
            else {
                $body = $email->getBodyPlainForSending();

                $messageType = 'text/plain';
            }
        }

        $message->setBody($body);

        if ($messageType == 'text/plain') {
            if ($message->getHeaders()->has('content-type')) {
                $message->getHeaders()->removeHeader('content-type');
            }

            $message->getHeaders()->addHeaderLine('Content-Type', 'text/plain; charset=UTF-8');
        }
        else {
            if (!$message->getHeaders()->has('content-type')) {
                $contentTypeHeader = new ContentTypeHeader();

                $message->getHeaders()->addHeader($contentTypeHeader);
            }

            /** @phpstan-ignore-next-line */
            $message->getHeaders()->get('content-type')->setType($messageType);
        }

        $message->setEncoding('UTF-8');

        try {
            $messageId = $email->get('messageId');

            if (
                empty($messageId) ||
                !is_string($messageId) ||
                strlen($messageId) < 4 ||
                strpos($messageId, 'dummy:') === 0
            ) {
                $messageId = $this->generateMessageId($email);

                $email->set('messageId', '<' . $messageId . '>');

                if ($email->hasId()) {
                    $this->entityManager->saveEntity($email, ['silent' => true]);
                }
            }
            else {
                $messageId = substr($messageId, 1, strlen($messageId) - 2);
            }

            $messageIdHeader = new MessageIdHeader();

            $messageIdHeader->setId($messageId);

            $message->getHeaders()->addHeader($messageIdHeader);

            assert($this->transport !== null);

            $this->transport->send($message);

            $email->setStatus(Email::STATUS_SENT);
            $email->set('dateSent', DateTime::createNow()->getString());
        }
        catch (Exception $e) {
            $this->resetParams();
            $this->useGlobal();

            $this->handleException($e);
        }

        $this->resetParams();
        $this->useGlobal();
    }

    /**
     * @return never
     * @throws SendingError
     */
    private function handleException(Exception $e): void
    {
        if ($e instanceof ProtocolRuntimeException) {
            $message = "Unknown error.";

            if (
                stripos($e->getMessage(), 'password') !== false ||
                stripos($e->getMessage(), 'credentials') !== false ||
                stripos($e->getMessage(), '5.7.8') !== false
            ) {
                $message = 'Invalid credentials.';
            }

            $this->log->error("Email sending error: " . $e->getMessage());

            throw new SendingError($message);
        }

        throw new SendingError($e->getMessage());
    }

    static public function generateMessageId(Email $email): string
    {
        $rand = mt_rand(1000, 9999);

        if ($email->getParentType() && $email->getParentId()) {
            $messageId =
                '' . $email->get('parentType') . '/' .
                $email->get('parentId') . '/' . time() . '/' . $rand . '@espo';
        }
        else {
            $messageId =
                '' . md5($email->get('name')) . '/' .time() . '/' .
                $rand .  '@espo';
        }

        if ($email->get('isSystem')) {
            $messageId .= '-system';
        }

        return $messageId;
    }

    /**
     * @deprecated
     *
     * @param array<string,mixed> $options
     */
    public function setEnvelopeOptions(array $options): self
    {
        $this->envelope = new Envelope($options);

        return $this;
    }

    private function addAddresses(Email $email, Message $message): void
    {
        $value = $email->get('to');

        if ($value) {
            $arr = explode(';', $value);

            foreach ($arr as $address) {
                $message->addTo(
                    trim($address)
                );
            }
        }

        $value = $email->get('cc');

        if ($value) {
            $arr = explode(';', $value);

            foreach ($arr as $address) {
                $message->addCC(
                    trim($address)
                );
            }
        }

        $value = $email->get('bcc');

        if ($value) {
            $arr = explode(';', $value);

            foreach ($arr as $address) {
                $message->addBCC(
                    trim($address)
                );
            }
        }

        $value = $email->get('replyTo');

        if ($value) {
            $arr = explode(';', $value);

            foreach ($arr as $address) {
                $message->addReplyTo(
                    trim($address)
                );
            }
        }
    }
}
