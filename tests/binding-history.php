<?php
// In-memory SQLite only. Never loads config.php or calls WeChat servers.
$root = dirname(__DIR__, 3);
$loader = require $root.'/vendor/autoload.php';
$loader->addPsr4('HamCQ\\WeChatAuth\\', dirname(__DIR__).'/src/');

use Flarum\Http\RequestUtil;
use Flarum\User\Command\RegisterUser;
use Flarum\User\Command\RegisterUserHandler;
use Flarum\User\Guest;
use Flarum\User\LoginProvider;
use Flarum\User\RegistrationToken;
use Flarum\User\User;
use HamCQ\WeChatAuth\Api\Controllers\WeChatUnlinkController;
use HamCQ\WeChatAuth\BindingHistory;
use HamCQ\WeChatAuth\Http\Controllers\WXRespFactoryController;
use Illuminate\Database\Schema\Blueprint;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$checks = 0;
function wxCheck($ok, string $message): void {
    ++$GLOBALS['checks'];
    if (!$ok) throw new RuntimeException($message);
}
function wxReject(callable $callback, string $type): void {
    try { $callback(); } catch (Throwable $error) {
        wxCheck($error instanceof $type, 'Expected '.$type.', got '.get_class($error).': '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: '.$type);
}
function wxDuring($request, callable $callback, int $status = 200) {
    return (new BindingHistory())->process($request, new class($callback, $status) implements RequestHandlerInterface {
        private $callback;
        private $status;
        public function __construct($callback, $status) { $this->callback = $callback; $this->status = $status; }
        public function handle(ServerRequestInterface $request): ResponseInterface {
            $result = ($this->callback)($request);
            return $result instanceof ResponseInterface ? $result : new EmptyResponse($this->status);
        }
    });
}
function wxUser(string $name): User {
    $user = new User(); $user->username = $name; $user->email = $name.'@example.test'; $user->save();
    return $user;
}
function wxSignup(string $identifier, string $username): array {
    $token = RegistrationToken::generate('wechat', $identifier, [], []); $token->save();
    $data = ['attributes' => ['username' => $username, 'email' => $username.'@example.test', 'token' => $token->token]];
    $request = RequestUtil::withActor((new ServerRequest(['REMOTE_ADDR' => '192.0.2.30']))->withMethod('POST'), new Guest())
        ->withAttribute('routeName', 'users.create')->withParsedBody(['data' => $data]);
    return [$request, $data, $token];
}

$container = new Illuminate\Container\Container(); Illuminate\Container\Container::setInstance($container);
$translator = new Flarum\Locale\Translator('en');
$container->instance('translator', $translator);
$container->instance(Illuminate\Contracts\Cache\Store::class, new Illuminate\Cache\ArrayStore());
$events = new Illuminate\Events\Dispatcher($container); $container->instance('events', $events);
$capsule = new Illuminate\Database\Capsule\Manager($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
$capsule->setEventDispatcher($events); $capsule->setAsGlobal(); $capsule->bootEloquent();
$db = $capsule->getConnection(); $schema = $db->getSchemaBuilder();
$db->getPdo()->sqliteCreateCollation('utf8mb4_bin', 'strcmp');
$schema->create('groups', function (Blueprint $table) { $table->increments('id'); });
$schema->create('users', function (Blueprint $table) {
    $table->increments('id'); $table->string('username')->unique(); $table->string('email')->unique();
    $table->string('password')->nullable(); $table->dateTime('joined_at')->nullable();
    $table->boolean('is_email_confirmed')->default(false); $table->text('preferences')->nullable();
});
$schema->create('login_providers', function (Blueprint $table) {
    $table->increments('id'); $table->unsignedInteger('user_id'); $table->string('provider', 100); $table->string('identifier', 100);
    $table->dateTime('created_at')->nullable(); $table->dateTime('last_login_at')->nullable(); $table->unique(['provider', 'identifier']);
});
$schema->create('registration_tokens', function (Blueprint $table) {
    $table->string('token', 40)->primary(); $table->string('provider'); $table->string('identifier');
    $table->text('user_attributes'); $table->text('payload'); $table->dateTime('created_at');
});
$legacy = wxUser('legacy'); $legacy->loginProviders()->create(['provider' => 'wechat', 'identifier' => 'legacy-union']);
$snapshot = $db->table('login_providers')->get()->toJson();
$migration = require dirname(__DIR__).'/migrations/2026_11_19_010000_create_wechat_binding_history.php';
$migration['up']($schema); $migration['up']($schema);
wxCheck($db->table('login_providers')->get()->toJson() === $snapshot && $db->table('wechat_binding_history')->count() === 0, 'Migration preserves active bindings and never fabricates old history');
$settings = new class implements Flarum\Settings\SettingsRepositoryInterface {
    public function all(): array { return ['allow_sign_up' => true]; }
    public function get($key, $default = null) { return $key === 'allow_sign_up' ? true : $default; }
    public function set($key, $value) {}
    public function delete($keyLike) {}
};
$container->instance(Flarum\Settings\SettingsRepositoryInterface::class, $settings);
User::setHasher(new Illuminate\Hashing\BcryptHasher(['rounds' => 4]));
$validator = new Illuminate\Validation\Factory($translator, $container);
$validator->setPresenceVerifier(new Illuminate\Validation\DatabasePresenceVerifier($capsule->getDatabaseManager()));
$userValidator = new class($validator, $translator) extends Flarum\User\UserValidator {
    protected function getClassExtensionId(): ?string { return null; }
};
$avatar = new class extends Flarum\User\AvatarUploader { public function __construct() {} };
$registrar = new RegisterUserHandler($events, $settings, $userValidator, $avatar, $validator, new Intervention\Image\ImageManager());
$factory = new class extends WXRespFactoryController {
    public ?int $loggedUser = null;
    public function __construct() {}
    public function makeLoggedInResponse(User $user, bool $isMobile = false, string $url = '') {
        $this->loggedUser = $user->id;
        return new HtmlResponse('logged-in');
    }
};

$user = wxUser('history');
$request = RequestUtil::withActor(new ServerRequest(['REMOTE_ADDR' => '192.0.2.10']), $user)
    ->withAttribute('ipAddress', '2001:db8::123')->withHeader('X-Forwarded-For', '198.51.100.99');
wxDuring($request, fn($request) => $factory->make('wechat', 'history-union', fn($registration) => $registration->provideTrustedEmail($user->email), false, '', $request));
$row = $db->table('wechat_binding_history')->where('user_id', $user->id)->first();
wxCheck($row->action === 'bind' && $row->identifier === 'history-union' && $row->binding_id, 'Successful bind records the WeChat identity and original binding row');
wxCheck($row->ip === '2001:db8::123' && (int) $row->actor_id === $user->id && $row->created_at && $row->bound_at, 'Records trusted IPv6, actor and timestamps');
$before = $db->table('wechat_binding_history')->count();
wxDuring($request, fn($request) => $factory->make('wechat', 'history-union', fn($registration) => null, false, '', $request));
wxCheck($factory->loggedUser === $user->id && $db->table('wechat_binding_history')->count() === $before, 'Existing WeChat login remains unchanged and adds no history');
$user->loginProviders()->create(['provider' => 'github', 'identifier' => 'other-login']);
wxCheck($db->table('wechat_binding_history')->count() === $before, 'Other login providers are not recorded');
$reply = wxDuring($request->withoutAttribute('ipAddress'), fn($request) => (new WeChatUnlinkController())->handle($request));
$row = $db->table('wechat_binding_history')->where('action', 'unbind')->first();
wxCheck($reply->getStatusCode() === 200 && $row->identifier === 'history-union' && $row->ip === '192.0.2.10', 'Real unlink endpoint deletes active binding but preserves its identity and IP');
wxCheck(!$user->loginProviders()->where('provider', 'wechat')->exists() && $user->loginProviders()->where('provider', 'github')->exists(), 'Unlink preserves other login providers');
$before = $db->table('wechat_binding_history')->count();
wxCheck(wxDuring($request, fn($request) => (new WeChatUnlinkController())->handle($request))->getStatusCode() === 400 && $db->table('wechat_binding_history')->count() === $before, 'Repeated unlink adds no false event');
wxReject(fn() => (new WeChatUnlinkController())->handle(RequestUtil::withActor(new ServerRequest(), new Guest())), Flarum\User\Exception\NotAuthenticatedException::class);
wxDuring($request, fn($request) => $factory->make('wechat', 'history-union', fn($registration) => $registration->provideTrustedEmail($user->email), false, '', $request));
wxCheck($db->table('wechat_binding_history')->where('user_id', $user->id)->where('action', 'bind')->count() === 2, 'Existing response-factory auto-link path appends a new history event');
$noIP = RequestUtil::withActor((new ServerRequest())->withHeader('X-Forwarded-For', '198.51.100.99'), $user);
$binding = $user->loginProviders()->create(['provider' => 'wechat', 'identifier' => 'no-trusted-ip']);
wxCheck(!$db->table('wechat_binding_history')->where('identifier', 'no-trusted-ip')->exists(), 'Raw model creation does not use any audit event listener');
BindingHistory::record($binding, 'bind', $noIP);
wxCheck($db->table('wechat_binding_history')->where('identifier', 'no-trusted-ip')->value('ip') === null, 'Forwarding headers alone cannot provide a trusted IP');
$binding = $user->loginProviders()->create(['provider' => 'wechat', 'identifier' => 'outside-request']);
BindingHistory::record($binding, 'bind');
wxCheck($db->table('wechat_binding_history')->where('identifier', 'outside-request')->value('ip') === null, 'Explicit log calls do not retain any request context');
wxDuring($request, function () use ($noIP, $user) {
    wxDuring($noIP, function ($request) use ($user) {
        $binding = $user->loginProviders()->create(['provider' => 'wechat', 'identifier' => 'nested-context']);
        BindingHistory::record($binding, 'bind', $request);
    });
    $binding = $user->loginProviders()->create(['provider' => 'wechat', 'identifier' => 'restored-context']);
    BindingHistory::record($binding, 'bind');
});
wxCheck($db->table('wechat_binding_history')->where('identifier', 'nested-context')->value('ip') === null && $db->table('wechat_binding_history')->where('identifier', 'restored-context')->value('ip') === null, 'Only an explicitly supplied request can determine the logged IP');

[$signupRequest, $signupData] = wxSignup('signup-union', 'signup');
wxDuring($signupRequest, fn() => $registrar->handle(new RegisterUser(new Guest(), $signupData)));
$row = $db->table('wechat_binding_history')->where('identifier', 'signup-union')->first();
wxCheck($row && $row->ip === '192.0.2.30' && $row->actor_id === null && (int) $row->user_id === User::where('username', 'signup')->value('id'), 'Core registration writes history with the final user ID and registration IP');
[$failedRequest, $failedData] = wxSignup('response-error-union', 'responseerror');
wxDuring($failedRequest, fn() => $registrar->handle(new RegisterUser(new Guest(), $failedData)), 422);
wxCheck(!User::where('username', 'responseerror')->exists() && !$db->table('wechat_binding_history')->where('identifier', 'response-error-union')->exists(), 'An error HTTP response rolls back registration and audit');
$schema->rename('wechat_binding_history', 'wechat_binding_history_unavailable');
$usersBefore = User::count();
[$failedRequest, $failedData, $failedToken] = wxSignup('failed-signup-union', 'failedsignup');
wxReject(fn() => wxDuring($failedRequest, fn() => $registrar->handle(new RegisterUser(new Guest(), $failedData))), Illuminate\Database\QueryException::class);
wxCheck(User::count() === $usersBefore && RegistrationToken::where('token', $failedToken->token)->exists() && !LoginProvider::where('identifier', 'failed-signup-union')->exists(), 'Audit failure rolls back new account, token consumption and binding');
wxReject(fn() => wxDuring($request, fn($request) => (new WeChatUnlinkController())->handle($request)), Illuminate\Database\QueryException::class);
wxCheck($user->loginProviders()->where('identifier', 'history-union')->exists(), 'Audit failure rolls back the real unlink endpoint');
$emailUser = wxUser('emailautolink');
wxReject(fn() => wxDuring($request, fn() => $factory->make('wechat', 'failed-auto-link', fn($registration) => $registration->provideTrustedEmail($emailUser->email))), Illuminate\Database\QueryException::class);
wxCheck(!$emailUser->loginProviders()->exists() && $db->transactionLevel() === 0, 'Response-factory link rolls back and restores transaction nesting');
$schema->rename('wechat_binding_history_unavailable', 'wechat_binding_history');
$binding = $user->loginProviders()->create(['provider' => 'wechat', 'identifier' => 'after-error-context']);
BindingHistory::record($binding, 'bind');
wxCheck($db->table('wechat_binding_history')->where('identifier', 'after-error-context')->value('ip') === null, 'Exception paths cannot leave an implicit request IP');
$count = $db->table('wechat_binding_history')->count();
$migration['down']($schema); $migration['up']($schema);
wxCheck($db->table('wechat_binding_history')->count() === $count, 'Uninstall and rerun preserve historical records');
$db->table('users')->where('id', $user->id)->delete();
wxCheck($db->table('wechat_binding_history')->where('user_id', $user->id)->exists(), 'History does not cascade-delete with a user');
echo "PASS: $checks WeChat binding, unlink, registration, IP and rollback checks.\n";
