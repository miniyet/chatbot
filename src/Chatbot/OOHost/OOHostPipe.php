<?php


namespace Commune\Chatbot\OOHost;


use Closure;
use Commune\Chatbot\App\Messages\System\MissedReply;
use Commune\Chatbot\App\Messages\System\QuitSessionReply;
use Commune\Chatbot\Blueprint\Conversation\Conversation;
use Commune\Chatbot\Config\Children\OOHostConfig;
use Commune\Chatbot\Config\ChatbotConfig;
use Commune\Chatbot\Contracts\CacheAdapter;
use Commune\Chatbot\Framework\Pipeline\ChatbotPipeImpl;
use Commune\Chatbot\Framework\Utils\OnionPipeline;
use Commune\Chatbot\OOHost\Session\Session;
use Commune\Support\Uuid\HasIdGenerator;
use Commune\Support\Uuid\IdGeneratorHelper;

/**
 * 面向对象的 host 机器人提供的chatbot pipe
 */
class OOHostPipe extends ChatbotPipeImpl implements HasIdGenerator
{
    use IdGeneratorHelper;

    const SESSION_ID_KEY = 'chatbot:sessionId:%s';

    /**
     * @var OOHostConfig
     */
    public $hostConfig;

    /**
     * @var ChatbotConfig
     */
    public $chatbotConfig;

    /**
     * @var CacheAdapter
     */
    public $cache;

    public function __construct(CacheAdapter $cache, ChatbotConfig $config)
    {
        $this->cache = $cache;
        $this->chatbotConfig = $config;
        $this->hostConfig = $config->host;
    }


    public function handleUserMessage(Conversation $conversation, Closure $next): Conversation
    {
        $belongsTo = $this->fetchBelongsTo($conversation);

        $sessionId = $this->fetchSessionId($belongsTo);

        // 生成 session
        $session = $this->makeSession($sessionId, $conversation);

        // 运行管道.
        $session = $this->callSession($session);

        $session->finish();

        // should close client by event
        if ($session->isQuiting()) {
            $conversation->reply(new QuitSessionReply());
            $this->forgetSession($belongsTo);
            return $conversation;
        }

        // 当前 session 没有搞定, 就继续往下走.
        if (!$session->isHandled()) {
            $conversation = $next($conversation);
        }

        $replies = $conversation->getReplies();
        if (empty($replies)) {
            $conversation->reply(new MissedReply());
        }

        return $conversation;

    }


    public function fetchBelongsTo(Conversation $conversation) : string
    {
        // 根据 scene 来做差异化. 只要输入有 scene, 就会拥有独立的 session
        $scene = $conversation->getRequest()->getScene();
        $sceneStr = isset($scene) ? "::$scene" : '';

        // 用 sessionId 来唤醒一个session.
        return ($conversation->getIncomingMessage()->getSessionId()
            ?? $conversation->getChat()->getChatId()) . $sceneStr;
    }

    /**
     * 从缓存里读取 belongsTo 背后存储的 sessionId
     *
     * @param string $belongsTo
     * @return string
     */
    public function fetchSessionId(string $belongsTo) : string
    {
        $key = sprintf(self::SESSION_ID_KEY, $belongsTo);

        $sessionId = $this->cache->get($key);
        if (empty($sessionId)) {
            $sessionId = $this->createUuId();
            $this->cache->set($key, $sessionId, $this->hostConfig->sessionExpireSeconds);
        }

        return $sessionId;
    }

    public function forgetSession(string $belongsTo) : void
    {
        $key = sprintf(self::SESSION_ID_KEY, $belongsTo);
        $this->cache->forget($key);
    }

    public function callSession(Session $session) : Session
    {
        if (empty($this->hostConfig->sessionPipes)) {
            return $this->doCallSession($session);

        // 还是要走管道.
        } else {
            $pipeline = new OnionPipeline(
                $session->conversation,
                $this->hostConfig->sessionPipes
            );

            return $pipeline
                ->via('handle')
                ->send(
                    $session,
                    $this->getDestination()
                );
        }
    }

    public function doCallSession(Session $session) : Session
    {
        $session->handle($session->incomingMessage->message);
        return $session;
    }

    
    public function getDestination() : Closure
    {
        return function(Session $session) {
            return $this->doCallSession($session);
        };
    }

    public function makeSession(
        string $sessionId,
        Conversation $conversation
    ) : Session
    {
        return $conversation->make(
            Session::class,
            [

                Session::SESSION_ID_VAR => $sessionId
            ]
        );

    }

    public function onException(Conversation $conversation, \Throwable $e): void
    {
    }


}