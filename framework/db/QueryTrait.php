<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\db;

use yii\base\NotSupportedException;

/**
 * The BaseQuery trait represents the minimum method set of a database Query.
 *
 * It is supposed to be used in a class that implements the [[QueryInterface]].
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
trait QueryTrait
{
    /**
     * @var string|array query condition. This refers to the WHERE clause in a SQL statement.
     * For example, `['age' => 31, 'team' => 1]`.
     * @see where() for valid syntax on specifying this value.
     */
    public $where;
    /**
     * @var integer maximum number of records to be returned. If not set or less than 0, it means no limit.
     */
    public $limit;
    /**
     * @var integer zero-based offset from where the records are to be returned. If not set or
     * less than 0, it means starting from the beginning.
     */
    public $offset;
    /**
     * @var array how to sort the query results. This is used to construct the ORDER BY clause in a SQL statement.
     * The array keys are the columns to be sorted by, and the array values are the corresponding sort directions which
     * can be either [SORT_ASC](http://php.net/manual/en/array.constants.php#constant.sort-asc)
     * or [SORT_DESC](http://php.net/manual/en/array.constants.php#constant.sort-desc).
     * The array may also contain [[Expression]] objects. If that is the case, the expressions
     * will be converted into strings without any change.
     */
    public $orderBy;
    /**
     * @var string|callable $column the name of the column by which the query results should be indexed by.
     * This can also be a callable (e.g. anonymous function) that returns the index value based on the given
     * row data. For more details, see [[indexBy()]]. This property is only used by [[QueryInterface::all()|all()]].
     */
    public $indexBy;


    /**
     * Sets the [[indexBy]] property.
     * 设置 indexBy 属性，指定返回数组的 index 是哪一列
     * @param string|callable $column the name of the column by which the query results should be indexed by.
     * This can also be a callable (e.g. anonymous function) that returns the index value based on the given
     * row data. The signature of the callable should be:
     *
     * ~~~
     * function ($row)
     * {
     *     // return the index value corresponding to $row
     * }
     * ~~~
     *
     * @return static the query object itself.
     */
    public function indexBy($column)
    {
        $this->indexBy = $column;
        return $this;
    }

    /**
     * Sets the WHERE part of the query.
     * 设置 where 条件
     *
     * See [[QueryInterface::where()]] for detailed documentation.
     *
     * @param string|array $condition the conditions that should be put in the WHERE part.
     * @return static the query object itself.
     * @see andWhere()
     * @see orWhere()
     */
    public function where($condition)
    {
        $this->where = $condition;
        return $this;
    }

    /**
     * Adds an additional WHERE condition to the existing one.
     * 在 where 中添加 and 条件
     * The new condition and the existing one will be joined using the 'AND' operator.
     * @param string|array $condition the new WHERE condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @return static the query object itself.
     * @see where()
     * @see orWhere()
     */
    public function andWhere($condition)
    {
        if ($this->where === null) {
            // 之前没有设置过 where，直接赋值即可
            $this->where = $condition;
        } else {
            // 否则，用 'and' 标记两个 where 的关系
            $this->where = ['and', $this->where, $condition];
        }
        return $this;
    }

    /**
     * Adds an additional WHERE condition to the existing one.
     * 在 where 中添加 or 条件
     * The new condition and the existing one will be joined using the 'OR' operator.
     * @param string|array $condition the new WHERE condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @return static the query object itself.
     * @see where()
     * @see andWhere()
     */
    public function orWhere($condition)
    {
        if ($this->where === null) {
            // 之前没有设置过 where，直接赋值即可
            $this->where = $condition;
        } else {
            // 否则，用 'or' 标记两个 where 的关系
            $this->where = ['or', $this->where, $condition];
        }
        return $this;
    }

    /**
     * Sets the WHERE part of the query but ignores [[isEmpty()|empty operands]].
     * 设置 where 的条件，但会过掉为空的条件
     *
     * This method is similar to [[where()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * The following code shows the difference between this method and [[where()]]:
     *
     * ```php
     * // WHERE `age`=:age
     * $query->filterWhere(['name' => null, 'age' => 20]);
     * // WHERE `age`=:age
     * $query->where(['age' => 20]);
     * // WHERE `name` IS NULL AND `age`=:age
     * $query->where(['name' => null, 'age' => 20]);
     * ```
     *
     * Note that unlike [[where()]], you cannot pass binding parameters to this method.
     *
     * @param array $condition the conditions that should be put in the WHERE part.
     * See [[where()]] on how to specify this parameter.
     * @return static the query object itself.
     * @see where()
     * @see andFilterWhere()
     * @see orFilterWhere()
     */
    public function filterWhere(array $condition)
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->where($condition);
        }
        return $this;
    }

    /**
     * Adds an additional WHERE condition to the existing one but ignores [[isEmpty()|empty operands]].
     * The new condition and the existing one will be joined using the 'AND' operator.
     * 在 where 中添加 and 条件，但会过掉内容为空的条件
     *
     * This method is similar to [[andWhere()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * @param array $condition the new WHERE condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @return static the query object itself.
     * @see filterWhere()
     * @see orFilterWhere()
     */
    public function andFilterWhere(array $condition)
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->andWhere($condition);
        }
        return $this;
    }

    /**
     * Adds an additional WHERE condition to the existing one but ignores [[isEmpty()|empty operands]].
     * The new condition and the existing one will be joined using the 'OR' operator.
     * 在 where 中添加 or 条件，但会过掉内容为空的条件
     *
     * This method is similar to [[orWhere()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * @param array $condition the new WHERE condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @return static the query object itself.
     * @see filterWhere()
     * @see andFilterWhere()
     */
    public function orFilterWhere(array $condition)
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->orWhere($condition);
        }
        return $this;
    }

    /**
     * Removes [[isEmpty()|empty operands]] from the given query condition.
     * 移除为空的条件
     *
     * @param array $condition the original condition
     * @return array the condition with [[isEmpty()|empty operands]] removed.
     * @throws NotSupportedException if the condition operator is not supported
     */
    protected function filterCondition($condition)
    {
        if (!is_array($condition)) {
            // 不是数组，就直接返回
            // 所以在拼好的字符串中，即使存在 'name is NULL' 也无法过掉
            return $condition;
        }

        if (!isset($condition[0])) {
            // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
            foreach ($condition as $name => $value) {
                if ($this->isEmpty($value)) {
                    // 如果是空值，就直接去掉该条件，如 ['name' => null, 'age' => '']
                    unset($condition[$name]);
                }
            }
            return $condition;
        }

        // operator format: operator, operand 1, operand 2, ...
        // array_shift() 将 array 的第一个单元移出并作为结果返回
        // 存在数组第一个为操作的情况，取出相应的操作
        // 操作的值可以是 and/or/not/between/not between/like
        // 数组的值可以是 ['and', ['name' => 'harry'], ['or', ['age' => 10], ['child' => true]]]
        $operator = array_shift($condition);

        switch (strtoupper($operator)) {
            // 将操作转变成大写在比较，避免由于大小写的不同，引起不匹配
            case 'NOT':
            case 'AND':
            case 'OR':
                foreach ($condition as $i => $operand) {
                    // 如果是 and/or/not 操作，需要对它的条件再次去空，递归调用 filterCondition 方法
                    $subCondition = $this->filterCondition($operand);
                    if ($this->isEmpty($subCondition)) {
                        // 子条件为空，就移除
                        unset($condition[$i]);
                    } else {
                        // 不为空，就把递归处理过的条件重新存入
                        $condition[$i] = $subCondition;
                    }
                }

                if (empty($condition)) {
                    // 如果只有一个操作，返回空数组
                    return [];
                }
                break;
            case 'BETWEEN':
            case 'NOT BETWEEN':
                if (array_key_exists(1, $condition) && array_key_exists(2, $condition)) {
                    if ($this->isEmpty($condition[1]) || $this->isEmpty($condition[2])) {
                        // 如果 index 为 1，2 的值存在，而且有一个空值，就返回空数组
                        return [];
                    }
                }
                break;
            default:
                // 进入到这里的可能是 like
                if (array_key_exists(1, $condition) && $this->isEmpty($condition[1])) {
                    // 如果 index 为 1 的值存在，而且是个空值，就返回空数组
                    return [];
                }
        }

        // 将操作的标记插回到条件的数组中
        array_unshift($condition, $operator);

        return $condition;
    }

    /**
     * Returns a value indicating whether the give value is "empty".
     * 判断一个值是否为空，与 php 中的 empty 函数不同
     *
     * The value is considered "empty", if one of the following conditions is satisfied:
     *
     * - it is `null`,
     * - an empty string (`''`),
     * - a string containing only whitespace characters,
     * - or an empty array.
     *
     * @param mixed $value
     * @return boolean if the value is empty
     */
    protected function isEmpty($value)
    {
        // 空字符串/空数组/null/只有空格符，制表符，换行符，回车符，空字节符和垂直制表符的字符串
        //" " (ASCII 32 (0x20))，普通空格符。
        // "\t" (ASCII 9 (0x09))，制表符。
        // "\n" (ASCII 10 (0x0A))，换行符。
        // "\r" (ASCII 13 (0x0D))，回车符。
        // "\0" (ASCII 0 (0x00))，空字节符。
        // "\x0B" (ASCII 11 (0x0B))，垂直制表符。
        return $value === '' || $value === [] || $value === null || is_string($value) && trim($value) === '';
    }

    /**
     * Sets the ORDER BY part of the query.
     * 设置 order by 条件
     * @param string|array $columns the columns (and the directions) to be ordered by.
     * Columns can be specified in either a string (e.g. `"id ASC, name DESC"`) or an array
     * (e.g. `['id' => SORT_ASC, 'name' => SORT_DESC]`).
     * The method will automatically quote the column names unless a column contains some parenthesis
     * (which means the column contains a DB expression).
     * Note that if your order-by is an expression containing commas, you should always use an array
     * to represent the order-by information. Otherwise, the method will not be able to correctly determine
     * the order-by columns.
     * @return static the query object itself.
     * @see addOrderBy()
     */
    public function orderBy($columns)
    {
        // 格式化 $columns 中的数据
        $this->orderBy = $this->normalizeOrderBy($columns);
        return $this;
    }

    /**
     * Adds additional ORDER BY columns to the query.
     * 添加 order by 条件
     * @param string|array $columns the columns (and the directions) to be ordered by.
     * Columns can be specified in either a string (e.g. "id ASC, name DESC") or an array
     * (e.g. `['id' => SORT_ASC, 'name' => SORT_DESC]`).
     * The method will automatically quote the column names unless a column contains some parenthesis
     * (which means the column contains a DB expression).
     * @return static the query object itself.
     * @see orderBy()
     */
    public function addOrderBy($columns)
    {
        $columns = $this->normalizeOrderBy($columns);
        if ($this->orderBy === null) {
            // 之前没有设置过 orderBy，直接赋值即可
            $this->orderBy = $columns;
        } else {
            // 否则，就 merge 标记两个 orderBy 的数据，且以现在传入的为主
            $this->orderBy = array_merge($this->orderBy, $columns);
        }
        return $this;
    }

    /**
     * Normalizes format of ORDER BY data
     * 格式化 order by 的数据，支持的格式如 'name desc, age asc, number'
     * 如果没有定义是 desc 还是 asc，默认为 asc
     * @param array|string $columns
     * @return array
     */
    protected function normalizeOrderBy($columns)
    {
        if (is_array($columns)) {
            // 如果是数组，就直接返回
            // 因为其功能就是将字符串与数组格式的 order by 数据统一为数组的格式
            return $columns;
        } else {
            // 根据逗号以及周围的空格等符号分割 order by 数据，且分割出的空值不返回
            $columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
            $result = [];
            foreach ($columns as $column) {
                if (preg_match('/^(.*?)\s+(asc|desc)$/i', $column, $matches)) {
                    // strcasecmp — 二进制安全比较字符串（不区分大小写）
                    // 假设 $column 是 'name desc',正则匹配出是 $matches[1] 是 'name',$matches[2] 是 'desc'
                    $result[$matches[1]] = strcasecmp($matches[2], 'desc') ? SORT_ASC : SORT_DESC;
                } else {
                    // 默认为 asc，即 $column 是 'number'
                    $result[$column] = SORT_ASC;
                }
            }
            return $result;
        }
    }

    /**
     * Sets the LIMIT part of the query.
     * 设置 limit 条件
     * @param integer $limit the limit. Use null or negative value to disable limit.
     * @return static the query object itself.
     */
    public function limit($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Sets the OFFSET part of the query.
     * 设置 offset 条件
     * @param integer $offset the offset. Use null or negative value to disable offset.
     * @return static the query object itself.
     */
    public function offset($offset)
    {
        $this->offset = $offset;
        return $this;
    }
}
