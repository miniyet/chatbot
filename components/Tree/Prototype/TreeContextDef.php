<?php

/**
 * This file is part of CommuneChatbot.
 *
 * @link     https://github.com/thirdgerb/chatbot
 * @document https://github.com/thirdgerb/chatbot/blob/master/README.md
 * @contact  <thirdgerb@gmail.com>
 * @license  https://github.com/thirdgerb/chatbot/blob/master/LICENSE
 */

namespace Commune\Components\Tree\Prototype;

use Commune\Blueprint\Ghost\Dialog;
use Commune\Blueprint\Ghost\MindDef\ContextDef;
use Commune\Blueprint\Ghost\MindDef\ContextStrategyOption;
use Commune\Blueprint\Ghost\MindDef\StageDef;
use Commune\Blueprint\Ghost\MindMeta\IntentMeta;
use Commune\Blueprint\Ghost\MindMeta\StageMeta;
use Commune\Blueprint\Ghost\Operate\Operator;
use Commune\Blueprint\Ghost\Ucl;
use Commune\Ghost\Context\Traits\ContextDefTrait;
use Commune\Ghost\Support\ContextUtils;
use Commune\Support\ArrTree\Branch;
use Commune\Support\ArrTree\Tree;
use Commune\Support\Option\AbsOption;


/**
 * @author thirdgerb <thirdgerb@gmail.com>
 *
 * @property-read string $name
 * @property-read string $title
 * @property-read string $desc
 * @property-read int $priority
 *
 *
 * ## stage 相关定义.
 * @property-read array $tree
 * @property-read bool $appendingBranch
 * @property-read string[] $stageEvents
 * @property-read string|null $relativeOption
 * @property-read StageMeta[] $stages
 *
 * ## 属性定义
 * @property-read array $dependingNames
 * @property-read string[] $memoryScopes
 * @property-read array $memoryAttrs
 * @property-read ContextStrategyOption $strategy
 *
 * ## 意图定义
 * @property-read IntentMeta|null $asIntent
 *
 * ## warpper
 * @property-read string $contextWrapper
 */
class TreeContextDef extends AbsOption implements ContextDef
{
    const IDENTITY = 'name';

    const FIRST_STAGE = 'root';
    const CANCEL_STAGE = 'cancel';

    use ContextDefTrait;

    /**
     * @var array|null
     */
    protected $_stageMap;

    public static function stub(): array
    {
        return [


            /*----- 核心参数 -----*/

            // context 的全名. 同时也是意图名称.
            'name' => '',
            // context 的标题. 可以用于 精确意图校验.
            'title' => '',
            // context 的简介. 通常用于 askChoose 的选项.
            'desc' => '',
            // context 的优先级. 若干个语境在 blocking 状态中, 根据优先级决定谁先恢复.
            'priority' => 0,

            // context 的默认参数名, 类似 url 的 query 参数.
            // query 参数值默认是字符串.
            // query 参数如果是数组, 则定义参数名时应该用 [] 做后缀, 例如 ['key1', 'key2', 'key3[]']
            'queryNames' => [],

            // 用一棵树来定义多轮对话结构.
            // 每一个节点都会成为一个 stage.
            // 该 stage 的响应策略则通过 Events 的方式来定义, 实现解耦.
            'tree' => [],
            // 树每个分支节点所使用的 Event
            'stageEvents' => [],
            // 树的节点是否通过 "." 符号连接成 stage_name
            // 例如: [ 'a' => 'b' ] 中的 b 节点, 名字是否为 a.b
            'appendingBranch' => false,
            // 关联配置类型.
            // 如果存在, 可以到 OptRegistry 通过 stageName 获取相关配置.
            'relativeOption' => null,


            // 通过 StageMeta, 而不是 tree 来定义的 stage 组件.
            'stages' => [],


            /*---- 以下为可选参数 ----*/

            // Context 启动时, 会依次检查的参数. 当这些参数都不是 null 时, 认为 Context::isPrepared
            'dependingNames' => [],

            'asIntent' => null,

            // 定义 context 上下文记忆的作用域.
            // 相关作用域参数, 会自动添加到 query 参数中.
            // 作用域为空, 则是一个 session 级别的短程记忆.
            // 不为空, 则是长程记忆, 会持久化保存.
            'memoryScopes' => [],
            // memory 记忆体的默认值.
            'memoryAttrs' => [],

            'strategy' => [
                'onCancel' => static::CANCEL_STAGE
            ],

            // context 实例的封装类.
            'contextWrapper' => '',
        ];
    }

    public static function relations(): array
    {
        return [
            'stages[]' => StageMeta::class,
            'asIntent' => IntentMeta::class,
            'strategy' => ContextStrategyOption::class,
        ];
    }

    /*--------  stage builder --------*/

    public function firstStage(): ? string
    {
        return static::FIRST_STAGE;
    }

    public function eachPredefinedStage(): \Generator
    {
        $map = $this->getPredefinedStageMap();
        foreach ($map as $stage) {
            yield $stage;
        }
    }

    public function getPredefinedStageMap() : array
    {
        if (isset($this->_stageMap)) {
            return $this->_stageMap;
        }

        $data = $this->tree;
        $tree = new Tree();
        $append = $this->appendingBranch ? '_' : '';

        $tree->build($data, static::FIRST_STAGE, $append);
        new Branch($tree, static::CANCEL_STAGE);

        // 初始化预定义的
        $this->_stageMap = [];
        foreach ($this->stages as $stageMeta) {
            $def = $stageMeta->toWrapper();
            $this->_stageMap[$def->getStageShortName()] = $def;
        }

        foreach ($tree->branches as $branch) {
           $stage = $this->buildStage($branch);
           $this->_stageMap[$stage->getStageShortName()] = $stage;
        }

        return $this->_stageMap;
    }

    protected function getFullStageName(?Branch $branch) : ? string
    {
        if (!isset($branch)) {
            return null;
        }
        return ContextUtils::makeFullStageName(
            $this->name,
            $branch->name
        );
    }

    protected function buildStage(Branch $branch) : BranchStageDef
    {
        $fullName = $this->getFullStageName($branch);
        $children = array_map(function(Branch $branch) {
            return $this->getFullStageName($branch);
        }, $branch->children);

        $stage = new BranchStageDef([
            'name' => $fullName,
            'title' => $fullName,
            'desc' => $fullName,
            'contextName' => $this->name,
            'stageName' => $branch->name,

            // 爹妈
            'parent' => $this->getFullStageName($branch->parent),
            // 儿女
            'children' => $children,
            // 哥哥姐姐
            'elder' => $this->getFullStageName($branch->elder),
            // 弟弟妹妹
            'younger' => $this->getFullStageName($branch->younger),
            'events' => $this->stageEvents,
            'asIntent' => null,
            'ifRedirect' => null,
        ]);
        return $stage;
    }

    public function getPredefinedStage(string $name): ? StageDef
    {
        return $this->getPredefinedStageMap()[$name] ?? null;
    }


    /*-------- 更多默认属性 --------*/

    public function getDependingNames(): array
    {
        return $this->dependingNames;
    }

    /*-------- asStage --------*/

    public function onRedirect(Dialog $prev, Ucl $current): ? Operator
    {
        return null;
    }

    /*-------- common routes --------*/


    public function __destruct()
    {
        unset($this->_stageMap);
        parent::__destruct();
    }

}