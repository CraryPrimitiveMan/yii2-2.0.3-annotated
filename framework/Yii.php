<?php
/**
 * Yii bootstrap file.
 *
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

require(__DIR__ . '/BaseYii.php');

/**
 * Yii is a helper class serving common framework functionalities.
 *
 * It extends from [[\yii\BaseYii]] which provides the actual implementation.
 * By writing your own Yii class, you can customize some functionalities of [[\yii\BaseYii]].
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class Yii extends \yii\BaseYii
{
}

/**
 * spl_autoload_register — 注册给定的函数作为 __autoload 的实现
 *
 * bool spl_autoload_register ([ callable $autoload_function [, bool $throw = true [, bool $prepend = false ]]] )
 *
 * 将函数注册到SPL __autoload函数队列中。如果该队列中的函数尚未激活，则激活它们。
 * 如果在你的程序中已经实现了__autoload()函数，它必须显式注册到__autoload()队列中。
 * 因为 spl_autoload_register()函数会将Zend Engine中的__autoload()函数取代为spl_autoload()或spl_autoload_call()。
 * 如果需要多条 autoload 函数，spl_autoload_register() 满足了此类需求。
 * 它实际上创建了 autoload 函数的队列，按定义时的顺序逐个执行。
 * 相比之下， __autoload() 只可以定义一次。
 *
 * autoload_function
 * 欲注册的自动装载函数。如果没有提供任何参数，则自动注册 autoload 的默认实现函数spl_autoload()。
 *
 * throw
 * 此参数设置了 autoload_function 无法成功注册时， spl_autoload_register()是否抛出异常。
 *
 * prepend
 * 如果是 true，spl_autoload_register() 会添加函数到队列之首，而不是队列尾部。
 *
 * Yii 注册了 Yii 的 autoload 函数，实现自动加载, 其实现在 \yii\BaseYii 中
 */
spl_autoload_register(['Yii', 'autoload'], true, true);
// 定义 Yii 核心的 class 的类名与文件地址的 Map
Yii::$classMap = require(__DIR__ . '/classes.php');
// 创建 Yii 的依赖注入的容器
Yii::$container = new yii\di\Container();
