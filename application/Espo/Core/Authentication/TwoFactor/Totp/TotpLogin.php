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

namespace Espo\Core\Authentication\TwoFactor\Totp;

use Espo\ORM\EntityManager;

use Espo\Entities\User;
use Espo\Entities\UserData;

use Espo\Repositories\UserData as UserDataRepository;

use Espo\Core\Authentication\TwoFactor\Login;
use Espo\Core\Authentication\Result;
use Espo\Core\Authentication\Result\Data as ResultData;
use Espo\Core\Authentication\Result\FailReason;

use Espo\Core\Api\Request;

use RuntimeException;

class TotpLogin implements Login
{
    /**
     * @var EntityManager
     */
    private $entityManager;

    private $totp;

    public function __construct(EntityManager $entityManager, Util $totp)
    {
        $this->entityManager = $entityManager;
        $this->totp = $totp;
    }

    public function login(Result $result, Request $request): Result
    {
        $code = $request->getHeader('Espo-Authorization-Code');

        $user = $result->getUser();

        if (!$user) {
            throw new RuntimeException("No user.");
        }

        if (!$code) {
            return Result::secondStepRequired($user, $this->getResultData());
        }

        if ($this->verifyCode($user, $code)) {
            return $result;
        }

        return Result::fail(FailReason::CODE_NOT_VERIFIED);
    }

    private function getResultData(): ResultData
    {
        return ResultData::createWithMessage('enterTotpCode');
    }

    private function verifyCode(User $user, string $code): bool
    {
        $userData = $this->getUserDataRepository()->getByUserId($user->getId());

        if (!$userData) {
            return false;
        }

        if (!$userData->get('auth2FA')) {
            return false;
        }

        if ($userData->get('auth2FAMethod') !== 'Totp') {
            return false;
        }

        $secret = $userData->get('auth2FATotpSecret');

        if (!$secret) {
            return false;
        }

        return $this->totp->verifyCode($secret, $code);
    }

    private function getUserDataRepository(): UserDataRepository
    {
        /** @var UserDataRepository $repository */
        $repository = $this->entityManager->getRepository(UserData::ENTITY_TYPE);

        return $repository;
    }
}
