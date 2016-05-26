<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\redis;

use yii\base\InvalidParamException;
use yii\base\NotSupportedException;
use yii\db\Exception;
use yii\db\Expression;

/**
 * LuaScriptBuilder builds lua scripts used for retrieving data from redis.
 * 使用 List 存储所有的主键
 * 使用 Hash 存储真正的数据，key 是主键
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
class LuaScriptBuilder extends \yii\base\Object
{
    /**
     * Builds a Lua script for finding a list of records
     * @param ActiveQuery $query the query used to build the script
     * @return string
     */
    public function buildAll($query)
    {
        // TODO add support for orderBy
        /* @var $modelClass ActiveRecord */
        $modelClass = $query->modelClass;
        $key = $this->quoteValue($modelClass::keyPrefix() . ':a:');

        // lua 中 .. 表示连接字符串
        // HGETALL -- 返回 key 指定的哈希集中所有的字段和值。返回值中，每个字段名的下一个是它的值，所以返回值的长度是哈希集大小的两倍
        // n 自加 1，pks 数组获得当前 key（主键） 的值，返回所有值即 pks 数组
        return $this->build($query, "n=n+1 pks[n]=redis.call('HGETALL',$key .. pk)", 'pks');
    }

    /**
     * Builds a Lua script for finding one record
     * @param ActiveQuery $query the query used to build the script
     * @return string
     */
    public function buildOne($query)
    {
        // TODO add support for orderBy
        /* @var $modelClass ActiveRecord */
        $modelClass = $query->modelClass;
        $key = $this->quoteValue($modelClass::keyPrefix() . ':a:');

        // 取到第一个直接 return，否则最终返回 pks，是一个空数组
        return $this->build($query, "do return redis.call('HGETALL',$key .. pk) end", 'pks');
    }

    /**
     * Builds a Lua script for finding a column
     * @param ActiveQuery $query the query used to build the script
     * @param string $column name of the column
     * @return string
     */
    public function buildColumn($query, $column)
    {
        // TODO add support for orderBy and indexBy
        /* @var $modelClass ActiveRecord */
        $modelClass = $query->modelClass;
        $key = $this->quoteValue($modelClass::keyPrefix() . ':a:');

        // 返回哈希表 key 中给定域 field 的值
        // n 自加 1，pks 数组获得当前 key（主键） 中 $column 的值（只选出需要的这一列），返回 pks 数组
        return $this->build($query, "n=n+1 pks[n]=redis.call('HGET',$key .. pk," . $this->quoteValue($column) . ")", 'pks');
    }

    /**
     * Builds a Lua script for getting count of records
     * @param ActiveQuery $query the query used to build the script
     * @return string
     */
    public function buildCount($query)
    {
        // n 自加 1，去计数，返回总数 n
        return $this->build($query, 'n=n+1', 'n');
    }

    /**
     * Builds a Lua script for finding the sum of a column
     * @param ActiveQuery $query the query used to build the script
     * @param string $column name of the column
     * @return string
     */
    public function buildSum($query, $column)
    {
        /* @var $modelClass ActiveRecord */
        $modelClass = $query->modelClass;
        $key = $this->quoteValue($modelClass::keyPrefix() . ':a:');

        // n 用来计算和，n 加上 key（主键） 中 $column 的值（只选出需要的这一列），返回总和 n
        return $this->build($query, "n=n+redis.call('HGET',$key .. pk," . $this->quoteValue($column) . ")", 'n');
    }

    /**
     * Builds a Lua script for finding the average of a column
     * @param ActiveQuery $query the query used to build the script
     * @param string $column name of the column
     * @return string
     */
    public function buildAverage($query, $column)
    {
        /* @var $modelClass ActiveRecord */
        $modelClass = $query->modelClass;
        $key = $this->quoteValue($modelClass::keyPrefix() . ':a:');

        // 求平均值，n 用来记总数，v 用来记和，最后放回 v/n
        return $this->build($query, "n=n+1 if v==nil then v=0 end v=v+redis.call('HGET',$key .. pk," . $this->quoteValue($column) . ")", 'v/n');
    }

    /**
     * Builds a Lua script for finding the min value of a column
     * @param ActiveQuery $query the query used to build the script
     * @param string $column name of the column
     * @return string
     */
    public function buildMin($query, $column)
    {
        /* @var $modelClass ActiveRecord */
        $modelClass = $query->modelClass;
        $key = $this->quoteValue($modelClass::keyPrefix() . ':a:');

        // 求最小值，n 用来记当前值，v 用来记最小值，最后放回 v
        return $this->build($query, "n=redis.call('HGET',$key .. pk," . $this->quoteValue($column) . ") if v==nil or n<v then v=n end", 'v');
    }

    /**
     * Builds a Lua script for finding the max value of a column
     * @param ActiveQuery $query the query used to build the script
     * @param string $column name of the column
     * @return string
     */
    public function buildMax($query, $column)
    {
        /* @var $modelClass ActiveRecord */
        $modelClass = $query->modelClass;
        $key = $this->quoteValue($modelClass::keyPrefix() . ':a:');

        // 求最大值，n 用来记当前值，v 用来记最大值，最后放回 v
        return $this->build($query, "n=redis.call('HGET',$key .. pk," . $this->quoteValue($column) . ") if v==nil or n>v then v=n end", 'v');
    }

    /**
     * @param ActiveQuery $query the query used to build the script 查询条件
     * @param string $buildResult the lua script for building the result 构建数据的 lua 脚本
     * @param string $return the lua variable that should be returned 最终返回值在 lua 脚本中的变量名
     * @throws NotSupportedException when query contains unsupported order by condition
     * @return string
     */
    private function build($query, $buildResult, $return)
    {
        if (!empty($query->orderBy)) {
            throw new NotSupportedException('orderBy is currently not supported by redis ActiveRecord.');
        }

        $columns = [];
        if ($query->where !== null) {
            $condition = $this->buildCondition($query->where, $columns);
        } else {
            $condition = 'true';
        }

        // 根据顺序位置设置在 lua 中使用的 $limitCondition，例子： 'i>1 and i<=10'
        $start = $query->offset === null ? 0 : $query->offset;
        $limitCondition = 'i>' . $start . ($query->limit === null ? '' : ' and i<=' . ($start + $query->limit));

        /* @var $modelClass ActiveRecord */
        $modelClass = $query->modelClass;
        $key = $this->quoteValue($modelClass::keyPrefix());
        $loadColumnValues = '';
        foreach ($columns as $column => $alias) {
            // HGET -- 返回 key 指定的哈希集中该字段所关联的值
            // 拿出所有的 ActiveRecord 的属性，以便在之后的 condition 中使用
            $loadColumnValues .= "local $alias=redis.call('HGET',$key .. ':a:' .. pk, '$column')\n";
        }

        return <<<EOF
local allpks=redis.call('LRANGE',$key,0,-1)
local pks={}
local n=0
local v=nil
local i=0
local key=$key
for k,pk in ipairs(allpks) do
    $loadColumnValues
    if $condition then
      i=i+1
      if $limitCondition then
        $buildResult
      end
    end
end
return $return
EOF;
    }

    /**
     * Adds a column to the list of columns to retrieve and creates an alias
     * @param string $column the column name to add
     * @param array $columns list of columns given by reference
     * @return string the alias generated for the column name
     */
    private function addColumn($column, &$columns)
    {
        if (isset($columns[$column])) {
            return $columns[$column];
        }
        // 创建 $column 的别名
        $name = 'c' . preg_replace("/[^A-z]+/", "", $column) . count($columns);

        // 返回生成的别名
        return $columns[$column] = $name;
    }

    /**
     * Quotes a string value for use in a query.
     * Note that if the parameter is not a string or int, it will be returned without change.
     * @param string $str string to be quoted
     * @return string the properly quoted string
     */
    private function quoteValue($str)
    {
        if (!is_string($str) && !is_int($str)) {
            return $str;
        }

        // addcslashes — 以 C 语言风格使用反斜线转义字符串中的字符
        // 返回字符串，该字符串在属于参数 charlist 列表中的字符前都加上了反斜线。
        return "'" . addcslashes(str_replace("'", "\\'", $str), "\000\n\r\\\032") . "'";
    }

    /**
     * Parses the condition specification and generates the corresponding Lua expression.
     * @param string|array $condition the condition specification. Please refer to [[ActiveQuery::where()]]
     * on how to specify a condition.
     * @param array $columns the list of columns and aliases to be used
     * @return string the generated SQL expression
     * @throws \yii\db\Exception if the condition is in bad format
     * @throws \yii\base\NotSupportedException if the condition is not an array
     */
    public function buildCondition($condition, &$columns)
    {
        static $builders = [
            'not' => 'buildNotCondition',
            'and' => 'buildAndCondition',
            'or' => 'buildAndCondition',
            'between' => 'buildBetweenCondition',
            'not between' => 'buildBetweenCondition',
            'in' => 'buildInCondition',
            'not in' => 'buildInCondition',
            'like' => 'buildLikeCondition',
            'not like' => 'buildLikeCondition',
            'or like' => 'buildLikeCondition',
            'or not like' => 'buildLikeCondition',
        ];

        if (!is_array($condition)) {
            throw new NotSupportedException('Where condition must be an array in redis ActiveRecord.');
        }
        if (isset($condition[0])) { // operator format: operator, operand 1, operand 2, ...
            // 存在 $condition[0]，意味着 $condition 是 ['not in', 'a', [1, 2, 3]] 或者 ['or', $condition1, $condition2] 的格式
            $operator = strtolower($condition[0]);
            if (isset($builders[$operator])) {
                $method = $builders[$operator];
                // 去掉开头的值
                array_shift($condition);

                // 调用相应的 build 方法
                return $this->$method($operator, $condition, $columns);
            } else {
                throw new Exception('Found unknown operator in query: ' . $operator);
            }
        } else { // hash format: 'column1' => 'value1', 'column2' => 'value2', ...

            return $this->buildHashCondition($condition, $columns);
        }
    }

    private function buildHashCondition($condition, &$columns)
    {
        $parts = [];
        foreach ($condition as $column => $value) {
            if (is_array($value)) { // IN condition
                // 如果是 ['a' => [1, 2, 3]] 的格式，表示是一个 in 的条件，要使用 buildInCondition
                $parts[] = $this->buildInCondition('in', [$column, $value], $columns);
            } else {
                // 将 bool 值转化为 int
                if (is_bool($value)) {
                    $value = (int) $value;
                }
                if ($value === null) {
                    // 查看哈希表 key 中，给定域 field 是否存在，不存在返回 0
                    // 将查看属性值是否不存在作为条件，不存在为true
                    $parts[] = "redis.call('HEXISTS',key .. ':a:' .. pk, ".$this->quoteValue($column).")==0";
                } elseif ($value instanceof Expression) {
                    // Expression 可以避免掉 quoteValue 的处理
                    $column = $this->addColumn($column, $columns);
                    $parts[] = "$column==" . $value->expression;
                } else {
                    $column = $this->addColumn($column, $columns);
                    $value = $this->quoteValue($value);
                    $parts[] = "$column==$value";
                }
            }
        }

        // 如果条件不只一个， 和成一个字符串返回，例如：(ca1==1) and (cb1=2) and (caa2==3)
        return count($parts) === 1 ? $parts[0] : '(' . implode(') and (', $parts) . ')';
    }

    private function buildNotCondition($operator, $operands, &$params)
    {
        // $Operator 是 not, $operands 是 ['a', 1], 表示 a!=1
        if (count($operands) != 1) {
            throw new InvalidParamException("Operator '$operator' requires exactly one operand.");
        }

        $operand = reset($operands);
        if (is_array($operand)) {
            // 正常构建 condition
            $operand = $this->buildCondition($operand, $params);
        }

        // 然后用!()包起来
        return "!($operand)";
    }

    private function buildAndCondition($operator, $operands, &$columns)
    {
        $parts = [];
        foreach ($operands as $operand) {
            if (is_array($operand)) {
                $operand = $this->buildCondition($operand, $columns);
            }
            if ($operand !== '') {
                $parts[] = $operand;
            }
        }
        if (!empty($parts)) {
            return '(' . implode(") $operator (", $parts) . ')';
        } else {
            return '';
        }
    }

    private function buildBetweenCondition($operator, $operands, &$columns)
    {
        if (!isset($operands[0], $operands[1], $operands[2])) {
            throw new Exception("Operator '$operator' requires three operands.");
        }

        list($column, $value1, $value2) = $operands;

        $value1 = $this->quoteValue($value1);
        $value2 = $this->quoteValue($value2);
        $column = $this->addColumn($column, $columns);

        return "$column >= $value1 and $column <= $value2";
    }

    private function buildInCondition($operator, $operands, &$columns)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new Exception("Operator '$operator' requires two operands.");
        }

        list($column, $values) = $operands;

        $values = (array) $values;

        if (empty($values) || $column === []) {
            return $operator === 'in' ? 'false' : 'true';
        }

        if (count($column) > 1) {
            return $this->buildCompositeInCondition($operator, $column, $values, $columns);
        } elseif (is_array($column)) {
            $column = reset($column);
        }
        $columnAlias = $this->addColumn($column, $columns);
        $parts = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = isset($value[$column]) ? $value[$column] : null;
            }
            if ($value === null) {
                $parts[] = "redis.call('HEXISTS',key .. ':a:' .. pk, ".$this->quoteValue($column).")==0";
            } elseif ($value instanceof Expression) {
                $parts[] = "$columnAlias==" . $value->expression;
            } else {
                $value = $this->quoteValue($value);
                $parts[] = "$columnAlias==$value";
            }
        }
        $operator = $operator === 'in' ? '' : 'not ';

        return "$operator(" . implode(' or ', $parts) . ')';
    }

    protected function buildCompositeInCondition($operator, $inColumns, $values, &$columns)
    {
        $vss = [];
        foreach ($values as $value) {
            $vs = [];
            foreach ($inColumns as $column) {
                if (isset($value[$column])) {
                    $columnAlias = $this->addColumn($column, $columns);
                    $vs[] = "$columnAlias==" . $this->quoteValue($value[$column]);
                } else {
                    $vs[] = "redis.call('HEXISTS',key .. ':a:' .. pk, ".$this->quoteValue($column).")==0";
                }
            }
            $vss[] = '(' . implode(' and ', $vs) . ')';
        }
        $operator = $operator === 'in' ? '' : 'not ';

        return "$operator(" . implode(' or ', $vss) . ')';
    }

    private function buildLikeCondition($operator, $operands, &$columns)
    {
        throw new NotSupportedException('LIKE conditions are not suppoerted by redis ActiveRecord.');
    }
}
