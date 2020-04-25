<?php

/**
 * This file is part of CommuneChatbot.
 *
 * @link     https://github.com/thirdgerb/chatbot
 * @document https://github.com/thirdgerb/chatbot/blob/master/README.md
 * @contact  <thirdgerb@gmail.com>
 * @license  https://github.com/thirdgerb/chatbot/blob/master/LICENSE
 */

namespace Commune\Blueprint\Ghost\Operator;

use Commune\Blueprint\Ghost\Cloner;

/**
 * 运行多轮对话逻辑时的算子.
 *
 * @author thirdgerb <thirdgerb@gmail.com>
 */
interface Operator
{

    // public function getName() : string;

    public function invoke(Cloner $cloner) : ? Operator;
}