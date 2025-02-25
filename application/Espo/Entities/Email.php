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

namespace Espo\Entities;

use Espo\Core\ApplicationUser;
use Espo\Core\Field\LinkMultiple;
use Espo\Core\Utils\Util;
use Espo\Core\ORM\Entity;
use Espo\Core\Field\DateTime;

use Espo\Repositories\Email as EmailRepository;

use Espo\Core\Field\LinkParent;
use Espo\Core\Field\Link;

use Espo\Tools\Email\Util as EmailUtil;
use RuntimeException;

class Email extends Entity
{
    public const ENTITY_TYPE = 'Email';

    public const STATUS_BEING_IMPORTED = 'Being Imported';
    public const STATUS_ARCHIVED = 'Archived';
    public const STATUS_SENT = 'Sent';
    public const STATUS_SENDING = 'Sending';
    public const STATUS_DRAFT = 'Draft';

    public const RELATIONSHIP_EMAIL_USER = 'EmailUser';

    protected function _getSubject(): ?string
    {
        return $this->get('name');
    }

    protected function _setSubject(?string $value): void
    {
        $this->set('name', $value);
    }

    /**
     * @return bool
     */
    protected function _hasSubject(): bool
    {
        return $this->has('name');
    }

    protected function _hasFromName(): bool
    {
        return $this->has('fromString');
    }

    protected function _hasFromAddress(): bool
    {
        return $this->has('fromString');
    }

    protected function _hasReplyToName(): bool
    {
        return $this->has('replyToString');
    }

    protected function _hasReplyToAddress(): bool
    {
        return $this->has('replyToString');
    }

    protected function _getFromName(): ?string
    {
        if (!$this->has('fromString')) {
            return null;
        }

        $string = EmailUtil::parseFromName($this->get('fromString'));

        if ($string === '') {
            return null;
        }

        return $string;
    }

    protected function _getFromAddress(): ?string
    {
        if (!$this->has('fromString')) {
            return null;
        }

        return EmailUtil::parseFromAddress($this->get('fromString'));
    }

    protected function _getReplyToName(): ?string
    {
        if (!$this->has('replyToString')) {
            return null;
        }

        $string = $this->get('replyToString');

        if (!$string) {
            return null;
        }

        return EmailUtil::parseFromName(
            trim(explode(';', $string)[0])
        );
    }

    protected function _getReplyToAddress(): ?string
    {
        if (!$this->has('replyToString')) {
            return null;
        }

        $string = $this->get('replyToString');

        if (!$string) {
            return null;
        }

        return EmailUtil::parseFromAddress(
            trim(explode(';', $string)[0])
        );
    }

    protected function _setIsRead(?bool $value): void
    {
        $this->setInContainer('isRead', $value !== false);

        if ($value === true || $value === false) {
            $this->setInContainer('isUsers', true);

            return;
        }

        $this->setInContainer('isUsers', false);
    }

    public function isManuallyArchived(): bool
    {
        return $this->getStatus() === self::STATUS_ARCHIVED &&
            $this->get('createdById') !== ApplicationUser::SYSTEM_USER_ID;
    }

    public function addAttachment(Attachment $attachment): void
    {
        if (!$this->id) {
            return;
        }

        $attachment->set('parentId', $this->id);
        $attachment->set('parentType', self::ENTITY_TYPE);

        if (!$this->entityManager) {
            throw new RuntimeException();
        }

        $this->entityManager->saveEntity($attachment);
    }

    protected function _getBodyPlain(): ?string
    {
        return $this->getBodyPlain();
    }

    public function hasBodyPlain(): bool
    {
        return $this->hasInContainer('bodyPlain') && $this->getFromContainer('bodyPlain');
    }

    public function getBodyPlain(): ?string
    {
        if ($this->getFromContainer('bodyPlain')) {
            return $this->getFromContainer('bodyPlain');
        }

        /** @var string $body */
        $body = $this->get('body') ?? '';

        $breaks = ["<br />", "<br>", "<br/>", "<br />", "&lt;br /&gt;", "&lt;br/&gt;", "&lt;br&gt;"];

        $body = str_ireplace($breaks, "\r\n", $body);
        $body = strip_tags($body);

        $reList = [
            '&(quot|#34);',
            '&(amp|#38);',
            '&(lt|#60);',
            '&(gt|#62);',
            '&(nbsp|#160);',
            '&(iexcl|#161);',
            '&(cent|#162);',
            '&(pound|#163);',
            '&(copy|#169);',
            '&(reg|#174);',
        ];

        $replaceList = [
            '',
            '&',
            '<',
            '>',
            ' ',
            chr(161),
            chr(162),
            chr(163),
            chr(169),
            chr(174),
        ];

        foreach ($reList as $i => $re) {
            /** @var string $body */
            $body = mb_ereg_replace($re, $replaceList[$i], $body, 'i');
        }

        return $body;
    }

    public function getBodyPlainForSending(): string
    {
        return $this->getBodyPlain() ?? '';
    }

    public function getBodyForSending(): string
    {
        $body = $this->get('body') ?? '';

        if (!empty($body)) {
            $attachmentList = $this->getInlineAttachmentList();

            foreach ($attachmentList as $attachment) {
                $id = $attachment->getId();

                $body = str_replace(
                    "\"?entryPoint=attachment&amp;id={$id}\"",
                    "\"cid:{$id}\"",
                    $body
                );
            }
        }

        return str_replace(
            "<table class=\"table table-bordered\">",
            "<table class=\"table table-bordered\" width=\"100%\">",
            $body
        );
    }

    /**
     * @return Attachment[]
     */
    public function getInlineAttachmentList(): array
    {
        $idList = [];

        $body = $this->get('body');

        if (empty($body)) {
            return [];
        }

        $matches = [];

        if (!preg_match_all("/\?entryPoint=attachment&amp;id=([^&=\"']+)/", $body, $matches)) {
            return [];
        }

        if (empty($matches[1]) || !is_array($matches[1])) {
            return [];
        }

        $attachmentList = [];

        foreach ($matches[1] as $id) {
            if (in_array($id, $idList)) {
                continue;
            }

            $idList[] = $id;

            if (!$this->entityManager) {
                throw new RuntimeException();
            }

            /** @var Attachment|null $attachment */
            $attachment = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $id);

            if ($attachment) {
                $attachmentList[] = $attachment;
            }
        }

        return $attachmentList;
    }

    public function getDateSent(): ?DateTime
    {
        /** @var ?DateTime */
        return $this->getValueObject('dateSent');
    }

    public function getSubject(): ?string
    {
        return $this->get('subject');
    }

    /**
     * @param self::STATUS_* $status
     */
    public function setStatus(string $status): self
    {
        $this->set('status', $status);

        return $this;
    }

    public function setSubject(?string $subject): self
    {
        $this->set('subject', $subject);

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->get('body');
    }

    public function setBody(?string $body): self
    {
        $this->set('body', $body);

        return $this;
    }

    public function isHtml(): ?bool
    {
        return $this->get('isHtml');
    }

    public function isRead(): ?bool
    {
        return $this->get('isRead');
    }

    public function setIsHtml(bool $isHtml = true): self
    {
        $this->set('isHtml', $isHtml);

        return $this;
    }

    public function setIsPlain(bool $isPlain = true): self
    {
        $this->set('isHtml', !$isPlain);

        return $this;
    }

    public function setFromAddress(?string $address): self
    {
        $this->set('from', $address);

        return $this;
    }

    public function addToAddress(string $address): self
    {
        $list = $this->getToAddressList();

        $list[] = $address;

        $this->set('to', implode(';', $list));

        return $this;
    }

    public function addCcAddress(string $address): self
    {
        $list = $this->getCcAddressList();

        $list[] = $address;

        $this->set('cc', implode(';', $list));

        return $this;
    }

    public function addBccAddress(string $address): self
    {
        $list = $this->getBccAddressList();

        $list[] = $address;

        $this->set('bcc', implode(';', $list));

        return $this;
    }

    public function addReplyToAddress(string $address): self
    {
        $list = $this->getReplyToAddressList();

        $list[] = $address;

        $this->set('replyTo', implode(';', $list));

        return $this;
    }

    public function getFromString(): ?string
    {
        return $this->get('fromString');
    }

    public function getFromAddress(): ?string
    {
        if (!$this->hasInContainer('from') && !$this->isNew()) {
            $this->getEmailRepository()->loadFromField($this);
        }

        return $this->get('from');
    }

    /**
     * @return string[]
     */
    public function getToAddressList(): array
    {
        if (!$this->hasInContainer('to') && !$this->isNew()) {
            $this->getEmailRepository()->loadToField($this);
        }

        $value = $this->get('to');

        if (!$value) {
            return [];
        }

        return explode(';', $value);
    }

    /**
     * @return string[]
     */
    public function getCcAddressList(): array
    {
        if (!$this->hasInContainer('cc') && !$this->isNew()) {
            $this->getEmailRepository()->loadCcField($this);
        }

        $value = $this->get('cc');

        if (!$value) {
            return [];
        }

        return explode(';', $value);
    }

    /**
     * @return string[]
     */
    public function getBccAddressList(): array
    {
        if (!$this->hasInContainer('bcc') && !$this->isNew()) {
            $this->getEmailRepository()->loadBccField($this);
        }

        $value = $this->get('bcc');

        if (!$value) {
            return [];
        }

        return explode(';', $value);
    }

    /**
     * @return string[]
     */
    public function getReplyToAddressList(): array
    {
        if (!$this->hasInContainer('replyTo') && !$this->isNew()) {
            $this->getEmailRepository()->loadReplyToField($this);
        }

        $value = $this->get('replyTo');

        if (!$value) {
            return [];
        }

        return explode(';', $value);
    }

    public function setDummyMessageId(): self
    {
        $this->set('messageId', 'dummy:' . Util::generateId());

        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->get('messageId');
    }

    public function getParentType(): ?string
    {
        return $this->get('parentType');
    }

    public function getParentId(): ?string
    {
        return $this->get('parentId');
    }

    public function getParent(): ?LinkParent
    {
        /** @var ?LinkParent */
        return $this->getValueObject('parent');
    }

    public function setParent(?LinkParent $parent): self
    {
        $this->setValueObject('parent', $parent);

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->get('status');
    }

    public function getAccount(): ?Link
    {
        /** @var ?Link */
        return $this->getValueObject('account');
    }

    public function getTeams(): LinkMultiple
    {
        /** @var LinkMultiple */
        return $this->getValueObject('teams');
    }

    public function getCreatedBy(): ?Link
    {
        /** @var ?Link */
        return $this->getValueObject('createdBy');
    }

    public function getGroupFolder(): ?Link
    {
        /** @var ?Link */
        return $this->getValueObject('groupFolder');
    }

    public function getReplied(): ?Link
    {
        /** @var ?Link */
        return $this->getValueObject('replied');
    }

    private function getEmailRepository(): EmailRepository
    {
        if (!$this->entityManager) {
            throw new RuntimeException();
        }

        /** @var EmailRepository */
        return $this->entityManager->getRepository(self::ENTITY_TYPE);
    }
}
