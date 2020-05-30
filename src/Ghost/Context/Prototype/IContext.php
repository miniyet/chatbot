<?php

/**
 * This file is part of CommuneChatbot.
 *
 * @link     https://github.com/thirdgerb/chatbot
 * @document https://github.com/thirdgerb/chatbot/blob/master/README.md
 * @contact  <thirdgerb@gmail.com>
 * @license  https://github.com/thirdgerb/chatbot/blob/master/LICENSE
 */

namespace Commune\Ghost\Context\Prototype;

use Commune\Blueprint\Ghost\Cloner;
use Commune\Blueprint\Ghost\Cloner\ClonerInstanceStub;
use Commune\Blueprint\Ghost\Context;
use Commune\Blueprint\Ghost\Memory\Recollection;
use Commune\Blueprint\Ghost\MindDef\ContextDef;
use Commune\Blueprint\Ghost\Runtime\Task;
use Commune\Blueprint\Ghost\Ucl;
use Commune\Message\Host\Convo\IContextMsg;
use Commune\Protocals\HostMsg\Convo\ContextMsg;
use Commune\Support\Arr\ArrayAbleToJson;
use Commune\Support\Arr\TArrayAccessToMutator;
use Commune\Blueprint\Exceptions\HostLogicException;
use Commune\Support\DI\TInjectable;
use Illuminate\Support\Collection;

/**
 * 上下文语境的默认.
 * 持有 Context 的上下文记忆 Memory, 用于读/写真正的数据.
 *
 *
 * 通常不是 New 出来, 而是 Cloner::findContext() 或者 Cloner::newContext() 来获取
 * @see Cloner
 *
 * 最终通过 ContextDef::wrapContext() 完成包装.
 *
 * @author thirdgerb <thirdgerb@gmail.com>
 */
class IContext implements Context
{
    use TInjectable, ArrayAbleToJson, TArrayAccessToMutator;

    /**
     * @var Ucl
     */
    protected $_ucl;

    /**
     * @var Cloner
     */
    protected $_cloner;

    /**
     * @var Recollection|null
     */
    protected $_recollection;

    /**
     * @var ContextDef|null
     */
    protected $_def;

    /**
     * @var Collection|null
     */
    protected $_query;

    /**
     * @var Task|null
     */
    protected $_task;

    /**
     * IContext constructor.
     * @param Ucl $ucl
     * @param Cloner $cloner
     */
    public function __construct(
        Ucl $ucl,
        Cloner $cloner
    )
    {
        $this->_ucl = $ucl;
        $this->_cloner = $cloner;
    }

    public function toInstanceStub(): ClonerInstanceStub
    {
        return new ContextStub($this->_ucl->toEncodedStr());
    }

    public function toUcl(): Ucl
    {
        return $this->getTask()->getUcl();
    }
    /*----- property -----*/


    public function getDef(): ContextDef
    {
        return $this->_def
            ?? $this->_def = $this->_ucl->findContextDef($this->_cloner);
    }

    public function getId(): string
    {
        return $this->_ucl->getContextId();
    }

    public function getName(): string
    {
        return $this->_ucl->contextName;
    }

    public function getPriority(): int
    {
        return $this->getDef()->getPriority();
    }

    public function getQuery(): Collection
    {
        return $this->_query
            ?? $this->_query = new Collection($this->_ucl->query);
    }

    public function getCloner(): Cloner
    {
        return $this->_cloner;
    }

    public function getTask(): Task
    {
        return $this->_task
            ?? $this->_task = $this->_cloner
                ->runtime
                ->getCurrentProcess()
                ->getTask($this->_ucl);
    }


    /*----- entities -----*/

    public function dependEntity(): ? string /* entityName */
    {
        $entities = $this
            ->getDef()
            ->getEntityNames();

        foreach ($entities as $name) {
            if (!$this->offsetExists($name)) {
                return $name;
            }
        }

        return null;
    }

    public function isPrepared(): bool
    {
        $depending = $this->dependEntity();
        return is_null($depending);
    }


    /*----- memory -----*/

    protected function getRecollection() : Recollection
    {
        return $this->_recollection
            ?? $this->_recollection = $this
                ->getDef()
                ->asMemoryDef()
                ->recall($this->_cloner, $this->_ucl->getContextId());

    }

    /*----- ArrayAccess -----*/

    public function toArray(): array
    {
        $data = $this->getQuery()->toArray();
        $data = $data + $this->getRecollection()->toArray();

        return $data;
    }

    public function toData(): array
    {
        return $this->getRecollection()->toData();
    }

    public function merge(array $data): void
    {
        foreach ($data as $key => $val) {
            $this->offsetSet($key, $val);
        }
    }


    public function toContextMsg(): ContextMsg
    {
        return new IContextMsg([
            'contextName' => $this->_ucl->contextName,
            'contextId' => $this->_ucl->getContextId(),
            'query' => $this->_ucl->query,
            'data' => $this->toData(),
        ]);
    }

    public function getIterator()
    {
        $def = $this->getDef();

        $names = $def->getParamsDefaults()->keys();

        foreach ($names as $name) {
            yield $this->offsetGet($name);
        }
    }


    /*----- ArrayAccess -----*/

    public function offsetExists($offset)
    {
        $collection = $this->getDef()->getParamsDefaults();

        if ($collection->hasParam($offset)) {
            return true;
        }

        $value = $this->offsetGet($offset);
        return isset($value);
    }

    public function offsetGet($offset)
    {
        $def = $this->getDef();
        $queries = $def->getQueryDefaults();

        if($queries->hasParam($offset)) {
            return $this->getQuery()[$offset] ?? null;
        }

        return $this->getRecollection()->offsetGet($offset);
    }

    public function offsetSet($offset, $value)
    {
        $def = $this->getDef();
        $queries = $def->getQueryDefaults();

        if ($queries->hasParam($offset)) {
            $contextName = $this->getName();
            $error = "context $contextName try to set value for query parameter $offset";
            $this->warningOrException($error);
            return;
        }

        $this->getRecollection()->offsetSet($offset, $value);
    }

    public function offsetUnset($offset)
    {
        $queries = $this->getDef()->getQueryDefaults();

        if ($queries->hasParam($offset)) {
            $contextName = $this->getName();
            $error = "context $contextName try to unset value for query parameter $offset";
            $this->warningOrException($error);
            return;
        }

        $this->getRecollection()->offsetUnset($offset);
        return;
    }

    protected function warningOrException(string $error)
    {
        if ($this->_cloner->isDebugging()) {
            $this->_cloner->logger->warning($error);
        } else {
            throw new HostLogicException($error);
        }
    }

    /*----- injectable -----*/

    public function getInterfaces(): array
    {
        return static::getInterfacesOf(Context::class);
    }

    public function __destruct()
    {
        $this->_def = null;
        $this->_query = null;
        $this->_ucl = null;
        $this->_cloner = null;
        $this->_task = null;
    }
}