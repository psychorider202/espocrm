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

namespace Espo\Core\Mail\Account\GroupAccount;

use Espo\Core\Mail\Account\Storage\Params;
use Espo\Core\Mail\Account\Account;
use Espo\Core\Mail\Account\StorageFactory as StorageFactoryInterface;
use Espo\Core\Mail\Account\Storage\LaminasStorage;
use Espo\Core\Mail\Exceptions\NoImap;
use Espo\Core\Mail\Mail\Storage\Imap;

use Espo\Core\Utils\Log;
use Espo\Core\InjectableFactory;

use Throwable;

class StorageFactory implements StorageFactoryInterface
{
    private Log $log;
    private InjectableFactory $injectableFactory;

    public function __construct(
        Log $log,
        InjectableFactory $injectableFactory
    ) {
        $this->log = $log;
        $this->injectableFactory = $injectableFactory;
    }

    /**
     * @throws NoImap
     */
    public function create(Account $account): LaminasStorage
    {
        $imapParams = $account->getImapParams();

        if (!$imapParams) {
            throw new NoImap();
        }

        $params = Params::createBuilder()
            ->setHost($imapParams->getHost())
            ->setPort($imapParams->getPort())
            ->setSecurity($imapParams->getSecurity())
            ->setUsername($imapParams->getUsername())
            ->setPassword($imapParams->getPassword())
            ->setId($account->getId())
            ->setImapHandlerClassName($account->getImapHandlerClassName())
            ->build();

        return $this->createWithParams($params);
    }

    public function createWithParams(Params $params): LaminasStorage
    {
        $rawParams = [
            'host' => $params->getHost(),
            'port' => $params->getPort(),
            'username' => $params->getUsername(),
            'password' => $params->getPassword(),
            'imapHandler' => $params->getImapHandlerClassName(),
            'id' => $params->getId(),
        ];

        if ($params->getSecurity()) {
            $rawParams['security'] = $params->getSecurity();
        }

        $imapParams = null;

        $handlerClassName = $rawParams['imapHandler'] ?? null;

        $handler = null;

        if ($handlerClassName && !empty($rawParams['id'])) {
            try {
                $handler = $this->injectableFactory->create($handlerClassName);
            }
            catch (Throwable $e) {
                $this->log->error(
                    "InboundEmail: Could not create Imap Handler. Error: " . $e->getMessage()
                );
            }

            if ($handler && method_exists($handler, 'prepareProtocol')) {
                // for backward compatibility
                $rawParams['ssl'] = $rawParams['security'] ?? null;

                // @todo Incorporate an interface `LaminasProtocolPreparator`.
                $imapParams = $handler->prepareProtocol($rawParams['id'], $rawParams);
            }
        }

        if (!$imapParams) {
            $imapParams = [
                'host' => $rawParams['host'],
                'port' => $rawParams['port'],
                'user' => $rawParams['username'],
                'password' => $rawParams['password'],
            ];

            if (!empty($rawParams['security'])) {
                $imapParams['ssl'] = $rawParams['security'];
            }
        }

        return new LaminasStorage(
            new Imap($imapParams)
        );
    }
}
