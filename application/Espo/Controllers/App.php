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

namespace Espo\Controllers;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Authentication\Authentication;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\InjectableFactory;

use Espo\Tools\App\AppService as Service;

use stdClass;

class App
{
    private InjectableFactory $injectableFactory;

    public function __construct(
        InjectableFactory $injectableFactory
    ) {
        $this->injectableFactory = $injectableFactory;
    }

    public function getActionUser(): stdClass
    {
        return (object) $this->getService()->getUserData();
    }

    /**
     * @throws BadRequest
     */
    public function postActionDestroyAuthToken(Request $request, Response $response): bool
    {
        $data = $request->getParsedBody();

        if (empty($data->token)) {
            throw new BadRequest();
        }

        $auth = $this->injectableFactory->create(Authentication::class);

        return $auth->destroyAuthToken($data->token, $request, $response);
    }

    private function getService(): Service
    {
        return $this->injectableFactory->create(Service::class);
    }
}
