<?php

namespace HamCQ\WeChatAuth;

use Flarum\Http\RequestUtil;
use Flarum\User\LoginProvider;
use Flarum\User\RegistrationToken;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class BindingHistory implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->registrationToken($request);
        if (!$token) return $handler->handle($request);
        $db = (new LoginProvider())->getConnection();
        $level = $db->transactionLevel();
        $db->beginTransaction();
        try {
            $response = $handler->handle($request);
            if ($response->getStatusCode() >= 400) $db->rollBack($level);
            else {
                // Core creates the first binding during registration; record it explicitly here.
                $binding = LoginProvider::where('provider', $token->provider)->where('identifier', $token->identifier)->firstOrFail();
                self::record($binding, 'bind', $request);
                $db->commit();
            }
            return $response;
        } catch (\Throwable $error) {
            $db->rollBack($level);
            throw $error;
        }
    }

    private function registrationToken(ServerRequestInterface $request): ?RegistrationToken
    {
        if ($request->getMethod() !== 'POST' || $request->getAttribute('routeName') !== 'users.create') return null;
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['data']['attributes']['token'] ?? null) : null;
        $token = is_string($value) ? RegistrationToken::where('token', $value)->first() : null;
        return $token && $token->provider === 'wechat' ? $token : null;
    }

    public static function record(LoginProvider $binding, string $action, ?ServerRequestInterface $request = null): void
    {
        if ($binding->provider !== 'wechat') return;
        $ip = $request ? $request->getAttribute('ipAddress') : null;
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = $request ? ($request->getServerParams()['REMOTE_ADDR'] ?? null) : null;
        }
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) $ip = null;
        $actor = $request && $request->getAttribute('actorReference')
            ? RequestUtil::getActor($request) : ($request ? $request->getAttribute('actor') : null);
        $binding->getConnection()->table('wechat_binding_history')->insert([
            'user_id' => $binding->user_id,
            'actor_id' => $actor instanceof User && $actor->id ? $actor->id : null,
            'binding_id' => $binding->id,
            'provider' => $binding->provider,
            'identifier' => $binding->identifier,
            'action' => $action,
            'ip' => $ip,
            'bound_at' => $binding->created_at,
            'created_at' => $binding->freshTimestampString(),
        ]);
    }
}
