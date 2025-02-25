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

namespace Espo\Services;

use Espo\Tools\Email\SendService;

use Espo\ORM\Entity;
use Espo\Entities\User;
use Espo\Entities\Email as EmailEntity;
use Espo\Tools\Email\Service;

use Espo\Entities\Attachment;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Di;
use Espo\Core\Mail\Exceptions\SendingError;
use Espo\Core\Mail\Sender;
use Espo\Core\Mail\SmtpParams;
use Espo\Core\Record\CreateParams;

use Espo\Tools\Email\Util;
use stdClass;

/**
 * @extends Record<\Espo\Entities\Email>
 */
class Email extends Record implements

    Di\FileStorageManagerAware
{
    use Di\FileStorageManagerSetter;

    protected $getEntityBeforeUpdate = true;

    /**
     * @var string[]
     */
    protected $allowedForUpdateFieldList = [
        'parent',
        'teams',
        'assignedUser',
    ];

    protected $mandatorySelectAttributeList = [
        'name',
        'createdById',
        'dateSent',
        'fromString',
        'fromEmailAddressId',
        'fromEmailAddressName',
        'parentId',
        'parentType',
        'isHtml',
        'isReplied',
        'status',
        'accountId',
        'folderId',
        'messageId',
        'sentById',
        'replyToString',
        'hasAttachment',
        'groupFolderId',
    ];

    private ?SendService $sendService = null;

    /**
     * @deprecated Use `Espo\Tools\Email\SendService`.
     */
    public function getUserSmtpParams(string $userId): ?SmtpParams
    {
        return $this->getSendService()->getUserSmtpParams($userId);
    }

    /**
     * @deprecated Use `Espo\Tools\Email\SendService`.
     * @throws BadRequest
     * @throws SendingError
     * @throws Error
     */
    public function sendEntity(EmailEntity $entity, ?User $user = null): void
    {
        $this->getSendService()->send($entity, $user);
    }

    private function getSendService(): SendService
    {
        if (!$this->sendService) {
            $this->sendService = $this->injectableFactory->create(SendService::class);
        }

        return $this->sendService;
    }

    /**
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws Conflict
     * @throws BadRequest
     * @throws SendingError
     */
    public function create(stdClass $data, CreateParams $params): Entity
    {
        /** @var EmailEntity $entity */
        $entity = parent::create($data, $params);

        if ($entity->getStatus() === EmailEntity::STATUS_SENDING) {
            $this->getSendService()->send($entity, $this->user);
        }

        return $entity;
    }

    protected function beforeCreateEntity(Entity $entity, $data)
    {
        /** @var EmailEntity $entity */

        if ($entity->getStatus() === EmailEntity::STATUS_SENDING) {
            $messageId = Sender::generateMessageId($entity);

            $entity->set('messageId', '<' . $messageId . '>');
        }
    }

    /**
     * @throws BadRequest
     * @throws Error
     * @throws SendingError
     */
    protected function afterUpdateEntity(Entity $entity, $data)
    {
        /** @var EmailEntity $entity */

        if ($entity->getStatus() === EmailEntity::STATUS_SENDING) {
            $this->getSendService()->send($entity, $this->user);
        }

        $this->loadAdditionalFields($entity);

        if (!isset($data->from) && !isset($data->to) && !isset($data->cc)) {
            $entity->clear('nameHash');
            $entity->clear('idHash');
            $entity->clear('typeHash');
        }
    }

    public function getEntity(string $id): ?Entity
    {
        /** @var ?EmailEntity $entity */
        $entity = parent::getEntity($id);

        if ($entity && !$entity->isRead()) {
            $this->markAsRead($entity->getId());
        }

        return $entity;
    }

    private function markAsRead(string $id, ?string $userId = null): void
    {
        $service = $this->injectableFactory->create(Service::class);

        $service->markAsRead($id, $userId);
    }

    /**
     * @deprecated Use `Util`.
     */
    static public function parseFromName(?string $string): string
    {
        return Util::parseFromName($string);
    }

    /**
     * @deprecated Use `Util`.
     */
    static public function parseFromAddress(?string $string): string
    {
        return Util::parseFromAddress($string);
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function getCopiedAttachments(
        string $id,
        ?string $parentType = null,
        ?string $parentId = null,
        ?string $field = null
    ): stdClass {

        $ids = [];
        $names = (object) [];

        if (empty($id)) {
            throw new BadRequest();
        }

        /** @var ?EmailEntity $email */
        $email = $this->entityManager->getEntityById(EmailEntity::ENTITY_TYPE, $id);

        if (!$email) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntityRead($email)) {
            throw new Forbidden();
        }

        $email->loadLinkMultipleField('attachments');

        $attachmentsIds = $email->get('attachmentsIds');

        foreach ($attachmentsIds as $attachmentId) {
            /** @var ?Attachment $source */
            $source = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

            if ($source) {
                /** @var Attachment $attachment */
                $attachment = $this->entityManager->getNewEntity(Attachment::ENTITY_TYPE);

                $attachment->set('role', Attachment::ROLE_ATTACHMENT);
                $attachment->set('type', $source->getType());
                $attachment->set('size', $source->getSize());
                $attachment->set('global', $source->get('global'));
                $attachment->set('name', $source->getName());
                $attachment->set('sourceId', $source->getSourceId());
                $attachment->set('storage', $source->getStorage());

                if ($field) {
                    $attachment->set('field', $field);
                }

                if ($parentType) {
                    $attachment->set('parentType', $parentType);
                }

                if ($parentType && $parentId) {
                    $attachment->set('parentId', $parentId);
                }

                if ($this->fileStorageManager->exists($source)) {
                    $this->entityManager->saveEntity($attachment);

                    $contents = $this->fileStorageManager->getContents($source);

                    $this->fileStorageManager->putContents($attachment, $contents);

                    $ids[] = $attachment->getId();

                    $names->{$attachment->getId()} = $attachment->getName();
                }
            }
        }

        return (object) [
            'ids' => $ids,
            'names' => $names,
        ];
    }

    protected function beforeUpdateEntity(Entity $entity, $data)
    {
        /** @var EmailEntity $entity */

        $skipFilter = false;

        if ($this->user->isAdmin()) {
            $skipFilter = true;
        }

        if ($entity->isManuallyArchived()) {
            $skipFilter = true;
        } else {
            if ($entity->isAttributeChanged('dateSent')) {
                $entity->set('dateSent', $entity->getFetched('dateSent'));
            }
        }

        if ($entity->getStatus() === EmailEntity::STATUS_DRAFT) {
            $skipFilter = true;
        }

        if (
            $entity->getStatus() === EmailEntity::STATUS_SENDING &&
            $entity->getFetched('status') === EmailEntity::STATUS_DRAFT
        ) {
            $skipFilter = true;
        }

        if (
            $entity->isAttributeChanged('status') &&
            $entity->getFetched('status') === EmailEntity::STATUS_ARCHIVED
        ) {
            $entity->set('status', EmailEntity::STATUS_ARCHIVED);
        }

        if (!$skipFilter) {
            $this->clearEntityForUpdate($entity);
        }

        if ($entity->getStatus() == EmailEntity::STATUS_SENDING) {
            $messageId = Sender::generateMessageId($entity);

            $entity->set('messageId', '<' . $messageId . '>');
        }
    }

    private function clearEntityForUpdate(EmailEntity $email): void
    {
        $fieldDefsList = $this->entityManager
            ->getDefs()
            ->getEntity(EmailEntity::ENTITY_TYPE)
            ->getFieldList();

        foreach ($fieldDefsList as $fieldDefs) {
            $field = $fieldDefs->getName();

            if ($fieldDefs->getParam('isCustom')) {
                continue;
            }

            if (in_array($field, $this->allowedForUpdateFieldList)) {
                continue;
            }

            $attributeList = $this->fieldUtil->getAttributeList(EmailEntity::ENTITY_TYPE, $field);

            foreach ($attributeList as $attribute) {
                $email->clear($attribute);
            }
        }
    }
}
