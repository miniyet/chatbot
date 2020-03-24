<?php

/**
 * This file is part of CommuneChatbot.
 *
 * @link     https://github.com/thirdgerb/chatbot
 * @document https://github.com/thirdgerb/chatbot/blob/master/README.md
 * @contact  <thirdgerb@gmail.com>
 * @license  https://github.com/thirdgerb/chatbot/blob/master/LICENSE
 */

namespace Commune\Ghost\Blueprint\Mind;
use Commune\Shell\Blueprint\Session\ShlSession;


/**
 * 一个可以被命中的意图的定义. 通常从属于 StageDef.
 * 与 NLU 定义的意图不同, 这里的 IntentDef 可用于各种内部判断逻辑.
 *
 * @author thirdgerb <thirdgerb@gmail.com>
 */
interface IntentDef
{

    /**
     * 意图名称. 通过 NLU 来命中
     *
     * @return string
     */
    public function intentName() : string;

    /**
     * 意图对应的命令.
     *
     * @return CommandDef|null
     */
    public function commandDef() : ? CommandDef;

    /**
     * 正则的匹配规则.
     * @return RegexDef|null
     */
    public function regexDef() : ? RegexDef;

    /*--------- 关联 ---------*/

    /**
     * 对应的 Stage
     * @return StageDef
     */
    public function getStageDef() : StageDef;

    /*--------- 操作 ---------*/

    public function validate(ShlSession $session) : bool;
}