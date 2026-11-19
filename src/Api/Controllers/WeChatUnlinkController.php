<?php

/*
 * This file is part of nomiscz/flarum-ext-auth-wechat.
 *
 * Copyright (c) 2021 NomisCZ.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HamCQ\WeChatAuth\Api\Controllers;

use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Flarum\Http\RequestUtil;
use HamCQ\WeChatAuth\BindingHistory;

class WeChatUnlinkController implements RequestHandlerInterface
{
    /**
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws Exception
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $deleted = $actor->getConnection()->transaction(function () use ($actor, $request) {
            $binding = $actor->loginProviders()->where('provider', 'wechat')->lockForUpdate()->first();
            if (!$binding) return false;
            BindingHistory::record($binding, 'unbind', $request);
            $binding->delete();
            return true;
        });
        if (!$deleted) {
            return new EmptyResponse(StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        return new EmptyResponse(StatusCodeInterface::STATUS_OK);
    }
}
