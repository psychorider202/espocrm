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

namespace Espo\Tools\Oidc;

use Espo\Core\Authentication\Jwt\Exceptions\Invalid;
use Espo\Core\Authentication\Oidc\Login as OidcLogin;
use Espo\Core\Authentication\Oidc\BackchannelLogout;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\ForbiddenSilent;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Json;

class Service
{
    private Config $config;
    private BackchannelLogout $backchannelLogout;

    public function __construct(Config $config, BackchannelLogout $backchannelLogout)
    {
        $this->config = $config;
        $this->backchannelLogout = $backchannelLogout;
    }

    /**
     * @return array{
     *     clientId: non-empty-string,
     *     endpoint: non-empty-string,
     *     redirectUri: non-empty-string,
     *     scopes: non-empty-array<string>,
     *     claims: ?string,
     *     prompt: 'login'|'consent'|'select_account',
     *     maxAge: ?int,
     * }
     * @throws Forbidden
     * @throws Error
     */
    public function getAuthorizationData(): array
    {
        if ($this->config->get('authenticationMethod') !== OidcLogin::NAME) {
            throw new ForbiddenSilent();
        }

        /** @var ?string $clientId */
        $clientId = $this->config->get('oidcClientId');
        /** @var ?string $endpoint */
        $endpoint = $this->config->get('oidcAuthorizationEndpoint');
        /** @var string[] $scopes */
        $scopes = $this->config->get('oidcScopes') ?? [];

        /** @var ?string $groupClaim */
        $groupClaim = $this->config->get('oidcGroupClaim');

        if (!$clientId) {
            throw new Error("No client ID.");
        }

        if (!$endpoint) {
            throw new Error("No authorization endpoint.");
        }

        $redirectUri = rtrim($this->config->get('siteUrl') ?? '', '/') . '/oauth-callback.php';

        array_unshift($scopes, 'openid');

        $claims = null;

        if ($groupClaim) {
            $claims = Json::encode([
                'id_token' => [
                    $groupClaim => ['essential' => true],
                ],
            ]);
        }

        /** @var 'login'|'consent'|'select_account' $prompt */
        $prompt = $this->config->get('oidcAuthorizationPrompt') ?? 'consent';
        /** @var ?int $maxAge */
        $maxAge = $this->config->get('oidcAuthorizationMaxAge');

        return [
            'clientId' => $clientId,
            'endpoint' => $endpoint,
            'redirectUri' => $redirectUri,
            'scopes' => $scopes,
            'claims' => $claims,
            'prompt' => $prompt,
            'maxAge' => $maxAge,
        ];
    }

    /**
     * @throws ForbiddenSilent
     */
    public function backchannelLogout(string $rawToken): void
    {
        if ($this->config->get('authenticationMethod') !== OidcLogin::NAME) {
            throw new ForbiddenSilent();
        }

        try {
            $this->backchannelLogout->logout($rawToken);
        }
        catch (Invalid $e) {
            throw new ForbiddenSilent("OIDC logout: Invalid JWT. " . $e->getMessage());
        }
    }
}
