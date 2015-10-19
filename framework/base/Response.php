<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\base;

/**
 * Response represents the response of an [[Application]] to a [[Request]].
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class Response extends Component
{
    /**
     * @var integer the exit status. Exit statuses should be in the range 0 to 254.
     * The status 0 means the program terminates successfully.
     * 退出的状态码
     */
    public $exitStatus = 0;


    /**
     * Sends the response to client.
     */
    public function send()
    {
    }

    /**
     * Removes all existing output buffers.
     */
    public function clearOutputBuffers()
    {
        // the following manual level counting is to deal with zlib.output_compression set to On
        // ob_get_level — 返回输出缓冲机制的嵌套级别
        for ($level = ob_get_level(); $level > 0; --$level) {
            // ob_end_clean — 清空（擦除）缓冲区并关闭输出缓冲
            if (!@ob_end_clean()) {
                // ob_clean — 清空（擦掉）输出缓冲区
                // 此函数用来丢弃输出缓冲区中的内容。
                // 此函数不会销毁输出缓冲区，而像 ob_end_clean() 函数会销毁输出缓冲区。
                ob_clean();
            }
        }
    }
}
