<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\base;

/**
 * Event is the base class for all event classes.
 *
 * It encapsulates the parameters associated with an event.
 * The [[sender]] property describes who raises the event.
 * And the [[handled]] property indicates if the event is handled.
 * If an event handler sets [[handled]] to be true, the rest of the
 * uninvoked handlers will no longer be called to handle the event.
 *
 * Additionally, when attaching an event handler, extra data may be passed
 * and be available via the [[data]] property when the event handler is invoked.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class Event extends Object
{
    /**
     * @var string the event name. This property is set by [[Component::trigger()]] and [[trigger()]].
     * Event handlers may use this property to check what event it is handling.
     * 事件的名字
     */
    public $name;
    /**
     * @var object the sender of this event. If not set, this property will be
     * set as the object whose "trigger()" method is called.
     * This property may also be a `null` when this event is a
     * class-level event which is triggered in a static context.
     * 触发事件的对象
     */
    public $sender;
    /**
     * @var boolean whether the event is handled. Defaults to false.
     * When a handler sets this to be true, the event processing will stop and
     * ignore the rest of the uninvoked event handlers.
     * 记录事件是否已被处理，当 handled 被设置为 true 时，执行到这个 event 的时候，会停止，并忽略剩下的 event
     */
    public $handled = false;
    /**
     * @var mixed the data that is passed to [[Component::on()]] when attaching an event handler.
     * Note that this varies according to which event handler is currently executing.
     */
    public $data;

    /**
     * 存储所有的 event，因为是 static 的属性，所有的 event 对象/类都共享这一份数据
     */
    private static $_events = [];


    /**
     * Attaches an event handler to a class-level event.
     *
     * When a class-level event is triggered, event handlers attached
     * to that class and all parent classes will be invoked.
     *
     * For example, the following code attaches an event handler to `ActiveRecord`'s
     * `afterInsert` event:
     *
     * ~~~
     * Event::on(ActiveRecord::className(), ActiveRecord::EVENT_AFTER_INSERT, function ($event) {
     *     Yii::trace(get_class($event->sender) . ' is inserted.');
     * });
     * ~~~
     *
     * The handler will be invoked for EVERY successful ActiveRecord insertion.
     *
     * For more details about how to declare an event handler, please refer to [[Component::on()]].
     *
     * 为一个类添加事件
     *
     * @param string $class the fully qualified class name to which the event handler needs to attach.
     * @param string $name the event name.
     * @param callable $handler the event handler.
     * @param mixed $data the data to be passed to the event handler when the event is triggered.
     * When the event handler is invoked, this data can be accessed via [[Event::data]].
     * @param boolean $append whether to append new event handler to the end of the existing
     * handler list. If false, the new handler will be inserted at the beginning of the existing
     * handler list.
     * @see off()
     */
    public static function on($class, $name, $handler, $data = null, $append = true)
    {
        // 去掉 class 最左边的斜杠
        $class = ltrim($class, '\\');
        // 如果 append 为true，就放到 $_events 中名字为 $name 的数组的最后，否则放到最前面
        if ($append || empty(self::$_events[$name][$class])) {
            self::$_events[$name][$class][] = [$handler, $data];
        } else {
            array_unshift(self::$_events[$name][$class], [$handler, $data]);
        }
    }

    /**
     * Detaches an event handler from a class-level event.
     *
     * This method is the opposite of [[on()]].
     *
     * 移除一个类的事件
     *
     * @param string $class the fully qualified class name from which the event handler needs to be detached.
     * @param string $name the event name.
     * @param callable $handler the event handler to be removed.
     * If it is null, all handlers attached to the named event will be removed.
     * @return boolean whether a handler is found and detached.
     * @see on()
     */
    public static function off($class, $name, $handler = null)
    {
        $class = ltrim($class, '\\');
        if (empty(self::$_events[$name][$class])) {
            // 不存在该事件
            return false;
        }
        if ($handler === null) {
            // 如果 handler 为空，直接将在该类下该事件移除，即移出所有的是这个名字的事件
            unset(self::$_events[$name][$class]);
            return true;
        } else {
            $removed = false;
            // 如果 $handler 不为空，循环 $_events 找到相应的 handler，只移除这个 handler 和 data 组成的数组
            foreach (self::$_events[$name][$class] as $i => $event) {
                if ($event[0] === $handler) {
                    unset(self::$_events[$name][$class][$i]);
                    $removed = true;
                }
            }
            if ($removed) {
                // 移除之后，使数组重新变成一个自然数组
                self::$_events[$name][$class] = array_values(self::$_events[$name][$class]);
            }

            return $removed;
        }
    }

    /**
     * Returns a value indicating whether there is any handler attached to the specified class-level event.
     * Note that this method will also check all parent classes to see if there is any handler attached
     * to the named event.
     * 检测在某个类或者对象是否具有某个事件
     * @param string|object $class the object or the fully qualified class name specifying the class-level event.
     * @param string $name the event name.
     * @return boolean whether there is any handler attached to the event.
     */
    public static function hasHandlers($class, $name)
    {
        if (empty(self::$_events[$name])) {
            // 不存在，直接返回
            return false;
        }
        if (is_object($class)) {
            // 如果是一个 object，就获取其类名
            $class = get_class($class);
        } else {
            // 如果是一个类名，就去掉 class 最左边的斜杠
            $class = ltrim($class, '\\');
        }
        // 如果该类中找不到，就去父类中找，直到找到或者没有父类了为止
        do {
            if (!empty(self::$_events[$name][$class])) {
                return true;
            }
        } while (($class = get_parent_class($class)) !== false);

        return false;
    }

    /**
     * Triggers a class-level event.
     * This method will cause invocation of event handlers that are attached to the named event
     * for the specified class and all its parent classes.
     * 触发某个类或者对象的某个事件
     * @param string|object $class the object or the fully qualified class name specifying the class-level event.
     * @param string $name the event name.
     * @param Event $event the event parameter. If not set, a default [[Event]] object will be created.
     */
    public static function trigger($class, $name, $event = null)
    {
        if (empty(self::$_events[$name])) {
            return;
        }
        if ($event === null) {
            // 事件不存在，就创建一个 Event 对象
            $event = new static;
        }
        // 设置event对象的属性，默认是未被处理的
        $event->handled = false;
        $event->name = $name;

        if (is_object($class)) {
            if ($event->sender === null) {
                // 如果 $class 是个对象，并且是 sender 为空，就将 $class 赋给 sender，即 $class 就是触发事件的对象
                $event->sender = $class;
            }
            $class = get_class($class);
        } else {
            $class = ltrim($class, '\\');
        }
        // 循环类的 $_event，直到遇到 $event->handled 为真或者没有父类了为止
        do {
            if (!empty(self::$_events[$name][$class])) {
                foreach (self::$_events[$name][$class] as $handler) {
                    // 将参数赋到 event 对象的 data 属性上
                    $event->data = $handler[1];
                    // 调用 $handler 方法
                    // 在方法中，可以用 $this->data 取到相应的参数
                    // 也可以在其中设置 $this->handled 的值，中断后续事件的触发
                    call_user_func($handler[0], $event);
                    // 当某个 handled 被设置为 true 时，执行到这个事件的时候，会停止，并忽略剩下的事件
                    if ($event->handled) {
                        return;
                    }
                }
            }
        } while (($class = get_parent_class($class)) !== false);
    }
}
